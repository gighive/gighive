# Refactor: Guest Credential Shared Helper (PHP)

## Status — 2026-08-15 — COMPLETE

**Completed (local patch, 2026):** The `{30,40}` → `{30,43}` regex bug in `guest-delete.php` was fixed in-place as part of the flag-retract work.

**Completed (this refactor):** `src/Services/GuestCredentialResolver.php` created. `guest-gallery.php`, `guest-report.php`, `guest-delete.php`, and `guest-stream.php` all updated to use the shared helper. Smoke tests in `shared_gallery` extended to cover delete and stream. Steps 1–6 of the checklist are checked off.

**Complete:** Deployed and verified across all environments 2026-08-15. dev ✅ → lab ✅ → staging ✅ → prod ✅.

**Separate follow-up:** `guest-status.php` still uses `{30,40}` — inconsistent with the canonical `{30,43}`. Not covered by this plan; see Out-of-Scope Follow-Up.

---

## Elevator Pitch

Every time the GigHive team needs to fix a bug in how guests authenticate to view their event gallery — a regex length rule, a token expiry check, a fallback path — that fix must currently be made in four separate PHP files. One of those files already drifted from the others and silently rejected valid guest sessions. This refactor creates a single shared authentication helper so the fix only ever happens in one place, making guest gallery, report, delete, and stream actions consistent and the codebase dramatically easier to maintain.

---

## Background / Motivation

Four guest API endpoints contain a duplicated credential validation and event-resolution auth block (~40–50 lines each):

| Endpoint | Auth paths | In original scope |
|---|---|---|
| `api/guest-gallery.php` | Nonce → approved upload; fallback `hash('sha256', $nonce)` → active token | Yes |
| `api/guest-report.php` | Nonce → approved upload; fallback `hash('sha256', $nonce)` → active token | Yes |
| `api/guest-delete.php` | Nonce → approved upload only (no token fallback) | Yes |
| `api/guest-stream.php` | Nonce → approved upload; fallback `hash('sha256', $nonce)` → active token | **No — gap in original plan** |

`guest-stream.php` contains an identical auth block (lines 31–82) to gallery and report: nonce path, token-hash fallback, and event-scoped expiry check. It was omitted from the original proposal. Completing the refactor without covering stream leaves a fourth copy of the auth block in production.

Each file independently copies:
- Nonce format regex
- `SELECT t.event_id FROM anon_upload_attributions JOIN upload_jobs JOIN event_upload_tokens` query
- The `hash('sha256', $nonce)` → `event_upload_tokens` token-hash fallback path
- 403 / 500 error handling

This is the brittle duplication pattern flagged in the SonarQube notes of `refactor_iphone_report_video_flag_retract.md`.

### Pre-existing Bug — Fixed Locally

`guest-delete.php` originally used nonce regex `{30,40}` while the other three endpoints used `{30,43}`. A nonce of 41–43 characters was silently rejected by delete but accepted by gallery, report, and stream. The one-line fix has already been applied. The shared helper is no longer needed to fix this specific bug, but without it each endpoint still independently maintains its own regex copy — consistency relies on manual discipline across files.

**Remaining inconsistency:** `guest-status.php` still uses `{30,40}`. It has a different auth pattern (no approved-upload lookup, no token-hash fallback) and is out of scope for this refactor. A separate one-line fix is tracked in Out-of-Scope Follow-Up.

---

## Architecture Context

### Where shared PHP code lives

This codebase follows a PSR-4 namespaced MVC layout under `src/`. The `composer.json` maps `Production\Api\` → `src/`, so every shared class belongs there and is autoloaded by all endpoints. No explicit `require` calls are needed for classes under `src/` — every guest endpoint already bootstraps the autoloader with `require_once __DIR__ . '/../vendor/autoload.php'`.

```
src/
  Config/          Configuration constants (e.g. MediaTypes)
  Controllers/     HTTP-facing controllers (MediaController, UploadController, ...)
  Exceptions/      Typed domain exceptions
  Infrastructure/  Cross-cutting concerns (Database, FileStorage)
  Models/          Plain data models (FileModel, JamModel, SongModel)
  Presentation/    View rendering helpers
  Repositories/    Pure data-access queries (EventRepository, FileRepository, ...)
  Services/        Business logic and multi-step operations
  Validation/      Input validation
  Views/           PHP view templates
```

Every guest endpoint already uses this layer: `use Production\Api\Infrastructure\Database;` is present in all four targets.

### Why `src/Services/` and not `src/Repositories/`

`src/Repositories/` is pure data access — one query per method, no logic between steps. `GuestCredentialResolver` implements a two-path fallback chain: nonce → approved upload first; raw-token hash fallback second. That is business logic, not data access, and belongs in `src/Services/`.

The direct analogue already in `src/Services/` is `UploadTokenValidator`, which handles the token-hash path (raw token → `event_upload_tokens`) against the same database table used in the fallback path here. `UploadTokenValidator` cannot be reused directly for the fallback because its `TokenValidationResult` return type does not include `expires_at`, which `guest-gallery.php` and `guest-stream.php` need for the expiry check and `$daysRemaining` calculation. The near-duplication of the fallback SQL is intentional and documented.

### What to ignore

- **`includes/`** — functional-style legacy helpers (`json_ok`, `json_err`). No classes, no namespace. New code does not go here.
- **`api/`** — HTTP entry points only. Shared library code placed here cannot be cleanly excluded from Apache access and is not on the autoload path.
- **`admin/*_lib.php`** — admin-specific procedural includes (e.g. `import_manifest_lib.php`). Not part of the `Production\Api` class hierarchy.

### Instance-based design, not static

Every class in `src/Services/` uses constructor injection — not static methods. `UploadTokenValidator` is the direct model:

```php
class UploadTokenValidator {
    public function __construct(private \PDO $pdo) {}
    public function validate(string $rawToken): ?TokenValidationResult { ... }
}

// Usage in callers:
$validator = new UploadTokenValidator($pdo);
$result    = $validator->validate($rawToken);
```

`GuestCredentialResolver` must follow the same pattern. Static methods were specified in the original plan — that design is incorrect for this codebase. The correct pattern is `new GuestCredentialResolver($pdo)` with non-static instance methods. This aligns with every existing service class, is testable with a mock PDO, and avoids threading `$pdo` through every call signature.

---

## Does the iPhone App Need to Change?

No. `src/Services/GuestCredentialResolver.php` is not an endpoint — it is an internal server-side class. The iPhone app never calls it directly.

The iPhone app speaks only to the HTTP API surface: URLs, request parameters, and JSON response shapes. Those are all owned by the `api/*.php` entry points. This refactor leaves every one of those unchanged:

| What the iPhone sees | Before | After |
|---|---|---|
| `/api/guest-gallery.php?nonce=...` | exists, same response shape | unchanged |
| `/api/guest-report.php` | exists, same request/response | unchanged |
| `/api/guest-delete.php` | exists, same request/response | unchanged |
| `/api/guest-stream.php?nonce=&job_id=` | exists, same byte stream | unchanged |

The only scenarios where the iPhone app would need a change are: adding a new endpoint URL, changing a request parameter name or type, or changing a JSON response field. None of those happen here.

---

## Goal

Extract the credential validation and event-resolution logic into `src/Services/GuestCredentialResolver.php` (namespace `Production\Api\Services`), used by all four guest endpoints and any future guest API endpoints. Callers add `use Production\Api\Services\GuestCredentialResolver;` to their existing `use` block — no additional require is needed.

---

## Design

### `GuestCredentialResolver` class

Instance-based, following the same pattern as `UploadTokenValidator` in `src/Services/`. The PDO connection is injected via the constructor — it is not passed per call. Both methods return a typed value or `false`; neither writes HTTP response codes (caller decides all HTTP behavior, including the 400 vs 403 distinction).

```php
namespace Production\Api\Services;

class GuestCredentialResolver {
    public function __construct(private \PDO $pdo) {}

    public function resolveNonceOrToken(string $nonce): array|false { ... }
    public function resolveNonceOnly(string $nonce): int|false { ... }
}
```

**Instantiation in callers (Steps 2–5):**

```php
use Production\Api\Services\GuestCredentialResolver;
// ... (after $pdo is established)
$resolver = new GuestCredentialResolver($pdo);
```

---

#### `resolveNonceOrToken(string $nonce): array|false`

Used by `guest-gallery.php`, `guest-report.php`, and `guest-stream.php`.

**Note on format validation:** Callers validate the nonce format before connecting to the database and before calling this method (sending 400 on failure). The helper does NOT re-validate format — this preserves the 400 vs 403 distinction and eliminates redundancy.

1. Try nonce → `anon_upload_attributions` → approved `upload_jobs` → `event_upload_tokens`:
   ```sql
   SELECT t.event_id, t.expires_at
   FROM anon_upload_attributions a
   JOIN upload_jobs j ON j.job_id = a.upload_job_id
   JOIN event_upload_tokens t ON t.token_id = a.token_id
   WHERE a.status_nonce = ? AND j.moderation_status = 'approved'
   ```
2. If no row: `hash('sha256', $nonce)` → active, unexpired `event_upload_tokens`:
   ```sql
   SELECT t.event_id, t.expires_at
   FROM event_upload_tokens t
   WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()
   ```
3. If still no row: return `false` (caller sends 403).

Returns `['event_id' => int, 'expires_at' => string]` on success.

**Note:** `expires_at` must be returned to avoid a second query in `guest-gallery.php` and `guest-stream.php` for the expiry check and `$daysRemaining` calculation. `guest-report.php` ignores `expires_at` — callers are free to discard unused fields.

---

#### `resolveNonceOnly(string $nonce): int|false`

Used by `guest-delete.php`. Nonce → approved upload path only. No token fallback — guests may only delete using a nonce tied to an approved upload. Gallery expiry is intentionally not checked: guests may delete their own video at any time.

**Note on format validation:** Same as above — callers own format validation. Helper does not re-validate.

1. Try nonce → `anon_upload_attributions` → approved `upload_jobs` → `event_upload_tokens`:
   ```sql
   SELECT t.event_id
   FROM anon_upload_attributions a
   JOIN upload_jobs j ON j.job_id = a.upload_job_id
   JOIN event_upload_tokens t ON t.token_id = a.token_id
   WHERE a.status_nonce = ? AND j.moderation_status = 'approved'
   ```
2. If no row: return `false` (caller sends 403).

Returns `event_id` int on success. Note: `guest-delete.php`'s downstream UPDATE scopes ownership via `WHERE a.status_nonce = ?` and does not consume `event_id` — it is returned for forward-compatibility with future callers.

---

### Caller replacement pattern

The following shows what lines 23–84 of `guest-gallery.php` become after the refactor. All other callers follow the same structure; the only differences are which result fields are used and what the 403 response body contains.

**Before (lines 23–84 — ~60 lines of duplicated auth logic):**
```php
try {
    $stmt = $pdo->prepare(
        'SELECT t.event_id, t.expires_at FROM anon_upload_attributions a ...'
    );
    $stmt->execute([$nonce]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}
if ($row === false) {
    try {
        $tokenHash = hash('sha256', $nonce);
        $stmt = $pdo->prepare('SELECT t.event_id, t.expires_at FROM event_upload_tokens t ...');
        $stmt->execute([$tokenHash]);
        $tokenRow = $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        http_response_code(500);
        exit;
    }
    if ($tokenRow === false) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $eventId = (int)$tokenRow['event_id'];
    // ... expiry / $daysRemaining calculation from $tokenRow['expires_at']
} else {
    $eventId = (int)$row['event_id'];
    // ... expiry / $daysRemaining calculation from $row['expires_at']
}
```

**After (~10 lines):**
```php
$resolver = new GuestCredentialResolver($pdo);
try {
    $result = $resolver->resolveNonceOrToken($nonce);
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}
if ($result === false) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
$eventId   = (int)$result['event_id'];
$expiresAt = $result['expires_at'];
// ... existing expiry / $daysRemaining logic continues unchanged
```

**For `guest-delete.php` (`resolveNonceOnly`):**
```php
$resolver = new GuestCredentialResolver($pdo);
try {
    $eventId = $resolver->resolveNonceOnly($nonce);
} catch (\PDOException $e) {
    http_response_code(500);
    exit;
}
if ($eventId === false) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
// $eventId not consumed by the downstream UPDATE; ownership scoped via a.status_nonce
```

---

### SonarQube / Best-Practice Notes

- **RSPEC-3776 (cognitive complexity):** `resolveNonceOrToken` has a two-branch fallback chain with a nested query on the miss path. Each branch is ~5 lines of SQL execution — manageable. Keep the two paths as sequential blocks (not nested) to minimize cognitive depth.
- **RSPEC-2635 (sensitive data in SQL):** The nonce is bound as a `?` parameter in all three prepared statements — never interpolated. The token hash is computed in PHP before binding. No violation.
- **RSPEC-107 (too many parameters):** After constructor injection, both methods take 1 parameter (`string $nonce`). No violation.
- **Observability:** `UploadTokenValidator` uses `error_log('[UPLOAD_TOKEN_DEBUG] ...]')` calls to make auth failures diagnosable in production. `GuestCredentialResolver` should follow the same pattern — log on both `false` returns (nonce path miss and token fallback miss) with enough context to distinguish the two cases.

---

## Implementation Overview

High-level map of all changes before the detailed checklist.

| Step | Action | File | Notes |
|---|---|---|---|
| 1 | Create helper class | `src/Services/GuestCredentialResolver.php` | **Deploy first — must exist before Steps 2–5** |
| 2 | Wire gallery | `api/guest-gallery.php` | Replace auth block lines 23–84; keep expiry / `$daysRemaining` logic in caller |
| 3 | Wire report | `api/guest-report.php` | Replace auth block lines 34–72; `expires_at` unused by this endpoint |
| 4 | Wire stream | `api/guest-stream.php` | Replace auth block lines 31–82; added to scope — was missing from original plan |
| 5 | Wire delete | `api/guest-delete.php` | Replace auth block lines 35–56; nonce-only path, no token fallback |
| 6 | Extend smoke tests | `ansible/roles/shared_gallery/tasks/main.yml` | Cover all four endpoints post-refactor |
| 7 | Deploy | Ansible playbook | Deploy Step 1 first; verify file exists on host; then deploy Steps 2–5 |

No database changes. No new endpoints. No iPhone app changes. No DDL. No `post_build_checks` 401 check needed — guest endpoints are public (no htpasswd); smoke tests in `shared_gallery` serve as functional proof of deploy.

---

## Files Under Change

### Checklist

- [x] **Step 1 — Create `ansible/roles/docker/files/apache/webroot/src/Services/GuestCredentialResolver.php`**
  - Namespace `Production\Api\Services`
  - Constructor injection: `public function __construct(private \PDO $pdo) {}`
  - Two public instance methods: `resolveNonceOrToken` and `resolveNonceOnly`
  - Does NOT validate nonce format — callers own that (400 vs 403 distinction preserved)
  - Returns typed value or `false` only; no HTTP side effects
  - Throws `\PDOException` on DB error (callers wrap in try/catch and send 500)
  - Add `error_log()` calls on both `false` return paths (match `UploadTokenValidator` pattern)
  - **Deploy this file before deploying Steps 2–5**

- [x] **Step 2 — Edit `ansible/roles/docker/files/apache/webroot/api/guest-gallery.php`**
  - Add `use Production\Api\Services\GuestCredentialResolver;` to the existing `use` block
  - After `$pdo` is established, instantiate: `$resolver = new GuestCredentialResolver($pdo);`
  - Replace lines 23–84 with the caller pattern from the Design section (try/catch + false check + unpack)
  - Unpack `$result['event_id']` and `$result['expires_at']`; existing expiry / `$daysRemaining` logic is unchanged
  - Note: `$credentialHash = hash('sha256', $nonce)` on line 86 is **not** part of the auth block — it stays in the caller for the downstream gallery query join

- [x] **Step 3 — Edit `ansible/roles/docker/files/apache/webroot/api/guest-report.php`**
  - Add `use Production\Api\Services\GuestCredentialResolver;` to the existing `use` block
  - After `$pdo` is established, instantiate: `$resolver = new GuestCredentialResolver($pdo);`
  - Replace lines 34–72 with the caller pattern (try/catch + false check + unpack)
  - Only `$result['event_id']` is needed; `expires_at` is unused by this endpoint
  - Note: `$credentialHash = hash('sha256', $nonce)` on line 74 is **not** part of the auth block — it stays in the caller for report INSERT/DELETE identity scoping

- [x] **Step 4 — Edit `ansible/roles/docker/files/apache/webroot/api/guest-stream.php`** *(added — was missing from original plan)*
  - Add `use Production\Api\Services\GuestCredentialResolver;` to the existing `use` block
  - After `$pdo` is established, instantiate: `$resolver = new GuestCredentialResolver($pdo);`
  - Replace lines 31–82 with the caller pattern (try/catch + false check + unpack)
  - Unpack `$result['event_id']` and `$result['expires_at']`; existing expiry check (403 `gallery expired`) at lines 73–81 is preserved in caller
  - Note: stream returns 403 on expiry, not the 200 `{status:'expired'}` that gallery returns — caller behavior differs; helper stays neutral

- [x] **Step 5 — Edit `ansible/roles/docker/files/apache/webroot/api/guest-delete.php`**
  - Add `use Production\Api\Services\GuestCredentialResolver;` to the existing `use` block
  - After `$pdo` is established, instantiate: `$resolver = new GuestCredentialResolver($pdo);`
  - Replace lines 35–56 with the `resolveNonceOnly` caller pattern (try/catch + false check)
  - The `{30,40}` regex bug is already fixed in-place; no additional regex change needed
  - `$eventId` is not consumed by the downstream UPDATE (which scopes via `a.status_nonce`); discard it

- [x] **Step 6 — Extend smoke tests in `ansible/roles/shared_gallery/tasks/main.yml`**
  - Verify gallery, report, delete, and stream all continue to pass after the auth path refactor
  - Cover: valid approved-nonce path, valid raw-token path, invalid nonce (403), expired gallery (gallery/stream)

- [ ] **Step 7 — Deploy via Ansible**
  - Deploy `GuestCredentialResolver.php` (Step 1) first and verify the file exists on the host
  - Then deploy the four modified endpoint files (Steps 2–5)
  - Run playbook: dev → verify smoke tests → lab → verify → staging → verify → prod
  - Do not deploy endpoint files before the helper class is confirmed present on the target host

---

## Risk and Testing

- Four live guest endpoints change their internal auth path — regression risk is non-trivial
- Functional behavior is unchanged; only the code path changes
- Smoke tests for gallery, report, delete, and stream must all pass before shipping
- Recommended: dev → lab → staging verification before prod deploy

**Consolidated failure point:** After the refactor, a bug in `GuestCredentialResolver` takes down all four guest auth paths simultaneously. Previously a bug in one endpoint's inline auth block left the other three unaffected. This is a known, accepted trade-off of consolidation — mitigated by smoke test coverage across all four endpoints before each environment deploy.

**Deployment ordering risk:** If the modified endpoint files are deployed before `GuestCredentialResolver.php` exists on the host, PHP throws a fatal `Class not found` error on every guest auth request. Mitigation: deploy helper first (Step 7 enforces this ordering).

**Rollback:** No DDL involved. Rollback is `git revert` of all five files + redeploy. The helper class and all four endpoint edits revert cleanly with no database side effects.

**post_build_checks:** Guest endpoints (`/api/guest-*.php`) are public — not htpasswd-protected — and will not return 401. The standard `post_build_checks/tasks/main.yml` unauthenticated 401 check pattern does not apply. Smoke tests in `shared_gallery` serve as the functional proof of deploy.

---

## Scope Estimate

~3–4 hours with `guest-stream.php` added to scope. Not bundled with `refactor_iphone_report_video_flag_retract.md` — deferred to keep that refactor focused.

---

## Out-of-Scope Follow-Up

**`guest-status.php` regex fix:** `guest-status.php` still uses `{30,40}`. It has a different auth pattern (no approved-upload lookup, no token-hash fallback) so it would not use the shared helper, but its nonce regex should be updated to `{30,43}` as a standalone one-line fix independent of this refactor.

---

## Progress

### Completed

- `{30,40}` → `{30,43}` regex bug in `guest-delete.php` fixed locally as part of flag-retract work.

### Completed — This Feature

- [x] Step 1: Create `src/Services/GuestCredentialResolver.php`
- [x] Step 2: Wire `api/guest-gallery.php`
- [x] Step 3: Wire `api/guest-report.php`
- [x] Step 4: Wire `api/guest-stream.php`
- [x] Step 5: Wire `api/guest-delete.php`
- [x] Step 6: Extend smoke tests in `shared_gallery`
- [x] Step 7: Deploy dev ✅ → lab ✅ → staging ✅ → prod ✅

### Remaining — Follow-on Tasks

- `guest-status.php` regex fix: update `{30,40}` → `{30,43}` (one-line, separate from this refactor)
