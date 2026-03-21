# Telemetry Server Implementation

## Overview

This document describes the server-side implementation for GigHive installation telemetry.

The scope here is operational and deployment-focused. It covers where the telemetry receiver runs, how it is exposed publicly, how it stores telemetry events, and how it is deployed.

This document is intentionally limited to the server side. The telemetry event model, privacy commitments, and end-user disclosure language are documented in `docs/TELEMETRY.md`.

## Confirmed Hosting Model

GigHive installation telemetry will be received by a small dedicated endpoint exposed at:

- `https://telemetry.gighive.app`

This hostname is the public telemetry endpoint.

## Network Architecture

The public telemetry endpoint is fronted by Cloudflare.

### Request flow

- installer sends HTTPS request to `https://telemetry.gighive.app`
- Cloudflare proxies the request
- Cloudflare forwards the request to the staging server
- the staging server forwards the request to the local telemetry receiver service
- the telemetry receiver validates and stores the event

### Important implementation choice

The telemetry receiver itself is not intended to be exposed directly to the public internet.

Instead, it should remain bound locally on the staging host and be reached only through the reverse-proxied public hostname.

## Origin Host

The telemetry receiver will run on the existing staging server.

This is acceptable because:

- staging is reliably available
- staging already supports HTTPS through the existing deployment setup
- this is the minimum-work deployment path
- the telemetry workload is expected to be very small

## Receiver Binding

The telemetry receiver should bind only to localhost on the staging host.

Example:

- `127.0.0.1:8088`

This prevents the receiver service from being directly reachable from the public network.

## Public Exposure Model

The preferred public exposure model is:

- dedicated subdomain: `telemetry.gighive.app`

This is preferred over placing the endpoint under the main application path because:

- it keeps telemetry clearly separated from the main app
- it avoids route collisions with the main application
- it makes future migration easier
- it provides a cleaner public explanation than using a staging hostname

## Cloudflare Considerations

Cloudflare will sit in front of the telemetry endpoint.

### Benefits

- HTTPS termination and proxying are already part of the existing setup
- the telemetry receiver can stay local-only on the staging host
- Cloudflare can provide coarse country information via request headers

### Country capture

The telemetry receiver may derive `country_code` from Cloudflare-provided request metadata.

The preferred source is:

- `CF-IPCountry`

The receiver may also optionally support other proxy/header-based country sources if needed.

### Privacy note

The telemetry service should store only the final coarse `country_code` as telemetry data.

It should not store:

- raw IP address as telemetry data
- precise location
- city-level location

## Endpoint Shape

The telemetry receiver is a very small HTTP service.

### Responsibilities

- accept JSON POST requests
- validate a narrow payload schema
- reject malformed requests
- derive `country_code` server-side when possible
- persist events to the telemetry database
- return quickly
- avoid affecting install success if the endpoint is unavailable

### Expected events

The receiver supports:

- `install_attempt`
- `install_success`

### Expected client fields

- `event_name`
- `app_version`
- `install_channel`
- `install_method`
- `app_flavor`
- `timestamp`
- `install_id`

### Server-derived field

- `country_code`

## Storage

The server-side implementation uses a standard relational database.

For the initial implementation, a dedicated MySQL container is used for telemetry storage.

### Table purpose

The database stores installation telemetry events for later aggregate analysis.

### Core stored columns

- event name
- app version
- install channel
- install method
- app flavor
- install ID
- event timestamp
- country code
- creation timestamp

### Important behavior

Repeated events with the same `install_id` are preserved.

This is intentional because repeated `install_attempt` events may indicate:

- install retries
- interrupted installs
- uncertainty about whether installation completed
- genuine install problems

The server should not automatically discard repeated same-ID events as duplicates.

## Deployment Model

The server-side telemetry implementation is deployed separately from the main GigHive application stack.

This keeps telemetry isolated and minimizes risk to the main application.

### Deployment mechanism

The initial implementation uses:

- a dedicated Ansible playbook
- a dedicated Ansible role
- a separate Docker Compose stack

### Confirmed Ansible artifacts

- `ansible/playbooks/telemetry_receiver.yml`
- `ansible/roles/telemetry_receiver/`

### Startup command

To deploy or start the telemetry receiver service with Ansible, run:

```bash
ansible-playbook -i ansible/inventories/inventory_lab.yml ansible/playbooks/telemetry_receiver.yml
```

Use the inventory file that points at the staging host which will serve `telemetry.gighive.app`.

If your staging host is represented by a different inventory, substitute that inventory file accordingly.

Example pattern:

```bash
ansible-playbook -i ansible/inventories/<staging-inventory>.yml ansible/playbooks/telemetry_receiver.yml
```

### Stack components

The telemetry deployment includes:

- a small PHP-based receiver service
- a dedicated MySQL database container

### Deployment location on host

The telemetry stack is deployed under:

- `{{ gighive_home }}/telemetry_receiver`

This keeps it separate from the main GigHive Docker project.

## Reverse Proxying

The telemetry receiver should be reverse-proxied from the public hostname to the telemetry VM over the VM network.

Conceptually:

- public: `https://telemetry.gighive.app`
- proxy origin target: `http://<telemetry-vm-ip>:8088`

The exact reverse-proxy implementation can be handled by the existing front-end path used on staging.

Because the Cloudflare proxy runs on a separate server, the telemetry receiver cannot remain bound to `127.0.0.1` only.

## Security Considerations

### Transport

- use HTTPS only for public telemetry requests
- restrict the origin receiver to the proxy host or private network where possible

### Input validation

- accept only POST
- accept only the expected JSON schema

### Local health-check target

- `127.0.0.1:8088`

### Exposure model

- Cloudflare-proxied dedicated subdomain
- reverse proxy from the separate proxy server to the telemetry VM on port `8088`
- firewall or network restriction so only the proxy path can reach the telemetry port

### Database

- dedicated MySQL telemetry database

## Operational Notes

### Availability

Telemetry is best-effort only.

If the telemetry endpoint is unavailable:

- installations must continue
- telemetry failure must not fail installation

### Future flexibility

Using `telemetry.gighive.app` allows the backend implementation to move later without changing the public contract.

For example, the receiver could later move from staging to another host while keeping the same public endpoint.

## Recommended Configuration Summary

### Public endpoint

- `https://telemetry.gighive.app`

### Origin host

- staging server

### Telemetry receiver bind

- `0.0.0.0:8088`

### Local health-check target

- `127.0.0.1:8088`

### Exposure model

- Cloudflare-proxied dedicated subdomain
- reverse proxy from the separate proxy server to the telemetry VM on port `8088`
- firewall or network restriction so only the proxy path can reach the telemetry port

### Database

- dedicated MySQL telemetry database

### Viewing the data from Docker

To inspect captured telemetry directly from the staging host, run MySQL queries inside the telemetry database container.

### Validation steps

The following steps were validated in the lab and staging environments.

1. Confirm that the telemetry containers are running:

```bash
docker ps
```

2. Verify that the PHP container can connect to MySQL:

```bash
docker exec telemetry_receiver_app php -r '
$dsn = "mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("MYSQL_DATABASE") . ";charset=utf8mb4";
try {
    $pdo = new PDO($dsn, getenv("MYSQL_USER"), getenv("MYSQL_PASSWORD"), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "DB_OK\n";
} catch (Throwable $e) {
    echo "DB_FAIL: " . $e->getMessage() . "\n";
}
'
```

Expected result:

- `DB_OK`

3. Insert a test telemetry event locally from the telemetry VM:

```bash
curl -i -X POST http://127.0.0.1:8088 \
  -H 'Content-Type: application/json' \
  -d '{
    "event_name": "install_attempt",
    "app_version": "1.2.0",
    "install_channel": "quickstart",
    "install_method": "virtualbox",
    "app_flavor": "gighive",
    "timestamp": "2026-03-21T13:00:00Z",
    "install_id": "550e8400-e29b-41d4-a716-446655440000"
  }'
```

Expected result:

- `HTTP/1.1 204 No Content`

4. Insert the same test telemetry event through the VM network address:

```bash
curl -i -X POST http://<telemetry-vm-ip>:8088 \
  -H 'Content-Type: application/json' \
  -d '{
    "event_name": "install_attempt",
    "app_version": "1.2.0",
    "install_channel": "quickstart",
    "install_method": "virtualbox",
    "app_flavor": "gighive",
    "timestamp": "2026-03-21T13:00:00Z",
    "install_id": "550e8400-e29b-41d4-a716-446655440001"
  }'
```

Expected result:

- `HTTP/1.1 204 No Content`

This validates that the receiver is reachable on the VM interface for a separate proxy host.

5. Insert the same test telemetry event through the public HTTPS hostname:

```bash
curl -i -X POST https://telemetry.gighive.app \
  -H 'Content-Type: application/json' \
  -d '{
    "event_name": "install_attempt",
    "app_version": "1.2.0",
    "install_channel": "quickstart",
    "install_method": "virtualbox",
    "app_flavor": "gighive",
    "timestamp": "2026-03-21T13:00:00Z",
    "install_id": "550e8400-e29b-41d4-a716-446655440002"
  }'
```

Expected result:

- `HTTP/2 204`

This validates the production-style access path through `https://telemetry.gighive.app`, with the public HTTPS endpoint routed to the staging telemetry origin.

6. Query the stored telemetry rows from the Docker host:

```bash
docker exec telemetry_db mysql -u telemetry_app -p<MYSQL_PASSWORD_VALUE> installation_telemetry -e "SELECT id, event_name, app_version, install_channel, install_method, app_flavor, install_id, event_timestamp, country_code, created_at FROM installation_events ORDER BY id DESC LIMIT 20;"
```

The password used by the MySQL client is the value of:

- `MYSQL_PASSWORD`

### Reset steps for a clean lab retest

If the telemetry database was initialized with stale credentials or state during lab testing, reset the telemetry stack data directory on the Docker host and redeploy.

On the lab VM:

```bash
cd /home/ubuntu/gighive/telemetry_receiver
sudo docker compose down
sudo rm -rf /home/ubuntu/gighive/telemetry_receiver/data
```

Then rerun the telemetry deployment from the Ansible control host:

```bash
ansible-playbook -i ansible/inventories/inventory_lab.yml ansible/playbooks/telemetry_receiver.yml
```

### Deployment method

- separate Ansible playbook and role
- separate Docker Compose stack

## Status

- confirmed as the preferred initial deployment model
- intended to minimize implementation effort while keeping telemetry isolated from the main GigHive app
