# Placeholder: Switch Clear Database to Minimal/Explicit Table List

## Context

`admin/clear_media.php` uses a **generic dynamic truncate** — it queries `SHOW TABLES`
for all tables in the database, excludes a small list of non-media tables, and truncates
the rest in one pass.

This was intentional while all tables were effectively media/content tables.

---

## Immediate Problem — Addressed (2026-07-03)

**Problem:** The SaaS refactor (QR code / multi-tenancy feature) added a `tenants` table
with a seed row `(tenant_id=1, slug='default')`. `clear_media.php` was wiping that row
along with media content, because `tenants` was not in the exclusion list. Every table
with a `FOREIGN KEY ... REFERENCES tenants` (`events`, `assets`, `upload_jobs`, etc.)
then failed on insert with `SQLSTATE[23000]: Integrity constraint violation 1452`.

The failure surfaced in the Playwright regression test: after the clear→backup→restore→clear
cycle (steps 4–8), the import in step 9 hit the FK constraint and returned ERROR, leaving
`#b-upload-panel` permanently hidden.

**Fix applied:** `tenants` added to the exclusion list in `clear_media.php`:

```php
$excluded = ['users', 'tenants'];
$tables   = array_values(array_filter($allTables, fn($t) => !in_array($t, $excluded, true)));
```

`tenants` now survives all clear/backup/restore cycles, keeping `tenant_id=1` available
at all times.

---

## Remaining Work — Explicit Allowlist

The dynamic approach still carries risk: any future non-media table added to the schema
will be silently wiped unless it is also added to `$excluded`. The long-term fix is to
switch to an **explicit allowlist** of media tables to truncate.

When to act:
- The `users` table contains real user accounts
- Additional non-media tables are added (settings, billing, audit logs, permissions, etc.)
- The "Clear Database" operation is split into separate granular operations

Candidate explicit allowlist (update to match current schema at time of change):

```php
$tables = [
    'taggings', 'derived_assets', 'helper_runs', 'ai_jobs', 'tags',
    'upload_job_files', 'upload_jobs',
    'anon_upload_attributions', 'event_upload_tokens',
    'catalog_entries', 'catalog_scans',
    'event_participants', 'event_items',
    'assets', 'events', 'participants',
];
```

## File to Edit

`ansible/roles/docker/files/apache/webroot/admin/clear_media.php`
