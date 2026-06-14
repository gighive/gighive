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

- A flavor's contact address should be updatable without requiring a code change.

---

## Planned Refactor (Future Work)

A future change should:

- Add `app_flavor_email` to all three group_vars files (`gighive2/gighive2.yml`, `gighive/gighive.yml`, `prod/prod.yml`), placed on the line immediately after `app_flavor` in each file. Binding it to `app_flavor` by name and position makes it clear they travel together. Render it into the container env via `.env.j2`.
- Update each flavor's `index.php` to read `APP_FLAVOR_EMAIL` from the environment via `getenv()`. Because `clear_env = no` is set in the php-fpm pool config, the container env var is available directly without loading phpdotenv.
- Wrap the contact section in each file with a PHP conditional so it is **entirely hidden** when `APP_FLAVOR_EMAIL` is empty (see scope notes below). Both files currently embed the mailto link as raw HTML, so PHP expression blocks must be added — it is not a simple string substitution.
- Use `htmlspecialchars()` when printing the env var value into the `href` attribute.

### "Hide if empty" scope per file

- **`webroot/index.php` (defaultcodebase)**: The contact link sits inside `<div id="contact-us">`, which also contains the "Powered by GigHive" block. Only the contact `<font>` element (the `CONTACT US` link) should be conditionally suppressed — the surrounding flex div and the "Powered by GigHive" element should remain visible regardless.
- **`overlays/gighive/index.php` (gighive)**: The contact link is inside a `<p>` preceded by a `<h3>Contact Us</h3>` heading. Both the heading and the paragraph must be conditionally suppressed together, otherwise the heading is left orphaned on the page when the email is empty.

### Files to change

- `ansible/inventories/group_vars/gighive2/gighive2.yml` — dev: add `app_flavor_email: "contactus@gighive.app"` immediately after `app_flavor: gighive`
- `ansible/inventories/group_vars/gighive/gighive.yml` — lab/staging: add `app_flavor_email: "contactus@gighive.app"` immediately after `app_flavor: gighive`
- `ansible/inventories/group_vars/prod/prod.yml` — prod: add `app_flavor_email: "admin@stormpigs.com"` immediately after `app_flavor: defaultcodebase`
- `ansible/roles/docker/templates/.env.j2` — render `APP_FLAVOR_EMAIL={{ app_flavor_email | default('') }}`
- `ansible/roles/docker/files/apache/webroot/index.php` — (`defaultcodebase`) wrap the `CONTACT US` `<font>` element in a PHP conditional that reads `APP_FLAVOR_EMAIL` via `getenv()` and outputs the address with `htmlspecialchars()`
- `ansible/roles/docker/files/apache/overlays/gighive/index.php` — (`gighive`) wrap the `<h3>Contact Us</h3>` heading and its `<p>` together in a PHP conditional that reads `APP_FLAVOR_EMAIL` via `getenv()` and outputs the address with `htmlspecialchars()`

### Impacted files summary

1. `ansible/inventories/group_vars/gighive2/gighive2.yml` — add one new var `app_flavor_email`
2. `ansible/inventories/group_vars/gighive/gighive.yml` — add one new var `app_flavor_email`
3. `ansible/inventories/group_vars/prod/prod.yml` — add one new var `app_flavor_email`
4. `ansible/roles/docker/templates/.env.j2` — add one new env var line rendering `APP_FLAVOR_EMAIL`
5. `ansible/roles/docker/files/apache/webroot/index.php` — add PHP conditional around the `CONTACT US` link
6. `ansible/roles/docker/files/apache/overlays/gighive/index.php` — add PHP conditional around the `Contact Us` heading and paragraph

This refactor is tracked separately from the GA4 feature work.
