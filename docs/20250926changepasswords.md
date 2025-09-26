# 2025-09-26: Change Passwords Page Updated to Include Uploader

This note documents the update to `ansible/roles/docker/files/apache/overlays/gighive/changethepasswords.php` to add the `uploader` user alongside existing `admin` and `viewer` accounts.

## What Changed

- **Page scope**
  - Updated header comment to reflect that the page now manages `admin`, `viewer`, and `uploader` passwords in the Apache `.htpasswd`.

- **New POST fields**
  - `uploader_password`
  - `uploader_password_confirm`

- **Validation**
  - Reuses the existing `validate_password()` function.
  - Applies the same checks used for `admin` and `viewer`:
    - Must match confirm value
    - Minimum length 8

- **Htpasswd map handling**
  - Ensures `uploader` key is present (like `admin` and `viewer`).
  - Hashes with `password_hash(..., PASSWORD_BCRYPT)`.
  - Writes via `write_htpasswd_atomic()` (same backup + atomic swap logic).

- **UI**
  - Added a third form section labeled “Uploader” with two password inputs and `required minlength="8"` attributes, matching the other two sections.

- **Access Gate (unchanged)**
  - The page remains protected and only the `admin` Basic Auth user may access it (top-of-file gate checks `PHP_AUTH_USER`/`REMOTE_USER` fallbacks).

- **Redirect (unchanged)**
  - On success, a 302 redirect to `/db/database.php` is issued.

## File(s) Touched

- `ansible/roles/docker/files/apache/overlays/gighive/changethepasswords.php`

## Operational Notes

- The `.htpasswd` path is read from `GIGHIVE_HTPASSWD_PATH` or defaults to `/var/www/private/gighive.htpasswd`.
- The script creates a timestamped backup before writing changes.
- Works with bind-mounted files by falling back to in-place writes when atomic rename is not possible.

## Follow-ups (Optional)

- If you want the page to allow updating only selected users (instead of requiring all three), remove the `required` attributes and add conditional map updates based on non-empty inputs.
- Consider updating the post-success redirect to `/db/database.php#all` for anchor consistency with the database page.
