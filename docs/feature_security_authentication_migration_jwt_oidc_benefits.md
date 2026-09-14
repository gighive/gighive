# Feature: Federated Authentication Migration — Owner Benefits

## Status — 2026-09-09
Business rationale — reconciled to the canonical browser-cookie, API/iOS Bearer, route-class, and atomic-cutover policy. No implementation is authorized by this document.

**Related docs:**
- `policy_authentication_credential_route.md` — authoritative authentication transport, route, precedence, response, and cutover policy
- `feature_security_authentication_migration_jwt.md` — strategic plan
- `feature_security_authentication_migration_jwt_implementation.md` — JWT Migration implementation
- `feature_security_authentication_migration_jwt_oidc_phase5.md` — JWT Migration Phase 5 (OIDC)

---

## Elevator Pitch

GigHive replaces shared installation passwords with individual identities that can be assigned, audited, and removed without disrupting everyone else. Event planners and media librarians receive only the access they need, while the platform owner retains a local break-glass path if an identity provider is unavailable. Browser credentials remain protected in an HttpOnly JWT cookie, while iOS and programmatic clients use Bearer JWTs.

---

## What You're Actually Trading

**Today:** One shared `admin` password in `secrets.yml`. Everyone who touches the system uses it. You change one line, run Ansible, done.

**After this:** Individual accounts per person. You create them once. You never touch them again unless something goes wrong.

That last part is the key insight: **the operational burden shifts from "I maintain passwords" to "I maintain a list of who has access"** — and that second thing is much closer to what you actually want to control.

### What users carry after migration

| Client | Credential | Why |
|---|---|---|
| Browser | Secure HttpOnly GigHive JWT cookie | Supports pages, forms, downloads, media, and AJAX without exposing the JWT to JavaScript |
| iOS / programmatic API | `Authorization: Bearer <JWT>` | Native/API clients control headers directly |
| QR contributor/gallery guest | Event-scoped upload token or nonce | Preserves accountless event access and restricts the request to its event capability |

One centralized route-class policy decides which credential is authoritative. A logged-in organizer testing a QR link receives the same guest-scoped behavior as an anonymous attendee.

---

## Where Your Life Gets Concretely Easier

### 1. Revoking one person's access no longer disrupts everyone else

Today, if a videographer or band planner leaves a project, you change the shared `admin` or `uploader` password. Then you have to notify everyone else who uses that password. Then some of them forget to update the iOS app. Then uploads break.

After this: disable that individual's `users` row without changing anyone else's credential. Disabling always prevents issuance of new JWTs. Whether an already-issued JWT stops on the next request depends on the implementation decision still open in the canonical policy: immediate revocation requires request-time account/token-version validation or a denylist; otherwise the JWT remains valid until its configured expiry. No Ansible run or shared-password notification is required.

### 2. You can give someone read-only access that actually means read-only

Today, there's no safe way to let a client browse their wedding gallery without either giving them the full `viewer` password (which every other client also knows) or building something custom. The shared-password model means "some access" and "full access" are your only real options.

After this: create a `viewer` account for the client. They see what a viewer sees. They cannot upload, cannot touch the admin UI, cannot interact with anyone else's data. You add them in SQL, done.

### 3. "Who did that?" becomes answerable

If something gets deleted or corrupted in the media library, today you can't tell whether it was you, a videographer, or a band planner — the audit trail just shows `admin`. After this, every write operation is attributable to a specific `users.id`. If you ever have a dispute with a client or contractor, you have a record.

### 4. OIDC means no passwords to rotate at all for most users

Once JWT Migration Phase 5 is live, a band planner signs in with their Google or Microsoft account. You never issue them a GigHive password, and password recovery remains with the identity provider. If they leave a project, you disable their GigHive user row. An IdP password reset protects future OIDC login, but an already-issued GigHive JWT follows GigHive's own expiry/revocation policy; immediate termination still requires the request-time revocation mechanism described above.

You keep one local `owner` account with a strong password in ansible-vault as an emergency backdoor if the IdP is unreachable. That's the only credential you manage going forward.

### 5. Managing an individual account doesn't require an Ansible run

Today, rotating the shared `admin` password requires editing ansible-vault and running the playbook. After this, you can reset an individual local password by generating a bcrypt hash and writing it to the database—no container rebuild or shared-password rotation:

```bash
# Step 1: generate the hash (run on any PHP 8.3 system)
php -r "echo password_hash('newpassword', PASSWORD_BCRYPT, ['cost'=>12]);"

# Step 2: update the row
docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "
UPDATE users SET password_hash = '\''HASH_HERE'\'' WHERE email = '\''user@example.com'\'';
"'
```

The value in `password_hash` must always be a bcrypt hash — never a plaintext password. OIDC users don't have GigHive passwords to rotate. Password, role, disable, and tenant-assignment changes affect new JWT issuance immediately; their effect on already-issued JWTs follows the selected request-time revocation policy.

---

## Where Your Life Stays the Same or Gets Slightly Harder

**Initial setup is more work.** You have to run the `ALTER TABLE`, seed the initial accounts, and for Phase 5 register the app in Google Cloud Console and Azure. That's a one-time cost, not ongoing.

**New collaborator onboarding has one more step.** Instead of handing someone the shared password, you run an `INSERT` into `users` (or they log in via OIDC and you get an auto-created row you then set the right role on). This is one SQL statement or one Ansible task vs. telling someone a password verbally — roughly equivalent friction, but more auditable.

**The emergency recovery procedure is different.** An identity-provider outage uses the retained local break-glass owner login and does not require restoring Basic Auth. A failed JWT cutover uses the reviewed rollback artifact and configuration for that environment; changing only `GIGHIVE_AUTH_MODE` is insufficient because Apache rules, PHP guards, browser cookies, and client credentials must remain coordinated. The user performs the documented Ansible rollback, verifies the environment, and stops promotion until it is clean.

---

## The Honest Summary

The shared password model works perfectly when you're the only person using the system. The moment a second person needs access — a videographer, a client, a band manager — the shared model starts working against you. You can't revoke one person without affecting everyone. You can't give read-only access that's actually enforced. You can't tell who did what.

This migration doesn't make your life more complex. It makes the complexity explicit and manageable rather than hidden in a single shared credential that you can never safely rotate once multiple people know it.
