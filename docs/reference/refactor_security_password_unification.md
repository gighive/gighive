# Password Change Unification — Options & Rationale

## Problem

There are currently two independent password change mechanisms that operate on the same
`gighive.htpasswd` file but diverge in meaningful ways:

| | Shell path (`install.sh` / `rotate_basic_auth.sh`) | PHP web path (`admin.php`) |
|---|---|---|
| Tool | Apache `htpasswd` binary (via `docker run httpd:2.4`) | PHP `password_hash(..., PASSWORD_BCRYPT)` |
| Hash written | `$apr1$...` (Apache MD5, default for `htpasswd -b`) | `$2y$...` (bcrypt) |
| Min length enforced | **12 characters** (`MIN_PASSWORD_LEN=12`) | **8 characters** (`strlen($pw) < 8`) |
| When it runs | Before / outside container | Inside running container |

Both hash formats are valid in Apache's `.htpasswd` (`mod_authn_file` accepts both), but whoever
runs last wins for all three accounts — so the hash format in the live file depends on which path
was used most recently. This is a latent inconsistency waiting to matter.

The minimum-length discrepancy means a user can set a shorter password via the web UI than the
installer would ever accept, which is the more immediately visible gap.

---

## Files Involved

| File | Role |
|---|---|
| `ansible/roles/docker/templates/install.sh.j2` | Rendered → one-shot-bundle `install.sh`; sets passwords at install time before container is running |
| `ansible/roles/docker/files/one_shot_bundle/rotate_basic_auth.sh` | Static shell script; rotates BasicAuth passwords on a running install via `docker run` |
| `ansible/roles/docker/files/apache/webroot/admin/admin.php` | Web UI password change; runs inside the container; uses PHP `password_hash()` |

---

## Benefit — Limited!

Option C is already implemented, which was the only change with a real security impact
(aligning the enforcement gap between install.sh and the web UI). What remains in Options A
and B is code hygiene only.

**Option A's sole concrete benefit:**
Hash format consistency — `$apr1$` (Apache MD5) from `install.sh` vs `$2y$` (bcrypt) from
`admin.php`. Apache handles both today without issue. This only matters if Apache config is
ever tightened to require a specific algorithm, or if a security audit flags mixed hash types.
Neither is likely soon.

**Option B's sole concrete benefit:**
Removes the `docker run httpd:2.4` invocation in `rotate_basic_auth.sh` — currently rotating
passwords requires spinning up a temporary Docker container just to hash a string. That's
mildly wasteful but works fine.

**Neither option:**
- Fixes a user-visible problem
- Improves security beyond what Option C already delivered
- Reduces a real operational risk

**Recommendation:** Park both A and B. The system is functional, Option C closed the real gap,
and the remaining divergence is cosmetic. The cost (new PHP shell invocation or new HTTP
endpoint + bash curl handling) outweighs the benefit for a small containerized app where
password changes are rare events. Revisit Option A if a specific trigger arises — Apache
algorithm enforcement, a security audit finding, or `rotate_basic_auth.sh` becoming genuinely
painful in practice. The options are documented here so the decision context is preserved.

---

## Option A vs Option B — Pros & Cons

| | Option A | Option B |
|---|---|---|
| **Fixes hash format divergence** | Yes — `$2y$` bcrypt everywhere | No — install.sh still `$apr1$` |
| **New HTTP surface** | No | Yes (BasicAuth-gated endpoint) |
| **Shell invocation in PHP** | Yes (`proc_open` + `escapeshellarg`) | No |
| **Container must be up for rotate** | No | Yes — `rotate_basic_auth.sh` requires stack running |
| **`rotate_basic_auth.sh` complexity** | Low change (add `-B` flag) | High (replace docker run with curl + JSON handling) |
| **Files changed** | `admin.php` + 2 shell scripts | `admin.php` + new endpoint + `rotate_basic_auth.sh` |
| **Federated auth readiness** | Worse (deeper htpasswd coupling in PHP) | Marginally better (API abstraction point) |

**Option A** is the better fit if hash consistency matters or minimizing new surfaces is the priority.
**Option B** is better if keeping PHP free of shell invocations is a hard rule, or if federated auth is a near-term goal.

---

## Option A — PHP calls `htpasswd` binary directly (full mechanical unification)

**Rationale:** The `htpasswd` binary is already present inside the Apache container at
`/usr/local/apache2/bin/htpasswd`. By having `admin.php` call it (with the `-n` flag to emit the
hash to stdout rather than write a file) we get the same hash format from all three paths.
`write_htpasswd_atomic` stays untouched — only hash generation changes.

### What changes

**`admin/admin.php`**
- `validate_password()` line 141: `strlen($pw) < 8` → `strlen($pw) < 12` (align min length)
- Lines 181–183: Replace the three `password_hash(...)` calls with a helper that runs
  `/usr/local/apache2/bin/htpasswd -nbB <user> <pass>` via `proc_open` + `escapeshellarg`,
  parses the `user:hash` stdout line, and extracts the hash portion. `write_htpasswd_atomic`
  is unchanged — it still receives the `$map` array and writes the file atomically.

**`ansible/roles/docker/templates/install.sh.j2`**
- Lines 194–196: Add `-B` flag to all `htpasswd` invocations to force bcrypt output, so all three
  paths produce `$2y$...` hashes consistently.

**`ansible/roles/docker/files/one_shot_bundle/rotate_basic_auth.sh`**
- Lines 100–102: Same `-B` flag addition as above.

**Complexity:** Medium. The `proc_open` + stdout-parse approach in PHP is a few more lines and
requires careful escaping. The `write_htpasswd_atomic` backup/atomic logic is preserved as-is.
`install.sh` is still a separate code path (container not running at install time) — that's
unavoidable.

---

## Option B — Shell scripts call a PHP API endpoint post-install (partial unification)

**Rationale:** Add a dedicated `/admin/api/change_passwords.php` endpoint that accepts admin
credentials + new passwords via `POST`. `rotate_basic_auth.sh` `curl`s this endpoint instead of
running `docker run httpd:2.4` directly.

### What changes

**New file: `admin/api/change_passwords.php`**
- Accepts `POST` with BasicAuth (admin credentials) + JSON body of new passwords.
- Validates (12-char minimum).
- Writes htpasswd using the same `write_htpasswd_atomic` path already in `admin.php`.

**`ansible/roles/docker/files/one_shot_bundle/rotate_basic_auth.sh`**
- Replace `docker run httpd:2.4 htpasswd ...` block with a `curl` call to the endpoint.
- Reads response JSON to determine success/failure.

**`admin/admin.php`**
- `validate_password()` line 141: `8` → `12` (align min length, separate from the API endpoint).

**Complexity:** Medium-high. `install.sh` is **not** unified here — it still uses the htpasswd
binary — so hash format divergence between install-time and post-install changes remains.
Also adds a new unauthenticated-from-outside (but BasicAuth-gated) HTTP surface.

---

## Option C — Policy-only alignment (minimal, no mechanism change) ✅ IMPLEMENTED

**Rationale:** The mechanism divergence (htpasswd vs `password_hash`) is tolerable since Apache
handles both hash formats. The more visible and operationally confusing gap is the minimum length
difference. Align the PHP side to the installer's policy with a one-statement change.

### Scope of alignment

`install.sh` enforces 12 characters on **5 passwords**; `admin.php` only covers **3 of them**:

| Password | `install.sh` (12-char) | `admin.php` web UI | `rotate_basic_auth.sh` |
|---|---|---|---|
| `admin` | ✓ | ✓ | ✓ |
| `uploader` | ✓ | ✓ | ✓ |
| `viewer` | ✓ | ✓ | ✓ |
| `MYSQL_PASSWORD` | ✓ (line 169) | ✗ no UI | ✗ |
| `MYSQL_ROOT_PASSWORD` | ✓ (line 170) | ✗ no UI | ✗ |

`MYSQL_PASSWORD` and `MYSQL_ROOT_PASSWORD` are intentionally excluded from the web UI.
This is an explicit architectural boundary, not an oversight:

- **Attack surface (OWASP ASVS v4.0 §2.10):** Service/infrastructure credentials must not be
  changeable through the application UI. If admin BasicAuth is ever compromised, a MySQL password
  change UI hands the attacker the database too. ASVS §2.10 separates *application credentials*
  (BasicAuth — appropriate for UI management) from *service credentials* (database passwords —
  ops tooling only).
- **Broken intermediate state risk:** A MySQL password change requires three sequential steps:
  `ALTER USER` in MySQL → rewrite `.env` files → restart the Apache container. A mid-sequence
  failure leaves the app down with a credential mismatch that cannot be recovered from the UI
  that just broke itself.
- **Self-destructive restart (12-Factor App, Factor III):** phpdotenv loads env at container
  startup. The UI would have to restart its own container to apply the new password — a web page
  triggering its own process restart is inherently unreliable.

References: OWASP ASVS 4.0 §2.10 (https://owasp.org/www-project-application-security-verification-standard/),
OWASP Top 10 A05:2021 (Security Misconfiguration), 12-Factor App Factor III (https://12factor.net/config).

**How to change MySQL passwords post-install:** backend CLI operation only — update
`mysql/externalConfigs/.env.mysql` and `apache/externalConfigs/.env` directly on the host,
run `ALTER USER` in MySQL, then restart both containers.

### What changes

**`ansible/roles/docker/files/apache/webroot/admin/admin.php`**
- Line 141 — one statement; both the numeric guard and the error message string update:
  ```php
  // before
  if (strlen($pw) < 8)   $e[] = "$label password must be at least 8 characters.";
  // after
  if (strlen($pw) < 12)  $e[] = "$label password must be at least 12 characters.";
  ```

No other files change. The hash format divergence and the MySQL password gap both remain as
known/accepted quirks.

**Complexity:** Trivial. One statement.

---

## Comparison Summary

| | Hash format unified | Min length unified | install.sh unified | Complexity |
|---|---|---|---|---|
| **Option A** | Yes (`$2y$` everywhere) | Yes (12) | No (impossible) | Medium |
| **Option B** | No | Yes (12) | No (impossible) | Medium-high |
| **Option C** | No | Yes (12) | No (impossible) | Trivial |

---

## Federated Authentication Consideration

Neither Option A nor Option B meaningfully prepares for a future move to federated
authentication (OAuth2/OIDC via `mod_auth_openidc`, SAML, LDAP, etc.).

With federated auth, `gighive.htpasswd` is replaced entirely by an external identity
provider. Both the password change UI in `admin.php` and `rotate_basic_auth.sh` become
dead code — the auth mechanism is swapped out at the Apache layer, not extended.

**Option A** makes the PHP layer *more* coupled to htpasswd by adding a binary invocation.
That coupling is entirely removed when federated auth arrives.

**Option B** is marginally better: the `/admin/api/change_passwords.php` endpoint is an
abstraction point — its *implementation* could be replaced (or stubbed to a no-op) when
user management moves to the IdP. The shell script calling an HTTP endpoint is also a
pattern that could survive an implementation swap behind the API. However, the endpoint
would still be deleted/replaced in full, so the advantage is limited.

**What would actually prepare for federated auth** is a separate, larger refactor:
abstracting the auth provider behind a PHP interface (e.g., `AuthProviderInterface` with a
`HtpasswdAuthProvider` today and an `OIDCAuthProvider` later). Neither option here does
that. Given the target users (musicians, wedding videographers running a local install),
federated auth is likely a distant concern — Option C is sufficient for now, and the
federated auth abstraction should be its own tracked decision when the time comes.

`install.sh` cannot be unified with any option — it runs before the container exists and must
use the `htpasswd` binary via a temporary Docker container. That is a permanent architectural
boundary.

Option C is appropriate as an immediate fix. Option A remains the right long-term target if
hash format consistency becomes a concern (e.g., if Apache config is ever tightened to require
a specific algorithm).
