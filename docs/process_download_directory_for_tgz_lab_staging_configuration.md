# Hosting `gighive-one-shot-bundle.tgz` on Staging + Lab (Artifact Distribution)

This document describes how to host `gighive-one-shot-bundle.tgz` from the **staging** GigHive instance while also supporting a **lab** testbed that mimics staging. It explains why the tarball must be treated as an **artifact** (not source code), and provides a complete implementation plan for distributing it to:

- **Staging**: provision the tarball by copying it from the Ansible controller when available.
- **Lab**: provision the tarball by downloading it from staging via `get_url`.

It also decouples “serve `/downloads`” behavior from `gighive_fqdn` by using an explicit boolean gate.

---

# Current Status (Implemented vs Remaining)

Implemented in this repo:

- `ansible/inventories/inventory_lab.yml` exists and is reachable via SSH (passwordless SSH configured on the controller).
- `ansible/inventories/group_vars/gighive/gighive.yml` contains the three `one_shot_bundle_*` variables.
- `ansible/roles/docker/tasks/main.yml` provisions the tarball based on `one_shot_bundle_source` and is gated by `serve_one_shot_installer_downloads`.
- `ansible/roles/docker/templates/docker-compose.yml.j2` mounts `/downloads` only when `serve_one_shot_installer_downloads` is true.

Still required for full staging + lab operation:

- Set `serve_one_shot_installer_downloads: true` and `one_shot_bundle_source: controller` on the **staging** host entry in the staging inventory files (e.g. `inventory_bootstrap.yml` and/or `inventory_virtualbox.yml`).

---

# Background / Key Constraints

## 1) The tarball is intentionally not in Git

`gighive-one-shot-bundle.tgz` is large (~500MB) and is typically excluded via `.gitignore` (e.g. `**/downloads`). This means:

- `git pull` on staging/lab will **not** bring the tarball onto those servers.
- Any Ansible task that does `copy: src=...` must ensure that the tarball exists **on the Ansible controller**.

This is why the tarball must be treated as a deployable **artifact** and distributed via a dedicated path.

## 2) Staging and lab are separate physical servers

Your Pop!_OS workstation is the development environment and a typical Ansible controller.

Staging and lab are separate machines that you keep in sync via `git pull`.

## 3) Lab is a staging-like testbed

Lab often uses staging-like configuration (including `gighive_fqdn` values) to test changes before staging. Because of that, it is brittle to couple “serve installer tarball” logic to `gighive_fqdn`.

---

# Design Goals

- Ensure `https://staging.gighive.app/downloads/gighive-one-shot-bundle.tgz` is reliably available.
- Support lab testbed while keeping the deployment logic explicit and predictable.
- Avoid accidental deployment to dev hosts (e.g. `gighive2`).
- Avoid hiding configuration in role defaults; prefer `group_vars` and inventory-host variables.
- Fail loudly if the tarball cannot be provisioned (do not allow it to silently go offline).

---

# High-Level Architecture

## Runtime serving: bind mount, not overlay

We continue to serve `/downloads` using a host bind mount:

- **Host path**: `{{ docker_dir }}/apache/downloads/`
- **Container path**: `/var/www/html/downloads/`

Rationale:

- Overlay would bake the tarball into the image and deploy it to all hosts.
- Bind mount keeps it as host runtime content and allows host-specific provisioning.

---

# Inventory and Variable Strategy

## Shared artifact metadata in `group_vars`

Add these variables to:

- `ansible/inventories/group_vars/gighive/gighive.yml`

```yaml
one_shot_bundle_filename: "gighive-one-shot-bundle.tgz"
one_shot_bundle_controller_src: "{{ repo_root }}/ansible/roles/docker/files/apache/downloads/{{ one_shot_bundle_filename }}"
one_shot_bundle_url: "https://staging.gighive.app/downloads/{{ one_shot_bundle_filename }}"
```

Notes:

- `one_shot_bundle_controller_src` is the canonical location on the controller when you build the tarball on Pop!_OS.
- `one_shot_bundle_url` is the canonical external distribution URL.

## Per-host behavior in inventory (not group_vars)

Two hosts (staging and lab) need to **serve** `/downloads`, but they differ in how they obtain the tarball.

We use two per-host vars set in inventory:

- `serve_one_shot_installer_downloads`: boolean gate for whether a host should serve `/downloads`.
- `one_shot_bundle_source`: selects artifact provisioning method.

Valid values for `one_shot_bundle_source`:

- `controller`: tarball is copied from controller path (`copy`).
- `url`: tarball is downloaded from staging URL (`get_url`).

### Staging inventory

Wherever staging is defined (e.g. `inventory_bootstrap.yml` and/or `inventory_virtualbox.yml`), set:

```yaml
serve_one_shot_installer_downloads: true
one_shot_bundle_source: controller
```

### Lab inventory (new file)

Create a separate inventory file:

- `ansible/inventories/inventory_lab.yml`

Define the lab host distinctly and set:

```yaml
serve_one_shot_installer_downloads: true
one_shot_bundle_source: url
```

This avoids “staging vs lab” ambiguity and allows different behavior while still sharing the same `group_vars/gighive/gighive.yml`.

---

# Ansible Implementation Details

## 1) Provision downloads directory + tarball (docker role)

File:

- `ansible/roles/docker/tasks/main.yml`

### Gating

Replace any checks like:

- `when: gighive_fqdn == 'gighive.gighive.internal'`

With:

- `when: serve_one_shot_installer_downloads | default(false)`

This prevents surprises when lab uses staging-like `gighive_fqdn`.

### Directory ownership

Ensure the downloads directory is created with ownership:

- `owner: {{ ansible_user }}`
- `group: {{ ansible_user }}`

This avoids root-owned directories inside the checked-out repo on controller machines and reduces permission-related confusion.

### Artifact provisioning logic

Within the gated block:

- Ensure downloads directory exists.
- If `one_shot_bundle_source == 'controller'`:
  - `stat` the controller file using `delegate_to: localhost`.
  - If missing: `fail`.
  - If present: `copy` it to the target.
- If `one_shot_bundle_source == 'url'`:
  - Use `get_url` to fetch from `one_shot_bundle_url`.
  - If it fails: the play fails (hard requirement).

This yields:

- Staging: copy from controller (fast; doesn’t depend on staging already hosting it).
- Lab: download from staging (no need to have tarball on lab controller).

## 2) Mount downloads into Apache (compose template)

File:

- `ansible/roles/docker/templates/docker-compose.yml.j2`

Replace the current `gighive_fqdn` conditional around the bind mount with:

```jinja2
{% if serve_one_shot_installer_downloads | default(false) %}
      - "{{ docker_dir }}/apache/downloads:/var/www/html/downloads:ro"
{% endif %}
```

This makes `/downloads` mount explicit and inventory-controlled.

---

# Operational Workflow

## Staging publish/update flow

1) On Pop!_OS dev box (controller), build/update the tarball at:

- `ansible/roles/docker/files/apache/downloads/gighive-one-shot-bundle.tgz`

2) Run Ansible against staging inventory.

Result:

- tarball is copied onto staging host at:
  - `{{ docker_dir }}/apache/downloads/{{ one_shot_bundle_filename }}`
- Apache serves it at:
  - `https://staging.gighive.app/downloads/{{ one_shot_bundle_filename }}`

## Lab sync flow

1) Ensure staging already hosts the tarball (above).
2) Run Ansible against `inventory_lab.yml`.

Result:

- lab downloads tarball from staging URL into:
  - `{{ docker_dir }}/apache/downloads/{{ one_shot_bundle_filename }}`
- lab serves it similarly if you expose lab via a public hostname.

---

# Failure Modes and Expected Errors

## Controller source missing (staging)

If `one_shot_bundle_source: controller` and the controller file is missing:

- The play should fail with an error indicating `one_shot_bundle_controller_src` does not exist.

This is intentional; it prevents the system from silently losing the installer.

## URL source fails (lab)

If `one_shot_bundle_source: url` and `get_url` fails (staging offline, DNS, 404):

- The play fails.

Also intentional.

---

# Security Notes

- The `/downloads` directory is served as static content.
- If you require access control, enforce it at Apache (BasicAuth, allowlists, etc.).
- Do not embed secrets into the tarball.

---

# Files Expected to Change (Implementation)

Files changed / expected to change:

- `ansible/inventories/group_vars/gighive/gighive.yml`
  - Added the three `one_shot_bundle_*` vars.
- `ansible/inventories/inventory_lab.yml`
  - Added new inventory for lab.
- `ansible/inventories/inventory_bootstrap.yml` and/or `ansible/inventories/inventory_virtualbox.yml`
  - set `serve_one_shot_installer_downloads: true` and `one_shot_bundle_source: controller` for staging.
- `ansible/roles/docker/tasks/main.yml`
  - Gated the downloads tasks with `serve_one_shot_installer_downloads`.
  - Implemented copy-vs-get_url logic with hard failure.
- `ansible/roles/docker/templates/docker-compose.yml.j2`
  - Gated the downloads bind mount with `serve_one_shot_installer_downloads`.

---

# Non-Goals

- This design does not attempt to store large artifacts in git.
- This design does not attempt to implement a general artifact repository.

---

# Approval Checklist

Before running against staging/lab, confirm:

1) Lab inventory host details (IP/SSH user) for `inventory_lab.yml` and that SSH is key-based.
2) Staging inventories are updated to set `serve_one_shot_installer_downloads: true` and `one_shot_bundle_source: controller`.
3) You want hard-fail behavior when the artifact cannot be sourced.
