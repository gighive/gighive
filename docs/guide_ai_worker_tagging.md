# Guide: AI Worker Video Tagging

## How It Works

1. A video file finishes uploading → `ingestComplete()` fires → if `AI_WORKER_ENABLED=true`, a `categorize_video` job is inserted into `ai_jobs` (status=`queued`).
2. The `ai-worker` container polls `ai_jobs` every 5 s, claims the next `queued` job, extracts frames, sends them to the LLM, and writes tags back to the DB.
3. Tags appear in the Media Library "Tags" column.

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

### ❌ No jobs in queue (worker was disabled during upload)
`ingestComplete()` never enqueued jobs. Use the **"Tag N Untagged Assets"** button on `/admin/ai_worker.php`. Idempotent — skips assets that already have an active job.

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
