# Refactor: Parameterize `www-data` References in Ansible Roles

## Status
Deferred — duplicate key cleaned up (see below); full parameterization is future work.

## Completed
Removed duplicate hardcoded `apache_group: www-data` from all three group_vars files:
- `ansible/inventories/group_vars/gighive2/gighive2.yml`
- `ansible/inventories/group_vars/gighive/gighive.yml`
- `ansible/inventories/group_vars/prod/prod.yml`

The surviving definition in each file is the dynamic one:
```yaml
apache_group: "{{ www_group | default('www-data') }}"
```
This follows the standard Ansible override pattern — falls back to `www-data` unless `www_group` is explicitly set in a group_vars file.

## Scope of Remaining Hardcoded `www-data` References

`www-data` is hardcoded in **9 files** across the docker role:

### Templates/configs (container image or runtime)
- `templates/apache2.conf.j2` — `User` and `Group` directives
- `templates/www.conf.j2` — php-fpm pool `user`, `group`, `listen.owner`, `listen.group`
- `templates/entrypoint.sh.j2` — 8x `chown www-data:www-data` for runtime dirs
- `files/apache/Dockerfile` — `chown` during image build
- `files/apache/externalConfigs/apache2-logrotate.conf` — `create 640 www-data www-data`

### Ansible tasks
- `tasks/main.yml` — `owner: www-data` / `group: www-data` on htpasswd file and restorelogs dir

### Shell/Python tools (run outside the container)
- `files/one_shot_bundle/rotate_basic_auth.sh` — `chown www-data:www-data` on htpasswd file
- `files/apache/webroot/tools/upload_media_by_hash.py` — `chown www-data:www-data` on remote paths
- `files/apache/webroot/tools/replace_existing_media.py` — same pattern

## Analysis

Most of these are inside the container context where `www-data` is correct and fixed (Debian/Ubuntu Apache default). The only ones that would realistically need `www_group` parameterization are the Ansible `tasks/main.yml` entries that touch host-side files.

The container-internal ones (`Dockerfile`, `entrypoint.sh.j2`, `www.conf.j2`, `apache2.conf.j2`) are always `www-data` by definition since the image is Debian-based — parameterizing those would add complexity with no practical benefit.
