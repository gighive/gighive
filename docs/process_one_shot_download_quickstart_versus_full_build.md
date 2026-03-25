---
description: Quickstart tarball install vs full normal build
---

# Quickstart vs Full Build

## Rationale (why Quickstart/one-shot bundle exists)

Quickstart exists to let end users install GigHive **without running (or understanding) Ansible**.

In the full workflow, Ansible is the tool that assembles configuration, templates, and deployment state. In Quickstart, maintainers publish a pre-built `gighive-one-shot-bundle.tgz` so users can install by extracting the tarball and running `./install.sh`.

GigHive supports two distinct installation paths:

1. **Quickstart (one-shot bundle)**
   - Download `gighive-one-shot-bundle.tgz` (served from staging) and run `./install.sh`.
   - Primary reference: `docs/process_download_directory_for_tgz.md`

2. **Full normal build / deployment workflow**
   - Clone the repo and follow the full build + configuration + deployment process.
   - Primary reference: `docs/README.md`

This document explains the benefits and drawbacks of each approach, and when each is the right choice.

## Benefits of Quickstart

- **Fast time-to-first-running-instance**
  - Best for demos, labs, and “fresh host” deployments.

- **Low prerequisite knowledge**
  - The installer can succeed without requiring the user to understand Ansible inventories, Docker build contexts, or the repo layout.

- **Reproducible “golden bundle”**
  - The tarball is a known snapshot (useful for consistent lab validation).

- **Decoupled distribution**
  - Lab hosts can download from staging over HTTP; the bundle doesn’t need to exist in every git clone.

## Drawbacks / risks of Quickstart

- **Trust and supply chain**
  - Users are running whatever is in the tarball. If the artifact host is compromised, the installer is a high-leverage delivery mechanism.
  - Recommended mitigations:
    - Publish a SHA256 file produced by maintainers and verify it.
    - Prefer a signed checksum (GPG) if/when you want stronger provenance.

- **Less flexible for customization**
  - Quickstart is designed to provide sane defaults. Deep customization typically belongs in the full normal workflow.

- **Harder source-level debugging**
  - When things break, debugging from a bundle can be less direct than debugging from a working tree + Ansible templates.

- **Upgrade and lifecycle management is extra work**
  - You need an explicit process for:
    - Versioning bundles
    - Rolling out updates
    - Supporting in-place upgrades vs fresh reinstall

- **Risk of drift from production**
  - If production is managed via the full workflow and Quickstart evolves separately, the two can diverge.

## Benefits of the full normal build

- **Most configurable and auditable**
  - You control all variables, templates, secrets, host paths, mounts, and security posture.

- **Idempotent infrastructure management**
  - Ansible is a strong fit for long-lived systems where you want repeatable state convergence.

- **Best debugging and maintenance posture**
  - Failures can be traced back to the repo, fixed in source, tested, then redeployed.

## Drawbacks of the full normal build

- **Higher learning curve**
  - More moving parts: inventories, group vars, roles, secrets, Docker, TLS/CRS considerations.

- **Slower to bootstrap**
  - Not ideal when your goal is “get a working instance running quickly on a fresh machine”.

# Recommended decision guide

Choose **Quickstart** when:

- You want the fastest path to a working instance.
- You’re deploying to a lab/test host or doing a demo.
- The installer user is not expected to be a GigHive infra/deployment expert.

Choose the **full normal build** when:

- You’re managing a long-lived environment.
- You need customization, traceability, or fleet-level operational consistency.
- You expect to perform upgrades/maintenance using infrastructure-as-code.

# Notes

- Quickstart and the full normal build are not mutually exclusive.
  - A common model is:
    - Quickstart for rapid evaluation
    - Full normal build for long-term adoption

# What the Quickstart is optimized for

The Quickstart exists to make GigHive easy to stand up on a **fresh Ubuntu host** with minimal prerequisite knowledge.

It treats the one-shot bundle as an **artifact**:

- It is built once (by maintainers), then distributed.
- Installers consume it without needing to understand or run the full build pipeline.

# Why Quickstart uses a host bind mount for `/downloads`

## Why this should not be implemented via Apache overlay

GigHive’s Apache image build uses an overlay mechanism:

- The canonical app code is copied into `${WEB_ROOT}`.
- Then `overlays/gighive/` is copied into the container image and applied at build time when `APP_FLAVOR=gighive`.

This means any file placed under:

- `ansible/roles/docker/files/apache/overlays/gighive/...`

will be baked into the Apache image and therefore be deployed to **every** host that builds/pulls that image flavor.

Because the installer tarball is intended to be hosted **only** on the staging host, placing it in the overlay directory is the wrong approach.

## Why a host bind mount is the correct solution

A bind mount lets us:

- Keep the tarball as **host runtime content** (not image content).
- Copy the tarball to **only one host** using a host guard.
- Expose it in Apache at runtime by mounting a host directory into the container at a known URL path.
- Update the tarball without rebuilding the Apache image.
