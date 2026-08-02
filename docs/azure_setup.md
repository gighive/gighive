# Azure Setup

GigHive includes an Azure deployment path for users who want to run the system in their own Azure subscription instead of setting it up locally.

This guide is written as a simple overview of the setup flow that already exists in this repository.

## Before you start

The most important prerequisites are:

- Install the required tools with `1prereqsInstall.sh`
- Create or update `azure.env` with your Azure subscription and tenant information

Without those two pieces, the Azure bootstrap flow will not work.

## Choose the workflow that matches your goal

### Option 1 — First-time setup: `1prereqsInstall.sh`

Use `1prereqsInstall.sh` first.

This script prepares your machine for the Azure deployment workflow.

It can install:

- Azure Python prerequisites
- Ansible
- Terraform
- VirtualBox

For Azure setup, the Azure, Ansible, and Terraform parts are the important ones.

What the script does:

- Installs base packages on Ubuntu 22.04
- Creates a Python virtual environment at `~/.ansible-azure` when needed
- Installs Azure-related Python packages from `azure-prereqs.txt`
- Installs Ansible and the requested collections
- Installs Terraform
- Optionally installs VirtualBox

Important note:

- The script is interactive and asks which components you want to install
- For an Azure deployment, make sure you install the pieces you actually need for Azure, Ansible, and Terraform

### Option 2 — Required configuration: `azure.env`

Before running the bootstrap script, make sure `azure.env` is present and contains your Azure environment values.

The scripts in this repository expect `azure.env` to define:

- `ARM_SUBSCRIPTION_ID`
- `ARM_TENANT_ID`

The comments in the scripts show the expected format as exported environment variables.

This step is important because:

- `2bootstrap.sh` loads `azure.env` before starting the Azure workflow
- `3deleteAll.sh` also loads `azure.env`
- `3deleteAll.sh` stops immediately if `ARM_SUBSCRIPTION_ID` is not set

### Option 3 — Build Azure infrastructure and run the app setup: `2bootstrap.sh`

Use `2bootstrap.sh` after your prerequisites are installed and `azure.env` is ready.

This is the main Azure setup script.

What it does:

1. Loads `azure.env` if the file is present
2. Checks whether your Azure CLI session is already authenticated
3. Starts Azure device-code login if no valid session is found
4. Sets the Azure subscription
5. Ensures the Terraform backend resources exist in Azure
6. Runs Terraform plan
7. Optionally applies the Terraform plan
8. Reads the VM public IP from Terraform output
9. Optionally updates the Ansible inventory from the generated IP
10. Optionally runs the Ansible build

This script supports partial or resumed runs.

Supported start stages are:

- `all`
- `plan`
- `apply`
- `inventory`
- `build`

That means you can run the full flow or restart from a later stage if part of the work has already been completed.

Important notes from the script:

- The script expects an `azure.env` file in the repo
- The script refers to `terraform/backend.tfvars`
- The script prints the Ansible command it uses for the build step
- The script says Terraform setup takes about 3 minutes and the default Azure VM Ansible build can take about 50 minutes

### Option 4 — Remove Azure resources when you are done: `3deleteAll.sh`

Use `3deleteAll.sh` only when you want to tear down Azure resources.

This script is destructive.

What it does:

- Loads `azure.env`
- Requires `ARM_SUBSCRIPTION_ID` to be set
- Sets the Azure subscription
- Lists the resource groups in that subscription
- Asks for confirmation
- Initiates deletion of all listed resource groups
- Polls until no resource groups remain

Important note:

- This script is not limited to a single GigHive resource group name in its current form
- It retrieves the resource groups in the configured subscription and asks whether you want to delete all of them

## Recommended order for most users

If you are new to the Azure path, use this order:

1. Run `1prereqsInstall.sh`
2. Make sure `azure.env` is present and correct
3. Run `2bootstrap.sh`
4. If you later want to tear everything down, run `3deleteAll.sh`

## Which option should I choose?

- If you have not prepared your machine yet, start with `1prereqsInstall.sh`
- If the Azure scripts are not picking up your subscription details, check `azure.env`
- If you are ready to create the Azure infrastructure and build GigHive, use `2bootstrap.sh`
- If you want to remove the Azure resources afterward, use `3deleteAll.sh`

## Important note

Before using the Azure scripts:

- Make sure you understand which Azure subscription you are targeting
- Make sure `azure.env` contains the values the scripts expect
- Remember that `2bootstrap.sh` includes optional apply, inventory, and build steps
- Remember that `3deleteAll.sh` is a teardown script and deletes all listed resource groups in the selected subscription after confirmation
