---
description: Rebuild criteria for Quickstart one-shot bundle tarball
---

# Quickstart one-shot bundle: rebuild criteria

## Rationale (why the one-shot bundle exists)

The one-shot bundle exists to give end users a **simple installation path** for GigHive that does **not** require them to run (or understand) Ansible.

Instead of cloning the repo, learning inventory/group_vars conventions, and executing playbooks, a user can:

- download a single `gighive-one-shot-bundle.tgz`
- extract it
- run `./install.sh`

The installer script and bundled files act as the “pre-built output” of the full Ansible-driven build/deploy workflow.

This document defines **when you must rebuild** the Quickstart artifact:

- `gighive-one-shot-bundle.tgz`

The Quickstart goal is to let an end user install GigHive **without running Ansible**. The tarball is therefore a **staging-targeted snapshot** of the minimal files (primarily sourced from `ansible/`) required to run:

- `./install.sh` (inside the bundle)
- a bundled `docker-compose.yml`
- a minimal `apache/` + `mysql/` + `tusd/` build/runtime layout

The tarball is published by maintainers (typically from the **staging server build target**) and served from:

- `https://staging.gighive.app/downloads/{{ one_shot_bundle_filename }}`

Related variables (staging target) live in:

- `ansible/inventories/group_vars/gighive/gighive.yml`
  - `one_shot_bundle_filename: "gighive-one-shot-bundle.tgz"`
  - `one_shot_bundle_controller_src: "{{ repo_root }}/ansible/roles/docker/files/apache/downloads/{{ one_shot_bundle_filename }}"`
  - `one_shot_bundle_url: "https://staging.gighive.app/downloads/{{ one_shot_bundle_filename }}"`

---

# Core rule

Rebuild the tarball **whenever a repo change would change what a Quickstart end user gets after running**:

- `tar -xzf gighive-one-shot-bundle.tgz`
- `cd gighive-one-shot-bundle`
- `./install.sh`

If the end user experience, container behavior, default config, bundled sample data, or security posture changes, you rebuild.

---

# Important: the bundle directory is generated

The directory `gighive-one-shot-bundle/` is a **generated staging-targeted artifact**.

- `install.sh` is maintained at `ansible/roles/docker/files/one_shot_bundle/install.sh`.
- Most other bundle files are migrated/copied from `ansible/` as a minimal subset needed to run the one-shot install.

Therefore, the primary rebuild trigger is **not** “the bundle directory changed” (because it is derivative), but rather:

- **Upstream Ansible source inputs changed** (and the bundle should be re-generated from them)
- **or** `install.sh` changed

If you do manually edit files inside `gighive-one-shot-bundle/`, treat that as a red flag and rebuild immediately, but the intended workflow is to edit the upstream `ansible/` sources and then re-generate the bundle.

---

# Rebuild triggers by function (practical checklist)

## A) Installer behavior (`install.sh`)

Rebuild if you change any of:

- prompt/CLI flag behavior (`--site-url`, `--audio-dir`, etc.)
- required inputs (passwords, mysql vars)
- how `.env` files are generated
- default values baked into `.env` output (ex: `APP_FLAVOR`, `GA4_*`, upload allowlists)
- htpasswd generation logic or credential handling
- the compose invocation (`docker compose up -d --build`)

Why:

- `install.sh` is the “replacement” for running Ansible; it defines the Quickstart contract.

## B) Compose topology (`docker-compose.yml`)

Rebuild if you change any of:

- container images/tags (ex: `mysql:8.4`, `tusproject/tusd:latest`)
- build context/dockerfile references (ex: `./apache/Dockerfile`)
- ports, container names
- volume mounts (paths, read/write flags)
- env files referenced

Why:

- This directly changes runtime behavior for every Quickstart install.

## C) Apache container build/runtime inputs (bundle-local files)

Rebuild if any bundled Apache build/runtime inputs change, such as:

- `gighive-one-shot-bundle/apache/Dockerfile`
- `gighive-one-shot-bundle/apache/externalConfigs/**`
  - apache conf, ssl vhost, php-fpm conf, `www.conf`, logrotate, entrypoint, modsecurity, CRS config/rules

Why:

- These files are bind-mounted into the container and/or used at build time.

## D) MySQL initialization / dataset inputs

Rebuild if any of these change:

- `gighive-one-shot-bundle/mysql/externalConfigs/*.sql`
- `gighive-one-shot-bundle/mysql/externalConfigs/*.cnf`
- `gighive-one-shot-bundle/mysql/externalConfigs/prepped_csvs/**` (sample dataset)

Why:

- Quickstart depends on a specific initialization behavior and dataset.

## E) Tusd hooks

Rebuild if any of these change:

- `gighive-one-shot-bundle/tusd/hooks/**`

Why:

- Hook behavior affects uploads and post-processing.

## F) Bundled sample media (if shipped)

Rebuild if you add/remove/replace bundled media in:

- `gighive-one-shot-bundle/_host_audio/**`
- `gighive-one-shot-bundle/_host_video/**`

Why:

- That changes the out-of-the-box demo content and tarball size.

---

# Ansible-side changes that typically require a rebuild

Because the bundle (except `install.sh`) is intentionally a **minimal subset sourced from under `ansible/`**, you generally rebuild when you change any upstream Ansible file that is copied into the bundle.

---

# Milestone 1: input monitoring (alert-only, no rebuild)

We maintain an explicit list of upstream inputs that feed the one-shot bundle/tarball and compute a controller-side fingerprint.

If those inputs change, Ansible:

- prints an **ALL CAPS** message
- shows **which files changed** (added/removed/changed)
- pauses until you press Enter

This gives a reliable “tripwire” so you know a Quickstart rebuild + publish is required.

## Configuration variables

Variables:

- `one_shot_bundle_input_paths` (list of files/dirs to monitor; recursive for dirs)
- `one_shot_bundle_inputs_fingerprint_path` (controller-local baseline fingerprint)

The monitor also stores a controller-local manifest alongside the digest:

- `{{ one_shot_bundle_inputs_fingerprint_path }}.json`

The manifest is a JSON dictionary mapping:

- `absolute_path -> mtime+size`

The controller-local fingerprint file (`{{ one_shot_bundle_inputs_fingerprint_path }}`) is computed from the manifest JSON (it is not a per-file checksum).

## Suggested input paths for full bundle coverage

If you want the monitor to cover the full set of upstream inputs that typically populate `gighive-one-shot-bundle/`, include at least:

- `{{ repo_root }}/ansible/roles/docker/templates`
- `{{ repo_root }}/ansible/roles/docker/files/apache/Dockerfile`
- `{{ repo_root }}/ansible/roles/docker/files/apache/externalConfigs`
- `{{ repo_root }}/ansible/roles/docker/files/apache/overlays`
- `{{ repo_root }}/ansible/roles/docker/files/apache/webroot`
- `{{ repo_root }}/ansible/roles/docker/files/mysql/externalConfigs`
- `{{ repo_root }}/ansible/roles/docker/files/tusd/hooks`
- `{{ repo_root }}/assets/audio`
- `{{ repo_root }}/assets/video`

## Gating (where it runs)

The monitor runs only when:

- `serve_one_shot_installer_downloads: true`
- and `one_shot_bundle_source: controller`

This ensures it runs only on inventories that are responsible for publishing the one-shot artifact, and only when the source is the controller.

## Exclusions

The fingerprint excludes macOS metadata files:

- `._*`
- `.DS_Store`

## First run behavior

On the first run (no previous baseline manifest exists), the monitor writes the baseline files and does not pause.

## Outcome

If inputs changed, you must rebuild the Quickstart tarball (`gighive-one-shot-bundle.tgz`) and copy it to the staging controller before continuing the publish flow.

Common upstream sources include:

- `ansible/roles/docker/templates/**` (if you regenerate bundle configs from these)
- `ansible/roles/docker/files/**` (if you vendor/copy these into the bundle)

Examples:

- If you change `ansible/roles/docker/templates/docker-compose.yml.j2` and then re-generate the bundle’s `docker-compose.yml`, you must rebuild.
- If you change modsecurity/CRS, entrypoint scripts, apache/php-fpm configs, or SQL init scripts under `ansible/roles/docker/files/**` that are mirrored into the bundle, you must rebuild.

---

# Changes that do NOT, by themselves, require a rebuild

## Documentation-only changes (outside the bundle)

If you change docs in `docs/` that are not included in the tarball, you don’t need to rebuild the tarball.

## Publishing/serving mechanics (artifact distribution)

Changes to how the artifact is hosted/copied (for example, Ansible tasks that copy the tarball into `/downloads`, or generate and publish a `.sha256`) do not require rebuilding the tarball **unless** you also changed tarball contents.

(You *will* typically re-run the publish playbook anyway, but that’s different from rebuilding the tarball.)

---

# Required companion artifacts

When you rebuild `gighive-one-shot-bundle.tgz`, you must also refresh integrity metadata:

- `gighive-one-shot-bundle.tgz.sha256`

These should be published together so Quickstart users can validate the download.

---

# Quick sanity checks after rebuild

From the repo root (controller):

```bash
# Rebuild tarball
# (bundle directory must exist at repo root)
tar -czf ansible/roles/docker/files/apache/downloads/gighive-one-shot-bundle.tgz -C . gighive-one-shot-bundle

# Inspect contents
ls -l ansible/roles/docker/files/apache/downloads/gighive-one-shot-bundle.tgz
tar -tzf ansible/roles/docker/files/apache/downloads/gighive-one-shot-bundle.tgz | head
```

Optionally validate that the installer is present:

```bash
tar -tzf ansible/roles/docker/files/apache/downloads/gighive-one-shot-bundle.tgz | grep -E '^gighive-one-shot-bundle/install\.sh$'
```

---

# Operational notes (Milestone 1 input monitor)

## Gating

The one-shot bundle input monitor runs only when:

- `serve_one_shot_installer_downloads | default(false)`
- and `one_shot_bundle_source == 'controller'`

## Baseline files written on the controller

The monitor writes these baseline files on the Ansible controller:

- `{{ one_shot_bundle_inputs_fingerprint_path }}.json`
- `{{ one_shot_bundle_inputs_fingerprint_path }}`

## First run

On the first run (no previous baseline manifest exists), the monitor writes the baseline files and does not pause.

## Subsequent runs

On subsequent runs, if any monitored input changes, the play pauses and shows:

- **ADDED** paths
- **REMOVED** paths
- **CHANGED** paths
