# Problem: SSH login rejected after OpenSSH security update — /home/ubuntu was group-writable

**Date:** 2026-04-26  
**System:** Lab VM (`ubuntu` user, Ubuntu 22.04, OpenSSL 3.0.13)  
**Symptom:** SSH logins to the labvm stopped working after a security update run

---

## 1. Problem Summary

After a routine `apt-get upgrade` on the labvm, SSH logins for the `ubuntu` user were
rejected by `sshd`. The fix was:

```bash
chmod 755 /home/ubuntu
```

---

## 2. Root Cause

### StrictModes and group-writable home directories

OpenSSH's `sshd` has a setting called `StrictModes` (in `/etc/ssh/sshd_config`). When
`StrictModes yes` is in effect (it is the compiled-in default — Ubuntu ships it enabled),
`sshd` performs ownership and permission checks on the authenticating user's home
directory before completing login. If `$HOME` is group-writable (mode `775`, `770`, etc.),
`sshd` refuses the connection with a message such as:

```
Authentication refused: bad ownership or modes for directory /home/ubuntu
```

### Why the update triggered the failure

The labvm had `/home/ubuntu` at mode `775` (group-writable). This had been silently
tolerated until the `openssh-server` package received a security update. That update
rewrote or refreshed `/etc/ssh/sshd_config`, restoring the default `StrictModes yes`
if it had previously been absent, commented out, or set to `no`. Once `sshd` reloaded
with `StrictModes yes` enforced, the group-writable home directory immediately caused
all SSH logins to fail.

### OpenSSL vs OpenSSH — attribution note

`StrictModes` is an **OpenSSH** feature, not an **OpenSSL** feature. OpenSSL (the
cryptographic library, version `3.0.13 30 Jan 2024` on Ubuntu 22.04) handles TLS/SSL
cipher operations and has no involvement in filesystem permission checks or SSH login
policy. The confusion is understandable because both packages often receive security
updates in the same `apt upgrade` run. The actual trigger was the `openssh-server`
package upgrade, not the `openssl` package.

---

## 3. Fix

```bash
chmod 755 /home/ubuntu
```

This removes the group-write bit from the home directory. `sshd` with `StrictModes yes`
requires that `$HOME` is not group- or world-writable (`755` or stricter is acceptable).

Verification:

```bash
ls -ld /home/ubuntu
# Expected: drwxr-xr-x 1 ubuntu ubuntu ... /home/ubuntu
sudo sshd -T | grep -i strictmodes
# Expected: strictmodes yes
```

---

## 4. Where did mode 775 come from?

### Ansible does NOT set /home/ubuntu to 775

The Ansible playbooks were audited and **no task anywhere sets `/home/ubuntu` itself to
`775` or any group-writable mode**. The relevant variable chain is:

```
site.yml pre_tasks:
  root_dir  = /home/ubuntu        (for ansible_user = ubuntu)
  video_dir = /home/ubuntu/video
  audio_dir = /home/ubuntu/audio
  web_root  = /home/ubuntu/gighive/ansible/roles/docker/files/apache/webroot
  gighive_home = /home/ubuntu/gighive
```

Tasks in `ansible/roles/base/tasks/main.yml` that set permissions on directories
**inside** `/home/ubuntu` (but not on `/home/ubuntu` itself):

| Task | Path | Mode |
|------|------|------|
| "Ensure correct ownership & permissions for web_root & video_dir" | `{{ video_dir }}` (`/home/ubuntu/video`) | `0775` |
| "Ensure correct ownership & permissions for web_root & video_dir" | `{{ web_root }}` | `0775` |
| "Ensure media bind mount dirs are writable by www-data group" | `/home/ubuntu/audio` | `2775` |
| "Ensure media bind mount dirs are writable by www-data group" | `/home/ubuntu/video` | `2775` |
| "Ensure scripts_dir directory exists" | `{{ gighive_home }}` (`/home/ubuntu/gighive`) | `0755` |
| "Ensure scripts_dir is owned by ubuntu" | `{{ gighive_home }}` (`/home/ubuntu/gighive`) | `0755` |
| "Ensure video_dir exists with proper ownership" | `/home/ubuntu/video` | `0775` |
| "Ensure audio_dir exists with proper ownership" | `/home/ubuntu/audio` | `0775` |

`/home/ubuntu` itself (`root_dir`) is referenced only as a prefix to construct other
paths. Its permissions are never set by any Ansible task.

### Probable source of the misconfiguration

The most likely causes of `/home/ubuntu` ending up at `775` are, in rough order of
likelihood:

1. **Ubuntu cloud-init / user provisioning** — some Ubuntu cloud images or `cloud-init`
   configurations create the `ubuntu` user with `umask 002`, which results in a home
   directory created at mode `775` instead of `755`. Check
   `/etc/cloud/cloud.cfg` or `/etc/adduser.conf` for a non-standard `DIR_MODE` or
   `UMASK` value.

2. **VirtualBox shared folder or snapshot import** — importing a VM snapshot or
   configuring shared folders can alter host-projected ownership/permissions, sometimes
   setting group-write on the home directory.

3. **Manual or scripted `chmod`** — a one-off `chmod g+w /home/ubuntu` or a script
   that ran `chmod -R 775 /home/ubuntu` at some point.

4. **`ansible.builtin.user` side-effect** — the base role adds `ubuntu` to the
   `www-data` group (`append: yes`). While this module should not alter home directory
   permissions when using `append: yes`, edge-case behavior in some Ansible/Python
   versions is possible. This is considered low-probability given the `append: yes`
   guard.

### Diagnostic commands to identify source

```bash
# Check current mode
ls -ld /home/ubuntu

# Check adduser default
grep -E 'DIR_MODE|UMASK' /etc/adduser.conf /etc/login.defs 2>/dev/null

# Check cloud-init user config
grep -A5 'ubuntu' /etc/cloud/cloud.cfg 2>/dev/null | grep -i 'perm\|mode\|umask'

# Verify StrictModes setting in active sshd config
sudo sshd -T | grep -i strictmodes
```

---

## 5. Should Ansible enforce 755 on /home/ubuntu?

The home directory for the `ubuntu` user is created by the OS/cloud-init before Ansible
runs. Adding an explicit task in the `base` role to enforce `chmod 755 /home/ubuntu`
would be defensive hardening against this class of misconfiguration. Whether to add such
a task is a judgement call — the tradeoff is:

- **Pro:** prevents future surprise SSH failures after any `sshd` update that enforces
  `StrictModes yes`
- **Con:** touching `$HOME` permissions explicitly is unusual and could mask the
  underlying misconfiguration source rather than fixing it upstream

If the root cause is confirmed as cloud-init creating the home dir at `775`, the cleaner
fix is to correct the cloud-init or `adduser` configuration so the home directory is
provisioned at `755` from the start.

---

**Status:** manually fixed (`chmod 755 /home/ubuntu` on labvm). Root cause of original
`775` not yet definitively identified. See diagnostic commands in Section 4.

---

## 6. VM Audit Results (2026-04-26)

Ran `vm_home_audit.sh` across all local VMs. Build dates confirmed via `tune2fs`
(`Filesystem created:` field in ext4 superblock — written once at `mkfs` time, not
affected by VDI resizes or `resize2fs` operations).

| Alias | IP | Perms (octal owner:group) | Built |
|-------|----|--------------------------|-------|
| gig2 | 192.168.1.50 | `750 ubuntu:ubuntu` | Thu Nov 13 07:55:50 2025 |
| labvm | 192.168.1.252 | `755 ubuntu:www-data` | Mon Mar 23 08:59:16 2026 |
| stagingvm | 192.168.1.248 | `750 ubuntu:ubuntu` | Mon Mar 23 08:59:16 2026 |

**Observations:**

- **gig2** is the oldest VM (Nov 2025). Permissions are `750` (no world access) — the
  Ubuntu 24.04 cloud image default. SSH works fine.
- **labvm** was fixed today (`chmod 755`). The group owner is `www-data` rather than
  `ubuntu` — unusual for a home directory. This is harmless at `755` but is a residual
  artefact of whatever caused the original `775`. Should be cleaned up with
  `chown ubuntu:ubuntu /home/ubuntu` on labvm.
- **stagingvm** is `750 ubuntu:ubuntu` — consistent with the cloud image default.
  SSH works fine.
- labvm and stagingvm were provisioned on the same day (Mar 23 2026), yet ended up
  with different group ownership on `/home/ubuntu`, suggesting something diverged
  post-provisioning on labvm.

**Note:** All three VMs are candidates for a clean rebuild at some point. gig2 in
particular is aging (Nov 2025) and has accumulated state across many playbook runs.
Not critical — current state is functional — but a fresh provision from the current
cloud image would give a clean baseline with correct permissions from the start.
