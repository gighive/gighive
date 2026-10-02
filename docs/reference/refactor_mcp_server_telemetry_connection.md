# Refactor: Expose Telemetry Database Through MCP Without Disturbing App DB Tools

## Status

Proposed only. No code changes implemented yet.

---

## Rationale

The current MCP server on GigHive is working again after pinning `mcp==1.28.1`, but it only exposes the main application database (`media_db`). The telemetry receiver uses a separate Docker stack, separate `.env`, separate database (`installation_telemetry`), and separate table set.

Because the MCP role is intentionally wired to the main app `.env`, telemetry queries through the existing MCP SQL tools fail with errors like:

```text
1146 (42S02): Table 'media_db.installation_events' doesn't exist
```

That behavior is correct for the current design, but it prevents operational inspection of telemetry data from the MCP layer.

---

## Goal

Add telemetry database access to the MCP server in a way that:

- preserves all current app-database MCP behavior
- does not retarget existing tools away from `media_db`
- makes telemetry access explicit and easy to reason about
- keeps the first implementation small and rollback-safe

---

## Current State

### MCP server role

The MCP role is explicitly configured to read the main app Docker `.env` and connect to the app DB on localhost:

- `mcp_env_file: "{{ docker_dir }}/apache/externalConfigs/.env"`
- `MYSQL_HOST = "127.0.0.1"`
- `MYSQL_PORT = 3306`
- `database=os.getenv('MYSQL_DATABASE', 'media_db')`

That means the current MCP server is intentionally scoped to the main app stack.

### Telemetry receiver role

The telemetry receiver is deployed separately under:

- `{{ gighive_home }}/telemetry_receiver`

It has its own env file and DB settings:

- database: `installation_telemetry`
- user: `telemetry_app`
- DB host (inside compose): `telemetry_db`
- main telemetry table: `installation_events`

### Confirmed behavior on staging

The deployed staging MCP server currently resolves to:

- `ENV_FILE=/home/ubuntu/gighive/ansible/roles/docker/files/apache/externalConfigs/.env`
- `MYSQL_DATABASE=media_db`
- connected database: `media_db`

So the current staging behavior matches the role exactly.

---

## Why Not Change the Existing `db.py`

Changing the current shared `db.py` to point at telemetry, or adding runtime switching into the existing app-db path, would be the wrong first move because it would:

- risk breaking all current app-data tools
- blur the boundary between app DB and telemetry DB
- make it harder to reason about what `execute_select` is querying
- increase rollback risk for a toolchain that is already in active operational use

The current `db.py` should remain dedicated to the app DB.

---

## Proposed Implementation

## Files Under Change

### New

1. `gighiveinfra/ansible/roles/mcp_server/files/mcp-server/telemetry_db.py` — separate MySQL helper dedicated to the telemetry receiver DB, mirroring the existing `db.py` pattern but loading telemetry env/config values.
2. `gighiveinfra/ansible/roles/mcp_server/files/mcp-server/tools/telemetry.py` — MCP tools for telemetry-safe read access, beginning with table discovery and read-only SQL execution.

### Modified

1. `gighiveinfra/ansible/roles/mcp_server/templates/config.py.j2` — add telemetry-specific config constants such as telemetry env path and, if appropriate, telemetry host/port.
2. `gighiveinfra/ansible/roles/mcp_server/files/mcp-server/server.py` — register the new telemetry tools without changing existing app-db tool registration.
3. `gighiveinfra/ansible/roles/mcp_server/templates/README.md.j2` — document the new telemetry MCP tools once added.
4. `gighiveinfra/ansible/roles/mcp_server/tasks/validate.yml` — add validation for the telemetry helper/tool import path after implementation.

### Unchanged

- `gighiveinfra/ansible/roles/mcp_server/files/mcp-server/db.py` should remain the app-db helper.
- Existing tools in `tools/media_library.py`, `tools/schema.py`, `tools/upload_jobs.py`, and `tools/ai_pipeline.py` should continue to query `media_db` unchanged.

### Step 1: Add telemetry-specific config

Extend `config.py.j2` with dedicated telemetry constants, for example:

```python
TELEMETRY_ENV_FILE = "{{ gighive_home }}/telemetry_receiver/.env"
TELEMETRY_MYSQL_HOST = "127.0.0.1"
TELEMETRY_MYSQL_PORT = 3306
```

This keeps telemetry configuration explicit rather than overloading the existing app-db constants.

### Step 2: Add a second DB helper

Create `telemetry_db.py` that mirrors the current `db.py` structure:

- load env vars from `TELEMETRY_ENV_FILE`
- connect with telemetry credentials
- use `MYSQL_DATABASE` from the telemetry env file
- provide small helper functions like `query()` and `query_one()`

This keeps telemetry DB access isolated from the existing app DB helper.

### Step 3: Add telemetry MCP tools

Create `tools/telemetry.py` with a minimal first tool set:

- `get_telemetry_tables()`
- `execute_telemetry_select(sql: str, limit: int = 200)`

Design notes:

- keep it read-only
- apply the same SELECT/CTE safety pattern already used by `tools/schema.py`
- keep the tool names telemetry-specific so the target DB is obvious at the call site

### Step 4: Register telemetry tools in `server.py`

Add one more import/registration block for the telemetry module, without touching the existing app-db tool registrations.

### Step 5: Extend validation

After implementation, validation should verify:

- telemetry helper imports cleanly
- telemetry tool module imports cleanly
- if practical, a lightweight read-only connection check to the telemetry DB succeeds

---

## Preferred Access Strategy

### Preferred: direct MySQL connection from host-side Python

If the telemetry MySQL service is reachable from the host on a stable localhost port, this is the cleanest path because it matches the current MCP architecture:

- host-side Python process
- direct MySQL connection
- no shelling out through Docker for normal read operations

### Fallback: `docker exec telemetry_db mysql ...`

If telemetry MySQL is not exposed on a host port, the fallback is to implement telemetry tools by shelling out through Docker:

- `docker exec telemetry_db mysql ...`

This should be treated as a fallback path only, because it is less elegant and more operationally coupled to container naming.

---

## Smallest Safe First Slice

The smallest safe first implementation is:

1. Add telemetry-specific config constants
2. Add `telemetry_db.py`
3. Add `tools/telemetry.py`
4. Register:
   - `get_telemetry_tables()`
   - `execute_telemetry_select()`

Do **not** modify the existing `execute_select()` behavior in the first slice.

This gives immediate operator value while keeping blast radius low.

---

## Verification

### Verify current app DB behavior remains unchanged

- `get_schema_tables` should still return the main app tables such as `events`, `assets`, and `upload_jobs`.
- Existing app-data MCP tools should behave exactly as before.

### Verify telemetry DB access works

- `get_telemetry_tables` should include `installation_events`.
- `execute_telemetry_select("SELECT * FROM installation_events ORDER BY id DESC LIMIT 20")` should return telemetry rows when present.

### Verify boundary clarity

- `execute_select("SELECT * FROM installation_events")` should still fail against `media_db` unless the app schema itself changes.
- `execute_telemetry_select(...)` should be the explicit path for telemetry access.

---

## Risks

- Telemetry MySQL may not be exposed on the host in the same way as the app DB; this must be verified before choosing the direct-connection implementation path.
- If telemetry env path or container naming changes later, telemetry-specific MCP config must be kept aligned with the telemetry receiver role.
- A shared helper refactor that tries to unify app DB and telemetry DB too early would create avoidable complexity.

---

## Recommendation

Implement telemetry MCP access as a **parallel, telemetry-specific path**, not as a mutation of the current app-db MCP path.

That gives the cleanest architecture:

- app DB tools stay on `media_db`
- telemetry tools explicitly target `installation_telemetry`
- operational intent stays obvious at every call site
- rollback remains simple

---

## Related

- `gighiveinfra/docs/feature_completed_mcp_server.md`
- `gighiveinfra/docs/problem_mcp_server_version_update_unpinned.md`
- `gighiveinfra/ansible/roles/mcp_server/files/mcp-server/db.py`
- `gighiveinfra/ansible/roles/mcp_server/files/mcp-server/tools/schema.py`
- `gighiveinfra/ansible/roles/telemetry_receiver/defaults/main.yml`
- `gighiveinfra/ansible/roles/telemetry_receiver/templates/telemetry.env.j2`
- `gighiveinfra/ansible/roles/telemetry_receiver/files/mysql/init/01-schema.sql`
