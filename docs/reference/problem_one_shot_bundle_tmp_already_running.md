---
description: RCA for one-shot bundle tmp output delete failure and why caching prior steps would not help
---

# Problem: One-Shot Bundle Fails Removing Existing `/tmp/gighive-one-shot-bundle`

## Summary

A one-shot bundle playbook run failed at the start of `output_bundle.yml` while trying to delete the existing fresh output directory at `/tmp/gighive-one-shot-bundle`.

The failure was:

```text
rmtree failed: [Errno 13] Permission denied: 'backups'
```

The question was whether earlier expensive steps in the playbook had already built reusable artifacts that could be cached in a separate `/tmp` staging area so they would not need to be redone after this failure.

After inspecting the role and the playbook log, the answer was effectively **no** for this failure mode. The expensive work completed before the failure was mostly **read-only monitoring, manifest generation, and diff analysis**, not bundle output construction.

## Impact

- The playbook consumed roughly 4 minutes before failing.
- Re-running after this failure repeats the expensive monitoring and comparison work.
- No meaningful fresh bundle output had been produced yet, so there was little or nothing to preserve from that failed run.

## Symptoms

Observed failure during playbook execution:

```text
TASK [one_shot_bundle : Remove existing fresh one-shot bundle output directory (controller)]
fatal: [gighive_vm -> localhost]: FAILED! => {"changed": false, "msg": "rmtree failed: [Errno 13] Permission denied: 'backups'"}
```

Timing summary showed the most expensive tasks before failure were:

- `Capture ls output for changed assembled bundle files`
- `Capture ls output for changed source files in assembled bundle comparison`
- `Add directory file entries to source manifest mapping bundle_path->mtime+size`
- `Add assembled bundle file entries to manifest mapping bundle_path->mtime+size`
- `Compute changed path details between source and assembled bundle`
- `Combine ls output for changed source-to-bundle files`

## Relevant Role Structure

The role already distinguishes between two bundle locations:

- `one_shot_bundle_bundle_dir`
  - In `gighive2`, this is:
  - `{{ repo_root }}/ansible/.tmp/one_shot_bundle/gighive-one-shot-bundle`
  - Used as the existing assembled/reference bundle for source-to-bundle comparison.

- `/tmp/gighive-one-shot-bundle`
  - Used as the fresh output directory created by `output_bundle.yml`.
  - This path is deleted first on each run.

This distinction matters because the expensive work before failure was centered on comparing source inputs to the existing assembled bundle, not on generating the fresh `/tmp` output tree.

## Root Cause

### Direct cause

The run failed immediately when Ansible attempted to remove `/tmp/gighive-one-shot-bundle` and encountered an undeletable `backups` entry.

### Why caching earlier work would not help much

The failure happened at the **first destructive/build step** of `output_bundle.yml`:

1. set `_one_shot_bundle_output_dir`
2. remove existing `/tmp/gighive-one-shot-bundle`  ← failure occurred here
3. create fresh output directory
4. create destination directories
5. render templates into fresh output
6. copy non-template files
7. preserve mtimes
8. normalize permissions
9. archive output

Because the playbook failed at step 2, the fresh output tree was not meaningfully rebuilt yet.

The expensive work that had already run belonged mainly to `monitor.yml`, which performs:

- source path stat/find operations
- source manifest generation
- assembled bundle manifest generation
- source-vs-bundle diff calculation
- `ls` capture for changed paths

Those tasks compute data in Ansible facts and debug output for the current run. They do not produce a substantial pre-output artifact that could be reused to avoid redoing the later output work.

## What Was Built Before Failure

Only minor write activity occurred before the failure point:

- `Write quickstart VERSION into one-shot bundle source`
  - Updates `ansible/roles/docker/files/one_shot_bundle/VERSION`
  - Cheap and not worth caching separately

The rest of the significant elapsed time was spent on analysis and comparison, not output assembly.

## Conclusion

For this specific failure mode, introducing a separate cache directory for “previous steps” is **not worth pursuing** as a fix for the wasted runtime.

Reason:

- The costly pre-failure work was primarily monitoring/diff logic.
- The fresh bundle output had not yet been built.
- There was little or no reusable output to preserve from the failed run.

## Operational Workaround Chosen

For now, the chosen manual workflow is:

- stop the quickstart containers
- delete `/tmp/gighive-one-shot-bundle`
- rerun the one-shot bundle playbook

This is the simplest short-term path until or unless the role is changed to improve either:

- delete robustness / ownership diagnostics for `/tmp/gighive-one-shot-bundle`, or
- the cost of the monitor/diff phase when a rebuild is all that is needed

## Better Future Optimization Targets

If future optimization is desired, the higher-value targets are:

- make the `/tmp/gighive-one-shot-bundle` cleanup step more robust
- add clearer ownership/perms diagnostics when delete fails
- optionally provide a way to skip or reduce the expensive `monitor.yml` diff/reporting phase when only rebuilding/archive output is desired

These would address the actual sources of friction better than introducing a cache for pre-failure output that does not really exist.
