# Milestone — Storage Media REST Endpoint: Tranche 1 Complete (2026-08-19)

**Date:** 2026-08-19  
**Status:** All Phases 1–5 verified across all environments (dev, lab, staging, prod)

---

## What Tranche 1 Was

Before Tranche 1, the media stack was a direct filesystem coupling: PHP files touched
storage paths, `tusd` ran as a sidecar container with its own lifecycle and failure modes,
and there was no abstraction between "the app" and "where bytes live." Azure Blob or any
other backend was structurally impossible without a rewrite.

After Tranche 1, none of that is true anymore.

---

## What Got Built, Phase by Phase

**Phase 1** established the runtime configuration foundation — IMDS access,
environment-aware config — so the system could know where it was running and act
accordingly. Boring infrastructure, but everything else depended on it.

**Phase 2** was the core architectural win: `MediaStorageService` and the backend
abstraction layer. This is the compute/storage boundary that the entire SaaS model
requires. It didn't exist before. Now it does, and it's the right shape.

**Phase 3** retired `tusd`. A whole container — with its own process lifecycle, port,
auth surface, and failure modes — gone. The tus upload path now runs entirely inside
PHP, under the same process model as the rest of the app. Simpler, more observable,
fewer moving parts.

**Phase 4** put `media-stream.php` behind the service layer. Streaming, thumbnails,
gallery nonce auth — all going through `MediaStorageService` now. The direct filesystem
read path is closed.

**Phase 5** completed the Local/VirtualBox environment, which closed out the last open
question about whether the abstraction held across all deployment targets. It did.

---

## Strategic Significance

The reason this scored 10/10 in the bang-for-buck ranking isn't what it delivered to
users today — local storage still works the same way end-to-end. The reason is what it
*unlocks*.

Tranche 2 — Azure Blob activation, private endpoint, IMDS auth, full Blob cutover,
backfill — is now a configuration and deployment exercise, not an architectural one.
The hard part is done. When the SaaS rollout is ready, switching the backend is a
`group_vars` change and a backfill run, not a rewrite.

A future multi-client SaaS deployment is now structurally possible without touching
the application layer again. That boundary didn't exist six weeks ago.

---

## Environment Awareness

"Environment-aware" in this context means precisely two modes:

- **Local** (current active mode) — `GIGHIVE_MEDIA_STORAGE_BACKEND=local`;
  `LocalMediaBackend` reads from bind-mounted host directories. Active on all four
  environments today.
- **Azure Blob** (Tranche 2) — `GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob`;
  `AzureBlobMediaBackend` and `AzureBlobTusBackend` route through
  `AzureBlobRestClient` with IMDS Managed Identity auth. Not yet activated.

The IMDS (Instance Metadata Service) access built in Phase 1 is Azure-specific — it is
how an Azure VM authenticates to Azure services without a stored credential. Phase 1
laid that groundwork while keeping local as the active backend.

The `MediaBackend::AZURE_FALLBACK` (`azure_blob_with_local_fallback`) mode is a
migration-window-only path for Phase 11, when blobs are being backfilled; it tries
Azure first and falls back to local for assets not yet migrated. It is not active and
`FallbackMediaBackend.php` must be deleted after Phase 11 backfill is verified.

---

## Issues Encountered Along the Way

It was not without pain. Documented here as a record of what the clean outcome cost.

### Phase 2 — Four Issues

**Issue A — `docker_container_exec` YAML list `command:` form**  
All four T-71–T-74 exec tasks used the YAML list form for `command:`. The
`community.docker.docker_container_exec` module serialised the list to the literal
string `[php, -l, /path]` and attempted to exec it as a binary name. Fixed by
converting all four to the scalar shell string form (`command: >-`) already used by
every other exec task in the file.

**Issue B — Wrong path to Composer binary**  
T-72 called `php /var/www/html/vendor/bin/composer`. That path is the project's
`vendor/bin/` symlink tree, not the Composer binary. The binary lives at
`/usr/local/bin/composer` as installed by the Dockerfile. Fixed by pointing directly
at `/usr/local/bin/composer`.

**Issue C — `php-apcu` missing from Dockerfile; `apc.enable_cli` not set**  
`AzureIdentityTokenCache` requires APCu for token caching. The package was not in the
Dockerfile's `apt-get install` line. Additionally, `apc.enable_cli=1` must be set
explicitly — APCu is silently disabled under CLI (cron) even when the package is
installed. Both fixes required a full Apache image rebuild.

**Issue D — Stale Docker compose project state: `No such image: sha256:...`**  
After the Apache image was retagged, the `ai_worker` compose project state still held
a reference to the old SHA. `docker_container_info` returned `exists: false` so the
stale-container removal loop skipped it, but the stale SHA in compose state caused
`docker compose images` to fail. Fixed by adding a `state: absent` tear-down step
immediately before the ai-worker deploy task, with `failed_when: false` for
first-ever deploys.

---

### Phase 3 — Six Issues

**Issue 1 — Old `[tus]` block crashed on tusd container absence**  
The existing `post_build_checks` `[tus]` block still expected `tusd` to exist —
container check, DNS probe, hook-wait, staging artifact cleanup, and a
`Get tusd version` task in the stack summary all assumed the container was live. Every
one of them failed immediately. The block had to be gutted and rebuilt around the
PHP tus endpoint.

**Issue 2 — Smoke test payload wrong: missing `filetype`, wrong MIME, wrong length**  
Three problems in the tus smoke test: (1) missing `filetype` in `Upload-Metadata`
caused a 415 — the old `tusd` smoke test never needed it but the new PHP
`handlePost()` validates MIME against an allowlist; (2) plain-text payload failed
MIME sniffing on the final PATCH — it doesn't sniff as `audio/wav`; (3)
`Upload-Length` used `tus_payload | length` (string length) instead of the actual
byte count. Fixed by adding `filetype`, replacing the payload with a real 44-byte
minimal WAV binary, and setting `Upload-Length` to 44.

**Issue 3 — Ansible `uri` module rejects non-UTF-8 binary bodies**  
The 44-byte WAV binary (b64-decoded) passed as `body` to Ansible's `uri` module
caused `'utf-8' codec can't encode character '\udcac': surrogates not allowed` before
the HTTP request was even sent. Ansible serialises all task parameters as UTF-8 JSON;
binary bytes with surrogates can't survive that path. Fixed by routing around `uri`
entirely: `docker exec` into the Apache container, writing the b64 string and a netrc
credentials file with `printf`, then piping `base64 -d` into
`curl --data-binary @- --netrc-file`.

**Issue 4 — `set_fact` self-reference: `tus_payload_b64` undefined**  
Ansible evaluates all keys in a single `set_fact` task simultaneously using variable
state from *before* the task runs. `tus_payload: "{{ tus_payload_b64 | b64decode }}"`
referenced `tus_payload_b64` being set in the same task — it didn't exist yet. Fixed
by splitting into two sequential `set_fact` tasks.

**Issue 5 — Probe cron inside `quickstart`-only guard; never written on full installs**  
The probe cron block was inside `if [[ "${GIGHIVE_INSTALL_CHANNEL:-full}" == "quickstart" ]]`.
Dev/lab/staging/prod all run `GIGHIVE_INSTALL_CHANNEL=full` (the default), so the
block never executed and `/etc/cron.d/gighive-probe` was never written. Fixed by
moving the probe cron, logrotate setup, and `service cron start` outside the
`quickstart` guard.

**Issue 6 — MySQL `innodb_lock_wait_timeout` defaulted to 50; T-86 required ≥ 60**  
MySQL 8 ships with `innodb_lock_wait_timeout=50`. The T-86 check requires ≥ 60 to
reduce lock contention during concurrent tus PATCH and finalize operations. The
setting was simply not added when the tus DB tables were introduced in Phase 3. Fixed
by adding `innodb_lock_wait_timeout=60` to `z-custommysqld.cnf`.

---

### Phase 4 — Ansible Version Skew (surfaced mid-rollout)

Phase 4 rollout surfaced the Ansible version skew across the four controller machines:
`2.17.12` on the dev/prod controller vs `2.20.4` on the lab/staging controllers. A
silent failure appeared on lab that did not appear on dev, costing multiple deploy
cycles. This issue earned its own `#0` slot in the bang-for-buck status ranking — a
force multiplier on all other work that must be resolved before the next Ansible run
on any environment.

---

### Phase 5 — Five Issues

**Issue 1 — T-64/T-66: `test.mp3` key fails `validateKey()` regex; returns 400 not 401**  
`validateKey()` in `media-stream.php` enforces a 64-character lowercase hex SHA-256
regex. `test.mp3` fails it and PHP returns 400 before auth is checked — the test
could never reach 401 as written. Fixed by replacing with the all-zeros 64-character
synthetic key already used by T-91/T-92.

**Issue 2 — T-68b: `community.docker.docker_container_exec` used instead of `docker exec`**  
The `validate_app` role uses `ansible.builtin.command: docker exec` exclusively
throughout. T-68b used `docker_container_exec` with the YAML list `command:` form —
the same failure mode documented in Phase 2 Issue A. The established pattern was
already present at multiple locations in the same file before the Phase 5 changes
were made.

**Issue 3 — Pre-existing stale-pending task: YAML list `command:` caused parse error**  
A pre-existing task at line 303 of `post_build_checks/tasks/main.yml` used
`community.docker.docker_container_exec` with the YAML list form. The SQL contained
single-quoted literals inside a double-quoted shell string, causing a "No closing
quotation" parse error at runtime. Same class of failure as Phase 2 Issue A.

**Issue 4 — `environment:` on `ansible.builtin.command` sets controller env, not container env**  
Both the stale-pending task and T-68b were initially written with
`environment: MYSQL_PWD: ...` on the `ansible.builtin.command` task. This sets the
variable on the Ansible controller process — it is not forwarded into the `docker exec`
subprocess inside the container. MySQL received no password and returned
`Access denied (using password: NO)`. Fixed by removing `environment:` and moving
the password inline: `sh -c "MYSQL_PWD={{ mysql_root_password | quote }} mysql ..."`.
The `sh -c "MYSQL_PWD=..."` pattern was already present at multiple locations in both
`post_build_checks` and `validate_app` before any Phase 5 changes were made. This
earned a lesson recorded in `SKILL.md`.

**Issue 5 — Live iPhone test: QR code guest upload returned 500**  
Discovered after the playbook run passed clean. The guest QR upload flow (iOS →
POST `/files/` with `X-Upload-Token`) returned `500 Failed request`. FPM log:
`ArgumentCountError: Too few arguments to UploadTokenValidator::__construct(), 0 passed`.
`tus-upload.php` was calling `new UploadTokenValidator()` with zero arguments. The
Phase 2/3 refactor had changed the constructor to require a `PDO` instance via
dependency injection but the call site in `tus-upload.php` was never updated. Basic
Auth admin/uploader uploads were completely unaffected — they skip the token block
entirely. Only the QR guest path hit line 64. Fixed by adding `Database::createFromEnv()`
inside the token block and passing `$pdo` to the constructor. Permanent regression
check T-93b added to `post_build_checks` to catch this if it recurs.

---

## Net Result

Tranche 1 shipped all five phases across all four environments without a rollback.
The Ansible version skew issue surfaced mid-flight and got managed. The migration
window worked. The clean outcome was earned through multiple debug cycles across
Phases 2, 3, and 5 — sixteen discrete issues in total — all of which are now
documented in the build issues sections of the implementation doc and will not be
repeated in Tranche 2.

Tranche 2 (Azure Blob activation, private endpoint, IMDS auth, Blob cutover,
backfill — Phases 6–11) is deferred until the SaaS rollout is ready. When that time
comes, the hard part is already done.

---

*Source docs:*  
- `refactor_storage_media_rest_endpoint.md` — architecture and decisions  
- `refactor_storage_media_rest_endpoint_implementation.md` — build guide and build issues  
- `refactor_storage_media_rest_endpoint_followons.md` — deferred cleanup items  
- `refactor_status_20260819.md` — bang-for-buck ranking; Tranche 1 marked complete
