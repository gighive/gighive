---
description: Quickstart Milestone 2: automate one-shot bundle rebuild + publish
---

# Quickstart Milestone 2: automate one-shot bundle rebuild + publish

This document describes the **Milestone 2** changes for the Quickstart one-shot bundle pipeline.

Milestone 1 provides an **alert-only** monitor that detects changes in upstream inputs and pauses the play so maintainers know the Quickstart artifact needs to be rebuilt.

Milestone 2 extends this so Ansible can:

- generate the bundle directory
- build `gighive-one-shot-bundle.tgz`
- generate `gighive-one-shot-bundle.tgz.sha256`
- publish both into the staging downloads directory

---

# Goals

- Provide a repeatable, controller-run, Ansible-driven way to regenerate the Quickstart artifact.
- Ensure **the served tarball and its `.sha256`** are updated together.
- Keep the “inputs changed” monitor as the guard rail / tripwire.

---

# Non-goals

- Automatically SCP/copy artifacts between hosts outside the playbook’s normal target host runs.
- Change the Quickstart UX (end-user experience) beyond whatever is implied by the updated bundle inputs.

---

# Terminology

- **Controller**: the machine from which `ansible-playbook` is executed. Tasks using `delegate_to: localhost` run on the controller.
- **Target host**: the staging VM being configured.
- **Bundle directory**: the controller-local generated directory that is tarred into the artifact.
- **Artifact**: `gighive-one-shot-bundle.tgz` plus `gighive-one-shot-bundle.tgz.sha256`.

---

# Inputs (sources of truth)

## Canonical installer

The canonical installer script is maintained at:

- `ansible/roles/docker/files/one_shot_bundle/install.sh`

It is copied into the **tarball root** as:

- `./install.sh`

## Other inputs

The remaining bundle content is sourced from a curated subset of:

- `ansible/roles/docker/files/**`
- `ansible/roles/docker/templates/**` (rendered as needed)
- `assets/audio/**` and `assets/video/**` (if shipping bundled sample media)

---

# Gating (when Milestone 2 runs)

Milestone 2 build/publish tasks must remain gated by inventory vars so the pipeline only runs when intended.

At minimum, the tasks should run only when:

- `serve_one_shot_installer_downloads | default(false)`
- and `one_shot_bundle_source == 'controller'`

This is consistent with Milestone 1 monitoring behavior.

---

# Variables

These variables exist today (artifact distribution):

- `one_shot_bundle_filename: "gighive-one-shot-bundle.tgz"`
- `one_shot_bundle_controller_src: "{{ repo_root }}/ansible/roles/docker/files/apache/downloads/{{ one_shot_bundle_filename }}"`
- `one_shot_bundle_url: "https://staging.gighive.app/downloads/{{ one_shot_bundle_filename }}"`

These variables exist today (Milestone 1 monitoring):

- `one_shot_bundle_input_paths` (list of files/dirs to monitor)
- `one_shot_bundle_inputs_fingerprint_path` (controller-local baseline fingerprint path)

Milestone 2 adds build-workspace variables:

- `one_shot_bundle_build_root`
  - controller-local directory used as a workspace for generating the bundle directory
- `one_shot_bundle_bundle_dir`
  - controller-local path to the generated bundle directory that will be archived

Optionally, add a rebuild policy variable:

- `one_shot_bundle_rebuild_mode` (suggested values: `on_change`, `always`, `never`)

---

# Outputs

## Controller-local outputs

After a successful Milestone 2 build, the controller should have:

- `{{ one_shot_bundle_controller_src }}`
- `{{ one_shot_bundle_controller_src }}.sha256`

Milestone 1 monitoring outputs also remain:

- `{{ one_shot_bundle_inputs_fingerprint_path }}`
- `{{ one_shot_bundle_inputs_fingerprint_path }}.json`

## Published outputs (served to Quickstart users)

On the target host, in the Apache downloads directory (example: `{{ docker_dir }}/apache/downloads`):

- `{{ one_shot_bundle_filename }}`
- `{{ one_shot_bundle_filename }}.sha256`

---

# Proposed Ansible flow (Milestone 2)

Milestone 2 extends the existing downloads staging block.

## Step 0: Run Milestone 1 monitor (existing)

- Build/refresh the controller-side input manifest and fingerprint.
- If inputs changed and a previous baseline exists:
  - pause with **ADDED/REMOVED/CHANGED** lists

## Step 1: Decide whether to rebuild

- If `one_shot_bundle_rebuild_mode == 'always'`: rebuild.
- If `one_shot_bundle_rebuild_mode == 'on_change'`: rebuild only when the monitor detected changes.
- If `one_shot_bundle_rebuild_mode == 'never'`: do not rebuild (legacy/manual behavior).

## Step 2: Generate the bundle directory (controller)

On the controller:

- ensure `{{ one_shot_bundle_build_root }}` exists
- create a clean `{{ one_shot_bundle_bundle_dir }}` directory
- create required subdirectories (`apache/`, `mysql/`, `tusd/`, etc.)
- copy in curated file trees from `ansible/roles/docker/files/**`
- copy in the canonical installer script to `{{ one_shot_bundle_bundle_dir }}/install.sh`
- render `docker-compose.yml` into the bundle directory (from a template)

## Step 3: Create the `.tgz` (controller)

On the controller:

- archive `{{ one_shot_bundle_bundle_dir }}` into `{{ one_shot_bundle_controller_src }}`

Implementation detail:

- prefer `ansible.builtin.archive` over a `tar` shell command

## Step 4: Create/update the artifact `.sha256` (controller)

On the controller:

- generate `{{ one_shot_bundle_controller_src }}.sha256`

## Step 5: Publish artifact to the target host downloads directory

On the target host:

- copy `{{ one_shot_bundle_controller_src }}` to `{{ docker_dir }}/apache/downloads/{{ one_shot_bundle_filename }}`
- copy `{{ one_shot_bundle_controller_src }}.sha256` to `{{ docker_dir }}/apache/downloads/{{ one_shot_bundle_filename }}.sha256`

---

# Operator workflow (recommended)

- Run the playbook against the staging inventory.
- If the input monitor pauses:
  - review the file list
  - proceed to allow rebuild/publish (Milestone 2) or abort if unexpected
- After publish:
  - sanity check the served files exist on the staging host

---

# Sanity checks

From the controller (repo root):

```bash
ls -l ansible/roles/docker/files/apache/downloads/gighive-one-shot-bundle.tgz
ls -l ansible/roles/docker/files/apache/downloads/gighive-one-shot-bundle.tgz.sha256
```

Inspect tarball contents:

```bash
tar -tzf ansible/roles/docker/files/apache/downloads/gighive-one-shot-bundle.tgz | head
```

Verify installer is present at tarball root:

```bash
tar -tzf ansible/roles/docker/files/apache/downloads/gighive-one-shot-bundle.tgz | grep -E '^gighive-one-shot-bundle/install\.sh$'
```

On the staging host:

```bash
ls -l {{ docker_dir }}/apache/downloads/{{ one_shot_bundle_filename }}
ls -l {{ docker_dir }}/apache/downloads/{{ one_shot_bundle_filename }}.sha256
```
