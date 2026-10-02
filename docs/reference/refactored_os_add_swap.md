# Refactor: Add 2 GB Swap to Base VM Cloud-Init Build

> **Document status: planning.**
> This document describes the planned change to add a persistent 2 GB swap file to the base VM build path managed by `ansible/roles/cloud_init`.

## Purpose

The VirtualBox-based base VM build currently provisions Ubuntu from a cloud image, expands the root filesystem, configures networking, and applies first-boot guest settings through cloud-init.

The goal of this refactor is to add a **persistent 2 GB swap file** during first boot so low-memory VM configurations have additional headroom without changing the existing VM RAM allocation.

## Summary of Planned Change

The change will be implemented through the existing cloud-init path used for VM provisioning.

### What will change

- Add two new variables to each VM-related `group_vars` file:
  - `swap_size`
  - `swap_max_size`
- Set both values to `2147483648` (2 GiB in bytes)
- Update `ansible/roles/cloud_init/templates/user-data.j2`
- Add a top-level cloud-init `swap:` block that consumes those two variables

### What will not change

- No change to the `base` role
- No manual `/etc/fstab` management in Ansible
- No `runcmd`-based manual `fallocate`, `mkswap`, or `swapon` logic
- No VM memory allocation change in `VBoxManage modifyvm`
- No disk layout or partitioning change beyond the existing root-grow behavior already in cloud-init

## Why `cloud_init` Owns This Change

This repository provisions VirtualBox VMs through the `cloud_init` role.

Authoritative call path:

- `ansible/playbooks/site.yml`
- play: `Provision VM in VirtualBox`
- hosts: `gighive:gighive2`
- role: `cloud_init`

Within that role:

- `ansible/roles/cloud_init/tasks/main.yml` renders the guest cloud-init files
- `ansible/roles/cloud_init/templates/user-data.j2` is the first-boot guest configuration surface

Since swap creation is an operating-system bootstrap concern, it belongs in cloud-init rather than later configuration roles.

## Variable Placement

Per established project convention, new Ansible configuration variables should live in `group_vars`, not role defaults.

For this repo, when referring to **each group var file** for this VM/base-build configuration, that means exactly:

- `ansible/inventories/group_vars/gighive/gighive.yml`
- `ansible/inventories/group_vars/gighive2/gighive2.yml`
- `ansible/inventories/group_vars/prod/prod.yml`

Planned values:

```yaml
swap_size: 2147483648
swap_max_size: 2147483648
```

## Cloud-Init Design

The planned template change in `ansible/roles/cloud_init/templates/user-data.j2` is a native cloud-init swap declaration.

Planned shape:

```yaml
swap:
  filename: /swap.img
  size: {{ swap_size }}
  maxsize: {{ swap_max_size }}
```

This keeps swap management declarative and co-located with the existing cloud-init responsibilities:

- package installation
- root filesystem growth
- hostname configuration
- SSH configuration
- first-boot guest setup

## Validation Performed

### Local host validation

The host environment has `cloud-init` installed, and schema validation accepted all of the following forms for `swap.size` and `swap.maxsize`:

- integer bytes: `2147483648`
- string gigabytes: `2G`
- string megabytes: `2048M`

### Guest validation on `gighive2.gighive.internal`

Validation was also run on the actual guest runtime as user `ubuntu`.

Observed guest version:

- `cloud-init 26.1-0ubuntu1~24.04.1`

On that guest, `cloud-init schema` accepted all three forms above, including plain integer bytes.

### Exact planned block validation on `gighive2.gighive.internal`

The exact cloud-init block planned for `ansible/roles/cloud_init/templates/user-data.j2` was also validated directly on `gighive2.gighive.internal` as user `ubuntu`.

Validated block:

```yaml
swap:
  filename: /swap.img
  size: 2147483648
  maxsize: 2147483648
```

Validation result:

- `cloud-init schema` returned `Valid schema`
- validation was run against the actual guest runtime version: `cloud-init 26.1-0ubuntu1~24.04.1`
- this confirms that the exact planned `swap:` block is accepted by the target VM cloud-init version

An attempted full-payload validation later failed because of YAML quoting issues in an ad hoc test payload for the existing `runcmd` `sed` commands, not because of the `swap:` block. The standalone `swap:` block validation above is the relevant evidence for this refactor.

Conclusion:

- `size: 2147483648`
- `maxsize: 2147483648`

are valid on the actual target VM runtime currently in use for `gighive2`.

## `/etc/fstab` Decision

A separate `/etc/fstab` edit is **not planned**.

Rationale:

- cloud-init already owns first-boot OS bootstrap behavior in this build path
- the native `swap:` block is the correct declarative surface for this concern
- splitting responsibility between `cloud_init` and `base` would create unnecessary duplication and ownership ambiguity

Therefore:

- do **not** add swap management to the `base` role
- do **not** add separate `/etc/fstab` tasks
- do **not** add duplicate swap commands in `runcmd`

## Scope and Runtime Notes

### New builds vs existing VMs

This change is intended for the **base VM build path**.

Because it is delivered through cloud-init user-data, it primarily affects:

- newly created VMs
- freshly reprovisioned VMs that consume a new NoCloud seed during first boot

It should **not** be assumed to retrofit already-provisioned VMs that have completed their initial cloud-init run.

### `prod.yml` note

Although `cloud_init` is currently wired to the `gighive` and `gighive2` hosts in `ansible/playbooks/site.yml`, the repository convention for this VM/base-build configuration is to keep the related variables in all three of these files:

- `gighive.yml`
- `gighive2.yml`
- `prod.yml`

This keeps the configuration surface consistent even though `prod` is not currently a direct consumer of the `cloud_init` play.

## Files Planned for Change

### 1. `ansible/inventories/group_vars/gighive/gighive.yml`

Add:

```yaml
swap_size: 2147483648
swap_max_size: 2147483648
```

### 2. `ansible/inventories/group_vars/gighive2/gighive2.yml`

Add:

```yaml
swap_size: 2147483648
swap_max_size: 2147483648
```

### 3. `ansible/inventories/group_vars/prod/prod.yml`

Add:

```yaml
swap_size: 2147483648
swap_max_size: 2147483648
```

### 4. `ansible/roles/cloud_init/templates/user-data.j2`

Add a top-level cloud-init block:

```yaml
swap:
  filename: /swap.img
  size: {{ swap_size }}
  maxsize: {{ swap_max_size }}
```

## Risks and Considerations

### 1. Existing VM behavior

Existing VMs may not pick up the change unless they are rebuilt or reprovisioned through the cloud-init path.

### 2. Disk headroom

A 2 GB swap file consumes disk space inside the guest. This is expected and should be acceptable relative to the current configured VM disk sizes.

### 3. Naming clarity

The planned variable names are:

- `swap_size`
- `swap_max_size`

These values are intended to be interpreted as raw bytes.

This is valid and has been schema-verified, but future maintainers should understand that these are byte values, not megabytes.

## Verification Plan

After implementation, verify on a freshly provisioned VM:

### Configuration verification

- Confirm the rendered `user-data` contains the `swap:` block
- Confirm the intended values are present in the relevant `group_vars` file

### Guest runtime verification

On the guest:

```bash
swapon --show
free -h
ls -lh /swap.img
cat /proc/swaps
```

Expected result:

- `/swap.img` exists
- swap is active
- reported swap size is approximately 2.0 GiB

### Cloud-init verification

Optional additional checks on the guest:

```bash
cloud-init status --long
sudo grep -i swap /var/log/cloud-init.log /var/log/cloud-init-output.log
```

Expected result:

- cloud-init completed successfully
- no swap-related failure messages

## Non-Goals

This refactor does not attempt to:

- tune Linux `vm.swappiness`
- tune kernel memory pressure behavior
- parameterize swap filename
- add dynamic swap sizing based on RAM or disk size
- retrofit already-running VMs outside the cloud-init provisioning path

## Recommended Implementation Order

1. Add `swap_size` and `swap_max_size` to the three agreed `group_vars` files
2. Update `ansible/roles/cloud_init/templates/user-data.j2`
3. Rebuild or freshly provision a VM through the existing cloud-init flow
4. Verify swap activation inside the guest

## Final Recommendation

The planned implementation is consistent with current repo structure and good Ansible ownership practices.

Recommended design:

- configuration in `group_vars`
- guest bootstrap logic in `cloud_init`
- no duplicate swap logic in `base`
- no separate `/etc/fstab` management

This keeps the change small, declarative, and aligned with the existing base VM provisioning model.
