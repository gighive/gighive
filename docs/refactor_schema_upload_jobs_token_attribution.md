# Refactor: Add `upload_jobs.token_id` FK to `event_upload_tokens`

## Problem

`upload_jobs` has no foreign key reference to `event_upload_tokens`. When a guest
uploads a file via a QR URL, the resulting `upload_jobs` row has no record of which
`event_upload_tokens` authorization was used to permit the upload.

The current schema:

```
event_upload_tokens (token_id PK, event_id, token_hash, expires_at, ...)
upload_jobs         (id PK, job_id UUID, event via upload context, ...)
```

There is no `upload_jobs.token_id → event_upload_tokens.token_id` link. The
relationship between which QR code authorized which upload is implicit at best
(both reference the same event) and untracked at worst.

This was noticed during Phase 5 (server-authoritative delete eligibility) when
diagnosing why `GH_TEST_GUEST_NONCE` (an `event_upload_tokens` raw token) was not
found in `upload_jobs.job_id` — they are structurally different identifiers and
were never meant to be the same value.

## Proposed Change

Add a nullable FK column to `upload_jobs`:

```sql
ALTER TABLE upload_jobs
  ADD COLUMN token_id BIGINT UNSIGNED NULL
    COMMENT 'FK to event_upload_tokens.token_id; NULL for non-QR uploads',
  ADD CONSTRAINT fk_upload_jobs_token
    FOREIGN KEY (token_id) REFERENCES event_upload_tokens (token_id)
    ON DELETE SET NULL;

ALTER TABLE upload_jobs
  ADD KEY idx_upload_jobs_token_id (token_id);
```

The column is nullable so that:
- Non-QR uploads (e.g. admin direct uploads) have `token_id = NULL`.
- Existing rows are unaffected.

The upload flow (PHP `UploadService` / TUS handler) must be updated to populate
`token_id` from the validated `event_upload_tokens` record at job creation time.

## Benefits

- **Traceability**: you can query which QR authorization produced which upload job.
- **No redundant storage**: the raw nonce is never stored anywhere; attribution is
  via the FK to the token record (which stores only the hash).
- **Audit / expiry enforcement**: joins between `upload_jobs` and
  `event_upload_tokens` can surface uploads made against since-expired or
  since-deactivated tokens, supporting future moderation tooling.
- **Cleaner test setup**: `GH_TEST_GUEST_JOB_ID` can be derived deterministically
  from `token_id` rather than requiring a separate numeric lookup.

## Risks and Side Effects

These must be evaluated before implementation.

### 1. Cascade complexity

`event_upload_tokens` already has `ON DELETE CASCADE` from `events`. Adding
`upload_jobs.token_id` with `ON DELETE SET NULL` creates a three-level chain:

```
events (deleted)
  → event_upload_tokens rows cascade-deleted
    → upload_jobs.token_id set NULL for all affected rows
```

The `upload_jobs` rows survive but lose attribution silently. Decide before
implementing whether this is acceptable or whether upload jobs should instead
be cascade-deleted when their authorizing token is removed.

### 2. Historical data is permanently unbackfillable

Raw tokens are never stored (only their SHA-256 hash). Every existing
`upload_jobs` row will permanently have `token_id = NULL`. There is no way to
recover attribution for historical uploads — not from the database, not from
logs, not from the QR URLs themselves. Document this clearly so nobody wastes
time attempting a backfill.

### 3. NULL ambiguity for historical rows

A NULL `token_id` can mean two distinct things:
- "This was a non-QR upload (e.g. admin direct upload)" — expected and permanent.
- "This was a QR upload before the FK column was added" — unknown and unresolvable.

Without a discriminator column (e.g. `upload_source ENUM('qr','direct')`) the two
cases are indistinguishable for pre-migration rows. Consider adding such a column
alongside the FK if the distinction matters for reporting or moderation.

### 4. Silent NULL population if upload flow is not updated

The FK only validates that when a `token_id` value IS written, it references a
valid `event_upload_tokens` row. It does not enforce that QR-authenticated uploads
must have a non-NULL value. If `UploadService` or the TUS handler is not updated,
new QR-based uploads silently receive `token_id = NULL` with no schema-level error.
A `CHECK` constraint or application-level assertion is needed to catch this.

### 5. JWT migration may obsolete this work

If the JWT migration replaces `event_upload_tokens` database rows with JWT claims
(tokens validated in-memory rather than looked up in a table), this FK relationship
becomes a dead-end before it delivers value. Confirm the post-JWT token model before
investing in this change. See
`feature_security_authentication_migration_jwt_implementation.md`.

### 6. Token expiry semantics on joins

`event_upload_tokens.expires_at` controls whether a QR URL is still valid for
*future* uploads — it does not retroactively invalidate completed uploads. Any query
joining `upload_jobs` to `event_upload_tokens` must filter on `expires_at` carefully
to avoid treating a legitimate historical upload as suspect simply because the
authorizing token has since expired or been deactivated (`is_active = 0`).

---

## Scope

- Schema: one `ALTER TABLE` (nullable column + FK + index); optionally a second
  `upload_source` discriminator column (see Risk 3).
- PHP: `UploadService` (or equivalent TUS handler) — populate `token_id` when
  creating an `upload_jobs` row from a QR-authenticated request.
- No iOS changes required.
- Apply via BABRRR process on each environment.

## Status

- [ ] Deferred — implement after JWT migration stabilises the upload auth model.
  Confirm the post-JWT token storage design before beginning (Risk 5).
  Ensure this is scoped alongside `feature_security_authentication_migration_jwt_implementation.md`.
