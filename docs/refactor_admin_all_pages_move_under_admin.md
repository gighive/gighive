# Refactor Plan: Move All Admin Pages and Admin Endpoints into `/admin/`

## Background

The admin surface has grown into a mix of root-level admin UI pages and root-level admin-only PHP endpoints. Apache currently protects these through a combination of:

- an explicit `<Location "/admin.php">` block
- a root-level `<LocationMatch>` for several admin-only PHP files

This works, but it spreads the admin area across the webroot and requires Apache config to keep growing as new admin endpoints are added.

The goal of this refactor is to move the full admin surface into a dedicated folder:

- `ansible/roles/docker/files/apache/webroot/admin/`

This will make the URL structure cleaner, simplify Apache protection, and keep all admin pages and admin-only endpoints grouped together.

## Scope

This plan assumes **all `admin*.php` pages move under `/admin/`**, including `admin.php` itself.

That is broader than the earlier protected-folder refactor note, which left `admin.php` at the webroot root. Under this plan, the main admin landing page will move from:

- `/admin.php`

to:

- `/admin/admin.php`

## Target URL Structure

After the refactor, the target URLs will be:

- `/admin/admin.php`
- `/admin/admin_system.php`
- `/admin/admin_database_load_import.php`
- `/admin/admin_database_load_import_media_from_folder.php`
- `/admin/import_database.php`
- `/admin/import_normalized.php`
- `/admin/import_manifest_*.php`
- `/admin/clear_media.php`
- `/admin/clear_media_files.php`
- `/admin/write_resize_request.php`

## Files to Move

### Admin UI pages

| Current path | Target path |
|---|---|
| `ansible/roles/docker/files/apache/webroot/admin.php` | `ansible/roles/docker/files/apache/webroot/admin/admin.php` |
| `ansible/roles/docker/files/apache/webroot/admin_system.php` | `ansible/roles/docker/files/apache/webroot/admin/admin_system.php` |
| `ansible/roles/docker/files/apache/webroot/admin_database_load_import.php` | `ansible/roles/docker/files/apache/webroot/admin/admin_database_load_import.php` |
| `ansible/roles/docker/files/apache/webroot/admin_database_load_import_media_from_folder.php` | `ansible/roles/docker/files/apache/webroot/admin/admin_database_load_import_media_from_folder.php` |

### Admin endpoints currently protected at root level

| Current path | Target path |
|---|---|
| `ansible/roles/docker/files/apache/webroot/import_database.php` | `ansible/roles/docker/files/apache/webroot/admin/import_database.php` |
| `ansible/roles/docker/files/apache/webroot/import_normalized.php` | `ansible/roles/docker/files/apache/webroot/admin/import_normalized.php` |
| `ansible/roles/docker/files/apache/webroot/clear_media.php` | `ansible/roles/docker/files/apache/webroot/admin/clear_media.php` |
| `ansible/roles/docker/files/apache/webroot/clear_media_files.php` | `ansible/roles/docker/files/apache/webroot/admin/clear_media_files.php` |
| `ansible/roles/docker/files/apache/webroot/write_resize_request.php` | `ansible/roles/docker/files/apache/webroot/admin/write_resize_request.php` |

### Import-manifest family

These should move as a group so their internal `__DIR__` relationships remain coherent.

| Current path | Target path |
|---|---|
| `ansible/roles/docker/files/apache/webroot/import_manifest_add.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_add.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_add_async.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_add_async.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_cancel.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_cancel.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_duplicates.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_duplicates.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_finalize.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_finalize.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_jobs.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_jobs.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_lib.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_lib.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_prepare.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_prepare.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_reload.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_reload.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_reload_async.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_reload_async.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_replay.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_replay.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_status.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_status.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_upload_finalize.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_upload_finalize.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_upload_start.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_upload_start.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_upload_status.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_upload_status.php` |
| `ansible/roles/docker/files/apache/webroot/import_manifest_worker.php` | `ansible/roles/docker/files/apache/webroot/admin/import_manifest_worker.php` |

## Files That Need Code Changes

### 1. `ansible/roles/docker/templates/default-ssl.conf.j2`

#### Current behavior

Apache currently protects admin functionality through:

- `<Location "/admin.php">`
- `<LocationMatch "^/(import_manifest_|import_database|import_normalized|admin_database_load_import|admin_system|clear_media|write_resize_request)">`

#### Planned change

Replace those root-level protections with a single path-based admin block:

```apache
<Location "/admin/">
    AuthType Basic
    AuthName "GigHive Admin"
    AuthBasicProvider file
    AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
    Require user admin
</Location>
```

#### Likely edits

- remove the explicit root-level `/admin.php` block
- remove the root-level admin `LocationMatch`
- add a single `/admin/` protection block
- update nearby comments to reflect the new layout

### 2. `ansible/roles/docker/files/apache/overlays/gighive/index.php`

#### Planned change

Update the Admin button link from:

- `/admin.php`

To:

- `/admin/admin.php`

#### Current user-facing text

- `Admin Functions (Change Passwords / Data Loading)`

The text can remain as-is for the initial refactor, or be renamed later if desired.

### 3. `ansible/roles/docker/files/apache/webroot/admin.php`

#### Planned changes

- move file into `webroot/admin/admin.php`
- update nav links to `/admin/...`
- keep PHP admin-user guard in place as a second layer of defense
- verify redirect destinations if any are meant to remain outside `/admin/`

### 4. `ansible/roles/docker/files/apache/webroot/admin_system.php`

#### Planned changes

- move file into `webroot/admin/admin_system.php`
- update nav links to `/admin/admin.php`, `/admin/admin_database_load_import.php`, and `/admin/admin_database_load_import_media_from_folder.php`
- update any admin endpoint fetches to `/admin/...` or keep them relative after the move

Known endpoint calls on this page:

- `write_resize_request.php`
- `clear_media.php`
- `clear_media_files.php`
- `/db/restore_database.php`
- `/db/restore_database_status.php`

The `/db/...` restore endpoints remain outside this move.

### 5. `ansible/roles/docker/files/apache/webroot/admin_database_load_import.php`

#### Planned changes

- move file into `webroot/admin/admin_database_load_import.php`
- update top-right nav links to `/admin/...`
- update import endpoint references for clarity

Known endpoint calls on this page:

- `import_database.php`
- `import_normalized.php`

Because these are currently relative fetches, they will likely continue working after the move. Even so, explicitly changing them to `/admin/import_database.php` and `/admin/import_normalized.php` would make the new structure clearer.

### 6. `ansible/roles/docker/files/apache/webroot/admin_database_load_import_media_from_folder.php`

#### Planned changes

- move file into `webroot/admin/admin_database_load_import_media_from_folder.php`
- update nav links to `/admin/...`
- update all manifest-related relative fetches to `/admin/...` for clarity and consistency

Known endpoint calls on this page include:

- `import_manifest_prepare.php`
- `import_manifest_finalize.php`
- `import_manifest_status.php`
- `import_manifest_upload_start.php`
- `import_manifest_upload_status.php`
- `import_manifest_upload_finalize.php`

These are currently relative and will likely still resolve after the move, but making them explicit is safer.

### 7. `ansible/roles/docker/files/apache/webroot/import_database.php`
### 8. `ansible/roles/docker/files/apache/webroot/import_normalized.php`
### 9. `ansible/roles/docker/files/apache/webroot/clear_media.php`
### 10. `ansible/roles/docker/files/apache/webroot/clear_media_files.php`
### 11. `ansible/roles/docker/files/apache/webroot/write_resize_request.php`

#### Planned changes

Move these files into `webroot/admin/` and verify all include paths.

#### Key risk

Several of these files load Composer autoloading from the current directory, for example:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

After moving into `webroot/admin/`, that path will break because `vendor/` remains at webroot root.

#### Required fix pattern

Change those includes to a parent-relative path, for example:

```php
require_once dirname(__DIR__) . '/vendor/autoload.php';
```

### 12. `ansible/roles/docker/files/apache/webroot/import_manifest_*.php`

#### Planned changes

Move the full import-manifest cluster into `webroot/admin/` together.

#### Why they must move together

They refer to each other using `__DIR__`, including:

- `require_once __DIR__ . '/import_manifest_lib.php';`
- worker launch commands using `__DIR__ . '/import_manifest_worker.php'`

If they move as a group, those internal sibling references remain valid.

#### Extra required fix — vendor/autoload.php path

The following manifest files each contain a direct `require_once __DIR__ . '/vendor/autoload.php'` call that will break after the move, because `vendor/` stays at webroot root while the files move into `webroot/admin/`:

- `import_manifest_lib.php`
- `import_manifest_add.php`
- `import_manifest_reload.php`
- `import_manifest_upload_start.php` (also has a direct require in addition to requiring lib)
- `import_manifest_upload_finalize.php` (also has a direct require in addition to requiring lib)

All five must be updated to a parent-relative path:

```php
require_once dirname(__DIR__) . '/vendor/autoload.php';
```

Files that only do `require_once __DIR__ . '/import_manifest_lib.php'` (with no direct vendor require) are safe once lib.php is fixed.

### 13. `ansible/roles/docker/files/apache/webroot/import_manifest_worker.php`

#### Planned changes

- move with the rest of the manifest files
- keep worker-launch references aligned with the new folder location

This is required because several async entry points construct CLI commands using the worker path from `__DIR__`.

## Additional Files to Review

### 14. `docs/refactor_preasset_librarian_admin_pages_move_to_protected_folder.md`

This earlier refactor note is now out of date relative to the broader plan documented here.

It currently assumes:

- `admin.php` remains at root

This new plan assumes:

- `admin.php` also moves into `/admin/`

That older doc should either be updated, superseded, or left in place with a note that `docs/refactor_admin_all_pages_move_under_admin.md` is the current plan.

### 15. Ansible smoke tests or post-build checks

During implementation, search for any automation that references the current root-level admin paths, including:

- `admin.php`
- `admin_system.php`
- `admin_database_load_import.php`
- `import_manifest_status.php`
- `import_database.php`
- `import_normalized.php`
- `clear_media.php`
- `clear_media_files.php`
- `write_resize_request.php`

Any such references will need to change to `/admin/...`.

## Recommended Implementation Order

### Phase 1 + 2 (atomic): Move files and fix internal dependencies in the same commit/deployment

> **These two phases must be applied together in a single deployment.** Moving files without fixing internal paths first will break the application immediately. Never deploy Phase 1 alone.

1. Create `ansible/roles/docker/files/apache/webroot/admin/`
2. Move all admin UI pages into it
3. Move all admin-only endpoints into it
4. Move the full import-manifest cluster into it
5. Update all `vendor/autoload.php` includes to `dirname(__DIR__) . '/vendor/autoload.php'` in the five manifest files that require it directly
6. Verify `__DIR__`-based sibling references still work (safe since manifest cluster moves together)
7. Verify async worker-launch paths still resolve

### Phase 3: Update callers and navigation

1. Update admin-page top-right nav links to `/admin/...`
2. Update homepage Admin button in `apache/overlays/gighive/index.php`
3. Update explicit JS endpoint URLs to `/admin/...`
4. Search for any remaining old root-level references

### Phase 4: Simplify Apache auth configuration

1. Remove the root-level `LocationMatch` workaround
2. Remove the old root-level `/admin.php` block
3. Add one `/admin/` Apache protection block
4. Update comments in `default-ssl.conf.j2`

### Phase 5: Verify end-to-end behavior

1. Load `/admin/admin.php`
2. Navigate across all admin pages
3. Verify password reset still works
4. Verify CSV import still works
5. Verify folder import and manifest upload flow still work
6. Verify clear-media and clear-media-files endpoints still work
7. Verify resize-request flow still works
8. Verify database restore still works

## Main Risks

### High risk

- broken `vendor/autoload.php` includes after moving files
- missed hard-coded root URLs
- partial migration leaving Apache config and URLs out of sync

### Medium risk

- relative JavaScript endpoint paths becoming ambiguous during transition
- overlooked automation or smoke-test references to old root paths

### Low risk

- import-manifest worker path, if the full manifest cluster moves together in one change

## Recommendation

Implement this as a **single coordinated refactor**, not piecemeal.

This move touches:

- filesystem layout
- Apache auth config
- homepage navigation
- admin-page navigation
- JavaScript endpoint URLs
- Composer autoload include paths
- async worker-launch paths

A single coordinated change will reduce the risk of mixed old/new paths and make testing much simpler.

## Status

- plan documented
- no implementation performed yet
