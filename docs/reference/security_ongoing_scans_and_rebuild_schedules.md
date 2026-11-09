# PR / Design Doc: Ongoing CVE Scans and Image Rebuild Schedules

**Status:** Implemented in PR #33 (merged; deployed to gighive2 and prod on 2026-11-06, MySQL 8.4.11/8.4.7 -> 8.4.13)
**Date:** 2026-11-06
**Related:** PR #32 (first report-only Trivy scan in `post_build_checks`), `docs/reference/refactor_security_docker_hardened_images.md`

---

## Summary

GigHive checks CVE compliance with a generic image scanner (Trivy) instead of writing a check per vulnerability. The approach has four parts:

| # | Practice | Where | When |
|---|---|---|---|
| 1 | App images rebuild from fresh bases, without build cache | compose templates (`no_cache: true`, `pull: true`) | Every deploy |
| 2 | MySQL is pinned to an exact tag + digest | `mysql_docker_image`, `telemetry_receiver_mysql_image` | Changes only when the pin is bumped |
| 3 | Renovate opens a PR when a newer MySQL 8.4.x image is published | `renovate.json` | Weekly (Monday before 6am) |
| 4 | Scheduled scan of freshly built images, the pinned MySQL image, and repo lockfiles | `.github/workflows/trivy-weekly.yml` | Weekly (Monday 11:00 UTC) + manual |

The deploy-time scan in `post_build_checks` (and in the telemetry receiver's post-deploy checks) answers "is what I just deployed clean?". The weekly workflow answers "has a CVE been published since then?".

## Rationale

- **Generic scanning over per-CVE checks.** Trivy matches every OS package and language lockfile in an image against vulnerability databases, so a newly published CVE is detected without new code. To confirm a specific CVE is gone, search the scan output for its ID.
- **`--ignore-unfixed`.** Only findings that have a fixed version are reported. Without it, the Apache image showed 386 HIGH/CRITICAL findings, almost all `linux-libc-dev` kernel headers that have no fix and don't apply inside a container. With it, the Apache image showed 0.
- **Rebuild app images, pin the database.** App images (Apache, ai-worker, telemetry PHP) have no persistent state, so rebuilding them on every deploy is a low-risk way to pick up base-image and apt security fixes. MySQL upgrades its data directory in place on startup, and that can't be undone. It should only change through a reviewed version bump, after a backup.
- **Why MySQL was pinned.** A floating `mysql:8.4` tag is pulled once and then cached. gighive2 was still running an older 8.4 image with 59 fixable HIGH OS-package CVEs, all fixed in the Nov 3 rebuild. `pull_policy: always` would have fixed that, but it also upgrades the database on any deploy and breaks deploys when Docker Hub is unreachable. A pin plus Renovate PRs keeps updates timely and deliberate.

### Sources

These are guidance documents that support the practices. Choosing Renovate, GitHub Actions and a weekly schedule was a project-level decision, not something they prescribe. Section names were cited from memory; verify them against the current versions.

- Docker, *Building best practices*: "Pin base image versions" (digest pinning, automated digest updates, rebuilding often). https://docs.docker.com/build/building/best-practices/
- OpenSSF Scorecard checks: "Pinned-Dependencies", "Dependency-Update-Tool". https://github.com/ossf/scorecard/blob/main/docs/checks.md
- NIST SP 800-190, *Application Container Security Guide*: image vulnerability scanning across the lifecycle; fix by rebuilding and redeploying. https://nvlpubs.nist.gov/nistpubs/SpecialPublications/NIST.SP.800-190.pdf
- NIST SP 800-218, *Secure Software Development Framework*: PW.4 (keep reused components up to date), RV.1 (continuously identify vulnerabilities). https://csrc.nist.gov/pubs/sp/800/218/final
- CIS Docker Benchmark, "Container Images and Build File" section (scan and rebuild images to include security patches). https://www.cisecurity.org/benchmark/docker
- OWASP Docker Security Cheat Sheet (integrate container scanning into CI/CD). https://cheatsheetseries.owasp.org/cheatsheets/Docker_Security_Cheat_Sheet.html

## Constraints / non-goals

- No per-CVE tasks and no blanket CVE allowlist.
- The deploy-time scan stays report-only (`failed_when: false`) and never blocks a deploy.
- Images are still built on each VM by Ansible. Building once in CI and pushing to a registry was considered but deferred.
- Application dependencies (Composer, pip) are not auto-updated by Renovate. `renovate.json` only enables the MySQL regex manager. The weekly `repo-deps` scan reports vulnerable lockfile entries.

## Agreed Requirements

1. The Trivy scan list must match GigHive's actual container inventory, derived from the compose files rather than hardcoded names.
2. Apache, ai-worker and telemetry PHP rebuild on every deploy with no build cache and a fresh base-image pull.
3. Both MySQL services (GigHive `mysqlServer` and telemetry `telemetry_db`) use a pinned tag + digest.
4. Renovate proposes MySQL pin bumps as PRs. It never merges them automatically.
5. A weekly scheduled scan catches CVEs published between deploys.

## Implementation

### 1. Rebuild schedule (every deploy)

| Service | Compose template | Dockerfile | Base image |
|---|---|---|---|
| Apache (`apacheWebServer`) | `ansible/roles/docker/templates/docker-compose.yml.j2` | `ansible/roles/docker/templates/Dockerfile.j2` | `ubuntu:{{ ubuntu_version }}` |
| ai-worker | `ansible/roles/ai_worker/templates/docker-compose-ai-worker.yml.j2` | `ansible/roles/ai_worker/files/ai-worker/Dockerfile` | `python:3.11-slim` |
| telemetry receiver | `ansible/roles/telemetry_receiver/templates/docker-compose.yml.j2` | `ansible/roles/telemetry_receiver/files/php/Dockerfile` | `php:8.3-apache` |

Each `build:` block has:
```yaml
no_cache: true   # no build-cache layers
pull: true       # re-pull the FROM base image
```
`Dockerfile.j2` also has a daily `package refresh` line before `apt-get upgrade`, added earlier, which already invalidated that layer once a day. `no_cache` extends this to every layer, including `composer install`. Expect longer deploys.

### 2. MySQL pin

```yaml
# ansible/roles/docker/defaults/main.yml       -> docker-compose.yml.j2: image: "{{ mysql_docker_image }}"
mysql_docker_image: "mysql:8.4.13@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902"

# ansible/roles/telemetry_receiver/defaults/main.yml
telemetry_receiver_mysql_image: "mysql:8.4.13@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902"
```
- The digest is the multi-arch index (amd64 + arm64), so it resolves on both gighive2 (aarch64) and x86 hosts.
- Either variable can be overridden per environment in `group_vars` if needed.
- Bumping the pin: merge the Renovate PR (or edit both values), take a DB backup, then deploy. A MySQL data directory upgraded to a newer 8.4.x can't be downgraded.

### 3. Renovate (`renovate.json`)

- Requires the Renovate GitHub App installed on `gighive/gighive` (https://github.com/apps/renovate). Until it is installed, the file does nothing.
- `enabledManagers: ["custom.regex"]`. A regex manager matches `mysql_docker_image` / `telemetry_receiver_mysql_image` in the two role defaults files.
- `allowedVersions: /^8\.4\.\d+$/`: only patch releases within the 8.4 LTS line. Moving to a new LTS is a manual decision.
- Both pins are updated in one grouped PR ("mysql image"), at most weekly, with `automerge: false`.
- Renovate also updates the digest when Oracle rebuilds the same 8.4.x tag (the Nov 3 rebuild was this case).
- A "Dependency Dashboard" issue lists pending and rate-limited updates.

#### How a MySQL update flows through Renovate

1. Oracle publishes a new 8.4.x tag, or rebuilds `8.4.13` with fixed packages, which gives it a new digest.
2. On its Monday run, Renovate sees that the pin in both defaults files is out of date. It opens one PR ("Update mysql image") that changes the tag and the `@sha256:` digest in `ansible/roles/docker/defaults/main.yml` and `ansible/roles/telemetry_receiver/defaults/main.yml`.
3. Review the PR. Optionally scan the new image first:
   `docker run --rm aquasec/trivy:0.76.1 image --scanners vuln --ignore-unfixed --severity HIGH,CRITICAL <new image ref>`
4. Merge the PR. Back up the database (admin page → "Create Database Backup Now"). Deploy gighive2 first, then prod.
5. Confirm the new version with `docker exec mysqlServer mysql --version`, and check the deploy-time Trivy report.

Nothing changes on a server until you merge and deploy. If Renovate opens no PR, the pinned image is still the newest 8.4.x.

#### Example: prod deploy-time scan of `mysql:8.4.13` (2026-11-06)

Command used to pull the non-zero rows from the playbook log:

```bash
grep -A200 "Scan compose images for fixable" ansible-playbook-prod-20261106.log | grep -E "Total:|│ .*│ .*[1-9]|CVE-" | head -60
```

Abridged output (the Apache image, Composer packages and MySQL OS packages (`oracle 9.8`) all reported 0):

```text
│ usr/lib/mysqlsh/lib/python3.13/site-packages/certifi-2026.2.25.dist-info/METADA- │ python-pkg │        1        │
│ usr/lib/mysqlsh/lib/python3.13/site-packages/cryptography-46.0.5.dist-info/META- │ python-pkg │        3        │
│ usr/lib/mysqlsh/lib/python3.13/site-packages/pyopenssl-25.3.0.dist-info/METADATA │ python-pkg │        1        │
│ usr/lib/mysqlsh/lib/python3.13/site-packages/urllib3-2.6.3.dist-info/METADATA    │ python-pkg │        4        │
│ usr/local/bin/gosu                                                               │  gobinary  │       26        │
Total: 9 (HIGH: 8, CRITICAL: 1)
│ certifi (METADATA)      │ GHSA-6q48-6c7w-9gw2 │ CRITICAL │ fixed  │ 2026.2.25         │ 2026.10.18    │ ...
│ cryptography (METADATA) │ CVE-2026-69247      │ HIGH     │        │ 46.0.5            │ 50.0.0        │ ...
│ urllib3 (METADATA)      │ CVE-2026-44431      │          │        │ 2.6.3             │ 2.7.0         │ ...
Total: 26 (HIGH: 25, CRITICAL: 1)
│ stdlib  │ CVE-2025-68121  │ CRITICAL │ fixed  │ v1.24.6           │ 1.24.13, 1.25.7, 1.26.0-rc.3 │ crypto/tls: ...
│         │ CVE-2025-61726  │ HIGH     │        │                   │ 1.24.12, 1.25.6              │ golang: net/url: ...
```

How to read it:

| Rows | Who can fix | Action |
|---|---|---|
| `oracle 9.8` OS packages | Oracle's image rebuild, then a Renovate PR | Merge the PR, back up, deploy. The weekly `mysql` job fails on these. |
| `mysqlsh` Python libraries (9) | Oracle only | None. GigHive does not run MySQL Shell. These are visible in SARIF but do not fail the job. |
| `gosu` Go stdlib (26) | Oracle only | None. `gosu` only drops privileges at container start. Visible in SARIF, does not fail the job. |

These 35 clear when Oracle ships an image with updated `mysqlsh` and `gosu`. Renovate then opens the PR for that image.

### 4. Scheduled scans

**Deploy-time (report only).** `ansible/roles/post_build_checks/tasks/trivy_scan.yml`:
```
docker compose -f <files> config --images   # in trivy_compose_dir
for each unique image: trivy image --scanners vuln --ignore-unfixed --severity HIGH,CRITICAL
```

| Caller | Compose files | Images |
|---|---|---|
| `ansible/roles/post_build_checks/tasks/main.yml` | `docker-compose.yml` + `docker-compose-ai-worker.yml` when `ai_worker_enabled` | Apache, MySQL, ai-worker |
| `ansible/roles/telemetry_receiver/tasks/post_deploy_checks.yml` | `{{ telemetry_receiver_home }}/docker-compose.yml` | telemetry PHP, telemetry MySQL |

Toggles / overrides: `trivy_scan_enabled` (default `true`), `trivy_docker_image` (default `aquasec/trivy:0.76.1`), `trivy_severity` (default `HIGH,CRITICAL`). The Trivy DB is cached in the `trivy-cache` Docker volume on each host.

**Weekly (`.github/workflows/trivy-weekly.yml`).** Runs Mondays 11:00 UTC and via "Run workflow" (`workflow_dispatch`), with one matrix job per target:

| Target | What is scanned |
|---|---|
| `apache` | `Dockerfile.j2` rendered with `ansible/inventories/group_vars/prod/prod.yml`, built `--pull --no-cache` with `APP_FLAVOR=gighive` |
| `telemetry-php` | `ansible/roles/telemetry_receiver/files/php` built `--pull --no-cache` |
| `ai-worker` | `ansible/roles/ai_worker/files/ai-worker` built `--pull --no-cache` |
| `mysql` | the pinned `mysql_docker_image` |
| `repo-deps` | `trivy fs` over the repo: `composer.lock`, `requirements.txt` files and other lockfiles |

- Results are uploaded as SARIF to the repo's **Security → Code scanning** tab, one category per target.
- A table scan with `--exit-code 1` then fails that matrix job when fixable HIGH/CRITICAL findings exist, so GitHub's failed-workflow notification acts as the alert.
- `ai-worker` Dockerfile runs `pip install --no-cache-dir --only-binary :all: setuptools==84.2.1 wheel==0.48.0` before installing requirements. The versions are pinned with wheels-only installs to satisfy SonarCloud's dependency-locking and setup-script rules; bump them if the weekly scan flags them. Without it, `python:3.11-slim` shipped `jaraco.context` 5.3.0 (CVE-2026-23949) and `wheel` 0.45.1 (CVE-2026-24049); a fresh build now has 0 findings (as of 2026-11-06).
- The `mysql` job fails only on OS-package findings (`--pkg-types os`), because those are what a Renovate pin bump fixes. Oracle's bundled MySQL Shell Python libraries and the `gosu` Go binary (35 findings as of 2026-11-06) can't be fixed from GigHive. They are still uploaded in the SARIF and visible in the Security tab, but they don't fail the job.

#### How the weekly Trivy scan flows

1. Every Monday at 11:00 UTC, or when you click **Actions → Weekly Trivy CVE scan → Run workflow**, GitHub starts 5 matrix jobs on `master`.
2. Each job builds or pulls its target fresh, then runs Trivy twice: once to produce SARIF, and once as a table with `--exit-code 1`.
3. SARIF results go to **Security and quality → Code scanning**, one category per target (`trivy-apache`, `trivy-mysql`, ...). Alerts stay open until a later run no longer reports them, and then they close automatically.
4. A job turns red only for findings GigHive can fix (see the Operating procedure table). GitHub emails the repo owner when a scheduled run fails.
5. Green run: nothing to do. Red run: open the failed job's "Trivy scan (table; fails the job on findings)" step, read the CVE rows, and act per the Operating procedure table.

#### Example: first run (2026-11-06, manual)

- Run: [Weekly Trivy CVE scan #1](https://github.com/gighive/gighive/actions/runs/42300625126), triggered by `workflow_dispatch` on `master` @ `08cf92d`.
- Result: **Success** in 4m 19s. All 5 jobs green: `scan (apache)`, `scan (telemetry-php)`, `scan (ai-worker)`, `scan (mysql)`, `scan (repo-deps)`.
- Annotations: 5 notices, all GitHub's `"The ubuntu-latest label will migrate to Ubuntu 26 beginning October 19, 2026"`. These are informational and need no action.
- Security tab: 35 open Trivy alerts, all in category `trivy-mysql`:

| Location in `mysql:8.4.13` | Alerts | Fails the job? |
|---|---|---|
| `usr/lib/mysqlsh/lib/python3.13/site-packages/` (`certifi`, `cryptography`, `pyopenssl`, `urllib3`) | 9 | No (not an OS package) |
| `usr/local/bin/gosu` (Go stdlib) | 26 | No (not an OS package) |
| MySQL OS packages (`oracle 9.8`), Apache, telemetry PHP, ai-worker, repo lockfiles | 0 | Would fail if non-zero |

These are the same 35 findings as the prod deploy-time scan above. GitHub's severity column (2 critical, 32 high, 1 medium) comes from CVSS scores, so it can differ slightly from Trivy's own HIGH/CRITICAL labels.

To list open alerts from a terminal:

```bash
gh api "repos/gighive/gighive/code-scanning/alerts?state=open&per_page=100" \
  --jq 'group_by(.most_recent_instance.category)[] | "\(.[0].most_recent_instance.category): \(length)"'
```

## Operating procedure

| Event | Action |
|---|---|
| Weekly job fails on `apache` / `telemetry-php` / `ai-worker` | A fresh rebuild still has a fixable CVE. All three Dockerfiles run `apt-get upgrade`, so Debian/Ubuntu package fixes are applied as soon as they reach apt, even before the base image is rebuilt. A failure therefore means the fix isn't in apt yet, or the CVE is in the language runtime (Python/PHP, fixed by a base-image refresh) or an app dependency (bump it). Once a fix is available, redeploy (the rebuild picks it up). |
| Weekly job passes for app images but prod hasn't been deployed recently | Prod may still run older images. Redeploy to pick up fixes. |
| Weekly job fails on `mysql` | An OS-package CVE is fixed in a newer image. Check for a Renovate PR: merge it, back up, deploy. |
| New `mysql` alert in the Security tab, job green | A bundled-library finding (MySQL Shell Python / `gosu`). Review the risk; it clears when Oracle updates the image and Renovate bumps the pin. |
| Weekly job fails on `repo-deps` | Update the affected Composer/pip dependency in a normal PR. |
| Checking a specific CVE | Search the deploy-time scan output, or the Security tab, for the CVE ID. |

## Verification

- `docker compose config --images` on the rendered gighive2 templates lists `ubuntu-apache-img:1.00`, the pinned MySQL ref, and the ai-worker image. The telemetry template lists `telemetry-receiver-php:1.0` and the pinned MySQL ref.
- `trivy_scan.yml` on a test compose project scans every listed image. With a missing compose file it shows the compose error and the play still succeeds.
- `ansible-playbook --syntax-check` passes for `ansible/playbooks/site.yml` and `ansible/playbooks/telemetry_receiver.yml`.
- `renovate-config-validator --strict renovate.json` passes. A local Renovate dry run detects both MySQL pins (`currentValue: 8.4.13`).
- `actionlint` passes on `.github/workflows/trivy-weekly.yml`. The workflow's build and scan commands were run locally for each target.
- After merge: run the workflow once from the Actions tab and confirm five jobs plus SARIF results in the Security tab. After installing Renovate, confirm the Dependency Dashboard issue lists the `mysql image` dependency.
- After deploy: the `post_build_checks` and telemetry post-deploy output include a Trivy report for every image listed above.

## Rollback

- Weekly scan: delete or disable `.github/workflows/trivy-weekly.yml` (Actions → workflow → Disable).
- Renovate: uninstall the app or delete `renovate.json`.
- MySQL pin: set `mysql_docker_image` / `telemetry_receiver_mysql_image` back to `mysql:8.4`. This doesn't downgrade an already-upgraded data directory; restoring an older version needs a DB backup.
- Rebuild-on-deploy: remove `no_cache` / `pull` from the three `build:` blocks.
- Deploy-time scan: set `trivy_scan_enabled: false`.

## Assumptions

- GitHub-hosted runners can pull from Docker Hub without hitting anonymous pull rate limits at this volume (I haven't verified this).
- Code scanning SARIF upload is available for free because `gighive/gighive` is public.
- `prod.yml` group_vars are representative of the image actually deployed to prod. The weekly build uses the `gighive` app flavor overlay.
