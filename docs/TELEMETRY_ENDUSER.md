# GigHive Installation Telemetry

GigHive sends a small amount of installation telemetry so the project can understand how often installs are started and completed.

This helps answer questions like:

- did an install start and later finish successfully?
- are some installs being retried multiple times?
- do repeated retries cluster by version, install channel, method, or country?

## What GigHive sends

- `install_attempt` and `install_success` events
- app version
- install channel
- install method
- app flavor
- timestamp
- a random install ID

## What GigHive does not send

- media files
- uploaded content
- database contents
- user passwords
- region, state, or city
- precise location

## Location data

- GigHive derives only `country_code` on the telemetry server side
- GigHive does not collect region/state information

## Opt out

- Installation telemetry can be disabled before install in both the full-build and quickstart setup flows

## Behavior

- Telemetry is best-effort only
- If telemetry fails, installation continues normally
