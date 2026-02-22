---
description: Setup instructions (full normal build)
---

## ⚙️  Prerequisites: Install Ansible and Python to your controller machine.
1. Decide where you will install Ansible as the controller and what target vm (virtualbox or Azure) will be the Gighive server. 
- If you are going to install on a virtualbox VM, find an open IP address to use in your network.
- If you are going to install to Azure, Azure will provision an IP for you.
- In a later step, you will add that IP to the appropriate variable in the Ansible inventory file. 
- See [Ansible core files](ANSIBLE_FILE_INTERACTION.md) discussion for more info on how Ansible's configuration works .

2. Log onto that server and install Ansible (3 minutes):
```bash
sudo apt update && sudo apt install -y pipx python3-venv git
pipx ensurepath
pipx install --include-deps ansible
```

3. Log out.

4. Log back in
```bash
ansible --version # Should be 2.17.2 or higher
```

5. Clone the repo from your desired location (usually /home/$USER, 4 minutes).
- The repo has some sample media files, so it's about 690MB in size.  
- Takes a few minutes to download on an average connection.
```bash
git clone https://github.com/gighive/gighive
```

6. GigHive sets and uses an Ansible variable `gighive_home` (default `~/gighive`) to locate the repo on the VM. If you cloned the repo anywhere else other than ~/gighive, edit the `gighive_home` variable found in `ansible/inventories/group_vars/gighive/all.yml` to the actual location of the repo.

7. Make sure you have id_rsa.pub in ./ssh for passwordless authentication.
```bash
ssh-keygen -t rsa
```

8. Update the secrets to your desired credentials.  Copy the example file and then edit the destination to your liking:
```bash
cd $GIGHIVE_HOME
cp ansible/inventories/group_vars/gighive/secrets.example.yml ansible/inventories/group_vars/gighive/secrets.yml
```

---

## ⚙️  Option A: Gighive as a virtualbox VM.  Install Gighive as a vm on your Ansible controller machine.
1. From $GIGHIVE_HOME, Install Virtualbox using Ansible (5 minutes). 
- Default shown below is the virtualbox install.
- install_virtualbox=true will be set in the below Ansible command.
- The script will ask for your sudo password, so enter it in when prompted.
```bash
cd $GIGHIVE_HOME
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/install_controller.yml -e install_virtualbox=true -e install_terraform=false -e install_azure_cli=false --ask-become-pass
```

2. When the script finishes, it will prompt you to reboot.  
- Hit "enter" to stop the script and then reboot.


3. Verify the installation (<1 minute).
```bash
cd $GIGHIVE_HOME
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/verify_controller.yml  -e target_provider=vbox -e install_virtualbox=true -e install_terraform=false -e install_azure_cli=false
```
- After finishing, you should see a green checkmark and the words "All prerequisites verified successfully!" at the bottom of the Ansible output.  Otherwise, redo the steps above.


4. In the inventory file below, set the "ansible_host" variable to the IP address to the IP address you decided upon in the Prerequisites. 
```bash
vi ansible/inventories/inventory_bootstrap.yml 
```

5. Execute the Ansible playbook that will install Gighive (15 minutes).
```bash
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/site.yml --ask-become-pass
```

6. If the previous step ran without error, CONGRATULATIONS!!  You've installed Gighive!! Now access it in a browser:
```bash
https://<ansible_host IP from earlier step>
```

OPTIONAL: It is helpful to set an alias in your .bashrc to access the vm you've created so you can check it out.
```bash
alias gighive='ssh ubuntu@<ansible_host value found in ansible/inventories/inventory_bootstrap.yml>"
```

OPTIONAL: You may want to setup the Virtualbox vm to autostart if the machine on which it is running is restarted.  If so, logon to the server that is home to your Virtualbox vm and perform these steps to create a systemd service to autostart the vm:
```bash
cd $GIGHIVE_HOME
vi ansible/inventories/group_vars/gighive/gighive.yml   # Set `enable_vbox_vm_autostart: true`
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml ansible/playbooks/vbox_autostart.yml --ask-become-pass
```
After running the steps, your vm if not started, will start.  Otherwise, the autostart service will be setup. 

---

## Option B: Gighive as an Azure VM.  Install Gighive on Azure (requires an Azure subscription).
Make sure prerequisites from above are installed.
  - **You will need at minimum an Azure Standard tier subscription to run this**.
  - **This will not work with a free subscription**.
  - **The default vm size is Standard_B2ms, a basic, cost-effective vm**.

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
  - apply Terraform plan 
  - update the ansible inventory file
  - run the ansible build
```bash
./2bootstrap.sh
```

3. If Step 2 ran without error, CONGRATULATIONS!!  You've installed Gighive!! Now access it in a browser:
```bash
https://<ansible_host IP captured from the output of the install or the inventory file>
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
