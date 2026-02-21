# Refactor Email Address Out of `webroot/index.php`

## Summary

The file `ansible/roles/docker/files/apache/webroot/index.php` currently contains a hardcoded contact email link:

- `mailto:admin@stormpigs.com`

Because this codebase is now a shared platform (multi-tenant / multi-flavor), this email address should not be hardcoded in a shared UI page.

---

## Current State

- `index.php` includes `header.php` and then renders the homepage content.
- The contact link is rendered inline in `index.php`.

---

## Why This Needs Refactoring

- The address `admin@stormpigs.com` is tenant-specific / historical.
- Hardcoding it makes it easy to accidentally deploy the wrong contact info for a given `APP_FLAVOR`.
- It is not configurable via Ansible or environment configuration.

---

## Planned Refactor (Future Work)

A future change should:

- Introduce a configurable variable for the contact email (via Ansible group_vars -> rendered into container env, or via a PHP config include).
- Update `index.php` to use that configurable value.
- Provide a safe default for `defaultcodebase` and a blank/hidden contact link for `gighive` unless configured.

This refactor is tracked separately from the GA4 feature work.
