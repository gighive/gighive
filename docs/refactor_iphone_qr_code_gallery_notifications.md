# Refactor: GuestGalleryView — Notifications & Per-Nonce Data

## Background

Each QR code upload creates a separate `GuestUploadRecord` with its own `statusNonce`
and `uploadJobId`. The splash screen deduplicates multiple records for the same event
into one gallery row, picking the first approved record. This means the active gallery
record's nonce is only one of potentially many nonces for that event.

Several bugs arose from code that operated on only the active record's data instead of
the full set of records for the event. All were fixed; this doc tracks the remaining
refactor opportunity.

---

## Bugs Fixed (already in code)

| Bug | Root Cause | Fix Applied |
|-----|-----------|-------------|
| "New videos" splash badge persists after viewing gallery | `lastSeenVideoCount` updated only for the active nonce; other nonces still had lower count, so next poll re-triggered the badge | `loadGallery` now updates `lastSeenVideoCount` on all event records |
| "New" per-video badge resets after app restart / new upload | `viewedUploadJobIds` stored per nonce; if deduplication switched to a different nonce after a new upload, viewed history was empty | `loadGallery` unions `viewedUploadJobIds` across all event records; `markViewed` writes to all event records |
| Delete X only on most-recently-uploaded video | `video.uploadJobId == record.uploadJobId` checks only the single active record | `ownUploadIds` state built from union of all event records' `uploadJobId` |
| "Gallery access could not be verified" when deleting older video | `performDelete` used `record.statusNonce` (gallery view nonce) for all deletes; server validates nonce matches the uploader | `performDelete` looks up the correct nonce for each `uploadJobId` before calling the delete API |

---

## Remaining Refactor Opportunities

### 1. Extract `updateAllEventRecords(_:)` helper  (HIGH VALUE)

`loadGallery` and `markViewed` both do the same pattern:

```
GuestUploadRecord.load()
→ iterate
→ filter by baseURLString + eventName
→ mutate one field
→ GuestUploadRecord.save(allRecords)
```

Extract into a shared helper:

```swift
private func updateAllEventRecords(_ modify: (inout GuestUploadRecord) -> Bool) {
    var all = GuestUploadRecord.load()
    var dirty = false
    for i in all.indices
        where all[i].baseURLString == record.baseURLString
           && all[i].eventName == record.eventName {
        if modify(&all[i]) { dirty = true }
    }
    if dirty { GuestUploadRecord.save(all) }
}
```

Usage in `loadGallery`:
```swift
updateAllEventRecords { r in
    guard r.lastSeenVideoCount != vc else { return false }
    r.lastSeenVideoCount = vc
    return true
}
```

Usage in `markViewed`:
```swift
updateAllEventRecords { r in
    guard !r.viewedUploadJobIds.contains(uploadJobId) else { return false }
    r.viewedUploadJobIds.append(uploadJobId)
    return true
}
```

### 2. Remove `let _ = logWithTimestamp(...)` from ViewBuilder  (MEDIUM)

Side-effecting expressions inline in a `@ViewBuilder` body fire on every re-render.
The row logging should be removed once debugging is confirmed complete, or moved into
an `.onAppear` on the row.

### 3. `loadGallery` does too many things  (LOW / optional)

Could split into:
- `refreshViewState(from: [GuestUploadRecord])` — sets `viewedIds`, `ownUploadIds`
- `persistLastSeen(vc: Int)` — updates `lastSeenVideoCount` across all event records

Probably only worth doing if `loadGallery` grows further.

### 4. `VideoPlayer` init logging  (LOW)

`logWithTimestamp(...)` in `VideoPlayerView.init` fires every SwiftUI re-render that
reconstructs the destination. Remove once debugging is done.

---

## Design Principle Going Forward

> Any data read or written inside `GuestGalleryView` that should persist across
> app restarts must be scoped to the **event** (`baseURLString + eventName`), not
> to a single nonce. The deduplication in `SplashView` can surface any approved
> record for that event as the gateway, so per-nonce storage is fragile.
