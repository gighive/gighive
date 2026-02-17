## Purpose

Document how to provision an optional fourth Apache Basic Auth user (`guest`) for GigHive.

Goals:

- Provision `guest` only when explicitly configured in `group_vars/<env>/secrets.yml`.
- Give `guest` the same effective privilege scope as `viewer`.

Non-goals:

- No legacy/compatibility behavior.
- No changes to application-level authorization; this is Apache Basic Auth only.

## Current behavior (what exists today)

### Where Basic Auth is enforced

Apache access control is configured in the vhost template, not via `.htaccess`.

- File: `ansible/roles/docker/templates/default-ssl.conf.j2`

Patterns:

- Most protected areas are guarded by `Require valid-user`.
- Privileged endpoints are guarded by explicit user allowlists:
  - Upload endpoints: `Require user admin uploader`
  - Admin endpoints: `Require user admin`

Implication:

- Any user present in the htpasswd file can access the "valid-user" areas.
- Users not explicitly named in the allowlists cannot access upload/admin endpoints.

### Where the `.htpasswd` file is provisioned

htpasswd users are created/updated by an Ansible role.

- File: `ansible/roles/security_basic_auth/tasks/main.yml`

Today it creates:

- `admin` (always)
- `viewer` (optional, only when vars exist)
- `uploader` (optional, only when vars exist)

## Desired behavior

Add a new optional Basic Auth user:

- Username variable defined in `group_vars/<env>/<env>.yml`:
  - `guest_user: guest`
- Password secret defined in `group_vars/<env>/secrets.yml`:
  - `gighive_guest_password: "..."`

Provisioning rule:

- The guest user must only be created if `gighive_guest_password` exists and is non-empty.

Privilege rule:

- The guest user must have the same effective access as `viewer`.
  - Concretely: guest should be able to access endpoints protected by `Require valid-user`.
  - Guest should not be able to access endpoints restricted to `admin` or `admin uploader`.

## Files to change

### 1) `ansible/inventories/group_vars/<env>/<env>.yml` (example: `.../gighive2/gighive2.yml`)

Add the guest username variable near the other users:

- Add: `guest_user: guest`

### 2) `ansible/inventories/group_vars/<env>/secrets.yml`

Add the guest password secret only for the environments where guest should exist:

- Add: `gighive_guest_password: "..."`

If `gighive_guest_password` is missing or empty, guest must not be provisioned.

### 3) `ansible/roles/security_basic_auth/tasks/main.yml`

Add a new task to create/update the guest user in the host htpasswd file, mirroring the existing optional viewer/uploader tasks.

Required characteristics:

- Use the same htpasswd module and bcrypt scheme used for other users.
- Use:
  - name: `{{ guest_user }}`
  - password: `{{ gighive_guest_password }}`
- Gate it behind `when:` such that provisioning only happens when:
  - `guest_user` is defined
  - `gighive_guest_password` is defined
  - `gighive_guest_password | length > 0`

### 4) `ansible/roles/docker/templates/default-ssl.conf.j2`

No change is required to grant viewer-equivalent scope.

Rationale:

- Viewer-equivalent access is already expressed by `Require valid-user` in the protected sections.
- Guest will automatically be excluded from:
  - `Require user admin uploader`
  - `Require user admin`

Only change this file if you want guest to have less than viewer (i.e., different scope).

### 5) Optional: verification probes (if you want guest verified)

The role currently includes end-to-end HTTP verification probes for `admin`, `viewer`, and `uploader`.

Optionally extend verification with a guest probe that mirrors the viewer probe so that CI/provisioning output confirms guest can authenticate where expected.
