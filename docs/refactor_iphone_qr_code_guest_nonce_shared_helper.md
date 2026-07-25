# Refactor: Guest Credential Shared Helper (PHP)

## Status

Planning only — not implemented. Deferred from `refactor_iphone_report_video_flag_retract.md`.

---

## Background / Motivation

Three guest API endpoints contain a duplicated credential validation and event-resolution auth block (~40–50 lines each):

| Endpoint | Auth paths |
|---|---|
| `api/guest-gallery.php` | Nonce → approved upload; fallback `hash('sha256', $nonce)` → active token |
| `api/guest-report.php` | Nonce → approved upload; fallback `hash('sha256', $nonce)` → active token |
| `api/guest-delete.php` | Nonce → approved upload only (no token fallback) |

Each file independently copies:
- Nonce format regex
- `SELECT t.event_id FROM anon_upload_attributions JOIN upload_jobs JOIN event_upload_tokens` query
- The `hash('sha256', $nonce)` → `event_upload_tokens` token-hash fallback path
- 403 / 500 error handling

This is the brittle duplication pattern flagged in the SonarQube notes of `refactor_iphone_report_video_flag_retract.md`.

### Pre-existing Bug

`guest-delete.php` line 15 uses nonce regex `{30,40}` while `guest-gallery.php` and `guest-report.php` use `{30,43}`. A nonce of 41–43 characters is accepted by gallery and report but silently rejected by delete. A one-line short-term fix (`{30,40}` → `{30,43}` in `guest-delete.php`) is documented in `refactor_iphone_report_video_flag_retract.md`. The shared helper fixes this permanently.

---

## Goal

Extract the credential validation and event-resolution logic into `src/GuestCredentialResolver.php`, used by all three endpoints and any future guest API endpoints.

---

## Design

### `GuestCredentialResolver` class

Two public static methods. Both own the canonical nonce regex; neither writes HTTP response codes (caller decides HTTP behavior).

---

#### `resolveNonceOrToken(PDO $pdo, string $nonce): array|false`

Used by `guest-gallery.php` and `guest-report.php`.

1. Validate nonce format: `/^[A-Za-z0-9_\-]{30,43}$/` — return `false` immediately if invalid.
2. Try nonce → `anon_upload_attributions` → approved `upload_jobs` → `event_upload_tokens`:
   ```sql
   SELECT t.event_id, t.expires_at
   FROM anon_upload_attributions a
   JOIN upload_jobs j ON j.job_id = a.upload_job_id
   JOIN event_upload_tokens t ON t.token_id = a.token_id
   WHERE a.status_nonce = ? AND j.moderation_status = 'approved'
   ```
3. If no row: `hash('sha256', $nonce)` → active, unexpired `event_upload_tokens`:
   ```sql
   SELECT t.event_id, t.expires_at
   FROM event_upload_tokens t
   WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()
   ```
4. If still no row: return `false` (caller sends 403).

Returns `['event_id' => int, 'expires_at' => string]` on success.

**Note:** `expires_at` must be returned to avoid a second query in `guest-gallery.php` for the expiry check and `$daysRemaining` calculation.

---

#### `resolveNonceOnly(PDO $pdo, string $nonce): int|false`

Used by `guest-delete.php`. Nonce → approved upload path only. No token fallback — guests may only delete using a nonce tied to an approved upload.

1. Validate nonce format: `/^[A-Za-z0-9_\-]{30,43}$/` — return `false` if invalid.
2. Try nonce → `anon_upload_attributions` → approved `upload_jobs` → `event_upload_tokens`:
   ```sql
   SELECT t.event_id
   FROM anon_upload_attributions a
   JOIN upload_jobs j ON j.job_id = a.upload_job_id
   JOIN event_upload_tokens t ON t.token_id = a.token_id
   WHERE a.status_nonce = ? AND j.moderation_status = 'approved'
   ```
3. If no row: return `false` (caller sends 403).

Returns `event_id` int on success.

---

## Files to Change

### Checklist

- [ ] **Step 1 — Create `ansible/roles/docker/files/apache/webroot/src/GuestCredentialResolver.php`**
  - Two static methods: `resolveNonceOrToken` and `resolveNonceOnly`
  - Owns canonical nonce regex `/^[A-Za-z0-9_\-]{30,43}$/`
  - No HTTP side effects — returns typed array or `false` only
  - Throw `\PDOException` on DB error (caller wraps in try/catch and sends 500)
- [ ] **Step 2 — Edit `ansible/roles/docker/files/apache/webroot/api/guest-gallery.php`**
  - Replace lines 23–84 with `GuestCredentialResolver::resolveNonceOrToken($pdo, $nonce)`
  - Receive `expires_at` from return value; existing expiry / `$daysRemaining` logic unchanged
- [ ] **Step 3 — Edit `ansible/roles/docker/files/apache/webroot/api/guest-report.php`**
  - Replace lines 33–71 with `GuestCredentialResolver::resolveNonceOrToken($pdo, $nonce)`
- [ ] **Step 4 — Edit `ansible/roles/docker/files/apache/webroot/api/guest-delete.php`**
  - Replace lines 35–56 with `GuestCredentialResolver::resolveNonceOnly($pdo, $nonce)`
  - Fixes pre-existing `{30,40}` regex bug as a side effect
- [ ] **Step 5 — Extend smoke tests in `ansible/roles/shared_gallery/tasks/main.yml`**
  - Verify gallery, report, and delete all continue to pass after the auth path refactor

---

## Risk and Testing

- All three live guest endpoints change their internal auth path — regression risk is non-trivial
- Functional behavior is unchanged; only the code path changes
- Smoke tests for gallery, report, and delete must all pass before shipping
- Recommended: dev → lab → staging verification before prod deploy

---

## Scope Estimate

~2–3 hours. Not bundled with `refactor_iphone_report_video_flag_retract.md` — deferred to keep that refactor focused.
