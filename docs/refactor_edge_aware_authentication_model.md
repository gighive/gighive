---
description: Future refactor options for moving protected media authentication to an edge-aware model
---

# Refactor: Edge-Aware Authentication Model

## Purpose

If protected media should eventually remain cacheable at Cloudflare, authentication must move to an edge-aware model instead of origin-only Apache Basic Auth (`.htpasswd`).

In this context, an edge-aware model means access is evaluated before Cloudflare serves the object from cache, rather than relying on Apache at origin to challenge the request.

## Why This Refactor Exists

The current short-term design is:

- keep Apache Basic Auth on `/audio/*` and `/video/*`
- bypass Cloudflare cache for those same paths

That is the correct operational choice for now.

If the product later requires both of the following at the same time:

- protected media access control
- Cloudflare edge caching for media

then the authentication model will need to change.

## Candidate Approaches

### Cloudflare Access

- Cloudflare enforces authentication at the edge before the request is allowed through.
- This is a good fit when protected media should only be available to authenticated human users in a browser.
- It changes the authentication experience from native Basic Auth to a Cloudflare-managed access flow.

### Signed URLs

- The application generates time-limited URLs containing a verifiable token or signature.
- Cloudflare or the application design can then allow caching while still restricting access to users with a valid link.
- This is often a strong fit for media playback because it supports temporary, shareable access to specific files.

### Signed Cookies

- Instead of signing each URL individually, the application issues a cookie proving the user may access a protected set of media objects.
- This can work well when a user browses many protected files during a session.
- It avoids rewriting every media URL but requires careful cookie scoping and expiration handling.

### Application Media Proxy with Token Validation

- Requests flow through application code that validates a session, JWT, or media-specific token before serving or redirecting to the media object.
- This gives the application the most control over authorization rules, auditing, and per-user logic.
- It is flexible, but usually adds more implementation complexity and may require careful tuning to preserve efficient streaming and range request support.

## Design Shift

In all of these approaches, the key design shift is that authorization is no longer dependent on Apache Basic Auth at origin for every protected media request.

## Current Recommendation

Until such a refactor is needed, bypassing Cloudflare cache for `/audio/*` and `/video/*` remains the correct approach.
