---
description: Staging-only /downloads hosting for one-shot installer tarball
---

# Goal

Host `gighive-one-shot-bundle.tgz` from the staging GigHive instance (`gighive.gighive.internal`, tunneled as `staging.gighive.app`) while ensuring the tarball is **not** deployed to other GigHive hosts.

# Rationale

## Why this should not be implemented via Apache overlay

GigHive’s Apache image build uses an overlay mechanism:

- The canonical app code is copied into `${WEB_ROOT}`.
- Then `overlays/gighive/` is copied into the container image and applied at build time when `APP_FLAVOR=gighive`.

This means any file placed under:

- `ansible/roles/docker/files/apache/overlays/gighive/...`

will be baked into the Apache image and therefore be deployed to **every** host that builds/pulls that image flavor.

Because the installer tarball is intended to be hosted **only** on the staging host, placing it in the overlay directory is the wrong approach.

## Why a host bind mount is the correct solution

A bind mount lets us:

- Keep the tarball as **host runtime content** (not image content).
- Copy the tarball to **only one host** using a host guard.
- Expose it in Apache at runtime by mounting a host directory into the container at a known URL path.
- Update the tarball without rebuilding the Apache image.

## Why `{{ docker_dir }}/apache/downloads` is the right host location

The project already uses `{{ docker_dir }}` as the host-side “deployment root” for docker bind mounts (e.g. `{{ docker_dir }}/apache/externalConfigs`). Placing staging-only hosted artifacts alongside these bind-mounted paths keeps deployment state consistent and discoverable.

Target host directory:

- `{{ docker_dir }}/apache/downloads/`

Container directory:

- `/var/www/html/downloads/`

Public URL:

- `https://gighive.gighive.internal/downloads/gighive-one-shot-bundle.tgz`

# Design constraints

- The tarball must be present **only** on the staging host.
- The tarball must be accessible via Apache as a static file.
- The solution must avoid baking the tarball into container images.
- The change must be minimal and consistent with existing Ansible patterns.

# Implementation plan (no compatibility layer)

## A) Create and populate the downloads directory (staging only)

Add tasks to `ansible/roles/docker/tasks/main.yml` (preferred, because this is docker runtime state) to:

1. Ensure the host directory exists:

- `{{ docker_dir }}/apache/downloads`

2. Copy the tarball from the controller repo to the staging host:

- Source (controller):
  - `ansible/roles/docker/files/apache/overlays/downloads/gighive-one-shot-bundle.tgz`

- Destination (staging host):
  - `{{ docker_dir }}/apache/downloads/gighive-one-shot-bundle.tgz`

3. Guard both tasks with:

- `when: gighive_fqdn == 'gighive.gighive.internal'`

Notes:

- File mode should be `0644`.
- Owner/group can be `root:root` (or `{{ ansible_user }}`), but readability is what matters.

## B) Add a staging-only bind mount in docker-compose template

Edit `ansible/roles/docker/templates/docker-compose.yml.j2` under the apache service `volumes:` list.

Add a conditional volume mount:

- Host: `{{ docker_dir }}/apache/downloads`
- Container: `/var/www/html/downloads`
- Mode: `:ro`

Guard this mount with the same host condition:

- `gighive_fqdn == 'gighive.gighive.internal'`

## C) Deploy / verify

1. Run your normal Ansible deploy for staging.
2. Confirm file presence on staging host:

- `ls -l {{ docker_dir }}/apache/downloads/`

3. Confirm the tarball is served:

- `curl -fI https://gighive.gighive.internal/downloads/gighive-one-shot-bundle.tgz`

Expected:

- `HTTP/2 200`
- `accept-ranges: bytes`
- `content-type: application/x-gzip` (or similar)

# Security / operational notes

- If the staging endpoint is truly public (`staging.gighive.app`), ensure it uses a publicly trusted TLS certificate so users do not need `curl -k`.
- Consider also publishing a `sha256` checksum file next to the tarball.
- Ensure Apache authentication rules do not protect `/downloads/` (do not include it in any protected paths).

# Rollback plan

- Remove the two Ansible tasks and the conditional bind mount.
- Redeploy.
- Remove `{{ docker_dir }}/apache/downloads` from staging host if desired.
