# Refactor: Standardise Ansible and Python Versions Across Environments

## Status

Standardisation complete (2026-08-16) — all three controllers pinned to ansible-core 2.20.4 via pipx under Python 3.12. Drift prevention is now an **ongoing process** (see section below).

---

## Problem

The Ansible control machine version differs between environments (dev vs lab), causing
playbook behaviour to diverge in ways that are hard to detect until a task fails in a
later environment. The specific trigger was the `MODULE_STRICT_UTF8_RESPONSE` default
changing between Ansible releases — dev tolerated a binary body passed through the `uri`
module, lab rejected it with a codec error.

### Observed symptom

```
[ERROR]: Task failed: Refusing to deserialize an invalid UTF8 string value:
'utf-8' codec can't encode character '\udcac' in position 25: surrogates not allowed
Task failed. Origin: post_build_checks/tasks/main.yml:350
```

This error only appeared on lab (not dev) because lab's Ansible/Python is a newer version
with `MODULE_STRICT_UTF8_RESPONSE` enabled by default. The same task ran silently on dev.

### Why this matters

- Tasks that work on dev may fail on lab, staging, or prod if the controller version
  differs. The failure may be cryptic and unrelated to the actual application change.
- Version skew makes it impossible to confidently promote a build through environments —
  the test signal from dev is not fully representative of later environments.
- The current setup has no enforcement mechanism. Version drift accumulates silently.

### Current known state (2026-08-16)

| Controller machine | Ansible core | Python | Jinja2 | Environments controlled |
|--------------------|-------------|--------|--------|------------------------|
| `sodo@pop-os` `/home/sodo` — pipx (upgraded 2026-08-16) | **2.20.4** | 3.12.13 | 3.1.6 | **dev + prod** |
| `sodo@lab.gighive.internal` — pipx (upgraded 2026-08-16) | **2.20.4** | 3.12.3 | 3.1.6 | lab |
| `sodo@staging.gighive.internal` — pipx | **2.20.4** | 3.12.7 | 3.1.6 | staging |

Note: the macOS machine (`/Users/sodo`) is the IDE/workstation only — it does not run
Ansible playbooks against any environment.

pop-os was upgraded on 2026-08-16: old pip-installed ansible-core 2.17.12 (Python 3.10)
replaced with pipx-installed ansible-core==2.20.4 under Python 3.12 via pipx.

All three controllers are now at **2.20.4**. Standardisation complete (2026-08-16).

All controllers are now audited. The version spread is **2.17.12 → 2.20.4** — nearly
three minor versions. `MODULE_STRICT_UTF8_RESPONSE` became `true` by default in Ansible
core 2.19, which is why the binary PATCH body was accepted on dev and rejected on lab/staging.

- **dev + prod** (`pop-os`, 2.17.12) — oldest and most permissive; also on Python 3.10
  which caps ansible-core upgrades at 2.17.x via pip (2.18+ requires Python ≥ 3.11)
- **lab** (2.20.1) — first environment with strict UTF-8 enforcement; where the bug surfaced
- **staging** (2.20.4) — strictest; already at target

Any task that passes on dev/prod may silently fail on lab/staging. The target
standardisation version is **2.20.4**. Reaching it on pop-os requires upgrading Python
to 3.11+ first (Python 3.10 is the blocking constraint, not Ansible itself).

---

## Root cause

Ansible is installed via `pip --user` on pop-os (`/home/sodo/.local/bin/ansible`,
Python 3.10), and via `pipx` on lab and staging (Python 3.12). There is no shared version
pin and no mechanism to enforce parity across machines.

The `installprerequisites` role (`ansible/roles/installprerequisites/`) provisions
controller packages but does not pin Ansible to a specific version, and it is not
guaranteed to have been run on every controller with the same inputs.

---

## Options

### Option A — Pin via `requirements.txt` / `pipx inject` (recommended)

Commit a `controller-requirements.txt` (or `pyproject.toml`) to the repo that pins
`ansible-core`, `jinja2`, and key collections to exact versions. The
`installprerequisites` role installs from this file using `pipx install --editable` or
`pip install -r`.

```
# controller-requirements.txt
ansible-core==2.18.8
jinja2==3.1.6
```

The `installprerequisites` role gains a task:

```yaml
- name: Install pinned Ansible and dependencies on controller
  ansible.builtin.pip:
    requirements: "{{ repo_root }}/controller-requirements.txt"
    virtualenv: "{{ ansible_venv_path }}"
    virtualenv_command: python3 -m venv
  delegate_to: localhost
  become: false
```

**Pros:** Version is code-reviewed, diffed, and identical everywhere.  
**Cons:** Upgrading Ansible requires a deliberate PR; any controller running an older
pip/pipx may need manual bootstrapping the first time.

### Option B — Docker-based controller

Run Ansible from a pinned Docker image (`cytopia/ansible`, `willhallonline/ansible`, or
a custom image in the repo). All environments pull the same image tag.

```bash
docker run --rm -v $(pwd):/repo ghcr.io/gighive/ansible-controller:2.18.8 \
  ansible-playbook -i inventories/inventory_lab.yml playbooks/site.yml
```

**Pros:** Hermetically sealed — OS, Python, pip packages all pinned.  
**Cons:** Requires Docker on all controller machines; adds image build/publish overhead;
`delegate_to: localhost` tasks run inside the container, not on the host (may affect
SSH agent forwarding, file paths).

### Option C — Ansible version check at playbook start (immediate mitigation)

Add a pre-flight assertion to `site.yml` that fails fast if the Ansible core version is
outside the tested range. Does not fix the skew but prevents silent divergence.

```yaml
- name: Pre-flight version check
  hosts: localhost
  gather_facts: false
  tasks:
    - name: Assert Ansible core version is within tested range
      ansible.builtin.assert:
        that:
          - ansible_version.major == 2
          - ansible_version.minor == 18
        fail_msg: >
          Ansible core {{ ansible_version.full }} is not the tested version (2.18.x).
          Run controller-requirements.txt installation to align versions.
```

**Pros:** Zero-effort immediate guard; fails loudly before any damage is done.  
**Cons:** Does not fix the skew, only detects it. Must be updated when the tested version
is intentionally upgraded.

---

## Recommended approach

1. **Immediate:** Implement Option C (version assertion) in `site.yml` to catch further
   skew during the Phase 3 rollout.
2. **Short-term:** Implement Option A — commit `controller-requirements.txt` and update
   `installprerequisites` to install from it. Run on all controller machines.
3. **Long-term:** Evaluate Option B as part of any future CI/CD automation effort.

---

## Files to change

| File | Change |
|---|---|
| `controller-requirements.txt` (new) | Pin `ansible-core`, `jinja2`, key collections |
| `ansible/roles/installprerequisites/tasks/main.yml` | Install from requirements file |
| `ansible/playbooks/site.yml` | Add pre-flight version assertion play |
| This doc | Update version table after auditing all controllers |

---

## Ongoing drift prevention

Standardisation is a point-in-time fix. Without active checks, version skew silently
returns whenever a controller machine is rebuilt, upgraded, or a pipx dependency resolves
to a newer version. The following process prevents that.

### Verification checklist (run after any controller maintenance or before a production deploy)

Run the drift-check script from pop-os — it covers all three controllers in one pass:

```bash
~/bin/check-ansible-versions.sh
```

To inspect a single controller manually:

```bash
# Run on the controller in question
ansible --version
python3 --version
pipx runpip ansible show jinja2 | grep ^Version
pipx runpip ansible show pip | grep ^Version
ansible-galaxy collection list community.docker community.general
terraform version
az version --output json | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('azure-cli'))"
VBoxManage --version
pipx --version
```

Expected baseline (update this table whenever versions are intentionally bumped):

| Package | Pinned version | Notes |
|---|---|---|
| ansible-core | 2.20.4 | via pipx |
| python3 | 3.12.x | pipx venv interpreter; patch may vary |
| jinja2 | 3.1.6 | pip package inside pipx ansible venv |
| pipx | — | no hard pin; keep current |
| jinja2-cli | — | installed in `~/.ansible-azure` venv |
| community.docker | ≥ 3.13.3 | ansible-galaxy collection |
| community.general | — | ansible-galaxy collection; no hard pin |
| terraform | — | optional; no hard pin |
| azure-cli | — | optional; no hard pin |
| virtualbox | — | optional; no hard pin |

### Automated drift check (cron / scheduled task)

The script lives on **pop-os only** at `~/bin/check-ansible-versions.sh`. It collects
versions locally then SSHs into `sodo@lab.gighive.internal` and
`sodo@staging.gighive.internal` (the two remote controllers) to collect the same data,
then prints a side-by-side comparison table and exits non-zero if any row shows drift.

`N/A` means the tool is not installed on that controller. Drift is only flagged when at
least one non-N/A value differs from the others — three identical `N/A` entries is not
drift.

```bash
#!/usr/bin/env bash
# ~/bin/check-ansible-versions.sh
# Run from pop-os. SSHes into lab and staging controllers and compares versions.
# Exits 1 if any package version differs across the three controllers.
set -uo pipefail

CONTROLLERS=("local" "sodo@lab.gighive.internal" "sodo@staging.gighive.internal")
LABELS=("pop-os (dev/prod)" "lab" "staging")

ANSIBLE_VENV_PATH="${HOME}/.ansible-azure"

# ---------------------------------------------------------------------------
# collect_versions HOST
#   Runs a probe script locally or via SSH and prints one key=value per line.
# ---------------------------------------------------------------------------
collect_versions() {
  local host="$1"
  local probe
  probe=$(cat <<'PROBE'
#!/usr/bin/env bash
VENV="${HOME}/.ansible-azure"
GALAXY="ansible-galaxy"
[[ -x "${VENV}/bin/ansible-galaxy" ]] && GALAXY="${VENV}/bin/ansible-galaxy"
COLLECTIONS_PATH="${HOME}/.ansible/collections"

# ansible-core
core=$(ansible --version 2>/dev/null | awk 'NR==1{print $NF}' | tr -d '[]')
echo "ansible_core=${core:-N/A}"

# python3 (interpreter used by the pipx venv, not the system one)
py=$(pipx runpip ansible show pip 2>/dev/null \
     | awk '/^Location:/{print $2}' \
     | xargs -I{} dirname {} \
     | xargs -I{} dirname {} \
     | xargs -I{} sh -c '"$1/bin/python3" --version 2>&1' _ {} \
     | awk '{print $2}')
[[ -z "$py" ]] && py=$(python3 --version 2>/dev/null | awk '{print $2}')
echo "python3=${py:-N/A}"

# jinja2 (pip package inside pipx ansible venv)
jinja2=$(pipx runpip ansible show jinja2 2>/dev/null | awk '/^Version:/{print $2}')
echo "jinja2=${jinja2:-N/A}"

# pipx
pipx_ver=$(pipx --version 2>/dev/null | awk '{print $1}')
echo "pipx=${pipx_ver:-N/A}"

# jinja2-cli (installed in ~/.ansible-azure venv)
j2cli=$("${VENV}/bin/jinja2" --version 2>/dev/null \
        | sed 's/^jinja2-cli //' | sed 's/,.*//' | tr -d ' ')
echo "jinja2_cli=${j2cli:-N/A}"

# community.docker
cd_ver=$("$GALAXY" collection list community.docker \
         --collections-path "${COLLECTIONS_PATH}" 2>/dev/null \
         | awk '/^community\.docker/{print $2}' | head -1)
echo "community_docker=${cd_ver:-N/A}"

# community.general
cg_ver=$("$GALAXY" collection list community.general \
         --collections-path "${COLLECTIONS_PATH}" 2>/dev/null \
         | awk '/^community\.general/{print $2}' | head -1)
echo "community_general=${cg_ver:-N/A}"

# terraform
tf=$(terraform version -json 2>/dev/null | python3 -c \
     "import sys,json; d=json.load(sys.stdin); print(d.get('terraform_version','N/A'))" \
     2>/dev/null)
echo "terraform=${tf:-N/A}"

# azure-cli
az_ver=$(az version --output json 2>/dev/null | python3 -c \
         "import sys,json; d=json.load(sys.stdin); print(d.get('azure-cli','N/A'))" \
         2>/dev/null)
echo "azure_cli=${az_ver:-N/A}"

# VirtualBox
vbox=$(VBoxManage --version 2>/dev/null | tr -d '[:space:]')
echo "virtualbox=${vbox:-N/A}"
PROBE
)

  if [[ "$host" == "local" ]]; then
    bash <(echo "$probe")
  else
    ssh -o BatchMode=yes -o ConnectTimeout=10 "$host" bash <<< "$probe"
  fi
}

# ---------------------------------------------------------------------------
# Collect from all three controllers
# ---------------------------------------------------------------------------
declare -A DATA
KEYS=()

for i in "${!CONTROLLERS[@]}"; do
  host="${CONTROLLERS[$i]}"
  label="${LABELS[$i]}"
  echo "Collecting from: ${label} ..." >&2
  while IFS='=' read -r key val; do
    [[ -z "$key" ]] && continue
    DATA["${key}:${i}"]="$val"
    if [[ $i -eq 0 ]]; then KEYS+=("$key"); fi
  done < <(collect_versions "$host")
done

# ---------------------------------------------------------------------------
# Print comparison table and detect drift
# ---------------------------------------------------------------------------
COL_W=22
drift=0

printf "\n%-22s %-22s %-22s %-22s %s\n" \
  "Package" "${LABELS[0]}" "${LABELS[1]}" "${LABELS[2]}" "Status"
printf '%s\n' "$(printf '%.0s-' {1..105})"

for key in "${KEYS[@]}"; do
  v0="${DATA["${key}:0"]:-N/A}"
  v1="${DATA["${key}:1"]:-N/A}"
  v2="${DATA["${key}:2"]:-N/A}"

  # Drift: at least one non-N/A value differs from the others
  non_na=()
  for v in "$v0" "$v1" "$v2"; do [[ "$v" != "N/A" ]] && non_na+=("$v"); done
  unique=$(printf '%s\n' "${non_na[@]}" | sort -u | wc -l)

  if [[ ${#non_na[@]} -gt 1 && "$unique" -gt 1 ]]; then
    status="DRIFT"
    drift=1
  else
    status="ok"
  fi

  printf "%-22s %-22s %-22s %-22s %s\n" "$key" "$v0" "$v1" "$v2" "$status"
done

printf '%s\n' "$(printf '%.0s-' {1..105})"

if (( drift )); then
  echo ""
  echo "DRIFT DETECTED — align versions before promoting a build."
  echo "Run: pipx upgrade ansible   or reinstall from controller-requirements.txt"
  exit 1
else
  echo ""
  echo "All versions consistent across controllers."
  exit 0
fi
```

Schedule on pop-os via cron (weekly, Monday 08:00):

```
0 8 * * 1 /home/sodo/bin/check-ansible-versions.sh >> /home/sodo/logs/ansible-version-drift.log 2>&1
```

### Intentional version bumps

When upgrading Ansible or its dependencies:

1. Update `controller-requirements.txt` (tracked in repo) with the new pinned versions.
2. Run the upgrade on all controllers in sequence: dev → lab → staging → prod.
3. Update the version table in the **Current known state** section of this doc.
4. Run `check-ansible-versions.sh` on pop-os to confirm all three controllers agree.
5. Commit the `controller-requirements.txt` change with the new version as the commit message subject.

### Playbook-level guard (Option C — already recommended above)

The pre-flight `assert` in `site.yml` is the last line of defence and should remain in
place permanently. Update the `ansible_version.minor` assertion whenever the target
version changes.

---

## Related documentation

- `docs/refactor_ansible_controller_prereqs.md` — controller Node.js prerequisite problem
  (same root cause: controller state diverges across environments)
- `docs/refactor_storage_media_rest_endpoint_implementation.md` — Phase 3 build issues,
  "PATCH binary body — Ansible uri module rejects non-UTF-8" section
