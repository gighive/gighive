# Problem: Environment Variable Change Requires Process Restart

## Summary

When `MYSQL_DATABASE` (or other connection parameters) in
`apache/externalConfigs/.env` is changed, any long-running process that loaded
the file at startup will continue using the old value until it is restarted.
This was first observed during the `music_db` → `media_db` database rename
(see `docs/refactored_database_rename_music_db.md`).

---

## Root Cause

The MCP server (`ansible/roles/mcp_server/files/mcp-server/db.py`) calls
`load_dotenv(config.ENV_FILE)` at **module import time** — once, when the
process first starts. Python's `load_dotenv` (with default `override=False`)
reads the file at that moment and writes values into `os.environ`. Subsequent
changes to the `.env` file on disk are never re-read by the running process.

```python
# db.py — called once at import, not per-query
load_dotenv(config.ENV_FILE)

def _connect():
    return mysql.connector.connect(
        database=os.getenv('MYSQL_DATABASE', 'media_db'),  # reads from os.environ, not from disk
        ...
    )
```

Windsurf spawns MCP server processes on-demand and they can persist for days.
During the rename, two stale MCP processes (PIDs 145827 from Jun 19,
213747 from Jun 24) were holding `MYSQL_DATABASE=music_db` in memory even
though the `.env` on disk had already been updated to `media_db` and the MySQL
database `music_db` no longer existed.

---

## Symptoms

- MCP tool calls returning: `1044 (42000): Access denied for user 'appuser'@'%' to database 'music_db'`
- Error 1044 (not 1049 "Unknown database") because MySQL returns "Access Denied"
  for non-privileged users attempting to connect to a non-existent database.
- `/proc/<pid>/environ | tr '\0' '\n' | grep MYSQL_DATABASE` returns **nothing**
  (the env var was not in the kernel-passed environment, it was set by `load_dotenv`
  inside the process, which `/proc/pid/environ` does not reflect).

---

## Detection

To see what value the running MCP process is actually using, simulate its
startup on the VM:

```bash
cd ~/gighive/mcp-server && ./venv/bin/python3 -c "
from dotenv import load_dotenv
import config, os
load_dotenv(config.ENV_FILE)
print('ENV_FILE:', config.ENV_FILE)
print('MYSQL_DATABASE:', os.getenv('MYSQL_DATABASE'))
"
```

To find stale MCP processes and their ages:

```bash
ps aux | grep mcp-server
```

---

## Fix Applied

A `pkill` task was added as the **first step** of
`ansible/roles/mcp_server/tasks/main.yml` so that future Ansible deploys
automatically kill any running MCP server processes before deploying updated
code or config:

```yaml
- name: Kill any stale mcp-server processes before updating
  ansible.builtin.shell: pkill -f "mcp-server/server.py"
  failed_when: false
  changed_when: false
  when: mcp_server_enabled | default(false) | bool
```

Windsurf automatically respawns a fresh MCP process on next connection, picking
up the current `.env` values.

---

## General Rule

Any process that reads `.env` **at startup** (not per-request) must be restarted
whenever the following `.env` keys change:

- `MYSQL_DATABASE`
- `MYSQL_USER`
- `MYSQL_PASSWORD`
- `MYSQL_HOST` / `DB_HOST`

**Affected processes:** MCP server, AI worker (reads `.env` via `load_dotenv`
in its own `db.py`).

**Not affected:** PHP/Apache container (Docker Compose re-reads `env_file` on
`docker compose up`, and the container is restarted by Ansible), so the app
itself picks up `.env` changes automatically on the next Ansible deploy.

---

## Restarting the MCP Server Without Full Windsurf Restart

If Ansible cannot be run immediately, the MCP server can be restarted manually:

```bash
# On each affected VM
pkill -f "mcp-server/server.py"
# Windsurf will auto-respawn on next MCP call
```

To reconnect in Windsurf without a full quit (`Ctrl+Q`):

1. **Command Palette** (`Ctrl+Shift+P`) → `Developer: Reload Window` — restarts
   the extension host and reconnects all MCP servers (recommended)
2. **Touch `mcp_config.json`** — Windsurf watches the config file; saving a
   trivial change triggers reconnect of all servers

The toggle disable/enable per-server option in Windsurf Settings does **not**
reliably force a reconnect.

---

## Related

- `docs/refactored_database_rename_music_db.md` — the refactor that surfaced this problem
- `ansible/roles/mcp_server/tasks/main.yml` — contains the pkill fix
