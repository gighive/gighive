# Refactor: Per-Container Network Stats on Admin System Page

## Current State (as of Jul 2026)

`/admin/admin_system.php` displays two OS-level sub-sections:

- **OS / apacheWebServer** — network rx/tx for the apache container's eth0, read from `/proc/net/dev` inside the container (two samples 1 second apart)
- **docker host** — disk free (via `disk_free_space()` on bind-mounted media paths) and mem free (via `/proc/meminfo`), both of which reflect host-level resources

The three other containers (`apacheWebServer_tusd`, `mysqlServer`, `ai-worker`) are not represented because PHP running inside `apacheWebServer` cannot access their network namespaces.

## Problem

Each Docker container has its own isolated network namespace. `/proc/net/dev` inside `apacheWebServer` only shows that container's virtual NIC. The other containers' interfaces are invisible from PHP.

## Options

### Option A — Host cron writes a docker stats file (recommended)

A cron job or systemd timer on the Docker VM runs:

```bash
docker stats --no-stream --format '{{json .}}' > /path/to/shared/docker_stats.json
```

That directory is bind-mounted read-only into the `apacheWebServer` container. PHP reads and parses the JSON.

**Implementation touchpoints:**
- Ansible `cron` task (or systemd timer unit) on the VM host
- One bind-mount line added to `docker-compose.yml.j2` for the apache service
- PHP parsing logic in `admin_system.php`

**Pros:** No security exposure, fits existing Ansible pattern, stats for all containers in one file  
**Cons:** Slightly stale (up to the cron interval, e.g. 5–10 s); cron must be kept in sync with container names

---

### Option B — Mount Docker socket into apache container

Add `/var/run/docker.sock` as a bind mount into `apacheWebServer`. PHP calls the Docker Engine API directly over the socket (or via `shell_exec('docker stats ...')`).

**Implementation touchpoints:**
- One bind-mount line in `docker-compose.yml.j2`
- PHP HTTP-over-unix-socket call or `shell_exec`

**Pros:** Real-time stats, no extra infrastructure  
**Cons:** Mounting the Docker socket grants effective root-on-host to any process in the container — a significant security hole; not appropriate for a shared or internet-facing deployment

---

### Option C — cAdvisor or Prometheus node exporter

Run cAdvisor as an additional Docker container. It exposes per-container CPU, memory, and network metrics via an HTTP JSON endpoint. PHP scrapes that endpoint.

**Pros:** Purpose-built, rich and accurate metrics, supports future dashboarding  
**Cons:** Additional container to deploy and maintain; likely overkill for a simple admin status page

---

## Decision

Deferred. Current clarity improvements (apache container network + docker host disk/mem clearly separated) are sufficient for now. Revisit if per-container network visibility becomes operationally important.
