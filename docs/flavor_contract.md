# Flavor Contract (web UI)

This document defines the current “flavor” mechanism for the Apache/PHP web UI.

## Scope

- The base docroot is:
  - `ansible/roles/docker/files/apache/webroot/`
- GigHive flavor overrides/additions live in:
  - `ansible/roles/docker/files/apache/overlays/gighive/`
- `app_flavor` selects whether the GigHive overlay is applied on top of the base docroot.

## Definitions

- **Base**: the default code shipped in `webroot/`.
- **Overlay**: files copied on top of the base with the same relative path taking precedence.
- **Override**: an overlay file that also exists in the base at the same relative path.
- **New endpoint**: a top-level request entrypoint added by the overlay (e.g., `/*.php`).
- **Branding asset**: images/icons added by the overlay.

## Hard rules / invariants

- **Path precedence**: if an overlay file exists at `X`, it replaces `webroot/X`.
- **No deletion semantics**: overlays can replace/add, but do not naturally remove base files.
- **Relative paths are the contract**: overrides are defined strictly by identical relative paths.
- **Runtime dependencies remain in the base unless explicitly overlaid**.

## Current GigHive overlay inventory

Total files in `overlays/gighive/`: **18**

### Overrides (3)

These files exist in both overlay and base. The overlay version is the flavor-specific implementation.

- `index.php`
- `src/Controllers/MediaController.php`
- `src/Views/media/list.php`

### New endpoints (2)

These files exist only in the overlay. They are additional HTTP entrypoints.

- `admin.php`
  - Admin-only page for changing Basic Auth passwords and triggering sample-data removal.
  - Uses `GIGHIVE_HTPASSWD_PATH` (default: `/var/www/private/gighive.htpasswd`).
- `clear_media.php`
  - Admin-only JSON endpoint that truncates media tables.
  - Requires Composer autoload at runtime: `vendor/autoload.php`.

### Branding assets (13)

These files exist only in the overlay and provide GigHive visuals/favicons.

- `images/beelogo.png`
- `images/beelogoNotTransparent.png`
- `images/databaseErd.png`
- `images/uploadutility.png`
- `images/icons/apple-touch-icon.png`
- `images/icons/favicon.ico`
- `images/icons/favicon-16.png`
- `images/icons/favicon-32.png`
- `images/icons/favicon-48.png`
- `images/icons/favicon-64.png`
- `images/icons/favicon-128.png`
- `images/icons/favicon-192.png`
- `images/icons/favicon-256.png`

## Notes about stormpigs-specific functionality

- The base (`webroot/`) contains stormpigs-specific pages/assets (e.g., timeline/homepage-related code and images).
- When `app_flavor=gighive`, only the **explicit overrides** above change stormpigs behavior; other stormpigs pages/assets may still be present in the deployed docroot unless separately gated/hidden.

## Operational assumptions

- The deployed docroot includes Composer vendor files (or otherwise ensures `vendor/autoload.php` exists) because GigHive endpoints and controllers rely on namespaced classes (e.g., `Production\\Api\\...`).
- The web server user must have appropriate permissions for:
  - Updating the `.htpasswd` file targeted by `GIGHIVE_HTPASSWD_PATH`.
  - Performing database operations used by `clear_media.php`.

## Implications for a future “reverse overlay” refactor

- Today, GigHive is a **small delta** from the base in file-count terms:
  - **3 overrides**, **2 new endpoints**, **13 branding assets**.
- Flipping the model (GigHive as base, stormpigs as overlay) is feasible, but stormpigs would likely need to overlay:
  - the corresponding stormpigs variants of the overridden files,
  - and stormpigs branding/timeline assets if GigHive branding becomes the base default.
