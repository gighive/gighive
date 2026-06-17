---
description: How install.sh.j2 becomes install.sh and what it does at user install time
---

# install.sh: Source, Assembly, and Runtime Behavior

## Overview

`install.sh` is the user-facing entry point for the quickstart (one-shot bundle) installation path.
It is the **only thing a bundle user needs to run** to bring up the GigHive stack.

The source is a Jinja2 template; the rendered static file is what ships in the bundle.

## Source and Assembly

| Artifact | Path |
|---|---|
| Jinja2 template (source) | `ansible/roles/docker/templates/install.sh.j2` |
| Rendered static file (bundle) | `install.sh` (at bundle root) |

The `one_shot_bundle` Ansible role renders `install.sh.j2` into `install.sh` using `ansible.builtin.template`
during the full-build Ansible playbook run (`output_bundle.yml` lines 91–135).
The rendered file is set to mode `0755` so it is directly executable.

Jinja2 expressions in the template are evaluated at bundle-build time using the active Ansible inventory
and group_vars. Once rendered, `install.sh` is a **static bash script** — no further Ansible involvement
occurs when the bundle user runs it.

## Install-Time Variables Baked In at Render Time

The following values are resolved from group_vars at bundle-build time and baked as literal strings
into the rendered `install.sh`:

| Shell variable | Source template expression | Typical rendered value |
|---|---|---|
| `_tracking_enabled` | `gighive_enable_installation_tracking` | `true` |
| `_tracking_endpoint` | `gighive_installation_tracking_endpoint` | `https://telemetry.gighive.app` |
| `_tracking_timeout` | `gighive_installation_tracking_timeout_seconds` | `3` |
| `_app_flavor` | `app_flavor` | `gighive` |

## Install-Time Variables Set at Runtime (Not Baked In)

The following are pure bash assignments set when the user runs `install.sh` — they are not Jinja2
expressions and are not influenced by group_vars:

| Shell variable | Value | Rationale |
|---|---|---|
| `_install_channel` | `"quickstart"` (hard-coded) | `install.sh` is exclusively the quickstart entry point |
| `_install_method` | `"docker"` (hard-coded) | Bundle users always install via Docker Compose |
| `_install_id` | read from `/proc/sys/kernel/random/uuid` | unique per install run |
| `_app_version` | read from `VERSION` file in bundle root | set at bundle-build time by `main.yml` |

## What install.sh Does

1. **Validates prerequisites** — checks for `docker` and `docker compose` / `docker-compose`
2. **Prompts for required inputs** — `SITE_URL`, `AUDIO_DIR`, `VIDEO_DIR`, all passwords
3. **Generates BasicAuth htpasswd** — runs `htpasswd` via a temporary `httpd:2.4` Docker container
4. **Patches the pre-rendered `.env`** — uses `_patch_env_key` to overwrite specific keys in
   `apache/externalConfigs/.env` with values that are only known at user install time:
   - `SITE_URL`
   - `MYSQL_PASSWORD`
   - `MYSQL_ROOT_PASSWORD`
5. **Writes `mysql/externalConfigs/.env.mysql`** — from scratch using prompted values
6. **Sends `install_attempt` telemetry** (if enabled)
7. **Runs `docker compose up -d --build`**
8. **Sends `install_success` telemetry** (if enabled)

## The _patch_env_key Mechanism

`_patch_env_key` is a bash function defined in `install.sh.j2` that does a line-by-line rewrite
of the target `.env` file, replacing the value of a specific `KEY=value` line while preserving
all other lines verbatim:

```bash
_patch_env_key "KEY" "value" "$FILE"
```

The `.env` file is pre-rendered (by Ansible from `.env.j2`) and shipped inside the bundle.
`_patch_env_key` is used to inject install-time values that Ansible cannot know ahead of time
(passwords, site URL).

## Known Gap: GIGHIVE_INSTALL_CHANNEL in the Pre-Rendered .env

The pre-rendered `apache/externalConfigs/.env` shipped in the bundle contains:

```
GIGHIVE_INSTALL_CHANNEL=full
```

This is because `.env.j2` renders `gighive_install_channel` from group_vars, and all current
group_vars inventories set `gighive_install_channel: "full"` (they describe full-build deployments).

`install.sh` does **not** currently patch `GIGHIVE_INSTALL_CHANNEL` to `quickstart`, so the
runtime PHP app sees `GIGHIVE_INSTALL_CHANNEL=full` even on bundle installs.

See `docs/feature_section_d_install_channel_toggle.md` for the planned fix.

## MySQL Volume Lifecycle and Schema Changes

### How the volume is created

`docker compose up` creates the named volume `<project>_mysql_data` the first time it runs if the
volume does not already exist. The project name defaults to the directory containing
`docker-compose.yml` (e.g. `gighive-one-shot-bundle`), so the full volume name is typically
`gighive-one-shot-bundle_mysql_data`.

MySQL's `docker-entrypoint-initdb.d/` mechanism — which runs `create_music_db.sql` and
`load_and_transform.sql` — only fires when the data directory is **empty** (i.e. on first
initialization of a new volume). On all subsequent starts, MySQL skips those scripts entirely.

### Consequence for schema changes

If a volume already exists from a prior install run, `docker compose up -d --build` leaves the
MySQL container running or restarts it against the existing volume. Any tables added to
`create_music_db.sql` after the volume was first created will be absent from the running database,
causing errors like:

```
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'music_db.catalog_scans' doesn't exist
```

### Fix: drop the volume before reinstalling

To force MySQL to reinitialize from the current `create_music_db.sql`, the named volume must be
removed. Run from the bundle directory:

```bash
docker compose down --volumes --remove-orphans
```

The `--volumes` flag is required. Plain `docker compose down` stops containers but **silently
preserves named volumes**. After dropping the volume, the next `docker compose up` (or re-running
`install.sh`) will create a fresh volume and run the init scripts against the current SQL.

Note: this destroys all data in the database. For a test or fresh install this is the correct
approach. For a production instance with real data, a SQL migration would be needed instead.

## Relationship to Telemetry

`_install_channel` is used as the `install_channel` field in telemetry payloads. It is already
correctly set to `"quickstart"` in `install.sh`. However, this value currently exists only in
the telemetry payload — it does not propagate to the runtime `.env` that PHP reads. This is the
root of the `GIGHIVE_INSTALL_CHANNEL` gap described above.
