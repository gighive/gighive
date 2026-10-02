# Refactor: Rename Database `music_db` → `media_db`

## Motivation

`music_db` reflects the original musician/band capture use case. The SaaS model changes
(`docs/feature_saas_model_changes.md`) broaden the platform to multiple verticals (weddings,
sports, etc.), making "music" a misnomer. `media_db` is neutral and accurate.

Note: The CHANGELOG already carried this as a to-do item:
> `DB: rename create_music_db.sql to create_db.sql`

This refactor supersedes that note with a more complete and named target (`media_db`).

---

## Impact Summary

**83 total files** contain `music_db` in the repository. The vast majority are log files,
docs, and CHANGELOG history — which are **read-only artifacts and do not need to change**.

Functional files requiring changes: **27 code/config files**, grouped below.

---

## Files Requiring Changes

| Group | Files | Nature |
|-------|-------|--------|
| **1. Ansible group vars** | 3 | `mysql_database: music_db` — single source of truth; change here propagates via templates |
| **2. SQL DDL** | 4 | `create_music_db.sql` needs **file rename** + internal SQL; `load_and_transform.sql`, `dropDb.sql`, `validation.sql` have `USE`/`DROP` statements |
| **3. Ansible templates** | 4 | Hardcoded fallback defaults + docker-compose volume mount path references the SQL filename |
| **4. OSB docker-compose static files** | 2 | Static (non-templated) volume mount paths reference the SQL filename |
| **5. Shell scripts** | 4 | `dbDump.sh` hardcodes DB name; others reference the SQL filename |
| **6. PHP app defaults** | 4 | `?: 'music_db'` fallbacks (unreachable in normal operation, but should be consistent) |
| **7. Python tools** | 3 + 1 sh | `add_jam_session.py` has hardcoded `USE` + exec command; others are CLI defaults |
| **8. Ansible role tasks** | 1 functional | `validate_app` hardcodes `-D music_db` |
| **9. Python worker defaults** | 2 | AI worker and MCP server fallback defaults |

---

### 1. Ansible Group Vars — Primary source of truth (3 files)

These define `mysql_database` which propagates through templates into `.env`.
Changing these is the single authoritative rename.

| File | Line | Change |
|------|------|--------|
| `ansible/inventories/group_vars/gighive2/gighive2.yml` | 378 | `mysql_database: music_db` → `media_db` |
| `ansible/inventories/group_vars/gighive/gighive.yml` | 378 | `mysql_database: music_db` → `media_db` |
| `ansible/inventories/group_vars/prod/prod.yml` | 380 | `mysql_database: music_db` → `media_db` |

---

### 2. SQL DDL — Database initialization (4 files, 1 rename)

The primary schema file also needs to be **renamed** from `create_music_db.sql` →
`create_media_db.sql`. All downstream mount-path references (groups 3 and 4) depend on
this rename.

| File | Change |
|------|--------|
| `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql` | **Rename file** to `create_media_db.sql`; update 3 internal references (`DROP DATABASE IF EXISTS`, `CREATE DATABASE`, `USE`) |
| `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql` | `USE music_db;` → `USE media_db;` |
| `ansible/roles/docker/files/mysql/dbScripts/dropDb.sql` | `DROP DATABASE IF EXISTS music_db;` → `media_db` |
| `ansible/roles/docker/files/mysql/dbScripts/validation.sql` | `USE music_db;` → `USE media_db;` |

---

### 3. Ansible Templates — Hardcoded fallback defaults (4 files)

These use `MYSQL_DATABASE` env var at runtime but fall back to `'music_db'` if unset.
The defaults should be updated to match. The `docker-compose.yml.j2` also embeds the
old SQL filename in a volume mount path.

| File | Line | Change |
|------|------|--------|
| `ansible/roles/docker/templates/.env.j2` | 5 | `\| default('music_db')` → `'media_db'` |
| `ansible/roles/docker/templates/docker-compose.yml.j2` | 85 | `create_music_db.sql` → `create_media_db.sql` (volume mount path) |
| `ansible/roles/docker/templates/install.sh.j2` | 36 | `MYSQL_DATABASE:-music_db` → `media_db` |
| `ansible/roles/docker/templates/install.ps1.j2` | 30 | `else { "music_db" }` → `"media_db"` |

---

### 4. One-Shot Bundle Docker Compose Static Files (2 files)

These are static (non-templated) docker-compose files in the OSB and embed the SQL
filename directly in volume mount paths.

| File | Line | Change |
|------|------|--------|
| `ansible/roles/docker/files/one_shot_bundle/docker-compose.yml` | 81 | `create_music_db.sql` → `create_media_db.sql` |
| `ansible/roles/docker/files/one_shot_bundle/docker-compose-ai.yml` | 81 | `create_music_db.sql` → `create_media_db.sql` |

---

### 5. Shell Scripts — DB name hardcoded or SQL filename referenced (3 files)

| File | Line | Change |
|------|------|--------|
| `ansible/roles/docker/files/mysql/dbScripts/dbDump.sh` | 5 | `DB=music_db` → `DB=media_db` (hardcoded, not using env var) |
| `ansible/roles/docker/files/mysql/dbScripts/dbCommands.sh` | 19, 22 | `create_music_db.sql` → `create_media_db.sql` (filename ref in cp/exec) |
| `ansible/roles/docker/files/mysql/dbScripts/reloadMyDatabase.sh` | 10 | `create_music_db.sql` → `create_media_db.sql` (filename ref in exec) |

---

### 6. PHP Application Code — Fallback defaults (4 files)

These read `MYSQL_DATABASE` from env; the `'music_db'` is an unreachable fallback in
normal operation (env is always set). Still update for correctness.

| File | Line | Change |
|------|------|--------|
| `ansible/roles/docker/files/apache/webroot/src/Infrastructure/Database.php` | 22 | `?: 'music_db'` → `'media_db'` |
| `ansible/roles/docker/files/apache/webroot/db/delete_media_files.php` | 123 | `?: 'music_db'` → `'media_db'` |
| `ansible/roles/docker/files/apache/webroot/admin/import_database.php` | 172 | `?: 'music_db'` → `'media_db'` |
| `ansible/roles/docker/files/apache/webroot/admin/import_normalized.php` | 190 | `?: 'music_db'` → `'media_db'` |

---

### 7. Python Tools — Hardcoded or default fallback (3 files + 1 shell example)

`add_jam_session.py` is the only tool with a **hardcoded** database name in generated SQL
and in the ssh/docker exec command. The others use env var fallback defaults or CLI defaults.

| File | Line | Change |
|------|------|--------|
| `ansible/roles/docker/files/apache/webroot/tools/add_jam_session.py` | 305 | `"USE music_db;"` → `"USE media_db;"` (hardcoded in generated SQL block) |
| `ansible/roles/docker/files/apache/webroot/tools/add_jam_session.py` | 420 | `mysql -u appuser music_db` → `media_db` (hardcoded in ssh exec command) |
| `ansible/roles/docker/files/apache/webroot/tools/upload_media_by_hash.py` | 12, 687 | Comment + `--db-name` default `"music_db"` → `"media_db"` |
| `ansible/roles/docker/files/apache/webroot/tools/replace_existing_media.py` | 325 | `--db-name` default `"music_db"` → `"media_db"` |
| `ansible/roles/docker/files/apache/webroot/tools/uploadMediaByHashExample.sh` | 6 | `--db-name music_db` → `media_db` |

---

### 8. Ansible Role Tasks — Hardcoded DB name (1 functional)

| File | Line | Change |
|------|------|--------|
| `ansible/roles/validate_app/tasks/main.yml` | 48 | `-D music_db` → `-D media_db` (hardcoded in shell command) |

---

### 9. Python Worker Defaults — AI worker and MCP server (2 files)

| File | Line | Change |
|------|------|--------|
| `ansible/roles/ai_worker/files/ai-worker/db.py` | 18 | `os.getenv('MYSQL_DATABASE', 'music_db')` → `'media_db'` |
| `ansible/roles/mcp_server/files/mcp-server/db.py` | 23 | `os.getenv('MYSQL_DATABASE', 'music_db')` → `'media_db'` |

---

## Files NOT Requiring Changes (Excluded)

- **Log files** (`ansible-playbook-*.log`) — historical artifacts
- **CHANGELOG.md** — historical record; do not rewrite history
- **All `docs/*.md`** — historical documentation; do not rewrite
- **`user-prompts.md`** — historical record
- `ansible/roles/db_migrations/tasks/main.yml` line 24 — comment only
- `ansible/roles/docker/files/mysql/dbScripts/backupSanity.sh` — example filename in comment; not referenced by any other script

---

## Risk Mitigation

**The env var is the real gate.** `MYSQL_DATABASE` in `.env` controls what the running
containers connect to — not the hardcoded defaults in PHP/Python (those fallbacks are
never reached in normal operation). The cutover is therefore a two-step atomic action
per environment:

1. Migrate the database (create `media_db` with all data — see options below)
2. Re-run Ansible (regenerates `.env` with `MYSQL_DATABASE=media_db`, restarts containers)

Until step 2 runs, running containers still connect to `music_db` unchanged. If step 1
fails, nothing in `.env` has changed — nothing breaks.

**Additional safeguards:**
- Do dev first; only promote to lab → staging → prod once fully verified
- Do **not** `DROP DATABASE music_db` immediately — leave it intact for several days
  as a zero-effort fallback. Drop it only after confirming the environment is stable.
- The dump from the migration step is itself a backup — keep it until prod is confirmed good.

---

## Live Database Migration — Existing Installs

MySQL has no `RENAME DATABASE` command. For **existing installs** (dev, lab, staging,
prod) there are two approaches. The backup-edit approach is preferred.

For **fresh installs**, updating the group_vars and SQL DDL is sufficient — the MySQL
container initializes with `create_media_db.sql` on first startup.

### Preferred: Edit the Backup (use GigHive's own backup/restore)

GigHive's `dbDump.sh` uses `mysqldump --databases $DB_NAME`, which embeds
`CREATE DATABASE` and `USE music_db` statements directly in the `.sql.gz` file.
`dbRestore.sh` pipes the dump to `mysql` without a `-D` flag, so those embedded
statements drive which database gets created and populated. This means the migration
reduces to: edit the dump to say `media_db`, then restore it. See the Implementation
Order below for the full serialized steps including bash commands.

### Alternative: Manual Dump / Create / Import / Drop

```bash
# On the host, against the running mysqlServer container
# Note: omit --databases so no embedded CREATE DATABASE/USE statements are written;
# the explicit "media_db" target on the import command is then authoritative.
docker exec mysqlServer mysqldump \
  -u root -p"$MYSQL_ROOT_PASSWORD" \
  --single-transaction --quick --lock-tables=0 \
  music_db > /tmp/music_db_dump.sql

docker exec -i mysqlServer mysql -u root -p"$MYSQL_ROOT_PASSWORD" \
  -e "CREATE DATABASE media_db DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

docker exec -i mysqlServer mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db \
  < /tmp/music_db_dump.sql

# Leave music_db in place until confident, then:
docker exec -i mysqlServer mysql -u root -p"$MYSQL_ROOT_PASSWORD" \
  -e "DROP DATABASE IF EXISTS music_db;"
```

---

## Implementation Order (Recommended)

- **Steps 1–7** — file changes across the codebase
- **Step 8** — `git commit` + `git push`; do not run Ansible yet
- **Steps 9–11** — per environment: backup → edit dump → restore (creates `media_db` while app stays on `music_db`)
- **Step 12** — pull latest code (lab/staging only)
- **Steps 13–14** — Ansible deploy (no DB creation needed; `media_db` already exists) and verify
- **Step 15** — leave `music_db` intact for several days as a fallback, then drop

### Phase 1 — Code changes (one branch, applies to all environments)

1. Rename `create_music_db.sql` → `create_media_db.sql` (`git mv`)
2. Update internal SQL content in the renamed file (3 occurrences)
3. Update all downstream mount-path references (docker-compose files, shell scripts)
4. Update group_vars in all three environment files
5. Update all template fallback defaults
6. Update PHP/Python/shell hardcoded names and defaults
7. Update `validate_app` Ansible task
8. Commit and push:
   ```bash
   git commit -m "files edited for music_db rename"
   git push origin master
   ```
   **Do not run the Ansible deploy yet** — each environment needs its `rebuild_mysql_data` flag set first (step 11) before the per-environment rollout begins.

### Phase 2 — Per-environment rollout (dev → lab → staging → prod)

Repeat steps 9–17 for each environment in order:

9. **Create a fresh backup** (Admin → System & Recovery → Section C, or wait for the daily
   cron). This produces `music_db_YYYY-MM-DD_HHMMSS.sql.gz` in the backups directory.

10. **Edit the dump** (SSH into the host, run from the backups directory):
    ```bash
    ORIG="music_db_YYYY-MM-DD_HHMMSS.sql.gz"   # replace with actual filename
    NEW="media_db_YYYY-MM-DD_HHMMSS.sql.gz"     # keep same date-stamp

    zcat "$ORIG" \
      | sed 's/`music_db`/`media_db`/g; s/USE music_db/USE media_db/g' \
      | gzip > "$NEW"

    # Verify the edit landed correctly
    zcat "$NEW" | grep -m5 'music_db\|media_db'
    ```

11. **Set `rebuild_mysql_data: true`** in the appropriate group_vars file on the control
    machine (do **not** commit this change):
    - Dev: `ansible/inventories/group_vars/gighive2/gighive2.yml`
    - Lab / Staging: `ansible/inventories/group_vars/gighive/gighive.yml`
    - Prod: `ansible/inventories/group_vars/prod/prod.yml`
    ```yaml
    rebuild_mysql_data: true # Rebuild MySQL container + wipe database (nuclear)
    ```

12. **Sync code to control machine:**
    - **Dev / Prod** — already have the code from step 8; nothing to do
    - **Lab / Staging** — pull the committed changes:
      ```bash
      git pull origin master
      ```

13. **Run Ansible deploy** — MySQL volume is wiped and `media_db` is freshly initialised
    from `create_media_db.sql`, which automatically grants `appuser` access. Brief MySQL
    downtime during the rebuild.

14. **Restore data via Admin UI** — after Ansible completes, the app is running on an empty
    `media_db`. Go to Admin → System & Recovery → Section B, select the
    `media_db_YYYY-MM-DD_HHMMSS.sql.gz` backup created in step 10, type `RESTORE` to
    confirm.

15. **Set `rebuild_mysql_data: false`** in the same group_vars file edited in step 11.

16. **Verify** with the `validate_app` role.

17. **Leave `music_db` intact** for several days of stable operation, then drop:
    ```bash
    docker exec -i mysqlServer mysql -u root -p"$MYSQL_ROOT_PASSWORD" \
      -e "DROP DATABASE IF EXISTS music_db;"
    ```

---

## Workflow per Environment

- **Dev** — perform steps 1–8 (file changes + `git commit`), then steps 9–17 (skip step 12)
- **Lab / Staging** — perform steps 9–17 (step 12 `git pull` applies)
- **Prod** — Ansible runs from pop-os (dev) and rsync's the codebase from there, so the committed changes are automatically included; perform steps 9–17 (skip step 12)

For all environments, steps 9–17 are performed independently in sequence on each environment.

**Step 13 Ansible commands by environment:**

Dev:
```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" ansible-playbook-gighive2-YYYYMMDD.log
```

Lab:
```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_lab.yml ansible/playbooks/site.yml --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" ansible-playbook-lab-YYYYMMDD.log
```

Staging:
```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive.yml ansible/playbooks/site.yml --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" ansible-playbook-gighive-YYYYMMDD.log
```

Prod:
```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_prod.yml ansible/playbooks/site.yml --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" ansible-playbook-prod-YYYYMMDD.log
```

---

## Rollback

If something goes wrong after the Ansible deploy (step 13) or restore (step 14) on a given environment:

- Revert group_vars (`mysql_database: media_db` → `music_db`) for that environment
- Re-run the Ansible deploy for that environment — containers restart pointing to `music_db`
- `music_db` was never dropped, so data is intact and the app recovers immediately
- Investigate the failure before retrying the migration
- Once resolved, re-run steps 9–17 from scratch on that environment (create a fresh backup first)

---

## Status

- [ ] Not started

---

## Related

- `docs/problem_environment_change_requires_process_restart.md` — documents the stale MCP server process issue discovered during this refactor, where long-running processes holding `MYSQL_DATABASE=music_db` in memory caused access-denied errors after `.env` was updated to `media_db`. The fix (pkill task in the mcp_server Ansible role) is documented there.
