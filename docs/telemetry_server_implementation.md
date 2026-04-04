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

Instead, it should remain bound on the staging host network interface so it can be reached from the separate proxy path.

## Origin Host

The telemetry receiver will run on the existing staging server.

This is acceptable because:

- staging is reliably available
- staging already supports HTTPS through the existing deployment setup
- this is the minimum-work deployment path
- the telemetry workload is expected to be very small

## Receiver Binding

The telemetry receiver should bind on the staging host network interface so it can be reached from the separate proxy path.

Example:

- `0.0.0.0:8088`

This allows the receiver service to be reached from the proxy path while still being restricted by firewall or private-network rules.

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
- the telemetry receiver can remain off the public hostname while being reachable from the proxy path
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

Because the Cloudflare proxy runs on a separate server, the telemetry receiver should bind on `0.0.0.0:8088` and be restricted by firewall or private-network rules.

## Security Considerations

### Transport

- use HTTPS only for public telemetry requests
- restrict the origin receiver to the proxy host or private network where possible

### Input validation

- accept only POST
- accept only the expected JSON schema

### Local health-check target

- `0.0.0.0:8088`

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

- `0.0.0.0:8088`

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

## Idempotent database provisioning note

The telemetry receiver role now includes an explicit post-start schema-apply step after MySQL begins accepting connections.

This is intentional.

The MySQL container still mounts `./mysql/init` into `/docker-entrypoint-initdb.d` for first-boot initialization convenience, but Ansible no longer relies on that one-time container-init path as the only mechanism for provisioning the telemetry database.

### Why this was added

- MySQL entrypoint init scripts run only when the datadir is empty
- a rerun against an existing or partially initialized datadir could otherwise leave the telemetry schema missing
- rerunning the role should reconcile the desired database/table state instead of depending on first-boot behavior

### What the role now does

- waits for the telemetry MySQL container to accept connections
- executes the schema SQL explicitly from Ansible
- keeps that schema SQL safe to rerun with `CREATE DATABASE IF NOT EXISTS` and `CREATE TABLE IF NOT EXISTS`
- grants the application user privileges on the telemetry database with `GRANT ALL PRIVILEGES ...`
- runs `FLUSH PRIVILEGES` so the receiver app can use the database immediately

### Credential-handling note

The explicit schema-apply task is marked with `no_log: true` so the MySQL root password is not exposed in Ansible output while executing the bootstrap SQL.

## GigHive Apache Container Proxy Configuration

The GigHive Apache container (`apacheWebServer`) is the TLS termination point for all `*.gighive.app` traffic including `telemetry.gighive.app`. Three changes are required to route telemetry traffic to the receiver. The first two are in the GigHive Ansible docker role templates; the third gates them to the staging host only.

### Change 1: extra_hosts in docker-compose.yml.j2

The Apache container must be able to resolve `host.docker.internal` so it can proxy requests to the telemetry receiver bound on the Docker host at port 8088.

In `ansible/roles/docker/templates/docker-compose.yml.j2`, add `extra_hosts` to the `apacheWebServer` service:

```yaml
    extra_hosts:
      - "host.docker.internal:host-gateway"
```

### Change 2: Telemetry VirtualHost in default-ssl.conf.j2

A dedicated `VirtualHost` block for `telemetry.gighive.app` must be appended to `ansible/roles/docker/templates/default-ssl.conf.j2`. It reuses the same wildcard SSL certificate (`*.gighive.app`) already in use by the main GigHive vhost.

```apache
<VirtualHost *:443>
    ServerName {{ gighive_telemetry_fqdn | default('telemetry.gighive.app') }}

    SSLEngine on
    SSLCertificateFile {{ gighive_ssl_cert_file | default('/etc/ssl/certs/origin_cert.pem') }}
    SSLCertificateKeyFile {{ gighive_ssl_key_file | default('/etc/ssl/private/origin_key.pem') }}
    SSLProtocol {{ gighive_ssl_protocols | default('all -SSLv3 -TLSv1 -TLSv1.1 +TLSv1.3') }}
    SSLCipherSuite {{ gighive_ssl_cipher_suite | default('HIGH:!aNULL:!MD5') }}
    SSLHonorCipherOrder {{ gighive_ssl_honor_order | default('on') }}
    Protocols {{ gighive_protocols | default('h2 http/1.1') }}

    ProxyPreserveHost On
    ProxyPass "/" "http://host.docker.internal:8088/"
    ProxyPassReverse "/" "http://host.docker.internal:8088/"
</VirtualHost>
```

`mod_proxy_http` is already active in the Apache container (the tusd proxy uses it). No additional module enablement is required.

Cloudflare's `CF-IPCountry` header is forwarded automatically by `mod_proxy_http` and is read by the telemetry receiver as `HTTP_CF_IPCOUNTRY` for country code capture.

### Change 3: gighive_enable_telemetry_proxy in group_vars/gighive/gighive.yml

Both template changes (1 and 2) are gated on `gighive_enable_telemetry_proxy | default(false)`. This ensures the telemetry VirtualHost and `extra_hosts` entry are only rendered for the staging host and never applied to other GigHive environments (e.g. `gighive2`).

Add the following to `ansible/inventories/group_vars/gighive/gighive.yml` in the installation tracking variable block:

```yaml
gighive_enable_telemetry_proxy: true
```

This variable is intentionally absent from `group_vars/gighive2/` and any other environment group_vars. Its absence causes the default (`false`) to apply, leaving those environments unaffected.

After applying all three changes, re-run the staging Ansible playbook (`site.yml`) targeting the docker role:

```bash
ansible-playbook -i ansible/inventories/inventory_staging_telemetry.yml \
  ansible/playbooks/site.yml --tags docker
```

## Post-Deploy Verification Checks

Two layers of automated post-deploy checks are implemented to catch failures that manual inspection can miss. These were added after a production incident where a container with a broken overlay filesystem mount reported as "running" but was silently failing all requests.

### Layer 1: telemetry_receiver.yml checks

These run at the end of `ansible/roles/telemetry_receiver/tasks/post_deploy_checks.yml` and are called from the end of `main.yml`. They verify the receiver stack in isolation, without going through the Apache proxy or Cloudflare.

#### Check 1: Container is running

Verify `telemetry_receiver_app` is running using `docker_container_info`. Asserts `State.Status == "running"`.

#### Check 2: Filesystem mount is accessible

Run `docker exec telemetry_receiver_app ls /var/www/html/index.php` and assert the command succeeds (rc == 0). This detects the broken overlay mount namespace scenario — a container can report "running" while its volume mount is inaccessible, causing silent 404s.

#### Check 3: Receiver responds correctly to GET (method guard)

HTTP GET to `http://{{ telemetry_receiver_probe_host }}:{{ telemetry_receiver_bind_port }}/` must return `405 Method Not Allowed`. This is the existing check already in `main.yml`; it is preserved in `post_deploy_checks.yml`.

#### Check 4: Receiver accepts a POST and returns 204

HTTP POST to `http://{{ telemetry_receiver_probe_host }}:{{ telemetry_receiver_bind_port }}/` with a valid JSON payload must return `204 No Content`. This is the functional correctness check — it exercises the full PHP code path including JSON parsing and database write.

Test payload:

```json
{
  "event_name": "install_attempt",
  "app_version": "ansible-post-deploy-check",
  "install_channel": "quickstart",
  "install_method": "virtualbox",
  "app_flavor": "gighive",
  "timestamp": "{{ ansible_date_time.iso8601 }}",
  "install_id": "ansible-check-{{ ansible_date_time.epoch }}"
}
```

#### Check 5: DB row was written

Query `installation_events` and assert at least one row exists with `install_id` matching the value sent in Check 4. Uses `docker exec telemetry_db mysql ...` with the application credentials.

### Layer 2: site.yml post_build_checks (gated on gighive_enable_telemetry_proxy)

These run as part of the existing `post_build_checks` role in `site.yml`, gated on `gighive_enable_telemetry_proxy | default(false)`. They verify the full request chain: Apache proxy → receiver → DB.

#### Check 1: Telemetry VirtualHost is loaded in Apache

Run `docker exec {{ apache_container_name }} apache2ctl -S` and assert the output contains `{{ gighive_telemetry_fqdn | default('telemetry.gighive.app') }}`. This confirms the new VirtualHost block was rendered and loaded — it does not catch a runtime proxy failure but does catch a missing config or failed rebuild.

#### Check 2: End-to-end HTTPS POST returns 204

HTTP POST to `https://{{ gighive_telemetry_fqdn | default('telemetry.gighive.app') }}/` with a valid JSON payload must return `204 No Content`. This exercises the complete path: GigHive Apache → `host.docker.internal:8088` → telemetry receiver PHP → MySQL.

Test payload uses the same shape as Layer 1 Check 4 with a distinct `install_id` prefix (`site-check-`) to distinguish from receiver-direct checks in the database.

### Container recreate behavior

The `telemetry_receiver.yml` playbook uses `community.docker.docker_compose_v2` with `recreate: always`. This ensures the receiver containers are always stopped and recreated on each playbook run, preventing the broken-mount-namespace scenario where a container is "running" but its overlay filesystem is corrupt from a prior unclean shutdown.

### Playbook run reference

| Goal | Command |
|---|---|
| Deploy or redeploy receiver only | `ansible-playbook -i ansible/inventories/inventory_staging_telemetry.yml ansible/playbooks/telemetry_receiver.yml` |
| Deploy GigHive Apache proxy changes + verify end-to-end | `ansible-playbook -i ansible/inventories/inventory_staging_telemetry.yml ansible/playbooks/site.yml --tags docker,post_build_checks` |
| Run post-deploy checks only (no deploy) | `ansible-playbook -i ansible/inventories/inventory_staging_telemetry.yml ansible/playbooks/site.yml --tags post_build_checks` |

## Status

- confirmed as the preferred initial deployment model
- intended to minimize implementation effort while keeping telemetry isolated from the main GigHive app
