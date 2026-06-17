# Refactor: Catalog Editing Tool UX Improvements

## Status

Implemented. Single file changed: `db/database_catalog.php`.

---

## Overview

A set of UX improvements to `db/database_catalog.php` (the Catalog Editing Tool). The changes bring the page in line with the `db/database.php` (Media Library) UX conventions, add server-side per-column search, fix default filter state, and replace non-functional button-based bulk actions with a proper checkbox-driven bulk action toolbar.

---

## Implementation Context

`db/database.php` is the **reference model** for UX patterns and behavior — not a code-sharing source. The two pages share no includes, templates, or stylesheets.

| Piece | Source in `database.php` | Approach for `database_catalog.php` |
|---|---|---|
| PHP boolean parser (`\|`/`&`/`!`) | `EventRepository::buildEventFilters()` | Written as self-contained `buildCatalogSearch()` (~65 lines); uses positional `?` PDO params — not named `:param` style as in `EventRepository` — to avoid PDO's constraint against mixing param styles in a single statement |
| JS patterns (checkbox wiring, `requestSubmit()`, reload-on-delete, `type="button"` guards) | `src/Views/media/list.php` JS | Written fresh, modeled on `list.php` |
| CSS | `list.php` light-theme stylesheet | `database_catalog.php` existing dark-theme inline CSS — no changes needed |

All implementation is contained in a single file: `db/database_catalog.php`.

---

## Changes

### Files Changing

| File | Nature of change |
|---|---|
| `ansible/roles/docker/files/apache/webroot/db/database_catalog.php` | **PHP** — title rename, default filter, search parser, query WHERE clauses, `qp()` update, clear links, instructional block, second paragraph; **HTML** — unified `<form>`, `<th>` search inputs, bulk action toolbar, remove `.bulk-bar`; **JS** — checkbox wiring, `updateBulkUi()`, bulk delete, bulk set-status with skip guard, Enter-key submit |

No new files. No new endpoints. No schema changes.

---

### 1. Page Title Rename

- **`<title>`** and **`<h1>`**: `"Catalog Media - Database Editing Tool"` → `"Catalog Editing Tool (edit the list of files to be uploaded)"`

---

### 2. Default Filter State

- The `is_supported` dropdown defaults to **Supported only** (`'1'`) when no `is_supported` GET param is present.
- Implementation: replace `?? ''` with an `isset()` check:
  ```php
  $filterSupp = isset($_GET['is_supported']) ? trim((string)$_GET['is_supported']) : '1';
  ```
- Navigating to `/db/database_catalog.php` with no params shows Supported only.
- Users can explicitly select "All" or "Unsupported only" from the dropdown.
- The **"Clear filters"** link preserves any active `q_*` search params but strips all dropdown params, landing back on the `is_supported=1` default. It does not clear search state.

---

### 3. Per-Column Search (server-side)

Matches the UX convention from `db/database.php` — search inputs sit inside the `<th>` cells below the column label. Search only applies to fields that are **visible as columns** in the table. No separate search panel for hidden metadata fields.

#### Searchable columns and GET params

| Column | GET param | SQL condition |
|---|---|---|
| File | `q_file` | `LOWER(file_name) LIKE %…%` |
| File | `q_relpath` | `LOWER(source_relpath) LIKE %…%` |
| Org / Date | `q_org` | `LOWER(org_name) LIKE %…%` |
| Org / Date | `q_date` | `LOWER(CAST(event_date AS CHAR)) LIKE %…%` |

The File column shows both `file_name` (bold) and `source_relpath` (muted, below) in each cell. Two stacked inputs appear under the File header — one per field — matching the pattern `database.php` uses for separate columns. Each maps to a single SQL column, so the standard single-param-per-term parser applies with no special cases.

The Org / Date column shows both `org_name` and `event_date`, so two stacked inputs (`q_org` and `q_date`) appear under that header.

Type, Size, Modified, Status, Scan, and Actions columns: no search inputs (Type and Status are already covered by the dropdown filter bar).

#### Operator syntax

Same as `db/database.php`:
- `|` — OR (e.g. `rock|jazz`)
- `&` — AND (e.g. `rock&guitar`)
- `!` — NOT prefix (e.g. `!rock`)
- Operators combinable; max 10 terms per field
- Invalid syntax (empty terms, `!!`, bare `!`) produces an inline error banner

#### Error display

Validation errors shown as a red block above the table, matching the existing `$dbError` style.

#### URL persistence

- The `qp()` helper is updated to carry `q_file`, `q_relpath`, `q_org`, `q_date` in all generated links (pagination).
- **"Clear filters"** link appears when `$filterStatus !== ''` OR `$filterType !== ''` OR `$filterSupp !== '1'` (i.e., any dropdown is not at its default value). It links to a URL that preserves all active `q_*` search params but strips all dropdown params (landing back on the `is_supported=1` default).
- **"Clear search"** link appears when any `q_*` param is active. It links to a URL that preserves all active dropdown filter params but strips all `q_*` params.
- The two links are fully independent — clearing one does not affect the other.

#### Instructional text block

A two-line instructional block is placed above the table (inside the form), matching the placement and style from `db/database.php`. Only the portions applicable to the catalog page are included:

```
Search will only take place after you fill in one or more fields and hit Enter.
| or & or ! allowed in search textboxes.  Pipe symbol means OR, ampersand means AND, ! means NOT.  You can combine these, but the ! takes precedence.  Precedence rule is ! > & > |.  Example: ".mp4&water&!ultra&!source"
```

Omitted from `database.php`'s version: column width/resize instructions, X removes column, checkbox minimizes column, sort-by-header, and Reset to Default View — none of those features exist on this page.

Styling: inline `<div>` with `class="muted"` and `font-size:.82rem` to match the catalog page's dark theme (no `header-block` class — that belongs to `list.php`'s light-theme stylesheet).

#### JS behavior

- Pressing **Enter** in any search input submits the form (same as `db/database.php`).

---

### 4. Checkbox-Based Bulk Actions

Replaces the non-functional checkbox column and the three vestigial bulk-bar buttons.

#### Remove

- The `.bulk-bar` div containing:
  - `"✓ Select all visible (supported)"`
  - `"✗ Skip all visible"`
  - `"Reset all visible to cataloged"`
- These buttons operated on all DOM rows regardless of checkbox state and are replaced entirely.

#### Wire up checkboxes

The existing `#chk-all` header checkbox and `.row-chk` per-row checkboxes are wired up:
- `#chk-all` toggles all `.row-chk` checkboxes on the current page.
- Checking/unchecking any `.row-chk` enables/disables the bulk action toolbar buttons.

#### Bulk action toolbar

Always visible, buttons **disabled by default**, enabled when ≥1 checkbox is checked — matching `db/database.php` convention.

A **"N checked"** status span sits next to the delete button and updates as checkboxes change (modeled on `database.php`'s `#deleteSelectedStatus` pattern, but labeled "checked" rather than "selected" to avoid confusion with the footer's `status = 'selected'` count).

Buttons:
- **Delete checked** — shows confirm dialog `"Delete N catalog entries?"` → calls `catalog_entry_save.php` with `action: 'delete'` sequentially for each checked ID → on completion calls `window.location.reload()` (matches `database.php` post-delete reload; ensures pagination counts and footer totals refresh correctly).
- **Set status: `[dropdown]` → Apply** — dropdown options: Cataloged / Selected / Skipped; iterates checked rows and skips any whose current status is `imported` or `failed` (client-side guard, matching the per-row dropdown behavior which disables those options); calls `catalog_entry_save.php` with `action: 'save', status: <value>` for each eligible row → reloads on completion.

#### Unified search form

The filter dropdown bar and the table (with column search inputs) are wrapped in a **single `<form id="searchForm" method="get">`** — matching `database.php`'s structure exactly. Dropdowns keep `onchange="this.form.submit()"` to auto-submit on change. Search inputs submit on Enter via `form.requestSubmit()`.

#### Bulk action element constraints

- The bulk-status dropdown (Set status) must have **no `name` attribute** — otherwise it serializes into GET params when the search form submits, polluting the URL.
- All bulk action buttons (Delete checked, Apply) must be **`type="button"`** explicitly to prevent accidental form submission.

#### Scope

Bulk actions operate on **checked rows on the current page only** (client-side). A server-side "select all matching filters" across pages is deferred.

### 5. Second Explanatory Paragraph

A second `<p class="muted">` is added directly below the existing description paragraph. It covers the three gaps identified in the UX review:

1. **Status meanings** — what each status value means in terms of upload outcome
2. **Default filter** — why files may appear to be missing on first load
3. **Bulk checkbox workflow** — how to efficiently mark many files at once

Implemented text:

> File statuses control what gets uploaded: **Selected** entries will be included in the next upload; **Skipped** entries are excluded; **Cataloged** means the entry has not yet been reviewed. This page defaults to showing supported file types only — use the filter bar to show all files including unsupported types. To mark many files at once, use the checkboxes to select rows (or the header checkbox to check all on this page), then use the bulk Set Status toolbar to apply a status to all checked entries.

Styling: same `<p class="muted">` as the existing paragraph, no additional CSS needed.

---

## Implementation Notes

Two design decisions made during implementation that differ from the original plan:

1. **Positional vs. named PDO params** — The plan referenced `EventRepository::buildEventFilters()` which uses named `:param` style. The existing file uses positional `?` params throughout. PDO does not allow mixing the two styles in a single statement, so `buildCatalogSearch()` was written to output positional `?` params instead. No impact on behavior.

2. **Search inputs absent when no entries are visible** — The search inputs live inside `<thead>`, which only renders in the `else` branch (when entries exist). If a filter or search combination returns zero rows, the table is replaced by the "no entries" message and the user cannot type a new search term. Recovery is via the "Clear search" and "Clear filters" links. This is the same structural constraint as `database.php` and is an accepted UX tradeoff of the in-header input pattern.

---

## Summary of Changes

**PHP**
- `$filterSupp` defaults to `'1'` (Supported only) via `isset()` check; only applies when the param is completely absent
- 4 search vars (`$qFile`, `$qRelpath`, `$qOrg`, `$qDate`) parsed at the top
- `buildCatalogSearch()` — self-contained `|`/`&`/`!` boolean parser returning positional `?` params
- Search WHERE clauses merged into the existing filter params array (only when no parse errors)
- `qp()` updated to carry all 4 `q_*` params; `$clearFiltersHref` and `$clearSearchHref` computed via `qp()` overrides

**HTML**
- `<title>` and `<h1>` renamed
- Second `<p class="muted">` added explaining statuses, default filter, and bulk checkbox workflow
- Single `<form id="searchForm">` wraps filter bar through table; pagination links remain outside as `<a>` tags
- Bulk toolbar (Delete checked + Set Status dropdown + Apply to checked) replaces old `.bulk-bar`
- Instructional text block (2 lines, operator syntax) added above table
- Search error banner renders on parse failure
- `<th>` cells for File and Org/Date contain stacked `<input type="text">` search inputs
- Each `<tr>` gains a `data-status` attribute used by the bulk skip guard

**JS**
- `updateBulkUi()` — enables/disables buttons, updates "N checked" span as checkboxes change
- `deleteChecked()` — single confirm dialog with count, sequential deletes, `window.location.reload()`
- `applyStatusChecked()` — reads `data-status` to skip `imported`/`failed` rows, sequential saves, reload
- `DOMContentLoaded` wires `#chk-all`, `.row-chk` checkboxes, delete/apply buttons, and Enter-key form submit on search inputs
