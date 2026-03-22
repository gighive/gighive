# GigHive Installation Telemetry

GigHive collects a small amount of installation telemetry so the project can understand how often installs are started and completed. This helps answer questions like:

- did an install start and later finish successfully?
- are some installs being retried multiple times?
- do repeated retries cluster by version, install channel, method, or country?

## What GigHive collects

- `install_attempt` and `install_success` events
- app version
- install channel
- install method
- app flavor
- timestamp
- a random install ID

## What GigHive does not collect

- media files
- uploaded content
- database contents
- user IP
- user passwords
- region, state, or city
- precise location

## Location data

- GigHive derives only `country_code` on the telemetry server side
- GigHive does not collect any location information below country, like region/state/city/address

## Opt out

- Installation telemetry can be disabled before install in both the [full-build setup flow](setup_instructions_fullbuild.html) and the [quickstart setup flow](setup_instructions_quickstart.html)

## Behavior

- Telemetry is best-effort only
- If telemetry fails, installation continues normally
