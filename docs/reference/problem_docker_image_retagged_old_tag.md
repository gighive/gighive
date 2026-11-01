---
description: RCA for a Jekyll-breaking Docker/Compose incident doc; disable Liquid rendering for Docker and Ansible brace syntax
render_with_liquid: false
---

# Problem: Docker Compose Re-tagged Apache Image and Left the Running Container Pointing at a Missing Old Image ID

## Symptom

The Ansible playbook failed during the `ai_worker` role at:

```text
TASK [ai_worker : Deploy ai-worker container project_src=&#123;&#123; docker_dir &#125;&#125;, files=['docker-compose.yml', 'docker-compose-ai-worker.yml'], state=present, build=always]
```

The module error showed that `community.docker.docker_compose_v2` called:

```bash
docker compose --project-directory /home/ubuntu/gighive/ansible/roles/docker/files \
  --file docker-compose.yml \
  --file docker-compose-ai-worker.yml \
  images --format json
```

and Docker returned:

```text
Error response from daemon: No such image: sha256:c0309bc4058f2c71a919753bd1366879fa7d20f00b570bd21ff5df23a374d429
```

At the time of failure:

- `docker compose ... ps -a` still showed `apacheWebServer` running
- the `IMAGE` column for `apacheWebServer` was the same missing digest
- `docker compose ... images` failed on that digest

## Root Cause

This was not an `ai-worker` image problem.

The root cause was **project-scoped Compose execution instead of service-scoped Compose execution**.

The failure chain was:

1. The main `docker` role built an Apache image with digest `sha256:c0309bc...`.
2. Docker tagged that image as `ubuntu-apache-img:1.00`.
3. Docker then created and started the `apacheWebServer` container from that image.
4. Later in the same playbook run, the `ai_worker` role invoked Docker Compose again against the combined project (`docker-compose.yml` + `docker-compose-ai-worker.yml`) with `build: always`.
5. Because that deploy task was **project-scoped** and not limited to `services: [ai-worker]`, Compose considered every build-enabled service in the combined project eligible for rebuild.
6. The main `docker-compose.yml` includes `apacheWebServer` with its own `build:` block, so the `ai_worker` deploy rebuilt Apache too.
7. During that later Compose run, Docker built a new Apache image with digest `sha256:a00cd3a...` and moved the `ubuntu-apache-img:1.00` tag to the new image.
8. The already-running `apacheWebServer` container still referenced the old image object `sha256:c0309bc...`.
9. That old image object was no longer locally inspectable, so `docker compose images --format json` failed.
10. `community.docker.docker_compose_v2` surfaced that Compose image-listing failure as the `ai_worker` task failure.

## 100% Proof

### Current container and tag state

```bash
docker inspect apacheWebServer --format '&#123;&#123;.Image&#125;&#125;|&#123;&#123;.Config.Image&#125;&#125;|&#123;&#123;.Id&#125;&#125;'
docker image inspect ubuntu-apache-img:1.00 --format '&#123;&#123;.Id&#125;&#125;|&#123;&#123;json .RepoTags&#125;&#125;'
```

Observed:

```text
sha256:c0309bc4058f2c71a919753bd1366879fa7d20f00b570bd21ff5df23a374d429|ubuntu-apache-img:1.00|29ed26fd...
sha256:a00cd3a69cf2974cf9a96592995d80267c10e0a01263d23623d4138173271b90|["ubuntu-apache-img:1.00"]
```

This proves:

- the running container points at `c030...`
- the tag `ubuntu-apache-img:1.00` now points at `a00...`
- those are different image objects

### The old image object is missing

```bash
docker image inspect sha256:c0309bc4058f2c71a919753bd1366879fa7d20f00b570bd21ff5df23a374d429
```

Observed:

```text
Error response from daemon: No such image: sha256:c0309bc4058f2c71a919753bd1366879fa7d20f00b570bd21ff5df23a374d429
```

### Docker event history proves the retag sequence

```bash
docker events --since '2026-08-06T18:58:00' --until '2026-08-06T19:10:30' --filter type=image
docker events --since '2026-08-06T18:58:00' --until '2026-08-06T19:10:30' --filter type=container
```

Observed image events:

```text
2026-08-06T19:05:19 image create sha256:c0309bc...
2026-08-06T19:05:19 image tag sha256:c0309bc... name=ubuntu-apache-img:1.00
2026-08-06T19:05:47 image create sha256:a00cd3a...
2026-08-06T19:05:47 image tag sha256:a00cd3a... name=ubuntu-apache-img:1.00
```

Observed container events:

```text
2026-08-06T19:05:23 container create ... name=apacheWebServer image=ubuntu-apache-img:1.00
2026-08-06T19:05:24 container start ... name=apacheWebServer image=ubuntu-apache-img:1.00
```

This proves the exact antecedent sequence:

- image `c030...` was built and tagged
- `apacheWebServer` was created from that tag
- later, image `a00...` was built and tagged with the same tag
- the tag moved, but the running container still pointed at the old image object

## Diagnosis Commands

```bash
cd /home/ubuntu/gighive/ansible/roles/docker/files

# Show the broken compose behavior
docker compose -f docker-compose.yml -f docker-compose-ai-worker.yml ps -a
docker compose -f docker-compose.yml -f docker-compose-ai-worker.yml images

# Show container image object vs configured tag
docker inspect apacheWebServer --format '&#123;&#123;.Image&#125;&#125;|&#123;&#123;.Config.Image&#125;&#125;|&#123;&#123;.Id&#125;&#125;'
docker image inspect ubuntu-apache-img:1.00 --format '&#123;&#123;.Id&#125;&#125;|&#123;&#123;json .RepoTags&#125;&#125;'

# Prove the old image object is gone
docker image inspect sha256:c0309bc4058f2c71a919753bd1366879fa7d20f00b570bd21ff5df23a374d429

# Prove the historical retag sequence
docker events --since '2026-08-06T18:58:00' --until '2026-08-06T19:10:30' --filter type=image
docker events --since '2026-08-06T18:58:00' --until '2026-08-06T19:10:30' --filter type=container
```

## Why the Failure Surfaced in the `ai_worker` Role

The `ai_worker` role does not deploy only `ai-worker`. Its deploy task uses the combined compose project and `build: always`, but does **not** specify `services: [ai-worker]`.

That means the task is **project-scoped**, not **service-scoped**. Because the main `docker-compose.yml` contains `apacheWebServer` with a `build:` block, Compose rebuilt Apache as part of the `ai_worker` deploy.

So this was not a timing issue or random Docker behavior. It happened because the deploy operated on the whole merged project instead of being limited to the `ai-worker` service.

As a result, the later Compose invocation caused the Apache retagging problem, and the Ansible module failed while trying to list project images.

## Detection Plan (detection only, does not fix the root cause)

A single pre-flight task is added immediately before `TASK [ai_worker : Deploy ai-worker container ...]`
in `ansible/roles/ai_worker/tasks/main.yml`.

### What the task does

The final detection mechanism does **not** try to inspect current container image IDs.
That earlier approach was logically wrong for this case because the stale/missing image
condition is created **during** the later `ai_worker` deploy, not necessarily present
before it starts.

Instead, the detection now checks for the actual risky precondition:

1. Read `docker-compose.yml`.
2. Read `docker-compose-ai-worker.yml`.
3. Combine their `services` maps exactly the way the deploy task effectively does.
4. Find every service in the combined project that has a `build:` block.
5. Exclude `ai-worker` itself.
6. If any additional build-enabled services remain, fail before deploy and print their names.

In the current project, that additional build-enabled service is `apacheWebServer`.

### Example failure message

```text
The ai_worker deploy uses the combined compose project with build=always and no service scope.
The combined project includes additional build-enabled service(s): apacheWebServer.
Running this task will rebuild those service images during the ai_worker deploy, which is the
condition that led to the stale-image / "No such image" failure. Detection is stopping here
before the deploy runs.
```

### Where the task belongs in the playbook

The detection task must live in `ansible/roles/ai_worker/tasks/main.yml`, immediately
before the `Deploy ai-worker container` task — not in `post_build_checks`.

The playbook execution order is:

```
docker role          → starts apacheWebServer container
...
ai_worker role
  Sync ai-worker source
  Render docker-compose-ai-worker.yml
  [NEW] Preflight: detect unscoped compose build risk          ← detection fires here
  Deploy ai-worker container                                   ← risky task
post_build_checks    → runs only if ai_worker role succeeded
```

`post_build_checks` runs after `ai_worker` and only when the play has not already
failed. If `ai_worker` fails, `post_build_checks` is never reached. Placing detection
there would catch nothing in this failure mode.

### Why this is the right single check

- It detects the **actual hazard condition** proven in this incident: project-scoped
  `build: always` on a combined project that includes non-target services with `build:`.
- It fires before `community.docker.docker_compose_v2` runs the risky deploy.
- It names the additional build-enabled service(s) that would be rebuilt.
- It uses native Ansible data handling (`slurp`, `from_yaml`, `set_fact`, `fail`) rather
  than brittle shell parsing.

### Important caveat

This detection task will now fail every time in the current design, because Apache does
have a `build:` stanza in the combined project and the deploy task still has no service
scope. That is expected and correct for a detection-only solution.

## Final Fix Implemented

The final fix was implemented in `ansible/roles/ai_worker/tasks/main.yml` by scoping the
`community.docker.docker_compose_v2` deploy task to the `ai-worker` service only.

### Exact Ansible code

```yaml
- name: Deploy ai-worker container
  community.docker.docker_compose_v2:
    project_src: "&#123;&#123; docker_dir &#125;&#125;"
    files:
      - docker-compose.yml
      - docker-compose-ai-worker.yml
    services:
      - ai-worker
    state: present
    build: always
  when: ai_worker_enabled | default(false) | bool
```

### Why this fixed the root cause

Before the fix, the deploy task was **project-scoped** because it used the combined
compose project with `build: always` but did not specify `services:`.

That caused Docker Compose to consider every build-enabled service in the merged project,
including `apacheWebServer`, eligible for rebuild during the `ai_worker` deploy.  The unfortunate side effect was that Apache could be rebuilt and re-tagged under the same image name while an already-running container still pointed at the previous image object, leaving the shared compose project vulnerable to a later `No such image` failure.

After the fix, the deploy is **service-scoped**:
- the combined compose files are still loaded
- `build: always` still applies
- but the operation is restricted to `ai-worker`
- Apache is no longer rebuilt during the `ai_worker` deploy

This directly removed the retagging path that had been creating stale image references in
the shared compose project.

### Why this follows existing Ansible patterns

The existing handler already used the correct service-scoped pattern:

```yaml
- name: restart ai-worker
  community.docker.docker_compose_v2:
    project_src: "&#123;&#123; docker_dir &#125;&#125;"
    files:
      - docker-compose.yml
      - docker-compose-ai-worker.yml
    services:
      - ai-worker
    state: restarted
```

So the final fix simply brought the main deploy task into alignment with the handler.

### Validation of the final fix

After the stale historical containers were cleaned up, a normal playbook run completed
successfully.

That validates both conclusions:
1. the historical stale container state was the immediate blocker to recovery
2. the service-scoped `ai-worker` deploy prevented Apache from being rebuilt again

## Manual Cleanup Commands Used

After the service-scoping fix was implemented, the compose project still contained stale
historical container references, so one manual cleanup pass was required before the next
successful deploy.

### 1. Rebuild and recreate Apache with the correct current image

```bash
cd /home/ubuntu/gighive/ansible/roles/docker/files
docker rm -f apacheWebServer
docker compose -f docker-compose.yml up -d --build apacheWebServer
```

This repaired the stale Apache container reference and recreated `apacheWebServer`
against the current `ubuntu-apache-img:1.00` image.

### 2. Verify which container still held the missing image digest

```bash
cd /home/ubuntu/gighive/ansible/roles/docker/files
docker compose -f docker-compose.yml -f docker-compose-ai-worker.yml ps -a
docker inspect ai-worker --format '&#123;&#123;.Image&#125;&#125;|&#123;&#123;.Config.Image&#125;&#125;'
docker inspect mysqlServer --format '&#123;&#123;.Image&#125;&#125;|&#123;&#123;.Config.Image&#125;&#125;'
docker inspect apacheWebServer_tusd --format '&#123;&#123;.Image&#125;&#125;|&#123;&#123;.Config.Image&#125;&#125;'
```

Important correction: the tusd container name in this project is `apacheWebServer_tusd`,
not `tusdServer`.

That verification proved the remaining stale reference was the old `ai-worker`
container, which still pointed at the missing digest `sha256:73373643...`.

### 3. Remove the stale `ai-worker` container

```bash
docker rm -f ai-worker
```

### 4. Confirm the combined compose project image listing is healthy again

```bash
docker compose -f docker-compose.yml -f docker-compose-ai-worker.yml images
```

After removing `ai-worker`, `docker compose ... images` succeeded, confirming that the
compose project no longer contained any containers pointing at missing image objects.

## Automatic Recovery — Follow-up Fix (2026-08-15)

A second stale-image occurrence happened on 2026-08-15 (SHA `sha256:bf04ab...` missing,
`ai-worker` container was the stale holder). The service-scoping fix from the original
incident was still in place and working correctly — this recurrence was caused by a
separate prior playbook run leaving a stale container behind, not by the Apache
rebuild path.

To eliminate the need for manual cleanup on future occurrences, four pre-flight tasks
were added to `ansible/roles/ai_worker/tasks/main.yml` immediately before the
`Deploy ai-worker container` task.

### What the tasks do

1. **`community.docker.docker_host_info` (`images: true`)** — fetches the definitive
   list of all image IDs (`sha256:...`) currently available on the Docker host.
2. **`community.docker.docker_container_info` loop** — inspects all four containers
   in the combined project (`apacheWebServer`, `apacheWebServer_tusd`, `mysqlServer`,
   `ai-worker`) to read the image SHA each one was created from.
3. **`ansible.builtin.set_fact`** — flattens the available image IDs into a simple list
   (`_available_image_ids`) for use in the next task's `when` condition.
4. **`community.docker.docker_container` loop (`state: absent`)** — removes any
   container whose `.Image` SHA is not present in `_available_image_ids`.

Zero `shell` or `command` modules are used. All data manipulation is done with
standard Ansible modules and Jinja2 filters.

### Behavior on a clean run

All container image SHAs are found in `_available_image_ids`. The remove task's loop
iterates over an empty filtered list — nothing is removed, no disruption.

### Behavior on a stale-image run

One or more containers whose image SHA is absent from `_available_image_ids` are
automatically removed before the deploy runs. The `docker compose images` pre-flight
check inside `community.docker.docker_compose_v2` then succeeds, and the deploy
rebuilds and recreates the removed container(s) as normal. No manual intervention required.

### Validation of the automatic recovery

The updated role was first tested in dev, where the recovery tasks detected the
`sha256:bf04ab...` missing image and removed the stale `ai-worker` container before the
deploy ran; the playbook completed successfully.

A follow-up run on staging initially failed because the updated role had not yet been
copied there. Once the updated `main.yml` was in place, the staging run also completed
cleanly. The role was then promoted and run successfully across lab and production.

| Environment | Date | Result |
|---|---|---|
| dev | 2026-08-15 | Recovery tasks executed, stale container removed, deploy succeeded |
| staging | 2026-08-15 | Succeeded after updated role was copied |
| lab | 2026-08-15 | Succeeded |
| production | 2026-08-15 | Succeeded |

All environments now deploy without manual cleanup.

### Why the Manual Cleanup Commands section above is now superseded

The manual steps in `## Manual Cleanup Commands Used` are retained for historical
reference, but the automatic pre-flight tasks make them unnecessary for future
occurrences. The playbook self-heals on the next run.

## Third Occurrence — Compose Project State Stale SHA (2026-08-16)

A third `No such image: sha256:fb34ec...` failure was observed on 2026-08-16 during the
Phase 2 media storage refactor rollout on `gighive2` (dev VM).

### Why the 2026-08-15 automatic recovery did not prevent this

The 2026-08-15 fix removes containers whose `.Image` SHA is absent from the live image
store. The check uses `docker_container_info` and filters for `exists: true` before
inspecting `.Image`.

This occurrence was different: **no running or stopped container held the stale SHA**.
The stale reference lived only in Docker's compose project state — an internal compose
record from a prior deploy cycle that was never cleaned up. In that state,
`docker_container_info` returns `exists: false` for the service, so
`selectattr('exists', 'equalto', true)` excludes it from the removal loop. The stale
reference persists, and the next `docker compose images --format json` pre-flight call
inside `community.docker.docker_compose_v2` fails trying to resolve it.

### Exact error seen

```text
TASK [ai_worker : Deploy ai-worker container ...]
fatal: [gighive_vm]: FAILED! => {
  "msg": "Error while parsing JSON output of docker compose ... images --format json:
          Expecting value: line 1 column 1 (char 0)\n
          Error output: {\"error\":true,\"message\":\"Error response from daemon:
          No such image: sha256:fb34ec3582b5f7eeb7dceef0995dd965e427d82a900ed499922d37844dc5e161\"}"
}
```

The playbook was run with `--skip-tags vbox_provision,db_migrations,...` against
`inventory_gighive2.yml` as the first Phase 2 validation run.

### Root cause

The Apache container was rebuilt by the `docker` role earlier in the same playbook run
(expected — the Phase 2 deploy updates the webroot files). That rebuild moved the
`ubuntu-apache-img:1.00` tag to a new SHA. Docker's compose project state still held
an `ai-worker` service record referencing the prior SHA. The `ai-worker` container itself
did not exist by name (`docker_container_info` → `exists: false`), so the existing
stale-container removal loop did not remove anything. The stale SHA reference in compose
project state was enough to cause `docker compose images` to fail.

### Fix implemented (2026-08-16)

A `docker compose down --remove-orphans` step was added immediately before the `Deploy
ai-worker container` task in `ansible/roles/ai_worker/tasks/main.yml`. This explicitly
tears down the compose service state for `ai-worker` before rebuilding, unconditionally
clearing any stale SHA reference regardless of whether a named container exists.

`failed_when: false` is set on the tear-down task so it is safe on first-ever deploys
where no prior compose state exists.

```yaml
- name: Tear down ai-worker compose state to clear any stale image references
  community.docker.docker_compose_v2:
    project_src: "{{ docker_dir }}"
    files:
      - docker-compose.yml
      - docker-compose-ai-worker.yml
    services:
      - ai-worker
    state: absent
    remove_orphans: true
  failed_when: false
  when: ai_worker_enabled | default(false) | bool

- name: Deploy ai-worker container
  community.docker.docker_compose_v2:
    project_src: "{{ docker_dir }}"
    files:
      - docker-compose.yml
      - docker-compose-ai-worker.yml
    services:
      - ai-worker
    state: present
    build: always
  when: ai_worker_enabled | default(false) | bool
```

### Why this is correct and safe

- `state: absent` on a service-scoped task removes only the `ai-worker` compose state
  and container, not the rest of the project (`apacheWebServer`, `mysqlServer`, etc.).
- The subsequent `state: present` + `build: always` immediately rebuilds and recreates
  the service.
- The pre-flight container-info removal loop from the 2026-08-15 fix is still in place
  and still covers the case where a named stale container does exist. The two mechanisms
  are complementary, not redundant.
- `failed_when: false` is intentional: on a brand-new host where no prior compose state
  exists, `state: absent` would otherwise exit non-zero.

### Summary of all three failure variants

| Date | Stale reference lived in | Detected by | Fixed by |
|---|---|---|---|
| 2026-08-06 | Running container pointing at old SHA | Manual diagnosis | Service-scoping the deploy task |
| 2026-08-15 | Stopped/named container pointing at old SHA | Pre-flight `docker_container_info` loop | Automatic container removal loop |
| 2026-08-16 | Compose project state only (no named container) | Not caught by container-info loop | `state: absent` tear-down before deploy |

## Related Files

- `ansible/roles/ai_worker/tasks/main.yml` — the failing `docker_compose_v2` task
- `ansible/roles/docker/templates/docker-compose.yml.j2` — Apache service definition with `build:` and `image:`
- `ansible/inventories/group_vars/gighive2/gighive2.yml` — defines `apache_docker_image: "ubuntu-apache-img:1.00"`
- `ansible/roles/docker/tasks/main.yml` — starts the main compose project earlier in the playbook
