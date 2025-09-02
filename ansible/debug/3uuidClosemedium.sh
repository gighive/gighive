#!/usr/bin/bash -v
set -euo pipefail

# the absolute path of your VMDK on disk
DISK_PATH="$GIGHIVE_HOME/ansible/roles/cloud_init/files/jammy-server-cloudimg-amd64-gighive.vmdk"

# find the registered UUID whose Location matches your .vmdk
UUID=$(
  VBoxManage list hdds \
    --machinereadable \
  | awk -F'=' '
      $1 == "UUID"     {uuid=$2}
      $1 == "Location" { loc=$2; sub(/^"/,"",loc); sub(/"$/,"",loc)}
      loc == "'"${DISK_PATH}"'" { print uuid }
    ' \
  | tr -d '"'
)

if [[ -n "$UUID" ]]; then
  echo "Found old disk UUID $UUID → unregistering and deleting"
  VBoxManage closemedium disk "$UUID" --delete
else
  echo "No registered disk for $DISK_PATH, nothing to do"
fi

# If you also need to unregister a VM by name:
VM_NAME="gighive"
if VBoxManage list vms | grep -q "\"${VM_NAME}\""; then
  echo "Unregistering VM $VM_NAME"
  VBoxManage unregistervm "$VM_NAME" --delete
else
  echo "VM $VM_NAME not registered, skipping"
fi

