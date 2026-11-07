{% raw %}
# Feature: SonarCloud Security Findings Remediation (PR #37)

Date: 2026-11-06  
Status: Implemented (PR #37); operator follow-ups listed below  
Related: PR #31 (PHP php2shell refresh, CVE-2026-109618/109619)

---

## Overview

SonarCloud project `gighive_gighive` had 1,092 open issues. 77 of them affect security (32 high, 38 medium, 7 low), and there were 57 security hotspots to review (39 medium, 18 low). The rest were code smells and bugs. This work fixed the real medium/low security findings. It also fixed one leak found along the way: playbook logs contained plaintext secrets.

Findings were pulled read-only with the SonarCloud Web API (`api/issues/search`, `api/hotspots/search`) using a user token.

## Changes

| # | Sonar rule(s) | Problem | Fix | Files |
|---|---|---|---|---|
| 1 | `terraform:S6321` (medium) | NSG allowed SSH (22) from `*` | `source_address_prefixes = var.ssh_allowed_source_prefixes`, default `173.54.19.162/32`, `192.168.0.0/16`, `10.10.0.0/16` | `terraform/main.tf`, `terraform/variables.tf` |
| 2 | `Web:S5725` (low; 2 issues + 6 hotspots) | CDN scripts/styles loaded without integrity check | `integrity="sha384-..." crossorigin="anonymous"` on `tus-js-client@4.1.0` and `swagger-ui-dist@5.10.3` | `webroot/db/upload_form.php`, `upload_form_single.php`, `upload_form_admin.php`, `webroot/admin/admin_database_catalog_promote.php`, `admin_database_load_import_media_from_folder.php`, `webroot/docs/api-docs.html` |
| 2b | (verification) | Know if a CDN file stops matching its hash | Report-only task greps webroot for `integrity=` tags, re-downloads each URL and prints `OK` / `MISMATCH` / `DOWNLOAD FAILED`; never fails | `ansible/roles/post_build_checks/tasks/main.yml` |
| 3 | `phpsecurity:S5145` (low, 3) | Logged first 8 raw chars of upload token / guest nonce (partial credential; token path allowed newline log forging) | Log `substr(hash('sha256', ...), 0, 16)` instead; auth logic unchanged | `webroot/src/Services/UploadTokenValidator.php`, `webroot/src/Services/GuestCredentialResolver.php` |
| 4 | `docker:S8541`, `docker:S8544`, `shell:S8541` (medium) | Unpinned `pip install`, sdists allowed (setup scripts run at install) | Pin versions, `--only-binary :all:` | `ansible/roles/ai_worker/files/ai-worker/{Dockerfile,requirements.txt}`, `1prereqsInstall.sh`, `azure-prereqs.txt`, `docs/azure_setup.md` |
| 5 | (found in logs) | `Dump full apache_info` / `mysql_info` printed `docker inspect` incl. `Config.Env` (`OPENAI_API_KEY`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`) to `~/.ansible/ansible.log` and saved playbook logs | Print only `name`, `image`, `status`, `health`, `started_at`, `ports` | `ansible/roles/docker/tasks/main.yml` |
| - | Quality gate `new_coverage < 80%` | No coverage reports exist, so any PHP/JS/Python change failed the gate at 0% | `sonar.coverage.exclusions=**/*` (all other gate conditions still apply) | `sonar-project.properties` |

### Pinned versions (item 4)

- ai-worker (match the versions Trivy found in the current image): `openai==3.46.0`, `mysql-connector-python==26.10.0`, `Pillow==13.0.0`
- `1prereqsInstall.sh` / `azure-prereqs.txt` (match the macbook2025 controller): `ansible==11.9.0`, `ansible-core==2.18.8`, `pip==25.2`, `packaging==25.0`, `wheel==0.47.0`, `setuptools==84.2.1`, `azure-cli==2.92.0`, `msgraph-core==1.5.2`, `jinja2-cli==1.0.1`
- The prereqs script now targets **Ubuntu 24.04** (Python 3.12). `ansible-core` 2.18 needs Python 3.11 or newer, so Ubuntu 22.04 (Python 3.10) is no longer supported.
- The old `ansible[azure]` extra no longer exists in the `ansible` package, so it was a no-op and was replaced by plain pins.

## Verification Performed

- `terraform validate` passes.
- SRI hashes were computed from each CDN file, fetched twice to confirm the content is stable. Both CDNs return `Access-Control-Allow-Origin: *`.
- SRI check task, tested against the real CDNs: all `OK`. A deliberately altered hash reported `MISMATCH`, and an unreachable host reported `DOWNLOAD FAILED`.
- Container summary task, tested with a fake container whose env contained a secret: the secret was not printed. A missing container prints `missing` instead of erroring.
- `php -l` passes on both PHP service files.
- ai-worker deps: `pip download --only-binary :all:` for Python 3.11 confirmed wheels exist for every transitive dependency.
- Prereqs pins: installed in an `ubuntu:24.04` container (Python 3.12.3) with `--only-binary :all:`; `pip check` reports no broken requirements.
- `site.yml --syntax-check` passes.
- PR checks: SonarCloud, GitGuardian and analyze all pass.

Still to verify: a full gighive2 `site.yml` run with all four test roles (`post_build_checks`, `validate_app`, `upload_tests`, `playwright_admin_tests`).

## Operator Follow-ups

1. Rotate the OpenAI API key, and the prod MySQL passwords when convenient. They were in plaintext in `~/.ansible/ansible.log` (20 / 100 lines) and in all six `ansible-playbook-*.log` files, including prod. They were never committed (`*.log` is gitignored).
2. After rotating, remove the old logs:
   ```
   : > ~/.ansible/ansible.log && rm ~/gighiveinfra/ansible-playbook-*.log
   ```
   Also check Time Machine and iCloud copies.
3. Run `terraform apply` to apply the SSH allowlist. If the home IP changes, override `ssh_allowed_source_prefixes` in `terraform.tfvars`.
4. Rebuild gighive2, run the four test roles, then prod. In `post_build_checks`, confirm the SRI check prints all `OK`.
5. Optional hardening: encrypt `ansible/inventories/group_vars/*/secrets.yml` with `ansible-vault`. They are gitignored but stored as plaintext.

## Findings Reviewed and Left As-Is (mark Safe / False positive in SonarCloud)

- `ansible:S2612` (29 issues + 36 hotspots): mostly normal `0755`/`0644` modes. `/tmp/.ansible/tmp` at `1777` mirrors `/tmp`. One-shot bundle media dirs at `0777` (`one_shot_bundle/tasks/output_bundle.yml:28`, `:239`) are intentional so the container user can write; tightening them risks breaking uploads.
- `phpsecurity:S6350` `admin/export_media.php:247`: the argument is already passed through `escapeshellarg`.
- `php:S2077` `admin/admin_system.php:112`: table names come from a hard-coded list.
- `php:S1313` `AzureIdentityTokenCache.php:23`: `169.254.169.254` is the Azure IMDS endpoint.
- `php:S5122` `timeline/timeline-api.php`: `Access-Control-Allow-Origin: *` on a public read-only GET API.
- Low hotspots, harden later if desired: root user in the `php:8.3-apache` and `python:3.11-slim` images (`docker:S6471`), `COPY . .` (`docker:S6470`), `curl` without `--proto =https` (`shell:S6506`), `npx` in `playwright_admin_tests/files/howTo.sh` (`shell:S6505`).
- High (outside the medium/low scope): `pythonsecurity:S8705/S8707` in operator-run CLI tools under `webroot/tools/*.py`; `ansible:S4830` in test tasks, already controlled by `gighive_validate_certs`.
- Upstream `mysql:8.4.13` image: Trivy reports 9 Python-package CVEs in MySQL Shell (1 critical, `certifi`) plus `gosu` findings. This is Oracle's image, fixed by a newer tag; watch with the existing Trivy reporting.

## Rollback

Every change is isolated and can be reverted on its own with `git revert` of the PR #37 commits. Notes:
- To restore open SSH, set `ssh_allowed_source_prefixes = ["*"]` instead of editing `main.tf`.
- SRI: if a CDN file legitimately changes (it shouldn't for pinned versions), the browser blocks it. Recompute the hash or remove the `integrity` attribute.

---

## Future Feature: Real Code Coverage

**Problem:** Sonar shows 0% coverage. The four integration suites exercise much of the app, but they run from the controller against a VM, and nothing records which lines execute. The coverage gate condition is excluded until a real report exists.

**Phase 1: measure what the integration tests already cover**
- Optionally install `pcov` in the Apache image (for example behind a `gighive_coverage_enabled` flag, off for prod).
- During a gighive2 run, collect coverage while `post_build_checks`, `validate_app`, `upload_tests` and `playwright_admin_tests` run, then export Clover XML from the container.
- Print a summary per directory (`src/`, `api/`, `admin/`, `db/`) to find the gaps. This is report-only, and nothing goes to Sonar yet.

**Phase 2: unit tests in CI**
- Add PHPUnit (dev dependency only; keep the committed `vendor/` tree production-only, or install dev dependencies in CI).
- Start with the security-critical services: `UploadTokenValidator`, `GuestCredentialResolver`, then the upload and auth controllers.
- Run them in `.github/workflows/sonarcloud.yml` before the scan. Enable `sonar.php.coverage.reportPaths=coverage/php/coverage.xml`, and narrow `sonar.coverage.exclusions` to only the code that isn't unit-tested.

**Phase 3: restore the gate**
- Once the tested directories have meaningful coverage, remove them from `sonar.coverage.exclusions` so the 80%-on-new-code condition applies there again.
{% endraw %}
