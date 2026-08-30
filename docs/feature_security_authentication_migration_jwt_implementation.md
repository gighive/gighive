# Feature: Federated Auth Migration — Implementation Guide

**Status:** Pre-implementation — pending approval  
**Date:** 2026-08-19  
**Parent doc:** `docs/feature_security_authentication_migration_jwt.md`

---

## Elevator Pitch

GigHive's shared `admin`/`uploader`/`viewer` passwords mean every person who touches the system has the same key. This implementation replaces those shared keys with individual logins: each person signs in once, gets their own JWT, and the server knows exactly who did what. QR-code event access is untouched.

---

## Scope

This document covers only **Phase 1 through Phase 4** — JWT core, PHP guards, iOS client cutover, and Apache Basic Auth removal. Phase 5 (OIDC/federated login) is a follow-on and is scoped separately; only the scaffolding that Phase 5 requires is noted.

---

## Files Under Change

### New — Server (`ansible/roles/docker/files/apache/webroot/`)

1. `auth/jwt.php` — `JwtAuth` class: `generate()` and `validate()` using `firebase/php-jwt` library. Reads `JWT_SECRET` and `JWT_TTL_SECONDS` from env via constants defined in `config.php`.
2. `auth/helpers.php` — `requireRole(string $minRole): void` and `hasRole(string $minRole): bool`. Role hierarchy: `owner=3`, `contributor=2`, `viewer=1`.
3. `api/login.php` — `POST /api/login.php`: email + password exchange for JWT. Validates against `users` table, `idp_provider='local'`.
4. `api/verify.php` — `GET /api/verify.php`: validates stored JWT; returns distinct `token_expired` vs `invalid_token` error codes.

### New — Database (`ansible/roles/docker/files/mysql/externalConfigs/`)

5. `create_media_db.sql` — add `password_hash` and `disabled` columns to the existing `users` table definition (updated in the bootstrap file).

### New — iOS (`GigHive/Sources/App/`)

6. `JWTStore.swift` — Keychain wrapper for `StoredToken` (token string + role + expiry). Replaces `KeychainStore` for account-based auth.

### Modified — Server

7. `config.php` — add `GIGHIVE_AUTH_MODE`, `JWT_SECRET`, `JWT_TTL_SECONDS` constants.
8. `api/media-stream.php` — Phase 4: swap Basic Auth trust for JWT Bearer validation in `authenticateRequest()`.
9. `api/tus-upload.php` — Phase 4: add PHP-side JWT Bearer validation block before the existing QR token block.
10. `api/uploads.php` — Phase 2: add `requireRole('contributor')` at top.
11. `api/ai_jobs.php` — Phase 2: add `requireRole('viewer')` or `requireRole('owner')` per endpoint.
12. `db/database.php` — Phase 2: add `requireRole('viewer')` at top.
13. `db/database_catalog.php` — Phase 2: add `requireRole('viewer')` at top.
14. `db/upload_form.php` — Phase 2: add `requireRole('contributor')` at top.
15. `db/upload_form_admin.php` — Phase 2: add `requireRole('owner')` at top.
16. `db/delete_media_files.php` — Phase 2: add `requireRole('contributor')` at top.
17. `admin/*.php` (42 files) — Phase 2: add `requireRole('owner')` at top of each file. The Apache `/admin/` location block enforces `Require user admin` during Phases 1–3, so the PHP guard is defence-in-depth until Phase 4 makes PHP the sole gatekeeper.
18. `ansible/roles/docker/templates/.env.j2` — add `GIGHIVE_AUTH_MODE`, `JWT_SECRET`, `JWT_TTL_SECONDS`.
19. `ansible/roles/docker/files/apache/webroot/composer.json` + `composer.lock` — add `firebase/php-jwt ^6.10` (run `composer require firebase/php-jwt:^6.10` locally; commit both files). `Dockerfile.j2` already runs `composer install` — no change to `Dockerfile.j2` is needed for Phases 1–4. Phase 5 does require a Dockerfile change to add `libapache2-mod-auth-openidc`, but that is scoped to the Phase 5 document.
20. `ansible/roles/docker/templates/default-ssl.conf.j2` — Phase 4: remove all `AuthType Basic` blocks. Retain all QR `AuthMerging Off` blocks and all `SetEnvIf` directives.
21. `ansible/roles/post_build_checks/tasks/main.yml` — add smoke tests for new endpoints and JWT-auth paths.

### Modified — iOS

22. `AuthSession.swift` — replace `credentials: (user: String, pass: String)?` with `token: String?` and `expiresAt: Date?`; add role from JWT claim.
23. `LoginView.swift` — call `POST /api/login.php`; store result via `JWTStore`; replace username-derived role with JWT claim.
24. `SplashView.swift` — replace all `session.credentials` guards with `session.token` guards.
25. `DatabaseView.swift` — pass `session.token` as Bearer header instead of `session.credentials`.
26. `DatabaseDetailView.swift` — pass `token: session.token` to `MediaPlayerView` instead of `credentials:`.
27. `MediaPlayerView.swift` — replace `credentials: (user: String, pass: String)?` with `token: String?`; build `Authorization: Bearer` header.
28. `MediaResourceLoader.swift` — replace `init(credentials:)` with `init(token:)`; send Bearer header.
29. `DatabaseAPIClient.swift` — replace `basicAuth:` param and `Authorization: Basic` with `bearerToken:` and `Authorization: Bearer`.
30. `TUSUploadClient.swift` — replace `basicAuth` header branch with `bearerToken` branch; `uploadToken` branch unchanged.
31. `KeychainStore.swift` — mark deprecated; retain `load()` for one-time migration read on first launch; `JWTStore` handles new token storage.

### Unchanged (explicitly)

- `GuestUploadSession.swift`, `QRTokenAPIClient.swift` — QR flow is independent; no changes.
- `db/upload_form_single.php` — `Require all granted`; QR guest path unchanged.
- All `/api/guest-*.php`, `/api/upload-token.php` — unchanged.
- `apache/webroot/src/Services/UploadTokenValidator.php`, `GuestCredentialResolver.php` — unchanged.

---

## Prerequisite: PHP JWT Library

**No JWT library is currently vendored.** `composer.json` contains only `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `psr/http-message`, and `zircote/swagger-php`. Before writing `auth/jwt.php`, `firebase/php-jwt` must be added:

```bash
# Run locally in ansible/roles/docker/files/apache/webroot/
composer require firebase/php-jwt:^6.10
```

This updates `composer.json` and `composer.lock`. Both committed files are what the `Dockerfile.j2` `RUN composer install` step uses — no Dockerfile change is needed beyond committing the updated manifests.

`firebase/php-jwt` 6.10 was published 2025-01-13; it is well past the 7-day minimum age. PHP 8.3 is fully supported.

**SonarQube note:** Using a well-maintained library instead of raw `openssl_sign()`/`base64_encode()` inline avoids RSPEC-2635 (custom crypto) and RSPEC-3512 (predictable IV). Do not roll a JWT implementation by hand.

---

## Phase 1 — JWT Core

### 1a. Schema Change: `users` table

The `users` table already exists in `create_media_db.sql` (see parent doc). It does not have `password_hash` or `disabled` columns. Both are required for Phase 1 local-user login.

**Update `create_media_db.sql`** — add both columns to the `users` CREATE TABLE block (after `idp_subject`):

```sql
  password_hash   varchar(255)  DEFAULT NULL
                                COMMENT 'bcrypt hash; NULL for OIDC-only users',
  disabled        tinyint(1)    NOT NULL DEFAULT 0
                                COMMENT '1 = account suspended',
```

**Live ALTER command (BABRRR Step 2)** — run manually on each existing environment:

```bash
docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "
ALTER TABLE users
  ADD COLUMN password_hash varchar(255) DEFAULT NULL
      COMMENT '"'"'bcrypt hash; NULL for OIDC-only users'"'"'
      AFTER idp_subject,
  ADD COLUMN disabled tinyint(1) NOT NULL DEFAULT 0
      COMMENT '"'"'1 = account suspended'"'"'
      AFTER password_hash;
"'
```

Prerequisite: confirm neither column exists first: `SHOW COLUMNS FROM users LIKE 'password_hash';`

MySQL 8.4 compatibility: `ADD COLUMN` without `IF NOT EXISTS` is correct — `ADD COLUMN IF NOT EXISTS` is not valid MySQL syntax.

### 1b. `config.php` — new constants

Add after the existing `define('SAAS_MODE', ...)` line:

```php
define('GIGHIVE_AUTH_MODE', getenv('GIGHIVE_AUTH_MODE') ?: 'basic');
define('JWT_SECRET',        getenv('JWT_SECRET')        ?: '');
define('JWT_TTL_SECONDS',   (int)(getenv('JWT_TTL_SECONDS') ?: 2592000)); // 30 days
```

No literals anywhere else. `JWT_SECRET` must never have a hard-coded fallback in production; the empty string default will cause all token validations to fail, which is the safe failure mode.

**SonarQube note:** `JWT_SECRET` is never logged or echoed. RSPEC-2635 (sensitive data exposure) satisfied.

### 1c. `.env.j2` additions

```jinja2
GIGHIVE_AUTH_MODE={{ gighive_auth_mode | default('basic') }}
JWT_SECRET={{ jwt_secret }}
JWT_TTL_SECONDS={{ jwt_ttl_seconds | default(2592000) }}
```

`jwt_secret` has no default — it must be set explicitly in each environment's `secrets.yml` under ansible-vault. A missing variable causes Ansible to fail at template render time, which is the correct failure mode.

**group_vars to add in each environment's `secrets.yml`:**

```yaml
jwt_secret: "<generated-per-env-secret-min-32-chars>"
```

**group_vars to add in each environment's main `.yml` (e.g. `gighive2.yml`, `gighive.yml`, `prod.yml`):**

```yaml
gighive_auth_mode: "basic"         # Phase 1: basic (guards not yet deployed)
                                   # Phase 2+: change to "local" (PHP JWT guards active alongside Apache Basic Auth)
                                   # Phase 4 cutover: stays "local" (Apache Basic Auth removed, PHP is sole gatekeeper)
                                   # Phase 5+: change to "oidc"
jwt_ttl_seconds: 2592000           # 30 days
auth_mode_phase4_confirmed: false  # Set true ONLY after iOS Phase 3 is verified on all envs
```

All three environments (gighive2, gighive, prod) must have these vars. No hardcoding.

### 1d. `auth/jwt.php`

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT generation and validation for GigHive account-based auth.
 *
 * Uses HS256 throughout all phases (including Phase 5). Role values match the users.role enum:
 * 'owner' | 'contributor' | 'viewer'
 *
 * JWT_SECRET must be at least 32 characters. An empty secret causes
 * validate() to return null (fail-safe).
 */
final class JwtAuth
{
    private const ALGORITHM = 'HS256';
    private const ISSUER    = 'gighive';

    /**
     * Generate a signed JWT for a given user.
     *
     * @param int    $userId  users.id
     * @param string $role    'owner' | 'contributor' | 'viewer'
     * @param string $email   Display email; not used for auth decisions
     * @param int    $ttl     Seconds until expiry (default: JWT_TTL_SECONDS constant)
     */
    public static function generate(int $userId, string $role, string $email, int $ttl = 0): string
    {
        $secret = JWT_SECRET;
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET is not configured');
        }
        $now = time();
        $payload = [
            'iss'   => self::ISSUER,
            'sub'   => (string)$userId,
            'email' => $email,
            'role'  => $role,
            'iat'   => $now,
            'exp'   => $now + ($ttl > 0 ? $ttl : JWT_TTL_SECONDS),
        ];
        return JWT::encode($payload, $secret, self::ALGORITHM);
    }

    /**
     * Validate a JWT string.
     *
     * Returns the decoded payload array on success.
     * Returns null if the token is invalid, expired, or the secret is unset.
     * Callers MUST distinguish null (invalid) from a valid payload.
     *
     * @return array{sub: string, email: string, role: string, iat: int, exp: int, iss: string}|null
     */
    public static function validate(string $token): ?array
    {
        $secret = JWT_SECRET;
        if ($secret === '' || $token === '') {
            return null;
        }
        try {
            $decoded = JWT::decode($token, new Key($secret, self::ALGORITHM));
            $payload = (array)$decoded;
            // Enforce required fields before returning to callers
            if (!isset($payload['sub'], $payload['role'], $payload['exp'])) {
                return null;
            }
            return $payload;
        } catch (\Firebase\JWT\ExpiredException $e) {
            return null; // caller uses HTTP_AUTHORIZATION presence to distinguish expired vs invalid
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Returns the expiry reason string for use in api/verify.php.
     * 'token_expired' | 'invalid_token' | null (valid)
     */
    public static function validateWithReason(string $token): array
    {
        $secret = JWT_SECRET;
        if ($secret === '' || $token === '') {
            return [null, 'invalid_token'];
        }
        try {
            $decoded = JWT::decode($token, new Key($secret, self::ALGORITHM));
            $payload = (array)$decoded;
            if (!isset($payload['sub'], $payload['role'], $payload['exp'])) {
                return [null, 'invalid_token'];
            }
            return [$payload, null];
        } catch (\Firebase\JWT\ExpiredException $e) {
            return [null, 'token_expired'];
        } catch (\Throwable $e) {
            return [null, 'invalid_token'];
        }
    }
}
```

**SonarQube notes:**
- No force-unwrap equivalent in PHP — all array access uses `isset()` before access. RSPEC-6426 n/a (PHP).
- Cognitive complexity is low — single responsibility per method. RSPEC-3776 satisfied.
- No SQL in this file. RSPEC-2635 n/a.
- `JWT_SECRET` not logged. Sensitive data safe.

### 1e. `auth/helpers.php`

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/jwt.php';

/**
 * Role hierarchy constants.
 * Values must match the users.role enum in create_media_db.sql.
 */
const ROLE_LEVELS = [
    'viewer'      => 1,
    'contributor' => 2,
    'owner'       => 3,
];

/**
 * Extract and validate a Bearer JWT from the current request.
 * Returns the decoded payload or null.
 */
function currentJwtPayload(): ?array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authHeader, 'Bearer ')) {
        return null;
    }
    $token = substr($authHeader, 7);
    return JwtAuth::validate($token);
}

/**
 * Require that the current request carries a JWT with at least $minRole.
 * Sends 401 (no/invalid token) or 403 (insufficient role) and exits.
 *
 * Call at the top of any PHP page or endpoint that requires authentication.
 */
function requireRole(string $minRole): void
{
    $payload = currentJwtPayload();
    if ($payload === null) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unauthenticated']);
        exit;
    }
    $userLevel = ROLE_LEVELS[$payload['role'] ?? ''] ?? 0;
    $minLevel  = ROLE_LEVELS[$minRole] ?? 999;
    if ($userLevel < $minLevel) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
}

/**
 * Non-exiting variant for conditional logic inside a page.
 */
function hasRole(string $minRole): bool
{
    $payload = currentJwtPayload();
    if ($payload === null) {
        return false;
    }
    $userLevel = ROLE_LEVELS[$payload['role'] ?? ''] ?? 0;
    $minLevel  = ROLE_LEVELS[$minRole] ?? 999;
    return $userLevel >= $minLevel;
}
```

**Brittle coding note:** `ROLE_LEVELS` is the single source of truth for the hierarchy. Do not inline numeric comparisons anywhere else — always call `requireRole()` or `hasRole()`.

**Hardcoded path check:** `require_once __DIR__ . '/jwt.php'` is a relative path anchored to the file's own directory — this is correct and not deployment-specific. No group_var needed.

### 1f. `api/login.php`

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/jwt.php';

use Production\Api\Infrastructure\Database;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$body = (string)file_get_contents('php://input');
$data = json_decode($body, true);

$email    = trim((string)($data['email']    ?? ''));
$password = (string)($data['password'] ?? '');

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_fields']);
    exit;
}

// Basic email format guard (not a full RFC 5321 check — just prevents injection)
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_email']);
    exit;
}

try {
    $pdo = Database::createFromEnv();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
    exit;
}

// Lookup by email + idp_provider='local'. Use prepared statement.
$stmt = $pdo->prepare(
    'SELECT id, role, email, password_hash, disabled FROM users
     WHERE email = :email AND idp_provider = :provider
     LIMIT 1'
);
$stmt->execute([':email' => $email, ':provider' => 'local']);
$user = $stmt->fetch();

if ($user === false) {
    // Constant-time comparison even on not-found path (prevent timing oracle)
    password_verify('dummy', '$2y$12$invalidhashpadding000000000000000000000000000000000000000');
    http_response_code(401);
    echo json_encode(['error' => 'invalid_credentials']);
    exit;
}

if ((int)$user['disabled'] === 1) {
    http_response_code(403);
    echo json_encode(['error' => 'account_disabled']);
    exit;
}

if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_credentials']);
    exit;
}

try {
    $token = JwtAuth::generate((int)$user['id'], (string)$user['role'], (string)$user['email']);
} catch (\RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'token_generation_failed']);
    exit;
}

$expiresAt = date('Y-m-d\TH:i:s\Z', time() + JWT_TTL_SECONDS);

http_response_code(200);
echo json_encode([
    'token'      => $token,
    'role'       => $user['role'],
    'email'      => $user['email'],
    'expires_at' => $expiresAt,
]);
exit;
```

**Security notes:**
- PDO prepared statement with named parameters — no string interpolation near SQL. RSPEC-2635 satisfied.
- Constant-time password check on the not-found path prevents timing oracle attacks (attacker cannot distinguish "user not found" from "wrong password" via response time).
- `FILTER_VALIDATE_EMAIL` before DB query — input validated before DB access. RSPEC-2635 / secure coding satisfied.
- Password is not logged anywhere.

**SonarQube notes:**
- No force unwraps. Nulls handled with `?? ''` and explicit checks.
- Cognitive complexity: two sequential guard clauses + one credential check. Low. RSPEC-3776 satisfied.

### 1g. `api/verify.php`

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth/jwt.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!str_starts_with($authHeader, 'Bearer ')) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'error' => 'invalid_token']);
    exit;
}

$token = substr($authHeader, 7);
[$payload, $reason] = JwtAuth::validateWithReason($token);

if ($payload === null) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'error' => $reason]);
    exit;
}

$expiresAt = date('Y-m-d\TH:i:s\Z', (int)$payload['exp']);

http_response_code(200);
echo json_encode([
    'valid'      => true,
    'role'       => $payload['role'],
    'email'      => $payload['email'] ?? '',
    'expires_at' => $expiresAt,
]);
exit;
```

**iOS behavior contract:**
- `error: "token_expired"` → silent re-login flow (stored email/password or OIDC refresh)
- `error: "invalid_token"` → clear Keychain entry, present full login screen

---

## Phase 2 — PHP `requireRole()` Guards

Add the following at the top of each file, after its existing `require_once` lines. The `GIGHIVE_AUTH_MODE` check allows a graceful no-op during pre-Phase-1 deployments, but is dropped once Phase 1 is live.

**Pattern for viewer-level pages (`db/database.php`, `db/database_catalog.php`):**

```php
require_once __DIR__ . '/../auth/helpers.php';
if (GIGHIVE_AUTH_MODE !== 'basic') {
    requireRole('viewer');
}
```

**Pattern for contributor-level pages (`api/uploads.php`, `db/upload_form.php`, `db/delete_media_files.php`):**

```php
require_once __DIR__ . '/../auth/helpers.php';
if (GIGHIVE_AUTH_MODE !== 'basic') {
    requireRole('contributor');
}
```

**Pattern for owner-level pages (`db/upload_form_admin.php`, `admin/*.php`):**

```php
require_once __DIR__ . '/../auth/helpers.php';
if (GIGHIVE_AUTH_MODE !== 'basic') {
    requireRole('owner');
}
```

The `GIGHIVE_AUTH_MODE !== 'basic'` guard means:
- In `basic` mode (Phase 1 only): Apache handles auth as today, PHP guards are no-ops.
- In `local` mode (Phase 2 onward): PHP JWT guard is active alongside Apache Basic Auth during Phases 2–3; PHP is the sole gatekeeper from Phase 4 onward.
- In `oidc` mode (Phase 5+): PHP JWT guard remains active.

The mode transitions from `basic` → `local` when Phase 2 deploys, not at Phase 4. After Phase 4 removes Basic Auth, the mode is already `local`. The guard expression can be removed in a cleanup pass once all environments are past Phase 4.

**Brittle coding check:** Do not inline role-level integers in any of these files. Always call `requireRole()` from `auth/helpers.php`. This keeps the hierarchy definition in one place.

**Require path:** All `admin/*.php` files require from their directory. The path to `auth/helpers.php` from `admin/` is:

```php
require_once __DIR__ . '/../../auth/helpers.php';  // admin/ is one level deeper
```

Verify this path is correct relative to each file's location before committing.

---

## Phase 0 — iOS `AuthCredential` Refactor (prerequisite for Phase 3)

> **Full specification:** `feature_security_authentication_migration_jwt_ios_auth_cred_type.md`
>
> Phase 0 is a pure iOS refactor with no server-side changes. It must be completed and merged before Phase 3 begins. It:
> - Introduces `AuthCredential.swift` — an enum replacing the raw `(user: String, pass: String)` tuple
> - Replaces all seven duplicate `Authorization: Basic` header constructions with `credential?.apply(to:)`
> - Updates `AuthSession.credentials` → `AuthSession.credential: AuthCredential?`
> - Updates `UserRole`: removes `.admin`, adds `.contributor` and `.owner`
> - Retains `UploadClient`'s dual `sessionCredential:` + `uploadToken:` parameters (QR token and session credential are orthogonal)
> - Adds `KeychainStore.loadCredential(host:)` convenience without changing the on-disk format
>
> After Phase 0, Phase 3 changes only `LoginView`, `JWTStore` (new), and `SplashView` — the five network-client files require no further auth changes.

---

## Phase 3 — iOS Client JWT (Full Call-Site Chain)

> **Requires Phase 0 complete.** The `UserRole` enum, `AuthCredential` type, and seven Basic-header call sites are already updated. Phase 3 covers only the JWT login flow, token storage, and session restore.

### 3a. `UserRole` enum extension

> **Note:** `UserRole` is updated in Phase 0 (`AuthSession.swift` change). The full enum and `fromLegacyUsername` bridge documented here are the Phase 0 output — Phase 3 inherits them and does not repeat this step. Shown here for reference.

Current `AuthSession.swift` has `enum UserRole { case unknown, viewer, admin }`. Must add `contributor` and `owner` to match DB role names, and map the JWT `role` string:

```swift
enum UserRole: String {
    case unknown     = ""
    case viewer      = "viewer"
    case contributor = "contributor"
    case owner       = "owner"

    // Legacy Apache username → role mapping, used only during one-time Keychain migration
    static func fromLegacyUsername(_ username: String) -> UserRole {
        switch username.lowercased().trimmingCharacters(in: .whitespaces) {
        case "admin": return .owner
        default:      return .viewer
        }
    }
}
```

**iOS 14 compatibility:** `enum UserRole: String` with `rawValue` is available on all iOS versions. No `@available` guard needed.

### 3b. `AuthSession.swift` replacement

```swift
import Foundation
import SwiftUI

final class AuthSession: ObservableObject {
    @Published var baseURL: URL?
    @Published var token: String?
    @Published var expiresAt: Date?
    @Published var role: UserRole = .unknown
    @Published var allowInsecureTLS: Bool = false
    @Published var intendedRoute: AppRoute? = nil

    var isLoggedIn: Bool { token != nil }

    // Helper used by all API clients
    var bearerAuthHeader: String? {
        guard let t = token else { return nil }
        return "Bearer \(t)"
    }
}
```

**SonarQube:** No force unwraps. `bearerAuthHeader` returns `Optional<String>`; callers check for nil. RSPEC-6426 satisfied.

**Timing note:** `expiresAt` is checked client-side before calling `api/verify.php` to avoid a round-trip for obviously-expired tokens.

### 3c. `JWTStore.swift` (new file)

```swift
import Foundation
import Security

enum JWTStoreError: Error {
    case unexpectedStatus(OSStatus)
    case noData
    case decodingError
}

struct StoredToken {
    let token: String
    let role: UserRole
    let expiresAt: Date
}

enum JWTStore {
    private static let service = "com.gighive.jwt"

    private static func keyAttrs(host: String) -> [String: Any] {
        [
            kSecClass as String:       kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: host
        ]
    }

    static func save(token: String, host: String, role: UserRole, expiresAt: Date) throws {
        let payload: [String: Any] = [
            "token":      token,
            "role":       role.rawValue,
            "expires_at": expiresAt.timeIntervalSince1970
        ]
        let data = try JSONSerialization.data(withJSONObject: payload, options: [])
        var query = keyAttrs(host: host)
        SecItemDelete(query as CFDictionary)
        query[kSecValueData as String] = data
        let status = SecItemAdd(query as CFDictionary, nil)
        guard status == errSecSuccess else { throw JWTStoreError.unexpectedStatus(status) }
    }

    static func load(host: String) throws -> StoredToken? {
        var query = keyAttrs(host: host)
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        var item: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &item)
        if status == errSecItemNotFound { return nil }
        guard status == errSecSuccess, let data = item as? Data else {
            throw JWTStoreError.unexpectedStatus(status)
        }
        guard let dict = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let tokenStr = dict["token"] as? String,
              let roleStr  = dict["role"] as? String,
              let ts       = dict["expires_at"] as? TimeInterval else {
            throw JWTStoreError.decodingError
        }
        let role      = UserRole(rawValue: roleStr) ?? .viewer
        let expiresAt = Date(timeIntervalSince1970: ts)
        return StoredToken(token: tokenStr, role: role, expiresAt: expiresAt)
    }

    static func delete(host: String) throws {
        let status = SecItemDelete(keyAttrs(host: host) as CFDictionary)
        guard status == errSecSuccess || status == errSecItemNotFound else {
            throw JWTStoreError.unexpectedStatus(status)
        }
    }
}
```

**SonarQube:** No force unwraps — all optionals resolved via `guard let` or `?? .viewer` fallback. RSPEC-6426 satisfied. Token string is never logged.

### 3d. `LoginView.swift` replacement (key diff)

The full view structure (layout, Toggle, Cancel button) is preserved. Only the `signIn()` function changes:

```swift
private func signIn() async {
    errorMessage = nil
    isLoading = true
    defer { isLoading = false }

    let trimmed = base.trimmingCharacters(in: .whitespacesAndNewlines)
    let full = trimmed.hasPrefix("http") ? trimmed : "https://" + trimmed
    guard let baseURL = URL(string: full), baseURL.scheme?.hasPrefix("http") == true else {
        errorMessage = "Invalid URL"; return
    }

    guard let loginURL = URL(string: "\(full)/api/login.php") else {
        errorMessage = "Invalid server URL"; return
    }

    var request = URLRequest(url: loginURL)
    request.httpMethod = "POST"
    request.setValue("application/json", forHTTPHeaderField: "Content-Type")
    let body: [String: String] = ["email": username, "password": password]
    request.httpBody = try? JSONSerialization.data(withJSONObject: body)

    let cfg = URLSessionConfiguration.ephemeral
    let urlSession: URLSession = disableCertChecking
        ? URLSession(configuration: cfg, delegate: InsecureTrustDelegate.shared, delegateQueue: nil)
        : URLSession(configuration: cfg)

    do {
        let (data, response) = try await urlSession.data(for: request)
        guard let http = response as? HTTPURLResponse else {
            errorMessage = "Invalid server response"; return
        }
        logWithTimestamp("[Login] api/login.php HTTP \(http.statusCode)")
        switch http.statusCode {
        case 200:
            guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let token    = json["token"]      as? String,
                  let roleStr  = json["role"]        as? String,
                  let expiresStr = json["expires_at"] as? String else {
                errorMessage = "Unexpected server response"; return
            }
            let role = UserRole(rawValue: roleStr) ?? .viewer
            let expiresAt = ISO8601DateFormatter().date(from: expiresStr) ?? Date()

            session.baseURL         = baseURL
            session.token           = token
            session.expiresAt       = expiresAt
            session.role            = role
            session.allowInsecureTLS = disableCertChecking

            if let host = baseURL.host, !host.isEmpty {
                do {
                    if rememberOnDevice {
                        try JWTStore.save(token: token, host: host, role: role, expiresAt: expiresAt)
                        UserDefaults.standard.set(host, forKey: lastHostDefaultsKey)
                        logWithTimestamp("[Login] JWT saved to Keychain for host=\(host)")
                    } else {
                        try JWTStore.delete(host: host)
                        if UserDefaults.standard.string(forKey: lastHostDefaultsKey) == host {
                            UserDefaults.standard.removeObject(forKey: lastHostDefaultsKey)
                        }
                    }
                } catch {
                    logWithTimestamp("[Login] Keychain error: \(error.localizedDescription)")
                }
            }
            logWithTimestamp("[Login] Auth success role=\(role.rawValue); dismissing")
            dismissCompat()

        case 401:
            errorMessage = "Incorrect email or password"
        case 403:
            errorMessage = "Account is disabled"
        default:
            errorMessage = "Server error (\(http.statusCode))"
        }
    } catch {
        errorMessage = error.localizedDescription
        logWithTimestamp("[Login] Network error: \(error.localizedDescription)")
    }
}
```

The form fields change: `GHLabel(text: "USERNAME")` becomes `GHLabel(text: "EMAIL")` and the `NoAccessoryTextField` placeholder becomes `"you@example.com"` with `keyboardType: .emailAddress`.

**One-time Keychain migration on first launch:** In `onAppear`, attempt to read from `KeychainStore` (old format). If found and `JWTStore` is empty for the same host, prompt the user to re-login (do not silently convert — the old data is a password, not a token):

```swift
// In onAppear: detect old-format credential and clear it
if let host = URL(string: full)?.host,
   let _ = try? KeychainStore.load(host: host),
   (try? JWTStore.load(host: host)) == nil {
    // Old credential exists but no JWT — delete it and show clean login form
    try? KeychainStore.delete(host: host)
    logWithTimestamp("[Login] Cleared old Basic Auth keychain entry for host=\(host)")
}
```

**iOS 14 compatibility:** `URLSession.data(for:)` is available from iOS 15. Use `URLSession.data(from:)` with a completion handler bridged via `withCheckedThrowingContinuation` for iOS 14, or confirm minimum deployment target. Current project minimum is iOS 14.0 — this requires the continuation bridge:

```swift
// iOS 14-compatible async data fetch:
let (data, response) = try await withCheckedThrowingContinuation { cont in
    urlSession.dataTask(with: request) { data, response, error in
        if let error { cont.resume(throwing: error); return }
        guard let data, let response else {
            cont.resume(throwing: URLError(.badServerResponse)); return
        }
        cont.resume(returning: (data, response))
    }.resume()
}
```

**This is a hard compatibility requirement.** `URLSession.data(for:)` (async/await) is iOS 15+. All new async URLSession calls in this feature must use the continuation bridge for iOS 14.

### 3e. `SplashView.swift` changes

Replace all occurrences of `session.credentials` with `session.token` or `session.isLoggedIn`:

| Current | Replacement |
|---------|-------------|
| `session.credentials == nil` | `!session.isLoggedIn` |
| `session.credentials != nil` | `session.isLoggedIn` |
| `if let creds = session.credentials { Text("...as \(creds.user)") }` | `if let token = session.token { Text("Logged in") }` (token is not displayed; role or email from session is shown instead) |

The `isGuestOnly` computed property:

```swift
private var isGuestOnly: Bool {
    !session.isLoggedIn && !uploadRecords.isEmpty
}
```

### 3f. `DatabaseAPIClient.swift` changes

Replace `basicAuth:` parameter with `bearerToken:`:

```swift
final class DatabaseAPIClient {
    let baseURL: URL
    let bearerToken: String?   // replaces basicAuth
    let allowInsecure: Bool

    init(baseURL: URL, bearerToken: String?, allowInsecure: Bool = false) {
        self.baseURL     = baseURL
        self.bearerToken = bearerToken
        self.allowInsecure = allowInsecure
    }
    // ...
}
```

In `fetchMediaList()` and `deleteMediaFile()`, replace:

```swift
// OLD
if let auth = basicAuth {
    let credentials = "\(auth.user):\(auth.pass)"
    let base64 = Data(credentials.utf8).base64EncodedString()
    request.setValue("Basic \(base64)", forHTTPHeaderField: "Authorization")
}
// NEW
if let token = bearerToken {
    request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
}
```

Update log line: `authUser=\(basicAuth?.user ?? "<none>")` → `bearerToken=\(bearerToken != nil ? "<set>" : "<none>")` (never log the actual token).

**Call-site update — `DatabaseView.swift`:**

```swift
// OLD
let client = DatabaseAPIClient(baseURL: baseURL, basicAuth: session.credentials, allowInsecure: session.allowInsecureTLS)
// NEW
let client = DatabaseAPIClient(baseURL: baseURL, bearerToken: session.token, allowInsecure: session.allowInsecureTLS)
```

**PHP refactor rule:** `DatabaseAPIClient` is the only place that constructs a `DatabaseAPIClient`. Confirm with `grep -r "DatabaseAPIClient(" GigHive/Sources/` before marking complete.

### 3g. `MediaPlayerView.swift` and `MediaResourceLoader.swift` changes

`MediaPlayerView` holds `let credentials: (user: String, pass: String)?`. Replace with `let token: String?`.

The existing `headers["Authorization"] = "Basic \(token)"` block at line 449–451 becomes:

```swift
if let t = token {
    headers["Authorization"] = "Bearer \(t)"
}
```

`MediaResourceLoader.init` changes from `credentials: (user: String, pass: String)?` to `token: String?`. The `Basic` header construction at line 73–74 becomes:

```swift
if let t = token {
    req.setValue("Bearer \(t)", forHTTPHeaderField: "Authorization")
}
```

**Call-site update — `DatabaseDetailView.swift` line 46:**

```swift
// OLD
credentials: session.credentials,
// NEW
token: session.token,
```

**Call-site update — `MediaPlayerView.swift` line 475:**

```swift
// OLD
let loader = MediaResourceLoader(allowInsecureTLS: allowInsecureTLS, credentials: credentials)
// NEW
let loader = MediaResourceLoader(allowInsecureTLS: allowInsecureTLS, token: token)
```

**Phase 0 verification:** Run `grep -r "basicAuth" GigHive/Sources/` and `grep -r "credentials:" GigHive/Sources/` — both must return zero results after Phase 0 merges. `MediaResourceLoader(` call sites must use `credential:` not `credentials:`.

### 3h. `TUSUploadClient.swift` changes

The `headersBlock` in `init`:

```swift
// OLD
} else if let basicAuth {
    let credentials = "\(basicAuth.user):\(basicAuth.pass)"
    let encoded = Data(credentials.utf8).base64EncodedString()
    mutated["Authorization"] = "Basic \(encoded)"
}
// NEW
} else if let bearerToken {
    mutated["Authorization"] = "Bearer \(bearerToken)"
}
```

Constructor parameter `basicAuth: (user: String, pass: String)?` → `bearerToken: String?`.

QR `uploadToken` branch is unchanged (highest priority, checked first).

**Call-site:** Locate wherever `TUSUploadClient` is instantiated with `basicAuth:` and pass `bearerToken: session.token` instead. Run `grep -r "TUSUploadClient(" GigHive/Sources/`.

---

## Phase 4 — Apache Basic Auth Removal + `tus-upload.php` PHP Auth

**This is an atomic deployment.** Both changes must deploy together:

### 4a. `default-ssl.conf.j2` — remove Basic Auth blocks

Remove all blocks matching:

```
AuthType Basic
AuthName "..."
AuthBasicProvider file
AuthUserFile ...
Require valid-user
Require user admin [uploader]
```

Retain:
- All `AuthMerging Off` + `Require all granted` blocks (QR guest paths)
- All `SetEnvIf` directives (`upload_token_auth`, `gallery_nonce_auth`, `HTTP_AUTHORIZATION`)
- All `Require all denied` blocks (sensitive paths)
- All `SecRequestBodyLimit` and `SecRuleEngine` directives (TUS ModSecurity)

Also remove the `media-stream.php` `AuthType Basic` block (replace with the existing `AuthMerging Off` + `Require all granted` only — PHP does all auth).

### 4b. `api/tus-upload.php` — add PHP-side JWT guard

Insert after the OPTIONS block and before the `$userId = 0` line:

```php
// -------------------------------------------------------------------------
// Auth: Phase 4+ — Apache Basic Auth is removed; PHP enforces access.
//   Path 1: JWT Bearer — account-based uploads (owner / contributor)
//   Path 2: QR upload token (X-Upload-Token) — handled below, unchanged
// -------------------------------------------------------------------------
$tusAuthHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$tusRawToken   = $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? '';

if ($tusRawToken === '') {
    // No QR token — must be a JWT Bearer request
    if (!str_starts_with($tusAuthHeader, 'Bearer ')) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unauthenticated']);
        exit;
    }
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../auth/jwt.php';
    $tusToken   = substr($tusAuthHeader, 7);
    $tusPayload = JwtAuth::validate($tusToken);
    if ($tusPayload === null) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_token']);
        exit;
    }
    $allowedRoles = ['owner', 'contributor'];
    if (!in_array($tusPayload['role'] ?? '', $allowedRoles, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    // $userId stays 0 — same as the existing Basic Auth behaviour
}
```

**GIGHIVE_AUTH_MODE guard:** This block is only reached after Phase 4 removes Apache auth. During Phases 1–3, Apache still enforces `Require user admin uploader` before PHP runs, so this code block is harmless but unreachable for non-QR requests. No mode guard needed in `tus-upload.php` — the Apache config change is what gates Phase 4.

### 4c. `api/media-stream.php` — swap Basic Auth path

In `authenticateRequest()`, replace Path 1:

```php
// OLD — Trust Basic Auth forwarded by Apache (remove in Phase 4)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Basic ')) {
    return true;
}

// NEW — JWT Bearer validation (Phase 4+)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $token   = substr($authHeader, 7);
    $payload = JwtAuth::validate($token);
    return $payload !== null;
}
```

Add `require_once __DIR__ . '/../auth/jwt.php';` at the top of the file.

**Media streaming compatibility note:** `media-stream.php` also handles byte-range requests from `MediaResourceLoader.swift` (via AVPlayer). The `Authorization: Bearer` header is set in `MediaResourceLoader` after Phase 3. Range requests work the same — the auth check runs once at the start of the request regardless of byte range.

---

## DDL Summary

All schema changes are two `ALTER TABLE` columns on the existing `users` table. They are additive and backward-compatible (both nullable/default-safe).

**Live command for all existing environments:**

```bash
docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "
ALTER TABLE users
  ADD COLUMN password_hash varchar(255) DEFAULT NULL
      COMMENT '"'"'bcrypt hash; NULL for OIDC-only users'"'"'
      AFTER idp_subject,
  ADD COLUMN disabled tinyint(1) NOT NULL DEFAULT 0
      COMMENT '"'"'1 = account suspended'"'"'
      AFTER password_hash;
"'
```

Run this **before** deploying Phase 1 code. If the columns already exist (e.g. applied on dev before staging), MySQL will return `ERROR 1060 (42S21): Duplicate column name` — that is safe; it means the migration was already applied.

**Seed the initial owner account** (run after Phase 1 deploy on each environment):

```bash
# Generate hash first (run on any PHP 8.3 system):
# php -r "echo password_hash('REPLACE_ME', PASSWORD_BCRYPT, ['cost'=>12]);"

docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "
INSERT INTO users (tenant_id, idp_provider, idp_subject, role, email, password_hash)
VALUES (1, '"'"'local'"'"', NULL, '"'"'owner'"'"', '"'"'admin@gighive.local'"'"', '"'"'HASH_HERE'"'"')
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role);
"'
# Note: idp_subject is NULL for local accounts. It stores the IdP sub/oid claim and has
# no meaning for password-based local users. MySQL allows multiple NULLs in a UNIQUE KEY,
# so multiple local accounts are correctly supported with idp_subject = NULL.
```

Store the plaintext password in ansible-vault under `group_vars/<env>/secrets.yml` as `gighive_local_admin_password`. Do not store the hash there — generate it at seed time.

---

## Smoke Tests (`post_build_checks/tasks/main.yml`)

Add the following tasks. Use existing `[T-NN]` numbering — assign next available IDs (T-98 onward based on T-97 being the last existing test):

```yaml
# --- Auth Migration Smoke Tests ---

- name: "[T-98] GET /api/login.php returns 405 (POST only)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/login.php"
    method: GET
    status_code: 405
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  tags: [smoke]

- name: "[T-99] POST /api/login.php with no body returns 400"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/login.php"
    method: POST
    headers:
      Content-Type: application/json
    body: "{}"
    body_format: raw
    status_code: 400
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  tags: [smoke]

- name: "[T-100] POST /api/login.php with wrong password returns 401"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/login.php"
    method: POST
    headers:
      Content-Type: application/json
    body: '{"email":"admin@gighive.local","password":"definitelywrong"}'
    body_format: raw
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  tags: [smoke]

- name: "[T-101] GET /api/verify.php with no token returns 401"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/verify.php"
    method: GET
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  tags: [smoke]

- name: "[T-102] GET /api/verify.php with tampered token returns 401"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/verify.php"
    method: GET
    headers:
      Authorization: "Bearer eyJhbGciOiJIUzI1NiJ9.dGFtcGVyZWQ.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  register: t102_resp
  tags: [smoke]

- name: "[T-102a] Assert tampered token returns invalid_token error"
  ansible.builtin.assert:
    that:
      - t102_resp.json is mapping
      - t102_resp.json.valid == false
      - t102_resp.json.error == "invalid_token"
  tags: [smoke]

- name: "[T-103] GET /db/database.php with no auth returns 401 (JWT guard active)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/db/database.php?format=json"
    method: GET
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode != 'basic'
  tags: [smoke]

- name: "[T-104] POST /files/ with no auth returns 401 (Phase 4 — PHP TUS auth)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/files/"
    method: POST
    headers:
      Tus-Resumable: "1.0.0"
      Content-Length: "0"
      Upload-Length: "1024"
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode != 'basic'
  tags: [smoke]

# --- Phase 1: Positive login path ---

- name: "[T-112] POST /api/login.php with valid credentials returns 200 and token"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/login.php"
    method: POST
    headers:
      Content-Type: application/json
    body: '{"email":"{{ gighive_smoke_owner_email }}","password":"{{ gighive_smoke_owner_password }}"}'
    body_format: raw
    status_code: 200
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  register: t112_resp
  when: gighive_auth_mode != 'basic'
  tags: [smoke]

- name: "[T-112a] Assert login response contains token, role, and expires_at"
  ansible.builtin.assert:
    that:
      - t112_resp.json is mapping
      - t112_resp.json.token is string
      - t112_resp.json.token | length > 0
      - t112_resp.json.role == "owner"
      - t112_resp.json.expires_at is string
  when: gighive_auth_mode != 'basic'
  tags: [smoke]

# Note: gighive_smoke_owner_email and gighive_smoke_owner_password must be set in
# group_vars/<env>/secrets.yml (ansible-vault). Use the seeded local owner account.
# Never put real credentials in plaintext group_vars.

# --- Phase 1: Disabled user blocked ---

- name: "[T-113] POST /api/login.php with disabled user returns 403"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/login.php"
    method: POST
    headers:
      Content-Type: application/json
    body: '{"email":"{{ gighive_smoke_disabled_email }}","password":"{{ gighive_smoke_disabled_password }}"}'
    body_format: raw
    status_code: 403
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  register: t113_resp
  when:
    - gighive_auth_mode != 'basic'
    - gighive_smoke_disabled_email is defined
  tags: [smoke]

- name: "[T-113a] Assert disabled login response contains account_disabled error"
  ansible.builtin.assert:
    that:
      - t113_resp.json.error == "account_disabled"
  when:
    - gighive_auth_mode != 'basic'
    - gighive_smoke_disabled_email is defined
  tags: [smoke]

# --- Phase 1: verify.php positive path and expired-token distinction ---

- name: "[T-114] GET /api/verify.php with valid token returns 200 and valid:true"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/verify.php"
    method: GET
    headers:
      Authorization: "Bearer {{ t112_resp.json.token }}"
    status_code: 200
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  register: t114_resp
  when:
    - gighive_auth_mode != 'basic'
    - t112_resp is defined
  tags: [smoke]

- name: "[T-114a] Assert verify response contains valid:true, role, and expires_at"
  ansible.builtin.assert:
    that:
      - t114_resp.json.valid == true
      - t114_resp.json.role == "owner"
      - t114_resp.json.expires_at is string
  when:
    - gighive_auth_mode != 'basic'
    - t114_resp is defined
  tags: [smoke]

- name: "[T-115] GET /api/verify.php with structurally valid but expired token returns 401 token_expired"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/verify.php"
    method: GET
    headers:
      # Pre-generated HS256 JWT with exp=1 (1970-01-01). Secret is irrelevant — expiry is checked first.
      # Replace with a short-TTL token generated at test-setup time if the secret is known.
      Authorization: "Bearer {{ gighive_smoke_expired_token }}"
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  register: t115_resp
  when:
    - gighive_auth_mode != 'basic'
    - gighive_smoke_expired_token is defined
  tags: [smoke]

- name: "[T-115a] Assert expired-token response returns token_expired (not invalid_token)"
  ansible.builtin.assert:
    that:
      - t115_resp.json.valid == false
      - t115_resp.json.error == "token_expired"
  when:
    - gighive_auth_mode != 'basic'
    - gighive_smoke_expired_token is defined
  tags: [smoke]

# Note: gighive_smoke_expired_token should be a pre-signed token (using the env jwt_secret)
# with exp set to a past timestamp. Generate at provisioning time and store in group_vars.

# --- Phase 2: Role hierarchy ---

- name: "[T-116] GET /db/database.php with owner JWT passes viewer-level guard (role inheritance)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/db/database.php?format=json"
    method: GET
    headers:
      Authorization: "Bearer {{ t112_resp.json.token }}"
    status_code: 200
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when:
    - gighive_auth_mode != 'basic'
    - t112_resp is defined
  tags: [smoke]

- name: "[T-117] POST /api/uploads.php with viewer JWT returns 403 (insufficient role)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/uploads.php"
    method: POST
    headers:
      Authorization: "Bearer {{ gighive_smoke_viewer_token }}"
      Content-Type: application/json
    body: "{}"
    body_format: raw
    status_code: 403
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when:
    - gighive_auth_mode != 'basic'
    - gighive_smoke_viewer_token is defined
  tags: [smoke]

- name: "[T-118] GET /admin/admin.php with no auth returns 401"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/admin/admin.php"
    method: GET
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode != 'basic'
  tags: [smoke]

# Note: gighive_smoke_viewer_token should be a pre-signed token with role=viewer.
# Generate at provisioning time alongside gighive_smoke_expired_token and store in group_vars.

# --- Phase 2: QR guest regression (must pass in every mode, every phase) ---

- name: "[T-119] GET /api/guest-gallery.php without auth returns non-401 (QR path unaffected)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-gallery.php?nonce={{ gighive_smoke_gallery_nonce }}"
    method: GET
    status_code: [200, 400, 404]   # 400/404 if nonce is invalid/expired; 401 would indicate regression
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_smoke_gallery_nonce is defined
  tags: [smoke, qr_regression]

- name: "[T-120] GET /api/upload-token.php without auth returns non-401 (QR path unaffected)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/upload-token.php"
    method: GET
    status_code: [200, 400, 404, 405]   # Any non-401 confirms QR path is not auth-blocked
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  tags: [smoke, qr_regression]

# --- Phase 4: media-stream.php auth cutover ---

- name: "[T-121] GET /api/media-stream.php with no auth returns 401 (Phase 4 — Basic Auth removed)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/media-stream.php?id=1"
    method: GET
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode != 'basic'
  tags: [smoke]

- name: "[T-122] GET /api/media-stream.php with valid Bearer token returns non-401"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/media-stream.php?id=1"
    method: GET
    headers:
      Authorization: "Bearer {{ t112_resp.json.token }}"
    status_code: [200, 206, 404]   # 404 if asset id=1 doesn't exist; anything but 401/403
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when:
    - gighive_auth_mode != 'basic'
    - t112_resp is defined
  tags: [smoke]

# --- Phase 4: Basic Auth explicitly rejected ---

- name: "[T-123] GET /db/database.php with Basic Auth header returns 401 (Phase 4 — Basic removed)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/db/database.php?format=json"
    method: GET
    headers:
      Authorization: "Basic YWRtaW46cGFzc3dvcmQ="   # admin:password (base64) — known invalid after Phase 4
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode != 'basic'
  tags: [smoke]

# --- Phase 1: jwt_secret minimum length assertion (pre-deploy gate) ---

- name: "[T-124] Assert jwt_secret meets minimum 32-character length"
  ansible.builtin.assert:
    that:
      - jwt_secret is defined
      - jwt_secret | length >= 32
    fail_msg: "jwt_secret must be at least 32 characters. Set it in group_vars/<env>/secrets.yml under ansible-vault."
  tags: [smoke, pre_deploy]
```

**Ansible best-practice notes:**
- All tasks use `ansible.builtin.uri` (preferred module over shell + curl). SKILL.md rule satisfied.
- `validate_certs` driven by group_var `gighive_validate_certs`, not hardcoded.
- `when: gighive_auth_mode != 'basic'` gates Phase 2+ tests so they don't fail on pre-migration environments.
- No credentials appear in the task file — T-100 uses a known-wrong password for a negative test.

---

## SonarQube / Best-Practice Notes Summary

| Issue | Location | Status |
|-------|----------|--------|
| RSPEC-6426 (force unwrap) | All Swift files | None introduced — all optionals use `guard let`, `??`, or explicit nil checks |
| RSPEC-3776 (cognitive complexity) | `auth/jwt.php`, `auth/helpers.php`, `api/login.php` | All functions are single-responsibility; complexity kept low |
| RSPEC-2635 (SQL injection) | `api/login.php` | PDO prepared statement with named params; no string interpolation |
| RSPEC-2635 (sensitive data) | All files | `JWT_SECRET` and tokens never logged or echoed |
| Hardcoded paths | `auth/helpers.php`, `auth/jwt.php` | `__DIR__`-relative requires only — not deployment-specific |
| Hardcoded paths | `Dockerfile.j2` | `gighive_php_version` already a group_var; no new literals |
| Brittle code — duplicated auth logic | All PHP files | Single `requireRole()` function; no inline role checks |
| Brittle code — magic strings | `ROLE_LEVELS` constant | Central definition in `auth/helpers.php`; referenced everywhere |
| iOS 14 compatibility | `LoginView.swift` | `URLSession.data(for:)` is iOS 15+; must use continuation bridge |
| No JWT library in composer.json | `auth/jwt.php` | `firebase/php-jwt ^6.10` must be added before implementing |

---

## Hardcoded Path Audit

| Path | File | Status |
|------|------|--------|
| `/var/www/html/audio` | `.env.j2` (via `media_local_audio_dir`) | OK — group_var, not hardcoded |
| `/tmp/tus-staging` | `.env.j2` (via `tus_local_staging_dir`) | OK — group_var |
| `com.gighive.jwt` | `JWTStore.swift` (Keychain service name) | Acceptable — app-level constant, not deployment-specific |
| `com.gighive.credentials` | `KeychainStore.swift` | Existing; not changed by this feature |
| `gh_last_host` | `LoginView.swift` | Existing UserDefaults key; not changed |

No new hardcoded deployment-specific paths are introduced by this feature.

---

## Timing and Sequencing

| Step | Must complete before |
|------|---------------------|
| `firebase/php-jwt` added to `composer.json` + `composer.lock` | Any PHP auth code can deploy |
| ALTER TABLE on all environments | Phase 1 deploy |
| Phase 1 verified on dev + lab | Phase 2 deploy |
| Phase 2 verified on dev + lab + staging | Phase 0 iOS build (can run in parallel with server phases) |
| Phase 0 iOS build merged | Phase 3 iOS build |
| Phase 3 iOS build verified on all environments | Phase 4 deploy |
| `auth_mode_phase4_confirmed: true` set in group_vars | Phase 4 playbook runs |
| Phase 4 deploys `default-ssl.conf.j2` AND `tus-upload.php` changes | In the same playbook run — they are atomic |

**Race condition:** There is no server-side race between Phase 3 and Phase 4 because the server accepts both Basic and Bearer during Phases 1–3. The iOS app cannot send Basic Auth headers after Phase 3 ships (they're removed from the client). If Phase 4 deploys before the iOS Phase 3 build reaches all users, those users get 401 on every API call. This is prevented by the `auth_mode_phase4_confirmed` gate.

---

## Resiliency, Security, and Operability

### Resiliency
- **JWT secret rotation:** Change `jwt_secret` in ansible-vault, re-run Ansible. All existing JWTs immediately become invalid — all users must re-login. Acceptable given 30-day TTL; communicate before rotating. No server-side revocation list needed for Phase 1–4 (Phase 5 can add one).
- **Disabled user mid-session:** `users.disabled` is checked at login only. A token already issued to a disabled user remains valid until expiry. For Phase 1–4 (no customers), this is acceptable. Add `api/verify.php` call from iOS on each app launch to detect disabling earlier.
- **DB unavailable at login:** `api/login.php` returns 500. Existing valid JWTs continue working for all other endpoints (JWT validation is stateless, no DB required). No outage for logged-in users.

### Security
- **Rate limiting on `api/login.php`:** No Apache `mod_ratelimit` or PHP rate limiter currently exists. For Phase 1 (internal users only), this is acceptable. **Before any public-facing deployment, add IP-based rate limiting** — either via Apache `mod_ratelimit` or a PHP in-memory counter using APCu (which is already installed in the container via `php-apcu`). Document as a follow-on task.
- **CSRF:** `api/login.php` and `api/verify.php` are JSON-body POST/GET endpoints used by the iOS app via `URLSession`. No browser form submission — no CSRF surface. If these endpoints are ever called from a browser form, add CSRF tokens.
- **JWT algorithm confusion:** `firebase/php-jwt` validates that the `alg` header matches the expected algorithm. `JwtAuth::validate()` uses `new Key($secret, 'HS256')` — only HS256 tokens are accepted. An attacker cannot forge an RS256 token and have it accepted. Algorithm confusion attack mitigated.
- **`none` algorithm attack:** `firebase/php-jwt ^6.x` rejects `alg: none` by design. Confirmed by library documentation.

### Operability
- **Logging:** `api/login.php` logs `[login] success role=owner email=admin@...` and `[login] failure` (without the attempted password) to PHP FPM log (`/var/log/fpm-php.www.log`). iOS logs `[Login] api/login.php HTTP 200/401/403`. Both observable in existing log infrastructure.
- **Token TTL observability:** `api/verify.php` returns `expires_at` so operators can confirm TTL is applied correctly. No server-side dashboard needed for Phase 1.
- **Runbook for locked-out admin:** If the admin loses JWT access (e.g. misconfigured secret), recover via: (1) `GIGHIVE_AUTH_MODE=basic` + Ansible run (restores htpasswd auth), (2) fix the issue, (3) re-deploy `local` mode.

---

## Full Execution Trace

### Normal login flow (Phase 3+)

1. iOS user opens app → `SplashView.onAppear`
2. `JWTStore.load(host:)` → `StoredToken` with `expiresAt`
3. If `expiresAt > Date()` → set `session.token`, `session.role`, navigate normally
4. If expired → call `GET /api/verify.php` → `token_expired` → clear `session.token`, present `LoginView`
5. User enters email + password → `POST /api/login.php` → 200 + JWT
6. `JWTStore.save(...)` → `session.token` set → `LoginView` dismisses
7. `DatabaseView` loads: `DatabaseAPIClient(bearerToken: session.token)` → `GET /db/database.php` with `Authorization: Bearer` → 200
8. User taps media → `DatabaseDetailView` → `MediaPlayerView(token: session.token)` → `MediaResourceLoader(token:)` → byte-range GET with `Authorization: Bearer`
9. User uploads → `TUSUploadClient(bearerToken: session.token)` → `POST /files/` with `Authorization: Bearer` → 201

### Error flow — expired token

1. `GET /api/verify.php` → 401 `token_expired`
2. iOS clears `session.token`; presents `LoginView`
3. User re-logs in → new JWT stored
4. No data loss; in-progress uploads fail (TUSKit resumable — retry after re-login)

### Error flow — invalid/tampered token

1. `GET /api/verify.php` → 401 `invalid_token`
2. iOS calls `JWTStore.delete(host:)` — clears Keychain
3. Presents `LoginView` with clean state
4. Log: `[Login] Clearing invalid token for host=...`

### Error flow — Phase 4 deployed before iOS Phase 3

1. iOS sends `Authorization: Basic ...`
2. Apache no longer validates — PHP receives header but `str_starts_with($authHeader, 'Bearer ')` is false
3. All protected PHP endpoints return 401
4. Recovery: revert `default-ssl.conf.j2`, run Ansible — one playbook run

### QR upload flow (unchanged throughout all phases)

1. iOS QR scan → `GuestUploadSession` → `TUSUploadClient(uploadToken:)` → `X-Upload-Token` header
2. Apache `Require env upload_token_auth` passes (QR block, `AuthMerging Off`)
3. `tus-upload.php`: `$tusRawToken !== ''` → QR path → `UploadTokenValidator::validate()` → `$userId = tokenId`
4. Never touches JWT code path

---

## Progress

### Completed
- Feature doc reviewed and corrected (parent doc)
- Implementation doc written with PPRR applied

### Remaining — This Feature (Phase 1–4)
- [ ] Add `firebase/php-jwt ^6.10` to `composer.json` + `composer.lock`
- [ ] Update `create_media_db.sql` (add `password_hash`, `disabled` columns)
- [ ] Apply ALTER TABLE on dev; verify with `SHOW COLUMNS FROM users`
- [ ] Implement `auth/jwt.php`
- [ ] Implement `auth/helpers.php`
- [ ] Implement `api/login.php`
- [ ] Implement `api/verify.php`
- [ ] Update `config.php` (new constants)
- [ ] Update `.env.j2` (new vars)
- [ ] Add `gighive_auth_mode`, `jwt_ttl_seconds`, `auth_mode_phase4_confirmed` to all group_vars
- [ ] Add `jwt_secret` to all secrets.yml (ansible-vault)
- [ ] Add `requireRole()` guards to all PHP pages (Phase 2)
- [ ] **Phase 0 (iOS refactor — prerequisite; full checklist in `feature_security_authentication_migration_jwt_ios_auth_cred_type.md`):**
  - [ ] Create `AuthCredential.swift` (enum + `apply(to: URLRequest)` + `apply(to: [String:String])` + `displayUser`)
  - [ ] Update `AuthSession.swift` (`credential: AuthCredential?`; `UserRole` enum — `.admin` → `.owner`, add `.contributor`)
  - [ ] Update all seven Basic-header sites: `DatabaseAPIClient` (×2), `TUSUploadClient`, `UploadClient`, `MediaResourceLoader`, `MediaPlayerView` (×2 — proxy + AVURLAsset paths), `NetworkProgressUploadClient`
  - [ ] Update `SplashView`, `DatabaseView`, `DatabaseDetailView`, `UploadView` credential references
  - [ ] Add `KeychainStore.loadCredential(host:)` convenience
  - [ ] Build + smoke test (QR upload, login + DB view); zero compile errors
- [ ] **Phase 3 (JWT login — after Phase 0 merged):**
- [ ] Implement `JWTStore.swift`
- [ ] Update `LoginView.swift` (iOS 14 async bridge, email field, JWT response parsing, set `session.credential = .bearer(token:)`)
- [ ] Update `SplashView.swift` (restore session from `JWTStore` on launch)
- [ ] Deprecate `KeychainStore.swift`; add one-time migration read in `LoginView.onAppear`
- [ ] Phase 4: update `default-ssl.conf.j2` (remove Basic Auth blocks)
- [ ] Phase 4: update `api/tus-upload.php` (add PHP JWT guard)
- [ ] Phase 4: update `api/media-stream.php` (Basic → Bearer in `authenticateRequest()`)
- [ ] Add T-98 through T-124 smoke tests to `post_build_checks/tasks/main.yml`
- [ ] Add `gighive_smoke_owner_email`, `gighive_smoke_owner_password` to each env's `secrets.yml` (ansible-vault)
- [ ] Add `gighive_smoke_disabled_email`, `gighive_smoke_disabled_password` to each env's `secrets.yml` (ansible-vault) — requires a seeded disabled account
- [ ] Add `gighive_smoke_expired_token` to each env's `secrets.yml` — pre-signed JWT with past `exp`
- [ ] Add `gighive_smoke_viewer_token` to each env's `secrets.yml` — pre-signed JWT with `role=viewer`
- [ ] Add `gighive_smoke_gallery_nonce` to each env's group_vars — a valid or known-invalid gallery nonce for QR regression
- [ ] Seed initial owner account on each environment

### Remaining — Follow-on Tasks
- [ ] **Rate limiting on `api/login.php`** — APCu-based IP counter before any public deployment
- [ ] **Phase 5 OIDC** — Google + Microsoft/AAD; `api/oidc/callback.php`, `api/oidc/token-exchange.php`, `OIDCLoginView.swift`; requires separate implementation doc
- [ ] **Phase 6 — User management UI + audit log + self-service account deletion** (requires Phase 5 live):
  - [ ] `CREATE TABLE security_audit_log` — run DDL on all environments; update `create_media_db.sql`
  - [ ] `admin/users.php` — owner-only user list, role-change, disable/enable, delete (with `user_deleted` audit event); audit log second tab
  - [ ] `api/account/delete.php` — self-service account deletion endpoint; JWT-authenticated DELETE; hard-deletes caller's `users` row; writes `self_account_deleted` audit event; blocks last-owner deletion with 409 `last_owner_cannot_delete`; records `superadmin_notified` in audit detail when a non-last owner self-deletes
  - [ ] iOS Settings screen — "Delete my account" row; confirmation alert; calls `DELETE /api/account/delete.php`; clears `JWTStore` + `session.credential` on 200; shows 409 error alert without clearing session
  - [ ] Web settings page `account/delete.php` — authenticated page; confirmation form POST; calls `api/account/delete.php`; redirects to login on success; shows error on 409
  - [ ] Smoke tests: self-delete viewer, self-delete contributor, self-delete non-last owner, last-owner blocked (409), unauthenticated (401), audit row survival
  - [ ] See strategic doc for full test matrix and risk table
- [ ] **Server-side token revocation table** — if audit requirements emerge post-OIDC
- [ ] **Remove `GIGHIVE_AUTH_MODE !== 'basic'` guards** from PHP files — cleanup pass after all environments are past Phase 4
- [ ] **Remove `KeychainStore.swift`** — after migration period confirmed complete across all active installs
