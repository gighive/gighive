# PR / Design Doc: Ongoing CVE Scans and Image Rebuild Schedules

**Status:** Implemented in PR #33 (pending merge)
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
- `ai-worker` Dockerfile runs `pip install --no-cache-dir --upgrade setuptools wheel` before installing requirements. Without it, `python:3.11-slim` shipped `jaraco.context` 5.3.0 (CVE-2026-23949) and `wheel` 0.45.1 (CVE-2026-24049); a fresh build now has 0 findings (setuptools 84.2.1, wheel 0.48.0 as of 2026-11-06).
- The `mysql` job fails only on OS-package findings (`--pkg-types os`), because those are what a Renovate pin bump fixes. Oracle's bundled MySQL Shell Python libraries and the `gosu` Go binary (35 findings as of 2026-11-06) can't be fixed from GigHive. They are still uploaded in the SARIF and visible in the Security tab, but they don't fail the job.

## Operating procedure

| Event | Action |
|---|---|
| Weekly job fails on `apache` / `telemetry-php` / `ai-worker` | A fresh rebuild still has a fixable CVE. Check whether the fix has reached the base image or apt; once it has, redeploy (the rebuild picks it up). |
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
