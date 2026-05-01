# AI Video Tagger — One-Shot Bundle Integration

Date: 2026-05-01  
Parent: [feature_ai_video_tagger.md](feature_ai_video_tagger.md)

---

## What we're building

1. Gate bundle output on `ai_worker_enabled` in group_vars
2. Add `ai-worker/` to `one_shot_bundle_input_paths` when enabled
3. Add `ai-worker` service to bundle `docker-compose.yml`
4. Create `_host_ai_assets/` bind-mount dir in `output_bundle.yml`
5. Add AI env vars and `OPENAI_API_KEY` prompt to `install.sh.j2`
6. Frame retention purge (if cron-based in the container)
7. Verify execute bits on any shell scripts
8. Convert `instructions_quickstart.sh` to j2 and add AI worker step

---

## Design

### Bundle inclusion gate: `ai_worker_enabled` in group_vars

Whether the AI worker is included in the one-shot bundle is controlled by a single variable: `ai_worker_enabled` in the relevant `group_vars` file (e.g. `ansible/inventories/group_vars/gighive/gighive.yml`).

| `ai_worker_enabled` | Bundle outcome |
|---|---|
| `false` (default) | `ai-worker/` source tree, `_host_ai_assets/` dir, and the `ai-worker` compose service are **not** included. The DB schema tables (`ai_jobs`, `helper_runs`, `derived_assets`, `tags`, `taggings`) are still present — they are inert until the worker is wired up. |
| `true` | All of the above are included. The rendered `install.sh` gains one additional interactive prompt (see below). |

This is a **bundle-assembly-time** decision — the `ai_worker_enabled` variable is evaluated during `ansible-playbook … --tags one_shot_bundle`. `install.sh` is rendered from `install.sh.j2` and can carry Jinja2 conditionals. `docker-compose.yml` is a **static file** (not rendered from a template); AI-worker inclusion is handled by selecting between two static source files at assembly time (see checklist). End users cannot toggle it post-assembly; they receive either the AI-enabled or AI-disabled bundle depending on which was built.

---

### `install.sh` prompt flow when `ai_worker_enabled: true`

After `install.sh` collects the existing password prompts (admin password, viewer password, MySQL password), it displays an informational message and asks a yes/no question before collecting the API key:

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

The entered value is written into `apache/externalConfigs/.env` as `OPENAI_API_KEY=<value>` and `AI_WORKER_ENABLED=true`.

**If the user answers anything else (default: No):**

The API key prompt is skipped entirely. `OPENAI_API_KEY` is never written to `.env` and `AI_WORKER_ENABLED=false` is patched into `.env` instead. The `ai-worker` **container will still start** — Docker Compose starts every service defined in the compose file unconditionally. The worker process must therefore check `AI_WORKER_ENABLED` at startup and exit gracefully when it is `false`. This is a required design constraint on the ai-worker entrypoint.

If `ai_worker_enabled: false` at assembly time, this entire block (informational message, yes/no prompt, and API key prompt) does not appear.

---

### Implementation notes for `install.sh.j2`

- Gate the entire AI block with `{% if ai_worker_enabled | default(false) | bool %} … {% endif %}` in `install.sh.j2`
- Inside the gate, display the informational message, then prompt `[y/N]` using `read -r`; default to `N` if the user presses Enter without typing
- Only prompt for the API key if the user answered `y`/`Y`/`yes`; use `read -rs` (silent, no echo) for the key itself
- Write the outcome using the correct tool for each key:
  - `AI_WORKER_ENABLED` is pre-rendered as `true` in the ai-enabled bundle's `.env` (via `.env.j2`); use `_patch_env_key AI_WORKER_ENABLED false "$APACHE_ENV_FILE"` on the **no path** to override it. On the yes path no patch is needed (already `true`).
  - `OPENAI_API_KEY` is **never** in the pre-rendered `.env` (it is user-provided and secret); `_patch_env_key` cannot add a new key, only replace one. On the **yes path**, append with `printf 'OPENAI_API_KEY=%s\n' "$OPENAI_API_KEY" >> "$APACHE_ENV_FILE"`. On the **no path**, do nothing — the key simply never appears in `.env`.
- Also export `GIGHIVE_AI_ASSETS_DIR` (alongside the existing `GIGHIVE_AUDIO_DIR` / `GIGHIVE_VIDEO_DIR` exports) only on the yes path, so Compose picks up the correct bind-mount path
- The prompt position — after passwords, before `docker compose up` — follows the existing `install.sh` flow; no structural changes to the script are needed

---

## Historical context: why the AI worker was not in v1

The one-shot bundle is a self-contained quickstart for the core GigHive application. The AI worker was excluded from v1 for three reasons:

1. **API key required** — every installation needs its own `OPENAI_API_KEY`; there is no safe default and no way to pre-configure it in a redistributable bundle.
2. **Image size and build time** — `python:3.11-slim` + `apt-get install ffmpeg` adds ~400–500 MB and several minutes to first-run Docker build, degrading the quickstart experience.
3. **Feature scope** — the AI tagger is an opt-in add-on. The bundle is gated by `AI_WORKER_ENABLED=false` so the PHP side is inert even if the env var leaks through; the worker container simply won't exist.

The `ai_worker_enabled` gate in group_vars resolves all three: the size/time trade-off is accepted by whoever sets `ai_worker_enabled: true` for a bundle build, and the API key is collected interactively by `install.sh` rather than pre-configured.

---

## Implementation Checklist

Each item below is annotated with the specific past bundle problem it addresses.

| Step | Task | Detail | Lesson from past problems |
|------|------|---------|--------------------------|
| 1 | Gate bundle output on `ai_worker_enabled` | **`monitor.yml`**: no `when:` wrapping on tasks — those tasks iterate over `one_shot_bundle_input_paths` in a single loop; a `when:` on the task would skip the whole loop, not just AI paths. Gate by conditionally including the ai-worker path in `one_shot_bundle_input_paths` itself (see next item). **`output_bundle.yml`**: the three dir-create/mode-normalize tasks need `_host_ai_assets` added to their loop lists using Jinja2 conditional concatenation (see `_host_ai_assets` item below); the docker-compose copy needs a conditional file-select task (see docker-compose item below). | Consistent with how other optional features are gated in the role; avoids the Ansible anti-pattern of `when:` on a loop task to skip individual items |
| 2 | Add `ai-worker/` to `one_shot_bundle_input_paths` when enabled | Use the **parent** dir `roles/ai_worker/files/ai-worker`, not a child path. Conditional inclusion in group_vars using Jinja2 list concatenation — do **not** just append the path unconditionally: `one_shot_bundle_input_paths: "{{ base_paths + ([repo_root ~ '/ansible/roles/ai_worker/files/ai-worker'] if ai_worker_enabled \| default(false) \| bool else []) }}"`. Also requires a new `_one_shot_bundle_ai_worker_prefix` variable and `elif` branches in both `monitor.yml` and `output_bundle.yml` path-mapping blocks (see structural item below). | `problem_one_shot_bundle_missing_tusd_hook.md` — child paths require re-adding entries when subdirs are added; parent paths are future-proof |
| 3 | Add `ai-worker` service to bundle `docker-compose.yml` | The bundle `docker-compose.yml` is a **static file** — Jinja2 conditionals cannot be used inside it. Correct approach: keep `roles/docker/files/one_shot_bundle/docker-compose.yml` as the base (no AI worker) and add `roles/docker/files/one_shot_bundle/docker-compose-ai.yml` as the AI-enabled variant. Add a conditional copy task in `output_bundle.yml` after the bulk copy step: `src: "docker-compose{{ '-ai' if ai_worker_enabled \| default(false) \| bool else '' }}.yml"` → `dest: docker-compose.yml`. This is idempotent — copying a static file is always safe to re-run. Do **not** use `blockinfile` or in-place text manipulation on the output file; that is brittle and not idempotent. | `output_bundle.yml` skips the `.j2` template and picks up the static file via the `one_shot_bundle_prefix` copy path |
| 4 | Create `_host_ai_assets/` bind-mount dir in `output_bundle.yml` | The three tasks (create dirs / normalize dir modes / normalize file modes) use a `loop:` list of `{path: ...}` items. Do **not** add `_host_ai_assets` unconditionally — that would create the dir even when `ai_worker_enabled: false`. Use Jinja2 conditional list concatenation on each loop: `loop: "{{ base_items + ([{'path': '_host_ai_assets'}] if ai_worker_enabled \| default(false) \| bool else []) }}"`. Mode must be `0777`; do **not** use `www-data` group or mode `2775`. | `problem_one_shot_bundle_fullbuild_maclinux_wwwdata_diffs.md` — `www-data` group does not exist on macOS/Windows; unconditional dir creation leaks AI artifacts into non-AI bundles |
| 5 | Add AI env vars and `OPENAI_API_KEY` prompt to `install.sh.j2` | See prompt flow design above; gate the entire block with `{% if ai_worker_enabled %}`. `AI_WORKER_ENABLED=true` is baked into `.env` at assembly time. | User experience gap — silent misconfiguration is the failure mode |
| 6 | Frame retention purge (if cron-based in the container) | Any cron job inside the `ai-worker` container that needs env vars must write them into the cron.d file at entrypoint time — Linux cron does not inherit the container process environment | `problem_one_shot_bundle_cron_fix.md` — `MYSQL_DATABASE` not visible to cron; fixed by writing vars via `printf` into `/etc/cron.d/` at entrypoint start |
| 7 | Verify execute bits on any shell scripts | Python scripts are fine (`python3 script.py`), but any helper `.sh` files must have `+x` committed; bundle assembly uses `mode: preserve` | `problem_one_shot_bundle_missing_tusd_hook.md` — `post-finish` hook had `664` mode; tusd could not execute it |
| 8 | Convert `instructions_quickstart.sh` to j2 and add AI worker step | `instructions_quickstart.sh` is currently a **static file** in `roles/docker/files/one_shot_bundle/` — it cannot carry Jinja2 conditionals. To support an AI-conditional section, it must be converted to `instructions_quickstart.sh.j2` and added to the template path-mapping in both `monitor.yml` and `output_bundle.yml`. The rendered output should include (when `ai_worker_enabled: true`) a note that the API key prompt will appear during `./install.sh` and where to obtain a key (`https://platform.openai.com/api-keys`). | User experience gap; static-file constraint mirrors the same issue as `docker-compose.yml` |

---

## Bundle `docker-compose.yml` — `ai-worker` service block

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
      - mysql
```

`GIGHIVE_AI_ASSETS_DIR` follows the same env-var-with-fallback convention as `GIGHIVE_VIDEO_DIR` and `GIGHIVE_AUDIO_DIR`, with `./_host_ai_assets` as the default.

---

## Non-trivial structural item: new source prefix in `monitor.yml` / `output_bundle.yml`

The bundle assembly role currently knows four source root prefixes:

| Variable | Source root |
|---|---|
| `_one_shot_bundle_one_shot_bundle_prefix` | `roles/docker/files/one_shot_bundle/` |
| `_one_shot_bundle_files_prefix` | `roles/docker/files/` |
| `_one_shot_bundle_templates_prefix` | `roles/docker/templates/` |
| `_one_shot_bundle_assets_prefix` | `assets/` |

The `ai-worker/` source lives under `ansible/roles/ai_worker/files/` — a **fifth prefix** not currently handled. Both `monitor.yml` and `output_bundle.yml` need a new `_one_shot_bundle_ai_worker_prefix` variable and corresponding Jinja2 `elif` branches in the dest-file mapping blocks to route those files to `ai-worker/` in the bundle output.
