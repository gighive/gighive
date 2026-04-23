# Event Metadata Duplication — Concrete Example and Clarification

This document captures a precise walkthrough of the duplication problem described in
`docs/refactor_db_fix_event_metadata_duplication.md`, using a concrete example.

## The Scenario

The same video file (`video1.mp4`, SHA256=`1234abcd`) is uploaded twice to the same
event date, but with different `org_name` values:

| Upload | event_date | org_name       | SHA256     |
|--------|------------|----------------|------------|
| #1     | 2026-02-02 | stormpigs      | 1234abcd   |
| #2     | 2026-02-02 | weddingevent   | 1234abcd   |

## What Happens in the Database

The `sessions` table has a `UNIQUE(date, org_name)` constraint. Because `org_name`
differs between the two uploads, both rows are considered valid and distinct:

- Upload #1 creates session row for `(2026-02-02, "stormpigs")`
- Upload #2 creates session row for `(2026-02-02, "weddingevent")`

Result: **two session rows exist** for what is logically the same real-world recording date.

## What Happens on the Filesystem

The SHA256-based asset dedup correctly prevents duplicate file storage:

- Upload #1 stores `video1.mp4` on disk, creates one `files` row (sha256=`1234abcd`)
- Upload #2 detects the SHA already exists — **no second file is written to disk**

Result: **one physical file** on the filesystem.

## The Mismatch

| Layer      | Count | Notes                                              |
|------------|-------|----------------------------------------------------|
| Filesystem | 1     | SHA dedup is working correctly                     |
| `files` rows | 1   | SHA dedup is working correctly                     |
| `sessions` rows | 2 | Both are valid under current UNIQUE constraint   |
| `session_songs` / `song_files` | 2 sets | Both point at the same single `files` row |

The Media Library JOIN traverses both linkage paths and surfaces the same video under
two different events — even though only one physical file exists on disk.

## Why SHA Dedup Does Not Solve This

SHA dedup operates at the **asset level** — it prevents the same bytes from being
stored twice. It has no awareness of session/event identity and cannot prevent a second
session row from being created.

The duplication is entirely at the **database linkage level**, not in storage.

## Why This Happens

`org_name` is mutable display metadata, but the current schema uses it as part of event
identity via `UNIQUE(date, org_name)`. This means:

- Editing `org_name` after the first upload makes the next upload look like a new event
- Uploading the same file to two different `org_name` values on the same date creates
  two legitimate-looking (but logically duplicate) session rows
- The system has no way to know that `stormpigs` and `weddingevent` on `2026-02-02`
  refer to the same real-world event

## The Fix (Summary)

Introduce `sessions.event_key` — a stable, explicit identifier for event identity that
is independent of editable display metadata. `org_name` becomes pure display metadata;
`event_key` becomes the canonical lookup key for session upserts.

See `docs/refactor_db_fix_event_metadata_duplication.md` for the full stop-gap plan.
