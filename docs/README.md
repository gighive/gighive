---
title: Readme
layout: default
---
# GigHive Music and Video Library 

GigHive is an **open-source deployment framework** for hosting your own media library, a band’s library or fan videos, or even videos uploaded by guests from events like a wedding.  There are two pieces to Gighive:
- A pre-built web-accessible media library waiting to be populated.  This is the server piece. Gighive uses Ansible and Terraform to build a vm and on top, a fast Apache server and MySQL database. It includes a few audio and video clips donated from one of our other users as a sample.
- Companion iPhone app for fans and wedding guests to upload content (web-based upload also built-in).

The automation spins up a fully reproducible environment using **Docker, Ansible, and Terraform**.  It has a very simple interface: a splash page, a single database of stored videos and an upload utility.

This project is designed to be portable, easy to deploy, and suitable for local development or cloud environments (Azure supported out of the box).

## Components
- **Ansible Control Machine**: Tested on Ubuntu 24.04 or 22.04, so the requirements are **any flavor of Ubuntu 22.04 or Pop-OS**, installed on bare metal.  Virtualbox implementation assumes Control Machine would also be home to your Virtualbox VMs.
- **Target Server**: Your choice of Virtualbox or Azure deployment targets for the vm and containerized environment.
 - Default vm size is 64GB, ~10GB of which will be used by the OS (configurable in ansible/group_vars).  
 - You will have ~54GB for media files.  
 - Docker will be installed to that VM. Bind mounts used for config files and media files. Check Dockerfile for exact specs.

## Software Prerequisites
Software needed for either option, Virtualbox or Azure:
 - Ansible, Python and git will be installed.  More detailed listing [here](PREREQS.html).
 - An id_rsa.pub file is needed for passwordless authentication into the Gighive server.

For Virtualbox installations, Virtualbox will be the additional component installed.
For Azure deployments, az and azure-cli will be installed.

## Architecture (logical)

<a href="images/architecture.png" target="_blank">
  <img src="images/architecture.png" alt="GigHive Architecture Diagram" height="400" style="cursor: pointer;">
</a>

*Click the diagram above to view full size*

## Suggested Platforms
Gighive runs very efficiently on an [Orange Pi 5](https://a.co/d/90UDcvi) or [GMKtech mini PC](https://www.amazon.com/dp/B0F8NL4ST4?ref=ppx_yo2ov_dt_b_fed_asin_title&th=1).  Stuff a few NVMe's in either and you'll have plenty of storage for your videos.  We've tested on both of them.

---

## ⚙️  Prerequisites: Install Ansible and Python to your controller machine.
1. Decide on where you will install Ansible as the controller and what target (virtualbox or Azure) that you will install Gighive on. 
- If you are going to install on a virtualbox VM, find an open IP address to use in your network.
- If you are going to install to Azure, Azure will provision an IP for you.
- In either case, the install will ask you to add the IP to the appropriate Ansible configuration inventory file. 
- See [Ansible core files](ANSIBLE_FILE_INTERACTION.md) discussion for more info on how Ansible's configuration works .

2. Log onto that server and install Ansible:
```bash
sudo apt update && sudo apt install -y pipx python3-venv git
pipx ensurepath
```

3. Log out.

4. Log back in.
```bash
pipx install --include-deps ansible
ansible --version # Should be 2.17.2 or higher
```

5. Clone the repo from your desired location (usually /home/$USER).  
- The repo has some sample media files, so it's about 690MB in size.  
- Takes a few minutes to download on an average connection.
```bash
git clone https://github.com/gighive/gighive
```

6. Wherever you have installed gighive to, set the GIGHIVE_HOME variable and test to see if it's correct.  
- Example: GIGHIVE_HOME is located in user's home directory.  
```bash
export GIGHIVE_HOME=/home/$USER/gighive
echo $GIGHIVE_HOME
cd $GIGHIVE_HOME
```

7. Add GIGHIVE_HOME export to your .bashrc.
```bash
echo "export GIGHIVE_HOME=/home/$USER/gighive" >> ~/.bashrc
cat ~/.bashrc
```

8. Make sure you have id_rsa.pub in ./ssh for passwordless authentication.
```bash
ssh-keygen -t rsa
```

---

## ⚙️  Option A: Gighive as a virtualbox VM.  Install Gighive as a vm on your Ansible controller machine.
1. From $GIGHIVE_HOME, Install Virtualbox using Ansible. 
- Default shown below is the virtualbox install.
- install_virtualbox=true will be set in the below Ansible command.
- The script will ask for your sudo password, so enter it in when prompted.
```bash
cd $GIGHIVE_HOME
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/install_controller.yml -e install_virtualbox=true -e install_terraform=false -e install_azure_cli=false --ask-become-pass
```

2. When the script finishes, it will prompt you to reboot.  
- Hit "enter" to stop the script and then reboot.

3. Verify the installation.
```bash
cd $GIGHIVE_HOME
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/verify_controller.yml  -e target_provider=vbox -e install_virtualbox=true -e install_terraform=false -e install_azure_cli=false
```
- After finishing, you should see a green checkmark and the words "All prerequisites verified successfully!" in the text above.  Otherwise, redo the steps above.

4. In the inventory file below, set the "ansible_host" variable to the IP address to the IP address you decided upon in the Prerequisites. 
```bash
vi ansible/inventories/inventory_bootstrap.yml 
```

5. Execute the Ansible playbook that will install Gighive.
```bash
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --ask-become-pass
```

6. If the previous step ran without error, CONGRATULATIONS!!  You've installed Gighive!! Now access it in a browser:
```bash
https://<ansible_host IP from step 11>
```

OPTIONAL: It is helpful to set an alias in your .bashrc to access the vm you've created so you can check it out.
```bash
alias gighive='ssh ubuntu@<ansible_host value found in ansible/inventories/inventory_virtualbox.yml>"
```

---

## Option B: Gighive as an Azure VM.  Install Gighive on Azure (requires an Azure subscription).
Make sure prerequisites from above are installed.

1. Export Azure Vars (as noted at top of 2bootstrap.sh)
```bash
export ARM_SUBSCRIPTION_ID=[put your subscription id here]
export ARM_TENANT_ID=[put your tenant id/mgmt group id here]
```
or, if you have azure.env configured with these exports, just:
```bash
source ./azure.env
```

2. Provision infrastructure.  Run ./2bootstrap.sh. Watch for and respond to these prompts:
.. apply Terraform plan 
.. update the ansible inventory file
.. run the ansible_playbook
```bash
./2bootstrap.sh
```

3. If Step 2 ran without error, CONGRATULATIONS!!  You've installed Gighive!! Now access it in a browser:
```bash
https://<ansible_host IP cpatured from the install>
```

OPTIONAL: If you're finished with the VM, delete all resources in Azure
```bash
cd $GIGHIVE_HOME;./3deleteAll.sh 
```

---


## ⚙️ Setup & Installation
- Once installed, there will be a splash page, a link to the database and a link to the uploads page. Simple! 
- Default install will populate the database with ~10 sample video and audio files. These can be deleted later with <a href="">database reset procedure</a>.
- There are three users: 
  * viewer: Viewers can view media files, but can't upload. 
  * uploader: Uploaders can upload and view media files. 
  * admin: Admin can view and upload files and change passwords.
- Default passwords are set in $GIGHIVE_HOME/ansible/inventories/group_vars/gighive.yml and should be changed.
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

### License
GigHive is dual-licensed:

- **[MIT License](LICENSE_MIT.md)**: Free for personal, single-instance, non-commercial use.
- **[Commercial License](LICENSE_COMMERCIAL.md)**: Required for SaaS, multi-tenant, or commercial use.

---

## 🤝 Contributing
Contributions welcome! Please open issues and pull requests.  

👉 [Contact us](mailto:contactus@gighive.app) for commercial licensing or for any other questions regarding Gighive. <img src="images/beelogo.png" alt="GigHive bee mascot" style="height: 1em; vertical-align: middle;">
