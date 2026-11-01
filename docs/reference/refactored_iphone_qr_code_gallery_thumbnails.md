---
description: Add video thumbnails to the guest event gallery row
---

# Refactor: Guest Gallery — Video Thumbnails in Row

## Status — 2026-08-15 — COMPLETE

Fully implemented and deployed across all environments. PHP `guest-gallery.php` now emits `thumbnail_url` for each video. Swift `GuestGalleryAPIClient.swift` and `GuestGalleryView.swift` decode and render thumbnails. Apache nonce gate regex fixed from `{30,40}` to `{30,43}`. Verified on device (HTTP 200, image/png).

## Rationale

The gallery list rows currently show only a play icon, the uploader name, and the video label. Adding a thumbnail between the play icon and the name gives users a visual preview before tapping, improves scannability, and is a low-effort change since thumbnails are already generated on upload by `MediaProbeService::generateVideoThumbnail` and stored at `/video/thumbnails/{checksum_sha256}.png`.

## Goal

**Display a small video thumbnail in each gallery row, to the right of the play icon and to the left of the name/label/New-badge stack.**

## Decision

Use the existing thumbnail files at `/video/thumbnails/{sha256}.png`. Expose the URL via `guest-gallery.php` (same nonce-embedding pattern as `stream_url`). Apache's existing `gallery_nonce_auth` gate already permits nonce-authenticated requests to `/video/thumbnails/`. On iOS, implement a lightweight iOS 14-compatible `AsyncThumbnail` view using `URLSession` (no third-party dependency).

Thumbnail is **optional**: if `thumbnail_url` is absent from the JSON response (old entries, upload errors, non-video files), the row renders exactly as today.

## Architecture — How Thumbnails Are Already Served

```
upload ingest
    └── MediaProbeService::generateVideoThumbnail()
            └── ffmpeg → /var/www/html/video/thumbnails/{sha256}.png

Apache /video/ LocationMatch
    RequireAny:
        - Require valid-user (Basic Auth)
        - Require env gallery_nonce_auth    ← set by SetEnvIf when URL contains ?nonce=...
```

The SHA-256 filename is unguessable without the original file, matching the comment in `default-ssl.conf.j2`: "SHA-256 filenames provide a second layer — unguessable without possessing the original file."

## Proposed Implementation

### Files Under Change

| File | Repo | Change |
|---|---|---|
| `api/guest-gallery.php` | gighiveinfra | extract sha256 from `file_relpath`; `file_exists` check; add `thumbnail_url` field |
| `GuestGalleryAPIClient.swift` | gighiveapp | Add `thumbnailUrl: String?` to `GuestGalleryVideo` |
| `GuestGalleryView.swift` | gighiveapp | Add `AsyncThumbnail` component; insert in row layout |

---

### 1. `api/guest-gallery.php`

**Change:** No SQL change needed. `upload_jobs.file_relpath` (already selected) contains `video/{sha256}.ext` — the sha256 is the same value used to name the thumbnail file at `/var/www/html/video/thumbnails/{sha256}.png`. Extract it with `preg_match` in PHP and gate on `file_exists` before emitting `thumbnail_url`.

**Why not `upload_job_files`:** Guest QR uploads (`qr_guest_upload` job type) are written by `UploadService::finalizeTusUpload()`, which only inserts into `upload_jobs` and `anon_upload_attributions`. The `upload_job_files` table is only populated by manifest-import paths. It has no rows for guest uploads.

**SQL is unchanged.** Update the video-building loop:

```php
foreach ($rows as $r) {
    $streamUrl    = '/api/guest-stream.php?nonce=' . urlencode($nonce) . '&job_id=' . (int)$r['upload_job_id'];
    $thumbnailUrl = null;
    if (preg_match('@^video/([0-9a-f]{64})\.@', (string)($r['file_relpath'] ?? ''), $m)) {
        $thumbPath = '/var/www/html/video/thumbnails/' . $m[1] . '.png';
        if (is_file($thumbPath)) {
            $thumbnailUrl = '/video/thumbnails/' . $m[1] . '.png?nonce=' . urlencode($nonce);
        }
    }
    $videos[] = [
        'upload_job_id'  => (int)$r['upload_job_id'],
        'label'          => $r['label'],
        'stream_url'     => $streamUrl,
        'thumbnail_url'  => $thumbnailUrl,
        'display_name'   => $r['display_name'],
        'approved_at'    => $r['approved_at'],
        'reported_by_me' => (bool)$r['reported_by_me'],
    ];
}
```

**Note:** `is_file()` check on the PNG path gates out any video where ffmpeg failed to generate a thumbnail, so `thumbnail_url` is only non-null when the file actually exists on disk. No 404s will be sent to iOS.

**Apache fix (required):** The `SetEnvIf Request_URI` regex for `gallery_nonce_auth` originally capped nonces at 40 chars (`{30,40}`), but PHP's `random_bytes(32)` base64url encoding produces 43-char nonces. Stream URLs for iOS avoid this because they go through `guest-stream.php` (a PHP proxy) — not through Apache's SetEnvIf gate. Thumbnail requests are direct static-file requests that must pass Apache's `RequireAny` gate. With a 43-char nonce, `gallery_nonce_auth` was never set → Apache returned 401 → `UIImage(data:)` on the HTML error body returned nil → blank space on device. Fixed in `default-ssl.conf.j2`: `{30,40}` → `{30,43}`.

---

### 2. `GuestGalleryAPIClient.swift`

**Change:** Add `thumbnailUrl` to `GuestGalleryVideo` and its `CodingKeys`.

```swift
struct GuestGalleryVideo: Codable, Identifiable {
    let uploadJobId: Int
    let label: String?
    let streamUrl: String
    let thumbnailUrl: String?      // nil for audio, old entries, or failed thumbnail generation
    let displayName: String?
    let approvedAt: String?
    let reportedByMe: Bool

    enum CodingKeys: String, CodingKey {
        case uploadJobId = "upload_job_id"
        case label
        case streamUrl = "stream_url"
        case thumbnailUrl = "thumbnail_url"
        case displayName = "display_name"
        case approvedAt = "approved_at"
        case reportedByMe = "reported_by_me"
    }
}
```

`init(from:)` already uses `decodeIfPresent` for optionals — add the same for `thumbnailUrl`:

```swift
thumbnailUrl = try container.decodeIfPresent(String.self, forKey: .thumbnailUrl)
```

---

### 3. `GuestGalleryView.swift`

**Add `AsyncThumbnail` — iOS 14 compatible image loader** (no `AsyncImage` which is iOS 15+):

```swift
private final class ThumbnailLoader: ObservableObject {
    @Published var image: UIImage?
    func load(from url: URL) {
        guard image == nil else { return }  // already loaded — don't re-fire on scroll
        URLSession.shared.dataTask(with: url) { data, _, _ in
            guard let data = data, let img = UIImage(data: data) else { return }
            DispatchQueue.main.async { self.image = img }
        }.resume()
    }
}

private struct AsyncThumbnail: View {
    let url: URL?   // nil → blank space (same 56×40 frame, no image)
    @StateObject private var loader = ThumbnailLoader()
    var body: some View {
        Group {
            if let img = loader.image {
                Image(uiImage: img)
                    .resizable()
                    .scaledToFit()  // shows full image; avoids heavy crop of portrait videos
            }
            // else: transparent — blank space matching the 56×40 frame
        }
        .frame(width: 56, height: 40)
        .cornerRadius(4)
        .onAppear {
            if let url = url { loader.load(from: url) }
        }
    }
}
```

**Alignment note:** `AsyncThumbnail` is rendered unconditionally for every row (see row layout below). When `thumbnail_url` is `null`, `url` is `nil`, no network request is made, and a transparent 56×40pt space is shown. This keeps the name/badge/label `VStack` left-aligned at the same horizontal position across all rows.

**Update the row layout** — insert `AsyncThumbnail` unconditionally between the play icon and the name/label VStack, inside the `NavigationLink` label:

```swift
NavigationLink(destination: VideoPlayerView(...)) {
    HStack(spacing: 10) {
        Image(systemName: "play.circle.fill")
            .font(.system(size: 28))
            .foregroundColor(.yellow)
            .frame(width: 40, height: 40)

        // Always rendered — blank 56×40 space when thumbnail_url is nil
        AsyncThumbnail(url: buildThumbnailURL(video: video))

        VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 6) {
                Text(video.displayName ?? "Attendee")
                    ...
                if !viewedIds.contains(video.uploadJobId) {
                    Text("New") ...
                }
            }
            if let label = video.label, !label.isEmpty {
                Text(label) ...
            }
        }
    }
    .contentShape(Rectangle())
}
```

Add `buildThumbnailURL(video:)` alongside the existing `buildStreamURL(video:)` (confirmed at `GuestGalleryView.swift:260`). Both use the same `URL(string:relativeTo:)?.absoluteURL` pattern:

```swift
private func buildThumbnailURL(video: GuestGalleryVideo) -> URL? {
    guard let thumbStr = video.thumbnailUrl,
          let base = URL(string: record.baseURLString) else { return nil }
    return URL(string: thumbStr, relativeTo: base)?.absoluteURL
}
```

---

## SonarQube / Best-Practice Notes

- **RSPEC-6426:** No force unwraps. `thumbnailUrl` is `String?`; `buildThumbnailURL` returns `URL?`; both guarded with `if let`.
- **RSPEC-3776:** `ThumbnailLoader.load` is a single URLSession call — minimal complexity.
- Row layout width: when `thumbnailUrl` is nil the row is identical to today. No layout regression.

## Testing Checklist

| # | Scenario | Expected result |
|---|---|---|
| 1 | Video with a generated thumbnail | Thumbnail image appears between play icon and name |
| 2 | Video without a thumbnail (old entry, ffmpeg failed, PNG absent from disk) | `thumbnail_url` is `null` in JSON (`is_file()` returns false); `thumbnailUrl` is `nil` on iOS; row renders as today — no crash, no broken image |
| 3 | Mixed gallery (some thumbed, some not) | Each row independently shows or omits thumbnail |
| 4 | Scroll away from a row and back | No duplicate network request (`guard image == nil` fires) |
| 5 | Tap thumbnail area | Navigates to `VideoPlayerView` (thumbnail is inside the `NavigationLink` touch area) |
| 6 | Flag button on row with thumbnail | Flag action fires correctly; thumbnail does not intercept the tap |
| 7 | Old iOS app (no `thumbnail_url` field decoded) | `decodeIfPresent` returns nil; row renders as today — no decode error |

## Files to Change

1. `ansible/roles/docker/files/apache/webroot/api/guest-gallery.php` (gighiveinfra) — extract sha256 from `file_relpath` via `preg_match`; `is_file()` check; emit `thumbnail_url` field
2. `GigHive/Sources/App/GuestGalleryAPIClient.swift` (gighiveapp) — add `thumbnailUrl: String?` field and CodingKey
3. `GigHive/Sources/App/GuestGalleryView.swift` (gighiveapp) — add `ThumbnailLoader` + `AsyncThumbnail` views; insert thumbnail in NavigationLink label

## Deployment

After PHP change: run Ansible playbook targeting devvm → verify on staging → deploy to prod. No DDL changes required — uses `upload_jobs.file_relpath` already present in production; no new DB columns needed.

## Progress

### Completed
- PHP change (`guest-gallery.php`) implemented and deployed to devvm
- Swift changes (`GuestGalleryAPIClient.swift`, `GuestGalleryView.swift`) implemented and built
- Identified and corrected root cause: `upload_job_files` has no rows for guest uploads; sha256 sourced from `upload_jobs.file_relpath` instead
- Apache query-string nonce gate: replaced broken `SetEnvIf Query_String` with proven `SetEnvIfExpr "%{QUERY_STRING} =~ /(^|&)nonce=/"` — confirmed working via trace logging
- iOS SwiftUI fix: empty `Group {}` does not fire `.onAppear` in iOS 14; added `else { Color.clear }` so the group always has content — confirmed thumbnails loading on device (HTTP 200, image/png)

### Completed — This Feature
- [x] Deploy all changes to staging → prod

### Remaining — Follow-on Tasks
- Thumbnail caching: `URLSession` does no disk caching for auth'd requests. If scroll performance is poor, add a simple `NSCache`-backed image cache.

---

## Appendix: `assets` Table vs `upload_jobs` Table

### Why guest gallery queries use `upload_jobs`, not `assets`

**`assets` = content-addressed file store.** It tracks the physical media file — checksum, duration, media_info, file_type. It is designed around deduplication (unique key on `tenant_id + checksum_sha256`) and is the canonical record for a media file. It has no concept of "who uploaded it," "for which event," or "was it approved."

**`upload_jobs` = upload workflow record.** It was created for the manifest-import admin workflow, then extended for guest QR uploads with all guest-specific lifecycle columns:

| Column | Purpose |
|---|---|
| `moderation_status` | Admin must approve before video appears in gallery |
| `approved_at` | Used to drive the "New" badge on iOS |
| `guest_flagged` | Guest report flow |
| `guest_deleted` | Guest self-delete (soft-delete; file retained) |
| `label` | Uploader's clip label |
| `file_relpath` | `video/{sha256}.ext` — added because the path is not reconstructable from other DB columns after the fact |

The gallery query chain is `upload_jobs` → `anon_upload_attributions` → `event_upload_tokens` → event scoping. None of that attribution or moderation state lives in `assets`.

### Why `upload_job_files` was wrong for this feature

`upload_job_files` is only populated by the manifest-import admin workflow (`import_manifest_upload_start.php` / `import_manifest_upload_finalize.php`). Guest QR uploads go through `UploadService::finalizeTusUpload()`, which only inserts into `upload_jobs` and `anon_upload_attributions`. `upload_job_files` has **zero rows** for any guest upload.

The initial plan used a correlated subquery against `upload_job_files` to fetch `checksum_sha256`. This always returned `NULL` for guest videos — confirmed by `curl` against devvm showing all `thumbnail_url: null` after deployment.

### Correct approach

`upload_jobs.file_relpath` (already selected by the gallery query) contains `video/{sha256}.ext`. The sha256 is extracted with `preg_match` in the PHP loop, and `is_file()` on the PNG path confirms the thumbnail exists before emitting the URL. No SQL change, no new DB columns, no 404s to iOS.

---

See `problem_iphone_qr_code_gallery_thumbnails.md` for the full Apache query-string nonce gate RCA, empirical debugging log, and step-by-step commands.
