# Refactor Plan: `admin.php`

## Context / Goals

`ansible/roles/docker/files/apache/webroot/admin.php` is currently a single large PHP file that mixes:

- Server-side PHP templating
- A large HTML document with multiple “sections” of admin functionality
- A substantial amount of inline JavaScript that manages UI state, performs fetch requests, and renders status/output

The goal of this refactor plan is to make the admin page easier to maintain and safer to change by reducing duplication, isolating responsibilities, and standardizing patterns.

## Guiding Principles

- Keep behavior identical while refactoring (no UX changes unless explicitly intended).
- Prefer small, incremental changes that are easy to review.
- Centralize shared logic (fetch wrappers, rendering helpers, UI state transitions).
- Reduce string-concatenated HTML where feasible.

## Current Pain Points

- Repeated `fetch(...).then(...).catch(...)` request/response parsing patterns.
- Repeated “success” and “error” banner rendering patterns.
- Repeated button state transitions (disable/enable, label changes, styling).
- Inline HTML string building (`innerHTML = '...'`) scattered across the file.
- Large monolithic functions that handle multiple responsibilities (validate, compute, render, upload, poll, etc.).
- Mixed escaping practices (some messages escaped, some not).

## Proposed Refactor (Incremental)

### Phase 1: Standardize Common UI Rendering

- Add shared helpers:
  - `escapeHtml(str)`
  - `renderMutedBanner(message)`
  - `renderErrBanner(message)`
  - `renderOkBanner(message)`
  - `renderDbLinkButton(label)`
  - `renderOkBannerWithDbLink(message, label)`

**Outcome**: All status boxes are rendered consistently and safely. Styles and link behaviors are defined in one place.

### Phase 2: Standardize Fetch + JSON Parsing

- Add a single wrapper:
  - `fetchJson(url, options)` returning `{ ok, status, data }`
  - Handle JSON parse errors consistently

- Replace ad-hoc `fetch` code in each section with the wrapper.

**Outcome**: Networking is predictable, error handling is uniform, and changes to request logic happen in one place.

### Phase 3: Centralize Button / UI State Handling

- Add helpers for frequently repeated button operations:
  - `setButtonLoading(btn, label)`
  - `setButtonSuccessLocked(btn, label)`
  - `setButtonIdle(btn, label)`

- Add per-section state setters (optional):
  - `setSectionStatus(el, html)`

**Outcome**: UI state changes become consistent and reduce copy/paste bugs.

### Phase 4: Reduce Duplicate Code Paths

- Identify duplicated flows and consolidate:
  - Section 5 “add-to-database” success handling appeared in multiple branches; similar patterns exist elsewhere.

- Use shared “render result” functions:
  - `renderImportSuccess(data)`
  - `renderImportError(data)`
  - `renderAddSuccess(data)`

**Outcome**: “One source of truth” for each section’s rendering and state transitions.

### Phase 5: Split JavaScript Into Separate Files (Optional but Recommended)

Move the inline `<script>` into dedicated JS files, grouped by feature:

- `admin.shared.js` (helpers: fetchJson, escapeHtml, banners, button state)
- `admin.section2.clear-media.js`
- `admin.section3.csv-import.js` (3A/3B)
- `admin.section5.hash-and-add.js`
- `admin.section7.restore.js`

**Outcome**: Smaller units of code, easier review, easier future changes.

### Phase 6: Move Inline Styles to CSS Classes (Optional)

- Create CSS classes for repeated styles:
  - “db-link button”
  - “success locked” buttons
  - warning/success/error banners

**Outcome**: Shorter diffs and fewer string-literal style copies.

### Phase 7: Improve Separation of Markup From Logic (Optional)

- Consider using `<template>` tags for repeated HTML fragments instead of string concatenation.
- Prefer DOM creation over `innerHTML` where practical.

**Outcome**: Less fragile rendering and easier future UI changes.

## Test Plan (Regression)

For each refactor phase, re-test:

- Section 2: Clear Sample Media Data
- Section 3A: Legacy CSV import + reload
- Section 3B: Normalized sessions + session_files import + reload
- Section 5: Hash/scan folder + add-to-database
- Section 7: Restore from backup + log polling

Also verify:

- Error states (invalid input, server errors, network errors)
- Button states (disabled/enabled, label changes, “success locked” behavior)
- Links (database links open as intended)

## Risk Notes

- Refactors that touch rendering are prone to subtle regressions in string concatenation order and spacing.
- Changes to escaping must be tested with typical and edge-case server messages.
- Splitting JS into separate files may require careful deployment/Ansible packaging to ensure assets are deployed.
