# Upload Tests for the One-Shot Bundle

## Goal

Enable `ansible/roles/upload_tests` to run against a locally running one-shot bundle,
exercising the same test variants that run against the full build, from the developer's
machine without modifying the role's task files.

---

## How the bundle is built (context that matters)

```
script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive.yml \
  ansible/playbooks/site.yml \
  --tags set_targets,one_shot_bundle,one_shot_bundle_archive --diff" \
  ansible-playbook-gighive-bundle-$(date +%Y%m%d).log
```

The bundle role (`ansible/roles/one_shot_bundle`) runs under `inventory_gighive.yml`,
which puts the `gighive_vm` host in the `gighive` group. That group inherits
`ansible/inventories/group_vars/gighive/gighive.yml`. The bundle's `.env`, htpasswd,
container names, DB vars, and default credentials are all rendered from those group_vars
at build time.

Key consequence: the developer's dev machine — where they run the bundle — is the same
machine that has the full repo. The `group_vars/gighive/gighive.yml` var space that
built the bundle is still available on the controller.

---

## Why `upload_tests` can't target the bundle via the existing `site.yml` play

`site.yml` runs upload_tests against a configured inventory host (gighive2, prod, etc.),
which is a remote VM running Docker. For a locally-running bundle there is no remote VM
— the Docker containers are on `localhost`.

The four specific blockers:

| # | Blocker | Notes |
|---|---------|-------|
| 1 | `community.docker.docker_container_exec` has no `delegate_to: localhost` | Runs on the Ansible target host; for full build that is the remote VM |
| 2 | `gighive_base_url` resolves to the remote VM's IP | Bundle listens on `localhost` |
| 3 | `upload_media_by_hash.py` path anchored to `{{ repo_root }}/...` | Needs `repo_root` correct; SSH target assumes remote host |
| 4 | No inventory entry / group_vars load for `localhost` as a test target | `baremetal` host in `inventory_gighive.yml` is localhost but not in `gighive` group |

---

## What the gighive group_vars context gives us for free

Because the bundle was built from `group_vars/gighive/gighive.yml`, most blocker surface
area collapses when we target `baremetal` (localhost) with those vars loaded:

| Concern | Value in gighive.yml | Bundle value | Match? |
|---------|---------------------|--------------|--------|
| `apache_container_name` | `apacheWebServer` | `apacheWebServer` | ✓ |
| `mysql_container_name` | `mysqlServer` | `mysqlServer` | ✓ |
| `mysql_database` | `music_db` | `music_db` | ✓ |
| `mysql_user` | `appuser` | `appuser` | ✓ |
| `mysql_appuser_password` | from secrets | `MYSQL_PASSWORD` in bundle .env | ⚠ see note |
| `gighive_admin_password` | from secrets (same used to build bundle htpasswd) | seeded in `gighive.htpasswd` | ✓ |
| `gighive_scheme` | `https` | HTTPS on 443 | ✓ |
| `gighive_host` | `{{ ansible_host }}` → `localhost` for baremetal | `localhost` | ✓ automatic |
| `gighive_base_url` | `{{ gighive_scheme }}://{{ gighive_host }}` → `https://localhost` | `https://localhost` | ✓ automatic |
| `upload_tests_csv_dir` | `{{ repo_root }}/ansible/fixtures/upload_tests/csv` | on dev machine | ✓ (full repo present) |
| `upload_test_direct_upload_fixture` | `{{ audio_reduced }}/007e...mp3` | in `assets/audio/` | ✓ |
| `upload_test_run_upload_media_by_hash` | `false` | n/a (disabled) | ✓ no SSH needed |

**⚠ `mysql_appuser_password` note:** The bundle's `MYSQL_PASSWORD` in its `.env` may
differ from what is in `group_vars/gighive/secrets.yml`. Pass it explicitly at runtime:

```bash
-e "mysql_appuser_password=<bundle_mysql_password>"
```

**Blocker 1** (docker_container_exec) resolves automatically: when the Ansible play
targets `baremetal` (`ansible_connection: local`), all tasks including
`community.docker.docker_container_exec` run on `localhost` and hit the local Docker
daemon — which is exactly where the bundle containers are running.

**Blocker 2** (`gighive_base_url`) resolves automatically: `ansible_host: localhost` for
`baremetal`, so `gighive_base_url` evaluates to `https://localhost` with no override.

**Blocker 3** (`upload_media_by_hash`) is already suppressed: `upload_test_run_upload_media_by_hash: false`
in gighive.yml, so the SSH-dependent step is skipped entirely.

**Blocker 4** is the only gap that needs a new artifact.

---

## Remaining gap — only one thing needed

The upload_tests role is only invoked from `site.yml` targeting a configured remote host.
There is no playbook that:
- Targets `baremetal` (localhost)
- Loads `group_vars/gighive/gighive.yml` vars
- Enables upload_tests with the right bundle-appropriate variant list

---

## Solution: `ansible/playbooks/upload_tests_bundle.yml`

A new playbook targeting the `baremetal` host from `inventory_gighive.yml`. It loads
gighive group_vars via `vars_files` and overrides run-control variables via a
`pre_tasks: set_fact` block.

**Important:** overrides must be in `pre_tasks: set_fact`, not in `vars:`. Ansible's
variable precedence causes `vars_files` values to win over `vars:` values from the same
play, so a `set_fact` in `pre_tasks` (which has higher precedence) is required.

```yaml
---
- name: Upload tests against locally-running one-shot bundle
  hosts: baremetal
  gather_facts: true
  vars_files:
    - "{{ playbook_dir }}/../inventories/group_vars/gighive/gighive.yml"
    - "{{ playbook_dir }}/../inventories/group_vars/gighive/secrets.yml"

  pre_tasks:
    - name: Override vars for bundle test context
      ansible.builtin.set_fact:
        run_upload_tests: true
        allow_destructive: true
        upload_test_restore_db_after: true
        upload_test_variants:
          - { name: "3a_legacy_import_gighive",     section: "3a", app_flavor: "gighive" }
          - { name: "6_direct_upload_api",          section: "6" }
          - { name: "7_tus_finalize",               section: "7" }
          - { name: "3b_normalized_import_gighive", section: "3b", app_flavor: "gighive" }
      tags: always

  roles:
    - role: upload_tests
      tags: [ upload_tests ]
      when: run_upload_tests | bool
```

### Why test 6 and 7 must come before 3B

The full build runs `3b_normalized_import_gighive` followed by
`3b_normalized_import_defaultcodebase`. The second 3B does `TRUNCATE TABLE files` and
reloads from `session_filesLarge.csv`, which does not contain the test 6 or test 7
fixture checksums. By the time test 6 runs on the full build, those checksums are gone.

The bundle only has one 3B. `session_filesSmall.csv` (used by `3b_normalized_import_gighive`)
contains the exact `checksum_sha256` values for both the test 6 fixture (`007e8780...mp3`)
and the test 7 fixture (`1982d302...mp3`). Running test 6 or 7 after 3B causes
`/api/uploads` to return HTTP 409 "Duplicate Upload" for those files.

After 3A (`import_database.php`), `checksum_sha256` is NULL for all file records.
`mysqlPrep_full.py` processes `databaseSmall.csv`, which contains original filenames
(e.g. `20050303_8.mp3`). The audio directory stores files by SHA256 hash name
(`007e8780...mp3`), so `mysqlPrep_full.py` cannot match them — checksums stay NULL.

Running tests 6 and 7 before 3B means they execute while checksums are NULL → no
duplicate detected → uploads succeed. 3B then runs last, truncating and restoring
the normalized state with proper checksums as the final DB state.

See also: `docs/process_upload_tests_bundle.md` for a concise summary.

### How to run

```bash
ansible-playbook \
  -i ansible/inventories/inventory_gighive.yml \
  ansible/playbooks/upload_tests_bundle.yml \
  --tags upload_tests \
  -e "mysql_appuser_password=<bundle_mysql_password>"
```

Prerequisites:
- Bundle running: `docker compose up -d` from the bundle directory
- `group_vars/gighive/secrets.yml` present with `gighive_admin_password` matching
  the bundle's htpasswd (they match when secrets.yml was used to build the bundle)
- `mysql_appuser_password` passed as `-e` if it differs from secrets.yml
- Current user in `docker` group (needed for `docker_container_exec` without sudo)
- No media file cleanup needed in `_host_audio/` or `_host_video/`

---

## No changes needed to `ansible/roles/upload_tests` task files

All task files work as-is under this approach:

- `query_db_counts.yml` / `assert_db_invariants.yml` / `derive_expected_files_from_prepped_csv.yml` —
  `community.docker.docker_container_exec` targets local Docker daemon automatically
- `test_3a.yml` / `test_3b.yml` — curl runs `delegate_to: localhost`; CSV fixture path resolves
- `test_6.yml` — curl runs `delegate_to: localhost`; fixture MP3 on dev machine
- `test_7.yml` — curl/shell run `delegate_to: localhost`; TUS fixture MP3 on dev machine
- `run_upload_media_by_hash.yml` — skipped (`upload_test_run_upload_media_by_hash: false`)

---

## Separate playbook vs integrating into site.yml

The question of adding a second play to `site.yml` vs a new file was considered and
rejected. Key reasons:

| | **Separate playbook** | **Add play to site.yml** |
|---|---|---|
| **Tag safety** | No interaction with existing `upload_tests` tag | `upload_tests` already used on line 119; new play needs a different tag — two tags for one role is confusing |
| **Preflight SSH check** | Not present — clean run | `tags: always` on the first play fires unconditionally; SSH key check runs even for a localhost test |
| **Concern mixing** | Dedicated: "test locally running bundle" | site.yml covers VM provisioning, Docker deploy, DB migrations, bundle build — adding "test local bundle" is out of scope |
| **vars_files pattern** | Self-contained to one file | Introduces `vars_files` to site.yml, which currently has none — new pattern for the codebase |

**Decision: keep the separate playbook.**

---

## Run topology

- **192.168.1.235** = Pop!_OS host / dev machine — where `ansible-playbook` is invoked
- **192.168.1.248** = VirtualBox VM — not involved in bundle testing
- The bundle runs on 192.168.1.235 via `docker compose`

`ansible_connection: local` means all tasks — including `community.docker.docker_container_exec`
— execute on 192.168.1.235. That module connects to `/var/run/docker.sock` on localhost,
which is where the bundle containers are. `gighive_base_url` resolves to `https://localhost`
(from `gighive_scheme://ansible_host`), which hits the container's port 443 bound to
`0.0.0.0`. No remote connection involved at any step.

### Sanity check before first run

Confirm `gighive_admin_password` in `group_vars/gighive/secrets.yml` matches the `admin`
entry in the running bundle's htpasswd:

```bash
docker exec apacheWebServer cat /var/www/private/gighive.htpasswd
```

---

## Files touched

| File | Change |
|------|--------|
| `ansible/playbooks/upload_tests_bundle.yml` | **new** — playbook targeting baremetal/localhost |
| `ansible/inventories/group_vars/gighive/gighive.yml` | added `upload_test_tus_fixture` |
| `ansible/inventories/group_vars/prod/prod.yml` | added `upload_test_tus_fixture` (sync) |
| `ansible/roles/docker/templates/install.sh.j2` | htpasswd ownership fix (www-data) |
| `ansible/roles/upload_tests/tasks/*` | **none** — no changes required |
