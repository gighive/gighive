# Phase 1a Implementation Checklist — QR Code Guest Upload

**Status:** Not started  
**Reference plan:** `docs/feature_iphone_qr_code_support.md`  
**Walkthrough rationale:** `docs/feature_iphone_qr_code_support_conversation.md`

---

## Already Done (Infrastructure)

These items are complete and do not need to be implemented:

| Done | Item |
|---|---|
| ✅ | `create_media_db.sql` — `event_upload_tokens` and `anon_upload_attributions` DDLs added |
| ✅ | Group_vars — all 9 QR variables in `gighive2`, `gighive`, `prod` (incl. `qr_code_js_version`) |
| ✅ | `.env.j2` — `QR_TOKEN_DEFAULT_TTL_HOURS`, `SAAS_MODE`, and `QR_CODE_JS_VERSION` added |
| ✅ | `ansible/roles/qr_code/tasks/main.yml` — smoke test role (AASA, Apache bypass, env vars, DB tables) |
| ✅ | `ansible/roles/docker/templates/apple-app-site-association.j2` — AASA Jinja2 template |
| ✅ | `ansible/roles/docker/tasks/main.yml` — task renders AASA template before docker build |
| ✅ | `ansible/playbooks/site.yml` — `qr_code` role wired in |
| ✅ | `ansible/roles/docker/templates/default-ssl.conf.j2` — all 6 Step 2 Apache changes complete |

---

## Build Order

Dependencies must be respected. Do not start a step before its prerequisites are complete.

```
Step 3  ──────────────────────────────────────────┐
Step 2 (Apache) ✅ DONE                           │
                                                  ▼
Step 4  ──────────────────────────────────────── depends on 3
Step 5  ──────────────────────────────────────── depends on 3
Step 6  ──────────────────────────────────────── depends on 2, 3
Step 7  ──────────────────────────────────────── depends on 3
Step 10 ──────────────────────────────────────── independent (bootstrap only)

iOS Step 13 ──────────────────────────────────── independent
iOS Step 14 ──────────────────────────────────── depends on 13
iOS Steps 15, 16 ─────────────────────────────── independent (refactors)
iOS Step 17 ──────────────────────────────────── depends on 13
iOS Steps 18, 19 ─────────────────────────────── independent
iOS Step 20 ──────────────────────────────────── depends on 13
iOS Step 21 ──────────────────────────────────── depends on 13, 14, 15, 16, 17, 18, 19, 20
iOS Step 22 ──────────────────────────────────── depends on 21
iOS Step 23 ──────────────────────────────────── part of step 21 (no separate file)
```

---

## Server / PHP Steps

> **Deployment path for all PHP files:** `ansible/roles/docker/files/apache/webroot/`
> The Docker role bakes the entire webroot into the image at build time — there is no separate "copy to server" step. Create or modify files at the path above and they deploy automatically on the next `ansible-playbook site.yml` run.

### ~~Step 2~~ — `ansible/roles/docker/templates/default-ssl.conf.j2` ✅ COMPLETE

Six Apache changes. Deploy before testing any guest-path endpoint.

**1. Add `SetEnvIf` inside `<VirtualHost>` before the `<LocationMatch>` auth blocks:**
```apache
SetEnvIf X-Upload-Token .+ upload_token_auth
```

**2. Guest upload rewrite rule — place BEFORE the existing MVC rewrite rules:**
```apache
RewriteRule ^/upload/([A-Za-z0-9_-]+)$ /db/upload_form_single.php?token=$1 [L,QSA]
```

**3. Auth exemption for guest landing page:**
```apache
<Location "/db/upload_form_single.php">
    AuthMerging Off
    Require all granted
</Location>
```

**4 & 5. Replace `Require user admin uploader` in `/files/` and `/api/uploads/` `LocationMatch` blocks:**
```apache
<RequireAny>
    Require user admin uploader
    Require env upload_token_auth
</RequireAny>
```

**6. Auth exemption for token validation API:**
```apache
<Location "/api/upload-token.php">
    AuthMerging Off
    Require all granted
</Location>
```

**AASA Content-Type** (already in place — verify only):
```apache
<Location "/.well-known/apple-app-site-association">
    Header set Content-Type "application/json"
    Options -Indexes
</Location>
```

---

### Step 3 — `src/Services/UploadTokenValidator.php` *(New)*

**Namespace:** `Production\Api\Services`  
**Build before:** steps 4, 5, 6

```php
namespace Production\Api\Services;

readonly class TokenValidationResult {
    public function __construct(
        public int    $tokenId,
        public int    $eventId,
        public string $eventDate,
        public string $orgName,
        public string $eventType,
    ) {}
}

class UploadTokenValidator {
    public function __construct(private \PDO $pdo) {}

    public function validate(string $rawToken): ?TokenValidationResult {
        $hash = hash('sha256', $rawToken);
        $stmt = $this->pdo->prepare(
            'SELECT t.token_id, e.event_id, e.event_date, e.org_name,
                    COALESCE(e.event_type, \'\') AS event_type
             FROM event_upload_tokens t
             JOIN events e ON e.event_id = t.event_id
             WHERE t.token_hash = ? AND t.is_active = 1 AND t.expires_at > NOW()'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        return new TokenValidationResult(
            tokenId:   (int)$row['token_id'],
            eventId:   (int)$row['event_id'],
            eventDate: $row['event_date'],
            orgName:   $row['org_name'],
            eventType: $row['event_type'],
        );
    }
}
```

**Key notes:**
- No `hash_equals()` — validation is a DB lookup via prepared statement, not a string comparison
- Returns `null` for expired, revoked, and nonexistent tokens — no distinction exposed to callers
- `COALESCE(e.event_type, '')` — `event_type` is nullable in `events`; auto-created event rows (new-customer path) will have NULL; `TokenValidationResult::$eventType` is typed `string` (non-nullable) so NULL would throw a `TypeError` in PHP 8+ without the COALESCE

---

### Step 4 — `api/upload-token.php` *(New)*

Unauthenticated `GET` endpoint. Returns event details for iOS `QRTokenAPIClient`.

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Production\Api\Services\UploadTokenValidator;

header('Content-Type: application/json');

$rawToken = $_GET['token'] ?? '';
if ($rawToken === '') { http_response_code(400); echo json_encode(['error' => 'missing token']); exit; }
// Bound length before hashing — prevents DoS on pathologically long input
if (strlen($rawToken) > 128) { http_response_code(400); echo json_encode(['error' => 'invalid token']); exit; }

// $pdo — obtain via existing DB bootstrap (same pattern as api/tags.php)
$validator = new UploadTokenValidator($pdo);
$result = $validator->validate($rawToken);

if ($result === null) { http_response_code(404); echo json_encode(['error' => 'invalid or expired']); exit; }

echo json_encode([
    'event_id'   => $result->eventId,
    'event_date' => $result->eventDate,
    'org_name'   => $result->orgName,
    'event_type' => $result->eventType,
]);
```

**Key notes:**
- Accessed as `/api/upload-token.php` (with `.php` extension) — no rewrite rule needed
- Returns `404` for all invalid/expired tokens — never distinguish between the two
- iOS `QRTokenAPIClient` uses `.convertFromSnakeCase` JSON decoding; keep keys `snake_case`

---

### Step 5 — `UploadController.php` + `UploadService.php` *(Both Modified)*

**`src/Controllers/UploadController.php` — `finalize()` method:**

1. Read `X-Upload-Token` header: `$rawToken = $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? null;`
2. If non-null, validate: `$tokenResult = (new UploadTokenValidator($this->pdo))->validate($rawToken);`
3. If null result, return `404`
4. Pass `$tokenResult` and request body fields to `UploadService::finalizeTusUpload()`
5. If `$rawToken` is null, fall through to existing Basic Auth path unchanged

**`src/Services/UploadService.php` — `finalizeTusUpload()` token-mode path:**

```php
// Token mode additions (steps within finalizeTusUpload):

// 1. JSON body guard (before any key access)
$body = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
    http_response_code(400); exit;
}

// 2. Validate required fields
$label      = trim($body['label'] ?? '');
$tosAccepted = $body['tos_accepted'] ?? null;
$displayName = isset($body['display_name'])
    ? substr(strip_tags(trim($body['display_name'])), 0, 100)
    : null;

if ($label === '' || strlen($label) > 255 || $tosAccepted !== true) {
    http_response_code(400); exit;
}

// 3. Event context from token (ignore any client-supplied event fields)
$eventDate = $tokenResult->eventDate;
$orgName   = $tokenResult->orgName;
$eventType = $tokenResult->eventType;

// 4. Atomic write — anon_upload_attributions INSERT in same transaction as upload_jobs
$stmt = $pdo->prepare(
    'INSERT INTO anon_upload_attributions (token_id, upload_job_id, display_name, tos_accepted_at)
     VALUES (?, ?, ?, NOW())'
);
$stmt->execute([$tokenResult->tokenId, $uploadJobId, $displayName]);
```

**Key notes:**
- `$body['tos_accepted'] === true` — strict; string `"true"` or integer `1` → `400`
- Sanitize `display_name` here — do not defer
- `TODO:` After implementing, update `docs/database_schema.mermaidchart` to add the two new tables

---

### Step 6 — `db/upload_form_single.php` *(Modified)*

Add at the top of the file (currently absent — same pattern as `db/delete_media_files.php`):
```php
require_once __DIR__ . '/../vendor/autoload.php';
use Production\Api\Services\UploadTokenValidator;
```

**Token mode logic (third mode, alongside existing admin and authenticated-user modes):**

```php
$rawToken = $_GET['token'] ?? null;
$tokenResult = null;

if ($rawToken !== null) {
    // Bound length before hashing — prevents DoS on pathologically long input
    if (strlen($rawToken) > 128) {
        http_response_code(400); exit;
    }
    $tokenResult = (new UploadTokenValidator($pdo))->validate($rawToken);
    if ($tokenResult === null) {
        // Render clean error page — no form, no PHP warnings, no info disclosure
        http_response_code(410);
        // ... render "This upload link is no longer valid" HTML ...
        exit;
    }
}
```

**In the form HTML (token mode branch):**
- Pre-populate event name/date/type from `$tokenResult` as read-only fields — no user input for event metadata
- Add ToS checkbox (required — JS disables Upload button until checked)
- Add display name field (optional, `maxlength="100"`, `htmlspecialchars()` at every render point)
- Omit `withCredentials: true` and `Authorization: Basic` from TUS `headers`
- Add `X-Upload-Token: <rawToken>` to TUS `headers` option
- Add `X-Upload-Token: <rawToken>` to finalize `fetch` headers
- Pass `display_name` and `tos_accepted: true` in finalize body

**Key notes:**
- No admin QR management on this page — that is `admin/event_qr.php` (step 7)
- Existing admin and authenticated-user modes are completely unaffected
- **ToS text is a Phase 1a go-live blocker** — the checkbox and `tos_accepted_at` storage are implemented here, but the actual legal text shown to the guest must be authored before shipping. See "Phase 1a Go-Live Blockers" section.

---

### Step 7 — `admin/event_qr.php` *(New)*

New page following the `admin/ai_worker.php` convention. Gate: `$user === 'admin'`. Scoped by `?event_id=X`.

**Nav link — add to all `admin_*.php` pages that have nav links:**

`event_qr.php` must be added as the **last/bottom link** on every `admin_*.php` page that already carries a navigation block. Two patterns exist:

- **`admin_system.php` pattern** — upper-right `<div>` with stacked buttons; append:
```html
<a href="/admin/event_qr.php"><button type="button" style="border-color:#22c55e;font-size:.8rem;padding:.4rem .8rem">Guest QR Upload</button></a>
```
- **`ai_worker.php` pattern** — `<p>` inline link row; append:
```html
&nbsp;|&nbsp; <a href="/admin/event_qr.php">Guest QR Upload</a>
```

Pages to update: scan all `admin_*.php` files for either pattern and add the link as the last entry. `event_qr.php` itself should include a `← System` back-link to `admin_system.php` (same as `ai_worker.php` does).

**Event selector — top of page:**

`event_date` (`<input type="date">`) + `org_name` (`<input type="text">` with `<datalist>` of all distinct `org_name` values from the `events` table). Helper text under the org_name field:

> *Choose an existing organization or add new*

Sections only render once both fields are submitted and a valid event is resolved. Section headers show `(org_name — event_date)` for context.

**Initial load state (no params / blank database):**
```html
<p>Enter a date and org name, then click Load Event.</p>
<p class="muted">This is your starting point — no existing media required.</p>
```
Only the Event card renders; QR Generator, Active Tokens, and Guest Uploads are hidden. Form uses `method="GET"` so the resolved `org_name` + `event_date` bookmark in the URL.

**Submit button — explicit click, not auto-submit on change:**
```html
<button type="submit">Load Event</button>
```
Do NOT use `onchange="this.form.submit()"` — prevents accidental event row creation from a partial org name mid-typing.

**Look up or create event row (no schema change — purely application logic):**

The event card form uses `method="GET"` (bookmarkable URL). QR generation and Revoke use separate `method="POST"` forms. Read event card params from `$_GET`, not `$_POST`:

```php
$orgName   = trim($_GET['org_name'] ?? '');
$eventDate = trim($_GET['event_date'] ?? '');

// Validate before any DB access
if ($orgName === '' || strlen($orgName) > 255) {
    $loadError = 'Org name is required (max 255 characters).';
} elseif (\DateTime::createFromFormat('Y-m-d', $eventDate) === false
       || \DateTime::createFromFormat('Y-m-d', $eventDate)->format('Y-m-d') !== $eventDate) {
    $loadError = 'Invalid date — use YYYY-MM-DD format.';
}

// Only proceed if no validation error and both params present
if ($orgName !== '' && $eventDate !== '' && !isset($loadError)) {
    $stmt = $pdo->prepare(
        'SELECT event_id FROM events WHERE tenant_id = 1 AND event_date = ? AND org_name = ? LIMIT 1'
    );
    $stmt->execute([$eventDate, $orgName]);
    $eventId = $stmt->fetchColumn();

    if (!$eventId) {
        $stmt = $pdo->prepare(
            'INSERT INTO events (tenant_id, event_key, event_date, org_name) VALUES (1, UUID(), ?, ?)'
        );
        $stmt->execute([$eventDate, $orgName]);
        $eventId = (int)$pdo->lastInsertId();
    }
}
```

The `events` unique key `(tenant_id, event_date, org_name)` prevents duplicates. `event_id` is never exposed to the admin.

**`tenant_id = 1` hardcoded** — acceptable for Phase 1a (single-tenant). Flag as a Phase 2 change point when multi-tenancy ships.

**CSRF** — Generate QR and Revoke are state-mutating POSTs. Basic Auth provides inherent CSRF protection (browsers do not send Basic Auth credentials on cross-origin requests). No additional CSRF token needed for Phase 1a; document this decision explicitly if the security posture is ever reviewed.

**`<datalist>` population:**
```php
$orgs = $pdo->query(
    'SELECT DISTINCT org_name FROM events ORDER BY org_name ASC'
)->fetchAll(PDO::FETCH_COLUMN);
```
```html
<input type="date" name="event_date" required
       value="<?= htmlspecialchars($eventDate, ENT_QUOTES) ?>">
<input type="text" name="org_name" list="org-list" required
       value="<?= htmlspecialchars($orgName, ENT_QUOTES) ?>">
<datalist id="org-list">
  <?php foreach ($orgs as $o): ?>
    <option value="<?= htmlspecialchars($o) ?>">
  <?php endforeach; ?>
</datalist>
<small>Choose an existing organization or add new</small>
```

**Section 1 — QR Code Generator:**

Expiry options (radio buttons): **4h / 24h / 7d / 14d** — pre-selected from `QR_TOKEN_DEFAULT_TTL_HOURS` env var (default: `168` = 7 days). Allowed server-side values: `4, 24, 168, 336` — reject anything else with 400.

```php
// Token generation
$rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$tokenHash = hash('sha256', $rawToken);
$expiryHours = (int)($_POST['ttl_hours'] ?? getenv('QR_TOKEN_DEFAULT_TTL_HOURS') ?? 168);
// Allowlist — reject anything not in the set; prevents 0-hour (instant expiry) or year-long tokens
if (!in_array($expiryHours, [4, 24, 168, 336], true)) {
    http_response_code(400); exit;
}
$stmt = $pdo->prepare(
    'INSERT INTO event_upload_tokens (event_id, token_hash, expires_at, created_by_user_id)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), NULL)'
);
$stmt->execute([$eventId, $tokenHash, $expiryHours]);
// $rawToken used to build QR URL — never stored
// Use server's own scheme+host so the URL works on dev/staging too
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'gighive.app';
$qrUrl  = $scheme . '://' . $host . '/upload/' . $rawToken;
```

QR rendered to `<canvas>` via `qrcode.js` (jsDelivr CDN). CDN URL in `event_qr.php`:
```html
<script src="https://cdn.jsdelivr.net/npm/qrcode@<?= htmlspecialchars(getenv('QR_CODE_JS_VERSION') ?: '1.5.4', ENT_QUOTES) ?>/build/qrcode.min.js"></script>
```
Version is tracked via `qr_code_js_version` group_var → `QR_CODE_JS_VERSION` env var (add to `.env.j2` and group_vars alongside the other QR vars). Appears in the **Stack Versions Summary** as `QR_Code_JS`. To upgrade: bump the group_var in all three group_vars files; the CDN URL updates on next deploy.

PNG download exports canvas data URL.

**Section 2 — Active Tokens table:**
- Columns: truncated hash (first 8 chars + `…`), expiry datetime, status badge (Active / Expired / Revoked), Action
- **[Revoke] button appears on Active rows only** — Expired and Revoked rows show `—` in the Action column; no reactivation path
- [Revoke] POSTs `token_id` + `event_id`; handler verifies `token_id` belongs to `event_id` before `SET is_active = 0`
- Expired/Revoked rows remain in table for audit — never deleted

**Section 3 — Guest Uploads list:**
```sql
SELECT a.display_name, a.tos_accepted_at, a.created_at,
       j.label, j.job_id,
       t.is_active AS token_active, t.expires_at
FROM anon_upload_attributions a
JOIN event_upload_tokens t ON t.token_id = a.token_id
JOIN upload_jobs j ON j.job_id = a.upload_job_id
WHERE t.event_id = ?
ORDER BY a.created_at DESC
```

Flag rows from revoked/expired tokens with a warning indicator (e.g. `⚠` prefix or `color:#ef4444` styling). Render `display_name`:
```php
$name = $row['display_name'] !== null
    ? htmlspecialchars($row['display_name'], ENT_QUOTES)
    : '<em>(anonymous)</em>';
```

**Operator license link** — add a small muted link in the page footer or header:
```html
<p class="muted" style="font-size:.8rem">
  GigHive is dual-licensed. <a href="https://gighive.app" target="_blank">Licensing info</a>
</p>
```
This surfaces the commercial license requirement to operators without cluttering the page.

---

### Step 10 — `config.php` (or PHP bootstrap) *(Modified)*

Read `SAAS_MODE` from env at bootstrap:
```php
define('SAAS_MODE', filter_var(getenv('SAAS_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN));
```

**No conditional behavior wired in Phase 1a.** The constant is defined for the Step 7 OIDC gate only.

---

## iOS Steps

### Steps 11 & 12 — `Configs/GigHive.entitlements` + `project.yml` *(Both Modified)*

Commit these together — never one without the other.

**`GigHive.entitlements`** — add:
```xml
<key>com.apple.developer.associated-domains</key>
<array>
    <string>applinks:gighive.app</string>
</array>
```

**`project.yml`** — add under target:
```yaml
targets:
  GigHive:
    entitlements: Configs/GigHive.entitlements
```

Run `xcodegen generate` after both are in place.

---

### Step 13 — `Sources/App/GuestUploadSession.swift` *(New)*

```swift
@MainActor
final class GuestUploadSession: ObservableObject {
    @Published var rawToken: String?
    @Published var baseURL: String?
    @Published var eventDetails: QREventDetails?
    @Published var displayName: String = ""
    @Published var tosAccepted: Bool = false

    func clear() {
        rawToken = nil; baseURL = nil; eventDetails = nil
        displayName = ""; tosAccepted = false
    }
}

struct QREventDetails: Codable {
    let eventId: Int
    let eventDate: String
    let orgName: String
    let eventType: String
}
```

**Build before:** steps 14, 20, 21, 22

---

### Step 14 — `Sources/App/QRTokenAPIClient.swift` *(New)*

```swift
@MainActor
final class QRTokenAPIClient {
    func validate(rawToken: String, baseURL: String) async throws -> QREventDetails {
        var components = URLComponents(string: baseURL)!
        components.path = "/api/upload-token.php"   // .php extension required
        components.queryItems = [URLQueryItem(name: "token", value: rawToken)]
        let (data, response) = try await URLSession.shared.data(from: components.url!)
        guard (response as? HTTPURLResponse)?.statusCode == 200 else {
            throw QRTokenError.invalidOrExpired
        }
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return try decoder.decode(QREventDetails.self, from: data)
    }
}

enum QRTokenError: Error {
    case invalidOrExpired
    case networkFailure(Error)
}
```

**Build before:** step 21

---

### Steps 15 & 16 — Extractions from `UploadView.swift`

**Step 15 — New files `FinalizeResponse.swift` + `FinalizeResponseHandler.swift`:**
- Move `private struct FinalizeResponse` → `internal struct FinalizeResponse` (own file)
- Move `private func handleFinalizeResponse` + `private func extractJSONCandidate` → `internal` (own file)

**Step 16 — New files `PHPickerView.swift` + `DocumentPickerView.swift`:**
- Move `PHPickerView` `UIViewControllerRepresentable` → `internal` (own file)
- Move `DocumentPickerView` `UIViewControllerRepresentable` → `internal` (own file)

**`UploadView.swift`** — remove the moved definitions; references resolve from the new files automatically.

**Key note:** `GuestUploadView` uses `PHPickerFilter.videos` only — guests upload video clips. This filter is set in `GuestUploadView`'s configuration of `PHPickerView`, not inside the picker itself.

**Build before:** step 21

---

### Step 17 — `Sources/App/UploadPayload+GuestUpload.swift` *(New)*

```swift
extension UploadPayload {
    static func forGuestUpload(
        fileURL: URL,
        eventDetails: QREventDetails,
        displayName: String
    ) -> UploadPayload {
        UploadPayload(
            label: fileURL.deletingPathExtension().lastPathComponent,
            eventDate: eventDetails.eventDate,
            orgName: eventDetails.orgName,
            eventType: eventDetails.eventType,
            displayName: String(displayName.prefix(100)).trimmingCharacters(in: .whitespaces)
        )
    }
}
```

**Build before:** step 21

---

### Step 18 — `Sources/App/UploadClient.swift` *(Modified)*

1. Add `private let uploadToken: String?` stored property
2. Add `uploadToken: String? = nil` to `init()` — existing callers unaffected
3. In finalize `URLRequest` builder, replace the `Authorization` header logic:
```swift
if let token = uploadToken {
    request.setValue(token, forHTTPHeaderField: "X-Upload-Token")
} else {
    request.setValue("Basic \(basicAuth)", forHTTPHeaderField: "Authorization")
}
```

**Build before:** step 21

---

### Step 19 — `Sources/App/TUSUploadClient.swift` *(Modified)*

1. Add `private let uploadToken: String?` stored property
2. Add `uploadToken: String? = nil` to `init(tusBaseURL:basicAuth:allowInsecure:chunkSize:)` — existing callers unaffected
3. Capture `uploadToken` in the closure: `{ [basicAuth, uploadToken] _, headers, completion in`
4. Replace the `if let basicAuth` block:
```swift
if let uploadToken {
    mutated["X-Upload-Token"] = uploadToken
} else if let basicAuth {
    mutated["Authorization"] = "Basic \(basicAuth)"
}
```

---

### Step 20 — `Sources/App/GigHiveApp.swift` *(Modified)*

```swift
@StateObject private var guestSession = GuestUploadSession()
```

On `WindowGroup` (both NavigationStack iOS 16+ and NavigationView iOS 14–15 branches):
```swift
.environmentObject(guestSession)
.onOpenURL { url in
    guard url.host == "gighive.app",
          url.pathComponents.count >= 3,
          url.pathComponents[1] == "upload",
          let token = url.pathComponents.last, !token.isEmpty,
          let host = url.host   // captured here — eliminates force-unwrap below
    else { return }
    guestSession.rawToken = token
    guestSession.baseURL = "\(url.scheme ?? "https")://\(host)"
}
```

**`pathComponents` structure:** `https://gighive.app/upload/abc123` → `["/", "upload", "abc123"]`

**Build before:** step 22

---

### Step 21 — `Sources/App/GuestUploadView.swift` *(New)*

**Depends on steps 13–20 all complete.**

Key implementation points:

**`.onAppear`:**
```swift
.onAppear {
    Task { await validateToken() }
}

@MainActor func validateToken() async {
    guard let token = guestSession.rawToken,
          let base = guestSession.baseURL else { return }
    do {
        guestSession.eventDetails = try await QRTokenAPIClient().validate(rawToken: token, baseURL: base)
    } catch {
        showErrorState = true
    }
}
```

**`doUpload()` — annotate `@MainActor`:**
```swift
@MainActor func doUpload() async {
    guard let token = guestSession.rawToken,
          let base  = guestSession.baseURL,   // guarded here — eliminates force-unwrap on baseURL!
          let details = guestSession.eventDetails,
          let fileURL = selectedFileURL,
          guestSession.tosAccepted else { return }

    let payload = UploadPayload.forGuestUpload(
        fileURL: fileURL, eventDetails: details, displayName: guestSession.displayName)
    // tusBaseURL, allowInsecure, chunkSize come from existing app config (same source as UploadView)
    let tusClient = TUSUploadClient(
        tusBaseURL: base + "/files/",   // base from guard above — no force-unwrap
        basicAuth: nil,
        allowInsecure: false,
        chunkSize: existingChunkSize,
        uploadToken: token)
    let uploadClient = UploadClient(uploadToken: token)
    // ... run TUS upload with tusClient ...
    // ... call finalize with uploadClient, pass display_name + tos_accepted: true ...
    // On 200/201:
    guestSession.clear()  // rawToken and eventDetails become nil
}
```

**Post-`clear()` state — must handle nil gracefully:**
```swift
// After guestSession.clear() the view body re-evaluates with both nil.
// Guard against force-unwrap crashes:
if guestSession.rawToken == nil && !isUploading {
    // Show "Upload received — thank you!" + Dismiss button
}
```

**Dismiss button** pops the view; `SplashView` sees `rawToken == nil` and does not re-navigate.

---

### Step 22 — `Sources/App/SplashView.swift` *(Modified)*

Add third `NavigationLink` (invisible — empty label):
```swift
@State private var isGuestUpload = false

NavigationLink(destination: GuestUploadView(), isActive: $isGuestUpload) { }
    .onChange(of: guestSession.rawToken) { token in
        isGuestUpload = token != nil
    }
```

`@EnvironmentObject var guestSession: GuestUploadSession` — already injected by step 20.

---

### Step 23 — iOS Error / Fallback Screen *(inside `GuestUploadView`)*

Shown when `QRTokenAPIClient` throws. No separate file.

```swift
// Error state view:
VStack {
    Text("This upload link is no longer valid.")
    Text("It may have expired or been revoked by the event organizer.")
        .font(.caption)
    Button("Open in Safari") {
        let urlString = "\(guestSession.baseURL ?? "https://gighive.app")/upload/\(guestSession.rawToken ?? "")"
        if let url = URL(string: urlString) {
            UIApplication.shared.open(url)
        }
    }
}
```

Safari fallback lands on `upload_form_single.php` → `UploadTokenValidator` → clean error page (no form shown).

---

## Testing Checklist

### Server / PHP

- [ ] Step 2: Deploy Apache config; verify guest URL (`/upload/test`) rewrites without 401
- [ ] Step 3: Unit test `UploadTokenValidator` with valid, expired, revoked, and nonexistent tokens
- [ ] Step 4: `curl https://host/api/upload-token.php?token=<valid>` returns 200 JSON; `?token=bad` returns 404
- [ ] Step 5: `POST /api/uploads/finalize` with `X-Upload-Token` header creates `anon_upload_attributions` row; `tos_accepted: "true"` (string) returns 400
- [ ] Step 6: Web form at `/upload/<token>` shows read-only event fields and ToS; expired token shows clean error
- [ ] Step 7: Admin can generate QR, revoke token, view guest upload list; cross-event revoke attempt is blocked
- [ ] **Playwright — `tests/admin_event_qr.spec.ts`:** cover the following scenarios:
  - Unauthenticated request to `/admin/event_qr.php` returns 401
  - Page loads with event selector; selecting an event populates all three sections
  - QR generator: submitting expiry dropdown POSTs and renders a `<canvas>` element; PNG download button is present
  - Active tokens table: newly generated token appears with status "Active"; [Revoke] button sets status to "Revoked" on reload
  - Cross-event revoke: POST with a `token_id` belonging to a different `event_id` is rejected (no row change)
  - Guest uploads list: row appears after a token-authenticated upload is completed; revoked token rows are visually flagged
  - Nav link: `← System` link present and points to `/admin/admin_system.php`

### iOS

- [ ] Tapping QR link with app installed opens `GuestUploadView` (not Safari)
- [ ] Spinner shown during token validation; error state shown for invalid token
- [ ] "Open in Safari" on error opens correct URL
- [ ] Upload with valid token succeeds; `anon_upload_attributions` row created in DB
- [ ] `guestSession.clear()` after upload shows "thank you" state; Dismiss returns to SplashView without re-triggering navigation
- [ ] Existing `UploadView` (Basic Auth flow) is unaffected — no regressions

### Ansible Test Additions Required

Three Ansible changes must be made alongside the feature implementation. Add them after the PHP steps are deployed.

**1. `ansible/roles/qr_code/tasks/main.yml` — add after existing auth bypass checks (tags: `qr_code,smoke,api`):**
```yaml
- name: Token API — missing ?token param must return 400
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}{{ qr_token_api_path }}"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _qr_host_header }}"
    status_code: 400
  changed_when: false
  tags: [qr_code, smoke, api]

- name: Token API — invalid token must return 404
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}{{ qr_token_api_path }}?token=invalid-token-abc123"
    method: GET
    validate_certs: "{{ gighive_validate_certs }}"
    headers: "{{ _qr_host_header }}"
    status_code: 404
  changed_when: false
  tags: [qr_code, smoke, api]
```
*Prerequisite: Steps 3 and 4 deployed.*

**2. `ansible/roles/post_build_checks/tasks/main.yml` — add a token-auth TUS block (tags: `tus,qr_code`) after the existing Basic Auth TUS block:**

Block outline (implement in full):
- INSERT test event row + test token into MySQL via `docker exec` (SHA-256 of `qr_smoke_token`; expires 1 hour from now)
- TUS POST `/files/` with `X-Upload-Token: {{ qr_smoke_token }}` — assert 201
- TUS PATCH (upload payload) — assert 204
- Wait for tusd hook JSON
- POST `/api/uploads/finalize` with `X-Upload-Token` header and body `{"upload_id": ..., "tos_accepted": "true", "label": "QR_TUS_VALIDATE"}` — assert **400** (string `"true"` rejected)
- POST `/api/uploads/finalize` with `tos_accepted: true` (boolean) — assert 201; assert `anon_upload_attributions` row exists in DB
- Cleanup: DELETE token row, event row, TUS artifacts
*Prerequisite: Steps 3, 4, 5 deployed.*

**3. `ansible/roles/playwright_admin_tests/files/tests/` — two new spec files:**
- `admin_event_qr.spec.ts` — covers all 6 scenarios in the Playwright checklist above
- `guest_upload_form.spec.ts` — covers Step 6: load `/upload/<smoke_token>` (requires a valid token in DB); assert read-only event fields present; assert ToS checkbox present and Upload button disabled until checked; load `/upload/expired-token`; assert clean error page (no form, no PHP warnings)
*Prerequisite: Steps 3, 4, 6, 7 deployed; test token seeded in DB.*

### Ansible Smoke Test

Run after deploy:
```bash
ansible-playbook ansible/playbooks/site.yml --tags qr_code
```
All tasks should pass including AASA Content-Type, Apache bypass checks, env var format, and DB table existence.

---

## Phase 1a Go-Live Blockers

These are not implementation steps but must be resolved before Phase 1a ships:

- **ToS text — two distinct audiences, two distinct documents:**

  **1. Guest (end-user) ToS** — shown on `db/upload_form_single.php` and `GuestUploadView`; accepted via checkbox before upload; `tos_accepted_at` stored in `anon_upload_attributions`. Minimum required content:
    - Uploaded content is accessible to the event organizer
    - Uploader confirms they have the right to upload the content (no third-party copyrighted material)
    - Participation is anonymous — no account is created
    - Optional display name is self-reported and not verified by GigHive
    - Content may be retained by the organizer indefinitely

  **2. Operator (commercial) license** — separate from the guest ToS. GigHive is dual-licensed (AGPL v3 / commercial). Any company running GigHive as a hosted service, embedding it in a paid product, or offering it to clients must obtain a commercial license (`contactus@gighive.app`). Self-hosted non-commercial use is covered under AGPL v3. The admin UI should link to the `LICENSE` file or `https://gighive.app` licensing page so operators are clearly informed.

  These two documents are independent: a guest agreeing to upload terms is not agreeing to the operator license, and vice versa. Do not conflate them in a single checkbox or document.

---

## Deferred (Not Phase 1a)

- Owner UI: browsable guest-contributed upload list per event (data exists in `anon_upload_attributions`; UI is follow-on)
- `created_by_user_id` population — wired when OIDC/RBAC ships (step 7 SaaS model); `NULL` in interim
- Rate limiting per QR token (max N uploads per token) — not designed yet
- Storage quota measurement for guest uploads — Phase 2 step 13
- htpasswd `guest` user rename to `readonly` — avoids conceptual collision with QR guest persona; deferred, no Phase 1a functional impact
- `anon_upload_attributions.display_name` column COMMENT — update `'Self-reported fan display name'` → `'Self-reported guest display name'` in `create_media_db.sql`; reverted from this session; bundle with next DDL change batch (cosmetic only, no schema impact)
