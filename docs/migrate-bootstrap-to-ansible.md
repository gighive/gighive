# Migrate Bootstrap to Ansible Implementation Plan

## Overview

This document outlines the detailed implementation plan for replacing GigHive's bash script entry points with Ansible-first bootstrap playbooks. This approach preserves the existing sophisticated infrastructure while eliminating brittleness through proper Ansible orchestration.

## Architecture Overview

The Ansible-first approach creates a **unified entry point** that orchestrates the existing sophisticated infrastructure through proper Ansible playbooks instead of brittle bash scripts.

### New Playbook Structure

```
ansible/playbooks/
├── bootstrap.yml           # Main entry point (replaces all 3 bash scripts)
├── prerequisites.yml       # Replaces 1prereqsInstall.sh
├── infrastructure.yml      # Replaces 2bootstrap.sh
├── teardown.yml           # Replaces 3deleteAll.sh
└── site.yml              # Existing application deployment (unchanged)
```

## Implementation Details

### 1. Prerequisites Role (Replaces `1prereqsInstall.sh`)

Create `ansible/roles/prerequisites/` with these capabilities:

#### `ansible/roles/prerequisites/tasks/main.yml`
```yaml
---
- name: Detect Linux distribution
  setup:
    gather_subset: 
      - "!all"
      - "!min" 
      - "distribution"

- name: Fail if unsupported distribution
  fail:
    msg: "Unsupported distribution: {{ ansible_distribution }} {{ ansible_distribution_version }}"
  when: not (ansible_distribution == "Ubuntu" and ansible_distribution_version is version('20.04', '>='))

- name: Install base packages
  apt:
    name: "{{ prerequisite_packages }}"
    state: present
    update_cache: yes
  become: yes

- name: Add Docker repository
  block:
    - name: Add Docker GPG key
      apt_key:
        url: https://download.docker.com/linux/ubuntu/gpg
        keyring: /etc/apt/keyrings/docker.gpg
        state: present

    - name: Add Docker repository
      apt_repository:
        repo: "deb [arch=amd64 signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu {{ ansible_distribution_release }} stable"
        state: present
        filename: docker

- name: Install Docker packages
  apt:
    name: "{{ docker_packages }}"
    state: present
    update_cache: yes
  become: yes

- name: Install Python packages in virtual environment
  pip:
    name: "{{ python_packages }}"
    virtualenv: "{{ ansible_venv_path }}"
    virtualenv_python: python3
  when: create_python_venv | default(true)

- name: Install Terraform
  include_tasks: terraform.yml
  when: install_terraform | default(true)

- name: Verify installations
  include_tasks: verify.yml
```

#### `ansible/roles/prerequisites/vars/main.yml`
```yaml
---
prerequisite_packages:
  - python3
  - python3-pip 
  - python3-venv
  - curl
  - gnupg
  - software-properties-common
  - lsb-release
  - ca-certificates
  - apt-transport-https
  - genisoimage
  - cloud-image-utils

docker_packages:
  - docker-ce
  - docker-ce-cli
  - containerd.io
  - docker-compose-plugin

python_packages:
  - ansible
  - azure-cli
  - azure-mgmt-resource
  - azure-storage-blob
  - docker-compose

ansible_venv_path: "{{ ansible_env.HOME }}/.ansible-azure"
```

#### `ansible/roles/prerequisites/tasks/terraform.yml`
```yaml
---
- name: Add HashiCorp GPG key
  apt_key:
    url: https://apt.releases.hashicorp.com/gpg
    keyring: /usr/share/keyrings/hashicorp-archive-keyring.gpg
    state: present
  become: yes

- name: Add HashiCorp repository
  apt_repository:
    repo: "deb [signed-by=/usr/share/keyrings/hashicorp-archive-keyring.gpg] https://apt.releases.hashicorp.com {{ ansible_distribution_release }} main"
    state: present
    filename: hashicorp
  become: yes

- name: Install Terraform
  apt:
    name: terraform
    state: present
    update_cache: yes
  become: yes
```

#### `ansible/roles/prerequisites/tasks/verify.yml`
```yaml
---
- name: Verify Docker installation
  command: docker --version
  register: docker_version
  changed_when: false

- name: Verify Docker Compose installation
  command: docker compose version
  register: compose_version
  changed_when: false

- name: Verify Terraform installation
  command: terraform version
  register: terraform_version
  changed_when: false
  when: install_terraform | default(true)

- name: Verify Azure CLI installation
  command: "{{ ansible_venv_path }}/bin/az version"
  register: az_version
  changed_when: false
  when: create_python_venv | default(true)

- name: Display installation verification
  debug:
    msg:
      - "Docker: {{ docker_version.stdout }}"
      - "Docker Compose: {{ compose_version.stdout }}"
      - "Terraform: {{ terraform_version.stdout | default('Not installed') }}"
      - "Azure CLI: {{ 'Installed' if az_version.rc == 0 else 'Not installed' }}"
```

### 2. Infrastructure Role (Replaces `2bootstrap.sh`)

Create `ansible/roles/infrastructure/` for cloud resource management:

#### `ansible/roles/infrastructure/tasks/main.yml`
```yaml
---
- name: Validate Azure credentials
  azure.azcollection.azure_rm_resourcegroup_info:
    name: "{{ terraform_backend_rg }}"
  register: rg_check
  failed_when: false
  when: deployment_target == "azure"

- name: Authenticate to Azure if needed
  block:
    - name: Check existing Azure session
      command: az account show
      register: az_account
      failed_when: false
      changed_when: false

    - name: Login to Azure
      command: az login --use-device-code
      when: az_account.rc != 0
  when: deployment_target == "azure"

- name: Create Terraform backend resources
  include_tasks: terraform_backend.yml
  when: deployment_target == "azure"

- name: Initialize Terraform
  terraform:
    project_path: "{{ terraform_dir }}"
    state: present
    backend_config: "{{ terraform_backend_config }}"
    variables: "{{ terraform_variables }}"
    plan_file: "{{ terraform_dir }}/tfplan"
  register: terraform_plan

- name: Show Terraform plan
  debug:
    var: terraform_plan.stdout_lines

- name: Apply Terraform plan
  terraform:
    project_path: "{{ terraform_dir }}"
    state: present
    plan_file: "{{ terraform_dir }}/tfplan"
  when: 
    - terraform_plan.changed
    - auto_apply_terraform | default(false) or (ansible_interactive | default(true) and (user_confirm_apply | default('') | lower in ['y', 'yes']))

- name: Extract infrastructure outputs
  terraform:
    project_path: "{{ terraform_dir }}"
    state: present
  register: terraform_outputs

- name: Update Ansible inventory with new IP
  include_tasks: update_inventory.yml
  when: terraform_outputs.outputs.vm_public_ip is defined
```

#### `ansible/roles/infrastructure/tasks/terraform_backend.yml`
```yaml
---
- name: Set subscription
  command: az account set --subscription "{{ azure_subscription_id }}"
  when: azure_subscription_id is defined

- name: Create backend resource group
  azure.azcollection.azure_rm_resourcegroup:
    name: "{{ terraform_backend_rg }}"
    location: "{{ terraform_backend_location | default('eastus') }}"
    state: present

- name: Create backend storage account
  azure.azcollection.azure_rm_storageaccount:
    resource_group: "{{ terraform_backend_rg }}"
    name: "{{ terraform_backend_storage_account }}"
    location: "{{ terraform_backend_location | default('eastus') }}"
    account_type: Standard_LRS
    kind: StorageV2
    state: present

- name: Create backend storage container
  azure.azcollection.azure_rm_storageblob:
    resource_group: "{{ terraform_backend_rg }}"
    storage_account_name: "{{ terraform_backend_storage_account }}"
    container: "{{ terraform_backend_container }}"
    state: present
```

#### `ansible/roles/infrastructure/tasks/update_inventory.yml`
```yaml
---
- name: Generate inventory from template
  template:
    src: inventory_azure.yml.j2
    dest: "{{ gighive_home }}/ansible/inventories/inventory_azure.yml"
    backup: yes
  vars:
    vm_public_ip: "{{ terraform_outputs.outputs.vm_public_ip.value }}"

- name: Display updated inventory
  debug:
    msg: "Updated inventory with VM IP: {{ terraform_outputs.outputs.vm_public_ip.value }}"
```

### 3. Main Bootstrap Playbook

#### `ansible/playbooks/bootstrap.yml`
```yaml
---
- name: GigHive Bootstrap - Prerequisites
  hosts: localhost
  connection: local
  gather_facts: yes
  vars:
    deployment_target: "{{ target | default('virtualbox') }}"
  roles:
    - role: prerequisites
      tags: [prereqs]
  tags: [bootstrap, prereqs]

- name: GigHive Bootstrap - Infrastructure  
  hosts: localhost
  connection: local
  vars:
    deployment_target: "{{ target | default('virtualbox') }}"
  roles:
    - role: infrastructure
      tags: [infra]
  when: deployment_target in ['azure', 'virtualbox']
  tags: [bootstrap, infra]

- name: GigHive Bootstrap - Application Deployment
  import_playbook: site.yml
  vars:
    deployment_target: "{{ target | default('virtualbox') }}"
  tags: [bootstrap, deploy]
```

#### `ansible/playbooks/teardown.yml` (Replaces `3deleteAll.sh`)
```yaml
---
- name: GigHive Infrastructure Teardown
  hosts: localhost
  connection: local
  gather_facts: no
  vars:
    deployment_target: "{{ target | default('azure') }}"
  tasks:
    - name: Confirm teardown
      pause:
        prompt: "Are you sure you want to destroy ALL resources in {{ deployment_target }}? (yes/no)"
      register: confirm_teardown
      when: not force_teardown | default(false)

    - name: Fail if not confirmed
      fail:
        msg: "Teardown cancelled by user"
      when: 
        - not force_teardown | default(false)
        - confirm_teardown.user_input | lower != 'yes'

    - name: Destroy Terraform infrastructure
      terraform:
        project_path: "{{ terraform_dir }}"
        state: absent
        force_init: true
      when: deployment_target == "azure"

    - name: Monitor teardown progress
      include_tasks: monitor_teardown.yml
      when: deployment_target == "azure"
```

## Usage Examples

### Complete Bootstrap (All-in-One)
```bash
# VirtualBox deployment
ansible-playbook ansible/playbooks/bootstrap.yml -e target=virtualbox

# Azure deployment  
ansible-playbook ansible/playbooks/bootstrap.yml -e target=azure -e auto_apply_terraform=true

# Bare metal deployment
ansible-playbook ansible/playbooks/bootstrap.yml -e target=baremetal -i ansible/inventories/inventory_baremetal.yml
```

### Selective Execution
```bash
# Only install prerequisites
ansible-playbook ansible/playbooks/bootstrap.yml --tags prereqs

# Only infrastructure provisioning
ansible-playbook ansible/playbooks/bootstrap.yml --tags infra -e target=azure

# Skip prerequisites (already installed)
ansible-playbook ansible/playbooks/bootstrap.yml --skip-tags prereqs
```

### Environment-Specific Deployment
```bash
# Development environment
ansible-playbook ansible/playbooks/bootstrap.yml -e target=virtualbox -e database_full=false

# Production environment  
ansible-playbook ansible/playbooks/bootstrap.yml -e target=azure -e database_full=true -e app_flavor=gighive
```

### Teardown Operations
```bash
# Interactive teardown with confirmation
ansible-playbook ansible/playbooks/teardown.yml -e target=azure

# Force teardown without confirmation
ansible-playbook ansible/playbooks/teardown.yml -e target=azure -e force_teardown=true
```

## Configuration Management

### Environment Variables → Ansible Variables

Replace environment variable dependencies with Ansible variable precedence:

#### `ansible/inventories/group_vars/all.yml`
```yaml
---
# Global defaults
gighive_home: "{{ ansible_env.HOME }}/scripts/gighive"
terraform_dir: "{{ gighive_home }}/terraform"
ansible_venv_path: "{{ ansible_env.HOME }}/.ansible-azure"

# Azure configuration (can be overridden via -e or vault)
azure_subscription_id: "{{ lookup('env', 'ARM_SUBSCRIPTION_ID') | default('') }}"
azure_tenant_id: "{{ lookup('env', 'ARM_TENANT_ID') | default('') }}"

# Terraform backend configuration
terraform_backend_rg: "gighive-terraform-backend"
terraform_backend_storage_account: "gighiveterraformstate"
terraform_backend_container: "tfstate"
terraform_backend_key: "gighive.tfstate"

# Deployment behavior
auto_apply_terraform: false
create_python_venv: true
install_terraform: true
```

### Credential Management with Ansible Vault

```bash
# Create encrypted credentials file
ansible-vault create ansible/inventories/group_vars/vault.yml

# Content of vault.yml:
azure_subscription_id: "your-subscription-id"
azure_tenant_id: "your-tenant-id"
gighive_admin_password: "secure-password"
gighive_viewer_password: "secure-password"
gighive_uploader_password: "secure-password"
```

#### Using vault in deployment
```bash
# Deploy with vault
ansible-playbook ansible/playbooks/bootstrap.yml --ask-vault-pass -e target=azure

# Or with vault password file
ansible-playbook ansible/playbooks/bootstrap.yml --vault-password-file ~/.vault_pass -e target=azure
```

## Error Handling & Validation

### Pre-flight Checks
```yaml
- name: Validate prerequisites
  block:
    - name: Check GIGHIVE_HOME is accessible
      stat:
        path: "{{ gighive_home }}"
      register: gighive_home_stat
      failed_when: not gighive_home_stat.stat.exists

    - name: Verify SSH keys exist
      stat:
        path: "{{ gighive_home }}/ssh/{{ item }}"
      register: ssh_key_check
      failed_when: not ssh_key_check.stat.exists
      loop:
        - id_rsa.pub
        - id_ed25519.pub
      ignore_errors: yes

    - name: Fail if no SSH keys found
      fail:
        msg: "No SSH public keys found in {{ gighive_home }}/ssh/"
      when: ssh_key_check.results | selectattr('stat.exists') | list | length == 0

    - name: Validate Azure credentials
      command: az account show
      register: az_account_check
      failed_when: false
      changed_when: false
      when: deployment_target == "azure"

    - name: Warn about Azure authentication
      debug:
        msg: "Azure CLI not authenticated. Will prompt for device code login."
      when: 
        - deployment_target == "azure"
        - az_account_check.rc != 0
```

### Rollback Capabilities
```yaml
- name: Infrastructure rollback
  block:
    - name: Destroy Terraform resources
      terraform:
        project_path: "{{ terraform_dir }}"
        state: absent
        force_init: true
      when: rollback_infrastructure | default(false)

    - name: Clean up local state
      file:
        path: "{{ item }}"
        state: absent
      loop:
        - "{{ terraform_dir }}/.terraform"
        - "{{ terraform_dir }}/tfplan"
      when: clean_local_state | default(false)

    - name: Remove generated inventory
      file:
        path: "{{ gighive_home }}/ansible/inventories/inventory_azure.yml"
        state: absent
      when: cleanup_inventory | default(false)
  rescue:
    - name: Log rollback failure
      debug:
        msg: "Rollback failed, manual cleanup may be required"
      
    - name: Display manual cleanup instructions
      debug:
        msg:
          - "Manual cleanup may be required:"
          - "1. Check Azure portal for remaining resources"
          - "2. Remove {{ terraform_dir }}/.terraform directory"
          - "3. Remove {{ terraform_dir }}/tfplan file"
```

### Post-Deployment Validation
```yaml
- name: Validate deployment
  block:
    - name: Wait for VM to be accessible
      wait_for:
        host: "{{ terraform_outputs.outputs.vm_public_ip.value }}"
        port: 22
        timeout: 300
      when: deployment_target == "azure"

    - name: Test SSH connectivity
      command: ssh -o ConnectTimeout=10 -o StrictHostKeyChecking=no {{ ansible_user }}@{{ terraform_outputs.outputs.vm_public_ip.value }} echo "SSH OK"
      register: ssh_test
      when: deployment_target == "azure"

    - name: Validate application deployment
      uri:
        url: "https://{{ gighive_host }}"
        validate_certs: no
        status_code: [200, 401]  # 401 is expected due to basic auth
      register: app_test
      when: deployment_target in ["azure", "virtualbox"]

    - name: Display deployment summary
      debug:
        msg:
          - "Deployment completed successfully!"
          - "Target: {{ deployment_target }}"
          - "VM IP: {{ terraform_outputs.outputs.vm_public_ip.value | default('N/A') }}"
          - "Application URL: https://{{ gighive_host }}"
          - "SSH Access: ssh {{ ansible_user }}@{{ terraform_outputs.outputs.vm_public_ip.value | default('N/A') }}"
```

## Integration with Existing Infrastructure

### Preserve Current Workflow

The Ansible-first approach **enhances** rather than replaces existing sophisticated infrastructure:

1. **Keep all existing Ansible roles** - no changes needed to base, docker, security roles
2. **Preserve Docker orchestration** - same containers, same compose files  
3. **Maintain Terraform infrastructure** - same resource definitions in main.tf
4. **Keep inventory structure** - same environment separation and group_vars

### Backward Compatibility
```bash
# Traditional approach still works
ansible-playbook -i ansible/inventories/inventory_azure.yml ansible/playbooks/site.yml

# New unified approach
ansible-playbook ansible/playbooks/bootstrap.yml -e target=azure
```

### Template Files

#### `ansible/roles/infrastructure/templates/inventory_azure.yml.j2`
```yaml
all:
  hosts:
    localhost:
      ansible_connection: local
      ansible_python_interpreter: {{ ansible_venv_path }}/bin/python

    azure_vm:
      ansible_host: {{ vm_public_ip }}
      ansible_user: azureuser
      ansible_python_interpreter: /usr/bin/python3
      ansible_ssh_common_args: '-o StrictHostKeyChecking=no'
      ansible_become: yes

  children:
    gighive:
      hosts:
        azure_vm:

    target_vms:
      children:
        gighive:
```

## Benefits of This Approach

1. **Idempotent Operations**: Safe to run multiple times without side effects
2. **Error Recovery**: Proper failure handling and rollback capabilities
3. **Environment Consistency**: Same process across VirtualBox, Azure, bare metal
4. **Credential Security**: Ansible Vault integration for sensitive data
5. **Selective Execution**: Tag-based deployment control for targeted operations
6. **Validation**: Pre-flight and post-deployment checks
7. **Logging**: Structured logging and audit trails
8. **CI/CD Ready**: Easy integration with automation pipelines
9. **Rollback Capability**: Safe infrastructure teardown and cleanup
10. **Documentation**: Self-documenting infrastructure through Ansible tasks

## Migration Path

### Phase 1: Create Prerequisites Role (Week 1-2)
- Create `ansible/roles/prerequisites/` alongside existing bash scripts
- Test prerequisite installation on clean systems
- Validate against existing `1prereqsInstall.sh` functionality

### Phase 2: Add Infrastructure Role (Week 2-3)
- Create `ansible/roles/infrastructure/` for Terraform orchestration
- Test Azure resource provisioning
- Validate against existing `2bootstrap.sh` functionality

### Phase 3: Create Unified Bootstrap (Week 3-4)
- Create `ansible/playbooks/bootstrap.yml` main entry point
- Test complete end-to-end deployment
- Validate against existing workflow

### Phase 4: Add Teardown Capabilities (Week 4)
- Create `ansible/playbooks/teardown.yml`
- Test infrastructure destruction
- Validate against existing `3deleteAll.sh` functionality

### Phase 5: Deprecate Bash Scripts (Week 5)
- Update documentation to use new Ansible approach
- Keep bash scripts as backup during transition period
- Monitor for any edge cases or missing functionality

### Phase 6: Enhanced Features (Week 6+)
- Add Ansible Vault for credential management
- Implement monitoring and alerting
- Add CI/CD pipeline integration
- Create automated testing framework

## Testing Strategy

### Unit Testing
```bash
# Test individual roles
ansible-playbook ansible/playbooks/bootstrap.yml --tags prereqs --check
ansible-playbook ansible/playbooks/bootstrap.yml --tags infra --check
```

### Integration Testing
```bash
# Test complete workflow in check mode
ansible-playbook ansible/playbooks/bootstrap.yml -e target=azure --check

# Test with minimal deployment
ansible-playbook ansible/playbooks/bootstrap.yml -e target=virtualbox -e database_full=false
```

### Validation Testing
```bash
# Test teardown and rebuild
ansible-playbook ansible/playbooks/teardown.yml -e target=azure -e force_teardown=true
ansible-playbook ansible/playbooks/bootstrap.yml -e target=azure
```

This comprehensive migration plan preserves your sophisticated architecture while eliminating the brittleness of bash script entry points through proper Ansible orchestration, providing a robust, maintainable, and scalable deployment solution.
