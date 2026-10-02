# Upload File States — Definition and Sequence

Applies to Section A and Section B Step 2 on `/admin/admin_database_load_import_media_from_folder.php`.

## State Sequence (happy path)

```
pending → uploading → uploaded → db_done → thumbnail_done
```

| State | Meaning |
|-------|---------|
| `pending` | File queued, not yet sent to server |
| `uploading` | TUS chunked upload in progress |
| `uploaded` | TUS transfer complete; DB write not yet confirmed |
| `db_done` | Asset row written to DB; thumbnail generation not yet confirmed |
| `thumbnail_done` | Fully complete — asset in DB with probe metadata and thumbnail |
| `already_present` | Checksum already existed in DB; skipped |
| `failed` | Upload or DB write failed (see retryable vs. permanent below) |

## Failure Types

| Type | Meaning | Action |
|------|---------|--------|
| Retryable | Transient error (network, timeout); `retry_count < 3` | Hit "Resume Upload" |
| Permanent | Non-recoverable (e.g. corrupt file, local file missing) | Resolve manually |

## Poll Race — Why "pending" Count Can Jump Up

The browser polls `import_manifest_upload_status.php` every few seconds while uploads are in
flight. In rare cases the poll response can overwrite a just-completed file's in-memory state
back to `pending`. This is cosmetic — the file was already uploaded. Hitting "Resume Upload"
re-evaluates the actual server state and skips files already present on disk (TUS resumes
from the byte offset already written).

**Symptom:** Upload appeared to have 77 files left; after "Restart Upload" showed 175+ pending.
**Cause:** Poll race overwrote completed state on the prior stall/network blip.
**Action:** Let it run — TUS resumes partial uploads, does not re-upload from scratch.

## Verifying True State Server-Side

```bash
docker exec apacheWebServer cat /var/www/private/import_jobs/<job_id>/upload_status.json \
  | python3 -m json.tool | grep -E '"state"' | sort | uniq -c
```

Find the current job_id from the Step 1 banner on the import page, or:
```bash
docker exec apacheWebServer ls -lt /var/www/private/import_jobs/ | head -5
```

## Verifying True State in DB

```bash
docker exec mysqlServer mysql -u root \
  -p$(grep MYSQL_ROOT_PASSWORD ~/gighive/ansible/roles/docker/files/apache/externalConfigs/.env | cut -d= -f2) \
  music_db -e "SELECT COUNT(*) total,
    SUM(duration_seconds IS NOT NULL) probed,
    SUM(duration_seconds IS NULL) stubs
  FROM assets WHERE file_type='video';"
```

`stubs` (duration_seconds IS NULL) = files TUS received but `ingestComplete` hasn't run yet.
These resolve automatically as the upload completes.

## Related Docs

- `docs/guide_ai_worker_tagging.md` — what happens after upload completes (AI tagging)
- `docs/problem_missing_api_key_for_ai_worker.md` — crash loop / veth churn problem
