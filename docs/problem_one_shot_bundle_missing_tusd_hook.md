# Problem: one-shot bundle uploads fail with 500 on finalize — missing tusd post-finish hook

## Symptom

After a TUS chunked upload completes successfully (all `PATCH /files/{id}` requests return 204),
the subsequent `POST /api/uploads/finalize` returns HTTP 500 with response body:

```json
{"error":"Server Error","message":"Upload not found or not finished yet"}
```

All prior PATCH chunks succeed; only finalize fails.

## Root cause

The assembled `gighive-one-shot-bundle/tusd/hooks/` directory was **empty** — it did not contain
the `post-finish` hook script.

tusd is launched with `-hooks-dir=/hooks` (bind-mounted from `tusd/hooks/`). Without a `post-finish`
executable in that directory, tusd silently skips the hook after each upload finishes. Nothing writes
the upload JSON payload to the shared `tus_hooks` named volume.

When `POST /api/uploads/finalize` runs, `UploadService::finalizeTusUpload()` looks for:

```
/var/www/private/tus-hooks/uploads/{upload_id}.json
```

That file never exists, so the method throws `RuntimeException('Upload not found or not finished yet')`,
which the controller catches and returns as a 500.

### Why the hook was missing

The `post-finish` script lives in the full-build source at:

```
ansible/roles/docker/files/tusd/hooks/post-finish
```

The one-shot bundle assembly role (`ansible/roles/one_shot_bundle`) scans
`one_shot_bundle_input_paths` and strips the `ansible/roles/docker/files/` prefix to produce
bundle-relative paths. The `post-finish` script correctly maps to `tusd/hooks/post-finish` in
the assembled bundle.

However, the previously assembled bundle (`gighive-one-shot-bundle/`) was a stale snapshot that
predated the `post-finish` script being written. The bundle had not been regenerated after the
hook was added to the source tree, so the runtime `tusd/hooks/` directory ended up empty.

A secondary issue was that `one_shot_bundle_input_paths` listed the child path
`ansible/roles/docker/files/tusd/hooks` rather than the parent `ansible/roles/docker/files/tusd`.
This was replaced with the parent path to capture any future tusd files (config, additional hooks)
without requiring another input path entry.

## Data flow reminder

```
tusd PATCH chunks → complete upload → post-finish hook runs
  → writes /hook-out/uploads/{id}.json  (tus_hooks named volume)
  → Apache container sees same volume at /var/www/private/tus-hooks/uploads/{id}.json

POST /api/uploads/finalize
  → reads hook JSON → reads /var/www/private/tus-data/{id} (tusd_data named volume)
  → calls handleUpload() → moves file, writes DB record → returns 201
```

## Fixes applied

### 1. Immediate: copy hook into the running bundle instance

```bash
cp ansible/roles/docker/files/tusd/hooks/post-finish \
   /tmp/gighive-one-shot-bundle/tusd/hooks/post-finish
chmod +x /tmp/gighive-one-shot-bundle/tusd/hooks/post-finish
```

Because `tusd/hooks` is a bind mount (not a named volume), tusd picks up the script
immediately without a container restart.

### 2. Source: replace child path with parent in `one_shot_bundle_input_paths`

In all three group_vars files (`gighive/gighive.yml`, `gighive2/gighive2.yml`, `prod/prod.yml`),
replaced:

```yaml
- "{{ repo_root }}/ansible/roles/docker/files/tusd/hooks"
```

with:

```yaml
- "{{ repo_root }}/ansible/roles/docker/files/tusd"
```

The next Ansible playbook run will assemble a fresh bundle that correctly includes
`tusd/hooks/post-finish`.

## Verification

After copying the hook, retry a file upload through the UI. The finalize request should return
HTTP 201 instead of 500.

To confirm the hook is running, check its debug log inside the running bundle:

```bash
docker exec apacheWebServer_tusd cat /hook-out/hook-debug.log
```

Each successful post-finish invocation appends a line like:

```
2026-04-03T20:48:29Z 33:33 hook=post-finish has_TUS_ID=1 ... mv_ok=1 outfile_size=NNN
```
