# Refactor: Consolidate upload_form.php and upload_form_admin.php into a single file

## Problem

There are currently two upload form files with ~400 lines of duplicated JS logic:

- `ansible/roles/docker/files/apache/webroot/db/upload_form.php` — shown to both `admin` and `uploader` roles; simplified fields only
- `ansible/roles/docker/files/apache/webroot/db/upload_form_admin.php` — shown to `admin` only; adds Advanced section (Participants, Keywords, Location, Rating, Notes)

The TUS upload logic, chunk tracking, finalize handler, localStorage delete token management, status bar rendering, and ETA calculation are copy-pasted between them. Any fix must be applied twice and the files will inevitably diverge — which is exactly what happened with the `asset_ids` vs `asset_id` delete bug (May 2026).

## Goal

Create a single `upload_form_single.php` (new file, independent of the existing two) that:

- Serves both `admin` and `uploader` roles
- Shows the Advanced section conditionally based on `IS_ADMIN` (PHP-injected from `$user`)
- Can be tested without touching the existing live forms

After verification, cut over by replacing `upload_form.php` with `upload_form_single.php` and deleting `upload_form_admin.php`.

No behavior change to either user experience.

---

## Phase 1: Build and Test (`upload_form_single.php`)

### 1. Create `db/upload_form_single.php` (new file)

- Read `$user` using the full fallback chain from `upload_form.php`:
  ```php
  $user = $_SERVER['PHP_AUTH_USER']
      ?? $_SERVER['REMOTE_USER']
      ?? $_SERVER['REDIRECT_REMOTE_USER']
      ?? 'Unknown';
  ```
- Inject `const IS_ADMIN = <?= json_encode($user === 'admin') ?>;` into the JS
- Include all uploader-facing fields unchanged from `upload_form.php`
- Add Advanced section inside a single `<div id="adminFields">` that includes the `<hr>`, `<h3>Advanced (Admin)</h3>` separator, and all five fields (Participants, Keywords, Location, Rating, Notes):
  - Style must be **PHP-rendered** at output time — not set by JS — to avoid a flash of admin fields before JS executes:
    ```php
    <div id="adminFields" style="<?= $user === 'admin' ? 'display:block' : 'display:none' ?>">
    ```
  - **The `<hr>` and `<h3>` must be inside this div** — if outside, they render for uploader even when the fields are hidden
- Delete logic: use `IS_ADMIN` branch (same as the May 2026 fix in `upload_form.php`):
  - Admin: `{ asset_ids: [Number(fileId)] }`
  - Uploader: `{ asset_id: Number(fileId), delete_token: token }`
  - Guard: `if (!fileId || (!IS_ADMIN && !token)) return;`
- Carry over the `user-indicator` div from `upload_form.php` (absent in `upload_form_admin.php` but useful for both roles):
  ```php
  <div class="user-indicator">User is logged in as <?= htmlspecialchars($user, ENT_QUOTES) ?></div>
  ```
- No "For Admins" link — the page already adapts to the current user

### 2. JS variable scoping — use outer-scope pattern from `upload_form.php`

`upload_form.php` declares `statusEl`, `resultEl`, and `btn` in the **outer IIFE scope**. `upload_form_admin.php` declares them inside the submit/delete handlers. When copying the Advanced section from `upload_form_admin.php`, do not carry over those inner declarations — use the outer-scope variables already declared at the top of the IIFE. Mixing both would produce double-declaration errors or silent shadowing bugs.

### 3. `db/upload_form.php` — no changes during Phase 1

- Do not touch `upload_form.php` during Phase 1
- Navigate directly to `/db/upload_form_single.php` by URL to test

### 4. Apache auth for `upload_form_single.php` during testing

- No new location block is required. `upload_form_single.php` falls under the existing broad LocationMatch:
  ```apache
  <LocationMatch "^/(?:...|db/(?!health\.php$).*)(?:/|$)">
      Require valid-user
  ```
- Only `admin` and `uploader` are defined in the htpasswd, so `valid-user` is equivalent to `Require user admin uploader` in practice

### 5. `localStorage` persistence check behavior

- Enforce for **uploader only**: block the upload button if localStorage is unavailable (private browsing) — uploader's only delete path is the token stored in localStorage, so uploading without it would create a file they can never delete
- Admin is **never blocked** by the localStorage check — admin can always delete via the database UI regardless of localStorage
- Implementation: `if (!hasPersistentStorage && !IS_ADMIN) { btn.disabled = true; ... }`

### 6. CSS `form { max-width }`

- Use `720px` for all users — minor visual widening for uploader vs today's `640px`, acceptable

### 7. Deploy Phase 1 changes

- Run Ansible to sync the new file into the container:
  ```
  ansible-playbook ansible/playbooks/site.yml
  ```
- `upload_form_single.php` will not exist at `/db/upload_form_single.php` on the server until this runs

---

## Phase 2: Cutover (after verification)

### 1. Replace `db/upload_form.php` with `db/upload_form_single.php`

- Copy `upload_form_single.php` → `upload_form.php` (overwrite)
- Delete `upload_form_single.php`
- The existing `/db/upload_form.php` Apache location block (`Require user admin uploader`) remains unchanged — it already covers both roles

### 2. Delete `db/upload_form_admin.php`

- Delete the file

### 3. Update `ansible/roles/docker/templates/default-ssl.conf.j2`

- Remove the `/db/upload_form_admin.php` location block:
  ```apache
  # --- ADMIN UPLOAD FORM: admin only ---
  <Location "/db/upload_form_admin.php">
      AuthType Basic
      AuthName "GigHive Admin"
      AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
      Require user admin
  </Location>
  ```
- **All three Phase 2 changes (Steps 1–3) must ship in a single Ansible run.** If the Apache location block (Step 3) is removed before the file is deleted (Step 2), `upload_form_admin.php` temporarily falls under the broad LocationMatch (`Require valid-user`), allowing `uploader` to access the admin form. A single run handles this atomically.

### 4. Deploy Phase 2 changes

- Run Ansible to apply the file overwrite, deletion, and Apache config change in one pass:
  ```
  ansible-playbook ansible/playbooks/site.yml
  ```

---

## Verification (Phase 1 — test on `upload_form_single.php`)

1. Log in as `uploader` → visit `/db/upload_form_single.php` → Advanced section must NOT be visible; upload and delete must work
2. Log in as `admin` → visit `/db/upload_form_single.php` → Advanced section IS visible; upload and delete must work
3. Run `upload_tests`: `ansible-playbook ansible/playbooks/site.yml --tags upload_tests`

## Verification (Phase 2 — after cutover)

1. Repeat steps 1–2 above against `/db/upload_form.php`
2. Access `/db/upload_form_admin.php` as admin → expect **404** (file deleted, Apache block removed, Ansible re-run). If the page renders, the file was not deleted. A 401 only appears for unauthenticated requests when the Apache location block is still present but the file is gone — that is an intermediate state, not the clean end state

---

## Notes

- **`IS_ADMIN` must be implemented from scratch** in `upload_form_single.php` — the existing injection in `upload_form.php` is not inherited; it must be re-implemented the same way
- **Security tradeoff**: Currently Apache prevents `uploader` from even loading `upload_form_admin.php`. After consolidation, the Advanced fields exist in the HTML source of `upload_form.php` (just hidden). A determined uploader could submit those fields via a crafted request, and `UploadService` DOES process `participants`, `keywords`, `location`, `rating`, `notes` from TUS metadata regardless of role. This is an acceptable tradeoff since these fields are metadata only — they carry no elevated permissions or destructive capability
- **`delete_token` localStorage**: The key `uploader_delete_tokens_v1` is shared between both existing forms, so `upload_form_single.php` will inherit all stored tokens without any migration
- **`.admin-link` CSS class**: The `.admin-link` style in `upload_form.php` can be removed at cutover since the "For Admins" link is gone
- **Phase 2 cutover auto-discards Phase 1 artifacts**: overwriting `upload_form.php` with `upload_form_single.php` is the only step needed to go live — there is no temporary change in `upload_form.php` to revert
- **No other live code references to `upload_form_admin.php`**: grep confirms the only reference in the webroot is the "For Admins" link in `upload_form.php`, which is auto-discarded by the Phase 2 overwrite. Docs that mention the path are informational and do not need updating
