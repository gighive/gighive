#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE' >&2
Usage:
  run_resize_request.sh -i <inventory_file> <request_file>
  run_resize_request.sh -i <inventory_file> --request-host <vm_host> [--request-inventory-host <inventory_host>] [--request-dir <dir>] --latest
  run_resize_request.sh -i <inventory_file> --request-host <vm_host> [--request-inventory-host <inventory_host>] [--request-dir <dir>] <remote_request_filename>
  run_resize_request.sh -i <inventory_file> [--request-host <vm_host> [--request-inventory-host <inventory_host>] [--request-dir <dir>] (--latest|<remote_request_filename>)] --dry-run

Examples:
  ./ansible/tools/run_resize_request.sh -i ansible/inventories/inventory_gighive2.yml \
    /home/ubuntu/scripts/gighive/ansible/roles/docker/files/apache/externalConfigs/resizerequests/req-*.json

  ./ansible/tools/run_resize_request.sh -i ansible/inventories/inventory_gighive2.yml --request-host gighive2 --latest

  ./ansible/tools/run_resize_request.sh -i ansible/inventories/inventory_gighive2.yml --request-host gighive2 --latest --dry-run
USAGE
}

inventory_file=""
latest=false
dry_run=false
request_host=""
request_inventory_host=""
request_dir=""

request_id=""
cache_root="${HOME}/.cache/gighive/resize_requests"
processed_dir="${cache_root}/processed"
lock_path="${cache_root}/lock"

while [[ $# -gt 0 ]]; do
  case "$1" in
    -i)
      inventory_file="${2:-}"
      shift 2
      ;;
    --request-host)
      request_host="${2:-}"
      shift 2
      ;;
    --request-inventory-host)
      request_inventory_host="${2:-}"
      shift 2
      ;;
    --request-dir)
      request_dir="${2:-}"
      shift 2
      ;;
    --latest)
      latest=true
      shift
      ;;
    --dry-run)
      dry_run=true
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      break
      ;;
  esac
done

ansible_get_var() {
  local host="$1"
  local var_name="$2"
  local out=""
  local value=""

  # Extract first matching var value from ansible's -o output.
  # Works for both inventory hostnames and groups (will take the first host result).
  out="$(ansible -i "$inventory_file" "$host" -m ansible.builtin.debug -a "var=${var_name}" -o 2>/dev/null || true)"

  if [[ -z "$out" ]]; then
    return 0
  fi

  value="$(echo "$out" \
    | sed -n "s/.*\"${var_name}\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" \
    | awk 'NF { print; exit }')"

  if [[ -z "$value" || "$value" == "VARIABLE IS NOT DEFINED!" ]]; then
    return 0
  fi

  echo "$value"
}

if [[ -n "$request_host" && -z "$request_inventory_host" ]]; then
  request_inventory_host="$request_host"
fi

# Derive the remote request directory from Ansible inventory vars.
# Tries gighive_resize_requests_host_path first; if unresolvable (docker_dir
# is a set_fact, not a group_var), falls back to gighive_home + known suffix.
derive_request_dir() {
  local inv_host="$1"
  local dir=""

  dir="$(ansible_get_var "$inv_host" "gighive_resize_requests_host_path" || true)"
  # Reject if empty or still contains an unresolved Jinja template
  if [[ -z "$dir" || "$dir" == *"{{"* ]]; then
    dir=""
  fi

  if [[ -z "$dir" ]]; then
    local gighive_home=""
    gighive_home="$(ansible_get_var "$inv_host" "gighive_home" || true)"
    if [[ -n "$gighive_home" && "$gighive_home" != *"{{"* ]]; then
      dir="${gighive_home}/ansible/roles/docker/files/apache/externalConfigs/resizerequests"
    fi
  fi

  echo "$dir"
}

mkdir -p "$processed_dir"

exec 9>"$lock_path"
if ! flock -n 9; then
  echo "Error: another resize request run is already in progress (lock: $lock_path)" >&2
  exit 2
fi

if [[ -z "$inventory_file" ]]; then
  echo "Error: missing -i <inventory_file>" >&2
  usage
  exit 2
fi

if [[ ! -f "$inventory_file" ]]; then
  echo "Error: inventory file not found: $inventory_file" >&2
  exit 2
fi

request_file="${1:-}"

tmp_request_file=""
cleanup() {
  if [[ -n "$tmp_request_file" && -f "$tmp_request_file" ]]; then
    rm -f "$tmp_request_file"
  fi
}
trap cleanup EXIT

if [[ "$latest" == "true" ]]; then
  if [[ -z "$request_host" ]]; then
    echo "Error: --latest requires --request-host <vm_host>" >&2
    usage
    exit 2
  fi

  if [[ -z "$request_dir" ]]; then
    if [[ -z "$request_inventory_host" ]]; then
      echo "Error: unable to derive request dir without --request-inventory-host (or --request-host)" >&2
      exit 2
    fi
    request_dir="$(derive_request_dir "$request_inventory_host" || true)"
    if [[ -z "$request_dir" ]]; then
      echo "Error: unable to derive request dir for ${request_inventory_host}. Pass --request-dir explicitly." >&2
      exit 2
    fi
  fi

  remote_latest="$(ssh "ubuntu@${request_host}" "ls -1t ${request_dir}/req-*.json 2>/dev/null | head -n 1" 2>/dev/null || true)"
  if [[ -z "$remote_latest" ]]; then
    echo "Error: no request files found on ${request_host}:${request_dir}" >&2
    exit 2
  fi

  request_id="$(basename "$remote_latest")"

  tmp_request_file="$(mktemp -t gighive-resize-req.XXXXXX.json)"
  ssh "ubuntu@${request_host}" "cat ${remote_latest}" > "$tmp_request_file"
  request_file="$tmp_request_file"
else
  if [[ -n "$request_host" ]]; then
    if [[ -z "$request_file" ]]; then
      echo "Error: missing remote request filename" >&2
      usage
      exit 2
    fi

    if [[ -z "$request_dir" ]]; then
      if [[ -z "$request_inventory_host" ]]; then
        echo "Error: unable to derive request dir without --request-inventory-host (or --request-host)" >&2
        exit 2
      fi
      request_dir="$(derive_request_dir "$request_inventory_host" || true)"
      if [[ -z "$request_dir" ]]; then
        echo "Error: unable to derive request dir for ${request_inventory_host}. Pass --request-dir explicitly." >&2
        exit 2
      fi
    fi

    if [[ "$request_file" == /* ]]; then
      remote_path="$request_file"
    else
      remote_path="${request_dir}/${request_file}"
    fi

    request_id="$(basename "$remote_path")"

    tmp_request_file="$(mktemp -t gighive-resize-req.XXXXXX.json)"
    ssh "ubuntu@${request_host}" "cat ${remote_path}" > "$tmp_request_file"
    request_file="$tmp_request_file"
  fi
fi

if [[ -z "$request_id" ]]; then
  if [[ -n "$request_file" ]]; then
    request_id="$(basename "$request_file")"
  fi
fi

if [[ -z "$request_id" ]]; then
  echo "Error: unable to determine request id (filename)" >&2
  exit 2
fi

processed_marker="${processed_dir}/${request_id}.ok"

if [[ -z "$request_file" ]]; then
  echo "Error: missing request file (or none found for --latest)" >&2
  usage
  exit 2
fi

if [[ ! -f "$request_file" ]]; then
  echo "Error: request file not found: $request_file" >&2
  exit 2
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "Error: jq not found. Install jq to use this tool." >&2
  exit 2
fi

if ! command -v ansible-inventory >/dev/null 2>&1; then
  echo "Error: ansible-inventory not found. Install Ansible to use this tool." >&2
  exit 2
fi

if ! command -v ansible >/dev/null 2>&1; then
  echo "Error: ansible not found. Install Ansible to use this tool." >&2
  exit 2
fi

inventory_host="$(jq -r '.inventory_host // empty' "$request_file")"
disk_size_mb="$(jq -r '.disk_size_mb // empty' "$request_file")"

if [[ -z "$inventory_host" ]]; then
  echo "Error: inventory_host missing in request: $request_file" >&2
  exit 2
fi

print_guest_summary() {
  local ssh_host=""
  local ssh_user=""

  ssh_host="$(ansible_get_var "$inventory_host" "ansible_host" || true)"
  ssh_user="$(ansible_get_var "$inventory_host" "ansible_user" || true)"
  if [[ -z "$ssh_user" ]]; then
    ssh_user="ubuntu"
  fi
  if [[ -z "$ssh_host" ]]; then
    ssh_host="$inventory_host"
  fi

  echo
  echo "--- Guest disk/filesystem summary ---"
  if ssh "${ssh_user}@${ssh_host}" 'df -h /; echo; lsblk' 2>/dev/null; then
    echo
    return 0
  fi
  echo "Warning: unable to fetch guest summary via SSH (${ssh_user}@${ssh_host})" >&2
  echo
  return 0
}

if ! [[ "$disk_size_mb" =~ ^[0-9]+$ ]]; then
  echo "Error: disk_size_mb missing/invalid in request: $request_file" >&2
  exit 2
fi

if [[ "$disk_size_mb" -lt 16384 ]]; then
  echo "Error: disk_size_mb too small (< 16384): $disk_size_mb" >&2
  exit 2
fi

disk_size_gib=$(( disk_size_mb / 1024 ))
disk_size_gb=$(( disk_size_mb / 1000 ))

# Resolve cloud_image_vdi to a concrete path (not raw Jinja templates)
cloud_image_vdi="$(ansible -i "$inventory_file" "$inventory_host" -m ansible.builtin.debug -a 'var=cloud_image_vdi' -o 2>/dev/null | sed -n 's/.*"cloud_image_vdi"[[:space:]]*:[[:space:]]*"\(.*\)".*/\1/p' | head -n 1)"

if [[ -z "$cloud_image_vdi" || "$cloud_image_vdi" == *"{{"* ]]; then
  cloud_image_vdi=""
fi

current_vdi_mb=""
if [[ -n "$cloud_image_vdi" ]]; then
  current_vdi_mb_raw="$(VBoxManage showmediuminfo disk "$cloud_image_vdi" 2>/dev/null | awk -F: '/^Capacity:/ {gsub(/^[[:space:]]+/, "", $2); print $2; exit 0}' || true)"
  if [[ "$current_vdi_mb_raw" =~ ^([0-9]+)[[:space:]]+MBytes$ ]]; then
    current_vdi_mb="${BASH_REMATCH[1]}"
  fi
fi

if [[ -n "$current_vdi_mb" ]]; then
  if [[ "$current_vdi_mb" -ge "$disk_size_mb" ]]; then
    echo "SKIP REASON: VDI capacity already >= requested. CapacityMB=${current_vdi_mb} RequestedMB=${disk_size_mb}"

    if [[ "$dry_run" == "true" ]]; then
      echo "DRY RUN: would mark request processed and skip."
      print_guest_summary
      exit 0
    fi

    date -Is > "$processed_marker"
    echo "OK: marked request processed (skipped): $processed_marker"
    print_guest_summary
    exit 0
  fi
fi

echo "Request: $request_file"
echo "RequestId: $request_id"
echo "Host:    $inventory_host"
echo "SizeMB:  $disk_size_mb"
echo "SizeGiB: $disk_size_gib"
echo "SizeGB:  $disk_size_gb"
if [[ -n "$cloud_image_vdi" ]]; then
  echo "VDI:     $cloud_image_vdi"
fi
if [[ -n "$current_vdi_mb" ]]; then
  echo "VDI_MB:  $current_vdi_mb"
fi

ansible_cmd=(
  ansible-playbook -i "$inventory_file" ansible/playbooks/resize_vdi.yml
  --limit "$inventory_host"
  -e "disk_size_mb=${disk_size_mb}"
)

guest_cmd=(
  ssh "$( (ansible_get_var "$inventory_host" "ansible_user" || true) | head -n 1 | sed -e 's/^$/ubuntu/' )@$( (ansible_get_var "$inventory_host" "ansible_host" || true) | head -n 1 | sed -e "s/^$/${inventory_host}/" )" 'sudo growpart /dev/sda 1 && sudo resize2fs /dev/sda1'
)

if [[ "$dry_run" == "true" ]]; then
  echo "DRY RUN: would execute:"
  if [[ -f "$processed_marker" ]]; then
    echo "SKIP REASON: request already processed (marker exists): $processed_marker"
    print_guest_summary
    exit 0
  fi
  printf '  %q' "${ansible_cmd[@]}"; echo
  printf '  %q' "${guest_cmd[@]}"; echo
  print_guest_summary
  exit 0
fi

if [[ -f "$processed_marker" ]]; then
  echo "SKIP REASON: request already processed (marker exists): $processed_marker"
  echo "OK: skipping execution"
  print_guest_summary
  exit 0
fi

"${ansible_cmd[@]}"

"${guest_cmd[@]}"

print_guest_summary

date -Is > "$processed_marker"

echo "OK: completed resize request for ${inventory_host}"
