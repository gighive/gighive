# Duplicate Upload Policy — Cross-Event Linking Allowed

Date: 2026-05-31

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

## Rationale

This design was settled in two places:

1. **`docs/questions_for_librarianAsset_musicianSession_decision.md`** — early decision: "Reject duplicate uploads globally when `checksum_sha256` already exists." Cross-event linking was placed in the backlog: "consider allowing reuse/linking of an existing asset into a different event if clientele asks for it."

2. **`docs/pr_librarianAsset_musicianEvent_completed.md`** — implementation refinement: "decide what happens if the same user tries to upload the same binary into a different Event: This should succeed as a **new link**, not as a 'reject upload entirely'."

The implementation doc supersedes the early global-reject framing for the cross-event case.

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
