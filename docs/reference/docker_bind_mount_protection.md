# Docker bind-mount protection (prevent “directory onto file” failures)

## Summary

GigHive’s Ansible `docker` role bind-mounts several host-side configuration *files* into the Apache container (e.g. `apache2.conf`, `default-ssl.conf`, `ports.conf`).

Docker has a sharp edge: if a bind-mount **source path does not exist** at container create/start time, Docker may auto-create it — and it is typically created as a **directory**. If the container expects a *file* mount, this results in:

- Container stuck in state `created`
- An OCI runtime error similar to:

  - `Are you trying to mount a directory onto a file (or vice-versa)?`
  - `... error mounting "<host>/default-ssl.conf" to rootfs at "/etc/apache2/.../default-ssl.conf": not a directory ...`

This document describes how to diagnose the issue and the hardening changes added to prevent it.

## Symptoms

During Ansible runs you may see:

- A failing assert like:
  - `apacheWebServer container is not running or not present. Detected status: created`

On the VM, inspecting the container state shows an OCI mount error:

```bash
docker inspect apacheWebServer --format '{{json .State}}'
```

A common finding is that a supposed-to-be-file path is actually a directory:

```bash
ls -ld /home/ubuntu/gighive/ansible/roles/docker/files/apache/externalConfigs/default-ssl.conf
# drwxr-xr-x ... default-ssl.conf
```

## Root cause

A Docker bind mount in compose like:

- `<host>/default-ssl.conf:/etc/apache2/sites-available/default-ssl.conf:ro`

requires `<host>/default-ssl.conf` to be a **file**.

If that source path is missing when `docker compose up` (or Ansible’s `docker_compose_v2`) runs, Docker can create the missing endpoint as a **directory**. Once that happens, subsequent runs fail because the host-side mount source is the wrong type.

Common reasons the file may be missing at compose time:

- `docker compose` run outside Ansible before Ansible renders/copies config files
- Running Ansible with tags that start compose but skip the render/copy steps
- Previous failed/partial runs leaving behind directory placeholders

## How we diagnosed it

1. Confirm container status:

```bash
docker ps -a --filter name=apacheWebServer
```

2. Inspect container runtime error:

```bash
docker inspect apacheWebServer --format '{{.State.Status}} {{.State.Error}} {{.State.ExitCode}}'
```

3. Verify the host mount source type:

```bash
ls -ld <docker_dir>/apache/externalConfigs/default-ssl.conf
```

If it’s a directory, that is the failure.

## Immediate remediation (one-off fix)

On the VM, remove the miscreated directory and re-run the docker role:

```bash
sudo rm -rf <docker_dir>/apache/externalConfigs/default-ssl.conf
```

Then rerun Ansible `--tags docker` (or the full play). The role will render/copy the config as a real file and the container should start.

## Hardening implemented in Ansible (prevention)

### 1) Make compose prerequisites run under the same tags as compose

In `ansible/roles/docker/tasks/main.yml`, we grouped bind-mount prerequisites into a single block:

- `Render bind-mounted docker config files`

and tagged the whole block with:

- `tags: docker, compose`

This ensures that `--tags compose` cannot start containers without first creating the required host-side config files.

### 2) Guard against miscreated directories for file mount sources

For bind-mounted files that must exist on the host, we added a consistent pattern:

- `stat` the path
- if it is a directory, remove it
- then render/copy the file

This was added for:

- `apache2.conf` (template)
- `default-ssl.conf` (template)
- `ports.conf` (static file copy)
- `logging.conf` (static file copy)
- `apache2-logrotate.conf` (static file copy)

### 3) Copy static external config files explicitly

Some bind-mounted Apache configs are not templates and live under:

- `ansible/roles/docker/files/apache/externalConfigs/`

We now explicitly copy these into the target host path (`{{ docker_dir }}/apache/externalConfigs/`) before compose runs, with the same guard behavior.

## Operational guidance

- Prefer running `--tags docker` or `--tags compose` now that the prerequisites are aligned.
- If you ever see a container stuck in `created`, check `.State.Error` and then `ls -ld` the bind-mounted source path.
- If a file path is a directory, remove it and rerun the docker role.

## Related files

- Ansible role tasks:
  - `ansible/roles/docker/tasks/main.yml`

- Compose template:
  - `ansible/roles/docker/templates/docker-compose.yml.j2`

- Static external configs:
  - `ansible/roles/docker/files/apache/externalConfigs/ports.conf`
  - `ansible/roles/docker/files/apache/externalConfigs/logging.conf`
  - `ansible/roles/docker/files/apache/externalConfigs/apache2-logrotate.conf`
