---
description: Quickstart install instructions (one-shot bundle)
---
# Quickstart install instructions

These are instructions to get up and running with Gighive for users with Docker already installed.

## Prerequisites

- Read the [PREREQS](PREREQS.md).

## Download and verify integrity of tarball

1. Download the file.
    ```bash
    curl -fL -O https://staging.gighive.app/downloads/gighive-one-shot-bundle.tgz
    ```
2. Download the SHA file.
    ```bash
    curl -fL -O https://staging.gighive.app/downloads/gighive-one-shot-bundle.tgz.sha256
    ```
3. Validate the bundle integrity against the SHA.
    ```bash
    sha256sum gighive-one-shot-bundle.tgz
    cat gighive-one-shot-bundle.tgz.sha256
    ```
4. Confirm the outputs match.

    The outputs should match, but they do not need to match the example SHA below because the bundles are subject to change.

    ```bash
    sodo@pop-os:~/gighive$ cat gighive-one-shot-bundle.tgz.sha256
    d7d4d03adf70f5c023000a8a884355e9442d01a20b57fc2a45a69f50b6537500  gighive-one-shot-bundle.tgz
    sodo@pop-os:~/gighive$ sha256sum gighive-one-shot-bundle.tgz
    d7d4d03adf70f5c023000a8a884355e9442d01a20b57fc2a45a69f50b6537500  gighive-one-shot-bundle.tgz
    ```
5. If verification matches, continue. If verification fails, do **not** continue. Re-download the bundle.

## Install GigHive

1. Expand the tarball.
    ```bash
    tar -xzf gighive-one-shot-bundle.tgz
    ```
2. Optional: disable installation telemetry before running the installer.

    GigHive sends the [**bare minimum of information for debugging purposes**](TELEMETRY_ENDUSER.md). If you do not want GigHive to send this minimal installation telemetry, edit `gighive-one-shot-bundle/install.sh` and set `GIGHIVE_ENABLE_INSTALLATION_TRACKING` to `false` before running `./install.sh`.
3. Run the installer to install Gighive.
    ```bash
    cd gighive-one-shot-bundle
    ./install.sh
    ```
4. During `./install.sh`, set BasicAuth passwords for the following users.

    These are written to `apache/externalConfigs/gighive.htpasswd`.

    - `admin`
    - `uploader`
    - `viewer`

5. Wait a minute or two for `mysqlServer` and `apacheWebServer` containers to spin up fully.

6. Validate the installation by performing the smoke tests below and accessing the URL in a browser.

## `install.sh` sample run

```text
ubuntu@gighive:~/gighive-one-shot-bundle$ ./install.sh
SITE_URL (example: https://192.168.1.230): https://192.168.1.252
BasicAuth password for user 'admin': 
BasicAuth password for user 'uploader': 
BasicAuth password for user 'viewer': 
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

## `docker compose ps` output
```text
NAME                   IMAGE                    COMMAND                  SERVICE           STATUS          PORTS
apacheWebServer        ubuntu-apache-img:1.00   "/entrypointapache.sh"   apacheWebServer   Up ...          0.0.0.0:443->443/tcp
apacheWebServer_tusd   tusproject/tusd:latest   "/usr/local/share/do…"   tusd              Up ...          8080/tcp
mysqlServer            mysql:8.4                "docker-entrypoint.s…"   mysqlServer       Up ...          0.0.0.0:3306->3306/tcp
```

## curl HTTP smoke tests 

1. Replace `192.168.1.252` with your host IP.
2. Run the smoke tests.
    ```bash
    curl -kI https://192.168.1.252/
    curl -kI https://192.168.1.252/db/database.php
    curl -kI https://viewer:secretviewer@192.168.1.252/db/database.php
    ```
3. Open the URL in your favorite browser.

Example: `https://192.168.1.252/`

4. Note that you will get a security warning because the certificate is self-signed.

## ⚙️ After Installation

1. Once installed, there will be a splash page, a link to the database, and links to the uploads and admin pages.
2. The default install populates the database with about 10 sample video and audio files.
3. These can be deleted later with the database reset procedure on the admin page (`admin.php`).
4. There are three users:
   - `viewer`: viewers can view media files, but cannot upload
   - `uploader`: uploaders can upload and view media files
   - `admin`: admin can view and upload files and change passwords
5. The admin utility (`admin.php`) also lets admins reset default passwords in the GUI.

# Troubleshooting
If you see `HTTP 500` after BasicAuth, check:

```bash
docker compose logs -n 200 apacheWebServer
docker compose exec apacheWebServer bash -lc 'tail -n 200 /var/log/apache2/error.log'
```

# Clean reinstall / full wipe (install host)

Use this when you want to restart from a known-clean state during testing.

## Commands (run in the extracted bundle directory)

```bash
cd ~/gighive-one-shot-bundle

# Stop and remove containers, networks, and named volumes
docker compose down --volumes --remove-orphans

# Removes unused containers, networks, dangling images, and build cache.
docker image rm ubuntu-apache-img:1.00
docker compose down -v --rmi local
docker system prune -f

# Optional: remove bundle-created host dirs (only if you are OK losing local contents)
rm -rf ./mysql/dbScripts/backups ./_host_audio ./_host_video

# Sanity checks
docker compose ps
docker ps | egrep 'apacheWebServer|mysqlServer|apacheWebServer_tusd' || true

# Remove gighive-one-shot-bundle directory
cd ..
sudo rm -rf gighive-one-shot-bundle
```

## All-in-one cleanup
    ```bash
    cd gighive-one-shot-bundle
    docker compose down --volumes --remove-orphans
    docker image rm ubuntu-apache-img:1.00
    docker compose down -v --rmi local
    docker system prune -f
    cd ..
    sudo rm -rf gighive-one-shot-bundle
    ```


