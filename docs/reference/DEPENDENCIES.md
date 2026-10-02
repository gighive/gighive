# GigHive Dependencies

This document lists all software dependencies managed by Ansible for the GigHive project.

## System Packages (APT)

### Base System Dependencies
Installed by `roles/base/tasks/main.yml`:
- **docker-ce** - Docker Engine
- **docker-ce-cli** - Docker CLI
- **containerd.io** - Container runtime
- **docker-compose** - Docker Compose standalone
- **docker-compose-plugin** - Docker Compose V2 plugin
- **software-properties-common** - Manage software repositories
- **apt-transport-https** - HTTPS support for APT
- **ca-certificates** - Common CA certificates
- **curl** - Transfer data with URLs
- **wget** - Network downloader
- **gnupg-agent** - GNU Privacy Guard agent
- **lsb-release** - Linux Standard Base version reporting
- **net-tools** - Network tools (ifconfig, netstat, etc.)
- **audispd-plugins** - Audit dispatcher plugins
- **auditd** - Linux audit daemon
- **inotify-tools** - File system event monitoring
- **python3** - Python 3 interpreter
- **python3-pip** - Python package installer
- **python3-docker** - Docker SDK for Python

### Controller Prerequisites
Installed by `roles/installprerequisites/vars/main.yml`:
- **python3** - Python 3 interpreter
- **python3-pip** - Python package installer
- **python3-venv** - Python virtual environment support
- **curl** - Transfer data with URLs
- **gnupg** - GNU Privacy Guard
- **ca-certificates** - Common CA certificates
- **lsb-release** - Linux Standard Base version reporting
- **apt-transport-https** - HTTPS support for APT

### Optional: Terraform
Installed by `roles/installprerequisites/tasks/terraform.yml`:
- **terraform** - Infrastructure as Code tool

### Optional: VirtualBox
Installed by `roles/installprerequisites/tasks/virtualbox.yml`:
- **virtualbox** - Virtualization platform

### Optional: Blobfuse2 (Azure Storage)
Installed by `roles/blobfuse2/tasks/main.yml`:
- **blobfuse2** - Azure Blob Storage FUSE adapter
- **fuse3** - Filesystem in Userspace v3

### NFS Support
Installed by `roles/nfs_mount/tasks/main.yml`:
- **nfs-common** - NFS client utilities
- **net-tools** - Network tools

### Security: Basic Auth
Installed by `roles/security_basic_auth/tasks/main.yml`:
- **python3-passlib** - Password hashing library
- **python3-bcrypt** - bcrypt password hashing

## Python Packages (pip)

### Controller Python Packages
Installed in virtualenv by `roles/installprerequisites/vars/main.yml`:
- **azure-cli** - Azure command-line interface
- **jinja2-cli** - Jinja2 templating CLI tool

### System Python Packages
Installed globally:
- **docker** - Docker SDK for Python
- **docker-compose** - Docker Compose Python library

### Legacy Azure Prerequisites
From `azure-prereqs.txt`:
- **ansible[azure]** - Ansible with Azure support
- **azure-cli** - Azure command-line interface
- **msgraph-core** - Microsoft Graph API client
- **packaging** - Python packaging utilities
- **jinja2-cli** - Jinja2 templating CLI tool

## Docker Images

### Apache Web Server
Base image: **ubuntu:22.04**

Installed packages in Apache container (`roles/docker/files/apache/Dockerfile`):
- **apache2** - Apache HTTP Server
- **apache2-bin** - Apache binaries
- **apache2-dev** - Apache development files
- **autoconf** - Automatic configure script builder
- **automake** - Tool for generating Makefiles
- **build-essential** - Build tools (gcc, make, etc.)
- **composer** - PHP dependency manager
- **curl** - Transfer data with URLs
- **ssl-cert** - SSL certificate creation tool
- **ca-certificates** - Common CA certificates
- **iproute2** - Networking utilities
- **libapache2-mod-security2** - ModSecurity web application firewall
- **libcap2-bin** - POSIX capabilities utilities
- **libssl-dev** - SSL development libraries
- **libtool** - Generic library support script
- **logrotate** - Log rotation utility
- **net-tools** - Network tools
- **nfs-common** - NFS client utilities
- **php-curl** - PHP cURL extension
- **php-fpm** - PHP FastCGI Process Manager
- **php-mbstring** - PHP multibyte string extension
- **php-mysql** - PHP MySQL extension
- **php-xml** - PHP XML extension
- **pkg-config** - Package configuration tool
- **tzdata** - Timezone data
- **unzip** - ZIP archive extractor
- **vim** - Text editor
- **zlib1g-dev** - Compression library development files

Apache modules enabled:
- ssl
- http2
- proxy
- proxy_fcgi
- headers
- rewrite
- cache
- cache_disk
- security2 (ModSecurity)
- remoteip
- mpm_event
- setenvif

### MySQL Database
Image: **mysql:8.0**

## PHP/Composer Dependencies

From `roles/docker/files/apache/webroot/composer.json`:
- **php** >= 7.4
- **guzzlehttp/psr7** ^2.0 - PSR-7 HTTP message implementation
- **guzzlehttp/guzzle** - HTTP client (added at runtime)
- **psr/http-message** ^1.0 - PSR-7 HTTP message interfaces
- **zircote/swagger-php** ^4.0 - OpenAPI/Swagger documentation generator
- **james-heinrich/getid3** ^1.9 - Media file metadata parser

## Ansible Collections

Installed by `roles/installprerequisites/tasks/ensure_collections.yml`:
- **community.general** - Community general collection
- **community.docker** - Docker management collection

## Configuration Files

Key configuration managed by Ansible:
- Docker Compose stack configuration
- Apache SSL/TLS certificates (self-signed)
- ModSecurity WAF rules (OWASP CRS)
- PHP-FPM configuration
- MySQL database initialization scripts
- Environment variables (.env files)

## External Services

- **Azure Blob Storage** - Optional cloud storage backend
- **NFS** - Optional network file system storage

## Build Tools

- **Terraform** - Infrastructure provisioning (optional)
- **VirtualBox** - Local VM testing (optional)
- **Ansible** - Configuration management and deployment

---

**Note**: This dependency list is automatically derived from Ansible role configurations. 
For GitHub dependency graph support, see language-specific manifest files:
- Python: `azure-prereqs.txt` (controller dependencies)
- PHP: `ansible/roles/docker/files/apache/webroot/composer.json`
