# One-Shot Bundle: AI Worker File Flow & Install Scenarios

## File Flow: Ansible Full Build vs One-Shot Bundle

```
╔══════════════════════════════════════════════════════════════════════════════╗
║              SOURCE FILES (repo)                                             ║
╠═══════════════════════════════════╦══════════════════════════════════════════╣
║  ansible/roles/docker/templates/  ║  ansible/roles/docker/files/             ║
║                                   ║    one_shot_bundle/                      ║
║  docker-compose.yml.j2  ──────────╫───────── SKIPPED by bundle renderer     ║
║  .env.j2                          ║    docker-compose.yml  (static)          ║
║  install.sh.j2                    ║    docker-compose-ai.yml  (EXCLUDED)     ║
║  Dockerfile.j2                    ║                                          ║
║  entrypoint.sh.j2  etc.           ║  ansible/roles/ai_worker/                ║
║                                   ║    templates/                            ║
║  docker-compose-ai-worker.yml.j2  ║      docker-compose-ai-worker.yml.j2    ║
╚═══════════════════════════════════╩══════════════════════════════════════════╝
          │                                        │
          │                                        │
   ┌──────┴──────────────────┐    ┌────────────────┴────────────────────────┐
   │  REGULAR ANSIBLE BUILD  │    │  ONE-SHOT BUNDLE ASSEMBLY (site.yml)    │
   │  (site.yml → docker     │    │  (one_shot_bundle role)                 │
   │   role tasks)           │    └──────────┬──────────────────────────────┘
   └──────┬──────────────────┘               │
          │                           Ansible renders .j2 templates
          │  Ansible renders          + copies static files into
          │  all .j2 → server         /tmp/gighive-one-shot-bundle/
          │                                   │
          ▼                                   ▼
   ┌─────────────────────┐       ┌────────────────────────────┐
   │  SERVER             │       │  BUNDLE OUTPUT             │
   │  ~/gighive/         │       │  /tmp/gighive-one-shot-    │
   │                     │       │  bundle/                   │
   │  docker-compose.yml │       │                            │
   │  ← from .j2         │       │  docker-compose.yml        │
   │  (uses {{ vars }})  │       │  ← from static file        │
   │                     │       │  (uses ${SHELL_VARS})      │
   │  ai-worker/         │       │  + profiles: [ai] service  │
   │  docker-compose.yml │       │                            │
   │  ← from ai-worker   │       │  install.sh ← from .j2     │
   │    .j2 template     │       │  apache/ ← rendered .j2s  │
   │                     │       │  ai-worker/ ← static files │
   │  .env               │       │  .env ← pre-rendered .j2  │
   │  ← from .env.j2     │       │   (patched at install time)│
   └──────┬──────────────┘       └────────────┬───────────────┘
          │                                    │ tgz'd → user downloads
          │  docker compose up                 │
          │  (+ separate                       ▼
          │   ai-worker compose)      ┌────────────────────┐
          ▼                           │  USER MACHINE      │
   ┌─────────────┐                    │  ./install.sh      │
   │  containers │                    │  answers Yes/No    │
   │  running on │                    │  → patches .env    │
   │  server     │                    │  → docker compose  │
   └─────────────┘                    │  [--profile ai]    │
                                      │  up -d --build     │
                                      └────────────────────┘
```

### Key Differences

| | Regular Ansible Build | One-Shot Bundle |
|---|---|---|
| `docker-compose.yml` source | `docker-compose.yml.j2` rendered with `{{ ansible_vars }}` | Static file with `${SHELL_VARS}`, copied as-is |
| `docker-compose.yml.j2` | Rendered → live server | **Skipped** by bundle renderer |
| AI worker compose | Separate file from `docker-compose-ai-worker.yml.j2` | Embedded in `docker-compose.yml` under `profiles: [ai]` |
| Variable context | Ansible inventory vars | Shell env vars exported by `install.sh` |

---

## Install Scenarios

### Scenario: No (`ai_worker_osb_enabled=true`, user selects N)

| Step | What happens |
|---|---|
| Bundle assembly | `output_bundle.yml` copies base `docker-compose.yml` (with `profiles: [ai]` service); `ai-worker/` files included |
| install.sh | `COMPOSE_PROFILE_ARG=()` initialized; `if ai_worker_osb_enabled` block not rendered — no AI prompt |
| User selects N | `*` branch: patches `AI_WORKER_ENABLED=false` in `.env`; array stays empty |
| Compose up | `docker compose up -d --build` — no `--profile ai`; ai-worker service ignored by Compose |
| **Result** | **3 containers: apacheWebServer, tusd, mysqlServer** |

### Scenario: Yes (`ai_worker_osb_enabled=true`, user selects Y)

| Step | What happens |
|---|---|
| Bundle assembly | Same `docker-compose.yml`; `ai-worker/` files present; `_host_ai_assets/` dir created |
| install.sh | `COMPOSE_PROFILE_ARG=()` initialized |
| User selects Y | Patches `AI_WORKER_ENABLED=true` and `OPENAI_API_KEY`; exports `GIGHIVE_AI_ASSETS_DIR`; sets `COMPOSE_PROFILE_ARG=(--profile ai)` |
| Compose up | `docker compose --profile ai up -d --build` — `ai` profile active; builds `./ai-worker/` |
| **Result** | **4 containers: apacheWebServer, tusd, mysqlServer, ai-worker** |

### Scenario: `ai_worker_osb_enabled=false` (no AI prompt rendered)

| Step | What happens |
|---|---|
| Bundle assembly | Same `docker-compose.yml` shipped (phantom `profiles: [ai]` entry, but harmless); `ai-worker/` files **not** included |
| install.sh | `COMPOSE_PROFILE_ARG=()` initialized; `{% if ai_worker_osb_enabled %}` block not rendered — no AI prompt |
| Compose up | `docker compose up -d --build` — profile never activated; `./ai-worker/` never referenced |
| **Result** | **3 containers: apacheWebServer, tusd, mysqlServer** |

---

## Relevant Files

| File | Role |
|---|---|
| `ansible/roles/docker/files/one_shot_bundle/docker-compose.yml` | Static bundle compose file; ai-worker defined under `profiles: [ai]` |
| `ansible/roles/docker/files/one_shot_bundle/docker-compose-ai.yml` | Legacy standalone ai compose file; excluded from bundle via `one_shot_bundle_exclude_source_paths` |
| `ansible/roles/docker/templates/install.sh.j2` | Bundle install script template; controls `COMPOSE_PROFILE_ARG` based on user Yes/No |
| `ansible/roles/one_shot_bundle/tasks/output_bundle.yml` | Bundle assembly; "Select docker-compose.yml variant" task always copies base `docker-compose.yml` |
| `ansible/roles/docker/templates/docker-compose.yml.j2` | Ansible-deployed compose (server); uses `{{ ansible_vars }}`; skipped by bundle renderer |
| `ansible/roles/ai_worker/templates/docker-compose-ai-worker.yml.j2` | Ansible-deployed ai-worker compose (server only); not used by bundle |
| `ansible/inventories/group_vars/gighive/gighive.yml` | `ai_worker_osb_enabled: true` for lab/staging; controls AI prompt rendering and `ai-worker/` inclusion |

---

## Known Limitation

When `ai_worker_osb_enabled=false`, the shipped `docker-compose.yml` still contains the `ai-worker` service definition under `profiles: [ai]` since it is a static file shared across both configurations. The `./ai-worker/` build context will not exist in those bundles, but this is harmless as the profile is never activated. A future improvement would be to generate `docker-compose.yml` from a bundle-specific `.j2` template to conditionally omit the service entirely.
