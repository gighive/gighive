# Problem: `www-data` ownership in one-shot bundle `/tmp` output causes `rmtree` failure

## Symptom

After performing a real upload through the one-shot bundle installation at `/tmp/gighive-one-shot-bundle`, the next Ansible playbook run fails at the cleanup step with:

```
TASK [one_shot_bundle : Remove existing fresh one-shot bundle output directory (controller)]
fatal: [gighive_vm -> localhost]: FAILED! => {"changed": false, "msg": "rmtree failed: [Errno 13] Permission denied: '96d354f09b6b7d6f7cfd743614d65271a938f96a93ab2b5c1c4842ed1dc6d32d.mp4'"}
```

## Root cause

`ansible/roles/one_shot_bundle/tasks/output_bundle.yml` creates the runtime media dirs (`_host_audio`, `_host_video`, `_host_video/thumbnails`) with:

- `group: "www-data"`
- `mode: "2775"` (setgid, rwxrwsr-x)

It then normalizes all dirs and files under those paths with `chgrp www-data`.

This is appropriate for production Ubuntu hosts (where `www-data` group always exists and Ansible runs with `become`), but the one-shot bundle output tasks run entirely on the **controller** with `delegate_to: localhost, become: false`.

When the bundle is used as a live test runtime and a file is uploaded via `UploadService.php`:

- PHP-FPM runs as `www-data` (UID 33) inside the Apache container
- The uploaded file (e.g. `{sha256}.mp4`) is created on the host as UID 33
- On the next Ansible run, the "Remove existing output directory" task calls Python's `shutil.rmtree` as the controller user
- The controller user cannot delete files from dirs that were group-normalized to `www-data` after uploads created subdirectory trees owned by `www-data`

## Why this does not affect the full build

| Factor | One-shot bundle `output_bundle.yml` | Full build `docker` role |
|---|---|---|
| Where tasks run | `delegate_to: localhost, become: false` (controller) | Directly on Ubuntu target host |
| `www-data` group guaranteed? | No — fails on macOS, dev machines | Yes — standard Ubuntu |
| Privilege level | No sudo | `become: true` available |
| Purpose of dirs | Temporary `/tmp` test output, cleaned up each run | Persistent production runtime dirs |
| Cleanup via `rmtree`? | Yes — role removes and recreates on every run | No — accumulates uploaded files intentionally |

For the full build, `www-data` group ownership + `2775` is the **correct** production security posture and should not change.

## Fix (applied to `ansible/roles/one_shot_bundle/tasks/output_bundle.yml`)

For the controller-side `/tmp` output bundle, replace `www-data` group semantics with portable world-writable permissions.

### Why `0777` for dirs

- Container `www-data` (UID 33) can write to `0777` dirs without any group dependency ✓
- `UploadService.php` can create new files in the dir ✓
- Thumbnails generated into `_host_video/thumbnails/` work correctly ✓
- Controller user can delete `www-data`-owned files from `0777` dirs (no sticky bit) ✓
- No dependency on `www-data` group existing on the controller (fixes macOS portability) ✓

### Why `0644` for existing media files

- `0644` (world-readable) is sufficient for Apache to serve files to the browser ✓
- Files do not need to be world-writable — PHP creates new files, it does not overwrite sample files ✓
- More conservative than `0666` for media assets ✓

### Changes

**1. Dir creation** — remove `group: "www-data"`, change mode to `0777`:

```yaml
# Before
group: "www-data"
mode: "2775"

# After
mode: "0777"
```

**2. Dir normalization** — drop `chgrp`, use `chmod 0777`, add `_host_video/thumbnails` to loop:

```yaml
# Before
find ... -type d -exec chgrp www-data {} \; -exec chmod 2775 {} \;
loop: [_host_audio, _host_video]

# After
find ... -type d -exec chmod 0777 {} \;
loop: [_host_audio, _host_video, _host_video/thumbnails]
```

**3. File normalization** — drop `chgrp`, use `chmod 0644`, add `_host_video/thumbnails` to loop:

```yaml
# Before
find ... -type f -exec chgrp www-data {} \; -exec chmod 0664 {} \;
loop: [_host_audio, _host_video]

# After
find ... -type f -exec chmod 0644 {} \;
loop: [_host_audio, _host_video, _host_video/thumbnails]
```

## One-time recovery step (if `/tmp/gighive-one-shot-bundle` already has `www-data`-owned files)

The removal task runs before any permission normalization. If the existing `/tmp` dir already contains `www-data`-owned files from prior uploads, manually delete it first:

```bash
sudo rm -rf /tmp/gighive-one-shot-bundle
```

After that, the fix ensures all future runs clean up correctly without sudo.
