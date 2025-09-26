# Minimal Changes to Add an "uploader" Role (Apache Basic Auth)

This document outlines the smallest set of changes to introduce an "uploader" role alongside existing `admin` and `viewer` users, while making `viewer` truly read‑only. It keeps the current htpasswd‑based authentication model and applies authorization with Apache directives (no application code changes required).

## Goals
- Add a new Apache auth group: `uploaders`.
- Restrict upload endpoints/forms to `uploaders` and `admins` only.
- Ensure `viewer` can browse/read but cannot upload.
- Minimize changes: prefer local `.htaccess` rules or small vhost Location blocks.

## 1) Update Apache auth group file
Add an `uploaders` line to your existing Apache group file (sibling to your current `viewers` and `admins`). Example (path will match your current setup, e.g. `/etc/apache2/groups`):

```
viewers: alice bob
uploaders: charlie
admins: admin
```

- Keep using the same htpasswd file you already have (e.g., `/etc/apache2/gighive.htpasswd`).
- Create uploader users with `htpasswd` if they do not exist yet.

## 2) Restrict upload forms (deny viewer)
Add `.htaccess` next to each upload form to allow only `uploaders` and `admins`.

File: `webroot/db/.htaccess` (or per‑file stanzas in your existing db/.htaccess)
```apache
<Files "upload_form.php">
  AuthType Basic
  AuthName "GigHive"
  AuthUserFile /etc/apache2/gighive.htpasswd
  AuthGroupFile /etc/apache2/groups
  Require group uploaders admins
</Files>

<Files "upload_form_admin.php">
  AuthType Basic
  AuthName "GigHive Admin"
  AuthUserFile /etc/apache2/gighive.htpasswd
  AuthGroupFile /etc/apache2/groups
  Require group admins
</Files>
```

Notes:
- This is the most surgical approach and avoids touching other areas.
- Ensure your vhost allows `.htaccess` to apply auth (`AllowOverride AuthConfig` or `AllowOverride All`).

## 3) Restrict the Upload API endpoint (deny viewer)
Place an `.htaccess` in the API directory to protect the upload endpoint:

File: `webroot/api/.htaccess`
```apache
<Files "uploads.php">
  AuthType Basic
  AuthName "GigHive"
  AuthUserFile /etc/apache2/gighive.htpasswd
  AuthGroupFile /etc/apache2/groups
  Require group uploaders admins
</Files>
```

This ensures only `uploaders` and `admins` can POST uploads.

## 4) Keep browse/read pages open to all authenticated users
If you currently gate browse areas (e.g., `/db/database.php`, random players) via `Require valid-user` or an equivalent, you can:

- Leave as‑is (any htpasswd user can browse), or
- Make it explicit with groups:

```apache
# Example in db/.htaccess or vhost
<Files "database.php">
  AuthType Basic
  AuthName "GigHive"
  AuthUserFile /etc/apache2/gighive.htpasswd
  AuthGroupFile /etc/apache2/groups
  Require group viewers uploaders admins
</Files>
```

Either approach is fine; the key point is that upload endpoints are stricter.

## 5) Optional: vhost alternative instead of .htaccess
If you prefer to keep auth in vhost templates (e.g., `default-ssl.conf.j2`), add per‑path Location/Files blocks mirroring the above:

```apache
<Location "/db/upload_form.php">
  AuthType Basic
  AuthName "GigHive"
  AuthUserFile /etc/apache2/gighive.htpasswd
  AuthGroupFile /etc/apache2/groups
  Require group uploaders admins
</Location>

<Location "/db/upload_form_admin.php">
  AuthType Basic
  AuthName "GigHive Admin"
  AuthUserFile /etc/apache2/gighive.htpasswd
  AuthGroupFile /etc/apache2/groups
  Require group admins
</Location>

<Location "/api/uploads.php">
  AuthType Basic
  AuthName "GigHive"
  AuthUserFile /etc/apache2/gighive.htpasswd
  AuthGroupFile /etc/apache2/groups
  Require group uploaders admins
</Location>
```

## 6) Modules and overrides checklist
- Enable these Apache modules (usually already on):
  - `mod_auth_basic`, `mod_authn_file`, `mod_authz_user`, `mod_authz_groupfile`
- Ensure `.htaccess` is allowed to set auth (if using .htaccess):
  - In vhost: `AllowOverride AuthConfig` (or `All`) for the relevant directories.

## 7) Test matrix
- Viewer user:
  - Can browse `/db/database.php` and other read‑only pages.
  - Is denied (HTTP 401/403) on `/db/upload_form.php` and `/api/uploads.php`.
- Uploader user:
  - Can access `/db/upload_form.php` and upload via `/api/uploads.php`.
  - Is denied on `/db/upload_form_admin.php`.
  - Can still browse read‑only pages.
- Admin user:
  - Can access everything.

## 8) No application code changes required
- iOS and web upload flows continue to use Basic Auth; just supply an `uploader` credential when uploading.
- MVC/PHP controllers remain unchanged—Apache enforces roles up front.

---

This plan introduces only:
- A new `uploaders` line in the Apache groups file.
- Three small auth stanzas targeting: `db/upload_form.php`, `db/upload_form_admin.php` (admin‑only), and `api/uploads.php`.

Everything else remains as it is.
