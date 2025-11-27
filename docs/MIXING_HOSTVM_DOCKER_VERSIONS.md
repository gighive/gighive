# Host VM and Docker Container Version Compatibility

## Overview

GigHive's production deployment uses Docker containers that may run different Ubuntu and PHP versions than the host VM. This document explains how this works and why it's safe.

## Current Configuration

### Host VM
- **OS**: Ubuntu 22.04 LTS (Jammy Jellyfish)
- **Architecture**: aarch64 (ARM64)
- **Role**: Runs Docker daemon and orchestrates containers

### Docker Containers

#### Apache Container
- **Base Image**: `ubuntu:24.04` (Noble Numbat)
- **PHP Version**: 8.3 (via `php-fpm`)
- **Web Server**: Apache 2.4 with event MPM
- **Defined in**: `ansible/roles/docker/files/apache/Dockerfile`

#### MySQL Container
- **Base Image**: `mysql:8.0`
- **Role**: Database server

## Why Different Versions Work

Docker containers are **isolated environments** that run their own operating system userspace. The key points:

1. **Kernel Sharing**: Containers share the host's Linux kernel but run their own Ubuntu userspace
2. **Package Independence**: Container packages (PHP 8.3, Apache, etc.) come from the container's Ubuntu 24.04 repos, not the host's 22.04 repos
3. **Docker Compatibility**: Only the Docker daemon needs to be compatible with the host OS, not the container contents

### What This Means

- ✅ Ubuntu 24.04 container runs fine on Ubuntu 22.04 host
- ✅ PHP 8.3 in container is independent of host PHP version (if any)
- ✅ Container upgrades don't require host OS upgrades
- ✅ Multiple containers can run different OS versions simultaneously

## Ansible Rebuild Process

When you run the Ansible playbook, it ensures a clean rebuild:

```bash
ansible-playbook -i ansible/inventories/inventory_baremetal.yml \
  ansible/playbooks/site.yml \
  --skip-tags vbox_provision,blobfuse2,mysql_backup
```

### Rebuild Steps (from `ansible/roles/docker/tasks/main.yml`)

1. **Stop Apache container** - Removes running `apacheWebServer` container
2. **Remove Apache image** - Deletes cached image to force rebuild
3. **Build with `build: always`** - Rebuilds from Dockerfile with latest changes
4. **Start containers** - Launches new container with updated configuration

## Version Configuration

### PHP Version Control

The PHP version is controlled via Ansible variables:

**File**: `ansible/inventories/group_vars/prod/prod.yml`
```yaml
gighive_php_version: "8.3"
gighive_php_fpm_bin: "php-fpm{{ gighive_php_version }}"
```

**File**: `ansible/roles/docker/files/apache/Dockerfile`
```dockerfile
ARG PHP_VERSION=8.3
```

### PHP-FPM Socket Paths

Templates automatically use the correct socket path based on `gighive_php_version`:

- **Apache config**: `/run/php/php8.3-fpm.sock`
- **PHP-FPM pool**: `/run/php/php8.3-fpm.sock`

These are templated in:
- `ansible/roles/docker/templates/apache2.conf.j2`
- `ansible/roles/docker/templates/php-fpm.conf.j2`
- `ansible/roles/docker/templates/www.conf.j2`

## Upgrading Strategies

### Upgrading Container OS/PHP (Safe)

To upgrade the container to a newer Ubuntu or PHP version:

1. Update `Dockerfile` base image: `FROM ubuntu:24.04`
2. Update `ARG PHP_VERSION=8.3`
3. Update `prod.yml`: `gighive_php_version: "8.3"`
4. Run Ansible playbook - automatic rebuild

**No host OS upgrade required.**

### Upgrading Host OS (Independent)

To upgrade the host VM from 22.04 to 24.04:

1. Perform standard Ubuntu upgrade on host
2. Verify Docker daemon still works
3. Containers continue running unchanged

**No container rebuild required.**

## Migration History

| Date | Host OS | Container OS | PHP Version | Notes |
|------|---------|--------------|-------------|-------|
| 2025-11 | 22.04 | 24.04 | 8.3 | Current production config |
| Previous | 22.04 | 22.04 | 8.1 | Legacy configuration |

## Troubleshooting

### Container Won't Start After Rebuild

**Check PHP socket path**:
```bash
docker exec apacheWebServer ls -la /run/php/
```

Should show: `php8.3-fpm.sock`

**Check PHP-FPM is running**:
```bash
docker exec apacheWebServer ps aux | grep php-fpm
```

### Version Verification

**Check host OS**:
```bash
cat /etc/lsb-release
```

**Check container OS**:
```bash
docker exec apacheWebServer cat /etc/lsb-release
```

**Check container PHP version**:
```bash
docker exec apacheWebServer php -v
```

## Best Practices

1. **Keep versions in sync across configs**: Dockerfile `ARG PHP_VERSION` should match `prod.yml` `gighive_php_version`
2. **Test in dev first**: Always test container upgrades in development/staging before production
3. **Document changes**: Update this file when changing OS or PHP versions
4. **Monitor logs**: Check Apache and PHP-FPM logs after rebuilds for compatibility issues

## References

- Dockerfile: `ansible/roles/docker/files/apache/Dockerfile`
- Docker Compose template: `ansible/roles/docker/templates/docker-compose.yml.j2`
- Production variables: `ansible/inventories/group_vars/prod/prod.yml`
- Rebuild tasks: `ansible/roles/docker/tasks/main.yml`
