---
description: Add stable event_key UUID to the canonical events table so event identity is independent of mutable display metadata
---

# Refactor: Add `event_key` to `events` for Stable Event Identity

**Date:** 2026-05-29  
**Status:** Not started  
**Supersedes:** `docs/refactor_db_fix_event_metadata_duplication_completed.md` (that doc targeted the legacy `sessions` table, which no longer exists; this doc targets the canonical `events` table)

---

## Problem Statement

`EventRepository::ensureEvent()` resolves an event by `(event_date, org_name)`:

```php
// EventRepository.php line 331
'SELECT event_id FROM events WHERE event_date = :d AND org_name = :o LIMIT 1'
```

`org_name` is mutable display metadata — admins edit it routinely (e.g., correcting
`default` → `StormPigs` after a first upload). The moment `org_name` is changed, any
subsequent upload or manifest import for that same real-world event calls
`ensureEvent($date, 'StormPigs', ...)`, finds no row, and **silently creates a second
`events` row** for the same performance.

The same behaviour exists in the manifest importer:

```php
// import_manifest_lib.php lines 257-260
$key = $it['event_date'] . '|' . $orgName;
if (!isset($eventsByKey[$key])) {
    $eventsByKey[$key] = $eventRepo->ensureEvent($it['event_date'], $orgName, $eventType);
}
```

The canonical `events` table carries `CONSTRAINT uq_events_date_org UNIQUE (event_date,
org_name)` — which enforces uniqueness but on the wrong key. It prevents two rows with
the same `(date, org_name)` pair, but does not prevent two rows for the same *logical
event* after a metadata rename.

**Today (broken) — two `events` rows for one real-world gig:**

| event_id | event_date | org_name   | (what happened) |
|----------|------------|------------|-----------------|
| 12       | 2024-11-15 | default    | first upload, before admin rename |
| 31       | 2024-11-15 | StormPigs  | second upload, after admin renamed org_name |

Both rows exist permanently. Assets from the first upload are under event 12; assets
from the second upload are under event 31. The media library shows two separate entries
for the same night.

**After fix — one `events` row, regardless of rename:**

| event_id | event_key                            | event_date | org_name  | (what happened) |
|----------|--------------------------------------|------------|-----------|-----------------|
| 12       | a3f8c2d1-04e5-4b67-9f12-8c3d7e6a1b90 | 2024-11-15 | StormPigs | first upload created the row; second upload found it by `event_key` and reused it; `org_name` is display metadata and can be edited freely in the UI without affecting this lookup |

---

## Multi-org Handling / Multi-event Per Day Limit

### Two different bands on the same night — works correctly

`UNIQUE(event_date, org_name)` stays on the table and the fallback lookup still uses
`(event_date, org_name)`. Two different orgs on the same date produce two separate event
rows with no interference from each other:

| event_id | event_key | event_date | org_name    |
|----------|-----------|------------|-------------|
| 12       | uuid-aaa  | 2024-11-15 | StormPigs   |
| 13       | uuid-bbb  | 2024-11-15 | Jethro Tull |

`ensureEvent('2024-11-15', 'Jethro Tull', ...)` finds no row for that `(date, org_name)`
pair and creates a new one. This is unaffected by the fix.

### Same band twice on the same night — known limitation

If StormPigs played an afternoon set and an evening set on the same date,
`UNIQUE(event_date, org_name)` blocks a second row for that pair.
`ensureEvent('2024-11-15', 'StormPigs', ...)` always resolves to the same row.

Two options to handle this edge case:

1. **Workaround within current constraint** — use a disambiguated `org_name` such as
   `StormPigs - Afternoon` and `StormPigs - Evening`. Ugly but functional with no schema
   change.

2. **Drop `UNIQUE(event_date, org_name)` after `event_key` is fully adopted** — once all
   callers consistently supply `event_key`, the `(date, org_name)` constraint is no
   longer needed as a safety net and can be relaxed to a plain index. This would allow
   two StormPigs events on the same night as long as each carries a distinct `event_key`.
   This is a follow-on migration, not in scope for this refactor.

For the GigHive use case (one band per night is the dominant pattern), option 1 is
sufficient today. Option 2 is deferred.

---

## Benefit to Admins

Admins can freely rename an event's `org_name` at any time without causing duplicate
event rows or splitting assets across phantom entries — repeat uploads and manifest
imports always resolve to the same logical event regardless of how the display name
has changed.

---

## Benefit to Librarians

The librarian is the persona most directly affected by this change.

**Cleaner event browse view.** The librarian's primary workspace is the event view
(`EventRepository::fetchEventView()`). Today, editing `org_name` and re-uploading
silently creates a second row — the librarian sees two entries for the same night with a
split setlist and no obvious way to merge them. After the fix: one row, complete setlist,
always.

**Safe metadata editing.** The librarian's job includes correcting display names
(e.g., `default` → `StormPigs`). Today that is a trap — any upload after the rename
goes to a new phantom event. After the fix, `org_name` is pure display metadata that
can be corrected at any time with no structural side effects.

**Accurate tag search results.** `search_assets_by_tag` results include event context
(`event_date`, `org_name`). When assets are split across duplicate events, some results
show the wrong event name. After the fix, the event context is always correct.

**MCP `list_events` tool (P7).** The planned MCP librarian tool queries `events`
directly. Duplicate rows surface as confusing phantom entries to an AI assistant. This
fix is the precondition for that tool returning trustworthy data.

---

## Root Issue with `(event_date, org_name)` as Identity

`(event_date, org_name)` is a *proxy* for identity, not real identity. It works only
under the assumption that a band plays exactly once per calendar day and never renames.
Both assumptions are fragile. `event_key` is actual identity — a stable, meaningless
UUID that never changes regardless of what display metadata does.

With `event_key` as primary identity and `UNIQUE(event_date, org_name)` dropped (option
2 from the multi-org section above, the follow-on migration):

- StormPigs afternoon set and evening set on 2024-11-15 → two UUIDs, two rows, no workaround needed
- StormPigs and Jethro Tull both on 2024-11-15 → two UUIDs, two rows, works the same as today
- A festival where both play the same bill → one event row, both artists as `event_participants`, single `event_key` shared across all their uploads

You go from a model where string-matching a `(date, name)` tuple *is* the event, to a
model where a UUID *is* the event and `(date, name)` is just a searchable label — the
same design principle as any surrogate key: it separates identity from description.

### Practical upside for the API and MCP

The client (iOS app, upload form, manifest importer, MCP tool) gets a stable `event_key`
on first upload and holds onto it. Subsequent operations reference the event by key —
no string coordination, no sensitivity to name changes, no ambiguity if two shows happen
to share a date. The MCP `list_events` tool surfaces `event_key` as the canonical
reference an AI assistant uses to ask follow-up questions about a specific event.

The multi-show benefit fully materialises only after option 2 (dropping
`UNIQUE(event_date, org_name)`) — a straightforward follow-on migration once all callers
consistently supply `event_key`. It is worth treating this as a second-order benefit of
the refactor, not just a bug fix.

---

## Impact — What This Breaks or Hinders

### Data model integrity
- **Duplicate event rows** accumulate silently whenever `org_name` is edited between
  uploads. Two `events` rows → two sets of `event_items` → assets appear split across
  duplicate event entries.
- Assets uploaded before the rename live under event A; assets uploaded after live under
  event B. No FK or constraint prevents this split.

### Media Library (event view)
- `EventRepository::fetchEventView()` lists all events. Duplicate rows surface as two
  separate entries (e.g., "StormPigs — 2024-11-15" and "default — 2024-11-15"), both
  showing a partial setlist.
- Inline edit of `org_name` on either row does not merge the two events; it just updates
  one row while the other persists.

### MCP `list_events` tool (P7)
- The planned `list_events` MCP tool queries the `events` table directly. Duplicate rows
  would be surfaced to an AI assistant as two distinct events, producing confusing and
  incorrect answers about event coverage and asset counts.

### MCP `search_assets_by_tag` tool (P6)
- Assets split across duplicate events may appear under the wrong event context when
  the tag search includes event metadata (date, org_name) in the result.

### Re-upload / idempotency contract
- Callers (upload form, TUS finalize, Section 5 manifest) reasonably expect that
  re-uploading to the same event is a safe, idempotent no-op for the event row. That
  contract is broken whenever `org_name` has drifted since the first upload.

---

## Proposed Fix

Add a stable `event_key CHAR(36)` UUID column to `events`. Switch `ensureEvent()` to
look up by `event_key` when provided, falling back to `(event_date, org_name)` only
when no key is supplied (backward-compatible auto-generation path). Return `event_key`
alongside `event_id` from `ensureEvent()` so callers can include it in responses and
reuse it on subsequent uploads to the same event.

`org_name` becomes pure display metadata — it can be edited freely without affecting
event identity.

### Operational consideration — librarian re-upload workflow

Once `event_key` is live, uploading additional files to an *existing* event requires
knowing that event's `event_key`. The client (upload form, iOS app) receives `event_key`
in the upload response on first upload and should store it. The relevant librarian
scenario is:

> "I uploaded a partial set last week and now have the rest of the songs — how do I add
> them to the same event?"

With `event_key`: supply the UUID from the first upload. Without it, the fallback
`(event_date, org_name)` path still works as long as neither has changed since the first
upload. The admin event detail view should surface `event_key` as a read-only
copy-able field so librarians can retrieve it when needed. This is a small UI addition
captured in the Files to Change table below.

---

## Files to Change

| File | Change |
|------|--------|
| `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql` | Add `event_key CHAR(36)` + unique constraint to `events` DDL |
| `ansible/roles/docker/files/mysql/dbScripts/` (new migration file) | Backfill `event_key` on existing deployed DBs |
| `ansible/roles/docker/files/apache/webroot/src/Repositories/EventRepository.php` | Update `ensureEvent()` signature and lookup logic |
| `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php` | Accept + pass `event_key`; include in return array |
| `ansible/roles/docker/files/apache/webroot/src/Controllers/UploadController.php` | Add `event_key` to OpenAPI request body properties |
| `ansible/roles/docker/files/apache/webroot/admin/import_manifest_lib.php` | Accept `event_key` in top-level payload; use in `ensureEvent()` |
| `ansible/roles/docker/files/apache/webroot/admin/import_manifest_add.php` | Direct `ensureEvent()` caller — destructure array return; pass `event_key` |
| `ansible/roles/docker/files/apache/webroot/admin/import_manifest_reload.php` | Direct `ensureEvent()` caller — destructure array return; pass `event_key` |
| `ansible/roles/docker/files/apache/webroot/admin/iphone_import_worker.php` | Direct `ensureEvent()` caller — destructure array return; pass `event_key` |
| `ansible/roles/docker/files/mysql/externalConfigs/load_and_transform.sql` | `LOAD DATA INFILE` into `events` omits `event_key` — add `event_key = UUID()` to the existing `SET` clause so MySQL generates a UUID per row during the load (strict-mode safe; no post-load UPDATE needed) |
| Admin event detail view (exact file TBD) | Surface `event_key` as read-only copy-able field for librarian re-upload workflow |
| `ansible/roles/upload_tests/tasks/generate_manifest.yml` | Add `event_key` to manifest payload |
| `ansible/roles/upload_tests/tasks/test_7.yml` | Add `event_key` to TUS finalize POST body |

---

## Code Changes

### 1. `create_music_db.sql` — add `event_key` to `events` DDL

```sql
CREATE TABLE events (
    event_id   INT PRIMARY KEY AUTO_INCREMENT,
    event_key  CHAR(36) NOT NULL,               -- ← ADD: stable UUID identity
    event_date DATE NOT NULL,
    org_name   VARCHAR(128) NOT NULL DEFAULT 'default',
    event_type ENUM('band','wedding','other') DEFAULT NULL,
    title VARCHAR(255) NULL,
    -- ... remaining columns unchanged ...
    CONSTRAINT uq_events_key     UNIQUE (event_key),          -- ← ADD
    CONSTRAINT uq_events_date_org UNIQUE (event_date, org_name)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Note: `DEFAULT (UUID())` as a column default requires MySQL 8.0.13+. For fresh installs
there are two write paths: the application code supplies the UUID explicitly (upload and
TUS finalize), and `load_and_transform.sql` generates it via `UUID()` in the LOAD DATA
`SET` clause (CSV bootstrap). Both paths satisfy `NOT NULL` without a DDL default.

---

### 2. DB migration script (new file alongside existing DB scripts)

Add `ansible/roles/docker/files/mysql/dbScripts/migrate_events_add_event_key.sql`:

```sql
-- Safe to run on any existing deployed DB.
-- Idempotent: skips if event_key column already exists.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'events'
      AND COLUMN_NAME  = 'event_key'
);

-- Step 1: add nullable column
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE events ADD COLUMN event_key CHAR(36) NULL AFTER event_id',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Step 2: backfill existing rows
UPDATE events SET event_key = UUID() WHERE event_key IS NULL;

-- Step 3: enforce NOT NULL
SET @col_nullable = (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'events'
      AND COLUMN_NAME  = 'event_key'
);
SET @sql2 = IF(@col_nullable = 'YES',
    'ALTER TABLE events MODIFY COLUMN event_key CHAR(36) NOT NULL',
    'SELECT 1'
);
PREPARE s FROM @sql2; EXECUTE s; DEALLOCATE PREPARE s;

-- Step 4: add unique constraint (skip if already present)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'events'
      AND INDEX_NAME   = 'uq_events_key'
);
SET @sql3 = IF(@idx_exists = 0,
    'ALTER TABLE events ADD CONSTRAINT uq_events_key UNIQUE (event_key)',
    'SELECT 1'
);
PREPARE s FROM @sql3; EXECUTE s; DEALLOCATE PREPARE s;
```

---

### 3. `EventRepository.php` — update `ensureEvent()`

```php
/**
 * Find or create an event.
 *
 * When $eventKey is supplied, lookup is by event_key (stable identity).
 * When $eventKey is null, lookup falls back to (event_date, org_name) for
 * callers that have not yet been updated; a fresh UUID is generated and
 * stored so all future calls can use the key.
 *
 * Returns ['event_id' => int, 'event_key' => string].
 */
public function ensureEvent(
    string $date,
    string $orgName,
    string $eventType,
    string $location  = '',
    string $rating    = '',
    string $notes     = '',
    string $keywords  = '',
    ?string $eventKey = null   // ← NEW optional parameter
): array {
    // Primary lookup: by event_key when provided
    if ($eventKey !== null && $eventKey !== '') {
        $stmt = $this->pdo->prepare(
            'SELECT event_id, event_key FROM events WHERE event_key = :k LIMIT 1'
        );
        $stmt->execute([':k' => $eventKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['event_id' => (int)$row['event_id'], 'event_key' => $row['event_key']];
        }
    }

    // Fallback lookup: by (event_date, org_name) for callers without a key
    $stmt = $this->pdo->prepare(
        'SELECT event_id, event_key FROM events WHERE event_date = :d AND org_name = :o LIMIT 1'
    );
    $stmt->execute([':d' => $date, ':o' => $orgName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['event_id' => (int)$row['event_id'], 'event_key' => (string)$row['event_key']];
    }

    // Not found — create a new event with a stable key
    $newKey    = ($eventKey !== null && $eventKey !== '') ? $eventKey : $this->generateUuid();
    $ratingVal = ($rating !== '' && ctype_digit($rating)) ? (int)$rating : null;
    $sql = 'INSERT INTO events (event_key, event_date, org_name, event_type, location, summary, rating, keywords)'
         . ' VALUES (:key, :date, :org, :etype, :location, :summary, :rating, :kw)';
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
        ':key'      => $newKey,
        ':date'     => $date,
        ':org'      => $orgName,
        ':etype'    => $eventType !== '' ? $eventType : null,
        ':location' => $location  !== '' ? $location  : null,
        ':summary'  => $notes     !== '' ? $notes     : null,
        ':rating'   => $ratingVal,
        ':kw'       => $keywords  !== '' ? $keywords  : null,
    ]);
    return ['event_id' => (int)$this->pdo->lastInsertId(), 'event_key' => $newKey];
}

// Note: mt_rand() is not cryptographically secure but is sufficient for a
// low-volume surrogate key. Replace with random_bytes() if stricter entropy is required.
private function generateUuid(): string
{
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
```

**Callers of `ensureEvent()` that return `int` today must be updated** to destructure
the new array return:

```php
// Before:
$eventId = $this->eventRepo->ensureEvent($eventDate, $orgName, $eventType, ...);

// After:
['event_id' => $eventId, 'event_key' => $eventKey] =
    $this->eventRepo->ensureEvent($eventDate, $orgName, $eventType, ..., $eventKey);
```

---

### 4. `UploadService.php` — accept and propagate `event_key`

```php
// Near line 75 where event context is parsed:
$eventKey = trim((string)($post['event_key'] ?? ''));
// (empty string = auto-generate in ensureEvent)

// Line 94 — update ensureEvent call and destructure result:
['event_id' => $eventId, 'event_key' => $resolvedKey] =
    $this->eventRepo->ensureEvent(
        $eventDate, $orgName, $eventType,
        $location, $rating, $notes, $keywords,
        $eventKey !== '' ? $eventKey : null
    );

// Include event_key in both return arrays (lines ~139-155 and ~228-235):
'event_key' => $resolvedKey,
```

---

### 5. `UploadController.php` — add `event_key` to OpenAPI request body

In the `#[OA\Post(path: '/uploads/finalize', ...)]` `requestBody` properties array,
add alongside the existing `org_name` property:

```php
new OA\Property(
    property: 'event_key',
    type: 'string',
    pattern: '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
    description: 'Stable event UUID. If supplied, the event is looked up by this key regardless of org_name changes. Auto-generated and returned when absent.'
),
```

Apply the same addition to the `POST /uploads` request body schema.

---

### 6. `import_manifest_lib.php` — accept `event_key` in payload

```php
// In gighive_manifest_validate_payload(), near line 157:
$orgName  = trim((string)($payload['org_name']   ?? 'default'));
$eventKey = trim((string)($payload['event_key']  ?? ''));   // ← ADD: optional stable key

// In the event resolution loop (lines 255-260), replace:
// NOTE: a single $eventKey from the payload is only correct for single-event manifests
// (one org, one date or a date range treated as one event). Multi-date manifests that
// span genuinely distinct events must omit event_key and let (event_date, org_name)
// deduplication handle each date independently via the fallback path.
$eventsByKey = [];
foreach ($validated as $it) {
    $key = $it['event_date'] . '|' . $orgName;
    if (!isset($eventsByKey[$key])) {
        // Only pass $eventKey for single-event manifests; for multi-date, pass null
        // so each date resolves via the (event_date, org_name) fallback.
        $keyForDate = (count(array_unique(array_column($validated, 'event_date'))) === 1)
            ? ($eventKey !== '' ? $eventKey : null)
            : null;
        $result = $eventRepo->ensureEvent($it['event_date'], $orgName, $eventType,
                                          '', '', '', '', $keyForDate);
        $eventsByKey[$key] = $result['event_id'];
        // Capture resolved key for response (first iteration wins)
        if (!isset($resolvedEventKey)) {
            $resolvedEventKey = $result['event_key'];
        }
    }
}
```

Return `event_key` in the import completion response so callers can capture and reuse it.

---

### 7. Upload tests — supply a stable `event_key`

**`ansible/roles/upload_tests/tasks/generate_manifest.yml`** — add to the Python
manifest generator payload:

```yaml
- name: Set upload test event key
  set_fact:
    upload_test_event_key: "{{ upload_test_event_key | default('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee') }}"
```

Pass `event_key: "{{ upload_test_event_key }}"` into the manifest payload dict.

**`ansible/roles/upload_tests/tasks/test_7.yml`** — add to the finalize POST body:

```yaml
event_key: "{{ upload_test_event_key }}"
```

Assert that the finalize response contains a matching `event_key` field.

---

## Migration Plan

### For fresh installs
No action needed beyond deploying the updated `create_music_db.sql`. The `event_key`
column is present from the first bootstrap.

### For existing deployed DBs
1. Run `migrate_events_add_event_key.sql` against the running MySQL container:
   ```bash
   docker exec -i <mysql_container> mysql -u root -p music_db \
     < ansible/roles/docker/files/mysql/dbScripts/migrate_events_add_event_key.sql
   ```
2. Deploy updated PHP code (`EventRepository`, `UploadService`, `import_manifest_lib`).
3. Verify: `SELECT event_id, event_key, event_date, org_name FROM events LIMIT 10;`
   — every row must have a non-null, non-empty `event_key`.

### Rollback
Drop the column:
```sql
ALTER TABLE events DROP CONSTRAINT uq_events_key;
ALTER TABLE events DROP COLUMN event_key;
```
Revert PHP code. The fallback `(event_date, org_name)` lookup path is still present in
`ensureEvent()` during the transition.

---

## Acceptance Criteria

- Re-uploading to an event after editing `org_name` reuses the same `events` row
  (same `event_id`, same `event_key`).
- A fresh upload with no `event_key` in the request receives a server-generated UUID in
  the response; a follow-up upload with that UUID resolves the same event regardless of
  any subsequent `org_name` edit.
- `SELECT COUNT(*) FROM events WHERE event_key IS NULL` returns 0 on all deployed DBs
  after migration.
- `SELECT event_key, COUNT(*) FROM events GROUP BY event_key HAVING COUNT(*) > 1`
  returns 0 rows (no duplicate keys).
- Full upload test suite passes with stable `upload_test_event_key` supplied.
- MCP `list_events` tool returns one row per logical event with no duplicates for
  a corpus that has had `org_name` edits between upload runs.
