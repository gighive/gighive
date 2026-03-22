#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  ./rotate_basic_auth.sh
EOF
}

if [[ ${1:-} == "--help" || ${1:-} == "-h" ]]; then
  usage
  exit 0
fi

MIN_PASSWORD_LEN=12
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
UPLOADER_PASSWORD="${UPLOADER_PASSWORD:-}"
VIEWER_PASSWORD="${VIEWER_PASSWORD:-}"

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

prompt_secret ADMIN_PASSWORD "BasicAuth password for user 'admin'" "$MIN_PASSWORD_LEN"
prompt_secret UPLOADER_PASSWORD "BasicAuth password for user 'uploader'" "$MIN_PASSWORD_LEN"
prompt_secret VIEWER_PASSWORD "BasicAuth password for user 'viewer'" "$MIN_PASSWORD_LEN"

mkdir -p apache/externalConfigs

HTPASSWD_HOST_FILE="apache/externalConfigs/gighive.htpasswd"
HTPASSWD_TMP_FILE="apache/externalConfigs/gighive.htpasswd.new"

rm -f "${HTPASSWD_TMP_FILE}"

echo "Generating BasicAuth file: ${HTPASSWD_HOST_FILE}"
docker run --rm \
  -e ADMIN_PASSWORD="${ADMIN_PASSWORD}" \
  -e UPLOADER_PASSWORD="${UPLOADER_PASSWORD}" \
  -e VIEWER_PASSWORD="${VIEWER_PASSWORD}" \
  -v "${PWD}/apache/externalConfigs:/work" \
  httpd:2.4 sh -lc '
    set -e
    /usr/local/apache2/bin/htpasswd -bc /work/gighive.htpasswd.new admin "$ADMIN_PASSWORD"
    /usr/local/apache2/bin/htpasswd -b  /work/gighive.htpasswd.new uploader "$UPLOADER_PASSWORD"
    /usr/local/apache2/bin/htpasswd -b  /work/gighive.htpasswd.new viewer "$VIEWER_PASSWORD"
  '

mv -f "${HTPASSWD_TMP_FILE}" "${HTPASSWD_HOST_FILE}"
sudo chown www-data:www-data "${HTPASSWD_HOST_FILE}"
sudo chmod 0640 "${HTPASSWD_HOST_FILE}"

echo "A restart of apacheWebServer is required for the new BasicAuth passwords to take effect."
echo "You may be prompted for your sudo password to restart apacheWebServer."
sudo -v
echo "Restarting apacheWebServer..."
sudo "${DOCKER_COMPOSE[@]}" restart apacheWebServer

echo "Done."
echo "Verify with:"
echo "  - ${DOCKER_COMPOSE[*]} logs -n 50 apacheWebServer"
