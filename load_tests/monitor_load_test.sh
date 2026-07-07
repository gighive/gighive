#!/usr/bin/env bash
# monitor_load_test.sh — System monitoring companion for load_test_guest_uploads.py
#
# Captures per-container CPU, memory, network I/O, and block I/O via docker stats,
# plus FPM worker count, FPM listen queue depth, and MySQL concurrency every
# POLL_INTERVAL seconds. Writes timestamped TSV to load_test_runs/ and shows a
# live summary line in the terminal.
#
# Usage:
#   ./monitor_load_test.sh                    # use defaults below
#   APACHE_CONTAINER=myweb ./monitor_load_test.sh
#
# All settings can be overridden via environment variables.
#
# In a separate terminal on pop-os (~/gighive/load_tests/), run the load test:
#   python3 load_test_guest_uploads.py --url https://devvm.gighive.internal --token <TOKEN> \
#       --no-ssl-verify --concurrency 10 --count 30
#
# Stop monitoring with Ctrl-C; a summary of peak values is printed on exit.

set -euo pipefail

# ── Configurable settings ──────────────────────────────────────────────────────
APACHE="${APACHE_CONTAINER:-apacheWebServer}"
TUSD="${TUSD_CONTAINER:-apacheWebServer_tusd}"
MYSQL="${MYSQL_CONTAINER:-mysqlServer}"
PHP_VER="${PHP_VERSION:-8.3}"
FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"
FPM_MAX_CHILDREN="${FPM_MAX_CHILDREN:-20}"   # set to your pm.max_children value
INTERVAL="${POLL_INTERVAL:-2}"               # seconds between samples

# ── Output directory ───────────────────────────────────────────────────────────
SCRIPTDIR="$(cd "$(dirname "$0")" && pwd)"
RUNDIR="${SCRIPTDIR}/load_test_runs"
mkdir -p "$RUNDIR"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
LOGFILE="${RUNDIR}/monitor_${TIMESTAMP}.tsv"

# ── Colours ────────────────────────────────────────────────────────────────────
RED='\033[0;31m'; YEL='\033[0;33m'; GRN='\033[0;32m'; CYN='\033[0;36m'; RST='\033[0m'

# ── State for peak tracking ────────────────────────────────────────────────────
peak_apache_cpu=0
peak_tusd_cpu=0
peak_mysql_cpu=0
peak_apache_mem=0
peak_fpm_workers=0
peak_fpm_queue=0
peak_mysql_threads=0
sample_count=0

# ── Cleanup on Ctrl-C ─────────────────────────────────────────────────────────
cleanup() {
    echo ""
    echo ""
    echo -e "${CYN}══════════════════════════════════════════════════════════════${RST}"
    echo -e "${CYN}  Peak values over ${sample_count} samples (${INTERVAL}s interval)${RST}"
    echo -e "${CYN}══════════════════════════════════════════════════════════════${RST}"
    printf "  %-28s %s\n" "Apache CPU%:"          "${peak_apache_cpu}%"
    printf "  %-28s %s\n" "tusd CPU%:"            "${peak_tusd_cpu}%"
    printf "  %-28s %s\n" "MySQL CPU%:"           "${peak_mysql_cpu}%"
    printf "  %-28s %s\n" "Apache memory:"        "${peak_apache_mem}"
    printf "  %-28s %s / ${FPM_MAX_CHILDREN}\n" \
           "FPM workers (peak):"  "${peak_fpm_workers}"
    printf "  %-28s %s\n" "FPM listen queue (peak):" "${peak_fpm_queue}"
    printf "  %-28s %s\n" "MySQL Threads_running:"    "${peak_mysql_threads}"
    echo ""
    echo -e "  Full log: ${CYN}${LOGFILE}${RST}"
    echo -e "${CYN}══════════════════════════════════════════════════════════════${RST}"
    exit 0
}
trap cleanup INT TERM

# ── Verify containers are running ─────────────────────────────────────────────
for cname in "$APACHE" "$TUSD" "$MYSQL"; do
    if ! docker inspect --format '{{.State.Running}}' "$cname" 2>/dev/null | grep -q true; then
        echo -e "${RED}ERROR: Container '$cname' is not running.${RST}"
        echo "  Override with: APACHE_CONTAINER=name TUSD_CONTAINER=name MYSQL_CONTAINER=name"
        exit 1
    fi
done

# ── Write TSV header ───────────────────────────────────────────────────────────
printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "timestamp" \
    "apache_cpu_pct" "apache_mem_mib" \
    "apache_net_in_mb" "apache_net_out_mb" \
    "apache_blk_r_mb" "apache_blk_w_mb" \
    "tusd_cpu_pct" "tusd_blk_w_mb" \
    "mysql_cpu_pct" "mysql_mem_mib" \
    "fpm_spawned" "fpm_listen_queue" "mysql_threads_running" \
    > "$LOGFILE"

# ── Helper: strip % and unit suffixes, convert to a bare number ───────────────
# Converts "12.5%" → "12.5", "256MiB" → "256", "1.5GB" → "1536" (MB)
strip_unit() {
    local val="${1//\%/}"
    case "$val" in
        *GiB) echo "${val%GiB*}" | awk '{printf "%.0f", $1 * 1024}' ;;
        *MiB) echo "${val%MiB*}" ;;
        *GB)  echo "${val%GB*}"  | awk '{printf "%.0f", $1 * 1000}' ;;
        *MB)  echo "${val%MB*}"  ;;
        *kB)  echo "${val%kB*}"  | awk '{printf "%.3f", $1 / 1000}' ;;
        *B)   echo "0" ;;
        *)    echo "${val:-0}" ;;
    esac
}

max_of() { echo "$1 $2" | awk '{print ($1>$2)?$1:$2}'; }

# ── Container stats parser (defined once, closes over stats_raw via global) ────
parse_container() {
    local name="$1"
    echo "$stats_raw" | awk -F'\t' -v n="$name" '$1 == n {print $2"\t"$3"\t"$4"\t"$5}'
}

# ── Print header line ─────────────────────────────────────────────────────────
echo ""
echo -e "${CYN}GigHive Load Test Monitor${RST}  |  log → ${LOGFILE}"
echo -e "Containers: ${GRN}${APACHE}${RST}  ${GRN}${TUSD}${RST}  ${GRN}${MYSQL}${RST}"
echo -e "FPM max_children: ${FPM_MAX_CHILDREN}  |  Poll interval: ${INTERVAL}s  |  Press Ctrl-C to stop"
echo ""
printf "%-22s %-12s %-12s %-10s %-12s %-12s %-8s %-10s\n" \
    "TIME" "APACHE_CPU" "TUSD_CPU" "MYSQL_CPU" \
    "FPM_SPAWNED" "FPM_QUEUE" "MEM_MiB" "MYSQL_THR"
printf '%s\n' "$(printf '─%.0s' {1..88})"

# ── Main polling loop ─────────────────────────────────────────────────────────
while true; do
    ts=$(date +%H:%M:%S)
    ts_full=$(date +%Y-%m-%dT%H:%M:%S)

    # ── docker stats snapshot (single sample, all three containers) ───────────
    # Output: NAME<TAB>CPU%<TAB>MEM_USAGE<TAB>NET_IO<TAB>BLOCK_IO
    stats_raw=$(docker stats --no-stream \
        --format "{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}\t{{.BlockIO}}" \
        "$APACHE" "$TUSD" "$MYSQL" 2>/dev/null || true)

    apache_raw=$(parse_container "$APACHE")
    tusd_raw=$(parse_container "$TUSD")
    mysql_raw=$(parse_container "$MYSQL")

    # CPU% (strip %)
    apache_cpu="${apache_raw%%	*}"; apache_cpu="${apache_cpu//%/}"
    tusd_cpu="${tusd_raw%%	*}";   tusd_cpu="${tusd_cpu//%/}"
    mysql_cpu="${mysql_raw%%	*}";  mysql_cpu="${mysql_cpu//%/}"

    # Memory: "256MiB / 8GiB" → take first token
    apache_mem_raw=$(echo "$apache_raw" | awk -F'\t' '{print $2}' | awk '{print $1}')
    apache_mem=$(strip_unit "$apache_mem_raw")

    # Network I/O: "1.5GB / 2.1GB" → in / out
    apache_net=$(echo "$apache_raw" | awk -F'\t' '{print $3}')
    apache_net_in=$(strip_unit "$(echo "$apache_net" | awk '{print $1}')")
    apache_net_out=$(strip_unit "$(echo "$apache_net" | awk '{print $3}')")

    # Block I/O: "0B / 1.2GB" → read / write
    apache_blk=$(echo "$apache_raw" | awk -F'\t' '{print $4}')
    apache_blk_r=$(strip_unit "$(echo "$apache_blk" | awk '{print $1}')")
    apache_blk_w=$(strip_unit "$(echo "$apache_blk" | awk '{print $3}')")

    tusd_blk=$(echo "$tusd_raw" | awk -F'\t' '{print $4}')
    tusd_blk_w=$(strip_unit "$(echo "$tusd_blk" | awk '{print $3}')")

    mysql_mem_raw=$(echo "$mysql_raw" | awk -F'\t' '{print $2}' | awk '{print $1}')
    mysql_mem=$(strip_unit "$mysql_mem_raw")

    # ── FPM worker process count (inside Apache container) ────────────────────
    # Counts master + all workers; subtract 1 for master to get worker slots used.
    fpm_total=$(docker exec "$APACHE" bash -c \
        "ps aux 2>/dev/null | grep -c '[p]hp-fpm'" 2>/dev/null || echo "0")
    fpm_workers=$((fpm_total > 0 ? fpm_total - 1 : 0))

    # ── FPM listen queue depth (Recv-Q on the LISTEN unix socket) ─────────────
    # > 0 means requests are queueing because all workers are busy.
    fpm_queue=$(docker exec "$APACHE" bash -c \
        "ss -xlnp 2>/dev/null | awk -v s='${FPM_SOCK}' '\$0 ~ s && \$2 == \"LISTEN\" {print \$3}'" \
        2>/dev/null || echo "0")
    fpm_queue="${fpm_queue:-0}"

    # ── MySQL Threads_running ──────────────────────────────────────────────────
    mysql_threads=$(docker exec "$MYSQL" bash -c \
        'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --silent --skip-column-names \
         -e "SHOW GLOBAL STATUS LIKE \"Threads_running\"" 2>/dev/null | awk "{print \$2}"' \
        2>/dev/null || echo "?")
    mysql_threads="${mysql_threads:-?}"

    # ── Update peak values ─────────────────────────────────────────────────────
    peak_apache_cpu=$(max_of "${peak_apache_cpu}" "${apache_cpu:-0}")
    peak_tusd_cpu=$(max_of "${peak_tusd_cpu}"   "${tusd_cpu:-0}")
    peak_mysql_cpu=$(max_of "${peak_mysql_cpu}"  "${mysql_cpu:-0}")
    peak_apache_mem=$(max_of "${peak_apache_mem}" "${apache_mem:-0}")
    peak_fpm_workers=$(max_of "${peak_fpm_workers}" "${fpm_workers:-0}")
    if [[ "$fpm_queue" =~ ^[0-9]+$ ]]; then
        peak_fpm_queue=$(max_of "${peak_fpm_queue}" "$fpm_queue")
    fi
    if [[ "$mysql_threads" =~ ^[0-9]+$ ]]; then
        peak_mysql_threads=$(max_of "${peak_mysql_threads}" "$mysql_threads")
    fi
    sample_count=$((sample_count + 1))

    # ── Colour-code FPM saturation signals ────────────────────────────────────
    fpm_colour="${GRN}"
    if [[ "$fpm_workers" -ge "$FPM_MAX_CHILDREN" ]]; then
        fpm_colour="${RED}"       # pool fully saturated
    elif [[ "$fpm_workers" -ge $((FPM_MAX_CHILDREN * 80 / 100)) ]]; then
        fpm_colour="${YEL}"       # ≥ 80% full
    fi
    queue_colour="${GRN}"
    if [[ "$fpm_queue" =~ ^[0-9]+$ ]] && [[ "$fpm_queue" -gt 0 ]]; then
        queue_colour="${RED}"     # requests are queueing
    fi

    # ── Print live summary line ────────────────────────────────────────────────
    printf "%-22s %-12s %-12s %-10s ${fpm_colour}%-12s${RST} ${queue_colour}%-12s${RST} %-8s %-10s\n" \
        "$ts" \
        "${apache_cpu:--}%" \
        "${tusd_cpu:--}%" \
        "${mysql_cpu:--}%" \
        "${fpm_workers}/${FPM_MAX_CHILDREN} spawned" \
        "${fpm_queue}" \
        "${apache_mem:--}" \
        "${mysql_threads}"

    # ── Append TSV row ─────────────────────────────────────────────────────────
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
        "$ts_full" \
        "${apache_cpu:-}" "${apache_mem:-}" \
        "${apache_net_in:-}" "${apache_net_out:-}" \
        "${apache_blk_r:-}" "${apache_blk_w:-}" \
        "${tusd_cpu:-}" "${tusd_blk_w:-}" \
        "${mysql_cpu:-}" "${mysql_mem:-}" \
        "${fpm_workers:-}" "${fpm_queue:-}" \
        "${mysql_threads:-}" \
        >> "$LOGFILE"

    sleep "$INTERVAL"
done
