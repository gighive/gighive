# Feature: Playwright Admin Tests — Ansible Role

## Problem

The Playwright admin-page regression test (`tests/admin-pages.spec.ts`) lives outside
`ansible/roles/`, making it easy to overlook during a deployment cycle. The test's
credentials are maintained manually in a gitignored `tests/.env`, creating drift risk.

## Goal

Wrap the existing Playwright test in an Ansible role (`playwright_admin_tests`) so it
can be kicked off via `site.yml` (or a standalone playbook) under the same gate pattern
used by `upload_tests`. Test files are maintained under the role; repo-root copies are
kept for manual reference. Credentials are derived from group_vars/secrets.yml — no
manual `.env` maintenance required.

---

## Files at a Glance

### Role files (new)

| File | Function |
|------|----------|
| `ansible/roles/playwright_admin_tests/tasks/main.yml` | 9-step orchestration: assert guards → media prep → sync → render `.env` → npm ci → playwright install → run tests → assert pass |
| `ansible/roles/playwright_admin_tests/templates/tests_env.j2` | Jinja2 template — generates `tests/.env` from group_vars/secrets.yml; no manual credential management |
| `ansible/roles/playwright_admin_tests/files/playwright.config.ts` | Playwright config — testDir, baseURL, HTTP basic auth, headless mode, 60s timeout, dotenv loader |
| `ansible/roles/playwright_admin_tests/files/package.json` | npm project manifest — declares `@playwright/test` dependency |
| `ansible/roles/playwright_admin_tests/files/package-lock.json` | Lockfile — ensures reproducible `npm ci` installs |
| `ansible/roles/playwright_admin_tests/files/.nvmrc` | Pins Node version to 20 |
| `ansible/roles/playwright_admin_tests/files/howTo.sh` | Manual re-run helper — sources nvm, runs `npx playwright test` from work dir |
| `ansible/roles/playwright_admin_tests/files/tests/admin-pages.spec.ts` | 12-step Playwright test spec; one line edited to support `REPO_ROOT` env var for CSV/asset path resolution |

### Existing files changed

| File | Change |
|------|--------|
| `ansible/playbooks/site.yml` | Add role entry after `upload_tests`, gated by `run_playwright_admin_tests` |
| `ansible/playbooks/test_admin_pages.yml` | New standalone playbook for on-demand test runs |
| `ansible/inventories/group_vars/gighive2/gighive2.yml` | Add `run_playwright_admin_tests`, `playwright_work_dir`, `playwright_media_folder` |
| `ansible/inventories/group_vars/gighive/gighive.yml` | Same three vars (required for defined-variable safety; `allow_destructive: false` prevents actual execution) |
| `ansible/inventories/group_vars/prod/prod.yml` | Same three vars; gate var locked `false` |

### Repo-root files (unchanged — kept for manual reference)

| File | Note |
|------|------|
| `tests/admin-pages.spec.ts` | Original spec — unchanged, usable via `tests/howTo.sh` |
| `playwright.config.ts` | Original config — unchanged |
| `package.json` / `package-lock.json` / `.nvmrc` | Original npm files — unchanged |
| `tests/.env` | Manual credential file (gitignored) — unchanged, used for manual runs |
| `tests/howTo.sh` | Original manual run instructions — unchanged |

---

## Existing Test Coverage (for reference)

The 12-step serial test in `tests/admin-pages.spec.ts` covers all four admin pages:

| Step | Page | Action |
|------|------|--------|
| 1 | `admin/admin.php` | Password reset → redirect assert |
| 2 | `admin/admin_system.php` | Export media to ZIP |
| 3 | `admin/admin_system.php` | Write disk resize request (full channel only) |
| 4 | `admin/admin_system.php` | Clear all media data |
| 5 | `admin/admin_system.php` | Restore DB from backup |
| 6 | `admin/admin_system.php` | Clear all media data (again, post-restore) |
| 7 | `admin/admin_system.php` | Delete all media files from disk |
| 8 | `admin/admin_database_load_import_media_from_folder.php` | Add-to-DB folder upload |
| 9 | `admin/admin_database_load_import_media_from_folder.php` | Single-file upload utility tab |
| 10 | `admin/admin_database_load_import_media_from_folder.php` | Reload-DB folder upload |
| 11 | `admin/admin_database_load_import_csv.php` | Legacy single-CSV import |
| 12 | `admin/admin_database_load_import_csv.php` | Normalized CSV import (final state) |

---

## Design Decisions

- **Copy, don't move** — repo-root files (`tests/`, `playwright.config.ts`, `package.json`,
  etc.) remain in place for manual runs. The role has its own independent copies under `files/`.
- **Working directory** — role syncs `files/` to `{{ playwright_work_dir }}` at runtime.
  `node_modules/` never enters the repo.
- **Credentials** — role renders `tests/.env` from a Jinja2 template using group_vars /
  secrets.yml. The existing `tests/.env` at repo root is untouched (manual-run fallback).
- **Idempotent password step** — `TEST_ADMIN_PW = gighive_admin_password` so step 1 of
  the test "changes" the password to its current value — no cleanup needed.
- **Runs on controller** — all tasks use `delegate_to: localhost, become: false`. Playwright
  hits the live app URL. Same pattern as `upload_tests` URI tasks.
- **Gate var** — `run_playwright_admin_tests: false` keeps it off by default, matching
  `run_upload_tests` convention.
- **Destructive tests** — steps 4, 6, 7 clear all media data and delete all media files
  from disk on the target. The role asserts `allow_destructive: true` before proceeding.
  This var is `true` only in `gighive2` (dev); it is `false` in `gighive` (lab/staging)
  and `prod`, providing an automatic hard stop against accidental data loss.

---

## New Role Structure

```
ansible/roles/playwright_admin_tests/
  tasks/
    main.yml
  templates/
    tests_env.j2
  files/
    playwright.config.ts       <- copy of repo-root version (no edits needed)
    package.json               <- copy
    package-lock.json          <- copy
    .nvmrc                     <- copy (Node 20)
    howTo.sh                   <- copy of tests/howTo.sh
    tests/
      admin-pages.spec.ts      <- copy with one line edited (REPO_ROOT, see below)
```

`files/` mirrors the original directory layout exactly so `playwright.config.ts` needs
no changes — `testDir: './tests'` and `dotenv({ path: 'tests/.env' })` resolve correctly
relative to itself.

---

## tasks/main.yml outline

All tasks: `delegate_to: localhost, become: false`.

1. **Assert `allow_destructive`** — fail immediately with a clear message if
   `allow_destructive | default(false) | bool` is not `true`. The tests wipe all media
   data from the target; this guard prevents accidental runs against staging or prod.
2. **Assert Node 20+** — `ansible.builtin.command: node --version`; assert major version
   `>= 20` via Jinja2 `regex_replace` (no shell pipe); fail with helpful message if below 20.
3. **Prepare media dir** — create `{{ playwright_media_folder }}`; use `ansible.builtin.find`
   to locate `*.mp4` and `*.mp3` under `{{ repo_root }}/assets/`; copy each result via
   `ansible.builtin.copy` loop (no shell glob).
4. **Sync role files** — `ansible.builtin.copy` / `synchronize`
   `{{ role_path }}/files/` → `{{ playwright_work_dir }}/`.
5. **Render `.env`** — template `tests_env.j2` → `{{ playwright_work_dir }}/tests/.env`.
6. **`npm ci`** — `chdir: {{ playwright_work_dir }}` so npm finds `package.json` and
   writes `node_modules/` in the correct location.
7. **Install Chromium** — `npx playwright install chromium` (idempotent); `chdir: {{ playwright_work_dir }}`
   so npx resolves against the local `node_modules/.bin/playwright` just installed. Omit
   `--with-deps` since that flag installs OS-level system packages and requires `sudo`;
   the controller is a known developer machine where OS deps are already present. If
   ever running on a fresh controller OS, run `npx playwright install --with-deps chromium`
   once manually before using the role.
8. **Run tests** — `npx playwright test`; `chdir: {{ playwright_work_dir }}` so Playwright
   finds `playwright.config.ts`; register result, do not `failed_when` immediately.
9. **Assert pass** — fail with path to
   `{{ playwright_work_dir }}/playwright-report/index.html` if exit code ≠ 0.

---

## templates/tests_env.j2

```
ADMIN_URL={{ gighive_base_url }}
ADMIN_USER={{ admin_user }}
ADMIN_PASS={{ gighive_admin_password }}
TEST_ADMIN_PW={{ gighive_admin_password }}
TEST_VIEWER_PW={{ gighive_viewer_password }}
TEST_UPLOADER_PW={{ gighive_uploader_password }}
MEDIA_FOLDER={{ playwright_media_folder }}
REPO_ROOT={{ repo_root }}
```

All three password vars used in the template (`gighive_admin_password`,
`gighive_viewer_password`, `gighive_uploader_password`) are confirmed present in all
environments' `secrets.yml` files. `admin_user` is confirmed in all environments'
group_vars as the literal value `admin`. `repo_root` is defined in `group_vars/all.yml`
as `{{ (playbook_dir | dirname | dirname) }}` and resolves correctly for any playbook
under `ansible/playbooks/` — including the standalone playbook.

---

## Edit to copied spec (`files/tests/admin-pages.spec.ts`)

Change one line so CSV/asset paths resolve correctly when running from `/tmp/`:

```typescript
// Before:
const REPO = path.resolve(__dirname, '..');

// After:
const REPO = process.env.REPO_ROOT ?? path.resolve(__dirname, '..');
```

The fallback (`path.resolve(__dirname, '..')`) keeps manual runs from the repo-root
`tests/` directory working without any changes.

---

## Changes to Existing Ansible Files

### `ansible/playbooks/site.yml`

Add after the `upload_tests` role entry:

```yaml
- role: playwright_admin_tests
  tags: [ playwright_admin_tests ]
  when: run_playwright_admin_tests | default(false) | bool
```

### `ansible/inventories/group_vars/gighive2/gighive2.yml` (dev)

Add near the `run_upload_tests` block:

```yaml
run_playwright_admin_tests: false
playwright_work_dir: /tmp/gighive-playwright
playwright_media_folder: /tmp/gighive-media
```

### `ansible/inventories/group_vars/gighive/gighive.yml` (lab/staging)

Add the same three vars (required — the standalone lab/staging run command uses this
inventory and the role will fail with undefined variable errors if they are absent):

```yaml
run_playwright_admin_tests: false
playwright_work_dir: /tmp/gighive-playwright
playwright_media_folder: /tmp/gighive-media
```

### `ansible/inventories/group_vars/prod/prod.yml` (production)

Add the same three vars near the `run_upload_tests` block. Gate var stays `false` —
Playwright tests should not run against production automatically:

```yaml
run_playwright_admin_tests: false
playwright_work_dir: /tmp/gighive-playwright
playwright_media_folder: /tmp/gighive-media
```

---

## Standalone Playbook

`ansible/playbooks/test_admin_pages.yml` — the preferred invocation for on-demand test
runs. Runs only the `playwright_admin_tests` role without executing any deployment roles.
`repo_root` resolves correctly via `group_vars/all.yml` for any playbook under
`ansible/playbooks/`.

```yaml
---
- name: Run Playwright admin page regression tests
  hosts: target_vms
  gather_facts: false
  roles:
    - role: playwright_admin_tests
      tags: [ playwright_admin_tests ]
```

---

## Regular site.yml push behaviour

The role will **not** run during a regular push. The `when: run_playwright_admin_tests | default(false) | bool`
gate prevents execution regardless of whether the tag appears in `--skip-tags`.

A typical regular push (e.g.):
```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml \
  --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests" \
  ansible-playbook-gighive2-20260618.log
```
does not need `playwright_admin_tests` in `--skip-tags` because the gate var already
suppresses it. However, it is good practice to add it for explicit clarity and
self-documentation, consistent with how `upload_tests` and others are handled:

```bash
--skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests
```

To run the tests, use one of two options:

1. **Standalone (preferred for on-demand runs)** — skips all deployment roles, runs only
   the tests:
   ```bash
   ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/test_admin_pages.yml
   ```

2. **Wired into a full `site.yml` run** — set `run_playwright_admin_tests: true` in
   group_vars (or override inline with `-e run_playwright_admin_tests=true`) and omit
   the tag from `--skip-tags`. Tests run at the end of the full deployment, which is the
   intended use for post-deploy validation.

---

## Run commands (from repo root)

**Standalone playbook — dev (gighive2):**
```bash
ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/test_admin_pages.yml
```

**Via `site.yml` (tag-only, override gate var inline):**
```bash
ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml \
  --tags playwright_admin_tests \
  -e run_playwright_admin_tests=true
```

> The standalone playbook is preferred for on-demand test runs — it skips all deployment
> roles and runs only the tests. The `site.yml` tag-only form is useful when you want
> the tests wired into a full build run; set `run_playwright_admin_tests: true` in the
> relevant group_vars file instead of relying on `-e`.

---

## Status

- [x] Implement role files
- [ ] Test run against dev (gighive2)
