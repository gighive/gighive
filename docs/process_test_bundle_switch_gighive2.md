# Process: Test Bundle Switch for `gighive2`

## Assumptions

- A new bundle has been created.
- A SHA file has been generated for that bundle.
- The bundle and SHA file have been copied to the staging server with:

```bash
scp gighive-one-shot-bundle.tgz* ubuntu@stagingvm.gighive.internal:/home/ubuntu/gighive/ansible/roles/docker/files/apache/downloads
```

## How to Run

### Recommended sanity checks

Repeat `status`:

```bash
ansible-playbook -K ansible/playbooks/switch_runtime.yml \
  -i ansible/inventories/inventory_gighive2.yml \
  -e switch_target_mode=status
```

Repeat the current target mode twice.

If you are currently on bundle:

```bash
ansible-playbook -K ansible/playbooks/switch_runtime.yml \
  -i ansible/inventories/inventory_gighive2.yml \
  -e switch_target_mode=gighive_bundle
```

Run it twice and confirm the second run does less work.

If you are currently on VM:

```bash
ansible-playbook -K ansible/playbooks/switch_runtime.yml \
  -i ansible/inventories/inventory_gighive2.yml \
  -e switch_target_mode=gighive2_vm
```

Run it twice and confirm the second run reports fewer changes.

## Scope

This document captures the phased implementation plan for an Ansible-driven switch between:

- the `gighive2` VirtualBox VM workflow
- the `gighive-one-shot-bundle` workflow

## Constraints

- `switch_runtime.yml` will be run only from the Pop!_OS development server.
- The VM-side source of truth is limited to:
  - `ansible/inventories/inventory_gighive2.yml`
  - `ansible/inventories/group_vars/gighive2/gighive2.yml`
- The bundle under test will be downloaded from `staging.gighive.app`.
- The downloaded bundle may be extracted and expanded under `/home/sodo`.
- The repo source directory `gighive-one-shot-bundle/` should not be edited, overwritten, or used as the runtime install target.

## High Level Phases

**Phase 1: Scaffold and Observability**
- Define `switch_runtime.yml` with modes `status`, `gighive2_vm`, `gighive_bundle`
- Derive VBox user context and set `HOME`/`VBOX_USER_HOME`
- Run preflight checks for `VBoxManage`, Docker, paths, and variables
- Implement `status` mode — VM state, processes, bundle containers, reachability

**Phase 2: Bundle Readiness**
- Download `.tgz` + `.sha256` from staging, verify checksum, extract under `/home/sodo`
- Detect bootstrap state; if uninitialized, stop and print manual `install.sh` instructions
- After bootstrap, validate generated config files and running containers
- Provide `docker compose up` / `docker compose down` as repeat runtime controls with improved rerun behavior

**Phase 3: Bidirectional Switch Execution**
- VM→bundle: stop VM, wait for clean poweroff/unlock, verify ports free, compose up
- Bundle→VM: compose down, verify containers stopped, verify ports free, `VBoxManage` start
- Wait for stable state and validate reachability/app health after each direction, including longer post-start VM readiness waits

**Phase 4: Hardening**
- Add fail-fast guardrails before any state-changing action
- Standardize operator output: mode requested → initial state → actions taken → final state → health result

**Implementation Notes**
- `gighive_bundle` can now reuse an already-running healthy bundle instead of forcing `docker compose up`
- failure diagnostics now include the failed task, failure detail, and initial runtime snapshot
- repeated runs are faster because artifact download/extraction and compose operations are more idempotent
- bundle artifact downloads are no longer forced on every `gighive_bundle` run

---

## Phase 1: Scaffold and Observability

### Goal

Establish the playbook entry point, variable contract, VirtualBox execution context, preflight checks, and a read-only `status` mode.

### What This Phase Delivers

- new playbook entry point `ansible/playbooks/switch_runtime.yml`
- three supported modes: `status`, `gighive2_vm`, `gighive_bundle`
- VBox execution context derived using the same user/home pattern as `ansible/roles/vbox_vm_autostart`
- preflight assertions for all required tools and paths
- `status` mode that reports current VM and bundle state without changing anything

### Required Variables

- `switch_target_mode`
- `switch_vm_name` derived from `vm_name`
- `switch_vm_ip` derived from the `gighive2` inventory host
- `switch_bundle_download_base_url` set to `https://staging.gighive.app/downloads`
- `switch_bundle_home` set to `/home/sodo`
- `switch_bundle_download_dir` under `/home/sodo`
- `switch_bundle_extract_dir` under `/home/sodo`

### VirtualBox Context

Derive and consistently set:

- the Pop!_OS user that owns the VirtualBox context
- that user's home directory
- `VBOX_USER_HOME`
- `XDG_RUNTIME_DIR` if needed for reliable `VBoxManage` behavior

### Preflight Checks

Fail early if any of the following are missing or invalid:

- `vm_name`
- `gighive2` inventory host/IP
- `/home/sodo`
- `VBoxManage`
- Docker and Docker Compose
- bundle download base URL

### `status` Mode Output

- `VBoxManage showvminfo` state for `gighive2`
- `VBoxManage list runningvms`
- VirtualBox host processes (`VBoxHeadless`, `VirtualBoxVM`)
- VM SSH reachability
- VM app health
- whether the extracted bundle runtime directory exists under `/home/sodo`
- `docker compose ps` summary for the extracted bundle runtime

### Suggested Task Layout

- `tasks/preflight.yml`
- `tasks/derive_vbox_context.yml`
- `tasks/check_vm_state.yml`
- `tasks/check_vm_processes.yml`
- `tasks/check_vm_reachability.yml`
- `tasks/check_bundle_containers.yml`
- `tasks/status.yml`

### Failure Conditions to Detect Clearly

- `VBoxManage` not available or not visible under the derived user context
- VM registered in inventory but not found by VirtualBox
- VM locked or in an inconsistent session state
- stale VBox host processes conflicting with reported VM state
- VM running but SSH unreachable
- bundle runtime directory missing

### Deliverable

A `switch_runtime.yml` with fixed scope, stable variable contract, reliable preflight, and a trustworthy `status` mode. No runtime changes occur in this phase.

---

## Phase 2: Bundle Readiness

### Goal

Handle everything on the bundle side: download and verify the artifact from staging, extract it under `/home/sodo`, detect whether first-time bootstrap is needed, and provide compose-based start/stop control after bootstrap.

### What This Phase Delivers

- download `gighive-one-shot-bundle.tgz` and `.sha256` from `staging.gighive.app`
- verify checksum before proceeding
- extract into a dedicated runtime directory under `/home/sodo`
- avoid unnecessary re-download and re-extraction on repeated successful runs
- confirm extracted bundle is structurally valid
- detect whether `install.sh` bootstrap has already been completed
- if bootstrap is required, stop and print exact manual instructions
- after bootstrap, validate generated config files
- `docker compose up -d --build` and `docker compose down` as the runtime controls

### Bundle Variables

- `switch_bundle_tgz_name`
- `switch_bundle_sha_name`
- `switch_bundle_download_dir`
- `switch_bundle_extract_dir`

### Bootstrap Detection

Check for presence of:

- `apache/externalConfigs/.env`
- `mysql/externalConfigs/.env.mysql`
- `apache/externalConfigs/gighive.htpasswd`

If these are missing, Ansible stops and prints:

- the extracted runtime path
- the exact manual command: `cd /home/sodo/<runtime-dir> && ./install.sh`
- a reminder to follow the quickstart prompts

Once completed manually, subsequent runs use Compose directly.

### `install.sh` Constraint

`install.sh` is interactive by design. It rejects `--non-interactive` and CLI password args. Manual first bootstrap is the recommended approach. `expect`-based automation can be added later if needed.

### Post-Bootstrap Validation

- `apache/externalConfigs/.env` exists
- `mysql/externalConfigs/.env.mysql` exists
- `apache/externalConfigs/gighive.htpasswd` exists
- `docker compose ps` shows expected services
- optionally: `docker compose logs -n 100 mysqlServer` and `apacheWebServer`

### Idempotency Notes

- repeated `gighive_bundle` runs no longer forcibly re-download the bundle artifacts when local files are already present
- repeated `gighive_bundle` runs no longer remove and re-extract the runtime unless the bundle files changed or extracted marker files are missing
- `docker compose up` / `docker compose down` change reporting is now tied more closely to whether the runtime state actually changed
- if the upstream staging bundle changes without local filenames changing, a manual refresh strategy may still be needed to force retrieval of the newer artifact

### Suggested Task Layout

- `tasks/prepare_bundle_dirs.yml`
- `tasks/download_bundle.yml`
- `tasks/verify_bundle_checksum.yml`
- `tasks/extract_bundle.yml`
- `tasks/check_bundle_initialized.yml`
- `tasks/emit_bootstrap_instructions.yml`
- `tasks/validate_bundle_post_install.yml`
- `tasks/bundle_compose_up.yml`
- `tasks/bundle_compose_down.yml`
- `tasks/check_bundle_health.yml`

### Failure Conditions to Detect Clearly

- staging download URL unavailable
- TLS or download failure
- checksum mismatch
- extracted bundle missing `install.sh` or `docker-compose.yml`
- extracted path points at the repo source directory
- bootstrap files missing after `install.sh`
- containers not created after bootstrap
- `docker compose up` fails
- HTTPS endpoint unreachable after startup
- `docker compose down` leaves containers or networks active

---

## Phase 3: Bidirectional Switch Execution

### Goal

Implement the actual VM↔bundle flip in both directions, reusing tasks from Phases 1 and 2.

### VM → Bundle

- inspect `gighive2` VM state
- stop or power off the VM using the derived VBox user context
- wait until `VBoxManage showvminfo` reports powered off and unlocked
- verify relevant host ports are free
- run `docker compose up -d --build` in the extracted runtime directory when the bundle is not already running and healthy
- validate container state and app reachability

### Bundle → VM

- inspect bundle container state
- run `docker compose down`
- verify containers are no longer running
- verify relevant host ports are free
- start `gighive2` using `VBoxManage` under the derived user context
- wait until VirtualBox reports the VM running and stable
- validate SSH reachability with a longer post-start wait
- validate app reachability with a longer post-start retry window

### Suggested Task Layout

Reuses Phase 1 and Phase 2 tasks, plus:

- `tasks/stop_gighive2_vm.yml`
- `tasks/wait_for_vm_poweroff.yml`
- `tasks/check_host_ports.yml`
- `tasks/start_gighive2_vm.yml`
- `tasks/wait_for_vm_running.yml`
- `tasks/check_vm_app_health.yml`

### Failure Conditions to Detect Clearly

- VM does not stop cleanly or VBox session remains locked
- host ports remain occupied after VM shutdown
- bundle fails to start or endpoint is unhealthy
- bundle is already running but unhealthy when `gighive_bundle` is requested
- bundle containers remain active after `docker compose down`
- VM fails to start
- VM starts but SSH or app is unreachable

### Deliverable

Both switch directions are fully functional. The playbook can flip from either runtime to the other with validation at each step.

---

## Phase 4: Hardening

### Goal

Make the switch workflow safe, repeatable, and self-diagnosing for day-to-day use.

### What This Phase Adds

- fail-fast guardrails before any state-changing action:
  - refuse to switch if prerequisites are missing
  - refuse to start the bundle if ports are still occupied
  - refuse to start the VM if bundle teardown is incomplete
  - refuse to proceed on checksum mismatch or VBox lock state
- additional initial-state inspection before switching:
  - capture initial VM state and reachability
  - capture initial bundle state and health
  - inspect required host port occupancy
- structured failure diagnostics:
  - print failed task name and failure detail
  - print initial runtime snapshot alongside the failure
- consistent operator output for every mode:
  - requested mode
  - previous detected state
  - actions taken
  - final detected state
  - final health result
- explicit final readiness/result facts:
  - `vm_ready`
  - `bundle_ready`
  - final `success` / `incomplete` result
- concise output on success, more detailed output on failure
- final command shape for `status`, `gighive_bundle`, and `gighive2_vm`

### Suggested Task Layout

- `tasks/check_switch_guardrails.yml`
- `tasks/print_state_summary.yml`
- `tasks/print_failure_diagnostics.yml`

### Deliverable

The switch workflow is safe to run repeatedly, self-diagnosing when something goes wrong, and ready for day-to-day use as the standard VM/bundle flip mechanism.
