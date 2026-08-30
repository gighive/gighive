# Refactor: Server-Authoritative Delete Eligibility for Authenticated Media Database

## Status — 2026-08-29

**Phase 2 (server) — implemented, pending deploy + smoke test run from pop-os.**
**Phase 3 (iOS) — implemented, pending Xcode build and UI test run against deployed server.**

Phase 1 (guest-token mitigation) — complete.
Phase 4 (delete grants) — not started.
Phase 3 Step 7 (uploader performDelete via JWT role-claim) — deferred to JWT migration.
Unit tests 31–32 — blocked on creating a `GigHiveTests` Xcode unit-test target.

---

Short-term mitigation already shipped (see P15 in `problem_ios_testing_media_player_unification.md`):
- `GuestUploadView` no longer writes tokens into `UploaderDeleteTokenStore`.
- The ✕ delete button is hidden for the `uploader` role in the authenticated Media Database. Only `admin` sees it.

No implementation work for the long-term phases should begin from this document without explicit user approval.

---

## Elevator Pitch

Right now the authenticated Media Database decides which videos show a ✕ delete button by checking a Keychain token store on the device. That store was designed for the authenticated iPhone upload path, but it leaks tokens from the guest QR code upload path, and tokens go stale whenever a file is re-uploaded or deduplicated. The result is a delete button that appears for files the user cannot actually delete, producing a confusing 403.

The real fix is to stop guessing on the client and let the server say, per entry, whether the current authenticated user is allowed to delete it. The iOS view then becomes a simple consumer of a `can_delete` flag — no Keychain, no stale tokens, no upload-path confusion.

---

## Rationale

### How the current implementation works

`loadAuthenticatedVideos` in `UnifiedVideoListView.swift` loads all entries in `UploaderDeleteTokenStore` for the current host and builds a `tokenMap: [Int: String]`. Any `MediaEntry` whose `asset_id` matches a key in that map gets `isOwnUpload: true` and a non-nil `authDeleteTokens` entry. `showDeleteButton(for:)` returns `true` when `authDeleteTokens[video.id] != nil`.

This means delete eligibility is a client-side guess based on what tokens happen to be in Keychain — not a server assertion about who owns what.

### Why the current approach is fragile

**Token leakage across upload paths.** `UploaderDeleteTokenStore` was also written to by `GuestUploadView` (the QR code path). Guest uploads produce a real `asset_id` and a server-issued `delete_token`. Both ended up in the same Keychain store, making the authenticated Media Database show ✕ for files the uploader user cannot actually delete via `delete_media_files.php`.

**Token staleness.** `UploadService::setDeleteTokenHashIfNull` only writes `delete_token_hash` once (`WHERE delete_token_hash IS NULL`). If the same file content is re-uploaded under the same `asset_id` (deduplication), the second upload returns no token. The Keychain entry from the first upload is now stale — it will produce 403 on every delete attempt until the 403 handler drains it.

**No server-side ownership model.** `database.php` returns no per-entry indication of who uploaded a file or whether the current user may delete it. There is no way for the server to tell the client "you may delete this one but not that one."

### The real-world scenario that makes this matter

An event organizer fires up a permanent QR code. Fans upload videos as guests. Later the organizer promotes a trusted fan to the `uploader` role. That fan now sees the full Media Database. Some of those videos are their own guest uploads. The fan reasonably expects to be able to delete their own content — but the current system either:
- Shows ✕ (if the stale token happens to be in Keychain) and then 403s on tap, or
- Shows nothing (after the P15 fix) — which is safer but still not the intended behavior.

The correct answer requires the server to know that asset X was uploaded by guest credential Y, that Y is now the authenticated user, and therefore that user may delete X.

---

## Goal

Have `database.php` return a per-entry `can_delete` field. The iOS view reads that field directly:
- `can_delete: true` → show ✕; delete will succeed.
- `can_delete: false` (or field absent) → hide ✕; no attempt made.

No Keychain token guessing. No stale token 403s. No upload-path leakage.

**Policy: the server asserts delete eligibility. The client enforces it in the UI only.**

---

## Decision

Adopt a server-authoritative model over the current client-side token-map model.

The server already has all the data needed:
- `admin` credential → can delete any asset (already implemented in `delete_media_files.php`, no token required).
- `uploader` credential + `assets.delete_token_hash` set → can delete if they possess the matching plaintext token.
- Guest-uploaded assets are identifiable via the `upload_jobs` table (`upload_jobs.file_relpath` links back to the `assets` checksum path without any schema change).

The long-term option deferred for now: allow an admin to grant delete rights to a specific uploader for a specific guest-uploaded file (e.g. `delete_grants` table). This is niche enough that admin-delete-on-behalf-of covers it adequately until demand justifies the extra surface.

---

## Benefits / Potential Drawbacks

| Benefits | Potential Drawbacks |
|---|---|
| Eliminates the entire class of false-positive ✕ buttons — server truth replaces client-side guessing | `database.php` list query becomes more complex (LEFT JOIN on `upload_jobs`); must be benchmarked on large libraries |
| Stale Keychain tokens can no longer cause 403s on tap — eligibility is re-evaluated at list-load time | `can_delete: true` entries may expose the plaintext `delete_token` in a list response (Option A, Phase 3); security review required |
| Admin delete behavior is unchanged — already unconditional and token-free | Promoted-fan scenario (Phase 4) requires a `delete_grants` table and a grant-creation UI; nontrivial surface if deferred too long |
| Backward-compatible rollout — old iOS builds see no `can_delete` field and continue with the P15 baseline (no ✕ for uploaders) | If Option A (return plaintext token in list) is chosen, any future caching of the `database.php` response (CDN, app-level) would need to be carefully scoped to avoid leaking one user's token to another |
| Single authoritative place for delete eligibility logic — server — instead of three separate places (Keychain store, `isOwnUpload` flag, `showDeleteButton` check) | Phase 2 requires server deploy before Phase 3 iOS build; coordinated release window needed |
| Correctly handles the promoted-fan scenario without any client change — server logic updated, iOS picks up the change automatically | `upload_jobs` LEFT JOIN ties `upload_source` to the presence of a row in a table that is specific to the guest path; if that assumption ever changes (e.g. a future feature creates `upload_jobs` rows for authenticated uploads), the `upload_source` derivation breaks silently |

---

## Real World Use Cases

### Scenario 1 — Uploader uploads via the authenticated iPhone path

**Jordan** logs into the iPhone app as `uploader`, records a clip on-site, and uploads it via the `UploadView` TUS flow.

| | Current (post P15 mitigation) | After this refactor |
|---|---|---|
| ✕ shown? | No — hidden for all uploaders | Yes — `can_delete: true` returned by server |
| Delete succeeds? | N/A | Yes — token hash matches |
| Stale token risk? | N/A | None — server evaluates at list-load time |

### Scenario 2 — Fan uploads via QR code, later promoted to uploader

**Riley** attends an event, scans the QR code, uploads three videos as a guest (`viewer` role in the JWT model). The organizer later promotes Riley to `contributor` (the JWT equivalent of `uploader`) via the owner-only user management page `admin/users.php`. That page — including the role-change action — is planned in Phase 6 of `feature_security_authentication_migration_jwt_implementation.md`, which requires Phase 5 (OIDC) to be live first. Riley logs in with their individual account and opens the Media Database.

| | Current (post P15 mitigation) | After this refactor |
|---|---|---|
| ✕ shown for Riley's own guest uploads? | No | Depends on server grant logic (see Phase 4 — optional `delete_grants` table) |
| ✕ shown for other users' videos? | No | No |
| Confusion / false 403? | None | None |
| How promotion happens | Manual credential hand-off (shared password) | Owner changes Riley's role via `admin/users.php` (Phase 6 of JWT migration) |

### Scenario 3 — Admin manages the full library

**Sam** is the `admin`. Sam opens the Media Database to clean up test uploads before an event goes live.

| | Current | After this refactor |
|---|---|---|
| ✕ shown? | Yes — already correct | Yes — `can_delete: true` for all entries |
| Delete succeeds? | Yes | Yes |

### Scenario 4 — Dual-role device (reproducer of P15 bug)

**Alex** uses the same iPhone to upload as a guest via QR code AND logs in as `uploader` to manage the database.

| | Before P15 fix | After P15 + this refactor |
|---|---|---|
| Guest token written to Keychain? | Yes | No (P15 fixed this) |
| ✕ shown for guest uploads? | Yes — always false positive | No — server returns `can_delete: false` |
| False 403? | Yes | Never shown |

---

## Design Principles

- Server truth drives delete eligibility. The client renders only what the server authorizes.
- `admin` delete is unconditional and token-free (already correct — preserve it).
- `uploader` delete requires a server-verified ownership signal, not a client Keychain token.
- Guest-uploaded assets are identifiable without schema change, via `upload_jobs.file_relpath` → `assets.checksum_sha256` join.
- No new schema is required for Phase 1 or Phase 2. Phase 4 (promoted-fan grant) would need a `delete_grants` table if implemented.
- Roll out in backward-compatible stages: old iOS builds see no `can_delete` field and continue with no ✕ for uploaders (P15 baseline). New builds consume the field.
- Every solution step must include an Ansible smoke test per `SKILL.md`.

---

## Current State

### iOS (`gighiveapp`)

`GigHive/Sources/App/UnifiedVideoListView.swift`
- `loadAuthenticatedVideos` loads `UploaderDeleteTokenStore` and builds `tokenMap: [Int: String]`.
- `authDeleteTokens: [Int: String]` is a `@State` dictionary populated from `tokenMap`.
- `showDeleteButton(for:)` for `.uploaderAndAdmin` returns `authDeleteTokens[video.id] != nil`.
- **Post P15 mitigation:** `showDeleteButton` returns `credential?.displayUser == "admin"` only — uploader ✕ is suppressed entirely.

`GigHive/Sources/App/VideoListContext.swift`
- `UnifiedVideo` has no `canDelete` field. Delete eligibility is inferred from `isOwnUpload` / `authDeleteTokens` at render time.

`GigHive/Sources/App/DatabaseModels.swift`
- `MediaEntry` has no `can_delete` or `delete_token` field.

### Server (`gighiveinfra`)

`ansible/roles/docker/files/apache/webroot/db/database.php` → `MediaController::listJson()`
- Returns per-entry: `id`, `index`, `date`, `duration`, `duration_seconds`, `org_name`, `song_title`, `file_type`, `file_name`, `url`, `thumbnail_url`.
- Does **not** return `can_delete`, `delete_token`, or `upload_source`.

`ansible/roles/docker/files/apache/webroot/db/delete_media_files.php`
- `admin` path: `{"asset_ids": [...]}` — no token, no ownership check.
- `uploader` path: `{"asset_id": x, "delete_token": "..."}` — validates `hash('sha256', token)` against `assets.delete_token_hash`.

`ansible/roles/docker/files/apache/webroot/src/Repositories/AssetRepository.php`
- `getDeleteTokenHashById(int $assetId): ?string` — reads `assets.delete_token_hash`.
- `setDeleteTokenHashIfNull(int $assetId, string $hash): bool` — writes once, idempotent.

`ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
- Sets `delete_token_hash` after asset insert, returns plaintext `delete_token` in finalize response.
- If `setDeleteTokenHashIfNull` returns false (hash already set — deduplication), `delete_token` is nulled and absent from response.

### Schema (relevant to this refactor)

`assets` table:
- `asset_id` — primary key used as `MediaEntry.id` in `database.php` response.
- `checksum_sha256` — SHA-256 of file content; used as filename on disk.
- `delete_token_hash` — SHA-256 of plaintext delete token; NULL until first upload finalizes.

`upload_jobs` table:
- `file_relpath` — relative path of the uploaded file (e.g. `video/<sha256>.mp4`). Encodes the checksum.
- Populated only for guest QR code uploads. Authenticated iPhone uploads produce no `upload_jobs` row.
- This `upload_jobs` vs no-`upload_jobs` distinction is the server-side observable that separates guest uploads from authenticated uploads — **no schema change required** to read it.

---

## Proposed Implementation

Phases must be executed in order. Phase 1 is already complete. Phases 2 onward are not yet implemented.

### Phase 1 — Short-term iOS mitigation (complete)

Goal: stop false-positive ✕ buttons with no server changes.

- [x] **Step 1** — Remove `UploaderDeleteTokenStore.upsert` from `GuestUploadView.swift`. Guest tokens no longer enter the authenticated token store. (P15 fix.)
- [x] **Step 2** — Change `showDeleteButton(for: .uploaderAndAdmin)` to return `true` only when `credential?.displayUser == "admin"`. Uploader ✕ suppressed entirely until server-authoritative model is ready.

Result: no false positives, no stale-token 403s. Admin delete unaffected. Uploader cannot delete from the app — acceptable interim state.

---

### Phase 2 — Server: add `can_delete` and `upload_source` to `database.php` response

Goal: server asserts per-entry delete eligibility so the iOS client stops guessing.

#### `can_delete` logic per caller role

- `admin` → always `true` (consistent with `delete_media_files.php` admin path).
- `uploader` → `true` only if `assets.delete_token_hash` IS NOT NULL AND the asset has no corresponding `upload_jobs` row (i.e. it was uploaded via the authenticated iPhone path, not via QR code guest path).
- Any other caller → `false` (defensive default; `database.php` already requires `admin` or `uploader` auth).

#### `upload_source` derivation

No new columns needed. Use a LEFT JOIN from `assets` to `upload_jobs` on `file_relpath`:

```sql
-- upload_jobs.file_relpath format: "video/<sha256>.ext" or "audio/<sha256>.ext"
-- assets.checksum_sha256 + assets.file_ext reconstruct the same path.
-- Cross-reference UploadService.php before implementing to confirm the exact
-- format used when file_relpath is written — a mismatch silently returns 0 JOIN
-- matches, making all files appear as upload_source "authenticated".

LEFT JOIN upload_jobs uj
  ON  uj.file_relpath = CONCAT(a.file_type, '/', a.checksum_sha256,
                               IF(COALESCE(a.file_ext, '') != '', CONCAT('.', a.file_ext), ''))
-- COALESCE guards against NULL file_ext: IF(NULL != '', ...) evaluates to NULL
-- in MySQL 8.4 strict mode, causing the false branch to fire unexpectedly.

-- Performance: upload_jobs.file_relpath must be indexed for this JOIN to be
-- efficient at scale. Verify the index exists and benchmark before deploying
-- Phase 2 to any environment with a significant asset count.

-- upload_source (returned as a column):
CASE WHEN uj.id IS NULL THEN 'authenticated' ELSE 'guest' END AS upload_source

-- can_delete: computed in PHP after the query, not as a SQL CASE with a bind
-- parameter. In MediaController::listJson(), read $user from
-- $_SERVER['PHP_AUTH_USER'] and apply per row:
--   admin    → can_delete = true
--   uploader, delete_token_hash IS NOT NULL, uj.id IS NULL → can_delete = true
--   otherwise → can_delete = false
```

#### Steps

- [x] **Step 1** — Verify `file_relpath` format in `UploadService.php` (confirmed `<file_type>/<checksum>.<ext>`); add `idx_upload_jobs_file_relpath` to `create_media_db.sql`. **Live ALTER command (BABRR Step 2) — run on each existing environment before deploying Phase 2.** Verify the index is absent first (`SHOW INDEX FROM upload_jobs WHERE Key_name = 'idx_upload_jobs_file_relpath';`), then:

  ```bash
  docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "ALTER TABLE upload_jobs ADD KEY idx_upload_jobs_file_relpath (file_relpath);"'
  ```

- [x] **Step 2** — Extended `MediaController::listJson()` with LEFT JOIN on `upload_jobs`; `can_delete` computed in PHP from `$_SERVER['PHP_AUTH_USER']` (not as a SQL CASE bind parameter).
- [x] **Step 3** — Added `can_delete` (bool) and `upload_source` (string) to the per-entry response mapping in `MediaController.php`.
- [x] **Step 4** — Updated `OpenApi.php` to add the two new fields to the `MediaEntry` schema annotation.
- [ ] **Step 5** — Regenerate `openapi.yaml` per `docs/process_api_swagger_generation.md` (manual step; run `composer openapi` from `ansible/roles/docker/files/apache/webroot/`).
- [x] **Step 6** — Added smoke tests T-134 through T-139 to `ansible/roles/post_build_checks/tasks/main.yml`.

---

### Phase 3 — iOS: consume `can_delete` from server response

Goal: replace the Keychain-token model with the server-authoritative field.

#### Dependencies

Phase 3 has no dependency on Phase 6 of `feature_security_authentication_migration_jwt_implementation.md` (the user management UI / role-promotion page). Phase 3 operates entirely within the current Basic Auth model. The `can_delete` logic in `MediaController::listJson()` branches on `$_SERVER['PHP_AUTH_USER'] === 'admin'` or `=== 'uploader'` — the same server variable already used in `delete_media_files.php`. No JWT, no user accounts, no role-change UI required.

Phase 3 can ship as soon as Phase 2 (server adds `can_delete` to the `database.php` response) is verified.

Two future points where the JWT migration does intersect with this refactor:

- **Phase 4 (optional `delete_grants`)** — if a UI is needed for an owner to grant Riley delete rights over guest uploads, `admin/users.php` (Phase 6 of JWT) is the natural home for that action. Not a blocker.
- **JWT cutover (Phases 4–5 of JWT migration)** — when Basic Auth is retired and `$_SERVER['PHP_AUTH_USER']` is replaced by a JWT role claim, the `can_delete` logic in `MediaController` will need to switch from checking the username string to checking the JWT role. That is a small targeted update at JWT migration time, scoped to the `MediaController` change already made in Phase 2 of this refactor.

#### Rationale — uploader self-delete deferred to JWT migration

The JWT authentication migration follows immediately after this refactor. Under JWT, uploader self-delete is implemented correctly via role-claim ownership verification — no per-asset delete token is involved. Implementing Option A or Option B now would mean building a token-delivery mechanism that is torn out within the next sprint. The SaaS-target architecture also argues against embedding credentials in a high-frequency list response (Option A) or retaining a per-asset Keychain store (Option B).

**Decision:** Phase 3 ships Steps 1–6 (the display fix and store cleanup). Step 7 (uploader `performDelete` token source) is explicitly deferred to the JWT migration, where it will be implemented as a role-claim-authenticated delete with no separate token. This must be scoped as a named deliverable in `feature_security_authentication_migration_jwt_implementation.md` — if it is absent, add it before beginning JWT migration work.

#### Option A vs Option B — documented for context; superseded by JWT migration

The `performDelete` uploader path would require sending a plaintext delete token to the server under the current Basic Auth model. Two options were evaluated:

- **Option A:** Server returns the plaintext `delete_token` alongside `can_delete` in the `database.php` response. Cleaner architecture but puts a credential in a high-frequency list response; incompatible with CDN caching. **If Option A is ever chosen, the `database.php` response must never be cached at any layer (CDN, app-level HTTP cache) — a cached response containing a token could be served to a different authenticated user.**
- **Option B:** Keep the Keychain token for the `performDelete` call only; do not use it for eligibility display. More conservative but retains a token store that becomes legacy under JWT.

Neither option is implemented in this phase. Both are superseded by the JWT migration.

#### Duplicate-path edge case

If the same file was uploaded via both the authenticated iPhone path AND the guest QR path (same `asset_id`, `upload_jobs` row exists), the server returns `can_delete: false` for the uploader even though they have a valid Keychain token. The JOIN finds the `upload_jobs` row and treats the asset as guest-originated. This is the safe default — no false-positive ✕ — but it means the uploader cannot self-serve delete that asset without admin intervention or a Phase 4 grant.

#### Steps

- [x] **Step 1** — Added `canDelete: Bool` to `MediaEntry` in `DatabaseModels.swift` with `decodeIfPresent` defaulting to `false`.
- [x] **Step 2** — Added `canDelete: Bool` to `UnifiedVideo` in `VideoListContext.swift`.
- [x] **Step 3** — Mapped `entry.canDelete` → `video.canDelete` in `loadAuthenticatedVideos` in `UnifiedVideoListView.swift`.
- [x] **Step 4** — Updated `showDeleteButton(for: .uploaderAndAdmin)`: admin returns `video.canDelete`; uploader returns `false`.
- [x] **Step 5** — Removed `authDeleteTokens` / `tokenMap` / `UploaderDeleteTokenStore` loading from `loadAuthenticatedVideos`; updated `performDelete` 403 handler and success handler to remove all `authDeleteTokens` references.
- [x] **Step 6** — `UploaderDeleteTokenStore` assessed: still actively used by `UploadView` "My Uploads" tab; cannot be removed. Scope is now limited to `UploadView` only — `UnifiedVideoListView` no longer touches it.
- [ ] **Step 7** — *(Deferred to JWT migration)* Implement `performDelete` uploader path using JWT role-claim ownership verification; no per-asset delete token required. Confirm this is a named deliverable in `feature_security_authentication_migration_jwt_implementation.md` before closing this phase.

#### Backward compatibility

Old iOS builds that do not decode `can_delete` will continue with the P15 baseline (no ✕ for uploaders). New builds consume the field. No forced upgrade required.

---

### Phase 4 — (Optional, deferred) Admin-granted delete rights for guest-uploaded assets

Goal: allow an admin to explicitly grant a specific uploader the right to delete a specific guest-uploaded asset. Covers the promoted-fan scenario.

This phase is explicitly deferred. Admin-delete-on-behalf-of is the interim solution (admin deletes the file at the fan's request). Only implement this phase if demand justifies the added surface.

#### Dependencies

- **Phase 3 Steps 1–6 must be complete** before Phase 4 is deployed to any environment where the iOS client is running. Phase 3 Step 3 causes the iOS app to show ✕ whenever the server returns `can_delete: true`. Phase 4 Step 3 extends the server to return `can_delete: true` for granted guest assets. If Phase 4 is deployed while the uploader delete action is still deferred (Phase 3 Step 7 not yet shipped), contributors will see ✕ on granted assets but the delete will fail.
- **Phase 3 Step 7 (JWT uploader delete) must be complete** before the iOS-facing effect of Phase 4 (✕ appearing for contributors on granted assets) produces a working end-to-end flow.
- **JWT Phase 6** (`admin/users.php` role management) is required for Step 2's grant-creation UI surface.

- [ ] **Step 1** — Design and run DDL for `delete_grants` table `(grant_id, asset_id, granted_to_user, granted_by_user, created_at)`; update `create_media_db.sql`.
- [ ] **Step 2** — Add grant-creation endpoint (owner-only); wire into `admin/users.php` (requires JWT Phase 6 of `feature_security_authentication_migration_jwt_implementation.md`).
- [ ] **Step 3** — Extend `MediaController::listJson()` to join `delete_grants` and include granted assets in the `can_delete: true` set for contributor callers.
- [ ] **Step 4** — Add smoke tests: grant creation; `can_delete: true` on a granted guest asset; rejection of ungrant attempts by non-owners.

#### Related schema work

Before or alongside Phase 4, consider `refactor_schema_upload_jobs_token_attribution.md`:
`upload_jobs` currently has no FK back to `event_upload_tokens`, so there is no
database-level record of which QR code authorized which upload. That attribution
would be useful for the grant model (e.g. surfacing all uploads from a specific QR
token so an admin can grant bulk delete rights). Review that refactor's risk section
before implementing, as it has cascade and JWT-migration dependencies.

---

## Files Under Change

### Modified — iOS (`gighiveapp`)

1. `GigHive/Sources/App/GuestUploadView.swift` — (Phase 1, complete) removed `UploaderDeleteTokenStore.upsert`; guest tokens no longer enter the authenticated store.
2. `GigHive/Sources/App/UnifiedVideoListView.swift` — (Phase 1) suppressed uploader ✕ in `showDeleteButton`; (Phase 3) drive `showDeleteButton` from `video.canDelete`; remove `authDeleteTokens` / `tokenMap` / `UploaderDeleteTokenStore` load; update 403 handler in `performDelete`. Uploader `performDelete` token delivery deferred to JWT migration (Phase 3 Step 7).
3. `GigHive/Sources/App/DatabaseModels.swift` — (Phase 3) add `canDelete: Bool` to `MediaEntry` via `decodeIfPresent` with default `false`.
4. `GigHive/Sources/App/VideoListContext.swift` — (Phase 3) add `canDelete: Bool` to `UnifiedVideo`.

### Modified — Server (`gighiveinfra`)

5. `ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php` — (Phase 2) extend `listJson()` with LEFT JOIN on `upload_jobs`; compute `can_delete` in PHP; add `can_delete` and `upload_source` to per-entry response mapping.
6. `ansible/roles/docker/files/apache/webroot/src/OpenApi.php` — (Phase 2) add `can_delete` and `upload_source` to `MediaEntry` schema annotation.
7. `ansible/roles/docker/files/apache/webroot/docs/openapi.yaml` — (Phase 2) regenerated artifact; do not edit manually.
8. `ansible/roles/post_build_checks/tasks/main.yml` — (Phase 2) add smoke tests: 401 unauthenticated check; `can_delete` correct values for admin and uploader callers.

### Phase 4 only — if implemented

9. `ansible/roles/docker/files/mysql/externalConfigs/create_media_db.sql` — add `delete_grants` table definition.
10. New grant-creation endpoint (path TBD) — owner-only; wired into `admin/users.php`.
11. `ansible/roles/docker/files/apache/webroot/src/Controllers/MediaController.php` — extended further to join `delete_grants` and include granted assets in `can_delete: true` for contributor callers.

### Unchanged

- `GigHive/Sources/App/UploadView.swift` — "My Uploads" token flow unaffected by this refactor.
- `GigHive/Sources/App/UploaderDeleteTokenStore.swift` — store itself unchanged in Phase 1–2; Phase 3 Step 6 determines whether it can be narrowed or removed.
- `ansible/roles/docker/files/apache/webroot/db/delete_media_files.php` — delete execution endpoint unchanged; this refactor only changes eligibility signalling, not delete mechanics.

---

## Follow-on Work

- Once Phase 3 ships and `authDeleteTokens` / `tokenMap` are removed from `loadAuthenticatedVideos`, assess whether `UploaderDeleteTokenStore` is still needed at all for any path other than `UploadView`'s "My Uploads" list. If not, the store can be removed or narrowed.
- `UploadView` "My Uploads" section currently uses `UploaderDeleteTokenStore.load` to show the user their own recent authenticated uploads with individual delete buttons. That path is separate from the Media Database and is unaffected by this refactor — but should be reviewed for token-staleness risk under the same deduplication edge case.
- The deduplication edge case in `UploadService` (second upload of same content returns no token) should be documented in the server OpenAPI spec as a known limitation, with a user-facing message consistent with the one already in `UploadView.swift` line 1081.
- **JWT cutover** (Phases 4–5 of `feature_security_authentication_migration_jwt_implementation.md`) — when Basic Auth is retired and `$_SERVER['PHP_AUTH_USER']` is replaced by a JWT role claim, update the `can_delete` PHP logic in `MediaController::listJson()` to read the JWT role instead of the username string. Small targeted change, scoped to the same function modified in Phase 2 of this refactor.

---

## Tests

Tests are numbered using the project T-number convention. Server-side tests live in `ansible/roles/post_build_checks/tasks/main.yml` tagged `[smoke]`. Client-side invariants that cannot be exercised via Ansible are noted as iOS XCTest cases. All tests must be permanent and idempotent. The next available T-number is **T-134** — T-98 through T-115 are reserved by `feature_security_authentication_migration_jwt_implementation.md` and T-105 through T-111 and T-125 through T-133 are reserved by `feature_security_authentication_migration_jwt_oidc_phase5.md`. Verify availability in both docs before assigning any new T-number.

### Phase 2 tests — server `can_delete` response (add at Phase 2 ship)

| T-number | What it validates | Where |
|---|---|---|
| T-134 | Unauthenticated GET `/db/database.php` returns 401 — confirms the endpoint is still protected after the query change | `post_build_checks` |
| T-135 | Authenticated GET `/db/database.php` as `admin` returns 200 and every entry in the response has `can_delete: true` | `post_build_checks` |
| T-136 | Authenticated GET `/db/database.php` as `uploader` returns 200; a known guest-uploaded asset (seeded fixture with an `upload_jobs` row) has `can_delete: false` | `post_build_checks` |
| T-137 | Authenticated GET `/db/database.php` as `uploader` returns 200; a known authenticated upload (seeded fixture with no `upload_jobs` row and `delete_token_hash` set) has `can_delete: true` | `post_build_checks` |
| T-138 | `upload_source` field is present in every response entry and contains only `"authenticated"` or `"guest"` — no null, no other value | `post_build_checks` |
| T-139 | A guest-uploaded asset has `upload_source: "guest"`; an authenticated upload has `upload_source: "authenticated"` — validates the JOIN logic is classifying correctly | `post_build_checks` |

**"must never" invariant covered by T-134:** `database.php` must never be accessible without authentication after the Phase 2 query change.

**"must never" invariant — Option A only (if chosen):** The `database.php` response must never be served from a cache when it contains a plaintext `delete_token`. Validate by asserting the response includes `Cache-Control: no-store` (or equivalent) when `can_delete: true` entries are present. Add as T-140 at Phase 2 ship if Option A is selected.

### Phase 3 tests — iOS client behaviour (add at Phase 3 ship)

Server-side delete mechanics are unchanged; these tests validate that the iOS client correctly consumes `can_delete` and that the Keychain token store is no longer used for eligibility decisions. Tests follow the format and numbering in `docs/testing_ios.md` (Phase 5 — Server-Authoritative Delete Eligibility). UI tests go in `GigHiveUITests.swift` under `// MARK: - Phase 5 — Delete Eligibility`; unit tests go in `GigHiveTests.swift` under the same mark.

| # | Method name | File | Helper | Needs credentials | What it validates |
|---|---|---|---|---|---|
| 29 | `testDelEligUploaderNoDeleteButtonForGuestUpload` | `GigHiveUITests.swift` | `launchAuthListWithToken(injectToken: false, useUploader: true)` | `GH_TEST_UPLOADER_USER` + `GH_TEST_UPLOADER_PASS` | Signs in as `uploader`; waits for ≥1 `unified_list_video_cell`; asserts `unified_list_delete_button` does not exist anywhere in the list |
| 30 | `testDelEligAdminSeesDeleteButton` | `GigHiveUITests.swift` | `launchAuthListWithToken(injectToken: false, useUploader: false)` | `GH_TEST_HOST` + `GH_TEST_USER` (admin) + `GH_TEST_PASS` | Signs in as `admin`; waits for ≥1 `unified_list_video_cell`; asserts `unified_list_delete_button` exists on at least one cell (server returns `can_delete: true` for admin on all entries) |
| 31 | `testDelEligMissingCanDeleteDecodesAsFalse` | `GigHiveTests.swift` | n/a — unit test | None | Decodes synthetic `MediaEntry` JSON without `can_delete`; asserts `entry.canDelete == false`; no crash |
| 32 | `testDelEligGuestUploadDoesNotWriteTokenStore` | `GigHiveTests.swift` | n/a — unit test | None | Calls guest finalize path with a mock response containing `delete_token`; asserts `UploaderDeleteTokenStore.load(host:)` returns empty — guest tokens must never enter the authenticated store |

#### Test 29 — preconditions and known gaps

**Fixture precondition (P9 analogy):** Test 29 asserts that NO ✕ button appears anywhere in the list when signed in as `uploader`. This assertion is only valid if the test environment's media database contains no authenticated uploads made by the uploader account (i.e., no entries where `upload_jobs` row is absent AND `delete_token_hash` is non-null for that account). If the uploader has made any authenticated iPhone uploads on the dev server, those entries will have `can_delete: true` from Phase 2 and ✕ will appear — breaking the assertion. Before writing the test, confirm the dev server fixture has only guest-uploaded content accessible via `database.php`.

**Per-row identification gap (P10 analogy):** The assertion "no ✕ anywhere" is coarser than "no ✕ on this specific guest-uploaded cell." The coarser assertion is acceptable under the fixture precondition above. If per-cell assertions become necessary in the future (e.g., when the uploader has a mix of guest and authenticated uploads on the test server), add `"unified_list_delete_button_\(video.id)"` as a per-row accessibility identifier to each ✕ button during Phase 3 implementation and register it in `testing_ios.md`'s Accessibility Identifiers table. Until then, fixture isolation is the correct approach.

**`GH_TEST_GUEST_UPLOAD_FILE_ID` is read by the test runner only — not forwarded to the app (contrast with P6):** Unlike `GH_TEST_DELETE_FILE_ID`, which the app reads from `app.launchEnvironment` to inject a token, `GH_TEST_GUEST_UPLOAD_FILE_ID` is only used by the XCUITest runner process to identify which server asset to seed/verify in comments. It does NOT need to be set in `app.launchEnvironment`. Do not forward it via the helper's launch environment block.

#### "must never" invariants and their test coverage

| Invariant | Covered by |
|---|---|
| Guest `delete_token` must never enter `UploaderDeleteTokenStore` | Test 32 (unit test) |
| `database.php` must never be accessible without authentication | T-134 (Ansible) |
| Option A response must never be served from a cache (if Option A is chosen) | T-140 (Ansible, conditional) |

**Phase 4 tests 24–27 were updated when Phase 3 shipped.** Those tests drove ✕ visibility via `--uitest-inject-delete-token` (Keychain token injection). After Phase 3, `showDeleteButton` uses `video.canDelete` from the server response — the injection mechanism no longer affects ✕ visibility.

- Tests 24 (`testAuthDeleteButtonAbsentWithoutToken`), 25 (`testAuthDeleteButtonVisibleForOwnUpload`), and 27 (`testAuthDelete403ClearsToken`) were replaced with `XCTSkip` bodies. Rationale: admin always receives `can_delete: true` (so the "absent" assertion is inverted), token injection no longer drives visibility, and the uploader's `showDeleteButton` always returns `false` (deferred to JWT migration) so the 403 path is unreachable from the list UI.
- Test 26 (`testAuthDeleteConfirmDialogAppears`) was updated to remove token injection (`injectToken: false`); admin sees delete buttons without injection via server-provided `can_delete`.

See the supersession detail in `testing_ios.md` Phase 4 inventory.

### Phase 4 tests — admin-granted delete (add only if Phase 4 is implemented)

| T-number | What it validates | Where |
|---|---|---|
| T-141 | Owner-authenticated POST to grant-creation endpoint creates a `delete_grants` row; `database.php` response for the granted asset now returns `can_delete: true` for the grantee contributor | `post_build_checks` |
| T-142 | Non-owner (contributor) attempt to create a grant returns 403 | `post_build_checks` |
| T-143 | Unauthenticated attempt to create a grant returns 401 | `post_build_checks` |
| T-144 | After a grant is revoked/deleted, `database.php` returns `can_delete: false` for the previously granted asset | `post_build_checks` |

### Fixture prerequisites

T-100, T-101, T-103 require seeded database fixtures:
- A known guest upload: an `assets` row + a matching `upload_jobs` row with `file_relpath` in the correct format. Seed in a `block` before the tests and clean up in an `always` block after.
- A known authenticated upload: an `assets` row with `delete_token_hash` set and no corresponding `upload_jobs` row.

Verify that fixture cleanup uses `failed_when: false` on the delete tasks so a cleanup failure never masks a test result.

---

## Progress

### Completed

- [x] Phase 1, Step 1 — Removed `UploaderDeleteTokenStore.upsert` from `GuestUploadView.swift`
- [x] Phase 1, Step 2 — Suppressed uploader ✕ in `showDeleteButton`; admin-only until Phase 3 ships

### Remaining — This Refactor

- [ ] Phase 2 — all steps (server `can_delete` + smoke tests T-134 through T-139, plus T-140 if Option A)
- [ ] Phase 3 — Steps 1–6 (display fix + store cleanup); iOS XCTest cases 29–32; Step 7 deferred to JWT migration

### Remaining — Follow-on Tasks

- [ ] Phase 4 — Admin-granted delete rights (explicitly deferred; implement only if demand justifies; tests T-141 through T-144)
- [ ] `UploaderDeleteTokenStore` cleanup audit after Phase 3 ships
- [ ] `UploadView` "My Uploads" token-staleness review under deduplication edge case
- [ ] Add `upload_jobs.token_id → event_upload_tokens.token_id` FK — `upload_jobs` currently has no reference back to the QR authorization that permitted each upload; see `refactor_schema_upload_jobs_token_attribution.md`
- [ ] Document deduplication edge case in server OpenAPI spec
- [ ] JWT cutover — update `MediaController::listJson()` `can_delete` logic when Basic Auth is retired
