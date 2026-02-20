---
description: RCA for Cloudflare caching of error responses (cached-401 poisoning)
---

# Problem: Cached Error Responses Causing Authenticated Media Requests to Fail

## Summary

After introducing custom Apache error pages via `ErrorDocument` for `401/404/500`, some media URLs (e.g. `/audio/...` and `/video/thumbnails/...`) began intermittently returning `401 Unauthorized` even when valid Basic Auth credentials were supplied. The failures persisted until Cloudflare cache entries were purged.

The root issue was that certain `401` responses became cacheable (`Cache-Control: public, max-age=86400`), allowing Cloudflare to cache the error response for a given media URL. Cloudflare’s cache does not vary by the `Authorization` header by default, so a cached `401` could be served to subsequent authenticated requests.

## Impact

- Authenticated users experienced broken media playback and missing thumbnails.
- Browser UI showed repeated authentication prompts or broken images.
- The issue was “sticky” until Cloudflare cache was purged.

## Symptoms

Typical observed behavior:

- A protected media URL returns `401` and Cloudflare reports it is cached:

  - `HTTP/2 401`
  - `cf-cache-status: HIT`
  - `age: <seconds>`

- Even with credentials, the same URL continues to return `401`:

  - `curl -u viewer:... https://dev.gighive.app/video/thumbnails/<hash>.png` still returns `401` and `HIT`

- Adding a cache-busting query string returns the expected media:

  - `curl -u viewer:... https://dev.gighive.app/video/thumbnails/<hash>.png?nocache=<ts>` returns `200 image/png` and `MISS`

## Timeline (high-level)

- Bundle A implemented: Cloudflare response headers + Apache custom error pages.
- Shortly after: media URLs intermittently failed for authenticated users.
- Investigation using `curl` showed `401` responses were cached at Cloudflare for media URLs.
- Cache purge immediately restored functionality.
- Root cause traced to cacheable `401` responses (error bodies were `.html` and inherited cache headers).
- Fix applied in Apache template(s) to prevent cacheable error responses.

## Root Cause

### Direct cause

A `401` response for a media URL was emitted with cacheable headers (notably `Cache-Control: public, max-age=86400`), so Cloudflare cached the `401` response for that URL.

Because Cloudflare does not vary cache by `Authorization` by default, the cached `401` response was served to later authenticated requests to the same URL.

### Why did the `401` become cacheable?

Apache was configured to serve a custom `401` body via:

- `ErrorDocument 401 /errors/401.html`

The error body was an `.html` file, and a global Apache `FilesMatch` cache policy applied `Cache-Control: public, max-age=86400` to `*.html` (and other extensions), which inadvertently affected error responses.

## Contributing Factors

- Global cache policy applied by file extension rather than by response status.
- Error document mapping caused a protected URL’s error body to be an `.html` file, which matched the global cache rules.
- Cloudflare caching behavior does not vary on `Authorization` unless explicitly configured.
- Automated/unauthenticated requests (e.g., ZAP baseline scan, browser prefetching, or bots) can trigger `401` on media URLs, populating Cloudflare cache with a cacheable error object.

## Resolution

### Immediate remediation

- Purge Cloudflare cache entries for affected URLs.

### Durable remediation

- Ensure error responses are not cacheable:
  - Emit `Cache-Control: no-store` for `401/404/500` responses.
  - Ensure global cache headers are only applied to successful responses (e.g., `REQUEST_STATUS < 400`).

## Verification

### Confirm Cloudflare is not caching 401

```bash
curl -skI https://dev.gighive.app/video/ \
  | egrep -i '^(HTTP/|cache-control:|cf-cache-status:|age:|www-authenticate:)' 
```

Expected:

- `HTTP/... 401`
- `cache-control: no-store`
- `cf-cache-status: DYNAMIC`

### Confirm authenticated thumbnail returns image (no cache-bust)

```bash
curl -skI -u viewer:secretviewer \
  https://dev.gighive.app/video/thumbnails/<hash>.png \
  | egrep -i '^(HTTP/|content-type:|cache-control:|cf-cache-status:|age:)' 
```

Expected:

- `HTTP/... 200`
- `content-type: image/png`

### Origin-only check (bypass Cloudflare)

```bash
curl -skI -u viewer:secretviewer \
  https://gighive2.gighive.internal/video/thumbnails/<hash>.png \
  | egrep -i '^(HTTP/|content-type:|cache-control:|server:)' 
```

## Preventative Actions

- Maintain a single “cache policy” owner (Apache vs Cloudflare) and document it.
- Never allow cacheable headers on error responses (`401/403/404/500`).
- Add Cloudflare Cache Rules to bypass caching for authenticated media paths if appropriate.
- Re-run ZAP baseline after config changes and confirm no regressions in media behavior.
