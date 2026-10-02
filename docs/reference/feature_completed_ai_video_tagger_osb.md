{% raw %}
# AI Video Tagger — One-Shot Bundle Integration

Date: 2026-05-01  
Updated: 2026-05-23 (review corrections incorporated)  
Parent: [feature_ai_video_tagger.md](feature_ai_video_tagger.md)

---

## Files Modified

1. `ansible/roles/ai_worker/files/ai-worker/worker.py` — Prerequisite guard
2. `ansible/inventories/group_vars/gighive/gighive.yml` — Step 1: new var, refactored input paths, two exclusions
3. `ansible/roles/one_shot_bundle/tasks/monitor.yml` — Step 2: new prefix set_fact + `elif` branch
4. `ansible/roles/one_shot_bundle/tasks/output_bundle.yml` — Steps 2, 3, 4: `elif` branch, conditional compose copy task, three loop updates
5. `ansible/roles/docker/templates/install.sh.j2` — Step 5: AI prompt block
6. `ansible/roles/docker/files/one_shot_bundle/docker-compose-ai.yml` — Step 3: new AI-enabled compose variant

---

## High Level Steps

**Prerequisite — `worker.py` `AI_WORKER_ENABLED` guard**  
Add a startup check in `worker.py` that exits cleanly when `AI_WORKER_ENABLED` is not `true`. Must be done before the bundle is usable — without it the container crashes when the user declines AI at install time.

**Step 1 — group_vars**  
Gate `one_shot_bundle_input_paths` on `ai_worker_osb_enabled` using Jinja2 list concatenation. Add `__pycache__` and `docker-compose-ai.yml` to `one_shot_bundle_exclude_source_paths`.

**Step 2 — `monitor.yml` + `output_bundle.yml`: new source prefix and routing**  
Add `_one_shot_bundle_ai_worker_prefix` set_fact to `monitor.yml`. Add `elif` routing branches in both path-mapping blocks to route `ai-worker/` source files to `ai-worker/` in the bundle output.

**Step 3 — `docker-compose-ai.yml`**  
Create a static `docker-compose-ai.yml` file with the `ai-worker` service block. Add a conditional copy task in `output_bundle.yml` that selects the base or AI compose file depending on `ai_worker_osb_enabled`.

**Step 4 — `output_bundle.yml` directory loops**  
Add `_host_ai_assets/` to the three loop tasks (create dirs / normalize dir modes / normalize file modes) using Jinja2 conditional list concatenation.

**Step 5 — `install.sh.j2` AI prompt block**  
Add the yes/no AI enable prompt, silent API key collection, `_patch_env_key` calls for `AI_WORKER_ENABLED` and `OPENAI_API_KEY`, and `GIGHIVE_AI_ASSETS_DIR` export — all gated with `{% if ai_worker_osb_enabled %}`.

---

## `install.sh.j2` Decision Tree

**Assembly time** (Jinja2 renders `install.sh.j2`):

```
ai_worker_osb_enabled?
├── false → AI block omitted entirely from rendered install.sh. Done.
└── true  → AI block included. Continues at runtime ↓
```

**Runtime** (end user runs `install.sh`):

```
"Do you want to enable AI features? [y/N]"
│
├── y / Y / yes
│   ├── Prompt: "Enter your OpenAI API key (sk-...):" (silent input)
│   ├── _patch_env_key AI_WORKER_ENABLED true   → .env
│   ├── _patch_env_key OPENAI_API_KEY "$key"    → .env
│   └── export GIGHIVE_AI_ASSETS_DIR
│
└── anything else (default: N)
    ├── _patch_env_key AI_WORKER_ENABLED false  → .env
    ├── OPENAI_API_KEY stays empty in .env
    └── GIGHIVE_AI_ASSETS_DIR not exported
        └── compose uses default fallback: ./_host_ai_assets
            └── ai-worker container starts → hits worker.py guard → exits cleanly
```

---

## Design Notes

### Bundle inclusion gate: `ai_worker_osb_enabled` in group_vars

Whether the AI worker is included in the one-shot bundle is controlled by `ai_worker_osb_enabled` in group_vars. This is a **dedicated OSB flag, separate from `ai_worker_enabled`** (which gates server-side deployment of the ai_worker role). The two are independent — you can run AI on your own server (`ai_worker_enabled: true`) without offering it in OSB distributions (`ai_worker_osb_enabled: false`), and vice versa. Defaults to `false`.

| `ai_worker_osb_enabled` | Bundle outcome |
|---|---|
| `false` (default) | `ai-worker/` source tree, `_host_ai_assets/` dir, and the `ai-worker` compose service are **not** included. The DB schema tables (`ai_jobs`, `helper_runs`, `derived_assets`, `tags`, `taggings`) are still present — they are inert until the worker is wired up. |
| `true` | All of the above are included. The rendered `install.sh` gains one additional interactive prompt (see Step 5). |

> ⚠️ **When building an AI-enabled OSB, only set `ai_worker_osb_enabled: true`. Leave `ai_worker_enabled: false`.** Setting `ai_worker_enabled: true` during a bundle build causes `.env.j2` to render `AI_WORKER_ENABLED=true` into the pre-baked `.env` (overridden by `install.sh` at runtime, but misleading), and more importantly it activates the `ai_worker` Ansible role — which asserts a non-empty `OPENAI_API_KEY` and will fail if secrets are not present. The two variables are independent by design.

This is a **bundle-assembly-time** decision — the `ai_worker_osb_enabled` variable is evaluated during `ansible-playbook … --tags one_shot_bundle`. `install.sh` is rendered from `install.sh.j2` and can carry Jinja2 conditionals. `docker-compose.yml` is a **static file** (not rendered from a template); AI-worker inclusion is handled by selecting between two static source files at assembly time (see Step 3). End users cannot toggle it post-assembly; they receive either the AI-enabled or AI-disabled bundle depending on which was built.

---

## Historical context: why the AI worker was not in v1

The one-shot bundle is a self-contained quickstart for the core GigHive application. The AI worker was excluded from v1 for three reasons:

1. **API key required** — every installation needs its own `OPENAI_API_KEY`; there is no safe default and no way to pre-configure it in a redistributable bundle.
2. **Image size and build time** — `python:3.11-slim` + `apt-get install ffmpeg` adds ~400–500 MB and several minutes to first-run Docker build, degrading the quickstart experience.
3. **Feature scope** — the AI tagger is an opt-in add-on. The bundle is gated by `AI_WORKER_ENABLED=false` so the PHP side is inert even if the env var leaks through; the worker container simply won't exist.

The `ai_worker_osb_enabled` gate in group_vars resolves all three: the size/time trade-off is accepted by whoever sets `ai_worker_osb_enabled: true` for a bundle build, and the API key is collected interactively by `install.sh` rather than pre-configured.

---

## Implementation Details

### Prerequisite: `worker.py` `AI_WORKER_ENABLED` guard

**File:** `ansible/roles/ai_worker/files/ai-worker/worker.py`

`worker.py` currently proceeds directly to `db.get_connection()` without checking `AI_WORKER_ENABLED`. When a bundle user answers "No" to the AI prompt, `AI_WORKER_ENABLED=false` is patched into `.env` but the container still starts. Without this guard the worker crashes at startup (DB connection or bad/absent API key). Add at the very top of `main()`, before any I/O:

```python
if os.getenv('AI_WORKER_ENABLED', 'false').lower() not in ('1', 'true', 'yes'):
    logger.info('AI_WORKER_ENABLED is not true — exiting cleanly')
    return
```

---

### Step 1: group_vars

**File:** `ansible/inventories/group_vars/gighive/gighive.yml`

First, add the new variable (defaults to `false`):

```yaml
ai_worker_osb_enabled: false
```

Rename the existing `one_shot_bundle_input_paths` YAML list to `one_shot_bundle_base_input_paths` (all current entries unchanged), then redefine `one_shot_bundle_input_paths` as a Jinja2 expression. Do **not** append the ai-worker path unconditionally — use the **parent** dir, not a child path (child paths require re-adding entries when subdirs are added; see `problem_one_shot_bundle_missing_tusd_hook.md`):

```yaml
one_shot_bundle_base_input_paths:
  - ... # all current entries, unchanged

one_shot_bundle_input_paths: "{{ one_shot_bundle_base_input_paths + ([repo_root ~ '/ansible/roles/ai_worker/files/ai-worker'] if ai_worker_osb_enabled | default(false) | bool else []) }}"
```

> **Verify**: check that no other tasks or roles reference `one_shot_bundle_base_input_paths` by the old name `one_shot_bundle_input_paths` directly — a quick grep will confirm.

Also add both entries to `one_shot_bundle_exclude_source_paths`:

```yaml
- "{{ repo_root }}/ansible/roles/ai_worker/files/ai-worker/__pycache__"
- "{{ repo_root }}/ansible/roles/docker/files/one_shot_bundle/docker-compose-ai.yml"
```

- **`__pycache__`** — the find task in `monitor.yml` only excludes `._*` and `.DS_Store`; without this, architecture-specific `.pyc` bytecode ships in the bundle.
- **`docker-compose-ai.yml`** — without this, the bulk copy task picks it up as a monitored file and copies it as a stray file into the bundle root.

> **Ansible note**: the `when:` anti-pattern does not apply here — `one_shot_bundle_input_paths` is a variable, not a task loop condition. Jinja2 list concatenation at the variable level is the correct gating mechanism and avoids the anti-pattern of `when:` on a loop task to skip individual items.

---

### Step 2: `monitor.yml` + `output_bundle.yml` — new source prefix and routing

**Files:** `ansible/roles/one_shot_bundle/tasks/monitor.yml`, `ansible/roles/one_shot_bundle/tasks/output_bundle.yml`

The bundle assembly role currently knows four source root prefixes:

| Variable | Source root |
|---|---|
| `_one_shot_bundle_one_shot_bundle_prefix` | `roles/docker/files/one_shot_bundle/` |
| `_one_shot_bundle_files_prefix` | `roles/docker/files/` |
| `_one_shot_bundle_templates_prefix` | `roles/docker/templates/` |
| `_one_shot_bundle_assets_prefix` | `assets/` |

The `ai-worker/` source lives under `ansible/roles/ai_worker/files/` — a **fifth prefix** not currently handled. Add `_one_shot_bundle_ai_worker_prefix` to the `set_fact` task at the top of `monitor.yml` alongside the four existing prefix vars (`output_bundle.yml` relies on them being set upstream by `monitor.yml`):

```yaml
_one_shot_bundle_ai_worker_prefix: "{{ repo_root }}/ansible/roles/ai_worker/files/ai-worker"
```

Then add an `elif` branch in every path-mapping block in both `monitor.yml` and `output_bundle.yml`. Place it **before** the `_one_shot_bundle_files_prefix` catch-all (convention: specific before general):

```
{% elif _p.startswith(_one_shot_bundle_ai_worker_prefix ~ '/') %}
ai-worker/{{ _p[(_one_shot_bundle_ai_worker_prefix ~ '/') | length:] }}
```

---

### Step 3: `docker-compose-ai.yml`

**Files:**
- New: `ansible/roles/docker/files/one_shot_bundle/docker-compose-ai.yml`
- Modified: `ansible/roles/one_shot_bundle/tasks/output_bundle.yml`

The bundle `docker-compose.yml` is a **static file** — Jinja2 conditionals cannot be used inside it. Create `docker-compose-ai.yml` as the AI-enabled variant: copy the base `docker-compose.yml` and add the `ai-worker` service block to the `services:` section:

```yaml
  ai-worker:
    build: ./ai-worker
    restart: unless-stopped
    env_file: ./apache/externalConfigs/.env
    volumes:
      - "${GIGHIVE_AI_ASSETS_DIR:-./_host_ai_assets}:/data/ai_assets:rw"
      - "${GIGHIVE_VIDEO_DIR:-./_host_video}:/data/video:ro"
      - "${GIGHIVE_AUDIO_DIR:-./_host_audio}:/data/audio:ro"
    depends_on:
      - mysqlServer  # matches the service name in the base docker-compose.yml
```

`GIGHIVE_AI_ASSETS_DIR` follows the same env-var-with-fallback convention as `GIGHIVE_VIDEO_DIR` and `GIGHIVE_AUDIO_DIR`, with `./_host_ai_assets` as the default.

Add a conditional copy task in `output_bundle.yml` **after** the bulk non-template copy step. This task selects the AI or base compose file and writes to `docker-compose.yml` in the bundle output:

```yaml
- name: Select docker-compose.yml variant for fresh one-shot bundle output (controller)
  delegate_to: localhost
  become: false
  ansible.builtin.copy:
    src: "{{ repo_root }}/ansible/roles/docker/files/one_shot_bundle/docker-compose{{ '-ai' if ai_worker_osb_enabled | default(false) | bool else '' }}.yml"
    dest: "{{ _one_shot_bundle_output_dir }}/docker-compose.yml"
    mode: preserve
```

Do **not** use `blockinfile` or in-place text manipulation — that is brittle and not idempotent. Copying a static file is always safe to re-run.

---

### Step 4: `output_bundle.yml` directory loops

**File:** `ansible/roles/one_shot_bundle/tasks/output_bundle.yml`

Three tasks use a `loop:` list of `{path: ...}` items for the `_host_*` runtime directories: create dirs, normalize dir modes, normalize file modes. Add `_host_ai_assets` conditionally to each using Jinja2 list concatenation:

```yaml
loop: "{{ base_items + ([{'path': '_host_ai_assets'}] if ai_worker_osb_enabled | default(false) | bool else []) }}"
```

Mode must be `0777`. Do **not** use `www-data` group or mode `2775` — `www-data` does not exist on macOS/Windows where bundle users may be running Docker Desktop (see `problem_one_shot_bundle_fullbuild_maclinux_wwwdata_diffs.md`).

---

### Step 5: `install.sh.j2` AI prompt block

**File:** `ansible/roles/docker/templates/install.sh.j2`

#### Prompt flow

After `install.sh` collects the existing password prompts, it displays an informational message and asks a yes/no question:

```
==> AI Video Tagger
    GigHive includes an optional AI feature that automatically tags your videos.
    If you wish to take advantage of this, you will need an OpenAI API key.
    Note: enabling this will add 500-700 MB of additional downloads on first start.
    If you do not want to use the AI features, just select No when prompted.

Do you want to enable the AI features of GigHive? [y/N]:
```

**If the user answers `y` / `Y` / `yes`:**

```
Enter your OpenAI API key (sk-...):
```

The entered key and `AI_WORKER_ENABLED=true` are patched into `apache/externalConfigs/.env`. ⚠️ Do not rely on the pre-rendered value — `.env.j2` uses `ai_worker_enabled`, not `ai_worker_osb_enabled`, so the pre-rendered value of `AI_WORKER_ENABLED` cannot be assumed to be `true`.

**If the user answers anything else (default: No):**

The API key prompt is skipped. `AI_WORKER_ENABLED=false` is patched into `.env`. The `ai-worker` container still starts (Docker Compose starts every service unconditionally) but the guard added in the Prerequisite causes it to exit cleanly.

If `ai_worker_osb_enabled: false` at assembly time, this entire block does not appear.

#### Implementation notes

- Gate the entire block with `{% if ai_worker_osb_enabled | default(false) | bool %} … {% endif %}`
- Inside the gate, display the informational message, then prompt `[y/N]` using `read -r`; default to `N` if the user presses Enter without typing
- Only prompt for the API key if the user answered `y`/`Y`/`yes`; use `read -rs` (silent, no echo) for the key itself
- Write the outcome using the correct tool for each key:
  - `AI_WORKER_ENABLED` — always patch explicitly on both paths; do not rely on the pre-rendered value (`.env.j2` uses `ai_worker_enabled`, not `ai_worker_osb_enabled`): yes path: `_patch_env_key AI_WORKER_ENABLED true "$APACHE_ENV_FILE"`; no path: `_patch_env_key AI_WORKER_ENABLED false "$APACHE_ENV_FILE"`.
  - `OPENAI_API_KEY` is present in the pre-rendered `.env` as an **empty value** (`OPENAI_API_KEY=`), rendered by `.env.j2` via `{{ openai_api_key | default('') }}`. Use `_patch_env_key OPENAI_API_KEY "$OPENAI_API_KEY" "$APACHE_ENV_FILE"` on the **yes path** to fill it in. Do **not** use `printf >> $FILE` — the key already exists and appending would create a duplicate entry that most parsers silently ignore. On the **no path**, do nothing (the key stays empty; `AI_WORKER_ENABLED=false` is the runtime gate). ⚠️ **Security**: if `openai_api_key` is set in `secrets.yml` on the build machine, it will be baked non-empty into the bundle. For OSB builds, `openai_api_key` must be absent or empty in the Ansible inventory.
- Also export `GIGHIVE_AI_ASSETS_DIR` (alongside the existing `GIGHIVE_AUDIO_DIR` / `GIGHIVE_VIDEO_DIR` exports) only on the yes path, so Compose picks up the correct bind-mount path
- The prompt position — after passwords, before `docker compose up` — follows the existing `install.sh` flow; no structural changes to the script are needed

---

## UI / runtime behavior notes

### PHP AI feature gating reads the runtime env var, not the Ansible variable

All PHP pages (`admin/ai_worker.php`, `db/media_tags.php`, `db/tag_browser.php`, `api/ai_jobs.php`, `src/Services/UnifiedIngestionCore.php`) gate AI features via `getenv('AI_WORKER_ENABLED')` — the **container's runtime environment variable**, not the Ansible variable `ai_worker_enabled`. Docker Compose injects this from `apache/externalConfigs/.env` at container start (via `env_file:`).

For OSB installs the flow is:
1. Bundle assembled with `ai_worker_enabled: false` → `.env` baked with `AI_WORKER_ENABLED=false`
2. User runs `install.sh`, answers yes to AI → `_patch_env_key AI_WORKER_ENABLED true` rewrites it in `.env`
3. `docker compose up` → container starts with `AI_WORKER_ENABLED=true`
4. PHP reads `getenv('AI_WORKER_ENABLED')` → `true` → AI features are visible and active

There is no dependency on `ai_worker_enabled` being `true` at bundle-assembly time for the PHP UI to work correctly.

---

## Known issues / future fixes

### Stale "re-run Ansible" message in `admin/ai_worker.php`

`admin/ai_worker.php` line 155 displays:

```
Set <code>ai_worker_enabled: true</code> in group_vars and re-run Ansible to enable.
```

This message is shown when `AI_WORKER_ENABLED=false` at runtime. For server-side Ansible deployments the advice is correct, but for OSB installs the user has no Ansible inventory — they should re-run `install.sh` (or manually patch `apache/externalConfigs/.env`) instead. The message should be updated to distinguish between the two deployment paths.

---

## Deferred

### `instructions_quickstart.sh` → `.j2` conversion

`instructions_quickstart.sh` is a download-and-run bootstrap script (downloads the bundle tarball, verifies checksum, expands it, and calls `install.sh`). It is not used in practice and since `install.sh` handles all AI prompts directly, the UX gap is minimal.

If implemented later: (a) move to `roles/docker/templates/instructions_quickstart.sh.j2`; (b) add path mapping in both `monitor.yml` and `output_bundle.yml`; (c) add the static `instructions_quickstart.sh` to `one_shot_bundle_exclude_source_paths` — the bulk copy task runs **after** the template render and would overwrite the rendered output without this exclusion; (d) add `instructions_quickstart.sh.j2` to the `_one_shot_bundle_output_mode` `0755` override block in `output_bundle.yml`.
{% endraw %}
