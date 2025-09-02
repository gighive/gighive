---
title: Readme
layout: default
---
# GigHive Music and Video Library 

GigHive is an **open-source deployment framework** for hosting a band’s music library and jam sessions.  Or a wedding photographer could use it for their guests to upload photos.  It packages **Apache**, **MySQL**, and supporting automation into a fully reproducible environment using **Docker, Ansible, and Terraform**.  

This project is designed to be portable, easy to deploy, and suitable for local development or cloud environments (Azure supported out of the box).

---

## 🚀 Features
- **Automated Infrastructure**  
  - Provisioning with **Terraform** (Azure-ready)  
  - Configuration with **Ansible**  
  - CI/CD with **GitHub Actions**  

- **Web + Database Stack**  
  - Apache HTTP Server (custom-built with HTTP/2/3 support)  
  - MySQL database container for structured music/jam session data  
  - REST API layer (PHP-based) for audio/video file access  

- **Containerized Deployment**  
  - Docker containers for all core services  

---

## 📂 Repository Structure
```
├── 1prereqsInstall.sh
├── 2bootstrap.sh
├── 3deleteAll.sh
├── ansible
│   ├── callback_plugins
│   ├── inventories
│   │   ├── group_vars
│   │   ├── inventory_azure.yml
│   │   ├── inventory_azure.yml.j2
│   │   ├── inventory_baremetal.yml
│   │   └── inventory_virtualbox.yml
│   ├── playbooks
│   │   └── site.yml
│   ├── roles
│   │   ├── base
│   │   ├── blobfuse2
│   │   ├── cloud_init
│   │   ├── cloud_init_disable
│   │   ├── docker
│   │   ├── mysql_backup
│   │   ├── nfs_mount
│   │   ├── post_build_checks
│   │   ├── security_basic_auth
│   │   ├── security_owasp_crs
│   │   ├── validate_app
│   │   └── varscope
│   └── vdiLockedWriteDelete.sh
├── ansible.cfg
├── assets
│   ├── audio
│   └── video
├── azure.env
├── azure-prereqs.txt
├── CHANGELOG.md
├── docs
│   ├── commonissue1.txt
│   ├── commonissue2.txt
│   ├── index.html
│   ├── PREREQS.md
│   ├── README.md
│   └── timings.txt
├── inventory.ini
├── sonar-project.properties
├── terraform
│   └── variables.tf
└── tree.txt
```

---

## ⚙️ Preparation
```bash
# 1. Set GIGHIVE_HOME variable
export GIGHIVE_HOME=<location of where you cloned gighive>
eg: export GIGHIVE_HOME=/home/user/gighive

# 2. Install Azure, Terraform and Ansible prerequisites 
cd $GIGHIVE_HOME;./1prereqsInstall.sh
Note VirtualBox install will require a reboot.

# 3. Make sure you have id_rsa.pub or id_ed25519.pub in 
./ssh for passwordless authentication

```

---

## ⚙️ Setup & Installation
- Once installed, there will be a splash page, a link to the database and a link to the uploads page. Simple! Oh, and a page for the admins to reset default password.
- Default install will populate the database with ~10 sample video and audio files. These can be deleted later with <a href="">database reset procedure</a>.
- Default password set in $GIGHIVE_HOME/ansible/inventories/group_vars files.

---
## Option A: Azure
```bash
# 1. Export Azure Vars (as noted at top of 2bootstrap.sh)
export ARM_SUBSCRIPTION_ID=[put your subscription id here]
export ARM_TENANT_ID=[put your tenant id/mgmt group id here]

# 2. Provision infrastructure
./2bootstrap.sh

..part of ./2bootstrap.sh will be running ansible .. 
cd $GIGHIVE_HOME;ansible-playbook -i ansible/inventories/inventory_azure.yml \
 ansible/playbooks/site.yml

# 3. If you're finished, delete all resources in Azure
cd $GIGHIVE_HOME;./3deleteAll.sh 
```

---
## Option B: VirtualBox
```bash
# 1. Decide on an IP in your home network that you'd like to use. 

# 2. Edit the inventory file and put in that IP of the target vm you'll create
$GIGHIVE_HOME/ansible/inventories/inventory_virtualbox.yml

# 3. Run Ansible 
cd $GIGHIVE_HOME;ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --ask-become-pass
```

---
## Option C: Baremetal
```bash
# 1. Edit the inventory file and put the IP of your bare metal server that is prepped for Gighive 
$GIGHIVE_HOME/ansible/inventories/inventory_baremetal.yml

# 2. Run Ansible
cd $GIGHIVE_HOME;ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml 
```

---

## 🧑‍💻 Development Environment
- Use the included Ansible + Docker setup to run locally.  
- Access services:  
  - Apache web server → `http://localhost:8080`  
  - MySQL database → `localhost:3306`  
  - Portainer → `http://localhost:9000`  

---

## 📊 CI/CD & Quality
- **SonarCloud** integration via GitHub Actions  
- Linting and testing scripts under `.github/`  

---

## 📜 License
GigHive Community Edition is licensed under the MIT License.
This edition is intended for self-hosted, single-tenant use cases.
For SaaS or multi-tenant solutions, please see GigHive Cloud (proprietary).


---

## 🤝 Contributing
Contributions welcome! Please open issues and pull requests.  

