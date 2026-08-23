# Feature: Federated Auth — Phase 5 OIDC Implementation

**Status:** Pre-implementation — pending Phase 4 completion  
**Date:** 2026-08-19  
**Parent doc:** `docs/feature_security_authentication_migration_jwt.md`  
**Phases 1–4 doc:** `docs/feature_security_authentication_migration_jwt_implementation.md`

---

## Elevator Pitch

Phase 4 gave every user an individual GigHive account with their own password. Phase 5 removes the need for a GigHive-specific password entirely: users sign in with their existing Google or Microsoft identity, and GigHive maps their IdP group membership to a role. No new passwords to manage, no shared accounts, and organizations can provision and deprovision access through their own identity infrastructure.

Local-user login (`api/login.php`) remains active alongside OIDC — it is the operator's fallback if an IdP goes down.

---

## Scope

This document covers Phase 5 only: OIDC federation with Google OAuth2/OIDC and Microsoft Entra ID (AAD). It assumes Phase 4 is fully deployed and verified — Apache Basic Auth is gone, PHP JWT guards are the sole auth layer.

**Not in scope:**
- Apple Sign-In (potential Phase 6)
- Server-side token revocation table
- Account linking UI (same user in both Google and Microsoft)
- Keycloak intermediary setup (operator option, documented separately)

---

## How Phase 5 Works — Architecture Overview

```
                 ┌─────────────────────────────────────────────┐
                 │                GigHive Server                │
                 │                                             │
  Browser ──────▶│  Apache + mod_auth_openidc                  │
                 │  ├── /oidc/callback  ──▶ api/oidc/callback.php
                 │  └── all other paths: pass-through          │
                 │                                             │
                 │  PHP JWT layer (unchanged from Phase 4)      │
                 │  └── auth/jwt.php validates Bearer tokens    │
                 │                                             │
  iOS app ──────▶│  api/oidc/token-exchange.php                │
                 │  └── PKCE code exchange; returns GigHive JWT │
                 └─────────────────────────────────────────────┘
                           │               │
                 ┌──────────┘               └──────────┐
                 ▼                                     ▼
       Google OAuth2/OIDC                  Microsoft Entra ID
  accounts.google.com/.well-known    login.microsoftonline.com/{tenant}
```

**Key design choices:**
- GigHive **always issues its own JWT** — neither Google nor Microsoft tokens reach the PHP API layer. The IdP token is consumed by the server and exchanged for a GigHive JWT with the same `{sub, role, email, iss, iat, exp}` payload as a local-user JWT. PHP validation code (`auth/jwt.php`) is unchanged.
- `mod_auth_openidc` handles the browser OIDC flow (authorization code redirect). The iOS app uses a pure PKCE flow via `ASWebAuthenticationSession` — no Apache involvement for mobile.
- Role is determined server-side from IdP group claims, with a configurable group→role mapping. The user cannot influence their own role.
- Local-user login (`POST /api/login.php`) stays active. `GIGHIVE_AUTH_MODE=oidc` does **not** disable it.
- QR guest paths are untouched. They have never intersected with account auth and never will.

---

## Two Login Paths in Phase 5

### Path A — Browser (web UI)

1. User visits a protected page (e.g. `/admin/admin.php`)
2. Apache `mod_auth_openidc` detects no OIDC session cookie → redirects to IdP authorization endpoint
3. IdP authenticates user (with MFA if configured), redirects back to `https://<host>/oidc/callback` with an authorization code
4. `mod_auth_openidc` exchanges code for IdP tokens; sets an encrypted session cookie; makes IdP claims available as Apache environment variables (e.g. `OIDC_CLAIM_sub`, `OIDC_CLAIM_email`, `OIDC_CLAIM_groups`)
5. Apache proxies the request to `api/oidc/callback.php` with claims in the environment
6. PHP upserts the `users` row, maps IdP groups to a GigHive role, generates a GigHive JWT, sets it as a cookie or returns it to the browser

### Path B — iOS app (native PKCE)

1. User taps "Sign in with Google" or "Sign in with Microsoft" in `OIDCLoginView`
2. iOS constructs a PKCE authorization URL (with `code_challenge`, `code_challenge_method=S256`, `state`, `nonce`) and launches `ASWebAuthenticationSession`
3. System browser opens the IdP login page; user authenticates
4. IdP redirects to `gighive://oidc/callback?code=…&state=…`
5. `GigHiveApp.onOpenURL` fires → routed to `OIDCLoginView` via `session.pendingOIDCCallback`
6. iOS calls `POST /api/oidc/token-exchange.php` with `{code, code_verifier, redirect_uri, provider}`
7. Server exchanges code with IdP, validates claims, upserts `users` row, returns GigHive JWT
8. iOS stores JWT in `JWTStore`; session proceeds identically to local-user login

---

## Files Under Change

### New — Server (`ansible/roles/docker/files/apache/webroot/`)

1. `api/oidc/callback.php` — browser OIDC callback handler. Reads IdP claims from Apache env vars; upserts `users` row; maps groups to role; generates GigHive JWT.
2. `api/oidc/token-exchange.php` — iOS PKCE code exchange. Accepts `{code, code_verifier, redirect_uri, provider}`; exchanges with IdP discovery endpoint; validates `id_token`; upserts `users` row; returns GigHive JWT. The OIDC client secret never leaves the server.
3. `auth/oidc.php` — shared OIDC helpers: `OidcProvider::discover(string $provider): array` (caches `.well-known/openid-configuration`), `OidcProvider::exchangeCode(...)`, `OidcProvider::validateIdToken(...)`, `OidcRoleMapper::mapGroups(array $groups): string`.

### New — iOS (`GigHive/Sources/App/`)

4. `OIDCLoginView.swift` — new view presenting "Sign in with Google" and "Sign in with Microsoft" buttons. Constructs PKCE parameters, launches `ASWebAuthenticationSession`, handles callback URL, calls `api/oidc/token-exchange.php`, stores result via `JWTStore`.
5. `PKCEHelper.swift` — generates `code_verifier` (32 random bytes → base64url) and `code_challenge` (SHA-256 of verifier → base64url). No external dependency.

### Modified — Server

6. `ansible/roles/docker/templates/Dockerfile.j2` — add `libapache2-mod-auth-openidc` and `a2enmod auth_openidc` to the container build.
7. `ansible/roles/docker/templates/default-ssl.conf.j2` — add `mod_auth_openidc` configuration block at VirtualHost level; add `<Location "/oidc/callback">` block.
8. `ansible/roles/docker/templates/.env.j2` — add `OIDC_GOOGLE_CLIENT_ID`, `OIDC_GOOGLE_CLIENT_SECRET`, `OIDC_MS_CLIENT_ID`, `OIDC_MS_CLIENT_SECRET`, `OIDC_MS_TENANT_ID`, `OIDC_CRYPTO_PASSPHRASE`, `OIDC_ROLE_MAP_JSON`, `OIDC_DEFAULT_ROLE`, `OIDC_GROUPS_CLAIM`. (`OIDC_REDIRECT_URI` is not included — the browser redirect URI is rendered directly into the Apache VirtualHost config by Jinja2 and is not read by PHP.)
9. `auth/jwt.php` — **no functional change in Phase 5.** HS256 continues; GigHive-issued JWTs remain HS256 throughout Phase 5. The strategic document (`feature_security_authentication_migration_jwt.md` line 34) states "RS256 for OIDC interop in Phase 5" — this is superseded by the Phase 5 design decision: GigHive does **not** expose its JWTs to external parties, so RS256 is not required for interop. RS256 would be required only if GigHive JWTs were consumed by a third party that needs to verify them without the shared secret. That is not the case. The OIDC `id_token` (an external JWT from the IdP) is validated using the IdP's RS256 public JWKS; the GigHive-issued JWT remains HS256. **Action required:** update the strategic document's algorithm table to reflect this decision when Phase 5 is approved.
10. `config.php` — add `OIDC_*` constants reading from env.
11. `ansible/roles/post_build_checks/tasks/main.yml` — add T-105 through T-111 smoke tests; add `mod_auth_openidc` version-fetch task and `mod_auth_openidc` key to the **Build Stack Versions Summary** fact.
12. `LoginView.swift` — add "Or sign in with Google / Microsoft" section linking to `OIDCLoginView`.
13. `AuthSession.swift` — add `pendingOIDCCallback: ((URL) -> Void)?` closure property for routing callback URLs from `onOpenURL`.
14. `GigHiveApp.swift` — update `handleIncomingURL` to route `gighive://oidc/callback` URLs to `session.pendingOIDCCallback` instead of the QR guest path.

### Unchanged (explicitly)

- `auth/jwt.php`, `auth/helpers.php` — PHP JWT validation is identical for OIDC-issued tokens. No changes.
- `api/login.php`, `api/verify.php` — local-user login remains active.
- `api/media-stream.php`, `api/tus-upload.php` — Bearer token auth is provider-agnostic.
- All QR paths — untouched in every phase.
- `JWTStore.swift`, `KeychainStore.swift` — token storage is identical for OIDC-issued tokens.
- `DatabaseAPIClient.swift`, `TUSUploadClient.swift`, `MediaResourceLoader.swift` — Bearer token sending is provider-agnostic.

---

## Prerequisite: `mod_auth_openidc` Version

The Ubuntu 24.04 `noble` apt repository ships `libapache2-mod-auth-openidc` 2.4.15. This version supports both Google and Microsoft/AAD with `OIDCProviderMetadataURL` discovery. Confirm version before container rebuild:

```bash
docker exec apacheWebServer apt-cache show libapache2-mod-auth-openidc | grep Version
```

Required minimum: 2.4.0. No new PHP library is needed — `firebase/php-jwt` (added in Phase 1) can validate the `id_token` signature for the token-exchange path.

---

## IdP Registration — What Operators Must Do First

Phase 5 cannot deploy without IdP app registrations. This is operator work done outside the codebase.

### Google

1. Google Cloud Console → APIs & Services → Credentials → Create OAuth 2.0 Client ID
2. Application type: **Web application**
3. Authorized redirect URIs: `https://<host>/oidc/callback`
4. Copy **Client ID** → `oidc_google_client_id` in ansible-vault
5. Copy **Client Secret** → `oidc_google_client_secret` in ansible-vault
6. (Optional) Configure Groups: requires Google Workspace with Admin SDK; `groups` claim is populated by a directory sync or a custom claim in Google Identity Platform

### Microsoft Entra ID (AAD)

1. Azure Portal → Azure Active Directory → App registrations → New registration
2. Supported account types: **Accounts in this organizational directory only** (or multitenant for SaaS)
3. Redirect URI: `https://<host>/oidc/callback` (platform: Web)
4. Under "Certificates & secrets": create client secret → `oidc_ms_client_secret` in ansible-vault
5. Copy **Application (client) ID** → `oidc_ms_client_id` in ansible-vault
6. Copy **Directory (tenant) ID** → `oidc_ms_tenant_id` in ansible-vault (or use `common` for multitenant)
7. Under "Token configuration": add optional claim `groups` (type: ID token) — requires "groups" claim or security group IDs
8. Under "API permissions": add `openid`, `email`, `profile`, `GroupMember.Read.All` (for group claim population)

**iOS redirect URI** — separate from the browser callback:
- Google: register `gighive://oidc/callback` under "iOS" application type in Google Cloud Console
- Microsoft: add `gighive://oidc/callback` as a redirect URI under "Mobile and desktop applications" platform type

---

## Phase 5a — Server: Apache `mod_auth_openidc`

### `Dockerfile.j2` addition

In the `apt-get install -y` block (after `libapache2-mod-security2`):

```dockerfile
    libapache2-mod-auth-openidc \
```

In the `a2enmod` line (after `security2 remoteip`):

```dockerfile
    a2enmod auth_openidc && \
```

This requires a container **rebuild and restart** — the only container change in Phase 5.

### `default-ssl.conf.j2` — OIDC global config

Add inside `<VirtualHost *:443>`, before any `<Location>` blocks:

{% raw %}
```apache
{% if gighive_auth_mode == 'oidc' %}
# ── OIDC global config ────────────────────────────────────────────────
OIDCCryptoPassphrase        "{{ oidc_crypto_passphrase }}"
OIDCSessionType             server:cache
OIDCCacheType               shm
OIDCSessionInactivityTimeout 3600

# Google provider
OIDCProviderMetadataURL     https://accounts.google.com/.well-known/openid-configuration
OIDCClientID                {{ oidc_google_client_id }}
OIDCClientSecret            {{ oidc_google_client_secret }}
OIDCRedirectURI             {{ gighive_base_url }}/oidc/callback
OIDCScope                   "openid email profile"
OIDCRemoteUserClaim         email
OIDCPassClaimsAs            environment
OIDCAuthNHeader             X-OIDC-Remote-User

# ── OIDC callback location ────────────────────────────────────────────
<Location "/oidc/callback">
    AuthType openid-connect
    Require valid-user
    # PHP callback handler receives OIDC_CLAIM_* env vars via mod_proxy_fcgi
</Location>
{% endif %}
```
{% endraw %}

**Microsoft requires a second provider entry.** `mod_auth_openidc` supports multiple providers via `OIDCMetadataDir`. The Microsoft discovery URL is:

{% raw %}
```
https://login.microsoftonline.com/{{ oidc_ms_tenant_id }}/v2.0/.well-known/openid-configuration
```
{% endraw %}

For multi-provider support, replace the single `OIDCProviderMetadataURL`/`OIDCClientID`/`OIDCClientSecret` directives with:

```apache
OIDCMetadataDir             /etc/apache2/oidc
```

And place two JSON files in `/etc/apache2/oidc/`:
- `accounts.google.com.conf` — `{"client_id": "...", "client_secret": "..."}`
- `login.microsoftonline.com%2F<tenant>%2Fv2.0.conf` — same structure

These files are generated from group_vars by a new Ansible task (see §Ansible Changes below).

### `api/oidc/callback.php` — browser OIDC callback

This file is invoked only when Apache has already validated the OIDC session. Claims are available as `$_SERVER` env vars prefixed `OIDC_CLAIM_`.

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth/jwt.php';
require_once __DIR__ . '/../../auth/oidc.php';

use Production\Api\Infrastructure\Database;

// Apache has already validated the OIDC session.
// Claims are in OIDC_CLAIM_* environment variables.
$sub      = $_SERVER['OIDC_CLAIM_sub']    ?? '';
$email    = $_SERVER['OIDC_CLAIM_email']  ?? '';
$name     = $_SERVER['OIDC_CLAIM_name']   ?? '';
$provider = $_SERVER['OIDC_CLAIM_iss']    ?? '';

if ($sub === '' || $email === '') {
    http_response_code(400);
    error_log('[oidc/callback] Missing sub or email claim');
    exit;
}

$idpProvider = OidcProvider::normalizeIssuer($provider);
// 'https://accounts.google.com' → 'google'
// 'https://login.microsoftonline.com/...' → 'microsoft'
// Anything else → 'unknown' (rejected below)

if ($idpProvider === 'unknown') {
    http_response_code(400);
    error_log('[oidc/callback] Unrecognised issuer claim: ' . $provider);
    exit;
}

// Parse groups claim for role mapping.
// The claim name is configurable via OIDC_GROUPS_CLAIM (default: 'groups').
// Apache sets OIDC_CLAIM_<claimname> from OIDCPassClaimsAs environment.
$claimKey  = 'OIDC_CLAIM_' . OIDC_GROUPS_CLAIM;
$groupsRaw = $_SERVER[$claimKey] ?? '[]';
$groups    = json_decode($groupsRaw, true) ?: [];
$role      = OidcRoleMapper::mapGroups($groups);

try {
    $pdo = Database::createFromEnv();
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[oidc/callback] DB connection failed: ' . $e->getMessage());
    exit;
}

// Upsert user row. idp_provider + idp_subject is the unique key.
// tenant_id = 1 for SaaS v1 single-tenant; extend when multi-tenant provisioning arrives.
$stmt = $pdo->prepare(
    'INSERT INTO users (tenant_id, idp_provider, idp_subject, role, email, display_name)
     VALUES (:tenant_id, :provider, :subject, :role, :email, :name)
     ON DUPLICATE KEY UPDATE
       role         = VALUES(role),
       email        = VALUES(email),
       display_name = VALUES(display_name),
       updated_at   = CURRENT_TIMESTAMP'
);
$stmt->execute([
    ':tenant_id' => 1,
    ':provider'  => $idpProvider,
    ':subject'   => $sub,
    ':role'      => $role,
    ':email'     => $email,
    ':name'      => $name,
]);

// Fetch the canonical users.id for the JWT sub claim.
// Also check the disabled flag — a disabled OIDC user must not receive a new JWT.
$fetch = $pdo->prepare(
    'SELECT id, disabled FROM users WHERE idp_provider = :provider AND idp_subject = :subject LIMIT 1'
);
$fetch->execute([':provider' => $idpProvider, ':subject' => $sub]);
$user = $fetch->fetch();

if ($user === false) {
    http_response_code(500);
    error_log('[oidc/callback] Upsert succeeded but row not found');
    exit;
}

if ((int)$user['disabled'] === 1) {
    http_response_code(403);
    error_log('[oidc/callback] Login denied — account disabled for idp_provider=' . $idpProvider);
    // Redirect to an error page rather than leaving the browser on the callback URL
    $dest = rtrim(SITE_URL, '/') . '/?error=account_disabled';
    header('Location: ' . $dest, true, 302);
    exit;
}

try {
    $token = JwtAuth::generate((int)$user['id'], $role, $email);
} catch (\RuntimeException $e) {
    http_response_code(500);
    error_log('[oidc/callback] JWT generation failed: ' . $e->getMessage());
    exit;
}

// For browser flows: redirect to the app root with the token in a fragment.
// The SPA/page reads it from the fragment and stores it in sessionStorage.
// A fragment is not sent to the server in subsequent requests.
$dest = rtrim(SITE_URL, '/') . '/#token=' . urlencode($token);
header('Location: ' . $dest, true, 302);
exit;
```

**Security notes:**
- Claims are read from `$_SERVER` env vars set by Apache/mod_auth_openidc, not from user-supplied input — Apache has already validated the IdP token's signature, expiry, audience, and nonce.
- The `users.id` (not `idp_subject`) becomes the JWT `sub`. This prevents JWT `sub` from leaking IdP-internal identifiers.
- `email` is display-only; auth decisions use `idp_provider + idp_subject` as identity.
- The token is delivered in a URL fragment (after `#`), which is never sent to the server in HTTP requests.

### `api/oidc/token-exchange.php` — iOS PKCE exchange

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth/jwt.php';
require_once __DIR__ . '/../../auth/oidc.php';

use Production\Api\Infrastructure\Database;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$body = (string)file_get_contents('php://input');
$data = json_decode($body, true);

$code         = (string)($data['code']          ?? '');
$codeVerifier = (string)($data['code_verifier'] ?? '');
$redirectUri  = (string)($data['redirect_uri']  ?? '');
$provider     = (string)($data['provider']       ?? '');

if ($code === '' || $codeVerifier === '' || $redirectUri === '' || $provider === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_fields']);
    exit;
}

// Validate provider allowlist
$allowed = ['google', 'microsoft'];
if (!in_array($provider, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'unsupported_provider']);
    exit;
}

// Validate redirect URI allowlist (must match registration exactly)
$allowedRedirectUris = ['gighive://oidc/callback'];
if (!in_array($redirectUri, $allowedRedirectUris, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_redirect_uri']);
    exit;
}

// Exchange code for tokens at the IdP token endpoint
try {
    $idpTokens = OidcProvider::exchangeCode($provider, $code, $codeVerifier, $redirectUri);
} catch (\RuntimeException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'token_exchange_failed']);
    error_log('[oidc/token-exchange] ' . $e->getMessage());
    exit;
}

// Validate id_token: signature, iss, aud, exp, nonce not required (PKCE replaces it)
try {
    $claims = OidcProvider::validateIdToken($provider, $idpTokens['id_token']);
} catch (\RuntimeException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_id_token']);
    error_log('[oidc/token-exchange] id_token validation failed: ' . $e->getMessage());
    exit;
}

$sub         = (string)($claims['sub']   ?? '');
$email       = (string)($claims['email'] ?? '');
$displayName = (string)($claims['name']  ?? '');   // 'name' claim: present for Google + AAD

if ($sub === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_claims']);
    exit;
}

// Parse groups if present (Google Workspace / AAD group claim).
// Claim name is configurable via OIDC_GROUPS_CLAIM constant (default: 'groups').
$groups = (array)($claims[OIDC_GROUPS_CLAIM] ?? []);
$role   = OidcRoleMapper::mapGroups($groups);

try {
    $pdo = Database::createFromEnv();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
    exit;
}

// Upsert user row — mirrors callback.php; display_name is updated on every login.
// disabled is intentionally excluded from ON DUPLICATE KEY UPDATE: a suspended account
// remains suspended even if it re-authenticates with a valid IdP token.
$stmt = $pdo->prepare(
    'INSERT INTO users (tenant_id, idp_provider, idp_subject, role, email, display_name)
     VALUES (:tenant_id, :provider, :subject, :role, :email, :display_name)
     ON DUPLICATE KEY UPDATE
       role         = VALUES(role),
       email        = VALUES(email),
       display_name = VALUES(display_name),
       updated_at   = CURRENT_TIMESTAMP'
);
$stmt->execute([
    ':tenant_id'    => 1,
    ':provider'     => $provider,
    ':subject'      => $sub,
    ':role'         => $role,
    ':email'        => $email,
    ':display_name' => $displayName,
]);

// Fetch row and check disabled — a disabled OIDC user must not receive a new JWT.
$fetch = $pdo->prepare(
    'SELECT id, disabled FROM users WHERE idp_provider = :provider AND idp_subject = :subject LIMIT 1'
);
$fetch->execute([':provider' => $provider, ':subject' => $sub]);
$user = $fetch->fetch();

if ($user === false) {
    http_response_code(500);
    echo json_encode(['error' => 'user_lookup_failed']);
    exit;
}

if ((int)$user['disabled'] === 1) {
    http_response_code(403);
    echo json_encode(['error' => 'account_disabled']);
    error_log('[oidc/token-exchange] Login denied — account disabled for provider=' . $provider);
    exit;
}

try {
    $token = JwtAuth::generate((int)$user['id'], $role, $email);
} catch (\RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'token_generation_failed']);
    exit;
}

$expiresAt = date('Y-m-d\TH:i:s\Z', time() + JWT_TTL_SECONDS);

http_response_code(200);
echo json_encode([
    'token'      => $token,
    'role'       => $role,
    'email'      => $email,
    'expires_at' => $expiresAt,
]);
exit;
```

### `auth/oidc.php` — shared OIDC helpers

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

/**
 * OIDC provider discovery, code exchange, and id_token validation.
 *
 * Supports 'google' and 'microsoft' as $provider values.
 * Discovery metadata is cached in APCu for 1 hour.
 */
final class OidcProvider
{
    private const DISCOVERY_CACHE_TTL = 3600;

    private static function clientId(string $provider): string
    {
        return match ($provider) {
            'google'    => OIDC_GOOGLE_CLIENT_ID,
            'microsoft' => OIDC_MS_CLIENT_ID,
            default     => throw new \InvalidArgumentException("Unknown provider: $provider"),
        };
    }

    private static function clientSecret(string $provider): string
    {
        return match ($provider) {
            'google'    => OIDC_GOOGLE_CLIENT_SECRET,
            'microsoft' => OIDC_MS_CLIENT_SECRET,
            default     => throw new \InvalidArgumentException("Unknown provider: $provider"),
        };
    }

    private static function discoveryUrl(string $provider): string
    {
        return match ($provider) {
            'google'    => 'https://accounts.google.com/.well-known/openid-configuration',
            'microsoft' => 'https://login.microsoftonline.com/' . OIDC_MS_TENANT_ID . '/v2.0/.well-known/openid-configuration',
            default     => throw new \InvalidArgumentException("Unknown provider: $provider"),
        };
    }

    /**
     * Fetch and cache the OIDC discovery document.
     * Returns the decoded JSON as an associative array.
     */
    public static function discover(string $provider): array
    {
        $cacheKey = "oidc_discovery_$provider";
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $success);
            if ($success && is_array($cached)) {
                return $cached;
            }
        }
        $url  = self::discoveryUrl($provider);
        $json = @file_get_contents($url);
        if ($json === false) {
            throw new \RuntimeException("Discovery fetch failed for provider=$provider url=$url");
        }
        $meta = json_decode($json, true);
        if (!is_array($meta)) {
            throw new \RuntimeException("Discovery JSON invalid for provider=$provider");
        }
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $meta, self::DISCOVERY_CACHE_TTL);
        }
        return $meta;
    }

    /**
     * Exchange an authorization code for IdP tokens.
     * Returns array with at minimum 'id_token'.
     *
     * @throws \RuntimeException on HTTP or JSON error
     */
    public static function exchangeCode(
        string $provider,
        string $code,
        string $codeVerifier,
        string $redirectUri
    ): array {
        $meta = self::discover($provider);
        $tokenEndpoint = $meta['token_endpoint']
            ?? throw new \RuntimeException("No token_endpoint in discovery for $provider");

        $params = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => self::clientId($provider),
            'client_secret' => self::clientSecret($provider),
            'code_verifier' => $codeVerifier,
        ]);

        // ssl context options enforce TLS certificate verification explicitly —
        // file_get_contents inherits php.ini openssl.cafile but does not guarantee
        // peer verification is active unless set in the context. Do NOT set
        // 'verify_peer' => false in any environment (PPRR P8).
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $params,
                'timeout' => 10,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($tokenEndpoint, false, $ctx);
        if ($response === false) {
            throw new \RuntimeException("Token endpoint request failed for $provider");
        }
        $tokens = json_decode($response, true);
        if (!is_array($tokens) || empty($tokens['id_token'])) {
            $err = $tokens['error'] ?? 'unknown';
            throw new \RuntimeException("Token exchange failed for $provider: $err");
        }
        return $tokens;
    }

    /**
     * Validate an id_token JWT from the IdP.
     * Fetches the IdP's JWKS, validates signature, iss, aud, and exp.
     * Returns the decoded claims array.
     *
     * @throws \RuntimeException on validation failure
     */
    public static function validateIdToken(string $provider, string $idToken): array
    {
        $meta = self::discover($provider);
        $jwksUri = $meta['jwks_uri']
            ?? throw new \RuntimeException("No jwks_uri in discovery for $provider");

        $cacheKey = "oidc_jwks_$provider";
        $jwksRaw  = null;
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $success);
            if ($success && is_string($cached)) {
                $jwksRaw = $cached;
            }
        }
        if ($jwksRaw === null) {
            $jwksRaw = @file_get_contents($jwksUri);
            if ($jwksRaw === false) {
                throw new \RuntimeException("JWKS fetch failed for $provider");
            }
            if (function_exists('apcu_store')) {
                apcu_store($cacheKey, $jwksRaw, self::DISCOVERY_CACHE_TTL);
            }
        }

        $jwks = json_decode($jwksRaw, true);
        if (!is_array($jwks)) {
            throw new \RuntimeException("JWKS JSON invalid for $provider");
        }

        try {
            $keys    = JWK::parseKeySet($jwks);
            $decoded = JWT::decode($idToken, $keys);
            $claims  = (array)$decoded;
        } catch (\Throwable $e) {
            throw new \RuntimeException("id_token decode failed: " . $e->getMessage());
        }

        // Validate iss
        $expectedIss = $meta['issuer'] ?? '';
        if (($claims['iss'] ?? '') !== $expectedIss) {
            throw new \RuntimeException("id_token iss mismatch: expected=$expectedIss got={$claims['iss']}");
        }

        // Validate aud
        $clientId = self::clientId($provider);
        $aud = $claims['aud'] ?? '';
        $audList = is_array($aud) ? $aud : [$aud];
        if (!in_array($clientId, $audList, true)) {
            throw new \RuntimeException("id_token aud does not include client_id=$clientId");
        }

        return $claims;
    }

    /**
     * Normalize an OIDC issuer URL to a short provider string.
     * Used by the browser callback to identify the IdP from the iss claim.
     */
    public static function normalizeIssuer(string $iss): string
    {
        if (str_contains($iss, 'google')) {
            return 'google';
        }
        if (str_contains($iss, 'microsoftonline') || str_contains($iss, 'microsoft')) {
            return 'microsoft';
        }
        return 'unknown';
    }
}

/**
 * Maps IdP group membership to a GigHive role.
 * Reads OIDC_ROLE_MAP_JSON from env — a JSON object mapping group name or
 * group ID to a GigHive role: {"gighive-owners":"owner","gighive-contributors":"contributor"}
 *
 * Falls back to OIDC_DEFAULT_ROLE (default: 'viewer') if no group matches.
 *
 * Role hierarchy: owner > contributor > viewer.
 * If a user is in multiple groups, the highest role wins.
 */
final class OidcRoleMapper
{
    private static function roleLevel(string $role): int
    {
        return match ($role) {
            'owner'       => 3,
            'contributor' => 2,
            'viewer'      => 1,
            default       => 0,
        };
    }

    public static function mapGroups(array $groups): string
    {
        $map = json_decode(OIDC_ROLE_MAP_JSON, true) ?: [];
        $defaultRole = OIDC_DEFAULT_ROLE ?: 'viewer';

        $best = $defaultRole;
        foreach ($groups as $group) {
            $mapped = $map[$group] ?? null;
            if ($mapped !== null && self::roleLevel($mapped) > self::roleLevel($best)) {
                $best = $mapped;
            }
        }
        return $best;
    }
}
```

**SonarQube notes:**
- All IdP HTTP calls use `stream_context_create` with a 10-second timeout — no unbounded blocking. RSPEC-3776 satisfied.
- `clientSecret()` is never logged. RSPEC-2635 satisfied.
- `exchangeCode()` validates the response has `id_token` before returning. Callers cannot receive a partial response.
- `validateIdToken()` checks `iss` and `aud` explicitly — algorithm confusion and audience confusion attacks mitigated.
- APCu cache avoids hammering IdP discovery and JWKS endpoints on every request. Cache TTL = 1 hour; acceptable for production. Clear with `apc_clear_cache()` if a key rotation is needed mid-session.
- **TLS verification (PPRR P8):** `discover()` and `validateIdToken()` use bare `file_get_contents($url)` for JWKS/discovery fetches — these must be upgraded to include `'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]` in their stream contexts, matching the pattern used in `exchangeCode()`. The production container uses Ubuntu 24.04 with a valid `openssl.cafile` (`/etc/ssl/certs/ca-certificates.crt`), but making TLS verification explicit in the stream context is required for correctness and SonarQube RSPEC-4830. Add `ssl` context to both `discover()` and `validateIdToken()` JWKS fetch during implementation.

### `config.php` — new OIDC constants

Add after the existing `JWT_TTL_SECONDS` line:

```php
// OIDC (Phase 5)
define('OIDC_GOOGLE_CLIENT_ID',     getenv('OIDC_GOOGLE_CLIENT_ID')     ?: '');
define('OIDC_GOOGLE_CLIENT_SECRET', getenv('OIDC_GOOGLE_CLIENT_SECRET') ?: '');
define('OIDC_MS_CLIENT_ID',         getenv('OIDC_MS_CLIENT_ID')         ?: '');
define('OIDC_MS_CLIENT_SECRET',     getenv('OIDC_MS_CLIENT_SECRET')     ?: '');
define('OIDC_MS_TENANT_ID',         getenv('OIDC_MS_TENANT_ID')         ?: 'common');
define('OIDC_ROLE_MAP_JSON',        getenv('OIDC_ROLE_MAP_JSON')        ?: '{}');
define('OIDC_DEFAULT_ROLE',         getenv('OIDC_DEFAULT_ROLE')         ?: 'viewer');
define('OIDC_GROUPS_CLAIM',         getenv('OIDC_GROUPS_CLAIM')         ?: 'groups');
define('OIDC_CRYPTO_PASSPHRASE',    getenv('OIDC_CRYPTO_PASSPHRASE')    ?: '');
```

**Note on `OIDC_GROUPS_CLAIM`:** The claim name containing group membership differs between IdPs and AAD configurations. Google typically uses `groups`; AAD may use `groups`, `roles`, or a custom claim name depending on the app registration. `OIDC_GROUPS_CLAIM` makes this configurable without a code change. Both `callback.php` and `token-exchange.php` must read the groups claim using this constant rather than the hardcoded string `'groups'` — see the PHP code sections below for the correct usage pattern.

All secrets have empty-string PHP defaults — missing secrets fail at runtime rather than silently bypassing auth. **Exception: `OIDC_CRYPTO_PASSPHRASE`.** `mod_auth_openidc` uses this value directly as the Apache `OIDCCryptoPassphrase` directive, which is read by Apache (not PHP) at startup. If the Jinja2 template renders `OIDCCryptoPassphrase ""` (empty), `mod_auth_openidc` will refuse to start, taking Apache down. This is a hard deployment gate: `oidc_crypto_passphrase` must be set in ansible-vault before the `gighive_auth_mode == 'oidc'` block is rendered. Add an Ansible `assert` before rendering `default-ssl.conf.j2`:

```yaml
- name: Assert oidc_crypto_passphrase is set before rendering OIDC Apache config
  ansible.builtin.assert:
    that:
      - oidc_crypto_passphrase is defined
      - oidc_crypto_passphrase | length >= 32
    fail_msg: "oidc_crypto_passphrase must be defined and at least 32 characters in ansible-vault"
  when: gighive_auth_mode == 'oidc'
  no_log: true
```

---

## Phase 5b — Ansible Configuration

### `.env.j2` additions

{% raw %}
```jinja2
# ── OIDC (Phase 5) ────────────────────────────────────────────────────────────
OIDC_GOOGLE_CLIENT_ID={{ oidc_google_client_id | default('') }}
OIDC_GOOGLE_CLIENT_SECRET={{ oidc_google_client_secret | default('') }}
OIDC_MS_CLIENT_ID={{ oidc_ms_client_id | default('') }}
OIDC_MS_CLIENT_SECRET={{ oidc_ms_client_secret | default('') }}
OIDC_MS_TENANT_ID={{ oidc_ms_tenant_id | default('common') }}
OIDC_CRYPTO_PASSPHRASE={{ oidc_crypto_passphrase }}
OIDC_ROLE_MAP_JSON={{ oidc_role_map | default({}) | to_json }}
OIDC_DEFAULT_ROLE={{ oidc_default_role | default('viewer') }}
OIDC_GROUPS_CLAIM={{ oidc_groups_claim | default('groups') }}
```
{% endraw %}

**Note on `OIDC_REDIRECT_URI`:** This variable was previously listed here but has been removed. The browser-flow redirect URI (rendered as `{% raw %}{{ gighive_base_url }}{% endraw %}/oidc/callback`) is rendered directly into the Apache VirtualHost config as the `OIDCRedirectURI` directive — Apache reads it from the `.conf` file, not from the PHP environment. PHP code does not need this value: `token-exchange.php` validates the iOS redirect URI (`gighive://oidc/callback`) against a hardcoded allowlist, not an env var. Including it in `.env.j2` would only create a misleading dead variable.

### group_vars additions — per-environment `secrets.yml` (ansible-vault)

```yaml
oidc_google_client_id:     "<from Google Cloud Console>"
oidc_google_client_secret: "<from Google Cloud Console>"
oidc_ms_client_id:         "<from Azure App Registration>"
oidc_ms_client_secret:     "<from Azure App Registration>"
oidc_crypto_passphrase:    "<random 32+ char string for mod_auth_openidc session encryption>"
```

### group_vars additions — per-environment main `.yml`

```yaml
gighive_auth_mode: "oidc"        # set this only when Phase 5 is fully deployed
oidc_ms_tenant_id: "common"      # or specific AAD tenant GUID for single-tenant apps
oidc_default_role: "viewer"      # role for OIDC users not in any mapped group
oidc_groups_claim: "groups"      # claim name in the id_token containing group list
oidc_role_map:
  gighive-owners:       "owner"
  gighive-contributors: "contributor"
  # Add group names or AAD group object IDs here
```

**Important:** `oidc_role_map` keys for AAD are typically group object IDs (GUIDs), not display names, unless the Azure app is configured to emit display names in the `groups` claim. Confirm which format the `groups` claim contains before populating this map.

### Ansible task — generate `mod_auth_openidc` metadata files (multi-provider)

Add to `ansible/roles/docker/tasks/main.yml` (or a new `oidc_setup.yml` task file):

{% raw %}
```yaml
# Use ansible.builtin.command + docker exec — consistent with project convention in
# ansible/roles/docker/tasks/main.yml and post_build_checks/tasks/main.yml.
# community.docker.docker_container_exec is NOT used in this project.

- name: Ensure OIDC metadata directory exists in container
  ansible.builtin.command: >
    docker exec -i "{{ apache_container_name }}"
    mkdir -p /etc/apache2/oidc
  when: gighive_auth_mode == 'oidc'
  changed_when: true

- name: Write Google OIDC client config file
  ansible.builtin.shell: >
    docker exec -i "{{ apache_container_name }}" sh -c
    'printf "%s" {{ {"client_id": oidc_google_client_id, "client_secret": oidc_google_client_secret} | to_json | quote }}
    > /etc/apache2/oidc/accounts.google.com.conf'
  when: gighive_auth_mode == 'oidc' and oidc_google_client_id != ''
  no_log: true  # prevent secret from appearing in Ansible output
  changed_when: true

- name: Write Microsoft OIDC client config file
  ansible.builtin.shell: >
    docker exec -i "{{ apache_container_name }}" sh -c
    'printf "%s" {{ {"client_id": oidc_ms_client_id, "client_secret": oidc_ms_client_secret} | to_json | quote }}
    > /etc/apache2/oidc/login.microsoftonline.com%2F{{ oidc_ms_tenant_id | urlencode }}%2Fv2.0.conf'
  when: gighive_auth_mode == 'oidc' and oidc_ms_client_id != ''
  no_log: true
  changed_when: true
```
{% endraw %}

`no_log: true` is required on both write tasks — client secrets must not appear in Ansible output or logs. `changed_when: true` is required because `ansible.builtin.shell` with `docker exec` always exits 0 and Ansible would otherwise always report `ok` rather than `changed`.

---

## Phase 5c — iOS: `OIDCLoginView.swift` and `PKCEHelper.swift`

**Phase 3 dependency:** `OIDCLoginView` references `StoredToken`, `UserRole`, and `JWTStore` — all defined in Phase 3. `AuthSession.swift` currently has `UserRole` as `case unknown, viewer, admin` (the legacy form). Phase 3 replaces `.admin` with `.owner` and `.contributor`. Phase 5 assumes `UserRole` is the Phase-3-corrected version: `case unknown, viewer, contributor, owner`. If Phase 5 iOS work begins before Phase 3 is merged, the `UserRole` enum will need to be updated as part of Phase 5. Do not use `.admin`.

### `PKCEHelper.swift`

```swift
import Foundation
import CryptoKit

/// Generates PKCE code_verifier and code_challenge.
/// RFC 7636 — no external dependency; uses CryptoKit (iOS 13+).
enum PKCEHelper {
    /// Generate a random 32-byte URL-safe base64 code_verifier.
    static func generateVerifier() -> String {
        var bytes = [UInt8](repeating: 0, count: 32)
        _ = SecRandomCopyBytes(kSecRandomDefault, bytes.count, &bytes)
        return Data(bytes).base64URLEncodedString()
    }

    /// Derive the code_challenge (S256) from a verifier.
    static func challenge(for verifier: String) -> String {
        let data = Data(verifier.utf8)
        let hash = SHA256.hash(data: data)
        return Data(hash).base64URLEncodedString()
    }
}

private extension Data {
    /// Base64url encoding without padding (RFC 4648 §5).
    func base64URLEncodedString() -> String {
        base64EncodedString()
            .replacingOccurrences(of: "+", with: "-")
            .replacingOccurrences(of: "/", with: "_")
            .replacingOccurrences(of: "=", with: "")
    }
}
```

`CryptoKit` is available from iOS 13+, so no `@available` guard is needed. `SecRandomCopyBytes` is available on all iOS versions.

### `OIDCLoginView.swift`

```swift
import SwiftUI
import AuthenticationServices

struct OIDCLoginView: View {
    @EnvironmentObject private var session: AuthSession
    let baseURL: URL
    let onDismiss: () -> Void

    @State private var isLoading = false
    @State private var errorMessage: String?

    var body: some View {
        VStack(spacing: 24) {
            Text("Sign in with your organization account")
                .font(.headline)

            Button("Sign in with Google") {
                Task { await startOIDC(provider: "google") }
            }
            .disabled(isLoading)

            Button("Sign in with Microsoft") {
                Task { await startOIDC(provider: "microsoft") }
            }
            .disabled(isLoading)

            if let error = errorMessage {
                Text(error).foregroundColor(.red).font(.caption)
            }

            Button("Cancel", action: onDismiss)
                .foregroundColor(.secondary)
        }
        .padding()
    }

    private func startOIDC(provider: String) async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        let verifier  = PKCEHelper.generateVerifier()
        let challenge = PKCEHelper.challenge(for: verifier)
        let state     = UUID().uuidString

        // Build authorization URL from server-side discovery
        // Using well-known URLs directly avoids a round-trip to the server
        let authBase: String
        switch provider {
        case "google":
            authBase = "https://accounts.google.com/o/oauth2/v2/auth"
        case "microsoft":
            // For multi-tenant; replace with tenant-specific URL for single-tenant
            authBase = "https://login.microsoftonline.com/common/oauth2/v2.0/authorize"
        default:
            errorMessage = "Unknown provider"
            return
        }

        // Client IDs are fetched from the server to avoid embedding them in the app bundle.
        // A lightweight GET /api/oidc/config.php returns {google_client_id, ms_client_id}.
        guard let clientId = await fetchClientId(provider: provider) else {
            errorMessage = "Could not load provider configuration"
            return
        }

        var components = URLComponents(string: authBase)!
        components.queryItems = [
            URLQueryItem(name: "response_type",          value: "code"),
            URLQueryItem(name: "client_id",              value: clientId),
            URLQueryItem(name: "redirect_uri",           value: "gighive://oidc/callback"),
            URLQueryItem(name: "scope",                  value: "openid email profile"),
            URLQueryItem(name: "state",                  value: state),
            URLQueryItem(name: "code_challenge",         value: challenge),
            URLQueryItem(name: "code_challenge_method",  value: "S256"),
        ]
        guard let authURL = components.url else {
            errorMessage = "Failed to build authorization URL"
            return
        }

        // Store callback handler on session so GigHiveApp.onOpenURL can route to us.
        // Non-throwing continuation: handleCallback returns Optional (never throws externally).
        // The continuation MUST be resumed exactly once — the error path inside
        // launchWebAuthSession clears pendingOIDCCallback and resumes with nil.
        let token: StoredToken? = await withCheckedContinuation { cont in
            session.pendingOIDCCallback = { callbackURL in
                session.pendingOIDCCallback = nil
                Task {
                    let result = await self.handleCallback(
                        callbackURL: callbackURL,
                        provider: provider,
                        expectedState: state,
                        verifier: verifier
                    )
                    cont.resume(returning: result)
                }
            }
            // Launch ASWebAuthenticationSession on the main thread.
            // launchWebAuthSession resumes the continuation with nil on error,
            // guaranteeing the continuation is always resumed exactly once.
            DispatchQueue.main.async {
                self.launchWebAuthSession(url: authURL, state: state, cont: cont)
            }
        }

        guard let storedToken = token else { return }

        if let host = baseURL.host {
            try? JWTStore.save(
                token: storedToken.token,
                host: host,
                role: storedToken.role,
                expiresAt: storedToken.expiresAt
            )
        }
        session.token     = storedToken.token
        session.role      = storedToken.role
        session.expiresAt = storedToken.expiresAt
        session.baseURL   = baseURL
        onDismiss()
    }

    // Continuation is passed in so the error path can resume it with nil,
    // guaranteeing the continuation is never abandoned (prevents Swift concurrency leak).
    private func launchWebAuthSession(
        url: URL,
        state: String,
        cont: CheckedContinuation<StoredToken?, Never>
    ) {
        let scheme = "gighive"
        let webAuthSession = ASWebAuthenticationSession(
            url: url,
            callbackURLScheme: scheme
        ) { callbackURL, error in
            if let error {
                logWithTimestamp("[OIDC] ASWebAuthenticationSession error: \(error.localizedDescription)")
                self.session.pendingOIDCCallback = nil
                DispatchQueue.main.async { self.errorMessage = error.localizedDescription }
                cont.resume(returning: nil)   // resume so the continuation is never leaked
                return
            }
            guard let callbackURL else {
                self.session.pendingOIDCCallback = nil
                cont.resume(returning: nil)
                return
            }
            self.session.pendingOIDCCallback?(callbackURL)
        }
        // presentationContextProvider is required on iOS 14+.
        // OIDCLoginView sets this via the conformance on the WindowScene anchor below.
        webAuthSession.presentationContextProvider = WindowAnchorProvider.shared
        webAuthSession.prefersEphemeralWebBrowserSession = true
        webAuthSession.start()
    }

    private func handleCallback(
        callbackURL: URL,
        provider: String,
        expectedState: String,
        verifier: String
    ) async -> StoredToken? {
        let components = URLComponents(url: callbackURL, resolvingAgainstBaseURL: false)
        let params = Dictionary(
            uniqueKeysWithValues: (components?.queryItems ?? []).compactMap {
                $0.value.map { ($0.name, $0) }
            }
        )

        guard let code = params["code"]?.value else {
            await setError("Authorization code missing from callback")
            return nil
        }
        guard params["state"]?.value == expectedState else {
            await setError("State mismatch — possible CSRF")
            return nil
        }

        // Exchange code at server
        guard let exchangeURL = URL(string: "\(baseURL.absoluteString)/api/oidc/token-exchange.php") else {
            await setError("Invalid server URL")
            return nil
        }
        var request = URLRequest(url: exchangeURL)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        let body: [String: String] = [
            "code":          code,
            "code_verifier": verifier,
            "redirect_uri":  "gighive://oidc/callback",
            "provider":      provider,
        ]
        request.httpBody = try? JSONSerialization.data(withJSONObject: body)

        let cfg = URLSessionConfiguration.ephemeral
        let urlSession = session.allowInsecureTLS
            ? URLSession(configuration: cfg, delegate: InsecureTrustDelegate.shared, delegateQueue: nil)
            : URLSession(configuration: cfg)

        // iOS 14 compatible async data fetch
        let (data, response): (Data, URLResponse)
        do {
            (data, response) = try await withCheckedThrowingContinuation { cont in
                urlSession.dataTask(with: request) { data, response, error in
                    if let error { cont.resume(throwing: error); return }
                    guard let data, let response else {
                        cont.resume(throwing: URLError(.badServerResponse)); return
                    }
                    cont.resume(returning: (data, response))
                }.resume()
            }
        } catch {
            await setError(error.localizedDescription)
            return nil
        }

        guard let http = response as? HTTPURLResponse, http.statusCode == 200 else {
            await setError("Token exchange failed (\((response as? HTTPURLResponse)?.statusCode ?? 0))")
            return nil
        }

        guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let tokenStr = json["token"]     as? String,
              let roleStr  = json["role"]      as? String,
              let expiresStr = json["expires_at"] as? String else {
            await setError("Unexpected response from server")
            return nil
        }

        let role      = UserRole(rawValue: roleStr) ?? .viewer
        let expiresAt = ISO8601DateFormatter().date(from: expiresStr) ?? Date()
        return StoredToken(token: tokenStr, role: role, expiresAt: expiresAt)
    }

    private func fetchClientId(provider: String) async -> String? {
        guard let url = URL(string: "\(baseURL.absoluteString)/api/oidc/config.php") else { return nil }
        let cfg = URLSessionConfiguration.ephemeral
        let urlSession = URLSession(configuration: cfg)
        let (data, _): (Data, URLResponse)
        do {
            (data, _) = try await withCheckedThrowingContinuation { cont in
                urlSession.dataTask(with: url) { data, response, error in
                    if let error { cont.resume(throwing: error); return }
                    guard let data, let response else {
                        cont.resume(throwing: URLError(.badServerResponse)); return
                    }
                    cont.resume(returning: (data, response))
                }.resume()
            }
        } catch { return nil }
        guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: String] else { return nil }
        return json["\(provider)_client_id"]
    }

    @MainActor
    private func setError(_ msg: String) {
        errorMessage = msg
        logWithTimestamp("[OIDC] \(msg)")
    }
}

/// ASWebAuthenticationSession requires a presentationContextProvider on iOS 12+.
/// On iOS 14 the default is nil which causes a runtime crash on some configurations.
/// This shared anchor finds the key window from the active scene.
final class WindowAnchorProvider: NSObject, ASWebAuthenticationPresentationContextProviding {
    static let shared = WindowAnchorProvider()

    func presentationAnchor(for session: ASWebAuthenticationSession) -> ASPresentationAnchor {
        // UIApplication.shared.windows is deprecated iOS 15+; use UIWindowScene for iOS 15+.
        // For iOS 14 compatibility, UIApplication.shared.windows is the correct path.
        #if canImport(UIKit)
        if #available(iOS 15, *) {
            return UIApplication.shared.connectedScenes
                .compactMap { $0 as? UIWindowScene }
                .flatMap { $0.windows }
                .first(where: { $0.isKeyWindow })
                ?? ASPresentationAnchor()
        } else {
            return UIApplication.shared.windows.first(where: { $0.isKeyWindow })
                ?? ASPresentationAnchor()
        }
        #else
        return ASPresentationAnchor()
        #endif
    }
}
```

**iOS 14 `presentationContextProvider` requirement:** `ASWebAuthenticationSession.presentationContextProvider` is non-optional at runtime on iOS 14 — failing to set it results in a crash or silent failure depending on the iOS version. `WindowAnchorProvider.shared` must be set before calling `webAuthSession.start()`.

### `api/oidc/config.php` — client ID endpoint (new)

The iOS app fetches public client IDs from the server so they are not embedded in the app bundle. Client secrets never appear here.

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

// Client IDs are public (they appear in authorization URLs visible to the user).
// Client secrets are never returned here.
http_response_code(200);
echo json_encode([
    'google_client_id'    => OIDC_GOOGLE_CLIENT_ID,
    'microsoft_client_id' => OIDC_MS_CLIENT_ID,
]);
exit;
```

### `GigHiveApp.swift` — route OIDC callback URLs

Update `handleIncomingURL` to detect the `gighive://oidc/callback` path and route it to `session.pendingOIDCCallback` before the QR guest path check:

```swift
@MainActor
private func handleIncomingURL(_ url: URL, via source: String,
                                 guestSession: GuestUploadSession,
                                 authSession: AuthSession) {
    logWithTimestamp("[\(source)] parsing: \(url.absoluteString)")

    // Route OIDC callback before QR guest path
    if url.scheme == "gighive", url.host == "oidc", url.pathComponents.first(where: { $0 != "/" }) == "callback" {
        logWithTimestamp("[\(source)] routing to OIDC callback handler")
        authSession.pendingOIDCCallback?(url)
        return
    }

    // Existing QR guest path (unchanged)
    guard url.pathComponents.count >= 3,
          url.pathComponents[1] == "upload",
          let host = url.host,
          let scheme = url.scheme,
          let baseURL = URL(string: "\(scheme)://\(host)") else {
        logWithTimestamp("[\(source)] BAIL: did not match any known URL pattern")
        return
    }
    let token = url.pathComponents[2]
    guestSession.baseURL = baseURL
    guestSession.rawToken = token
}
```

Update all three `handleIncomingURL` call sites in `GigHiveApp.swift` to pass `authSession: session`.

### `AuthSession.swift` — add `pendingOIDCCallback`

Add one property:

```swift
/// Set by OIDCLoginView before launching ASWebAuthenticationSession.
/// Cleared by GigHiveApp.onOpenURL after routing the callback.
var pendingOIDCCallback: ((URL) -> Void)?
```

This is not `@Published` — it is not UI state. It is a routing closure.

### `LoginView.swift` — add OIDC entry point

Add below the existing email/password form, after the Sign In button:

```swift
Divider().padding(.vertical, 8)

Text("Or sign in with your organization")
    .font(.caption)
    .foregroundColor(.secondary)

Button("Sign in with Google / Microsoft") {
    showOIDCLogin = true
}
.sheet(isPresented: $showOIDCLogin) {
    if let baseURL = session.baseURL ?? URL(string: full) {
        OIDCLoginView(baseURL: baseURL, onDismiss: { showOIDCLogin = false })
            .environmentObject(session)
    }
}
```

Add `@State private var showOIDCLogin = false` to `LoginView`.

---

## Tenant Resolution in Phase 5

All Phase 5 upserts hard-code `tenant_id = 1`. This is correct for SaaS v1 (single-tenant GigHive deployment). When multi-tenant support arrives, the tenant must be resolved before the upsert — options:

- From the `state` parameter (encode tenant slug in the PKCE state and validate it on callback)
- From the hostname of the redirect URI (each tenant has its own subdomain)
- From the IdP's `hd` (hosted domain) claim (Google Workspace only)

Document as a follow-on. Do not add multi-tenant resolution to Phase 5.

---

## `superadmin` Role and OIDC

The `users.role` enum includes `superadmin`. `OidcRoleMapper::mapGroups()` never returns `superadmin` — the highest role it can emit is `owner`. `superadmin` is a GigHive platform operator role assigned directly in the database; it is not grantable through IdP group membership. No change needed.

---

## Database — No New DDL

Phase 5 adds no new columns or tables. The `users` table already has `idp_provider`, `idp_subject`, `role`, `email`, and `display_name`. The Phase 1 `ALTER TABLE` added `password_hash` (NULL for OIDC users) and `disabled`. No further schema change is needed.

---

## Smoke Tests (`post_build_checks/tasks/main.yml`)

{% raw %}
```yaml
# --- Phase 5 OIDC Smoke Tests ---

- name: "[T-105] GET /api/oidc/config.php returns 200 with JSON body"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/oidc/config.php"
    method: GET
    status_code: 200
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  register: t105_resp
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

- name: "[T-105a] Assert config.php response contains client_id keys"
  ansible.builtin.assert:
    that:
      - t105_resp.json is mapping
      - "'google_client_id' in t105_resp.json"
      - "'microsoft_client_id' in t105_resp.json"
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

- name: "[T-106] POST /api/oidc/token-exchange.php with missing fields returns 400"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/oidc/token-exchange.php"
    method: POST
    headers:
      Content-Type: application/json
    body: "{}"
    body_format: raw
    status_code: 400
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

- name: "[T-107] POST /api/oidc/token-exchange.php with unknown provider returns 400"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/oidc/token-exchange.php"
    method: POST
    headers:
      Content-Type: application/json
    body: '{"code":"x","code_verifier":"y","redirect_uri":"gighive://oidc/callback","provider":"evil"}'
    body_format: raw
    status_code: 400
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

- name: "[T-108] POST /api/oidc/token-exchange.php with invalid redirect_uri returns 400"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/oidc/token-exchange.php"
    method: POST
    headers:
      Content-Type: application/json
    body: '{"code":"x","code_verifier":"y","redirect_uri":"https://evil.example.com","provider":"google"}'
    body_format: raw
    status_code: 400
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

- name: "[T-109] POST /api/oidc/token-exchange.php with bad code returns 401"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/oidc/token-exchange.php"
    method: POST
    headers:
      Content-Type: application/json
    body: '{"code":"invalid","code_verifier":"invalid","redirect_uri":"gighive://oidc/callback","provider":"google"}'
    body_format: raw
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

- name: "[T-110] GET /api/login.php returns 405 (local login still available in oidc mode)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/login.php"
    method: GET
    status_code: 405
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

- name: "[T-111] GET /oidc/callback without OIDC session returns 302 or 401 (mod_auth_openidc active)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/oidc/callback"
    method: GET
    status_code: [302, 401]
    follow_redirects: none
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

# --- Phase 5: Local login still works in oidc mode (positive path) ---

- name: "[T-125] POST /api/login.php with valid local credentials still returns 200 in oidc mode"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/login.php"
    method: POST
    headers:
      Content-Type: application/json
    body: '{"email":"{{ gighive_smoke_owner_email }}","password":"{{ gighive_smoke_owner_password }}"}'
    body_format: raw
    status_code: 200
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  register: t125_resp
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

- name: "[T-125a] Assert local login in oidc mode returns token and role"
  ansible.builtin.assert:
    that:
      - t125_resp.json.token is string
      - t125_resp.json.token | length > 0
      - t125_resp.json.role in ["owner", "contributor", "viewer"]
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

# --- Phase 5: Auth-guarded endpoints still require auth in oidc mode (regression) ---

- name: "[T-126] GET /db/database.php with no auth returns 401 in oidc mode (PHP guard still active)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/db/database.php?format=json"
    method: GET
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

- name: "[T-127] GET /admin/admin.php with no auth returns 401 in oidc mode"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/admin/admin.php"
    method: GET
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

- name: "[T-128] POST /files/ with no auth returns 401 in oidc mode (TUS PHP guard still active)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/files/"
    method: POST
    headers:
      Tus-Resumable: "1.0.0"
      Content-Length: "0"
      Upload-Length: "1024"
    status_code: 401
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode == 'oidc'
  tags: [smoke]

# --- Phase 5: QR guest regression (must pass regardless of mode) ---

- name: "[T-129] GET /api/guest-gallery.php without auth returns non-401 in oidc mode (QR path unaffected)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/guest-gallery.php?nonce={{ gighive_smoke_gallery_nonce }}"
    method: GET
    status_code: [200, 400, 404]   # Any response except 401/403 confirms QR isolation is intact
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when:
    - gighive_auth_mode == 'oidc'
    - gighive_smoke_gallery_nonce is defined
  tags: [smoke, qr_regression]

- name: "[T-130] GET /api/upload-token.php without auth returns non-401 in oidc mode (QR path unaffected)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/upload-token.php"
    method: GET
    status_code: [200, 400, 404, 405]
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode == 'oidc'
  tags: [smoke, qr_regression]

# --- Phase 5: oidc_crypto_passphrase minimum length assertion (pre-deploy gate) ---

- name: "[T-131] Assert oidc_crypto_passphrase meets minimum 32-character length"
  ansible.builtin.assert:
    that:
      - oidc_crypto_passphrase is defined
      - oidc_crypto_passphrase | length >= 32
    fail_msg: "oidc_crypto_passphrase must be at least 32 characters. mod_auth_openidc will refuse to start without it."
  when: gighive_auth_mode == 'oidc'
  tags: [smoke, pre_deploy]

# --- Phase 5: Rollback regression — local mode after oidc ---

- name: "[T-132] POST /api/login.php with valid local credentials returns 200 after oidc rollback (local mode)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/login.php"
    method: POST
    headers:
      Content-Type: application/json
    body: '{"email":"{{ gighive_smoke_owner_email }}","password":"{{ gighive_smoke_owner_password }}"}'
    body_format: raw
    status_code: 200
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode == 'local'
  tags: [smoke, rollback]

- name: "[T-133] GET /api/oidc/config.php returns 404 or non-200 when not in oidc mode (endpoint inactive)"
  ansible.builtin.uri:
    url: "{{ gighive_base_url }}/api/oidc/config.php"
    method: GET
    status_code: [404, 403, 500]
    validate_certs: "{{ gighive_validate_certs | default(true) }}"
  when: gighive_auth_mode != 'oidc'
  tags: [smoke, rollback]
```
{% endraw %}

---

## Security Analysis

| Threat | Mitigation |
|--------|-----------|
| Authorization code interception | PKCE: `code_verifier` is never transmitted; `code_challenge` is useless without it. An intercepted code cannot be exchanged. |
| CSRF on authorization callback | `state` parameter round-trips through the IdP; iOS checks `state` matches before proceeding. |
| Token substitution (wrong IdP token sent to server) | `api/oidc/token-exchange.php` validates `iss` and `aud` claims on the `id_token` against the IdP's own JWKS and discovery document. |
| Client secret exposure | Secret stays on the server. iOS fetches only the public `client_id` from `api/oidc/config.php`. |
| Open redirect in browser callback | `api/oidc/callback.php` redirects only to `SITE_URL + /#token=…` — no user-supplied redirect parameter. |
| Open redirect in iOS PKCE | `redirect_uri` is validated against an allowlist on the server before the code exchange proceeds. |
| OIDC issuer confusion (wrong IdP for `sub`) | `idp_provider` is stored alongside `idp_subject`; `UNIQUE KEY uq_users_idp(idp_provider, idp_subject)` prevents cross-provider `sub` collisions. A Google `sub` value can never match a Microsoft `sub` — they are keyed separately. |
| Account takeover via shared email | Email is display-only; auth decisions use `idp_provider + idp_subject`. Two users with the same email in different IdPs get two separate `users` rows — no confusion. |
| JWKS cache poisoning | JWKS is fetched over HTTPS from the IdP's own discovery endpoint. APCu cache TTL is 1 hour; no user input influences the cache key. |
| `none` algorithm attack on id_token | `firebase/php-jwt` + `JWK::parseKeySet()` validates against the JWKS keys; the algorithm is taken from the JWKS key definition, not the token header. |
| Token delivered via URL fragment (browser) | Fragments are never sent to the server in HTTP requests. The token stays in the browser process. |

---

## Rollback

**Phase 5 rollback = one Ansible variable change:**

```yaml
gighive_auth_mode: "local"
```

Run Ansible. Apache `mod_auth_openidc` blocks are disabled (they are inside `{% if gighive_auth_mode == 'oidc' %}`). Local-user login via `api/login.php` continues. The `api/oidc/token-exchange.php` and `api/oidc/config.php` endpoints become unreachable from the iOS app (but are still deployed — they just don't matter).

**OIDC-only users after rollback:** Any user whose `users` row has `password_hash = NULL` (i.e. they never set a local password) cannot log in after rollback. Recovery: the operator runs:

```bash
# php -r "echo password_hash('temppass', PASSWORD_BCRYPT, ['cost'=>12]);"
docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "
UPDATE users SET password_hash = '"'"'HASH_HERE'"'"' WHERE idp_provider = '"'"'google'"'"' AND email = '"'"'user@example.com'"'"';
"'
```

Document this in the operator guide. For internal-only deployments (current state: no customers), this is acceptable.

---

## Open Questions

| Question | Notes |
|----------|-------|
| Multi-tenant OIDC tenant resolution | Deferred. Phase 5 uses `tenant_id = 1` for all OIDC users. |
| AAD group claim format (display name vs object ID) | Confirm at registration time. Populate `oidc_role_map` keys accordingly. |
| Google Workspace groups claim availability | Requires Google Identity Platform + directory sync or custom attribute. May not be available for all Google accounts. Fallback: email domain matching for role assignment. |
| `gighive://` custom URL scheme registration in Xcode | **PPRR finding:** `GigHive/Configs/AppInfo.plist` does not contain `CFBundleURLSchemes` — the scheme is not registered. `onOpenURL` fires for Universal Links (HTTPS) but not for custom scheme URLs unless `CFBundleURLSchemes` is declared. Without this, the IdP redirect to `gighive://oidc/callback` will not open the app. Add `gighive` to `CFBundleURLSchemes` in `AppInfo.plist` before Phase 5 can work on device. |
| `ASWebAuthenticationSession` and `presentationContextProvider` | On iOS 14, `ASWebAuthenticationSession` requires a `presentationContextProvider`. The `OIDCLoginView` is presented in a sheet — the window anchor must be set correctly. |
| Email domain fallback for role mapping | If groups are not available from the IdP, consider an email domain → role map as a secondary mechanism. Not implemented in Phase 5. |

---

## Progress

### Completed
- Feature doc written (parent doc)
- Phases 1–4 implementation doc written and PPRR'd
- Phase 5 scope and architecture documented (this doc)

### Remaining — Phase 5
- [ ] Register app in Google Cloud Console; add `gighive://oidc/callback` as iOS redirect URI
- [ ] Register app in Azure Entra ID; configure group claims; add `gighive://oidc/callback` as mobile redirect URI
- [ ] Confirm AAD group claim format (display name vs object ID); populate `oidc_role_map`
- [ ] **Add `gighive` to `CFBundleURLSchemes` in `GigHive/Configs/AppInfo.plist`** — scheme is not currently registered; without it the PKCE callback redirect will not open the app (PPRR P3)
- [ ] Implement `auth/oidc.php` (`OidcProvider`, `OidcRoleMapper`)
- [ ] Implement `api/oidc/config.php`
- [ ] Implement `api/oidc/callback.php`
- [ ] Implement `api/oidc/token-exchange.php`
- [ ] Update `config.php` (OIDC constants)
- [ ] Update `.env.j2` (OIDC vars)
- [ ] Add OIDC group_vars to all environments (main `.yml` + secrets.yml)
- [ ] Update `Dockerfile.j2` (`libapache2-mod-auth-openidc`)
- [ ] Update `default-ssl.conf.j2` (OIDC VirtualHost config; multi-provider `OIDCMetadataDir`)
- [ ] Add Ansible tasks for `OIDCMetadataDir` JSON files (`no_log: true`)
- [ ] Implement `PKCEHelper.swift`
- [ ] Implement `OIDCLoginView.swift`
- [ ] Update `AuthSession.swift` (`pendingOIDCCallback`)
- [ ] Update `GigHiveApp.swift` (`handleIncomingURL` OIDC routing)
- [ ] Update `LoginView.swift` (OIDC button and sheet)
- [ ] Add T-105 through T-133 smoke tests to `post_build_checks/tasks/main.yml`
- [ ] Add `mod_auth_openidc` version to Stack Versions Summary in `post_build_checks/tasks/main.yml`: fetch via `dpkg-query -W -f='${Version}' libapache2-mod-auth-openidc` inside the Apache container (gated on `gighive_auth_mode == 'oidc'`); add `mod_auth_openidc` key to the `Build Stack Versions Summary` fact (`'not installed (pre-Phase 5)'` when mode is not `oidc`). `firebase/php-jwt` version is already covered automatically by the existing `Composer_PHP_Dependency_Manager_Packages` key — no additional task needed for it.
- [ ] Add `gighive_smoke_owner_email`, `gighive_smoke_owner_password` to each env's `secrets.yml` (ansible-vault) if not already added in Phase 1–4
- [ ] Add `gighive_smoke_gallery_nonce` to each env's group_vars for QR regression tests
- [ ] End-to-end test: Google login → `users` row → JWT → DatabaseView loads
- [ ] End-to-end test: Microsoft login → `users` row → JWT → role from group
- [ ] End-to-end test: OIDC user TUS upload succeeds with contributor/owner role
- [ ] End-to-end test: `gighive_auth_mode=local` rollback; OIDC-only user cannot login; local user can
- [ ] Operator guide: document `oidc_role_map` configuration; rollback procedure; OIDC-only user recovery

---

## PPRR Findings and Corrections

The following issues were identified and corrected in this document during post-write review. They are recorded here for traceability.

| ID | Severity | Category | Finding | Correction applied |
|----|----------|----------|---------|-------------------|
| P1 | High | Logic | `startOIDC()` used `withCheckedContinuation` (non-throwing) but passed the continuation to an inner `Task` that could not resume it via `throwing` path. Additionally, the continuation signature was implicit. | Made the return type explicit (`StoredToken?`); passed `cont` directly to `launchWebAuthSession` so the error path can resume it. |
| P2 | High | Crash | `ASWebAuthenticationSession.presentationContextProvider` is required at runtime on iOS 14+. Leaving it `nil` causes a runtime crash or silent failure depending on the iOS version. | Added `WindowAnchorProvider` conforming to `ASWebAuthenticationPresentationContextProviding`; set `webAuthSession.presentationContextProvider = WindowAnchorProvider.shared` in `launchWebAuthSession`. |
| P3 | Blocker | Config gap | `GigHive/Configs/AppInfo.plist` does not declare `CFBundleURLSchemes`. Without it the custom scheme `gighive://` is not registered with iOS; the IdP redirect to `gighive://oidc/callback` will not open the app, making the PKCE flow entirely non-functional on device. | Added PPRR note in Open Questions and marked as a blocker in the Remaining checklist. |
| P4 | High | Module mismatch | The Ansible tasks for writing `OIDCMetadataDir` JSON files used `community.docker.docker_container_exec`, which is not used anywhere in this project. The project's established pattern (in `docker/tasks/main.yml` and `post_build_checks/tasks/main.yml`) is `ansible.builtin.command: docker exec` or `ansible.builtin.shell` with `docker exec`. | Replaced all three tasks with `ansible.builtin.command` / `ansible.builtin.shell` + `docker exec -i "{% raw %}{{ apache_container_name }}{% endraw %}"`. Added `changed_when: true` and `no_log: true`. |
| P5 | High | Config safety | `OIDC_CRYPTO_PASSPHRASE` had an empty-string PHP default. Unlike application-layer secrets, this value is consumed by Apache at startup via `OIDCCryptoPassphrase`. An empty value causes `mod_auth_openidc` to refuse to start, taking Apache down. | Added an Ansible `assert` task requiring `oidc_crypto_passphrase` to be defined and ≥32 characters before the `default-ssl.conf.j2` template is rendered. Added explanatory note in `config.php` block. |
| P6 | High | Security | Both `callback.php` and `token-exchange.php` upserted the user row then fetched `id` only. A disabled OIDC user (`disabled = 1`) would receive a new JWT because the `disabled` column was never checked after the upsert. The `ON DUPLICATE KEY UPDATE` clause deliberately does not update `disabled` (correct), but the post-upsert fetch did not gate on it. | Added `disabled` to both `SELECT` queries. Added an explicit `if ((int)$user['disabled'] === 1)` check with `403` response before JWT generation in both files. `callback.php` redirects to `/?error=account_disabled`; `token-exchange.php` returns `{"error":"account_disabled"}`. |
| P7 | Medium | Security | `OidcProvider::normalizeIssuer()` returns `'unknown'` for unrecognised issuers. `callback.php` consumed the return value without checking for `'unknown'`, meaning a request from an IdP with an unrecognised `iss` claim would proceed to the upsert with `idp_provider = 'unknown'`. | Added an `if ($idpProvider === 'unknown')` guard immediately after `normalizeIssuer()` in `callback.php`. Returns `400` and logs the unrecognised issuer. |
| P8 | Medium | Security | `discover()` and `validateIdToken()` used bare `file_get_contents($url)` without explicit TLS context options. While the production container has a valid CA bundle, TLS peer verification is not guaranteed unless stated explicitly in the stream context, and SonarQube RSPEC-4830 flags it. `exchangeCode()` was corrected inline. | Added explicit `'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]` to the `exchangeCode()` stream context. Added a SonarQube note requiring the same treatment for `discover()` and the JWKS fetch in `validateIdToken()` during implementation. |
| R1 | High | Resilience | `launchWebAuthSession` cleared `pendingOIDCCallback` on error but did not resume the `withCheckedContinuation` continuation, leaving it permanently suspended (Swift concurrency warning; memory leak in practice). | Fixed by passing `cont` to `launchWebAuthSession` and calling `cont.resume(returning: nil)` in all early-exit paths inside the `ASWebAuthenticationSession` completion handler. |
| R2 | Low | Clarity | Two Ansible tasks had the same name `[T-105]` — the `uri` task and the `assert` task. Duplicate task names break Ansible playbook idempotency reporting and make log output ambiguous. | Renamed the assert task to `[T-105a] Assert config.php response contains client_id keys`. |
| C1 | Medium | Cross-doc | The strategic document (`feature_security_authentication_migration_jwt.md`, line 34 and table row "JWT algorithm") states "RS256 for OIDC interop in Phase 5". The implementation doc (`feature_security_authentication_migration_jwt_implementation.md`, line 187/195) and `auth/jwt.php` use `HS256` hardcoded. These are contradictory. | Resolved in this document: GigHive-issued JWTs remain HS256. The OIDC `id_token` is an external RS256 JWT from the IdP; GigHive validates it using the IdP JWKS but does not adopt RS256 for its own tokens. The strategic document's algorithm table must be updated when Phase 5 is approved. Noted in the "Files Under Change" section above. |
| C2 | Low | Cross-doc | `AuthSession.swift` currently defines `UserRole` as `case unknown, viewer, admin` (legacy). The Phase 5 doc's `OIDCLoginView` code uses `UserRole(rawValue: roleStr)` expecting the Phase-3-corrected enum (`owner`, `contributor`, `viewer`, `unknown`). | Added an explicit Phase 3 dependency note in the Phase 5c section. Phase 5 iOS work must not begin until the Phase 3 `UserRole` correction is merged, or the enum must be updated as part of Phase 5 itself. |
| C3 | Medium | Logic | Cross-document review (all three docs) found 13 additional inconsistencies: (1) Strategic/impl docs still said RS256 Phase 5 — strategic doc updated. (2) Strategic doc's `token-exchange` body omitted `provider` field — updated. (3) Strategic doc named generic OIDC env vars (`OIDC_CLIENT_ID` etc.) instead of provider-specific names — updated. (4) Strategic doc `secrets.yml` used generic Ansible var names — updated. (5) Strategic doc used `OIDC_ROLE_MAP` instead of `OIDC_ROLE_MAP_JSON` — updated. (6) Keycloak realm files in strategic doc were framed as required deliverables — clarified as optional operator references. (7) Strategic doc promised "1h access + 7d refresh" OIDC token TTL — corrected: GigHive issues a 30-day JWT for both local and OIDC users; IdP tokens are consumed server-side only. (8) Impl doc spelled BABRRR as "BABRR" at line 110 — corrected. (9) Both strategic and impl docs seeded local users with `idp_subject='admin@gighive.local'` — corrected to `NULL` (local users have no IdP subject). (10) `auth/oidc.php` and `api/oidc/config.php` were absent from strategic doc's new-files table — added. (11) `PKCEHelper.swift` was absent from strategic doc's new iOS files table — added. (12) Impl doc item 19 attributed `firebase/php-jwt` addition to `Dockerfile.j2` — corrected to `composer.json` + `composer.lock`. (13) `token-exchange.php` upsert omitted `display_name`, making it asymmetric with `callback.php` — fixed in this document. | Applied all 13 corrections across the three documents. |
| C4 | Medium | Correctness | Fourth cross-document PPRR (covering all four docs including the new benefits doc) found 5 issues: see C4a–C4e below. | All corrected in this PPRR pass. |
| C4a | Medium | Correctness | Strategic doc `auth/jwt.php` function signature listed as `JwtAuth::generate(int $userId, string $role, int $ttlSeconds): string` — missing the `$email` parameter added during Phase 1 implementation, and missing the `validateWithReason()` method entirely. The impl doc has the correct signature. | Updated strategic doc table entry to `JwtAuth::generate(int $userId, string $role, string $email, int $ttl = 0): string` and added `validateWithReason()` to the description. |
| C4b | Medium | Correctness | T-114, T-116, and T-122 (impl doc) reference `t112_resp.json.token` in their `headers:` value but their `when:` clauses only guard on `gighive_auth_mode != 'basic'`. If run in isolation (e.g. `--start-at-task` or a tag subset that excludes T-112), `t112_resp` is undefined and Ansible crashes with an undefined variable error. T-112a also checks `t112_resp.json is mapping` without guarding that `t112_resp` exists. | Added `- t112_resp is defined` to the `when:` clauses of T-114, T-114a, T-116, and T-122. |
| C4c | Medium | Correctness | `T-102` assert task (impl doc) had a duplicate name — both the `uri` task and its `assert` companion were named `[T-102]`. This is the same defect as R2 (the `[T-105]` duplicate in Phase 5). Duplicate Ansible task names break idempotency reporting and log disambiguation. | Renamed the assert task to `[T-102a] Assert tampered token returns invalid_token error`. |
| C4d | High | Correctness | `OIDC_GROUPS_CLAIM` was declared in `.env.j2` and `group_vars` but never defined as a PHP constant in `config.php`, and both `callback.php` and `token-exchange.php` hardcoded the claim key as the string `'groups'` rather than reading the configurable value. This made `OIDC_GROUPS_CLAIM` a dead environment variable — impossible to override without a code change. AAD may use `roles` or a custom claim name, making configurability essential. | Added `define('OIDC_GROUPS_CLAIM', getenv('OIDC_GROUPS_CLAIM') ?: 'groups')` to the `config.php` constants block. Updated `callback.php` to derive the Apache env-var key as `'OIDC_CLAIM_' . OIDC_GROUPS_CLAIM` and `token-exchange.php` to use `$claims[OIDC_GROUPS_CLAIM]`. Added an explanatory note after the `config.php` block. |
| C4e | Low | Correctness | `OIDC_REDIRECT_URI` was listed in `.env.j2` and in the "Files Under Change" list for `.env.j2`, but is never read by PHP. The browser redirect URI is rendered directly into the Apache VirtualHost config by Jinja2 (`OIDCRedirectURI {% raw %}{{ gighive_base_url }}{% endraw %}/oidc/callback`). PHP's `token-exchange.php` validates the iOS redirect URI against a hardcoded allowlist — not from an env var. Including it in `.env.j2` created a misleading dead variable. | Removed `OIDC_REDIRECT_URI` from the `.env.j2` block and updated the "Files Under Change" list with an explanatory note. Added a "Note on `OIDC_REDIRECT_URI`" paragraph after the `.env.j2` block. |
