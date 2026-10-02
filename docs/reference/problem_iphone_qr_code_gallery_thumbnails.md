# RCA — Apache Query-String Nonce Gate (Gallery Thumbnails)

**Related feature:** `refactor_iphone_qr_code_gallery_thumbnails.md`

## What happened

Thumbnails were implemented, the PHP was deployed, the app was rebuilt and reinstalled — and still no thumbnails on device. The API was returning valid non-null `thumbnail_url` values. The iOS app was silently getting **401 Unauthorized** on every thumbnail request, showing a blank space with no error.

## Where

`ansible/roles/docker/templates/default-ssl.conf.j2` — the Apache directive that sets the `gallery_nonce_auth` environment variable for the `/video/` `RequireAny` gate.

## What we tried (empirical log, in order)

| Directive | Result |
|---|---|
| `SetEnvIf Request_URI "[?&]nonce=[A-Za-z0-9_\-]{30,40}"` | 401 (original) |
| `SetEnvIf Request_URI "[?&]nonce=[A-Za-z0-9_\-]{30,43}"` | 401 (nonce length fix) |
| `SetEnvIf Query_String "nonce=[A-Za-z0-9_-]{30,43}"` | 401 |
| `SetEnvIf Query_String "nonce=.+"` | 401 |
| `SetEnvIf Query_String "nonce=[A-Za-z0-9]*"` | 401 |
| `SetEnvIf Query_String "nonce=ZTBb...{exact literal}"` | 401 |
| `SetEnvIfExpr "%{QUERY_STRING} =~ /(^|&)nonce=/"` | **200** ✅ |
| `SetEnvIf X-Gallery-Nonce .+` (header, control) | **200** ✅ |

Apache trace logging confirmed the exact failure point for `SetEnvIf Query_String`:

```text
AH01626: authorization result of Require env gallery_nonce_auth: denied
AH01626: authorization result of <RequireAny>: denied
```

And confirmed success with `SetEnvIfExpr`:

```text
Evaluation of expression from default-ssl.conf:67 gave: 1
Setting gallery_nonce_auth
AH01626: authorization result of Require env gallery_nonce_auth: granted
```

## Root cause

**`SetEnvIf Query_String` does not work reliably in this Apache build for query-string matching.** Even an exact literal match of the full nonce string returned 401 with no `Setting gallery_nonce_auth` trace entry. `SetEnvIfExpr` using `%{QUERY_STRING}` in an expression context works correctly and is the required form.

## The thumbnail diagram

iOS stream URLs (via `guest-stream.php`) never touch the Apache auth gate — PHP validates the nonce in code. Thumbnails are direct static file requests that must pass Apache's `/video/` `RequireAny` gate. This is why the bug was invisible until thumbnails were introduced.

```
iOS request for /video/thumbnails/{sha}.png?nonce=...
        |
        v
Apache RequireAny block
        |
        +-- Require valid-user  → denied (no Basic Auth)
        |
        +-- Require env gallery_nonce_auth  → ???
                |
                SetEnvIf Query_String → NOT SET (bug)
                SetEnvIfExpr %{QUERY_STRING} → SET ✅ (fix)
```

## Fix

```apache
SetEnvIfExpr "%{QUERY_STRING} =~ /(^|&)nonce=/" gallery_nonce_auth
```

## Debugging commands used

### Step 1 — baseline curl (confirmed 401)

```bash
curl -sk "https://devvm.gighive.internal/video/thumbnails/de3b45fc23344f0accfb7e722d0c1d63ad694b304266d9dd7f26df771266275c.png?nonce=ZTBbk8ATsCA27cOHbIgpLi20TcioqO7gGmXdYetYzx4" \
  -o /dev/null -w "HTTP %{http_code}\n"
```

### Step 2 — broaden regex to `.*` to rule out regex as the culprit

```bash
docker exec apacheWebServer bash -c 'cp /etc/apache2/sites-enabled/default-ssl.conf /tmp/default-ssl.conf.bak && sed -i "s/SetEnvIf Query_String.*/SetEnvIf Query_String \".*\" gallery_nonce_auth/" /etc/apache2/sites-enabled/default-ssl.conf && apache2ctl graceful'
curl -sk "https://devvm.gighive.internal/video/thumbnails/de3b45fc23344f0accfb7e722d0c1d63ad694b304266d9dd7f26df771266275c.png?nonce=ZTBbk8ATsCA27cOHbIgpLi20TcioqO7gGmXdYetYzx4" \
  -o /dev/null -w "HTTP %{http_code}\n"
# Result: HTTP 401 — still failing even with match-everything regex
```

### Step 3 — control test: header-based nonce (confirmed header path works)

```bash
curl -sk "https://devvm.gighive.internal/video/thumbnails/de3b45fc23344f0accfb7e722d0c1d63ad694b304266d9dd7f26df771266275c.png" \
  -H "X-Gallery-Nonce: ZTBbk8ATsCA27cOHbIgpLi20TcioqO7gGmXdYetYzx4" \
  -o /dev/null -w "HTTP %{http_code}\n"
# Result: HTTP 200 — header path works; query-string path is the broken one
```

### Step 4 — enable Apache trace logging to see exactly what the auth engine decides

```bash
docker exec apacheWebServer bash -c 'cp /etc/apache2/sites-enabled/default-ssl.conf /tmp/default-ssl.conf.debugbak && sed -i "/CustomLog/a\\    LogLevel setenvif:trace8 authz_core:debug headers:debug rewrite:trace3" /etc/apache2/sites-enabled/default-ssl.conf && apache2ctl graceful'
docker exec apacheWebServer bash -c '> /var/log/apache2/error.log'
curl -sk "https://devvm.gighive.internal/video/thumbnails/de3b45fc23344f0accfb7e722d0c1d63ad694b304266d9dd7f26df771266275c.png?nonce=ZTBbk8ATsCA27cOHbIgpLi20TcioqO7gGmXdYetYzx4" \
  -o /dev/null -w "HTTP %{http_code}\n"
docker exec apacheWebServer cat /var/log/apache2/error.log
# Log showed: AH01626: authorization result of Require env gallery_nonce_auth: denied
# No "Setting gallery_nonce_auth" entry — SetEnvIf Query_String never fired
```

### Step 5 — switch to SetEnvIfExpr and confirm

```bash
docker exec apacheWebServer bash -c '
cp /etc/apache2/sites-enabled/default-ssl.conf /tmp/default-ssl.conf.exprbak &&
sed -i "/SetEnvIf Query_String/c\\    SetEnvIfExpr \"%{QUERY_STRING} =~ /(^|&)nonce=/\" gallery_nonce_auth" /etc/apache2/sites-enabled/default-ssl.conf &&
apache2ctl graceful
'
docker exec apacheWebServer bash -c '> /var/log/apache2/error.log'
curl -sk "https://devvm.gighive.internal/video/thumbnails/de3b45fc23344f0accfb7e722d0c1d63ad694b304266d9dd7f26df771266275c.png?nonce=ZTBbk8ATsCA27cOHbIgpLi20TcioqO7gGmXdYetYzx4" \
  -o /dev/null -w "HTTP %{http_code}\n"
# Result: HTTP 200 ✅
docker exec apacheWebServer cat /var/log/apache2/error.log
# Log showed: Setting gallery_nonce_auth
#             AH01626: authorization result of Require env gallery_nonce_auth: granted
```

## Lesson

`SetEnvIf Query_String` and `SetEnvIfExpr "%{QUERY_STRING}"` are not equivalent in all Apache builds. When adding any new direct-file access path through the `/video/` `RequireAny` gate, verify with `setenvif:trace2` logging that `Setting gallery_nonce_auth` actually appears in the error log before declaring it working.
