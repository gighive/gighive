# DB Update: Add `media_created_at`

## Goal
Add a dedicated nullable `media_created_at` column to the `files` table and populate it consistently for both:

- upload API / direct upload paths
- manifest add/reload import paths launched from `admin.php`

This field is intended to store the media's original creation timestamp derived from media metadata, not the database row creation time.

## Why a dedicated column
`media_created_at` should be a first-class database field rather than a value derived on every read from `media_info`.

Benefits:

- simpler queries and sorting
- cleaner display in `db/database.php`
- consistent semantics across ingestion paths
- easier future migration to the Event/Asset model
- preserves raw probe metadata separately from normalized canonical fields

## Important distinction
The existing `files.created_at` column records when the database row was created.

The new `files.media_created_at` column should record when the media was originally created according to metadata extracted from the media file.

## Scope
### In scope
- add `files.media_created_at DATETIME NULL`
- populate it during standard upload API ingestion
- populate it during TUS finalize ingestion
- populate it during manifest add imports
- populate it during manifest reload imports
- display it in `db/database.php`

### Out of scope
- Event/Asset schema remodel
- backfill of all historical rows unless separately approved
- new dedicated columns for GPS, camera make/model, or other metadata

## Source of truth for extraction
Preferred metadata source order:

1. `format.tags.creation_time`
2. `streams[0].tags.creation_time`

If neither value exists or cannot be parsed, store `NULL` in `media_created_at`.

Normalization is the responsibility of `probeMediaCreatedAt()`. ffprobe returns timestamps in ISO 8601 format with timezone and microseconds (e.g. `2023-08-15T14:22:18.000000Z`). The method must convert this to a MySQL-safe `YYYY-MM-DD HH:MM:SS` string before returning it.

## Storage rules
- Parse media creation time from probe metadata
- Normalize to a DB-safe datetime value
- Store canonical value in `files.media_created_at`
- Preserve raw `media_info` unchanged

`media_info` is the raw structured metadata captured from an `ffprobe` run against the media file. This plan keeps that `ffprobe`-derived JSON intact while also saving the normalized `media_created_at` value in its own column.

## Recommended implementation approach
`MediaProbeService` and `UnifiedIngestionCore` are now in place and shared by all ingestion paths. `media_created_at` extraction and persistence are added directly into those services.

## Required code areas
### 1) Baseline schema for new installs
Update:

- `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`

Add the new column to the `files` table definition so fresh environments include it automatically.

### 2) Idempotent migration for existing installs
Update:

- `ansible/roles/db_migrations/tasks/main.yml`

Add a migration following the existing role pattern:

- check whether `files.media_created_at` exists
- add it if missing
- verify it exists afterward
- fail clearly if the migration did not take effect

### 3) Application code
- `src/Services/MediaProbeService.php` — extraction method
- `src/Services/UnifiedIngestionCore.php` — pass-through in `ingestStub()` and `ingestComplete()`
- `src/Repositories/FileRepository.php` — column in `create()` and `updateProbeMetadata()`

## Upload API path changes
### Files that will change
- **`src/Services/MediaProbeService.php`** — add `probeMediaCreatedAt(?string $mediaInfoJson): ?string` extracting `format.tags.creation_time` or `streams[0].tags.creation_time` from the already-fetched ffprobe JSON string; method is responsible for normalizing to `YYYY-MM-DD HH:MM:SS` before returning (takes the JSON string, not the file path — avoids a second ffprobe invocation)
- **`src/Services/UnifiedIngestionCore.php`** — in `ingestComplete()`: call `$this->probe->probeMediaCreatedAt($mediaInfo)` immediately after `probeMediaInfo()` and pass the result to `updateProbeMetadata()`; in `ingestStub()`: add `'media_created_at' => null` to the `create()` call
- **`src/Repositories/FileRepository.php`** — add `media_created_at` to `create()` insert fields and to `updateProbeMetadata()`
- **`src/Services/UploadService.php`** — `handleUpload()` calls `FileRepository::create()` directly (not via UIC); after calling `probeMediaInfo()`, also call `$this->probe->probeMediaCreatedAt($mediaInfo)` and pass `'media_created_at' => $mediaCreatedAt` to `create()`

### Files that will not change
- **`import_manifest_lib.php`** — already routes through `UnifiedIngestionCore::ingestStub()`; no direct changes needed

### Coverage
All ingestion paths are covered by changes to the shared services:

- **S1** (upload API): `ingestComplete()` probes and inserts `media_created_at` via `FileRepository::create()`
- **S2** (TUS finalize): same as S1; `finalizeTusUpload` delegates to `handleUpload`
- **S3** (manifest TUS finalize): `ingestComplete()` probes and fills in `media_created_at` via `FileRepository::updateProbeMetadata()`
- **W1** (manifest stub): file not on disk; `ingestStub()` stores `NULL`; filled in later by S3

## Manifest import path
No direct changes to manifest import files are required.

`import_manifest_lib.php` routes through `UnifiedIngestionCore::ingestStub()`. Since the file is not on disk when W1 runs, `media_created_at` is stored as `NULL` in the stub row. When the TUS upload completes, `finalizeManifestTusUpload` (S3) calls `UnifiedIngestionCore::ingestComplete()`, which probes the file and fills in `media_created_at` via `FileRepository::updateProbeMetadata()`.

This is the same two-phase pattern already in place for `duration_seconds` and `media_info`.

## Display changes
### Files that will change
- `ansible/roles/docker/files/apache/webroot/src/Repositories/SessionRepository.php`
- `ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php`
- `ansible/roles/docker/files/apache/webroot/src/Views/media/list.php`

### Files that will not change
- `ansible/roles/docker/files/apache/webroot/db/database.php` — thin dispatcher only; no changes required

### Planned behavior
- select `media_created_at` in the listing query path
- pass it through the controller/view model
- render it in the media table shown by `db/database.php`

Recommended initial label:

- `Media Create Date`

## Per-file change details

| File | What changes |
|---|---|
| `create_music_db.sql` | `media_created_at DATETIME NULL` added to `CREATE TABLE files` |
| `db_migrations/tasks/main.yml` | New migration block: check → ALTER → verify → fail |
| `MediaProbeService.php` | New `probeMediaCreatedAt(?string $mediaInfoJson): ?string` — parse JSON already in hand, normalize ISO 8601 → `YYYY-MM-DD HH:MM:SS`, no second ffprobe call |
| `UnifiedIngestionCore.php` | `ingestStub`: add `null` to `create()`; `ingestComplete`: call `probeMediaCreatedAt($mediaInfo)`, pass result to `updateProbeMetadata()`, include in returned array |
| `FileRepository.php` | `create()`: add column + bind param; `updateProbeMetadata()`: new `?string $mediaCreatedAt` param + SET clause |
| `SessionRepository.php` | Add `f.media_created_at AS media_created_at` to all three SELECT queries |
| `UploadService.php` | Call `probeMediaCreatedAt($mediaInfo)` after `probeMediaInfo()` in `handleUpload()`; pass `media_created_at` to `FileRepository::create()` |
| `MediaController.php` | Extract from row, add to `$viewRows[]` in `list()`; add to `$entries[]` in `listJson()` |
| `list.php` | Column entry in both flavor arrays after `duration`; `elseif` render case |
| `db/database.php` | No changes |

### `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`
In the `CREATE TABLE files` block, add after the `created_at` line:
```sql
media_created_at DATETIME NULL,
```

### `ansible/roles/db_migrations/tasks/main.yml`
Add a new migration block following the existing role pattern:
- check `files.media_created_at` exists (`SHOW COLUMNS FROM files LIKE 'media_created_at'`)
- run `ALTER TABLE files ADD COLUMN media_created_at DATETIME NULL` if missing
- verify the column exists afterward
- fail clearly if the migration did not take effect

### `src/Services/MediaProbeService.php`
Add new public method:
```php
public function probeMediaCreatedAt(?string $mediaInfoJson): ?string
```
- Input `NULL` or empty string must return `NULL` without error
- Decode `$mediaInfoJson`; check `$decoded['format']['tags']['creation_time']` first, then `$decoded['streams'][0]['tags']['creation_time']`
- Normalize the value: strip microseconds and UTC suffix (e.g. `2023-08-15T14:22:18.000000Z` → `2023-08-15 14:22:18`); replace `T` separator with a space; truncate to 19 characters
- Return the normalized `YYYY-MM-DD HH:MM:SS` string, or `NULL` if the tag is absent or cannot be parsed
- Do NOT run ffprobe again — use the JSON already fetched by `probeMediaInfo()`

### `src/Services/UploadService.php`

#### `handleUpload()`
This method calls `FileRepository::create()` directly (not via UIC). After the existing `probeMediaInfo()` call, add:
```php
$mediaCreatedAt = $this->probe->probeMediaCreatedAt($mediaInfo);
```
Add `'media_created_at' => $mediaCreatedAt` to the `create([...])` call.

### `src/Services/UnifiedIngestionCore.php`

#### `ingestStub()`
Add `'media_created_at' => null` to the `$this->files->create([...])` call. File is not on disk at W1 time; always `NULL`.

#### `ingestComplete()`
After `$mediaInfo = $this->probe->probeMediaInfo($filePath);`, add:
```php
$mediaCreatedAt = $this->probe->probeMediaCreatedAt($mediaInfo);
```
Pass `$mediaCreatedAt` as the new last argument to `$this->files->updateProbeMetadata(...)`.  
Add `'media_created_at' => $mediaCreatedAt` to the returned array.

### `src/Repositories/FileRepository.php`

#### `create()`
- Add `media_created_at` to the INSERT column list in `$sql`
- Add `:media_created_at` to the `VALUES` list in `$sql`
- Add `':media_created_at' => $data['media_created_at'] ?? null` to the `execute([...])` array

#### `updateProbeMetadata()`
- Add `?string $mediaCreatedAt` as a new last parameter
- Add `media_created_at = :media_created_at,` to the `SET` clause in `$sql` (before `WHERE`)
- Add `':media_created_at' => $mediaCreatedAt` to the `execute([...])` array

### `src/Repositories/SessionRepository.php`
In all three query methods — `fetchMediaList()`, `fetchMediaListFiltered()`, `fetchMediaListPage()` — add to the `SELECT` column list after `f.media_info AS media_info`:
```sql
f.media_created_at                    AS media_created_at,
```

### `src/Controllers/MediaController.php`

#### `list()` method
In the `foreach ($rows as $row)` loop where `$viewRows[]` is built:
- Extract `$mediaCreatedAt = (string)($row['media_created_at'] ?? '');`
- Add `'mediaCreatedAt' => $mediaCreatedAt` to the `$viewRows[]` array

#### `listJson()` method
Add `'media_created_at' => (string)($row['media_created_at'] ?? '')` to the `$entries[]` array for API consumers.

### `src/Views/media/list.php`

#### Column definitions
In both column definition arrays (defaultcodebase and gighive variants), add after the `duration` entry:
```php
['key' => 'media_created_at', 'label' => 'Media Create Date', 'title' => 'Media Create Date', 'search' => null],
```

#### Cell rendering
In the `if/elseif` cell-rendering chain, add a case:
```php
<?php elseif ($key === 'media_created_at'): ?>
  <td data-col="media_created_at"><?= htmlspecialchars($r['mediaCreatedAt'] ?? '', ENT_QUOTES) ?></td>
```

## Acceptance criteria
- `files.media_created_at` exists in baseline schema and is added via migration on existing installs
- upload API path stores `media_created_at` when probe metadata provides a creation timestamp
- TUS finalize path stores `media_created_at`
- manifest add path stores `media_created_at`
- manifest reload path stores `media_created_at`
- rows without valid metadata store `NULL`
- `db/database.php` displays the field
- raw `media_info` remains preserved and unmodified

## Testing
All upload ingestion paths affected by this change are exercised by the `upload_tests` Ansible role.

```sh
script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive2.yml ansible/playbooks/site.yml --tags set_targets,upload_tests" ansible-playbook-gighive2-20260402.log
```

The `script` wrapper captures full terminal output (including color/timing) to the named log file while also printing to the terminal.

## Recommended sequencing
1. Add schema column and migration
2. Add `probeMediaCreatedAt()` to `MediaProbeService`; wire through `UnifiedIngestionCore` and `FileRepository`
3. Add listing/display support
4. Optionally consider historical backfill as a separate follow-up

## Risks and checks
### Manifest import metadata availability
W1 always stores `NULL` (no file on disk, no probe possible). S3 probes the uploaded file via the same `MediaProbeService` code path as S1/S2. The only remaining risk is that some uploaded files do not contain `creation_time` metadata; this is handled cleanly by the nullable fallback.

### Duplication risk
Resolved. `MediaProbeService` is the single canonical extraction point shared by all ingestion paths.

### Nullability
Some files will not provide a usable creation timestamp. The column should remain nullable and the application should tolerate `NULL` cleanly.

## Summary
This change should add a dedicated `media_created_at` column and populate it consistently across both major ingestion families:

- upload API / TUS upload flow
- manifest add/reload import flow

It is implemented as a targeted schema-and-write-path enhancement layered on top of the completed unified ingestion core refactor.

