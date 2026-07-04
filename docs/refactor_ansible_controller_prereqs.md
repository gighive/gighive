# Refactor: Centralize Ansible Controller Prerequisites

## Status
Deferred — Option A (self-contained install inside `playwright_admin_tests` role) was applied as the immediate fix.

## Problem

The `playwright_admin_tests` role runs all tasks with `delegate_to: localhost`, meaning they execute on whichever machine is running the Ansible playbook (the controller — e.g. `sodo@lab` or `sodo@pop-os`), not on the target VM. Node.js must therefore be installed on the **controller**, not on `gighive_vm`.

Currently:
- The `base` role installs Node.js on `target_vms` (the VM) — correct for the VM, useless for the controller.
- The `installprerequisites` role installs controller packages, but is only invoked by `install_controller.yml`, a separate one-time setup playbook not called by `site.yml`.
- Result: any controller machine (e.g. lab baremetal) that has never run `install_controller.yml` will be missing Node.js, causing the playwright test play to fail.

## Option B — Centralized fix (this refactor)

Wire controller Node.js installation into the existing `installprerequisites` role and ensure `site.yml` invokes it on localhost before the playwright play runs.

### Step 1 — Add NodeSource setup to `installprerequisites/tasks/main.yml`

Add after the `Install controller base packages` task:

```yaml
- name: Add NodeSource LTS repository on controller
  ansible.builtin.shell:
    cmd: curl -fsSL {{ nodesource_setup_url }} | bash -
  args:
    creates: /etc/apt/sources.list.d/nodesource.list
  become: true

- name: Install Node.js (LTS) on controller
  ansible.builtin.apt:
    name: nodejs
    state: present
    update_cache: yes
  become: true
```

`nodesource_setup_url` is defined in `ansible/inventories/group_vars/all.yml` — no URL hardcoding in the role.

### Step 2 — Add `nodejs` to `installprerequisites/vars/main.yml`

This makes the intent explicit for anyone reading the var file:

```yaml
base_packages:
  - python3
  - python3-pip
  - python3-venv
  - python3-yaml
  - curl
  - gnupg
  - ca-certificates
  - lsb-release
  - apt-transport-https
  - mysql-client
  # nodejs is installed separately via NodeSource (see tasks below base_packages)
```

### Step 3 — Add a dedicated play in `site.yml` before the playwright role

Insert a new play between the main `target_vms` play and `postflight varscope`:

```yaml
- name: Ensure controller prerequisites are present (Node.js for Playwright)
  hosts: localhost
  gather_facts: false
  become: true
  tags: [ playwright_admin_tests ]
  when: run_playwright_admin_tests | default(false) | bool
  roles:
    - role: installprerequisites
      vars:
        install_terraform: false
        install_azure_cli: false
        install_virtualbox: false
        ensure_required_collections: false
```

This ensures that whenever the test tag is active, the controller is bootstrapped first — whether the runner is lab, staging, or pop-os.

## Files to change

| File | Change |
|---|---|
| `ansible/roles/installprerequisites/tasks/main.yml` | Add NodeSource + nodejs install tasks |
| `ansible/roles/installprerequisites/vars/main.yml` | Add clarifying comment about nodejs |
| `ansible/playbooks/site.yml` | Add localhost play before postflight, tagged `playwright_admin_tests` |

## Why this is better long-term

- Controller prerequisites live in one place (`installprerequisites`) — no duplication across roles that need Node.
- Any future controller-side tool (e.g. Playwright, k6, etc.) gets added to `installprerequisites` once.
- `install_controller.yml` and `site.yml` stay in sync automatically.
- The `playwright_admin_tests` role reverts to its original responsibility: running tests, not installing system packages.

## Rollback of Option A

When this refactor is applied, remove the two NodeSource tasks added to
`ansible/roles/playwright_admin_tests/tasks/main.yml` (lines 12–26).
