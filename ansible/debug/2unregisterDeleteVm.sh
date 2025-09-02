#!/usr/bin/bash -v
set -euo pipefail

BOX="gighive"
TIMEOUT=60    # total seconds to wait for clean shutdown
INTERVAL=5    # seconds between checks

echo "Currently running VMs:"
VBoxManage list runningvms
VBoxManage showvminfo $BOX | grep ^VMState


echo
echo "→ Sending ACPI shutdown to VM '$BOX'..."
VBoxManage controlvm "$BOX" acpipowerbutton

# wait for clean shutdown…
state="running"
elapsed=0
while [[ $elapsed -lt $TIMEOUT ]]; do
  state=$(VBoxManage showvminfo "$BOX" --machinereadable \
    | awk -F= '/^VMState=/{gsub(/"/,"",$2); print $2}')
  [[ "$state" == "poweroff" ]] && break
  sleep $INTERVAL
  (( elapsed += INTERVAL ))
done

if [[ $state != poweroff ]]; then
  echo "ACPI failed: forcibly powering off"
  VBoxManage controlvm "$BOX" poweroff
fi

echo "VM is now powered off. Unregistering and deleting everything…"
VBoxManage unregistervm "$BOX" --delete

echo "→ VM '$BOX' has been unregistered and all disk/images removed."
VBoxManage list runningvms
VBoxManage showvminfo $BOX | grep ^VMState

ls -l ~/VirtualBox\ VMs
echo "Done."
