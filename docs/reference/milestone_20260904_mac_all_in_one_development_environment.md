# Milestone — Mac All-in-One Development Environment Complete (2026-09-04)

**Date:** 2026-09-04  
**Status:** Migration from Pop!_OS Intel controller to MacBook M4 Pro verified; full local VM provisioning succeeds on Apple Silicon

---

## What This Milestone Was

Before this milestone, GigHive development was split across machines. The Pop!_OS Intel
tower was the established Ansible controller and local VirtualBox host, while the Mac was
not yet capable of reproducing the complete infrastructure workflow. Development depended
on a fixed Linux workstation and on controller state that had accumulated over time.

After this milestone, the MacBook M4 Pro is a complete, portable development environment.
It can hold the repository, run the development tools, act as the Ansible controller,
create the ARM64 Ubuntu VM, provision the full GigHive stack, and validate the resulting
application from one machine.

This was not simply a file migration. The infrastructure automation crossed operating
system and CPU-architecture boundaries and emerged more explicit, reproducible, and
portable than it was before.

---

## What Was Accomplished

**The Ansible controller moved from Pop!_OS to macOS.**  
The Mac now drives the same provisioning workflow previously run from the Linux tower.
Ansible's required third-party collections are installed in the standard per-user
location, `~/.ansible/collections`, rather than being copied into or committed with the
repository.

**Local virtualization moved from Intel/AMD64 to Apple Silicon/ARM64.**  
The `cloud_init` role now recognizes both Linux's `aarch64` and macOS's `arm64` architecture
names. It selects the correct Ubuntu ARM64 cloud image, VirtualBox operating-system type,
and image-conversion tooling automatically.

**Headless VirtualBox provisioning now works on the M4 Pro.**  
ARM64 guests no longer inherit an incompatible legacy VGA controller. The playbook disables
graphics for the headless VM on ARM64 while leaving the existing AMD64 Linux path unchanged.

**Latent Ansible module errors were corrected at source.**  
Eight tasks that relied on incorrect or ambiguous module names now use their proper fully
qualified collection names: `ansible.posix.synchronize` and
`community.general.htpasswd`. These are platform-independent correctness improvements,
not Mac-only workarounds.

**A clean build completed successfully.**  
The full provisioning run against `gighive2` finished with no failed or unreachable tasks:

```text
gighive_vm : ok=565  changed=130  unreachable=0  failed=0  skipped=86
Playbook run took 0 days, 0 hours, 8 minutes, 16 seconds
```

---

## Strategic Significance

The largest benefit is the removal of a machine boundary from everyday development. A
single portable computer can now perform application development, infrastructure changes,
local environment creation, deployment, and validation. Work is no longer tied to the
physical Pop!_OS tower or dependent on switching between separate controller and
development machines.

That consolidation shortens the feedback loop. An infrastructure change can be edited,
provisioned into a clean local VM, and tested on the same machine without transferring
files or coordinating state across computers. The Mac can travel with the complete
development environment, making the workflow available wherever the work happens.

The migration also delivered a more valuable architectural benefit: it forced assumptions
about the controller platform into the open. CPU architecture, collection ownership,
module namespaces, cloud-image selection, and VirtualBox graphics behavior are now
explicit. The automation is less dependent on undocumented state accumulated on one
long-lived workstation.

This makes the environment easier to reproduce on a replacement Mac, another ARM64
controller, or a future developer machine. The Pop!_OS path remains supported, so the
result is broader portability rather than a platform swap that abandons Linux.

---

## Benefits of the All-in-One Environment

- **One machine, one workflow:** code, automation, virtualization, deployment, and local
  validation are available together.
- **Portable development:** the complete working environment is no longer anchored to a
  desktop tower.
- **Faster iteration:** infrastructure and application changes can be tested locally
  without a cross-machine handoff.
- **Clean-environment confidence:** the migration proved that the repository can bootstrap
  a fresh controller once its external prerequisites are installed.
- **ARM64 readiness:** local infrastructure now works natively on Apple Silicon and handles
  both `arm64` and `aarch64` controller naming.
- **Continued AMD64 support:** architecture-specific changes are guarded, preserving the
  established Pop!_OS/Linux workflow.
- **Reduced hidden state:** required Ansible collections and controller prerequisites are
  now known and documented.
- **Cleaner repository boundaries:** downloaded Galaxy collections and cloud disk images
  remain machine-local instead of entering Git history.
- **More resilient automation:** corrected FQCNs remove behavior that depended on an older,
  more permissive Ansible installation.
- **Simpler recovery:** a new controller can be reconstructed from the repository,
  documented prerequisites, and pinned dependency information rather than from an image
  of the old workstation.

---

## Issues the Migration Exposed

### 1. Required Ansible Collections Were Previously Implicit

The Pop!_OS controller already had the required Galaxy collections installed under
`~/.ansible/collections`, so their role in the environment was easy to overlook. The fresh
Mac installation exposed the dependency immediately. The required collections are now
installed in the same standard per-user location on macOS and excluded from the repository.

### 2. Incorrect Module Names Had Been Silently Tolerated

The old environment allowed several incorrect or unqualified module references to work.
The Mac controller rejected them. Fixing the references made the playbooks correct on both
platforms and removed reliance on compatibility behavior.

### 3. Architecture Detection Assumed Linux Naming

The automation understood `aarch64` but not Apple's `arm64`. That caused an AMD64 VM to be
created on an ARM64 host, which VirtualBox could not run. Architecture detection now covers
both conventions.

### 4. VirtualBox ARM64 Guests Cannot Use the Legacy VGA Path

After selecting the correct ARM64 image, the VM still failed with a graphics-memory
conflict. Because the GigHive VM runs headless, disabling its graphics controller on ARM64
was both the correct fix and the simplest configuration.

### 5. Generated Dependencies Were Accidentally Staged

Project-local Galaxy collections and an Ubuntu cloud image were caught by `git add .`.
They were removed before the milestone commit, the collections were reinstalled under
`~/.ansible/collections`, and repository ignore rules now keep generated dependencies and
virtual disk images out of future commits.

---

## Environment Model Going Forward

The repository contains the source of truth: playbooks, roles, inventory structure,
configuration, and dependency declarations. Machine-local downloaded artifacts remain
outside Git:

- Ansible collections: `~/.ansible/collections/ansible_collections`
- Ubuntu cloud images and generated virtual disks: local artifacts, ignored by Git
- VirtualBox VM state: managed on the development host, not stored in the repository

The playbooks support two controller architecture families:

- **Apple Silicon/macOS:** `arm64`, ARM64 Ubuntu image, headless VirtualBox graphics disabled
- **Intel/AMD Linux:** `x86_64`/AMD64 behavior retained without the ARM64-only adjustment

For reproducibility, collection versions should be declared in a tracked Ansible
requirements file and installed with `ansible-galaxy collection install -r requirements.yml`.

---

## Net Result

GigHive now has an all-in-one Mac development environment capable of rebuilding and
validating the local stack from infrastructure source. The Pop!_OS tower is no longer a
required part of the daily development path, yet its Linux/AMD64 workflow remains intact.

The immediate result is convenience and speed. The lasting result is stronger: the
infrastructure is more portable because assumptions that lived silently on the old
controller are now represented in code or documentation. A successful 565-task Ansible
run on a clean Apple Silicon environment proves that the development platform can cross
both an operating-system boundary and a CPU-architecture boundary without changing the
target application stack.

This is a foundational milestone for the project. Development is now consolidated,
portable, reproducible, and ready to move with the person doing the work.

---

*Source docs:*  
- `problem_ansible_mac_migration.md` — controller migration errors, root causes, and fixes  
- `virtualbox_mac_apple_silicon_install.md` — Apple Silicon VirtualBox setup and constraints  
- `CHANGELOG.md` — migration implementation history  
- Git commits `ecc4a75` and `b9e5d8a` — architecture preparation and completed Mac support
