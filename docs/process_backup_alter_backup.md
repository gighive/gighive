# Process: Backup → Alter → Backup → Rebuild → Restore (BABRR)

Use this process whenever a schema change must be applied to an existing live database across
environments (dev, lab, staging, prod). It ensures data is safe at every step and that the
running schema is consistent with `create_media_db.sql` (the canonical source of truth).

The five steps are:

1. **Backup** — dump the live database before touching anything (pre-migration backup)
2. **Alter** — apply the DDL change against the live database
3. **Backup** — dump again after the DDL (post-migration backup; this is the restore target)
4. **Rebuild** — wipe the MySQL volume and reinitialise from the updated `create_media_db.sql`
5. **Restore** — restore the Step 3 backup into the fresh container via the Admin UI

> **Important:** Steps 2 and 4 require that `create_media_db.sql` has already been updated
> with the matching DDL change and **committed + pushed** before Step 4 runs. The Ansible
> rebuild pulls from the repo; if the SQL file is stale the schema will be wrong after Step 4.

---

## Step 1 — Pre-migration backup

Run from the **docker host** for the target environment.

```bash
docker exec mysqlServer sh -lc 'mysqldump -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > backup_pre_migration_$(date +%Y%m%d_%H%M%S).sql
```

Verify the dump is non-empty and not corrupt:

```bash
wc -l backup_pre_migration_*.sql | tail -1
gzip -t backup_pre_migration_*.sql && echo "OK"
```

---

## Step 2 — Apply DDL (Alter)

Run from the **docker host**. Replace the `MIGRATION` heredoc body with your specific
`ALTER TABLE` statement(s).

```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"' << 'MIGRATION'
-- INSERT YOUR ALTER TABLE STATEMENT(S) HERE
-- Example (loosen NOT NULL constraint):
--   ALTER TABLE assets MODIFY checksum_sha256 CHAR(64) NULL;
-- Example (add column):
--   ALTER TABLE events ADD COLUMN IF NOT EXISTS my_col TINYINT(1) NOT NULL DEFAULT 0 AFTER event_type;
MIGRATION
```

### Tips

- Use `ADD COLUMN IF NOT EXISTS` / `MODIFY` / `DROP COLUMN IF EXISTS` for idempotency where
  possible (requires MySQL 8.0+).
- Drop indexes/keys before dropping their columns.
- Add indexes/keys after adding their columns.

Verify the change took effect:

```bash
docker exec mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE" \
  -e "SHOW CREATE TABLE <table_name>\G"'
```

---

## Step 3 — Post-migration backup

Run from the **docker host**. This backup is the restore target in Step 5.

```bash
docker exec mysqlServer sh -lc 'mysqldump -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > backup_post_migration_$(date +%Y%m%d_%H%M%S).sql
```

Verify:

```bash
gzip -t backup_post_migration_*.sql && echo "OK"
```

Copy it somewhere accessible (e.g. the backups directory) so the Admin UI can find it for
Step 5, or upload it via the Admin System page's restore section.

---

## Step 4 — Ansible rebuild

Run from the **control machine** (pop-os / `192.168.1.235`).

### 4a — Set the rebuild flag

Edit the appropriate group_vars file. **Do not commit this change.**

| Environment | group_vars file |
|-------------|----------------|
| dev | `ansible/inventories/group_vars/gighive2/gighive2.yml` |
| lab | `ansible/inventories/group_vars/gighive/gighive.yml` |
| staging | `ansible/inventories/group_vars/gighive/gighive.yml` |
| prod | `ansible/inventories/group_vars/prod/prod.yml` |

```yaml
rebuild_mysql_data: true  # Rebuild MySQL container + wipe database (nuclear)
```

### 4b — Run the Ansible playbook

**Dev:**
```bash
script -q -c "ansible-playbook \
  -i ansible/inventories/inventory_gighive2.yml \
  ansible/playbooks/site.yml \
  --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" \
  ansible-playbook-gighive2-$(date +%Y%m%d).log
```

**Lab:**
```bash
script -q -c "ansible-playbook \
  -i ansible/inventories/inventory_lab.yml \
  ansible/playbooks/site.yml \
  --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" \
  ansible-playbook-lab-$(date +%Y%m%d).log
```

**Staging:**
```bash
script -q -c "ansible-playbook \
  -i ansible/inventories/inventory_gighive.yml \
  ansible/playbooks/site.yml \
  --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" \
  ansible-playbook-gighive-$(date +%Y%m%d).log
```

**Prod:**
```bash
script -q -c "ansible-playbook \
  -i ansible/inventories/inventory_prod.yml \
  ansible/playbooks/site.yml \
  --skip-tags vbox_provision,db_migrations,installation_tracking,one_shot_bundle,one_shot_bundle_archive,upload_tests,playwright_admin_tests" \
  ansible-playbook-prod-$(date +%Y%m%d).log
```

### 4c — Revert the rebuild flag immediately after Ansible completes

```yaml
rebuild_mysql_data: false
```

> **Warning:** Leaving `rebuild_mysql_data: true` will wipe the database on the next routine
> Ansible run. Revert it before doing anything else.

---

## Step 5 — Restore

Restore the **Step 3 post-migration backup** (not the Step 1 pre-migration backup) into the
freshly rebuilt container using the Admin UI:

1. Navigate to `https://<host>/admin/admin_system.php`
2. Go to **Section B — Restore Database**
3. Select the Step 3 backup file from the dropdown
4. Click **Restore** and wait for completion

The restore replaces the Ansible-built empty schema with the migrated data state. The schema
from `create_media_db.sql` and the schema embedded in the backup will both contain the
altered columns.

After restore, run `validate_app` and `upload_tests` to confirm the environment is healthy.

---

## Rollback

If something goes wrong after Step 2 but before Step 4, you can revert the DDL change
against the live database (drop in reverse order of addition; drop keys before columns):

```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"' << 'ROLLBACK'
-- INSERT YOUR REVERSE ALTER TABLE STATEMENT(S) HERE
-- Example (restore NOT NULL constraint):
--   ALTER TABLE assets MODIFY checksum_sha256 CHAR(64) NOT NULL;
-- Example (drop added column):
--   ALTER TABLE events DROP COLUMN IF EXISTS my_col;
ROLLBACK
```

If rollback via DDL is not possible or the database is in an unknown state, restore the
**Step 1 pre-migration backup** instead:

1. Admin UI → Section B — Restore Database → select the Step 1 file
2. OR: `docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < backup_pre_migration_<timestamp>.sql`

---

## Environment host quick-reference

| Environment | Docker host | SSH |
|-------------|-------------|-----|
| dev | devvm.gighive.internal (192.168.1.50) | `ssh ubuntu@gighive2.gighive.internal` |
| lab | labvm.gighive.internal (192.168.1.252) | `ssh ubuntu@labvm.gighive.internal` |
| staging | stagingvm.gighive.internal (192.168.1.248) | `ssh ubuntu@stagingvm.gighive.internal` |
| prod | prod.gighive.internal (192.168.1.227) | direct Docker host (no VirtualBox) |

---

## Related documentation

- `docs/feature_iphone_qr_code_shared_gallery_implementation.md` — original source of this
  process pattern (Database Migration section)
- `docs/process_mysql_init.md` — how MySQL initialisation works internally
- `docs/guide_docker_compose_behavior.md` — `rebuild_mysql_data` flag mechanics
- `docs/problem_security_checksum_sha256_mysql_initialization_drop_unique_constraint.md` —
  example of a schema fix that triggered this process
