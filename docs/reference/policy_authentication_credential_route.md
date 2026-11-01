# Architecture Policy: Authentication Credentials and Route Classes

## Status — 2026-09-09
Draft policy approved as the reconciliation source — focused policy PPRR completed 2026-09-09. Browser JWT cookie, centralized route-class policy, atomic per-environment cutover, and sequential environment gates are confirmed planning decisions. Exact implementation changes remain subject to review and explicit approval. This document authorizes no code, configuration, or deployment.

**Policy scope:** Browser, iOS, API, QR guest, media, and public authentication behavior during and after the Apache Basic Auth to JWT migration.  
**Applies to:** GigHive SaaS and self-hosted installations.  
**Related documents:**
- `docs/architecture_options_202609.md`
- `docs/feature_security_authentication_migration_jwt.md`
- `docs/feature_security_authentication_migration_jwt_implementation.md`
- `docs/feature_security_authentication_migration_jwt_endpoint_guard_checklist.md`
- `docs/feature_completed_security_authentication_migration_jwt_ios_auth_cred_type.md`
- `docs/refactored_security_authentication_shared_auth_function.md`
- `docs/ui_role_matrix.html`

---

## Elevator Pitch

GigHive serves signed-in event organizers, media librarians, platform operators, mobile users, and anonymous QR guests through the same application. A single universal credential rule would either break legitimate guest workflows or allow a privileged login to change what a guest link can access. This policy gives every route a clear authentication class so the right credential, scope, and error behavior are applied consistently today in PHP and later in another backend such as Java.

---

## Business Rationale

GigHive is moving from a self-hosted application, where one administrator owns the installation, to a multi-tenant SaaS platform with distinct viewers, contributors, Local Admins, and Platform Admins. Authentication must therefore establish more than whether a request is signed in. It must also preserve:

- The tenant the caller belongs to.
- The event a QR guest is allowed to access.
- The difference between authenticated administration and anonymous contribution.
- The difference between browser, mobile, API, and media-client behavior.
- The distinction between Local Admin and Platform Admin scope.

A logged-in organizer must be able to test a QR code and see the same restricted experience as a real guest. An invalid guest link must not work merely because the organizer also has a privileged browser session. A media player must receive a media-appropriate HTTP error, not an HTML login page. An API client must receive JSON, not a browser redirect.

Central route classes make those outcomes deliberate, auditable, testable, and portable across backend implementations.

---

## Purpose

Define the authoritative JWT-era policy for:

1. Credential transport by client type.
2. Route classes and accepted credentials.
3. Credential precedence when multiple credentials arrive.
4. Invalid-credential fallback behavior.
5. HTML, JSON, guest, and media error responses.
6. CSRF requirements for cookie-authenticated mutations.
7. Tenant and event scoping.
8. Browser versus iOS/API login behavior.
9. Basic-to-JWT cutover and environment-promotion gates.
10. Security, testing, and operability invariants.

This document defines policy, not endpoint implementation syntax. Existing implementation and checklist documents must not be refactored to this policy until the user reviews and explicitly approves that work.

---

## Current Authentication Model

### Browser administration

Apache Basic Auth currently protects `/admin/`, most `/db/`, and most `/api/` paths. The browser caches Basic credentials and sends them automatically with page navigation, native forms, AJAX, downloads, images, audio, and video.

### Guest routes

Specific Apache exceptions allow QR upload and gallery requests to reach PHP without Basic Auth. PHP validates upload tokens or gallery nonces inside the guest endpoints.

### Media route

`api/media-stream.php` currently evaluates credentials in this order:

1. Basic Auth.
2. Upload token.
3. Gallery nonce.

That order means Basic can win when an authenticated organizer tests a guest media link.

### iOS

The iOS `AuthCredential` type represents one selected credential: Basic, Bearer, or upload token. Current upload logic explicitly makes the QR upload token win over session credentials. Guest gallery requests use their nonce without an Authorization header.

### Current limitations

- Authentication policy is divided among Apache configuration, PHP endpoints, and client-specific branches.
- Error formats vary by enforcement layer.
- Credential precedence is not centrally defined.
- Browser localStorage Bearer cannot authenticate page navigation, native forms, direct downloads, or media elements.
- Existing dynamic HTML sinks make JavaScript-readable privileged tokens inappropriate.

---

## Exact Current Server Surface

### `admin/` — 59 real PHP files

Five macOS `._*` metadata files caused an earlier incorrect count of 64 and are excluded.

| Classification | Count | Required JWT-era treatment |
|---|---:|---|
| `HTML_PAGE` | 9 | Cookie authentication + role/tenant scope + HTML failures |
| `AUTHENTICATED_API` | 38 | Bearer/cookie resolution + role/tenant scope + JSON failures + CSRF for cookie mutations |
| `AUTHENTICATED_DOWNLOAD` | 2 | Bearer/cookie resolution + binary-safe failures |
| `INTERNAL_WORKER` | 7 | Deny HTTP; retain/add CLI-only runtime guard |
| `INTERNAL_LIBRARY` | 3 | Deny direct HTTP or move outside webroot |

Authenticated HTML files:

1. `admin.php`
2. `admin_database_catalog_media_from_folder.php`
3. `admin_database_catalog_promote.php`
4. `admin_database_load_import_csv.php`
5. `admin_database_load_import_media_from_folder.php`
6. `admin_database_load_import_media_from_iphone.php`
7. `admin_system.php`
8. `ai_worker.php`
9. `event_qr.php`

Authenticated downloads:

1. `download_backup.php`
2. `export_media_download.php`

Internal workers:

1. `export_media_worker.php`
2. `export_media_worker_azure.php`
3. `import_manifest_worker.php`
4. `import_media_zip_scan_worker.php`
5. `import_media_zip_worker.php`
6. `import_media_zip_worker_azure.php`
7. `iphone_import_worker.php`

Six workers already reject non-CLI execution. `import_manifest_worker.php` consumes `$argv` but lacks the explicit non-CLI rejection used by the other six; implementation must add it.

Include-only libraries:

1. `admin_media_lib.php`
2. `admin_sys_stats_lib.php`
3. `import_manifest_lib.php`

### `db/` — 14 PHP files

| Classification | Count | Files |
|---|---:|---|
| `PUBLIC` | 1 | `health.php` |
| `HTML_PAGE` | 8 | `ai_tags.php`, `database.php`, `database_catalog.php`, `media_tags.php`, `tag_browser.php`, `upload_form.php`, `upload_form_admin.php`, plus the HTML mode of `singlesRandomPlayer.php` |
| `GUEST_UPLOAD` / authenticated dual-mode HTML | 1 | `upload_form_single.php` |
| `AUTHENTICATED_API` | 4 | `catalog_entry_save.php`, `database_edit_musicians_preview.php`, `database_edit_save.php`, `delete_media_files.php` |
| `DUAL_RESPONSE` | 1 mode within the HTML count | `singlesRandomPlayer.php` returns HTML or JSON; split routes preferred long-term |

### `api/` — 12 PHP files

| Classification | Count | Files |
|---|---:|---|
| `AUTHENTICATED_API` | 4 | `ai_jobs.php`, `tags.php`, `taggings.php`, `uploads.php` |
| `GUEST_GALLERY` | 5 | `guest-delete.php`, `guest-gallery.php`, `guest-report.php`, `guest-status.php`, `guest-stream.php` |
| `GUEST_UPLOAD` | 1 | `upload-token.php` |
| Mixed guest/auth upload | 1 | `tus-upload.php` |
| `MIXED_MEDIA` | 1 | `media-stream.php` |

Additional surfaces:

- `src/index.php` — mixed guest/auth upload front controller.
- `timeline/timeline-api.php` — `PUBLIC`.
- `src/Jobs/*.php` — `INTERNAL_WORKER`.
- `src/` implementation classes and `vendor/` — `INTERNAL_LIBRARY`; deny direct HTTP. The endpoint guard checklist must enumerate the exact directly addressable file/path set before implementation approval; this policy does not claim that broad directory labels are a complete file manifest.
- Root and registration routes remain public only where explicitly classified `PUBLIC` or `LOGIN`.

---

## Confirmed Decisions

1. **Browser credential:** A signed-in web browser uses a secure HttpOnly JWT cookie. The JWT is not stored in localStorage or sessionStorage.
2. **iOS/API credential:** iOS and programmatic API clients use `Authorization: Bearer <JWT>`.
3. **Centralized policy:** Endpoints declare a route class; one shared server resolver implements credential parsing, precedence, validation, scope, and failure behavior.
4. **Guest intent:** An explicitly supplied QR upload token or gallery nonce is authoritative for its guest route and cannot silently fall back to a more privileged browser/API identity.
5. **Invalid explicit credential:** If a request explicitly supplies a credential accepted by its route class and that credential is invalid, the request fails. It does not fall back to another credential with broader authority.
6. **Role enforcement:** Credential resolution establishes identity and scope; `requireRole()` separately enforces viewer, contributor, owner, or platform-admin authority.
7. **Cutover:** No production Basic/JWT overlap period is required. Each environment performs an atomic Basic-to-JWT cutover only after the preceding environment passes.
8. **Promotion:** Implementation is completed and fully validated in dev, then the same release is promoted through sequential gates: dev → lab → staging → production.

---

## Industry Precedent

The policy follows common web/API separation patterns:

- Server-rendered browser applications use secure HttpOnly cookies so navigation, forms, downloads, and media requests carry credentials automatically.
- Native mobile and programmatic clients use Bearer tokens because they control request headers directly.
- Browser-facing API calls can use the same HttpOnly cookie but require CSRF protection for unsafe methods.
- Explicit guest capabilities, such as signed links, upload tokens, or nonces, remain scoped capabilities rather than silently inheriting a signed-in user's broader authority.
- Authentication parsing is centralized, while endpoints declare the policy appropriate to their route purpose.

This pattern is independent of PHP, Java, Go, or another backend runtime.

---

## Credential Types

| Credential | Transport | Primary clients | Scope source | JavaScript-readable |
|---|---|---|---|---|
| Browser JWT | Secure HttpOnly cookie | Desktop/mobile web browser | JWT tenant, role, subject, expiry claims | No |
| API JWT | `Authorization: Bearer` | iOS, programmatic clients, administrative tools | JWT tenant, role, subject, expiry claims | Controlled by client |
| QR upload token | Route token or `X-Upload-Token` | Anonymous browser/iOS contributor | Token-bound event and permitted upload capabilities | Yes where required by guest client |
| Gallery nonce | Query/body/header according to endpoint contract | Anonymous browser/iOS gallery viewer | Nonce-bound event, uploader, and expiry | Yes where required by guest client |
| Apache Basic | `Authorization: Basic` | Current browser/iOS migration source only | htpasswd username | Browser-managed |

Apache Basic is not part of the final resolver policy. It remains authoritative only before an environment performs its atomic JWT cutover.

---

## Proposed Browser Cookie Contract

The exact variable names and values must be finalized in the implementation plan and declared in all applicable Ansible group vars. The recommended contract is:

| Attribute | Recommended policy | Rationale |
|---|---|---|
| Name | `__Host-gighive_session` | `__Host-` requires Secure, host-only scope, Path `/`, and no Domain attribute |
| Value | Signed JWT | Reuses the same validation and claims contract as Bearer clients |
| `HttpOnly` | Required | Prevents JavaScript from reading the JWT |
| `Secure` | Required | Prevents transmission over plaintext HTTP |
| `SameSite` | `Lax` initially | Blocks cross-site unsafe-method cookie sending while allowing normal top-level links; verify all login and QR entry flows |
| `Path` | `/` | Required by `__Host-`; supports pages, APIs, downloads, and media |
| `Domain` | Omitted | Required by `__Host-`; prevents subdomain-wide scope |
| Lifetime | No longer than JWT expiry | Cookie must not outlive the signed credential |
| Rotation | Rotate on login and privilege change | Reduces session fixation and stale-role risk |
| Clearing | Same name/path/security attributes | Ensures logout actually removes the cookie |

`SameSite` provides defense in depth but does not replace explicit CSRF protection.

### HTTPS prerequisite

JWT browser-cookie mode requires HTTPS in dev, lab, staging, production, and supported self-hosted deployments. The `__Host-` prefix and `Secure` attribute must not be weakened to support plaintext HTTP. Development and self-hosted environments must provide a trusted or explicitly managed TLS endpoint before browser JWT authentication is enabled; otherwise the environment remains in its pre-cutover authentication mode.

---

## Route-Class Design

Endpoints declare one of the following route classes. The resolver implements the class centrally; endpoints do not parse credentials independently.

| Route class | Purpose | Examples |
|---|---|---|
| `PUBLIC` | Deliberately unauthenticated content | Homepage, loops, public timeline, registration entry point |
| `LOGIN` | Browser or API session establishment/termination | Browser login page, browser session endpoint, API/iOS token endpoint, logout |
| `HTML_PAGE` | Authenticated server-rendered browser page | Admin system, event QR management, database/catalog/tag pages |
| `AUTHENTICATED_API` | JSON endpoint used by browser AJAX, iOS, or tools | AI jobs, tags, taggings, admin status and mutation endpoints |
| `AUTHENTICATED_DOWNLOAD` | Binary/file response initiated by browser navigation or an API client | Database backup and media-export downloads |
| `GUEST_UPLOAD` | QR-token-scoped upload and finalization | QR upload landing, TUS upload, upload finalization |
| `GUEST_GALLERY` | Nonce-scoped gallery/status/report/delete operations | Guest gallery/status/report/delete/stream endpoints |
| `MIXED_MEDIA` | Media delivery supporting guest and authenticated access | Media stream and rewritten media/audio/video paths |
| `DUAL_RESPONSE` | One legacy controller currently returns HTML or JSON based on an explicit mode | `db/singlesRandomPlayer.php`; split routes are preferred long-term |
| `INTERNAL_WORKER` | Non-HTTP worker execution | Cron/queue workers invoked inside the deployment |
| `INTERNAL_LIBRARY` | Include-only PHP/source/vendor code with no direct HTTP contract | Admin libraries, `src/` implementation classes, and `vendor/` |

Route class does not replace minimum-role assignment. An `HTML_PAGE` can require viewer, contributor, owner, or platform-admin authority.

### Dual-response rule

`DUAL_RESPONSE` is an interim compatibility classification, not a preferred pattern. The endpoint must select its declared response mode before authentication and apply the corresponding `HTML_PAGE` or `AUTHENTICATED_API` response policy. It must not infer security behavior from the `Accept` header alone. Where practical, split HTML and JSON into separate routes during implementation.

### Direct-access-denied rule

`INTERNAL_WORKER` and `INTERNAL_LIBRARY` are not JWT-authenticated web routes. Direct HTTP access is denied before execution. CLI workers retain an explicit `PHP_SAPI === 'cli'` check as defense in depth; include-only code should be moved outside the webroot where practical.

---

## Credential Precedence Matrix

| Route class | Credential order | Invalid explicit credential | Cookie behavior |
|---|---|---|---|
| `PUBLIC` | None required | N/A | Ignore for authorization; response remains public |
| `LOGIN` | Endpoint-specific login/logout contract | Fail without fallback | Existing cookie may be cleared/replaced only by the session contract |
| `HTML_PAGE` | Browser JWT cookie | Clear invalid cookie; do not use Bearer fallback for ordinary browser navigation | Required for authenticated browser page |
| `AUTHENTICATED_API` | Explicit Bearer; otherwise browser JWT cookie | Invalid explicit Bearer → `401`; no cookie fallback | Accepted for same-origin browser AJAX; unsafe methods require CSRF |
| `AUTHENTICATED_DOWNLOAD` | Explicit Bearer for an API client; otherwise browser JWT cookie | Invalid explicit Bearer → `401`; no cookie fallback | Accepted for browser navigation; no login redirect may replace a binary response |
| `GUEST_UPLOAD` | Explicit QR upload token; otherwise Bearer; otherwise browser JWT cookie | Invalid supplied upload token fails; no authenticated fallback | Used only when no QR token was supplied and route permits authenticated upload |
| `GUEST_GALLERY` | Gallery nonce only | Invalid/expired nonce fails | Ignored for guest authorization |
| `MIXED_MEDIA` | Explicit gallery nonce; explicit upload token where supported; otherwise Bearer; otherwise browser JWT cookie | Invalid supplied guest/Bearer credential fails; no broader fallback | Used only when no explicit guest/Bearer credential was supplied |
| `DUAL_RESPONSE` | Apply `HTML_PAGE` or `AUTHENTICATED_API` policy according to the endpoint's explicit response mode | Apply the selected policy; no cross-mode fallback | Cookie accepted in both modes; Bearer accepted only by JSON/API mode |
| `INTERNAL_WORKER` | No HTTP credential; trusted invocation boundary | Any HTTP request denied | Not applicable |
| `INTERNAL_LIBRARY` | No HTTP credential | Any HTTP request denied | Not applicable |

### Precedence invariant

The resolver must distinguish **credential absent** from **credential supplied but invalid**. Fallback is permitted only when the higher-priority credential is absent, never when it is present and invalid.

### Route intent invariant

Credential strength does not override route intent. A privileged browser cookie does not broaden a request explicitly made through a QR guest route.

---

## Response Policy

| Route/request type | Unauthenticated | Authenticated but unauthorized | Expired/invalid credential |
|---|---|---|---|
| HTML GET navigation | Redirect to browser login with validated relative `next` path | HTML `403` | Clear invalid cookie, then redirect to login |
| HTML unsafe method | HTML `401` session-expired response; do not replay mutation automatically | HTML `403` | Clear invalid cookie; action is not executed |
| JSON API | JSON `401` | JSON `403` | JSON `401` with stable machine-readable code |
| Authenticated download | HTTP `401` without login-page substitution | HTTP `403` | No redirect or HTML body substituted for the file |
| QR guest endpoint | Guest-format `401`/`403` according to existing contract | Guest-format `403` | No login redirect |
| Media request | HTTP `401`/`403`; preserve Range semantics | HTTP `403` | No login redirect and no HTML response body substituted for media |
| Dual-response endpoint | Apply HTML or JSON row according to explicit mode | Apply selected mode | No content negotiation based only on incidental headers |
| Internal worker/library over HTTP | Denied before execution | Denied | Never redirected or executed |
| Public route | Public response | N/A | Incidental invalid cookie does not make public content unavailable |

### Safe login destination

The browser login flow accepts only a same-origin relative path:

- Must begin with one `/`.
- Must not begin with `//`.
- Must contain no scheme or host.
- Must contain no control characters.
- Invalid destinations fall back to the default authenticated landing page.

Unsafe POST bodies are never stored and replayed after login.

---

## Browser and API Login Separation

### Browser login/session endpoint

- Accepts browser credentials over TLS.
- Sets the HttpOnly JWT cookie.
- Does not return the JWT to browser JavaScript.
- Rotates any prior browser credential.
- Redirects only to a validated same-origin relative destination.

### API/iOS login endpoint

- Returns the JWT in a JSON response for the explicit API client.
- Does not set the browser session cookie.
- iOS stores the JWT using its secure credential store and sends Bearer headers.

### Logout

- Browser logout clears the cookie with identical name, Path, Secure, SameSite, and Domain behavior.
- Logout is an unsafe operation protected against CSRF.
- Clearing the cookie terminates the browser session locally.
- Server-side early revocation, token-version claims, or a denylist remains an implementation decision; short JWT lifetime is the baseline containment mechanism.

Separating browser and API login responses prevents iOS `URLSession` from acquiring an unintended browser cookie alongside its Bearer token.

---

## CSRF Policy

The browser JWT cookie is attached automatically, so every cookie-authenticated state-changing request requires CSRF protection.

### Required behavior

1. Safe methods (`GET`, `HEAD`, `OPTIONS`) do not change state.
2. Unsafe methods (`POST`, `PUT`, `PATCH`, `DELETE`) authenticated by browser cookie require a valid CSRF token.
3. Native forms send the token in a hidden field.
4. Browser AJAX sends the token in an `X-CSRF-Token` header through centralized request behavior.
5. Bearer-authenticated API/iOS requests do not require the browser CSRF token because the credential is not attached automatically.
6. QR-token/nonce requests use their explicit capability credential and existing endpoint validation; they do not inherit browser-cookie authority.
7. Missing or invalid CSRF token returns `403` and performs no mutation.
8. `Origin` validation is added as defense in depth for browser-cookie unsafe requests where browser behavior permits it.

### CSRF token design to finalize

The implementation plan must choose one centralized design and document key management and validation. Preferred direction: a server-generated token bound to the authenticated browser JWT identity/session and rendered into HTML forms/meta without exposing the JWT itself. Do not implement endpoint-specific CSRF algorithms.

---

## Identity, Role, Tenant, and Event Scope

Authentication resolution returns a structured context, conceptually:

```text
credential type
subject/user identifier
role
JWT identifier and expiry
tenant identifier
optional guest event identifier
optional uploader/nonce scope
route class
```

Authorization then applies:

1. Minimum role.
2. Tenant boundary.
3. Event/resource ownership.
4. Guest capability restrictions.
5. Platform-admin-only scope where explicitly permitted.

Platform Admin status does not automatically override an explicit guest route. Platform operations should use authenticated platform routes, not guest endpoints.

---

## Failure and Fallback Rules

1. A malformed or invalid explicit Bearer JWT returns `401`; do not fall back to the browser cookie.
2. An invalid explicit QR upload token fails; do not fall back to Bearer or cookie.
3. An invalid gallery nonce fails; do not fall back to authenticated tenant access.
4. An invalid browser cookie is cleared before HTML login redirect.
5. APIs never redirect to login.
6. Media endpoints never substitute an HTML login page.
7. Public endpoints remain public even when an incidental invalid cookie is present.
8. Authentication errors never log raw credentials, JWTs, cookies, upload tokens, or nonces.

---

## Real-World Use Cases

| Persona and action | Credentials present | Required result |
|---|---|---|
| Local Admin opens admin system page | Browser JWT cookie | Resolve cookie; require owner; enforce tenant scope |
| Local Admin tests an event QR upload in same browser | Cookie + QR upload token | QR token is authoritative; upload remains event-scoped guest contribution |
| Local Admin tests an expired gallery link | Cookie + expired nonce | Guest request fails; cookie does not hide the broken link |
| Anonymous guest opens gallery | Valid nonce | Event-scoped gallery response; no account required |
| Media librarian calls tags API from web page | Browser cookie + CSRF on mutation | Cookie identity; role/tenant authorization; JSON response |
| iOS authenticated viewer calls database API | Bearer JWT | Bearer identity; viewer role; tenant-scoped JSON |
| iOS QR contributor uploads | Upload token; no Bearer for request | Token-scoped upload; current iOS exclusive-credential behavior preserved |
| Media player loads authenticated media | Browser cookie or Bearer | Media response with Range support; no redirect |
| Guest media player loads nonce URL while organizer cookie exists | Cookie + nonce | Nonce controls guest event scope |
| API tool sends invalid Bearer while browser cookie exists | Invalid Bearer + valid cookie | `401`; no fallback |
| Platform Admin follows public homepage link | Cookie incidental | Public response remains public; no privileged data added |

---

## Exact XSS Hardening Scope

The source audit found 115 `innerHTML` assignments across 15 files. One is vendored `qrcode.min.js`; many others use static markup, numeric values, or correctly escaped dynamic content. The four application files below have proven unescaped dynamic API/database values and require remediation before privileged browser-cookie authentication is accepted as complete:

1. `admin/admin_system.php` — unescaped endpoint messages, errors, and error arrays.
2. `admin/ai_worker.php` — `ai_jobs.error_msg` interpolated directly into generated HTML.
3. `admin/admin_database_load_import_csv.php` — endpoint success and error messages inserted without consistent escaping.
4. `db/media_tags.php` — AJAX `job.error_msg` reaches `innerHTML` through progress rendering.

Remediation policy:

- Prefer `textContent` and DOM construction.
- Use one reviewed escaping helper only where formatted HTML is unavoidable.
- Do not edit vendored minified code directly.
- Add a permanent regression test for each remediated dynamic data class.
- Introduce CSP in report-only mode before enforcement; do not claim meaningful script-injection protection while broad `'unsafe-inline'` remains enabled.

Reviewed files whose current dynamic data is escaped or locally constrained do not enter the proven modification set solely because they contain `innerHTML`. The implementation must re-run the sink search after changes and treat any newly discovered unescaped dynamic sink as additional scope.

---

## Benefits / Potential Drawbacks

| Benefits | Potential drawbacks |
|---|---|
| One auditable policy for all route families | More policy classes and tests than one universal order |
| Preserves QR guest scope when an admin cookie is present | Endpoints must be classified accurately |
| Browser navigation/forms/downloads/media work naturally with cookie authentication | Cookie-authenticated mutations require CSRF controls |
| JavaScript cannot read the browser JWT | XSS can still perform actions through the victim's browser, so XSS/CSP hardening remains necessary |
| iOS and integrations retain standard Bearer authentication | Two credential transports require one carefully designed resolver |
| Error format matches client type | HTML/API/media response detection must be explicit, not guessed from fragile headers alone |
| Same policy can move from PHP to Java | Existing Apache/PHP behavior and documentation require broad reconciliation |

---

## Alternatives Considered

### Universal credential order

Rejected because credential strength alone does not express route intent. It can allow a privileged cookie to hide an invalid guest link or broaden a QR-scoped request.

### Endpoint-specific authentication

Rejected because it duplicates parsing, fallback, error, CSRF, and scope logic across many endpoints and increases migration/audit risk.

### Browser localStorage Bearer

Rejected because JavaScript-readable tokens are exposed to same-origin XSS, and localStorage cannot authenticate server-rendered navigation, native forms, downloads, images, audio, or video.

### Basic/JWT overlap in each environment

Rejected because no production users/sessions need preservation, and Apache Basic plus PHP Bearer require incompatible uses of the single Authorization header. Sequential environment promotion provides safer evidence without dual authentication inside one environment.

---

## Environment Cutover Policy

Implementation occurs step-by-step in dev. Applicable permanent tests are added as each implementation step lands.

### Development

1. Implement one approved phase/step.
2. Add or update permanent tests.
3. The user deploys to dev.
4. Run applicable post-build, browser, upload, and iOS tests.
5. Mark the step complete only after it passes.
6. Complete all JWT phases and the full suite in dev before promotion.

### Sequential promotion gates

```text
Dev fully validated
  -> Lab deploy and full applicable checks
  -> Staging deploy and full regression checks
  -> Production controlled cutover and safe checks
```

Rules:

- Promote the same reviewed release/configuration forward.
- Each environment must pass before the next environment is deployed.
- Capture a rollback state before each environment cutover.
- Each environment switches atomically from Basic to JWT; it does not run a Basic/JWT overlap period.
- A failure stops promotion and triggers rollback or correction in the failed environment.
- The user runs all Ansible playbooks.

---

## Java and Future-Backend Portability

The policy is runtime-independent:

- Browser cookie and API Bearer contain the same JWT claims contract.
- Route class is an application policy concept, not a PHP construct.
- Credential resolution returns a normalized authentication context.
- Role, tenant, event, and capability authorization operate on that context.
- A Java service can implement the same resolver and route-class declarations.
- Existing browser and iOS clients do not need another credential migration solely because the backend language changes.

A future Java migration should preserve the contract rather than copy PHP endpoint-specific behavior.

---

## Security Invariants

1. Browser JWT is never available to JavaScript.
2. Raw credentials and capability tokens are never logged.
3. Invalid explicit credentials never fall back to broader authority.
4. Guest routes never gain tenant-wide access from an incidental cookie.
5. Cookie-authenticated unsafe requests require CSRF validation.
6. Public routes never expose additional data because a cookie is present.
7. API and media requests never receive login redirects.
8. Tenant and event scope are enforced server-side after authentication.
9. Role names and levels come from one authoritative hierarchy.
10. Apache Basic is removed only after the target environment passes its JWT cutover gate.
11. Authenticated downloads never substitute HTML login content for the requested file.
12. Internal workers, include-only libraries, implementation classes, and vendor PHP cannot execute through direct HTTP access.
13. A dual-response controller selects its declared HTML or JSON mode before applying authentication response behavior.

Each invariant requires a corresponding permanent automated test before implementation is marked complete.

---

## Required Test Matrix

Test identifiers remain assigned by the implementation documents after checking the shared project T-number namespace.

| Route class / behavior | Required test |
|---|---|
| `HTML_PAGE` | Valid cookie loads page; missing/expired cookie redirects safely; wrong role returns HTML `403` |
| `AUTHENTICATED_API` | Valid Bearer and valid cookie succeed independently; missing/invalid returns JSON `401`; wrong role JSON `403` |
| Explicit invalid Bearer + valid cookie | Must return `401`; prove no fallback |
| `GUEST_UPLOAD` | Valid token succeeds with/without incidental cookie; invalid token + valid cookie fails |
| `GUEST_GALLERY` | Valid nonce succeeds with/without incidental cookie; invalid nonce + valid cookie fails |
| `MIXED_MEDIA` | Nonce, upload-token, Bearer, and cookie paths work independently; invalid explicit credential does not fall back; Range preserved |
| `AUTHENTICATED_DOWNLOAD` | Browser cookie and explicit Bearer download paths succeed independently; unauthenticated/invalid requests return status without login HTML or partial file content |
| `DUAL_RESPONSE` | Explicit HTML mode uses HTML failure behavior; explicit JSON mode uses JSON failure behavior; incidental `Accept` changes do not alter authorization scope |
| `INTERNAL_WORKER` | Direct HTTP denied for all seven admin workers and `src/Jobs`; CLI invocation remains functional; `import_manifest_worker.php` has explicit CLI guard |
| `INTERNAL_LIBRARY` | Direct HTTP denied for three admin libraries, `src/` implementation files, and `vendor/` PHP |
| `PUBLIC` | Public response succeeds with no credential and with invalid incidental cookie; response scope unchanged |
| Browser cookie | HttpOnly, Secure, host-only, Path `/`, expected SameSite; absent from JavaScript/localStorage/sessionStorage |
| CSRF | Cookie-authenticated unsafe request succeeds with valid token and returns `403` with missing/invalid token; no mutation occurs |
| Login redirect | Relative same-origin path succeeds; absolute, protocol-relative, and control-character destinations are rejected |
| Browser/API login split | Browser login sets cookie and does not return JWT to JS; API login returns JWT and does not set browser cookie |
| Logout | Cookie clears with matching attributes; subsequent protected request fails |
| Error formats | HTML, JSON, guest, and media routes return the policy-defined response type |
| Tenant/event scope | Same identifier in another tenant/event remains inaccessible |
| Role hierarchy | Viewer, contributor, owner, and platform-admin requests prove allowed and denied boundaries from the one authoritative hierarchy |
| Credential logging | Deliberately trigger invalid Bearer, cookie, upload-token, and nonce requests; verify raw credential values are absent from application, Apache, and audit logs |
| Environment promotion | Applicable suite passes in dev, lab, staging, then production in order |

---

## Operability Requirements

- Log route class, credential type, outcome, role result, tenant/event scope identifier, and request correlation ID without logging credential material.
- Distinguish `401` from `403` metrics.
- Track invalid/expired credential counts by route class.
- Track CSRF rejection counts.
- Preserve Apache/PHP request correlation through rewritten media paths.
- Provide rollback instructions for each environment cutover.
- Make cookie/JWT expiry and key-rotation behavior observable.
- Ensure CSP reporting and XSS remediation are completed before privileged SaaS users are onboarded.

---

## Open Implementation Decisions

The policy direction is confirmed, but implementation must still finalize:

1. JWT lifetime and renewal behavior.
2. Early revocation mechanism, if any.
3. CSRF token derivation, storage, and rotation.
4. Exact PHP route-class API and normalized authentication-context type.
5. Exact HTML session-expiry page/redirect behavior for unsafe requests.
6. Exact stable JSON error codes.
7. Cookie/JWT key rotation and backward-validation window.
8. CSP report collection and final enforcement policy.
9. Complete endpoint-to-route-class inventory.
10. Complete test identifiers and owning Ansible/iOS test roles.
11. Login endpoint rate limiting — `api/login.php` and `auth/login.php` are credential-bearing POST endpoints. A brute-force protection mechanism (e.g., per-IP attempt count in MySQL, ModSecurity request limiting, or a PHP exponential-backoff lockout) must be defined and tested before production rollout. The security audit log records failed auth attempts for visibility; active rate limiting is the prevention layer and must be specified in `feature_security_authentication_migration_jwt_implementation.md` Phase 1.

These are implementation decisions, not permission to revisit the confirmed browser-cookie, API-Bearer, centralized route-class, or sequential-gate decisions without new evidence.

---

## Documentation Reconciliation Plan — Approval Required Per Step

The user approved the reconciliation sequence but requires a pause and explicit approval between document steps:

- [x] **Step 4.1** — Refine this canonical route-policy document and run its focused PPRR.
- [x] **Step 4.2** — Add a superseded notice to `security_auth_jwt_token_migration.md`; preserve its historical body.
- [x] **Step 4.3** — Correct future-migration narrative in `feature_completed_security_authentication_migration_jwt_ios_auth_cred_type.md` without changing completed Swift scope.
- [x] **Step 4.4** — Correct `feature_security_authentication_migration_jwt_oidc_benefits.md` while retaining its business rationale.
- [x] **Step 4.5** — Reconcile strategic policy, phases, cutover, and tests in `feature_security_authentication_migration_jwt.md`.
- [x] **Step 4.6** — Replace localStorage/overlap browser design and add route-policy implementation in `feature_security_authentication_migration_jwt_implementation.md`.
- [x] **Step 4.7** — Rebuild `feature_security_authentication_migration_jwt_endpoint_guard_checklist.md` around exact route classes, credentials, roles, scopes, CSRF, and responses.
- [x] **Step 4.8** — Reconcile browser-cookie and API/iOS Bearer behavior in `feature_security_authentication_migration_jwt_oidc_phase5.md`.
- [x] **Step 4.9** — Align `refactored_security_authentication_shared_auth_function.md` with resolved policy decisions, exact counts, atomic cutover, and tests.
- [x] **Step 5** — Search all documentation for stale or conflicting authentication guidance.
- [x] **Step 6** — Run the full cross-document PPRR. Completed 2026-09-09. One finding: login endpoint rate limiting added as Open Implementation Decision #11. All other checks passed.

Only the currently approved step may be edited; approval does not carry forward automatically.

---

## Files Under Change

### New

1. `docs/policy_authentication_credential_route.md` — This policy document only.

### Modified

None. Existing JWT and refactor documents remain unchanged pending review and explicit approval.
