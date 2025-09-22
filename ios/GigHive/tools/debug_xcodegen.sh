#!/usr/bin/env bash
set -Eeuo pipefail

# Debug script for investigating xcodegen plist generation issues
# Usage:
#   chmod +x tools/debug_xcodegen.sh
#   tools/debug_xcodegen.sh

THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${THIS_DIR}/.." && pwd)"
cd "$ROOT_DIR"

TS="$(date +%Y%m%d_%H%M%S)"
LOG="${ROOT_DIR}/debug_xcodegen_${TS}.log"
SPEC_JSON="${ROOT_DIR}/spec_${TS}.json"
TMP_DIR="${ROOT_DIR}/tmp_debug_${TS}"
mkdir -p "$TMP_DIR"

log() {
  echo -e "$*" | tee -a "$LOG"
}

section() {
  log "\n===== $* ====="
}

section "Context"
log "PWD: $(pwd)"
log "Listing key dirs:"
ls -ld "${ROOT_DIR}" | tee -a "$LOG"
ls -ld "${ROOT_DIR}/Configs" | tee -a "$LOG" || true
ls -ld "${ROOT_DIR}/Sources" | tee -a "$LOG" || true
ls -ld "${ROOT_DIR}/Sources/App" | tee -a "$LOG" || true
ls -ld "${ROOT_DIR}/Sources/ShareExtension" | tee -a "$LOG" || true

section "XcodeGen version"
if command -v xcodegen >/dev/null 2>&1; then
  xcodegen version | tee -a "$LOG"
else
  log "xcodegen not found in PATH"
fi

section "plutil -lint on plists (if present)"
for P in \
  "${ROOT_DIR}/Configs/AppInfo.plist" \
  "${ROOT_DIR}/Sources/App/Info.plist" \
  "${ROOT_DIR}/Sources/ShareExtension/Info.plist"; do
  if [[ -f "$P" ]]; then
    log "plutil -lint $P"
    plutil -lint "$P" 2>&1 | tee -a "$LOG" || true
  else
    log "(missing) $P"
  fi
done

section "Dumping resolved XcodeGen spec"
if command -v xcodegen >/dev/null 2>&1; then
  if xcodegen dump > "$SPEC_JSON" 2>>"$LOG"; then
    log "spec written: $SPEC_JSON"
    log "Inspecting spec for plist and URL types related keys:"
    grep -nE 'INFOPLIST_FILE|infoPlist|CFBundleURLTypes|urlTypes' "$SPEC_JSON" | tee -a "$LOG" || true
  else
    log "xcodegen dump failed"
  fi
fi

section "Directory writability checks"
for D in \
  "${ROOT_DIR}/Configs" \
  "${ROOT_DIR}/Sources/App" \
  "${ROOT_DIR}/Sources/ShareExtension"; do
  if [[ -d "$D" ]]; then
    log "Checking write to $D"
    TEST_FILE="$D/.write_test_${TS}"
    (echo test > "$TEST_FILE" && rm -f "$TEST_FILE" && echo "OK") 2>&1 | tee -a "$LOG" || true
  else
    log "(missing dir) $D"
  fi
done

section "Attempt xcodegen generate (verbose)"
export XCODEGEN_LOG_LEVEL=verbose
set +e
xcodegen generate 2>&1 | tee -a "$LOG"
XG_STATUS=${PIPESTATUS[0]}
set -e
log "xcodegen generate exit status: $XG_STATUS"

section "Post-generate: presence of plists"
for P in \
  "${ROOT_DIR}/Configs/AppInfo.plist" \
  "${ROOT_DIR}/Sources/App/Info.plist" \
  "${ROOT_DIR}/Sources/ShareExtension/Info.plist"; do
  if [[ -f "$P" ]]; then
    log "exists: $P ($(wc -c < "$P") bytes)"
  else
    log "missing: $P"
  fi
done

if [[ $XG_STATUS -ne 0 ]]; then
  section "Isolation: disable Share Extension target and try again"
  TMP_SPEC="$TMP_DIR/project.noext.yml"
  cp "${ROOT_DIR}/project.yml" "$TMP_SPEC"
  # Comment out the GigHiveShare target block at the same indentation level.
  # This sed range starts at a line that begins with two spaces then 'GigHiveShare:'
  # and comments lines until the next line that begins with two spaces and an alphanumeric (next top-level target) or 'schemes:'
  sed -i '' -e '/^  GigHiveShare:/,/^  [A-Za-z]|^schemes:/ s/^/# /' "$TMP_SPEC" 2>/dev/null || true
  # If BSD sed with -i '' failed, try GNU sed syntax
  if ! grep -q "#   GigHiveShare:" "$TMP_SPEC"; then
    sed -i -e '/^  GigHiveShare:/,/^  [A-Za-z]\|^schemes:/ s/^/# /' "$TMP_SPEC" 2>/dev/null || true
  fi
  log "Using temp spec: $TMP_SPEC"
  set +e
  xcodegen --spec "$TMP_SPEC" generate 2>&1 | tee -a "$LOG"
  XG_STATUS_NOEXT=${PIPESTATUS[0]}
  set -e
  log "xcodegen (no Share Extension) exit status: $XG_STATUS_NOEXT"
fi

section "Done"
log "Log saved to: $LOG"

exit 0
