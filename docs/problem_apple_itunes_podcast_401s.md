---
description: RCA for Apple Podcasts receiving HTTP 401 on /video/podcasts media; fix to grant public access to podcast directory only
---

# Problem: Apple Podcasts Receives HTTP 401 on Podcast Episode Media

## Summary

Apple Podcasts repeatedly requests episode media files under `/video/podcasts/` and receives HTTP 401
Unauthorized on every attempt. Apache's Basic Auth protection covers all of `/video/`, which Apple
cannot satisfy because podcast clients do not send Basic Auth credentials. Apple retries approximately
once per day from different Apple-owned `17.x.x.x` IP addresses without success.

---

## Impact

- Podcast episodes are not validated or ingested by Apple Podcasts.
- Apple retries indefinitely; the episode remains in a limbo state and is never surfaced to subscribers.
- No subscriber-facing error is shown — the failure is silent from the subscriber's perspective.
- Affects any media file served under `/video/podcasts/` on non-staging environments.

---

## Symptoms

Apache access log shows a repeating HEAD + GET pair from Apple-owned IP addresses, both returning 401:

```text
17.58.59.23 - - [05/Aug/2026:01:59:54 -0400] "HEAD /video/podcasts/StormPigs20040818.mp4 HTTP/2.0" 401
17.58.59.23 - - [05/Aug/2026:01:59:54 -0400] "GET  /video/podcasts/StormPigs20040818.mp4 HTTP/2.0" 401
```

- Source IPs are in the `17.0.0.0/8` range (Apple-owned ASN).
- The pattern is HEAD immediately followed by GET, both failing.
- Requests repeat roughly once per day from rotating IPs.
- No successful responses are ever logged for these requests.

---

## Root Cause

The Apache vhost template (`ansible/roles/docker/templates/default-ssl.conf.j2`) protects all
of `/video/` using a `<LocationMatch "^/video(?:/|$)">` block that requires Basic Auth in all
non-staging environments:

```apache
<LocationMatch "^/video(?:/|$)">
    AuthMerging Off
    <If "%{HTTP_HOST} == 'staging.gighive.app'">
        Require all granted
    </If>
    <Else>
        AuthType Basic
        AuthName "GigHive Protected"
        AuthBasicProvider file
        AuthUserFile (htpasswd path)
        <RequireAny>
            Require valid-user
            Require env gallery_nonce_auth
        </RequireAny>
    </Else>
</LocationMatch>
```

`/video/podcasts/` falls under this block. Apple Podcasts does not send Basic Auth credentials
and there is no mechanism for it to obtain them, so every request receives 401.

A broader protected-path `LocationMatch` at the same level already contains a negative lookahead
that correctly excludes `/video/podcasts/` from its coverage:

```text
video(?!/podcasts(?:/|$))
```

So the broad rule is not the problem. The specific `/video` block is the sole source of the 401s.

**Why User-Agent whitelisting is not the solution:**
User-Agent strings are trivially spoofed and Apple rotates them without notice. The correct fix
is to make the directory genuinely public rather than selectively trust a client identifier.

---

## Resolution

Edit `ansible/roles/docker/templates/default-ssl.conf.j2`.

Change the `<If>` condition in the `/video` `<LocationMatch>` block to also grant public access
when the request URI is under `/video/podcasts/`:

**Before:**

```apache
    <LocationMatch "^/video(?:/|$)">
        AuthMerging Off
        <If "%{HTTP_HOST} == 'staging.gighive.app'">
            Require all granted
        </If>
        <Else>
            ...auth...
        </Else>
    </LocationMatch>
```

**After:**

```apache
    # --- VIDEO DIRECTORY: Basic Auth for all roles; gallery nonce for approved guests ---
    # Apache is a forward gate only; nonce validity was established in guest-gallery.php
    # or guest_event_view.php before stream_url was issued. SHA-256 filenames provide a
    # second layer — unguessable without possessing the original file.
    # The staging conditional is preserved so staging.gighive.app retains its existing
    # public /video/ access; /video/podcasts is also publicly accessible for Apple Podcasts.
    <LocationMatch "^/video(?:/|$)">
        AuthMerging Off
        <If "%{HTTP_HOST} == 'staging.gighive.app' || %{REQUEST_URI} =~ /^\/video\/podcasts(\/|$)/">
            Require all granted
        </If>
        <Else>
            AuthType Basic
            AuthName "GigHive Protected"
            AuthBasicProvider file
            AuthUserFile &#123;&#123; gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') &#125;&#125;
            <RequireAny>
                Require valid-user
                Require env gallery_nonce_auth
            </RequireAny>
        </Else>
    </LocationMatch>
```

**Only the `<If>` line changes.** Everything else in the block is unchanged.

### Why this form

- Uses `/^\/video\/podcasts(\/|$)/` with standard `/` delimiters and escaped slashes — the
  documented Apache `ap_expr` regex syntax. Alternate delimiters such as `m#...#` are not
  documented as valid in `ap_expr` and risk silently failing on some Apache versions.
- The negative lookahead `video(?!/podcasts(?:/|$))` in the broader `LocationMatch` (line 186)
  already prevents `/video/podcasts/` from matching the general protected-path rule, so no change
  is needed there.
- Staging behavior is unchanged: the `staging.gighive.app` branch of the `<If>` still fires first
  and still grants all access.
- All other `/video/` paths remain protected by Basic Auth + gallery nonce, unchanged.

---

## Verification

After deploying the updated template and rebuilding the Apache container:

**1. Public HEAD (expect HTTP/2 200):**

```bash
curl -I https://<host>/video/podcasts/StormPigs20040818.mp4
```

**2. Byte-range support (expect `Accept-Ranges: bytes` in response headers):**

```bash
curl -I \
  -H "Range: bytes=0-1023" \
  https://<host>/video/podcasts/StormPigs20040818.mp4
```

**3. Partial content (expect HTTP/2 206):**

```bash
curl -H "Range: bytes=0-1023" \
  -o /dev/null -D - \
  https://<host>/video/podcasts/StormPigs20040818.mp4
```

Expected response headers on step 3:

```text
HTTP/2 206
Content-Range: bytes 0-1023/<total-size>
Accept-Ranges: bytes
```

**4. Confirm other /video paths remain protected (expect HTTP/2 401):**

```bash
curl -I https://<host>/video/some-other-file.mp4
```

**5. Monitor Apache access log** for future Apple Podcasts retries (IPs in `17.0.0.0/8`).
Expected to change from:

```text
HEAD /video/podcasts/... 401
GET  /video/podcasts/... 401
```

to:

```text
HEAD /video/podcasts/... 200
GET  /video/podcasts/... 206
```

---

## Files Under Change

1. `gighiveinfra/ansible/roles/docker/templates/default-ssl.conf.j2` — change the `<If>` condition
   in the `<LocationMatch "^/video(?:/|$)">` block to also grant public access when
   `REQUEST_URI =~ /^\/video\/podcasts(\/|$)/`.
