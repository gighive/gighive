---
title: Security
---
# GigHive Security Overview

This document summarizes the primary security controls implemented in the GigHive stack and how to configure them per environment.

## Highlights (Most to Least Critical)

- **Authentication on sensitive paths**
  - Basic Auth is enforced via Apache `LocationMatch` for protected areas.
  - Protected prefixes include: `/db`, `/src`, `/vendor`, `/audio`, `/video`, and `/app/` (excluding `/app/cache`).
  - Config: `ansible/roles/docker/templates/default-ssl.conf.j2` under the `LocationMatch` block.
  - Credentials file path: {% raw %}`{{ gighive_htpasswd_path }}`{% endraw %} (defaults to `/etc/apache2/gighive.htpasswd`).

- **TLS hardening and optional HSTS**
  - TLS 1.3 enabled with strong ciphers and order preference.
  - Optional HSTS can be enabled via group vars to enforce HTTPS on clients.
  - Config: `default-ssl.conf.j2` (TLS params); `default-ssl.conf.j2` HSTS header; additional cache headers in `apache2.conf.j2`.

- **Web Application Firewall (ModSecurity + CRS)**
  - ModSecurity and Core Rule Set templates are included and loaded to mitigate common web attacks.
  - Config includes: `apache2.conf.j2` (includes modsecurity), `templates/security2.conf.j2`, and `templates/crs-setup.conf.j2`.

- **Secure Apache defaults**
  - `.htaccess` is disabled (centralized config, faster and safer).
  - Directory indexes disabled, cache/log directories denied.
  - Sensitive files (e.g., `composer.json`, `composer.lock`, `config.php`, dotfiles) are denied.
  - Config: `default-ssl.conf.j2` `<Directory "/var/www/html"> AllowOverride None, Options -Indexes`, `<FilesMatch>` deny blocks.

- **Application-layer upload hardening**
  - App-level upload size cap via `UPLOAD_MAX_BYTES`.
  - MIME inspection via PHP `fileinfo` (`finfo`), checksum SHA-256, and sanitized filenames with per-type storage under `/audio` or `/video`.
  - Code: `ansible/roles/docker/files/apache/webroot/src/Validation/UploadValidator.php`, `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`.

## Protected Paths

Auth is enforced by Apache for the following URI prefixes:

- `/db`
- `/src`
- `/vendor`
- `/audio`
- `/video`
- `/app/` (excluding `/app/cache`)

To protect an additional path (e.g., `/upload`), add a block to `default-ssl.conf.j2`:

{% raw %}
```apache
<LocationMatch "^/upload(?:/|$)">
  AuthType Basic
  AuthName "GigHive Protected"
  AuthBasicProvider file
  AuthUserFile {{ gighive_htpasswd_path }}
  Require valid-user
</LocationMatch>
```
{% endraw %}

## Environment & Configuration

- `.env` variables are rendered from `ansible/roles/docker/templates/.env.j2` and populated via group vars in `ansible/inventories/group_vars/gighive.yml`.
- Relevant variables:
  - `UPLOAD_MAX_BYTES`: Application max upload size (bytes).
  - `FILENAME_SEQ_PAD`: Filename sequence padding (default 5).

Example in `group_vars/gighive.yml`:

```yaml
filename_seq_pad: 5
upload_max_bytes: 1500000000
```

`.env.j2` wires them through to the container environment.

## PHP/Apache Integration

- PHP handled via FPM proxy: `SetHandler "proxy:unix:/run/php/php8.1-fpm.sock|fcgi://localhost"` for `*.php`.
- Authorization header is explicitly passed to PHP-FPM: `SetEnvIfNoCase Authorization` in `default-ssl.conf.j2`.

## WAF Notes

- ModSecurity is included; ensure CRS is in enforcement mode in production.
- Tune false positives by adding exceptions to `security2.conf.j2` / CRS setup.

## Dependency Management

- Composer uses a committed `composer.lock` to ensure reproducible builds.
- Docker build runs `composer install --no-dev --optimize-autoloader --classmap-authoritative` inside the image.

## Hardening Checklist (Per Environment)

- **Enable HSTS** in `group_vars` (production only):
  - `gighive_hsts_enabled: true`
- **Rotate and manage Basic Auth credentials** (`gighive.htpasswd`):
  - Default path inside container: `/etc/apache2/gighive.htpasswd`.
- **Set upload limits** according to expected media size:
  - `upload_max_bytes` (app), and align PHP/nginx/Apache body limits if changed.
- **Review protected paths**: ensure any admin tools or upload forms live under protected prefixes (e.g., `/db`).
- **Verify ModSecurity/CRS** is active and logs are monitored.

## File/Path References

- Apache config templates:
  - `ansible/roles/docker/templates/apache2.conf.j2`
  - `ansible/roles/docker/templates/default-ssl.conf.j2`
  - `ansible/roles/docker/templates/security2.conf.j2`
  - `ansible/roles/docker/templates/crs-setup.conf.j2`
- App upload code:
  - `ansible/roles/docker/files/apache/webroot/src/Validation/UploadValidator.php`
  - `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
- Env/config:
  - `ansible/roles/docker/templates/.env.j2`
  - `ansible/inventories/group_vars/gighive.yml`

## Reporting Security Issues

Please report suspected vulnerabilities privately to the repository owner/maintainers. Provide:

- A clear description of the issue and impact.
- Reproduction steps or proof-of-concept where possible.
- Affected endpoints/paths and configuration context.

We will acknowledge receipt and work on a fix with an appropriate disclosure timeline.
