# Refactor: QR Code Gallery Access for All Attendees

## Status — 2026-07-15

**Complete — confirmed working on device.** End-to-end test passed: QR scan without prior upload shows "Visit Event Gallery" link; gallery visible immediately; upload + approval flow still works; "Your Event Galleries" on SplashView unaffected.

---

## Rationale

The QR code is already the gate. It is printed on a flyer or shown on a screen at
a live event. Getting it requires physical presence — or at minimum, being trusted
enough that someone who was there shared it with you. That is meaningful real-world
friction, far more than a paywall or login screen. This does not open the gallery
to the internet; it opens it to the room.

Moderation is unchanged. The gallery only surfaces organizer-approved videos. The
organizer still curates what is visible. Gallery access does not equal gallery chaos.

Social reciprocity is a real factor. If someone scans the code and immediately sees
a gallery of clips from the show, they are far more likely to upload their own.
Gating the view behind "upload first and get approved" is the worst possible incentive
structure — it asks for effort before delivering any value.

This is a policy reversal from the original spec (gallery access gated on approved
uploads). It was the right call for the original build but the product has matured.
The new policy is deliberate: **QR token in hand → gallery viewer access granted.**

Beyond the UX win, this refactor simplifies the system in two concrete ways:

**Admin simplification.** Today an organizer configuring an event must set two
independent expiry fields: the QR token TTL *and* the gallery lifespan. These can
be set inconsistently — a token that expires before the gallery closes, or a gallery
that closes before the token does — and the admin UI had to warn about this mismatch.
After the follow-on cleanup, there is one field: the token TTL. It governs upload
access and gallery access. One number to set, nothing to keep in sync, no mismatch
to warn about.

**Codebase simplification.** Five PHP files currently carry a dual-expiry auth path:
token TTL enforced by `upload-token.php`; `gallery_expires_at` enforced separately in
`guest-gallery.php`, `guest-stream.php`, `guest-status.php`, and `guest_event_view.php`
via a `JOIN events` that exists solely to fetch that one column. After the follow-on
removes `gallery_expires_at`, the join disappears, the dual path collapses to one, and
the gallery auth queries become materially simpler. Less code, less surface area, less
to reason about when debugging a 403.

---

## Goal

Allow any attendee who scans the event QR code to view the event gallery — not just
those whose upload has been approved. The QR code itself is the gate: if they had it,
they attended the show, and they've earned the right to browse.

This is a policy reversal from the original spec (gallery access gated on approved
uploads). The new policy is: **QR token in hand → gallery viewer access granted.**

---

## Industry Precedent

Every comparable platform uses a single-credential / single-lifespan model:
Google Photos shared albums, iCloud Photo Sharing, WeTransfer, Dropbox shared
links, Fotaflo (event photo sharing), Pixieset client galleries. None separate
"upload window TTL" from "view window TTL" as two independently configured fields
on the same object. The separation only appears in enterprise CMSes where uploader
and viewer are different roles — not the same person with the same QR code.

---

## Decision

`token.expires_at` is the sole expiry clock. However long the token is valid,
the gallery is accessible — via upload nonce or raw QR token. `is_active` handles
explicit revocation. `gallery_expires_at` on the `events` table is vestigial and
will be removed in a follow-on task (see *Token TTL vs Gallery Expiry* below).

---

## Real World Use Cases

Three fans at a StormPigs show. Same QR code on the stage backdrop.

---

### Scenario 1 — The Casual Attendee (no plan to upload)

**Alex** scans the QR code between sets, just curious what the app does.

| | Before | After |
|---|---|---|
| What they see | Upload form with required fields and TOS | Upload form **and** a "Visit Event Gallery" link at the top |
| What they do | Confused by the form, backs out | Taps the gallery link, watches 3 clips from the opening act |
| Outcome | Zero engagement, app deleted | Shares a clip with a friend via iOS share sheet — organic reach for the band |

---

### Scenario 2 — The Hesitant Uploader (wants to browse before committing)

**Jordan** filmed a great crowd-surf moment but is unsure about privacy and what
the organizer will do with submissions.

| | Before | After |
|---|---|---|
| What they see | Upload form — must commit before seeing anything | Gallery link right above the upload form |
| What they do | Abandons the app; too much friction before any reward | Browses the gallery first, sees that approved clips are tasteful and credited by display name |
| Outcome | Video never submitted | Feels comfortable, goes back to the form, uploads their clip |

**Key dynamic:** showing the gallery first removes uncertainty and makes the upload
ask feel safe. The gallery is the social proof; the upload form is the call to action.

---

### Scenario 3 — The Returning Fan (missed upload window on the night)

**Sam** was at the show but the phone died before they could upload. They come back
three days later with the original QR code still in their camera roll.

| | Before | After |
|---|---|---|
| What they see | Upload form (token still valid) — but no gallery access until upload is approved | Upload form + gallery link |
| What they do | Submits the upload — waits 48 hrs for approval — only then sees the gallery | Taps gallery immediately, sees what others shared, feels part of the event community, then uploads |
| Outcome | Delayed, transactional experience | Immediate sense of belonging; upload follows naturally |

---

### Summary

The old model treats the gallery as a **reward for uploading**. The new model treats
the gallery as a **reason to engage** — which also increases uploads. Giving access
to the person who already showed up (literally or digitally) is both the right UX
and the right growth mechanic.

---

## Design Principles

- The QR code is the physical gate. Possession of it implies attendance.
- The gallery only surfaces organizer-approved videos; moderation is unchanged.
- Viewer access is strictly read-only (no delete). Report is best-effort.
- No new tables, migrations, or persistent state for viewer sessions.
- **Token TTL = gallery lifetime.** However long the token is valid, that is how
  long the gallery is accessible. When the token expires or is revoked, the gallery
  closes. This is the only expiry clock.
- **Files are never deleted.** Expiry of the token closes *access* — it does not
  purge video files or DB records from the GigHive instance. Files remain on disk
  indefinitely. A new token for the same event could re-open access if desired.
- Fail gracefully: if the raw token is expired (`expires_at` passed) or revoked
  (`is_active = 0`), the viewer gets "Gallery access could not be verified" —
  same UX as any other 403.

---

## Current Auth Model (why it blocks non-uploaders)

Both `guest-gallery.php` and `guest-stream.php` authenticate requests via a
`status_nonce` that must:
1. Exist in `anon_upload_attributions`
2. Have an associated `upload_jobs` row with `moderation_status = 'approved'`

A first-time QR scanner has no `status_nonce` at all (they haven't uploaded yet,
or haven't been approved yet). The server returns HTTP 403.

The raw QR upload token (validated by `upload-token.php` against `event_upload_tokens`)
is an entirely different credential — the gallery endpoints don't know about it.

---

## Proposed Implementation

### Changes at a Glance

| File | Repo | What changes |
|------|------|--------------|
| `api/guest-gallery.php` | gighiveinfra | Widen nonce regex; add fallback token-auth path |
| `api/guest-stream.php` | gighiveinfra | Widen nonce regex; add fallback token-auth path |
| `GuestUploadView.swift` | gighiveapp | Add "Visit Event Gallery" NavigationLink |

`GuestGalleryView.swift`, `GuestGalleryAPIClient.swift`, `GuestUploadRecord.swift` — **no changes needed.**

No DB migrations for this feature. `ALTER TABLE events DROP COLUMN gallery_expires_at` (and removal of the Gallery Lifespan admin UI field and `is_multi_day` checkbox) is a **separate follow-on task**.

---

### Backend: `gighiveinfra` (2 files)

Add a secondary auth path to both gallery endpoints. If the incoming `nonce`
parameter fails the existing `anon_upload_attributions` lookup, attempt to match
it as a raw upload token in `event_upload_tokens`. If found (active, not expired),
grant **read-only** gallery access.

#### Validated schema facts

- Raw token is generated as: `rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')` → **43 characters**, charset `[A-Za-z0-9_\-]`
- Raw token is **never stored** — only its SHA-256 hex digest in `token_hash char(64)`
- `status_nonce` (existing credential) is `varchar(40)` — max 40 chars
- `event_upload_tokens` has: `token_hash`, `event_id`, `expires_at datetime NOT NULL`, `is_active tinyint(1) DEFAULT 1`

#### Correction 1 — Widen nonce regex (REQUIRED)

Both files currently validate `'/^[A-Za-z0-9_\-]{30,40}$/'`. A 43-char raw token
is rejected before any DB lookup runs. Change to `{30,43}` in both files.

#### Correction 2 — Correct fallback query (REQUIRED)

There is no `token_value` column. Hash the raw token in PHP (consistent with
`UploadTokenValidator.php`; avoids sending the raw credential to MySQL — defense in depth):

```php
$tokenHash = hash('sha256', $nonce);
$stmt = $pdo->prepare(
    'SELECT t.event_id, t.expires_at
     FROM event_upload_tokens t
     WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()'
);
$stmt->execute([$tokenHash]);
$tokenRow = $stmt->fetch(\PDO::FETCH_ASSOC);
```

Use `$tokenRow` (not `$row`) to distinguish from the primary nonce result.
`daysRemaining` is computed from `$tokenRow['expires_at']` using the same
`diff()->days` pattern as the existing `gallery_expires_at` calculation:

```php
$tokenExpiry   = new \DateTime($tokenRow['expires_at']); // NOT NULL per schema; 
                                                          // \Exception (not \PDOException) if corrupt
$now           = new \DateTime('now');
$diff          = $now->diff($tokenExpiry);                // cache — do not call diff() twice
$daysRemaining = $diff->days > 3650                      // > ~10 years = No Expiration sentinel
    ? null                                               // sentinel stored as +100 years (~36 500 days)
    : max(0, (int)$diff->days);                          // max() is defensive; SQL already guarantees future
```

The `> 3650` guard returns `null` for No Expiration tokens (stored as `+100 years`).
The threshold is deliberately set at 3650 days (10 years) — well above the highest
finite TTL option (72 days / 1728h) and well below the sentinel (≈36 500 days) — so
there is no overlap risk with current admin-UI options. If a token > 3650 days is ever
created by other means, it also returns `null` for `daysRemaining`, which is acceptable.
Matches the `gallery_expires_at IS NULL` → `null` behaviour in the existing nonce path.
Without this, the UI would display "Available for 36,498 more days".
No join to `events` required.

**`api/guest-gallery.php`** — changes:
1. Widen nonce regex to `{30,43}`
2. After existing nonce lookup returns no row, run the fallback (wrap in try/catch PDOException → 500, matching existing error-handling pattern)
3. If `$tokenRow` found: set `$eventId = (int)$tokenRow['event_id']`; **skip the `$isExpired` block entirely** — token is guaranteed live because the fallback SQL already has `AND t.expires_at > NOW()`; proceed directly to `$daysRemaining` calculation then Step 2
4. Compute `daysRemaining` from `$tokenRow['expires_at']`; apply `> 3650` guard to return `null` for No Expiration tokens
5. Stream URLs constructed as `?nonce=<rawToken>` — unchanged; stream.php does the same dual lookup
6. If both lookups fail → 403 as before

**`api/guest-stream.php`** — changes:
1. Widen nonce regex to `{30,43}`
2. Same fallback query after existing nonce lookup fails (same try/catch pattern); SELECT `t.event_id` only — `expires_at` is not needed in PHP because expiry is enforced in SQL (`AND t.expires_at > NOW()`)
3. Uses `$tokenRow['event_id']`; proceeds to file resolution

No new tables, endpoints, or DB migrations needed.

#### SonarQube / best-practice notes (PHP)

- **Raw token never reaches MySQL** — PHP-side `hash('sha256', $nonce)` matches `UploadTokenValidator.php` pattern; avoids RSPEC-2635 (sensitive data in query).
- **Error handling** — fallback query must be in its own try/catch PDOException block; an uncaught DB error must return 500, not leak a stack trace.
- **Variable naming** — use `$tokenRow` distinct from `$row` (nonce result) to prevent shadowing bugs.
- **Duplication** — the fallback block is identical in both files, following the existing pattern where both files also duplicate the primary nonce query. Acceptable for minimal change; a shared `GuestAuthHelper` class is a future refactor opportunity, not required here.

#### Apache — `default-ssl.conf.j2`

**No changes needed for this feature.**

`default-ssl.conf.j2` line 66 has a parallel `{30,40}` regex:

```apache
SetEnvIf Request_URI "[?&]nonce=[A-Za-z0-9_\-]{30,40}" gallery_nonce_auth
```

This sets the `gallery_nonce_auth` env var, which is used to bypass Basic Auth on the
`/video/` location block. A 43-char raw token does **not** match this regex.

However, this is not in the iOS viewer's critical path:
- Video is served through `/api/guest-stream.php` — the PHP script reads the file from
  disk internally via `readfile()`/`fread()` and streams it to the client.
- `/api/guest-stream.php` has `AuthMerging Off` + `Require all granted` (lines 286–289)
  — completely independent of `gallery_nonce_auth`.
- The `/video/` location block is never hit during iOS viewer sessions.

**Follow-on consideration**: if `guest_event_view.php` (the web browser gallery) is ever
extended to accept 43-char raw tokens as viewer credentials, it would generate
`<video src="/video/...?nonce=<43-char-token>">` URLs. Line 66 would fail to match →
`gallery_nonce_auth` not set → the `/video/` block falls back to Basic Auth → 401.
At that point, line 66 must be widened to `{30,43}` (matching the PHP regex change).

---

### iOS: `GigHive` (1 file changed)

**`GuestUploadView.swift`** — add a "Visit Event Gallery" link.

- Show the link whenever `guestSession.eventDetails` is non-nil (i.e., the QR
  token has been validated and the upload form is visible).
- Style: identical to `GuestGalleryView`'s in-body header block — bee logo image
  + "Visit Event Gallery" in `.title3.bold()` + event name subtitle in `.caption` —
  wrapped in a `NavigationLink` with a trailing chevron.
- Placement: inside the `else if let details = guestSession.eventDetails` branch, **before**
  the existing `GHCard(pad: 10)` upload form. That branch currently contains one card;
  wrap both in a `VStack(alignment: .leading, spacing: 16)` and insert the gallery link card first.
- On tap: navigate to `GuestGalleryView` with a **synthetic** `GuestUploadRecord`.
  `details` is already bound by the surrounding `else if`, so the NavigationLink guard
  only needs: `if let token = guestSession.rawToken, let urlString = guestSession.baseURL?.absoluteString`
  — no force unwraps, no `?? ""` fallback (RSPEC-6426):
  - `statusNonce = token`
  - `uploadJobId = 0` (sentinel — viewer has no upload)
  - `eventName = "\(details.orgName) — \(details.eventDate)"` (`details` from outer `else if` binding)
  - `submittedAt = Date()`
  - `baseURLString = urlString`
  - Other fields: `approvalStatus = "viewer"`, `lastSeenVideoCount = 0`,
    `viewedUploadJobIds = []`, `daysRemaining = nil`
- This record is **not persisted** to UserDefaults — it is constructed in-memory
  solely for navigation.
- The `NavigationLink` must be `.disabled(isUploading)`. Without this, a user who taps
  the gallery link mid-upload navigates away while the `Task { await doUpload() }` continues
  in the background (Swift Tasks are not cancelled on view disappearance). The upload
  completes, `guestSession.clear()` fires, and when the user returns they see the "Upload
  received" card — confusing because they didn't consciously complete the upload. Disabling
  the link during upload keeps the UX consistent with the existing "Do not navigate away"
  warning already shown by the form.

#### SonarQube / best-practice notes (Swift)

- **No force unwraps** — `guestSession.rawToken` and `guestSession.baseURL` are in the inner `if let` guard; `eventDetails` is already bound by the outer `else if`; eliminates `?? ""` fallbacks (RSPEC-6426).
- **`approvalStatus = "viewer"`** — not an enum value, but `GuestGalleryView` does not switch on this field so there is no runtime risk. Flag as a future cleanup if `approvalStatus` is ever typed as an enum.
- **Synthetic record not saved** — `GuestUploadRecord.upsert()` must not be called for viewer sessions; confirmed the link construction is in-memory only.

**`GuestGalleryView.swift`** — no structural changes required.

- `uploadJobId = 0` means `ownUploadIds` will be empty → delete buttons never
  appear for viewer sessions. ✓
- Report button calls `guest-report.php` with the raw token as the nonce. `guest-report.php` has the same `{30,40}` regex and is **not** being widened in this change — it returns HTTP 400 (not 403) for the 43-char token. The iOS client catches this as `badServer(400)`. The report silently fails; user sees a generic error alert. Acceptable for v1 — extend in a follow-on by widening `guest-report.php`'s regex and adding the same fallback auth path.
- `updateAllEventRecords` finds no stored records for this synthetic event (nothing
  was saved for a viewer-only visit) — `viewedIds` starts empty each session.
  Acceptable; "New" badges don't persist across sessions for pure viewers. ✓

---

## Deployment Sequencing

1. **Deploy PHP changes first** (via Ansible to gighiveinfra): `guest-gallery.php` and
   `guest-stream.php`. The backend now accepts raw tokens as a secondary auth path.
   Existing nonce-based gallery access is unchanged — backward-compatible.
2. **Ship iOS update second**: `GuestUploadView.swift` gallery link change goes out in
   the next App Store update after the backend is confirmed live on the target environment.

The iOS link is harmless before the backend lands (viewer gets a 403 → "Gallery access
could not be verified" — same as any expired nonce), but the intended sequence is
backend first.

**Verified: `guestSession.clear()` interaction** — on upload success, `clear()` nulls
`rawToken` and `eventDetails`, so the gallery link correctly disappears and the
"Upload received" card shows. No interaction issue between the upload and viewer paths.

---

## Wireframe

```
GuestUploadView (after QR token validated)
─────────────────────────────────────────
  🐝  Guest Upload

  ┌─────────────────────────────────────┐
  │  🐝  Visit Event Gallery         ›  │   ← NEW NavigationLink
  │      StormPigs — 2026-07-17         │     same bee logo + title3.bold() + caption
  └─────────────────────────────────────┘

  ┌─────────────────────────────────────┐
  │  Event                              │   ← existing upload form, unchanged
  │  StormPigs                          │
  │  2026-07-17                         │
  │                                     │
  │  Your name *  [___________________] │
  │  Clip label   [___________________] │
  │  □  I accept the Terms of Service   │
  │  [Upload]                           │
  └─────────────────────────────────────┘


GuestGalleryView (entered via viewer path)
─────────────────────────────────────────
  🐝  Event Gallery
      StormPigs — 2026-07-17

  Available for 87 more days · 4 videos

  ┌─────────────────────────────────────┐
  │  Jane Smith          New  ▶  🚩     │   ← no ✕ (ownUploadIds is empty)
  └─────────────────────────────────────┘
  ┌─────────────────────────────────────┐
  │  Bob Ruffino              ▶  🚩     │
  └─────────────────────────────────────┘
```

---

## Files to Change

| File | Repo | Change |
|------|------|--------|
| `api/guest-gallery.php` | gighiveinfra | Add raw-token fallback auth path |
| `api/guest-stream.php` | gighiveinfra | Add raw-token fallback auth path |
| `GigHive/Sources/App/GuestUploadView.swift` | gighiveapp | Add "Visit Event Gallery" NavigationLink |

`GuestGalleryView.swift`, `GuestGalleryAPIClient.swift`, `GuestUploadRecord.swift` —
**no changes needed.**

---

## Token TTL vs Gallery Expiry

> **Decision already made** — see *Decision* section above. This section is
> historical context documenting why Option B was rejected.
>
> **Known transient inconsistency**: until the follow-on `gallery_expires_at`
> removal task lands, existing uploaders (nonce path) see `daysRemaining` from
> `gallery_expires_at`, while viewers (token path) see it from `token.expires_at`.
> These values can differ if the two fields were set independently. This resolves
> when the follow-on task unifies all paths to `token.expires_at`.

The upload token in `event_upload_tokens` has its own expiry (enforced in
`UploadTokenValidator`, used only by `upload-token.php`). The event gallery has a
separate `gallery_expires_at` on the `events` table. The question: for *viewer*
access via raw token, which clock wins?

### Option A — Jibe them: use only `gallery_expires_at` for viewer access

**Pros**
- One expiry date governs the full viewer experience. The gallery is "open" for
  exactly `gallery_expires_at` days regardless of how you're accessing it.
- Returning attendees who come back days or weeks after the show can browse the
  gallery for its full duration — no surprise 403 on a gallery that is still live.
- Simpler backend: the raw-token auth path checks `gallery_expires_at` only,
  identical to the existing nonce path. No second expiry field to juggle.
- The upload token's TTL remains authoritative only for the upload action
  (`upload-token.php` already enforces it there). No repurposing confusion.

**Cons**
- If a token is marked active but the organizer intended its short TTL to also
  close off new viewers, that intent is lost. Mitigated by checking
  `is_active` (or equivalent) on the token row — revocation still works.
- If `gallery_expires_at` is null (no expiry configured), the raw token grants
  indefinite viewer access. Low actual risk since the gallery is read-only and
  moderated, but worth noting.

### Option B — Don't jibe: check both the token's own expiry AND `gallery_expires_at`

**Pros**
- Token revocation/expiry also revokes viewer access — tighter access control.
- Organizer can close the upload window and simultaneously stop new viewer sessions.

**Cons**
- Viewer gets a confusing 403 on a gallery that is still live and accessible to
  uploaders with approved nonces.
- Two independent expiry clocks to reason about, both in code and operationally.
- Creates exactly the surprise edge case called out in the original plan.

### Schema facts (confirmed)

- `event_upload_tokens.expires_at` — `datetime NOT NULL` — always set, per token
- `event_upload_tokens.is_active` — `tinyint(1) DEFAULT 1` — revocation flag
- `events.gallery_expires_at` — `datetime DEFAULT NULL` — nullable, per event;
  currently the only expiry signal used by `guest-gallery.php` and `guest-stream.php`

One event can have multiple tokens with different `expires_at` values. Under the
single-clock model, each QR code's token TTL governs gallery access for whoever
scanned that specific code — which is actually more precise than a single
per-event gallery window.

### Follow-on task: remove `gallery_expires_at`

Confirmed scope (10 items, from codebase grep):

1. `api/guest-gallery.php` — Nonce auth path JOINs `events` to get `gallery_expires_at`; `daysRemaining` calc
2. `api/guest-stream.php` — Nonce auth path JOINs `events` to get `gallery_expires_at`; expiry check
3. `api/guest-status.php` — `expired` status + `daysRemaining` calc; also JOINs `events` for `org_name`, `event_date`
4. `admin/event_qr.php` — **Active admin UI**: "Gallery lifespan (days)" form field; `save_event_settings` POST handler writes the column; `$ttlWarningHours` block; `ttl-mismatch-warning` JS; reads `QR_GALLERY_DEFAULT_LIFESPAN_DAYS` env var
5. `guest_event_view.php` — Expiry check; also JOINs `events` for `org_name`, `event_date`
6. `mysql/externalConfigs/create_media_db.sql` — DDL column definition; must be removed so new installs don't create it
7. `ansible/roles/shared_gallery/tasks/main.yml` — Two sets of smoke tests (see coupling note)
8. `ansible/roles/docker/templates/.env.j2` — Defines `QR_GALLERY_DEFAULT_LIFESPAN_DAYS` env var (line 66)
9. `ansible/inventories/group_vars/gighive/gighive.yml`, `gighive2/gighive2.yml`, `prod/prod.yml` — Each defines `qr_gallery_default_lifespan_days: 90` (the Ansible var that feeds item 8)
10. Database — `ALTER TABLE events DROP COLUMN gallery_expires_at`

> **Coupling note**: `main.yml` checks `SELECT gallery_expires_at, is_multi_day FROM events LIMIT 0`
> in a single SQL statement covering both columns. This smoke test fails as soon as either
> column is dropped. It must be patched (or removed) as part of whichever follow-on task runs
> first — do not run either `ALTER TABLE DROP COLUMN` before updating `main.yml`.

> **Sequencing note**: Do items 1–9 together with the `is_multi_day` removal in a single
> deployment. The `save_event_settings` action in item 4 writes both columns in one UPDATE;
> removing `gallery_expires_at` alone requires splitting that UPDATE.

Steps (numbered to match scope above):

1. **`api/guest-gallery.php`** — Drop `JOIN events e` entirely from nonce-path query (that JOIN
   exists only for `gallery_expires_at`); substitute `t.expires_at` from `event_upload_tokens`
   (already aliased as `t`).
   - Remove null-guard from expiry check: `$galleryExpiry !== null && $galleryExpiry <= $now`
     → `$tokenExpiry <= $now` (`token.expires_at` is `NOT NULL`; leaving the guard produces dead code)
   - Apply `> 3650` guard to `daysRemaining` nonce path (same guard already in token fallback path)

2. **`api/guest-stream.php`** — Drop `JOIN events e` entirely (same reason as item 1). Substitute
   `t.expires_at`; remove null-guard from expiry check. No `daysRemaining` in stream — just the
   expiry gate.

3. **`api/guest-status.php`** — **Keep `JOIN events e`** (still needed for `org_name`, `event_date`).
   Add `t.expires_at` to SELECT; remove `e.gallery_expires_at`. Replace
   `$row['gallery_expires_at']` → `$row['expires_at']`; remove null-guard; apply `> 3650`
   guard to `daysRemaining`. Nonce regex stays `{30,40}`.

4. **`admin/event_qr.php`** — Remove all gallery-lifespan code (do together with `is_multi_day`
   task per sequencing note):
   - Remove `save_event_settings` action handler (lines ~158–181)
   - Remove `$galleryExpiresAt`, `$isMultiDay`, `$galleryLifespanDays` variable declarations (~260–262)
   - Remove the events settings SELECT (`gallery_expires_at, is_multi_day, DATEDIFF(...)`, ~316–331)
     and all `if ($evtRow)` logic that follows
   - Remove `$galleryDefaultLifespan` (~339) — reads `QR_GALLERY_DEFAULT_LIFESPAN_DAYS` env var
   - Remove `$ttlWarningHours` computation block (~340–351)
   - Remove the entire `<form action="save_event_settings">` HTML block (~437–454):
     lifespan input + `is_multi_day` checkbox + Save button
   - Remove `ttl-mismatch-warning` `<div>` and its `<script>` block (~499–521)

5. **`guest_event_view.php`** — **Keep `JOIN events e`** (still needed for `org_name`, `event_date`).
   Add `t.expires_at` to SELECT; remove `e.gallery_expires_at`. Replace column reference;
   remove null-guard from `$isExpired`; apply `> 3650` guard to `daysRemaining`.
   > **Cross-task note**: If Task 3 has already been implemented, a raw-token fallback query
   > will exist that aliases `t.expires_at AS gallery_expires_at`. Task 1 must also update
   > that fallback query: remove the `AS gallery_expires_at` alias, and change the downstream
   > PHP (`$meta['gallery_expires_at']` → `$meta['expires_at']`) so both paths use the same
   > column name.

6. **`mysql/externalConfigs/create_media_db.sql`** — Remove `gallery_expires_at DATETIME NULL,` line.

7. **`ansible/roles/shared_gallery/tasks/main.yml`** — Remove two sets of tasks:
   - Events DB column check (`SELECT gallery_expires_at, is_multi_day FROM events LIMIT 0`) —
     both the assert task and the fail task (lines ~77–95)
   - `QR_GALLERY_DEFAULT_LIFESPAN_DAYS` env var check — both assert tasks (lines ~17–34);
     this env var is only consumed by the gallery lifespan feature being removed

8. **`ansible/roles/docker/templates/.env.j2`** — Remove the line:
   `QR_GALLERY_DEFAULT_LIFESPAN_DAYS={{ qr_gallery_default_lifespan_days | default(90) | int }}`

9. **`ansible/inventories/group_vars/gighive/gighive.yml`**, **`gighive2/gighive2.yml`**, **`prod/prod.yml`** —
   Remove `qr_gallery_default_lifespan_days: 90` from each (all three files, same line).

10. **Database** — `ALTER TABLE events DROP COLUMN gallery_expires_at;` — run after items 1–9
    are deployed.

This is a meaningful admin UI change, not just a backend sweep. Do together with `is_multi_day` task.

### Follow-on task: remove `is_multi_day`

All `admin/event_qr.php` changes are done together with the `gallery_expires_at` task — the
form + action that writes `is_multi_day` is removed as a unit (see sequencing note above).

Confirmed scope (3 items):

1. `mysql/externalConfigs/create_media_db.sql` — DDL column definition
2. `ansible/roles/shared_gallery/tasks/main.yml` — Column check (handled together with `gallery_expires_at` task item 7)
3. Database — `ALTER TABLE events DROP COLUMN is_multi_day`

Steps (numbered to match scope above):

1. **`mysql/externalConfigs/create_media_db.sql`** — Remove `is_multi_day TINYINT(1) NOT NULL DEFAULT 0,` line.

2. **`ansible/roles/shared_gallery/tasks/main.yml`** — Already handled in the `gallery_expires_at`
   task item 7 (the events column check task that covers both columns is removed there).

3. **Database** — `ALTER TABLE events DROP COLUMN is_multi_day;` — run together with
   `gallery_expires_at` drop.

### Follow-on task: widen `guest-report.php` + add viewer auth

v1 known limitation: viewer-only attendees (no approved upload) cannot report a video via the
JSON API or the web gallery, because both files only authenticate approved contributors and
reject 43-char raw tokens.

Confirmed scope (2 files):

1. `api/guest-report.php` — Nonce regex `{30,40}`; no raw-token fallback auth path
2. `guest_event_view.php` — Nonce regex `{30,40}`; no raw-token fallback for GET (gallery display) or POST (report)

Steps (numbered to match scope above):

1. **`api/guest-report.php`** — Two changes:

   **a. Widen nonce regex** (line 15):
   ```
   Before: preg_match('/^[A-Za-z0-9_\-]{30,40}$/', $body->nonce ?? '')
   After:  preg_match('/^[A-Za-z0-9_\-]{30,43}$/', $body->nonce ?? '')
   ```

   **b. Add raw-token fallback auth path** — replace the existing `if ($row === false) { exit(403); }`
   block (lines 49–55) with:
   ```php
   if ($row === false) {
       try {
           $tokenHash = hash('sha256', $nonce);
           $stmt = $pdo->prepare(
               'SELECT t.event_id
                FROM event_upload_tokens t
                WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()'
           );
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
   } else {
       $eventId = (int)$row['event_id'];
   }
   ```
   The existing `$eventId = (int)$row['event_id'];` at line 55 moves into the `else` branch
   above — **delete it from its original location**. The Step 2 UPDATE already uses `$eventId`
   only; flag logic is unchanged.

   **`main.yml` smoke test** — Existing test sends a 32-char nonce (within `{30,43}`) → falls
   through both auth lookups → 403. No change needed.

2. **`guest_event_view.php`** — Three changes:

   **a. Widen nonce regex** (line 10):
   ```
   Before: preg_match('/^[A-Za-z0-9_\-]{30,40}$/', $rawNonce) === 1
   After:  preg_match('/^[A-Za-z0-9_\-]{30,43}$/', $rawNonce) === 1
   ```

   **b. POST report handler — add raw-token fallback** — the existing `if ($rowR !== false)`
   block (lines ~33–47) only flags if the contributor is approved; viewer-only raw tokens
   return `$rowR === false` and silently skip the flag. Restructure as:
   ```php
   if ($rowR !== false) {
       $eventIdR = (int)$rowR['event_id'];
   } else {
       $tokenHash = hash('sha256', $safeNonce);
       $stmtTok = $pdoR->prepare(
           'SELECT t.event_id FROM event_upload_tokens t
            WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()'
       );
       $stmtTok->execute([$tokenHash]);
       $rowTok   = $stmtTok->fetch(\PDO::FETCH_ASSOC);
       $eventIdR = $rowTok !== false ? (int)$rowTok['event_id'] : null;
   }
   if ($eventIdR !== null) {
       $stmtFlag = $pdoR->prepare(
           'UPDATE upload_jobs j_target
            JOIN anon_upload_attributions a2 ON a2.upload_job_id = j_target.job_id
            JOIN event_upload_tokens t2 ON t2.token_id = a2.token_id
            SET j_target.guest_flagged = 1, j_target.guest_flagged_at = NOW()
            WHERE j_target.id = ? AND j_target.moderation_status = \'approved\'
              AND t2.event_id = ?'
       );
       $stmtFlag->execute([$targetJobId, $eventIdR]);
       if ($stmtFlag->rowCount() > 0) { $reportResult = 'ok'; }
   }
   ```

   **c. GET gallery auth — add raw-token fallback** — the existing `if ($meta === false)`
   block (lines ~93–104) immediately renders a 403 HTML page. Insert a raw-token lookup
   before the 403 response. Alias `t.expires_at AS gallery_expires_at` so the expiry/display
   PHP below (lines ~107–113) keeps working unchanged until Task 1 removes `gallery_expires_at`:
   ```php
   if ($meta === false) {
       try {
           $tokenHash = hash('sha256', $safeNonce);
           $stmtTok = $pdo->prepare(
               'SELECT t.event_id, t.expires_at AS gallery_expires_at,
                       e.org_name, e.event_date
                FROM event_upload_tokens t
                JOIN events e ON e.event_id = t.event_id
                WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()'
           );
           $stmtTok->execute([$tokenHash]);
           $meta = $stmtTok->fetch(\PDO::FETCH_ASSOC);
       } catch (\PDOException $e) {
           http_response_code(500);
           exit;
       }
   }
   if ($meta === false) {
       http_response_code(403);
       // ... existing 403 HTML ...
       exit;
   }
   ```
   Note: `$meta['gallery_expires_at']` is now `t.expires_at` (NOT NULL) for the raw-token
   path — the null-guard `$galleryExpiry !== null &&` in line ~109 becomes dead code on this
   path but does no harm; Task 1 removes it when the column is dropped.

---

## Progress

### Completed

- Full feature spec written, validated against codebase and DB schema
- Two blockers identified and corrected (token length 43 chars, no `token_value` column)
- SonarQube and best-practice review completed for all planned changes
- `admin/event_qr.php` — QR token TTL options updated:
  - Removed 4h option
  - Added 28 days (672h), 72 days (1728h), No Expiration options
  - Switched INSERT to PHP-side `$expiresAt` datetime to support the no-expiry sentinel
  - Updated server-side `in_array` validation and `$ttlWarningHours` logic accordingly
- `admin/event_qr.php` — Fixed pre-existing undefined `$now` variable bug (RSPEC-3512):
  - `$now` was declared only in the HTML token-table block but used earlier in the PHP
    section's `$activeTokenCount` loop, causing all expired tokens to appear active
- Plan — Added `daysRemaining` null guard for No Expiration tokens:
  - `expires_at = +100 years` would have produced "Available for 36,498 more days" in the iOS UI
  - Backend fallback branch applies a `> 3650 days` threshold and returns `null` instead

### Completed — Core Feature (2026-07-15)

- `api/guest-gallery.php` — nonce regex widened to `{30,43}`; raw-token fallback auth path added; `daysRemaining` > 3650 null guard applied
- `api/guest-stream.php` — nonce regex widened to `{30,43}`; raw-token fallback auth path added (event_id only; expiry enforced in SQL)
- `GuestUploadView.swift` — "Visit Event Gallery" `NavigationLink` added above upload form; synthetic `GuestUploadRecord` (not persisted); `.disabled(isUploading)`

### Remaining — Follow-on Tasks (separate)

| Task | Scope |
|------|-------|
| Remove `gallery_expires_at` | 10 items (5 PHP + `create_media_db.sql` + `main.yml` + `.env.j2` + 3 group_vars + `ALTER TABLE`) |
| Remove `is_multi_day` | `admin/event_qr.php` + `create_media_db.sql` + `main.yml` (see coupling note) + `ALTER TABLE events DROP COLUMN` |
| Widen `guest-report.php` + add viewer auth | `api/guest-report.php` + `guest_event_view.php` — viewer-only nonce widening + raw-token fallback for GET (gallery) and POST (report) |

---

## Database — ALTER TABLE Commands

Run after all code/config changes (items 1–9 in the follow-on task) are deployed and `main.yml` smoke test is patched:

```bash
docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "ALTER TABLE events DROP COLUMN gallery_expires_at; ALTER TABLE events DROP COLUMN is_multi_day;"'
```
