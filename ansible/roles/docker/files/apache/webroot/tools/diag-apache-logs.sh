#!/usr/bin/env bash
# diag-apache-logs.sh
# Read-only diagnostics for Apache logging inside a container.
# It does not modify any config. It will issue a single 404 request to test logging.

set -euo pipefail

section() { printf "\n===== %s =====\n" "$*"; }

CMD_APACHECTL=""
if command -v apachectl >/dev/null 2>&1; then
  CMD_APACHECTL="apachectl"
elif command -v apache2ctl >/dev/null 2>&1; then
  CMD_APACHECTL="apache2ctl"
elif command -v httpd >/dev/null 2>&1; then
  CMD_APACHECTL="httpd"
else
  echo "ERROR: Could not find apachectl/apache2ctl/httpd in PATH." >&2
  exit 1
fi

APACHE_USER="$(id -un 2>/dev/null || echo unknown)"
APACHE_GROUP="$(id -gn 2>/dev/null || echo unknown)"

section "Environment & Binaries"
date
echo "User: $APACHE_USER"
echo "Group: $APACHE_GROUP"
uname -a || true
if command -v httpd >/dev/null 2>&1; then
  httpd -v || true
fi
if command -v apache2 -v >/dev/null 2>&1; then
  apache2 -v || true
fi
$CMD_APACHECTL -v || true

section "Apache Config Test"
$CMD_APACHECTL -t || true

section "Loaded Modules (grep: log)"
# mod_log_config and related modules must be loaded
$CMD_APACHECTL -M 2>/dev/null | sort | tee /tmp/apache_modules.txt || true
echo
echo "Log-related modules:"
grep -E 'log|mod_security|security' -i /tmp/apache_modules.txt || true

section "Run-time Config Dump (selected)"
# Shows how Apache is actually configured at runtime
$CMD_APACHECTL -t -D DUMP_RUN_CFG 2>/dev/null | sed -n '1,200p' || true

section "VHosts Dump"
$CMD_APACHECTL -S 2>/dev/null || true

section "Global/VirtualHost Log Directives (grep)"
LOG_DIRS=()
if [ -d /etc/apache2 ]; then
  LOG_DIRS+=("/etc/apache2")
fi
if [ -d /etc/httpd ]; then
  LOG_DIRS+=("/etc/httpd")
fi
if [ -d /usr/local/apache2/conf ]; then
  LOG_DIRS+=("/usr/local/apache2/conf")
fi

if [ "${#LOG_DIRS[@]}" -eq 0 ]; then
  echo "No standard Apache config directories found."
else
  echo "Scanning config in: ${LOG_DIRS[*]}"
  grep -RniE '^\s*(ErrorLog|CustomLog|LogFormat|TransferLog|SecAuditLog|SecAuditLogStorageDir|Define\s+APACHE_LOG_DIR)' "${LOG_DIRS[@]}" 2>/dev/null | sed -e 's/^/CONF: /' || true
fi

section "APACHE_LOG_DIR and Common Log Paths"
echo "APACHE_LOG_DIR env: ${APACHE_LOG_DIR:-<unset>}"
# Common Debian path: /var/log/apache2; RHEL/CentOS: /var/log/httpd
for p in /var/log/apache2 /var/log/httpd /usr/local/apache2/logs; do
  if [ -d "$p" ]; then
    echo "Listing: $p"
    ls -l "$p" || true
    echo
    echo "Tail last lines of logs under $p (if readable)"
    find "$p" -maxdepth 1 -type f -name '*.log' -print0 | while IFS= read -r -d '' f; do
      echo "--- $f ---"
      tail -n 50 "$f" || echo "(cannot read $f)"
      echo
    done
  fi
done

section "Permissions on Log Directories/Files"
for p in /var/log/apache2 /var/log/httpd /usr/local/apache2/logs; do
  if [ -d "$p" ]; then
    echo "stat $p:"
    stat "$p" || true
    echo
    echo "Ownership summary:"
    ls -ld "$p" || true
    find "$p" -maxdepth 1 -type f -printf "%M %u:%g %p\n" 2>/dev/null || true
    echo
  fi
done

section "Container-style STDOUT/STDERR log targets"
# In containerized Apache images, CustomLog/ErrorLog often point to /proc/self/fd/1 and /proc/self/fd/2
echo "Grepping for /proc/self/fd in config:"
if [ "${#LOG_DIRS[@]}" -gt 0 ]; then
  grep -Rni --color=never '/proc/self/fd' "${LOG_DIRS[@]}" 2>/dev/null || echo "No direct fd targets found."
else
  echo "No config dirs to search."
fi

section "LogLevel"
# If LogLevel is too low, you may miss events; Dump detected LogLevel entries
if [ "${#LOG_DIRS[@]}" -gt 0 ]; then
  echo "Configured LogLevel entries:"
  grep -RniE '^\s*LogLevel\b' "${LOG_DIRS[@]}" 2>/dev/null || echo "(No explicit LogLevel found; default should be warn)"
fi

section "ModSecurity (if present)"
# If ModSecurity is enabled, audit log config may redirect or fail due to permissions
if grep -qi 'security2_module' /tmp/apache_modules.txt 2>/dev/null; then
  echo "Security2 (ModSecurity) appears loaded."
  # look for audit log settings shown earlier; also show runtime directives
  $CMD_APACHECTL -t -D DUMP_MODULES 2>/dev/null | grep -i security || true
  echo "Check audit log dir (if configured):"
  AUDIT_DIRS=$(grep -RniE 'SecAuditLog(StorageDir)?' "${LOG_DIRS[@]}" 2>/dev/null | awk -F':' '{print $3}' | awk '{print $2}' | tr -d '\"' || true)
  for d in $AUDIT_DIRS; do
    if [ -n "$d" ] && [ -d "$d" ]; then
      echo "Listing $d:"
      ls -l "$d" || true
    fi
  done
else
  echo "ModSecurity not detected (or modules list unavailable)."
fi

section "Trigger a test 404 (should create access/error log entries)"
# This will generate a 404. If Apache is healthy, it should log it either to files or stdout/stderr.
HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/this-should-404-$$ || true)"
echo "curl -> HTTP $HTTP_CODE"
echo "If HTTP code is 404, check logs above for new entries."

section "Common Root Causes & Next Steps (summary)"
cat <<'EOF'
- If ErrorLog/CustomLog point to /proc/self/fd/{1,2}:
  - Access/Error logs go to container stdout/stderr. Check `docker logs <container>` or your orchestrator's log viewer.
- If pointing to files under /var/log/apache2 or /var/log/httpd:
  - Ensure the directories/files are writable by the Apache user (often www-data:adm or apache:apache).
  - Verify no AppArmor/SELinux denials (less common in containers; check dmesg/ausearch if applicable).
- If no log directives found in vhosts:
  - Ensure mod_log_config is loaded and CustomLog is defined globally or per-vhost.
- If LogLevel is too low:
  - Temporarily set LogLevel to info or debug and reload to see more details (requires config change/reload).
- If ModSecurity is enabled but audit log is empty:
  - Verify SecAuditLog or SecAuditLogStorageDir permissions and that rules are not disabled.
- If the test 404 did not log:
  - Apache may not be receiving traffic on 127.0.0.1:80 (different listen/port).
  - Check `$ apachectl -S` and `$ netstat -tulpn | grep -E ':(80|443)\b'` (install net-tools if needed).
- Docker logging driver issues:
  - If using stdout/stderr logs, verify the container logging driver isn't dropping logs.
EOF

echo
echo "Diagnostics complete."
