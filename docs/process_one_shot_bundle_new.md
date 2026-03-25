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

## Current Roles of the Main Files

### `ansible/roles/one_shot_bundle/tasks/main.yml`

This file now runs the one-shot bundle workflow in this order for controller source mode:

1. `monitor.yml`
2. `output_bundle.yml`
3. `rebuild.yml` only when monitor-only mode is disabled
4. `publish.yml` only when monitor-only mode is disabled

That means the existing tagged playbook run can now:

- detect drift
- emit a fresh `/tmp` bundle
- avoid rebuild/publish when `one_shot_bundle_monitor_only: true`

### `ansible/roles/one_shot_bundle/tasks/monitor.yml`

This file performs direct source-to-bundle drift detection.

It scans repository input paths, maps each input file into the bundle-relative location it should correspond to, and then compares that expected manifest to the checked-out bundle directory.

### `ansible/roles/one_shot_bundle/tasks/output_bundle.yml`

This file creates a fresh output bundle at:

- `/tmp/gighive-one-shot-bundle`

It currently uses the same path-mapping logic as the monitor so that the output structure follows the same bundle-relative layout.

It also performs a post-output validation pass using the same four categories.

## Source Inputs Included in Monitoring

The monitored source inputs currently include the repo-controlled inputs that feed the bundle structure, including:

- `ansible/roles/docker/templates`
- `ansible/roles/docker/files/one_shot_bundle`
- `ansible/roles/docker/files/apache`
- `ansible/roles/docker/files/mysql/externalConfigs`
- `ansible/roles/docker/files/tusd/hooks`
- `assets/audio`
- `assets/video`

This includes `mysql/externalConfigs/prepped_csvs`, which is intentionally included in the monitoring scope.

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

The current test flow uses the existing playbook command with the one-shot-bundle tags.

The exact command may vary by current operator practice, but the tested path has been the existing tagged `ansible-playbook` workflow that includes:

- `set_targets`
- `one_shot_bundle`

with `one_shot_bundle_monitor_only: true` so rebuild/publish are skipped.

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
