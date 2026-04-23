# Setting Upload Limits

## Quickstart bundle

The PHP upload limits are **baked into `apache/Dockerfile` at bundle-creation time**.

Set `upload_max_bytes` in your group_vars **before** running `output_bundle.yml`. Changing it after a bundle has been distributed has no effect on existing installs — you must rebuild the bundle.

## Full Ansible build

Set `upload_max_bytes` in your group_vars file (e.g. `ansible/inventories/group_vars/gighive/gighive.yml`). The value is applied at deploy time on each `site.yml` run.

## What one variable controls

| Enforcement point | Mechanism |
|---|---|
| ModSecurity body limit | `{{ upload_max_bytes }}` in `modsecurity.conf.j2` |
| App validator cap (`UploadValidator.php`) | `UPLOAD_MAX_BYTES=` in `.env.j2` |
| PHP `upload_max_filesize` / `post_max_size` | Derived in `Dockerfile.j2` (4% headroom above `upload_max_bytes`) |

PHP limits are set slightly above `upload_max_bytes` so PHP never becomes the binding constraint before ModSecurity or the app validator enforce the limit.
