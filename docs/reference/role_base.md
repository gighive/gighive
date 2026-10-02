# base role (`ansible/roles/base`)

## Summary

The `base` role prepares the target VM/host for running GigHive (Docker, filesystem layout, time sync, DNS, etc.). It does **not** manage Docker containers directly.

## Task breakdown (host vs container)

| Task name (as in role) | What it does | Target |
|---|---|---|
| `Set the system hostname` | Sets the host hostname to `vm_name`. | Host |
| `Check for required per-VM secrets file on controller` | Verifies `group_vars/<vm_name>/secrets.yml` exists. | Controller (localhost) |
| `Fail if per-VM secrets.yml is missing` | Stops the run if secrets file is missing. | Host (control flow) |
| `Remove any legacy Docker apt source file` | Deletes `/etc/apt/sources.list.d/docker.list` if present. | Host |
| `Install systemd-timesyncd for time synchronization` | Installs time sync package (best-effort). | Host |
| `Enable and start systemd-timesyncd` | Enables/starts time sync service. | Host |
| `Force immediate time synchronization` | Runs `timedatectl set-ntp true`. | Host |
| `Restart systemd-timesyncd to force immediate sync with large offset` | Restarts time sync service. | Host |
| `Wait for time synchronization to complete` | Pauses 10 seconds. | Host |
| `Wait for time synchronization (up to 30 seconds)` | Polls `timedatectl timesync-status`. | Host |
| `Display current system time` | Runs `date` and captures output. | Host |
| `Show system time` | Prints the captured time. | Host |
| `Update APT cache` | Runs apt cache update. | Host |
| `Ensure Ansible remote_tmp directory exists with safe permissions` | Ensures `/tmp/.ansible/tmp` exists with mode `1777`. | Host |
| `Fetch Docker’s GPG key into a keyring` | Downloads Docker apt GPG key to `/etc/apt/keyrings/docker.asc`. | Host |
| `Add Docker’s APT repository (multi-arch)` | Adds Docker apt repository for `amd64,arm64`. | Host |
| `Upgrade all packages to the latest available versions` | Runs `apt` with `upgrade: dist`. | Host |
| `Install required build-and-runtime dependencies (incl. Docker)` | Installs common packages (docker, tooling, mysql-client, etc.). | Host |
| `Install Docker Engine & Compose V2 plugin (distro packages)` | Installs `docker_packages`. | Host |
| `Verify "docker compose" is callable` | Runs `docker compose version`. | Host |
| `Fail early if Compose V2 CLI is missing` | Fails if Compose V2 plugin isn’t working. | Host (control flow) |
| `Replace ~/.bashrc with managed aliases` | Replaces user `.bashrc` with aliases/paths used by GigHive. | Host |
| `Install and refresh CA certificates` | Installs `ca-certificates` and runs `update-ca-certificates`. | Host |
| `Add ansible_user user to sudoers` | Creates `/etc/sudoers.d/<ansible_user>` with NOPASSWD. | Host |
| `Validate sudoers configuration` | Runs `visudo -c`. | Host |
| `Show visudo validation output` | Prints `visudo` output. | Host |
| `Ensure correct ownership & permissions for web_root & video_dir` | Creates/sets perms on `web_root`, `video_dir`, and `video_dir/podcasts`. | Host |
| `Ensure scripts_dir directory exists` | Creates `scripts_dir` (destination for repo sync). | Host |
| `Ensure scripts_dir is owned by ubuntu` | Recursively sets ownership of `scripts_dir`. | Host |
| `Sync scripts to the VM (excluding cloud-init)` | Rsync/synchronize repo contents to `scripts_dir` with excludes. | Controller -> Host (rsync) |
| `Ensure prepped_csvs/full is owned by {{ ansible_user }}` | Ownership fix under `configs_dir/prepped_csvs/full`. | Host |
| `Ensure prepped_csvs/sample is owned by {{ ansible_user }}` | Ownership fix under `configs_dir/prepped_csvs/sample`. | Host |
| `Ensure ansible_user is in www-data group` | Adds `ansible_user` to `www-data`. | Host |
| `Ensure resize request directory exists and is writable by Apache` | Creates host directory for resize requests (bind-mounted into container). | Host |
| `Ensure media bind mount dirs are writable by www-data group` | Creates/sets perms on `/home/<user>/audio` and `/home/<user>/video`. | Host |
| `Ensure video_dir exists with proper ownership by {{ ansible_user }}` | Ensures `video_dir` exists and is owned by `ansible_user`. | Host |
| `Ensure video directory on web_root exists and is writable by {{ ansible_user }}` | Ensures `web_root/video` exists and is writable. | Host |
| `Sync full video directory` | Rsync full video set to `video_dir` (optional). | Controller -> Host (rsync) |
| `Sync reduced video directory (development subset)` | Rsync reduced video set to `video_dir` (optional). | Controller -> Host (rsync) |
| `Ensure audio_dir exists with proper ownership by {{ ansible_user }}` | Ensures `audio_dir` exists and is owned by `ansible_user`. | Host |
| `Ensure audio directory on web_root exists and is writable by {{ ansible_user }}` | Ensures `web_root/audio` exists and is writable. | Host |
| `Sync full audio directory` | Rsync full audio set to `audio_dir` (optional). | Controller -> Host (rsync) |
| `Sync reduced audio directory` | Rsync reduced audio set to `audio_dir` (optional). | Controller -> Host (rsync) |
| `Ensure audio directory exists and is writable by Apache` | Ownership/perms on `web_root/audio` for Apache group. | Host |
| `Recursively fix directory permissions under {{ web_root }}` | `find ... chmod 755` for directories under `web_root`. | Host |
| `Recursively fix file permissions under {{ web_root }}` | `find ... chmod 644` for files under `web_root`. | Host |
| `Recursively fix directory permissions under {{ video_dir }}` | `find ... chmod 755` for directories under `video_dir`. | Host |
| `Recursively fix file permissions under {{ video_dir }}` | `find ... chmod 644` for files under `video_dir`. | Host |
| `Recursively fix directory permissions under {{ audio_dir }}` | `find ... chmod 755` for directories under `audio_dir`. | Host |
| `Recursively fix file permissions under {{ audio_dir }}` | `find ... chmod 644` for files under `audio_dir`. | Host |
| `Ensure audio_dir and contents have proper ownership` | Recursively sets owner/group under `audio_dir` to `apache_group`. | Host |
| `Ensure video_dir and contents have proper ownership` | Recursively sets owner/group under `video_dir` to `apache_group`. | Host |
| `Ensure ansible_user is in apache_group (needed for thumbnail creation via SSH)` | Adds `ansible_user` to `apache_group`. | Host |
| `Ensure video thumbnails directory exists and is group-writable` | Creates/sets perms on `video_dir/thumbnails`. | Host |
| `Ensure tzdata is installed` | Installs `tzdata`. | Host |
| `Verify zoneinfo file exists for configured timezone` | Validates `/usr/share/zoneinfo/<tz>` exists. | Host |
| `Fail if configured timezone is not available on the host` | Fails if timezone is missing. | Host (control flow) |
| `Write /etc/timezone` | Writes `/etc/timezone`. | Host |
| `Set /etc/localtime symlink to configured timezone` | Updates `/etc/localtime` symlink. | Host |
| `Ensure the host’s own hostname is in /etc/hosts` | Ensures `127.0.1.1 <hostname>` line is present. | Host |
| `Stop and disable systemd-resolved` | Disables `systemd-resolved`. | Host |
| `Remove any existing /etc/resolv.conf (symlink or file)` | Removes `/etc/resolv.conf`. | Host |
| `Create custom /etc/resolv.conf` | Writes a static `/etc/resolv.conf`. | Host |
| `Restart networking service so the new resolv.conf is used` | Restarts `systemd-networkd`. | Host |

## Notes

- The role creates/permissions host directories that are later bind-mounted into containers (e.g. media directories, resize request path). Those tasks still run on the **host**.

## Recommendation

- Consider making `Upgrade all packages to the latest available versions` (`upgrade: dist`) **optional / gated by a variable** (or moved into a separate maintenance play). This reduces the chance a host-specific package failure blocks provisioning when the host is primarily meant to run containers.
