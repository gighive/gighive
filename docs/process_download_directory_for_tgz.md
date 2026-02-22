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

# End-user install instructions (updated best practice)

```bash
curl -fL -C - -o gighive-one-shot-bundle.tgz \
  https://staging.gighive.app/downloads/gighive-one-shot-bundle.tgz

# optional integrity check (if you publish the sha file)
curl -fL -o gighive-one-shot-bundle.tgz.sha256 \
  https://staging.gighive.app/downloads/gighive-one-shot-bundle.tgz.sha256
sha256sum -c gighive-one-shot-bundle.tgz.sha256

tar -xzf gighive-one-shot-bundle.tgz
cd gighive-one-shot-bundle
# THe installer will ask you to confirm an IP address.  This will be the ip address of the docker host on which you will run the containers.
./install.sh
docker compose ps
```

# Sample debugging output (successful install)

Use this section as a reference for what a successful install commonly looks like.

## `install.sh` run (example)

```text
ubuntu@gighive:~/gighive-one-shot-bundle$ ./install.sh
SITE_URL (example: https://192.168.1.230): https://192.168.1.252
MYSQL_PASSWORD:
MYSQL_ROOT_PASSWORD:
Wrote:
  - apache/externalConfigs/.env
  - mysql/externalConfigs/.env.mysql
Bringing stack up...
[+] Building ... FINISHED
[+] up ...
 ✔ Network gighive-one-shot-bundle_default   Created
 ✔ Volume gighive-one-shot-bundle_mysql_data Created
 ✔ Volume gighive-one-shot-bundle_tusd_data  Created
 ✔ Volume gighive-one-shot-bundle_tus_hooks  Created
 ✔ Container apacheWebServer                 Created
 ✔ Container mysqlServer                     Created
 ✔ Container apacheWebServer_tusd            Created
Done.
Next checks:
  - docker compose ps
  - docker compose logs -n 200 mysqlServer
  - docker compose logs -n 200 apacheWebServer
```

## Expected `docker compose ps` (example)

```text
NAME                   IMAGE                    COMMAND                  SERVICE           STATUS          PORTS
apacheWebServer        ubuntu-apache-img:1.00   "/entrypointapache.sh"   apacheWebServer   Up ...          0.0.0.0:443->443/tcp
apacheWebServer_tusd   tusproject/tusd:latest   "/usr/local/share/do…"   tusd              Up ...          8080/tcp
mysqlServer            mysql:8.4                "docker-entrypoint.s…"   mysqlServer       Up ...          0.0.0.0:3306->3306/tcp
```

## Quick HTTP smoke tests (example)

```bash
curl -kI https://192.168.1.252/
curl -kI https://192.168.1.252/db/database.php
curl -kI https://viewer:secretviewer@192.168.1.252/db/database.php
```

If you see `HTTP 500` after BasicAuth, check:

```bash
docker compose logs -n 200 apacheWebServer
docker compose exec apacheWebServer bash -lc 'tail -n 200 /var/log/apache2/error.log'
```

# Clean reinstall / full wipe (install host)

Use this when you want to restart from a known-clean state during testing.

## Rationale

- The first run creates named volumes (MySQL data, tusd data/hooks) and may also build a local apache image.
- If you change init SQL/CSV inputs, credentials, or want to verify the installer from scratch, you should remove containers and volumes so MySQL re-initializes cleanly.

## Commands (run in the extracted bundle directory)

```bash
cd ~/gighive-one-shot-bundle

# Stop and remove containers, networks, and named volumes
docker compose down --volumes --remove-orphans

# Optional: remove the locally built apache image so the next install rebuilds from scratch
docker image rm ubuntu-apache-img:1.00

# Optional: remove bundle-created host dirs (only if you are OK losing local contents)
rm -rf ./mysql_backups ./_host_audio ./_host_video

# Sanity checks
docker compose ps
docker ps | egrep 'apacheWebServer|mysqlServer|apacheWebServer_tusd' || true
```
