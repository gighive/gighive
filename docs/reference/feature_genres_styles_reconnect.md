# Feature Plan: Reconnect genres / styles to new schema

## Background

`genres` and `styles` are seeded during manifest reload
(`import_manifest_lib.php` lines 255-264) but no FK column in the current
schema points to them. In the old schema, `songs` carried `genre_id` and
`style_id` FKs. Those columns were lost when `songs` was replaced by the
`event_items` / `assets` model.

---

## Decision point: where do the FKs live?

| Option | Pro | Con |
|--------|-----|-----|
| `event_items.genre_id` + `event_items.style_id` | Per-clip granularity (a set can have a jazz track and a rock track) | More complex UI; most clips won't have values |
| `events.genre_id` + `events.style_id` | Simpler — one genre per event | Coarse; can't tag individual clips differently |
| Both | Maximum flexibility | Most work |

**Recommended starting point:** `event_items` FKs (mirrors the old `songs`
placement and preserves per-clip tagging).

---

## Implementation steps

### 1 – Schema migration (`create_music_db.sql`)

Add nullable FK columns to `event_items` and wire them to the lookup tables:

```sql
ALTER TABLE event_items
    ADD COLUMN genre_id INT NULL AFTER item_type,
    ADD COLUMN style_id INT NULL AFTER genre_id,
    ADD CONSTRAINT fk_event_items_genre
        FOREIGN KEY (genre_id) REFERENCES genres(genre_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_event_items_style
        FOREIGN KEY (style_id) REFERENCES styles(style_id) ON DELETE SET NULL;
```

Also add to the `CREATE TABLE event_items` block in `create_music_db.sql` so
fresh installs include them from the start.

### 2 – Mermaid chart (`docs/database_schema.mermaidchart`)

Add the two FK fields to `event_items` and two new relationship lines:

```
genres  ||--o{ event_items : "has"
styles  ||--o{ event_items : "has"
```

### 3 – Manifest payload & validation (`import_manifest_lib.php`)

- Accept optional `genre` and `style` string fields per item in the manifest
  payload.
- In `gighive_manifest_validate_payload()`, resolve each to an id via
  `SELECT genre_id FROM genres WHERE name = ?` (case-insensitive). Unknown
  values: reject with error OR silently ignore (decide at impl time).
- Pass resolved `genre_id` / `style_id` into `ingestStub` / `ensureEventItem`
  call.

### 4 – `EventItemRepository::ensureEventItem()`

Add `?int $genreId` and `?int $styleId` parameters and include them in the
upsert / update path.

### 5 – Admin UI

- Expose genre/style dropdowns when editing an `event_item` record.
- Optionally allow bulk-set at event level (sets same genre/style on all
  `event_items` for that event).

### 6 – Seed data

Seed logic already exists in `import_manifest_lib.php` lines 255-264 for the
reload path. Verify seed runs before any items are inserted so FK lookups
succeed. No changes needed unless the seed list is expanded.

### 7 – Tests

- Add a manifest payload fixture that includes `genre` / `style` fields and
  assert correct `genre_id` / `style_id` values appear in `event_items` rows.
- Add a test for unknown genre/style values (whichever rejection policy is
  chosen).

---

## Files to change (summary)

| File | Change |
|------|--------|
| `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql` | Add `genre_id`, `style_id` columns + FKs to `event_items` CREATE TABLE |
| `docs/database_schema.mermaidchart` | Add FK fields to `event_items`; add two relationship lines |
| `ansible/roles/docker/files/apache/webroot/admin/import_manifest_lib.php` | Accept + resolve genre/style in payload validation |
| `ansible/roles/docker/files/apache/webroot/src/Repositories/EventItemRepository.php` | Add genre_id/style_id params to `ensureEventItem` |
| Admin UI PHP/JS | Dropdowns for genre/style on event_item edit |
| Upload tests | New fixture + assertions |

---

## Not in scope (deferred)

- Full-text or tag-based genre/style search
- Multi-genre / multi-style per clip (would require a junction table)
- Genre/style on `events` directly (can revisit if per-clip granularity proves
  too complex for users)
