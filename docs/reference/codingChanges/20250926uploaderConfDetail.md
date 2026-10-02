# Changes on 2025-09-26

This document summarizes the code changes applied during this round. All paths are relative to the repository root `gighive/`.

## Summary of Modified Files

- `ansible/roles/docker/templates/default-ssl.conf.j2`
  - Centralized per-URL authentication rules in the vhost:
    - `/db/upload_form.php` → `Require user admin uploader`
    - `/db/upload_form_admin.php` → `Require user admin`
    - `/api/uploads.php` → `Require user admin uploader`
  - Kept existing `LocationMatch` with `Require valid-user` for general browsing.

- `ansible/roles/docker/templates/apache2.conf.j2`
  - Removed overlapping auth block for `/db/upload_form_admin.php` to avoid duplication and keep auth logic in one place (the vhost).

- `ansible/inventories/group_vars/gighive.yml`
  - Added uploader account variables:
    - `uploader_user: uploader`
    - `gighive_uploader_password: "secretuploader"` (recommend moving to Vault)

- `ansible/roles/security_basic_auth/tasks/main.yml`
  - Host htpasswd:
    - Create/update `uploader` in host htpasswd (bcrypt) when vars defined.
  - Container htpasswd:
    - Ensure `uploader` in container htpasswd (bcrypt).
  - Verification (htpasswd):
    - Added `Verify uploader (host)` and extended facts `uploader_ok`.
  - HTTP probes:
    - Added `Probe as uploader (expect 200)` and extended assertions to include `uploader_http_ok`.

- `ansible/roles/docker/files/apache/webroot/api/uploads.php`
  - Added HTML confirmation mode after POST (based on `Accept: text/html` or `?ui=html`).
  - HTML includes a link to the database:
    - If the response body includes an `id`, link to `/db/database.php#media-<id>`.
    - Otherwise, fall back to `/db/database.php#all`.
  - Preserved JSON-by-default behavior and JSON error responses.

- `ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php`
  - Included `f.file_id AS id` in the media listing result set to support row anchors.

- `ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php`
  - Passed through `id` to the view for each row.

- `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`
  - Added anchor `id="all"` to the page header (previously `recent`).
  - Each table row now includes `id="media-<id>"` for deep linking to the newly uploaded item.

## Git Summary

- Current branch: `master`
- Working tree changes (not staged):
  - `ansible/roles/docker/files/apache/webroot/api/uploads.php`
  - `ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php`
  - `ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php`
  - `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`
  - `CHANGELOG.md`

- Diff stat:
```
CHANGELOG.md                                                                     |  4 ++
ansible/roles/docker/files/apache/webroot/api/uploads.php                        | 26 +++++++++++++++++++++++--
ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php    |  2 ++
ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php |  1 +
ansible/roles/docker/files/apache/webroot/src/Views/media/list.php               |  4 ++--
5 files changed, 33 insertions(+), 4 deletions(-)
```

## Post-Deploy Checklist

- Rebuild/reload Apache container so vhost changes take effect.
- Verify access control:
  - Viewer: browse OK; blocked on `/db/upload_form.php` and `/api/uploads.php`.
  - Uploader: browse OK; allowed on `/db/upload_form.php` and `/api/uploads.php`.
  - Admin: allowed everywhere.
- Test upload flow:
  - After upload, confirmation page shows link to DB.
  - Link navigates to `/db/database.php#media-<id>` (or `#all` fallback) and scrolls correctly.
- Run Ansible `security_basic_auth` role to seed/ensure `uploader` in both host and container htpasswd and verify via htpasswd and HTTP probes.
