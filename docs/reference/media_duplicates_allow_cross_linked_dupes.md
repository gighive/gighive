# Duplicate Upload Policy — Cross-Event Linking Allowed

Date: 2026-05-31

---

## Rationale

**Deduplication is about storage and binary identity — not about catalog membership.**

One file = one asset row = one copy on disk. But a single piece of media can have legitimate editorial relationships to more than one event. Blocking the second upload would force users to work around the system (e.g. rename the file to strip the checksum match) to achieve what is a valid cataloging action.

---

## Customer Journey Use Cases

### Musician / Wedding Event Media Producer

These users upload raw capture footage tied to a specific event. Scenarios where the same binary legitimately belongs to more than one event:

- **Multi-day festival or multi-set event** — Day 1 and Day 2 are separate events in the system. A soundcheck clip, venue B-roll, or intro video is legitimately cataloged under both event dates.
- **Band collaboration** — Two bands share a stage; one video file belongs to both bands' event records (different `org_name`).
- **Wedding ceremony + reception split** — Ceremony and reception are tracked as separate events; a getting-ready clip or shared highlight reel applies to both.
- **Correction upload** — User uploaded to the wrong org name first, then re-uploaded to the correct event. The system accepting the second upload is correct behavior; the wrong-org link can be cleaned up separately.

### Media Librarian

These users are organizing files already on disk — not capturing live. Cross-linking is a natural cataloging operation:

- **Same master file, multiple catalog contexts** — A recording belongs under both the venue's catalog entry and the band's catalog entry.
- **Highlight / compilation file** — A year-end recap video draws from multiple events and should appear in each event's asset list.
- **Legacy folder structure** — The same physical file was previously stored in two different client folders. Both folder paths are valid catalog entries, but it is one binary.
- **Shared promo or intro clip** — A recurring promo or title card is reused across dozens of events. One asset row; many event links.

---

## Decision History

This design was settled in two places:

1. **`docs/questions_for_librarianAsset_musicianSession_decision.md`** — early decision: "Reject duplicate uploads globally when `checksum_sha256` already exists." Cross-event linking was placed in the backlog: "consider allowing reuse/linking of an existing asset into a different event if clientele asks for it."

2. **`docs/pr_librarianAsset_musicianEvent_completed.md`** — implementation refinement: "decide what happens if the same user tries to upload the same binary into a different Event: This should succeed as a **new link**, not as a 'reject upload entirely'."

The implementation doc supersedes the early global-reject framing for the cross-event case.

---

## Summary

Uploading the same binary file (identical SHA-256 checksum) is **not globally blocked**. Whether the second upload is accepted or rejected depends on whether it targets the same event or a different event.

---

## Policy

| Scenario | Behavior |
|---|---|
| Same binary → same event | Deduplicated: existing asset row reused; no second DB row created; friendly message shown |
| Same binary → different event | **Accepted**: new event link created; friendly "already exists" message shown; no new asset row |

---

## Concrete Example

A user uploads `gig_footage.mp4` via the iPhone app with band name **Jenny**. They then upload the same file again with band name **Boat**.

- Jenny and Boat are different `org_name` values → different events.
- The second upload **succeeds**.
- Only one `assets` row exists (one binary, one checksum).
- Two `event_items` rows exist — one linking the asset to the Jenny event, one to the Boat event.
- The iPhone app shows a post-upload dialog noting that the file was already on the server.

---

## What the "duplicate" dialog means

When the iOS app shows a duplicate message after a successful upload, it means:

- The TUS upload and finalize call completed successfully (HTTP 200 or 201).
- The finalize response does **not** include a `delete_token` (tokens are only minted for net-new asset rows, not for cross-event links on an existing asset).
- The asset was linked to the new event, not inserted a second time.

This is expected behavior, not an error.

---

## If you want to block cross-event re-uploads

The current design explicitly allows cross-event linking. Blocking it would require a new design decision reversing the "new link" behavior in `pr_librarianAsset_musicianEvent_completed.md`. This is currently **not planned**.

---

## Related docs

- `docs/questions_for_librarianAsset_musicianSession_decision.md` — original global-reject decision + backlog note
- `docs/pr_librarianAsset_musicianEvent_completed.md` — implementation refinement allowing cross-event links
- `docs/pr_delete_for_uploader.md` — explains why deduped uploads do not receive a `delete_token`
- `docs/pr_delete_upload_iphone.md` — iOS "My uploads on this device" section and behavior when no token is returned
