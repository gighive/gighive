# Ansible File Interaction Guide

This document explains how the key Ansible configuration files work together to provision and configure GigHive VMs.

## Overview

GigHive supports multiple VM configurations (e.g., `gighive`, `gighive2`):
- `gighive` is the primary group name, `gighive2` group can be used for testing a second vm if needed.
- The files below are the main config files for Ansible.

1. **`ansible.cfg`** - Global Ansible configuration
2. **`inventories/inventory_*.yml`** - Host and group definitions
3. **`inventories/group_vars/all.yml`** - Global group variables
4. **`inventories/group_vars/*`** - Group-specific variables (including optional per-group subdirectories)
5. **`playbooks/site.yml`** - Main playbook with task orchestration

## File Interaction Flow

```
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml
                    ↓
┌─────────────────────────────────────────────────────────────────────┐
│ 1. ansible.cfg (Global Settings)                                    │
│    - inventory = ansible/inventories (directory to scan)            │
│    - roles_path = ansible/roles                                     │
│    - collections_path = ansible/collections:~/.ansible/collections  │
│    - Various SSH, logging, and performance settings                 │
└─────────────────────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────────────────────┐
│ 2. inventory_bootstrap.yml (Host Definitions)                      │
│    - Defines group: gighive                                         │
│    - Defines host: gighive_vm (192.168.1.248)                       │
│    - Makes gighive a child of target_vms                            │
│    - Sets ansible_user, ansible_host, SSH options                   │
└─────────────────────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────────────────────┐
│ 3. group_vars/all.yml (Global Group Variables)                      │
│    - repo_root: base path of the repo                               │
│    - roles_dir: "{{ repo_root }}/ansible/roles"                     │
│    - cloud_init_files_dir: "{{ roles_dir }}/cloud_init/files"       │
│    - Other shared convenience paths                                 │
└─────────────────────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────────────────────┐
│ 4. group_vars/gighive/ (Auto-loaded Variables)                      │
│    - group_vars/gighive/gighive.yml: main config for gighive group  │
│    - group_vars/gighive/secrets.yml: optional secrets vars file     │
│      (plain or Vault-encrypted)                                     │
│    - Ansible automatically loads all files in this directory for    │
│      the gighive group                                              │
└─────────────────────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────────────────────┐
│ 5. playbooks/site.yml (Task Execution)                              │
│    Play 1: hosts: gighive(:gighive2) → VM Provisioning (VirtualBox) │
│    Play 2: hosts: gighive(:gighive2) → Cloud-init Disable           │
│    Play 3: hosts: target_vms → Main Configuration (Docker, etc.)    │
└─────────────────────────────────────────────────────────────────────┘

### Visual: Ansible File Interaction (Mermaid)

![Ansible file interaction diagram](ansibleFileInteration.png)

## Detailed File Descriptions

### 1. ansible.cfg

**Location:** `/home/sodo/scripts/gighive/ansible.cfg`

**Purpose:** Global Ansible configuration that applies to all playbook runs.

**Key Settings:**
```ini
[defaults]
inventory = ansible/inventories          # Where to find inventory files
roles_path = ansible/roles               # Where to find roles
collections_path = ansible/collections:~/.ansible/collections:/usr/share/ansible/collections
log_path = ~/.ansible/ansible.log        # Logging location
host_key_checking = False                # Disable SSH host key checking
stdout_callback = yaml                   # Output format
callbacks_enabled = timer, profile_tasks, vars_trace

[ssh_connection]
pipelining = True                        # Faster SSH execution
ssh_args = -o ControlMaster=auto -o ControlPersist=60s
```

**Important Notes:**
- All paths are relative to the repository root (`$GIGHIVE_HOME`)
- Run `ansible-playbook` commands from `$GIGHIVE_HOME`
- No group-specific logic here - this is global configuration

### 2. playbooks/site.yml

**Location:** `/home/sodo/scripts/gighive/ansible/playbooks/site.yml`

**Purpose:** Main orchestration playbook that defines what tasks run on which hosts.

**Key Plays:**

```yaml
# Play 1: VM Provisioning (runs on Ansible controller)
- name: Provision VM in VirtualBox
  hosts: gighive(:gighive2)              # Matches either group
  connection: local                     # Runs on controller, not VM
  tags: [ vbox_provision,cloud_init ]
  roles:
    - cloud_init

# Play 2: Cloud-init Disable (runs inside VM after creation)
- name: Disable Cloud-Init inside VM
  hosts: gighive(:gighive2)              # Matches either group
  become: yes
  tags: [ vbox_provision,cloud_init_disable ]
  roles:
    - cloud_init_disable

# Play 3: Main Configuration (runs on all target VMs)
- name: Configure target VM
  hosts: target_vms                     # Parent group containing gighive/gighive2
  become: true
  roles:
    - base
    - docker
    - security_basic_auth
    - post_build_checks
    - validate_app
    - mysql_backup
```

**Host Pattern Syntax:**
- `gighive:gighive2` - Matches hosts in EITHER group (colon = OR)
- `target_vms` - Matches all hosts in the target_vms group (includes both gighive and gighive2)

### 3. inventories/inventory_*.yml

**Location:** `/home/sodo/scripts/gighive/ansible/inventories/`

**Purpose:** Define hosts, groups, and their relationships.

**Example: inventory_bootstrap.yml**
```yaml
all:
  children:
    target_vms:                        # Parent group
      children:
        gighive: {}                   # Child group

    gighive:                          # Group definition
      hosts:
        gighive_vm:                    # Host label (arbitrary)
          ansible_host: 192.168.1.248  # Actual IP address
          ansible_user: ubuntu         # SSH user
          ansible_python_interpreter: /usr/bin/python3
          ansible_ssh_common_args: '-o StrictHostKeyChecking=no'
```

**Key Concepts:**
- **Host label** (`gighive_vm`): Arbitrary name Ansible uses internally
- **Group name** (`gighive`): Must match playbook `hosts:` patterns
- **ansible_host**: The actual IP address to connect to
- **Hierarchy**: `gighive` is a child of `target_vms`

**Available Inventories:**
- `inventory_bootstrap.yml` - Primary `gighive` VM (e.g. 192.168.1.248)
- `inventory_gighive2.yml` - Optional secondary/test VM (`gighive2`)
- `inventory_baremetal.yml` - For bare metal Ubuntu hosts
- `inventory_azure.yml` - For Azure cloud deployments

### 4. inventories/group_vars/*.yml

**Location:** `/home/sodo/scripts/gighive/ansible/inventories/group_vars/`

**Purpose:** Define variables that automatically apply to specific groups.

**Auto-loading Rules:**
- `group_vars/all.yml` is loaded for **all hosts in all inventories**
- When targeting group `gighive`, Ansible automatically loads:
  - `group_vars/gighive/gighive.yml` if it exists, and
  - every file in the `group_vars/gighive/` directory (for example:
    `group_vars/gighive/gighive.yml`, `group_vars/gighive/secrets.yml`)
- When targeting group `gighive2`, Ansible automatically loads
  `group_vars/gighive2.yml` and any files in `group_vars/gighive2/` if present

#### 4.1 group_vars/all.yml

**Location:** `/home/sodo/scripts/gighive/ansible/inventories/group_vars/all.yml`

**Purpose:** Provide global convenience variables and path roots shared by all environments.

**Key Settings:**
```yaml
# One true repo root
repo_root: "{{ lookup('env','GIGHIVE_HOME') | default((playbook_dir | dirname | dirname), true) }}"

# Convenience paths
roles_dir: "{{ repo_root }}/ansible/roles"
cloud_init_files_dir: "{{ roles_dir }}/cloud_init/files"
```

These values are referenced by other group vars (for example, `cloud_image_dir`, `cloud_image_vmdk`, and `nocloud_iso` in `group_vars/gighive/gighive.yml`) and by roles such as `cloud_init`.

**Example usage in `group_vars/gighive/gighive.yml`:**
```yaml
# vmdk/vdi specs for local vm
cloud_image_dir: "{{ cloud_init_files_dir }}"
cloud_image_vmdk: "{{ cloud_init_files_dir }}/{{ ubuntu_codename }}-server-cloudimg-amd64-{{ vm_name }}.vmdk"
cloud_image_vdi: "{{ cloud_init_files_dir }}/{{ ubuntu_codename }}-server-cloudimg-amd64-{{ vm_name }}.vdi"
nocloud_iso: "{{ cloud_init_files_dir }}/seed-{{ vm_name }}.iso"
```

#### 4.2 group_vars/gighive/gighive.yml

**Example: group_vars/gighive/gighive.yml**
```yaml
# VM Identity
hostname: "gighive"
vm_name: "gighive"
static_ip: "{{ ansible_host }}"        # References inventory value

# VirtualBox Configuration
disk_size_mb: 64000
bridge_iface: "enp8s0"
gateway: "192.168.1.1"

# Application Configuration
app_flavor: gighive                    # Determines which overlay files to use
database_full: false                   # Use sample database (not full dataset)

# Media Sync Configuration
sync_video: true
reduced_video: true                    # Use reduced video set from assets/
sync_audio: true
reduced_audio: true                    # Use reduced audio set from assets/

# Docker Rebuild Control
rebuild_mysql: false                   # Preserve MySQL data
rebuild_mysql_data: false              # Don't wipe database

# Authentication
admin_user: admin
viewer_user: viewer
uploader_user: uploader
gighive_admin_password: 
gighive_viewer_password
gighive_uploader_password:

# Paths (derived from site.yml pre_tasks)
gighive_htpasswd_host_path: "{{ docker_dir }}/apache/externalConfigs/gighive.htpasswd"
mysql_backup_script_dir: "{{ dbscripts_dir }}"
mysql_backups_dir: "{{ dbscripts_dir }}/backups"

# Upload Limits
upload_max_bytes: 6442450944           # 6GB
upload_max_mb: 6144
```

## Complete Execution Flow

### Command
```bash
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml \
                 ansible/playbooks/site.yml \
                 --ask-become-pass \
                 --skip-tags blobfuse2
```

### Step-by-Step Execution

1. **Ansible reads `ansible.cfg`**
   - Sets global defaults (SSH options, logging, callbacks)
   - Knows to look in `ansible/roles` for roles
   - Knows to look in `ansible/inventories` for inventory files

2. **Ansible loads `inventory_bootstrap.yml`**
   - Discovers group: `gighive`
   - Discovers host: `gighive_vm` at `192.168.1.248`
   - Notes that `gighive2` is a child of `target_vms`

3. **Ansible auto-loads `group_vars/all.yml`**
   - Global variables like `repo_root`, `roles_dir`, and `cloud_init_files_dir` are set

4. **Ansible auto-loads gighive group vars**
   - All variables from `group_vars/gighive/gighive.yml` (if present) and
     any files in `group_vars/gighive/` (for example
     `group_vars/gighive/gighive.yml`, `group_vars/gighive/secrets.yml`)
     become available to plays targeting `gighive`
   - Variables like `vm_name`, `hostname`, `app_flavor`, plus any
     MySQL credentials defined in a secrets file, are now set

5. **Ansible executes `site.yml` plays in order:**

   **Play 1: Provision VM in VirtualBox**
   - `hosts: gighive(:gighive2)` matches both the `gighive` and `gighive2` groups 
   - `connection: local` means run on controller (not VM)
   - Executes `cloud_init` role to create VM in VirtualBox
   - Uses variables from `group_vars/gighive/gighive.yml`

   **Play 2: Disable Cloud-Init inside VM**
   - `hosts: gighive(:gighive2)` matches both the `gighive` and `gighive2` groups 
   - Connects to newly created VM at `192.168.1.248`
   - Executes `cloud_init_disable` role

   **Play 3: Configure target VM**
   - `hosts: target_vms` matches because `gighive` is a child 
   - Runs all configuration roles: base, docker, security, etc.
   - Uses variables from `group_vars/gighive/gighive.yml`

## Supporting Multiple Configurations

The playbook primarily targets the `gighive` VM, but can optionally support a second `gighive2` VM:

### Typical run: gighive VM
```bash
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml \
                 ansible/playbooks/site.yml \
                 --ask-become-pass
```
- Loads `group_vars/gighive/gighive.yml`
- Creates VM named "gighive" at 192.168.1.248

### Optional: gighive2 VM (secondary/test)
```bash
ansible-playbook -i ansible/inventories/inventory_gighive2.yml \
                 ansible/playbooks/site.yml \
                 --ask-become-pass
```
- Loads `group_vars/gighive2.yml`
- Creates VM named "gighive2" at 192.168.1.254

## Common Patterns

### Host Pattern Matching in Playbooks

```yaml
# Match single group
hosts: gighive

# Match multiple groups (OR logic)
hosts: gighive:gighive2

# Match parent group (includes all children)
hosts: target_vms

# Match all hosts
hosts: all

# Match with wildcards
hosts: gighive*
```

### Variable Precedence (highest to lowest)

1. Extra vars (`-e` on command line)
2. Task vars
3. Block vars
4. Role vars
5. Play vars
6. Host vars (`host_vars/`)
7. **Group vars (`group_vars/`)** ← Most common for GigHive
8. Inventory vars
9. Role defaults

## Understanding Group Hierarchy and Playbook Execution

**This is a critical concept for understanding how Ansible executes playbooks in this environment.**

### The Key Principle: `target_vms` Controls Everything

The `target_vms` group is the **overarching parent group** that controls configuration and execution of the main playbooks. Individual child groups (like `gighive`, `gighive2`, `ubuntu`, `prod`) are just organizational labels.

### Group Hierarchy Structure

```yaml
all:
  children:
    target_vms:           ← This is what site.yml targets
      children:
        ubuntu:           ← Just a child member of target_vms
        gighive:          ← Also a child member of target_vms
        gighive2:         ← Also a child member of target_vms
        prod:             ← Would also be a child member of target_vms
```

### How Playbook Targeting Works

Looking at `site.yml`:

```yaml
- name: Configure target VM
  hosts: target_vms      ← Matches ALL children groups
  become: true
  roles:
    - base
    - docker
    - security_basic_auth
    # ... etc
```

**The playbook doesn't care what the child groups are named.** It just says "run on everything under `target_vms`".

### Why This Matters

When you have a host in any child group of `target_vms`:
- The playbook automatically targets it via `hosts: target_vms`
- All the same roles execute on it
- The only difference is **which `group_vars/` directory gets loaded**

For example:
- Host in `gighive` group → loads `group_vars/gighive/`
- Host in `gighive2` group → loads `group_vars/gighive2.yml`
- Host in `ubuntu` group → loads `group_vars/ubuntu/`
- Host in `prod` group → loads `group_vars/prod/`

### Practical Implications

1. **Renaming child groups is safe** - As long as the group remains a child of `target_vms`, the playbook will still target it
2. **Child group names are organizational** - They primarily determine which variables to load, not which tasks to run
3. **Consistency matters** - The child group name must match the `group_vars/` directory name for variables to load correctly

### Example: Adding a New Environment

To add a new "prod" environment:

1. Create inventory with `prod` as a child of `target_vms`:
   ```yaml
   all:
     children:
       target_vms:
         children:
           prod: {}
       prod:
         hosts:
           prod_server:
             ansible_host: 192.168.1.100
   ```

2. Create matching variables: `group_vars/prod/prod.yml`

3. Run the playbook - `hosts: target_vms` automatically includes it

No changes to `site.yml` are needed because it targets the parent group `target_vms`, not individual child groups.

### The Bottom Line

**The child group name is really just a label for:**
- Organizing hosts
- Determining which variables to load from `group_vars/`

**The actual execution is controlled by `target_vms`**, which is why all child groups get the same roles applied to them.

## Troubleshooting

### Problem: "Could not match supplied host pattern"

**Symptom:**
```
[WARNING]: Could not match supplied host pattern, ignoring: gighive
```

**Cause:** Playbook references a group name that doesn't exist in the inventory.

**Solution:** 
- Check `playbooks/site.yml` `hosts:` lines match group names in inventory
- Use `hosts: gighive:gighive2` to support both configurations

### Problem: Variables not loading

**Symptom:** Playbook fails with undefined variable errors.

**Cause:** Group vars file doesn't match the group name in inventory.

**Solution:**
- Inventory group: `gighive` → Must have `group_vars/gighive/gighive.yml`
- Inventory group: `gighive2` → Must have `group_vars/gighive2.yml`

### Problem: Wrong IP address

**Symptom:** Ansible connects to wrong host or can't connect.

**Cause:** `ansible_host` in inventory doesn't match actual VM IP.

**Solution:**
- Check VM's actual IP: `VBoxManage guestproperty enumerate <vm-name> | grep IP`
- Update `ansible_host` in inventory file to match

## Best Practices

1. **Always run from repo root** (`$GIGHIVE_HOME`)
   - All paths in `ansible.cfg` are relative to repo root

2. **Use descriptive group names**
   - Group name should match the VM purpose: `gighive`, `gighive2`, `prod`, etc.

3. **Keep group_vars files in sync**
   - When creating new inventory, create matching `group_vars/*.yml`

4. **Use parent groups for shared configuration**
   - `target_vms` contains common configuration for all VMs
   - Individual groups (`gighive`, `gighive2`) contain specific overrides

5. **Document IP addresses**
   - Keep a reference of which IP belongs to which VM configuration

## Reference: File Locations

```
$GIGHIVE_HOME/
├── ansible.cfg                                    # Global Ansible config
├── ansible/
│   ├── inventories/
│   │   ├── inventory_bootstrap.yml              # gighive group (192.168.1.248)
│   │   ├── inventory_gighive2.yml                # gighive2 group (192.168.1.254)
│   │   ├── inventory_baremetal.yml               # Bare metal hosts
│   │   ├── inventory_azure.yml                   # Azure cloud hosts
│   │   └── group_vars/
│   │       ├── all.yml                           # Variables for all hosts
│   │       ├── gighive/                          # Vars for gighive group
│   │       │   ├── gighive.yml                   # Main vars for gighive
│   │       │   └── secrets.yml                   # Optional secrets vars (plain or vaulted)
│   │       ├── gighive2.yml                      # Variables for gighive2 group
│   │       ├── prod.yml                          # Variables for prod group
│   │       └── ubuntu.yml                        # Variables for ubuntu group
│   ├── playbooks/
│   │   └── site.yml                              # Main orchestration playbook
│   └── roles/
│       ├── cloud_init/                           # VM provisioning role
│       ├── cloud_init_disable/                   # Cloud-init cleanup role
│       ├── base/                                 # Base system configuration
│       ├── docker/                               # Docker and containers
│       └── ...                                   # Other roles
└── docs/
    └── ANSIBLE_FILE_INTERACTION.md               # This document
```

## See Also

- [README.md](README.md) - Main project documentation
- [database-import-process.md](database-import-process.md) - Database CSV import process
- [STREAMING_ARCHITECTURE_20251008.md](protected/STREAMING_ARCHITECTURE_20251008.md) - Streaming architecture
