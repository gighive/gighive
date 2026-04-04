---
title: Installation Telemetry Design
layout: default
---

# GigHive Installation Telemetry

## Overview

This document defines a minimal, transparent installation telemetry design for GigHive.

The scope is intentionally narrow. The goal is not broad usage analytics. The goal is to learn:

- whether installations are being started
- whether installations are completing successfully
- whether users appear to be retrying installation
- which install methods and countries are represented in aggregate

This design follows the general transparency patterns used by projects such as Homebrew and Next.js:

- explain telemetry clearly
- collect only a small set of fields
- provide an easy opt-out
- allow users to inspect what would be sent
- never let telemetry failures break installation

## Goals

- Track installation attempts
- Track installation successes
- Estimate install completion rate
- Identify repeated install attempts that may indicate install issues
- Understand install method distribution
- Understand aggregate country distribution
- Maintain user privacy and transparency

## Non-Goals

- Track page views or feature usage
- Track user identity
- Track media, database, or file contents
- Track precise location
- Build user profiles across time

## Industry Examples

Many open-source projects collect limited telemetry, but the important patterns are more relevant than the specific backend:

- **Homebrew**
  - documents telemetry clearly
  - provides opt-out
  - allows users to inspect what would be sent
- **Next.js**
  - documents exactly what is collected
  - provides explicit disable commands and env-var control
  - emphasizes anonymous, limited collection

### Best Practices We Are Following

1. **Always provide opt-out**
2. **Be transparent**
3. **Collect minimally**
4. **Avoid personal data**
5. **Use HTTPS only**
6. **Do not fail install if telemetry fails**

## Approved Architecture

### Tiny HTTP Endpoint

GigHive will use a small HTTP endpoint as the telemetry receiver.

The endpoint will:

- accept JSON POST requests
- validate a very small schema
- infer `country_code` server-side
- store telemetry events for later analysis
- return success quickly
- avoid exposing any write token in installer-side code

### Why a Tiny Endpoint

- simpler than a full analytics platform
- more appropriate than GA4 for install-state events
- easy to document and reason about
- keeps the payload intentionally minimal

## Event Model

GigHive will send two telemetry events.

### `install_attempt`

Sent near the start of installation.

Purpose:

- count installation starts
- identify repeated attempts for the same installation run
- detect installs that never appear to complete

### `install_success`

Sent only after installation completes successfully.

Purpose:

- count successful installs
- compare starts versus successful finishes
- estimate completion rate by version, install channel, and method

## Event Fields

### Client-Sent Fields

Both events will include:

- `event_name`
  - `install_attempt` or `install_success`
- `app_version`
- `install_channel`
  - `full` or `quickstart`
- `install_method`
- `app_flavor`
- `timestamp`
- `install_id`

### Server-Derived Field

The endpoint will add:

- `country_code`

## Why `install_id` Exists

GigHive generates a random `install_id` for each installation run.

This identifier exists so the start and finish of the same installation can be related, and so repeated attempts from the same installation run can be recognized.

That helps answer questions like:

- did an install start and later finish successfully?
- are some installs being retried multiple times?
- do repeated retries cluster by version, install channel, method, or country?

The `install_id` is random and is not tied to a person, account, or personal identity.

## Install Channel

GigHive should record the installation channel as a separate field rather than mixing it into the version.

### Allowed values

- `full`
- `quickstart`

### Why this is separate from version

- version should identify the release being installed
- install channel should identify how GigHive was installed
- keeping them separate makes analysis clearer

Examples:

- is `quickstart` completing more reliably than `full`?
- are retries clustered in one install channel?
- does a specific release behave differently across install channels?

### Important Behavior

Repeated events with the same `install_id` should be preserved, not automatically discarded.

That is intentional. Repeated attempts may indicate:

- installer retries
- interrupted runs
- uncertainty about completion
- real install problems

## Country Handling

GigHive may record a coarse country code such as `US` or `CA`.

This should be inferred server-side from the incoming request rather than sent by the installer.

### Why infer country server-side

- avoids adding location logic to the installer
- keeps the client payload smaller
- avoids storing precise location data
- gives useful aggregate geographic insight with lower privacy impact

### What to store

- `country_code`

### What not to store

- raw IP address as telemetry data
- precise location
- city
- hostname

## Example Payloads

### `install_attempt`

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

### `install_success`

```json
{
  "event_name": "install_success",
  "app_version": "1.2.0",
  "install_channel": "quickstart",
  "install_method": "virtualbox",
  "app_flavor": "gighive",
  "timestamp": "2026-03-21T12:42:00Z",
  "install_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

### Example Stored Event

```json
{
  "event_name": "install_success",
  "app_version": "1.2.0",
  "install_channel": "quickstart",
  "install_method": "virtualbox",
  "app_flavor": "gighive",
  "timestamp": "2026-03-21T12:42:00Z",
  "install_id": "550e8400-e29b-41d4-a716-446655440000",
  "country_code": "US"
}
```

## Interpreting the Data

Examples:

- **One `install_attempt` followed by one `install_success` for the same `install_id`**
  - likely a clean install

- **One or more `install_attempt` events with no matching `install_success`**
  - possible failed or abandoned install

- **Multiple `install_attempt` events followed by one `install_success`**
  - likely retries before eventual success

- **Multiple `install_attempt` events and multiple `install_success` events with the same `install_id`**
  - likely reruns or repeated installer execution
  - still useful as a signal and should be retained

## Configuration

### Example Variables

```yaml
# Installation telemetry
GIGHIVE_ENABLE_INSTALLATION_TRACKING: true

# Tiny endpoint URL
GIGHIVE_INSTALLATION_TRACKING_ENDPOINT: "https://telemetry.example.com/api/install-telemetry"

# Print payload locally without sending it
GIGHIVE_ENABLE_INSTALLATION_TRACKING_DEBUG: false

# Installation channel
GIGHIVE_INSTALL_CHANNEL: "full"

# Optional request timeout
GIGHIVE_INSTALLATION_TRACKING_TIMEOUT_SECONDS: 3
```

### Shared Runtime Location

The shared runtime configuration location for installation telemetry should be the Apache env file:

- `apache/externalConfigs/.env`

This works for both installation paths:

- **Full install**
  - define values in `ansible/inventories/group_vars/gighive.yml`
  - render them into `apache/externalConfigs/.env`

- **Quickstart**
  - write values directly into `gighive-one-shot-bundle/apache/externalConfigs/.env`
  - typically from `gighive-one-shot-bundle/install.sh`

## Why Full Install and Quickstart Need Separate Client Implementations

GigHive should keep one telemetry data contract, but it still needs two separate client-side implementations because the two installation paths execute in different environments and are controlled by different tooling.

### Full install

The full install path is driven by Ansible.

That means telemetry for the full install should be emitted from Ansible-managed tasks or helper scripts invoked by those tasks.

The full install path is responsible for:

- generating one `install_id` for the Ansible-driven install run
- rendering telemetry-related values into `apache/externalConfigs/.env`
- emitting `install_attempt` near the beginning of the install flow
- emitting `install_success` only after the full install completes successfully

### Quickstart

The quickstart path is driven by the Docker Compose quickstart bundle installer, typically `gighive-one-shot-bundle/install.sh`.

That means telemetry for quickstart should be emitted from the quickstart installer flow or a helper it calls, not from the full-install Ansible path.

The quickstart path is responsible for:

- generating one `install_id` for the quickstart install run
- writing telemetry-related values into `gighive-one-shot-bundle/apache/externalConfigs/.env`
- emitting `install_attempt` near the beginning of the quickstart flow
- emitting `install_success` only after the quickstart flow completes successfully

### Why this split is necessary

- the full install and quickstart paths do not share the same entrypoint
- they do not use the same orchestration tool
- they create runtime configuration in different ways
- they have different success boundaries and failure paths

Because of that, one implementation cannot simply be dropped into both flows without adding coupling or hidden assumptions.

### What must stay identical across both implementations

Although the client implementations are separate, they must emit the same payload shape and follow the same behavior rules.

Both implementations must:

- use the same endpoint
- honor `GIGHIVE_ENABLE_INSTALLATION_TRACKING`
- honor debug mode
- send the same fields
- preserve repeated same-`install_id` events
- treat telemetry as best-effort only
- never fail the installation if telemetry sending fails

## Opt-Out Mechanism

Users can disable tracking by:

1. **Setting variable:**
   in `apache/externalConfigs/.env`:
   ```dotenv
   GIGHIVE_ENABLE_INSTALLATION_TRACKING=false
   ```

2. **For full install, setting the upstream Ansible value:**
   in `ansible/inventories/group_vars/gighive.yml` so it renders into the Apache `.env`:
   ```yaml
   gighive_enable_installation_tracking: false
   ```

3. **Skipping role or tag:**
   ```bash
   ansible-playbook ... --skip-tags installation_tracking
   ```

4. **Potential future environment variable:**
   ```bash
   export GIGHIVE_NO_TELEMETRY=1
   ```

## Debug Mode

Like other transparent telemetry systems, GigHive should support a mode that prints the exact client payload locally without sending it.

In debug mode, the client should not send the request, so the telemetry receiver does not receive the event and no telemetry database row is created.

Example output:

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
[telemetry] country_code is inferred server-side and is not included in the client payload
```

## Privacy & Legal Considerations

### What We Collect

- `event_name`
- `app_version`
- `install_channel`
- `install_method`
- `app_flavor`
- `timestamp`
- `install_id`
- `country_code` inferred server-side

### What We Do Not Collect

- names
- email addresses
- usernames
- passwords or credentials
- file contents
- media metadata
- database contents
- precise location
- hostname
- raw IP address stored as telemetry data

### Disclosure Expectations

Because even minimal telemetry should be obvious to users, this design should be disclosed:

- in `README.md`
- in installation documentation
- in install output near the point telemetry is mentioned
- alongside opt-out instructions

## Documentation & Transparency

### README.md Addition

```markdown
## Telemetry

GigHive can send two small anonymous installation telemetry events:

- `install_attempt` at the start of installation
- `install_success` at the end of installation

The goal is to help us understand whether installs are completing successfully and whether users appear to be retrying installation.

**What we collect:**
- Event name
- GigHive version
- Install channel (`full` or `quickstart`)
- Install method
- App flavor
- Timestamp
- Random installation ID
- Country code inferred server-side

**Why the installation ID exists:**
- It lets us relate the start and finish of the same install run
- It helps us see when users may be retrying installation multiple times
- It is random and is not tied to a person or account

**What we DON'T collect:**
- Personal information
- File contents or database contents
- Precise location
- Hostname
- Raw IP address stored as telemetry data

**Opt-out:**
Set `GIGHIVE_ENABLE_INSTALLATION_TRACKING=false` in `apache/externalConfigs/.env`
```

### Installation Output

```
TASK [installation_tracking : Report install_attempt telemetry] ****************
ok: [gighive] => {
    "msg": "GigHive telemetry can send anonymous install_attempt and install_success events. Set GIGHIVE_ENABLE_INSTALLATION_TRACKING=false in apache/externalConfigs/.env to opt out."
}

TASK [installation_tracking : Report install_success telemetry] ****************
ok: [gighive] => {
    "msg": "Installation completed successfully. Telemetry is best-effort only and does not affect install success."
}
```

## Alternative Approaches

### 1. GA4 Measurement Protocol

Possible, but less aligned with this simple install-state use case than a small dedicated endpoint.

### 2. Third-Party Services

- **PostHog** (open-source analytics)
- **Plausible** (privacy-focused)
- **Umami** (simple, self-hosted)
- **Matomo** (self-hosted Google Analytics alternative)

## Metrics & Analytics

### Key Metrics to Track

1. **Install Attempts**
   - Overall count
   - By version, install channel, and method

2. **Install Successes**
   - Overall count
   - By version, install channel, and method

3. **Completion Rate**
   - `install_success` / `install_attempt`

4. **Deployment Distribution**
   - VirtualBox: X%
   - Azure: Y%
   - Bare Metal: Z%

5. **Install Channel Distribution**
   - Full: X%
   - Quickstart: Y%

6. **Repeated Attempts**
   - Install IDs with multiple `install_attempt` events
   - Useful as a possible install-friction signal

7. **Geographic Distribution**
   - By country code

### Visualization

Results can be viewed in any lightweight dashboard or exported for aggregate analysis.

## Implementation Checklist

- [ ] Build tiny telemetry endpoint
- [ ] Create `installation_tracking` Ansible role
- [ ] Add `install_attempt` event task near install start
- [ ] Add `install_success` event task at install completion
- [ ] Generate one random `install_id` per install run
- [ ] Add `install_channel` to emitted payloads
- [ ] Add server-side `country_code` inference
- [ ] Add `GIGHIVE_ENABLE_INSTALLATION_TRACKING` to Apache `.env`
- [ ] Render Apache `.env` values from full-install Ansible config
- [ ] Write Apache `.env` values from quickstart bundle installer
- [ ] Add debug mode that prints payload without sending
- [ ] Integrate role into site.yml
- [ ] Update README.md with telemetry disclosure
- [ ] Create TELEMETRY.md documentation ( This file)
- [ ] Test with private installation
- [ ] Verify opt-out mechanism works
- [ ] Verify telemetry failures never fail installation

## Decision Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2025-11-02 | Document telemetry design before implementation | Allows for informed decision-making |
| 2026-03-21 | Use a tiny HTTP endpoint | Simpler and better suited than broad analytics tooling for install-state events |
| 2026-03-21 | Track `install_attempt` and `install_success` | Allows install completion analysis rather than only success counting |
| 2026-03-21 | Keep random `install_id` | Relates the start and finish of the same install and highlights repeated attempts |
| 2026-03-21 | Add `install_channel` as a separate field | Keeps install path separate from version so full vs quickstart can be analyzed cleanly |
| 2026-03-21 | Infer `country_code` server-side | Provides coarse geographic insight without storing precise location |
| 2026-03-21 | Preserve repeated events with the same `install_id` | Repeated attempts may indicate install trouble and should remain visible |
| 2026-03-21 | Use Apache `.env` as the shared telemetry config surface | Works for both full-install Ansible flow and quickstart bundle flow |

---

*Last Updated: 2026-03-21*
*Status: Design Phase - Not Yet Implemented*
