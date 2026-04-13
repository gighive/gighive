# OpenSSH Server Upgrade Breaks VM Provisioning Playbook

**Date:** 2026-04-12  
**System:** VirtualBox VM (`gighive2`) provisioned from Ubuntu 24.04 cloud image  
**Affected playbook:** `ansible/playbooks/site.yml` — `base` role `dist-upgrade` task

---

## 1. Problem Summary

The full VM provisioning playbook (`site.yml` with `vbox_provision`) began failing during `apt-get dist-upgrade` in the `base` role with:

```
E: Sub-process /usr/bin/dpkg returned an error code (1)
```

dpkg's specific error:

```
Setting up openssh-server (1:9.6p1-3ubuntu13.15) ...
Could not execute systemctl: at /usr/bin/deb-systemd-invoke line 148.
dpkg: error processing package openssh-server (--configure):
 installed openssh-server package post-installation script subprocess
 returned error exit status 1
```

Downstream consequence: `/run/sshd` (the privilege separation directory) was destroyed when sshd was stopped for the upgrade but never recreated, leaving the SSH service permanently dead until the VM was rebooted or manually fixed.

---

## 2. Why It Happened

### Immediate trigger

Ubuntu published `openssh-server 1:9.6p1-3ubuntu13.15` on 2026-04-12. The Ubuntu 24.04 cloud image ships an older version. For months prior, `dist-upgrade` had no new version of openssh-server to install, so the post-install restart path was never exercised. The moment a new version appeared in Ubuntu's repo, the latent conflict surfaced.

### Root cause: socket activation + live SSH session

Ubuntu 24.04 runs openssh-server in socket-activated mode (`ssh.socket` → `ssh.service`). The upgrade sequence inside dpkg is:

```
1. dpkg stops ssh.service → SIGTERM sent to sshd
2. systemd marks unit stopped, but PID 1249 (socket listener) stays alive
   because there is an active Ansible SSH connection
3. systemd cleans up /run/sshd (RuntimeDirectory)
4. dpkg's post-install script calls:
     deb-systemd-invoke restart ssh.service
5. deb-systemd-invoke calls systemctl to restart ssh.service
6. systemctl cannot restart cleanly (unit is in a partial stop state
   due to the live socket connection)
7. deb-systemd-invoke reports "Could not execute systemctl" and exits non-zero
8. dpkg reports post-install script failure, error code 1
9. dist-upgrade fails; openssh-server is left half-configured
10. All subsequent sshd start attempts fail:
      fatal: Missing privilege separation directory: /run/sshd
```

`systemctl is-system-running` reports `degraded` because `ssh.service` is dead.

### Why this only happens in Ansible provisioning (not manual runs)

The conflict is specific to upgrading openssh-server **over the same SSH connection the upgrade is running through**. A human doing `sudo apt-get dist-upgrade` interactively would likely still fail, but the Ansible remote execution context makes this completely unrecoverable mid-run since there is no operator present to intervene.

---

## 3. Observed Symptoms

```
fatal: [gighive_vm]: FAILED! => {
  "msg": "'/usr/bin/apt-get dist-upgrade ' failed:
    E: Sub-process /usr/bin/dpkg returned an error code (1)"
}
```

On the VM console after the failed playbook:

```
ubuntu@gighive2:~$ sudo dpkg --audit
The following packages are only half configured:
 openssh-server    secure shell (SSH) server, for secure access from remote

ubuntu@gighive2:~$ sudo systemctl is-system-running
degraded

ubuntu@gighive2:~$ sudo systemctl status ssh
● ssh.service - OpenBSD Secure Shell server
   Active: inactive (dead)
   ...
   sshd[19420]: fatal: Missing privilege separation directory: /run/sshd
   sshd[19421]: fatal: Missing privilege separation directory: /run/sshd
```

---

## 4. Fix

### Permanent fix — `ansible/roles/base/tasks/main.yml`

Wrap `dist-upgrade` with a `policy-rc.d` that returns 101, preventing all dpkg post-install scripts from invoking `deb-systemd-invoke` during the upgrade. After the upgrade completes cleanly, `needrestart -r a` restarts any services that need it — at that point dpkg is clean and systemd can start openssh-server normally, including recreating `/run/sshd`.

```yaml
- name: Prevent service restarts during dist-upgrade
  become: yes
  ansible.builtin.copy:
    dest: /usr/sbin/policy-rc.d
    content: "#!/bin/sh\nexit 101\n"
    mode: '0755'

- name: Upgrade all packages to the latest available versions
  ansible.builtin.apt:
    upgrade: dist
  become: yes

- name: Remove policy-rc.d to restore normal service management
  become: yes
  ansible.builtin.file:
    path: /usr/sbin/policy-rc.d
    state: absent

- name: Restart services that need restarting after upgrade
  become: yes
  ansible.builtin.command: needrestart -r a -q
  failed_when: false
  changed_when: false
```

### Mechanism

- **`policy-rc.d` returning 101** is the standard Debian/Ubuntu mechanism for suppressing service restarts during automated package operations. `deb-systemd-invoke` explicitly checks this file before calling systemctl. When it returns 101, `deb-systemd-invoke` exits 0 cleanly — no post-install script can fail on a service restart.
- **`needrestart -r a`** (`-r a` = auto-restart, `-q` = quiet) handles all deferred service restarts once dpkg is in a clean state. This is safe because the upgrade is fully committed before any restart is attempted.
- **`failed_when: false`** ensures the task is safe on systems where `needrestart` is not installed.

This is the same pattern Docker uses in `Dockerfile` builds to prevent service restarts during `apt-get` operations.

---

## 5. Why This Will Not Recur

Any future openssh-server (or any other service) upgrade will follow the same path:

1. dpkg upgrades the package without restarting the service
2. `policy-rc.d` ensures no post-install script can trigger a `deb-systemd-invoke` failure
3. After `dist-upgrade` completes, `needrestart` restarts all services cleanly

The fix applies to all packages that restart services via `deb-systemd-invoke`, not just openssh-server.

---

## 6. Manual Recovery (if VM is in broken state)

If the VM is already in the half-configured state:

```bash
# From VirtualBox console or any non-SSH session
sudo mkdir -p /run/sshd
sudo chmod 755 /run/sshd
sudo dpkg --configure openssh-server
sudo systemctl start ssh
sudo dpkg --audit   # should return nothing
```

Then re-run the playbook — `openssh-server` will already be at the upgraded version and dist-upgrade will skip it.

**Status:** fixed in `ansible/roles/base/tasks/main.yml`.
