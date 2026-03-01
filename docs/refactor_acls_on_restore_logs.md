# Refactor: lock down restore logs permissions (ACLs)

## Context
The one-shot bundle binds a host directory into the Apache/PHP container for restore job logging:

- Host: `./apache/externalConfigs/restorelogs`
- Container: `/var/www/private/restorelogs`

The restore endpoint (`apache/webroot/db/restore_database.php`) requires that the directory exists and is writable by the PHP-FPM worker user (`www-data`).

In typical deployments the bundle is extracted by a host user (e.g. `ubuntu`, UID 1000). The bind-mounted directory then appears inside the container owned by the host UID/GID (e.g. `1000:1000`) and may not be writable by `www-data` (typically UID/GID `33:33`).

## Current approach (temporary)
For now we are not relying on ACLs.

This means operators may use a permissive directory mode (or other host-side permission workaround) to ensure `www-data` can write restore logs.

## Desired end state
Make restore logging writable by `www-data` without resorting to overly permissive modes.

Preferred options (in order):

1. Preserve and apply POSIX ACLs on `restorelogs` so `www-data` can write.
2. Alternatively, adjust ownership/group strategy in a predictable way (e.g., group mapping) while keeping permissions tight.
3. If needed, move restore logs to a Docker named volume with controlled ownership initialization.

## Proposed ACL-based solution
On the host (bundle directory), grant write access to UID 33 (`www-data`) and ensure new files inherit it:

- Set ACL:
  - `setfacl -m u:33:rwx ./apache/externalConfigs/restorelogs`
- Set default ACL:
  - `setfacl -d -m u:33:rwx ./apache/externalConfigs/restorelogs`

If distributing as a tarball, ensure ACLs are preserved during packaging and extraction:

- Create tarball with ACLs:
  - `tar --acls -czf gighive-one-shot-bundle.tgz gighive-one-shot-bundle`
- Extract with ACLs:
  - `tar --acls -xzf gighive-one-shot-bundle.tgz`

## Verification
Inside the running apache container:

- Check perms:
  - `ls -ld /var/www/private/restorelogs`
- Check writability as `www-data`:
  - `su -s /bin/bash -c "test -w /var/www/private/restorelogs && echo WRITABLE || echo NOT_WRITABLE" www-data`

## Notes
- Some hosts may not have `setfacl` installed by default (package `acl` on Ubuntu).
- This doc is a reminder to revisit and harden permissions once we decide on a consistent packaging/deployment expectation.
