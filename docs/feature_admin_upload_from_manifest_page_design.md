# Feature Design: admin_upload_from_manifest.php

## Summary

This document captures the agreed design for a new browser-based manifest upload workflow page:

- `admin_upload_from_manifest.php`

The goal is to replace the current manual Part II shell/Python workflow for Sections 4/5 with a manifest-aware browser experience while preserving the existing manifest/hash generation model.

The page is intentionally planned as a new dedicated workflow page. Changes to `admin.php` are explicitly deferred until after this page exists and is working.

## Scope for first implementation

Build a new page:

- `admin_upload_from_manifest.php`

Do not edit `admin.php` yet.

Deferred follow-up task:

- after `admin_upload_from_manifest.php` is implemented and working, add an entry point from `admin.php`

## Page structure

Keep the UI simple for the first implementation with two separate sections on the new page:

- `Section 4: Refresh the Database from a Folder Manifest (destructive)`
- `Section 5: Add to the Database from a Folder Manifest (non-destructive)`

Each section will have two buttons:

- `Create Manifest and Hashes`
- `Upload Media`

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

### Duplicate hash handling

`checksum_sha256` remains the authoritative key.

However, `source_relpath` must still be shown to the user for reassurance and human verification.

If multiple files in the selected folder have the same checksum, step 1 must not silently continue.

Instead, at the end of hashing it should:

- summarize duplicate checksum groups
- show the associated `source_relpath` entries for each duplicate checksum group
- ask the user:
  - `Which one should we keep?`

Meaning:

- which duplicate file path should be retained as the canonical manifest entry for that checksum

Only the chosen file should survive into the finalized manifest for that checksum.

This makes duplicate resolution a step 1 concern rather than deferring ambiguity into step 2.

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

## Upload failure behavior

If one file fails during step 2, the batch should not simply hard-stop or blindly continue.

On the first failure, the system should pause and offer these choices:

- `Retry this file`
- `Skip this file and continue one-by-one`
- `Skip this file and continue automatically with the default action for remaining failures`
- `Stop batch`

The agreed default action for the rest is:

- `mark failed and continue`

Then summarize failures at the end.

This makes long-running jobs practical while still allowing user control once the first problem occurs.

## Recovery and resiliency

A major design goal is resiliency.

Manifest and upload jobs may continue:

- in the background
- overnight
- after browser refresh or crash

The browser should be able to reconnect later and recover the state from the backend.

### Return/reconnect UX

When a user returns to the page after a crash, reload, or long-running background work, the page should show:

- a short summary of what was done
  - for example: `N files uploaded`
- a link to a PHP page showing detailed upload results
- a clear continue button based on the stage where work stopped or failed
  - `Continue with manifest/hashing`
  - or `Continue with upload`

The reconnect experience should be summary-first, with details available on demand.

## Persisted upload job state

The current manifest import workflow already persists status files under the job directory and exposes them through status endpoints.

The new upload workflow should follow the same persisted file-based pattern under the same manifest job directory where practical.

Suggested upload-specific persisted artifacts may include files such as:

- `upload_status.json`
- `upload_result.json`
- `upload_cancel.json`
- `upload_meta.json`

The exact filenames can be decided during implementation, but the pattern should mirror the manifest job model:

- persisted JSON state
- pollable by browser
- recoverable after refresh/crash

## Section behavior on the new page

### Job preselection

The page should preselect the most appropriate job for the user.

In practice this likely means:

- the most recent relevant job

If a `job_id` is explicitly supplied in the URL, that job should be the focus.

### Upload Media button enablement

`Upload Media` must be disabled until a successful manifest exists for that section/job.

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

## Default page behavior

The new page should default to preselecting the most appropriate job for the user in each section.

That likely means:

- the most recent relevant job

A strong emphasis was placed on resiliency and return-to-page recovery. For that reason, preselecting the most appropriate recent job is desirable.

If a `job_id` is present in the URL, it should override the default focus behavior.

## What is deferred

The following is intentionally deferred from the first implementation scope:

- editing `admin.php`
- wiring `admin.php` to point to `admin_upload_from_manifest.php`

That is a return task after the new page is built and validated.

## Proposed implementation phases

### Phase 1

Create `admin_upload_from_manifest.php` with:

- two sections
- the step 1 manifest/hash workflow surfaced there
- job summaries and recovery UI

### Phase 2

Add duplicate checksum resolution at end of step 1:

- group duplicates by checksum
- display associated `source_relpath` values
- require keep-choice before finalizing manifest

### Phase 3

Add step 2 manifest-aware upload flow:

- load manifest by `job_id`
- use TUS
- match by checksum
- show `source_relpath` as reassurance
- persist upload progress using the same job-directory style pattern

### Phase 4

Add reconnect/recovery behavior:

- summary on return
- details link
- continue button based on failure point
- paused failure-decision UI for batch upload failures

### Phase 5

Deferred return task:

- add entry point from `admin.php`

## Notes for implementation

Key invariants to preserve:

- `manifest.json` remains the source of truth for step 2
- `job_id` is the canonical identifier
- checksum is authoritative for matching
- `source_relpath` remains visible for human reassurance
- duplicate checksum ambiguity must be resolved before step 2
- upload jobs must remain recoverable after browser loss/reload
- success means all required post-processing is satisfied, not merely bytes uploaded
