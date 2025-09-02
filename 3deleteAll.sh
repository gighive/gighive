#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'
#
# Deleting the resources in Azure usually takes about three minutes
#
# Define your environment variables for ARM_SUBSCRIPTION_ID and ARM_TENANT_ID in azure.env, for example:
# export ARM_SUBSCRIPTION_ID="00000000-1111-2222-3333-444444444444"
# export ARM_TENANT_ID="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
ENVIRONMENT=azure.env

# Load Azure environment variables, if present
if [ -f "$ENVIRONMENT" ]; then
  set -o allexport
  source "$ENVIRONMENT"
  set +o allexport
fi

# Ensure ARM_SUBSCRIPTION_ID is set
if [ -z "${ARM_SUBSCRIPTION_ID-}" ]; then
  echo "❌ ARM_SUBSCRIPTION_ID is not set. Please export it in $ENVIRONMENT."
  exit 1
fi

echo "==> Logging into Azure (uncomment if needed)"
# az login --use-device-code

echo "==> Setting subscription to ID: $ARM_SUBSCRIPTION_ID"
az account set --subscription "$ARM_SUBSCRIPTION_ID"

# Fetch all resource group names in this subscription
echo "==> Retrieving list of resource groups in subscription..."
RG_LIST=( $(az group list \
               --subscription "$ARM_SUBSCRIPTION_ID" \
               --query "[].name" \
               -o tsv) )

if [ "${#RG_LIST[@]}" -eq 0 ]; then
  echo "⚠️  No resource groups found in subscription ID '$ARM_SUBSCRIPTION_ID'. Exiting."
  exit 0
fi

echo "Found the following resource groups:"
printf '  - %s\n' "${RG_LIST[@]}"
echo

read -p "❓ Are you sure you want to delete ALL of these resource groups and all their resources? [y/N]: " CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
  echo "⏭️  Aborting. No changes have been made."
  exit 0
fi

# Kick off deletion for each RG asynchronously
for RG in "${RG_LIST[@]}"; do
  echo "==> Initiating delete for: $RG"
  az group delete \
    --subscription "$ARM_SUBSCRIPTION_ID" \
    --name "$RG" \
    --yes \
    --no-wait
done

echo
echo "✅ All deletions initiated."
echo "==> Polling deletion status (updates every 10 seconds). Press Ctrl+C to stop."

# Poll until no resource groups remain
while true; do
  NOW=$(date '+%Y-%m-%d %H:%M:%S')
  echo
  echo "[$NOW] Current resource group provisioning states:"
  az group list \
    --subscription "$ARM_SUBSCRIPTION_ID" \
    --query "[].{Name:name, State:provisioningState}" \
    -o table

  COUNT=$(az group list --subscription "$ARM_SUBSCRIPTION_ID" --query "length(@)" -o tsv)
  if [ "$COUNT" -eq 0 ]; then
    echo
    echo "🎉 All resource groups have been deleted."
    break
  fi

  sleep 10
done

