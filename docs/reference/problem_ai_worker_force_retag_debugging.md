# AI Worker Force Re-tag Debugging Session
**Date:** 2026-04-29

## Problem
The "Force Re-tag All" feature was showing incorrect job counts (e.g. "28 of 53" instead of "23 of 23"), some jobs were failing with file-not-found errors, and the progress UI lost state on page refresh.

---

## Debugging Commands Used

### 1. Check DB state — recent categorize_video jobs
```bash
docker exec mysqlServer mysql -u root -p$(grep MYSQL_ROOT_PASSWORD /home/ubuntu/gighive/ansible/roles/docker/files/apache/externalConfigs/.env | cut -d= -f2) music_db -e "
SELECT id, status, target_id, attempts, LEFT(COALESCE(error_msg,''), 60) as error
FROM ai_jobs
WHERE job_type='categorize_video'
ORDER BY id DESC LIMIT 35;"
```
**Finding:** Revealed job counts, statuses, and truncated error messages. Identified jobs stuck in `running` with file-not-found errors.

---

### 2. Show available databases (when DB name was unknown)
```bash
docker exec mysqlServer mysql -u root -p$(grep MYSQL_ROOT_PASSWORD /home/ubuntu/gighive/ansible/roles/docker/files/apache/externalConfigs/.env | cut -d= -f2) -e "SHOW DATABASES;"
```
**Finding:** DB is named `music_db`, not `gighive`.

---

### 3. Look up asset details for a failing job
```bash
docker exec mysqlServer mysql -u root -p$(grep MYSQL_ROOT_PASSWORD /home/ubuntu/gighive/ansible/roles/docker/files/apache/externalConfigs/.env | cut -d= -f2) music_db -e "
SELECT asset_id, source_relpath, checksum_sha256, file_ext
FROM assets WHERE asset_id = 37;"
```
**Finding:** Asset 37 is `Downloads/tutorials/c4bd861d...mp4` with checksum `c4bd861d33bf97b1a2415b8f16325907dd4f72e9b60398c55219abd52ee1d13b`.

---

### 4. Get the full (untruncated) error message for failing jobs
```bash
docker exec mysqlServer mysql -u root -p$(grep MYSQL_ROOT_PASSWORD /home/ubuntu/gighive/ansible/roles/docker/files/apache/externalConfigs/.env | cut -d= -f2) music_db -e "
SELECT id, error_msg FROM ai_jobs WHERE id IN (58, 49) \G"
```
**Finding:** Full error was `[Errno 2] No such file or directory: '/data/ai_assets/diagnostics'` — the `diagnostics` subdirectory did not exist in the ai-worker container.

---

### 5. Verify the video file exists on the host
```bash
ls -lh /home/ubuntu/video/c4bd861d33bf97b1a2415b8f16325907dd4f72e9b60398c55219abd52ee1d13b.mp4
```
**Finding:** File present, 245M. The video itself was not the problem.

---

### 6. Verify the video file is visible inside the ai-worker container
```bash
docker exec ai-worker ls -lh /data/video/c4bd861d33bf97b1a2415b8f16325907dd4f72e9b60398c55219abd52ee1d13b.mp4
```
**Finding:** File present at `/data/video/` inside the container (245M). Volume mount is correct.

---

### 7. Inspect the ai_assets directory structure inside the container
```bash
docker exec ai-worker ls -la /data/ai_assets/
```
**Finding:**
```
drwxr-xr-x  diagnostics   (created 17:05 — after container rebuild)
drwxr-xr-x  frames
drwxr-xr-x  thumbnails
```
The `diagnostics` directory **did not exist before the container rebuild**. It was created at 17:05 when the playbook was rerun. All prior failures (jobs 47, 48, 49, 56, 57, 58) were caused by this missing directory.

---

### 8. Find running Docker containers (when container name was unknown)
```bash
docker ps --format '{{.Names}}' | grep -i ai
docker ps --format '{{.Names}}' | grep -i mysql
```
**Finding:** AI worker container is named `ai-worker`; MySQL container is named `mysqlServer`.

---

## Root Cause Summary

| Issue | Root Cause | Fix |
|---|---|---|
| Jobs 47, 48, 49, 56, 57, 58 failed | `/data/ai_assets/diagnostics` missing in container | Resolved by rebuilding the container via Ansible playbook |
| Progress bar showed "of 53" instead of "of 23" | Poll counted all historical jobs, not just the current batch | API now returns `job_ids`; JS filters poll results to those IDs |
| Progress UI lost state on page refresh | State was only in JS memory | PHP queries active job IDs on page load and auto-starts polling |
| `retag_all` batch total off by one | Already-running jobs were excluded from `job_ids` response | API now merges newly created IDs with skipped-but-active IDs |
