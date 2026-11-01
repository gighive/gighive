# Guide: AI Worker Video Tagging

## How It Works

1. A video file finishes uploading → `UploadService::handleUpload()` or `ingestComplete()` fires → if `AI_WORKER_ENABLED=true`, a `categorize_video` job is inserted into `ai_jobs` (status=`queued`).
2. The `ai-worker` container polls `ai_jobs` every 5 s, claims the next `queued` job, extracts frames, sends them to the LLM, and writes tags back to the DB.
3. Tags appear in the Media Library "Tags" column.

---

## Ingestion Methods — AI Job Auto-Enqueue Reference

Whether an AI job is automatically enqueued depends on **which ingestion path** was used:

| # | Method | Entry Point | PHP Path | Auto-enqueues AI job? | Manual trigger needed? |
|---|--------|-------------|----------|-----------------------|------------------------|
| 1 | **iPhone / web upload** | `db/upload_form.php` → `POST /uploads` | `UploadService::handleUpload()` | ✅ Yes | No |
| 2 | **Folder import — Add** | `admin_database_load_import_media_from_folder.php` (add mode) | `ingestStub()` → TUS → `finalizeManifestTusUpload()` → `ingestComplete()` | ✅ Yes | No |
| 3 | **Folder import — Reload** | `admin_database_load_import_media_from_folder.php` (reload mode) | Same as above | ✅ Yes | No |
| 4 | **CSV Section A** (legacy single CSV) | `admin_database_load_import_csv.php` → `import_database.php` | Raw SQL via `mysql` shell; assets table not populated | ❌ No | Yes — use **"Tag N Untagged Assets"** on `/admin/ai_worker.php` |
| 5 | **CSV Section B** (normalized 2 CSVs) | `admin_database_load_import_csv.php` → `import_normalized.php` | Raw SQL via `mysql` shell; bypasses PHP service layer | ❌ No | Yes — use **"Tag N Untagged Assets"** on `/admin/ai_worker.php` |

> CSV imports (Sections A and B) write directly to MySQL via the shell client and never pass through the PHP service layer, so no AI jobs are enqueued. After a CSV import, use the bulk enqueue button on `/admin/ai_worker.php` to tag all video assets at once.

---

## Preconditions

- `ai_worker_enabled: true` in `ansible/inventories/group_vars/gighive/gighive.yml`
- `openai_api_key` set (non-empty) in `secrets.yml`
- Playbook run that includes the `docker` tag (regenerates `.env`)

---

## Scenarios

### ✅ Normal: upload ran, worker was healthy
Jobs were auto-enqueued during upload. Worker drains them automatically — no action needed. Monitor at `/admin/ai_worker.php`.

### ⚠️ Worker was crash-looping during upload (e.g. missing API key)
Jobs were still enqueued if `AI_WORKER_ENABLED=true` was set. Once the worker is fixed and healthy it will drain the queue automatically.

Verify jobs exist:
```bash
docker exec mysqlServer mysql -u root \
  -p$(grep MYSQL_ROOT_PASSWORD ~/gighive/ansible/roles/docker/files/apache/externalConfigs/.env | cut -d= -f2) \
  music_db -e "SELECT status, COUNT(*) FROM ai_jobs GROUP BY status;"
```

If `queued` rows exist → worker will pick them up on its own.

#### Sub-case: Jobs enqueued hours/days before the crash loop was fixed
Jobs can sit in `queued` state indefinitely — they are durable in the DB. If a "Force Re-tag All" or "Tag N Untagged Assets" was triggered at any point while `AI_WORKER_ENABLED=true` (even before the crash loop was diagnosed), those jobs remain queued and will be processed automatically once the worker recovers. No re-enqueueing is needed. You may see a large backlog on `/admin/ai_worker.php` with a "previous Force Re-tag job still running" warning — this is expected and normal.

### ❌ No jobs in queue (worker was disabled during upload)
`ingestComplete()` never enqueued jobs. Use the **"Tag N Untagged Assets"** button on `/admin/ai_worker.php`. Idempotent — skips assets that already have an active job.

### ⏳ New upload while a large batch is already in queue
Each file's AI tagging job is enqueued immediately when its TUS upload finalizes (`ingestComplete()` fires per file). However, new jobs go to the **back of the queue** behind any existing backlog (e.g. a Force Re-tag batch). The newly uploaded files will not receive tags until the backlog ahead of them drains.

This is expected behavior — nothing is wrong. Monitor queue position on `/admin/ai_worker.php`. The "Queued jobs" count will tick up slightly as each new file finalizes, confirming jobs are being enqueued correctly.

### 🔄 Force re-tag after model or config change
Use the **"Force Re-tag All"** button on `/admin/ai_worker.php`. Re-enqueues all video assets including previously tagged ones.

---

## Job States

| Status | Meaning |
|--------|---------|
| `queued` | Waiting to be claimed by the worker |
| `running` | Worker actively processing |
| `done` | Tagged successfully |
| `failed` | Permanent failure (e.g. corrupt file); see `error_msg` column |

A transient failure (e.g. network blip) resets the job to `queued` for retry. After `AI_WORKER_MAX_ATTEMPTS` (default: 3) it is marked `failed` permanently.

---

## Quick Diagnostics

```bash
# Is the worker healthy?
docker ps --format '{{.Names}}\t{{.Status}}' | grep ai-worker

# Recent worker logs
docker logs ai-worker --tail 30

# Job queue snapshot
docker exec mysqlServer mysql -u root \
  -p$(grep MYSQL_ROOT_PASSWORD ~/gighive/ansible/roles/docker/files/apache/externalConfigs/.env | cut -d= -f2) \
  music_db -e "SELECT id, status, target_id, attempts, LEFT(COALESCE(error_msg,''),60) FROM ai_jobs ORDER BY id DESC LIMIT 20;"

# Verify API key is set in container
docker exec ai-worker printenv | grep OPENAI_API_KEY
```

---

## Related Docs

- `docs/problem_missing_api_key_for_ai_worker.md` — empty `OPENAI_API_KEY` crash-loop
- `docs/problem_ai_worker_force_retag_debugging.md` — force re-tag debugging session
- `docs/feature_ai_video_tagger.md` — full technical spec
