# VirtualBox VM Autostart (Host Boot) Implementation

## Purpose

When the **VirtualBox host (controller)** reboots, VirtualBox VMs do not necessarily restart automatically. This can leave the GigHive VM(s) offline until a human manually starts them.

This change introduces an **idempotent host-boot autostart mechanism** so that, after a host reboot, selected GigHive VirtualBox VMs are started automatically.

Scope (explicit):

- The goal is **scenario A** from our discussion: **the VirtualBox host OS reboots**.
- This is **not** intended to manage in-guest services directly (e.g., Docker inside the VM), beyond whatever those services already do on VM boot.

## Why a systemd unit (Option 2)

We discussed two approaches:

- VirtualBox “autostart” feature (native)
- A dedicated **systemd unit** that runs `VBoxManage startvm` at boot

We chose the **systemd unit** approach because it is:

- More explicit and predictable on Ubuntu 22.04 / 24.04
- Easier to reason about and troubleshoot
- Easy to manage idempotently via Ansible

## Key design points

### Correct VirtualBox user context

`VBoxManage` interacts with the VM registry under a specific user’s VirtualBox home directory (typically `~/.config/VirtualBox`). If the unit runs as the wrong user (e.g., `root`), it may not see the expected VM definitions.

Therefore the unit must run as the **controller login user** (the person who runs the playbook / owns the VirtualBox VMs on the host).

Because controller plays may run with `become: true`, we cannot assume the effective user is the correct VM owner. The implementation will derive the controller login user using:

- `SUDO_USER` (preferred when sudo escalation is used)
- otherwise `USER`
- optionally (fallback) `id -un` executed with `become: false`

The unit will also set:

- `VBOX_USER_HOME` to the autostart user’s VirtualBox config directory (derived from that user’s home directory)

### Idempotency

The goal is that running the configuration multiple times (or booting repeatedly) is safe.

- The systemd unit will check whether the VM is already running before attempting to start it.
- Enabling/starting the unit instances via Ansible is inherently idempotent.

### Single-VM scope (why we do not loop inventory groups)

This implementation is intentionally **single-VM** right now.

- The role reads `enable_vbox_vm_autostart` and `vm_name` from the single VM host under the `target_vms` inventory group.
- It assumes the inventory group `target_vms` contains exactly one host.

This keeps the workflow simple while still allowing multi-VM support later if needed.

## Updated scope + what will be implemented

### 1) Group var placement (only one file)

- Modify only: `ansible/inventories/group_vars/gighive/gighive.yml`
- Add: `enable_vbox_vm_autostart: true`
- Optional: `vbox_autostart_debug: true` to enable verbose debugging output from the role
- No changes to any other `group_vars` files (you can add the var yourself later, per-VM)

### 2) New role (core functionality)

- Add: `ansible/roles/vbox_vm_autostart/tasks/main.yml`

Responsibilities:

- Derive `vbox_autostart_user` from the controller login user (prefer `SUDO_USER`, fallback `USER`, optional `id -un` with `become: false`)
- Resolve the autostart user’s `uid` and home directory (via `getent passwd`) to build stable `HOME`, `XDG_RUNTIME_DIR`, and `VBOX_USER_HOME` values for the systemd unit
- Install `/etc/systemd/system/gighive-vbox-autostart@.service` (systemd template unit)
- Run `systemctl daemon-reload`
- Derive the VM name from `vm_name` in `ansible/inventories/group_vars/gighive/gighive.yml` (single-VM model)
- Enable/start `gighive-vbox-autostart@<vm_name>.service` when `enable_vbox_vm_autostart: true`
- Print final confirmation output:
  - `systemctl status gighive-vbox-autostart@<vm_name>.service --no-pager`
  - `VBoxManage list runningvms`

### 3) New playbook (run anytime)

- Add: `ansible/playbooks/vbox_autostart.yml`

Runs the role on `hosts: baremetal` with `become: true` so autostart can be configured at any time.

### 4) Optional include in initial controller install playbook

- Modify: `ansible/playbooks/install_controller.yml`

Include the new role *optionally*, guarded by whether `enable_vbox_vm_autostart: true` is set for the single `gighive` host.

## Usage

### Enable during initial controller install

1. Set `enable_vbox_vm_autostart: true` in `ansible/inventories/group_vars/gighive/gighive.yml`.
2. Run controller install:

   - `ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/install_controller.yml --ask-become-pass`

### Enable sometime later

1. Set `enable_vbox_vm_autostart: true` in `ansible/inventories/group_vars/gighive/gighive.yml`.
2. Run:

   - `ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/vbox_autostart.yml --ask-become-pass`

## Example: enable autostart after initial install (gighive)

This section is an example workflow for the case where:

- The controller is already installed
- The `gighive` VM is already working
- You now want to enable host-boot autostart for `gighive`

### 1) Set the toggle for gighive

- Update: `ansible/inventories/group_vars/gighive/gighive.yml`
- Add:
  - `enable_vbox_vm_autostart: true`

### 2) Run the autostart playbook on the controller

Run:

- `ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/vbox_autostart.yml --ask-become-pass`

### 3) Validate

On the controller host, you can validate with:

- `systemctl status gighive-vbox-autostart@gighive.service --no-pager`
- `VBoxManage list runningvms`
