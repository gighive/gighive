# Problem: Missing Sessions After Production DB Rebuild

**Date:** 2026-04-26  
**Affected sessions:** 83 (2005-05-26), 136 (2026-03-18)

---

## Root Cause

The new schema builds `event_items` exclusively via the `session_songs` → `songs` → `song_files` pipeline in `load_and_transform.sql`. The old schema allowed files to be linked directly to sessions via `files.session_id` without going through songs. Sessions whose files were added this way were never represented in `session_songs.csv` / `song_files.csv`, leaving their assets orphaned (in `assets` table, but with no `event_items` rows) after a rebuild.

Additionally, session 136 (2026-03-18) was never in `sessions.csv` at all — it was uploaded directly to production through the GigHive upload interface after the last CSV export.

---

## Discovery

After a `rebuild_mysql_data: true` run, query orphaned assets:

```sql
SELECT a.asset_id, a.source_relpath
FROM assets a
WHERE NOT EXISTS (SELECT 1 FROM event_items ei WHERE ei.asset_id = a.asset_id)
ORDER BY a.asset_id;
```

33 orphaned assets were found. Only sessions 83 and 136 had actual media files on disk — the others were legacy records without physical files and were ignored.

---

## CSV Files That Need Updating

All files live in:
`ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/`

| File | What to add |
|---|---|
| `sessions.csv` | New session row (or update existing row with metadata) |
| `songs.csv` | One song per file, starting at `MAX(song_id)+1` |
| `session_songs.csv` | One row per song: `session_id, song_id` |
| `song_files.csv` | One row per song: `song_id, file_id` |
| `event_participants.csv` | One row per musician: `event_id, participant_id` |
| `participants.csv` | Add new participant if not already present |

---

## Fix Process

### Step 1 — Identify file_ids for the session

```bash
python3 -c "
import csv
with open('ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/files.csv') as f:
    for r in csv.reader(f):
        if 'YYYYMMDD' in ''.join(r[:3]):
            print(r[0], r[1])
"
```

### Step 2 — Get max IDs

```bash
tail -3 songs.csv | cut -d',' -f1          # max song_id
tail -3 sessions.csv | cut -d',' -f1       # max session_id
```

### Step 3 — Append rows to CSVs

Use Python `csv.writer` with `lineterminator='\r\n'` (required by `LOAD DATA INFILE`).

```python
import csv

BASE = "ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full"

def append_rows(fname, rows):
    with open(f"{BASE}/{fname}", 'a', newline='') as f:
        csv.writer(f, lineterminator='\r\n').writerows([[str(x) for x in r] for r in rows])
```

### Step 4 — Live production fix

Apply the same data directly to production without waiting for a rebuild. Key pitfall: **use `UPDATE` instead of `INSERT IGNORE` for events**, because the upload pipeline may have auto-created a placeholder event row with the next available `event_id`, causing `INSERT IGNORE` to silently no-op.

```bash
# Write SQL to a file on the server (avoids shell quoting issues with apostrophes)
cat << 'SQLEOF' | ssh ubuntu@prod.gighive.internal "cat > /tmp/fix.sql"
-- For a new event:
UPDATE events SET
  event_date = '2026-03-18', org_name = 'StormPigs', event_type = 'band',
  title = 'Mar 18', location = 'Smash Studios, NYC', rating = 3.0,
  summary = '...', published_at = '2026-03-25 00:00:00',
  explicit = 1, duration_seconds = 5367, keywords = '...'
WHERE event_id = 136;

-- event_items (one per asset)
INSERT IGNORE INTO event_items (event_id, asset_id, item_type, label) VALUES
(136, 682, 'song', 'Split the Son'), ...;

-- event_participants
INSERT IGNORE INTO event_participants (event_id, participant_id) VALUES
(136, 28), (136, 2), (136, 9), (136, 10), (136, 11);
SQLEOF

ssh ubuntu@prod.gighive.internal \
  "docker exec -i mysqlServer mysql -u appuser -pmusiclibrary music_db < /tmp/fix.sql" 2>/dev/null
```

### Step 5 — Verify

```bash
ssh ubuntu@prod.gighive.internal "docker exec mysqlServer mysql -u appuser -pmusiclibrary music_db -N -B -e \"
SELECT COUNT(*) FROM event_items WHERE event_id=136;
SELECT p.name FROM event_participants ep
JOIN participants p ON ep.participant_id=p.participant_id
WHERE ep.event_id=136 ORDER BY p.name;
\"" 2>/dev/null
```

### Step 6 — Commit

```bash
git add ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/
git commit -m "data: add sessions XX and YY to CSV linkage tables"
git push
```

---

## Gotchas

- **`INSERT IGNORE` on events**: The GigHive upload pipeline auto-creates an event row when a file is uploaded. If a rebuild happens before the CSV is updated, and then files are uploaded, the auto-created event_id will collide with your intended INSERT. Always use `UPDATE` for events where the event_id is known.
- **`\r\n` line terminators**: `LOAD DATA INFILE` in `load_and_transform.sql` uses `LINES TERMINATED BY '\r\n'`. Use `lineterminator='\r\n'` in Python's `csv.writer` or rows will not load.
- **song type for whole-jam files**: Use `event_label` in `songs.csv`; `load_and_transform.sql` maps this to `item_type='clip'` in `event_items`.
- **`rating` is `DECIMAL(2,1)`**: The star format (`***`, `** 1/2`) is converted by `load_and_transform.sql`. For direct SQL inserts use the numeric value (e.g. `3.0`).
- **`duration_seconds` is INT**: Convert `HH:MM:SS` → seconds for direct SQL inserts. `load_and_transform.sql` handles `TIME_TO_SEC` from the CSV format automatically.

---

## Participant Normalization

If duplicate participant names are found (e.g. "Trebor Greb" vs "Trebor"):

```sql
-- Remap all event_participants from old_id to canonical_id
UPDATE event_participants SET participant_id = <canonical_id> WHERE participant_id = <old_id>;
DELETE FROM participants WHERE participant_id = <old_id>;
```

Then update `participants.csv` (remove the old row) and `event_participants.csv` (remap the old id).

---

## Files Changed (2026-04-26 session)

| File | Change |
|---|---|
| `sessions.csv` | Added session 136; backfilled metadata for session 83 |
| `songs.csv` | Added songs 738–760 |
| `session_songs.csv` | Added 23 rows for sessions 83 and 136 |
| `song_files.csv` | Added 23 rows for songs 738–760 |
| `event_participants.csv` | Added 5 rows for event 136; remapped Trebor Greb (38→26) |
| `participants.csv` | Removed Trebor Greb (id 38) |
