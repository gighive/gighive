# Refactor: Standardise Ansible and Python Versions Across Environments

## Status

Open — surfaced during Phase 3 (media storage refactor) rollout on 2026-08-16.

---

## Problem

The Ansible control machine version differs between environments (dev vs lab), causing
playbook behaviour to diverge in ways that are hard to detect until a task fails in a
later environment. The specific trigger was the `MODULE_STRICT_UTF8_RESPONSE` default
changing between Ansible releases — dev tolerated a binary body passed through the `uri`
module, lab rejected it with a codec error.

### Observed symptom

```
[ERROR]: Task failed: Refusing to deserialize an invalid UTF8 string value:
'utf-8' codec can't encode character '\udcac' in position 25: surrogates not allowed
Task failed. Origin: post_build_checks/tasks/main.yml:350
```

This error only appeared on lab (not dev) because lab's Ansible/Python is a newer version
with `MODULE_STRICT_UTF8_RESPONSE` enabled by default. The same task ran silently on dev.

### Why this matters

- Tasks that work on dev may fail on lab, staging, or prod if the controller version
  differs. The failure may be cryptic and unrelated to the actual application change.
- Version skew makes it impossible to confidently promote a build through environments —
  the test signal from dev is not fully representative of later environments.
- The current setup has no enforcement mechanism. Version drift accumulates silently.

### Current known state (2026-08-16)

| Controller machine | Ansible core | Python | Jinja2 | Environments controlled |
|--------------------|-------------|--------|--------|------------------------|
| `sodo@pop-os` `/home/sodo` — pipx (upgraded 2026-08-16) | **2.20.4** | 3.12.13 | 3.1.6 | **dev + prod** |
| `sodo@lab.gighive.internal` — pipx (upgraded 2026-08-16) | **2.20.4** | 3.12.3 | 3.1.6 | lab |
| `sodo@staging.gighive.internal` — pipx | **2.20.4** | 3.12.7 | 3.1.6 | staging |

Note: the macOS machine (`/Users/sodo`) is the IDE/workstation only — it does not run
Ansible playbooks against any environment.

pop-os was upgraded on 2026-08-16: old pip-installed ansible-core 2.17.12 (Python 3.10)
replaced with pipx-installed ansible-core==2.20.4 under Python 3.12 via pipx.

All three controllers are now at **2.20.4**. Standardisation complete (2026-08-16).

All controllers are now audited. The version spread is **2.17.12 → 2.20.4** — nearly
three minor versions. `MODULE_STRICT_UTF8_RESPONSE` became `true` by default in Ansible
core 2.19, which is why the binary PATCH body was accepted on dev and rejected on lab/staging.

- **dev + prod** (`pop-os`, 2.17.12) — oldest and most permissive; also on Python 3.10
  which caps ansible-core upgrades at 2.17.x via pip (2.18+ requires Python ≥ 3.11)
- **lab** (2.20.1) — first environment with strict UTF-8 enforcement; where the bug surfaced
- **staging** (2.20.4) — strictest; already at target

Any task that passes on dev/prod may silently fail on lab/staging. The target
standardisation version is **2.20.4**. Reaching it on pop-os requires upgrading Python
to 3.11+ first (Python 3.10 is the blocking constraint, not Ansible itself).

---

## Root cause

Ansible is installed via `pip --user` on pop-os (`/home/sodo/.local/bin/ansible`,
Python 3.10), and via `pipx` on lab and staging (Python 3.12). There is no shared version
pin and no mechanism to enforce parity across machines.

The `installprerequisites` role (`ansible/roles/installprerequisites/`) provisions
controller packages but does not pin Ansible to a specific version, and it is not
guaranteed to have been run on every controller with the same inputs.

---

## Options

### Option A — Pin via `requirements.txt` / `pipx inject` (recommended)

Commit a `controller-requirements.txt` (or `pyproject.toml`) to the repo that pins
`ansible-core`, `jinja2`, and key collections to exact versions. The
`installprerequisites` role installs from this file using `pipx install --editable` or
`pip install -r`.

```
# controller-requirements.txt
ansible-core==2.18.8
jinja2==3.1.6
```

The `installprerequisites` role gains a task:

```yaml
- name: Install pinned Ansible and dependencies on controller
  ansible.builtin.pip:
    requirements: "{{ repo_root }}/controller-requirements.txt"
    virtualenv: "{{ ansible_venv_path }}"
    virtualenv_command: python3 -m venv
  delegate_to: localhost
  become: false
```

**Pros:** Version is code-reviewed, diffed, and identical everywhere.  
**Cons:** Upgrading Ansible requires a deliberate PR; any controller running an older
pip/pipx may need manual bootstrapping the first time.

### Option B — Docker-based controller

Run Ansible from a pinned Docker image (`cytopia/ansible`, `willhallonline/ansible`, or
a custom image in the repo). All environments pull the same image tag.

```bash
docker run --rm -v $(pwd):/repo ghcr.io/gighive/ansible-controller:2.18.8 \
  ansible-playbook -i inventories/inventory_lab.yml playbooks/site.yml
```

**Pros:** Hermetically sealed — OS, Python, pip packages all pinned.  
**Cons:** Requires Docker on all controller machines; adds image build/publish overhead;
`delegate_to: localhost` tasks run inside the container, not on the host (may affect
SSH agent forwarding, file paths).

### Option C — Ansible version check at playbook start (immediate mitigation)

Add a pre-flight assertion to `site.yml` that fails fast if the Ansible core version is
outside the tested range. Does not fix the skew but prevents silent divergence.

```yaml
- name: Pre-flight version check
  hosts: localhost
  gather_facts: false
  tasks:
    - name: Assert Ansible core version is within tested range
      ansible.builtin.assert:
        that:
          - ansible_version.major == 2
          - ansible_version.minor == 18
        fail_msg: >
          Ansible core {{ ansible_version.full }} is not the tested version (2.18.x).
          Run controller-requirements.txt installation to align versions.
```

**Pros:** Zero-effort immediate guard; fails loudly before any damage is done.  
**Cons:** Does not fix the skew, only detects it. Must be updated when the tested version
is intentionally upgraded.

---

## Recommended approach

1. **Immediate:** Implement Option C (version assertion) in `site.yml` to catch further
   skew during the Phase 3 rollout.
2. **Short-term:** Implement Option A — commit `controller-requirements.txt` and update
   `installprerequisites` to install from it. Run on all controller machines.
3. **Long-term:** Evaluate Option B as part of any future CI/CD automation effort.

---

## Files to change

| File | Change |
|---|---|
| `controller-requirements.txt` (new) | Pin `ansible-core`, `jinja2`, key collections |
| `ansible/roles/installprerequisites/tasks/main.yml` | Install from requirements file |
| `ansible/playbooks/site.yml` | Add pre-flight version assertion play |
| This doc | Update version table after auditing all controllers |

---

## Related documentation

- `docs/refactor_ansible_controller_prereqs.md` — controller Node.js prerequisite problem
  (same root cause: controller state diverges across environments)
- `docs/refactor_storage_media_rest_endpoint_implementation.md` — Phase 3 build issues,
  "PATCH binary body — Ansible uri module rejects non-UTF-8" section
