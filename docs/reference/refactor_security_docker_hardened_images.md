# Docker Hardened Images (DHI) Assessment

**Date:** 2026-04-22  
**Scope:** `ansible/roles/docker/templates/docker-compose.yml.j2`, `Dockerfile.j2`, `entrypoint.sh.j2`, and related install scripts

---

## What Are Docker Hardened Images?

Docker Hardened Images (DHI) launched in May 2025 and were made **free and open source (Apache 2.0)** on December 17, 2025. No subscription required, no usage restrictions, no vendor lock-in.

DHI addresses the **image supply chain layer**:
- CVE reduction of up to 95% vs community images, with SLA-backed patching
- Distroless/minimal runtime — no package manager, no shell, no debug tools in the final layer
- Complete SBOM (Software Bill of Materials)
- SLSA Build Level 3 provenance
- Cryptographic proof of authenticity (image signing)
- Built on **Debian and Alpine** base distributions

DHI does **not** automatically add container runtime hardening controls (capabilities, seccomp, read-only filesystems, etc.) — those remain the operator's responsibility in `docker-compose.yml`.

---

## Complete Image Inventory

| Image | Where Used | Purpose |
|---|---|---|
| `ubuntu:{{ ubuntu_version }}` | `Dockerfile.j2:1` | Apache/PHP container build base |
| `tusproject/tusd:latest` | `docker-compose.yml.j2` | TUS upload server service |
| `mysql:8.4` | `docker-compose.yml.j2` | MySQL database service |
| `alpine` | `install.sh.j2` | Throwaway: `chown`/`chmod` on `restorelogs` dir |
| `httpd:2.4` | `install.sh.j2`, `install.ps1.j2`, `rotate_basic_auth.sh` | Throwaway: generates `gighive.htpasswd` via `htpasswd` binary |

---

## DHI Replaceability Per Image

### `ubuntu:{{ ubuntu_version }}` → `docker/ubuntu` or `docker/debian`
**Possible — but requires entrypoint rework.**

- DHI is built on Debian and Alpine, not Ubuntu. The closest swap would be `docker/debian`, since the package names are identical.
- The `FROM` line in `Dockerfile.j2` is a one-line change.
- **Structural blocker:** `entrypoint.sh.j2` runs as root throughout startup (`chown`, `mkdir`, `service cron start`, `a2enconf`, etc.). DHI images default to non-root. The entrypoint would need to be redesigned to separate root-required setup (move to Dockerfile build time) from runtime operations before DHI's non-root default can be honored.

### `mysql:8.4` → `docker/mysql:8.4`
**Best candidate — most straightforward swap.**

- Docker publishes hardened MySQL images.
- The `docker-entrypoint-initdb.d/` convention and `conf.d` mounting are preserved in DHI MySQL.
- Needs a quick verify that the `env_file` and init-script behavior is identical.

### `tusproject/tusd:latest`
**Not replaceable via DHI — no DHI equivalent exists for third-party images.**

- Note: `tusd` already runs as non-root (`user: "33:33"` in compose), which is the main runtime benefit DHI would otherwise add.
- If hardening tusd further matters, the only path is building a custom image from the tusd Go binary on a `docker/debian` or `docker/alpine` base.

### `alpine` → `docker/alpine`
**Easy win — low-risk swap.**

- Used as a throwaway `docker run --rm` container to set ownership on `restorelogs`.
- DHI Alpine exists; the one-liner `chown 33:33 /target && chmod 750 /target` carries over unchanged.

### `httpd:2.4` → `docker/httpd:2.4`
**Easy win — low-risk swap.**

- Used as a throwaway `docker run --rm` container solely to run the `htpasswd` binary.
- DHI `httpd` likely exists; behavior for this use case (running `htpasswd`) should be identical.
- Used in three places: `install.sh.j2`, `install.ps1.j2`, `rotate_basic_auth.sh`.

---

## Current Security Posture vs DHI — Per Container

### Apache/PHP Container

| Control | Current State | DHI Impact |
|---|---|---|
| Base image CVEs | `ubuntu` community — unaudited CVE baseline | Up to 95% CVE reduction, SLA-backed patching |
| Image provenance | None | SLSA Build Level 3, cryptographic signing, public SBOM |
| Runtime user | **Runs as root** — no `user:` in compose | DHI defaults non-root; **blocked by entrypoint design** |
| Installed packages | Full Ubuntu stack including `vim`, `net-tools`, `build-essential`, `autoconf` left in image | Distroless runtime — minimal packages in final layer |
| `cap_drop` / `cap_add` | **Not set** | Not added by DHI — operator responsibility |
| `security_opt: no-new-privileges` | **Not set** | Not added by DHI — operator responsibility |
| `read_only` filesystem | **Not set** | Not added by DHI — operator responsibility |
| Application WAF | ModSecurity + CRS rules mounted in ✅ | No change |
| TLS | Self-signed or origin cert on 443 ✅ | No change |
| Bind mounts | Most config mounts are `:ro` ✅ | No change |

### MySQL Container

| Control | Current State | DHI Impact |
|---|---|---|
| Base image CVEs | `mysql:8.4` community — unaudited CVE baseline | Patched image, SBOM, provenance |
| Runtime user | MySQL official image runs as `mysql` internally (non-root) ✅ | Maintained; cryptographic attestation added |
| `cap_drop` / `security_opt` | **Not set** | Not added by DHI — operator responsibility |
| Port exposure | `3306:3306` exposed to host (`0.0.0.0`) | No change — DHI doesn't affect port bindings |

### tusd Container

| Control | Current State | DHI Impact |
|---|---|---|
| Base image CVEs | `tusproject/tusd:latest` — no DHI equivalent | No improvement possible via DHI |
| Runtime user | `user: "33:33"` (www-data) — already non-root ✅ | N/A |
| `cap_drop` / `security_opt` | **Not set** | Not applicable (no DHI for tusd) |

### Throwaway Containers

| Image | Current State | DHI Impact |
|---|---|---|
| `alpine` | Community image, runs as root, `--rm` ephemeral | `docker/alpine` — minimal CVEs, SBOM; one-liner carries over |
| `httpd:2.4` | Community image, runs as root, `--rm` ephemeral | `docker/httpd:2.4` — same benefit; equally low risk |

---

## Runtime Hardening Gaps (Independent of DHI)

These are missing from the current compose files and are unrelated to DHI — they apply regardless of which base images are used:

| Control | Applies To | Recommendation |
|---|---|---|
| `cap_drop: [ALL]` | All services | Drop all Linux capabilities by default; add back only what's needed |
| `security_opt: ["no-new-privileges:true"]` | All services | Prevent privilege escalation inside container |
| `pids_limit` | All services | Limit process count to prevent fork bombs |
| Memory / CPU limits | All services | Add `mem_limit` and `cpus` for resource isolation |
| MySQL port binding | MySQL | Change `3306:3306` to `127.0.0.1:3306:3306` to avoid exposing DB to the network |

---

## Summary and Recommended Order of Work

| Priority | Action | Effort |
|---|---|---|
| 1 | Swap `mysql:8.4` → `docker/mysql:8.4` | Low |
| 2 | Swap `alpine` → `docker/alpine` in `install.sh.j2` | Low |
| 3 | Swap `httpd:2.4` → `docker/httpd:2.4` in install scripts and `rotate_basic_auth.sh` | Low |
| 4 | Add runtime hardening (`cap_drop`, `no-new-privileges`, resource limits, MySQL port binding) to `docker-compose.yml.j2` | Medium |
| 5 | Redesign `entrypoint.sh.j2` to separate root setup (move to Dockerfile build time) from runtime, enabling non-root container operation | High |
| 6 | Swap `ubuntu:{{ ubuntu_version }}` → `docker/debian` once entrypoint is redesigned | Low (after step 5) |
| 7 | Evaluate custom hardened tusd image if tusd supply chain risk becomes a concern | Low urgency |

The three throwaway image swaps (steps 1–3) are entirely self-contained and can be done independently without touching the running stack.
