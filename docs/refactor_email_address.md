# Refactor Email Address Out of `webroot/index.php`

## Summary

Two flavor-specific `index.php` files each contain a hardcoded contact email link:

- `ansible/roles/docker/files/apache/webroot/index.php` (`app_flavor=defaultcodebase` / stormpigs) — `mailto:admin@stormpigs.com`
- `ansible/roles/docker/files/apache/overlays/gighive/index.php` (`app_flavor=gighive`) — `mailto:contactus@gighive.app`

Even though each file is flavor-specific, hardcoding the address makes it non-configurable and couples the code to a particular contact address that may change.

---

## Current State

- Each flavor's `index.php` renders the contact link inline.
- Neither address is configurable via Ansible or environment configuration.

---

## Why This Needs Refactoring

- Addresses are not configurable via Ansible or environment configuration.
- A flavor's contact address may change without requiring a code change.

---

## Planned Refactor (Future Work)

A future change should:

- Introduce a configurable variable `app_flavor_email` in Ansible group_vars, rendered into the container env via `.env.j2`. Binding it to `app_flavor` by name makes it clear they travel together.
- Update each flavor's `index.php` to read `APP_FLAVOR_EMAIL` from the environment, hiding the contact link if the value is empty.

### Files to change

- `ansible/inventories/group_vars/gighive2/gighive2.yml` — dev: add `app_flavor_email`
- `ansible/inventories/group_vars/gighive/gighive.yml` — lab/staging: add `app_flavor_email`
- `ansible/inventories/group_vars/prod/prod.yml` — prod: add `app_flavor_email`
- `ansible/roles/docker/templates/.env.j2` — render `APP_FLAVOR_EMAIL={{ app_flavor_email | default('') }}`
- `ansible/roles/docker/files/apache/webroot/index.php` — (`defaultcodebase`) replace hardcoded `mailto:admin@stormpigs.com` with `APP_FLAVOR_EMAIL` env lookup
- `ansible/roles/docker/files/apache/overlays/gighive/index.php` — (`gighive`) replace hardcoded `mailto:contactus@gighive.app` with `APP_FLAVOR_EMAIL` env lookup

This refactor is tracked separately from the GA4 feature work.
