# htpasswd File Ownership and Permissions Changes

**Date**: 2025-11-28  
**Issue**: PHP script `changethepasswords.php` unable to write to htpasswd file  
**Error**: `Open for write failed (bind-mount or perms?): /var/www/private/gighive.htpasswd`

## Problem Description

When users attempted to change passwords via the web interface at `/changethepasswords.php`, the PHP script (running as `www-data`) could not write to the htpasswd file because:

1. Initial file was created as `root:root 0644`
2. After first successful write, file became `www-data:www-data 0644`
3. Subsequent writes failed due to permission inconsistencies

## Solution Implemented: Option B (Temporary)

We implemented **Option B** to allow web-based password changes while transitioning to database-managed passwords in the near future.

### Target Configuration
- **Owner**: `www-data`
- **Group**: `www-data`
- **Mode**: `0640` (owner read/write, group read, no world access)

### Industry Best Practice (for reference)
The canonical best practice from Apache/Ansible documentation is:
- **Owner**: `root`
- **Group**: `www-data`
- **Mode**: `0640`

This prevents web processes from modifying the auth file, but requires password changes via Ansible or command-line `htpasswd` utility only.

## Changes Made

### 1. `/home/sodo/scripts/gighive/ansible/roles/docker/tasks/main.yml`

**Lines 24-37** - Initial file creation and ownership enforcement:

**BEFORE:**
```yaml
- name: Ensure host htpasswd file exists (correct type)
  ansible.builtin.file:
    path: "{{ gighive_htpasswd_host_path }}"
    state: touch
    mode: "0644"   # 0644 avoids group-existence issues across distros
```

**AFTER:**
```yaml
- name: Create host htpasswd file if missing
  ansible.builtin.file:
    path: "{{ gighive_htpasswd_host_path }}"
    state: touch
    mode: "0640"
  when: not ghp.stat.exists

- name: Ensure host htpasswd file has correct ownership and permissions
  ansible.builtin.file:
    path: "{{ gighive_htpasswd_host_path }}"
    state: file
    owner: www-data
    group: www-data
    mode: "0640"
```

**Key Change**: Split into two tasks because `state: touch` doesn't reliably apply ownership to existing files. The second task with `state: file` enforces ownership and permissions on every Ansible run.

### 2. `/home/sodo/scripts/gighive/ansible/roles/security_basic_auth/tasks/main.yml`

**Lines 53-62** - Admin user creation:

**BEFORE:**
```yaml
- name: Create/Update admin user in host htpasswd (bcrypt)
  community.general.htpasswd:
    path: "{{ gighive_htpasswd_host_path }}"
    name: "{{ admin_user | default('admin') }}"
    password: "{{ gighive_admin_password }}"
    crypt_scheme: bcrypt
    owner: "{{ gighive_htpasswd_owner | default('root') }}"
    group: "{{ gighive_htpasswd_group | default('www-data') }}"
    mode: "{{ gighive_htpasswd_mode  | default('0640') }}"
```

**AFTER:**
```yaml
- name: Create/Update admin user in host htpasswd (bcrypt)
  community.general.htpasswd:
    path: "{{ gighive_htpasswd_host_path }}"
    name: "{{ admin_user | default('admin') }}"
    password: "{{ gighive_admin_password }}"
    crypt_scheme: bcrypt
    owner: "{{ gighive_htpasswd_owner | default('www-data') }}"  # Changed from 'root'
    group: "{{ gighive_htpasswd_group | default('www-data') }}"
    mode: "{{ gighive_htpasswd_mode  | default('0640') }}"
  become: true  # Added - required to set www-data ownership
```

**Similar changes** were made to:
- Viewer user task (lines 64-77): Changed owner default to `www-data`, added `become: true`
- Uploader user task (lines 79-92): Changed owner default to `www-data`, added `become: true`

**Key Addition**: `become: true` is critical - without it, the tasks run as the ansible_user and cannot set ownership to www-data, causing the file to revert to root ownership.

### 3. `/home/sodo/scripts/gighive/ansible/roles/post_build_checks/tasks/main.yml`

**Lines 95-105** - Removed conflicting task:

**BEFORE:**
```yaml
# keep host perms predictable
- name: Ensure htpasswd ownership/perms on host
  ansible.builtin.file:
    path: "{{ gighive_htpasswd_host_path }}"
    owner: root
    group: root
    mode: "0644"
    state: file
  when: ht_host.stat.exists
  changed_when: false
  failed_when: false
  become: true
```

**AFTER:**
```yaml
# Task removed - post_build_checks should only validate, not modify
```

**Reason**: This task was resetting the file to `root:root 0644` after `security_basic_auth` correctly set it to `www-data:www-data 0640`. The `post_build_checks` role should only perform validation, not modify state.

### 4. `/home/sodo/scripts/gighive/ansible/roles/docker/files/apache/webroot/changethepasswords.php`

**Line 79** - In-place write path:

**BEFORE:**
```php
fclose($fh);
@chmod($path, 0664);
return;
```

**AFTER:**
```php
fclose($fh);
@chmod($path, 0640);  // Changed from 0664 to 0640
return;
```

**Line 104** - Atomic replace path:

**BEFORE:**
```php
@chmod($path, 0664);
```

**AFTER:**
```php
@chmod($path, 0640);  // Changed from 0664 to 0640
```

## How to Revert

If you need to revert to strict best practice (Option A):

### 1. Revert Ansible files

```yaml
# docker/tasks/main.yml line 26-28
mode: "0640"
owner: root      # Change back to root
group: www-data  # Keep as www-data

# security_basic_auth/tasks/main.yml lines 59, 70, 85
owner: "{{ gighive_htpasswd_owner | default('root') }}"  # Change back to 'root'
```

### 2. Revert PHP file

```php
// changethepasswords.php lines 79, 104
@chmod($path, 0640);  // Keep as 0640
// Note: PHP won't be able to write anymore - disable the web interface
```

### 3. Disable web password changes

Comment out or remove the password change functionality from the web interface, or add a check that prevents it from running.

## Alternative Options Considered

### Option A: Strict Best Practice (root:www-data 0640)
- **Pros**: Most secure, follows Apache best practices
- **Cons**: Disables web-based password changes
- **Use case**: Production systems with admin-only password management

### Option C: Hybrid Approach (root:www-data 0660)
- **Pros**: Maintains root ownership, allows web writes via group permission
- **Cons**: Group-writable files are less common, may confuse auditors
- **Use case**: Middle ground between security and convenience

## Migration Path to Database Authentication

When migrating to database-managed passwords:

1. Implement database authentication system
2. Migrate existing htpasswd users to database
3. Update Apache configuration to use database authentication module
4. Remove or archive htpasswd file
5. Remove `changethepasswords.php` script
6. Revert these changes or remove htpasswd-related Ansible tasks

## Security Considerations

**Current Setup (Option B)**:
- ✅ Apache can read the file (owner permission)
- ✅ PHP can write the file (owner permission)
- ✅ No world access (secure from other users)
- ⚠️ Web process can modify authentication file (less secure than Option A)
- ✅ File is outside web document root (`/var/www/private/`)
- ✅ Apache blocks direct access to `.ht*` files

**Risk Assessment**: Low to Medium
- File is not web-accessible
- Only authenticated admin users can access the password change page
- Temporary solution until database authentication is implemented

## Testing After Changes

1. Run Ansible playbook to recreate htpasswd file with new permissions
2. Verify file ownership: `ls -la /var/www/private/gighive.htpasswd` (inside container)
3. Should show: `-rw-r----- 1 www-data www-data <size> <date> gighive.htpasswd`
4. Test password change via web interface
5. Verify file permissions remain `0640` after change
6. Verify file ownership remains `www-data:www-data` after change
7. Test authentication with new passwords

## References

- [Apache Authentication Documentation](https://httpd.apache.org/docs/2.4/howto/auth.html)
- [Ansible htpasswd Module](https://docs.ansible.com/projects/ansible/latest/collections/community/general/htpasswd_module.html)
- [Apache Security Tips](https://httpd.apache.org/docs/2.4/misc/security_tips.html)
