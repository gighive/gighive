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
- **Canonical inputs**: curated, source-controlled files and templates used to generate the bundle.
- **Generated workspace**: a controller-local directory created by Ansible to assemble the bundle content. It must not overlap the canonical inputs.
- **Bundle directory**: the controller-local generated directory (inside the generated workspace) that is tarred into the artifact.
- **Artifact**: `gighive-one-shot-bundle.tgz` plus `gighive-one-shot-bundle.tgz.sha256`.

---

# Inputs (sources of truth)

## Canonical installer

The canonical installer script is maintained at:

- `ansible/roles/docker/files/one_shot_bundle/install.sh`

It is copied into the **tarball root** as:

- `./install.sh`

## Other inputs

The bundle contents are defined by `one_shot_bundle_input_paths`.

- The list is used for both monitoring and packaging.
- The packaged roots are exactly the roots listed in `one_shot_bundle_input_paths`.

## Generated workspace (controller)

Milestone 2 generates the bundle in a controller-local workspace directory.

- The generated workspace is not source-controlled.
- It must not write into `ansible/roles/docker/files/**` or `ansible/roles/docker/templates/**`.

Generated workspace location:

- `{{ repo_root }}/ansible/.tmp/one_shot_bundle/`

---

# Gating (when Milestone 2 runs)

Milestone 2 build/publish tasks run only when:

- `serve_one_shot_installer_downloads | default(false)`

When `one_shot_bundle_source == 'controller'`, the playbook monitors inputs on the controller and optionally rebuilds/publishes.

When `one_shot_bundle_source == 'url'`, the playbook downloads the artifact from `one_shot_bundle_url` and publishes it into the target downloads directory.

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

- `one_shot_bundle_bundle_dir`
  - controller-local path to the generated bundle directory that will be archived
  - (this also serves as the build workspace for Milestone 2)
  - value: `{{ repo_root }}/ansible/.tmp/one_shot_bundle/gighive-one-shot-bundle`

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

## Step 0: Monitor inputs (controller)

On the controller, the playbook computes an inputs manifest based on `one_shot_bundle_input_paths`.

If a previous baseline manifest exists, the playbook computes and prints **ADDED/REMOVED/CHANGED** lists.

Additional operator-facing output:

- The playbook always emits whether a previous baseline JSON exists at `{{ one_shot_bundle_inputs_fingerprint_path }}.json`.

## Step 1: Ask whether to rebuild (interactive)

- After the monitor step, prompt the operator:
  - `Do you want to rebuild the bundle? (yes/no)`
- The prompt is shown when no baseline exists (first run) or when inputs have diffs.
- If the operator answers `no`, the playbook does not rebuild the artifact.
- If the operator answers `no` and the controller artifact is missing, the playbook fails.

## Step 2: Generate the bundle directory (controller)

On the controller, the playbook creates a clean `{{ one_shot_bundle_bundle_dir }}` directory and populates it from `one_shot_bundle_input_paths`.

`ansible/roles/docker/files/one_shot_bundle/install.sh` is copied to the tarball root as `install.sh`.

## Step 3: Create the `.tgz` (controller)

On the controller:

- archive `{{ one_shot_bundle_bundle_dir }}` into `{{ one_shot_bundle_controller_src }}`

The playbook uses `ansible.builtin.archive`.

## Step 4: Create/update the artifact `.sha256` (controller)

On the controller, the playbook generates `{{ one_shot_bundle_controller_src }}.sha256`.

## Step 5: Publish artifact to the target host downloads directory

On the target host, the playbook publishes the artifact into the Apache downloads directory.

Publishing is a no-op when the target already has the exact artifact (sha256 match) and the operator did not rebuild.

After a successful rebuild, the playbook advances the baseline manifest and fingerprint on the controller.

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
