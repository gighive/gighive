---
description: Plan to hide/show admin_system.php Section D based on install channel
---

# Feature Plan: Section D Visibility Toggle by Install Channel

## Goal

Hide **Section D: Write Disk Resize Request (Optional)** in `admin_system.php` when the
install is a quickstart bundle, and show it only for full-build installs. Disk resize is a
VirtualBox/Ansible-backed operation that does not apply to bundle installs.

## Root Cause of the Current Gap

`apache/externalConfigs/.env` is pre-rendered by Ansible from `.env.j2` using group_vars that
all declare `gighive_install_channel: "full"`. The bundle ships with this pre-rendered `.env`,
so `GIGHIVE_INSTALL_CHANNEL=full` even on quickstart installs.

`install.sh` already has `_install_channel="quickstart"` as a hard-coded bash variable (the
authoritative declaration that this is a bundle install), but it is only used for telemetry
payloads today — it is never written into the runtime `.env`.

See `docs/process_one_shot_bundle_install_sh.md` for full background.

## Planned Changes (two files)

### Change 1 — `ansible/roles/docker/templates/install.sh.j2`

Add one `_patch_env_key` call in the telemetry variable block (after `$_install_channel` is
assigned), referencing it to avoid repeating the literal string `"quickstart"`:

```bash
_patch_env_key "GIGHIVE_INSTALL_CHANNEL" "$_install_channel" "$APACHE_ENV_FILE"
```

This propagates the single authoritative declaration of the install channel from `install.sh`
into the runtime `.env`, making `GIGHIVE_INSTALL_CHANNEL=quickstart` visible to PHP.

**Placement:** NOT with the other three patch calls — `$_install_channel` is not assigned until
later in the script. With `set -euo pipefail` active, referencing an unbound variable is a fatal
error. The call must go immediately after the `_install_channel` assignment in the telemetry
variable block:

```bash
_install_channel="quickstart"
_install_method="docker"
_app_flavor="{{ app_flavor | default('gighive') }}"
# ADD HERE:
_patch_env_key "GIGHIVE_INSTALL_CHANNEL" "$_install_channel" "$APACHE_ENV_FILE"
```

### Change 2 — `ansible/roles/docker/files/apache/webroot/admin/admin_system.php`

Read `GIGHIVE_INSTALL_CHANNEL` from the environment at the top of the file and conditionally
render Section D only when the channel is `full`. Use a positive allow-list (show only for
`full`; hide for anything else, including unexpected values):

```php
$__install_channel = getenv('GIGHIVE_INSTALL_CHANNEL') ?: 'full';
$__show_disk_resize = ($__install_channel === 'full');
```

Then wrap the Section D HTML block:

```php
<?php if ($__show_disk_resize): ?>
      <div class="section-divider">
        <h2>Section D: Write Disk Resize Request (Optional)</h2>
        ...
      </div>
<?php endif; ?>
```

## Why This Approach

- **Single source of truth** — `_install_channel="quickstart"` in `install.sh.j2` remains the
  one place where "this is a bundle install" is declared. The patch call propagates it; no
  separate variable or group_vars entry is needed.
- **Safe default** — if `GIGHIVE_INSTALL_CHANNEL` is absent or unexpected, the PHP fallback
  is `full` (Section D visible), so full-build installs are unaffected even if `.env` is missing
  the key.
- **No new config surface** — `GIGHIVE_INSTALL_CHANNEL` already exists in `.env.j2` and is
  already read into the PHP runtime env via phpdotenv / php-fpm `clear_env = no`.

## Status

Planned — awaiting approval before implementation.
