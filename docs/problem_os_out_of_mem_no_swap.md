---
description: RCA for VM memory pressure caused by no configured swap in the base VM build
---

# Problem: Base VM Can Run Out of Memory Because No Swap Is Configured

## Summary

The base VM build had no configured swap file, which means the guest relied entirely on physical RAM. On small-memory VM configurations, this increases the risk of out-of-memory conditions during package installation, Docker startup, application provisioning, or other transient high-memory operations.

The chosen resolution was to add a native cloud-init `swap:` block in the VM build path owned by `ansible/roles/cloud_init`, with `swap_size` and `swap_max_size` defined in the agreed environment `group_vars` files.

## Impact

- VM provisioning and post-provision configuration can be less stable on low-RAM guests.
- Transient memory spikes have less headroom and can trigger OOM behavior.
- Small-footprint VirtualBox VM configurations are more fragile than necessary.
- The VM build lacked a simple, native cloud-init mechanism to provide predictable swap capacity.

## Symptoms

Observed and inferred operational symptoms for this problem space include:

- A small-memory guest can become unstable during initial build or later Ansible runs.
- Memory-intensive operations such as package upgrades, Docker startup, or application bring-up have no swap-backed safety margin.
- `swapon --show` returns no active swap device.
- `/swap.img` does not exist on the guest.
- `free -h` shows `Swap: 0B 0B 0B`.

Example diagnostic commands:

```bash
swapon --show
free -h
ls -lh /swap.img
cat /proc/swaps
```

## Diagnostics Used / Recommended

Because the exact historical command transcript was not fully recoverable, this section records the concrete guest-side diagnostics that are appropriate for determining whether no configured swap was contributing to out-of-memory risk or instability.

### 1 - Confirm whether swap exists at all

These commands answer the first and most direct question: does the guest currently have any active swap?

```bash
swapon --show
cat /proc/swaps
free -h
ls -lh /swap.img
```

Interpretation:

- If `swapon --show` and `cat /proc/swaps` return no active swap entries, the guest has no swap configured.
- If `free -h` shows `Swap: 0B 0B 0B`, the guest has no swap-backed memory headroom.
- If `/swap.img` does not exist, the expected cloud-init-managed swap file was never created.

### 2 - Check whether the kernel reported OOM pressure or an OOM kill

These commands are the fastest way to confirm whether the kernel actually reported out-of-memory conditions:

```bash
dmesg -T | egrep -i 'out of memory|oom-killer|killed process'
journalctl -k -b | egrep -i 'out of memory|oom-killer|killed process'
sudo journalctl -b -p warning --no-pager | egrep -i 'out of memory|oom|killed process'
```

Interpretation:

- `dmesg -T` shows kernel ring buffer messages with human-readable timestamps.
- `journalctl -k -b` restricts output to kernel messages from the current boot.
- If these commands show OOM-killer activity, the issue moved from "lack of safety margin" to a confirmed memory-exhaustion event.

### 3 - Check current and recent memory state on the guest

These commands help determine whether the VM is running close to the edge even if no OOM kill has yet occurred:

```bash
free -h
vmstat 1 5
ps aux --sort=-%mem | head -20
```

Interpretation:

- `free -h` shows total/used/free RAM and swap.
- `vmstat 1 5` helps identify sustained memory pressure and whether the system is blocked or thrashing.
- `ps aux --sort=-%mem | head -20` identifies the largest memory consumers at the time of inspection.

### 4 - Check cloud-init logs to see whether swap creation was attempted

Because swap ownership in this design belongs to cloud-init, these commands help determine whether the guest ever tried to create the swap file:

```bash
cloud-init status --long
sudo grep -i swap /var/log/cloud-init.log /var/log/cloud-init-output.log
sudo journalctl -u cloud-init -u cloud-config -u cloud-final -b --no-pager
```

Interpretation:

- `cloud-init status --long` confirms whether cloud-init completed normally.
- Grepping the cloud-init logs for `swap` shows whether the `swap:` block was parsed and acted on.
- The `journalctl` unit view helps correlate any first-boot failure with swap creation or schema problems.

### 5 - Validate the exact planned cloud-init payload on the guest runtime

This was part of the pre-implementation validation and is useful if the schema surface is ever questioned again:

```bash
cloud-init --version
cloud-init schema --config-file /path/to/test-user-data.yaml
```

Interpretation:

- `cloud-init --version` confirms the exact runtime version on the target guest.
- `cloud-init schema --config-file ...` verifies whether the exact `swap:` block is accepted by that runtime before rebuilding the VM.

## Problems Encountered

### 1 - No swap existed in the base VM build path

The VM build configuration in `ansible/roles/cloud_init` already owned first-boot guest setup through cloud-init, including package installation, hostname setup, and root filesystem growth, but it did not declare any swap configuration.

Relevant file:

- `ansible/roles/cloud_init/templates/user-data.j2`

This meant the guest came up without a configured swap file unless it was added manually later.

### 2 - Need to decide where swap configuration belongs

There was an ownership question between the `cloud_init` role and the `base` role.

Conclusion:

- `cloud_init` is the correct owner for swap creation in this design.
- `base` should not add separate swap creation logic, `/etc/fstab` management, or `runcmd`-based `fallocate` / `mkswap` / `swapon` commands.

Reasoning:

- `ansible/roles/cloud_init/templates/user-data.j2` is the authoritative first-boot configuration surface.
- Native cloud-init swap support is simpler and more correct than duplicating swap setup in later provisioning roles.
- `ansible/roles/base/tasks/main.yml` is primarily a post-provision bootstrap/configuration role, not the owner of first-boot disk or swap configuration.

### 3 - Need to validate the exact cloud-init schema accepted by the target runtime

Before implementation, the exact planned `swap:` block was validated against the actual guest runtime on `gighive2.gighive.internal` as user `ubuntu`.

Observed guest version:

- `cloud-init 26.1-0ubuntu1~24.04.1`

Validated block:

```yaml
swap:
  filename: /swap.img
  size: 2147483648
  maxsize: 2147483648
```

Validation result:

- `cloud-init schema` returned `Valid schema`
- Plain integer byte values were accepted for both `size` and `maxsize`

## Root Cause

### Direct cause

The base VM build did not define any swap in the cloud-init user-data, so guests booted with no swap configured.

### Why this mattered

The project uses small-memory VM configurations in at least some environments. Without swap, temporary RAM pressure during provisioning or runtime has no fallback, making OOM behavior more likely.

### Why `cloud_init` is the correct fix location

The swap file is part of first-boot machine initialization, not an application-layer concern. In this repository, that responsibility lives in:

- `ansible/roles/cloud_init/templates/user-data.j2`

The `base` role contains a few sanity checks and assertions, but `ansible/roles/base/tasks/main.yml` is mostly concerned with:

- package/bootstrap work
- Docker installation
- directory creation and permissions
- file synchronization
- timezone/network/hostname adjustments
- reboot handling

It does not read as the canonical owner of swap creation.

## Resolution

### Configuration changes

Add these variables to the agreed VM build `group_vars` files:

- `ansible/inventories/group_vars/gighive/gighive.yml`
- `ansible/inventories/group_vars/gighive2/gighive2.yml`
- `ansible/inventories/group_vars/prod/prod.yml`

Values:

```yaml
swap_size: 2147483648
swap_max_size: 2147483648
```

Add this native cloud-init block to:

- `ansible/roles/cloud_init/templates/user-data.j2`

```yaml
swap:
  filename: /swap.img
  size: {{ swap_size }}
  maxsize: {{ swap_max_size }}
```

### Testing ownership decision

If a role-level test is added for this behavior, it makes more sense for that test to live with `cloud_init`, not `base`.

Reasoning:

- `cloud_init` declares the `swap:` block and owns first-boot configuration.
- `base` is not the source of truth for swap creation.
- If a final-host integration check is desired, that can exist as a broader provisioning verification step, but swap ownership still belongs to `cloud_init`.

## Verification

### Config-level verification

Confirm the expected variables exist in:

- `ansible/inventories/group_vars/gighive/gighive.yml`
- `ansible/inventories/group_vars/gighive2/gighive2.yml`
- `ansible/inventories/group_vars/prod/prod.yml`

Confirm `ansible/roles/cloud_init/templates/user-data.j2` includes:

```yaml
swap:
  filename: /swap.img
  size: {{ swap_size }}
  maxsize: {{ swap_max_size }}
```

### Guest verification after rebuild/provision

Run on a freshly provisioned VM:

```bash
swapon --show
free -h
ls -lh /swap.img
cat /proc/swaps
```

Expected:

- `/swap.img` exists
- swap is active
- total swap is approximately 2 GiB

Optional cloud-init diagnostics:

```bash
cloud-init status --long
sudo grep -i swap /var/log/cloud-init.log /var/log/cloud-init-output.log
```

Expected:

- cloud-init completes successfully
- no swap-related schema or creation errors appear in logs

## Preventative Actions

- Keep swap configuration owned by `ansible/roles/cloud_init` rather than duplicating it in `base`.
- Prefer native cloud-init features over ad hoc `runcmd` shell logic when the platform already supports the needed behavior.
- When introducing VM build configuration values, continue using the agreed environment files:
  - `ansible/inventories/group_vars/gighive/gighive.yml`
  - `ansible/inventories/group_vars/gighive2/gighive2.yml`
  - `ansible/inventories/group_vars/prod/prod.yml`
- If a future test is added, place the role-level ownership check near `cloud_init`, and reserve broader host-state verification for explicit integration/provision validation.

## Command Lists For Future Incidents

### VM host

1. `VBoxManage showvminfo gighive --machinereadable | egrep '^(VMState|VMStateChangeTime|name=)'`
2. `sudo journalctl --since "2026-07-16 18:20:00" --until "2026-07-16 18:40:00" --no-pager`
3. `sudo journalctl -k --since "2026-07-16 18:20:00" --until "2026-07-16 18:40:00" --no-pager`
4. `ls -lah "/home/sodo/VirtualBox VMs/gighive/Logs"`
5. `tail -200 "/home/sodo/VirtualBox VMs/gighive/Logs/VBox.log"`
6. `egrep -n 'Heartbeat|flatline|catch-up|unresponsive|Reset initiated by ACPI' "/home/sodo/VirtualBox VMs/gighive/Logs/VBox.log"`
7. `free -h`
8. `swapon --show`
9. `cat /proc/swaps`

### VM guest

1. `sudo journalctl -b -1 -k --no-pager | egrep -i 'out of memory|oom-killer|killed process'`
2. `sudo dmesg -T | egrep -i 'out of memory|oom-killer|killed process'`
3. `swapon --show`
4. `free -h`
5. `cat /proc/swaps`
6. `sudo journalctl -b -1 --no-pager | tail -300`
7. `sudo journalctl -b -1 -p warning --no-pager | tail -300`
8. `sudo journalctl -b -1 -k --no-pager | tail -300`
9. `sudo journalctl -b -1 -k --no-pager | egrep -i 'soft lockup|hard lockup|watchdog|hung task|blocked for more than|rcu|stall|panic|segfault|general protection|BUG:'`
10. `ls -lah /var/log/sysstat/`
11. `sar -r -f /var/log/sysstat/sa16`
12. `sar -S -f /var/log/sysstat/sa16`
13. `sar -B -f /var/log/sysstat/sa16`
