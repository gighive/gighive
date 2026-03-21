---
title: Versioning
layout: default
---

# GigHive Versioning

## Overview

This document explains how versioning currently works in the GigHive repo.

At present, GigHive uses a repo-root `VERSION` file as a lightweight build/version marker.

## Current Version Source

The canonical version marker in the repo is:

- `/VERSION`

The value stored in this file is currently derived from the latest Git commit SHA in shortened form.

Example:

```text
VERSION
068725e
```

This corresponds to the short SHA of the current `HEAD` commit.

## How `VERSION` Is Generated

The repo contains a Git hook at:

- `.git/hooks/post-commit`

Its contents are:

```bash
git rev-parse --short HEAD > VERSION
```

That means after a commit is created locally, the `VERSION` file is updated to the short SHA of the latest commit.

## Git Ignore Behavior

The repo `.gitignore` includes:

```gitignore
/VERSION
```

So the generated `VERSION` file is intentionally not tracked in Git.

## What This Means Operationally

The current GigHive version marker is not a semantic release version such as `1.0.1`.

Instead, it is a lightweight repo-state identifier derived from the current Git commit.

This makes it useful for:

- identifying the exact code revision associated with a build or playbook run
- correlating deployments with a specific commit
- providing a simple provenance marker during installation and operations

## How Ansible Uses `VERSION`

GigHive documentation already treats `VERSION` as an expected repo marker.

In particular, `docs/refactored_gighive_home_and_scripts_dir.md` documents that the base role validates and reads `{{ gighive_home }}/VERSION` for visibility and verification purposes.

The documented behavior includes:

- verifying `{{ gighive_home }}/VERSION` exists
- reading `VERSION`
- printing the resolved `gighive_home` and `VERSION` for log visibility

This establishes `VERSION` as part of the expected operational repo layout.

## Relationship To Release Notes And Changelog Entries

The repo also contains changelog and release-note style entries that may mention human-readable versions such as `1.0.1`.

Those are not the same thing as the current repo-root `VERSION` file.

So there are currently two different version concepts that may appear in the project:

- **Repo/build marker**
  - short Git SHA stored in `VERSION`

- **Human release version**
  - semantic-style version references that may appear in release notes or changelog text

## Guidance For Telemetry

For installation telemetry, the leading candidate source for `app_version` is the repo-root `VERSION` file, because it is already part of the repo workflow and is directly tied to the installed code revision.

However, this choice should still be made explicitly, because a short Git SHA and a human release version answer slightly different questions.

- **Use `VERSION` if the goal is build provenance**
- **Use a semantic release version if the goal is release-level reporting**

## Current Decision For `installation_tracking`

For the initial implementation of the `installation_tracking` role, GigHive should use the repo-root `VERSION` file as the source of telemetry `app_version`.

This is the right initial choice because:

- `VERSION` already exists and is part of the current repo workflow
- it is directly tied to the installed code revision
- it is mechanically reliable and available during installation
- it avoids introducing a new version-management process just to ship telemetry

At this time, GigHive should not block installation telemetry on a separate semantic-versioning scheme.

Although semantic or human-readable versions such as `1.0.1` may be useful later, they currently do not have the same clearly defined, machine-readable source in the install flow that `VERSION` already provides.

So for now:

- telemetry `app_version` should be the contents of `VERSION`

Semantic versioning remains a valid future improvement, but it should be treated as a separate release-management decision rather than a prerequisite for installation telemetry.

## Summary

GigHive currently versions the installed repo state using:

- a generated root `VERSION` file
- populated from `git rev-parse --short HEAD`
- updated by `.git/hooks/post-commit`
- ignored by Git via `/VERSION`

This should be treated as the current repo/build version marker unless and until the project adopts a different explicit versioning scheme.
