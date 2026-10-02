---
description: HEVC videos selected from Photos can be auto-transcoded to H.264 by PHPicker, changing uploaded bytes and defeating SHA-only duplicate detection
---

# Problem: Photos Picker Video Transcode Behavior Changes Uploaded SHA

## Summary

When GigHive uploads a video selected from the iPhone Photos library via `PHPickerViewController`, the uploaded file bytes do not always match the original asset bytes stored in Photos.

For HEVC source videos, the current picker configuration allows iOS to supply a compatible H.264 representation during `loadFileRepresentation(...)`. Because GigHive computes duplicate detection using the SHA-256 of the uploaded bytes, this compatibility transcode can cause repeated uploads of the same human-visible video to produce different checksums.

In contrast, H.264 source videos appear not to require this compatibility transcode in the current flow, so repeated uploads have stable uploaded bytes and duplicate detection works as expected.

This means SHA-only duplicate detection is currently reliable for H.264-origin videos but can be unreliable for HEVC-origin videos selected from Photos.

## Impact

- duplicate uploads of the same human-visible HEVC-origin video can be accepted into the same event gallery
- event-level duplicate prevention is less reliable for Photos-selected HEVC source assets than for H.264 source assets
- the issue can create user confusion because the uploaded videos look identical while the server sees distinct `checksum_sha256` values
- changing the picker to preserve original HEVC bytes would likely trade away smaller uploads and broad browser playback compatibility

## Symptoms

### HEVC source asset selected from Photos

Observed behavior:

- The original asset in Photos is HEVC.
- GigHive uses `PHPickerViewController` and `NSItemProvider.loadFileRepresentation(...)`.
- The server receives an H.264 file.
- Repeated uploads of the same source video can produce different `checksum_sha256` values.
- The server therefore treats them as distinct uploads and allows duplicates into the same event gallery.

### H.264 source asset selected from Photos

Observed behavior:

- The original asset in Photos is already H.264.
- The upload path does not appear to require a compatibility transcode.
- Repeated uploads preserve the same uploaded bytes.
- The server sees the same `checksum_sha256` and correctly rejects the duplicate.

## Root Cause

GigHive’s iPhone picker code currently uses `PHPickerConfiguration()` without explicitly setting `preferredAssetRepresentationMode`.

That means the picker uses the default `.automatic` representation mode.

Prior research in this repo and Apple documentation indicate:

- `.automatic` allows the system to choose a compatible representation.
- For HEVC source videos, that often means iOS exports H.264 during `loadFileRepresentation(...)`.
- `.current` is the mode that avoids transcoding, if possible.

GigHive itself does not appear to run its own explicit HEVC→H.264 conversion step before upload. The conversion appears to happen in the Photos picker representation pipeline.

## Evidence Collected

### Code path

Current picker implementation:

- `GigHive/Sources/App/PickerBridges.swift`
- `PHPickerConfiguration()`
- `config.filter = .videos`
- `config.selectionLimit = 1`
- no explicit `preferredAssetRepresentationMode`
- `loadFileRepresentation(forTypeIdentifier: UTType.movie.identifier)`

### Server-side behavior

Server duplicate detection is based on the SHA-256 of the uploaded file bytes.

Relevant behavior:

- the server computes `hash_file('sha256', ...)` on the uploaded file bytes
- duplicate rejection happens only when the uploaded checksum matches an existing asset already linked to the same event

### Observed ffprobe characteristics

For duplicated HEVC-origin uploads, server-side files were observed as:

- H.264 video
- same duration
- same resolution
- same approximate bitrate
- same visible content
- but differing container metadata such as `creation_time`

That is enough to produce different SHA-256 values even when the video looks identical to a person.

## Resolution

Current decision:

- keep the current H.264-compatible picker behavior
- do not treat this as a high-priority immediate fix
- if/when this is addressed, prefer Option A by adding additional duplicate signaling beyond uploaded SHA

This preserves the original product goals of smaller uploads and broad browser playback compatibility while acknowledging the HEVC duplicate edge case.

## Verification

This problem was verified with a combination of code inspection, Apple documentation, and observed upload behavior.

### What was checked

- `GigHive/Sources/App/PickerBridges.swift` picker configuration and `loadFileRepresentation(...)` usage
- server-side upload hashing and duplicate-rejection behavior
- prior repo docs describing `PHPicker` transcoding behavior
- ffprobe output from uploaded server-side files
- control testing with both HEVC-origin and H.264-origin source videos

### What was observed

- the original Photos asset for the problematic case was confirmed to be HEVC
- the uploaded server-side files for that case were observed as H.264
- repeated uploads of the same HEVC-origin source video produced different `checksum_sha256` values
- repeated uploads of an H.264-origin source video preserved the same `checksum_sha256` and triggered normal duplicate rejection

## Prior Rationale for the Current Design

The repo already documents why GigHive kept the default picker behavior instead of forcing original-format preservation.

### Why we are not requesting Photos permission or switching to a PhotoKit/original-resource path

The key distinction is that **Photos permission by itself is not the lever that changes this behavior**.

Today, GigHive's guest upload flow uses `PHPickerViewController` with the default representation behavior and does **not** request full Photos library authorization. In this design, iOS may provide a compatible representation during `loadFileRepresentation(...)`, which is the behavior that often yields H.264 output for HEVC source assets.

If GigHive merely added Photos permission but otherwise kept the same picker-centered import path, that would not materially change the current representation/export behavior. The change that would matter would be a design switch away from the current picker flow and toward a PhotoKit/original-resource retrieval path, such as preserving the current/original asset representation more aggressively.

We are intentionally staying away from that path for the current product because it would work against several documented goals:

- smaller uploads
- broader browser playback compatibility
- better odds of staying under upload-size limits
- more predictable delivery behavior for mixed client environments

In other words, the project is not avoiding Photos permission as an isolated privacy preference only. It is avoiding the broader architectural shift that would likely accompany full PhotoKit/original-resource retrieval, because that shift would trade away the current H.264-compatible, size-conscious upload behavior that GigHive presently prefers.

An additional product benefit of the current approach is that it reduces the number of things the user has to do. Guests can pick a video from Photos and continue through a simpler upload flow without extra permission prompts or original-format decisions.

Browser compatibility is also a top priority in this decision. Even if preserving original HEVC resources could improve source-byte fidelity, that would be less valuable to the product than keeping uploads easy to complete and playback reliable across the widest possible range of browsers and client devices.

This approach may reduce some source characteristics in certain cases, including cases where the compatible representation is lower fidelity than the original asset. For now, GigHive is accepting that tradeoff and will monitor how noticeable any practical resolution or quality loss becomes as real-world usage accumulates.

Primary rationale from `docs/app_video_picker_transcoding_method.md`:

- smaller file sizes make uploads more feasible
- H.264 has universal compatibility
- files are more likely to stay under the 6GB upload limit
- Chrome and other browsers handle H.264 better

Related rationale from the iPhone import/catalog docs:

- GigHive can ingest HEVC without modification
- browser playback of HEVC depends on client codec support
- HEVC playback is especially weak in Chrome on Windows
- browser preview/download UX is better when files are already H.264-compatible

This means the current behavior was a deliberate tradeoff in favor of:

- smaller uploads
- broader playback compatibility
- more predictable browser behavior

## Why This Is Not an Immediate Must-Fix

This issue is real but currently bounded:

- it primarily affects HEVC-origin videos selected from Photos
- H.264-origin uploads still dedupe correctly
- the current behavior still preserves the original product goals of smaller uploads and broad browser playback compatibility

At the moment, this appears to be a manageable edge case rather than a platform-wide ingestion failure.

## Path Forward Options

### Option A — Keep current H.264-compatible picker behavior and add additional duplicate signaling

Keep the current `.automatic` picker behavior so HEVC videos continue to be converted to browser-friendly H.264 uploads when Photos decides that is appropriate.

Then add a second duplicate-detection signal beyond uploaded-file SHA.

Possible signals include:

- Photos asset identifier if obtainable from the picker flow
- media creation timestamp
- duration
- dimensions
- device-origin metadata
- other stable source-asset identity or metadata hints

Pros:

- preserves the original rationale for the current design
- keeps upload sizes smaller
- preserves strong browser playback compatibility
- addresses the real duplicate problem without switching delivery format

Cons:

- duplicate detection becomes more complex than pure SHA matching
- may require heuristic or source-identity logic
- needs careful event scoping to avoid false positives

Current leaning:

- **This is the leading option.**
- We are leaning toward Option A with additional signaling.

### Option B — Preserve original HEVC bytes in the Photos picker flow

Switch the picker to request the current/original representation where possible, for example by using `.current` instead of the default `.automatic` behavior.

Pros:

- uploaded bytes remain closer or identical to the original asset
- SHA-based duplicate detection becomes more reliable for HEVC videos
- preserves original codec and quality

Cons:

- larger uploads
- worse browser playback compatibility for HEVC
- increased chance of crossing upload-size limits
- undermines the original rationale for choosing browser-friendly H.264 output

### Option C — Dual-track design: preserve original identity while continuing to serve H.264-compatible media

Treat source identity and delivery format as separate concerns.

Possible approach:

- preserve enough metadata or source identity to dedupe by original asset
- continue storing or serving an H.264-compatible representation for playback

Pros:

- strongest long-term conceptual model
- clean separation between dedupe identity and browser delivery needs
- avoids relying purely on transcoded output bytes for identity

Cons:

- significantly larger design and implementation scope
- likely schema and pipeline changes
- not justified as an urgent fix right now

### Option D — Add an advanced or optional original-format mode

Keep the current default behavior, but allow a future opt-in path for preserving original HEVC uploads.

Pros:

- retains today’s default UX
- gives advanced users a path to preserve originals
- lower-risk than changing the default for everyone

Cons:

- does not solve the duplicate problem for the normal default flow
- adds product and UI complexity
- still requires separate duplicate strategy if default remains `.automatic`

## Preventative Actions

- keep this behavior documented so future duplicate investigations do not assume SHA changes imply server-side corruption
- if duplicate reports become frequent, prioritize Option A and add a second event-scoped duplicate signal beyond uploaded-file SHA
- preserve the current rationale in docs so future picker changes weigh browser compatibility and upload-size constraints explicitly

## Current Direction

For now, the preferred direction is:

- keep the current H.264-compatible picker behavior
- do not treat this as a high-priority immediate fix
- if/when we address it, pursue **Option A** by adding additional duplicate signaling beyond uploaded SHA

This best matches the original product goals while reducing the specific duplicate edge case for HEVC-origin uploads.

## Related Documents

- `docs/app_video_picker_transcoding_method.md`
- `docs/rearch_prepare_video_cancel.md`
- `docs/feature_iphone_upload_catalog.md`
- `docs/feature_iphone_upload_catalog_caveats.md`
- `docs/feature_iphone_upload_catalog_reservations.md`
