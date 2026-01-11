# Refactor plan: consolidate `fetchMediaList*` queries

## Background

The Media Library (`/db/database.php`) pulls data via `MediaController`, which delegates to `SessionRepository` methods:

- `countMediaListRows($filters)`
- `fetchMediaListPage($filters, $limit, $offset)`
- `fetchMediaListFiltered($filters)`
- `fetchMediaList()`

Over time, these methods drifted and ended up containing duplicated SQL with inconsistent join semantics (some paths used `LEFT JOIN song_files/files`, which produced "no-file" rows). The chosen invariant for Media Library is:

- **Media Library should show only rows that have an associated file.**

This invariant is best encoded in SQL by using:

- `INNER JOIN song_files` and `INNER JOIN files`

## Goals

- Ensure **a single authoritative SQL definition** for the Media Library dataset.
- Prevent future drift (no reintroduction of `LEFT JOIN song_files/files` in one code path).
- Keep behavior identical across:
  - HTML (`list()`) and JSON (`listJson()`) outputs
  - paginated and non-paginated modes
  - both `APP_FLAVOR`s

## Non-goals

- Do not implement metadata pruning (deleting `songs`, `sessions`, `session_songs`).
- Do not change the Media Library UI or column layout.
- Do not change pagination thresholds or request parameters.

## Proposed approach

### 1) Introduce a single SQL builder for the Media Library

Add one private method in `SessionRepository` to build the core SELECT with consistent joins:

- **Option A (preferred):** return a SQL string + parameter list

  - `private function buildMediaListQuery(array $filters, ?int $limit, ?int $offset): array`
    - returns `[$sql, $params, $bindSpec]`

- **Option B:** return the base SQL fragments and let callers assemble

  - `private function mediaListSelectSql(): string`
  - `private function mediaListFromSql(): string`
  - `private function mediaListGroupOrderSql(): string`

Whichever is chosen, the resulting SQL must:

- Use `JOIN song_files sf` and `JOIN files f`
- Include the same selected columns currently used by the view/controller
- Preserve:
  - `GROUP_CONCAT(DISTINCT m.name ...) AS crew`
  - `GROUP BY sesh.session_id, s.song_id, f.file_id`
  - `ORDER BY sesh.date DESC`

### 2) Make the public methods thin wrappers

After the builder exists, re-implement these methods to call it:

- `fetchMediaList()`
  - calls builder with empty filters and no limit/offset
- `fetchMediaListFiltered($filters)`
  - calls builder with filters and no limit/offset
- `fetchMediaListPage($filters, $limit, $offset)`
  - calls builder with filters and limit/offset

`countMediaListRows($filters)` can either:

- continue to wrap the media query in a `SELECT COUNT(*) FROM ( ... ) t`, or
- use a dedicated builder that shares the exact same `FROM/JOIN/WHERE` fragments.

### 3) Remove redundant/legacy SQL blocks

Once all methods delegate to the builder, remove any duplicated SQL strings that previously lived inside each method.

This is the critical outcome: **one SQL definition**.

## Verification plan

### Functional checks

- `/db/database.php` renders and filters as before.
- `/db/database.php?format=json` returns:
  - no entries with missing `file_id` (no `id: 0` artifacts)
  - consistent `pagination` block

### Regression checks

- Filtering still works for:
  - `date`, `org_name`, `song_title`, `file_name`, `source_relpath`, etc.
- Pagination mode still works above threshold.
- Confirm both non-paginated code paths are covered:
  - no filters (`fetchMediaList()`)
  - filters present (`fetchMediaListFiltered()`)

### Operational checks

- Deploy and restart the PHP/Apache container if OPcache is enabled.

## Rollout strategy

- Implement refactor behind the same public method signatures (no controller changes).
- Verify on dev:
  - delete a file, then confirm the Media Library hides the row
  - verify JSON output matches expectations
- Promote to staging and verify the same.

## Follow-up cleanup (optional)

Once stable, consider:

- removing the now-redundant `f.file_id IS NOT NULL` condition from `buildMediaListFilters()` (if inner joins are guaranteed everywhere)
- tightening typing/return normalization so missing DB values do not get cast to `0` in output
