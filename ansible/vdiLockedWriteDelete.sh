#!/usr/bin/env bash
#
# vdiLockedWriteDelete.sh
#
# Interactively unlock and delete a VirtualBox VDI stuck in "locked write".
# - Shows HDD info
# - Detects running VM(s) and offers to stop them (ACPI / savestate / poweroff) with escalation
# - Targets the VM's own VBoxHeadless PID first if still stuck
# - Shows remaining VBox processes, lets you kill per PID (y/n/a=all/q=quit)
# - Tries VBoxManage closemedium (with/without --delete)
# - Removes .lock file if present
# - Optionally deletes the VDI
# - NEW: Finds media-registry mismatches (e.g., missing/inaccessible .vmdk/.vdi) and offers to remove stale entries:
#        VBoxManage closemedium disk <medium-UUID>
#

# Require bash
if [[ -z "${BASH_VERSION:-}" ]]; then
  echo "[ERR ] Run with bash (e.g., 'bash vdiLockedWriteDelete.sh')." >&2
  exit 1
fi

# Strict-ish, avoid -e so prompts/timeouts won't abort
set -uo pipefail

# ----------------------
# Defaults (your values)
# ----------------------
UUID_DEFAULT="de0c2506-0d5b-44d6-903f-fbe325fabc21"
VDI_DEFAULT="/home/sodo/gighive/ansible/roles/cloud_init/files/jammy-server-cloudimg-amd64-gighive.vdi"

UUID="${UUID_DEFAULT}"
VDI_PATH="${VDI_DEFAULT}"

# Tuning
WAIT_SECONDS=30        # wait time after ACPI/savestate/poweroff
SLEEP_STEP=1           # polling step seconds

log()  { printf "[INFO] %s\n" "$*"; }
warn() { printf "[WARN] %s\n" "$*" >&2; }
err()  { printf "[ERR ] %s\n" "$*" >&2; }

# --- Prompt helpers that ALWAYS read from the terminal ---
prompt_line() {
  # $1 = prompt text; echoes the answer to stdout. Returns 0 if read OK, 1 otherwise.
  local _ans=""
  if read -r -p "$1" _ans </dev/tty; then
    echo "$_ans"
    return 0
  else
    return 1
  fi
}

confirm_tty() {
  # $1 = prompt text (w/o [y/N])
  local _ans
  if ! _ans=$(prompt_line "$1 [y/N]: "); then
    warn "No input available; defaulting to No."
    return 1
  fi
  [[ "${_ans:-}" =~ ^[Yy]$ ]]
}

# ----------------------
# Helpers
# ----------------------
vm_is_running() {
  local id="$1"
  VBoxManage list runningvms 2>/dev/null | grep -q "$id"
}

list_running_vms() {
  VBoxManage list runningvms 2>/dev/null || true
}

vm_headless_pid() {
  # prints VBoxHeadless PID that launched --startvm <UUID> (if any)
  local id="$1"
  ps -eo pid,args | awk -v id="$id" '
    /VBoxHeadless/ {
      for (i=1;i<=NF;i++) {
        if ($i=="--startvm" && (i+1)<=NF && $(i+1)==id) {
          print $1
        }
      }
    }'
}

progress_wait_until_stopped() {
  # $1 vm_id, $2 label, $3 timeout seconds
  local id="$1" label="$2" timeout="$3" i=0
  while (( i < timeout )); do
    if ! vm_is_running "$id"; then
      printf "\r[INFO] VM '%s' stopped.%-40s\n" "$label" ""
      return 0
    fi
    printf "\r[INFO] Waiting for '%s' to stop (%ds/%ds)..." "$label" "$i" "$timeout"
    sleep "$SLEEP_STEP"
    ((i+=SLEEP_STEP))
  done
  printf "\r[WARN] VM '%s' still running after %ds.%-20s\n" "$label" "$timeout" ""
  return 1
}

try_control_and_wait() {
  # $1 vm_id, $2 label, $3 command (acpipowerbutton|savestate|poweroff)
  local id="$1" label="$2" cmd="$3"
  case "$cmd" in
    acpipowerbutton) log "Sending ACPI power button to '$label'...";;
    savestate)       log "Saving state for '$label'...";;
    poweroff)        warn "Hard power off '$label'...";;
  esac
  VBoxManage controlvm "$id" "$cmd" 2>/dev/null || true
  progress_wait_until_stopped "$id" "$label" "$WAIT_SECONDS"
}

stop_vm_with_escalation() {
  local vm_id="$1" vm_label="$2"

  echo ""
  echo "VM detected: $vm_label ($vm_id)"
  echo "Choose how to stop it:"
  echo "  [1] ACPI power button (graceful shutdown)"
  echo "  [2] Save state (fast, safe)"
  echo "  [3] Power off (hard power cut)"
  echo "  [s] Skip"

  local sel
  if ! sel=$(prompt_line "Selection: "); then
    warn "No selection read (stdin/tty closed?). Skipping VM stop."
    return 0
  fi

  case "$sel" in
    1)
      if try_control_and_wait "$vm_id" "$vm_label" acpipowerbutton; then
        return 0
      fi
      # Escalation after ACPI
      local next
      if ! next=$(prompt_line "ACPI failed to stop. Try [s]avestate, [p]oweroff, or [k]ill headless PID? (s/p/k/skip): "); then
        warn "No input; skipping escalation."
        return 0
      fi
      case "$next" in
        s|S)
          if try_control_and_wait "$vm_id" "$vm_label" savestate; then return 0; fi
          ;;
        p|P)
          if try_control_and_wait "$vm_id" "$vm_label" poweroff; then return 0; fi
          ;;
        k|K)
          kill_vm_headless_pid "$vm_id" "$vm_label"
          ;;
        *) log "Skipping escalation."; ;;
      esac
      ;;
    2)
      if try_control_and_wait "$vm_id" "$vm_label" savestate; then return 0; fi
      ;;
    3)
      if try_control_and_wait "$vm_id" "$vm_label" poweroff; then return 0; fi
      ;;
    *)
      log "Skipping stop for '$vm_label'."
      ;;
  esac

  # Final chance: offer to kill the VM's headless PID if still running
  if vm_is_running "$vm_id"; then
    kill_vm_headless_pid "$vm_id" "$vm_label"
  fi
}

kill_vm_headless_pid() {
  # $1 vm_id, $2 label
  local id="$1" label="$2"
  local pid
  pid="$(vm_headless_pid "$id" | head -n1 || true)"
  if [[ -n "${pid:-}" ]]; then
    warn "VM '$label' still running; found VBoxHeadless PID: $pid"
    local ans
    if ans=$(prompt_line "Kill VBoxHeadless PID $pid for VM '$label'? [y/N]: "); then
      if [[ "$ans" =~ ^[Yy]$ ]]; then
        log "Killing PID $pid (VBoxHeadless for '$label')"
        kill -9 "$pid" || true
        sleep 1
        if ! vm_is_running "$id"; then
          log "VM '$label' no longer running."
          return 0
        else
          warn "VM '$label' still appears to be running."
        fi
      else
        log "Skipped killing VBoxHeadless PID $pid."
      fi
    else
      warn "No input; skipped killing VBoxHeadless."
    fi
  else
    warn "No VBoxHeadless PID found for VM '$label'."
  fi
}

show_and_optionally_kill_remaining_pids() {
  log "Checking for running VirtualBox processes..."
  # PID, COMMAND, ARGS
  mapfile -t vbox_lines < <(ps -eo pid,comm,args | awk '/VBoxSVC|VBoxHeadless|VirtualBox/ && $2 !~ /awk/')
  if [[ ${#vbox_lines[@]} -eq 0 ]]; then
    log "No VirtualBox processes found."
    return 0
  fi

  printf "\n%-8s %-20s %s\n" "PID" "COMMAND" "ARGS"
  printf "%s\n" "${vbox_lines[@]}" | awk '{printf "%-8s %-20s %s\n",$1,$2,substr($0,index($0,$3))}'
  echo ""

  local kill_all=false
  for line in "${vbox_lines[@]}"; do
    local pid cmd
    pid=$(awk '{print $1}' <<< "$line")
    cmd=$(awk '{print $2}' <<< "$line")

    if $kill_all; then
      log "Killing PID $pid ($cmd)"
      kill -9 "$pid" || true
      continue
    fi

    local ans
    if ! ans=$(prompt_line "Kill PID $pid ($cmd)? [y/n/a=all/q=quit]: "); then
      warn "Prompt read failed; skipping remaining kills."
      break
    fi
    case "$ans" in
      y|Y) log "Killing PID $pid ($cmd)"; kill -9 "$pid" || true ;;
      n|N) log "Skipping PID $pid ($cmd)" ;;
      a|A) log "Killing this and all remaining…"; kill_all=true; kill -9 "$pid" || true ;;
      q|Q) log "Quitting process kill loop"; break ;;
      *)   log "Skipping PID $pid ($cmd)" ;;
    esac
  done
}

closemedium_attempts() {
  local extra="$1" # "" or "--delete"
  log "Attempting: VBoxManage closemedium disk ${UUID} ${extra}"
  VBoxManage closemedium disk "${UUID}" ${extra} && return 0 || true

  if [[ -e "${VDI_PATH}" ]]; then
    log "Attempting (by path): VBoxManage closemedium disk \"${VDI_PATH}\" ${extra}"
    VBoxManage closemedium disk "${VDI_PATH}" ${extra} && return 0 || true
  fi
  return 1
}

# ----------------------
# NEW: Media registry mismatch scan & cleanup (VDMK/VDI)
# ----------------------
scan_and_fix_media_registry() {
  log "Scanning VirtualBox media registry for missing/inaccessible disks…"
  if ! VBoxManage list hdds >/dev/null 2>&1; then
    warn "Unable to list hdds; skipping registry scan."
    return 0
  fi

  # Collect blocks as: UUID|Location|Format|State
  mapfile -t MEDIA < <(VBoxManage list hdds | awk '
    BEGIN{RS=""; FS="\n"}
    {
      uuid=""; loc=""; fmt=""; state="";
      for(i=1;i<=NF;i++){
        if ($i ~ /^UUID:/)    { sub(/^UUID:[ \t]*/,"",$i); uuid=$i }
        if ($i ~ /^Location:/){ sub(/^Location:[ \t]*/,"",$i); loc=$i }
        if ($i ~ /^Format:/)  { sub(/^Format:[ \t]*/,"",$i); fmt=$i }
        if ($i ~ /^State:/)   { sub(/^State:[ \t]*/,"",$i); state=$i }
      }
      if (uuid!="") {
        printf "%s|%s|%s|%s\n", uuid, loc, fmt, state
      }
    }')

  local found_mismatch=false

  for entry in "${MEDIA[@]}"; do
    IFS='|' read -r m_uuid m_loc m_fmt m_state <<<"$entry"

    # Only care about disk-like media (VDI/VMDK/Parallels/etc.) — default to checking all
    # Mismatch if file missing OR state says inaccessible
    local missing=false inaccessible=false
    if [[ -n "$m_loc" && ! -e "$m_loc" ]]; then
      missing=true
    fi
    # Some installs report "inaccessible" in State; double-check via showmediuminfo for certainty
    if grep -qi '^inaccessible' <<<"$m_state"; then
      inaccessible=true
    else
      if VBoxManage showmediuminfo "$m_uuid" 2>/dev/null | grep -qi '^State: *inaccessible'; then
        inaccessible=true
      fi
    fi

    if $missing || $inaccessible; then
      found_mismatch=true
      echo ""
      warn "Stale media entry detected in registry"
      printf "  UUID:     %s\n" "$m_uuid"
      printf "  Location: %s\n" "${m_loc:-<none>}"
      printf "  Format:   %s\n" "${m_fmt:-unknown}"
      printf "  State:    %s\n" "${m_state:-unknown}"
      if $missing;     then warn "  Reason:   File missing on disk"; fi
      if $inaccessible; then warn "  Reason:   Reported inaccessible"; fi

      if confirm_tty "Remove this stale entry from the VirtualBox media registry? (runs: VBoxManage closemedium disk ${m_uuid})"; then
        log "Running: VBoxManage closemedium disk ${m_uuid}"
        if VBoxManage closemedium disk "${m_uuid}"; then
          log "Successfully removed registry entry for ${m_uuid}"
        else
          warn "Failed to remove registry entry for ${m_uuid}"
        fi
      else
        log "Skipped removing ${m_uuid}"
      fi
    fi
  done

  if ! $found_mismatch; then
    log "No stale/mismatched media entries found."
  fi
}

# ----------------------
# Step 0: HDD state
# ----------------------
if ! command -v VBoxManage >/dev/null 2>&1; then
  err "VBoxManage not found in PATH."
  exit 1
fi

log "Current VirtualBox HDDs (filtered for the provided UUID/path):"
if VBoxManage list hdds >/dev/null 2>&1; then
  VBoxManage list hdds | awk -v u="${UUID}" -v p="${VDI_PATH}" '
    BEGIN{RS=""; FS="\n"}
    {
      m=0
      for(i=1;i<=NF;i++){
        if(index($i,u)>0 || index($i,p)>0) m=1
      }
      if(m){print "---\n"$0"\n---"}
    }'
else
  warn "Failed to list hdds."
fi

# ----------------------
# Step 1: Show running VMs and offer to stop them (with escalation)
# ----------------------
echo ""
log "Running VMs (from VBoxManage):"
list_running_vms || true

# Also infer VM IDs from VBoxHeadless PIDs (e.g., --startvm <UUID>)
mapfile -t headless_vm_ids < <(ps -eo args | awk '
  /VBoxHeadless/ {
    for (i=1;i<=NF;i++) {
      if ($i=="--startvm" && (i+1)<=NF) {
        print $(i+1)
      }
    }
  }' | sort -u)

if [[ ${#headless_vm_ids[@]} -gt 0 ]]; then
  log "Detected VM IDs from VBoxHeadless processes:"
  printf "  %s\n" "${headless_vm_ids[@]}"
fi

# Prompt to stop each detected VM (unique list of names/ids)
declare -A seen=()
while IFS= read -r line; do
  # Expect: "NAME" {UUID}
  name=$(sed -n 's/^\("\{0,1\}\)\(.*\)\1 \({.*}\)$/\2/p' <<< "$line")
  uuid_braced=$(sed -n 's/^.* \({.*}\)$/\1/p' <<< "$line")
  [[ -z "${name:-}" || -z "${uuid_braced:-}" ]] && continue
  uuid="${uuid_braced#\{}"; uuid="${uuid%\}}"
  if [[ -z "${seen[$uuid]:-}" ]]; then
    seen[$uuid]=1
    stop_vm_with_escalation "$uuid" "$name"
  fi
done < <(VBoxManage list runningvms || true)

for id in "${headless_vm_ids[@]}"; do
  if [[ -z "${seen[$id]:-}" ]]; then
    seen[$id]=1
    stop_vm_with_escalation "$id" "$id"
  fi
done

# ----------------------
# Step 2: Offer to kill remaining VBox processes (fallback)
# ----------------------
show_and_optionally_kill_remaining_pids

# ----------------------
# Step 2.5: Scan for registry mismatches (stale VMDK/VDI) and offer cleanup
# ----------------------
scan_and_fix_media_registry

# ----------------------
# Step 3: Try to close/unregister & delete target medium
# ----------------------
if ! closemedium_attempts "--delete"; then
  warn "closemedium with --delete failed. Retrying without delete…"
  closemedium_attempts "" || warn "closemedium without delete also failed."
fi

# ----------------------
# Step 4: Remove lock file (if present)
# ----------------------
LOCKFILE="${VDI_PATH}.lock"
if [[ -e "$LOCKFILE" ]]; then
  warn "Found lock file: $LOCKFILE"
  if confirm_tty "Remove lock file?"; then
    rm -f -- "$LOCKFILE"
    log "Removed $LOCKFILE"
  else
    warn "Skipped removing lock file."
  fi
fi

# Retry close after lock removal
closemedium_attempts "" || true

# ----------------------
# Step 5: Delete VDI if user approves
# ----------------------
if [[ -e "${VDI_PATH}" ]]; then
  if confirm_tty "Delete VDI file at '${VDI_PATH}'?"; then
    rm -f -- "${VDI_PATH}"
    log "Deleted ${VDI_PATH}"
  else
    warn "Skipped deleting ${VDI_PATH}."
  fi
else
  log "VDI file not found at '${VDI_PATH}' (already removed or moved)."
fi

log "Done."

