# Key Takeaway

Use of "dev" as hostname

chrome://net-internals/#hsts

Query HSTS/PKP domain
Input a domain name to query the current HSTS/PKP set:

Domain: 
dev
 
Found:
static_sts_domain: dev
static_upgrade_mode: FORCE_HTTPS
static_sts_include_subdomains: true
static_sts_observed: 1769475335
static_pkp_domain:
static_pkp_include_subdomains:
static_pkp_observed:
static_spki_hashes:
dynamic_sts_domain:
dynamic_upgrade_mode: UNKNOWN
dynamic_sts_include_subdomains:
dynamic_sts_observed:
dynamic_sts_expiry:
static_sts_domain: dev
static_upgrade_mode: FORCE_HTTPS
static_sts_include_subdomains: true
static_sts_observed: 1769475335

TLS failures were caused by hostname choices colliding with modern browser security policy, not Apache or OpenSSL misconfiguration.

Standardizing on gighive.internal and using clean wildcard SANs eliminates this entire class of issues and enables clean Cloudflare Full (strict) operation.
