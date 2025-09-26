# Current Apache Auth Model in GigHive

This document summarizes how the "admin" and "viewer" roles are currently enforced by Apache in the repo, and explains what `valid-user` means in Apache configuration.

## Current Enforcement in Templates

- **Primary auth file (htpasswd)**
  - Path is templated as `{{ gighive_htpasswd_path }}` and used in multiple places.

- **Most app paths are protected by "any valid user"**
  - File: `ansible/roles/docker/templates/default-ssl.conf.j2`
  - Snippet:
    ```apache
    <LocationMatch "^/(?:app/(?!cache(?:/|$)).*|api|db|debug|src|vendor|video|audio)(?:/|$)">
        AuthType Basic
        AuthName "GigHive Protected"
        AuthBasicProvider file
        AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
        Require valid-user
    </LocationMatch>
    ```
  - **Effect:** Any username present in the htpasswd can access `/api`, `/db`, `/video`, `/audio`, etc. This is why a "viewer" account currently can reach upload endpoints (it matches `valid-user`).

- **Admin-only routes are enforced by exact username**
  - File: `ansible/roles/docker/templates/default-ssl.conf.j2`
    ```apache
    <Files "changethepasswords.php">
        AuthType Basic
        AuthName "GigHive Admin"
        AuthBasicProvider file
        AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
        Require user admin
    </Files>
    ```
  - File: `ansible/roles/docker/templates/apache2.conf.j2`
    ```apache
    <Location "/db/upload_form_admin.php">
        AuthType Basic
        AuthName "Gighive Admin"
        AuthUserFile {{ gighive_htpasswd_path }}
        Require user {{ admin_user }}
    </Location>
    ```
  - **Effect:** Only the specific admin username (e.g., `admin`) is permitted on these endpoints.

- **Groups are not currently used**
  - There is no `AuthGroupFile` nor `Require group` in the templates today.
  - Current model effectively is:
    - "Admin" = specific username(s) whitelisted with `Require user` on selected endpoints.
    - "Viewer" = any other htpasswd user; because of `Require valid-user`, viewers can access most protected areas, including `/api` and `/db`.

## What `valid-user` Means in Apache

- **`valid-user`** is a special Apache authorization keyword used with the `Require` directive.
- When you configure:
  ```apache
  AuthType Basic
  AuthName "Protected"
  AuthBasicProvider file
  AuthUserFile /etc/apache2/htpasswd
  Require valid-user
  ```
  it authorizes **any authenticated user**—that is, any username/password found in the htpasswd file.
- `valid-user` is provided by Apache authorization modules (Apache 2.4+), typically `mod_authz_user` together with `mod_auth_basic` (and an authn provider such as `mod_authn_file`).

### How it differs from other forms
- `Require user alice bob`
  - Only those exact usernames are authorized.
- `Require group uploaders admins`
  - Only users in those groups are authorized (requires `AuthGroupFile` and `mod_authz_groupfile`).
- `Require all granted` / `Require all denied`
  - Allow or deny everyone.
- Expression-based `Require expr` (advanced)
  - e.g., IP-based or header-based conditions.

## Why viewers can upload today
- Because `/api`, `/db`, `/video`, `/audio` fall under `Require valid-user`, any htpasswd account (including a "viewer" account) can reach them unless a more specific rule overrides it.
- Admin-only exceptions are carved out explicitly with `Require user admin` (or `{{ admin_user }}`) for specific files like `upload_form_admin.php`.

## Modules typically needed
- `mod_auth_basic` — Basic authentication front-end.
- `mod_authn_file` — htpasswd file authentication.
- `mod_authz_user` — Enables `Require user` and `Require valid-user`.
- (If/when you add groups) `mod_authz_groupfile` — Enables `Require group` with an `AuthGroupFile`.

## If you later add an "uploader" group (future plan guidance)
- Restrict upload endpoints (forms + API) to `Require group uploaders admins`.
- Keep browse-only pages at `Require group viewers uploaders admins` (or `Require valid-user` if you want any account to browse).
- Continue admin-only with `Require group admins` or `Require user admin`.

---

This reflects the current state in:
- `ansible/roles/docker/templates/default-ssl.conf.j2`
- `ansible/roles/docker/templates/apache2.conf.j2`

And explains `valid-user` as used by Apache.
