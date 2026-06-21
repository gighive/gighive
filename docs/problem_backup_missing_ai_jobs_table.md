# Problem: Backup Restore Fails When Backup Predates AI Tables

**Date:** 2026-06-21  
**Environment:** dev (gighive2)  
**Symptom:** `clear_media.php` returns HTTP 500 with `SQLSTATE[42S02]: Base table or view not found: 1146 Table 'music_db.ai_jobs' doesn't exist`

---

## Symptom

After the Playwright admin regression test ran Step 6 (Restore Database From Backup), Step 7 (Clear All Media Data) failed with:

```
Error: Failed to clear media tables: SQLSTATE[42S02]:
Base table or view not found: 1146 Table 'music_db.ai_jobs' doesn't exist
```

The Playwright test timed out at `#clearMediaStatus .alert-ok` after 60 seconds.

---

## Root Cause

`clear_media.php` uses `SHOW TABLES` to dynamically discover all tables and then `TRUNCATE`s each one (except `users`). This is safe immediately after a restore because it reflects the actual schema on disk.

The failure occurred because the database had previously been restored from an **old backup predating the AI feature** (`docs/feature_ai_video_tagger.md`). That backup did not include the five AI tables added in the AI tagger feature:

- `ai_jobs`
- `helper_runs`
- `derived_assets`
- `tags`
- `taggings`

The Playwright test then:

1. **Step 4** — Cleared the media data (succeeded, but the DB still lacked AI tables)
2. **Step 5** — Created a backup of the now-empty, schema-incomplete DB (3.3 KB)
3. **Step 6** — Restored from that backup → DB still missing AI tables
4. **Step 7** — Called `clear_media.php` → `SHOW TABLES` returned a list without `ai_jobs` → `TRUNCATE ai_jobs` → **1146 error**

---

## Resolution

Restored the database from a **recent full backup** (`music_db_2026-06-21_111126.sql.gz`, 742.3 KB) that includes all 15 current tables. Re-ran the Playwright tests — both passed.

---

## Prevention

- Always restore from a backup dated **after 2026-06-14** (the date `ai_jobs` et al. were introduced).
- A backup smaller than ~100 KB is a strong indicator it is schema-only (empty data). Do not use these to recover a production system.
- If running a full site rebuild (`ansible-playbook site.yml`), the `db_migrations` role ensures the correct schema is applied from `create_music_db.sql`, so a missing-table condition cannot arise from a fresh build — only from restoring an old dump.

---

## Side Effect: Playwright Tests Leave Small Schema-Only Backups

The Playwright admin regression test creates a backup in **Step 5** specifically to test the restore flow. Because Step 4 clears all media data first, the backup created by Step 5 contains only the schema (CREATE TABLE statements, no rows). This produces a small ~3.3 KB file in the backup list, e.g.:

```
music_db_2026-06-21_113622.sql.gz  (3.3 KB)   ← Playwright test artifact
music_db_2026-06-21_113614.sql.gz  (742.3 KB) ← real backup
```

These files are **valid schema-complete backups** but contain no media data. They are harmless but should not be used to recover a system that has real content. They can be identified by their small size and the fact that they appear at test run times (not at the `02:10` scheduled cron time).
