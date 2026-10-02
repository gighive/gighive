# Ansible Playbook Mac Migration — Runtime Errors and Fixes

**Date:** 2026-09-03
**System:** MacBook M4 Pro (Apple Silicon, macOS 15 / Darwin 24.6.0)
**Affected playbook:** `ansible/playbooks/site.yml` — full VM provisioning run against `gighive2`
**Prior controller:** Pop!_OS Intel tower (amd64 Linux)

---

## Problem Summary

Migrating the Ansible controller from a Linux tower to a MacBook M4 Pro exposed four blocking
issues — none caused by the target VM or playbook logic, all caused by the new controller environment.

| # | Problem | Business Impact |
|---|---|---|
| 1 | **Missing Ansible collections** — A fresh macOS Ansible install ships only `ansible-core`; three required community collections were absent. | Playbook failed immediately on first run; no provisioning work was possible until resolved. |
| 2 | **Wrong module FQCNs in role tasks** — Eight tasks referenced incorrect module namespaces silently tolerated by the old Linux Ansible version. | Playbook failed mid-run even after fixing Problem 1; affected all environments, not just Mac. |
| 3 | **macOS ARM64 not recognised by architecture detection** — The playbook mapped only Linux ARM64 (`aarch64`), so Apple Silicon reported as x86 and created an incompatible VM. | VirtualBox refused to start the VM; all provisioned infrastructure was unusable until the bad VM was deleted and re-created. |
| 4 | **Legacy VGA controller incompatible with Apple Silicon VirtualBox** — VirtualBox assigned ARM64 VMs a VGA device that conflicts with the ARM64 memory layout. | Even after fixing Problem 3, the VM would not start; required an additional playbook change to disable the graphics controller on ARM64. |

Successful result after all fixes:

```
gighive_vm : ok=565  changed=130  unreachable=0  failed=0  skipped=86
Playbook run took 0 days, 0 hours, 8 minutes, 16 seconds
```

---

## Root Cause Overview

Pop!_OS had accumulated Ansible collections and was running a version of Ansible permissive
enough to silently route incorrect FQCNs to the right modules. The Mac had:

- A fresh Ansible install with no collections pre-installed.
- A stricter FQCN resolver that rejected incorrect collection namespaces rather than falling
  back silently.
- Apple Silicon ARM hardware, which exposed architecture-specific VirtualBox behaviours
  not present on x86.

---

## Problems Encountered

### Problem 1 — Missing Ansible collections

**Error (first hit):**
```
ERROR! couldn't resolve module/action 'community.general.homebrew'.
```
**Error (second hit):**
```
ERROR! couldn't resolve module/action 'ansible.builtin.synchronize'.
```
**Error (third hit):**
```
ERROR! couldn't resolve module/action 'community.docker.docker_compose_v2'.
```

A fresh `pip install ansible` (or `brew install ansible`) installs only `ansible-core`.
The following collections are not bundled and must be installed separately:

| Collection | Used by |
|---|---|
| `community.general` | `cloud_init` Homebrew prereq, `security_basic_auth` htpasswd |
| `ansible.posix` | `base` role `synchronize` tasks |
| `community.docker` | `docker` role `docker_compose_v2`, `docker_container`, `docker_image`, etc. |

Pop!_OS had all three installed (likely from a prior `ansible-galaxy collection install` or
distro package). The Mac had none.

---

### Problem 2 — Wrong FQCNs in existing role tasks

Even after installing `ansible.posix`, the `synchronize` error persisted because one of the
five `synchronize` calls in `base/tasks/main.yml` used the incorrect FQCN
`ansible.builtin.synchronize`. Similarly, three calls in `security_basic_auth/tasks/main.yml`
used `ansible.builtin.htpasswd`.

Neither module exists in `ansible.builtin`. Both were silently routed on Pop!_OS via the
older Ansible collection compatibility layer; the stricter Mac resolver rejected them outright.

| File | Wrong FQCN | Correct FQCN |
|---|---|---|
| `ansible/roles/base/tasks/main.yml` (×5) | `ansible.builtin.synchronize` / unqualified `synchronize` | `ansible.posix.synchronize` |
| `ansible/roles/security_basic_auth/tasks/main.yml` (×3) | `ansible.builtin.htpasswd` | `community.general.htpasswd` |

This is a platform-agnostic correctness fix — it applies to Pop!_OS as well.

---

### Problem 3 — Architecture detection did not handle macOS ARM64

**Error:**
```
VBoxManage: error: Cannot run the machine because its platform architecture x86
is not supported on ARM
```

The `cloud_init` role detects the control machine architecture via `uname -m` and uses the
result to select the correct Ubuntu cloud image, VirtualBox ostype, and conversion tool.
The detection mapped the output to `arm64` only when `uname -m` returned `aarch64`.

| Platform | `uname -m` output |
|---|---|
| Linux ARM64 | `aarch64` |
| macOS ARM64 (Apple Silicon) | `arm64` |

On the Mac, `uname -m` returns `arm64`, which did not match `aarch64`, so `cloud_arch` was
set to `amd64`. The VM was created with ostype `Ubuntu_64` (x86), which VirtualBox on
Apple Silicon refused to start.

---

### Problem 4 — Legacy VGA controller incompatible with Apple Silicon VirtualBox

**Error (after Problem 3 was fixed and an ARM64 VM was created):**
```
VBoxManage: error: Failed to construct device 'vga' instance #0 (VERR_PGM_RAM_CONFLICT)
VBoxManage: error: Details: code NS_ERROR_FAILURE (0x80004005)
```

VirtualBox on Apple Silicon assigns ARM64 guests a default VGA graphics controller (`vga`
device). The legacy VGA device uses a fixed RAM region that conflicts with the ARM64 physical
memory layout, producing `VERR_PGM_RAM_CONFLICT` at VM startup. VirtualBox on Apple Silicon
does not support the legacy VGA device for ARM64 guests.

Since the VM is started headless (`--type headless`), no graphics output is needed at all.

---

## Fixes Applied

**Problem 1** — Install all required collections before the first playbook run:

```bash
ansible-galaxy collection install community.general ansible.posix community.docker
```

**Problem 2** — Updated all eight incorrect calls at source:

- `ansible/roles/base/tasks/main.yml` (×5): `ansible.builtin.synchronize` and unqualified
  `synchronize` → `ansible.posix.synchronize`
- `ansible/roles/security_basic_auth/tasks/main.yml` (×3): `ansible.builtin.htpasswd`
  → `community.general.htpasswd`

**Problem 3** — Updated the `Set cloud_arch fact` task in `cloud_init/tasks/main.yml`,
`nat.yml`, and `test.yml` to accept both Linux and macOS ARM64 output:

```yaml
cloud_arch: "{{ 'arm64' if ctrl_uname.stdout | trim in ['aarch64', 'arm64'] else 'amd64' }}"
```

The assertion `fail_msg` was also updated to mention both `aarch64` (Linux ARM64) and
`arm64` (macOS ARM64) as valid inputs.

Note: any broken VM created before this fix must be deleted before re-running:

```bash
VBoxManage unregistervm gighive2 --delete
```

**Problem 4** — Added a `modifyvm` task in `cloud_init/tasks/main.yml` immediately after
`Set VM memory and CPUs`, guarded to ARM64 only:

```yaml
- name: Disable legacy VGA controller (ARM64 — incompatible with Apple Silicon)
  when: cloud_arch == 'arm64'
  command: VBoxManage modifyvm "{{ vm_name }}" --graphicscontroller none
```

Skipped entirely on amd64 Linux; the existing Pop!_OS workflow is unaffected.

Note: any broken VM created before this fix must be deleted before re-running:

```bash
VBoxManage unregistervm gighive2 --delete
```

---

## New Mac Controller Checklist

If setting up Ansible on a new Mac controller from scratch, run the following before the
first playbook execution:

```bash
# Install required collections (not bundled with ansible-core)
ansible-galaxy collection install community.general ansible.posix community.docker

# Verify VirtualBox is installed and VBoxManage is on PATH
which VBoxManage

# Verify Homebrew is installed (required for mkisofs and qemu-img)
which brew

# The cloud_init pre-flight tasks will install mkisofs and qemu-img via Homebrew
# automatically if they are missing, but Homebrew itself must be present first.
```

Problems 2, 3, and 4 are now fixed in the playbooks themselves, so they will not recur on
a new controller. Problem 1 requires the manual collection install above — there is no
self-bootstrapping mechanism for missing collections since Ansible cannot use a module from
a collection that has not yet been installed.
