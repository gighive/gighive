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

- `VBOX_USER_HOME=%h/.config/VirtualBox`

### Idempotency

The goal is that running the configuration multiple times (or booting repeatedly) is safe.

- The systemd unit will check whether the VM is already running before attempting to start it.
- Enabling/starting the unit instances via Ansible is inherently idempotent.

### Why we loop through `groups['target_vms']`

The enablement toggle and VM name are **per VM**, not global.

Even if the variable name `enable_vbox_vm_autostart` exists in multiple `group_vars` files, Ansible variables are scoped **per host**. When running the controller play on the controller host (inventory host `baremetal`), there is no single global value of `enable_vbox_vm_autostart` to consult.

To decide which VMs should have autostart enabled, the controller tasks must:

- Iterate the inventory group `target_vms`
- Read each VM host’s variables via `hostvars[...]`, specifically:
  - `hostvars[vm_host].enable_vbox_vm_autostart`
  - `hostvars[vm_host].vm_name`

This yields the set of systemd unit instances to enable, e.g.:

- `gighive-vbox-autostart@gighive.service`
- `gighive-vbox-autostart@gighive2.service`

## Updated scope + what will be implemented

### 1) Group var placement (only one file)

- Modify only: `ansible/inventories/group_vars/gighive/gighive.yml`
- Add: `enable_vbox_vm_autostart: true`
- No changes to any other `group_vars` files (you can add the var yourself later, per-VM)

### 2) New role (core functionality)

- Add: `ansible/roles/vbox_vm_autostart/tasks/main.yml`

Responsibilities:

- Derive `vbox_autostart_user` from the controller login user (prefer `SUDO_USER`, fallback `USER`, optional `id -un` with `become: false`)
- Install `/etc/systemd/system/gighive-vbox-autostart@.service` (systemd template unit)
- Run `systemctl daemon-reload`
- Loop over `groups['target_vms']` and enable/start unit instances for VMs where:
  - `hostvars[item].enable_vbox_vm_autostart | bool` is true
  - the instance name uses `hostvars[item].vm_name`

### 3) New playbook (run anytime)

- Add: `ansible/playbooks/vbox_autostart.yml`

Runs the role on `hosts: baremetal` with `become: true` so autostart can be configured at any time.

### 4) Optional include in initial controller install playbook

- Modify: `ansible/playbooks/install_controller.yml`

Include the new role *optionally*, guarded by whether any host in `groups['target_vms']` has `enable_vbox_vm_autostart: true`.

## Usage

### Enable during initial controller install

1. Set `enable_vbox_vm_autostart: true` in the relevant VM’s `group_vars`.
2. Run controller install:

   - `ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/install_controller.yml --ask-become-pass`

### Enable sometime later

1. Set `enable_vbox_vm_autostart: true` in the relevant VM’s `group_vars`.
2. Run:

   - `ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/vbox_autostart.yml --ask-become-pass`

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

- `ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/vbox_autostart.yml --ask-become-pass`

### 3) Validate

On the controller host, you can validate with:

- `systemctl status gighive-vbox-autostart@gighive.service`
- `VBoxManage list runningvms`
