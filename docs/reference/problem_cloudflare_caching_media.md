---
description: Cloudflare caching of protected media can bypass origin Basic Auth expectations
---

# Problem: Cloudflare Caching of Protected Media Files

## Summary

Protected media under `/audio/*` and `/video/*` can be cached by Cloudflare after an initial request. Once a media object is served from Cloudflare edge cache, the request may no longer reach Apache at origin.

Because authentication for these paths is currently enforced by Apache Basic Auth (`.htpasswd`), serving a cached object from Cloudflare creates a mismatch with user expectations: users expect an authentication challenge for protected media, but a Cloudflare cache hit bypasses the origin auth check entirely.

This is a distinct issue from cached error responses. In this case, the concern is successful media objects being cached at the CDN edge even though access control is still being enforced only at the origin.

## Current Decision

For now, the chosen approach is to bypass Cloudflare caching for protected media paths:

- `/audio/*`
- `/video/*`

This preserves the current security and user experience model:

- every protected media request reaches Apache
- Apache remains the authority that issues the Basic Auth challenge
- users continue to see the expected authentication prompt for protected media

## Tradeoff

Bypassing edge caching for media may increase origin load somewhat, especially for repeated playback requests.

For current needs, this is considered the correct tradeoff for customer-facing behavior and access control consistency.

Operationally, existing TUS/chunked upload support remains beneficial for upload reliability and large-transfer handling, but it does not directly offset increased origin load for protected media downloads.

## Proposed Cloudflare Rule

Use a Cloudflare Cache Rule that matches protected media paths and disables caching.

### Recommended expression

```text
starts_with(http.request.uri.path, "/audio/") or starts_with(http.request.uri.path, "/video/")
```

### Recommended action

Set the rule action so Cloudflare does not cache matching requests.

Depending on the Cloudflare UI/version, this is typically expressed as one of the following:

- `Cache eligibility: Bypass cache`
- or `Cache status: Bypass`

## Why This Rule Is Correct

Apache Basic Auth only works when the request reaches origin.

If Cloudflare serves a cached media response at the edge:

- Apache does not receive the request
- Apache cannot issue a `401 Unauthorized` challenge
- Cloudflare does not automatically vary cached objects by the `Authorization` header

As a result, origin-only auth and CDN edge caching are not compatible for these protected paths.

The correct short-term design is therefore:

- keep Apache Basic Auth on `/audio/*` and `/video/*`
- bypass Cloudflare cache for those same paths

## Verification After Rule Deployment

Expected behavior after enabling the bypass rule:

### Unauthenticated request

A request to a protected media URL should:

- return `401 Unauthorized`
- include `WWW-Authenticate`
- not be served from Cloudflare cache

Example:

```bash
curl -skI https://your-host.example/video/path/to/file.mp4 \
  | egrep -i '^(HTTP/|www-authenticate:|cache-control:|cf-cache-status:|age:)' 
```

### Authenticated request

An authenticated request should:

- return `200 OK`
- be fetched from origin rather than edge cache

Example:

```bash
curl -skI -u viewer:yourpassword https://your-host.example/video/path/to/file.mp4 \
  | egrep -i '^(HTTP/|content-type:|cache-control:|cf-cache-status:|age:)' 
```

### Expected Cloudflare behavior

For matching protected media paths, the response should not show a cache hit for the object.

## Rollout Note

Once the Cloudflare rule is deployed, new requests for matching media paths should begin bypassing edge cache immediately. End users generally should not need to restart their browser to pick up the change.

However, what a user observes right away can still be affected by browser-local behavior:

- a browser may reuse a locally cached media object if it already has one
- a browser may continue sending previously accepted Basic Auth credentials during the current session

In practice, a page reload or a fresh request is usually enough to observe the new behavior. A full browser restart should not normally be required.

## Longer-Term Alternatives

Future refactor options for an edge-aware authentication model are documented in:

- `docs/refactor_edge_aware_authentication_model.md`

Until then, bypassing Cloudflare cache for `/audio/*` and `/video/*` is the correct approach.
