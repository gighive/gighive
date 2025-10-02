---
title: Readme
layout: default
---
# GigHive Music and Video Library 

GigHive is an **open-source deployment framework** for hosting your own media library, a band’s library or fan videos, or even videos uploaded by guests from events like a wedding.  There are two pieces to Gighive:
- Scripts that build a vm and within, fully automated Apache and MySQL containers. MySQL is prebuilt with a media library database waiting to be populated.  As a sample, it has a few audio and video clips from one of our other users.
- Companion iPhone app for fans and wedding guests to upload content (web-based version also built-in).

The automation allows you to spin up a fully reproducible environment using **Docker, Ansible, and Terraform**.  It has a very simple interface: a splash page, a single database of stored videos and an upload utility.

This project is designed to be portable, easy to deploy, and suitable for local development or cloud environments (Azure supported out of the box).

---

REQUIREMENTS
- Control Machine: Tested on Ubuntu 22.04, so the requirements are **any flavor of Ubuntu 22.04 or Pop-OS**, installed on bare metal for the Virtualbox implementation or you can install to a VM if you are deploying to an external Azure or bare metal server.  Virtualbox implementation assumes Control Machine would also be home to your Virtualbox VMs.
- Target Server: Your choice of virtualbox, Azure or bare metal deployment targets for the vm and containerized environment.

## Architecture

<a href="images/architecture.png" target="_blank">
  <img src="images/architecture.png" alt="GigHive Architecture Diagram" height="400" style="cursor: pointer;">
</a>

*Click the diagram above to view full size*

---

## ⚙️ Preparation
### Do these steps before moving ahead with one of the options below.
```bash
# 1. Clone the repo
git clone https://github.com/gighive/gighive

# 2. Set GIGHIVE_HOME variable
export GIGHIVE_HOME=<location of where you cloned gighive>
eg: export GIGHIVE_HOME=/home/$USER/gighive

# 3. Install Azure, Terraform and Ansible prerequisites 
cd $GIGHIVE_HOME;./1prereqsInstall.sh
Note VirtualBox install will require a reboot.

# 4. Make sure you have id_rsa.pub or id_ed25519.pub in 
./ssh for passwordless authentication

```

---
## Option A: Install to Azure VM (requires an Azure subscription)
```bash
# 1. Export Azure Vars (as noted at top of 2bootstrap.sh)
export ARM_SUBSCRIPTION_ID=[put your subscription id here]
export ARM_TENANT_ID=[put your tenant id/mgmt group id here]

# 2. Provision infrastructure
./2bootstrap.sh

Watch and respond to these prompts:
.. apply Terraform plan 
.. update the ansible inventory file and finally,
.. run the ansible_playbook

# 3. If you're finished with the VM, delete all resources in Azure
cd $GIGHIVE_HOME;./3deleteAll.sh 
```

---
## Option B: Create and install to new Virtualbox VM
```bash
# 1. Decide on an IP in your home network that you'd like to use. 

# 2. Edit the "ansible_host" field in $GIGHIVE_HOME/ansible/inventories/inventory_virtualbox.yml 

# 3. Run Ansible 
cd $GIGHIVE_HOME;ansible-playbook -i ansible/inventories/inventory_virtualbox.yml ansible/playbooks/site.yml --ask-become-pass
```

---
## Option C: Install to existing baremetal server in your environment
```bash
# 1. Edit the inventory file and put the IP of your bare metal server that is prepped for Gighive 
$GIGHIVE_HOME/ansible/inventories/inventory_baremetal.yml

# 2. Run Ansible
cd $GIGHIVE_HOME;ansible-playbook -i ansible/inventories/inventory_baremetal.yml ansible/playbooks/site.yml 
```

---

## ⚙️ Setup & Installation
- Once installed, there will be a splash page, a link to the database and a link to the uploads page. Simple! 
- Default install will populate the database with ~10 sample video and audio files. These can be deleted later with <a href="">database reset procedure</a>.
- Default password set in $GIGHIVE_HOME/ansible/inventories/group_vars files should be changed.
- Admin utility: a page for the admins to reset default password in GUI as well.

---


## 📂 Repository Structure
```
├── 1prereqsInstall.sh
├── 2bootstrap.sh
├── 3deleteAll.sh
├── ansible
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
│   ├── index.html
│   ├── PREREQS.md
│   └── README.md
├── inventory.ini
├── terraform
│   └── variables.tf
└── tree.txt
```

---

## 📜 License
GigHive Community Edition is licensed under the MIT License.
This edition is intended for self-hosted, single-tenant use cases.
For SaaS or multi-tenant solutions, please see GigHive Cloud (proprietary).

---

## 🤝 Contributing
Contributions welcome! Please open issues and pull requests.  

👉 [Contact us](mailto:contactus@gighive.app) for commercial licensing or for any other questions regarding Gighive. <img src="images/beelogo.png" alt="GigHive bee mascot" style="height: 1em; vertical-align: middle;">
