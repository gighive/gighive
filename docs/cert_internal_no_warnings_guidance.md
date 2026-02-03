# Internal TLS: “No Browser Warnings” Guidance

This document explains why you can still get browser TLS warnings even when a certificate’s `subjectAltName` matches the hostname/IP you’re visiting, and it outlines options (in increasing “properness”) to achieve **clean HTTPS without warnings** for internal/LAN access.

This guidance is intended to complement `docs/cert_naming_consistency.md`.

---

## Why warnings happen even when SAN matches

A TLS certificate can match the name you’re connecting to (DNS name or IP in SAN) and still trigger a warning if the browser does not **trust the issuer**.

Common causes:

- The certificate is **self-signed** (issuer == subject).
- The certificate is signed by a private CA, but the private CA’s root is **not installed** in the client trust store.
- The server does not provide the **full chain** (missing intermediate CA).
- The certificate is expired or not-yet-valid.

Name matching (SAN) and issuer trust are **independent**.

---

## Scope and assumptions

- IP-based access (`https://<ip>`) is supported for convenience, but it is acceptable for it to produce warnings unless you explicitly implement trust for it.
- For a clean, warning-free experience, prefer **DNS names that match the certificate SAN**.
- Avoid bare hostnames like `dev`, `qa`, etc. for HTTPS due to browser policy (see `cert_naming_consistency.md`).

---

## Options in increasing “properness”

### Option 0: Do nothing (accept warnings)

- Keep using self-signed certs.
- Users will see “Connection not private” warnings.
- Automation must use insecure modes (e.g., `curl -k`) or disable verification.

Pros:

- No setup

Cons:

- Not suitable for a smooth UX
- Breaks strict clients

---

### Option 1: Use HTTP for IP access; use HTTPS only for the recommended DNS name

- Document two access patterns:
  - `https://<fqdn>` (recommended, matches SAN)
  - `http://<ip>` (fallback, no TLS)

Pros:

- Very simple
- Avoids the “untrusted issuer” UX for the IP path

Cons:

- IP access is not encrypted

---

### Option 2: Trust a single self-signed leaf certificate on each client (quick but brittle)

- Generate a self-signed certificate.
- Install that certificate into each client device trust store.

Pros:

- Fastest way to eliminate warnings without a CA

Cons:

- Brittle operationally
- If you rotate/reissue the cert, every client must re-trust it
- Doesn’t scale well to multiple internal services

---

### Option 3: Create an internal CA and trust its root on clients (recommended for LAN)

- Create one internal CA (root certificate).
- Install the CA root cert into each client’s trust store.
- Issue server certificates for your internal names (e.g., `dev.gighive.internal`) from that CA.

Pros:

- Clean UX across browsers
- Scales to multiple internal hosts/services
- Cert rotation is manageable (clients trust the CA, not each individual leaf)

Cons:

- Requires a one-time “install CA root” step per device
- You must protect CA private key

Notes:

- Align the certificate SAN set with your chosen internal namespace.
- Keep SANs minimal and intentional (see `cert_naming_consistency.md`).

---

### Option 4: Let’s Encrypt (publicly trusted, most proper for public hostnames)

- Use Let’s Encrypt (ACME) to obtain certificates that are trusted by browsers by default.
- Works best when:
  - the hostname is publicly resolvable, and
  - you can complete HTTP-01 or DNS-01 challenges.

Pros:

- No client-side trust installation required
- Widest compatibility (browsers, mobile apps, strict API clients)

Cons:

- Typically requires a public domain name you control
- Not appropriate for non-public internal TLDs like `.internal`

---

### Option 5: Cloudflare Origin Certificates (proper for Cloudflare-to-origin strict mode)

- Use Cloudflare Origin certs when Cloudflare is in front of your origin.
- This enables Cloudflare “Full (strict)” between Cloudflare and your origin.

Pros:

- Great for Cloudflare-based deployments
- Clean and maintainable when traffic flows through Cloudflare

Cons:

- Origin certs are not universally browser-trusted for direct-to-origin LAN browsing
- Direct browsing to the origin (bypassing Cloudflare) will still warn unless you also implement Option 3 (internal CA trust) or use publicly trusted certs

---

## Practical recommendation

For internal/LAN “no warnings” with the naming strategy in `cert_naming_consistency.md`:

- Prefer **Option 3 (internal CA)** for a homelab/LAN.
- Use **Option 4 (Let’s Encrypt)** when you have a public domain and want universal trust.
- Use **Option 5 (Cloudflare Origin)** when Cloudflare is in the path and you primarily care about Cloudflare-to-origin strictness.

---

## Quick verification commands

Inspect what the server is presenting:

```bash
openssl s_client -connect <host-or-ip>:443 -servername <fqdn> </dev/null 2>/dev/null | openssl x509 -noout -issuer -subject -dates -ext subjectAltName
```

Interpretation:

- If `issuer == subject`, it’s self-signed (untrusted unless installed).
- If `subjectAltName` does not contain the exact DNS name you browse to, it’s a name mismatch.
