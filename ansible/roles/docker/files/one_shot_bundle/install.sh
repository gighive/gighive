#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  ./install.sh [--non-interactive]
               [--site-url URL]
               [--audio-dir PATH]
               [--video-dir PATH]
               [--admin-password PASS]
               [--uploader-password PASS]
               [--viewer-password PASS]
               [--mysql-db NAME]
               [--mysql-user USER]
               [--mysql-password PASS]
               [--mysql-root-password PASS]
               [--tz TZ]
               [--mysql-dataset sample]

Notes:
- Default dataset is 'sample'.
- In interactive mode, you'll be prompted for any missing values.
EOF
}

NON_INTERACTIVE=0

MIN_PASSWORD_LEN=12

SITE_URL="${SITE_URL:-}"
AUDIO_DIR="${AUDIO_DIR:-./_host_audio}"
VIDEO_DIR="${VIDEO_DIR:-./_host_video}"

MYSQL_DATABASE="${MYSQL_DATABASE:-music_db}"
MYSQL_USER="${MYSQL_USER:-appuser}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-}"

ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
UPLOADER_PASSWORD="${UPLOADER_PASSWORD:-}"
VIEWER_PASSWORD="${VIEWER_PASSWORD:-}"

TZ="${TZ:-America/New_York}"
MYSQL_DATASET="${MYSQL_DATASET:-sample}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --help|-h) usage; exit 0 ;;
    --non-interactive) NON_INTERACTIVE=1; shift ;;
    --site-url) SITE_URL="${2:-}"; shift 2 ;;
    --audio-dir) AUDIO_DIR="${2:-}"; shift 2 ;;
    --video-dir) VIDEO_DIR="${2:-}"; shift 2 ;;
    --admin-password) echo "Password args are not supported right now; use interactive prompts." >&2; exit 2 ;;
    --uploader-password) echo "Password args are not supported right now; use interactive prompts." >&2; exit 2 ;;
    --viewer-password) echo "Password args are not supported right now; use interactive prompts." >&2; exit 2 ;;
    --mysql-db) MYSQL_DATABASE="${2:-}"; shift 2 ;;
    --mysql-user) MYSQL_USER="${2:-}"; shift 2 ;;
    --mysql-password) echo "Password args are not supported right now; use interactive prompts." >&2; exit 2 ;;
    --mysql-root-password) echo "Password args are not supported right now; use interactive prompts." >&2; exit 2 ;;
    --tz) TZ="${2:-}"; shift 2 ;;
    --mysql-dataset) MYSQL_DATASET="${2:-}"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; usage; exit 2 ;;
  esac
done

if [[ "$NON_INTERACTIVE" -eq 1 ]]; then
  echo "--non-interactive is not supported right now; interactive prompts are required." >&2
  exit 2
fi

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "Missing required command: $1" >&2; exit 1; }
}

require_cmd docker
if docker compose version >/dev/null 2>&1; then
  DOCKER_COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  DOCKER_COMPOSE=(docker-compose)
else
  echo "Missing Docker Compose (need 'docker compose' or 'docker-compose')" >&2
  exit 1
fi

if [[ ! -f docker-compose.yml ]]; then
  echo "Run this from the directory containing docker-compose.yml" >&2
  exit 1
fi

prompt() {
  local var_name="$1"
  local prompt_text="$2"
  local secret="${3:-0}"
  local current_val="${!var_name:-}"

  if [[ -n "$current_val" ]]; then
    return 0
  fi

  if [[ "$NON_INTERACTIVE" -eq 1 ]]; then
    echo "Missing required value: $var_name" >&2
    exit 1
  fi

  if [[ "$secret" -eq 1 ]]; then
    echo "Internal error: prompt() should not be used for secret values" >&2
    exit 1
  else
    read -r -p "$prompt_text: " current_val
  fi

  if [[ -z "$current_val" ]]; then
    echo "Value required: $var_name" >&2
    exit 1
  fi

  printf -v "$var_name" '%s' "$current_val"
}

prompt_secret() {
  local var_name="$1"
  local prompt_text="$2"
  local min_len="$3"

  local v1=""
  local v2=""
  local attempts=0

  while true; do
    attempts=$((attempts + 1))
    if [[ "$attempts" -gt 5 ]]; then
      echo "Too many attempts for $var_name" >&2
      exit 1
    fi

    read -r -s -p "$prompt_text: " v1
    echo
    read -r -s -p "$prompt_text (confirm): " v2
    echo

    if [[ -z "$v1" ]]; then
      echo "Value required: $var_name" >&2
      continue
    fi

    if [[ "$v1" != "$v2" ]]; then
      echo "Values did not match. Please try again." >&2
      continue
    fi

    if [[ "${#v1}" -lt "$min_len" ]]; then
      echo "$var_name must be at least ${min_len} characters." >&2
      continue
    fi

    printf -v "$var_name" '%s' "$v1"
    return 0
  done
}

# Required inputs
prompt SITE_URL "SITE_URL (example: https://192.168.1.252)"
prompt AUDIO_DIR "Host path for audio dir (will be created if missing)"
prompt VIDEO_DIR "Host path for video dir (will be created if missing)"
prompt_secret ADMIN_PASSWORD "BasicAuth password for user 'admin'" "$MIN_PASSWORD_LEN"
prompt_secret UPLOADER_PASSWORD "BasicAuth password for user 'uploader'" "$MIN_PASSWORD_LEN"
prompt_secret VIEWER_PASSWORD "BasicAuth password for user 'viewer'" "$MIN_PASSWORD_LEN"
prompt_secret MYSQL_PASSWORD "MYSQL_PASSWORD" "$MIN_PASSWORD_LEN"
prompt_secret MYSQL_ROOT_PASSWORD "MYSQL_ROOT_PASSWORD" "$MIN_PASSWORD_LEN"

if [[ "$MYSQL_DATASET" != "sample" ]]; then
  echo "Only 'sample' is supported by this installer right now. You set: $MYSQL_DATASET" >&2
  exit 1
fi

mkdir -p "$AUDIO_DIR" "$VIDEO_DIR"

# Ensure config dirs exist (bundle should include them; this is defensive)
mkdir -p apache/externalConfigs mysql/externalConfigs

HTPASSWD_HOST_FILE="apache/externalConfigs/gighive.htpasswd"

echo "Generating BasicAuth file: ${HTPASSWD_HOST_FILE}"
rm -f "${HTPASSWD_HOST_FILE}"

docker run --rm \
  -e ADMIN_PASSWORD="${ADMIN_PASSWORD}" \
  -e UPLOADER_PASSWORD="${UPLOADER_PASSWORD}" \
  -e VIEWER_PASSWORD="${VIEWER_PASSWORD}" \
  -v "${PWD}/apache/externalConfigs:/work" \
  httpd:2.4 sh -lc '
    set -e
    /usr/local/apache2/bin/htpasswd -bc /work/gighive.htpasswd admin "$ADMIN_PASSWORD"
    /usr/local/apache2/bin/htpasswd -b  /work/gighive.htpasswd uploader "$UPLOADER_PASSWORD"
    /usr/local/apache2/bin/htpasswd -b  /work/gighive.htpasswd viewer "$VIEWER_PASSWORD"
  '

APACHE_ENV_FILE="apache/externalConfigs/.env"
MYSQL_ENV_FILE="mysql/externalConfigs/.env.mysql"

# Write apache env
cat > "$APACHE_ENV_FILE" <<EOF
SITE_URL=$SITE_URL
DB_HOST=mysqlServer
DB_PORT=3306

MYSQL_DATABASE=$MYSQL_DATABASE
MYSQL_USER=$MYSQL_USER
MYSQL_PASSWORD=$MYSQL_PASSWORD
MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD

GIGHIVE_HTPASSWD_PATH=/var/www/private/gighive.htpasswd
GIGHIVE_MYSQL_BACKUPS_DIR=/var/www/private/mysql_backups
GIGHIVE_MYSQL_RESTORE_LOG_DIR=/var/www/private/restorelogs
FILENAME_SEQ_PAD="\${FILENAME_SEQ_PAD:-5}"

MEDIA_SEARCH_DIRS=/var/www/html/audio:/var/www/html/video
MEDIA_LIST_PAGINATION_THRESHOLD=750

APP_FLAVOR=gighive

GA4_MEASUREMENT_ID_DEFAULTCODEBASE=
GA4_MEASUREMENT_ID_GIGHIVE=G-HBWR9ZZE3N

# NOTE: keep these lists aligned with your gighive.yml defaults if you change them
UPLOAD_ALLOWED_MIMES_JSON=["audio/mpeg","audio/mp3","audio/wav","audio/x-wav","audio/aac","audio/flac","audio/mp4","video/mp4","video/quicktime","video/x-matroska","video/webm","video/x-msvideo","audio/aiff","audio/x-aiff","audio/ogg","application/ogg","audio/vorbis","audio/basic","audio/x-au","audio/au","audio/mp2","audio/mpeg2","audio/x-ms-wma","video/mpeg","video/mp2t","video/MP2T","video/ogg","video/x-flv","application/mxf","video/mxf","application/vnd.rn-realmedia","audio/vnd.rn-realaudio","audio/x-pn-realaudio","video/vnd.rn-realvideo","video/x-ms-wmv","video/x-ms-asf","application/vnd.ms-asf"]
UPLOAD_AUDIO_EXTS_JSON=["mp3","wav","flac","aac","m4a","m4p","aif","aifc","aiff","ogg","oga","au","m2a","wma"]
UPLOAD_VIDEO_EXTS_JSON=["mp4","mov","mkv","webm","m4v","avi","mpg","mpeg","ts","m2t","m2ts","ogv","mxf","flv","rm","rmvb","rv","wmv","vob","m2v","bup","ifo"]

TUS_CLIENT_CHUNK_SIZE_BYTES=8388608
EOF

# Write mysql env
cat > "$MYSQL_ENV_FILE" <<EOF
MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD
MYSQL_DATABASE=$MYSQL_DATABASE
MYSQL_USER=$MYSQL_USER
MYSQL_PASSWORD=$MYSQL_PASSWORD
DB_HOST=mysqlServer
MYSQL_ROOT_HOST=%
EOF

echo "Wrote:"
echo "  - $APACHE_ENV_FILE"
echo "  - $MYSQL_ENV_FILE"

# Export host media paths for compose (compose should reference these)
export GIGHIVE_AUDIO_DIR="$AUDIO_DIR"
export GIGHIVE_VIDEO_DIR="$VIDEO_DIR"

echo "Bringing stack up..."
"${DOCKER_COMPOSE[@]}" up -d --build

echo "Done."
echo "Next checks:"
echo "  - ${DOCKER_COMPOSE[*]} ps"
echo "  - ${DOCKER_COMPOSE[*]} logs -n 200 mysqlServer"
echo "  - ${DOCKER_COMPOSE[*]} logs -n 200 apacheWebServer"
