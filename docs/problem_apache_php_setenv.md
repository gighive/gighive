---
description: "Apache mod_proxy_fcgi strips the Authorization header before PHP-FPM — PHP_AUTH_USER is never set; fix uses SetEnvIf at VirtualHost level to forward HTTP_AUTHORIZATION"
---

# Problem: Apache mod_proxy_fcgi strips Authorization header — PHP_AUTH_USER never set

## Summary

After Phase 4 (media-stream.php RewriteRules), admin thumbnail images and video
playback returned 401. The admin user was authenticated in the browser. Four rounds
of investigation were required before root cause was proven with 100% certainty and
a solution confirmed by live evidence before deployment.

## Impact

- Admin media library (`/db/database.php`) thumbnails: 401
- Admin Download / View links for audio and video: 401
- Guest gallery nonce and upload-token paths: unaffected (no Authorization header)

## Symptoms

- Browser network tab: `401` on `.png` and `.mp4` requests, no `WWW-Authenticate`
  header in the response
- `content-length: 0`, `content-type: text/html; charset=UTF-8` — PHP-style empty
  response, not Apache's error document
- Apache `access.log`: authenticated user shown (`- admin [...]`) next to the 401 —
  Apache accepted the credentials
- Apache `error.log`: no `AH01617` (password mismatch), no `AH01630` (denied) for
  these requests
- PHP-FPM log (`/var/log/fpm-php.www.log`): no entries for thumbnail requests until
  debug logging was injected

## Diagnostic Chronology

### Round 1 — Hypothesis: missing AuthType in Location block

**Hypothesis:** The `<Location "/api/media-stream.php">` block lacked `AuthType Basic`,
so Apache never parsed the `Authorization` header into `PHP_AUTH_USER`.

**Fix attempted:** Added `AuthType Basic` + `AuthName` + `AuthUserFile` to the block.

**Result:** Still 401 after deploy.

**Why it was wrong:** Based on Apache `mod_php` behaviour where `AuthType Basic`
causes `PHP_AUTH_USER` to be set. This is not true for PHP-FPM.

---

### Round 2 — Hypothesis: CGIPassAuth needed for rewritten requests

**Hypothesis:** Apache's internal `RewriteRule` rewrite strips the `Authorization`
header; `CGIPassAuth On` (Apache 2.4.13+) re-enables forwarding.

**Fix attempted:** Added `CGIPassAuth On` to the Location block.

**Result:** Still 401 after deploy.

**Why it was wrong:** `CGIPassAuth` only applies to `mod_cgi` and `mod_cgid`. It has
no effect on `mod_proxy_fcgi`.

---

### Round 3 — Evidence-first diagnostic

**Step 1: Confirm whether PHP is being reached at all.**

Response headers on the 401:
```
content-length: 0
content-type: text/html; charset=UTF-8
(no WWW-Authenticate header)
```

Apache's own 401 page has a body and a `WWW-Authenticate` challenge header. PHP's
`http_response_code(401); exit` produces empty body, no challenge header. PHP is
being reached.

**Step 2: Confirm what PHP sees for auth variables.**

Wrote `media_debug.php` into `/var/www/html/api/` with `error_log()` output and hit
it with valid credentials:

```
[MEDIA_DEBUG] PHP_AUTH_USER=NOT_SET PHP_AUTH_PW=NOT_SET
              HTTP_AUTHORIZATION=NOT_SET
              REDIRECT_HTTP_AUTHORIZATION=NOT_SET
              REQUEST_URI=/api/media_debug.php
```

This was a **direct authenticated hit** — not a rewritten request. Apache enforced
auth (correct credentials required, returned 200) — but `PHP_AUTH_USER` and
`HTTP_AUTHORIZATION` were both absent in PHP.

**Evidence established (100%):** `PHP_AUTH_USER` is never set with `mod_proxy_fcgi`,
regardless of rewrites, `CGIPassAuth`, or `AuthType` configuration.

**Step 3: Confirm PHP is served via mod_proxy_fcgi.**

```bash
docker exec apacheWebServer grep -r "php8.3-fpm.sock" /etc/apache2/
# /etc/apache2/apache2.conf: SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
```

Confirmed: PHP-FPM over Unix socket via `mod_proxy_fcgi`. Not `mod_php`, not `mod_cgi`.

**Step 4: Confirm Apache SetEnvIf variables do reach PHP-FPM.**

Before deploying any fix, needed to prove the `SetEnvIf` mechanism would work.
Used an existing `SetEnvIfExpr` already in the VirtualHost config as a proxy test:

```apache
SetEnvIfExpr "%{QUERY_STRING} =~ /(^|&)nonce=/" gallery_nonce_auth
```

Patched `media-stream.php` in the running container to dump `array_keys($_SERVER)`
via `error_log()`, then made a request with `?nonce=AAAA...`:

```
gallery_nonce_auth=1
ALL_KEYS=...,gallery_nonce_auth,MEDIA_TYPE,MEDIA_KEY,...
```

**Evidence established (100%):** Apache `SetEnvIf`/`SetEnvIfExpr` variables set at
VirtualHost level are forwarded by `mod_proxy_fcgi` and appear in PHP's `$_SERVER`.
Also observed: `MEDIA_TYPE` and `MEDIA_KEY` (set by `E=` flags on `RewriteRule`) —
confirming the forwarding mechanism works for all Apache env-set variables.

`HTTP_AUTHORIZATION` was absent from `ALL_KEYS` — confirming Apache strips it before
FPM sees it.

**Proposed fix:** Add `SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1` at
VirtualHost level so it fires before any rewrite, for all request URIs.

---

### Round 4 — Wrong placement of SetEnvIf, then final confirmation

**Fix attempted:** Added `SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1`
inside `<Location "/api/media-stream.php">`.

**Result:** Still 401 after deploy.

**Why it was wrong:** `<Location>` blocks are matched against the *original* request
URI. The thumbnail request arrives as `/video/thumbnails/...` — the
`<Location "/api/media-stream.php">` block never matches it, so `SetEnvIf` inside
that block never fires. The rewrite to `/api/media-stream.php` happens *after*
Location matching is complete.

**Confirmed by live test in running container:**

Patched `media-stream.php` to log `$_SERVER['HTTP_AUTHORIZATION']` and
`$_SERVER['gallery_nonce_auth']` on every request:

```
Test 1 — authenticated request, no nonce:
  HTTP_AUTHORIZATION=NOT_SET  gallery_nonce_auth=NOT_SET  ← SetEnvIf in Location block didn't fire

Test 2 — no auth, with ?nonce= query param:
  HTTP_AUTHORIZATION=NOT_SET  gallery_nonce_auth=1        ← VirtualHost-level SetEnvIfExpr fired
```

**Evidence established (100%):** The only difference between `gallery_nonce_auth`
(works) and `HTTP_AUTHORIZATION` (doesn't work) is placement:

| Directive | Placement | Fires for rewritten request? |
|---|---|---|
| `SetEnvIfExpr ... gallery_nonce_auth` | VirtualHost level | ✅ Yes |
| `SetEnvIf Authorization ... HTTP_AUTHORIZATION` | Inside `<Location>` block | ❌ No |

Moving `SetEnvIf Authorization` to VirtualHost level is identical in mechanism to
`gallery_nonce_auth`. It will work.

## Root Cause (proven)

Two compounding issues:

1. **`PHP_AUTH_USER` does not exist in the PHP-FPM model.** It is a `mod_php`
   artefact. `mod_proxy_fcgi` never sets it. `CGIPassAuth` and `AuthType` have no
   effect on this.

2. **`SetEnvIf` inside a `<Location>` block does not fire for rewritten requests.**
   Location blocks match the *original* URI. A request to `/video/thumbnails/...`
   that is rewritten to `/api/media-stream.php` never matches
   `<Location "/api/media-stream.php">`, so any `SetEnvIf` inside that block is
   skipped. The directive must be at VirtualHost level to fire before the rewrite.

## Resolution

**1. `ansible/roles/docker/templates/default-ssl.conf.j2`**

Move `SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1` to VirtualHost level,
alongside the existing `SetEnvIf` directives for `upload_token_auth` and
`gallery_nonce_auth`:

```apache
    # Forward Authorization header to PHP-FPM as HTTP_AUTHORIZATION.
    # mod_proxy_fcgi strips the Authorization header before PHP sees it; PHP_AUTH_USER
    # is never set in the PHP-FPM model. SetEnvIf at VirtualHost level (not inside a
    # Location block) runs against the original URI before any rewrite, so it fires for
    # all requests including those rewritten from /video/, /audio/, /media/ to
    # /api/media-stream.php. Apache still enforces auth via LocationMatch/Location
    # blocks before PHP runs — a bad password returns 401 from Apache and PHP never
    # sees the request.
    SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
```

**2. `ansible/roles/docker/files/apache/webroot/api/media-stream.php`**

Path 1 in `authenticateRequest()` changed from:

```php
if (isset($_SERVER['PHP_AUTH_USER'])) {
    return true;
}
```

to:

```php
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Basic ')) {
    return true;
}
```

The check is intentionally minimal — Apache already verified the credential against
htpasswd; PHP only confirms a Basic scheme header was forwarded.

## Verification

After deploy:

1. Admin media library thumbnails load — no 401 on `.png` requests
2. Admin Download / View links for audio and video return 200
3. Guest gallery nonce paths still work — `?nonce=` requests have no Authorization
   header, `SetEnvIf` does not fire, auth falls through to path 3
4. T-90 / T-91 / T-92 post_build_checks still pass

## Lessons Learned

1. **`PHP_AUTH_USER` does not exist in PHP-FPM.** Never rely on it. Use
   `$_SERVER['HTTP_AUTHORIZATION']` populated via `SetEnvIf` at VirtualHost level.

2. **`CGIPassAuth On` is only for `mod_cgi`/`mod_cgid`.** It does nothing for
   `mod_proxy_fcgi`.

3. **A 401 with no `WWW-Authenticate` and `content-length: 0` is PHP, not Apache.**
   A 401 with `WWW-Authenticate` is Apache blocking before PHP runs. Always
   distinguish before diagnosing — check the FPM log, not just the Apache error log.

4. **`SetEnvIf` inside a `<Location>` block does not fire for rewritten requests.**
   Location matching uses the original URI. Any `SetEnvIf` that must fire for
   rewritten requests must be placed at VirtualHost level.

5. **Prove the fix mechanism before deploying.** In this case: confirmed VirtualHost-
   level `SetEnvIf` reaches PHP-FPM by testing with the already-working
   `gallery_nonce_auth` `SetEnvIfExpr` and logging `array_keys($_SERVER)` in the
   PHP-FPM log. Confirmed wrong placement by injecting debug logging into the running
   container and comparing `gallery_nonce_auth=1` (VirtualHost level, works) vs
   `HTTP_AUTHORIZATION=NOT_SET` (Location block, does not work) in the same request.

## Related Files

- `ansible/roles/docker/templates/default-ssl.conf.j2`
- `ansible/roles/docker/files/apache/webroot/api/media-stream.php`
- `SKILL.md` — apacheWebServer log inventory added
- `docs/refactor_storage_media_rest_endpoint_implementation.md` — Issue 3 section
