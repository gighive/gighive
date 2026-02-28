# Process: original creation of the one-shot bundle

## Purpose / goal
The goal was to create a **portable “one-shot” bundle** so someone can stand up GigHive via `docker compose` without cloning the repo and without relying on host-specific absolute paths.

The core idea was to ship:
- A portable `docker-compose.yml`
- The required external configs (apache + mysql)
- The required `tusd` hook(s)
- A guided `install.sh` that prompts for the environment-specific values and then runs `docker compose up -d`

## Inputs / constraints
- The existing compose used host-absolute paths (e.g. `/home/ubuntu/...`). For a portable bundle, those mounts needed to become **relative paths** inside the extracted bundle (e.g. `./apache/externalConfigs/...`).
- Large/variable user data such as the **audio/video directories** should remain **host mounts**, but parameterized via env vars rather than hardcoded paths:
  - `${GIGHIVE_AUDIO_DIR}:/var/www/html/audio`
  - `${GIGHIVE_VIDEO_DIR}:/var/www/html/video`
- For the initial portable bundle experience, the default MySQL initialization should use the **sample dataset**.

## Process summary (chronological)

### 1) Identify what must be included in the bundle
We enumerated what a fresh host would need so the stack can start cleanly:
- Apache external configs:
  - `ansible/roles/docker/files/apache/externalConfigs/`
- MySQL external configs + init scripts:
  - `ansible/roles/docker/files/mysql/externalConfigs/`
  - Expected pieces included:
    - `create_music_db.sql`
    - `load_and_transform.sql`
    - `z-custommysqld.cnf`
    - A `prepped_csvs/` directory suitable for sample-by-default
- tusd hooks:
  - `ansible/roles/docker/files/tusd/hooks/` (notably `post-finish`)

### 2) Package the external config directories as `.tgz` building blocks
You requested concrete tar/zip commands, and we used `.tgz` as the preferred packaging format.

Example (apache):
- `tar -czf apache-externalConfigs.tgz apache/externalConfigs`

We then worked toward having three input bundles under `docs/`:
- `docs/apache-externalConfigs.tgz`
- `docs/mysql-externalConfigs.tgz`
- `docs/tusd-hooks.tgz`

### 3) Verify the contents of the `.tgz` bundles
We inspected bundle contents to ensure the portable compose could mount everything it expects:
- `apache-externalConfigs.tgz` contained the expected Apache/PHP-FPM configs plus the CRS subtree.
- `tusd-hooks.tgz` contained `tusd/hooks/post-finish`.
- `mysql-externalConfigs.tgz` listing confirmed presence of:
  - `mysql/externalConfigs/create_music_db.sql`
  - `mysql/externalConfigs/load_and_transform.sql`
  - `mysql/externalConfigs/z-custommysqld.cnf`
  - plus the `prepped_csvs` structure

This verification mattered because missing files would break the “portable folder + relative mounts” approach at runtime.

### 4) Define the “one-shot” installer UX
We defined two delivery modes for the same installer concept:
- Bundle-based (safer/clearer):
  - User extracts a tarball and runs `./install.sh`
  - Script prompts for environment-specific values (URLs, passwords, media paths), writes `.env` files, then runs `docker compose up -d`
- Curl-based (ZAP-like convenience):
  - `curl -fsSL <url>/install.sh | bash`

You chose:
- Provide **both** `install.sh` and the curl invocation pattern
- Use the **sample dataset** by default

### 5) Decide hosting strategy for `install.sh`
We discussed where to host `install.sh` and landed on the recommendation:
- Keep `install.sh` in the git repo and host it at a stable raw URL.
- Use **GitHub Releases** when distributing a bundle tarball so you can pin installs to a tag/version.

### 6) Converge on the target bundle folder layout
We defined the concrete directory structure the tarball should contain:
- `gighive-one-shot-bundle/`
  - `docker-compose.yml` (portable: **relative paths**, not host absolute paths)
  - `install.sh`
  - `apache/externalConfigs/` (from `apache-externalConfigs.tgz`)
  - `mysql/externalConfigs/` (from `mysql-externalConfigs.tgz`, sample default)
  - `tusd/hooks/` (from `tusd-hooks.tgz`)

### 7) Provide assembly commands to build the final tarball
We defined the repeatable workflow:
- Create the bundle directory.
- Extract the 3 `.tgz` inputs into the correct subpaths.
- Place the portable `docker-compose.yml` and `install.sh` at the top level of the bundle directory.
- Create the deliverable:
  - `tar -czf gighive-one-shot-bundle.tgz gighive-one-shot-bundle`
- Optional quick sanity check:
  - `tar -tzf gighive-one-shot-bundle.tgz | sed -n '1,120p'`

### 8) Confirmed results from your local assembly
You provided a `tree` of the assembled `gighive-one-shot-bundle/` showing:
- `apache/externalConfigs` populated
- `mysql/externalConfigs` populated (including `prepped_csvs`)
- `tusd/` present
- `install.sh` present

## Cutoff
This document summarizes the work up to (but not including) the subsequent request to document the rationale and implementation plan for hosting the tarball under a staging-only `downloads/` directory.
