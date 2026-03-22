# Quickstart user password reset

This procedure resets the Apache BasicAuth user passwords for the quickstart bundle.

It applies to these users:

- `admin`
- `uploader`
- `viewer`

The quickstart bundle includes a helper script named `rotate_basic_auth.sh` that rebuilds `apache/externalConfigs/gighive.htpasswd` using the same password-generation logic as `install.sh`.

## Requirements

1. Run the commands from the extracted `gighive-one-shot-bundle` directory.
2. Have Docker and Docker Compose available.
3. Be ready to enter your `sudo` password when the script needs to restart `apacheWebServer`.

## Reset user passwords

1. Change into the extracted bundle directory.

```bash
cd ~/gighive-one-shot-bundle
```

2. Run the password reset script.

```bash
bash ./rotate_basic_auth.sh
```

3. When prompted, enter and confirm new passwords for:

- `admin`
- `uploader`
- `viewer`

4. The script then:

- rebuilds `apache/externalConfigs/gighive.htpasswd`
- sets file ownership to `www-data:www-data`
- sets file mode to `0640`
- prompts for your `sudo` password
- restarts `apacheWebServer` so the new passwords take effect

## Verify the change

After the script completes, you can verify the Apache restart and then test the new credentials in a browser.

```bash
docker compose logs -n 50 apacheWebServer
```

You can also test a protected page directly.

```bash
curl -kI https://YOUR-HOST-IP/db/database.php
```
