# Refactor: Security Upgrade — SSH Key Type Migration (RSA → ED25519)

## Status: Pending

## Background

All SDLC VMs (dev, lab, staging, prod) are provisioned via cloud-init using
`ansible/roles/cloud_init`. The authorized SSH public key is currently hardcoded
to `~/.ssh/id_rsa.pub` in `cloud_init/tasks/main.yml`. This means:

- Changing key type requires editing task code, not configuration.
- All environments implicitly use RSA regardless of intent.
- A future key rotation or algorithm upgrade requires a code change.

The lab VM (`labvm.gighive.internal`, 192.168.1.252) was found to have only an
`id_ed25519` key in its `authorized_keys` (cause unknown — likely provisioned or
modified outside the standard cloud-init flow). The RSA key was subsequently added
via `ssh-copy-id`. This discrepancy triggered the investigation.

## Goal

Move the SSH public key file path to a `group_vars` variable so:
- The key type is configuration, not code.
- Individual environments can override the default.
- A fleet-wide migration from RSA to ED25519 is a single-line change in `all.yml`.

---

## Current State

**`ansible/roles/cloud_init/tasks/main.yml` (line 340):**
```yaml
- name: Read SSH public key
  set_fact:
    my_ssh_key: "{{ lookup('file', lookup('env','HOME') + '/.ssh/id_rsa.pub') }}"
```

`id_rsa.pub` is hardcoded. The value is passed to `user-data.j2` via `my_ssh_key`,
which is already correctly templated.

**`ansible/roles/cloud_init/templates/user-data.j2` (line 26):**
```yaml
    ssh_authorized_keys:
      - {{ my_ssh_key | quote }}
```

No change needed here — already uses the variable.

---

## Proposed Changes

### Step 1 — `ansible/inventories/group_vars/all.yml`

Add a new variable that expresses the default key path:

```yaml
# SSH public key used when provisioning new VMs via cloud-init.
# Override per-environment in group_vars/<env>/<env>.yml when migrating to ed25519.
# set_fact with lookup('file') always reports 'changed' — this is expected for one-shot provisioning.
ssh_public_key_file: "~/.ssh/id_rsa.pub"
```

### Step 2 — `ansible/roles/cloud_init/tasks/main.yml`

Replace the hardcoded path with the variable:

```yaml
- name: Read SSH public key
  set_fact:
    my_ssh_key: "{{ lookup('file', ssh_public_key_file) }}"
```

### Step 3 — Per-environment override (future, when migrating)

In the target environment's group_vars (e.g. `group_vars/gighive2/gighive2.yml` for dev):

```yaml
ssh_public_key_file: "~/.ssh/id_ed25519.pub"
```

Or change `all.yml` globally once the entire fleet is migrated.

---

## Files Affected

| File | Change |
|------|--------|
| `ansible/inventories/group_vars/all.yml` | Add `ssh_public_key_file` variable |
| `ansible/roles/cloud_init/tasks/main.yml` | Use `ssh_public_key_file` instead of hardcoded path |
| `ansible/roles/cloud_init/templates/user-data.j2` | No change needed for key migration; see fqdn note below |
| `ansible/roles/cloud_init/files/user-data` | Dead code — superseded by `user-data.j2`; safe to remove |

---

## SDLC Environment Reference

| Environment | Host FQDN | VM FQDN | IP | group_vars | Current Key |
|---|---|---|---|---|---|
| dev | pop-os (192.168.1.235) | devvm.gighive.internal | 192.168.1.50 | `gighive2/` | id_rsa |
| lab | lab.gighive.internal (192.168.1.233) | labvm.gighive.internal | 192.168.1.252 | `gighive/` | id_rsa (fixed via ssh-copy-id) |
| staging | staging.gighive.internal (192.168.1.231) | stagingvm.gighive.internal | 192.168.1.248 | `gighive/` | id_rsa |
| prod | prod.gighive.internal (192.168.1.227) | — | — | `prod/` | id_rsa |

---

## Known Issues Not in Scope (track separately)

### `fqdn` domain in `user-data.j2`
`fqdn: {{ hostname }}.mysettings.com` uses `.mysettings.com` but actual environment
FQDNs use `.gighive.internal`. Fix during any full VM re-provision.

### `nat.yml` and `test.yml` hardcode `id_rsa`
Two other task files in the `cloud_init` role generate `user-data` inline with
hardcoded SSH key content. They are out of scope for this refactor but should be
updated when those paths are used.

### Console password backdoor — move to secrets
See section below.

---

## Console Password Backdoor

`user-data.j2` sets a local account password (`ubuntu:yoboiboi`) as a VirtualBox
console recovery method. This is intentional and useful — it provides access when
SSH key auth fails. However, the password is **plaintext in a version-controlled
template**, which is a security risk.

**Fix:** move to `secrets.yml` (ansible-vault encrypted).

### Step — add to each environment's `secrets.yml`

```yaml
vm_console_password: "<new-strong-password>"
```

### Step — update `user-data.j2`

Replace:
```yaml
chpasswd:
  expire: false
  list:
    - ubuntu:yoboiboi
```
With:
```yaml
chpasswd:
  expire: false
  list:
    - "ubuntu:{{ vm_console_password }}"
```

Files affected: `ansible/roles/cloud_init/templates/user-data.j2`,
all `secrets.yml` files (`gighive2/`, `gighive/`, `prod/`).

Note: `gighive/secrets.yml` covers both lab and staging (they share the same group_vars directory).

---

## Implementation Checklist

### SSH Key Type Migration
- [ ] Generate `id_ed25519` keypair if not present: `ssh-keygen -t ed25519 -C "sodo@pop-os"`
- [ ] Add `ssh_public_key_file` to `group_vars/all.yml`
- [ ] Update `cloud_init/tasks/main.yml` to use `ssh_public_key_file`
- [ ] Delete dead code: `ansible/roles/cloud_init/files/user-data`
- [ ] **Existing VMs** — add ed25519 key and remove RSA (do NOT re-provision):
  - `ssh-copy-id -i ~/.ssh/id_ed25519.pub <host>` for each VM
  - Remove old RSA entry from `~/.ssh/authorized_keys` on each VM
  - Update `~/.ssh/config` `IdentityFile` to `id_ed25519` for each host
  - Verify `ssh <host> echo ok`
- [ ] **New VMs** — cloud-init will use `id_ed25519` automatically after `all.yml` change

### Console Password
- [ ] Generate a strong replacement password
- [ ] Add `vm_console_password` to each environment's `secrets.yml` (ansible-vault)
- [ ] Update `user-data.j2` to use `{{ vm_console_password }}`
- [ ] Re-provision new VMs to pick up the change (existing VMs: update manually via console)
