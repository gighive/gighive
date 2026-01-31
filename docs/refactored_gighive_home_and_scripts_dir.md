---
title: Refactor gighive_home and scripts_dir
---

# Background / Problem Statement

Historically, the Ansible codebase has mixed two concepts:

- Controller-side repo location (where you run `ansible-playbook` from).
- Guest/VM repo location (where the GigHive repo lives on the VM).

The initial approach relied on a controller environment variable `GIGHIVE_HOME`, and a VM-side convention that the repo lived under `~/scripts/gighive` (`scripts_dir`). This became problematic because:

- Requiring users to manually export `GIGHIVE_HOME` is brittle.
- Non-interactive Ansible runs do not reliably see user shell exports.
- Some installs place the repo somewhere other than `~/scripts/gighive`.
- The “marker file” feature should land in the actual repo directory on the guest.

To make installs simpler and the automation more reliable, we are moving toward a single Ansible variable for the guest repo root:

- `gighive_home` (VM repo root), defaulting to `~/gighive`.

# What we changed already

## 1) Removed controller `GIGHIVE_HOME` requirement

The codebase had a controller preflight check and group_vars dependency on `lookup('env','GIGHIVE_HOME')`.

Changes made:

- `ansible/playbooks/site.yml`
  - Removed the “Preflight require GIGHIVE_HOME” hard-fail.
  - Kept SSH public key sanity checks.

- `ansible/inventories/group_vars/all.yml`
  - `repo_root` now derives from `playbook_dir` only.

## 2) Introduced `gighive_home` for VM repo root

- `ansible/inventories/group_vars/all.yml`
  - Added default:
    - `gighive_home: "{{ ansible_env.HOME }}/gighive"`

This is intended to be the canonical path for the GigHive repo on the guest.

## 3) Added base-role validation of `gighive_home` via `VERSION`

Added idempotent, best-practice validation tasks near the end of:

- `ansible/roles/base/tasks/main.yml`

Validation logic:

- Assert `gighive_home` is defined and non-empty (`ansible.builtin.assert`).
- Verify `{{ gighive_home }}/VERSION` exists (`ansible.builtin.stat`).
- Fail fast if missing (`ansible.builtin.fail`).
- Read `VERSION` without shelling out (`ansible.builtin.slurp`).
- Print resolved `gighive_home` and `VERSION` for log visibility (`ansible.builtin.debug`).

This ensures playbook runs fail clearly when the repo is not cloned where expected.

## 4) Marker file on guest

We implemented a “last run” marker file on the guest that:

- Uses an environment token derived from inventory groups, excluding umbrella groups.
- Uses the playbook run date `YYYYMMDD`.
- Writes into `{{ gighive_home }}`.
- Stores controller git details in the file body.

Current marker behavior (base role):

- Controller:
  - `git log -1 --no-color` with `delegate_to: localhost`, `run_once: true`, `changed_when: false`.
- Guest:
  - `copy:` to:
    - `{{ gighive_home }}/ansible-playbook-{{ playbook_environment_token }}-lastrun-{{ playbook_run_date.stdout }}.log`
  - with the `git log -1` output as file content.

This is idempotent: the marker file only changes when the controller’s HEAD commit changes.

# Current Known Follow-ups / Backlog

- Update/remove the stale comment in `ansible/playbooks/site.yml` that still mentions `$GIGHIVE_HOME`.

# Planned refactor: retire `scripts_dir` in favor of `gighive_home`

## Why

`scripts_dir` is currently used only to:

- Create a directory on the guest.
- Set ownership/permissions.
- `synchronize` the controller repo into that location.

Now that `gighive_home` is intended to be the canonical guest repo root, leaving `scripts_dir` in place creates a “split brain”:

- Ansible sync might populate `scripts_dir`.
- Validation/marker expects the repo at `gighive_home`.

These must converge.

## Proposed migration steps

### Phase 1 (safe / low risk): keep `scripts_dir` as a deprecated alias

- In `ansible/playbooks/site.yml`:
  - Set `scripts_dir: "{{ gighive_home }}"` (or remove `scripts_dir` and change downstream vars).

This preserves backwards compatibility for any tasks still using `scripts_dir` while making it point at the canonical location.

### Phase 2: update all base role `sync_scripts` tasks

In `ansible/roles/base/tasks/main.yml`:

- Replace `scripts_dir` references with `gighive_home`:
  - ensure directory exists
  - ensure ownership/mode
  - synchronize destination

### Phase 3: update derived variables in `site.yml`

In `ansible/playbooks/site.yml` pre_tasks:

- Update variables that derive from `scripts_dir` to derive from `gighive_home` instead.
  - Example: `docker_dir` currently derives from `scripts_dir`.

### Phase 4: update managed `.bashrc`

The base role currently hardcodes:

- `export GIGHIVE_HOME="$HOME/scripts/gighive"`

Once the repo root is canonicalized as `gighive_home`, change that to:

- `export GIGHIVE_HOME="{{ gighive_home }}"`

So interactive shells match what Ansible expects.

### Phase 5: verification

- Grep for `scripts_dir` usage in `**/*.yml`, `**/*.yaml`, `**/*.j2`.
- Run a test playbook run on at least:
  - `inventory_virtualbox.yml` (gighive)
  - `inventory_gighive2.yml`
  - `inventory_prod.yml`

# Notes / Constraints

- All new tasks should be idempotent.
- Prefer `assert`/`stat`/`slurp` for validation rather than shelling out.
- Prefer `copy` for the marker so contents are deterministic and update only when needed.
- Inventory “environment token” should avoid umbrella groups like `target_vms`.
