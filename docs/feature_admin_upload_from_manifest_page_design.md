# Feature Design: admin_media_import.php

## Summary

This document captures the agreed design for a new browser-based media import workflow page:

- `admin_media_import.php`

The goal is to replace the current manual Part II shell/Python workflow with a manifest-aware browser experience while preserving the existing manifest/hash generation model.

The page hosts both steps of the workflow:

- Step 1: Create Manifest and Hashes
- Step 2: Upload Media

The page is intentionally planned as a new dedicated workflow page. Changes to `admin.php` are explicitly deferred until after this page exists and is working.

## Scope for first implementation

Build a new page:

- `admin_media_import.php`

Do not edit `admin.php` yet.

Deferred follow-up task:

- after `admin_media_import.php` is implemented and working, add an entry point from `admin.php`

## Authentication and authorization

`admin_media_import.php` and all supporting backend endpoints are restricted to `admin` users only.

This is consistent with the existing manifest endpoints (`import_manifest_status.php`, `import_manifest_jobs.php`, `import_manifest_cancel.php`) which use the same pattern:

```php
if ($user !== 'admin') { http_response_code(403); exit; }
```

## Page structure

The new page has two separate workflow sections:

- `Reload Database from Folder (destructive)`
- `Add to Database from Folder (non-destructive)`

Each section has two buttons:

- `Create Manifest and Hashes`
- `Upload Media`

The numbered section labels (Section 4, Section 5) from `admin.php` are intentionally dropped on this standalone page. Descriptive names are used instead.

## High-level workflow

The new page preserves a two-part workflow:

1. Create manifest and hashes
2. Upload media using the manifest context created in step 1

The key design decision is that the second step must be manifest-aware and tied to the backend job created by step 1.

## Canonical job identity

The canonical identifier for the workflow is:

- `job_id`

For step 2, `job_id` alone is the canonical input.

The backend already persists manifest jobs under:

- `/var/www/private/import_jobs/<job_id>/`

Current persisted manifest job files/patterns already include:

- `meta.json`
- `manifest.json`
- `status.json`
- `result.json`
- `cancel.json`

The manifest job and the upload job share the same `job_id` and the same job directory. Upload-specific artifacts are added alongside the existing manifest artifacts. The `job_id` is the implicit join key between manifest and upload state — no separate linkage field is needed.

This existing persisted job-status pattern should be followed for the new upload workflow wherever possible.

## Source of truth

The current source of truth for step 2 is:

- `manifest.json`

Unless a future reason appears to change this, the new page should continue to follow that pattern.

## Step 1: Create Manifest and Hashes

Step 1 remains the first action in each section.

Its responsibilities are:

- scan the selected folder
- find supported media files
- compute SHA-256 checksums
- generate/import manifest metadata
- persist manifest job state

### JavaScript state machine migration

The existing Step 1 logic in `admin.php` is driven by two JavaScript state machines:

- `_scanFolderImportRunState` — for the destructive reload section
- `_scanFolderAddRunState` — for the non-destructive add section

Each state machine manages `idle`, `hashing`, `uploading`, `stopping` states and handles UI transitions, progress polling, cancellation, and error handling.

Migrating these state machines to `admin_media_import.php` is a first-class part of Phase 1 implementation work, not an afterthought.

Step 2 will introduce additional upload state machine logic alongside the migrated Step 1 state machines.

### Duplicate hash handling

`checksum_sha256` remains the authoritative key.

However, `source_relpath` must still be shown to the user for reassurance and human verification.

If multiple files in the selected folder have the same checksum, step 1 must not silently continue.

Instead, before the duplicate resolution dialog is shown:

- the pending hashing job is saved to the server under the same job directory
- a new file `duplicates.json` is written containing all duplicate checksum groups and their associated `source_relpath` values
- the job state is set to `awaiting_duplicate_resolution`

Only then is the user shown the duplicate resolution dialog asking:

- `Which one should we keep?`

Meaning:

- which duplicate file path should be retained as the canonical manifest entry for that checksum

Only the chosen file should survive into the finalized manifest for that checksum.

Step 1 is not complete and the manifest is not submitted to the server until all duplicate checksum groups have been resolved by the user.

#### Duplicate resolution recovery

If the user closes the browser before resolving duplicates, the pending job with `awaiting_duplicate_resolution` state is already persisted on the server.

When the user returns to the page:

- the pending job is preselected via the recovery controls
- the page shows the duplicate resolution dialog directly from the saved `duplicates.json`
- the user does not need to re-select their local folder
- the user simply resumes from where they left off

## Step 2: Upload Media

Step 2 is the new browser-driven portion of the workflow.

It must be driven by the manifest job created in step 1.

### Canonical linkage

Step 2 uses:

- `job_id`

The page/backend then reads:

- `manifest.json`

for expected files and workflow context.

### Matching strategy

The authoritative match key is:

- `checksum_sha256`

`source_relpath` is still displayed for user reassurance and context, but checksum is the authoritative matching key.

This keeps matching simple, robust to file renames, and aligned with current checksum-based storage/deduplication behavior.

### Upload transport

The agreed upload transport for step 2 is:

- `TUS`

This choice was made because:

- uploads may run for a long time
- jobs may continue overnight
- browser crashes/reloads/reconnects must be recoverable

### Completion rule

A file is only considered complete after all required post-processing is satisfied:

- media upload is satisfied
- thumbnail handling is satisfied
- database update is satisfied

### Status model

The agreed user-facing states include:

- `pending`
- `matched_locally`
- `uploading`
- `uploaded`
- `already_present`
- `thumbnail_done`
- `db_done`
- `failed`

Internally, `already_present` should be differentiated by artifact type when needed.

Recommended internal breakdown:

- media state
- thumbnail state
- db state

This is important because these are distinct layers of completion.

A file can be:

- already present as media
- missing thumbnail
- not yet finalized in DB

So artifact-specific state should be preserved internally even if a simpler summary is shown in the UI.

### already_present display

User-facing display rules for `already_present`:

- **Fully complete** (media + thumbnail + DB all satisfied): show `Already present`
- **Incomplete** (media present but one or more artifacts missing): show inline what is missing, e.g. `Already present — missing: thumbnail, db` or `Already present — missing: db`
- **Detail view**: an inline toggle that expands to show per-artifact states (media / thumbnail / DB). This is not a separate page — it is hidden text toggled in place on the same row.

### Post-upload state: thumbnail_done and db_done

After a TUS upload completes, the server performs post-processing:

1. generate the thumbnail
2. update the database record

These are server-side operations. The browser cannot know when they complete without polling.

The browser learns `thumbnail_done` and `db_done` states by polling the upload job status endpoint, following the same polling pattern as the existing Step 1 manifest job status polling.

The upload job status endpoint must expose per-file states so the browser can update the UI item-by-item as each file's post-processing completes.

### Resume behavior

When a user resumes an upload job after a crash or browser close, the upload batch must not restart from the beginning.

On resume:

- each manifest entry is checked against the persisted `upload_status.json`
- any file already in a terminal success state (`uploaded`, `thumbnail_done`, `db_done`, `already_present`) is skipped
- only files in `pending`, `failed`, or `uploading` states are processed

This applies both to automatic TUS reconnect and to user-initiated resume after a crash.

This is the same idempotent resume pattern already used in the Step 1 manifest job.

## Upload failure behavior

### TUS-level failures vs. batch-level failures

There are two distinct failure levels in the upload flow:

**Level 1 — TUS-level (transparent, automatic)**

- connection dropped mid-upload
- browser paused or suspended temporarily
- short network interruption

TUS handles these transparently via its resumable protocol. The user should never see a decision dialog for Level 1 failures.

**Level 2 — Batch-level (permanent, requires user decision)**

- file checksum does not match the manifest after upload completes
- server rejects the file (wrong type, oversized, etc.)
- thumbnail generation fails on the server
- DB write fails on the server
- TUS upload finalized but post-processing failed

Only Level 2 failures trigger the user decision dialog.

### Batch failure decision dialog

On the first Level 2 (permanent) failure, the system should pause and offer these choices:

- `Retry this file`
- `Skip this file and continue one-by-one`
- `Skip this file and continue automatically with the default action for remaining failures`
- `Stop batch`

The agreed default action for the rest is:

- `mark failed and continue`

Then summarize failures at the end.

This makes long-running jobs practical while still allowing user control once the first permanent problem occurs.

## Recovery and resiliency

A major design goal is resiliency.

Manifest and upload jobs may continue:

- in the background
- overnight
- after browser refresh or crash

The browser should be able to reconnect later and recover the state from the backend.

### Return/reconnect UX

When a user returns to the page after a crash, reload, or long-running background work, the page automatically detects the current job state by polling the backend on load and renders the appropriate view.

There are three possible states:

**Still running**

- show live polling status as if the user never left
- display progress bar, current file, elapsed time, and counts so far
- no manual action required

**Completed successfully**

- show a summary: e.g. `N files uploaded`
- show an inline toggle or link to detailed upload results
- no continue button needed

**Stopped or failed**

- show a summary of what was done before the stop or failure
- show a clear continue button based on the stage where work stopped:
  - `Continue with manifest/hashing`
  - or `Continue with upload`

The reconnect experience is summary-first, with detail available on demand via an inline toggle.

## Persisted upload job state

The current manifest import workflow already persists status files under the job directory and exposes them through status endpoints.

The new upload workflow follows the same persisted file-based pattern under the same manifest job directory.

Upload-specific persisted artifacts include:

- `duplicates.json` — duplicate checksum groups pending resolution (written before showing the resolution dialog)
- `upload_status.json` — per-file upload state, polled by the browser
- `upload_result.json` — final upload job result
- `upload_cancel.json` — cancellation signal
- `upload_meta.json` — upload job metadata

All of these live under `/var/www/private/import_jobs/<job_id>/` alongside the existing manifest artifacts.

The pattern mirrors the manifest job model:

- persisted JSON state
- pollable by browser
- recoverable after refresh/crash

## Upload Media button states

The `Upload Media` button has four distinct states:

| Job upload state | Button behavior |
|---|---|
| No successful manifest yet | Disabled — `Upload Media` |
| Manifest exists, no upload job yet | Enabled — `Upload Media` |
| Upload job in progress | Disabled — `Upload in Progress…` with link to live progress |
| Upload job complete | Disabled — `Uploads Complete` with option to re-run via recovery controls |

## Job preselection

There are two distinct preselection rules, applied to two different UI controls:

### Upload Media button preselection

Preselect the most recent successful manifest job for that section that does not yet have a completed upload job.

This ensures the button is pointed at the most relevant unfinished work.

### Recovery controls preselection

Preselect the most recent incomplete or failed job for that section, regardless of whether it is a manifest job or an upload job.

This ensures recovery controls are pointed at the most relevant interrupted work.

If a `job_id` is explicitly supplied in the URL, that job overrides both preselection rules and becomes the focus for that section.

## Section behavior on the new page

### User reassurance before action

The page should display enough identifying information for the selected job that the user can determine it is the correct job before pressing the action button.

Examples of useful identifying details include:

- `job_id`
- mode
- created time
- item count
- state
- summary/result message

### Recovery controls

Each section should keep:

- last job summary
- previous jobs dropdown
- retry/recovery controls

If the user attempts retry/recovery on a job that was already successful, the UI should ask for confirmation with messaging along the lines of:

- `Our records indicate your previous job was successful. Do you want to do this?`

### Reopening older jobs

Older manifest jobs must be reopenable later.

Users should be able to:

- revisit old jobs
- continue uploads later
- resume from persisted backend state

## What is deferred

The following is intentionally deferred from the first implementation scope:

- editing `admin.php`
- wiring `admin.php` to point to `admin_media_import.php`

That is a return task after the new page is built and validated.

## Proposed implementation phases

All phases are internal development sequencing. They all ship together in one change window. Phases are not independent production releases.

### Phase 1

Create `admin_media_import.php` with:

- two sections with descriptive labels
- the step 1 manifest/hash workflow migrated from `admin.php`
- migration of `_scanFolderImportRunState` and `_scanFolderAddRunState` JavaScript state machines from `admin.php` — this is first-class Phase 1 work
- duplicate detection with hard block on manifest submission if duplicates are present (resolution dialog added in Phase 2)
- job summaries and recovery UI

### Phase 2

Add full duplicate checksum resolution at end of step 1:

- save `duplicates.json` and set `awaiting_duplicate_resolution` state before showing dialog
- group duplicates by checksum
- display associated `source_relpath` values
- require keep-choice before finalizing manifest
- support recovery of unresolved duplicate state after browser close

### Phase 3

Add step 2 manifest-aware upload flow:

- load manifest by `job_id`
- use TUS for upload transport
- match by checksum
- show `source_relpath` as reassurance
- persist upload progress under the same job directory (`upload_status.json`, etc.)
- implement per-file post-processing polling for `thumbnail_done` and `db_done`
- implement resume behavior: skip terminal success states on reconnect

### Phase 4

Add full reconnect/recovery behavior:

- three-state reconnect model (still running / completed / stopped+failed)
- auto-detection of job state on page load via backend polling
- inline detail toggle for per-file results
- paused failure-decision UI for batch Level 2 failures
- `already_present` inline missing-artifact display

### Phase 5

Deferred return task:

- add entry point from `admin.php`

## Notes for implementation

Key invariants to preserve:

- `manifest.json` remains the source of truth for step 2
- `job_id` is the canonical identifier and the implicit join key between manifest and upload artifacts
- checksum is authoritative for matching
- `source_relpath` remains visible for human reassurance
- duplicate checksum ambiguity must be resolved before step 2
- pending duplicate resolution state must be persisted to the server before the dialog is shown
- upload jobs must remain recoverable after browser loss/reload
- on resume, only pending/failed/uploading files are processed — terminal success states are skipped
- TUS handles transient connection failures transparently; user decision dialogs are only shown for permanent (Level 2) failures
- success means all required post-processing is satisfied (media + thumbnail + DB), not merely bytes uploaded
- all endpoints and the page itself are admin-only
