---
description: Quickstart install instructions (one-shot bundle)
---

# Quickstart install instructions (for users with docker already installed)
## Download and verify integrity of tarball

Download the file:

``` bash
curl -fL -O https://staging.gighive.app/downloads/gighive-one-shot-bundle.tgz
```
Create the checksum file:

``` bash
sha256sum gighive-one-shot-bundle.tgz > gighive-one-shot-bundle.tgz.sha256
```

Verify the bundle integrity:

``` bash
sha256sum -c gighive-one-shot-bundle.tgz.sha256
```

Expected output:
  If verification fails, do **not** continue. Re-download the bundle.
``` text
gighive-one-shot-bundle.tgz: OK
```

## Install the tarball and verify containers are running

Expand the tarball

```bash
tar -xzf gighive-one-shot-bundle.tgz
```

Run the installer to install gighive
```bash
cd gighive-one-shot-bundle
./install.sh
```

During `./install.sh`, you will be prompted to set BasicAuth passwords for the following users (written to `apache/externalConfigs/gighive.htpasswd`)

- `admin`
- `uploader`
- `viewer`

Validate installation by performing the smoke tests below and accessing the URL in a browser.

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

Replace `192.168.1.252` with your host IP.

```bash
curl -kI https://192.168.1.252/
curl -kI https://192.168.1.252/db/database.php
curl -kI https://viewer:secretviewer@192.168.1.252/db/database.php
```
# Open the URL in your favorite browser; 
Example: `https://192.168.1.252/`
  Note you will get a security warning because the certificate is self-signed.

## ⚙️ After Installation
- Once installed, there will be a splash page, a link to the database and a link to the uploads and admin pages. Simple! 
- Default install will populate the database with ~10 sample video and audio files. These can be deleted later with <a href="">database reset procedure</a>.
- There are three users: 
  * viewer: Viewers can view media files, but can't upload. 
  * uploader: Uploaders can upload and view media files. 
  * admin: Admin can view and upload files and change passwords.
- Admin utility (admin.php): a page for the admins to reset default password in GUI as well.

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

# Optional: remove the locally built apache image so the next install rebuilds from scratch
docker image rm ubuntu-apache-img:1.00

# Optional: remove bundle-created host dirs (only if you are OK losing local contents)
rm -rf ./mysql_backups ./_host_audio ./_host_video

# Sanity checks
docker compose ps
docker ps | egrep 'apacheWebServer|mysqlServer|apacheWebServer_tusd' || true
```

## To rotate BasicAuth passwords later
  Regenerate `apache/externalConfigs/gighive.htpasswd` and restart the apache container:

```bash
docker compose restart apacheWebServer
```
