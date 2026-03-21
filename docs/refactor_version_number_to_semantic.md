---
title: Refactor Version Number To Semantic
layout: default
---

# Refactor Version Number To Semantic

## Overview

GigHive currently uses the repo-root `VERSION` file as its operational build/version marker.

That file is generated from the latest Git commit short SHA via:

```bash
git rev-parse --short HEAD > VERSION
```

For the initial implementation of installation telemetry, this is sufficient and should be used as telemetry `app_version`.

However, a future refactor may introduce a more human-readable semantic version such as `1.0.1` for release-level reporting and communication.

## Why This Is Deferred

Semantic versioning is useful, but it is not currently required to implement installation telemetry.

At this time:

- `VERSION` already exists and is reliable
- `VERSION` is directly tied to the installed code revision
- semantic versioning does not yet have a clearly defined, machine-readable source in the install flow
- introducing semantic versioning now would add release-process decisions beyond the scope of installation telemetry

So this should remain a future follow-up item rather than a prerequisite.

## Current Decision

For now:

- installation telemetry `app_version` should use the contents of `VERSION`

## Future Goals

A future semantic-versioning refactor should answer the following:

- where the canonical semantic version is stored
- who updates it and when
- how it is kept in sync with Git commits and releases
- whether the semantic version replaces `VERSION` or exists alongside it
- whether telemetry should report semantic version, Git-based version, or both
- how release notes and changelog entries relate to the canonical semantic version source

## Candidate Implementation Directions

Possible future approaches include:

- **Add a tracked semantic version file**
  - for example a repo-root file dedicated to semantic versioning

- **Keep both concepts**
  - semantic version for release communication
  - `VERSION` short SHA for build provenance

- **Expose both in telemetry in a future schema revision**
  - if release reporting and provenance both become important enough to justify separate fields

## Telemetry Considerations

If GigHive adopts semantic versioning later, revisit:

- `docs/TELEMETRY.md`
- `docs/TELEMETRY_CLIENT.md`
- `docs/VERSIONING.md`
- the `installation_tracking` role implementation
- any quickstart telemetry client implementation

This will ensure the telemetry contract remains intentional and consistent.

## Suggested Future Deliverables

- define a canonical semantic version source
- document the release/version update workflow
- decide whether `VERSION` remains SHA-based
- update telemetry docs if `app_version` semantics change
- update installer/client implementation if needed

## Status

- deferred follow-up item
- not required for initial installation telemetry implementation
