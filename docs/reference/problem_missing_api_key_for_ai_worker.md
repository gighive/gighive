# Problem: AI Worker Crash-Loops Due to Missing OPENAI_API_KEY

## Symptom

After a full deploy, the `ai-worker` container starts but immediately crashes and restarts every
minute or so. Videos are not tagged. The Tags column in the Media Library shows "Tag this media"
buttons for all assets but no tags are ever assigned.

`docker ps` reveals the tell: `ai-worker` has a very short uptime (e.g. "Up 55 seconds") while
all other containers have been up for hours.

## Root Cause

`OPENAI_API_KEY` was empty in the container environment. The failure chain:

1. `openai_api_key` was **not set** in
   `ansible/inventories/group_vars/gighive/secrets.yml` when the **first** playbook run executed
   the `docker` role.
2. The `docker` role rendered `templates/.env.j2` → `apache/externalConfigs/.env` with
   `OPENAI_API_KEY=` (empty string).
3. The key was added to `secrets.yml` before the **second** playbook run, but the second run
   **skipped the `docker` tag** (`--skip-tags ... docker ...`), so `.env` was never regenerated.
4. The ai-worker container launched with the stale empty `.env`, and the `OpenAIAdapter()`
   constructor raises `openai.OpenAIError: Missing credentials` immediately on startup — before
   any polling even begins.

```
openai.OpenAIError: Missing credentials. Please pass an `api_key` ...
  or set the `OPENAI_API_KEY` or `OPENAI_ADMIN_KEY` environment variable.
```

## Secondary Symptom: VM Network Destabilization

If the ai-worker crash-loops for an extended period (hours), it can take down the **VM's external
network interface** entirely — making the VM unreachable via SSH/ping even though VirtualBox still
reports it as "running."

**Mechanism:** Each Docker container restart creates and destroys a new `veth` (virtual ethernet)
pair. With a ~60s restart backoff, ~180+ veth interfaces were created and destroyed over ~3 hours,
accumulating iptables/conntrack/netfilter kernel state until `eth0` became unstable.

Confirmed via `journalctl -b -1 -u systemd-networkd`:
```
10:50:30  eth0: Link UP, Gained carrier          ← VM booted
10:51:01  veth3e350b7: Lost carrier, Link DOWN   ← first ai-worker crash (~30s after boot)
10:51:40  veth768ba4b: Link UP                   ← Docker restarted container
10:52:40  veth...: Lost carrier                  ← crashed again (every ~60s thereafter)
...       [~180 cycles over ~3 hours]
13:50     eth0 becomes unreachable from network  ← kernel networking state exhausted
```

**Recovery:** `VBoxManage controlvm gighive reset` from the physical host.

## Diagnosis Commands

```bash
# Confirm crash-loop
docker ps                                         # check ai-worker uptime vs other containers
docker logs ai-worker --tail 50                   # look for OpenAIError on startup

# Confirm empty key in container env
docker exec ai-worker printenv | grep -E 'OPENAI|AI_WORKER'

# Confirm empty key in .env on disk
grep -E 'OPENAI|AI_WORKER' ~/gighive/apache/externalConfigs/.env

# Confirm key is present in secrets.yml
grep openai_api_key ~/gighive/ansible/inventories/group_vars/gighive/secrets.yml
```

## Fix

1. Ensure `openai_api_key` is set in
   `ansible/inventories/group_vars/gighive/secrets.yml`:
   ```yaml
   openai_api_key: "sk-..."
   ```

2. Re-run the playbook **including the `docker` tag** to regenerate `.env`:
   ```bash
   ansible-playbook -i ansible/inventories/inventory_lab.yml ansible/playbooks/site.yml \
     --tags docker
   ```

3. Verify the key is now live in the container:
   ```bash
   docker exec ai-worker printenv | grep OPENAI_API_KEY
   ```
   The worker will pick up any `ai_jobs` rows that were already enqueued during the import
   automatically once it stays healthy.

## Prevention

- **Always set `openai_api_key` in `secrets.yml` before the first playbook run** when
  `ai_worker_enabled: true`. The `ai_worker` role's `validate.yml` asserts this at deploy time,
  but only if the `ai_worker` tag is not skipped.
- If you re-run the playbook after changing any secret, **do not skip `docker`** — that is the
  role that renders `.env` from the template. Skipping it leaves stale values on disk.
- `secrets.example.yml` does not include `openai_api_key` as a placeholder. Consider adding one
  so first-time setup is less error-prone (see below).

## Related Files

- `ansible/inventories/group_vars/gighive/secrets.example.yml` — template for secrets; lacks
  `openai_api_key` placeholder (potential improvement)
- `ansible/roles/docker/templates/.env.j2` — renders `OPENAI_API_KEY` from `openai_api_key`
- `ansible/roles/ai_worker/tasks/validate.yml` — asserts key is non-empty at deploy time
- `ansible/roles/docker/files/apache/webroot/src/Services/UnifiedIngestionCore.php` —
  `ingestComplete()` only enqueues `ai_jobs` rows when `AI_WORKER_ENABLED=true`
