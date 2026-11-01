# Process: Excluding Paths from the One-Shot Bundle

## Problem

`one_shot_bundle_input_paths` in group_vars includes broad source directories such as
`files/apache/webroot` and `files/mysql/externalConfigs`. Some files and subdirectories
under those roots are Stormpigs/defaultcodebase-specific or are development artifacts that
should not be shipped in the GigHive one-shot bundle (OSB).

Without an exclusion mechanism, every file under those roots ends up in the bundle and
(because the Dockerfile does `COPY webroot/ ${WEB_ROOT}/`) in the Docker image as well.

## Solution

A new group_vars variable `one_shot_bundle_exclude_source_paths` holds a list of absolute
source paths (files or directory prefixes) to skip during both bundle monitoring
(`monitor.yml`) and bundle assembly (`output_bundle.yml`).

The exclusion check appends `/` to both the candidate path and each exclude entry, then
uses a substring `in` test. This avoids dynamic regex building and is safe when the
exclude list is empty (an empty list produces zero matches → nothing excluded):

```yaml
when: >-
  (one_shot_bundle_exclude_source_paths | default([])
   | map('regex_replace', '$', '/')
   | select('in', item.path ~ '/')
   | list | length) == 0
```

The `/` append ensures exact path-component boundary matching — e.g. `prepped_csvs/`
will not match a sibling path `prepped_csvs_backup/`.

## Files Changed

| File | Change |
|---|---|
| `ansible/inventories/group_vars/gighive/gighive.yml` | Add `one_shot_bundle_exclude_source_paths` |
| `ansible/inventories/group_vars/gighive2/gighive2.yml` | Add `one_shot_bundle_exclude_source_paths` |
| `ansible/inventories/group_vars/prod/prod.yml` | Add `one_shot_bundle_exclude_source_paths` |
| `ansible/roles/one_shot_bundle/tasks/monitor.yml` | Add `when` to "Add directory file entries to source manifest" loop |
| `ansible/roles/one_shot_bundle/tasks/output_bundle.yml` | Add `when` to "Ensure destination directories exist" loop and "Copy non-template files" loop |

## Currently Excluded Paths

```yaml
one_shot_bundle_exclude_source_paths:
  - "{{ repo_root }}/ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full"
  - "{{ repo_root }}/ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full_csvmethod"
  - "{{ repo_root }}/ansible/roles/docker/files/apache/webroot/images/jam"
  - "{{ repo_root }}/ansible/roles/docker/files/apache/webroot/images/stormpigsItunesVideo.jpg"
  - "{{ repo_root }}/ansible/roles/docker/files/apache/webroot/images/stormpigsPodcastSplash.png"
```

**Reason for each exclusion:**

- **`mysql/externalConfigs/prepped_csvs/full`** — Full Stormpigs dataset CSVs; not needed
  in the OSB. `prepped_csvs/sample` is intentionally kept — it is bind-mounted to
  `/var/lib/mysql-files/` in docker-compose.yml and seeds the sample database on first
  MySQL init.
- **`mysql/externalConfigs/prepped_csvs/full_csvmethod`** — Same as above; alternate
  import-method variant of the full dataset.
- **`apache/webroot/images/jam`** — Stormpigs/defaultcodebase-specific image assets;
  not part of the GigHive flavor.
- **`apache/webroot/images/stormpigsItunesVideo.jpg`** — Same as above.
- **`apache/webroot/images/stormpigsPodcastSplash.png`** — Same as above.

## Adding New Exclusions

Add the absolute source path to `one_shot_bundle_exclude_source_paths` in all three
group_vars files. Directory paths exclude the directory and all files within it. File paths
exclude only that file.

```yaml
one_shot_bundle_exclude_source_paths:
  - "{{ repo_root }}/ansible/roles/docker/files/path/to/exclude"
```

No changes to `monitor.yml` or `output_bundle.yml` are needed when adding new paths — the
`when` condition reads the variable at runtime.

## Validation

After a bundle rebuild, verify the excluded paths are absent from the bundle output:

```bash
# Should return no output (paths not present in bundle)
ls /tmp/gighive-one-shot-bundle/mysql/externalConfigs/prepped_csvs 2>&1
ls /tmp/gighive-one-shot-bundle/apache/webroot/images/jam 2>&1
ls /tmp/gighive-one-shot-bundle/apache/webroot/images/stormpigsItunesVideo.jpg 2>&1
ls /tmp/gighive-one-shot-bundle/apache/webroot/images/stormpigsPodcastSplash.png 2>&1
```

The bundle monitor validation step should also report zero `extra_in_existing_bundle`
entries for the excluded paths.
