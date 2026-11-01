# Reminder: Remove NetworkProgressUploadClient.swift

After the iPhone app has fully cut over to TUS uploads and the implementation is verified stable in real usage (including >100MB uploads), remove the legacy multipart upload implementation:

- `GigHive/Sources/App/NetworkProgressUploadClient.swift`

Rationale:
- It implements the legacy single-request `multipart/form-data` upload path which can exceed Cloudflare’s 100MB per-request limit and is no longer part of the intended upload architecture.
- Progress reporting will be provided by the TUS upload implementation instead.

Deletion timing:
- Keep temporarily during the initial rollout for reference and to reduce risk.
- Remove once TUS upload + finalize is working end-to-end in the target environments.
