#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# GigHive Prerequisites Installer (Ubuntu 22.04)
#  - Prompted sections: Azure, Ansible, Terraform, VirtualBox
#  - Azure + Ansible live in the same Python venv: ~/.ansible-azure
#  - Python SDK sanity checks: WARN ONLY
#  - Core CLI sanity checks: HARD FAIL
# ==============================================================================

log()  { printf "\n\033[1;34m%s\033[0m\n" "$*"; }
warn() { printf "\n\033[1;33m%s\033[0m\n" "$*"; }
err()  { printf "\n\033[1;31mERROR:\033[0m %s\n" "$*" >&2; }
die()  { err "$*"; exit 1; }

prompt_yes_no() {
  local q="$1"; local default="${2:-Y}"; local ans
  local prompt="[y/N]"
  [[ "${default^^}" == "Y" ]] && prompt="[Y/n]"
  read -r -p "${q} ${prompt} " ans || true
  ans="${ans:-$default}"
  [[ "${ans}" =~ ^([Yy]|[Yy][Ee][Ss])$ ]]
}

ensure_base_packages() {
  log "🔧 Ensuring base packages are present (Ubuntu 22.04)..."
  sudo apt-get update -y
  sudo apt-get install -y \
    python3 python3-pip python3-venv \
    curl gnupg software-properties-common lsb-release ca-certificates \
    apt-transport-https \
    genisoimage cloud-image-utils
}

VENV_DIR="${HOME}/.ansible-azure"
activate_or_create_venv() {
  if [[ ! -d "${VENV_DIR}" ]]; then
    log "📦 Creating Python virtualenv at ${VENV_DIR}..."
    python3 -m venv "${VENV_DIR}" || die "Could not create venv"
  fi
  # shellcheck source=/dev/null
  source "${VENV_DIR}/bin/activate" || die "Failed to activate venv at ${VENV_DIR}"
  log "✅ Using venv: ${VENV_DIR}"
}

sanity_check_python_warn_only() {
  log "✅ Running Python sanity checks (warn-only)..."
  python - <<'EOF'
import importlib
mods = [
    "packaging",
    "azure", "azure.cli", "azure.mgmt.resource", "azure.storage.blob",
    "msgraph", "jinja2", "jinja2cli"
]
for m in mods:
    try:
        importlib.import_module(m)
        print(f"✅ Python import OK: {m}")
    except Exception as e:
        print(f"⚠️  Python import warning: {m} not available ({e})")
EOF
}

check_cli_or_die() {
  local cmd="$1"
  if ! command -v "$cmd" >/dev/null 2>&1; then
    die "Required CLI '$cmd' not found in PATH"
  fi
  if ! "$cmd" --version >/dev/null 2>&1; then
    die "Required CLI '$cmd' is installed but not functioning"
  fi
  echo "✅ $cmd present and responding"
}

install_azure() {
  log "☁️  (Azure) Upgrading pip toolchain..."
  python -m pip install --upgrade pip packaging wheel setuptools

  local req_file="azure-prereqs.txt"
  if [[ -f "${req_file}" ]]; then
    log "📥 (Azure) Installing Python prerequisites from ${req_file}..."
    pip install -r "${req_file}"
  else
    warn "(Azure) ${req_file} not found — skipping file-based installs."
  fi

  sanity_check_python_warn_only
  log "🎉 Azure prerequisites step complete."
}

install_ansible() {
  log "🐜 (Ansible) Installing via pip (in venv)..."
  python -m pip install --upgrade pip
  pip install ansible

  log "📦 (Ansible) Installing collections: community.general, community.docker..."
  ansible-galaxy collection install community.general
  ansible-galaxy collection install community.docker

  log "🧪 (Ansible) CLI sanity check (hard fail if broken)..."
  check_cli_or_die ansible
  check_cli_or_die ansible-galaxy

  if ! ansible-galaxy collection list | grep -E 'community\.general|community\.docker' >/dev/null 2>&1; then
    warn "(Ansible) Could not verify collections via 'ansible-galaxy collection list'."
  fi

  log "🎉 Ansible installed (in venv) with requested collections."

  # Offer to persist PATH update
  if prompt_yes_no "Add ~/.ansible-azure/bin to your shell PATH permanently?" "Y"; then
    SHELL_RC=""
    if [ -n "${ZSH_VERSION:-}" ]; then
      SHELL_RC="$HOME/.zshrc"
    elif [ -n "${BASH_VERSION:-}" ]; then
      SHELL_RC="$HOME/.bashrc"
    else
      SHELL_RC="$HOME/.bashrc"
    fi

    if ! grep -qs '.ansible-azure/bin' "$SHELL_RC"; then
      echo 'export PATH="$HOME/.ansible-azure/bin:$PATH"' >> "$SHELL_RC"
      log "✅ Added ~/.ansible-azure/bin to PATH in $SHELL_RC"
      log "ℹ️  Run 'source $SHELL_RC' or open a new terminal for it to take effect."
    else
      log "ℹ️  ~/.ansible-azure/bin already in PATH in $SHELL_RC"
    fi
  fi
}

install_terraform() {
  log "🧱 (Terraform) Installing from HashiCorp apt repo..."
  if [[ ! -f /usr/share/keyrings/hashicorp-archive-keyring.gpg ]]; then
    curl -fsSL https://apt.releases.hashicorp.com/gpg \
      | sudo gpg --dearmor -o /usr/share/keyrings/hashicorp-archive-keyring.gpg
  fi
  UBU_CODENAME="$(lsb_release -cs)"
  echo "deb [signed-by=/usr/share/keyrings/hashicorp-archive-keyring.gpg] https://apt.releases.hashicorp.com ${UBU_CODENAME} main" \
    | sudo tee /etc/apt/sources.list.d/hashicorp.list >/dev/null

  sudo apt-get update -y
  sudo apt-get install -y terraform

  log "🧪 (Terraform) CLI sanity check (hard fail if broken)..."
  check_cli_or_die terraform
  log "🎉 Terraform installed."
}

install_virtualbox() {
  log "📦 (VirtualBox) Installing from Oracle’s apt repo..."
  sudo apt-get install -y dkms "linux-headers-$(uname -r)" || \
    warn "(VirtualBox) linux-headers for current kernel not found; continuing."

  if [[ ! -f /usr/share/keyrings/oracle-virtualbox-2016.gpg ]]; then
    curl -fsSL https://www.virtualbox.org/download/oracle_vbox_2016.asc \
      | sudo gpg --dearmor -o /usr/share/keyrings/oracle-virtualbox-2016.gpg
  fi
  UBU_CODENAME="$(lsb_release -cs)"
  echo "deb [signed-by=/usr/share/keyrings/oracle-virtualbox-2016.gpg] https://download.virtualbox.org/virtualbox/debian ${UBU_CODENAME} contrib" \
    | sudo tee /etc/apt/sources.list.d/virtualbox.list >/dev/null

  sudo apt-get update -y
  sudo apt-get install -y virtualbox-7.0

  log "🧪 (VirtualBox) CLI sanity check (hard fail if broken)..."
  if command -v VBoxManage >/dev/null 2>&1; then
    check_cli_or_die VBoxManage
  elif command -v vboxmanage >/dev/null 2>&1; then
    check_cli_or_die vboxmanage
  else
    die "VirtualBox CLI (VBoxManage) not found in PATH after install"
  fi

  if prompt_yes_no "Install VirtualBox Extension Pack (matching version)?" "N"; then
    VBCTL="$(command -v VBoxManage || command -v vboxmanage)"
    VB_VER="$("$VBCTL" --version | cut -d'r' -f1 || true)"
    if [[ -n "${VB_VER}" ]]; then
      TMP="/tmp/Oracle_VM_VirtualBox_Extension_Pack.vbox-extpack"
      curl -fL "https://download.virtualbox.org/virtualbox/${VB_VER}/Oracle_VM_VirtualBox_Extension_Pack-${VB_VER}.vbox-extpack" -o "${TMP}" || {
        warn "(VirtualBox) Could not fetch Extension Pack for ${VB_VER}."
      }
      if [[ -f "${TMP}" ]]; then
        sudo "$VBCTL" extpack install --replace "${TMP}" || warn "(VirtualBox) extpack install failed."
        rm -f "${TMP}"
      fi
    else
      warn "(VirtualBox) Could not determine installed VBox version for extpack."
    fi
  fi

  if getent group vboxusers >/dev/null 2>&1; then
    sudo usermod -aG vboxusers "$USER" || warn "(VirtualBox) Could not add $USER to vboxusers."
    log "ℹ️  You may need to log out/in for vboxusers group to take effect."
  fi

  log "🎉 VirtualBox installed."
}

# ------------------------------ Main ------------------------------

ensure_base_packages

echo
log "Which components would you like to install?"
echo "  1) Azure (Python libs via venv)"
echo "  2) Ansible (pip in same venv) + collections (community.general, community.docker)"
echo "  3) Terraform (system apt)"
echo "  4) VirtualBox (Oracle apt)"
echo

DO_AZURE=false
DO_ANSIBLE=false
DO_TERRAFORM=false
DO_VBOX=false

if prompt_yes_no "Install Azure prerequisites?" "Y"; then DO_AZURE=true; fi
if prompt_yes_no "Install Ansible + requested collections?" "Y"; then DO_ANSIBLE=true; fi
if prompt_yes_no "Install Terraform?" "Y"; then DO_TERRAFORM=true; fi
if prompt_yes_no "Install VirtualBox?" "N"; then DO_VBOX=true; fi

if $DO_AZURE || $DO_ANSIBLE; then
  activate_or_create_venv
fi

$DO_AZURE     && install_azure
$DO_ANSIBLE   && install_ansible
$DO_TERRAFORM && install_terraform
$DO_VBOX      && install_virtualbox

log "✅ All selected installs complete."

