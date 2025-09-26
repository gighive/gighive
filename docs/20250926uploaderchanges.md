# Minimal Changes to Add an "uploader" Role (Current Model: htpasswd + Require valid-user)

This document reflects your current Apache setup and user set:

- Users: `admin` (existing), `viewer` (existing), and `uploader` (new to be added).
- You use a single htpasswd file for authentication.
- Authorization largely uses `Require valid-user`.
- There is no `/etc/apache2/groups` file today.

Goal: introduce an effective "uploader" role with minimal changes, without introducing Apache groups right now.

## Minimal approach (centralized in the vhost: default-ssl.conf.j2)

Standard practice is to put per-URL authentication/authorization in the site’s VirtualHost, not globally in `apache2.conf` and not in scattered `.htaccess` files. We will centralize all rules in `default-ssl.conf.j2` and avoid overlapping rules in `apache2.conf.j2`.

Keep browse/read areas as-is (using `Require valid-user`) in the vhost, but make upload endpoints/forms stricter by allowing only `admin` and `uploader` via `Require user`.

### 1) In `default-ssl.conf.j2`, add vhost-level rules

```apache
# Browse/read areas (any authenticated account)
<LocationMatch "^/(?:app/(?!cache(?:/|$)).*|api|db|debug|src|vendor|video|audio)(?:/|$)">
  AuthType Basic
  AuthName "GigHive Protected"
  AuthBasicProvider file
  AuthUserFile /etc/apache2/gighive.htpasswd
  Require valid-user
</LocationMatch>

# Upload forms (admin + uploader only)
<Location "/db/upload_form.php">
  AuthType Basic
  AuthName "GigHive"
  AuthUserFile /etc/apache2/gighive.htpasswd
  Require user admin uploader
</Location>

# Admin upload form (admin only)
<Location "/db/upload_form_admin.php">
  AuthType Basic
  AuthName "GigHive Admin"
  AuthUserFile /etc/apache2/gighive.htpasswd
  Require user admin
</Location>

# Upload API (admin + uploader only)
<Location "/api/uploads.php">
  AuthType Basic
  AuthName "GigHive"
  AuthUserFile /etc/apache2/gighive.htpasswd
  Require user admin uploader
</Location>
```

### 2) Remove/avoid overlapping rules in `apache2.conf.j2`
- Ensure there are no duplicate or conflicting `<Location>`/`<Files>` auth blocks for the same paths in `apache2.conf.j2`.
- Keep security headers, caching, PHP-FPM proxy, etc., in `apache2.conf.j2` if desired, but consolidate auth in the vhost to avoid surprises.

### 3) Leave browse/read pages as `Require valid-user`
Your current config already protects broader app paths using `Require valid-user` (any authenticated account):

```apache
<LocationMatch "^/(?:app/(?!cache(?:/|$)).*|api|db|debug|src|vendor|video|audio)(?:/|$)">
  AuthType Basic
  AuthName "GigHive Protected"
  AuthBasicProvider file
  AuthUserFile /etc/apache2/gighive.htpasswd
  Require valid-user
</LocationMatch>
```

You can keep this as-is so viewers can still browse read-only pages. The `.htaccess` snippets above will override with stricter `Require user` for the specific upload endpoints.

### 4) Optional alternative: .htaccess (not preferred)
If you must use `.htaccess`, you can place equivalent `<Files>` stanzas in `webroot/db/.htaccess` and `webroot/api/.htaccess`. Ensure the vhost has `AllowOverride AuthConfig` enabled. Centralizing in the vhost remains the recommended approach.

### 5) Modules and overrides checklist
- Ensure these Apache modules are enabled (usually already on):
  - `mod_auth_basic`, `mod_authn_file`, `mod_authz_user`
- If using .htaccess, confirm your vhost allows it:
  - `AllowOverride AuthConfig` (or `All`) for the relevant directories.

### 6) Test matrix (exact users: admin, viewer, uploader)
- Viewer:
  - Can browse `/db/database.php` and other read-only pages (valid-user).
  - Is denied (401/403) on `/db/upload_form.php` and `/api/uploads.php`.
- Uploader:
  - Can access `/db/upload_form.php` and upload via `/api/uploads.php`.
  - Is denied on `/db/upload_form_admin.php`.
  - Can still browse read-only pages.
- Admin:
  - Can access everything.

---

## Optional future enhancement: switch to groups later
If you later prefer role names instead of explicit user allowlists, you can introduce an Apache `AuthGroupFile` (e.g., `/etc/apache2/groups`) and replace `Require user ...` with `Require group uploaders admins`. That requires enabling `mod_authz_groupfile` and maintaining a group file. For now, the `Require user` approach achieves the same outcome with the least change.

---

## Appendix: Proposed diffs (do not apply yet)

Below are unified diffs showing the exact changes we plan to make to centralize auth in `ansible/roles/docker/templates/default-ssl.conf.j2` and remove overlapping auth from `ansible/roles/docker/templates/apache2.conf.j2`.

### 1) default-ssl.conf.j2 (add vhost-level rules for uploader/admin)

```diff
*** a/ansible/roles/docker/templates/default-ssl.conf.j2
--- b/ansible/roles/docker/templates/default-ssl.conf.j2
@@
     # --- EXISTING PROTECTED AREAS (any valid user) ---
     <LocationMatch "^/(?:app/(?!cache(?:/|$)).*|api|db|debug|src|vendor|video|audio)(?:/|$)">
         AuthType Basic
         AuthName "GigHive Protected"
         AuthBasicProvider file
         AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
         Require valid-user
     </LocationMatch>

+    # --- UPLOAD FORMS: admin + uploader ---
+    <Location "/db/upload_form.php">
+        AuthType Basic
+        AuthName "GigHive"
+        AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
+        Require user admin uploader
+    </Location>
+
+    # --- ADMIN UPLOAD FORM: admin only ---
+    <Location "/db/upload_form_admin.php">
+        AuthType Basic
+        AuthName "GigHive Admin"
+        AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
+        Require user admin
+    </Location>
+
+    # --- UPLOAD API: admin + uploader ---
+    <Location "/api/uploads.php">
+        AuthType Basic
+        AuthName "GigHive"
+        AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
+        Require user admin uploader
+    </Location>
```

### 2) apache2.conf.j2 (remove overlapping auth block)

Currently `ansible/roles/docker/templates/apache2.conf.j2` includes an auth block for `/db/upload_form_admin.php`. We will remove it to avoid duplication, since the vhost will own all auth.

```diff
*** a/ansible/roles/docker/templates/apache2.conf.j2
--- b/ansible/roles/docker/templates/apache2.conf.j2
@@
-# Restrict admin-only upload form
-<Location "/db/upload_form_admin.php">
-    AuthType Basic
-    AuthName "Gighive Admin"
-    AuthUserFile {{ gighive_htpasswd_path }}
-    Require user {{ admin_user }}
-</Location>
```

Notes:
- We are not changing headers, caching, PHP-FPM proxy, or logging in `apache2.conf.j2`.
- After applying the above, restart Apache inside your container to load the unified rules.
