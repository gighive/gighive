# Refactor Plan: Move Root-Level Admin PHP Files to /admin/ Folder

## Background

A security audit (March 2026) revealed that 21 root-level PHP files require admin authentication via PHP's `$_SERVER['PHP_AUTH_USER']` check but had no corresponding Apache `Location` or `LocationMatch` auth block. Without an Apache auth block, `PHP_AUTH_USER` is never populated — even when an `Authorization` header is sent — because Apache only sets that variable for paths it actively authenticates.

A quick-fix `LocationMatch` block was added to `default-ssl.conf.j2` as an interim measure. This refactor moves the files to a dedicated `/admin/` subdirectory so that a single, clean Apache `Location /admin/` block covers all admin endpoints by path, and the per-file PHP auth checks become a redundant second layer of defense.

## Files to Move

All files currently at webroot root level that check `$user !== 'admin'`:

| Current path | Target path |
|---|---|
| `webroot/import_manifest_add.php` | `webroot/admin/import_manifest_add.php` |
| `webroot/import_manifest_add_async.php` | `webroot/admin/import_manifest_add_async.php` |
| `webroot/import_manifest_cancel.php` | `webroot/admin/import_manifest_cancel.php` |
| `webroot/import_manifest_duplicates.php` | `webroot/admin/import_manifest_duplicates.php` |
| `webroot/import_manifest_finalize.php` | `webroot/admin/import_manifest_finalize.php` |
| `webroot/import_manifest_jobs.php` | `webroot/admin/import_manifest_jobs.php` |
| `webroot/import_manifest_prepare.php` | `webroot/admin/import_manifest_prepare.php` |
| `webroot/import_manifest_reload.php` | `webroot/admin/import_manifest_reload.php` |
| `webroot/import_manifest_reload_async.php` | `webroot/admin/import_manifest_reload_async.php` |
| `webroot/import_manifest_replay.php` | `webroot/admin/import_manifest_replay.php` |
| `webroot/import_manifest_status.php` | `webroot/admin/import_manifest_status.php` |
| `webroot/import_manifest_upload_finalize.php` | `webroot/admin/import_manifest_upload_finalize.php` |
| `webroot/import_manifest_upload_start.php` | `webroot/admin/import_manifest_upload_start.php` |
| `webroot/import_manifest_upload_status.php` | `webroot/admin/import_manifest_upload_status.php` |
| `webroot/import_database.php` | `webroot/admin/import_database.php` |
| `webroot/import_normalized.php` | `webroot/admin/import_normalized.php` |
| `webroot/admin_database_load_import.php` | `webroot/admin/admin_database_load_import.php` |
| `webroot/admin_database_load_import_media_from_folder.php` | `webroot/admin/admin_database_load_import_media_from_folder.php` |
| `webroot/clear_media.php` | `webroot/admin/clear_media.php` |
| `webroot/clear_media_files.php` | `webroot/admin/clear_media_files.php` |
| `webroot/write_resize_request.php` | `webroot/admin/write_resize_request.php` |

`webroot/admin.php` stays at its current path (it is the main admin UI page and has its own explicit Location block).

## Apache Configuration Change

### Remove (interim quick-fix block)

In `ansible/roles/docker/templates/default-ssl.conf.j2`, remove the `LocationMatch` block added as the quick fix:

```apache
<LocationMatch "^/(import_manifest_|import_database|import_normalized|admin_database_load_import|clear_media|write_resize_request)">
    ...
</LocationMatch>
```

### Add (permanent clean block)

Replace it with a single path-based block:

```apache
<Location "/admin/">
    AuthType Basic
    AuthName "GigHive Admin"
    AuthBasicProvider file
    AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
    Require user admin
</Location>
```

## Callers to Update

All callers that construct URLs or paths to these files must be updated to the `/admin/` prefix. Known caller surfaces:

- `webroot/admin.php` — JavaScript `fetch()` calls to `import_manifest_*` endpoints
- `webroot/admin_database_load_import.php` — any internal references
- `webroot/admin_database_load_import_media_from_folder.php` — any internal references
- Ansible `post_build_checks` role — smoke test task that calls `import_manifest_status.php`
- Any other PHP files that use `include`, `require`, or construct URLs to these endpoints

## Steps

1. Create `webroot/admin/` directory.
2. Move all 21 files listed above into `webroot/admin/`.
3. Search all callers (JS fetch, PHP include/require, Ansible tasks) for references to the old paths and update to `/admin/<filename>`.
4. Update `default-ssl.conf.j2`: remove the interim `LocationMatch` block, add `<Location "/admin/">` block.
5. Verify `webroot/admin.php` still has its own separate `<Location "/admin.php">` block (it is not inside the `/admin/` directory).
6. Run full playbook against gighive2 and confirm `post_build_checks` pass.
7. Remove this plan doc or mark it complete.

## Notes

- The per-file `$user !== 'admin'` checks in the PHP files are a good second layer of defense and should be kept in place after the move.
- `webroot/db/` already follows the correct pattern — all files under `/db/` are covered by the broad `LocationMatch` for that prefix.
