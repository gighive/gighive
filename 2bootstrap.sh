#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'

# HOW TO RUN
# This script depends on azure.env file with a subscription and a tenant id.
# 1 Create a file called azure.env.  It will look like this:
#export ARM_SUBSCRIPTION_ID=[put subscription id here]
#export ARM_TENANT_ID=[put tenant id/mgmt group id here]

# 2 Source the environment
# . ~/azure.env

# 3 Execute this script: ./2bootstrap.sh

# 4 Add a hosts file entry for gighive for whatever IP address comes up in Azure.  Once the server is up, ping gighive to make sure it is accessible.
#[IP Address] gighive

#5 When you're done, execute the 3deleteAll.sh script to destroy all the resources and resource groups created.  This takes roughly 5 minutes.

ENVIRONMENT=azure.env
BACKEND_VARS_FILE="terraform/backend.tfvars"

# BUILD TIMINGS
# Takes about 3 minutes for the Terraform scripts to build the Azure infrastructure 
# Takes about 50 minutes for Ansible playbooks to run to completion on the default Azure vm assigned (very slow)

# Suggestion: After the vm is up and running, add an alias to your .bashrc in order to login to your azure vm and check things out as it builds.  Something like this:
#alias azure='ssh azureuser@52.224.160.15'

# Load Azure environment variables
if [ -f "$ENVIRONMENT" ]; then
  set -o allexport
  source "$ENVIRONMENT"
  set +o allexport
fi

# Parse backend.tfvars values
TF_RG=$(grep '^resource_group_name' "$BACKEND_VARS_FILE" | cut -d= -f2 | tr -d ' "')
TF_SUB_ID=$(az account show --query id -o tsv)
TF_SA=$(grep '^storage_account_name' "$BACKEND_VARS_FILE" | cut -d= -f2 | tr -d ' "')
TF_CONTAINER=$(grep '^container_name' "$BACKEND_VARS_FILE" | cut -d= -f2 | tr -d ' "')
TF_KEY=$(grep '^key' "$BACKEND_VARS_FILE" | cut -d= -f2 | tr -d ' "')
TF_LOCATION="eastus"  # hardcoded default; optional: make this a variable too

SUBSCRIPTION_NAME="test subscription"

echo "==> Logging into Azure (uncomment if needed)"
# az login --use-device-code

echo "==> Setting subscription to: $SUBSCRIPTION_NAME"
az account set --subscription "$SUBSCRIPTION_NAME"

# ─── Ensure backend infrastructure exists ─────────────────────────────
echo "==> Ensuring remote backend exists in Azure..."

if ! az group show --name "$TF_RG" &>/dev/null; then
  echo "Creating resource group: $TF_RG"
  az group create --name "$TF_RG" --location "$TF_LOCATION"
fi

if ! az storage account show --name "$TF_SA" --resource-group "$TF_RG" &>/dev/null; then
  echo "Creating storage account: $TF_SA"
  az storage account create \
    --name "$TF_SA" \
    --resource-group "$TF_RG" \
    --location "$TF_LOCATION" \
    --sku Standard_LRS \
    --kind StorageV2
fi

CONTAINER_EXISTS=$(az storage container exists \
  --name "$TF_CONTAINER" \
  --account-name "$TF_SA" \
  --auth-mode login \
  --query "exists" -o tsv)

if [ "$CONTAINER_EXISTS" != "true" ]; then
  echo "Creating storage container: $TF_CONTAINER"
  az storage container create \
    --name "$TF_CONTAINER" \
    --account-name "$TF_SA" \
    --auth-mode login
fi

# ─── Run Terraform ────────────────────────────────────────────────────
echo "==> Running Terraform init/validate/plan"
pushd terraform >/dev/null
terraform init -backend-config=backend.tfvars
terraform validate
# explicitly tell Terraform which RG to use for infra (not the backend RG)
terraform plan -var-file=infra.tfvars -out=tfplan
popd >/dev/null

echo
echo "✅ Bootstrap complete!"

# ─── Optionally apply the plan ────────────────────────────────────────
read -p "❓ Do you want to apply the Terraform plan now? [y/N]: " APPLY_PLAN
if [[ "$APPLY_PLAN" =~ ^[Yy]$ ]]; then
  echo "==> Applying Terraform plan..."
  terraform -chdir=terraform apply -var-file=infra.tfvars tfplan
else
  echo "⏭️ Skipping apply. You can run it later with:"
  echo "   cd terraform && terraform apply tfplan"
fi

# ─── Extract IP and optionally update inventory ───────────────────────
if terraform -chdir=terraform output -raw vm_public_ip &>/dev/null; then
  VM_IP=$(terraform -chdir=terraform output -raw vm_public_ip)
  echo "==> Terraform VM public IP: $VM_IP"

  read -p "❓ Do you want to update the Ansible inventory with this IP? $VM_IP [y/N]: " UPDATE_INV
  if [[ "$UPDATE_INV" =~ ^[Yy]$ ]]; then
    echo "==> Generating Ansible inventory from template..."
    jinja2 ansible/inventories/inventory_azure.yml.j2 \
      -D vm_public_ip="$VM_IP" \
      > ansible/inventories/inventory_azure.yml
    echo "✅ Inventory updated: ansible/inventories/inventory_azure.yml"
    cat ansible/inventories/inventory_azure.yml
  else
    echo "⏭️ Skipping inventory update."
  fi
else
  echo "⚠️  Could not extract VM IP — Terraform may not have applied yet."
fi
echo "✅ To execute ansible build, run:"
echo "  ansible-playbook -i ansible/inventories/inventory_azure.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2 -v" 

# ─── Optionally run ansible build ────────────────────────────────────────
read -p "❓ Do you want to run the Ansible build now? [y/N]: " RUN_BUILD
if [[ "$RUN_BUILD" =~ ^[Yy]$ ]]; then
  echo "==> Run Ansible Build..."
  ansible-playbook -i ansible/inventories/inventory_azure.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2 -v
  
else
  echo "⏭️ Skipping build. You can run it later with:"
  echo " ansible-playbook -i ansible/inventories/inventory_azure.yml   ansible/playbooks/site.yml --skip-tags vbox_provision,blobfuse2 -v"
fi
