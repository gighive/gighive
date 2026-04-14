# Refactor: Database UTF-8 Enforcement and Backend Text Normalization

## Goal
Protect all server-side ingestion and upload paths so that only valid UTF-8 reaches the database, while also centralizing text normalization and canonical comparison behavior in one backend abstraction.

This plan is intentionally focused on the backend first. Mobile-side normalization in the iPhone app is still desirable later, but is not a prerequisite for protecting the server.

## Background
A recent upload introduced a smart apostrophe from the iPhone app (`Pat’s Birthday`). That surfaced two separate weaknesses:

- invalid non-UTF-8 text was able to reach persistence/output paths
- punctuation handling is currently inconsistent across session matching, slug generation, and downstream validation tooling

The immediate symptom was an Ansible failure in `validate_app` when MySQL output contained a Windows-1252 apostrophe byte (`0x92`), which Ansible refused to deserialize as UTF-8.

The broader lesson is that filename slugification, dedupe logic, and database text hygiene should not rely on scattered regular expressions or entrypoint-specific behavior.

## Decision
Adopt a backend-first UTF-8 enforcement plan with the following principles:

- all text written to the database must be valid UTF-8
- Unicode-aware normalization must be centralized in one backend service
- display text, canonical comparison text, and filename slug text must be treated as separate concerns
- upload and manifest ingestion paths must all pass through the same normalization policy
- slug regexes must not be reused as business-identity or dedupe logic

## Scope
This plan applies to all upload and ingestion paths covered by the unified-ingestion design in `docs/refactored_preasset_librarian_unified_ingestion_core.md`:

- Upload API / direct upload flow
- TUS finalize flow
- Manifest add flow from `admin.php`
- Manifest reload flow from `admin.php`

It also applies to the persistence logic that these paths ultimately call.

## Codebase analysis findings

This section records the specific findings from a deep-dive review of the codebase against this plan. Each finding maps to a concrete file and explains what is missing or incorrect.

### Finding 1: `create_music_db.sql` has no charset declarations (CRITICAL)

`ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql` contains `CREATE DATABASE music_db` and all `CREATE TABLE` statements with **no `CHARACTER SET` or `COLLATE` declarations**.

If the MySQL server default is `latin1` (which is the MySQL 5.x and some 8.x default), the database and all tables store and return text as latin1. This is the most likely root cause of why `0x92` bytes entered the database and surfaced as the Ansible deserialization failure.

Required fix: add `DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` to the `CREATE DATABASE` statement and all text-bearing `CREATE TABLE` statements.

### Finding 2: No MySQL server configuration file exists

There is no `my.cnf`, `conf.d` snippet, or MySQL init file deployed anywhere in the Docker role files or templates that sets the server-level default charset. The MySQL container therefore uses its compiled-in defaults.

This compounds Finding 1: even if the schema DDL is fixed, a new database without a server-level config can still default to `latin1` depending on the MySQL version.

Required fix: add a MySQL configuration snippet to the Docker role that explicitly sets `character-set-server = utf8mb4` and `collation-server = utf8mb4_unicode_ci`.

### Finding 3: `Database::createFromEnv()` already uses `utf8mb4` (no action needed)

`src/Infrastructure/Database.php` already defaults to `charset=utf8mb4` in the DSN and accepts an optional `DB_CHARSET` env override. The plan's connection hardening section incorrectly implied this was missing. The PDO connection layer is correct as-is.

Note: there is no explicit `PDO::MYSQL_ATTR_INIT_COMMAND` set; the DSN charset is sufficient for the PHP MySQL driver but is worth verifying under the specific driver/container combination used.

### Finding 4: `UnifiedIngestionCore::ensureSession()` passes raw text to DB and dedupe query

`src/Services/UnifiedIngestionCore.php` lines 162–198: `$orgName` is passed directly into both the deduplication `WHERE` clause and the INSERT with no UTF-8 validation or normalization. The same applies to `$location`, `$notes`, and `$keywords`.

This is the exact reason `Pat's` and `Pat's Birthday` create two separate session rows: the raw string comparison treats them as different.

Required fix: normalize `$orgName` and other text fields before both the lookup and the write. Use a canonical comparison value for the WHERE clause.

### Finding 5: `UnifiedIngestionCore::ensureSong()` passes raw title to DB and dedupe query

Lines 203–215: `$title` is passed directly to both the SELECT and INSERT with no normalization. Song deduplication is identically brittle to session deduplication.

Required fix: same as Finding 4 — normalize before lookup and write.

### Finding 6: `UploadService::slugify()` uses non-multibyte `strtolower()`

`src/Services/UploadService.php` line 505: the slugify method calls PHP's non-multibyte `strtolower()`. For ASCII input this is harmless, but for text containing accented characters (e.g. `Beyoncé`) it will silently mangle or fail to lowercase them correctly.

Required fix: replace `strtolower()` with `mb_strtolower()`. This is a one-line change independent of the normalization service work.

### Finding 7: `import_manifest_lib.php` does not validate `$orgName` or derived label for UTF-8

`admin/import_manifest_lib.php` line 152: `$orgName` is extracted with `trim()` only — no encoding check. Line 346: the label derived from `gighive_manifest_basename_no_ext($labelSource)` is passed directly to `ensureSong()` with no validation. If the manifest JSON was generated from a source containing non-UTF-8 bytes, they pass straight through.

Required fix: add UTF-8 validation to `gighive_manifest_validate_payload()` for `$orgName` and all text fields on each item before they reach the UIC.

### Finding 8: `finalizeTusUpload()` merges TUS metadata without encoding check

`src/Services/UploadService.php` lines 293–301: values from the TUS hook metadata (`$meta[$k]`) are merged into `$mergedPost` via a simple array copy with no charset validation. If the TUS client sends non-UTF-8 metadata (e.g. from an iPhone with smart punctuation), it passes silently through to `handleUpload()` and then to the DB.

Required fix: validate or normalize metadata values from TUS hook payloads before merging, or delegate to the UIC normalization boundary.

### Finding 9: `assert_db_invariants.yml` only checks row counts

`ansible/roles/upload_tests/tasks/assert_db_invariants.yml` asserts only `sessions_count` and `files_count`. There are no assertions about the content of text fields, encoding validity, or canonical comparison behavior. New text-integrity assertions need to be added as part of the test matrix expansion.

### Finding 10: `select.sql` in `validate_app` — immediate symptom fix available

The current `validate_app` Ansible failure (`\udc92` deserialization error) can be addressed as an immediate, independent fix by prepending `SET NAMES utf8mb4;` to `ansible/roles/docker/files/mysql/dbScripts/select.sql`. This instructs MySQL to emit query output as UTF-8 even if the underlying column charset is latin1, allowing Ansible to deserialize the output cleanly.

This is a **symptom fix only**. The root problem (latin1 schema + no server charset config + no input normalization) must still be addressed by this refactor. However, it unblocks `validate_app` immediately without waiting for the full plan.

### Finding 11: `intl` PHP extension availability confirmed

The plan recommends using `Normalizer` from the PHP `intl` extension for Unicode normalization. This has now been confirmed in the running container via `php -m | grep -i intl`, so `Normalizer` is available for the planned backend normalization service.

## Core invariants

### 1) Encoding invariant
- all text entering persistence must be valid UTF-8
- invalid UTF-8 must be rejected or normalized before DB write
- database, tables, columns, and connection settings must consistently use `utf8mb4`

### 2) Separation invariant
- display text is not the same as comparison text
- comparison text is not the same as filename slug text
- filename sanitization must not define business identity

### 3) Centralization invariant
- no controller, upload endpoint, worker, or import path should write user-provided text directly to the database without passing through one shared normalization policy

## Recommended architecture

### Shared backend text normalization service
Introduce a single backend service used by the Unified Ingestion Core (UIC), such as:

- `TextNormalizer`
- `CanonicalTextService`
- `IngestionTextPolicy`

The specific class name can be chosen later, but the responsibility should be singular and centralized.

### Responsibilities of the text normalization service
The service should expose separate operations for separate jobs.

#### `normalizeForStorage()`
Used for display-preserving persistence.

Responsibilities:
- validate UTF-8
- normalize Unicode form (preferably NFC unless a stronger policy is intentionally chosen)
- trim leading and trailing whitespace
- collapse repeated whitespace
- normalize line endings where relevant
- optionally normalize known smart punctuation if that is chosen as the product policy

This method should produce the canonical stored display value.

#### `canonicalizeForComparison()`
Used for dedupe/session matching/search helpers.

**Resolved policy** (see [Unicode TR#15 Normalization Forms](https://unicode.org/reports/tr15/) and [ICU Transliteration](https://unicode-org.github.io/icu/userguide/transforms/general/)):

| Step | Rule | Example |
| --- | --- | --- |
| NFC normalize | via [`Normalizer::normalize()`](https://www.php.net/manual/en/class.normalizer.php) | combining chars handled consistently |
| Lowercase | `mb_strtolower()` | `Pat's` → `pat's` |
| Strip all apostrophe variants | U+0027 `'`, U+2019 `'`, U+2018 `'`, U+0060 `` ` ``, U+2032 `′` → removed | `pat's` → `pats` |
| Normalize all dashes to `-` | en dash `–`, em dash `—`, hyphen `-` → `-` | `A–B` → `a-b` |
| Transliterate accents to ASCII | `transliterator_transliterate('Any-Latin; Latin-ASCII', $s)` | `Beyoncé` → `beyonce` |
| Collapse whitespace | tabs, NBSP, multiple spaces → single space, then trim | `Pat  s` → `pat s` |

This means the following all produce the same canonical key:
- `Pat's Birthday` → `pats birthday`
- `Pat's Birthday` → `pats birthday`
- `Pats' Birthday` → `pats birthday`
- `Beyoncé` and `Beyonce` → `beyonce`
- `A–B` and `A—B` and `A-B` → `a-b`

This method should produce the comparison value used for equality/matching decisions.

#### `slugifyForFilename()`
Used only for filenames/slugs.

Responsibilities:
- start from normalized text, not raw text
- transliterate to ASCII if desired for filesystem safety
- restrict to safe filename characters
- collapse separators consistently
- remain separate from dedupe logic

## Common library vs custom logic
Best practice is not to hunt for a single magical third-party “scrubber” and delegate all policy to it.

The recommended split is:

- use standard Unicode-aware PHP facilities for correctness
- wrap them in a project-owned normalization policy for domain behavior

### Foundation libraries / extensions to prefer
- `mb_*` functions for multibyte-safe string handling
- `Normalizer` from the PHP `intl` extension for Unicode normalization
- transliteration support where needed for slug generation

### Why project-owned policy is still needed
Only the project can decide whether the following should compare equal. **These decisions are now resolved:**

- `Pat's Birthday` same — apostrophe stripped in canonical form
- `Pat's Birthday` same — curly apostrophe stripped in canonical form
- `Pats' Birthday` same — trailing apostrophe stripped in canonical form
- `Pats Birthday` different — no apostrophe to strip, but also no apostrophe in original; this is a legitimately different input

That is business identity policy, not merely character encoding.

## Relationship to the Unified Ingestion Core
The correct enforcement point is the shared ingestion core described in `docs/refactored_preasset_librarian_unified_ingestion_core.md`.

Route handlers may still do basic request validation, but the UIC should be the final authority before persistence.

This means:

- all outer upload/import entrypoints may remain distinct
- all final text normalization and canonical comparison policy should happen in the same backend write-path abstraction

## Upload paths that must be protected

### 1) Upload API / direct upload flow
The direct upload path must normalize all relevant request fields before session creation, label creation, and file metadata persistence.

Fields include at minimum:
- `org_name`
- `label`
- `participants`
- `keywords`
- `location`
- `notes`
- any other user-provided text attached to the upload

Protection requirements:
- reject invalid UTF-8 before DB writes
- use normalized display text for storage
- use canonical comparison text for dedupe/session matching
- use a separate slug path for filename generation

### 2) TUS finalize flow
If TUS metadata or finalize payload text participates in persistence decisions, it must pass through the same normalization policy.

Protection requirements:
- TUS finalize must not bypass UTF-8 enforcement just because the file bytes already exist on disk
- any title/event/organization metadata supplied through the finalize flow must go through the UIC text policy before persistence

### 3) Manifest add flow
Manifest add is a high-risk path for encoding problems because it may ingest text originating outside the browser.

Protection requirements:
- normalize manifest text before persistence
- explicitly handle source files that may contain cp1252/latin1 punctuation
- ensure all values reaching the UIC are valid UTF-8
- use the same canonical comparison rules as the upload API path

### 4) Manifest reload flow
Manifest reload must use the same normalization and comparison behavior as manifest add.

Protection requirements:
- no separate normalization behavior from manifest add
- no opportunity for reload to reintroduce legacy non-UTF-8 text

## Data model recommendation
Longer term, the cleanest pattern is to maintain both:

- a display-preserving stored value
- a canonical comparison value

Examples:

- display value: `Pat’s Birthday`
- canonical comparison value: `pats birthday`

For the current schema, that can be phased in gradually. The most important immediate identity point is the field currently used to match sessions/events.

## Database and connection hardening
Application-layer normalization is necessary but not sufficient.

### Database / schema hardening (action required — see Finding 1 and 2)
- add `DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` to `CREATE DATABASE` in `create_music_db.sql`
- add the same charset/collation declaration to all `CREATE TABLE` statements in `create_music_db.sql`
- update `ansible/roles/docker/files/mysql/externalConfigs/z-custommysqld.cnf` to explicitly set `character-set-server = utf8mb4` and `collation-server = utf8mb4_unicode_ci`
- verify future schema migrations preserve these declarations

### Connection hardening (already implemented — see Finding 3)
- `Database::createFromEnv()` already sets `charset=utf8mb4` in the DSN by default
- verify that the `DB_CHARSET` env var is not being overridden to a non-UTF-8 value anywhere in group_vars or `.env` templates
- ensure no secondary DB connection path bypasses `Database::createFromEnv()`

## Legacy data cleanup
Existing bad rows should not be left in place indefinitely.

Recommended follow-up:
- scan current text-bearing tables for invalid or suspicious byte sequences
- repair cp1252-style punctuation where appropriate
- verify that validation and export tooling can safely read all rows afterward

Without cleanup, existing bad rows can continue to break validation tasks, exports, or other tools even after new writes are fixed.

## What not to do
- do not reuse slug regex behavior as dedupe/session identity behavior
- do not scatter ad hoc regex “fixes” across multiple controllers and scripts
- do not rely on disabling strict UTF-8 checks as the main solution
- do not normalize only at the API edge while allowing imports/workers to bypass the policy
- do not silently change dedupe semantics without explicitly deciding the canonical comparison policy

## Files Under Change
| path/to/filename/filename | Existing or New |
| --- | --- |
| `ansible/roles/docker/files/mysql/dbScripts/select.sql` | Existing |
| `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql` | Existing |
| `ansible/roles/docker/files/mysql/externalConfigs/z-custommysqld.cnf` | Existing |
| `ansible/roles/docker/files/apache/webroot/src/Services/TextNormalizer.php` | New |
| `ansible/roles/docker/files/apache/webroot/src/Services/UnifiedIngestionCore.php` | Existing |
| `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php` | Existing |
| `ansible/roles/docker/files/apache/webroot/admin/import_manifest_lib.php` | Existing |
| `ansible/roles/upload_tests/tasks/assert_db_invariants.yml` | Existing |
| `ansible/roles/validate_app/tasks/main.yml` | Existing |

## Files that will need to change
- `ansible/roles/docker/files/mysql/dbScripts/select.sql`
  - add `SET NAMES utf8mb4;` as the immediate symptom fix so `validate_app` can safely deserialize MySQL output as UTF-8
- `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql`
  - add explicit `utf8mb4` charset/collation declarations to `CREATE DATABASE` and the text-bearing tables
- `ansible/roles/docker/files/mysql/externalConfigs/z-custommysqld.cnf`
  - add server defaults such as `character-set-server = utf8mb4` and `collation-server = utf8mb4_unicode_ci`
- new backend normalization service, e.g. `ansible/roles/docker/files/apache/webroot/src/Services/TextNormalizer.php`
  - centralize UTF-8 validation, Unicode normalization, canonical comparison, and filename slug normalization
- `ansible/roles/docker/files/apache/webroot/src/Services/UnifiedIngestionCore.php`
  - normalize event/org and song/title text before dedupe lookups and writes; stop using raw caller text as identity
- `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
  - switch `slugify()` to `mb_strtolower()` and ensure TUS metadata is normalized or validated before persistence
- `ansible/roles/docker/files/apache/webroot/admin/import_manifest_lib.php`
  - validate manifest text fields as UTF-8 before they reach the UIC and before derived labels are persisted
- `ansible/roles/upload_tests/tasks/assert_db_invariants.yml`
  - extend assertions beyond row counts to include UTF-8 safety and canonicalization behavior
- `ansible/roles/validate_app/tasks/main.yml`
  - add validation tasks that explicitly check UTF-8-safe SQL output and text-integrity regressions

## Recommended implementation phases

### Phase 0: Immediate symptom fix (unblocks `validate_app` now)
Apply this independently of the normalization refactor to restore the `validate_app` role:
- prepend `SET NAMES utf8mb4;` to `ansible/roles/docker/files/mysql/dbScripts/select.sql`

This is a one-line change. It unblocks the Ansible run without altering any application code or schema.

### Phase 1: Foundations — DB hardening and field-level policy
This phase establishes the infrastructure prerequisites before any PHP code changes. DB and server charset hardening belongs here, not after the application work, because the storage layer must be correct before normalized writes flow through it.

DB and server hardening files to change:
- `ansible/roles/docker/files/mysql/externalConfigs/create_music_db.sql` — add `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` to `CREATE DATABASE` and all text-bearing `CREATE TABLE` statements (see Finding 1)
- `ansible/roles/docker/files/mysql/externalConfigs/z-custommysqld.cnf` — add `character-set-server = utf8mb4` and `collation-server = utf8mb4_unicode_ci` (see Finding 2)
- verify `DB_CHARSET` is not overridden in any group_vars or `.env` templates

**Live DB charset confirmed** — `SHOW CREATE DATABASE music_db` returns `DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci`. No `ALTER TABLE ... CONVERT TO CHARACTER SET` migration is needed. The DDL fix to `create_music_db.sql` is for portability and explicit intent only.

Policy decisions — **resolved** (see `canonicalizeForComparison()` in the Recommended architecture section above):
- apostrophes (all variants) → stripped in canonical form
- dashes (en dash, em dash) → normalized to `-`
- accent-bearing characters → transliterated to ASCII (`Beyoncé` → `beyonce`)
- whitespace → collapsed to single space, trimmed
- display values preserve original punctuation; only the canonical comparison key applies these transforms
- policy applies uniformly to all text field types: Event/org name, label/song title, location, freeform notes, participants, keywords

### Phase 2: Implementation — normalization service, UIC integration, and entry-point fixes
Once policy is decided and the storage layer is hardened, this phase is a continuous implementation sprint. Build `TextNormalizer`, wire it into the UIC, then fix all entry points. These three steps have clear sequential dependencies but no meaningful stopping point between them.

New file:
- `ansible/roles/docker/files/apache/webroot/src/Services/TextNormalizer.php` — centralize UTF-8 validation via `mb_check_encoding()` / `mb_convert_encoding()`, Unicode normalization via `Normalizer::normalize()` from the `intl` extension (confirmed installed), whitespace normalization, punctuation normalization, and separate `normalizeForStorage()`, `canonicalizeForComparison()`, and `slugifyForFilename()` methods (see Finding 11)

Files to change:
- `ansible/roles/docker/files/apache/webroot/src/Services/UnifiedIngestionCore.php` — inject `TextNormalizer`; normalize `$orgName`, `$location`, `$notes`, `$keywords` before the `ensureSession()` lookup and write; normalize `$title` before the `ensureSong()` lookup and write; use canonical comparison value in WHERE clauses (see Findings 4 and 5)
- `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php` — normalize TUS metadata values from `$meta[$k]` before merging in `finalizeTusUpload()`; replace `strtolower()` with `mb_strtolower()` in `slugify()` (see Findings 6 and 8)
- `ansible/roles/docker/files/apache/webroot/admin/import_manifest_lib.php` — add UTF-8 validation to `gighive_manifest_validate_payload()` for `$orgName` and all per-item text fields (see Finding 7)

### Phase 3: Legacy data cleanup
Run a controlled cleanup of already-persisted non-UTF-8 or mis-encoded text.
- scan current text-bearing tables for invalid or suspicious byte sequences
- repair cp1252-style punctuation where appropriate
- verify that validation and export tooling can safely read all rows afterward

### Phase 4: Test expansion
Add test fixtures and assertions so every ingestion path is verified against the same UTF-8 and canonicalization expectations, following the split defined in the Testing strategy section.

Files to change:
- `ansible/roles/upload_tests/tasks/assert_db_invariants.yml` — add text-content assertions alongside the existing row-count assertions; verify stored text fields contain valid UTF-8 and that canonical comparison behavior is stable (see Finding 9)
- `ansible/roles/validate_app/tasks/main.yml` — add new tasks per the testing strategy section of this doc

## Testing strategy
The UTF-8 and normalization plan should be tested in two complementary places.

### 1) `ansible/roles/upload_tests`
This role should remain the primary end-to-end harness for exercising ingestion paths in general. It should focus primarily on **valid UTF-8 inputs**, including tricky but conformant real-world text that must behave consistently across ingestion paths.

Recommended additions:
- fixture coverage for straight apostrophes
- fixture coverage for curly apostrophes
- fixture coverage for trailing apostrophes
- fixture coverage for smart quotes
- fixture coverage for en dash / em dash variants
- fixture coverage for accented characters
- fixture coverage for repeated whitespace / tabs

This role should verify end-to-end behavior for valid text, including:
- canonical-equivalence behavior across direct upload, TUS finalize, manifest add, and manifest reload
- display preservation versus normalization behavior according to the chosen field policy
- duplicate/session matching behavior for valid UTF-8 variants that should compare the same or differently by design

Assertions should verify:
- all paths accept valid UTF-8 inputs consistently
- all paths produce consistent session/event matching behavior for canonical equivalents
- all paths preserve or normalize display text according to the chosen policy
- duplicate behavior is stable across direct upload, TUS finalize, manifest add, and manifest reload

### 2) New validation-focused checks in `ansible/roles/validate_app`
In addition to `upload_tests`, new validation-oriented checks should be added under `ansible/roles/validate_app` based on the UTF-8 test matrix. This role is the primary home for **deliberately non-conformant or invalid text tests**.

Examples of negative/integrity-focused inputs for `validate_app`:
- invalid UTF-8 byte sequences
- cp1252-style smart punctuation presented as non-UTF-8 input
- malformed manifest or payload text intended to verify rejection/safe handling
- rows or query output specifically chosen to ensure SQL/Ansible output remains UTF-8 safe

Recommended focus for `validate_app`:
- verify deliberately invalid or malformed text is rejected, normalized safely, or otherwise prevented from being persisted as broken data
- verify database query output can be safely emitted as UTF-8
- verify no invalid UTF-8 text remains in relevant tables after test ingestion
- verify representative special-character rows round-trip safely through SQL output
- verify MySQL client/connection behavior does not emit cp1252-style bad bytes for stored text

The `validate_app` role is a good place for explicit post-ingestion UTF-8 safety checks and negative tests because it can fail fast on text-integrity regressions even when uploads appear to succeed functionally.

## Initial test matrix
At minimum, the following inputs should be exercised across the covered upload paths.

- `Pat's Birthday`
- `Pat’s Birthday`
- `Pats' Birthday`
- `“Quoted Title”`
- `A–B`
- `A—B`
- `Beyoncé`
- strings containing tabs or repeated spaces
- representative cp1252-problematic punctuation inputs where safe to simulate

Assertions should cover:
- stored display values are valid UTF-8
- canonical comparison values are stable and intentional
- session/event matching behaves as designed
- slug generation is deterministic but independent from dedupe identity
- no path writes invalid UTF-8 to the database

## Acceptance criteria
- all upload/manifest ingestion paths covered by the UIC enforce valid UTF-8 before persistence
- text normalization policy exists in one backend-owned abstraction
- session/event matching no longer relies on filename slug regex behavior
- direct upload, TUS finalize, manifest add, and manifest reload all share the same text rules
- `upload_tests` exercises the normalization matrix end-to-end
- `validate_app` contains explicit UTF-8 integrity checks derived from the matrix
- existing non-UTF-8 rows are identified and addressed as a follow-up cleanup step

## Summary
The recommended backend-first implementation is:

- one centralized Unicode-aware normalization service
- one canonical enforcement point inside the Unified Ingestion Core
- `utf8mb4` hardening at the DB and connection layers
- test coverage in both `ansible/roles/upload_tests` and `ansible/roles/validate_app`

This avoids brittle regex-driven behavior, prevents future ingress-path drift, and provides a safer foundation for the later Event/Asset hard cutover.
