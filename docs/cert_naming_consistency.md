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

gighive_cert_cn: gighive.internal
gighive_cert_dns_sans:
  - "*.gighive.internal"
  - "gighive.internal"

### Host Identity Variables (Apache)

gighive_fqdn: dev.gighive.internal

### Optional Alias Variables

gighive_server_aliases:
  - dev.gighive.app

---

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
