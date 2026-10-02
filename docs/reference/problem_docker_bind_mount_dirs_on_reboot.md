---
render_with_liquid: false
---

# Problem: Docker Creates Root-Owned Directories at Bind-Mount Source Paths After OS Reboot

## Date

2026-08-17

## Symptom

Ansible playbook failed in the `docker` role immediately after an OS reboot with:

```text
TASK [docker : Ensure host htpasswd file has correct ownership and permissions]
fatal: [gighive_vm]: FAILED! => {
  "msg": "file (/home/ubuntu/gighive/ansible/roles/docker/files/apache/externalConfigs/gighive.htpasswd)
          is directory, cannot continue"
}
```

Inspection of `externalConfigs/` on the VM showed that multiple bind-mount source paths
were root-owned directories instead of files:

```
drw-r-----  2 root  root  4096 Aug 17 18:37 gighive.htpasswd
drwxr-xr-x  2 root  root  4096 Aug 17 18:37 apache2.conf
drwxr-xr-x  2 root  root  4096 Aug 17 18:37 default-ssl.conf
drwxr-xr-x  2 root  root  4096 Aug 17 18:37 entrypoint.sh
drwxr-xr-x  2 root  root  4096 Aug 17 18:37 modsecurity.conf
drwxr-xr-x  2 root  root  4096 Aug 17 18:37 openssl_san.cnf
drwxr-xr-x  2 root  root  4096 Aug 17 18:37 php-fpm.conf
drwxr-xr-x  2 root  root  4096 Aug 17 18:37 security2.conf
drwxr-xr-x  2 root  root  4096 Aug 17 18:37 www.conf
```

## Root Cause

Proven from playbook logs and Docker systemd journal. Three facts combine to produce the failure:

### Fact 1 — rsync always deletes Jinja2-rendered files

The `base` role rsync task syncs `~/gighive/` on pop-os (the Ansible controller) to
`/home/ubuntu/gighive/` on the VM using `delete: yes`. The Jinja2-rendered bind-mount
config files (`entrypoint.sh`, `apache2.conf`, `default-ssl.conf`, etc.) are generated
at deploy time by the `docker` role — they do not exist in the pop-os source tree. rsync
therefore deletes them from the VM on every playbook run.

On normal deploys this is harmless: rsync deletes the files, the `docker` role immediately
re-renders them, and Docker starts with the correct files in place.

### Fact 2 — OS reboot opens a window between deletion and re-render

The `base` role runs rsync and then, if a package upgrade requires it, triggers an OS
reboot. The reboot takes ~42 seconds. After the reboot, Ansible waits for SSH to come
back, then continues with the `docker` role to re-render the files.

**The window:** rsync deletes the files at 18:36:19. Reboot is triggered at 18:36:31.
Docker systemd unit auto-starts at 18:37:13 — before Ansible has re-rendered anything.

### Fact 3 — Docker creates directories at absent bind-mount source paths

When Docker attempts to restore a container and a bind-mount source path does not exist
on the host, Docker creates a **root-owned directory** at that path. This is standard
Docker behaviour.

Docker journal at 18:37:15 confirms it attempted container restore and encountered
`entrypoint.sh` already as a directory (created moments earlier):

```text
error mounting "/home/ubuntu/gighive/ansible/roles/docker/files/apache/externalConfigs/entrypoint.sh"
to rootfs at "/entrypointapache.sh": not a directory: Are you trying to mount a directory onto a file?
```

### Why the existing guards did not save it

The `docker` role contains guard tasks (`render_guarded_template.yml`) that stat each
destination path and remove it if it is a directory before rendering. However:

1. The guard `state: absent` tasks run as `ansible_user` (ubuntu), not root.
2. The directories are owned by root (created by Docker).
3. `ansible.builtin.file: state=absent` run as a non-root user **silently returns ok**
   when it cannot remove a root-owned directory — it does not fail.
4. The subsequent template task then fails because the destination is still a directory.

Additionally, the `htpasswd` guard block does have `become: true` but it also lost the
TOCTOU race: the stat at 18:37:14 returned `isdir: false` (Docker had not yet created
the directory), the guard skipped, and by 18:37:15 Docker had created it.

## Evidence

| Evidence | Source |
|---|---|
| rsync returned `changed` at 18:36:19, 12 seconds before reboot | `ansible-playbook-gighive2-20260817.log` line 233 |
| OS reboot triggered at 18:36:31 | `ansible-playbook-gighive2-20260817.log` line 416 |
| Docker systemd started at 18:37:13 after `-- Boot --` marker | `journalctl -u docker` |
| Docker failed to start container because `entrypoint.sh` was already a directory | `journalctl -u docker` at 18:37:15 |
| Ansible reconnected at 18:37:14, stat returned `isdir: false` for htpasswd (race lost) | `ansible-playbook-gighive2-20260817.log` lines 424–430 |
| Prior deploy (20260816) ran clean: all guards skipped, all templates rendered, compose started | `ansible-playbook-gighive2-20260816.log` lines 522–642 |
| No docker compose or container-create activity between end of prior deploy and today's reboot | `journalctl` 19:22 Aug 16 → 18:36 Aug 17 |
| `externalConfigs/` on pop-os contains only 4 static files — rendered files absent | `ls ~/gighive/ansible/roles/docker/files/apache/externalConfigs/` on pop-os |

## Fix

Added a `Stop compose stack before rendering bind-mounted config files` task to
`ansible/roles/docker/tasks/main.yml`, immediately before the `Render bind-mounted
docker config files` block.

```yaml
- name: Stop compose stack before rendering bind-mounted config files
  community.docker.docker_compose_v2:
    project_src: "{{ docker_dir }}"
    state: stopped
  become: true
  become_user: "{{ ansible_user }}"
  failed_when: false
  tags: docker, compose
```

### Why this fixes the root cause

Docker cannot create directories at bind-mount source paths for a stopped container.
By stopping the compose stack before any file rendering occurs, the race window is
eliminated entirely: Docker is not running when rsync deletes the files and not running
when the render tasks write them back. Compose is brought back up later in the same role
as normal (the `Start Docker Compose stack` task).

`failed_when: false` is required for first-ever deploys where no prior compose state
exists — Docker Compose returns a non-zero exit code when there is nothing to stop.

### Why Option 1 (rsync exclusion) was not chosen

An rsync `--filter=protect` exclusion for `externalConfigs/` would prevent deletion but
requires manual maintenance: every new Jinja2-rendered bind-mount file would need a
corresponding exclusion entry. The self-healing stop/render/start approach requires no
per-file maintenance and is robust against any future cause of directory creation at
those paths.

## Verification

On the next deploy that triggers an OS reboot, the `Stop compose stack` task should
appear as `ok` or `changed` before the render tasks, and all render tasks should
complete as `changed` or `ok` with no `failed` outcome. The `docker` role should
complete without error.

## Related

- `docs/problem_docker_image_retagged_old_tag.md` — separate Docker compose lifecycle issue
- `ansible/roles/docker/tasks/render_guarded_template.yml` — per-file directory guards (still retained as defence-in-depth)
