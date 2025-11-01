# Bootstrap Phase 1: Controller Prerequisites and Verification

This document explains each YAML introduced in Phase 1 for preparing and verifying the Ansible controller. Phase 1 installs controller-side tooling only, supporting VirtualBox or Azure as build targets. Docker engine/compose is not installed on the controller in Phase 1.

Supported controller OS: Ubuntu 20.04, 22.04, and 24.10 (with repo codename fallbacks as needed).

---

## Summary of Phase 1 Completion

**Phase 1 Bootstrap to Ansible Migration - Prerequisites Role** is now complete and tested on Ubuntu 24.10 (baremetalgmktecg9).

### What was accomplished

1. **Automated prerequisite installation** on the controller:
   - Base packages (git, curl, etc.)
   - ansible-core CLI (system-level for reliability)
   - Python venv with Ansible
   - Required collections (community.general, community.docker)
   - VirtualBox (when enabled)

2. **Idempotent collection management**:
   - Detects and installs community.general if missing
   - Upgrades community.docker to minimum required version (3.13.3 → 4.8.2)
   - Installs to user home (`~/.ansible/collections`)
   - Uses explicit paths to avoid root/user confusion

3. **Robust verification**:
   - Checks Ansible core version
   - Verifies collection presence and version via JSON parsing
   - Provides clear error messages with remediation steps

### Key lessons learned

- **Tilde expansion depends on user context**: `~` expands to `/root` when running with `become`, not the controller user's home.
- **`become: no` is critical**: User-scoped installations must explicitly disable privilege escalation.
- **Explicit `$HOME` detection**: Using `echo $HOME` with `become: no` ensures correct paths.
- **JSON parsing is more reliable**: Structured data from `ansible-galaxy --format json` beats text parsing.
- **Collection search paths**: Ansible searches multiple locations; user home takes precedence over system paths.

### Verified configuration

**Tested on**: Ubuntu 24.10 (baremetalgmktecg9)

- ✅ **Ansible Core**: 2.17.12 (>= 2.17.12 required)
- ✅ **Collections**:
  - community.general: 8.3.0
  - community.docker: 4.8.2 (>= 3.13.3 required, upgraded from 3.7.0)
  - Collection path: `/home/gmk/.ansible/collections/ansible_collections`
- ✅ **Tools**:
  - Terraform: 1.13.4
  - Azure CLI: 2.78.0
  - VirtualBox: 7.0.20_Ubuntur163906

---

## ansible/roles/installprerequisites/defaults/main.yml
- Purpose
  - Centralizes defaults and feature flags for the controller prerequisites role.
- Key defaults
  - `ansible_venv_path`: path to controller Python venv.
  - `target_provider`: `vbox` or `azure` (used by verify and examples).
  - `min_ansible`, `min_comm_docker`: version thresholds for verification.
  - Install flags: `install_terraform`, `install_azure_cli`, `install_virtualbox` (default true for install-all).
- Behavior
  - Safe, opinionated defaults for new users; advanced users can opt out per feature.

## ansible/roles/installprerequisites/vars/main.yml
- Purpose
  - Defines package lists and Python packages used by the role.
- Contents
  - `python_packages`: e.g., `ansible`, `azure-cli` for controller venv.
  - `base_packages`: apt packages needed to enable installers and venvs.

## ansible/roles/installprerequisites/tasks/main.yml
- Purpose
  - Orchestrates controller setup at a high level.
- What it does
  - Installs base packages.
  - Creates/updates Python venv and installs Python deps.
  - Installs Terraform.
  - Installs Azure CLI when enabled.
  - Installs VirtualBox CLI when enabled.
- Inputs/variables
  - `install_terraform`, `install_azure_cli`, `install_virtualbox`.
  - `ansible_venv_path`.

## ansible/roles/installprerequisites/tasks/python_venv.yml
- Purpose
  - Creates a dedicated Python virtual environment and installs controller Python packages.
- What it does
  - Ensures venv directory, creates venv if missing, upgrades pip, installs `python_packages`.
- Inputs/variables
  - `ansible_venv_path`, `python_packages`.
- Does not
  - Modify system Python or global site-packages.

## ansible/roles/installprerequisites/tasks/terraform.yml
- Purpose
  - Installs Terraform CLI on the controller.
- What it does
  - Adds HashiCorp apt key and repository (with codename fallback when needed).
  - Installs `terraform` via apt.
- Output/behavior
  - `terraform version` will be available on PATH.
- Notes
  - On Ubuntu 24.10, repository codenames may fall back to the latest LTS (e.g., `noble`) if upstream doesn’t publish non‑LTS metadata.

## ansible/roles/installprerequisites/tasks/azure.yml
- Purpose
  - Installs Azure CLI for Azure target provisioning.
- What it does
  - Runs Microsoft’s install script for Debian/Ubuntu (`https://aka.ms/InstallAzureCLIDeb`).
  - Verifies with `az version`.
- Inputs/variables
  - Typically gated by `install_azure_cli: true`.
- Notes
  - If using apt repository mode instead, codename fallback may be needed on non‑LTS.

## ansible/roles/installprerequisites/tasks/virtualbox.yml
- Purpose
  - Installs VirtualBox CLI for local VM builds on the controller.
- What it does
  - Installs `virtualbox` via Ubuntu apt.
  - Verifies with `VBoxManage --version`.
- Prechecks (recommended)
  - Kernel headers present: `linux-headers-$(uname -r)` (warn if missing).
  - Secure Boot/DKMS signing considerations (warn only).
- Inputs/variables
  - Typically gated by `install_virtualbox: true`.

## ansible/roles/installprerequisites/tasks/ensure_collections.yml
- Purpose
  - Ensures required Ansible collections are installed and meet minimum version requirements.
- What it does
  - Detects and installs `community.general` if missing (required for idempotent collection management).
  - Upgrades `community.docker` to minimum required version (3.13.3 → 4.8.2+).
  - Installs to controller user's home: `$HOME/.ansible/collections` (e.g., `/home/gmk/.ansible/collections`).
  - Uses explicit `$HOME` detection with `become: no` to avoid root/user path confusion.
- Collection installation location
  - **User collections**: `~/.ansible/collections/ansible_collections` (preferred, no sudo required)
  - **System collections**: `/usr/share/ansible/collections/ansible_collections` (fallback)
  - **Distribution packages**: `/usr/lib/python3/dist-packages/ansible_collections` (read-only, managed by apt)
- Key lessons
  - Tilde `~` expansion depends on user context (root vs. controller user).
  - `become: no` is critical for user-scoped installations.
  - Explicit `$HOME` detection ensures correct paths across different privilege contexts.
- Inputs/variables
  - `ansible_galaxy_cmd`: Prefers venv ansible-galaxy if present, otherwise system.
  - `min_comm_docker`: Minimum version requirement (default: 3.13.3).
  - `ensure_required_collections`: Feature flag (default: true).

## ansible/roles/installprerequisites/tasks/verify.yml
- Purpose
  - Verifies controller readiness and versions.
- What it verifies
  - Ansible core version >= `min_ansible` (2.17.12).
  - `community.docker` collection version >= `min_comm_docker` (3.13.3).
  - Terraform CLI presence (`terraform version`).
  - Azure CLI presence (`az version`) when Azure installs are enabled.
  - VirtualBox CLI presence (`VBoxManage --version`) when VirtualBox installs are enabled.
- Collection detection method
  - Queries `ansible-galaxy collection list --format json` from controller user's home.
  - Parses JSON structure to extract version from `$HOME/.ansible/collections`.
  - Falls back to text parsing if JSON unavailable.
- Inputs/variables
  - `min_ansible`, `min_comm_docker`, and install flags to toggle checks.
- Output/behavior
  - Clear pass/fail messages for fast diagnostics.
  - Debug output shows all collection paths and versions found.

## ansible/playbooks/verify_controller.yml
- Purpose
  - Confirms the Ansible controller is ready for Phase 1 using the role’s verify tasks.
- Where it runs
  - Targets inventory host (e.g., `baremetalgmktecg9`) for context.
  - Delegates all checks to the controller via `delegate_to: localhost`.
- What it does
  - Imports `installprerequisites` verify tasks and asserts:
    - Ansible core >= 2.17.12
    - `community.docker` >= 3.13.3
    - Terraform available
    - Azure CLI available when Azure is enabled
    - VirtualBox CLI available when VirtualBox is enabled
- Inputs/variables
  - `target_provider: vbox | azure` (controls provider-specific checks)
  - Install flags: `install_terraform`, `install_azure_cli`, `install_virtualbox` (default true)
- Does not
  - Install or modify anything on the controller or target.
  - Provision any VMs (that occurs in later phases).
- How to run
  - `ansible-playbook -i <inventory> ansible/playbooks/verify_controller.yml -e target_provider=vbox`

---

## Design Principles
- Controller-only in Phase 1: sets up and verifies tooling required to build VirtualBox or Azure VMs.
- Install-all defaults for simplicity; feature flags allow opt-outs.
- Clear, actionable verification messages for quick troubleshooting.
- User-scoped installations (no sudo) for collections and Python packages.

## Next Steps
- Test on clean Ubuntu 20.04 and 22.04 systems to ensure compatibility.
- Extend to Phase 2: Target VM provisioning with VirtualBox.
- Add Terraform and Azure CLI prerequisites when needed.
