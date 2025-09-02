echo "This usually takes about 3 minutes"
echo "Destroying resource group.."
# Terraform will never delete its own backend as part of terraform destroy, because it needs the state to know what to destroy.
az storage account delete \
  --name gighivetfstate \
  --resource-group tfstate-rg \
  --yes

az group delete \
  --name tfstate-rg \
  --yes 

# In most regions Azure automatically enables a Network Watcher instance when you start provisioning networking resources; it isn’t something you declared in your Terraform code. Because Terraform never created it, it’s not in the state, and thus terraform destroy won’t touch it.
echo "Destroying network watcher.."
az group delete \
  --name NetworkWatcherRG \
  --yes 

echo "List any remaining resources.."
az group list --query "[].name" --output table
