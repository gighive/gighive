#!/usr/bin/env bash
set -euo pipefail

BUNDLE_URL="https://staging.gighive.app/downloads/gighive-one-shot-bundle.tgz"
BUNDLE_TGZ="gighive-one-shot-bundle.tgz"
BUNDLE_SHA="${BUNDLE_TGZ}.sha256"
BUNDLE_DIR="gighive-one-shot-bundle"

need_cmd() {
  command -v "$1" >/dev/null 2>&1
}

download_file() {
  local url="$1"
  local out="$2"

  if need_cmd curl; then
    curl -fL -o "$out" "$url"
  elif need_cmd wget; then
    wget -O "$out" "$url"
  else
    echo "ERROR: Neither curl nor wget is installed. Install one of them and re-run."
    exit 1
  fi
}

echo "== GigHive quickstart installer =="
echo "This script follows the Quickstart install instructions." 1>&2

# 1) Download tarball
echo
echo "==> Downloading bundle:"
echo "    $BUNDLE_URL"
if [[ -f "$BUNDLE_TGZ" ]]; then
  echo "NOTE: $BUNDLE_TGZ already exists; re-downloading to ensure freshness..."
  rm -f "$BUNDLE_TGZ"
fi
download_file "$BUNDLE_URL" "$BUNDLE_TGZ"

# 2) Create and verify checksum (as per quickstart doc)
echo
echo "==> Creating checksum file:"
sha256sum "$BUNDLE_TGZ" > "$BUNDLE_SHA"

echo "==> Verifying bundle integrity:"
sha256sum -c "$BUNDLE_SHA"

# 3) Expand tarball
echo
echo "==> Expanding tarball:"
rm -rf "$BUNDLE_DIR"
tar -xzf "$BUNDLE_TGZ"

# 4) Run installer (interactive prompts handled by install.sh)
echo
echo "==> Running installer:"
cd "$BUNDLE_DIR"
chmod +x ./install.sh
./install.sh

# 5) Quick verification helpers
echo
echo "==> Next checks you can run:"
echo "    cd \"$PWD\""
echo "    docker compose ps"
echo "    docker compose logs -n 200 mysqlServer"
echo "    docker compose logs -n 200 apacheWebServer"

echo
echo "==> Optional smoke tests (replace HOST_IP with your host IP):"
cat <<'EOF'
  curl -kI https://HOST_IP/
  curl -kI https://HOST_IP/db/database.php
  curl -kI https://viewer:YOUR_VIEWER_PASSWORD@HOST_IP/db/database.php
EOF

echo
echo "Done."
