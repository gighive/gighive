---
description: "finalizeTusUpload() omits the 'id' key from its JSON response — FinalizeResponse decode fails, GuestUploadRecord is never saved, approval polling never runs, and delete icon never appears"
---

# Problem: GuestUploadRecord not saved after TUS finalize — approval alerts and delete icon broken

## Executive Summary

After a guest films and uploads a clip via QR code, two things should happen: the app notifies them when an admin approves their video, and they can delete their own clip from the gallery. Neither works — the approval notification never appears and the delete button is invisible. Both failures trace to a single missing field (`"id"`) in a server response, causing the app to silently discard the upload record it needs to drive both features. The fix is one line of PHP.

---

## Summary

After a guest completes a QR-code upload via TUS, the iOS app calls
`POST /api/uploads/finalize` and attempts to decode the response into
`FinalizeResponse`. The response from `UploadService::finalizeTusUpload()` omits
the `"id"` key — it returns only `"asset_id"`. `FinalizeResponse.id` is a
non-optional `Int`; the `JSONDecoder` call throws, `resp` is `nil`, and
`GuestUploadRecord` is **never saved to UserDefaults**.

Without a saved record:

- `SplashView.pollGuestRecords()` finds no pending records to poll — approval
  notifications never fire.
- `GuestGalleryView.ownUploadIds` is empty — the delete icon is never shown for
  the uploader's own clip.

Both symptoms (Issue 3 and Issue 4 in
`docs/problem_storage_media_endpoint_upload_field_miss.md`) share this single
upstream root cause. The server-side `upload_jobs` and
`anon_upload_attributions` rows **are** created correctly by the existing
token-mode path in `finalizeTusUpload()`. The failure is entirely on the client
decode path.

Discovery date: **2026-08-19** (post-investigation following Fix 1 and Fix 2
deployment to dev).

---

## Impact

| # | Symptom | User-visible effect |
|---|---|---|
| 3 | Approval alerts not sent to iPhone | Guest uploader never sees the "Your video has been accepted!" banner; must manually open app and navigate |
| 4 | Delete icon absent in guest gallery | Guest cannot remove their own clip; only the flag (report) icon is visible |

---

## Symptoms

- **Issue 3:** After an admin approves a guest upload via `event_qr.php`, the
  iPhone shows no approval banner on the Splash screen. `pollGuestRecords()` is
  never triggered for this upload because no `GuestUploadRecord` with
  `approvalStatus == "pending"` exists in UserDefaults for this nonce.
- **Issue 4:** The guest gallery shows the clip correctly (once approved) but
  the `×` (delete) icon is absent. Only the flag icon appears. The delete
  button is guarded by `ownUploadIds.contains(video.uploadJobId)` — the set is
  empty because no record was saved at upload time.
- **Log evidence (app-side):** When the bug is active, `GuestUploadView`
  emits one of the following log lines immediately after a successful finalize:
  ```
  [GuestUpload] ⚠️ statusNonce or uploadJobId missing — record NOT persisted
  ```
  or, if `FinalizeResponse` decode fails entirely before the guard is reached:
  ```
  [GuestUpload] ⚠️ FinalizeResponse decode failed entirely
  ```
- **No server-side error:** The finalize endpoint returns HTTP 201 with a valid
  JSON body. No PHP error log entries. The failure is silent from the server's
  perspective.

---

## Root Cause Analysis

### Step 1 — `FinalizeResponse.id` is non-optional

`FinalizeResponse.swift` declares:

```swift
struct FinalizeResponse: Codable {
    let id: Int          // non-optional
    let fileName: String?
    ...
    let statusNonce: String?
    let uploadJobId: Int?
    ...
}
```

`FinalizeResponse.swift` line 4: `let id: Int`.

When `JSONDecoder` cannot find the `"id"` key in the response JSON, decoding
throws and the `try?` expression evaluates to `nil`.

### Step 2 — `finalizeTusUpload()` never returns `"id"`

`UploadService::finalizeTusUpload()` builds the result map as:

```php
$result = [
    'asset_id'        => $assetId,   // ← key is "asset_id", not "id"
    'file_name'       => $fileName,
    'file_type'       => $fileType,
    'size_bytes'      => $fileSize,
    'mime_type'       => $mimeType,
    'checksum_sha256' => $checksum,
    'duration_seconds'=> null,
    'thumbnail_done'  => false,
    'db_done'         => true,
];
```

`UploadService.php` lines 324–334. The key `"id"` is absent. The old
`handleUpload()` path (line 225) does include `'id' => $assetId` alongside
`'asset_id'`. This was not ported when `finalizeTusUpload()` was written.

### Step 3 — `extractJSONCandidate` fallback also fails

`GuestUploadView.handleFinalizeResponse()` has a two-stage decode:

```swift
let resp: FinalizeResponse? = {
    // Stage 1: direct decode — fails because "id" is absent
    if let r = try? JSONDecoder().decode(FinalizeResponse.self, from: data) { return r }
    // Stage 2: HTML-unwrap fallback
    guard let bodyText = String(data: data, encoding: .utf8),
          let candidate = extractJSONCandidate(bodyText),
          ...
    return try? JSONDecoder().decode(FinalizeResponse.self, from: candData)
}()
```

`extractJSONCandidate()` anchors JSON extraction on either `"\"delete_token\""` or
`"\"id\""` (with surrounding quotes):

```swift
let anchorKeys = ["\"delete_token\"", "\"id\""]
```

`FinalizeResponseHandler.swift` line 38.

A `finalizeTusUpload` response body looks like:

```json
{"asset_id":17,"file_name":"abc…mov","file_type":"video","size_bytes":12345678,
 "mime_type":"video/quicktime","checksum_sha256":"abc…","duration_seconds":null,
 "thumbnail_done":false,"db_done":true,
 "status_nonce":"aBcDeFgH…","upload_job_id":5}
```

- `"\"id\""` (with quotes) does not match the substring `asset_id` or
  `upload_job_id` because neither is the bare token `"id"`.
- `"\"delete_token\""` is absent from `finalizeTusUpload` responses (it is only
  returned by the old `handleUpload` path for admin uploads).

`extractJSONCandidate` returns `nil`. Stage 2 decode also fails. `resp` is `nil`.

### Step 4 — Record is never saved; poll never runs

```swift
if let nonce = resp.statusNonce, let jobId = resp.uploadJobId {
    let record = GuestUploadRecord(statusNonce: nonce, uploadJobId: jobId, ...)
    GuestUploadRecord.upsert(record)
} else {
    logWithTimestamp("[GuestUpload] ⚠️ statusNonce or uploadJobId missing — record NOT persisted")
}
```

`GuestUploadView.swift` lines 537–554.

`resp` is `nil`, so the `if let` guard fails immediately. No record is written
to UserDefaults.

`SplashView.pollGuestRecords()` filters to records where `approvalStatus ==
"pending" || approvalStatus == "approved"`. With no record, there is nothing
to poll. The approval banner logic (`bannerRecord`) is never reached.
`SplashView.swift` lines 308–310, 387–391.

### Step 5 — Delete icon never rendered

`GuestGalleryView.loadGallery()`:

```swift
ownUploadIds = Set(eventRecords.map { $0.uploadJobId })
```

`GuestGalleryView.swift` line 347.

`eventRecords` is all stored `GuestUploadRecord` entries for this
`baseURLString + eventName`. With no record saved, `eventRecords` is empty,
`ownUploadIds` is empty, and:

```swift
if ownUploadIds.contains(video.uploadJobId) {   // always false
    Button { ... } label: { Image(systemName: "xmark") ... }
}
```

`GuestGalleryView.swift` line 229. The delete button is never rendered.

### Why the smoke tests did not catch this

The `post_build_checks` TUS smoke test (`post_build_checks/tasks/main.yml`
~line 469) calls `POST /api/uploads/finalize` using **Basic Auth**, not an
`X-Upload-Token`. The `finalizeTusUpload()` token-mode block (lines 337–362 of
`UploadService.php`) only runs when `$tokenResult !== null`. In Basic Auth
mode, `$tokenResult` is `null`, so `status_nonce` and `upload_job_id` are
never added to the response, and the test correctly asserts only `asset_id` and
`file_name`. The test never exercised the token-mode response shape and never
checked for `"id"`.

### Relationship to Fix 1 (event linkage)

Fix 1 (deploying event linkage in `TusBlockUploadService::finalizeUpload()`)
is **not related** to Issues 3 and 4 and does not resolve them.
`guest-status.php` joins `anon_upload_attributions → upload_jobs →
event_upload_tokens → events` — it does not join `event_items`.
`guest-gallery.php` queries `upload_jobs` directly. Neither endpoint depends on
`events` or `event_items`. The iOS record-save failure precedes any server-side
query entirely.

---

## Resolution

### Fix 3 / Fix 4 — Add `"id"` to `finalizeTusUpload()` response

**Scope:** 1 file, 1 line.

**File:** `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
*(gighiveinfra)*

Add `'id' => $assetId` as the first key in the `$result` array inside
`finalizeTusUpload()`, matching the established pattern from `handleUpload()`
line 225:

```php
// Before (lines 324–334):
$result = [
    'asset_id'        => $assetId,
    'file_name'       => $fileName,
    ...
];

// After:
$result = [
    'id'              => $assetId,   // ← add this line
    'asset_id'        => $assetId,
    'file_name'       => $fileName,
    ...
];
```

No other PHP changes required. No Swift changes required. No schema changes.
No DDL. No BABRR step. No new endpoints.

**Why this fixes both issues atomically:**

1. `JSONDecoder().decode(FinalizeResponse.self, from: data)` now succeeds on
   Stage 1 — `id` is present and an `Int`.
2. `resp` is non-nil; `resp.statusNonce` and `resp.uploadJobId` are populated
   from the token-mode response fields.
3. `GuestUploadRecord` is saved with `statusNonce` and `uploadJobId = upload_jobs.id`.
4. `pollGuestRecords()` finds the pending record and polls `guest-status.php`.
   When the admin approves, the banner fires. **Issue 3 resolved.**
5. `ownUploadIds` contains the correct `upload_jobs.id` for this upload.
   `ownUploadIds.contains(video.uploadJobId)` is `true` when the gallery lists
   this clip. The delete button renders. **Issue 4 resolved.**

**SonarQube / best-practice notes:**

- The fix reuses the existing `$assetId` value — no new variable, no new query.
- Including both `"id"` and `"asset_id"` preserves backward compatibility for
  any current or future caller that reads either key.
- No SQL touched; no prepared statements needed.
- `handleUpload()` already returns both keys; this brings `finalizeTusUpload()`
  into parity with the established response contract.

---

## Tests

### T-97 — `finalizeTusUpload()` response contains `id`, `status_nonce`, and `upload_job_id` in token-mode

**Purpose:** Proves that the finalize endpoint returns all three fields that
`FinalizeResponse` requires to save a `GuestUploadRecord`. A missing `"id"`
would cause the iOS decode to fail silently and is the confirmed root cause of
Issues 3 and 4.

**Design constraints and scrutiny resolved:**

Four problems with simpler approaches were identified and resolved:

**Constraint 1 — `tus_upload_id` after cleanup (fatal).**
The existing TUS smoke block cleanup (`main.yml` lines 608–616) runs
`DELETE FROM tus_uploads WHERE upload_id='{{ tus_upload_id }}'` before its
block closes at line 620. T-97 is placed after T-96, which is after that
cleanup. `finalizeTusUpload()` looks up the `tus_uploads` row first
(`UploadService.php` lines 291–303) and throws `'Upload not found'` when the
row is absent — 500. T-97 would fail on every run. **Resolution:** T-97 must
own its own independent TUS upload (POST + PATCH) rather than reusing
`tus_upload_id`.

**Constraint 2 — Duplicate `job_id` on re-run (fatal).**
Even if the `tus_uploads` row were still present, `upload_jobs.job_id` has
`UNIQUE KEY uq_upload_jobs_job_id` (`create_media_db.sql` line 245). A second
token-mode call with the same `upload_id` as `job_id` fails with a MySQL
duplicate-key error → 500. **Resolution:** T-97 generates its own upload via
a fresh TUS POST, which yields a new UUID each run.

**Constraint 3 — `tus_upload_id` Ansible scope (verified safe, moot).**
Ansible `set_fact` is play-scoped regardless of block nesting. `tus_upload_id`
set at line 364 inside the existing block is accessible at T-97's position.
Not a problem, but irrelevant given Constraints 1 and 2 force T-97 to use its
own upload ID anyway.

**Constraint 4 — Raw token unrecoverable from DB (fatal for the "fetch a token" approach).**
`event_upload_tokens.token_hash` stores `hash('sha256', $rawToken)` — the raw
token is never stored (schema comment, line 365; `event_qr.php` line 116).
`UploadTokenValidator::validate()` receives the raw token and computes the hash
server-side (`UploadTokenValidator.php` line 18). Fetching `token_hash` from
the DB and sending it as `X-Upload-Token` would compute `hash('sha256',
token_hash)` — a double-hash, which will never match any stored row. The TUS
POST, PATCH, and finalize all require the **original raw token**. There is no
way to recover a raw token from the DB. **Resolution:** T-97 must generate its
own raw token, compute its SHA-256, and INSERT that hash into
`event_upload_tokens` itself — creating a fully self-managed test fixture.

**Test design — fully self-contained fixture:**

T-97 owns its entire FK chain from scratch:

1. Generate a raw token using MySQL `RANDOM_BYTES(24)` via `TO_BASE64` inside
   the MySQL container and capture it. Compute `SHA2(rawToken, 256)` in the
   same SQL statement and INSERT a row into `events` (sentinel org_name) and
   `event_upload_tokens`. The token is standard base64 (with `+`, `/`, `=`) —
   different from the base64url format used by `event_qr.php`, but acceptable
   since `UploadTokenValidator` only checks the SHA-256 hash, not the format.
2. POST to `/files/` with `X-Upload-Token: <rawToken>` to create a TUS upload.
   Capture `_t97_upload_id` from the `Location` header.
3. PATCH to `/files/<_t97_upload_id>` with `X-Upload-Token: <rawToken>` to
   deliver bytes and trigger `TusBlockUploadService::finalizeUpload()`.
4. POST to `/api/uploads/finalize` with `X-Upload-Token: <rawToken>` to invoke
   `UploadService::finalizeTusUpload()` in token-mode. Assert `id`,
   `status_nonce`, and `upload_job_id` are present and positive.
5. Cleanup in safe FK order: `probe_jobs`, `tus_uploads`, `upload_jobs`
   (cascades `anon_upload_attributions` via `fk_aua_job`), then `events`
   (cascades `event_upload_tokens` via `fk_eut_event`, which cascades
   `anon_upload_attributions` via `fk_aua_token` for any residual rows),
   then `assets` last.

**FK cascade chain (from `create_media_db.sql`):**

```
events  ──fk_eut_event ON DELETE CASCADE──►  event_upload_tokens
                                                     │
                                      fk_aua_token ON DELETE CASCADE
                                                     ▼
upload_jobs  ──fk_aua_job ON DELETE CASCADE──►  anon_upload_attributions
```

Deleting the `events` row is sufficient to clean up `event_upload_tokens` and
`anon_upload_attributions` via cascades. `upload_jobs`, `probe_jobs`,
`tus_uploads`, and `assets` have no FK cascade from `events` — deleted
explicitly.

**Generating the raw token in MySQL:**
Token generation uses `TO_BASE64(RANDOM_BYTES(24))` inside a SQL `SET`
statement, then captures the result. MySQL's `TO_BASE64` produces standard
base64 (with `+`, `/`, and `=` padding), which differs from the base64url
format used by `event_qr.php` (line 115: `rtrim(strtr(base64_encode(...),
'+/', '-_'), '=')`). The format difference is irrelevant: `UploadTokenValidator`
only checks `hash('sha256', $rawToken) == token_hash` — it imposes no format
constraint on the raw token. 24 random bytes produce 32 base64 characters —
well under MySQL's 76-character wrap threshold, so no newlines are embedded.
The token is passed via `-e T97_RAW_TOKEN=...` as a `docker exec` environment
variable to avoid shell-quoting problems with `+` and `=`.

**Placement:** After T-96 (probe cron vars), tagged `[smoke, tus]`, permanent.
Reuses `tus_payload_b64`, `tus_expected_offset`, and `tus_headers_common`
(play-scoped facts set at lines 274–282 of `main.yml`).

```yaml
# --- T-97: finalizeTusUpload() in token-mode returns id, status_nonce, upload_job_id ---
# Lifecycle: permanent — keep in post_build_checks.
# Root cause guard: the iOS FinalizeResponse struct requires "id" (non-optional Int).
# finalizeTusUpload() historically returned only "asset_id". If "id" is absent,
# JSONDecoder throws, GuestUploadRecord is never saved, and Issues 3 and 4 recur.
#
# Design: fully self-contained fixture — generates its own raw token, events row,
# and event_upload_tokens row. Reasons:
#   1. tus_upload_id from the earlier Basic Auth smoke block is deleted by that
#      block's cleanup before T-97 runs → finalizeTusUpload() returns 500.
#   2. upload_jobs.job_id has UNIQUE KEY uq_upload_jobs_job_id — reusing the same
#      UUID across runs causes a duplicate-key 500 on finalize.
#   3. event_upload_tokens.token_hash stores SHA-256(rawToken); the raw token is
#      never stored. Fetching the hash and sending it as X-Upload-Token double-hashes
#      it and never matches — the TUS POST/PATCH/finalize all return 401.
#      T-97 must generate the raw token itself.
# Reuses tus_payload_b64, tus_expected_offset, tus_headers_common (play-scoped facts).

- name: "[T-97] Create T-97 fixture: events row, raw token, and event_upload_tokens row"
  community.docker.docker_container_exec:
    container: "{{ mysql_container_name | default('mysqlServer') }}"
    command: >-
      sh -lc 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -sN -e
      "SET @t97_raw = TO_BASE64(RANDOM_BYTES(24));
       INSERT INTO events (tenant_id, event_key, event_date, org_name, event_type)
         VALUES (1, UUID(), CURDATE(), '\''T97_SMOKE_TEST'\'', '\''band'\'');
       SET @t97_event_id = LAST_INSERT_ID();
       INSERT INTO event_upload_tokens (event_id, token_hash, expires_at)
         VALUES (@t97_event_id, SHA2(@t97_raw, 256),
                 DATE_ADD(NOW(), INTERVAL 1 HOUR));
       SELECT @t97_raw, @t97_event_id;"'
  register: _t97_fixture
  changed_when: false
  no_log: "{{ tus_checks_no_log }}"
  tags: [smoke, tus]

- name: "[T-97] Parse raw token and event_id from fixture output"
  ansible.builtin.set_fact:
    _t97_raw_token: "{{ (_t97_fixture.stdout | trim).split('\t')[0] }}"
    _t97_event_id:  "{{ (_t97_fixture.stdout | trim).split('\t')[1] }}"
  changed_when: false
  tags: [smoke, tus]

- name: "[T-97] Assert fixture rows were created"
  ansible.builtin.assert:
    that:
      - _t97_raw_token | length > 0
      - (_t97_event_id | string) is regex('^[0-9]+$')
      - (_t97_event_id | int) > 0
    fail_msg: >-
      T-97 fixture creation failed.
      raw_token='{{ _t97_raw_token }}' event_id='{{ _t97_event_id }}'
  changed_when: false
  tags: [smoke, tus]

- name: "[T-97] Run token-mode TUS upload + finalize and assert response shape"
  block:

    - name: "[T-97] Create TUS upload with X-Upload-Token (POST /files/)"
      ansible.builtin.uri:
        url: "{{ gighive_base_url }}/files/"
        method: POST
        validate_certs: "{{ gighive_validate_certs }}"
        headers: "{{ tus_headers_common | combine({
          'Tus-Resumable': '1.0.0',
          'Upload-Length': tus_expected_offset | string,
          'X-Upload-Token': _t97_raw_token,
          'Upload-Metadata': (
            'filename ' ~ ('t97.wav' | b64encode) ~
            ',filetype ' ~ ('audio/wav' | b64encode) ~
            ',label ' ~ ('T-97 token test' | b64encode) ~
            ',org_name ' ~ ('gighive' | b64encode) ~
            ',event_date ' ~ (ansible_facts['date_time'].date | b64encode) ~
            ',event_type ' ~ ('band' | b64encode)
          )
        }) }}"
        status_code: [201]
        return_content: true
      register: _t97_create
      changed_when: false
      no_log: "{{ tus_checks_no_log }}"

    - name: "[T-97] Capture T-97 upload_id from Location header"
      ansible.builtin.set_fact:
        _t97_upload_id:  "{{ (_t97_create.location | default('')).split('/')[-1] }}"
        _t97_upload_url: "{{ gighive_base_url }}/files/{{ (_t97_create.location | default('')).split('/')[-1] }}"
      changed_when: false

    - name: "[T-97] Assert T-97 upload_id was captured"
      ansible.builtin.assert:
        that:
          - _t97_upload_id | length > 0
        fail_msg: "T-97 TUS POST did not return a Location header with an upload_id"
      changed_when: false

    - name: "[T-97] Write PATCH payload into apache container"
      ansible.builtin.command:
        argv:
          - docker
          - exec
          - -e
          - TUS_PAYLOAD_B64={{ tus_payload_b64 }}
          - -e
          - T97_RAW_TOKEN={{ _t97_raw_token }}
          - "{{ apache_container_name }}"
          - sh
          - -c
          - |
            printf '%s' "$TUS_PAYLOAD_B64" > /tmp/t97_payload.b64
      changed_when: false
      no_log: "{{ tus_checks_no_log }}"

    - name: "[T-97] Upload payload via TUS PATCH with X-Upload-Token"
      ansible.builtin.command:
        argv:
          - docker
          - exec
          - -e
          - T97_RAW_TOKEN={{ _t97_raw_token }}
          - "{{ apache_container_name }}"
          - sh
          - -c
          - >-
            base64 -d /tmp/t97_payload.b64 |
            curl -sS --http1.1
            {{ "-k" if not (gighive_validate_certs | default(true)) else "" }}
            -X PATCH
            -H "Tus-Resumable: 1.0.0"
            -H "Upload-Offset: 0"
            -H "Content-Type: application/offset+octet-stream"
            -H "Content-Length: {{ tus_expected_offset }}"
            -H "X-Upload-Token: $T97_RAW_TOKEN"
            {{ ('-H "Host: ' ~ gighive_hostname_for_host_header ~ '"') if (gighive_hostname_for_host_header | default('') | length) > 0 else "" }}
            --data-binary @-
            -o /dev/null
            -w "%{http_code}"
            "{{ _t97_upload_url }}"
      register: _t97_patch
      changed_when: false
      no_log: "{{ tus_checks_no_log }}"

    - name: "[T-97] Remove T-97 temp files from apache container"
      ansible.builtin.command: >
        docker exec {{ apache_container_name }}
        sh -c 'rm -f /tmp/t97_payload.b64'
      changed_when: false
      failed_when: false

    - name: "[T-97] Assert PATCH returned 204"
      ansible.builtin.assert:
        that:
          - _t97_patch.stdout | trim == '204'
        fail_msg: "T-97 TUS PATCH expected 204, got '{{ _t97_patch.stdout | trim }}'"
      changed_when: false

    - name: "[T-97] POST /api/uploads/finalize with X-Upload-Token (token-mode)"
      ansible.builtin.uri:
        url: "{{ gighive_base_url }}/api/uploads/finalize"
        method: POST
        validate_certs: "{{ gighive_validate_certs }}"
        headers: "{{ tus_headers_common | combine({
          'Content-Type': 'application/json',
          'X-Upload-Token': _t97_raw_token
        }) }}"
        body_format: json
        body:
          upload_id: "{{ _t97_upload_id }}"
          label: "T-97 token-mode test"
          tos_accepted: true
          display_name: "Ansible T-97"
        status_code: [201]
        return_content: true
      register: _t97_finalize
      changed_when: false
      no_log: "{{ tus_checks_no_log }}"

    - name: "[T-97] Assert finalize response contains id, status_nonce, upload_job_id"
      ansible.builtin.assert:
        that:
          - _t97_finalize.json is mapping
          - (_t97_finalize.json.id | default('') | string) is regex('^[0-9]+$')
          - (_t97_finalize.json.id | int) > 0
          - (_t97_finalize.json.status_nonce | default('')) | length > 0
          - (_t97_finalize.json.upload_job_id | default('') | string) is regex('^[0-9]+$')
          - (_t97_finalize.json.upload_job_id | int) > 0
        fail_msg: >-
          finalizeTusUpload() token-mode response is missing one or more required fields.
          id='{{ _t97_finalize.json.id | default("ABSENT") }}'
          status_nonce='{{ _t97_finalize.json.status_nonce | default("ABSENT") }}'
          upload_job_id='{{ _t97_finalize.json.upload_job_id | default("ABSENT") }}'
          Full response: {{ _t97_finalize.json | to_json }}
      changed_when: false

    # Cleanup: rows with no FK cascade from events/assets must be deleted explicitly.
    # Split into two tasks so that tus_uploads and upload_jobs (keyed on _t97_upload_id,
    # always known) are cleaned up even if finalize never ran and _t97_finalize is
    # undefined. probe_jobs and assets are keyed on _t97_finalize.json.id and are
    # guarded by a `when` — if finalize did not run, those rows simply don't exist.
    # Ordering: probe_jobs first (no FKs), then tus_uploads, then upload_jobs
    # (cascades anon_upload_attributions via fk_aua_job), then events (cascades
    # event_upload_tokens via fk_eut_event and anon_upload_attributions via fk_aua_token),
    # then assets last.
    - name: "[T-97] Cleanup — delete probe_jobs and assets (finalize-dependent rows)"
      when: _t97_finalize is defined and (_t97_finalize.json | default({})) is mapping and (_t97_finalize.json.id | default(0) | int) > 0
      community.docker.docker_container_exec:
        container: "{{ mysql_container_name | default('mysqlServer') }}"
        command: >-
          sh -lc 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -sN -e
          "DELETE FROM probe_jobs WHERE asset_id = {{ _t97_finalize.json.id | int }};
           DELETE FROM assets      WHERE asset_id = {{ _t97_finalize.json.id | int }};"'
      changed_when: false
      no_log: "{{ tus_checks_no_log }}"

    - name: "[T-97] Cleanup — delete tus_uploads and upload_jobs (upload-id-keyed rows)"
      community.docker.docker_container_exec:
        container: "{{ mysql_container_name | default('mysqlServer') }}"
        command: >-
          sh -lc 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -sN -e
          "DELETE FROM tus_uploads WHERE upload_id = '\''{{ _t97_upload_id }}'\'';
           DELETE FROM upload_jobs WHERE job_id    = '\''{{ _t97_upload_id }}'\'';"'
      changed_when: false
      failed_when: false
      no_log: "{{ tus_checks_no_log }}"

  always:
    # Belt-and-suspenders: if the block fails mid-way, ensure the fixture is still
    # cleaned up so stale events/tokens don't accumulate across failed runs.
    # Uses the sentinel org_name to find and delete the events row if it still exists.
    # Cascades handle event_upload_tokens and anon_upload_attributions automatically.
    - name: "[T-97] Safety cleanup — remove fixture events row if still present"
      community.docker.docker_container_exec:
        container: "{{ mysql_container_name | default('mysqlServer') }}"
        command: >-
          sh -lc 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot media_db -sN -e
          "DELETE FROM events WHERE event_id = {{ _t97_event_id | int }};"'
      changed_when: false
      failed_when: false
      no_log: "{{ tus_checks_no_log }}"

  tags: [smoke, tus]
```

**Why cleanup is split into two tasks and ordered this way:**

The cleanup is split so that a mid-block failure before `POST /api/uploads/finalize`
runs does not leave stale rows: `tus_uploads` and `upload_jobs` are keyed on
`_t97_upload_id` (set immediately after TUS POST) and cleaned unconditionally.
`probe_jobs` and `assets` are keyed on `_t97_finalize.json.id` (only available
after finalize returns 201) and are guarded by `when: _t97_finalize is defined`.

| Step | Task | Tables | Guard | Cascade triggered |
|---|---|---|---|---|
| 1 | finalize-dependent | `probe_jobs`, `assets` | `when: _t97_finalize is defined` | None |
| 2 | upload-id-keyed | `tus_uploads`, `upload_jobs` | Unconditional | `anon_upload_attributions` via `fk_aua_job` |
| 3 | `always:` safety | `events` | `failed_when: false` | `event_upload_tokens` → `anon_upload_attributions` via `fk_aua_token` |

`assets` is deleted in Step 1 (before `events`) because `assets` has no FK parent in this chain. `events` is deleted last (in `always:`) to ensure the cascade reaches `event_upload_tokens` regardless of whether earlier tasks failed.

The `always:` block ensures the `events` row (and its cascades) is deleted even
if the main block fails mid-way — preventing stale `T97_SMOKE_TEST` rows from
accumulating across failed CI runs.

**Sentinel org_name:** `T97_SMOKE_TEST` is used as the `events.org_name`. The
`uq_events_tenant_date_org` unique constraint (`create_media_db.sql` line 70)
means only one row with this name can exist per `(tenant_id, event_date)`. If a
previous run failed before cleanup, the fixture INSERT would fail. The
`always:` block handles this: the safety cleanup DELETE fires regardless, so a
re-run after a prior failure will succeed.

**`TO_BASE64(RANDOM_BYTES(24))` token format:** MySQL's `TO_BASE64` produces
standard base64 (with `+`, `/`, and padding `=`). `UploadTokenValidator` only
checks `hash('sha256', $rawToken) == token_hash` — it does not enforce the
base64url format used by `event_qr.php`. Any consistent string is valid as long
as the same raw bytes are hashed and sent. The token is passed via environment
variable into `docker exec` to avoid shell-quoting issues with `+` and `=`
characters.

**Idempotency across runs:** Each run generates a new `RANDOM_BYTES(24)` token
and a new `UUID()` event_key — no duplicate-key risk across play runs as long
as cleanup completes (which `always:` guarantees).

---

## Files Under Change

### Modified

1. `ansible/roles/docker/files/apache/webroot/src/Services/UploadService.php`
   *(gighiveinfra)* — add `'id' => $assetId` as first key in the `$result`
   array inside `finalizeTusUpload()` (one line).

2. `ansible/roles/post_build_checks/tasks/main.yml` *(gighiveinfra)* — add
   T-97 token-mode finalize response shape test after T-96.

### Unchanged

- `GigHive/Sources/App/FinalizeResponse.swift` — no change; the existing
  `let id: Int` field is correct; the server was not matching it.
- `GigHive/Sources/App/GuestUploadView.swift` — no change; the decode and
  record-save logic is correct; it will work once `"id"` is present.
- `GigHive/Sources/App/GuestGalleryView.swift` — no change.
- `GigHive/Sources/App/SplashView.swift` — no change.
- `api/guest-status.php` — no change; server-side data was always correct.
- `api/guest-gallery.php` — no change.
- `src/Services/TusBlockUploadService.php` — no change.
- `mysql/externalConfigs/create_media_db.sql` — no change; no schema change.

---

## Verification

### Manual (dev, post-deploy)

1. Run Ansible deploy to push the updated `UploadService.php`.
2. QR-upload a new video from an iPhone against the dev server.
3. Immediately after upload completes, check the app log for:
   ```
   [GuestUpload] GuestUploadRecord upserted: nonce=… jobId=… baseURL=…
   ```
   Absence of this line means the fix is not deployed.
4. Background the app; have the admin approve the clip in `event_qr.php`.
5. Bring the app back to foreground. Within 30 seconds (one poll cycle), the
   "Your video has been accepted!" banner should appear on the Splash screen.
   **Issue 3 verified.**
6. Open the Event Gallery. The clip should show the `×` (delete) icon alongside
   the flag icon. **Issue 4 verified.**
7. Tap the delete icon; confirm the clip is removed from the gallery.

### Automated (T-97)

T-97 passes in `post_build_checks` after deploy. It asserts:
- `id` is a positive integer.
- `status_nonce` is a non-empty string.
- `upload_job_id` is a positive integer.

A T-97 failure after any future `UploadService.php` change is an early warning
that the finalize response shape has regressed.

---

## Deployment Notes

- No DDL. No BABRR step. No container restart required.
- No iOS app release required — the fix is server-side only.
- Fix is safe to deploy independently of any other pending change. It is
  additive: adding `"id"` to an existing response cannot break any existing
  caller (the Ansible smoke tests only assert `asset_id`, not the absence of
  `"id"`).
- The fix resolves Issues 3 and 4 in a single deploy.

---

## Preventative Actions

1. **Response contract parity test:** Any new endpoint or new code path that
   returns a `FinalizeResponse`-shaped JSON must assert all non-optional fields
   (`id`, `asset_id`, `file_name`) in its smoke test. A Basic Auth smoke test
   alone is insufficient when a token-mode path adds or omits keys.
2. **Token-mode smoke test coverage:** `POST /api/uploads/finalize` should be
   exercised in token-mode (with `X-Upload-Token`) in `post_build_checks` —
   not just in Basic Auth mode. T-97 closes this gap.
3. **`handleUpload` / `finalizeTusUpload` parity checklist:** When the two
   paths diverge in response shape, document the delta explicitly. The current
   response contract differences are:
   - `handleUpload` returns `event_date`, `org_name`, `event_type`, `label`,
     `participants`, `keywords`, `duration_seconds`, `delete_token` (optional);
     `finalizeTusUpload` does not (probe is async; event data not in scope).
   - After this fix, both paths return `id` and `asset_id` with the same value.
4. **`extractJSONCandidate` anchor key list:** If `FinalizeResponse` adds new
   non-optional fields in future, verify the anchor key list in
   `FinalizeResponseHandler.swift` still matches at least one field that will
   always be present in the JSON. Currently `"\"id\""` will now be reliably
   present after this fix.
5. **Guest soft-delete physical file removal (future maintenance role):**
   `guest-delete.php` sets `upload_jobs.guest_deleted = 1` but does not remove
   the media file from disk or purge the database rows. Guest-deleted clips
   remain on disk indefinitely, hidden only by the soft-delete flag. This is
   acceptable short-term but should be addressed when the dedicated `maintenance`
   Ansible role is created. The maintenance role should hard-delete assets with
   `guest_deleted = 1` via `MediaStorageService` and cascade the row removal.
   Tracked in `docs/refactor_storage_media_rest_endpoint_followons.md` →
   **Maintenance Role → Guest soft-delete physical file removal**.
