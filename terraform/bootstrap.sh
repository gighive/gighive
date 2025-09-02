#!/usr/bin/env bash
set -euo pipefail

echo "This program usually takes about 3 minutes to run"
echo "There is one interactive press of the ENTER key that you will be prompted with"

# Print help if requested
if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  cat <<EOF
Usage: $(basename "$0") [options]

Environment variables (all optional):
  TFSTATE_RG        Resource group for remote state (default: tfstate-rg)
  TFSTATE_SA        Storage account for remote state (default: gighivetfstate)
  TFSTATE_LOCATION  Azure region for RG & SA (default: eastus)
  TFSTATE_CONTAINER Blob container name (default: tfstate)

Example:
  TFSTATE_SA=\"mycustomsa\" TFSTATE_RG=\"myrg\" TFSTATE_LOCATION=\"westus2\" ./bootstrap.sh
EOF
  exit 0
fi

# 0) Check for required commands
command -v az >/dev/null 2>&1 || { echo >&2 "Error: az CLI not found. Install Azure CLI first."; exit 1; }
command -v terraform >/dev/null 2>&1 || { echo >&2 "Error: terraform not found. Install Terraform first."; exit 1; }

# 0b) Check Azure login
if ! az account show >/dev/null 2>&1; then
  echo "Error: not logged in to Azure CLI. Run 'az login' and try again."
  exit 1
fi

# 1) Read environment overrides (or defaults)
TFSTATE_RG="${TFSTATE_RG:-tfstate-rg}"
TFSTATE_SA="${TFSTATE_SA:-gighivetfstate}"
TFSTATE_LOCATION="${TFSTATE_LOCATION:-eastus}"
TFSTATE_CONTAINER="${TFSTATE_CONTAINER:-tfstate}"

echo "Using the following settings for remote state backend:"
echo "  Resource Group:    $TFSTATE_RG"
echo "  Storage Account:   $TFSTATE_SA"
echo "  Location:          $TFSTATE_LOCATION"
echo "  Blob Container:    $TFSTATE_CONTAINER"
echo

# 2) Create (or ensure) the resource group
echo "1) Ensuring resource group \"$TFSTATE_RG\" exists..."
az group create \
  --name "$TFSTATE_RG" \
  --location "$TFSTATE_LOCATION" \
  --only-show-errors

# 3) Create (or ensure) the storage account (must be Standard_LRS for Terraform backend)
echo "2) Ensuring storage account \"$TFSTATE_SA\" exists..."
az storage account create \
  --name "$TFSTATE_SA" \
  --resource-group "$TFSTATE_RG" \
  --location "$TFSTATE_LOCATION" \
  --sku Standard_LRS \
  --kind StorageV2 \
  --only-show-errors

# 4) Retrieve the account key and create the blob container
echo "3) Ensuring blob container \"$TFSTATE_CONTAINER\" exists..."
ACCOUNT_KEY=$(az storage account keys list \
  --resource-group "$TFSTATE_RG" \
  --account-name "$TFSTATE_SA" \
  --query "[0].value" \
  -o tsv)

az storage container create \
  --name "$TFSTATE_CONTAINER" \
  --account-name "$TFSTATE_SA" \
  --account-key "$ACCOUNT_KEY" \
  --only-show-errors

# 5) Initialize Terraform (now that backend exists)
echo "4) Initializing Terraform with backend.tfvars..."
terraform init -reconfigure -backend-config=backend.tfvars

# 6) Run `plan` & `apply`
echo "5) Terraform plan (review the changes below):"
terraform plan

echo
echo "Press ENTER to continue with terraform apply..."
read -r _

terraform apply -auto-approve

