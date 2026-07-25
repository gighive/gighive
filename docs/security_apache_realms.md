---
# Apache Basic Auth realms (GigHive)

GigHive uses Apache HTTP Basic Authentication for access control. Apache presents a login prompt using the `AuthName` directive, which is commonly referred to as the **realm**.

A realm is not an authorization boundary by itself; it is a browser-visible label used for credential caching and UI prompts. Authorization is enforced by Apache directives like `Require valid-user` and `Require user ...`.

This document describes the realms currently configured in the Apache vhost template:

- `ansible/roles/docker/templates/default-ssl.conf.j2`

## Realm: "GigHive Protected"

**Purpose**

Protects general non-root application paths so that only a valid Basic-Auth user may access them.

**Where configured**

- `AuthName "GigHive Protected"`

**Primary coverage**

- General protected application paths matched by:
  - `LocationMatch "^/(?:app/(?!cache(?:/|$)).*|api|db/(?!health\.php$).*|debug|src|vendor|video(?!/podcasts(?:/|$))|audio)(?:/|$)"`

**Notes**

- The homepage (`/` and `/index.php`) is intentionally public.
- `/db/health.php` is explicitly excluded from the general authentication requirement.
- Some paths (like uploads and admin) have more specific blocks that override the general realm for those endpoints.

## Realm: "GigHive Upload"

**Purpose**

Protects upload-related endpoints with a narrower allowlist of users.

**Where configured**

- `AuthName "GigHive Upload"`

**Endpoints**

- `/db/upload_form.php`
- `/api/uploads.php`
- `^/api/uploads(?:/|$)`
- `/api/media-files`
- `^/files(?:/|$)` (tusd upload endpoint proxy)

**Authorization**

- Restricted to:
  - `Require user admin uploader`

## Realm: "GigHive Admin"

**Purpose**

Protects admin-only endpoints.

**Where configured**

- `AuthName "GigHive Admin"`

**Examples**

- `/admin.php`
- `/db/upload_form_admin.php`
- `/admin/restore_database.php`
- `/admin/restore_database_status.php`

**Authorization**

- Restricted to:
  - `Require user admin`

## Operational notes

- Changing a realm (`AuthName`) primarily affects browser behavior:
  - Browsers cache Basic Auth credentials by origin and realm.
  - After a realm rename, users will typically see a fresh login prompt the first time they access the affected paths.

## Null password variables — security behavior

The optional htpasswd user tasks in `ansible/roles/security_basic_auth/tasks/main.yml` (viewer, uploader, guest) use a three-part `when` guard:

```yaml
- gighive_guest_password is defined
- gighive_guest_password is not none
- (gighive_guest_password | length) > 0
```

**What happens when a password variable is set to YAML null (bare key, no value):**

- `is defined` evaluates to `True` — the key exists in the vars file.
- `is not none` evaluates to `False` — the task is **skipped**.
- No htpasswd entry is written for that user.
- Apache will **deny all login attempts** for that username with HTTP 401 because no matching hash exists in the htpasswd file.

**There is no passwordless-access risk.** A null variable does not create an account; it suppresses account creation entirely. htpasswd has no concept of a blank/empty password entry — the module requires a non-empty string and the `| length > 0` guard enforces this.

**Why the `is not none` guard is required:**
Without it, Jinja2's `| length` filter raises a `TypeError` on `NoneType`, crashing the playbook (`object of type 'NoneType' has no len()`). The fix was applied 2026-07-25.

**Convention for disabling a user account in a given environment:**
Set the password variable to YAML null (no value after the colon) in the relevant `group_vars/<env>/secrets.yml`. The task will skip silently and no htpasswd entry will be created or modified for that user.

