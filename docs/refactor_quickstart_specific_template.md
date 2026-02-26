# Refactor: Quickstart-Specific Docker Compose Template

## Context / Problem
The one-shot Quickstart bundle (`gighive-one-shot-bundle.tgz`) must contain a runnable `docker-compose.yml` at the bundle root because `install.sh` expects to be executed from a directory containing `docker-compose.yml`.

Currently, the repo contains two related but different Docker Compose concepts:

- **VM deployment compose (Ansible-managed)**
  - Template: `ansible/roles/docker/templates/docker-compose.yml.j2`
  - Rendered by Ansible onto a target VM at `{{ docker_dir }}/docker-compose.yml`
  - Encodes VM-specific absolute host paths and Ansible inventory assumptions (e.g., `ansible_user`, `docker_dir`, media paths)

- **Quickstart bundle compose (portable installer)**
  - Historically present as a concrete file at `gighive-one-shot-bundle/docker-compose.yml`
  - Encodes *portable* assumptions using:
    - `${GIGHIVE_AUDIO_DIR}` / `${GIGHIVE_VIDEO_DIR}`
    - bundle-relative paths such as `./apache/...`, `./mysql/...`, `./tusd/...`
  - Must remain compatible with the `install.sh` contract.

As the app matures, we want the Quickstart `docker-compose.yml` to stay in sync with changes to the application containers/config, without relying on a manually maintained static `docker-compose.yml`.

## Goal
Make the Quickstart `docker-compose.yml` generated at bundle build time from a **Quickstart-specific template**, ensuring:

- Bundle always includes `docker-compose.yml` at root.
- Compose remains portable across end-user machines.
- Compose remains consistent with evolving deployment details (images, configs, versions).
- We avoid coupling the Quickstart bundle to VM deployment path assumptions.

## Non-Goals
- Using the VM deployment compose (`ansible/roles/docker/templates/docker-compose.yml.j2`) directly as the Quickstart compose.
  - Even when rendered with staging inventory, it bakes in staging-specific paths and assumptions that may not work on end-user machines.

## Proposed Design
### 1) Introduce a Quickstart-specific compose template
Add a new template dedicated to the one-shot bundle, for example:

- `ansible/roles/docker/files/one_shot_bundle/docker-compose.yml.j2`

Template properties:

- **Portable host mounts** using environment-variable defaults:
  - `${GIGHIVE_AUDIO_DIR:-./_host_audio}`
  - `${GIGHIVE_VIDEO_DIR:-./_host_video}`

- **Bundle-relative file references**:
  - `./apache/externalConfigs/...`
  - `./mysql/externalConfigs/...`
  - `./tusd/hooks/...`

- **Installer contract compatibility**:
  - Service names and any assumptions referenced by `install.sh` must remain aligned.

- **Optionally parameterized values** (rendered by Ansible during bundle build):
  - `apache_docker_image`
  - PHP version (`gighive_php_version`) for bind-mounted `www.conf` location
  - dataset selection layout (e.g., `sample` vs `full`) if Quickstart intends to support bundling both

### 2) Render the Quickstart compose into the bundle workspace during rebuild
In `ansible/roles/docker/tasks/one_shot_bundle_rebuild.yml`, add a controller-local render step after workspace creation and before archiving:

- Render `docker-compose.yml.j2` (Quickstart-specific) into:
  - `{{ one_shot_bundle_bundle_dir }}/docker-compose.yml`

This keeps `docker-compose.yml` a generated artifact, always up to date with the template.

### 3) Stop shipping `docker-compose.yml` as a static monitored input
Once the render step exists:

- Remove `{{ repo_root }}/gighive-one-shot-bundle/docker-compose.yml` from `one_shot_bundle_input_paths`.
- Treat the Quickstart compose as a generated build output, not a canonical input.

### 4) Monitoring / drift detection
Ensure that changes to the Quickstart compose template participate in the rebuild trigger:

- Add the new template path to `one_shot_bundle_input_paths`, e.g.:
  - `{{ repo_root }}/ansible/roles/docker/files/one_shot_bundle/docker-compose.yml.j2`

This preserves the Milestone 2 “monitor inputs -> prompt rebuild -> rebuild bundle” loop.

## Migration Plan
### Temporary state (current)
- Bundle uses the known-good static file:
  - `{{ repo_root }}/gighive-one-shot-bundle/docker-compose.yml`

### Refactor steps
1. Add Quickstart-specific compose template.
2. Add controller-local render step into `{{ one_shot_bundle_bundle_dir }}/docker-compose.yml`.
3. Remove static compose file from `one_shot_bundle_input_paths`.
4. Add the new template path to `one_shot_bundle_input_paths` so it triggers rebuild.
5. Rebuild/publish; validate on lab and clean VM.

## Validation Checklist
- `tar -tzf gighive-one-shot-bundle.tgz | grep '^gighive-one-shot-bundle/docker-compose.yml$'` contains the file.
- Extract bundle and run `./install.sh` from bundle root succeeds past the compose presence check.
- `docker compose config` succeeds in the extracted directory.
- `docker compose up -d --build` starts containers without missing bind-mount paths.
- `install.sh` continues to work for both interactive and `--non-interactive` flows.
