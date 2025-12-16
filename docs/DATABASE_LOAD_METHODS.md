# Database Load Methods

This document describes the two database (re)load mechanisms used by GigHive and how they differ.

## Overview

GigHive currently supports two practical ways to wipe and repopulate the MySQL database with media data:

1. **MySQL initialization load** (container bootstrap via `/docker-entrypoint-initdb.d/`)
2. **Admin-triggered runtime import** (planned: `import_database.php` via the web admin UI, “Option A”)

Both approaches ultimately load per-table CSVs into MySQL, but they differ in *when* they run, *where* data lives during load, and how much infrastructure is involved.

---

## Method 1: MySQL initialization load (container bootstrap)

### Where it lives

- **Docker Compose / Ansible** mounts initialization SQL scripts into the MySQL container:
  - `/docker-entrypoint-initdb.d/00-create_music_db.sql`
  - `/docker-entrypoint-initdb.d/01-load_and_transform.sql`

### When it runs

- Runs **only when the MySQL container initializes a fresh/empty data directory**.
- In practical terms this happens when:
  - the MySQL container is created with a new/empty `/var/lib/mysql`
  - a persistent volume is removed/reset

If the MySQL container is restarted with an already-initialized datadir, the `/docker-entrypoint-initdb.d/` scripts are typically **not re-run**.

### How data is loaded

- Uses **server-side** loading:
  - `LOAD DATA INFILE '/var/lib/mysql-files/<table>.csv' ...`
- This relies on:
  - `secure_file_priv` permitting `/var/lib/mysql-files/`
  - the per-table CSVs being present in the MySQL container filesystem (usually via a volume mount)

### Pros

- Great for **first-time setup** and predictable provisioning.
- Works without exposing any “dangerous” import capability in the web UI.

### Cons

- Not convenient for frequent reloads (often implies container lifecycle steps).
- Generally tied to infrastructure actions (rebuild/recreate container, reset volume, rerun playbooks, etc.).

---

## Method 2: Admin-triggered runtime import (web admin, Option A)

### Where it lives

- Runs from the **Apache/PHP container** via an admin-only endpoint:
  - planned endpoint: `import_database.php`
- The admin UI (`admin.php`) provides a CSV upload control and triggers the import.

### When it runs

- Runs **on demand** while the system is live:
  - admin uploads a new source CSV
  - admin triggers “wipe + reload”

### How preprocessing works

- Uploads a single source CSV (typically the canonical `database.csv`).
- Runs the existing preprocessing script:
  - `mysqlPrep_full.py`
- That script generates normalized per-table CSVs (e.g., `sessions.csv`, `songs.csv`, etc.) in a job directory.

### Minimum required headers in the uploaded source CSV

The uploaded source CSV must include a header row. The admin UI performs a preflight check before starting an import.

Minimum required headers:

- `t_title` (sessions title)
- `d_date` (session date)
- `d_merged_song_lists` (songs source)
- `f_singles` (files source)

### How data is loaded

- Uses **client-side** loading from the Apache container into the MySQL server:
  - `LOAD DATA LOCAL INFILE ...`

This relies on two independent settings:

- **Server-side gate**: MySQL must have `local_infile=ON`
- **Client-side gate**: the mysql client must be invoked with `--local-infile=1` (client defaults often show `local-infile FALSE`)

With LOCAL INFILE, the CSV files do **not** need to be mounted into the MySQL container. The mysql client streams the file contents over the connection.

### Wipe + reseed behavior

The runtime import will:

- Truncate tables in the same safe order used by the existing admin “clear media” endpoint (`clear_media.php`).
- Reseed reference data such as `genres` and `styles` (matching the seed behavior in `01-load_and_transform.sql`).

### Pros

- **No Ansible run** required to reload data.
- **No shared mount** required to place per-table CSVs into the MySQL container.
- Much better admin workflow: upload CSV → click reload → done.

### Cons

- Must be carefully protected (admin-only), since it is a destructive operation.
- Requires runtime tooling in the Apache container:
  - `python3` + `pandas` (for preprocessing)
  - `mysql` client (recommended for predictable `LOAD DATA LOCAL INFILE` execution)

---

## Summary Table

| Aspect | MySQL initialization load | Admin-triggered runtime import (Option A) |
|---|---|---|
| Trigger | MySQL entrypoint on fresh datadir | Admin action in web UI |
| Best for | First install / provisioning | Ongoing admin reloads |
| Data file location | Must be readable by MySQL server (`/var/lib/mysql-files/`) | Can remain on Apache container; streamed via LOCAL |
| Load statement | `LOAD DATA INFILE` | `LOAD DATA LOCAL INFILE` |
| Requires shared mount into MySQL | Typically yes | No |
| Requires `local_infile` | No | Yes (server ON + client `--local-infile=1`) |

---

## Operational Notes

- `LOAD DATA INFILE` working does **not** imply `local_infile` is enabled. `local_infile` is only relevant to `LOAD DATA LOCAL INFILE`.
- For the official `mysql:8.0` image, mysqld reads includes from:
  - `/etc/my.cnf` and `!includedir /etc/mysql/conf.d/`
  Mount custom config files into `/etc/mysql/conf.d/` to ensure settings like `local-infile=1` are applied.
