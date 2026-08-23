# Feature: Federated Authentication Migration — Owner Benefits

**Related docs:**
- `feature_security_authentication_migration_jwt.md` — strategic plan
- `feature_security_authentication_migration_jwt_implementation.md` — Phases 1–4 implementation
- `feature_security_authentication_migration_jwt_oidc_phase5.md` — Phase 5 (OIDC) implementation

---

## What You're Actually Trading

**Today:** One shared `admin` password in `secrets.yml`. Everyone who touches the system uses it. You change one line, run Ansible, done.

**After this:** Individual accounts per person. You create them once. You never touch them again unless something goes wrong.

That last part is the key insight: **the operational burden shifts from "I maintain passwords" to "I maintain a list of who has access"** — and that second thing is much closer to what you actually want to control.

---

## Where Your Life Gets Concretely Easier

### 1. Revoking access takes seconds and is complete

Today, if a videographer or band planner leaves a project, you change the shared `admin` or `uploader` password. Then you have to notify everyone else who uses that password. Then some of them forget to update the iOS app. Then uploads break for a week.

After this: set `disabled = 1` on their `users` row. That's it. Their JWT stops being accepted on the next request. Nobody else is affected. No Ansible run needed. No one to notify.

### 2. You can give someone read-only access that actually means read-only

Today, there's no safe way to let a client browse their wedding gallery without either giving them the full `viewer` password (which every other client also knows) or building something custom. The shared-password model means "some access" and "full access" are your only real options.

After this: create a `viewer` account for the client. They see what a viewer sees. They cannot upload, cannot touch the admin UI, cannot interact with anyone else's data. You add them in SQL, done.

### 3. "Who did that?" becomes answerable

If something gets deleted or corrupted in the media library, today you can't tell whether it was you, a videographer, or a band planner — the audit trail just shows `admin`. After this, every write operation is attributable to a specific `users.id`. If you ever have a dispute with a client or contractor, you have a record.

### 4. OIDC means no passwords to rotate at all for most users

Once Phase 5 is live, a band planner signs in with their Google or Microsoft account. You never issue them a password. They never send you a password reset request. If they leave a project, you disable their row. If their Google account gets compromised and they reset it at Google, that immediately affects their GigHive access too — no separate password to worry about.

You keep one local `owner` account with a strong password in ansible-vault as an emergency backdoor if the IdP is unreachable. That's the only credential you manage going forward.

### 5. Changing your access doesn't require an Ansible run

Today, rotating the `admin` password requires editing ansible-vault, running the playbook, waiting for the container to rebuild. After this, you can reset any local password with two steps — generate a bcrypt hash and write it to the DB — no Ansible, no downtime, no container restart:

```bash
# Step 1: generate the hash (run on any PHP 8.3 system)
php -r "echo password_hash('newpassword', PASSWORD_BCRYPT, ['cost'=>12]);"

# Step 2: update the row
docker exec -i mysqlServer bash -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" media_db -e "
UPDATE users SET password_hash = '\''HASH_HERE'\'' WHERE email = '\''user@example.com'\'';
"'
```

The value in `password_hash` must always be a bcrypt hash — never a plaintext password. OIDC users don't have passwords to rotate at all.

---

## Where Your Life Stays the Same or Gets Slightly Harder

**Initial setup is more work.** You have to run the `ALTER TABLE`, seed the initial accounts, and for Phase 5 register the app in Google Cloud Console and Azure. That's a one-time cost, not ongoing.

**New collaborator onboarding has one more step.** Instead of handing someone the shared password, you run an `INSERT` into `users` (or they log in via OIDC and you get an auto-created row you then set the right role on). This is one SQL statement or one Ansible task vs. telling someone a password verbally — roughly equivalent friction, but more auditable.

**The emergency recovery procedure is different.** If you lose JWT access entirely, the runbook is: set `GIGHIVE_AUTH_MODE=basic` in group_vars, run Ansible (restores htpasswd auth temporarily). That's documented and is still one Ansible run — just a different one than today.

---

## The Honest Summary

The shared password model works perfectly when you're the only person using the system. The moment a second person needs access — a videographer, a client, a band manager — the shared model starts working against you. You can't revoke one person without affecting everyone. You can't give read-only access that's actually enforced. You can't tell who did what.

This migration doesn't make your life more complex. It makes the complexity explicit and manageable rather than hidden in a single shared credential that you can never safely rotate once multiple people know it.
