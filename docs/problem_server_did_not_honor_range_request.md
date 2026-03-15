# Problem: Server Does Not Honor HTTP Range Requests for Large MP4

**Date:** 2026-03-15
**Status:** Open — requires infrastructure investigation
**Severity:** High — blocks playback of at least one large video file

---

## Summary

The GigHive iOS app fails to play a specific large MP4 file (~4.7 GB, 24 minutes).
The server advertises `Accept-Ranges: bytes` in HEAD responses but returns
**HTTP 200 (full body)** instead of **HTTP 206 Partial Content** when the client
sends a `Range` header on GET requests for that file.

AVFoundation requires working byte-range support to stream MP4/MOV containers
because it must seek to specific byte offsets (e.g., the `moov` atom, track
indexes, sample tables) without downloading the entire file.

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

## Evidence from Logs

### HEAD request — server claims range support

```
[Player][HEAD] status=200 CT=video/mp4 CL=4712853544 Accept-Ranges=bytes
```

### GET with Range header — server ignores range

```
[Loader] GET https://dev.gighive.app/video/aeee5ec88a4d8dd37534fe82b46d5328c5db9c68490b39c477d923378c66f1cd.mp4
  headers=["Authorization": "Basic ...", "Range": "bytes=0-1"]
[Loader] HTTP 200 for /video/aeee5ec8...mp4
```

Expected response: **HTTP 206** with `Content-Range` header.
Actual response: **HTTP 200** (full-file body).

### Comparison: working large file (.mov, ~6 GB, same server)

```
[Loader] GET https://dev.gighive.app/video/3389a9a4fca6adf5a7c924029c443283e441b1f06a55d1cbbaf324e51ebfd338.mov
  headers=["Authorization": "Basic ...", "Range": "bytes=1852596-1900543"]
[Loader] HTTP 206 for /video/3389a9a4...mov
```

The `.mov` file correctly returns **206** for every range request.

### Resulting failure

```
[Asset] url=...aeee5ec8...mp4 playable=false protected=false duration=0.000 trackCount=0
  keyStatuses=["playable=failed error=Operation Stopped", ...]
[Player] Item status: failed: Operation Stopped
```

---

## Affected File

| Property | Value |
|---|---|
| **File** | `aeee5ec88a4d8dd37534fe82b46d5328c5db9c68490b39c477d923378c66f1cd.mp4` |
| **Size** | 4,712,853,544 bytes (~4.7 GB) |
| **Content-Type** | `video/mp4` |
| **Duration** | ~24 minutes |
| **URL path** | `/video/aeee5ec8...mp4` |

## Working File (for comparison)

| Property | Value |
|---|---|
| **File** | `3389a9a4fca6adf5a7c924029c443283e441b1f06a55d1cbbaf324e51ebfd338.mov` |
| **Size** | ~6 GB |
| **Content-Type** | `video/quicktime` |
| **Duration** | ~11 minutes |
| **URL path** | `/video/3389a9a4...mov` |

---

## Why Range Support Is Required

MP4 and MOV containers store metadata (the `moov` atom) that describes where
audio and video samples are located in the file. This atom can be at the
**beginning** or **end** of the file. For a 4.7 GB file:

- Without range support, the player would need to download the entire file
  before it can locate the metadata and start playback.
- AVFoundation (iOS media framework) **requires** byte-range access to parse
  the container, locate tracks, and begin streaming.
- If the server returns a full 200 response instead of a 206 partial response,
  AVFoundation cannot seek within the file and fails immediately with
  "Operation Stopped".

---

## Root Cause Analysis

### Primary suspect: `mod_cache_disk` serving cached full responses

In `apache2.conf.j2`, disk caching is enabled globally:

```apache
# apache2.conf.j2 lines 62-71
<IfModule mod_cache.c>
    CacheEnable disk /
    CacheRoot /var/cache/apache2
    CacheDirLevels 2
    CacheDirLength 2
    CacheDefaultExpire 3600
    CacheMaxExpire 86400
    CacheLastModifiedFactor 0.1
    CacheIgnoreCacheControl Off
</IfModule>
```

`CacheEnable disk /` caches **all paths** including `/video/` and `/audio/`.
When `mod_cache_disk` has a cached copy of a large media file, it can serve
the **full cached response as HTTP 200** instead of honoring the client's
`Range` header and returning `206 Partial Content`.

This is a known limitation of Apache's `mod_cache`: cached responses may not
properly support partial content negotiation, especially for large static
files that were originally cached as full `200 OK` responses.

### Contributing factor: aggressive cache headers on media files

```apache
# apache2.conf.j2 lines 29-31
<FilesMatch "\.(mp3|mp4|mov|au|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|otf|eot)$">
    Header set Cache-Control "public, max-age=604800, immutable"
</FilesMatch>
```

Media files are marked `immutable` with a 7-day max-age. This tells
`mod_cache_disk` to aggressively cache and serve these responses. Once a
full `200 OK` response is cached, subsequent `Range` requests may receive
the cached full response.

### Contributing factor: `Accept-Ranges` is manually set, not native

```apache
# apache2.conf.j2 lines 52-60
<FilesMatch "\.(mp4|mov|au|mp3|wav|aac)$">
    Header set Accept-Ranges "bytes"
    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Headers "Range"
    Header set Access-Control-Expose-Headers "Content-Length, Content-Range"
</FilesMatch>
```

This **advertises** `Accept-Ranges: bytes` but only as a response header —
it does not guarantee Apache will actually honor `Range` requests. Apache
handles ranges natively for static files served via its default handler,
but `mod_cache` sitting in front can intercept the request and serve the
cached full body before Apache's native range handler runs.

### Why .mov works but .mp4 doesn't

The `.mov` file may not be cached yet (or was evicted), so Apache's default
static file handler serves it directly — which natively supports byte ranges.
The `.mp4` file may have been cached by `mod_cache_disk` as a full response
on a prior non-ranged access (e.g., a browser download or HEAD request),
and subsequent ranged requests get the cached full `200`.

### Modules enabled (from Dockerfile)

```dockerfile
# Dockerfile line 50
a2enmod ssl http2 proxy proxy_http proxy_fcgi headers rewrite cache cache_disk security2 remoteip
```

Both `cache` and `cache_disk` are explicitly enabled at build time.

### Video files are bind-mounted into the container

```yaml
# docker-compose.yml.j2 lines 25-26
- "/home/{{ ansible_user }}/audio:{{ media_search_dir_audio }}"
- "/home/{{ ansible_user }}/video:{{ media_search_dir_video }}"
```

The `/video/` directory is a host bind mount. Apache serves these as static
files through its default handler — which natively supports ranges — but
only when `mod_cache` does not intercept first.

---

## Investigation Checklist

### 1. Confirm `mod_cache_disk` is the cause

- [ ] Shell into the running Apache container:
      ```bash
      docker exec -it <apache_container> bash
      ```
- [ ] Check if the failing MP4 is in the disk cache:
      ```bash
      find /var/cache/apache2 -name "*aeee5ec8*" -ls
      # or search by size
      find /var/cache/apache2 -size +1G -ls
      ```
- [ ] Clear the disk cache and retest:
      ```bash
      rm -rf /var/cache/apache2/*
      # then retry the failing MP4 from the iOS app
      ```
- [ ] If clearing the cache fixes it, this confirms `mod_cache_disk` as the
      root cause.

### 2. Test range support with curl

```bash
# Test the failing MP4 (include -v for full response headers)
curl -v -o /dev/null \
     -H "Range: bytes=0-1" \
     -H "Authorization: Basic dXBsb2FkZXI6c2VjcmV0dXBsb2FkZXI=" \
     "https://dev.gighive.app/video/aeee5ec88a4d8dd37534fe82b46d5328c5db9c68490b39c477d923378c66f1cd.mp4"
# Expected: HTTP/1.1 206 Partial Content
# Look for: Content-Range: bytes 0-1/4712853544

# Test the working MOV for comparison
curl -v -o /dev/null \
     -H "Range: bytes=0-1" \
     -H "Authorization: Basic dXBsb2FkZXI6c2VjcmV0dXBsb2FkZXI=" \
     "https://dev.gighive.app/video/3389a9a4fca6adf5a7c924029c443283e441b1f06a55d1cbbaf324e51ebfd338.mov"
# Expected: HTTP/1.1 206 Partial Content

# Test from inside the container (bypass Cloudflare)
docker exec -it <apache_container> \
  curl -v -o /dev/null -k \
       -H "Range: bytes=0-1" \
       -H "Authorization: Basic dXBsb2FkZXI6c2VjcmV0dXBsb2FkZXI=" \
       "https://localhost/video/aeee5ec88a4d8dd37534fe82b46d5328c5db9c68490b39c477d923378c66f1cd.mp4"
```

### 3. Check Cloudflare layer

- [ ] Inspect `cf-cache-status` header in the curl `-v` output above.
      If it shows `HIT`, Cloudflare may be serving a cached full response.
- [ ] Check Cloudflare page rules or cache rules for `*.mp4` or `/video/*` paths.

### 4. File-specific checks

- [ ] Verify the `.mp4` file is a regular file on disk (not a symlink, not
      zero-length, not still being written):
      ```bash
      ls -la /home/<user>/video/aeee5ec88a4d8dd37534fe82b46d5328c5db9c68490b39c477d923378c66f1cd.mp4
      stat /home/<user>/video/aeee5ec88a4d8dd37534fe82b46d5328c5db9c68490b39c477d923378c66f1cd.mp4
      ```

---

## Recommended Fixes (in `apache2.conf.j2`)

### Fix 1 (targeted): Disable disk cache for media paths

Add `CacheDisable` directives for `/video/` and `/audio/` so `mod_cache_disk`
does not intercept range requests for media files. These are large static files
where caching the full response is counterproductive (wastes disk, breaks
range requests).

```apache
<IfModule mod_cache.c>
    CacheEnable disk /
    CacheRoot /var/cache/apache2
    CacheDirLevels 2
    CacheDirLength 2
    CacheDefaultExpire 3600
    CacheMaxExpire 86400
    CacheLastModifiedFactor 0.1
    CacheIgnoreCacheControl Off

    # Do not cache media files — they are served as static files from disk
    # and require byte-range support for streaming playback.
    CacheDisable /video/
    CacheDisable /audio/
</IfModule>
```

### Fix 2 (alternative): Exclude media from disk cache by extension

If other paths under `/video/` or `/audio/` need caching, use environment
variables to selectively bypass the cache:

```apache
<FilesMatch "\.(mp4|mov|mp3|wav|aac|au)$">
    SetEnv no-cache
</FilesMatch>
```

Apache's `mod_cache` respects `SetEnv no-cache` and will bypass the cache
for matching requests.

### Fix 3 (belt-and-suspenders): Remove the manual `Accept-Ranges` header

Apache's default static file handler already sends `Accept-Ranges: bytes`
for files it can serve with range support. The manual `Header set
Accept-Ranges "bytes"` in the `<FilesMatch>` block is redundant and can be
misleading when `mod_cache` is actually serving the response (since the cache
may not support ranges but the header still claims it does).

Consider changing:

```apache
<FilesMatch "\.(mp4|mov|au|mp3|wav|aac)$">
    # Enable range requests for video/audio streaming
    Header set Accept-Ranges "bytes"
    ...
</FilesMatch>
```

To remove the `Accept-Ranges` line and let Apache set it natively:

```apache
<FilesMatch "\.(mp4|mov|au|mp3|wav|aac)$">
    # CORS headers for cross-origin playback
    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Headers "Range"
    Header set Access-Control-Expose-Headers "Content-Length, Content-Range, Accept-Ranges"
</FilesMatch>
```

---

## App-Side Changes Made (2026-03-15)

While the root cause is server-side, the following app-side changes were made
to improve resilience and diagnostics:

1. **Proxy loader for all authenticated media** — all authenticated requests
   now route through the custom `MediaResourceLoader` instead of relying on
   AVFoundation's `AVURLAssetHTTPHeaderFieldsKey` (which was unreliable for
   some large files).

2. **Explicit range-response validation** — the resource loader now detects
   when a ranged request receives HTTP 200 instead of 206 and surfaces a
   clear error: *"Server did not honor byte-range request for media playback."*

3. **Removed pre-load asset poisoning** — `logAssetDiagnostics` was calling
   `loadValuesAsynchronously` before `AVPlayerItem` was created. If the first
   range request failed, the asset keys were permanently cached as "failed",
   preventing AVFoundation from retrying. This diagnostic call has been
   replaced with a non-destructive status check that only reads cached key
   states.

---

## Expected Resolution

The server must return **HTTP 206 Partial Content** with a valid
`Content-Range` header when the client sends a `Range` request header.
This is a standard HTTP requirement for media streaming and is already
working correctly for `.mov` files on the same server.

The most likely fix is adding `CacheDisable /video/` and `CacheDisable /audio/`
to the `mod_cache` block in `apache2.conf.j2` so that Apache's native static
file handler serves media files directly with proper byte-range support.
