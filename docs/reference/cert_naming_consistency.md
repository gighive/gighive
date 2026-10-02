# Certificate Naming Consistency for GigHive

## Purpose

This document explains why we are standardizing internal DNS names and TLS certificate SANs, what problems we encountered, and the approved approach the team should follow going forward. The goal is to eliminate browser errors, avoid Chrome HSTS traps, simplify Cloudflare integration, and ensure the setup is maintainable and professional for an open-source project.

---

## Background: The Original Problem

We originally attempted to use bare hostnames such as:

- dev
- qa
- staging
- prod

Mapped via local DNS to internal IPs (e.g., 192.168.1.x). Modern browsers (Chrome in particular) hard-failed TLS connections to some of these names due to HSTS preload behavior.

---

## Root Cause

### Chrome HSTS Preload

Chrome preloads HSTS for certain labels such as `dev`. When this happens:
- Certificate mismatches are fatal
- Exceptions cannot be bypassed
- The issue cannot be fixed in Apache or OpenSSL

This was verified using:
chrome://net-internals/#hsts

### Certificate SAN Mismatch

Certificates contained unrelated DNS names and IP addresses but not the actual hostname being accessed, causing TLS validation failures under strict enforcement.

---

## Strategic Decisions

1. Never use bare hostnames for HTTPS
2. Separate internal and public DNS namespaces
3. Use wildcard SAN certificates
4. Eliminate IP SANs unless strictly required
5. Make Cloudflare Full (strict) achievable

---

## Final Naming Strategy

### Internal DNS (LAN only)

Standard domain:
*.gighive.internal

Examples:
- dev.gighive.internal
- qa.gighive.internal
- staging.gighive.internal
- prod.gighive.internal
- labvm.gighive.internal

Configured as static DNS entries on the FiOS router.

---

## TLS Certificate Strategy (Internal)

### Wildcard Certificate

- CN: gighive.internal
- SANs:
  - *.gighive.internal
  - gighive.internal

Notes:
- Wildcards belong in SAN, not CN
- IP SANs are intentionally excluded

---

## Apache Configuration Exceptions and Clarifications

Apache ServerName values must never be wildcards and must not be derived from certificate CNs.

### Global Apache Configuration

- ServerName must be a single, concrete hostname
- Example: dev.gighive.internal

### TLS VirtualHost Configuration

- ServerName must match the hostname clients connect to
- ServerAlias used only for explicit additional names

Example:

ServerName dev.gighive.internal
ServerAlias dev.gighive.app

---

## Standardized Ansible Variable Names

### Certificate Variables (TLS only)

These variables control what DNS names the generated TLS certificate is valid for. If a user browses to a hostname that is not present in the certificate SANs, the browser will show a certificate name mismatch error (even if Apache serves the site correctly).

gighive_cert_cn: gighive.internal
gighive_cert_dns_sans:
  - "*.gighive.internal"
  - "gighive.internal"

### Host Identity Variables (Apache)

gighive_fqdn: dev.gighive.internal

### Optional Alias Variables

This variable controls what hostnames Apache will accept and route to this vhost via `ServerAlias`. If a hostname is not listed here, Apache may not serve the site for that Host header (even if the certificate could be valid for it).

Rule of thumb:

- Put every hostname users will browse to in `gighive_cert_dns_sans` (TLS validity)
- Put every hostname Apache should serve on this vhost in `gighive_server_aliases` (HTTP routing)

gighive_server_aliases:
  - dev.gighive.app

---

## Example from group_vars/gighive/gighive.yml 

```yaml
# --- Naming strategy (gighive2/dev test) ---
# Certificate identity (TLS only)
gighive_cert_cn: "gighive.internal"
gighive_cert_dns_sans:
  - "*.gighive.internal"
  - "gighive.internal"
  - "*.gighive.app"

# Host identity (Apache / what clients connect to)
gighive_fqdn: "gighive.gighive.internal"

# Optional additional names for the vhost
gighive_server_aliases:
  - "gighive.gighive.internal"
  - "gighive2.gighive.internal"
  - "dev.gighive.internal"
  - "staging.gighive.internal"
  - "lab.gighive.internal"
  - "staging.gighive.app"
  - "lab.gighive.app"
```

## Required Template Usage

### Apache Global Config

ServerName {{ gighive_fqdn }}

### TLS VirtualHost Config

ServerName {{ gighive_fqdn }}
ServerAlias {{ alias }}

---

## Key Takeaway

TLS failures were caused by hostname choices colliding with modern browser security policy, not Apache or OpenSSL misconfiguration.

Standardizing on gighive.internal and using clean wildcard SANs eliminates this entire class of issues and enables clean Cloudflare Full (strict) operation.
