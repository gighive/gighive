# One-Shot Bundle New Process

## Purpose

This document explains the newer, simpler process for monitoring and producing the one-shot bundle.

The goal of the new approach is to make one-shot bundle maintenance more direct by:

- comparing repository inputs directly against the checked-out one-shot bundle
- reporting drift in a clear four-category summary
- producing a fresh output bundle in `/tmp/gighive-one-shot-bundle`
- reducing dependence on older baseline-oriented or indirect workflows

This document reflects the current implementation in:

- `ansible/roles/one_shot_bundle/tasks/monitor.yml`
- `ansible/roles/one_shot_bundle/tasks/output_bundle.yml`
- `ansible/roles/one_shot_bundle/tasks/main.yml`

## Rationale

The newer method is intended to be simpler and more direct than the older one-shot bundle handling.

Instead of treating the one-shot bundle as something inferred through an intermediate baseline manifest workflow, the new process treats it as two concrete things:

- a checked-out bundle directory that can be inspected directly
- a set of source inputs in the repo that can be mapped directly into bundle-relative paths

That gives a more understandable workflow:

1. inspect source inputs from the repo root
2. map them into their expected bundle-relative paths
3. compare those expected paths directly against the checked-out bundle
4. optionally emit a fresh bundle into `/tmp/gighive-one-shot-bundle`

This makes it easier to answer the practical questions that matter:

- what is missing from the checked-out bundle
- what is extra in the checked-out bundle
- what changed between source inputs and the checked-out bundle
- what did not change

It also gives a practical export step that can be tested independently of rebuild/publish behavior.

## Core Idea

The core idea is that repository inputs are the source of truth for what the bundle should contain, but the checked-out one-shot bundle is the practical assembled artifact that is monitored and refreshed.

The process therefore has two related but distinct responsibilities:

- monitoring drift between repo inputs and the checked-out bundle
- producing a fresh bundle output for inspection/testing

## Why Full Build and Quickstart Cannot Share a Single group_vars Entry

The quickstart bundle has no independent Ansible inventory or group_vars of its own — it is a static artifact assembled from within the full-build Ansible context. Once the bundle is handed to an end user, there is no Ansible run at all; the user just executes `install.sh` directly.

The constraint is structural:

- **Full build** → Ansible runs → group_vars available → `.env.j2` renders with `gighive_install_channel: full`
- **Quickstart** → no Ansible run → no group_vars → user runs `install.sh` standalone

There is nowhere in the quickstart path to inject a group_vars override unless it is baked invisibly into the bundle assembly step (e.g. a `set_fact` in the `one_shot_bundle` role). But even then it is a workaround, not a true single declared source.

The correct single source of truth for the quickstart channel is `install.sh.j2` itself — the declaration `_install_channel="quickstart"` already lives there (line 244) because `install.sh` *is* the quickstart entry point. Any runtime config that needs to reflect this (e.g. `GIGHIVE_INSTALL_CHANNEL` in `apache/externalConfigs/.env`) should be patched by `install.sh` at user install time using `_patch_env_key`, rather than relying on what was baked into the pre-rendered `.env` during the full-build Ansible run.

## Current Roles of the Main Files

### `ansible/roles/one_shot_bundle/tasks/main.yml`

This file runs the one-shot bundle workflow in this order:

1. Read the repo root `VERSION` file and write a `_quickstart`-suffixed version into `ansible/roles/docker/files/one_shot_bundle/VERSION`
2. `monitor.yml` — scan source inputs and build the source manifest
3. `output_bundle.yml` — render and copy all files into `/tmp/gighive-one-shot-bundle`, validate the output, then call `archive.yml` to produce the `.tgz` and `.sha256`
4. Show final four-category summary

There is no `rebuild.yml`, `publish.yml`, or `one_shot_bundle_monitor_only` flag in the current implementation. The archive step (`.tgz` creation) runs as part of every `output_bundle.yml` invocation via `archive.yml`, and is also available as a standalone re-run via `--tags one_shot_bundle_archive`.

### `ansible/roles/one_shot_bundle/tasks/monitor.yml`

This file performs direct source-to-bundle drift detection.

It scans repository input paths, maps each input file into the bundle-relative location it should correspond to, and then compares that expected manifest to the checked-out bundle directory.

### `ansible/roles/one_shot_bundle/tasks/output_bundle.yml`

This file creates a fresh output bundle at:

- `/tmp/gighive-one-shot-bundle`

It currently uses the same path-mapping logic as the monitor so that the output structure follows the same bundle-relative layout.

It also performs a post-output validation pass using the same four categories.

## Source Inputs Included in Monitoring

The monitored source inputs are defined by `one_shot_bundle_input_paths` in `group_vars` and currently include:

- `ansible/roles/docker/templates`
- `ansible/roles/docker/files/one_shot_bundle`
- `ansible/roles/docker/files/tusd`
- `ansible/roles/docker/files/apache/externalConfigs`
- `ansible/roles/docker/files/apache/overlays`
- `ansible/roles/docker/files/apache/webroot`
- `ansible/roles/docker/files/mysql/externalConfigs`
- `assets/audio`
- `assets/video`

Note: the `apache` directory is not monitored as a whole — the three subdirectories above are listed individually. `files/tusd` (not `files/tusd/hooks`) is the monitored path. `mysql/externalConfigs/prepped_csvs/full` and `prepped_csvs/full_csvmethod` are intentionally excluded via `one_shot_bundle_exclude_source_paths`.

## Bundle Path Mapping

A key part of the new method is the explicit mapping from source repo paths to expected bundle-relative paths.

Examples:

- `ansible/roles/docker/templates/docker-compose.yml.j2` -> `docker-compose.yml`
- `ansible/roles/docker/templates/entrypoint.sh.j2` -> `apache/externalConfigs/entrypoint.sh`
- `ansible/roles/docker/templates/.env.j2` -> `apache/externalConfigs/.env`
- `ansible/roles/docker/templates/.env.mysql.j2` -> `mysql/externalConfigs/.env.mysql`
- `assets/audio/...` -> `_host_audio/...`
- `assets/video/...` -> `_host_video/...`

This mapping is what allows the monitor to do like-for-like comparison against the checked-out bundle.

## Four Drift Categories

The new monitoring output is designed around four categories:

- `files_that_did_not_change`
- `missing_from_bundle`
- `extra_in_bundle`
- `changed_between_source_and_bundle`

These are intended to make the output understandable at a glance.

### Meaning of the categories

- `files_that_did_not_change`
  - source-mapped bundle paths that exist in both places and appear unchanged by the current comparison rule

- `missing_from_bundle`
  - source-mapped paths expected from the repo but not present in the checked-out bundle

- `extra_in_bundle`
  - paths present in the checked-out bundle that are not represented by the current monitored source inputs

- `changed_between_source_and_bundle`
  - paths present in both places but considered different by the current comparison rule

## Current Monitoring Behavior

The monitor currently compares manifest entries using a lightweight fingerprint based on:

- `mtime`
- `size`

This is useful for lightweight drift detection against the checked-out bundle.

This is good enough to alert when:

- a repo-side template source changed
- a repo-side file changed
- the checked-out bundle appears stale relative to those inputs

In particular, this remains useful even when the checked-out bundle contains pre-rendered files. If a template source in the repo changes and the checked-out bundle is not refreshed from staging, the monitor should still surface that as drift.

## Important Clarification About Pre-Rendered Files

The one-shot bundle is driven by pre-rendered files.

That means many outputs corresponding to files under:

- `ansible/roles/docker/templates`

already exist as pre-rendered files inside the checked-out one-shot bundle.

Examples include files such as:

- `docker-compose.yml`
- `apache/externalConfigs/.env`
- `apache/externalConfigs/gighive.htpasswd`
- other pre-rendered config files under `apache/externalConfigs` and `mysql/externalConfigs`

This affects the recommended long-term export strategy.

### Monitoring implication

Monitoring should still continue to map repo template sources into the expected bundle-relative outputs so drift can be detected.

That gives visibility into when a source template changed and the checked-out bundle should be manually refreshed from the staging VM.

### Export implication

For bundle production, the cleaner model is generally to prefer already pre-rendered files from the checked-out bundle whenever those files are already authoritative there, rather than re-rendering them again during `/tmp` export.

## Fresh Output Bundle

The new output step writes a fresh bundle to:

- `/tmp/gighive-one-shot-bundle`

This is intended for test inspection and verification.

The current implementation:

- removes any existing `/tmp/gighive-one-shot-bundle`
- recreates it
- creates destination directories according to the source-to-bundle mapping
- writes files into the mapped bundle locations
- reports the output directory
- validates the output using the same four categories

### Check Mode Clarification

If the playbook is run with `--check`, the `/tmp/gighive-one-shot-bundle` output directory is not actually refreshed.

Even though the role evaluates the steps that would normally:

- remove any existing `/tmp/gighive-one-shot-bundle`
- recreate it
- write the mapped output files into it

check mode prevents those filesystem changes from being applied.

That means an already existing `/tmp/gighive-one-shot-bundle` can remain in place with stale contents from an earlier non-check run.

Operationally, this means:

- `--check` is useful for previewing what the role would do
- `--check` should not be treated as proof that `/tmp/gighive-one-shot-bundle` was regenerated
- if a fresh `/tmp` output is needed for inspection, run without `--check`

Manual deletion of `/tmp/gighive-one-shot-bundle` before rerunning is optional. A normal non-check run already removes and recreates that directory. Deleting it first can still be useful as an operator convenience when you want an obvious clean-start signal.

### File Equivalence Versus Timestamp Equivalence

For direct-copy files in `/tmp/gighive-one-shot-bundle`, matching file content does not necessarily imply matching filesystem timestamps.

In the current implementation, a file in `/tmp` can be content-identical to its source file in the repo while still showing a different `mtime`.

This means:

- matching `sha256` hashes are the reliable signal that two files are effectively the same in content
- differing `mtime` values do not by themselves prove that the `/tmp` file is stale or incorrect
- `ll` output alone is not sufficient to decide whether a copied `/tmp` file differs from its source

Operationally, if a direct-copy file in `/tmp` and its repo source have the same hash, the bundle output should be treated as correct even if their timestamps differ.

### Why `/tmp` Timestamps Can Remain Older Than Expected

If `/tmp/gighive-one-shot-bundle` already exists from an earlier non-check run, an output file inside it can retain an older timestamp when the later run does not need to materially rewrite that file.

That means an older `mtime` in `/tmp` can simply reflect the last time that destination file instance was actually rewritten, rather than indicating a current content mismatch.

### Timestamp Preservation (Implemented)

Timestamp preservation for direct-copy (non-template) files is implemented in `output_bundle.yml`. After copying a non-template file into `/tmp/gighive-one-shot-bundle`, the role sets the destination file's `modification_time` to match the source file's `mtime`.

This applies narrowly to direct-copy files only:

- direct-copy files sourced from repo-controlled non-template files have their source `mtime` preserved
- template-rendered outputs are not treated as timestamp-equivalent to their `.j2` sources (rendered files have the mtime of the render run)

As a result, `mtime` equality is a reliable signal for copied files but not for rendered outputs.

## Current Special Handling Already Added

A special case was added for:

- `apache/externalConfigs/gighive.htpasswd`

Instead of rendering `gighive.htpasswd.j2` during output generation, the current implementation copies the already materialized file from the checked-out bundle into `/tmp`.

This was done because:

- the one-shot bundle is pre-rendered
- the checked-out bundle already contains the correct file
- rendering the template directly on localhost introduced an unnecessary `passlib` dependency

## Current Validation Caveat

The current `/tmp` validation runs and reports the four categories, but it still uses `mtime:size` style manifest comparison.

That means a freshly copied output bundle can appear as if every file changed even when:

- the file set is correct
- the paths are correct
- the contents are effectively what we intended to export

This happens because newly copied files in `/tmp` naturally have different mtimes.

As a result, the current validation is useful for:

- confirming structural completeness
- confirming no paths are missing or extra

but it is not yet ideal for true content-equivalence validation of a fresh output directory.

## Current Observed Result

A recent successful run showed:

- the playbook completed successfully
- `/tmp/gighive-one-shot-bundle` was produced
- the output validation reported:
  - `missing_from_bundle: 0`
  - `extra_in_bundle: 0`
  - `changed_between_source_and_bundle: 633`
  - `files_that_did_not_change: 0`

That result strongly suggests:

- the output bundle structure is complete
- the comparison rule is currently too sensitive for fresh output validation

## Recommended Next Refinement

The current direction should be:

- keep direct source-to-bundle monitoring
- keep the four-category summary
- increasingly treat the checked-out one-shot bundle as the authoritative source for pre-rendered bundle outputs
- continue using source mapping for drift detection so template changes remain visible

For future refinement, the `/tmp` validation should be updated to use content hashing instead of `mtime:size` if true equivalence validation is needed.

## Operational Process

The intended operating model for the new method is:

1. modify source-controlled repo inputs as needed
2. run the one-shot bundle playbook in monitor/output mode
3. inspect the four-category drift summary for the checked-out bundle
4. if repo template inputs changed, manually refresh the corresponding pre-rendered bundle files from the staging VM as needed
5. inspect `/tmp/gighive-one-shot-bundle`
6. test the fresh output bundle
7. after successful testing, continue deprecating the older one-shot bundle handling

## Example Test Command

The current flow uses the existing tagged `ansible-playbook` workflow. The standard tags are:

- `set_targets`
- `one_shot_bundle`

This runs the full flow: monitor, output `/tmp/gighive-one-shot-bundle`, archive to `.tgz`. There is no `one_shot_bundle_monitor_only` flag. To re-archive an already-built `/tmp` output without rebuilding it, use `--tags set_targets,one_shot_bundle_archive`.

## Why the New Method Is Better

The newer method is better because it is:

- simpler
- more direct
- easier to inspect
- easier to reason about
- closer to the actual bundle paths that matter

Instead of relying on older indirect mechanisms, it directly answers:

- what the repo says the bundle should contain
- what the checked-out bundle currently contains
- where they differ
- what a fresh output bundle looks like

## Deprecation Direction

The intent is to deprecate the older one-shot-bundle files/workflow once testing confirms the new approach is reliable.

Deprecation should be based on successful real-world testing of:

- drift detection
- fresh `/tmp` output production
- downstream usability of the generated bundle

Until that testing is complete, the older implementation should be treated as still available but increasingly superseded by this newer direct-monitor/direct-output model.

## Summary

The new one-shot-bundle process does the following:

- monitors repo inputs directly against the checked-out bundle
- reports four clear drift categories
- emits a fresh test bundle to `/tmp/gighive-one-shot-bundle`
- supports a simpler operational workflow
- preserves visibility into when repo-side template sources changed
- supports eventual deprecation of the older one-shot-bundle process after testing succeeds

# Process: Test Bundle Switch for `gighive2`

## Assumptions

- A new bundle has been created.

```bash
tar -czf gighive-one-shot-bundle.tgz gighive-one-shot-bundle/
```
- A SHA file has been generated for that bundle.

```bash
sha256sum gighive-one-shot-bundle.tgz > gighive-one-shot-bundle.tgz.sha256
```
- The bundle and SHA file have been copied to the staging server with:

```bash
scp gighive-one-shot-bundle.tgz* ubuntu@stagingvm.gighive.internal:/home/ubuntu/gighive/ansible/roles/docker/files/apache/downloads
```

## How to Run

### Recommended sanity checks

Repeat `status`:

```bash
ansible-playbook -K ansible/playbooks/switch_runtime.yml \
  -i ansible/inventories/inventory_gighive2.yml \
  -e switch_target_mode=status
```

Repeat the current target mode twice.

If you are currently on bundle:

```bash
ansible-playbook -K ansible/playbooks/switch_runtime.yml \
  -i ansible/inventories/inventory_gighive2.yml \
  -e switch_target_mode=gighive_bundle
```

Run it twice and confirm the second run does less work.

If you are currently on VM:

```bash
ansible-playbook -K ansible/playbooks/switch_runtime.yml \
  -i ansible/inventories/inventory_gighive2.yml \
  -e switch_target_mode=gighive2_vm
```

Run it twice and confirm the second run reports fewer changes.