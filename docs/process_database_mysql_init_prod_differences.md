# Process: Prod MySQL Rebuild Behavior — CSV Seed Data Differences

## Background

When `rebuild_mysql_data: true` is set in group_vars and the Ansible playbook runs, the
docker role:

1. Stops the MySQL container (`state: absent`)
2. Removes the named Docker volume `files_mysql_data` (`state: absent`)
3. Starts Docker Compose — MySQL detects an empty data directory and runs all files
   in `docker-entrypoint-initdb.d/` in order:
   - `00-create_media_db.sql` — schema DDL
   - `01-load_and_transform.sql` — data load from CSV files

This means the DB is **not empty after a rebuild** — it is repopulated from the CSV
seed data automatically.

---

## Why Prod Behaves Differently from Other Environments

The key difference is the `database_full` group_vars flag:

| Environment | `database_full` | Data after rebuild |
|---|---|---|
| dev (gighive2) | `false` | ~2 sample sessions — appears "wiped" |
| lab / staging | `false` | ~2 sample sessions — appears "wiped" |
| prod | `true` | Full historical dataset (~137 events, ~680 assets) |

`load_and_transform.sql` uses `LOAD DATA INFILE` from CSV files mounted at
`/var/lib/mysql-files/` inside the container. These CSVs are on a **separate bind
mount** — not part of the `files_mysql_data` volume — so they survive the volume wipe
and are available during fresh initialization.

On prod with `database_full: true`, this means the full Stormpigs catalog comes back
automatically. On dev/lab/staging with `database_full: false`, only the minimal sample
data comes back, which looks like a clean wipe.

---

## What the CSV Seed Data Does NOT Contain

The CSV data is a static snapshot generated at a point in time. It does **not** include
any live data created after the last CSV generation run, such as:

- QR upload tokens (`event_upload_tokens`)
- Guest upload attributions (`anon_upload_attributions`)
- Upload jobs (`upload_jobs`)
- AI jobs, tags, taggings (`ai_jobs`, `tags`, `taggings`, `derived_assets`)
- Any events or assets created via the app after the last CSV sync

This is why BABRR Step 5 (restore from the post-migration backup) is **always
required on prod** — even though the catalog data appears to come back from the
CSV seed, any live transactional data is missing until the restore is applied.

---

## Before/After Restore Comparison (Observed Jul 11 2026)

The following was captured via MCP before and after the Step 5 restore to illustrate
the difference:

| Metric | Pre-restore (CSV seed) | Post-restore (live backup) | Δ |
|---|---|---|---|
| `events` count | 137 | 141 | +4 |
| `max_event_id` | 137 | 142 | +5 |
| `max_event_date` | 2026-07-11 (stub) | 2026-06-24 | changed |
| `assets` count | 680 | 680 | — |
| `max_asset_id` | 693 | 693 | — |
| `ai_jobs` | 0 | 0 | — |
| `tags` / `taggings` | 0 / 0 | 0 / 0 | — |
| `event_upload_tokens` | 0 | 0 | — |
| `anon_upload_attributions` | 0 | 0 | — |
| `upload_jobs` | 0 | 0 | — |

**Observations:**

- The pre-restore state had a spurious `event_id=137` with `event_date=2026-07-11`
  (today's date at time of rebuild) and `org_name=gighive` — this was a CSV artifact
  and was correctly overwritten by the restore
- Events 138–142 (`org=gighive`, dates 2026-05-30 through 2026-06-24, no title/location)
  were QR test events created in live prod after the last CSV generation; these came back
  only after the restore
- The upload/AI tables remaining at zero is expected for this environment — prod is a
  static Stormpigs music video catalog with no guest upload activity at this time

---

## Recommended Snapshot Query for Before/After Comparison

Run via MCP (or directly on the docker host) before and after a BABRR restore:

```sql
SELECT
  (SELECT COUNT(*) FROM events) AS events,
  (SELECT MAX(event_id) FROM events) AS max_event_id,
  (SELECT MAX(event_date) FROM events) AS max_event_date,
  (SELECT COUNT(*) FROM assets) AS assets,
  (SELECT MAX(asset_id) FROM assets) AS max_asset_id,
  (SELECT COUNT(*) FROM event_upload_tokens) AS event_upload_tokens,
  (SELECT COUNT(*) FROM anon_upload_attributions) AS anon_upload_attributions,
  (SELECT COUNT(*) FROM upload_jobs) AS upload_jobs,
  (SELECT COUNT(*) FROM ai_jobs) AS ai_jobs,
  (SELECT COUNT(*) FROM tags) AS tags,
  (SELECT COUNT(*) FROM taggings) AS taggings,
  (SELECT COUNT(*) FROM derived_assets) AS derived_assets;
```

---

## Related Documentation

- `docs/process_backup_alter_backup.md` — BABRR process (Backup → Alter → Backup → Rebuild → Restore)
- `docs/process_mysql_init.md` — how MySQL initialisation works internally
- `docs/guide_docker_compose_behavior.md` — `rebuild_mysql_data` flag mechanics
