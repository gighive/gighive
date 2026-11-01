# Problem: iOS does not trust `gighive.internal` / `dev.gighive.internal` certificate

## Summary
When the iOS app connects to the dev server using `https://dev.gighive.internal`, the request fails during TLS handshake with:

- `ATS failed system trust`
- `system TLS Trust evaluation failed(-9802)`
- `NSURLErrorDomain Code=-1200 "A TLS error caused the secure connection to fail."`

This happens even when the app’s **Disable Certificate Checking** toggle is enabled (which uses a `URLSessionDelegate` to accept the server trust).

Using the server’s LAN IP (for example `https://192.168.1.252`) can appear to “work” (TLS completes and the app reaches the HTTP endpoint), but it is still insecure and inconsistent across iOS versions.

## Symptoms
### Failure when using `dev.gighive.internal`
Typical log sequence:

- The app creates a request with insecure TLS enabled:
  - `[DBClient] GET https://dev.gighive.internal/db/database.php?format=json; ... insecureTLS=true`
- The app receives the server-trust challenge and accepts it:
  - `[TLS][InsecureTrustDelegate] ... method=NSURLAuthenticationMethodServerTrust ... usingCredential ...`
- iOS aborts anyway:
  - `ATS failed system trust`
  - `... Trust evaluation failed(-9802)`
  - `NSURLErrorDomain Code=-1200 ...`

### “Works” when using the LAN IP
With the LAN IP, the app can proceed to the HTTP layer (example showed `HTTP 401`), which means TLS completed.

The trust evaluation still reports failures (expected for a self-signed cert):

- `Root is not trusted`
- Often also:
  - `SSL hostname does not match name(s) in certificate`

## Root cause
### 1) The server presents a self-signed certificate
The device sees a 1-certificate chain:

- `certCount=1`
- subject summary: `gighive.internal`

And trust evaluation fails:

- `SecTrustEvaluateWithError trusted=false`
- `Root is not trusted`

This is consistent with a self-signed leaf certificate (issuer == subject) or a certificate signed by a CA that is not installed/trusted on the iPhone.

### 2) ATS / system trust enforcement still blocks the connection
Even though the app’s `URLSession` delegate returns `.useCredential` for the server-trust challenge, iOS still reports:

- `ATS failed system trust`

In practice, iOS can enforce system trust more rigidly for certain host patterns / internal domains, and delegate-based “trust override” behavior is not a reliable way to make self-signed TLS work long term.

## Why the LAN IP can succeed while `dev.gighive.internal` fails
Both endpoints are using the same certificate (still untrusted). However:

- When connecting by IP, ATS/domain-policy logic can behave differently because there is no domain name to evaluate.
- When connecting by internal hostname (like `*.internal`), iOS may take a stricter ATS/system-trust path and fail the connection even if the app accepts the presented `SecTrust`.

Either way, both configurations remain insecure until the certificate is correctly trusted.

## Current workaround (accepted for now)
Use the LAN IP for development (example: `https://192.168.1.252`).

Notes:

- This does not “fix” TLS trust. It only avoids the strict failure mode observed with `dev.gighive.internal`.
- IP-based access is brittle (IP changes, cert/SAN mismatch, different iOS behaviors).

## Best solution (Solution A): make `dev.gighive.internal` trusted
### Goal
Make iOS system trust succeed without relying on an insecure trust override.

### Recommended approach: local dev CA + proper SANs
1. Create/use a local developer Certificate Authority (CA), e.g. via `mkcert`.
2. Generate a server certificate with **Subject Alternative Names (SANs)** that include:
   - `dev.gighive.internal`
   - `gighive.internal`
   - (optional) the LAN IP as an IP SAN if you want IP access to validate too
3. Configure Apache to serve that certificate and (if applicable) the full chain.
4. Install the **dev CA root** certificate on the iPhone and enable it:
   - Install the profile on the device
   - Then enable trust:
     - `Settings -> General -> About -> Certificate Trust Settings`

### Expected results
- Safari on iOS can open `https://dev.gighive.internal` without warnings.
- The app can connect successfully with normal TLS validation.
- The “Disable Certificate Checking” toggle becomes unnecessary for routine dev use.

## Debugging notes (how we proved it)
The iOS app logs TLS challenge details from the `URLSessionDelegate`:

- Shows the `NSURLAuthenticationMethodServerTrust` challenge is received.
- Dumps certificate chain count and subject summary.
- Runs `SecTrustEvaluateWithError` and logs the error (“Root is not trusted”).

This confirmed the failing condition is not “delegate not called”, but rather “system trust fails for the presented certificate”.
