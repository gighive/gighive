# Plan: Automated Jam Session Append to CSVs + Production

**Status:** Plan — pending review before implementation  
**Script location (proposed):** `ansible/roles/docker/files/apache/webroot/tools/add_jam_session.py`

---

## Rationale

The previous workflow for adding new StormPigs jam sessions used the browser-based
folder uploader (`admin_database_load_import_media_from_folder.php`). This caused:

- Events auto-created with wrong `org_name` ('gighive') and wrong `event_date` (upload date)
- No session metadata (crew, location, rating, summary, keywords)
- No song ordering / `position` in `event_items`
- `files.csv` not updated → a future `rebuild_mysql_data=true` would lose all media metadata

The correct source of truth is the six prepped CSVs. This script automates building
and applying those CSV entries from two standard input files (songlist + metadata)
that already exist as part of the session finalization workflow.

---

## End-to-End Overview

Given local video files, a songlist, and a metadata file, the script performs these steps in a single command:

1. **Parse inputs** — read `songlist.txt` and `metadata.txt` to build session metadata
2. **Determine next IDs** — derive next `session_id`, `song_id`, `file_id` from the prepped CSVs
3. **Resolve participants** — map crew names to `participant_id`s via `participants.csv`; abort on any unknown name
4. **Compute SHA256** — hash all local video files before transfer
5. **rsync to prod** — transfer video files to `<ssh-target>:/home/ubuntu/video/<YYYYMMDD>/`
6. **ffprobe on prod** — extract duration and media info from each remote file via SSH
7. **Build CSV + SQL rows** — construct rows for all 6 CSVs and the live-SQL payload
8. **Write CSVs** — append new rows to `files`, `sessions`, `songs`, `session_songs`, `song_files`, `event_participants`
9. **Apply SQL to prod** — insert event, assets, event_items, and event_participants into the live DB via `docker exec` over SSH
10. **Verify** — query production and print a row-count summary
11. **Git commit + push** — commit the 6 updated CSVs and push

---

## Proposed Invocation

```bash
python3 ansible/roles/docker/files/apache/webroot/tools/add_jam_session.py \
  --dir      ~/videos/stormpigs/finals/20260318/ \
  --songs    ~/videos/stormpigs/finals/songlists/StormPigs20260318.txt \
  --meta     ~/videos/stormpigs/finals/metadata/StormPigs20260318_metadata.txt \
  --ssh      ubuntu@prod.gighive.internal \
  --csv-dir  ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full \
  --repo-dir .                      # git root; defaults to cwd
  [--org     StormPigs]             # org_name; defaults to StormPigs
  [--dry-run]                       # print what would happen; make no changes
  [--no-push]                       # skip git commit/push
```

---

## How to Use

### Prerequisites

1. **Video files are finalized** — files are in their final form in a local directory,
   named using the StormPigs convention: `StormPigs20260318_1_SongTitle.mp4`
2. **`songlist.txt` exists** — one line per song in the standard format (see below)
3. **`metadata.txt` exists** — crew, location, rating, summary, etc. (see below)
4. **Crew members are in `participants.csv`** — run the script with `--dry-run` first
   to catch any unknown names before touching prod
5. **SSH access to prod** — `ssh ubuntu@prod.gighive.internal` works from your machine
6. **Run from the repo root** — so relative paths and `git` work correctly

### Workflow

**Step 1 — Dry run first (always)**

```bash
python3 ansible/roles/docker/files/apache/webroot/tools/add_jam_session.py \
  --dir     ~/videos/stormpigs/finals/20260318/ \
  --songs   ~/videos/stormpigs/finals/songlists/StormPigs20260318.txt \
  --meta    ~/videos/stormpigs/finals/metadata/StormPigs20260318_metadata.txt \
  --ssh     ubuntu@prod.gighive.internal \
  --csv-dir ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full \
  --dry-run
```

Review the output:
- Confirm session ID, date, org, metadata look correct
- Confirm all crew names resolved to participants
- Confirm song list order (positions 1, 2, 3…) matches the songlist
- Confirm file count matches songlist line count

**Step 2 — Fix any issues**

If a crew member is unknown, add them manually to `participants.csv` first:
```
<next_id>,NewPerson
```
Then re-run `--dry-run` to confirm.

**Step 3 — Run for real**

```bash
python3 ansible/roles/docker/files/apache/webroot/tools/add_jam_session.py \
  --dir     ~/videos/stormpigs/finals/20260318/ \
  --songs   ~/videos/stormpigs/finals/songlists/StormPigs20260318.txt \
  --meta    ~/videos/stormpigs/finals/metadata/StormPigs20260318_metadata.txt \
  --ssh     ubuntu@prod.gighive.internal \
  --csv-dir ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full
```

The script will:
- Compute SHA256 on local files
- rsync files to prod
- Run ffprobe on prod for each file
- Append rows to all 6 CSVs
- Apply SQL directly to the live production DB
- Print a verification summary
- `git commit` and `git push` the updated CSVs

**Step 4 — Check the verification summary**

```
Session 137 (2026-03-18):
  events:              1 row  ✓
  assets:             12 rows ✓
  event_items:        12 rows ✓
  event_participants:  5 rows ✓
  positions set:      12      ✓
```

If any row count is wrong, check the error output. The script is re-runnable —
re-running after a partial failure is safe (`INSERT IGNORE` / `ON DUPLICATE KEY UPDATE`
prevent duplicate rows).

**Step 5 — Verify in the UI**

Open `stormpigs.com/db/database.php?view=event&date=YYYY-MM-DD` and confirm the
session appears with correct metadata, song order, and participants.

### If Something Goes Wrong Mid-Run

The script is safe to re-run. IDs are always re-derived from the current state of
the CSVs, and all SQL uses either `INSERT IGNORE` or `ON DUPLICATE KEY UPDATE`. If
the CSVs were partially written, restore from git (`git checkout -- <file>`) and
re-run from scratch.

---

## Input File Formats

### `songlist.txt`

```
1 00:05:20-00:15:40 split the son *<br>
2 00:16:20-00:22:01 36th street boogie <br>
3 00:23:48-00:35:55 can you feel it *<br>
```

Parsed fields per line:
- **Column 1** — position (integer) → `session_songs.position`, `event_items.position`
- **Column 2** — `start-end` timestamp range (informational only; not stored)
- **Remaining** — song title (strip trailing `*`, `<br>`, whitespace)

The `*` marker is preserved as a `highlight` flag (stored in `event_items.item_type`
as `'highlight'` instead of `'song'`) if the system ever uses it, but defaults to
`'song'` for now to stay consistent with existing data.

### `metadata.txt`

```
for YYYYMMDD jam:
crew: Person1, Person2, Person3.
location: Venue Name, City.
Rating: 3.
Summary: Free-form text.
Published at YYYY-MM-DD.
Explicit: Yes.
Duration: HH:MM:SS
Keywords: word1, word2, word3.
```

Parsed fields:
- **Date** — extracted from `for YYYYMMDD jam:` line
- **crew** — comma-separated list of display names (used to resolve `participant_id`s)
- **location**, **Rating**, **Summary**, **Keywords** → `events` table columns
- **Published at** → `events.published_at` (appended ` 00:00:00`)
- **Explicit** — `Yes`→1, anything else→0
- **Duration** — `HH:MM:SS` → converted to seconds for `events.duration_seconds`

---

## Algorithm: Step by Step

### Step 1 — Parse inputs

- Parse `metadata.txt` → session dict
- Parse `songlist.txt` → ordered list of `{position, title}` dicts
- Scan `--dir` for video/audio files; classify each:
  - Matches `YYYYMMDD_N_Title.ext` → individual song file (`type='song'`)
  - Does **not** match that pattern (e.g. SHA256-named or `YYYYMMDD_entirejam.ext`) → whole-jam clip (`type='event_label'`); treated as a single extra entry appended after all songs with `position=NULL`
- Sort song files by the extracted sequence number `N`
- Validate: song file count matches songlist line count (warn if mismatch; whole-jam file is excluded from this count)

### Step 2 — Determine next IDs from CSVs

Read existing CSVs to find the next available ID for each:

```python
next_file_id    = max(int(r['file_id'])    for r in files_csv)    + 1
next_song_id    = max(int(r['song_id'])    for r in songs_csv)    + 1
next_session_id = max(int(r['session_id']) for r in sessions_csv) + 1
```

**Guard — duplicate session date**: Check that the session date parsed from
`metadata.txt` does not already appear in `sessions.csv`. If it does, abort.
(Checking `next_session_id` is not meaningful — it is always `max+1` by definition.)

**Guard — prod event_id collision**: Before inserting, query production for
`SELECT COUNT(*) FROM events WHERE event_id = next_session_id`. If a row already
exists (auto-created by the folder uploader), the SQL step must use
`INSERT INTO events ... ON DUPLICATE KEY UPDATE` rather than a bare `INSERT`,
because a bare `INSERT IGNORE` would silently leave wrong metadata in place.

### Step 3 — Compute SHA256 locally

For each video file, compute SHA256 in Python (`hashlib.sha256`, streaming in 8 MB
chunks) before transfer. This avoids needing remote access just for hashing and
confirms file integrity post-transfer.

### Step 4 — rsync files to production

```bash
rsync -avz --progress \
  <local_dir>/ \
  <ssh_target>:/home/ubuntu/video/<YYYYMMDD>/
```

Uses `--ignore-existing` to skip files already present (safe to re-run). After
rsync, verify integrity using rsync's own `--checksum` flag on a second pass
(avoids N extra SSH round-trips for individual sha256sum calls). If any checksum
fails, abort before touching the database.

### Step 5 — Run ffprobe remotely

For each file, SSH to prod and run:

```bash
ffprobe -v quiet -print_format json -show_streams -show_format \
  /home/ubuntu/video/YYYYMMDD/filename.mp4
```

Extract:
- `duration_seconds` — `int(float(format.duration))`
- `media_info` — full JSON string (stored as-is)
- `media_info_tool` — `"ffprobe " + ffprobe_version_string`

### Step 6 — Resolve participants

For each name in the crew list:

1. Look up `participants.csv` for a case-insensitive match on `name`
2. If found → use that `participant_id`
3. If **not found** → **abort with clear error** listing the unknown name(s)

No automatic participant creation — new participants must be added to `participants.csv`
manually first to preserve data integrity. The script prints the exact CSV row to
add if a name is missing.

### Step 7 — Update all 6 CSVs

All writes use `csv.writer(lineterminator='\r\n')` (required by MySQL
`LOAD DATA INFILE`). Rows are appended; no existing rows are modified.

#### `files.csv`

One row per video file:

| file_id | file_name | source_relpath | checksum_sha256 | file_type | duration_seconds | media_info | media_info_tool |
|---|---|---|---|---|---|---|---|
| next_file_id+N | `StormPigs20260318_1_splittheson.mp4` | `20260318/StormPigs20260318_1_splittheson.mp4` | sha256 | `video` | int | JSON | ffprobe string |

#### `sessions.csv`

One row for the session:

| session_id | title | date | org_name | event_type | description | cover_image_url | crew | location | rating | summary | published_at | explicit | duration | keywords |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| next_session_id | `Mar 18` | `2026-03-18` | `StormPigs` | `band` | `` | `images/jam/YYYYMMDD.jpg` | crew string | location | `***` | summary | `2026-03-25 00:00:00` | `1` | `01:29:27` | keywords |

- `title` derived from date: `strftime('%b %-d', date)` → `Mar 18`
- `rating` stars: integer 1–5 → that many `*` characters
- `cover_image_url` hardcoded pattern `images/jam/YYYYMMDD.jpg`

#### `songs.csv`

One row per song (position order):

| song_id | title | type | | | |
|---|---|---|---|---|---|
| next_song_id+N | `Split the Son` | `song` | `` | `` | `` |

Last 3 columns (duration, genre, style) remain empty — they are `@skip_*` in the SQL.

#### `session_songs.csv`

**⚠️ Position column is critical — song order.**  
One row per song, with position from songlist:

| session_id | song_id | position |
|---|---|---|
| next_session_id | next_song_id+0 | 1 |
| next_session_id | next_song_id+1 | 2 |
| … | … | … |

Position is taken directly from column 1 of `songlist.txt` — the canonical ordering.

#### `song_files.csv`

One row per song, mapping `song_id` → `file_id`:

| song_id | file_id |
|---|---|
| next_song_id+0 | next_file_id+0 |
| next_song_id+1 | next_file_id+1 |
| … | … |

Files are matched to songs by **explicit sequence number `N`** extracted from
filenames (e.g. `StormPigs20260318_3_canyoufeelit.mp4` → N=3). The songlist
position is also `N`. Both are matched on `N`, not on list index — this is safe
even if sequence numbers are non-consecutive or don't start at 1.

#### `event_participants.csv`

One row per crew member:

| event_id | participant_id |
|---|---|
| next_session_id | resolved_participant_id |

### Step 8 — Apply live SQL to production

No rebuild needed. The script generates and executes SQL directly:

```sql
-- Insert event (use ON DUPLICATE KEY UPDATE to handle auto-created placeholder rows)
INSERT INTO events (event_id, event_date, org_name, event_type, title,
  cover_image_url, location, rating, summary, published_at, explicit,
  duration_seconds, keywords)
VALUES (...)
ON DUPLICATE KEY UPDATE
  event_date=VALUES(event_date), org_name=VALUES(org_name),
  event_type=VALUES(event_type), title=VALUES(title),
  cover_image_url=VALUES(cover_image_url), location=VALUES(location),
  rating=VALUES(rating), summary=VALUES(summary),
  published_at=VALUES(published_at), explicit=VALUES(explicit),
  duration_seconds=VALUES(duration_seconds), keywords=VALUES(keywords);

-- Insert assets (one per file)
INSERT IGNORE INTO assets (asset_id, file_name, source_relpath, checksum_sha256,
  file_type, duration_seconds, media_info, media_info_tool) VALUES (...), ...;

-- Insert event_items (one per song, with position)
INSERT IGNORE INTO event_items (event_id, asset_id, item_type, label, position)
VALUES (...), ...;

-- Insert event_participants
INSERT IGNORE INTO event_participants (event_id, participant_id) VALUES (...), ...;
```

SQL is written to a temp file, scp'd to the remote host, then executed via:
```bash
# Password is sourced from $MYSQL_PASSWORD inside the container — never passed over SSH
ssh <target> 'docker exec -i mysqlServer sh -c '"'"'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u appuser music_db < /tmp/add_session.sql'"'"''
```

### Step 9 — Verify

After SQL execution, query production and print a summary:

```
Session 137 (2026-03-18):
  events:             1 row   ✓
  assets:            12 rows  ✓
  event_items:       12 rows  ✓
  event_participants: 5 rows  ✓
  positions set:     12       ✓
```

### Step 10 — Git commit + push

```bash
git add ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/
git commit -m "data: add session <ID> (<YYYY-MM-DD>) to all CSVs"
git push
```

Skipped if `--no-push` is passed.

---

## Edge Cases and Guards

| Condition | Behavior |
|---|---|
| Session **date** already in `sessions.csv` | Abort with error (not session_id — that's always new by definition) |
| Crew member not in `participants.csv` | Abort; print exact CSV row to add manually |
| File count ≠ songlist line count | Warn; proceed with what matches |
| File already exists on prod (rsync) | Skip copy via `--ignore-existing`; still run ffprobe |
| SHA256 mismatch after transfer | Abort |
| `--dry-run` | Print all proposed CSV rows and SQL; no writes |
| Re-run after partial failure | IDs re-derived from CSVs; `INSERT IGNORE` on assets/event_items/event_participants; `ON DUPLICATE KEY UPDATE` on events |

---

## Files Changed Per Run

| File | Change |
|---|---|
| `files.csv` | N new rows (one per video file) |
| `sessions.csv` | 1 new row |
| `songs.csv` | N new rows |
| `session_songs.csv` | N new rows (with position) |
| `song_files.csv` | N new rows |
| `event_participants.csv` | M new rows (one per crew member) |

---

## What Is NOT Automated

- Adding a new participant to `participants.csv` (must be done manually before running)
- Thumbnail generation (handled by the existing PHP app on first page load, or separately via `upload_media_by_hash.py`)
- Updating `cover_image_url` if the image filename doesn't follow `images/jam/YYYYMMDD.jpg`
- `org_name` defaults to `StormPigs`; pass `--org` to override for other bands
