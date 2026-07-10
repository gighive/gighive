# Shared Event Gallery — Rationale and Safety Framework

**Related docs:** `docs/feature_iphone_qr_code_support.md` · `docs/feature_iphone_qr_code_implementation.md`

---

## Why a Shared Gallery Is Worth Building

An event-based shared gallery — where every attendee of the same concert, wedding, or private gathering can contribute and view each other's video clips — solves a real problem: **no single person captures the whole story**.

- **Concerts and live performances:** Different vantage points in the crowd catch different moments — the guitar solo from the front row, the crowd reaction from the back, the backstage setup. A shared gallery turns isolated clips into a multi-angle record of the night.
- **Weddings and celebrations:** Guests witness different scenes simultaneously — the ceremony, candid family moments, the reception floor. The couple gets a crowd-sourced archive that no hired videographer could replicate alone.
- **The shared context is the trust baseline.** Everyone present at the event received the same QR code, attended the same gathering, and shares the same memory. This is a meaningfully different risk profile from a fully anonymous public upload endpoint — participation requires physical presence or explicit invitation.

The feature's value is directly proportional to how complete and accessible the gallery becomes. Friction that blocks legitimate contributors ultimately defeats the purpose.

---

## The Risks That Must Be Managed

Shared visibility creates a surface for abuse that a private-to-organizer-only upload does not:

- **Explicit or inappropriate content** uploaded by a bad actor surfaces immediately to all other event attendees if the gallery is public and unmoderated.
- **Off-topic or unrelated content** — clips recorded days before or miles from the venue — dilutes the gallery's coherence and may introduce privacy concerns (footage of people not present at the event).
- **No identity verification** means the QR code link is the only gate. If a QR code is photographed and shared beyond the event, the upload surface widens beyond intended attendees.

---

## Shared Gallery Walkthrough Summary

### Guest — Phase 1: Upload

1. Guest scans QR code at venue with iPhone camera → Universal Link opens GigHive app
2. `SplashView` detects token → navigates to `GuestUploadView`; token validation spinner shown
3. On valid token: upload form renders with read-only event info (org, date, type), honor system warning, display name field (auto-filled from device name, editable), optional **Clip label** field (user-friendly name shown in the gallery; if left blank the server stores a formatted upload timestamp as the label — e.g. *"Video Jul 5, 2026 3:21 PM"*; helper text reads *"Files are stored under a unique name for privacy. Your label helps you identify the clip in the gallery."*), ToS checkbox, and **Select Video** button
4. Guest selects a video clip via `PHPickerViewController` (videos only — see Media Type note below)
5. Guest checks ToS checkbox — **Upload** button enables only when **both** a video has been selected (step 4) **and** ToS is checked; either alone is insufficient
6. Guest taps **Upload** → TUS chunked upload runs with progress indicator → finalize call sent
7. Server returns `status_nonce` + `upload_job_id` → stored locally as `GuestUploadRecord` in `UserDefaults`
8. Success screen: "Your video is going into a queue for review…" message → **Done** returns to `SplashView`

### Guest — Phase 2: Status Check & Gallery Access (on later app opens)

9. App launch → background poll via `/api/guest-status.php?nonce=…` covering two populations:
   - **Pending records** (`approvalStatus == "pending"`): check for status change to approved or rejected
   - **Approved records** (`approvalStatus == "approved"`): check `video_count` for new additions since last visit (requires endpoint to return `video_count`; store last-seen count in `GuestUploadRecord`)
10. **Approved:** in-app banner shown with the following message and a **"View Event Gallery"** button:

    > *"Your video has been accepted! You now have access to the event gallery. The gallery may be empty at first if you're among the first approved — it grows as the organizer reviews other submissions. Note: access is only available to attendees who have uploaded a video from this event, and will be available for [N] days."*

    The `[N]` days value is calculated from `days_remaining` returned by the status endpoint. Tapping **"View Event Gallery"** navigates to the native `GuestGalleryView` (see Gallery Page below), passing the `status_nonce`. The gallery displays all approved videos for that event using native `AVPlayer` playback; a **Report** action sheet on each video flags it for organizer re-review.

    > ⚠️ *"Important: your gallery access is stored on this device. If you delete the GigHive app, you will permanently lose access to this gallery and it cannot be recovered."*

    This device-bound warning is also shown as a persistent footer on every `GuestGalleryView` visit (not just the one-time approval banner) so the guest is reminded on every session.

    After dismissing the banner, the gallery remains accessible via a persistent **"Your Event Galleries"** section on `SplashView` (see Phase 3).
11. **Rejected:** brief non-alarming message shown; record marked resolved; polling stops for this record

### Guest — Phase 3: Ongoing Anonymous Gallery Access

12. Approved `GuestUploadRecord` entries persist in `UserDefaults` and are surfaced as a dedicated **"Your Event Galleries"** section on `SplashView`, listing each event by name with a **"View Gallery"** button. This section is always visible when approved records exist — it is the primary persistent entry point after the one-time approval banner is dismissed. **Multiple nonces for the same event** (e.g. scanning two different QR codes for the same event on the same device) are deduplicated in the UI — they collapse to a single row keyed by `baseURLString + eventName`. All nonces are still polled in the background and stale ones pruned; the deduplication is display-only. The "New videos" badge fires if *any* nonce for that event has unseen videos.
13. Guest taps a gallery entry → app navigates to native `GuestGalleryView`, passing the `status_nonce`; gallery fetches the approved video list from `/api/guest-gallery.php?nonce=…` and renders each clip using `AVPlayer`; gallery may have grown since last visit as other attendees' videos are approved over time
14. App can show a **"New videos added"** indicator if the gallery count has increased since last visit (requires the status endpoint to return a `video_count` alongside `status`)
15. If the gallery has passed its lifespan: status endpoint returns `expired` → local record shows "This gallery is no longer available" state; no error, no crash
16. If the guest's device is reset or the app is deleted, the `status_nonce` is lost — access cannot be recovered without the nonce; this is a known limitation of the accountless model
17. **Stale record cleanup:** if the server returns `404` for a polled nonce (e.g. the database was rebuilt in a dev/staging environment), the `GuestUploadRecord` is automatically removed from `UserDefaults` on that poll. Network errors (transient) are distinguished from `404`/`403` (definitive) — only definitive failures trigger removal.
18. **Guest-aware login prompt:** `SplashView` shows *"Please login first"* only to users with no credentials and no approved gallery records. Guests with at least one approved gallery record see a softer footnote instead: *"Login for full database and upload access"* — confirming they are legitimately using the app as a gallery-only user without pressuring them to log in.

**How long should guests be able to access the gallery?**

There is no single right answer — it depends on event type and organizer intent. The gallery lifespan is an **event-level** setting configured when the admin loads or creates the event in `admin/event_qr.php` — it is independent of the QR token TTL and applies equally to all QR codes generated for the same event:

| Event type | Recommended default | Rationale |
|---|---|---|
| Concert / live show | **90 days** | Interest drops sharply after a few weeks; storage cost grows without a ceiling |
| Wedding / milestone celebration | **1 year** (or indefinite) | Once-in-a-lifetime event; couple and family revisit long after; organizer should control this |
| Corporate / private event | **30 days** | Content is often time-sensitive and may have confidentiality expectations |

**Practical constraint:** the `status_nonce` lives in `UserDefaults` on the device. If the guest deletes the app, resets their phone, or switches devices, the nonce is gone and gallery access cannot be recovered. This is an inherent limitation of the accountless model — document it in the UX so guests understand their access is device-bound.

**Schema addition:** `gallery_expires_at DATETIME NULL` on the **`events`** table (not `event_upload_tokens`). The gallery is event-scoped — all QR tokens for the same event share the same gallery expiry. `NULL` = indefinite. Set by the admin at event load time in `admin/event_qr.php`. A configurable system-wide default (90 days) is stored in Ansible group_vars and pre-populates the form.

**`gallery_expires_at` computation:** `events.event_date + N days`. The anchor is always the event date, not the time the admin loaded the page. This means an event loaded retroactively will already show a reduced (or expired) lifespan if the event date was in the past — which is the correct and expected behavior.

### Admin — Phase 1: QR Generation (before event)

1. Admin navigates to **Guest QR Upload** in the admin portal → `admin/event_qr.php`
2. Enters org name + event date → clicks **Load Event** → event row created or resolved
3. In the **Event** section (after Load Event): sets **Gallery lifespan** in days (pre-populated from `QR_GALLERY_DEFAULT_LIFESPAN_DAYS`; stored as `gallery_expires_at = event_date + N days` on the `events` row; applies to all QR codes for this event); checks **Multi-day event** checkbox if applicable (stored on `events`; applies to all tokens)
4. In QR Generator section: selects TTL (4h / 24h / 7d / 14d). **Validation:** if the selected TTL exceeds the gallery lifespan (e.g. 14-day TTL on a 7-day gallery), the form should warn: *"Upload window extends beyond gallery expiry — guests who upload near the end of the window may be approved after the gallery has already closed."* This is a warning, not a hard block. **Duplicate QR guard:** if at least one active (non-revoked, non-expired) token already exists for the event, clicking **Generate QR** triggers a browser `confirm()` dialog: *"⚠️ This event already has N active QR code(s). Generating a new one will create an additional upload link — the existing one(s) will remain active until revoked or expired. Continue anyway?"* Admin may cancel or proceed; this is a guard, not a hard block. → clicks **Generate QR**
5. QR canvas renders → **Download PNG** for printing/display at venue
6. Guests scan QR during event; uploads flow in

### Admin — Phase 2: Moderation Queue (within 24–48 h of event)

7. Admin returns to `event_qr.php` → Guest Uploads section shows pending submissions
8. Each row: display name, timestamp, file label, token status, moderation badge, **[Approve]** / **[Reject]** buttons
9. Admin reviews clip (thumbnail / download link) → clicks **[Approve]** or **[Reject]**
10. Approval sets `moderation_status = approved` → guest notified on next app open; video returned by `/api/guest-gallery.php`
11. Rejection sets `moderation_status = rejected` → guest notified on next app open; video excluded from gallery
12. If abuse detected: **[Revoke]** in Active Tokens section → deactivates token; blocks further uploads. **Revoking does NOT auto-reject pending uploads from that token** — they remain in `pending` state and the admin must reject them manually from the moderation queue. This is intentional: a token may be revoked because it was leaked (not abused), and the legitimate uploads that came through it may still deserve approval.

### Moderation Queue — Implementation Notes (`event_qr.php` Section 3)

The moderation queue is an enhancement of the existing Section 3 "Guest Uploads" table in `event_qr.php`. Code reuse is very high — all CSS, auth, DB connection, flash-message, and table patterns are already present.

**What already exists (zero new code needed):**
- All CSS classes: `.card`, `.badge`, `.badge-active/expired/revoked`, `.btn-danger`, `.btn-sm`, `.table-scroll`, `.alert-ok/err`
- Auth gate, `Database::createFromEnv()`, flash messages via `$_SESSION`
- The table structure and the `warn-row` highlight pattern

New functionality needed (~40–50 new PHP lines total) — see implementation plan Steps 5–9 for full queries, POST handlers, and HTML.

**Not needed from `database_catalog.php`:** its pagination and search machinery are overkill for a single-event moderation queue (typically 10–100 clips). The visual design language is identical so pages feel consistent without importing that complexity.

**`event_qr.php` wireframe — full page layout:**

```
┌──────────────────────────────────────────────────────────────────────┐
│  Guest QR Upload                                       ← System      │
├──────────────────────────────────────────────────────────────────────┤
│ ┌── Event Selector ──────────────────────────────────────────────┐   │
│ │  Event date * [2026-07-17]  Organization * [StormPigs        ] │   │
│ │                                            [ Load Event      ] │   │
│ │  ✅ Event loaded: StormPigs — 2026-07-17                       │   │
│ │  Gallery lifespan: [90] days   ☐ Multi-day event  [ Save ]    │   │
│ └────────────────────────────────────────────────────────────────┘   │
│                                                                       │
│ ┌── QR Code Generator — StormPigs / 2026-07-17 ─────────────────┐   │
│ │  Link expiry  ○ 4h  ● 24h  ○ 7d  ○ 14d                        │   │
│ │  ⚠ Upload window extends beyond gallery expiry  (conditional)  │   │
│ │  [ Generate QR ]                                               │   │
│ │    ┌───────────┐                                               │   │
│ │    │ ▓▓░▓░▓▓░  │  https://gighive.app/upload/abc123…          │   │
│ │    │ ░▓▓░▓░▓▓  │  [ Download PNG ]                            │   │
│ │    │ ▓░▓▓░▓░▓  │                                               │   │
│ │    └───────────┘                                               │   │
│ └────────────────────────────────────────────────────────────────┘   │
│                                                                       │
│ ┌── Upload Tokens ───────────────────────────────────────────────┐   │
│ │  Token       Expires              Status     Created   Action  │   │
│ │  a3f9bc2d…  2026-07-18 14:30    ● Active    07-17    [Revoke] │   │
│ │  88de1a04…  2026-07-17 08:00    ○ Expired   07-16    —        │   │
│ └────────────────────────────────────────────────────────────────┘   │
│                                                                       │
│ ┌── Guest Uploads — Moderation Queue ───────────────────────────┐   │
│ │  Name            Uploaded      Job    Token     Moderation     │   │
│ │  Preview  Action                                               │   │
│ │  ─────────────────────────────────────────────────────────    │   │
│ │  Scott's iPhone  07-17 21:05  #142   ● Active  ⏳ Pending     │   │
│ │  [▶ Preview]  [ Approve ]  [ Reject ]                          │   │
│ │                                                                │   │
│ │  Jane's iPhone   07-17 20:41  #139   ● Active  ✅ Approved    │   │
│ │  [▶ Preview]  —                                                │   │
│ │                                                                │   │
│ │  (anonymous)     07-17 20:12  #135   ○ Expired ❌ Rejected    │   │
│ │  [▶ Preview]  —                                                │   │
│ └────────────────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────────┘
```

**Notes on the wireframe:**
- The **Organization** field is a free-text `<input>` backed by a `<datalist>` populated from `SELECT DISTINCT org_name FROM events` — if the DB is empty the admin types freely (new org); if orgs already exist they appear as browser autocomplete suggestions; either way the value submitted is plain text (no separate organizations table)
- Gallery lifespan and Multi-day checkbox live in the **Event Selector** card (event-level settings), not in the QR Generator card (token-level settings)
- The TTL mismatch warning appears inline in the QR Generator card only when TTL > gallery lifespan
- Approve/Reject buttons only appear on `pending` rows; already-decided rows show `—`
- `▶ Preview` is a plain `<a target="_blank">` link to the stream URL — no modal needed for MVP

### Media Type Note — Video Only

Guest uploads are **video only** for this feature. Audio-only files are explicitly excluded for the following reasons:

- **Content moderation is blind to audio.** NudeNet and all frame-based NSFW tools operate on video frames. An audio-only file has nothing to scan — inappropriate verbal content has no automated detection path in the current stack.
- **The gallery UX is inherently visual.** The shared gallery concept ("different angles of the band") implies video clips. Audio-only files would require a separate player component in `GuestGalleryView` and `guest_event_view.php` with no visual moderation signal for the organizer.
- **`PHPickerViewController` is already scoped to `.videos` only.** Supporting audio would require adding `PHPickerFilter.audio` or a separate `UIDocumentPickerViewController` flow, plus updating the server-side MIME allowlist (`UPLOAD_ALLOWED_MIMES_JSON`).

*Revisit audio support when the content moderation pipeline has an audio analysis path (e.g. speech-to-text → content policy check).*

---

## Numbered Safety Framework — What We Have So Far

The following measures form a layered defense. Earlier items are lower cost and can be implemented sooner; later items provide stronger guarantees but require more infrastructure. No single measure is sufficient on its own.

1. **Honor system warning at the point of upload.**
   Display a clear plain-language warning on both `db/upload_form_single.php` and `GuestUploadView` before the upload is submitted: *"You're on the honor system — please don't upload abusive, pornographic, violent, or otherwise inappropriate content. Uploads are reviewed by the event organizer and may be removed."* This sets a behavioral norm, creates a paper trail that the guest was warned, and costs nothing to implement. It must be present even after automated moderation is added.

2. **Terms of Service acceptance gate.**
   Already implemented: `tos_accepted_at` is stored in `anon_upload_attributions`; upload is rejected server-side (`400`) if `tos_accepted !== true`. The ToS text (authored as a Phase 1a go-live blocker) should explicitly state that uploaded content must be directly related to the event, that the uploader confirms they have the right to upload the material, and that inappropriate content may be removed.

3. **Organizer-controlled moderation queue (hold-then-approve).** ← *Strongest current control*
   Rather than making uploads immediately visible to all attendees, hold them in a `pending` state accessible only to the organizer via `admin/event_qr.php`. The organizer can approve or reject each submission before it enters the shared gallery. This is the primary guard against explicit and off-topic content — it requires no external service, adds no cost, and gives the organizer final say over what their guests see. Design decision: whether to auto-approve after a configurable timeout (e.g. 24 h with no action) to reduce organizer burden for low-risk events.

4. **Organizer "report / remove" button on the public gallery page.**
   Even after initial approval, attendees should be able to flag content as inappropriate. The flag triggers re-review by the organizer. This is the lowest-cost human fallback and should be the baseline for both `GuestGalleryView` (iOS native, via `POST /api/guest-report.php`) and `guest_event_view.php` (web fallback) regardless of whether automated moderation is added.

5. **Display name required for shared gallery access.**
   Currently `display_name` is optional. If the shared gallery ships, make it required — anonymous uploads with no identifying information are harder to hold accountable. Auto-populate from the device name (`UIDevice.current.name` on iOS, e.g. "Scott's iPhone") so the field is pre-filled with something tied to the person's actual device, creating a soft social accountability signal without requiring account creation. Guests can change it, but the friction is a mild deterrent against casual abuse.

   In practice, most guests will see their device name pre-filled and move straight to the ToS checkbox and Upload — zero extra thought required. The name is visible in the upload form before submission, so the guest knowingly submits it; there is no surprise when it appears next to their video in the gallery. The honor system warning + ToS checkbox + display name field together create a deliberate moment of awareness that sets behavioral norms without blocking legitimate contributors. This is intentionally a **soft** accountability signal — guests can type any name they like. The real enforcement gate is the organizer moderation queue (item #3); the display name makes that queue human-readable and creates a mild social norm, not an identity guarantee.

6. **QR token time-to-live (TTL) limits the upload window.**
   Already implemented: tokens expire in 4 h / 24 h / 7 d / 14 d (admin-selectable). A short TTL (4 h – 24 h) limits the window during which a shared or leaked QR code can be used to upload content. Recommend defaulting to 24 h for most events rather than 7 d.

7. **Video creation-date metadata validation.**
   Extract the clip's `creation_date` on iOS via `AVAsset.creationDate` (returns a UTC-normalized timestamp) and optionally server-side via `ffprobe`. Clips recorded significantly before the event date are strong candidates for off-topic content.
   - **Default rule:** apply a ±1 day tolerance window from the event's start date — same calendar day is the primary target; ±1 day accommodates late-night events that cross midnight and minor device clock drift.
   - **Multi-day event flag:** add an `is_multi_day` boolean to the event QR admin page (`admin/event_qr.php`). When checked, pin the date check to the event's start date only (still ±1 day) but do not reject clips from subsequent days of the same engagement — the start date is the anchor, not a hard single-day ceiling. This is more reliable than inferring multi-day status from `event_type`, which is not always set consistently.
   - **Schema note:** `is_multi_day` is stored on the **`events`** table (consistent with `gallery_expires_at`). It is an event-wide property — an event is either multi-day or it isn't, regardless of how many QR codes were generated for it. Set by the admin at event load time alongside gallery lifespan.
   - **Action on mismatch:** silently flag for organizer review rather than hard-reject. The organizer moderation queue (#3) is the decision point; the date check is an input signal, not a gate.

8. **Automated content moderation.**
   A post-upload async job that scans video frames for explicit content before the asset enters the organizer's moderation queue. All detections feed flags into the queue — they do not auto-reject.

   **Phase 1 — Start here (free, self-hosted):**
   - **NudeNet v3** ([github.com/notai-tech/NudeNet](https://github.com/notai-tech/NudeNet)) — most capable free option. ONNX runtime (no GPU, no TensorFlow dependency), ~7 MB model, 18-label explicit content taxonomy (distinguishes exposed vs. covered body regions). For video: sample one frame every 2–5 seconds rather than every frame to keep compute cost proportional. Runs as a Python/FastAPI sidecar containerized alongside the existing Docker stack.
   - **NSFW.js** ([github.com/infinitered/nsfwjs](https://github.com/infinitered/nsfwjs)) — JavaScript model that can run client-side in the browser (or Node.js server-side) at zero server cost. Less accurate than NudeNet but useful as a fast pre-upload screen that catches obvious cases before the video is sent. Not a replacement for server-side scanning.

   **Phase 2 — Graduate to cloud if NudeNet detection rates are insufficient:**
   - **AWS Rekognition** video moderation: ~$0.10/min of video analyzed. Note: AWS announced deprecation of some batch and streaming moderation features for new customers as of April 2026 — verify current API availability before integrating.
   - **Azure AI Content Safety** (replaces the now-deprecated Azure Content Moderator): free tier 5,000 transactions/month (F0 SKU); standard tier ~$1.00/1,000 images. For video, extract frames and submit as images. Covers sexual, violence, hate, and self-harm categories with a 0–6 severity scale.
   - **Google Video Intelligence** explicit content detection: ~$0.10/min of video.
   - **Rough cost estimate for a mid-size event** (50 attendees × 3 clips × 3 min avg): ~450 min → ~$45/event on video-based cloud APIs. Frame-sampled image APIs are significantly cheaper for short clips.

   **CSAM / NCII:**
   - **PhotoDNA** (Microsoft): free for qualifying organizations via Microsoft's STOP CSAM program; requires application and approval. Perceptual hash matching against known CSAM databases. This layer is independent of and in addition to the NSFW classifier — they solve different problems.
   - **Scope note:** if the gallery is accessible only to contributors with approved uploads (see Gallery Access Model below), the CSAM exposure surface is dramatically reduced — content is organizer-reviewed before it reaches any other attendee. PhotoDNA is still worth pursuing as a mandatory baseline if any path to public or semi-public access exists.

9. **GPS / location metadata validation (weak signal — low priority).**
   If the event has a known venue lat/long and GPS coordinates are present in the video metadata, a clip recorded far from the venue is a soft indicator of off-topic content. However, efficacy is severely limited in this context: `PHPickerViewController` (which GigHive uses for video selection) strips location metadata from assets entirely as an OS-level privacy protection — the app never receives GPS coordinates through the picker. GPS will almost always be absent. If pursued, GPS data would need to be extracted server-side from video metadata via `ffprobe` (not from the iOS picker path). Treat as a soft signal only; never hard-gate. Absent GPS is the normal case, not a suspicious one.

---

## Gallery Page — `GuestGalleryView` (native iOS)

The in-app gallery is a native SwiftUI view (`GuestGalleryView.swift`), not a web page in `SFSafariViewController`. Reasons:

- **No browser chrome.** `SFSafariViewController` shows an address bar and Safari controls that break the app's UI consistency.
- **Native video playback.** The app already has `AVPlayer` / `MediaPlayerView` infrastructure; the gallery reuses it.
- **"New videos added" badge.** Communicating last-seen count between a web page and the app's polling state is fragile; native is straightforward.
- **Report flow.** A native `.alert` confirmation is cleaner than a web button. (`confirmationDialog` requires iOS 15+; the implementation uses `.alert` for iOS 14 compat.)
- **Navigation.** `SplashView` → `NavigationLink` → `GuestGalleryView` is natural; launching a browser is jarring.

### `GuestGalleryView` responsibilities

- Accepts `record: GuestUploadRecord` as input (provides `statusNonce`, `uploadJobId`, `eventName`, `baseURLString` — all needed within the view)
- On appear: calls `GET /api/guest-gallery.php?nonce=<record.statusNonce>`; server returns `403` for pending or rejected nonces — access is only granted once the nonce's own upload is approved. See implementation plan Step 11 for the full response contract.
- Renders each approved clip as a tappable thumbnail row; tapping opens full-screen `AVPlayer`. Videos are ordered by `upload_jobs.started_at ASC` — chronological capture order, giving the gallery a natural event-timeline feel.
- **Report button** per clip — guest can flag any approved video at the same event (not only their own); `POST /api/guest-report.php`; shows confirmation toast on `200`. See implementation plan Step 12 for the two-step cross-event validation.
- Shows `days_remaining` subtitle (e.g. "Available for 87 more days"); omit if indefinite
- Handles empty `videos` array defensively (not a reachable state via normal flow — if the nonce is approved, the nonce holder's own video is always in the result; see implementation plan Step 11): show *"You're in! No videos have been approved yet — check back as the organizer reviews submissions."* No video list
- Handles `expired` state gracefully: show "This gallery is no longer available" message, no video list

### Moderator interface — `admin/event_qr.php` (web, Section 3)

Moderation stays in `event_qr.php` as the existing **Guest Uploads** section. No separate PHP page is needed for MVP:

- The admin is already on this page managing the event and QR tokens — moderation is a continuation of that workflow.
- Event context (org name, event date) is already visible on the page.
- Avoids unnecessary navigation and page proliferation.

**Bulk actions are implemented:** an **Approve All Pending** / **Reject All Pending** button pair appears above the queue table whenever `pending_count > 0`. Reject All requires a browser confirm dialog. Both are POST forms (actions `approve_all_pending` / `reject_all_pending`) that UPDATE all pending rows for the event in a single query.

A dedicated `admin/event_qr_moderation.php` page is a future option if upload volumes grow large enough to make the combined page unwieldy (e.g. 50+ pending clips).

**Future feature — native iOS moderation:** the organizer should eventually be able to approve or reject pending clips from within the GigHive app without opening a browser. This would be a separate `OrganizerModerationView.swift` (not a repurposed `GuestGalleryView`) — different auth model (admin session vs guest nonce), different data scope (pending clips vs approved clips), and different actions (approve/reject vs report). Deferred; web portal is the moderator interface until event volume or organizer UX demands warrant it.

---

### `guest_event_view.php` — web fallback only

`guest_event_view.php` still needs to exist as a web-rendered page for:
- Guests who scanned the QR but do not have the GigHive app installed (Universal Link falls back to the web page)
- Non-iOS devices

It is **not** used by the iOS app. The iOS app calls `/api/guest-gallery.php` directly for data. Both the web page and the API endpoint authenticate via the same `status_nonce`.

See implementation plan Step 13 for the implementation approach (shared query include, `<video>` elements, HTML report form).

---

## Gallery Access Model — Contributor-Only

The shared gallery should **not** be publicly accessible to anyone with the QR code link. Access requires having submitted a video that has been **approved** by the organizer:

- **Why approved, not just submitted:** if access gates on submission alone, a bad actor submits one clip → gains gallery access immediately → sees other attendees' content before the organizer rejects the submission. Gating on approval means the organizer controls who enters the gallery, not just who uploads.
- **The UX flow aligns naturally:** guest uploads → waits 24–48 h for organizer review → receives in-app notification → accesses gallery. The moderation window is the access window.
- **Access token:** the upload `status_nonce` (see Guest Status Notification below) doubles as the gallery access credential. A guest with an approved nonce calls `/api/guest-gallery.php?nonce=…` (iOS native) or loads `guest_event_view.php?nonce=…` (web fallback) to retrieve the approved gallery for that event.
- **CSAM / liability impact:** this model means no content is ever visible to a second person before the organizer has reviewed it. The closed-loop contributor model dramatically reduces CSAM and inappropriate-content exposure compared to an open public gallery.

---

### How Apache grants guests access to `/video/`

All TUS-ingested video files are stored as `/var/www/html/video/<sha256>.<ext>` — the SHA-256 content hash is the filename for every upload. The `/video/` directory is protected by Basic Auth for admin, uploader, and viewer roles and is not publicly accessible.

Guest access to approved gallery videos uses **two separate paths** depending on the client:

- **iOS (`AVPlayer`):** the app fetches video through `/api/guest-stream.php?nonce=<statusNonce>&job_id=<id>` — a PHP streaming proxy. The proxy DB-validates the nonce on every request (nonce's own upload approved + gallery not expired + requested `job_id` belongs to the same event), then streams the file directly from disk with full `Accept-Ranges` / `Content-Range` support for AVPlayer seeking. The `/video/` Basic Auth layer is bypassed because the proxy itself sits in `/api/` (Apache-exempted). No custom HTTP headers are required from the iOS client.
- **Web browser (`guest_event_view.php`):** browsers cannot add custom headers to `<video src>` media requests. The nonce is appended as a query parameter: `<video src="/video/<sha256>.mp4?nonce=<statusNonce>">`. Apache detects it via a `SetEnvIf Request_URI` rule and passes the request through a `RequireAny` block (`Require valid-user OR env gallery_nonce_auth`) without requiring Basic Auth credentials.

The Apache `SetEnvIf X-Gallery-Nonce .+ gallery_nonce_auth` rule remains deployed and provides a path for any future client that sends the nonce as a header; it is not the iOS path in the current implementation.

Apache is a **forward gate only** for the web browser path — it checks for the presence of the nonce in the URL, not its validity. Actual validation for the web path occurred in `guest_event_view.php` before the page was rendered. The PHP proxy (`guest-stream.php`) is the iOS path's own validation gate. The SHA-256 filename is a second defence layer — computationally unguessable without possessing the original file.

Only `/video/` is affected. Guest uploads are **video only** (see Media Type Note above); `/audio/` requires no change and is not modified.

See implementation plan Phase 1 Step 2 for the `default-ssl.conf.j2` changes.

---

### Event and Org Isolation

**Why it matters:** guests from different events and organisations share the same server and the same `/video/` directory. A guest from a corporate event must not be able to retrieve videos from a wedding, and a guest from one organisation's event must not be able to reach another organisation's gallery — even if they hold a valid nonce.

**Mechanism — the nonce → token → event_id chain:** every `status_nonce` is stored in `anon_upload_attributions`, linked to a `token_id` in `event_upload_tokens`, which carries an `event_id`. This chain is the isolation boundary. Every gallery and report query derives `event_id` from that chain and uses it as a hard `WHERE` filter on the result set — a row from a different event simply cannot appear in the result.

- **`/api/guest-gallery.php`:** Step 1 resolves `event_id` from the nonce's token; Step 2 retrieves only videos where `event_upload_tokens.event_id = ?`. A guest from Event A receives exclusively Event A's approved videos.
- **`/api/guest-report.php`:** the target video's `event_id` must match the reporting guest's `event_id` — cross-event flagging is blocked at the SQL layer.
- **`/api/guest-status.php`:** returns only the status of the nonce's own upload — no cross-event data path exists.

**Apache layer — forward gate, not isolation gate:** Apache checks for the presence of a nonce signal, not which event it belongs to. A guest from Event A holding a valid nonce could in principle request `/video/<sha256>.mp4` for a file they don't have access to — Apache would pass the request through. The second defence is that SHA-256 filenames are computationally unguessable without possessing the original file, and the API never leaks filenames outside the `event_id` boundary. Together these two layers provide defence in depth: the SQL gate enforces isolation, the SHA-256 filename makes bypass infeasible in practice.

**Intentional non-isolation: tokens within the same event.** Multiple QR tokens for the same event (e.g. a 4 h token and a 24 h token generated for the same night) are deliberately not isolated from each other — they share a single gallery. The gallery query is event-scoped, not token-scoped. This is correct: all attendees of the same event should see the same gallery regardless of which QR code they scanned.

See implementation plan Notes section for the SQL constraints in each endpoint that enforce this boundary.

---

## Guest Status Notification

Guests upload without creating an account, which removes push notification and email as obvious channels. The notification mechanism must work without any persistent identity.

### UX message shown immediately after upload submission

Display this message on both `db/upload_form_single.php` (web) and `GuestUploadView` (iOS) after a successful upload:

> *"Your video is going into a queue for review. You will typically be notified in the app within 24–48 hours once the event organizer has reviewed your submission. After which, you will be able to see your video along with all the other videos individuals like yourself have captured during the event. Please return to the app within 24–48 hours to check on status."*

**Web nonce limitation:** the finalize JSON response returns `status_nonce` to whatever client made the request. On the web path (`db/upload_form_single.php`), there is no equivalent of `UserDefaults` — the nonce is in the response but the web page has no persistent storage to track it. Web uploaders cannot check their approval status or access the gallery without the app. This is a known limitation of the accountless model; the post-upload message's instruction to "return to the app" is the only recourse for web uploaders.

### Status nonce — the identity anchor

Since there is no account, the finalize response must return a `status_nonce` — a random 192-bit URL-safe base64 token generated at upload finalization, stored in `anon_upload_attributions`, and returned to the iOS client alongside `upload_job_id` (the INT `upload_jobs.id`, not the TUS UUID VARCHAR). See implementation plan Step 4 for the full `finalizeTusUpload` changes including INSERT ordering and `lastInsertId()` timing.

**App-kill recovery:** if the app is killed between the TUS upload completing and the finalize response arriving, the `status_nonce` is never stored. The guest has no recovery path (the video exists on the server but is untracked on the device). Mitigation: the iOS finalize call should be retried on next app launch if a TUS fingerprint is found in a "completed but not finalized" state. Documenting this as a known gap; full recovery logic is deferred.

The iOS app stores nonce(s) in `UserDefaults` as a `GuestUploadHistory` array of `GuestUploadRecord` structs — see implementation plan Phase 2 Step 1 for the full struct definition and persistence helpers.

### Status polling on app open

Every time the app launches (in `GigHiveApp` or `SplashView.onAppear`), poll all `GuestUploadRecord` entries where `approvalStatus` is `pending` or `approved` (i.e. skip `rejected` and `expired` — those are terminal). See implementation plan Step 10 for the status endpoint response contract.

- Endpoint is unauthenticated but nonce is 192 bits of entropy — not guessable.
- The nonce does not expire (unlike the QR upload token); it must remain valid as long as the upload record exists.
- If status changed to `approved`: update local record (`approvalStatus`, `daysRemaining`, `lastSeenVideoCount`) **before** showing the banner to prevent duplicate banners on rapid re-entry. Then show the in-app banner with **"View Event Gallery"** button.
- For already-`approved` records: update `daysRemaining` and `lastSeenVideoCount` from every poll response — the stored values go stale over time. If `video_count` increased since last visit, set the "New videos added" badge on the "Your Event Galleries" entry.
- If status changed to `rejected`: update local record, show a brief non-alarming message (e.g. "Your video from StormPigs was not added to the gallery."). Stop polling this record.
- **Stop polling** once status is `rejected` or `expired`. **Continue polling** `approved` records on each app open to detect `video_count` increases (Phase 3 step 14 "New videos added" indicator) and to catch `expired` transitions.

### APNs push (supplement, not replacement)

If the guest grants push notification permission after uploading, store the APNs device token in `anon_upload_attributions.apns_token`. When the organizer approves the video, trigger a push notification server-side. This is a nice-to-have — polling on app open is the reliable baseline since APNs tokens can rotate and push delivery is not guaranteed.

### Schema additions required

Nine new columns across three tables. Full DDL with ordering constraints and rationale comments is in the implementation plan Phase 1 Step 3. Summary:

| Table | New columns |
|---|---|
| `anon_upload_attributions` | `status_nonce` (UNIQUE NOT NULL), `apns_token` |
| `events` | `gallery_expires_at`, `is_multi_day` |
| `upload_jobs` | `label`, `file_relpath`, `moderation_status`, `approved_at`, `guest_flagged`, `guest_flagged_at`, `guest_deleted`, `guest_deleted_at` |

`moderation_status = NULL` means not a guest upload (manifest imports). `moderation_status = 'pending'` is set at INSERT time in `finalizeTusUpload`. `approved_at` is set by the admin approve handler and returned in the gallery API response. `guest_deleted = 1` is set by the guest self-delete endpoint; `moderation_status` is never changed by deletion — this is intentional to preserve gallery access (see Guest Self-Delete below).

---

## Guest Self-Delete

> **Infrastructure complete and deployed to dev — 2026-07-08.** iOS UI (Step 6) pending.

A guest who uploaded a video should be able to remove their own clip from the event gallery — for example, if they captured something they later decided they didn't want visible. Deleting a video must not revoke the guest's privilege to view the gallery for that event.

### Design principle

**Soft delete only.** `upload_jobs.guest_deleted` is set to `1`; the physical file (`/video/<sha256>.<ext>`) is never removed from disk. `moderation_status` remains `'approved'` — this is the key to preserving gallery access.

**Why `moderation_status` must not change:** `guest-gallery.php` grants gallery access in a two-step query:

- **Step 1 (access gate):** verifies the nonce's own upload has `moderation_status = 'approved'`. This step intentionally does **not** check `guest_deleted`, so a guest who deleted their video still passes the gate and retains full gallery viewing access.
- **Step 2 (results):** fetches approved videos with `AND j.guest_deleted = 0`, hiding the deleted clip from the result set.

The same `AND j.guest_deleted = 0` filter is applied to `guest-stream.php` (streaming a deleted video returns `403`) and the `video_count` subquery in `guest-status.php` (the badge count excludes deleted videos).

**Ownership enforcement.** Only the uploader can delete their own video. `POST /api/guest-delete.php` joins `upload_jobs` with `anon_upload_attributions` and requires both `j.id = <upload_job_id>` (from the iOS client) and `a.status_nonce = <nonce>` to match the same row. Any attempt to delete another guest's video returns `403`.

**Pre-approval deletion is not supported.** The delete endpoint requires `moderation_status = 'approved'` — the same access gate as `guest-gallery.php`. A guest with a `pending` or `rejected` upload cannot delete via this endpoint in MVP.

### iOS UI

In `GuestGalleryView`, each video row's button order is:

```
[content] — Spacer — [▶ play.circle.fill] — [🚩 flag] — [✕ xmark]
```

The `xmark` button (red) is only rendered on the row where `video.uploadJobId == record.uploadJobId` (the guest's own upload). Tapping shows a confirmation `.alert` (iOS 14 compat — not `confirmationDialog`):

> **Delete your video?**
> This removes your clip from the gallery. You'll still have access to view other videos.
> [Cancel] [Delete]

On confirmation, the row disappears from the local list immediately (client-side `deletedIds` filter); no gallery reload is required. The "Your Event Galleries" entry on `SplashView` continues to show because the guest retains access.

### Admin visibility

Deleted videos remain visible in the admin moderation queue in `admin/event_qr.php` with a muted "🗑 Deleted by guest" badge. The physical file is retained on disk. No admin un-delete button exists in MVP; restoring a guest-deleted video requires a direct database update (`UPDATE upload_jobs SET guest_deleted = 0 WHERE id = ?`).

---

## Release Gating — Three Phases

### Phase 1 — MVP (gallery ships to first real events)

**Required safety items:** 1 (honor system warning), 2 (ToS gate), 3 (organizer moderation queue), 4 (report button). Items 5 (display name required) and 6 (QR TTL) are strongly recommended and low-effort.

**New external software required: none.** The entire Phase 1 implementation is pure PHP, MySQL schema additions, and Swift — all within the existing Docker/Ansible stack.

See `docs/feature_iphone_qr_code_shared_gallery_implementation.md` for the full sequenced implementation plan (Phase 1: infrastructure/PHP; Phase 2: iOS; Phase 3: production Universal Links launch).

### Phase 2 — Beta promotion (before sharing beyond low-risk / known-audience events)

**Required safety items:** 7 (video creation-date validation, gated by `is_multi_day` flag) and 8 Phase 1 (NudeNet automated moderation running as a post-upload async job, feeding flags into the organizer queue).

**New external software required: NudeNet v3** — a Python/FastAPI sidecar container using ONNX runtime (~7 MB model, no GPU). Deployed alongside the existing Docker stack via Ansible. This is the only net-new software dependency introduced by this entire feature.

### Phase 3 — Scale (when organizer manual review becomes the bottleneck)

Layer cloud moderation APIs (AWS Rekognition, Azure AI Content Safety, Google Video Intelligence) on top of NudeNet. Pursue PhotoDNA registration in parallel for CSAM coverage. All are pay-per-use; no self-hosted software required beyond what Phase 2 introduced.

---

## Deferred

- **Rate limiting per QR token.** Capping uploads per token was initially considered as an abuse prevention measure. Deferred because a hard per-person limit would penalize legitimate avid videographers — a dedicated fan or family member capturing extensive footage of a concert or wedding is exactly the kind of contributor the shared gallery is meant to serve. If abuse patterns emerge in practice (bulk spam uploads), revisit with a higher ceiling (e.g. 20–50 clips) rather than a restrictive one.
- **GPS / location metadata validation** (item 9 above) is documented for completeness but is low priority given that GPS is stripped by `PHPickerViewController` on the iOS path. Revisit only if a future GigHive recording feature embeds GPS directly in captured footage.
