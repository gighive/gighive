# Problem: TUS Finalize Path Does Not Return Delete Token; Response Wrapped in HTML

## Business Summary

Every authenticated file upload via the iOS app incorrectly shows a warning
saying the file was a duplicate, even when it is a brand-new upload. The upload
system was migrated to a newer file-transfer method (TUS) but the step that
generates and returns a security token after a successful upload was not carried
over. Two targeted server changes fix this: one restores the token for authenticated
uploads, and one corrects the response format so the app can read it cleanly.

## Discovered

During Phase 5 validation of `refactor_video_player_page_delete_eligibility.md`
(2026-08-30), when testing authenticated uploads via the iOS "Upload a File" flow
against the dev server.

---

## Problem 1: `finalizeTusUpload` Never Returns a `delete_token`

### Symptom

Every successful authenticated upload via the TUS path shows the iOS "dedup" alert:

> *File uploaded successfully, but no delete token was returned by the server.
> This usually happens when the server dedupes the upload (same file content/sha256
> as a previous upload). Deduped uploads can't be deleted from the server via the app.*

The upload is **not** a duplicate — the server returns HTTP 201 and a new `asset_id`.
The message appears for every TUS-based authenticated upload, fresh or deduped alike.

### Root Cause

Two upload code paths exist in `src/Services/UploadService.php`:

**Old path — `processUpload()`** (non-TUS, lines ~173–246):
Generates a random delete token, hashes it, stores the hash via
`setDeleteTokenHashIfNull`, and includes the plaintext token in the response:
```php
$deleteToken = bin2hex(random_bytes(32));
$hash = hash('sha256', $deleteToken);
if (!$this->assetRepo->setDeleteTokenHashIfNull($assetId, $hash)) {
    $deleteToken = null;
}
// ...
if (is_string($deleteToken) && $deleteToken !== '') {
    $resp['delete_token'] = $deleteToken;
}
```

**New path — `finalizeTusUpload()`** (TUS, lines ~270–366):
Builds a `$result` array with asset metadata (`id`, `asset_id`, `file_name`,
`file_type`, `size_bytes`, etc.) but **never generates or includes `delete_token`**.
For guest/token-mode uploads it adds `status_nonce` and `upload_job_id`, but
authenticated uploads receive no token at all.

The iOS `UploadView` checks for `delete_token` in the finalize response and shows
the dedup alert whenever it is absent — regardless of whether the upload was
actually a dedup or a fresh new asset.

### Impact

- Every TUS-based authenticated upload triggers a false dedup alert on iOS.
- `UploaderDeleteTokenStore` is never populated for TUS uploads. If the iOS
  authenticated delete path (Phase 3 Step 7, deferred to JWT migration) is
  ever implemented, it would find no stored token for any TUS-uploaded asset.
- The dedup alert message misleads the user into thinking they uploaded a
  duplicate file when they did not.

### Fix

**File:** `src/Services/UploadService.php` — `finalizeTusUpload()`

Add delete token generation immediately before `return $result;`, after the
existing token-mode block. Uses the same `setDeleteTokenHashIfNull` helper
already called by `processUpload`:

```php
// Authenticated upload: generate and store delete token, mirroring processUpload.
// Guest/QR uploads ($tokenResult !== null) do not receive a delete token.
if ($tokenResult === null) {
    $rawToken = bin2hex(random_bytes(32));
    $hash     = hash('sha256', $rawToken);
    if ($this->assetRepo->setDeleteTokenHashIfNull($assetId, $hash)) {
        $result['delete_token'] = $rawToken;
    }
}

return $result;
```

The block replaces the bare `return $result;` at the end of the method. No
other changes to `UploadService.php` are needed.

**Dedup behavior preserved:** For a genuine duplicate checksum, the TUS hook
sets `tus_uploads.asset_id` to the existing asset's ID. That asset already has
`delete_token_hash` set (from its original upload), so `setDeleteTokenHashIfNull`
returns `false` and no `delete_token` is added to the response — the iOS dedup
alert fires correctly. The fix does not change this behavior.

**Code duplication (PPRR P2):** The token generation block is now present in
both `processUpload` and `finalizeTusUpload`. A follow-on refactor should extract
it to a private `generateAndStoreDeleteToken(int $assetId): ?string` method.

---

## Problem 2: Finalize Endpoint Returns HTML-Wrapped JSON

### Symptom

Xcode log shows:
```
[UploadView] Finalize direct JSON decode failed; attempting extraction
[FinalizeResponseHandler] Extracted JSON from <pre> block (entity-decoded)
```

The iOS client falls back to stripping the JSON out of a `<pre>` HTML block and
entity-decoding it before parsing. The fallback works, but direct `JSONDecoder`
on the raw response body fails.

### Root Cause

**File:** `src/index.php` (the MVC router, lines 87–105)

The router contains a branch that renders an HTML confirmation page when the
incoming request has `Accept: text/html` in its headers:

```php
$ui      = $_GET['ui'] ?? '';
$accept  = $_SERVER['HTTP_ACCEPT'] ?? '';
$wantsHtml = ($ui === 'html') || (stripos($accept, 'text/html') !== false);

if ($method === 'POST' && $wantsHtml) {
    // renders <!DOCTYPE html> ... <pre>$bodyPretty</pre> ... </html>
} else {
    header('Content-Type: application/json');
    echo json_encode($resp['body']);
}
```

The TUSKit library (used by the iOS app) sends `Accept: text/html,*/*` as part
of its default headers. This triggers `$wantsHtml = true` for the finalize POST,
causing the router to wrap the JSON body in an HTML page with a `<pre>` block.
The HTML branch was designed for browser-based form uploads, not API clients.

### Impact

- The iOS extraction fallback is fragile. Any change to the HTML structure of the
  response (different wrapper tag, additional whitespace, encoding changes) would
  break it silently.
- `JSONDecoder` cannot decode the raw response, so the primary decode path always
  fails. The first decode attempt is wasted on every upload.

### Fix

**File:** `src/index.php` — exclude `/uploads/finalize` from the HTML branch.

The finalize endpoint is an API-only route; it must never return HTML regardless
of the client's `Accept` header. Change one condition:

```php
// Before
if ($method === 'POST' && $wantsHtml) {

// After
if ($method === 'POST' && $wantsHtml && $path !== '/uploads/finalize') {
```

This leaves the HTML confirmation page intact for the browser-based `/uploads`
and `/media-files` POST routes while guaranteeing the finalize route always
returns bare JSON.

---

## Implementation

| # | File | Change |
|---|------|--------|
| 1 | `src/Services/UploadService.php` | Add delete token generation block before `return $result;` in `finalizeTusUpload()` — ~10 lines |
| 2 | `src/index.php` | Add `&& $path !== '/uploads/finalize'` to the `$wantsHtml` condition — 1 term added |
| 3 | `ansible/roles/post_build_checks/tasks/main.yml` | Add `delete_token` assertion to the existing Basic Auth finalize smoke test (~line 505); remove the stale comment at ~line 515 that says "no longer returns delete_token" |
| 4 | iOS `FinalizeResponseHandler` | Remove the `<pre>` extraction fallback once Fix 2 is confirmed deployed and returning bare JSON |

After Fix 1, the iOS `UploadView` will receive the token, store it in
`UploaderDeleteTokenStore`, and suppress the false dedup alert.

After Fix 2, the iOS `FinalizeResponseHandler` direct JSON decode will succeed
on the first attempt. Fix 4 then removes the now-dead fallback code.

**Note on Fix 3:** The existing smoke test comment at `main.yml` ~line 515
explicitly states `finalizeTusUpload()` no longer returns `delete_token`. This
comment must be removed and the assertion block extended to verify
`delete_token` is a 64-character hex string (i.e. `bin2hex(random_bytes(32))`)
for authenticated finalize responses. The T-97 token-mode finalize test must
**not** assert `delete_token` — guest uploads correctly do not receive one.

---

---

## PPRR Findings

| ID | Severity | Category | Finding | Resolution |
|---|---|---|---|---|
| P1 | High | Smoke test | Existing Basic Auth finalize smoke test (`main.yml` ~line 505) does not assert `delete_token`; comment at ~line 515 explicitly says "no longer returns delete_token" — both must be updated. | Added as Fix 3 in Implementation table. |
| P2 | Medium | Code duplication | Token generation logic is identical in `processUpload` and `finalizeTusUpload`; should be extracted to a private helper. | Flagged as follow-on refactor in the Fix 1 section. |
| P3 | Low | Correctness gap | Doc did not clarify that genuine deduplication still correctly suppresses the token after Fix 1. | Explained in the Fix 1 section under "Dedup behavior preserved". |
| P4 | Low | Completeness | iOS `<pre>` fallback removal was only in Status, not Implementation. | Added as Fix 4 in Implementation table. |
| C1 | Low | Formatting | Duplicate `---` before Implementation section. | Removed. |

---

## Status

- [x] Both issues are pre-existing TUS migration regressions; neither was introduced
  by the delete eligibility refactor.
- [x] Fix 1 — `finalizeTusUpload()` now generates and returns `delete_token` for
  authenticated uploads. Validated on dev 2026-08-30 for both roles:
  - Admin (asset 33): debug log shows `finalize delete_token present (len=64)`,
    `saved delete token`, "Upload succeeded." dialog. Finalize response 402 bytes.
  - Uploader (asset 34): same clean result. `canDelete=2` in the subsequent
    database load confirms server-authoritative attribution is correct — the
    uploader has `can_delete: true` for exactly the 2 TUS-uploaded assets
    attributed to them; all other 22 entries return `can_delete: false`.
  - Uploader "My uploads from this device" section shows asset 34 immediately
    with a local Delete button (Keychain token path intact).
- [x] Fix 2 — `/uploads/finalize` excluded from HTML branch. Validated on dev
  2026-08-30 for both roles: direct JSON decode succeeds (402-byte bare JSON);
  no "Finalize direct JSON decode failed" or extraction fallback triggered.
  Previous broken responses were ~1414 bytes (HTML-wrapped).
- [x] Fix 3 — Smoke test updated: stale comment removed, `delete_token` 64-char
  hex assertion added to the Basic Auth finalize block.
- [x] Fix 4 — iOS `<pre>` extraction fallback removed from `UploadView.swift`.
  Upload dialog now shows "Upload succeeded." instead of the false dedup warning.
- [x] End-to-end role matrix validated on dev 2026-08-30:

  | Role | Upload alert | DB `canDelete` | ✕ buttons in Media DB |
  |---|---|---|---|
  | Admin | "Upload succeeded." | 23/23 | All entries |
  | Uploader | "Upload succeeded." | 2/24 | None (deferred to JWT migration) |
- [ ] Problem 1 fix is a prerequisite for Phase 3 Step 7 (uploader `performDelete`
  via JWT role-claim) — now unblocked when that work is ready.
- [ ] Follow-on: extract shared token generation into a private helper to remove
  duplication between `processUpload` and `finalizeTusUpload` (PPRR P2).
