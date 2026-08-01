---
description: RCA for staging MCP server failure caused by unpinned mcp dependency upgrading to 2.0.0
---

# Problem: MCP Server Broke After Upstream `mcp` Major-Version Upgrade

## Summary

The staging MCP server stopped working after the Aug 1, 2026 `gighive` playbook run.
The direct failure was that `/home/ubuntu/gighive/mcp-server/server.py` still imports:

```python
from mcp.server.fastmcp import FastMCP
```

but the staging virtualenv had been rebuilt with `mcp 2.0.0`, which no longer provides
`mcp.server.fastmcp.FastMCP`.

The underlying issue was an **unpinned Python dependency** in:

- `ansible/roles/mcp_server/files/mcp-server/requirements.txt`

which listed:

```text
mcp
mysql-connector-python
python-dotenv
```

with no version cap or pin.

## Impact

- Devin / Windsurf MCP calls to `staging` failed because the remote MCP server process could not start.
- `lab`, `dev`, and `prod` continued working because their virtualenvs still had `mcp 1.28.1`.
- The problem appeared environment-specific even though the code under `server.py` was the same.

## Symptoms

Observed behavior on staging:

- MCP calls returned connection failure / could not connect to the `staging` MCP server.
- Manual SSH validation of the server-side venv showed:

```bash
/home/ubuntu/gighive/mcp-server/venv/bin/python -c "from mcp.server.fastmcp import FastMCP; print('FastMCP ok')"
```

returned:

```text
ModuleNotFoundError: No module named 'mcp.server.fastmcp'
```

At the same time:

```bash
/home/ubuntu/gighive/mcp-server/venv/bin/pip show mcp
```

showed:

```text
Version: 2.0.0
```

while `lab`, `dev`, and `prod` each showed:

```text
Version: 1.28.1
```

and successfully imported `FastMCP`.

## Problems Encountered / Trail of Tears

### Initial incorrect hypothesis

The first suspicion was that a reboot had "disabled" the MCP server.

That turned out to be incomplete. The MCP server is not a daemon and is not meant to survive
reboots as a running process. A reboot by itself is not the failure.

### What actually happened

The reboot caused the `gighive` playbook to continue into the `mcp_server` role, which then
reinstalled the MCP server virtualenv from an unpinned `requirements.txt`. By Aug 1, PyPI's
latest `mcp` release had advanced to `2.0.0`, and that major version removed the `FastMCP`
import path the GigHive server code still relies on.

### Two equivalent root-cause statements used during diagnosis

Version 1:

- The reboot itself did not break the MCP server. What broke it was the `mcp_server` role that ran after the reboot.
- The role does `pip install -r /home/ubuntu/gighive/mcp-server/requirements.txt`.
- `requirements.txt` contains just `mcp` — no version pin.
- On Aug 1, `pip` fetched the current latest `mcp` from PyPI, which was `2.0.0`.
- `mcp 2.0.0` no longer provides `mcp.server.fastmcp.FastMCP`.
- GigHive's `server.py` still imports `FastMCP`, so the staging MCP server can no longer start.

Version 2:

- Between 7/26 and 8/1, PyPI's `mcp` package advanced to `2.0.0`.
- Because `requirements.txt` was unpinned, the Aug 1 staging playbook installed the new major version.
- `mcp 2.0.0` removed `mcp.server.fastmcp.FastMCP`.
- GigHive's `server.py` still imports `FastMCP`.
- Result: staging MCP server can no longer spawn.

## Timeline

- **2026-07-26** — earlier staging playbook state predates the break; MCP server was not yet rebuilt against `mcp 2.0.0`.
- **2026-08-01 09:22:40 EDT** — `base : Reboot if required` runs during the `gighive` playbook on staging.
- **2026-08-01 09:25:47 EDT** — `mcp_server` role begins recreating / syncing `/home/ubuntu/gighive/mcp-server`.
- **2026-08-01 09:25:49 EDT** — `mcp_server` role installs Python dependencies into the virtualenv from `requirements.txt`.
- **2026-08-01 09:26:06 EDT** — Ansible validation passes because it only checks `import mcp` and `py_compile server.py`, not `from mcp.server.fastmcp import FastMCP`.
- **Later diagnosis** — manual SSH checks reveal staging has `mcp 2.0.0` while `lab`, `dev`, and `prod` still have `1.28.1`.

## Root Cause

### Direct cause

The staging host's MCP virtualenv contained `mcp 2.0.0`, but GigHive's `server.py` still used the
v1 API import:

```python
from mcp.server.fastmcp import FastMCP
```

That import fails under `mcp 2.0.0`, so the on-demand MCP process cannot start.

### Underlying cause

The dependency was unpinned in `requirements.txt`. The Ansible role rebuilt the venv by asking pip
for `mcp` with no version bound, so a later playbook run silently picked up a breaking upstream
major-version release.

### Why the reboot was related but not the root cause

The reboot did not itself remove or disable MCP functionality. It mattered only because the playbook
continued after the reboot and executed the `mcp_server` role, which reinstalled dependencies.

## Exact Execution Flow That Led to the Issue

### Stepwise failure path

1. `gighive` Ansible playbook starts on staging.
2. Base role detects `/var/run/reboot-required`.
3. Ansible reboots the staging VM host.
4. Host comes back; playbook resumes.
5. `mcp_server` role syncs `mcp-server/` source to `/home/ubuntu/gighive/mcp-server/`.
6. `mcp_server` role runs pip install into `/home/ubuntu/gighive/mcp-server/venv` using `requirements.txt`.
7. `requirements.txt` requests `mcp` with no pin.
8. Pip resolves the latest PyPI release at that time: `mcp 2.0.0`.
9. `server.py` remains unchanged and still imports `from mcp.server.fastmcp import FastMCP`.
10. Ansible validation passes because it only checks `import mcp` and syntax compilation.
11. Later, Devin/Windsurf tries to spawn the MCP server over SSH.
12. Python evaluates `server.py`, hits the `FastMCP` import, and raises `ModuleNotFoundError`.
13. MCP client reports that `staging` cannot connect.

### Why only staging broke

1. `lab`, `dev`, and `prod` had older, already-built virtualenvs.
2. Those older venvs still contained `mcp 1.28.1`.
3. `mcp 1.28.1` still exports `mcp.server.fastmcp.FastMCP`.
4. Staging alone rebuilt its venv after the upstream release changed, so staging alone picked up the incompatible version.

## Resolution

### Immediate fix options

Option A — safest / smallest change:

- Pin the dependency in `ansible/roles/mcp_server/files/mcp-server/requirements.txt` to a known-good 1.x release, e.g.:

```text
mcp==1.28.1
mysql-connector-python
python-dotenv
```

or at minimum:

```text
mcp<2.0
```

Option B — larger future migration:

- Port `server.py` from `FastMCP` to the `mcp 2.x` API.

### Recommended immediate path

Use Option A first. The current codebase and the implementation doc both assume the `FastMCP` API.
Pinning to the working major version restores parity with `lab`, `dev`, and `prod`.

## Future Feature

In the future, the MCP server should become **version-agnostic** with respect to the upstream
`mcp` Python SDK.

A reasonable target state would be:

- support the currently deployed `FastMCP` path while the codebase remains on `mcp 1.x`
- plan and test a deliberate migration to the `mcp 2.x` API instead of depending on whichever version pip resolves on a given day
- keep validation aligned with the actual runtime API so future upgrades fail during Ansible validation instead of later during Devin / Windsurf connection attempts

Until that migration is intentionally designed and implemented, the deployment should stay pinned
to `mcp==1.28.1`.

## Verification

### Check package version on each host

```bash
ssh staging '/home/ubuntu/gighive/mcp-server/venv/bin/pip show mcp'
ssh lab '/home/ubuntu/gighive/mcp-server/venv/bin/pip show mcp'
ssh dev '/home/ubuntu/gighive/mcp-server/venv/bin/pip show mcp'
ssh prod '/home/ubuntu/gighive/mcp-server/venv/bin/pip show mcp'
```

Expected after fix:

- staging reports the same pinned 1.x version as the other environments

### Check `FastMCP` import explicitly

```bash
ssh staging '/home/ubuntu/gighive/mcp-server/venv/bin/python -c "from mcp.server.fastmcp import FastMCP; print(\"FastMCP ok\")"'
```

Expected after fix:

```text
FastMCP ok
```

### Check MCP from Devin / Windsurf

- Run `mcp_list_tools` for `staging`.
- Run a simple staging tool call such as `get_schema_tables`.

Expected after fix:

- `staging` reconnects successfully
- tool calls return results instead of connection failures

## Preventative Actions

- Pin `mcp` to a known-good compatible version in `requirements.txt`.
- Add an Ansible validation step that checks the actual import used by production code:

```bash
{{ mcp_server_dir }}/venv/bin/python -c "from mcp.server.fastmcp import FastMCP; print('FastMCP ok')"
```

- Prefer version caps or exact pins for any dependency whose public API is imported directly by entry-point code.
- When a role rebuilds a virtualenv, treat unpinned dependencies as a potential nondeterminism risk.

## Related

- `docs/feature_completed_mcp_server.md`
- `docs/problem_environment_change_requires_process_restart.md`
- `ansible/roles/mcp_server/files/mcp-server/requirements.txt`
- `ansible/roles/mcp_server/files/mcp-server/server.py`
- `/home/sodo/gighive/ansible-playbook-gighive-20260801.log` on `staginghost`
