# SaaS Model Migration Plan

## Strategic Rationale

The recommended approach is **multi-tenant as the primary architecture, with
self-hosted as a first-class deployment mode** — the classic open-core model.

**Why keep self-hosted:**

- **Privacy moat** — bands and wedding clients who do not want their recordings
  on someone else's cloud will specifically seek out a self-hosted option. That
  is a real and underserved segment.
- **Trust signal for SaaS customers** — "you can self-host if you want to leave"
  is one of the most powerful things you can say to a prospective SaaS buyer. It
  removes lock-in fear entirely.
- **Open source community flywheel** — self-hosters file issues, contribute fixes,
  and become the most vocal advocates. Pure SaaS without open source loses that.
- **Low maintenance cost** — once the codebase is multi-tenant, the Ansible
  deployment track costs almost nothing to maintain. It is the delivery mechanism
  for the community edition.

**Why multi-tenant is the right primary target:**

- The vast majority of bands and wedding videographers will never self-host. They
  want to sign up, upload, and watch — zero ops burden.
- SaaS is where the revenue is. Without it, monetization is limited to consulting
  and support.
- Multi-tenant done properly *improves* the self-hosted version by forcing better
  data isolation, cleaner auth, and exportable data.

**The architectural good news:** if multi-tenancy is implemented with row-level
`tenant_id` isolation, a self-hosted install is simply multi-tenant with one tenant.
There is no separate codebase to maintain — only a `SAAS_MODE` env flag that controls
whether OIDC is required or a simplified local login is acceptable (preserving the
zero-config experience for self-hosters who do not want to register Google/Microsoft
app credentials just to run GigHive on a home server).

---

## Self-Hosted and SaaS: How They Coexist

A self-hosted install and the full SaaS platform run from the **same codebase**.
The difference is a single env flag — `SAAS_MODE` — and which track of changes
have been applied.

| Layer | `SAAS_MODE=false` (self-hosted) | `SAAS_MODE=true` (SaaS) |
|---|---|---|
| Schema | Identical — same tables, same `tenant_id`, seed tenant = 1 | Identical |
| Auth | Local PHP login form (replaces Basic Auth at step 8) | OIDC required (Google, Microsoft, Apple) |
| Tenants | Always one (`tenant_id = 1`) | Many; each with their own subdomain |
| Billing / quotas | Not enforced | Enforced via Stripe + plan limits |
| Subdomain routing | Not active | Wildcard `*.gighive.app` |

**Phase 1 schema changes are completely transparent to self-hosted installs.**
After Phase 1, a single-user install is multi-tenant with one tenant — the
application behaves identically to today.

**Phase 2 full SaaS features gate on `SAAS_MODE`**, which is established in Phase 1a. A self-hosted operator sets
`SAAS_MODE=false` and gets none of the SaaS-specific behaviour. They can
upgrade through any Phase 2 release safely, picking up bug fixes and
non-SaaS improvements without being forced into OIDC or multi-tenancy.

**The Basic Auth replacement gap (step 8):** When `SAAS_MODE=false` and step 8
ships, Apache Basic Auth is removed from the codebase. Self-hosted installs
must not be left without any auth mechanism. The `Auth.php` session gate must
include a local credential path for `SAAS_MODE=false`: a simple PHP login form
that validates against a hashed password stored in `.env` (or the `tenants`
row), sets the same `$_SESSION` keys that the OIDC path would set, and grants
the `owner` role for `tenant_id = 1`. This is intentionally minimal — it is
not a full user management system, just a drop-in replacement for the Basic
Auth gate that self-hosters already rely on. **This local login path must be
built as part of step 8**, not deferred, or every self-hosted install upgrading
through that release will be locked out.

---

## Do the Schema Work Before the First Real User

**The single most important takeaway from this document:**

The pre-release schema changes (steps 1–4) — update `create_media_db.sql`, migrate all environments, Ansible rebuild, and restore — put the `tenants` table, `tenant_id` FKs on 9 data tables, five unique-constraint fixes, the greenfield `users` table, and the QR upload tables in place. They are purely additive. They have no behavioral
impact on a single-user install. A seed tenant row is inserted, all existing rows
are backfilled with `tenant_id = 1`, and the application continues to work exactly
as before.

Doing this work *before* any public release means:

- No live migration on real user data.
- No risk of corrupting production records during a schema change.
- Every subsequent feature (OIDC, invite flow, plan enforcement) is built on a
  foundation that already supports multi-tenancy.

The auth changes (OIDC, invite links, role middleware) can and should ship
incrementally after the initial release. But the data model must be laid before
the first external user touches the system. Retrofitting `tenant_id` onto a live
database with real data and active uploads is the one migration that cannot be
done cheaply.

### Implementation Summary

**Phase 1 — SaaS-Ready Schema** *(pre-release)*
- Step 1: Update `create_media_db.sql` with all DB changes for SaaS-ready tenant schema and QR code upload feature; **git commit and push before starting Step 2** (same gate as rename doc step 8 — Ansible must have the updated file before the rebuild in Step 3)
- Step 2: *(repeat per environment)* Take pre-migration backup; apply combined migration via single docker command + DDL; take post-migration backup via `admin_system.php`
- Step 3: *(repeat per environment)* Run Ansible playbook to rebuild MySQL from `create_media_db.sql` — set `rebuild_mysql_data: true` in `group_vars` before running; verify the Step 2 post-migration backup is accessible first; reset `rebuild_mysql_data: false` in `group_vars` immediately after rebuild completes
- Step 4: *(repeat per environment)* Restore DB from the **post-migration** backup taken in Step 2 (the `admin_system.php` backup, not the pre-migration one); verify with `validate_app` + `upload_tests` once restore is confirmed good

> **Steps 2–4 follow the same backup → rebuild → restore pattern as the `music_db` → `media_db` rename** — see [`docs/refactored_database_rename_music_db.md`](refactored_database_rename_music_db.md) (Phase 2, steps 9–16) for the detailed per-environment bash commands and Ansible instructions. The key difference is that this migration sandwiches a DDL migration between two backups instead of one:
>
> | This doc (steps 2–4) | Rename doc (steps 9–16, excl. 10) |
> |---|---|
> | Step 2a: take **pre-migration** backup | Step 9: create fresh backup |
> | Step 2b: **apply DDL migration** *(unique to this migration)* | — |
> | Step 2c: take **post-migration** backup | *(rename has only one backup)* |
> | Step 3: set `rebuild_mysql_data: true` | Step 11: set `rebuild_mysql_data: true` |
> | *(implicit)* sync code to control machine | Step 12: `git pull` (lab/staging) |
> | Step 3: run Ansible deploy | Step 13: run Ansible deploy |
> | Step 4: restore from **post-migration** backup | Step 14: restore via Admin UI |
> | Step 3: reset `rebuild_mysql_data: false` | Step 15: set `rebuild_mysql_data: false` |
> | Step 4: verify with `validate_app` + `upload_tests` | Step 16: verify with `validate_app` |

**Phase 1a — Standalone Enhancements + SaaS Prerequisites** *(pre-release; ships with self-hosted)*
- Step 5: Per-event QR code upload links + anonymous upload form; `SAAS_MODE` env flag (group_vars → `.env.j2`) — see [`docs/feature_iphone_qr_code_support.md`](feature_iphone_qr_code_support.md) for full detail

**Phase 2 — Full SaaS Mode** *(post-release)*
- Step 6: Wildcard subdomain routing + Cloudflare TLS
- Step 7: OIDC federation — Google, Microsoft, Apple; JIT provisioning; ToS gate
- Step 8: RBAC middleware; remove Apache Basic Auth; local PHP login for `SAAS_MODE=false`
- Step 9: Signed contributor invite links (replace shared htpasswd)
- Step 10: Migrate file storage to tenant-scoped paths (`/<tenant-id>/`)
- Step 11: Public/private visibility enforcement (backend)
- Step 12: Tenant settings page + self-serve signup + onboarding UX
- Step 13: Storage quota tracking, enforcement, and dashboard bar
- Step 14: Stripe billing columns + webhook handling
- Step 15: Tenant suspension at auth middleware
- Step 16: Superadmin/operator console
- Step 17: Per-tenant data export + GDPR hard delete cascade
- Step 18: Rate limit config page (read-only)
- Step 19: Infrastructure upgrade — shared DB, object storage, Redis sessions, AI quota
- Step 20: Custom domain support *(deferred)*
- Step 21: Media provenance + blockchain anchoring *(deferred)*

---

### Migration Strategy

For existing environments, the schema changes are applied via in-place `ALTER TABLE`
statements rather than a rebuild from backup.

**Why not rebuild from backup:**
- Backups predate Phase 1, so a restore lands back at the old schema — the ALTER
  statements would still need to run after restore, so a restore buys nothing.
- Current test data (taggings, assets, events, etc.) is worth preserving for
  post-migration verification.

**Why in-place `ALTER TABLE` is correct:**
- Row counts are tiny — all ALTERs complete near-instantly with no lock contention.
- The `DEFAULT 1` on each new `tenant_id` column means no row needs to be touched
  before the FK is added; the backfill `UPDATE` statements are a safety net only.

**Two-track approach:**
1. **`create_media_db.sql`** — updated with the full Phase 1 schema so any future
   fresh install (new VM, CI, developer onboarding) comes up correctly without
   needing to run migrations.
2. **Migration script** — the `ALTER TABLE` SQL, run once in-place against all
   existing environments (dev, lab, staging, prod).

---

### Why Existing Code Is Unaffected

The doc's high-level claim of "purely additive / zero behavioral impact" holds because
every `INSERT` statement in the PHP codebase against the 8 affected tables uses
**explicit named column lists** — no positional `VALUES` anywhere. MySQL automatically
applies `DEFAULT 1` for the unspecified `tenant_id` on every existing `INSERT`.
No PHP page, API endpoint, or admin tool needs to change for Phase 1.

| File | Table | Pattern |
|---|---|---|
| `src/Repositories/AssetRepository.php` | `assets` | `INSERT INTO assets (checksum_sha256, file_ext, ...)` |
| `src/Repositories/EventRepository.php` | `events` | `INSERT INTO events (event_key, event_date, ...)` |
| `src/Services/UploadService.php` | `participants` | `INSERT INTO participants (name)` |
| `src/Services/UnifiedIngestionCore.php` | `ai_jobs` | `INSERT INTO ai_jobs (job_type, target_type, target_id)` |
| `api/ai_jobs.php` | `ai_jobs` | named columns (×3 call sites) |
| `api/taggings.php` | `taggings` | `INSERT INTO taggings (tag_id, target_type, ...)` |
| `admin/catalog_scan_start.php` | `catalog_scans` | named columns |
| `admin/import_manifest_upload_start.php` | `upload_jobs` | named columns |
| `admin/import_database.php` | `events` | named columns |
| `admin/import_normalized.php` | `events`, `assets` | named columns |
| `db/database_edit_save.php` | `participants` | named columns |

`SELECT` and `DELETE` statements are trivially unaffected by adding a column.

**The one deferred code change (step 8, not Phase 1):** The `DEFAULT 1` on `tenant_id`
is transitional. At step 8, when the RBAC middleware enforces `tenant_id` from session
context, the `DEFAULT` is dropped and every `INSERT` above must be updated to supply
the session's `tenant_id` explicitly. That work belongs to step 8, not here.

---

### Implementation Steps by Phase

**Phase 1 — SaaS-Ready Schema** *(do before first external release; zero behavioral impact on single-user mode)*

1. Update `create_media_db.sql` with all DB changes: `tenants` table, `tenant_id` FK on 9 data tables (with 5 unique-constraint fixes), greenfield `users` table, `event_upload_tokens` table, `anon_upload_attributions` table. Note: `tags` is intentionally kept as a global shared-vocabulary table — it does not receive `tenant_id`. Full DDL is in the Database Migration section.
2. *(Repeat per environment)* Take pre-migration backup; apply the combined migration via single docker exec command (see Database Migration section for the full DDL heredoc); take post-migration backup via `admin_system.php`. The `tenant_id` columns are added with `DEFAULT 1` — this backfills all existing rows automatically with no separate UPDATE needed. *The default is transitional and must be dropped at step 8 when RBAC middleware takes over enforcement. The existing `users` table is unused legacy with no data migration risk — it is replaced with the OIDC-native schema; auth is not wired until step 7.*
3. *(Repeat per environment)* Run Ansible playbook to rebuild MySQL from `create_media_db.sql` — set `rebuild_mysql_data: true` in `group_vars` before running; confirm the step 2 post-migration backup exists and passes `gzip -t` first — this step wipes the data volume. Reset `rebuild_mysql_data: false` in `group_vars` immediately after rebuild completes.
4. *(Repeat per environment)* Restore DB from the **post-migration** backup taken in step 2 (the `admin_system.php` backup, not the pre-migration one). The restore replaces the Ansible-built schema with the migrated data state. Once restore is confirmed good, git commit `create_media_db.sql` and any related changes.

**Phase 1a — Standalone Enhancements + SaaS Prerequisites** *(pre-release; ships with self-hosted)*

5. Per-event QR code guest upload links + `SAAS_MODE` env flag — owner generates a per-event QR code; guests scan to upload without an account; attribution recorded via ToS checkbox + optional display name; owner can revoke tokens and view guest-contributed uploads in the event admin page; iPhone with app installed uses iOS Universal Link → native app; Android and iPhone without app fall back to `db/upload_form_single.php`. `SAAS_MODE` flag gates Basic Auth (self-hosted, `false`) vs. OIDC (`true`); set via Ansible `group_vars` → `.env.j2`. *Does not depend on OIDC, RBAC, or subdomain routing — implement immediately after Phase 1.*

   → **Full implementation detail, sequenced task list, and test matrix:** [`docs/feature_iphone_qr_code_support.md`](feature_iphone_qr_code_support.md)

**Phase 2 — Full SaaS Mode** *(post-release; each step is independently shippable)*

> **iOS note:** Steps 6, 7, and 8 have parallel iOS app counterparts: step 6 (subdomain tenant routing), step 7 (OIDC login via Google/Apple), and step 8 (RBAC session enforcement). iOS App Store submission requires Sign in with Apple if any third-party login is offered (step 7). Track these as implementation subtasks within each step rather than separate steps.

6. Establish subdomain routing — wildcard DNS `*.gighive.app` + wildcard TLS (Cloudflare handles both); front controller extracts slug and resolves tenant; reserve blocked subdomains (`www`, `api`, `auth`, `admin`, `billing`, `login`, `signup`, etc.); single shared OIDC callback at `gighive.app/auth/callback` with tenant slug in `state` parameter; **CSRF protection: `state` must encode both the tenant slug AND a random nonce, and optionally an invite token reference** (e.g. JSON `{"slug":"band-foo","nonce":"<random>","invite_token_id":<id_or_null>}` base64-encoded), where the nonce is stored server-side in the pre-redirect session and verified on callback — without this the callback endpoint is forgeable; the `invite_token_id` field is null for self-serve signup and set to the `contributor_invite_tokens.token_id` for invite redemptions — the callback handler uses this to distinguish the two JIT provisioning paths (see step 9)
7. Implement OIDC federation — Google, Microsoft, Apple; Authorization Code Flow; single shared callback handler; JIT user provisioning on first login; **ToS/Privacy Policy gate fires here on any first login regardless of path**; **the callback handler owns `tenants` row creation for new self-serve signups** — `users.tenant_id` is `NOT NULL`, so the `tenants` row must be inserted atomically before the `users` row; the three provisioning paths are: (a) self-serve signup (`invite_token_id` null in state) → create `tenants` row → create `users` row with new `tenant_id`; (b) contributor invite (`invite_token_id` set) → validate invite token → look up existing `tenants` row → create `users` row; (c) returning user → find existing `users` row → no row creation; *GDPR/CCPA timing gap: data subject rights obligations begin the moment the first real user accepts ToS at this step. Hard delete (step 17) does not exist yet — any deletion request between steps 7 and 17 must be handled as a manual database operation* (self-serve signup, contributor invite, or direct login — every new `users` row must record ToS version + acceptance timestamp before the user reaches the application)
8. Build RBAC middleware — `Auth.php` session gate; enforce `owner` / `contributor` / `viewer` / `superadmin` on every page and API endpoint; **the middleware must check `tenants.is_public` before requiring a session** — requests for public-tenant content must pass through without login (the `is_public` column is already on `tenants` from step 1; the toggle UI is wired in step 12); **the QR upload route from step 5 must be explicitly exempted from the session gate** — it is secured by CSPRNG token hash validation (DB lookup), not by session; removing Basic Auth in this step must not break that endpoint; **`SAAS_MODE=false` local auth: a simple PHP login form must be shipped as part of this step** — it validates a hashed password from `.env`, sets the same `$_SESSION` keys as the OIDC path, and grants `owner` role for `tenant_id = 1`; without this, every self-hosted install upgrading through this release is locked out (see Coexistence section above); *drop the `DEFAULT 1` from all `tenant_id` columns at this step — the RBAC middleware now enforces tenant context on every request, so the transitional default added in step 2 is no longer needed and should be removed to prevent silent data leaks from any INSERT that omits `tenant_id`*
9. Replace shared htpasswd with signed contributor invite links — tenant owner generates; guest authenticates via any supported IDP; single-use and expiring; *Quota timing gap: uploads from steps 5 and 9 onwards accumulate without any measurement or enforcement until step 13 ships. Prioritize step 13 immediately after launch to close this window*
10. Migrate file storage to tenant-scoped paths; add session-gated media serving (PHP passthrough or X-Sendfile); migration scope includes all existing files including those uploaded via QR links in step 5 (which land in the flat filesystem pre-migration); ***breaking upgrade for all install types*** — self-hosted installs upgrading to any release containing this change must run the one-time file migration script to move existing media into `/<tenant-id>/` subdirectories; do not ship this without the migration script
11. Implement public/private content visibility — **backend only in this step**: wire `tenants.is_public` enforcement into the auth middleware (already built in step 8, which must have been designed `is_public`-aware); default is private (`is_public = 0`); the owner-facing UI toggle lives on the tenant settings page built in step 12; per-event granularity deferred as a future enhancement
12. Build tenant settings page + self-serve signup + onboarding flow — the tenant settings page is the owner UI shell; **this step wires the visibility toggle UI for step 11** (backend was done there, UI switch lives here), and provides the housing for the quota bar (step 13) and rate limit config (step 18) as those features are built; onboarding: OIDC signup creates tenant row; empty state shows brand message + two CTAs (import existing media / invite contributors); ToS acceptance is enforced at JIT provisioning (step 7), not here — this step covers the first-run UX only
13. Storage quota tracking — track per-tenant bytes used (blob storage is the cost driver; record count is negligible); surface usage bar on owner dashboard; enforce at upload time with a graceful error; alert at configurable threshold; *initial implementation measures local filesystem bytes — quota measurement mechanism must be updated in step 19 when object storage is adopted*
14. Wire billing — Stripe customer/subscription columns on `tenants`; webhook handling for plan changes
15. Implement tenant suspension — `is_active = 0` on the `tenants` row must cascade to a 403 at the auth middleware before any query runs; triggered by billing webhook (step 14) or manual superadmin action via the console (step 16); in-app banner shown to owner before full suspension; *note: between steps 15 and 16 the superadmin console does not yet exist — manual suspension in this window requires a direct DB UPDATE on `tenants.is_active`; document this in the ops runbook and treat it as a short-lived gap closed when step 16 ships*
16. Build superadmin/operator console — platform-level tenant list, usage metrics, suspend/reactivate controls
17. Per-tenant data export + GDPR hard delete cascade; *file deletion targets local filesystem until step 19 migrates to object storage — hard delete implementation must be updated at that point*
18. Rate limiting — existing Apache rate limit config carries forward as-is; surface current limits on a read-only configuration page (grayed out); future SaaS option allows tenants to adjust their own limits within plan-defined bounds
19. Upgrade infrastructure — shared DB with row-level isolation, object storage (S3 / Azure Blob), Redis/DB-backed sessions, AI worker quota awareness; update quota measurement (step 13) and hard delete (step 17) to target object storage at this point
20. Custom domain support *(deferred)* — Cloudflare Custom Hostnames lets a tenant point `media.theirband.com` → `theirband.gighive.app` with automatic cert issuance; not required for v1
21. Media provenance + blockchain anchoring *(deferred — requires own design doc)* — pseudonymous identity fingerprint (HMAC of IDP `sub` with GigHive-held key) anchored alongside `checksum_sha256` + upload timestamp via OpenTimestamps (Bitcoin-anchored, no gas fees); third-party verifiable assertion that "this content, in this exact form, was uploaded by this verified-but-pseudonymous identity, at this time"; real-name vs. verified-badge UX decision deferred; prerequisites already in place: OIDC-verified identity (step 7), ToS acceptance (step 7), content hash (`checksum_sha256` on `assets`), upload timestamp

---

## Overview

This document describes what would need to change to move GigHive from its current
single-tenant, self-hosted architecture to a multi-tenant SaaS model. It is intended
as a design reference, not an implementation spec — each section should be scoped
into its own feature doc before work begins.

The current architecture is a single-instance deployment: one Docker Compose stack,
one MySQL database, one filesystem, and one Apache Basic Auth user (`admin`). Every
table, file, and job belongs implicitly to that single instance. Nothing in the data
model enforces tenant isolation.

---

## Current Architecture — Single-Tenant Assumptions

| Concern | Current state |
|---|---|
| Auth | Apache Basic Auth via `.htpasswd`; one `admin` user per install |
| Database | Single DB (`media_db`), no tenant column on most tables |
| `org_name` on `events` | Free-text label, not a FK — used as a discriminator but not enforced |
| File storage | Flat local filesystem under `/var/www/html/audio/` and `/var/www/html/video/` |
| `users` table | Exists (email/password/activation) but has no FK to any data table |
| `assets` | Global checksum deduplication — `uq_assets_checksum` is instance-wide |
| `participants` | Global pool — one participant name is unique across the whole DB |
| `upload_jobs`, `ai_jobs`, `tags`, `taggings` | No tenant reference at all |
| Deployment | One VM per install, provisioned by Ansible |

---

## Schema Changes
> **Required before first external release. Transparent to single-user mode.**
> These are purely additive schema changes. The application behaves identically
> after them. A single-user install simply has one tenant row with `tenant_id = 1`.

**Goal:** Establish a canonical tenant identity before touching any other layer.

### Schema changes

1. Add a `tenants` table:
   ```sql
   CREATE TABLE tenants (
     tenant_id              int unsigned NOT NULL AUTO_INCREMENT,
     slug                   varchar(64)  NOT NULL,        -- URL-safe identifier, becomes subdomain
     display_name           varchar(255) NOT NULL,
     plan                   enum('free','pro','enterprise') NOT NULL DEFAULT 'free',
     is_active              tinyint(1)   NOT NULL DEFAULT 1,
     is_public              tinyint(1)   NOT NULL DEFAULT 0, -- tenant-level public/private toggle
     stripe_customer_id     varchar(64)  DEFAULT NULL,    -- reserved; wired in step 14
     stripe_subscription_id varchar(64)  DEFAULT NULL,    -- reserved; wired in step 14
     plan_expires_at        datetime     DEFAULT NULL,    -- reserved; wired in step 14
     created_at             datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_at             datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (tenant_id),
     UNIQUE KEY uq_tenants_slug (slug)
   );
   ```

2. Add `tenant_id` FK to the following data-owning tables (`tags` is the one exception — kept as global shared vocabulary, no `tenant_id` added):
   - `events` — add `tenant_id int unsigned NOT NULL`, drop the current
     `uq_events_date_org` unique key, replace with
     `UNIQUE (tenant_id, event_date, org_name)`.
   - `assets` — add `tenant_id int unsigned NOT NULL`, change
     `uq_assets_checksum` to `UNIQUE (tenant_id, checksum_sha256)` so the
     same file can be stored by two tenants without collision.
   - `participants` — add `tenant_id`, change `UNIQUE name` to
     `UNIQUE (tenant_id, name)`.
   - `upload_jobs` — add `tenant_id`.
   - `ai_jobs` — add `tenant_id`.
   - `tags` — keep global (no `tenant_id`); see note below.
   - `taggings` — add `tenant_id` as a denormalised scoping column; also fix
     `uq_taggings_tag_target` from `UNIQUE (tag_id, target_type, target_id)` to
     `UNIQUE (tenant_id, tag_id, target_type, target_id)` — the current constraint
     collides across tenants because `target_id` is a raw polymorphic integer.
     *Denormalised here means the tenant could technically be derived by following
     the FK chain (`taggings.run_id → helper_runs.job_id → ai_jobs.tenant_id`) for
     AI-created rows — but we store it directly on `taggings` anyway for query
     efficiency. We still add a proper FK constraint (`REFERENCES tenants`) because
     human-created taggings have `run_id = NULL` and have no chain to follow, so
     `tenant_id` is their only tenant anchor. The FK enforces referential integrity
     on all rows regardless of source.*
   - `catalog_scans` — add `tenant_id`; the `org_name` column here is free-text
     with no FK to `events`, so tenant scope cannot be derived from any existing
     FK chain.
   - `catalog_entries` — add `tenant_id`; same reason as `catalog_scans`; also
     fix `uq_catalog_entries_path_hash` from `UNIQUE (path_hash)` to
     `UNIQUE (tenant_id, path_hash)` — two tenants can have files at identical
     relative paths, producing the same hash.
   - `users` — do NOT add `tenant_id` here via ALTER; handled in step 3 below
     as a greenfield DROP/CREATE.
   - `helper_runs` — do NOT add `tenant_id`; tenant scope is derivable via
     `helper_runs.job_id` → `ai_jobs.tenant_id`.
   - `derived_assets` — do NOT add `tenant_id`; tenant scope is derivable via
     `derived_assets.run_id` → `helper_runs.job_id` → `ai_jobs.tenant_id`.
   - `event_items`, `event_participants`, `upload_job_files` — do NOT add
     `tenant_id`; tenant scope is derivable through their FK to `events` or
     `upload_jobs` respectively.

> **Tags vs. taggings tenant scoping note:** Tags like `genre:rock` are
> logically vocabulary items that could be shared across tenants. The
> simpler choice for SaaS v1 is to keep `tags` as a shared vocabulary table
> (no `tenant_id`) and put `tenant_id` on `taggings` only. This avoids tag
> duplication and keeps AI-derived vocabulary portable across the platform.

3. Drop and recreate `users` as a clean SaaS-native table:
   ```sql
   DROP TABLE IF EXISTS users;
   CREATE TABLE users (
     id              int unsigned  NOT NULL AUTO_INCREMENT,
     tenant_id       int unsigned  NOT NULL,
     idp_provider    varchar(32)   NOT NULL DEFAULT 'local'
                                   COMMENT 'google | microsoft | apple | local',
     idp_subject     varchar(255)  DEFAULT NULL
                                   COMMENT 'IDP sub/oid claim — globally unique per provider',
     role            enum('owner','contributor','viewer','superadmin')
                                   NOT NULL DEFAULT 'viewer',
     email           varchar(255)  DEFAULT NULL
                                   COMMENT 'Display/contact only — not an auth credential',
     display_name    varchar(255)  DEFAULT NULL,
     avatar_url      varchar(1024) DEFAULT NULL,
     tos_version     varchar(32)   DEFAULT NULL
                                   COMMENT 'ToS version accepted, e.g. "2024-01"',
     tos_accepted_at datetime      DEFAULT NULL
                                   COMMENT 'NULL means ToS not yet accepted',
     created_at      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_at      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (id),
     UNIQUE KEY uq_users_idp (idp_provider, idp_subject),
     -- Note: idp_subject must be enforced NOT NULL at the application layer for all
     -- OIDC-provisioned users. MySQL UNIQUE indexes treat each NULL as distinct, so
     -- two local/superadmin rows with idp_subject=NULL would both pass this constraint.
     -- Note: tenant_id is NOT NULL for ALL users including superadmins. Superadmins
     -- are assigned to the seed operator tenant (tenant_id = 1) by convention. This
     -- does not restrict their access — the RBAC middleware grants superadmins
     -- cross-tenant visibility regardless of their own tenant_id value. The bootstrap
     -- script must hardcode tenant_id = 1 when inserting the first superadmin row.
     KEY idx_users_tenant (tenant_id),
     CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   ```
   The existing `users` table is unused legacy (email/password/activation flow never
   wired to the live app). There is no data migration risk. All legacy columns
   (`password_hash`, `activation_token`, `reset_token`, `reset_expires`,
   `failed_logins`, `locked_until`, `is_active`) are intentionally omitted from
   the new schema. For SaaS v1, one user = one tenant (the band/wedding owner);
   multi-user per tenant is addressed in the Federated Identity and RBAC section via the contributor invite flow.

### Migration for existing data

- Insert one seed row into `tenants` for the single existing install.
- Backfill `tenant_id = 1` on all tables via `UPDATE … SET tenant_id = 1`.
- The existing `org_name` values on `events` remain valid as sub-labels
  within that tenant.

### Step 5 — Anonymous Upload Link Tables

These two tables are created as part of Phase 1 steps 1–4 (the combined DB migration). They do not need a direct `tenant_id` column — tenant scope is
derived through their FK chain.

```sql
CREATE TABLE event_upload_tokens (
  token_id    bigint unsigned NOT NULL AUTO_INCREMENT,
  event_id    int unsigned    NOT NULL,
  token_hash  char(64)        NOT NULL  COMMENT 'SHA-256 hex of the raw CSPRNG token; raw token is never stored',
  expires_at  datetime        NOT NULL,
  is_active           tinyint(1)      NOT NULL DEFAULT 1,
  created_by_user_id  int unsigned    DEFAULT NULL  COMMENT 'user_id of owner who generated the token; NULL pre-step-7 (Basic Auth era)',
  created_at          datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_id),
  UNIQUE KEY uq_event_upload_tokens_hash (token_hash),
  KEY idx_event_upload_tokens_event (event_id),
  KEY idx_event_upload_tokens_creator (created_by_user_id),
  CONSTRAINT fk_eut_event FOREIGN KEY (event_id)
    REFERENCES events (event_id) ON DELETE CASCADE
  -- fk_eut_created_by deferred to step 7: ALTER TABLE event_upload_tokens
  --   ADD CONSTRAINT fk_eut_created_by FOREIGN KEY (created_by_user_id)
  --   REFERENCES users (id) ON DELETE SET NULL;
  -- No users table exists in Phase 1a.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE anon_upload_attributions (
  attribution_id  bigint unsigned NOT NULL AUTO_INCREMENT,
  token_id        bigint unsigned NOT NULL,
  upload_job_id   varchar(64)     NOT NULL,
  display_name    varchar(255)    DEFAULT NULL  COMMENT 'Self-reported fan display name',
  tos_accepted_at datetime        NOT NULL      COMMENT 'Timestamp of anonymous ToS acceptance',
  created_at      datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attribution_id),
  KEY idx_anon_upload_token (token_id),
  KEY idx_anon_upload_job (upload_job_id),
  CONSTRAINT fk_aua_token FOREIGN KEY (token_id)
    REFERENCES event_upload_tokens (token_id) ON DELETE CASCADE,
  CONSTRAINT fk_aua_job FOREIGN KEY (upload_job_id)
    REFERENCES upload_jobs (job_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Federated Identity and RBAC
> **Post-release; required for SaaS sign-up. Breaking change to auth.**
> Self-hosted installs can remain on Basic Auth (via `SAAS_MODE=false`) indefinitely.
> This section enables a stranger to sign up and use GigHive without an admin
> provisioning them a password.

**Goal:** GigHive does not act as an identity provider. All authentication
is delegated to external IDPs via OpenID Connect (OIDC). GigHive only controls
*authorization* — what a verified identity is allowed to do.

The htpasswd gate is retired for SaaS. The entire legacy `users` table — including
`password_hash` and the email-based activation flow — was replaced with a clean
greenfield schema in the Schema Changes section (step 3). The existing `mail/register.php`
password-based registration UI is retired; account creation flows through IDP instead.
GigHive does not need to build or operate a transactional email infrastructure at v1
launch — see the Email section below for full rationale.

---

### Registration and Login Model

**How users register:** There is no GigHive-owned registration form. A user
taps "Continue with Google / Apple / Microsoft" and is redirected to their
IDP. On successful OIDC callback, GigHive creates a `users` row for that
identity if one does not already exist (JIT provisioning). That is the entire
registration flow — no password, no email verification step, no captcha.

**How users log in:** Same flow. OIDC handles credential verification,
MFA, and session security at the IDP. On mobile, Google Sign-In and Sign in
with Apple authenticate via Face ID / Touch ID against a phone-verified
account. GigHive never sees or stores a credential.

**Email is not required to launch SaaS.** GigHive collects the email address
from the OIDC identity claim and stores it as a contact field. It is never
used as a login credential.

**What does NOT require GigHive to send email:**

- **Billing receipts** — Stripe sends these automatically from its own
  infrastructure. Configure once in the Stripe dashboard. Zero code required.
- **Account recovery** — the user resolves this with their IDP (Google, Apple,
  Microsoft). Not GigHive's responsibility.
- **Invite links** — the owner copies a link from the UI and shares it via
  any channel they choose (SMS, WhatsApp, Slack, etc.). No email needed.
- **Job complete / export notifications** — handled as in-app status.
- **Plan suspension notice** — handled as an in-app banner.

**What would eventually benefit from email (deferred, not v1):**

- GDPR data deletion confirmation at scale (v1: manual support process)
- Digest / summary notifications for tenants who prefer email over in-app

**Decision: no transactional email provider at v1 launch.** Add one only
if user feedback after launch demonstrates clear demand. This removes
significant infrastructure complexity from the initial SaaS release.

**There is no GigHive password reset flow.** If a user loses access to their
IDP account, they resolve it with their IDP (Google, Apple, Microsoft) — not
with GigHive. This eliminates an entire class of credential-management
support burden.

---

### Terms of Service and Media Provenance

**ToS acceptance is explicit, not implied.** On first login (after JIT
provisioning), the user is presented with the GigHive Terms of Service and
Privacy Policy before reaching the application. Acceptance is recorded in the
`users` table with the ToS version string and acceptance timestamp. This is
not a "by continuing you agree" banner — it is a required gate.

**Rationale beyond legal compliance:** GigHive has a planned long-term
direction around media provenance and content authenticity — the ability to
assert "person X created this content at this time, and this is the
verifiable, unaltered original." A formal, timestamped ToS acceptance is
the first link in that chain:

- It establishes a legal record of the identity claim made at upload time.
- It creates a foundation for attribution: OIDC-verified identity +
  ToS acceptance + content hash (`checksum_sha256` already on `assets`) +
  upload timestamp = a traceable provenance record.
- Future direction: a pseudonymous identity fingerprint (HMAC of the IDP
  `sub` claim with a GigHive-held key) anchored alongside the content hash
  on a public blockchain (e.g., via OpenTimestamps against Bitcoin) would
  allow third-party verification of "this content existed, in this exact
  form, uploaded by this verified-but-pseudonymous identity, at this time"
  without exposing the user's real identity publicly.
- The balance between real identity and pseudonym ("show my name" vs.
  "show a verified creator badge") is a UX decision deferred to a future
  feature doc, but the data model must support both from day one.

This provenance direction is significant enough to warrant its own design
document. The ToS step here is the prerequisite that makes any provenance
claim legally defensible.

---

### 2a — Supported Identity Providers

**Tier 1 — Required at launch**

- **Google (Google Identity)** — largest coverage; handles personal Gmail and
  Google Workspace org accounts under the same OIDC endpoint. Most end users
  (band members, wedding guests, venue staff) will land here.
- **Microsoft Entra ID (Azure AD)** — essential for any business customer:
  wedding venues, booking agencies, studios with M365 tenants. Also covers
  personal Microsoft accounts. Full OIDC compliance.

**Tier 2 — High value for this use case**

- **Apple (Sign in with Apple)** — mandatory if the iOS upload app is ever
  submitted to the App Store (Apple requires SiwA on any app offering
  third-party login). Apple users already have Apple IDs, which aligns with
  the iPhone upload workflow. Note: Apple only sends the user's name on
  the *first* authorization; subsequent logins omit it — store the name
  from the first token.
- **Passkeys / WebAuthn** — not an IDP but the emerging passwordless standard
  pushed by both Google and Apple. Worth designing the auth layer to be
  passkey-aware from day one to avoid a retrofit later.

**Tier 3 — Defer**

- **Facebook/Meta** — bands live on Facebook, but Meta's OAuth is
  non-standard, their developer review is slow, and the ongoing maintenance
  burden is disproportionate for v1.
- **GitHub** — useful for self-hosters and developers; not relevant for band
  members or wedding guests.
- **Spotify** — interesting brand alignment but provides no auth value that
  Google doesn't already cover.

**Implementation library:** `league/oauth2-client` (PHP) has first-party
adapter packages for Google, Microsoft, and Apple. All three Tier 1/2 IDPs
are OIDC-compliant so the ID token validation logic is identical; only the
`/.well-known/openid-configuration` discovery URL differs per provider.
Use the Authorization Code Flow only — implicit flow is deprecated.

**App registration and credential storage:** Each IDP requires one platform-level
app registration (not per-tenant). Store `OIDC_GOOGLE_CLIENT_ID`,
`OIDC_GOOGLE_CLIENT_SECRET`, `OIDC_MICROSOFT_CLIENT_ID`,
`OIDC_MICROSOFT_CLIENT_SECRET`, `OIDC_APPLE_CLIENT_ID`, and
`OIDC_APPLE_TEAM_ID` + `OIDC_APPLE_KEY_ID` + `OIDC_APPLE_PRIVATE_KEY` as
env vars. Note: Apple does not use a client secret — it requires a
JWT signed with an ES256 private key that you generate from the Apple Developer
portal. The private key must be stored securely (not in `.env` on disk in
production; use a secrets manager). The redirect URI registered with each IDP
must exactly match `https://gighive.app/auth/callback`.

---

### 2b — `users` Table for Federated Identity

No migration SQL required here. The `users` table was dropped and recreated
as a clean greenfield schema in the Schema Changes section (step 3). The full DDL is in
the Schema Changes section. By the time this section ships, the table already
has `tenant_id`, `idp_provider`, `idp_subject`, `role`, `display_name`,
`avatar_url`, `tos_version`, and `tos_accepted_at`. This section only wires
those columns to the OIDC login flow and JIT provisioning logic.

`idp_provider` + `idp_subject` is the canonical identity key.
`email` is a display/contact field only — never an authentication credential.

---

### 2c — Three Legacy Roles Mapped to New RBAC

The old model had three implicit user types enforced entirely by Apache config:

| Old implicit type | How enforced today | New `role` value | Access level |
|---|---|---|---|
| `admin` | htpasswd `admin` user; `PHP_AUTH_USER === 'admin'` gate | `owner` | Full access to their tenant's data, settings, and imports |
| Uploader | Basic Auth on `/db/` with shared credentials | `contributor` | Upload media, fill in event metadata; no admin/system pages |
| Viewer | Public or Basic Auth depending on page | `viewer` | Read-only; browse events and media |
| (new) | — | `superadmin` | Cross-tenant visibility for GigHive platform operators |

**Key principle:** The IDP proves *who you are*. GigHive decides *what role
you have* within a tenant. Role assignment is always a local GigHive
operation — never derived from an IDP claim like an email domain.

---

### 2d — Contributor Invite Flow (replaces shared htpasswd)

The current model hands out a single htpasswd password to anyone who should
be able to upload — band members share the same credentials. The replacement:

1. Tenant `owner` generates a signed invite link via the admin UI.
2. The link encodes `tenant_id`, `role=contributor`, an expiry timestamp,
   and an HMAC signature.
3. Guest clicks the link → redirected to the OIDC login chooser (Google,
   Microsoft, Apple).
4. On successful OIDC callback, GigHive performs **just-in-time (JIT)
   provisioning**: if `(idp_provider, idp_subject)` does not exist, a new
   `users` row is created with the role from the invite token.
5. Invite link is single-use and expires.

This preserves the zero-friction model for fans/guests (they use an account
they already have) while giving the owner full visibility of who uploaded what.

**Schema (created at step 9):**
```sql
CREATE TABLE contributor_invite_tokens (
  token_id    bigint unsigned NOT NULL AUTO_INCREMENT,
  tenant_id   int unsigned    NOT NULL,
  token_hash  char(64)        NOT NULL  COMMENT 'SHA-256 of the raw signed token',
  role        enum('contributor','viewer') NOT NULL DEFAULT 'contributor',
  expires_at  datetime        NOT NULL,
  is_active   tinyint(1)      NOT NULL DEFAULT 1,
  created_at  datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_id),
  UNIQUE KEY uq_cit_hash (token_hash),
  KEY idx_cit_tenant (tenant_id),
  CONSTRAINT fk_cit_tenant FOREIGN KEY (tenant_id)
    REFERENCES tenants (tenant_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
The atomic single-use claim pattern from SEC-11 must be applied when redeeming
this token. The invite `token_id` must be included as `invite_token_id` in the
OIDC `state` JSON payload (see step 6 for the full state structure) so the
callback handler can resolve which tenant and role to assign.

---

### 2e — Session Layer and Auth Middleware

1. **`src/Middleware/Auth.php`** — called at the top of every protected page:
   - Calls `session_start()`.
   - Reads `$_SESSION['user_id']`, `$_SESSION['tenant_id']`, `$_SESSION['role']`.
   - Redirects to `/login` if session is absent.
   - Exposes `AuthContext::userId()`, `AuthContext::tenantId()`,
     `AuthContext::role()` as a request-scoped singleton.

2. **OIDC callback handler** (`/auth/callback.php`) — receives the
   authorization code, exchanges it for an ID token via the provider's
   token endpoint, validates the JWT signature against the provider's
   JWKS, extracts `sub`/`oid`, upserts the `users` row, and sets the
   session.

3. **Logout** — `session_destroy()` + redirect to the IDP's logout
   endpoint (important for shared-device scenarios).

4. **Remove Apache Basic Auth** from `httpd.conf` / VHost config for `/db/`
   and `/admin/`. The PHP session gate replaces it entirely.

5. **Protect the upload API** — the TUS upload endpoint currently has no
   auth. For SaaS, a tenant-scoped Bearer token (issued at session creation)
   must be passed in `Upload-Metadata` or as an `Authorization` header.
   The `/api/` endpoints need the same `Auth.php` check.

6. **Distributed sessions** (step 19 dependency) — PHP file sessions do not
   work when PHP-FPM runs on multiple nodes. Switch to database-backed or
   Redis sessions before horizontal scaling.

---

## Tenant-Scoped File Storage
> **Post-release; required for SaaS data isolation. Breaking change to file paths.**
> New self-hosted installs are unaffected — they get the scoped paths from day one.
> Existing self-hosted installs upgrading to this version need a one-time file
> migration script to move existing media into `/<tenant-id>/` subdirectories.

**Goal:** Files for tenant A must not be accessible via tenant B's URL and
must be cleanly separable for export or deletion.

### Current layout

```
/var/www/html/audio/<sha256>.mp3
/var/www/html/video/<sha256>.mp4
/var/www/html/video/thumbnails/<sha256>.jpg
```

All files share a flat namespace by content hash.

### Changes required

1. **Add tenant identifier to storage path** — use the numeric `tenant_id`,
   not the slug, as the directory name. The slug can change (tenant rename);
   the integer `tenant_id` never changes. Using the slug in the filesystem path
   would silently break all stored file references on any rename.
   ```
   /var/www/html/audio/<tenant-id>/<sha256>.mp3
   /var/www/html/video/<tenant-id>/<sha256>.mp4
   /var/www/html/video/thumbnails/<tenant-id>/<sha256>.jpg
   ```
   The subdomain-to-tenant resolution still uses the slug for URLs; only the
   on-disk path uses the stable integer ID.

2. **`FileStorage::ensureDir()`** already creates directories recursively;
   callers need to pass the tenant-scoped path. The `UploadService` and
   `UnifiedIngestionCore` both compute target paths — they need to accept
   a `tenantId` parameter (integer, not slug).

3. **Apache/Nginx access control** — static file serving (`/audio/`, `/video/`)
   must validate that the requesting session belongs to the tenant in the path.
   **Exception: public tenants** (`tenants.is_public = 1`, step 11) must serve
   media without requiring a session — the PHP gate must check `is_public` for
   the resolved tenant before enforcing the session requirement, consistent with
   the RBAC middleware behaviour defined in step 8.
   Options:
   - **PHP passthrough** (simplest): route all media requests through a PHP
     script that checks `is_public` or the session, then `readfile()`. Adds
     latency but is simple.
   - **`X-Accel-Redirect` (nginx) or `X-Sendfile` (Apache)**: PHP validates auth
     (or public bypass), sets the header, webserver serves the file directly.
     Best performance.

4. **`source_relpath`** on `assets` currently stores the original relative path
   of the file as ingested. In SaaS, this may need a `tenant_id` prefix or
   the column meaning should be clarified (it is metadata, not the served path).

5. **Checksum deduplication** — once `uq_assets_checksum` becomes
   `(tenant_id, checksum_sha256)`, two tenants uploading the same file will
   store it twice on disk. For SaaS cost efficiency, consider a separate
   `blob_store` table keyed by raw checksum with a many-to-one relationship
   to `assets` (content-addressable storage). This is a deferred optimisation,
   not a blocker.

---

## Tenant Lifecycle Management
> **Post-release; required for commercial SaaS operation from the first paying tenant.**
> Items 1–4 (registration, plan enforcement, billing, suspension) are needed before
> any paying user can sign up. Items 5–6 (export, hard delete) are needed before
> the platform has legal obligations to a real user base.

**Goal:** A tenant can sign up, be billed, be suspended, and have their data
deleted cleanly.

### Changes required

1. **Self-serve registration** — registration flows entirely through OIDC (step 7);
   there is no separate `/signup` form. The callback handler must distinguish two
   cases for a new `(idp_provider, idp_subject)` that has no existing `users` row:
   - **Self-serve signup** (no valid pending invite in the session): create a new
     `tenants` row (slug derived from display name, deduplicated), create the owner
     `users` row with `role = owner`, and provision the tenant storage directory.
   - **Contributor invite redemption** (valid `contributor_invite_tokens` record
     carried through the OIDC state parameter, step 9): create a `users` row with
     the `tenant_id` and `role` from the invite token; do NOT create a new `tenants`
     row. The invite token must be marked consumed atomically (SEC-11 pattern).
   Conflating these two paths — creating a new tenant for every unrecognised OIDC
   callback — would create a ghost tenant for every contributor invite redemption.
   ToS gate fires before the user reaches the application regardless of path.
   *No verification email* — identity is already verified by the IDP;
   PHPMailer and `mail/handle_register.php` are not used in the SaaS path.

2. **Plan enforcement** — `tenants.plan` drives limits (storage quota,
   upload count, AI job credits). A `TenantPlanService` should be the single
   place that checks limits before ingest/AI dispatch. *AI credit tracking:*
   for v1, AI credits are derived from the Stripe subscription entitlement
   (plan tier determines credits per billing cycle) rather than stored as a
   separate DB column; a `ai_credits_used` counter column on `tenants` should
   be added when AI billing is implemented in step 14.

3. **Billing integration** — `stripe_customer_id`, `stripe_subscription_id`,
   and `plan_expires_at` are already reserved as NULL columns in the `tenants`
   DDL (Phase 1 step 1). Step 14 wires them to the Stripe webhook handler.
   No schema migration is needed at billing time.

4. **Tenant suspension** — `is_active = 0` on the `tenants` row must cascade
   to a 403 at the auth middleware level before any data query runs.

5. **Tenant data export** — implement a `TenantExportService` that produces
   a ZIP of all assets + a JSON manifest of events/assets/tags for a given
   `tenant_id`. This is the equivalent of the existing
   `admin/export_media.php` but scoped to one tenant.

6. **Hard delete** — to support GDPR/right-to-erasure. The complete ordered
   delete sequence (respecting FK constraints) is:
   `anon_upload_attributions` → `event_upload_tokens` → `upload_job_files` →
   `derived_assets` → `helper_runs` → `ai_jobs` → `upload_jobs` → `taggings` →
   `catalog_entries` → `catalog_scans` → `event_items` → `event_participants` →
   `assets` → `events` → `participants` → `contributor_invite_tokens` → `users`
   WHERE `tenant_id = :id` → tenant storage directory → `tenants` row.
   See SEC-2 in the Security Considerations section for full detail and
   test requirements.

---

## Infrastructure Scaling
> **Post-release; ship when tenant count justifies the operational investment.**
> The Ansible/Docker Compose self-hosted model remains the delivery mechanism
> until this work is complete.

**Goal:** Many tenants on one set of infrastructure rather than one VM per install.

### Current deployment model

Ansible provisions one VM, deploys one Docker Compose stack (Apache + PHP-FPM +
MySQL), one `.env` file, one htpasswd. This is fine for self-hosted installs but
does not scale for SaaS.

### Options

**Option A — Shared single database (row-level isolation)**
All tenants in one MySQL instance, isolated by `tenant_id` foreign keys.
Simplest operationally; requires rigorous query discipline to never leak
cross-tenant data. Suitable for v1 SaaS.

**Option B — Schema-per-tenant**
One MySQL schema per tenant (`media_db_band_foo`, `media_db_band_bar`).
Strong isolation, simpler queries (no `WHERE tenant_id`), but schema
migrations must run N times and connection pooling is harder.

**Option C — Database-per-tenant**
Full isolation, easiest for GDPR hard-delete, but high operational overhead.
Not recommended until the tenant count justifies it.

**Recommendation:** Start with Option A. The `tenant_id` FK work in the Schema Changes section (steps 1–3)
is the prerequisite. Migrate to Option B only if a regulated enterprise customer
requires schema-level isolation.

### Other infrastructure changes

- **Single shared Docker Compose** (or Kubernetes) instead of one stack per VM.
- **Object storage** (S3 / Azure Blob) instead of local filesystem, so the web
  tier can scale horizontally without shared NFS.
- **Redis or database-backed sessions** instead of PHP file sessions (required
  when PHP-FPM runs on multiple nodes).
- **AI worker** — the existing Python worker polls a single DB. For SaaS it
  needs `tenant_id` on `ai_jobs` and must respect per-tenant job priorities/
  quotas. The `locked_by` column already exists for distributed locking;
  the `FOR UPDATE SKIP LOCKED` claim pattern in `db.py` carries over cleanly.
- **Ansible role** changes: the per-VM `installation_tracking` role and the
  single-install `.env.j2` template would be replaced by a centralised
  provisioning pipeline (Terraform + a control-plane API).

---

## Summary of Required Changes by Layer

| Layer | Change | Step |
|---|---|---|
| Schema | Add `tenants` table | 1 |
| Schema | Add `tenant_id` FK to all data tables | 2 |
| Schema | Fix `uq_assets_checksum` to be per-tenant | 2 |
| Schema | Fix `uq_events_date_org` to include `tenant_id` | 2 |
| Schema | Fix `UNIQUE name` on `participants` to include `tenant_id` | 2 |
| Schema | Fix `uq_taggings_tag_target` to include `tenant_id` | 2 |
| Schema | Add `tenant_id` to `catalog_scans` | 2 |
| Schema | Add `tenant_id` to `catalog_entries`; fix `uq_catalog_entries_path_hash` to include `tenant_id` | 2 |
| Schema | Drop legacy `users` table; CREATE clean SaaS-native `users` (tenant_id, idp_provider, idp_subject, role, email, display_name, avatar_url, tos_version, tos_accepted_at) | 3 |
| Upload | Per-event signed URL + QR code generator | 4 |
| Upload | `event_upload_tokens` table (token_hash, event_id, expires_at, is_active) | 4 |
| Upload | `anon_upload_attributions` table (upload_job_id, display_name, token_id) | 4 |
| Upload | Anonymous upload form with ToS acceptance checkbox | 4 |
| Auth | `SAAS_MODE` env flag | 5 |
| Routing | Wildcard DNS `*.gighive.app` + Cloudflare wildcard TLS | 6 |
| Routing | Front controller tenant slug resolver | 6 |
| Routing | Reserved subdomain blocklist | 6 |
| Routing | Single shared OIDC callback endpoint | 6 |
| Auth | Integrate Google OIDC (Authorization Code Flow) | 7 |
| Auth | Integrate Microsoft Entra ID OIDC | 7 |
| Auth | Integrate Apple Sign In | 7 |
| Auth | JIT user provisioning; ToS gate on every first login | 7 |
| Auth | `Auth.php` session middleware; `AuthContext` singleton | 8 |
| Auth | Propagate `tenant_id` through all repository queries | 8 |
| Auth | Remove Apache Basic Auth from `/db/` and `/admin/` | 8 |
| Auth | Protect upload API and TUS endpoint with session/Bearer token | 8 |
| Auth | Contributor invite-link flow (replaces shared htpasswd) | 9 |
| Schema | `contributor_invite_tokens` table (tenant_id, token_hash, role, expires_at, is_active) | 9 |
| Files | Migrate storage to tenant-scoped paths (including QR-uploaded files) | 10 |
| Files | Tenant-scoped media access control (PHP passthrough or X-Sendfile) | 10 |
| Files | Update `UploadService` and `UnifiedIngestionCore` for tenant path | 10 |
| Content | `is_public` column on `tenants` table (seeded in step 1 DDL) | 1 |
| Content | Tenant-level public/private visibility toggle UI | 11 |
| Lifecycle | Tenant settings page (owner UI shell for visibility, quota, rate limits) | 12 |
| Lifecycle | Self-serve signup + first-run onboarding UX | 12 |
| Lifecycle | Storage quota tracking (bytes); dashboard bar; upload-time enforcement | 13 |
| Lifecycle | Stripe billing columns; webhook handling | 14 |
| Lifecycle | Tenant suspension at auth middleware | 15 |
| Lifecycle | Superadmin/operator console | 16 |
| Lifecycle | Per-tenant data export + GDPR hard delete | 17 |
| Config | Rate limit config page (read-only; per-tenant adjustability deferred) | 18 |
| Infra | Shared DB with row-level isolation | 19 |
| Infra | Object storage (S3 / Azure Blob); update quota + hard delete targets | 19 |
| Infra | Redis/DB-backed sessions | 19 |
| Infra | AI worker `tenant_id` awareness and quota | 19 |
| Routing | Custom domain support via Cloudflare Custom Hostnames | 20 (deferred) |
| Provenance | Pseudonymous identity fingerprint + OpenTimestamps blockchain anchoring | 21 (deferred) |

---

## Security Considerations

The following security issues were identified during design review. Each is
rated by severity (Critical / High / Medium / Low) and carries a concrete
remediation. These must be addressed during implementation of the relevant step —
they are not deferred items.

### Critical

**SEC-1 — Stripe webhook signature verification (step 14)**
*Risk:* An unauthenticated POST to the webhook endpoint could trigger tenant
suspension or plan downgrades for any tenant.
*Remediation:* Before processing any webhook payload, verify the
`Stripe-Signature` header using `\Stripe\Webhook::constructEvent($payload,
$sigHeader, $signingSecret)`. The signing secret is a separate env var
(`STRIPE_WEBHOOK_SECRET`) distinct from the API key. Reject with HTTP 400 on
failure. Never process a webhook that fails signature verification.

**SEC-2 — GDPR hard delete table list is incomplete (step 17)**
*Risk:* `catalog_scans`, `catalog_entries`, `event_upload_tokens`,
`anon_upload_attributions`, and `upload_job_files` were added to the plan after
the hard delete cascade was drafted and are missing from it. A GDPR erasure
request would leave residual PII in these tables.
*Remediation:* The complete ordered delete sequence for a tenant hard-delete is:
`anon_upload_attributions` → `event_upload_tokens` → `upload_job_files` →
`derived_assets` → `helper_runs` → `ai_jobs` → `upload_jobs` → `taggings` →
`catalog_entries` → `catalog_scans` → `event_items` → `event_participants` →
`assets` → `events` → `participants` → `contributor_invite_tokens` → `users` →
tenant storage directory → `tenants`.
Write a unit test that asserts zero orphan rows in every table after executing
the cascade.

**SEC-3 — Superadmin provisioning path is undefined**
*Risk:* No described mechanism exists to create the first `superadmin` user.
Either the role is never provisioned (superadmin console is unreachable) or it
is created via an undocumented manual DB operation with no audit trail.
*Remediation:* Add a one-time bootstrap PHP CLI script (e.g.
`php bin/bootstrap-superadmin.php --idp=google --idp-subject=<sub>`) that
inserts the first superadmin row only when zero superadmin rows exist; exits
with an error if one already exists. GigHive does not use Laravel/Artisan —
do not use framework-specific tooling. Subsequent superadmin grants are made
via the superadmin console (step 16) by an existing superadmin. Document this
script in the deployment runbook. Superadmin rows must never be JIT-provisioned
— the role must be granted explicitly and only via this script or the console.

---

### High

**SEC-4 — Session fixation after OIDC login (step 7)**
*Risk:* If the PHP session ID is not rotated after successful authentication,
an attacker who obtained the pre-login session ID (shared device, network sniff
before HTTPS) retains a valid authenticated session.
*Remediation:* Call `session_regenerate_id(true)` immediately after writing
user data into `$_SESSION` in the OIDC callback handler, before any redirect.

**SEC-5 — Session cookie security flags not specified (step 7/8)**
*Risk:* Without explicit flags, PHP session cookies may be readable by
JavaScript (XSS pivot to session hijack), transmitted over HTTP, or submitted
cross-site.
*Remediation:* Set before every `session_start()`:
```php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '.gighive.app',  // leading dot covers all subdomains
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
```
Also set `session.gc_maxlifetime` appropriately (e.g. 8 hours) in `php.ini`
or via `ini_set`.

**SEC-6a — QR upload token security (step 4)**
*Design:* QR tokens are opaque CSPRNG tokens (32 bytes, base64url-encoded); the server stores only `SHA-256(raw_token)`. Security comes from 256-bit entropy — no HMAC signing key is required, managed, or rotatable. There is no `APP_HMAC_KEY` dependency for QR tokens.
*Risk:* Token entropy is only as strong as the CSPRNG. Using `rand()`, `mt_rand()`, or `uniqid()` would make tokens guessable.
*Remediation:* Use `random_bytes(32)` in PHP and `SecRandomCopyBytes` on iOS exclusively. Store only the hash. This is enforced in the implementation spec.

**SEC-6b — HMAC signing key management for contributor invite links (step 9)**
*Risk:* Contributor invite links rely on HMAC signatures. If the key is weak, hardcoded, or predictable, invite tokens can be forged.
There is no rotation strategy in the plan.
*Remediation:* Use env var `APP_HMAC_KEY` containing ≥32 bytes of
cryptographically random data (generated at deploy time via `openssl rand -hex
32`). Use `hash_hmac('sha256', $payload, $key)`. Document that rotating
`APP_HMAC_KEY` invalidates all outstanding invite links — warn
operators before rotation. Consider including a key version prefix in the token
(e.g. `v1:<hmac>`) so future rotation can support a brief overlap window.

**SEC-7 — OIDC JWT ID token validation specifics missing (step 7)**
*Risk:* Accepting an ID token without validating all claims allows token
replay, cross-client token injection, and expired token reuse.
*Remediation:* The callback handler must validate all of the following before
trusting `sub`:
- `iss` — must exactly match the provider's issuer URL
- `aud` — must contain your `client_id`
- `exp` — must be in the future (clock skew tolerance ≤ 60 s)
- `iat` — must not be in the future (clock skew tolerance ≤ 60 s)
- `nonce` — must match the value stored in the pre-redirect session
  (see SEC-CSRF note in step 6)
`league/oauth2-client` does not validate JWTs itself — use `firebase/php-jwt`
or `web-token/jwt-framework` for verification.

**JWKS caching is mandatory.** Each ID token must be verified against the
provider's public keys fetched from their JWKS URI. Fetching JWKS on every
login adds latency and will hit IDP rate limits under load. Cache the JWKS
response in a shared store (database or Redis) with a TTL matching the
provider's `Cache-Control` header (typically 1–24 h). Refresh the cache
only on a cache miss or on JWT signature verification failure (key rotation
fallback). Never fetch JWKS inline on the critical login path without a cache.

**SEC-8 — Private media files are content-addressed; SHA-256 names are derivable (step 10)**
*Risk:* A private tenant's files are served from
`/<tenant-slug>/<sha256>.mp4`. If an attacker obtains a file hash (from any API
response, a leaked DB dump, or by knowing the content), they can construct the
path. Direct Apache static file serving must be disabled for media directories
or the path is accessible without a session check.
*Remediation:* Disable Apache `DirectoryIndex` and `Options Indexes` for
`/audio/` and `/video/`. All media requests must be routed through the PHP
access-control passthrough or `X-Sendfile`. Add an Apache deny rule as a
defence-in-depth backstop:
```apache
<Directory "/var/www/html/audio">
    Require all denied
</Directory>
```
Only the PHP passthrough script should be allowed to serve those files.

---

### Medium

**SEC-9 — Anonymous upload display names are unvalidated user input — stored XSS (step 4)**
*Risk:* `anon_upload_attributions.display_name` is self-reported by an
unauthenticated guest. If displayed in the admin UI without output escaping, it
is a stored XSS vector.
*Remediation:* (a) Sanitize at write time: strip or reject any HTML/JS.
(b) Enforce length limit (e.g. 100 chars) in both the upload form and the DB
insert. (c) Escape on output with `htmlspecialchars($name, ENT_QUOTES, 'UTF-8')`
wherever `display_name` is rendered. (d) Set a `Content-Security-Policy` header
on all pages that render `display_name` — this is a requirement, not optional.
See implementation spec `docs/feature_iphone_qr_code_support.md` SEC-16 for the
exact CSP directive. At minimum: `default-src 'self'; script-src 'self'`.

**SEC-10 — No rate limiting on auth endpoints before step 18 (steps 7–17 window)**
*Risk:* `/auth/callback` and the upload endpoints have no per-IP or per-token
rate limiting until step 18. A flood of forged callback requests creates
unbounded session writes and DB lookups.
*Remediation:* `mod_ratelimit` throttles *bandwidth*, not request rate — it
is the wrong module for this purpose. Use one of the following:
- **Cloudflare WAF rate-limiting rule** (preferred — already in the stack,
  works at the edge before traffic reaches the origin): create a rule matching
  `URI Path equals /auth/callback` with a threshold of ~20 req/min per IP.
  Zero additional Apache config needed.
- **`mod_evasive`** (Apache fallback if Cloudflare is bypassed): configure
  `DOSPageInterval 1`, `DOSPageCount 10` for `/auth/callback` specifically.
This does not need to wait for the full rate-limit config page in step 18.

**SEC-11 — Invite link concurrent claim race condition (step 9)**
*Risk:* Two requests arriving simultaneously with the same single-use invite
token could both pass an `is_active = 1` check before either sets it to 0,
provisioning two accounts from one invite.
*Remediation:* Use an atomic update-and-check pattern against `contributor_invite_tokens`:
```sql
UPDATE contributor_invite_tokens SET is_active = 0
WHERE token_hash = ? AND is_active = 1 AND expires_at > NOW();
```
Check `$stmt->rowCount() === 1` before proceeding. If zero rows updated, the
token was already used or expired — reject the request.

*Important:* **Do NOT apply this pattern to `event_upload_tokens` (QR upload
tokens).** QR upload tokens are multi-use — many guests scan the same QR code
for the same event. The owner revokes a QR token manually by setting
`is_active = 0`. Setting it to 0 on the first upload would invalidate the token
for all subsequent guests. The race-condition concern does not apply because
multiple concurrent uploads from the same QR token are expected and valid.

**SEC-12 — Apple `display_name` arrives only once (step 7)**
*Risk:* Apple sends the user's name only on the first authorization. A code
path that overwrites `display_name` with a subsequent (empty) Apple response
permanently loses the user's name.
*Remediation:* In the JIT provisioning upsert, only write `display_name` if
`idp_provider = 'apple'` AND the incoming name is non-empty AND the existing
`display_name IS NULL`. Never overwrite an existing non-null Apple display name
from a subsequent login response.

---

### Low

**SEC-13 — Tenant slug squatting on confusable brand names (step 6)**
*Risk:* A user could register `gighive`, `support`, `help`, `status`, `app`,
`billing` or other terms that appear to be official GigHive infrastructure,
enabling phishing against other tenants or end users.
*Remediation:* Extend the reserved subdomain blocklist to include a curated
set of brand-adjacent terms in addition to the technical system names. Maintain
the list in a config file (not hardcoded) so it can be updated without a
deployment.

**SEC-14 — Authorization code exposed in server access logs (step 7)**
*Risk:* The OIDC callback URL contains `?code=…&state=…` as query parameters.
If Apache access logs record the full request URI, authorization codes appear
in logs. Codes are short-lived but log files are long-lived.
*Remediation:* Do NOT suppress the entire log line — that loses diagnostic
information (status codes, IPs, timing) needed to investigate auth failures.
Instead, log the path without the query string for `/auth/callback` only:
```apache
LogFormat "%h %l %u %t \"%m %U %H\" %>s %b" stripped_qs
SetEnvIf Request_URI "^/auth/callback" use_stripped_qs
CustomLog ${APACHE_LOG_DIR}/access.log combined        env=!use_stripped_qs
CustomLog ${APACHE_LOG_DIR}/access.log stripped_qs     env=use_stripped_qs
```
`%U` logs only the path (no query string); `%m` logs the method; `%H` logs
the protocol. All other paths log normally via `combined`. The callback
handler must also exchange the code immediately on receipt and never log
the raw `$_GET` array via `error_log()` or application logging.

---

## AWS Well-Architected Framework Review

Evaluated against the six pillars. Items marked **(gap)** are not currently
addressed in the plan and should be resolved before or during the relevant step.

### Pillar 1 — Operational Excellence

**Gap: No schema migration tooling defined.**
The plan has multiple schema changes across 3 Phase 1 steps and several
post-release steps. Without a migration tool (e.g. numbered SQL scripts managed
by Phinx, or plain `V001__description.sql` files applied by a custom runner),
there is no way to reliably reproduce the schema state across dev, staging, and
prod, or to roll back a bad migration. Define the migration approach at step 2
before any schema change ships.

**Gap: Observability not mentioned.**
The plan has no logging, metrics, or alerting strategy beyond Apache access
logs. For a SaaS platform, minimum required telemetry is: application error
rate, per-tenant upload success/failure rate, OIDC login failure rate, and
quota headroom per tenant. Without this, operational incidents are discovered
by customers, not the platform. Add structured application logging (PSR-3
`LoggerInterface`, e.g. Monolog) at step 7 when auth is first wired.

**Gap: Local development OIDC testing strategy not defined.**
Steps 5–9 cannot be developed without either real IDP credentials or a mock.
Options: (a) create a "dev" OAuth app per IDP that points to `localhost`, or
(b) use a self-hosted OIDC provider mock such as `dex` or `keycloak` in Docker
Compose for the dev environment. Without a clear choice, developers will share
production IDP credentials in dev — a security anti-pattern.

---

### Pillar 2 — Security

**Gap: Secrets management strategy for production.**
The plan references `.env` files for all credentials (OIDC client secrets,
Stripe keys, `APP_HMAC_KEY`). This is acceptable for self-hosted and dev
deployments but not for a production SaaS platform. Env files on disk are
readable by any process with the same OS user, persist in container images if
accidentally baked in, and have no rotation audit trail. For the SaaS
deployment, use a secrets manager (AWS Secrets Manager, Azure Key Vault, or
HashiCorp Vault). The application already loads from environment variables via
`vlucas/phpdotenv` — the transition to injecting from a secrets manager at
container startup requires only a change to the provisioning layer, not the
application code.

**Gap: `tenant_id` query enforcement mechanism not defined.**
The plan says "propagate `tenant_id` through all repository queries" (step 8)
but describes no enforcement mechanism. In a raw-SQL or query-builder codebase,
a single missing `WHERE tenant_id = ?` clause is a cross-tenant data breach.
Options: (a) a base repository class with a mandatory `withTenant(int $id)`
scope that throws if not called, (b) a query listener/middleware that asserts
`tenant_id` appears in all non-superadmin queries, or (c) a test suite that
runs every query as tenant 2 and asserts tenant 1 data is not returned. At
minimum, document the chosen enforcement pattern at step 8.

**Gap: Dependency vulnerability scanning.**
Composer packages (including `league/oauth2-client`, `firebase/php-jwt`,
Stripe PHP SDK) receive security updates. Without `composer audit` in the CI
pipeline, known CVEs in dependencies will go unnoticed. Add `composer audit`
as a pipeline step before any SaaS release.

---

### Pillar 3 — Reliability

**Gap: No backup strategy before Phase 1 migration.**
Phase 1 step 2 adds `tenant_id` to 9 live tables and drops/recreates `users`.
There is no instruction to take a database backup before running this
migration. If the migration fails mid-run, the schema is in a partially
modified state. **Always take a verified backup before any DDL migration on
a live database**, even one described as "additive."

**Gap: Multi-IDP lockout risk.**
If a tenant's only supported IDP (e.g. Google) experiences an outage, every
user authenticated via that IDP is locked out of GigHive until the IDP
recovers. The plan has no fallback. Options: (a) allow users to link multiple
IDPs to the same `users` row (future enhancement), or (b) document the
outage response in the ops runbook (accept the dependency, monitor IDP
status pages). At minimum, add IDP uptime monitoring to the alerting setup.

**Gap: Step 19 session migration invalidates all live sessions.**
When switching from PHP file sessions to Redis/database-backed sessions (step
19), all currently authenticated sessions are invalidated. Users mid-upload
will experience session loss. This is a planned service interruption. Document
it as such, schedule it during a low-traffic window, and add a pre-maintenance
banner notification to active users.

---

### Pillar 4 — Performance Efficiency

**Gap: `tenant_id` indexes not specified.**
The plan adds `tenant_id` to 8 tables via ALTER TABLE but does not specify
creating indexes on those columns. Without an index, every
`WHERE tenant_id = ?` query is a full table scan. At scale, this is the
difference between milliseconds and seconds per page load. Each ALTER TABLE in
step 2 must include a corresponding `ADD INDEX idx_<table>_tenant (tenant_id)`.
For `events`, `assets`, and `upload_jobs`, a composite index with the next
most-selective column (e.g. `(tenant_id, event_date)`) is preferable.

**Gap: PHP media passthrough latency vs X-Sendfile.**
The Tenant-Scoped File Storage section presents PHP `readfile()` passthrough and `X-Sendfile` as equal
options. They are not equal: `readfile()` routes every byte through PHP-FPM
memory, adding significant latency and memory pressure for large media files.
`X-Sendfile` lets Apache serve the file directly after PHP validates the
session. **X-Sendfile is the correct default for media serving.** PHP
passthrough should only be used as a fallback when X-Sendfile is unavailable.
Document this preference explicitly in the Tenant-Scoped File Storage section.

---

### Pillar 5 — Cost Optimization

**Gap: No storage lifecycle policy for old events.**
Bands upload media for a specific gig. After 2–3 years, that media is rarely
accessed but continues to consume storage quota. A lifecycle policy (e.g. move
files older than 18 months to cold storage tier on S3/Azure Blob) is
standard for media SaaS platforms. This does not need to be implemented at
v1, but the `assets` table should record a `last_accessed_at` column from day
one so that a future lifecycle policy has the data it needs.

**Note: Content deduplication already noted.**
The Tenant-Scoped File Storage section already flags the `blob_store` content-deduplication table as a
post-v1 optimization. This is the correct approach — do not block v1 on it.

---

### Pillar 6 — Sustainability

**Gap: N+1 query patterns not addressed.**
The plan doesn't describe how tenant dashboards (event lists, asset lists,
quota bar) will be rendered. If implemented naively, each list item triggers
individual queries. For a SaaS platform, N+1 queries compound per tenant at
scale and waste compute. Define JOIN-based or batch-load patterns for all
list views, enforced by code review. This is a low-cost-to-fix concern when
addressed during initial implementation and an expensive refactor later.

---

## What Does NOT Change

- The `events` / `assets` / `event_items` / `event_participants` core schema
  shape is already well-normalised. Adding `tenant_id` FKs is additive.
- The `org_name` field on `events` remains useful as a sub-label within a
  tenant (e.g. a booking agency managing multiple bands under one account).
- The TUS upload protocol and chunked ingestion flow are tenant-agnostic at
  the protocol level; only the metadata routing and storage path need updating.
- The AI worker's poll/claim/execute loop is sound for SaaS; only the
  `tenant_id` scoping and quota enforcement are new concerns.
- The existing Ansible + Docker Compose stack continues to work for
  self-hosted single-tenant installs; SaaS is a separate deployment track.

---

## Database Migration

### Step 2 — Apply Combined DB Migration *(repeat per environment)*

Apply the combined live migration to an existing `media_db`. Run after Step 1 (`create_media_db.sql` update) is committed.

**Prerequisite:** Complete Step 1 before running Step 2 on any environment. The Ansible rebuild in Step 3 will fail or produce a stale schema if `create_media_db.sql` is not already updated.

---

**A. Take a pre-migration backup**

Before touching anything, capture the current state. From the **docker host**:

```bash
docker exec -i mysqlServer sh -lc \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -h 127.0.0.1 -u root \
   --single-transaction --quick --lock-tables=0 --routines --events --triggers \
   --default-character-set=utf8mb4 --databases "$MYSQL_DATABASE" | gzip' \
  > pre_migration_$(date +%Y-%m-%d).sql.gz
```

This is your rollback — if anything goes wrong before step C, restore this file via `admin_system.php` (Section B).

---

**B. Apply the combined migration via single docker command**

```bash
docker exec -i mysqlServer sh -lc 'mysql -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" -D "$MYSQL_DATABASE"' << 'MIGRATION'

-- ── Tenant schema (Phase 1 steps 1–3) ────────────────────────────────────────

-- 1. Create tenants table and seed the single existing install as tenant 1
CREATE TABLE IF NOT EXISTS tenants (
  tenant_id              int unsigned NOT NULL AUTO_INCREMENT,
  slug                   varchar(64)  NOT NULL,
  display_name           varchar(255) NOT NULL,
  plan                   enum('free','pro','enterprise') NOT NULL DEFAULT 'free',
  is_active              tinyint(1)   NOT NULL DEFAULT 1,
  is_public              tinyint(1)   NOT NULL DEFAULT 0,
  stripe_customer_id     varchar(64)  DEFAULT NULL,
  stripe_subscription_id varchar(64)  DEFAULT NULL,
  plan_expires_at        datetime     DEFAULT NULL,
  created_at             datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id),
  UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tenants (tenant_id, slug, display_name, plan, is_active)
  VALUES (1, 'default', 'Default', 'free', 1);

-- 2. events: add tenant_id, replace instance-wide unique with per-tenant unique
ALTER TABLE events
  ADD COLUMN  tenant_id int unsigned NOT NULL DEFAULT 1 AFTER event_id,
  DROP INDEX  uq_events_date_org,
  ADD CONSTRAINT uq_events_tenant_date_org UNIQUE (tenant_id, event_date, org_name),
  ADD CONSTRAINT fk_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id);

-- 3. assets: add tenant_id, scope checksum deduplication per tenant
ALTER TABLE assets
  ADD COLUMN  tenant_id int unsigned NOT NULL DEFAULT 1 AFTER asset_id,
  DROP INDEX  uq_assets_checksum,
  ADD CONSTRAINT uq_assets_tenant_checksum UNIQUE (tenant_id, checksum_sha256),
  ADD CONSTRAINT fk_assets_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id);

-- 4. participants: add tenant_id, scope name uniqueness per tenant
--    The inline UNIQUE on the name column creates an index named 'name' in MySQL.
ALTER TABLE participants
  ADD COLUMN  tenant_id int unsigned NOT NULL DEFAULT 1 AFTER participant_id,
  DROP INDEX  name,
  ADD CONSTRAINT uq_participants_tenant_name UNIQUE (tenant_id, name),
  ADD CONSTRAINT fk_participants_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id);

-- 5. upload_jobs: add tenant_id
ALTER TABLE upload_jobs
  ADD COLUMN  tenant_id int unsigned NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_upload_jobs_tenant (tenant_id),
  ADD CONSTRAINT fk_upload_jobs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id);

-- 6. ai_jobs: add tenant_id
ALTER TABLE ai_jobs
  ADD COLUMN  tenant_id int unsigned NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_ai_jobs_tenant (tenant_id),
  ADD CONSTRAINT fk_ai_jobs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id);

-- 7. taggings: add tenant_id, scope tag+target uniqueness per tenant
--    idx_taggings_tag added to give fk_taggings_tag a covering index after uq_taggings_tag_target
--    (which starts with tag_id) is dropped; the new unique starts with tenant_id, not tag_id.
ALTER TABLE taggings
  ADD COLUMN  tenant_id int unsigned NOT NULL DEFAULT 1 AFTER id,
  ADD KEY idx_taggings_tag (tag_id),
  DROP INDEX  uq_taggings_tag_target,
  ADD CONSTRAINT uq_taggings_tenant_tag_target UNIQUE (tenant_id, tag_id, target_type, target_id),
  ADD CONSTRAINT fk_taggings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id);

-- 8. catalog_scans: add tenant_id (org_name is free-text; tenant cannot be derived via FK)
ALTER TABLE catalog_scans
  ADD COLUMN  tenant_id int unsigned NOT NULL DEFAULT 1 AFTER scan_id,
  ADD KEY idx_catalog_scans_tenant (tenant_id),
  ADD CONSTRAINT fk_catalog_scans_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id);

-- 9. catalog_entries: add tenant_id, scope path_hash uniqueness per tenant
ALTER TABLE catalog_entries
  ADD COLUMN  tenant_id int unsigned NOT NULL DEFAULT 1 AFTER catalog_entry_id,
  DROP INDEX  uq_catalog_entries_path_hash,
  ADD CONSTRAINT uq_catalog_entries_tenant_path_hash UNIQUE (tenant_id, path_hash),
  ADD CONSTRAINT fk_catalog_entries_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id);

-- 10. Recreate users table — legacy email/password schema replaced with OIDC-native schema.
--     No data migration risk: the existing users table was never wired to any data table.
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id              int unsigned  NOT NULL AUTO_INCREMENT,
  tenant_id       int unsigned  NOT NULL,
  idp_provider    varchar(32)   NOT NULL DEFAULT 'local'
                                COMMENT 'google | microsoft | apple | local',
  idp_subject     varchar(255)  DEFAULT NULL
                                COMMENT 'IDP sub/oid claim — globally unique per provider',
  role            enum('owner','contributor','viewer','superadmin')
                                NOT NULL DEFAULT 'viewer',
  email           varchar(255)  DEFAULT NULL
                                COMMENT 'Display/contact only — not an auth credential',
  display_name    varchar(255)  DEFAULT NULL,
  avatar_url      varchar(1024) DEFAULT NULL,
  tos_version     varchar(32)   DEFAULT NULL
                                COMMENT 'ToS version accepted, e.g. "2024-01"',
  tos_accepted_at datetime      DEFAULT NULL
                                COMMENT 'NULL means ToS not yet accepted',
  created_at      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_idp (idp_provider, idp_subject),
  KEY idx_users_tenant (tenant_id),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── QR code upload feature (Phase 1a step 5) ─────────────────────────────────

-- 11. QR upload tokens — no tenant_id needed; scope derives via event_id → events.tenant_id
CREATE TABLE IF NOT EXISTS event_upload_tokens (
  token_id            bigint unsigned NOT NULL AUTO_INCREMENT,
  event_id            INT             NOT NULL,
  token_hash          char(64)        NOT NULL  COMMENT 'SHA-256 hex of the raw token; raw token is never stored',
  expires_at          datetime        NOT NULL,
  is_active           tinyint(1)      NOT NULL DEFAULT 1,
  created_by_user_id  int unsigned    DEFAULT NULL
                                      COMMENT 'user_id of owner; NULL pre-step-7 (Basic Auth era)',
  created_at          datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_id),
  UNIQUE KEY uq_event_upload_tokens_hash (token_hash),
  KEY idx_event_upload_tokens_event (event_id),
  KEY idx_event_upload_tokens_creator (created_by_user_id),
  CONSTRAINT fk_eut_event FOREIGN KEY (event_id)
    REFERENCES events (event_id) ON DELETE CASCADE
  -- fk_eut_created_by deferred to step 7:
  --   ALTER TABLE event_upload_tokens ADD CONSTRAINT fk_eut_created_by
  --   FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL;
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Anonymous upload attribution — scope derives via upload_job_id → upload_jobs.tenant_id
CREATE TABLE IF NOT EXISTS anon_upload_attributions (
  attribution_id  bigint unsigned NOT NULL AUTO_INCREMENT,
  token_id        bigint unsigned NOT NULL,
  upload_job_id   varchar(64)     NOT NULL,
  display_name    varchar(255)    DEFAULT NULL  COMMENT 'Self-reported fan display name; max 100 chars enforced in app layer',
  tos_accepted_at datetime        NOT NULL      COMMENT 'Timestamp of anonymous ToS acceptance',
  created_at      datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attribution_id),
  KEY idx_anon_upload_token (token_id),
  KEY idx_anon_upload_job (upload_job_id),
  CONSTRAINT fk_aua_token FOREIGN KEY (token_id)
    REFERENCES event_upload_tokens (token_id) ON DELETE CASCADE,
  CONSTRAINT fk_aua_job FOREIGN KEY (upload_job_id)
    REFERENCES upload_jobs (job_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

MIGRATION
```

**Notes on step B:**
- `DEFAULT 1` on each new `tenant_id` column backfills all existing rows automatically — no separate UPDATE needed.
- `event_upload_tokens` and `anon_upload_attributions` use `IF NOT EXISTS` — safe to re-run if the command is interrupted after the ALTER statements complete.
- The ALTER statements are not idempotent — if step B is interrupted mid-way, restore from the step A backup and re-run from scratch.

---

**C. Take a post-migration backup via admin_system.php**

Use Section C ("Create Backup Now") in `admin_system.php`. This produces a timestamped `.sql.gz` full dump (schema + data + routines) and updates the `_latest.sql.gz` symlink. This is the backup used in Step 4.

**Rollback options for Step 2:**
- **Before step C completes:** restore the step A backup via `admin_system.php`.
- **After step C:** restore the step C backup — it captures the fully migrated state.
- **QR tables only (if needed in isolation):** `DROP TABLE IF EXISTS anon_upload_attributions; DROP TABLE IF EXISTS event_upload_tokens;`

---

### Step 3 — Rebuild MySQL from `create_media_db.sql` *(repeat per environment)*

Destroys the MySQL container's data volume and reinitializes from scratch using the updated `create_media_db.sql` as a Docker init script. Verifies the DDL file runs cleanly end-to-end.

1. Confirm the Step 2 post-migration backup (`admin_system.php` Section C backup) exists and is accessible.
2. Set `rebuild_mysql_data: true` in the environment's `group_vars`.
3. Run the Ansible playbook.
4. **Reset `rebuild_mysql_data: false` in `group_vars` immediately after the rebuild completes** — leaving it `true` will wipe the DB on the next routine Ansible run.

---

### Step 4 — Restore DB from Post-Migration Backup *(repeat per environment)*

Use Section B ("Restore Database From Backup") in `admin_system.php`, selecting the Step 2 post-migration backup (the `admin_system.php` Section C backup, not the pre-migration one). The restore pipes `zcat backup.sql.gz | mysql` — the dump's embedded `CREATE DATABASE / USE / DROP TABLE IF EXISTS` statements fully replace the Ansible-built schema with the correct migrated state.

Once restore is confirmed good (smoke test the app), git commit `create_media_db.sql` and any related changes.
