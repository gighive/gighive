# Adding a jam: `add_jam_session.py`

**Script:** `ansible/roles/docker/files/apache/webroot/tools/add_jam_session.py`

One command adds a new StormPigs jam to **both** the live prod database and the
canonical prepped CSVs in `ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full/`.
Both matter: the site reads the live DB, while a `rebuild_mysql_data: true` deploy wipes the
DB and reloads it only from those CSVs (`load_and_transform.sql`). The rows the script inserts
live are exactly what a rebuild produces from the appended CSV rows.

It replaces the old routes for new jams: the legacy `database.csv` import (a full
truncate-and-reload) and the folder upload followed by hand edits in `db/database.php`.

## Inputs

All in the jam folder (`--dir`) unless overridden:

| Input | Notes |
|---|---|
| `StormPigsYYYYMMDD_N_slug.mp4` | The singles. `N` is the song position; the date comes from the names. Other media in the folder (`.ts` sources etc.) is ignored. |
| `songlist.txt` (`--songs`) | One line per song, in order. Accepted forms: `N HH:MM:SS-HH:MM:SS title [*]`, `SOURCE.mov HH:MM:SS-HH:MM:SS title [*]`, or just `title [*]`. Without a leading number, line order is the position. A trailing `*` (highlight) is kept in the title, as in existing data. A trailing `<br>` is ignored. |
| `StormPigsYYYYMMDD_metadata.txt` (`--meta`) | Musicians, location, rating, summary, publish date, explicit, keywords. If missing, the script asks for them (the same questions as `makeStormpigsRecordCsv.py`) and saves the file for re-runs. The legacy `for YYYYMMDD jam:` format is read as-is. |
| Jam image (`--image`) | Defaults to `webroot/images/jam/YYYYMMDD.jpg`, then `<dir>/YYYYMMDD.jpg`. Without one, the event uses `images/jam/blank.jpg`. |

Ratings may be `***`, `** 1/2`, `2.5` or `3` (1–5 in half steps); the CSV stores stars, the DB a decimal.

## Usage

Run on the machine with the files (pop-os), from an up-to-date checkout:

```bash
cd ~/gighive && git pull
T=ansible/roles/docker/files/apache/webroot/tools/add_jam_session.py

python3 $T --dir ~/Media/Proxy/20261019 --ssh prod --dry-run   # read-only; review the plan
python3 $T --dir ~/Media/Proxy/20261019 --ssh prod             # apply (asks to confirm)
```

The dry run hashes and probes every file (cached in `~/.cache/add_jam_session/`, so later
runs are fast), reads prod, and prints the new IDs, musicians, song list and SQL. It uploads,
inserts, writes and commits nothing (it only creates the metadata file if you answered the questions).

## What a real run does

1. Checks the songlist against the files: same positions, titles match the file-name slugs,
   timestamp ranges match the file durations (warning only), no duplicate files.
2. Reads prod and compares row counts and max IDs of `events`, `assets`, `event_items`,
   `participants`, `event_participants` with the CSVs. Stops if they differ (`--allow-drift`
   to override), if the date is already in `sessions.csv` or prod, or if any file is already an asset on prod.
3. Picks new IDs as max(CSV, live) + 1.
4. Unknown musicians: asks whether to add them as new participants (`--yes` adds them).
5. Requires the CSV directory to be clean and the checkout not behind its upstream, then asks to confirm.
6. rsyncs the files to prod as `/home/ubuntu/video/<sha256>.<ext>` (resumable, skips files
   already there), verifies sizes, and uploads thumbnails to `video/thumbnails/<sha256>.png`
   (same frame choice as the app).
7. Inserts participants, the event, assets, event_items and event_participants in one transaction,
   then checks the row counts.
8. Appends the rows to the CSVs (`participants`, `sessions`, `songs`, `session_songs`,
   `song_files`, `files`, `event_participants`).
9. Copies the image into the repo and `docker cp`s it into the running `apacheWebServer`
   (images are baked into the image, so it also ships with the next deploy).
10. Commits the CSVs and image (`Changes: add StormPigs jam YYYY-MM-DD (event N)`) and pushes.
    `--no-push` commits only; `--no-commit` leaves the changes uncommitted.

If a run fails before step 7, re-running is safe (uploads resume). Step 7 is all-or-nothing.

## Options

`--org` (default `StormPigs`), `--whole-jam FILE` (adds an unpositioned "Entire Jam" clip),
`--csv-dir`, `--remote-video-dir` (default `/home/ubuntu/video`), `--mysql-container`
(default `mysqlServer`), `--apache-container` (default `apacheWebServer`),
`--allow-title-mismatch`, `--allow-drift`, `--yes`, `--no-push`, `--no-commit`.

The script uses the DB credentials already in the `mysqlServer` container environment
(`MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DATABASE`); none are passed over SSH.
