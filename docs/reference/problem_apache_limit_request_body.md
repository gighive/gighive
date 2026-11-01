---
description: RCA for Apache 413 uploads caused by LimitRequestBody defaulting to 1 GiB
---

# Problem: Large Admin Archive Uploads Failing with Apache `413 Request Entity Too Large`

## Summary

Large media archive uploads through `admin/import_media_zip.php` were failing with `413 Request Entity Too Large` before PHP application code could process the request.

The root cause was Apache core request body enforcement via `LimitRequestBody`. In Apache 2.4.54+ the effective default is `1073741824` bytes (1 GiB) when `LimitRequestBody` is not explicitly configured. Our admin media import archive was larger than that threshold, so Apache rejected the request before it reached PHP-FPM or application logic.

The fix was to add an explicit `LimitRequestBody` under the `/admin/` Apache `Location` block in `ansible/roles/docker/templates/default-ssl.conf.j2`, backed by a new `limit_request_body` variable in environment-specific `group_vars` files. The final chosen value was intentionally raised well beyond the immediate test archive size to support very large media-library backup imports.

## Impact

- Admin uploads of large `.tar.gz` media archives failed from the admin UI.
- The failure appeared as an Apache HTML `413` response instead of an application JSON response.
- The import workflow in `admin/admin_system.php` could not proceed for archives above roughly 1 GiB.

## Symptoms

Typical observed behavior:

- Uploading a large archive from `https://gighive2.gighive.internal/admin/admin_system.php` failed.
- The browser/XHR path reached `admin/import_media_zip.php` but the response was an Apache `413` page.
- Smaller synthetic payloads succeeded far enough to reach PHP and returned JSON `400`.
- A larger synthetic payload around `1200M` failed with Apache `413`.

The most important distinguishing signal was:

- Apache-layer failure:
  - `HTTP/2 413`
  - `server: Apache/2.4.58 (Ubuntu)`
  - HTML error page

versus application-layer failure:

- PHP/app reached:
  - `HTTP/2 400`
  - `server: Gighive`
  - `content-type: application/json`

## Affected Flow

### UI entry point

- `ansible/roles/docker/files/apache/webroot/admin/admin_system.php`

The client-side import flow posts the selected archive to:

- `import_media_zip.php`

### Upload endpoint

- `ansible/roles/docker/files/apache/webroot/admin/import_media_zip.php`

This script already contained explicit PHP-side checks for oversized `post_max_size` requests and would return a JSON `413` if PHP limits were the blocker. That behavior was useful diagnostically because the observed failure was not that JSON response.

## Initial Hypotheses Considered

We investigated several likely layers that can reject large uploads:

- PHP `upload_max_filesize`
- PHP `post_max_size`
- PHP-FPM vs CLI configuration mismatch
- ModSecurity `SecRequestBodyLimit`
- Apache core request-body handling
- HTTP/2 vs HTTP/1.1 behavior
- hostname/proxy differences
- temporary storage / upload tmp issues

## What We Verified

### 1. PHP CLI values were misleading

An early `php -r` check showed small limits such as `2M / 8M`, but those values came from the CLI SAPI, not the PHP-FPM/web SAPI actually serving requests.

### 2. PHP-FPM was configured for large uploads

Using `php-fpm8.3 -i`, we confirmed the active web SAPI limits were large enough for the test archive.

Observed values included:

- `upload_max_filesize = 11715M`
- `post_max_size = 11715M`

This ruled out PHP-FPM configuration as the cause of the Apache `413`.

### 3. ModSecurity was configured with a large body limit

Relevant template:

- `ansible/roles/docker/templates/modsecurity.conf.j2`

That template already set:

- `SecRequestBodyLimit {{ upload_max_bytes }}`
- `SecRequestBodyNoFilesLimit {{ upload_max_bytes }}`

and specifically included an `/admin/` block with a large `SecRequestBodyLimit`.

### 4. The Apache templates did not set `LimitRequestBody`

We searched the Apache Jinja templates under:

- `ansible/roles/docker/templates`

and confirmed there was no `LimitRequestBody` directive present initially.

The main relevant Apache template was:

- `ansible/roles/docker/templates/default-ssl.conf.j2`

### 5. Threshold testing isolated the failure to roughly 1 GiB

Synthetic file tests were run with payloads approximately:

- `200M`
- `400M`
- `800M`
- `1200M`

Results before the fix:

- `200M` -> `HTTP/2 400`, `server: Gighive`
- `400M` -> `HTTP/2 400`, `server: Gighive`
- `800M` -> `HTTP/2 400`, `server: Gighive`
- `1200M` -> `HTTP/2 413`, Apache HTML error

This was a strong signature for a request-body limit near 1 GiB.

### 6. Apache version matched the known changed default behavior

Apache version on the running container:

- `Apache/2.4.58 (Ubuntu)`

Research confirmed that Apache 2.4.54+ changed the effective default `LimitRequestBody` behavior to 1 GiB (`1073741824`) when not explicitly set, as part of the fix for CVE-2022-29404.

### 7. Runtime verification after the fix proved the diagnosis

After adding `LimitRequestBody` to the `/admin/` block and rebuilding, the live container showed:

```bash
docker exec apacheWebServer sh -lc "grep -Rni 'LimitRequestBody' /etc/apache2 2>/dev/null"
```

with results similar to:

```text
/etc/apache2/sites-enabled/default-ssl.conf:307:        LimitRequestBody 1289000000
/etc/apache2/sites-available/default-ssl.conf:307:        LimitRequestBody 1289000000
```

Re-running the synthetic test then produced:

- `200M` -> `HTTP/2 400`, `server: Gighive`
- `400M` -> `HTTP/2 400`, `server: Gighive`
- `800M` -> `HTTP/2 400`, `server: Gighive`
- `1200M` -> `HTTP/2 400`, `server: Gighive`

That change proved the request was now reaching PHP/application code even at the formerly failing large size.

Finally, the real archive upload from the admin UI succeeded.

## Root Cause

### Direct cause

Apache core rejected the request body before PHP handled it because no explicit `LimitRequestBody` override existed for the `/admin/` upload path.

In Apache 2.4.58, when `LimitRequestBody` is unset, the effective default is 1 GiB:

- `1073741824`

Our media archive exceeded that threshold.

### Why PHP was not the cause

The application endpoint `admin/import_media_zip.php` contains PHP-side oversized upload detection and would emit JSON if PHP `post_max_size` were exceeded. The observed failure was instead an Apache HTML `413`, proving the request was blocked before PHP application logic processed it.

### Why ModSecurity was not the primary cause

ModSecurity limits were already configured much higher through `upload_max_bytes`, and the failure pattern aligned exactly with Apache’s 1 GiB request-body behavior. The final before/after result changed only when Apache `LimitRequestBody` was added to the `/admin/` context.

## Contributing Factors

- Apache 2.4.54+ changed the effective default `LimitRequestBody` to 1 GiB.
- No explicit `LimitRequestBody` was present in the Apache Jinja templates.
- PHP CLI checks initially showed low values (`2M / 8M`), which could have misdirected the investigation if not separated from PHP-FPM values.
- Multiple independent layers can reject uploads, so diagnostics had to distinguish Apache, PHP, and ModSecurity carefully.

## Resolution

### Configuration changes

Added a new Ansible variable in the environment-specific `group_vars` files:

- `ansible/inventories/group_vars/gighive2/gighive2.yml`
- `ansible/inventories/group_vars/gighive/gighive.yml`
- `ansible/inventories/group_vars/prod/prod.yml`

Final variable value:

```yaml
limit_request_body: 1289000000000
```

This final value was chosen after the successful fix validation because the expected long-term use case includes very large user media libraries being backed up and restored through admin workflows.

Then wired that value into the Apache vhost template:

- `ansible/roles/docker/templates/default-ssl.conf.j2`

Final scoped fix:

```apache
<Location "/admin/">
    AuthType Basic
    AuthName "GigHive Admin"
    AuthBasicProvider file
    AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
    LimitRequestBody {{ limit_request_body }}
    Require user admin
</Location>
```

### Scope decision

`LimitRequestBody` was intentionally applied only to the `/admin/` block because that was the only path demonstrated to be failing. Broader upload endpoints were considered but not kept, following a minimal-change approach.

## Verification

### 1. Validate Apache syntax

```bash
docker exec apacheWebServer sh -lc "apache2ctl -t"
```

Expected:

- `Syntax OK`

### 2. Confirm live rendered directive

```bash
docker exec apacheWebServer sh -lc "grep -Rni 'LimitRequestBody' /etc/apache2 2>/dev/null"
```

Expected:

- `default-ssl.conf` shows `LimitRequestBody 1289000000000`

### 3. Check Apache error log

```bash
docker exec apacheWebServer sh -lc "tail -n 100 /var/log/apache2/error.log"
```

Expected:

- no new `Requested content-length ... is larger than the configured limit ...` errors for the tested upload

### 4. Re-run threshold test

Synthetic threshold script (`test2.sh`) was used to retest:

- `200M`
- `400M`
- `800M`
- `1200M`

Expected after fix:

- all tested sizes reach PHP/app and return JSON-style responses rather than Apache HTML `413`

### 5. Real upload confirmation

Re-test the actual archive from:

- `https://gighive2.gighive.internal/admin/admin_system.php`

Success criteria:

- upload progresses past the initial former failure point
- no Apache `413`
- import workflow proceeds normally

## Debugging Steps We Went Through

1. Confirmed the failing UI and endpoint (`admin_system.php` -> `import_media_zip.php`).
2. Considered PHP, ModSecurity, and Apache template configuration as possible sources.
3. Noted that CLI PHP values (`2M / 8M`) did not necessarily reflect PHP-FPM values.
4. Confirmed PHP-FPM upload limits were large enough.
5. Reviewed `Dockerfile.j2`, `www.conf.j2`, `php-fpm.conf.j2`, `apache2.conf.j2`, `security2.conf.j2`, and `modsecurity.conf.j2`.
6. Verified `upload_max_bytes` in `group_vars` was large and already feeding PHP and ModSecurity.
7. Ran threshold tests with synthetic files to identify the failure boundary.
8. Confirmed `800M` reached the app while `1200M` failed with Apache HTML `413`.
9. Confirmed Apache version was `2.4.58`.
10. Searched Apache Jinja templates and verified that `LimitRequestBody` was not set anywhere.
11. Researched Apache 2.4.54+ behavior and confirmed known 1 GiB default reports.
12. Added a new `limit_request_body` variable to environment `group_vars`.
13. Wired `LimitRequestBody {{ limit_request_body }}` into the `/admin/` block of `default-ssl.conf.j2`.
14. Rebuilt/reloaded Apache and confirmed the live rendered config contained the directive.
15. Re-ran threshold tests and observed `1200M` now reached PHP/app instead of failing at Apache.
16. Verified that the real admin archive upload worked.

## Lessons Learned

- For large upload debugging, always distinguish:
  - Apache core rejection
  - ModSecurity rejection
  - PHP/web SAPI rejection
  - application validation rejection
- `server: Apache/...` plus an HTML `413` strongly suggests rejection before PHP app code.
- `server: Gighive` plus JSON indicates the request reached the application layer.
- CLI `php -r` output is not authoritative for PHP-FPM-served uploads.
- Apache version changes can silently alter effective upload behavior even when app and WAF settings look correct.
- For this codebase, narrow path-specific Apache fixes are preferable to global request-body changes when only one endpoint is affected.

## Preventative Actions

- Keep `limit_request_body` documented as a separate Apache-specific control from `upload_max_bytes`.
- When supporting very large admin uploads, always check Apache core directives in addition to PHP and ModSecurity.
- Prefer endpoint-scoped `LimitRequestBody` settings over global increases unless broader behavior is explicitly desired.
- When diagnosing future `413` errors, compare:
  - response status
  - `server` header
  - content type / body shape
  - Apache logs
  - PHP-FPM effective settings

## Longer-Term Direction

- The current Apache fix unblocks large admin backup/import uploads.
- Longer term, the intended direction is to add a feature for synchronizing a user's media files with S3-backed cloud storage.
- If that feature is implemented, very large media libraries may rely less on single massive admin upload requests and more on incremental or remote synchronization workflows.
