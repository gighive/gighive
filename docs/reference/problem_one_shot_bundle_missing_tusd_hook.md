# Problem: one-shot bundle uploads fail with 500 on finalize — missing tusd post-finish hook

## Symptom

After a TUS chunked upload completes successfully (all `PATCH /files/{id}` requests return 204),
the subsequent `POST /api/uploads/finalize` returns HTTP 500 with response body:

```json
{"error":"Server Error","message":"Upload not found or not finished yet"}
```

All prior PATCH chunks succeed; only finalize fails.

## Root causes

Two separate defects combined to cause the failure. Both had to be fixed.

### Defect 1: stale assembled bundle (hooks dir empty)

The assembled `gighive-one-shot-bundle/tusd/hooks/` directory was empty — it did not contain the
`post-finish` script.

tusd is launched with `-hooks-dir=/hooks` (bind-mounted from `tusd/hooks/`). With no `post-finish`
executable present, tusd silently skips the hook after each upload finishes. Nothing writes the
upload JSON payload to the shared `tus_hooks` named volume.

When `POST /api/uploads/finalize` runs, `UploadService::finalizeTusUpload()` looks for:

```
/var/www/private/tus-hooks/uploads/{upload_id}.json
```

That file never exists, so the method throws `RuntimeException('Upload not found or not finished yet')`,
which the controller catches and returns as a 500.

The `post-finish` script lives in the full-build source at
`ansible/roles/docker/files/tusd/hooks/post-finish`. The bundle was a stale snapshot that predated
the hook being added to the source tree and had not been regenerated.

A secondary issue was that `one_shot_bundle_input_paths` listed the child path
`ansible/roles/docker/files/tusd/hooks` rather than the parent `ansible/roles/docker/files/tusd`.
This was replaced with the parent path to be more future-proof.

### Defect 2: missing execute bit on the source hook file

Even after the bundle was regenerated with `post-finish` present, the file had mode `664`
(`rw-rw-r--`) — no execute bit. tusd could read but not execute it, so the hook still never ran.

Verified via:
```bash
docker exec apacheWebServer_tusd ls -la /hooks/
# -rw-rw-r--    1 tusd     tusd          2267 Feb  1 18:19 post-finish
```

The source file `ansible/roles/docker/files/tusd/hooks/post-finish` was committed without the
executable bit. Since the bundle assembly copies files with `mode: preserve`, the 664 mode
propagated into every assembled bundle.

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

### 1. Source: add execute bit to hook script

```bash
chmod +x ansible/roles/docker/files/tusd/hooks/post-finish
```

Git tracks the execute bit, so this persists for all future bundle assemblies.

### 2. Live instance: chmod the running bundle's copy

```bash
chmod +x /tmp/gighive-one-shot-bundle/tusd/hooks/post-finish
```

Because `tusd/hooks` is a bind mount (not a named volume), tusd picks up the change
immediately without a container restart.

### 3. Source: replace child path with parent in `one_shot_bundle_input_paths`

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
`tusd/hooks/post-finish` with executable permissions.

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
