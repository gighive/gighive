# MySQL bind-mount behavior: why host CSV ownership becomes uid 999

## Symptom
When using the one-shot bundle, the sample CSVs under:

- `mysql/externalConfigs/prepped_csvs/sample/`

start out owned by the user who created the tarball (e.g. `sodo:sodo`), but after running `./install.sh` and starting the Compose stack, those files can appear on the host as owned by a numeric uid (commonly `999`), e.g.:

- `999:ubuntu`

## Root cause
This is a normal side effect of **bind-mounting host files into a container** and then allowing that container to adjust ownership/permissions.

In the Quickstart `docker-compose.yml`, the host directory is bind-mounted into the MySQL container:

- `./mysql/externalConfigs/prepped_csvs/sample:/var/lib/mysql-files/`

When the official `mysql:8.x` image starts, its entrypoint performs initialization and often ensures internal directories are owned by the `mysql` user.

Inside the container, the `mysql` user commonly has:

- uid `999` (and typically gid `999`)

If the container runs `chown` (or similar) against a bind-mounted path, the host sees that ownership change as:

- uid `999`

On hosts where there is no user with uid `999`, `ls -l` shows it numerically.

### How to confirm
On the target host:

- `docker exec -it mysqlServer id mysql`

This typically prints `uid=999(mysql) gid=999(mysql)`.

## Why it matters
Even if the files remain readable, ownership changes can be surprising and may:

- break expectations for backups/editing
- cause git workspace annoyance (if running in a dev checkout)
- create permission issues if later processes expect a specific owner

## Best practices for MySQL initialization CSVs (seed data)
Because these CSVs are **seed inputs** used during initialization (not runtime state), best practice is to prevent the container from mutating them.

### Option A (recommended first): mount seed CSVs read-only
Mount the seed directory as read-only so the container cannot `chown`/modify host files:

- `./mysql/externalConfigs/prepped_csvs/sample:/var/lib/mysql-files:ro`

Pros:
- minimal change
- preserves host ownership and content

Cons:
- if MySQL truly requires write access to that directory, startup will fail (usually seed CSVs only need read access)

### Option B (most robust): copy seed data into an internal volume on first run
Pattern:

- bind-mount the seed CSVs into the container at a dedicated path read-only (e.g. `/seed:ro`)
- on container init, copy seed files from `/seed` into a named volume or internal writable directory
- MySQL reads from the internal location

Pros:
- host files never get mutated
- MySQL has full freedom internally

Cons:
- requires a small init script or entrypoint hook

### Option C (workaround): chown the host directory back after install
Manually:

- `sudo chown -R <user>:<group> mysql/externalConfigs/prepped_csvs/sample`

Pros:
- simple

Cons:
- container may repeat the ownership change on subsequent runs

## Recommendation for GigHive Quickstart
Use **Option A** unless/until proven insufficient.

- Seed data should be treated as immutable inputs.
- If MySQL needs write access for some reason, then implement **Option B**.
