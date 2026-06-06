# Placeholder: Switch Clear Database to Minimal/Explicit Table List

## Context

`admin/clear_media.php` currently uses a **generic dynamic truncate** — it queries
`information_schema.TABLES` for all tables in the database except `users` and truncates
them all in one pass.

This was intentional while the `users` table holds no real data and all tables are
effectively media/content tables.

## When to Act

Once any of the following become true, revisit this and switch to an **explicit allowlist**
of media tables to truncate:

- The `users` table contains real user accounts that must survive a media wipe
- New non-media tables are added (e.g. settings, billing, audit logs, permissions) that
  should not be cleared by a "Clear Database" operation
- The admin "Clear Database" function is split into separate granular operations

## Required Change

Replace the dynamic `information_schema` query in `clear_media.php` with an explicit list:

```php
$tables = [
    'taggings', 'derived_assets', 'helper_runs', 'ai_jobs', 'tags',
    'upload_job_files', 'upload_jobs',
    'event_participants', 'event_items',
    'assets', 'events', 'participants',
];
```

Update the list to match the current schema at the time of the change.

## File to Edit

`ansible/roles/docker/files/apache/webroot/admin/clear_media.php`
