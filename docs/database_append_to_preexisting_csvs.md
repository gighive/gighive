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

## Proposed Invocation

```bash
python3 ansible/roles/docker/files/apache/webroot/tools/add_jam_session.py \
  --dir     ~/videos/stormpigs/finals/20260318/ \
  --songs   ~/videos/stormpigs/finals/songlists/StormPigs20260318.txt \
  --meta    ~/videos/stormpigs/finals/metadata/StormPigs20260318_metadata.txt \
  --ssh     ubuntu@prod.gighive.internal \
  --csv-dir ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full \
  [--dry-run]   # print what would happen; make no changes
  [--no-push]   # skip git commit/push
```

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
- Scan `--dir` for video/audio files matching `YYYYMMDD_N_Title.ext` pattern
- Sort files by the embedded sequence number `N`
- Validate: file count matches songlist line count (warn if mismatch)

### Step 2 — Determine next IDs from CSVs

Read existing CSVs to find the next available ID for each:

```python
next_file_id    = max(int(r['file_id'])    for r in files_csv)    + 1
next_song_id    = max(int(r['song_id'])    for r in songs_csv)    + 1
next_session_id = max(int(r['session_id']) for r in sessions_csv) + 1
```

Check that `next_session_id` does not already exist in `sessions.csv` (guard against
running the script twice for the same session).

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
rsync, verify each remote file's SHA256 matches the locally computed value.

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

Files are matched to songs by sorting both lists by their embedded sequence number `N`.

#### `event_participants.csv`

One row per crew member:

| event_id | participant_id |
|---|---|
| next_session_id | resolved_participant_id |

### Step 8 — Apply live SQL to production

No rebuild needed. The script generates and executes SQL directly:

```sql
-- Insert event
INSERT INTO events (event_id, event_date, org_name, ...) VALUES (...);

-- Insert assets (one per file)
INSERT IGNORE INTO assets (asset_id, file_name, source_relpath, checksum_sha256,
  file_type, duration_seconds, media_info, media_info_tool) VALUES (...), ...;

-- Insert event_items (one per song, with position)
INSERT IGNORE INTO event_items (event_id, asset_id, item_type, label, position)
VALUES (...), ...;

-- Insert event_participants
INSERT IGNORE INTO event_participants (event_id, participant_id) VALUES (...), ...;
```

SQL is written to a temp file first and piped via:
```bash
ssh <target> "docker exec -i mysqlServer mysql -u appuser -pmusiclibrary music_db" < /tmp/add_session.sql
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
| Session date already in `sessions.csv` | Abort with error |
| Crew member not in `participants.csv` | Abort; print exact CSV row to add manually |
| File count ≠ songlist line count | Warn; proceed with what matches |
| File already exists on prod (rsync) | Skip copy, still run ffprobe |
| SHA256 mismatch after transfer | Abort |
| `--dry-run` | Print all proposed CSV rows and SQL; no writes |
| Re-run after partial failure | IDs re-derived from CSVs; SQL uses `INSERT IGNORE` |

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

- Adding a new participant to `participants.csv` (must be done manually)
- Thumbnail generation (handled by the existing PHP app on first page load, or separately via `upload_media_by_hash.py`)
- Updating `cover_image_url` if the image filename doesn't follow `images/jam/YYYYMMDD.jpg`
