---
render_with_liquid: false
---

# Problem: Tus POST /files/ Returns 415 Due to Parallel Uploads Using Concatenation Extension

## Date

2026-08-17

## Symptom

During `playwright_admin_tests`, the browser console logged 70+ 415 errors:

```text
[browser error] Failed to load resource: the server responded with a status of 415 ()
```

Apache access log confirmed all 415s were `POST /files/` requests from
`admin_database_load_import_media_from_folder.php`. The playwright tests still
**passed** — tus-js-client catches the 415 per file and the test's `waitForFunction`
polls for zero pending badges, which completes when all files either succeed or error
out. However the 415'd files were never stored — silent data loss in a passing test.

## Investigation History

### First hypothesis — bare type label sent as MIME type (wrong)

The admin pages set `filetype: fileInfo.file_type` in tus metadata, where `file_type`
is GigHive's internal label (`'audio'` or `'video'`), not a MIME type. It was
hypothesised that `TusBlockUploadService::validateFileType()` rejected `'audio'`
because `str_starts_with('audio', 'audio/')` is false (missing the trailing slash).

This was partially correct — the admin pages were sending the wrong value — but fixing
it did not eliminate the 415s. The 415s persisted after `filetype` was removed from
the metadata entirely.

### Confirmed root cause — tus concatenation extension (parallel uploads)

A temporary `error_log` in `handlePost` was added. It never fired. This proved the
415 was returned **before PHP was reached** — the request was being rejected at the
Apache/tus routing layer.

The ModSecurity audit log for the 415 requests revealed:

```text
POST /files/ HTTP/2.0
Upload-Concat: partial
Upload-Length: 12160059
(no Upload-Metadata header)
```

`tus_client_parallel_uploads: 3` was set in all environment group_vars. When
`parallelUploads > 1`, tus-js-client activates the **tus concatenation extension**:

1. It splits the file into N parts.
2. It POSTs each part as `Upload-Concat: partial` with **no `Upload-Metadata`**.
3. After all partial uploads complete, it POSTs `Upload-Concat: final` to join them.

`TusBlockUploadService::handlePost()` does not implement the concatenation extension.
It receives `Upload-Concat: partial` with no `Upload-Metadata` → `$fileName = ''` →
`$fileExt = ''` → `$isAudio = false`, `$isVideo = false` → 415.

The comment in group_vars said "tusd concatenation extension (supported)" — this was
accurate when tusd was the upload server. tusd implemented concatenation natively. The
PHP replacement does not.

## Root Cause

`tus_client_parallel_uploads: 3` was carried over unchanged from the tusd era into
the Phase 3 PHP tus implementation. `TusBlockUploadService` does not implement the
tus concatenation extension. Any value > 1 causes 415 on every partial-upload POST.

## Evidence

| Evidence | Source |
|---|---|
| 70+ `POST /files/ HTTP/2.0" 415 80` in Apache access log | `docker exec apacheWebServer grep " 415 " /var/log/apache2/access.log` |
| Temporary `error_log` in `handlePost` never fired — PHP not reached | diagnostic added and confirmed absent from error.log |
| ModSecurity audit shows `Upload-Concat: partial` and no `Upload-Metadata` on 415 requests | `docker exec apacheWebServer grep -A30 <audit-id> /var/log/apache2/modsec_audit.log` |
| `TUS_CLIENT_PARALLEL_UPLOADS=3` in running container | `docker exec apacheWebServer printenv TUS_CLIENT_PARALLEL_UPLOADS` |
| `tus_client_parallel_uploads: 3` in gighive2, gighive, and prod group_vars | `grep tus_client_parallel_uploads ansible/inventories/group_vars/*/` |
| Old comment: "tusd concatenation extension (supported)" — no longer true after Phase 3 | group_vars comment, now updated |
| Playwright `rc == 0` despite 415s — tus-js-client error handler resolves the Promise, test badge-polling completes | `admin_database_load_import_media_from_folder.php` `onError` handler |

## Fix

Set `tus_client_parallel_uploads: 1` in all three environment group_vars files and
updated the comment:

- `ansible/inventories/group_vars/gighive2/gighive2.yml`
- `ansible/inventories/group_vars/gighive/gighive.yml`
- `ansible/inventories/group_vars/prod/prod.yml`

Additionally, `filetype` was removed from the tus `metadata` object in both admin
pages as a correctness fix (the value was a GigHive type label, not a MIME type):

- `admin/admin_database_load_import_media_from_folder.php`
- `admin/admin_database_catalog_promote.php`

This second fix is independently correct but was not the cause of the 415s.

### Why parallelUploads: 1 is the right long-term answer

The tus concatenation extension requires server-side support to:
- Accept partial uploads without metadata
- Track and join partial uploads into a final blob
- Apply file-type validation only on the final concatenation POST

Implementing this in `TusBlockUploadService` is significant scope. At GigHive's scale
(LAN uploads, admin-only, files up to a few GB) sequential 8 MiB chunks provide
adequate throughput. The per-chunk parallel benefit is not worth the implementation
complexity.

If parallel uploads are needed in future, `TusBlockUploadService` must be extended to
handle `Upload-Concat: partial` (skip metadata validation, store chunk) and
`Upload-Concat: final` (validate combined metadata, commit asset).

## Verification

After redeployment (`--tags docker`), run:

```bash
ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml \
  --tags set_targets,playwright_admin_tests \
  -e allow_destructive=true -e run_playwright_admin_tests=true -K
```

Apache access log should contain zero `POST /files/ HTTP/2.0" 415` entries:

```bash
ssh ubuntu@192.168.1.50 "docker exec apacheWebServer grep ' 415 ' /var/log/apache2/access.log"
```

## Related

- `docs/refactor_storage_media_rest_endpoint_implementation.md` — Phase 3
  `TusBlockUploadService` implementation; "Why the Upload Pipeline Changed" section
- `ansible/roles/upload_tests/tasks/test_7.yml` — upload test whose 415 was a
  separate issue (missing `filename` in metadata from curl); updated assertions
- `src/Services/TusBlockUploadService.php` — `handlePost()`, `validateFileType()`
- tus concatenation extension spec: https://tus.io/protocols/resumable-upload#concatenation
