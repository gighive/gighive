---
description: Eliminating Docker bind-mount failures caused by file paths turning into directories
---

# Problem statement

GigHive’s Apache container bind-mounts many host-side configuration files into the container (e.g. `ports.conf`, `default-ssl.conf`, `security2.conf`). Docker requires that the **host path type** matches the **container path type**:

- file -> file
- directory -> directory

If a host path that is expected to be a file becomes a directory (often due to an earlier miscreate, partial deploy, or a sync artifact), the container may get stuck in Docker state `created` with an error like:

- `not a directory: Are you trying to mount a directory onto a file (or vice-versa)?`

This document describes two approaches to prevent and/or eliminate these failures.

---

# Approach A: Expand the per-file "guard" pattern (stat + remove-if-dir)

## Summary

Add a consistent guard before every task that writes a bind-mounted file:

1. `stat` the destination path
2. if it is a directory, remove it (`state: absent`)
3. render/copy the file as normal

This is the same pattern already used in some parts of `ansible/roles/docker/tasks/main.yml` for files like `ports.conf`, `logging.conf`, and `default-ssl.conf`.

## Where this applies

Apply this to every bind-mounted **file** path under `{{ docker_dir }}/apache/externalConfigs/` that is later mounted into the container.

Examples (not exhaustive):

- `apache2.conf`
- `ports.conf`
- `default-ssl.conf`
- `logging.conf`
- `php-fpm.conf`
- `www.conf`
- `entrypoint.sh`
- `openssl_san.cnf`
- `modsecurity.conf`
- `security2.conf`
- `apache2-logrotate.conf`

Do the same for MySQL bind-mounted config files under `{{ docker_dir }}/mysql/externalConfigs/`.

## Implementation details

### Pattern template

For a given file `X`:

- Add:
  - `Stat X path (guard)`
  - `Remove miscreated X directory (if present)`
- Then keep the existing `copy:` or `template:` task that writes file `X`.

### Pros

- **Localized and low-risk**: doesn’t change any path conventions.
- **Easy to backport**: can be added file-by-file as errors are discovered.
- **Self-healing**: a rerun repairs a broken host state.

### Cons / drawbacks

- **Repetitive**: lots of boilerplate tasks.
- **Easy to miss one file**: one missed file can still brick container startup.
- **Does not address root cause**: it treats symptoms (miscreated dirs) rather than preventing them.

### Recommended usage

- Use when you want the smallest possible change to get reliability quickly.
- Good as an immediate mitigation.

---

# Approach B: Refactor to a dedicated runtime directory for Docker mounts (separate from repo sources)

## Summary

Use a dedicated host directory for all docker bind mounts (e.g. `{{ gighive_home }}/docker`) rather than binding directly from the repo source tree (e.g. `{{ gighive_home }}/ansible/roles/docker/files`).

This separates:

- **source-of-truth templates/files** (in the git repo)
- from **rendered runtime config** (on the host, used by docker-compose binds)

## Why this helps

Using the repo tree as a runtime mount root increases the chance of:

- accidental creation of directories where files are expected
- sync/delete behaviors (`synchronize: delete: yes`) interacting with runtime state
- confusing coupling between “repo content” and “live runtime config”

By moving runtime config to a dedicated location, you:

- reduce path confusion
- reduce the chance that rsync/synchronize operations create invalid mount paths
- make it obvious which directory is safe to edit manually on the host

## What must change

### 1) Define the runtime docker directory

Pick a single canonical runtime dir, for example:

- `docker_dir: "{{ gighive_home }}/docker"`

The repo already assumes this style in several places (and the docs also reference `{{ docker_dir }}/apache/...`).

### 2) Ensure the docker role renders/copies into the runtime directory

In `ansible/roles/docker/tasks/main.yml`, all tasks that create:

- `{{ docker_dir }}/apache/externalConfigs/...`
- `{{ docker_dir }}/mysql/externalConfigs/...`
- `{{ docker_dir }}/tusd/hooks/...`

should continue to do so, but now `docker_dir` points to the runtime directory.

### 3) Ensure docker-compose is rendered into the runtime directory

The compose file is rendered to:

- `{{ docker_dir }}/docker-compose.yml`

and should be invoked from `{{ docker_dir }}`.

### 4) Review any tasks/scripts that assume docker_dir == repo files path

Search for places that explicitly assume:

- `docker_dir == "$GIGHIVE_HOME/ansible/roles/docker/files"`

and update them.

Example: `ansible/roles/docker/files/mysql/dbScripts/dbCommands.sh` currently assumes `.env` lives under:

- `$GIGHIVE_HOME/ansible/roles/docker/files/apache/externalConfigs/.env`

If `docker_dir` changes, this script should instead reference:

- `$GIGHIVE_HOME/docker/apache/externalConfigs/.env`

(or derive from an env var).

## Pros

- **Structural fix**: reduces the probability of the issue happening at all.
- **Cleaner mental model**: “repo” vs “runtime” separation.
- **Easier incident response**: all runtime bind mounts live in one directory.

## Cons / drawbacks

- **Broader change surface**: touches playbook vars, tasks, and helper scripts.
- **Migration step needed**: may require cleanup/move of existing runtime directories on hosts.
- **Potential for subtle breakage** if any scripts/roles hardcode the old directory layout.

## Recommended usage

- Use when you want the system to be robust long-term.
- Combine with a smaller subset of Approach A guards for defense-in-depth.

---

# Suggested decision

- If you need the fastest stabilization: implement **Approach A** fully for all bind-mounted config files.
- If you want a long-term cleanup: implement **Approach B**, and optionally keep a few guards from **Approach A** for files that have historically been problematic.

# Verification checklist (either approach)

After changes:

- Ensure Apache container transitions to `running`.
- If it remains `created`, inspect:
  - `docker inspect apacheWebServer --format 'status={{.State.Status}} error={{.State.Error}}'`
- Confirm each host path used in binds exists and has the correct type.
