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
  --request-inventory-host <ansible_group> \
  --latest [--dry-run]
```

| Argument | Purpose |
|---|---|
| `-i <inventory_file>` | Ansible inventory to use for both var lookups and the playbook run |
| `--request-host` | SSH target to reach the VM's filesystem and read the request file |
| `--request-inventory-host` | Ansible group/host pattern — used as `--limit` for the playbook and for SSH var lookups |
| `--latest` | Automatically pick the most recent unprocessed request file |
| `--dry-run` | Print what would execute without making any changes |

### Examples

```bash
# gighive2 (standard dev/test VM)
./ansible/roles/docker/files/apache/webroot/tools/run_resize_request.sh \
  -i ansible/inventories/inventory_gighive2.yml \
  --request-host gighive2.gighive.internal \
  --request-inventory-host gighive2 \
  --latest

# lab VM (special instance — inventory group is 'gighive', not 'gighive2')
./ansible/roles/docker/files/apache/webroot/tools/run_resize_request.sh \
  -i ansible/inventories/inventory_lab.yml \
  --request-host labvm.gighive.internal \
  --request-inventory-host gighive \
  --latest
```

> **Do not run with `sudo`.** VBoxManage reads from `$HOME/.config/VirtualBox`;
> running as root points it at `/root/.config/VirtualBox` and breaks the VM lookup.

---

## `--request-inventory-host` vs JSON `inventory_host`

The resize request JSON written by the admin UI contains an `inventory_host` field
(set by the "Inventory host" input, defaulting to `gighive2`). The script formerly
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
| `inventory_gighive2.yml` | `gighive2` | `gighive2` | `gighive2` ✓ | `gighive2` ✓ |
| `inventory_prod.yml` | `gighive` | `gighive` | `gighive` ✓ | `gighive` ✓ |
| `inventory_lab.yml` | `gighive` | `gighive2` | `gighive` ✓ | `gighive2` ✗ |
