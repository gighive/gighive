# Refactor Plan: DB UI Based on Personas

## Goal

Reduce complexity caused by supporting two different audiences through the same database UI stack:

- `APP_FLAVOR=gighive`
- `APP_FLAVOR=defaultcodebase`

The current implementation mixes persona differences across:

- view rendering
- client-side edit behavior
- save endpoint payloads and responses
- assumptions about which fields exist and which workflows matter

The goal of this refactor is to make persona-specific behavior explicit, testable, and easier to evolve.

## Current Problems

- A single `APP_FLAVOR` switch controls multiple concerns at once.
- UI behavior and server response shape must remain synchronized by convention.
- The media library page is serving partially different product meanings for different audiences.
- Feature growth for one persona increases risk for the other.
- Hidden or incomplete database rows are handled implicitly by query structure rather than explicitly by product rules.

## High-Level Direction

## 1. Separate persona from capability

Replace broad flavor-driven branching with explicit capability decisions.

Examples:

- can edit musicians
- can edit extended session metadata
- can view incomplete rows
- can use simplified media librarian layout
- can use performer/session metadata layout

This allows behavior to be reasoned about feature-by-feature instead of treating `APP_FLAVOR` as a catch-all.

## 2. Define a stable response contract for edit/save flows

The save endpoint should return a consistent response shape regardless of persona.

Principles:

- always return the fields the UI may re-render
- use `null` or empty values intentionally rather than omitting fields unpredictably
- keep client-side repaint logic independent from hidden flavor assumptions

This reduces accidental drift between PHP and JavaScript.

## 3. Clarify page semantics

Decide what `db/database.php` is supposed to represent.

Possible choices:

- a pure media-backed library view
- an admin data integrity view that also shows incomplete rows
- a shared shell with persona-specific subviews

This should be explicit because query design, editing rules, and user expectations all depend on it.

## 4. Introduce persona-specific view composition

Keep shared infrastructure where it genuinely helps, but split persona-facing concerns more cleanly.

Possible structure:

- shared repository/query layer where valid
- shared controller plumbing for auth, pagination, and common request parsing
- persona-specific view models or presenters
- persona-specific partials or page templates where workflows differ materially

This avoids one template and one script becoming the dumping ground for both products.

## 5. Make incomplete-data handling explicit

Current hidden-row behavior is largely a side effect of inner joins.

Refactor toward explicit rules:

- whether orphan sessions should be visible
- whether song rows without files should be visible
- whether these appear only in admin/diagnostic mode
- how such rows should be labeled in the UI

This should be product policy, not an accidental SQL consequence.

## 6. Reduce cross-layer coupling

Today, one feature often requires coordinated edits in:

- PHP view rendering
- JavaScript DOM update logic
- AJAX response payloads
- SQL assumptions

Refactor goal:

- centralize field definitions
- centralize editable column metadata
- centralize which columns are persona-enabled

This lowers the chance of partial implementations.

## 7. Add targeted verification coverage

Add lightweight verification around the high-risk seams:

- edit/save behavior by persona
- expected response payload shape
- rendering of editable versus non-editable columns
- visibility rules for incomplete rows

The goal is not broad test volume, but focused protection around the dual-persona boundary.

## Suggested Phasing

## Phase 1: Inventory and contract definition

- document persona differences
- document current field/edit capabilities
- define the canonical save response contract
- define the intended semantics of the media library page

## Phase 2: Capability extraction

- replace broad flavor checks with explicit capability flags
- move capability decisions closer to configuration/bootstrap logic
- reduce inline branching inside templates and endpoint handlers

## Phase 3: View/model split

- extract persona-specific rendering concerns
- isolate shared table infrastructure from persona-specific columns and actions
- isolate shared edit plumbing from persona-specific field sets

## Phase 4: Query and data-integrity clarity

- make incomplete-row handling intentional
- separate diagnostic/integrity views from normal media browsing if needed
- document which queries are media-centric versus admin-diagnostic

## Phase 5: Hardening

- add targeted tests or verification scripts
- add docs for future contributors explaining persona boundaries

## Desired End State

- persona differences are explicit and easy to trace
- shared code is genuinely shared, not overloaded
- UI refresh behavior is consistent across personas
- query behavior matches declared product intent
- adding a field for one persona does not silently break the other

## Open Questions for Later

- Should `gighive` and `defaultcodebase` continue sharing one page at all?
- Should incomplete rows live in the main media library or only in diagnostics?
- Should persona be modeled as branding, capability set, workflow mode, or separate products?
- What is the minimum shared contract that both personas actually need?
