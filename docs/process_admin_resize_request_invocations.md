# Disk Resize Request: Admin Invocations

## Rationale

GigHive VMs are provisioned with a default virtual disk size of 64 GB. Over time,
as media assets accumulate, the disk may need to grow. The resize workflow is a
two-step process:

1. **Write the request** — an admin uses Section E of the System & Recovery admin
   page to write a JSON resize request file onto the VM's filesystem.
2. **Execute the resize** — the admin runs `run_resize_request.sh` on the
   VirtualBox host to consume the request, power off the VM, grow the VDI via
   `VBoxManage modifymedium`, re-attach and start the VM, then expand the
   partition and filesystem live with `growpart` + `resize2fs`.

> **Note:** The resize only grows the virtual disk. It **DOES NOT SHRINK** it.
> `VBoxManage modifymedium --resizebyte` is a grow-only operation.

---

## Standard Command Structure

Run from the **VirtualBox host** (not from inside the VM), `cd` into the gighive
repo root first:

```bash
./ansible/roles/docker/files/apache/webroot/tools/run_resize_request.sh \
  -i <inventory_file> \
  --request-host <vm_fqdn_or_ip> \
  --request-inventory-host <ansible_host_name> \
  --latest [--dry-run]
```

| Argument | Purpose |
|---|---|
| `-i <inventory_file>` | Ansible inventory to use for both var lookups and the playbook run |
| `--request-host` | SSH target to reach the VM's filesystem and read the request file |
| `--request-inventory-host` | Ansible host name (not group name) — used as `--limit` for the playbook and for SSH var lookups |
| `--latest` | Automatically pick the most recent unprocessed request file |
| `--dry-run` | Print what would execute without making any changes |

### Examples

```bash
# Standard end-user (gighive) — the most common case
./ansible/roles/docker/files/apache/webroot/tools/run_resize_request.sh \
  -i ansible/inventories/inventory_gighive.yml \
  --request-host gighive.gighive.internal \
  --request-inventory-host gighive_vm \
  --latest

# gighive2 (dev/test VM)
./ansible/roles/docker/files/apache/webroot/tools/run_resize_request.sh \
  -i ansible/inventories/inventory_gighive2.yml \
  --request-host gighive2.gighive.internal \
  --request-inventory-host gighive_vm \
  --latest

# lab VM
./ansible/roles/docker/files/apache/webroot/tools/run_resize_request.sh \
  -i ansible/inventories/inventory_lab.yml \
  --request-host labvm.gighive.internal \
  --request-inventory-host gighive_vm \
  --latest
```

> **Do not run with `sudo`.** VBoxManage reads from `$HOME/.config/VirtualBox`;
> running as root points it at `/root/.config/VirtualBox` and breaks the VM lookup.

---

## Common pitfalls

### SSH host key verification failure (silent)

The script suppresses all SSH stderr with `2>/dev/null || true` when fetching the
latest request file. This means a stale or mismatched entry in `~/.ssh/known_hosts`
produces a **silent failure** — SSH returns nothing, and the script reports the
misleading error:

```
Error: no request files found on <host>:<path>
```

when in fact the files are present and the real cause is a host key mismatch.

**Fix:** clear the stale key and re-accept:
```bash
ssh-keygen -f ~/.ssh/known_hosts -R '<vm_hostname>'
ssh ubuntu@<vm_hostname>   # accept the new key interactively, then re-run the script
```

This is most likely to occur after a VM is rebuilt (new host key) or when connecting
from the VirtualBox host to the VM for the first time.

---

## `--request-inventory-host` vs JSON `inventory_host`

The resize request JSON written by the admin UI contains an `inventory_host` field
(set by the "Inventory host" input, defaulting to `gighive_vm`). The script formerly
used this JSON field as the `--limit` for the Ansible playbook run.

This caused a mismatch on the lab machine, where the Ansible group is `gighive`
but the JSON was written with `inventory_host: gighive2`, producing:

```
[ERROR]: Specified inventory, host pattern and/or --limit leaves us with no hosts to target.
```

The script was fixed to use `$request_inventory_host` (the explicit CLI argument)
as both the `--limit` and the source for SSH var lookups. This is always the
correct value because it is provided by the operator alongside the inventory file
they are actually using.

### Inventory host value comparison

| Inventory | `--request-inventory-host` | JSON `inventory_host` | `--limit` (fixed) | `--limit` (old) |
|---|---|---|---|---|
| `inventory_gighive.yml` | `gighive_vm` | `gighive_vm` | `gighive_vm` ✓ | `gighive_vm` ✓ |
| `inventory_gighive2.yml` | `gighive_vm` | `gighive_vm` | `gighive_vm` ✓ | `gighive_vm` ✓ |
| `inventory_lab.yml` | `gighive_vm` | `gighive_vm` | `gighive_vm` ✓ | `gighive_vm` ✓ |
