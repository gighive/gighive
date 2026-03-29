# Refactor Plan: Remove Vestigial One-Shot Bundle Branching

## Purpose

This document captures the planned refactor to simplify the `one_shot_bundle` role by removing vestigial controller/url branching and deleting configuration that is no longer needed by the active implementation.

This is a planning document only. It does not itself make any implementation changes.

## Rationale for the Change

The current active one-shot-bundle implementation is effectively a single workflow:

1. run `monitor.yml`
2. run `output_bundle.yml`
3. report the final summary

The implementation used in current operator practice is the direct controller-side monitoring and `/tmp` output flow.

At this point:

- `monitor.yml` does not contain controller/url mode branching
- `output_bundle.yml` does not contain controller/url mode branching
- the only remaining controller/url split exists in `main.yml`

That remaining split is vestigial relative to the simplified workflow now being used.

Removing it would:

- make the role easier to understand
- reduce stale configuration in inventories and group vars
- align the code with the actual command currently being used for one-shot-bundle runs
- reduce the chance that an old source-mode setting causes confusion later

## Current Operator Usage Assumption

The refactor is based on the current operator workflow of running the one-shot bundle directly from the playbook, for example:

```bash
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --tags one_shot_bundle --check --diff
```

This supports the simplification toward a single active flow.

## Files Planned to Change

- `ansible/roles/one_shot_bundle/tasks/main.yml`
- `ansible/inventories/inventory_bootstrap.yml`
- `ansible/inventories/inventory_lab.yml`
- `ansible/inventories/inventory_staging_telemetry.yml`
- `ansible/inventories/group_vars/gighive/gighive.yml`
- `ansible/inventories/group_vars/gighive2/gighive2.yml`
- `ansible/inventories/group_vars/prod/prod.yml`

## Files Already Deleted (Manual)

During review, three orphaned legacy task files were identified in `ansible/roles/docker/tasks/` that still referenced vars being removed. These files had no active `include_tasks` callers anywhere in the ansible directory and were manually deleted before implementation:

- `ansible/roles/docker/tasks/one_shot_bundle_monitor.yml`
- `ansible/roles/docker/tasks/one_shot_bundle_rebuild.yml`
- `ansible/roles/docker/tasks/one_shot_bundle_publish.yml`

These files represented the old fingerprint-based monitoring, interactive rebuild prompt, and publish-to-downloads flow that the simplified three-file approach replaced.

## Planned Changes by File

### `ansible/roles/one_shot_bundle/tasks/main.yml`

Planned change:

- remove the `when: (one_shot_bundle_source | default('controller')) == 'controller'`
- remove the separate `url` branch
- remove the `get_url` task that uses `one_shot_bundle_url` and `one_shot_bundle_filename`
- leave a single unconditional controller-side workflow consisting of:
  - `monitor.yml`
  - `output_bundle.yml`
  - final summary output

Result:

- `main.yml` becomes the simple entrypoint for the three active one-shot-bundle YAMLs

### `ansible/inventories/inventory_bootstrap.yml`

Planned change:

- remove `one_shot_bundle_source: controller`

Expected result:

- inventory no longer declares a source mode that the role no longer uses

### `ansible/inventories/inventory_lab.yml`

Planned change:

- remove `one_shot_bundle_source: url`

Expected result:

- inventory no longer declares a source mode that the role no longer uses

### `ansible/inventories/inventory_staging_telemetry.yml`

Planned change:

- remove `one_shot_bundle_source: url`

Expected result:

- inventory no longer declares a source mode that the role no longer uses

### `ansible/inventories/group_vars/gighive/gighive.yml`

Planned removals:

- `one_shot_bundle_filename`
- `one_shot_bundle_url`
- `one_shot_bundle_controller_src`
- `one_shot_bundle_inputs_fingerprint_path`
- `one_shot_bundle_monitor_only`

Planned retained vars:

- `one_shot_bundle_bundle_dir`
- `one_shot_bundle_input_paths`

Expected result:

- group vars retain only the values needed by the active monitor/output implementation

### `ansible/inventories/group_vars/gighive2/gighive2.yml`

Planned removals:

- `one_shot_bundle_filename`
- `one_shot_bundle_url`
- `one_shot_bundle_controller_src`
- `one_shot_bundle_inputs_fingerprint_path`
- `one_shot_bundle_monitor_only`

Planned retained vars:

- `one_shot_bundle_bundle_dir`
- `one_shot_bundle_input_paths`

Expected result:

- group vars retain only the values needed by the active monitor/output implementation

### `ansible/inventories/group_vars/prod/prod.yml`

Planned removals:

- `one_shot_bundle_filename`
- `one_shot_bundle_url`
- `one_shot_bundle_controller_src`
- `one_shot_bundle_inputs_fingerprint_path`
- `one_shot_bundle_monitor_only`

Planned retained vars:

- `one_shot_bundle_bundle_dir`
- `one_shot_bundle_input_paths`

Expected result:

- group vars retain only the values needed by the active monitor/output implementation

## Additional Change: Remove `serve_one_shot_installer_downloads` Gate

After initial implementation, the decision was made to also remove `serve_one_shot_installer_downloads`.

Rationale:

- the project is always operated with explicit `--tags` or `--skip-tags` flags on the `ansible-playbook` command
- `tags: [one_shot_bundle]` already exists on the role in `site.yml` and serves as the functional gate
- the `when:` condition and inventory var add indirection without operational value

Additional files changed:

- `ansible/playbooks/site.yml` — removed `when: serve_one_shot_installer_downloads | default(false)` from the `one_shot_bundle` role
- `ansible/inventories/inventory_bootstrap.yml` — removed `serve_one_shot_installer_downloads: true`
- `ansible/inventories/inventory_lab.yml` — removed `serve_one_shot_installer_downloads: true`
- `ansible/inventories/inventory_staging_telemetry.yml` — removed `serve_one_shot_installer_downloads: true`

## Expected End State

After the planned refactor:

- the one-shot-bundle role will conceptually rely on only three active YAMLs:
  - `main.yml`
  - `monitor.yml`
  - `output_bundle.yml`
- controller/url source-mode branching will be removed
- inventories will no longer carry `one_shot_bundle_source`
- group vars will no longer carry old URL-source or rebuild/publish-era one-shot-bundle variables
- the `serve_one_shot_installer_downloads` gate has been removed from `site.yml` and all inventory files
- `tags: [one_shot_bundle]` in `site.yml` is the sole gate; use `--tags` or `--skip-tags` to control execution
- three orphaned legacy docker task files (`one_shot_bundle_monitor.yml`, `one_shot_bundle_rebuild.yml`, `one_shot_bundle_publish.yml`) have been deleted
- if `./install.sh` has been run from `/tmp/gighive-one-shot-bundle`, docker may create root-owned content there; manually delete the directory before the next bundle rebuild

## Implementation Status

The three legacy docker task files were manually deleted before implementation.

If `./install.sh` is run from `/tmp/gighive-one-shot-bundle`, docker container startup can create root-owned directories inside that path (e.g. `mysql/dbScripts/backups`). The cleanup task in `output_bundle.yml` runs as the current user and cannot remove root-owned content. Before the next bundle rebuild, run:

```bash
sudo rm -rf /tmp/gighive-one-shot-bundle
```

All remaining planned changes were implemented after explicit approval.
