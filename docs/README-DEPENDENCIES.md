# Setting Up GitHub Dependency Graph for GigHive

## Overview

GigHive's dependencies are managed through **Ansible** configuration, which installs and configures software across multiple layers. GitHub can automatically track these dependencies once properly configured.

## What Was Created

### 1. **/docs/DEPENDENCIES.md**
Complete list of all software dependencies extracted from Ansible roles:
- System packages (APT)
- Python packages (pip)
- PHP packages (Composer)
- Docker images
- Ansible collections

### 2. **.github/dependabot.yml**
Configures GitHub Dependabot to monitor:
- **Python dependencies** (`azure-prereqs.txt`)
- **PHP dependencies** (`ansible/roles/docker/files/apache/webroot/composer.json`)
- **Docker images** (`ansible/roles/docker/files/apache/Dockerfile`)
- **GitHub Actions** (if you add workflows)

### 3. **.github/dependency-graph.md**
Visual documentation with Mermaid diagram showing:
- Architecture layers
- Dependency relationships
- How components interact

## How GitHub Dependency Graph Works

GitHub automatically detects and tracks dependencies from these files in your repository:

### ✅ Already Present in Your Repo

1. **PHP/Composer** 
   - File: `ansible/roles/docker/files/apache/webroot/composer.json`
   - Tracks: guzzlehttp/psr7, zircote/swagger-php, james-heinrich/getid3, etc.

2. **Python**
   - File: `azure-prereqs.txt`
   - Tracks: ansible[azure], azure-cli, msgraph-core, jinja2-cli, etc.

3. **Docker**
   - File: `ansible/roles/docker/files/apache/Dockerfile`
   - Tracks: ubuntu:22.04 base image
   - File: `ansible/roles/docker/templates/docker-compose.yml.j2`
   - Tracks: mysql:8.0 image

## Viewing the Dependency Graph

After pushing these changes to GitHub:

1. Go to your repository: `https://github.com/YOUR_USERNAME/gighive`

2. Click the **Insights** tab

3. Click **Dependency graph** in the left sidebar

4. You'll see tabs for:
   - **Dependencies** - All packages your project uses
   - **Dependents** - Other projects that depend on yours
   - **Dependabot** - Security alerts and updates

## Enable Security Features

In your GitHub repository settings:

1. Go to **Settings** → **Security & analysis**

2. Enable these features:
   - ✅ **Dependency graph** (auto-enabled for public repos)
   - ✅ **Dependabot alerts** - Get notified of vulnerabilities
   - ✅ **Dependabot security updates** - Auto-create PRs for security fixes
   - ✅ **Dependabot version updates** - Auto-create PRs for version updates

## What Gets Tracked

### Python Dependencies (azure-prereqs.txt)
```
ansible[azure]
azure-cli
msgraph-core
packaging
jinja2-cli
```

### PHP Dependencies (composer.json)
```json
{
  "require": {
    "php": ">=7.4",
    "guzzlehttp/psr7": "^2.0",
    "psr/http-message": "^1.0",
    "zircote/swagger-php": "^4.0",
    "james-heinrich/getid3": "^1.9"
  }
}
```

### Docker Images
- `ubuntu:22.04` (Apache container base)
- `mysql:8.0` (MySQL database)

## System Packages (Not Tracked by GitHub)

GitHub's dependency graph **does not track** system packages installed via APT. These are documented in `/docs/DEPENDENCIES.md` but must be managed manually:

- Docker Engine, Docker Compose
- Apache, PHP-FPM, ModSecurity
- NFS client, audit daemon
- Build tools and libraries

These are managed by Ansible and updated during deployments.

## Dependabot Alerts

Once enabled, Dependabot will:

1. **Scan** your dependencies daily
2. **Alert** you to known security vulnerabilities
3. **Create PRs** to update vulnerable packages (if auto-updates enabled)
4. **Check** for new versions weekly (configured in dependabot.yml)

Example alert:
```
⚠️ Moderate severity vulnerability in guzzlehttp/psr7
Affects versions < 2.4.5
Fixed in version 2.4.5
```

## Updating Dependencies

### Python Dependencies
```bash
# Update packages in azure-prereqs.txt
pip install --upgrade -r azure-prereqs.txt

# Commit updated versions
git add azure-prereqs.txt
git commit -m "Update Python dependencies"
```

### PHP Dependencies
```bash
cd ansible/roles/docker/files/apache/webroot

# Update to latest compatible versions
composer update

# Commit updated composer.lock
git add composer.json composer.lock
git commit -m "Update PHP dependencies"
```

### Docker Images
Update version tags in:
- `ansible/roles/docker/files/apache/Dockerfile`
- `ansible/roles/docker/templates/docker-compose.yml.j2`

### System Packages
System packages are updated automatically by Ansible during deployment:
```bash
# Run Ansible playbook to update target VM
ansible-playbook -i inventory.ini site.yml
```

## Next Steps

1. **Commit and push** the new files:
   ```bash
   git add docs/DEPENDENCIES.md .github/dependabot.yml .github/dependency-graph.md docs/README-DEPENDENCIES.md
   git commit -m "Add dependency tracking and documentation"
   git push
   ```

2. **Enable Dependabot** in GitHub repository settings

3. **Review dependency graph** in GitHub Insights tab

4. **Monitor alerts** for security vulnerabilities

5. **Review PRs** created by Dependabot for updates

## Benefits

✅ **Visibility** - See all dependencies in one place  
✅ **Security** - Get alerts for vulnerable packages  
✅ **Automation** - Auto-update dependencies via PRs  
✅ **Compliance** - Track licenses and versions  
✅ **Documentation** - Clear dependency relationships  

## Limitations

⚠️ **System packages** (APT) are not tracked by GitHub  
⚠️ **Ansible roles** themselves are not tracked (unless using requirements.yml)  
⚠️ **Binary dependencies** in Docker containers require manual tracking  

For complete dependency management, refer to `/docs/DEPENDENCIES.md` which documents everything Ansible installs.
