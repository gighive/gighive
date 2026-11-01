# Intermittent Range Request Failure for Large MP4

**Date:** 2026-03-15
**Status:** Open — intermittently reproducible, under investigation
**Severity:** High — blocks playback of large video files when it occurs

---

## Summary

The GigHive iOS app intermittently fails to play a large MP4 file (~4.7 GB,
24 minutes). App logs show the custom resource loader receiving **HTTP 200**
instead of **HTTP 206 Partial Content** for a ranged GET request.

The failure is **intermittent**: the same file sometimes plays correctly
(all range requests return 206) and sometimes fails (first range request
returns 200). Direct curl tests from both inside the Apache container and
through Cloudflare consistently return correct 206 responses, so the issue
may be timing-dependent, load-dependent, or related to rapid media switching.

The failure has been observed on multiple occasions and across different
files on the same server. It is **not** limited to a single cached response.

---

## Server Stack

| Component | Detail |
|---|---|
| **OS** | Ubuntu 24.04 (Docker) |
| **Web server** | Apache 2 with `mpm_event` |
| **PHP** | PHP 8.3 FPM via `proxy_fcgi` |
| **TLS** | Apache `mod_ssl` (origin cert), Cloudflare in front |
| **Caching** | Apache `mod_cache` + `mod_cache_disk` |
| **Dockerfile** | `gighiveinfra/ansible/roles/docker/files/apache/Dockerfile` |
| **Apache global config** | `gighiveinfra/ansible/roles/docker/templates/apache2.conf.j2` |
| **SSL vhost config** | `gighiveinfra/ansible/roles/docker/templates/default-ssl.conf.j2` |
| **ModSecurity config** | `gighiveinfra/ansible/roles/docker/templates/modsecurity.conf.j2` |
| **Entrypoint** | `gighiveinfra/ansible/roles/docker/templates/entrypoint.sh.j2` |
| **Docker Compose** | `gighiveinfra/ansible/roles/docker/templates/docker-compose.yml.j2` |
| **Static configs** | `gighiveinfra/ansible/roles/docker/files/apache/externalConfigs/` |

---

## Timeline

| Time (UTC) | Event |
|---|---|
| ~14:35 | App logs `HTTP 200` for ranged GET on the MP4; asset fails with "Operation Stopped" |
| 14:35–15:02 | Multiple curl tests from inside/outside container — all return **206** |
| 15:07 | Same MP4 played successfully from the app — all loader requests return **206** |

---

## Evidence

### Original failure (app log, ~14:35 UTC)

```
[Loader] GET ...aeee5ec8...mp4 headers=["Range": "bytes=0-1", ...]
[Loader] HTTP 200 for /video/aeee5ec8...mp4
[Loader] ❌ Server did not honor byte-range request ...
[Player] Item status: failed: Operation Stopped
```

### Confirmed working (~15:07 UTC, same app, same file)

```
[Loader] GET ...aeee5ec8...mp4 headers=["Range": "bytes=0-1", ...]
[Loader] HTTP 206 for /video/aeee5ec8...mp4
[Loader] GET ...aeee5ec8...mp4 headers=["Range": "bytes=0-4712853543", ...]
[Loader] HTTP 206 for /video/aeee5ec8...mp4
[Asset] playable=true duration=1494.600 trackCount=7
[Player] ▶️ timeControlStatus=playing
```

All subsequent range requests (8 total) also returned **206**.

### Curl verification (15:02–15:05 UTC)

| Test | Protocol | Status | `cf-cache-status` |
|---|---|---|---|
| Inside container, direct to Apache | HTTP/2 | **206** | n/a |
| Outside, through Cloudflare | HTTP/2 | **206** | MISS |
| Outside, forced HTTP/1.1 | HTTP/1.1 | **206** | MISS |
| Outside, mid-file range | HTTP/2 | **206** | MISS |
| HEAD request | HTTP/2 | **200** (correct for HEAD) | MISS |

---

## Affected File

| Property | Value |
|---|---|
| **File** | `aeee5ec88a4d8dd37534fe82b46d5328c5db9c68490b39c477d923378c66f1cd.mp4` |
| **Size** | 4,712,853,544 bytes (~4.7 GB) |
| **Content-Type** | `video/mp4` |
| **Duration** | ~24 minutes (1494.6 seconds) |
| **URL path** | `/video/aeee5ec8...mp4` |

---

## Updated Root-Cause Assessment

The issue is no longer best described as transient or Cloudflare-originated.
Newer evidence shows:

1. **The app sends the correct `Range` header** — the loader logs confirm
   requests like `Range: bytes=0-1`.

2. **Cloudflare is not serving a cache hit** — failing responses include
   `cf-cache-status: MISS`.

3. **Apache origin itself logs the bad response** — access logs show the same
   ranged GET arriving at Apache and being recorded as `HTTP 200` with the
   full file length.

4. **`CacheDisable /video/` is present in the live Apache config** — this
   weakens the theory that the problem is explained solely by an obvious
   `mod_cache_disk` misconfiguration.

The current best conclusion is:

- **The failure is origin-side Apache behavior**
- **The app and AVFoundation are not fabricating the issue**
- **Cloudflare is not the primary source of the bad `200`**
- **`mod_cache_disk` may still be involved, but it is no longer sufficient as
  the only explanation**

---

## Preventive Recommendations

Even though the failure was transient, the following configuration changes
would reduce the likelihood of recurrence.

### 1. Exclude media paths from Apache disk cache

`CacheEnable disk /` in `apache2.conf.j2` caches everything including large
media files. Caching multi-GB files in `mod_cache_disk` is wasteful (they're
already on disk) and risks breaking range request handling.

```apache
# In apache2.conf.j2, inside the <IfModule mod_cache.c> block, add:
CacheDisable /video/
CacheDisable /audio/
```

### 2. Let Apache set `Accept-Ranges` natively

The manual `Header set Accept-Ranges "bytes"` in `apache2.conf.j2` advertises
range support even when `mod_cache` is serving a response that may not honor
ranges. Removing this line lets Apache's native handler set the header only
when it can actually fulfill range requests.

```apache
# In apache2.conf.j2, change:
<FilesMatch "\.(mp4|mov|au|mp3|wav|aac)$">
    # Remove this line:
    # Header set Accept-Ranges "bytes"

    # Keep CORS headers:
    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Headers "Range"
    Header set Access-Control-Expose-Headers "Content-Length, Content-Range, Accept-Ranges"
</FilesMatch>
```

### 3. Monitor Cloudflare cache behavior for media paths

- Check Cloudflare dashboard for cache rules affecting `/video/*` or `*.mp4`.
- Consider adding a Cloudflare page rule to **bypass cache** for `/video/*`
  and `/audio/*` paths, since these are authenticated and should not be
  cached at the CDN edge.

---

## App-Side Changes Made (2026-03-15)

These changes improved resilience and diagnostics regardless of the transient
server-side issue:

1. **Proxy loader for all authenticated media** — all authenticated requests
   now route through the custom `MediaResourceLoader` instead of relying on
   AVFoundation's `AVURLAssetHTTPHeaderFieldsKey` (which was unreliable for
   some large files).

2. **Explicit range-response validation** — the resource loader now detects
   when a ranged request receives HTTP 200 instead of 206 and surfaces a
   clear error: *"Server did not honor byte-range request for media playback."*
   This made diagnosing the transient failure possible.

3. **Removed pre-load asset poisoning** — `logAssetDiagnostics` was calling
   `loadValuesAsynchronously` before `AVPlayerItem` was created. If the first
   range request failed, the asset keys were permanently cached as "failed",
   preventing AVFoundation from retrying. This diagnostic call has been
   replaced with a non-destructive status check that only reads cached key
   states.

---

## Current Status

- **Issue is intermittently reproducible** — large MP4 playback sometimes
  fails because the first ranged GET receives `HTTP 200` instead of `206`
- **Apache origin is confirmed to be returning the bad `200`** — access logs
  show the same media GET recorded as `200` with full file length
- **Cloudflare is not the primary source of the failure** — failing responses
  include `cf-cache-status: MISS`
- **App now shows a specific error message** when range mismatch occurs:
  *"Server did not honor byte-range request for media playback. Expected
  HTTP 206 Partial Content but received HTTP 200"*
- **App now logs full response headers and player failure details** on range
  mismatch for diagnosis
- **`CacheDisable /video/` is present in the live Apache config** but the
  issue still reproduces, so `mod_cache_disk` alone is not yet proven as the
  sole cause

---

## Deferred Debugging Steps

These steps were intentionally saved for later follow-up after confirming that:

- the app sends a correct `Range` header
- Cloudflare reports `cf-cache-status: MISS`
- Apache access logs still show intermittent `HTTP 200` for ranged media GETs

### 1. Confirm Apache has fully reloaded the updated config

Even if `/etc/apache2/apache2.conf` contains:

```apache
CacheDisable /video/
CacheDisable /audio/
```

the running Apache worker processes may still be using older config if the
service was not reloaded after the file changed.

Suggested checks:

```bash
docker exec -it apacheWebServer apachectl -t
docker exec -it apacheWebServer apachectl -S
docker exec -it apacheWebServer ps -ef | grep apache2
docker exec -it apacheWebServer apachectl graceful
```

### 2. Clear any existing Apache disk cache artifacts

Even after adding `CacheDisable /video/`, old cache entries may still exist
under `/var/cache/apache2`.

Suggested checks:

```bash
docker exec -it apacheWebServer find /var/cache/apache2 -maxdepth 3 -type f | head
docker exec -it apacheWebServer rm -rf /var/cache/apache2/*
```

Then reproduce the same playback request again.

### 3. Add Apache access logging for the incoming `Range` header

The current access log confirms that Apache returns `HTTP 200` for some media
GETs, but it does not yet log the incoming `Range` request header directly.

Add `%{Range}i` to the access log format, and ideally also:

- `%{Content-Range}o`
- `%{Content-Length}o`
- `%{Accept-Ranges}o`

This will make it possible to prove in one Apache log line that:

- the request included `Range: bytes=0-1`
- Apache responded with `200`
- the response omitted `Content-Range`
- the response used full-file `Content-Length`

### 4. Re-test after Apache reload and cache clear

After reloading Apache and clearing `/var/cache/apache2`, retry the same
failing MP4 from the iOS app.

Interpretation:

- If Apache now returns `206`, then stale cache or unreloaded config was
  likely involved.
- If Apache still returns `200`, then `mod_cache` is no longer sufficient as
  the root-cause explanation.

### 5. Reassess the root-cause hypothesis if failures continue

If failures continue after `CacheDisable /video/`, Apache reload, and cache
clear, investigate other origin-side causes such as:

- authentication-protected static file handling
- HTTP/2-specific behavior
- another Apache module interfering with range handling
- file-specific handling differences for large MP4s

At that point, the correct conclusion is:

- the problem is still origin-side
- but `mod_cache_disk` alone is not enough to explain it

### 6. Keep the app-side diagnostics in place

The app now logs:

- outgoing request headers
- response headers on mismatch
- the exact user-facing range error
- AVFoundation item failure details

These diagnostics should remain enabled until the origin-side cause is fully
explained.
