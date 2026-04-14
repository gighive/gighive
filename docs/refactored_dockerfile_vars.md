# Refactor: Substitute Ansible Variables into Dockerfile.j2

## Purpose

Three values in `ansible/roles/docker/templates/Dockerfile.j2` are hardcoded
but have existing Ansible variables that already track the same information.
Substituting them keeps the Dockerfile template consistent with the rest of the
codebase and ensures changes to those variables propagate automatically.

---

## Changes (no new variables)

| Line | Before | After | Notes |
|---|---|---|---|
| 1 | `FROM ubuntu:24.04` | `FROM ubuntu:{{ ubuntu_version }}` | Jinja sub — `ubuntu_version` (group_vars) |
| 4 | `ARG PHP_VERSION=8.3` | `ARG PHP_VERSION={{ gighive_php_version }}` | Jinja sub — `gighive_php_version` (group_vars) |
| 59 | `ARG APP_FLAVOR=stormpigs` | `ARG APP_FLAVOR` | Strip dead default — docker-compose always supplies value |

---

## Why `APP_FLAVOR=stormpigs` default never caused problems

`docker-compose.yml.j2` always passes `APP_FLAVOR` as an explicit Docker build
arg:

```yaml
build:
  args:
    APP_FLAVOR: "{{ app_flavor | default('defaultcodebase') }}"
```

Docker ARG defaults are only used when `--build-arg` is **not** supplied.
Because builds always go through `docker compose build`, the correct flavor is
passed every time and the Dockerfile default is never reached. The only scenario
where `stormpigs` would have been used is a raw `docker build` call without
`--build-arg` — which is not part of this project's workflow.

### Why strip the default rather than substitute `{{ app_flavor }}`

The `ARG` declaration itself must remain — Docker requires it before `$APP_FLAVOR`
can be referenced in any `RUN` instruction. But the default value is dead code,
so it is simply removed (`ARG APP_FLAVOR` with no `=`). The correct value is
always injected by docker-compose; there is no fallback scenario worth catering
for.

---

## Implementation status

- [x] `Dockerfile.j2` line 1: `FROM ubuntu:{{ ubuntu_version }}`
- [x] `Dockerfile.j2` line 4: `ARG PHP_VERSION={{ gighive_php_version }}`
- [x] `Dockerfile.j2` line 59: `ARG APP_FLAVOR` (remove `=stormpigs` default)
