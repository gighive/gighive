---
title: Installation Telemetry Client Implementation
layout: default
---

# GigHive Telemetry Client Implementation

## Overview

This document describes the client-side implementation model for GigHive installation telemetry.

The telemetry client is the installer-side logic that builds telemetry payloads and sends them to the public telemetry endpoint.

This document covers:

- the shared client telemetry contract
- the full build client implementation
- the quickstart client implementation
- why the full build and quickstart flows need separate client implementations
- the behavior that must remain identical across both installation channels

The server-side receiver, storage, hosting, and deployment model are documented in `docs/TELEMETRY_SERVER_IMPLEMENTATION.md`.

The telemetry event model, privacy commitments, and disclosure expectations are documented in `docs/TELEMETRY.md`.

## Shared Client Contract

Both installation channels must send the same telemetry payload shape to the same endpoint.

### Public endpoint

- `https://telemetry.gighive.app`

### Events

- `install_attempt`
- `install_success`

### Client-sent fields

- `event_name`
- `app_version`
- `install_channel`
- `install_method`
- `app_flavor`
- `timestamp`
- `install_id`

### Server-derived field

- `country_code`

The client does not send `country_code`.

## Shared Client Behavior

Although the full build and quickstart implementations are separate, they must follow the same behavior rules.

Both client implementations must:

- use the same public endpoint
- send the same payload field names
- honor `GIGHIVE_ENABLE_INSTALLATION_TRACKING`
- honor debug mode
- generate one random `install_id` per install run
- preserve repeated same-`install_id` events
- treat telemetry as best-effort only
- never fail installation if telemetry sending fails

## Shared Runtime Configuration

The shared runtime configuration surface for installation telemetry is:

- `apache/externalConfigs/.env`

This is the common config model across both installation channels, even though each channel populates it differently.

Configuration values outside of `.env` should follow the existing Ansible convention and live under:

- `ansible/inventories/group_vars/gighive*`

### Common configuration values

```dotenv
GIGHIVE_ENABLE_INSTALLATION_TRACKING=true
GIGHIVE_INSTALLATION_TRACKING_ENDPOINT=https://telemetry.gighive.app
GIGHIVE_ENABLE_INSTALLATION_TRACKING_DEBUG=false
GIGHIVE_INSTALLATION_TRACKING_TIMEOUT_SECONDS=3
GIGHIVE_INSTALL_CHANNEL=full
```

## Why Two Separate Client Implementations Are Required

GigHive should keep one telemetry protocol, but it should not try to force both installation channels through one identical installer-side implementation.

That is because the two install paths use different orchestration systems and different execution environments.

### Full build path

The full build path is driven by Ansible.

Telemetry in this path should therefore be emitted by Ansible-managed tasks or by helper scripts called from those tasks.

### Quickstart path

The quickstart path is driven by the Docker Compose quickstart bundle installer, typically `gighive-one-shot-bundle/install.sh`.

Telemetry in this path should therefore be emitted by installer shell logic or by a helper script called from the quickstart installer.

### Why one implementation cannot simply be reused unchanged

- the two install paths do not share the same entrypoint
- they do not use the same orchestration tool
- they set up configuration differently
- they have different success boundaries and failure paths
- they surface errors differently

Because of that, the implementation should be separate by channel, while the telemetry contract remains identical.

## Full Build Client Implementation

The full build is the recommended starting point for client-side telemetry implementation because it follows an existing, familiar Ansible pattern.

## Execution model

The full build telemetry client should be implemented as part of the Ansible-driven install flow.

The recommended structure is:

- a dedicated `installation_tracking` Ansible role
- integration through `ansible/playbooks/site.yml`
- helper logic inside the role where needed
- one integration point near install start
- one integration point at final successful completion

### Responsibilities

The full build client is responsible for:

- defining the full-build telemetry variables in `ansible/inventories/group_vars/gighive*`
- starting initial testing in `ansible/inventories/group_vars/gighive2/gighive2.yml`
- rendering telemetry-related values into `apache/externalConfigs/.env`
- generating one `install_id` per Ansible-driven install run
- emitting `install_attempt` near the beginning of the install flow
- emitting `install_success` only after the full build completes successfully
- ensuring telemetry failures do not fail the Ansible run

### Implementation readiness drivers

The full-build `installation_tracking` plan is ready to implement because the key design choices have already been resolved.

- the telemetry inputs are defined: `app_version` from the controller-side root `VERSION` file, `install_method` as `virtualbox` or `azure`, and default `app_flavor=gighive`
- the Ansible integration pattern is defined: `install_attempt` in `pre_tasks` and `install_success` in `post_tasks` using `ansible.builtin.include_role`
- the configuration path is defined: extend the existing Docker role Apache `.env` template path rather than create a competing writer
- the runtime behavior is defined: telemetry is best-effort, non-fatal, debug mode prints the payload instead of sending it, and timeout behavior remains short
- the success interpretation is defined: if an `install_id` receives `install_attempt` but never receives `install_success`, that is a meaningful signal that the install did not complete successfully

### Full build `installation_tracking` role steps

1. Create the `installation_tracking` role skeleton.
2. Define the non-`.env` telemetry variables in `ansible/inventories/group_vars/gighive2/gighive2.yml`.
3. Set the resolved role inputs: `app_version` from the controller-side root `VERSION` file, `install_method` as `virtualbox` or `azure`, default `app_flavor=gighive`, `install_channel=full`, endpoint, enable flag, debug flag, and timeout.
4. Extend the existing Docker role Apache `.env` template path so telemetry keys are rendered into `apache/externalConfigs/.env` for runtime visibility, while keeping event sending driven directly by Ansible role variables.
5. Add logic to generate one `install_id` per Ansible-driven install run.
6. Add the sender implementation that builds the JSON payload and posts it to `https://telemetry.gighive.app`.
7. Implement debug behavior with Ansible `debug` output that prints the payload and skips sending when debug mode is enabled.
8. Add the `install_attempt` integration in the main full-build play `pre_tasks` using `ansible.builtin.include_role` so the role can run before the main role list.
9. Add the `install_success` integration in the main full-build play `post_tasks` using `ansible.builtin.include_role` so the role can run after the main role list completes successfully.
10. Make telemetry sending non-fatal so failures do not fail the install and keep default logging concise.
11. Integrate the role into `ansible/playbooks/site.yml` with explicit tagging so it remains part of the standard build but can be skipped explicitly.
12. Test enabled mode, debug mode, opt-out, timeout behavior, and failure isolation.

### Implementation notes

- telemetry logic should remain isolated in the `installation_tracking` role
- the role should be integrated into `ansible/playbooks/site.yml` via `pre_tasks` and `post_tasks` using `ansible.builtin.include_role`
- non-`.env` configuration should follow the existing `group_vars` convention
- debug mode should print the payload instead of sending it
- timeout behavior should be short and best-effort

## Quickstart Client Implementation

## Execution model

The quickstart telemetry client should be implemented in the Docker Compose quickstart bundle installer flow.

The recommended structure is:

- orchestration in `gighive-one-shot-bundle/install.sh`
- telemetry payload assembly and sending in installer shell logic or a small helper script
- one integration point near quickstart start
- one integration point at final quickstart success

### Responsibilities

The quickstart client is responsible for:

- defining or resolving the quickstart telemetry values
- writing telemetry-related values into `gighive-one-shot-bundle/apache/externalConfigs/.env`
- generating one `install_id` per quickstart install run
- emitting `install_attempt` near the beginning of the quickstart flow
- emitting `install_success` only after the quickstart flow completes successfully
- ensuring telemetry failures do not fail the quickstart install

### Recommended quickstart flow

1. Start the quickstart installer.
2. Determine whether telemetry is enabled.
3. Resolve `app_version`, `install_method`, `app_flavor`, and `install_channel=quickstart`.
4. Generate one random `install_id` for the quickstart run.
5. Write telemetry configuration into `gighive-one-shot-bundle/apache/externalConfigs/.env`.
6. Emit `install_attempt`.
7. Continue the normal Docker Compose quickstart installation flow.
8. After final success criteria are satisfied, emit `install_success`.
9. Ignore telemetry send failures for install success purposes.

### Recommended implementation approach

The cleanest quickstart implementation is:

- keep install orchestration in `gighive-one-shot-bundle/install.sh`
- place telemetry send logic in a small helper script or shell function
- call that telemetry logic twice
  - once for `install_attempt`
  - once for `install_success`

This keeps the quickstart installer readable while preserving a clear separation between installation orchestration and telemetry sending.

## Payload Assembly

Regardless of channel, the telemetry client must build the same payload shape.

### Example client payload

```json
{
  "event_name": "install_attempt",
  "app_version": "1.2.0",
  "install_channel": "quickstart",
  "install_method": "virtualbox",
  "app_flavor": "gighive",
  "timestamp": "2026-03-21T12:31:00Z",
  "install_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

## Debug Mode

If debug mode is enabled:

- print the payload locally
- do not send the request

Example behavior:

```text
[telemetry] install tracking enabled
[telemetry] event would be sent:
{
  "event_name": "install_attempt",
  "app_version": "1.2.0",
  "install_channel": "quickstart",
  "install_method": "virtualbox",
  "app_flavor": "gighive",
  "timestamp": "2026-03-21T12:31:00Z",
  "install_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

## Failure Handling

Telemetry must remain best-effort only.

If the telemetry endpoint is unavailable or sending fails:

- installation must continue
- telemetry must not cause install failure
- send failures should not change the install success result

## Validation Checklist

### Full build

- verify the `installation_tracking` role runs in the expected place
- verify `install_attempt` is sent once per full-build run
- verify `install_success` is sent only after final success
- verify opt-out works
- verify debug mode prints instead of sends
- verify telemetry failure does not fail the Ansible install

### Quickstart

- verify the quickstart installer writes telemetry config correctly
- verify `install_attempt` is sent once per quickstart run
- verify `install_success` is sent only after final success
- verify opt-out works
- verify debug mode prints instead of sends
- verify telemetry failure does not fail the quickstart install

## Status

- approved as the client-side implementation model for GigHive installation telemetry
- intended to keep full build and quickstart implementations separate while preserving one telemetry contract
