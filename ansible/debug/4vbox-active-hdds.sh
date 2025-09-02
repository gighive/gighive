#!/usr/bin/env bash
# vbox-active-hdds.sh
# Lists VirtualBox HDDs that are attached to VMs vs. unreferenced (stale) ones.
# Usage:
#   ./vbox-active-hdds.sh            # check all defined VMs
#   ./vbox-active-hdds.sh --running  # check only running VMs

set -euo pipefail

only_running=0
if [[ "${1:-}" == "--running" ]]; then
  only_running=1
fi

# --- Declare associative arrays up front
declare -A HDD_STATE
declare -A HDD_LOCATION
declare -A USED_BY  # UUID -> newline-separated VM names

# --- Build HDD registry: UUID -> {state, location}
current_uuid=""
while IFS= read -r line; do
  case "$line" in
    UUID:\ *)
      current_uuid="${line#UUID:           }"
      current_uuid="${current_uuid// /}"
      ;;
    State:\ *)
      [[ -n "$current_uuid" ]] && HDD_STATE["$current_uuid"]="${line#State:          }"
      ;;
    Location:\ *)
      [[ -n "$current_uuid" ]] && HDD_LOCATION["$current_uuid"]="${line#Location:       }"
      ;;
  esac
done < <(VBoxManage list hdds)

# --- Get list of VMs
if (( only_running )); then
  mapfile -t VMS < <(VBoxManage list runningvms | awk -F\" '{print $2}')
else
  mapfile -t VMS < <(VBoxManage list vms | awk -F\" '{print $2}')
fi

if (( ${#VMS[@]} == 0 )); then
  echo "No VMs found (running: $only_running). Exiting."
  exit 2
fi

# --- Process attached disks for each VM
for vm in "${VMS[@]}"; do
  while IFS= read -r line; do
    # Look for image UUIDs from --machinereadable output
    if [[ "$line" =~ -ImageUUID-[0-9]+-[0-9]+=\"([0-9a-fA-F-]{36})\" ]]; then
      uuid="${BASH_REMATCH[1]}"
      [[ -z "$uuid" ]] && continue
      USED_BY["$uuid"]+="$vm"$'\n'
    fi
  done < <(VBoxManage showvminfo "$vm" --machinereadable)
done

# --- Display used disks
printf "\n=== Disks IN USE (%s) ===\n" "$([[ $only_running -eq 1 ]] && echo "running VMs" || echo "all VMs")"
printf "%-36s  %-22s  %-14s  %s\n" "UUID" "VM(s)" "State" "Location"
printf "%-36s  %-22s  %-14s  %s\n" "------------------------------------" "----------------------" "------------" "------------------------------"

for uuid in "${!USED_BY[@]}"; do
  vms=$(printf "%s" "${USED_BY[$uuid]}" | tr -s '\n' ',' | sed 's/,$//')
  state="${HDD_STATE[$uuid]:-unknown}"
  loc="${HDD_LOCATION[$uuid]:-<unknown>}"
  printf "%-36s  %-22s  %-14s  %s\n" "$uuid" "$vms" "$state" "$loc"
done

# --- Display orphaned disks
printf "\n=== Disks NOT referenced by any VM (likely stale) ===\n"
printf "%-36s  %-14s  %s\n" "UUID" "State" "Location"
printf "%-36s  %-14s  %s\n" "------------------------------------" "------------" "------------------------------"

orphan_found=0
for uuid in "${!HDD_LOCATION[@]}"; do
  if ! [[ -v USED_BY[$uuid] ]]; then
    printf "%-36s  %-14s  %s\n" "$uuid" "${HDD_STATE[$uuid]}" "${HDD_LOCATION[$uuid]}"
    orphan_found=1
  fi
done

# --- Exit code:
# 0 = everything used
# 1 = some orphans found
# 2 = no VMs at all
(( orphan_found == 1 )) && exit 1 || exit 0

