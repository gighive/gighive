# Docker Container Auto-Restart on VM Host Reboot

## Summary

All GigHive Docker containers are configured with `restart: unless-stopped`, meaning they
will automatically restart after an unplanned VM host reboot without any manual intervention.

## Restart Policy

`unless-stopped` behavior:
- **Restarts automatically** after a host reboot or Docker daemon restart
- **Does not restart** if the container was manually stopped with `docker stop` or
  `docker compose down` before the host went down
- This is intentional: manual stops (e.g. during maintenance) are respected

## Where It Is Set

### Main GigHive stack

`ansible/roles/docker/templates/docker-compose.yml.j2` — three containers:

| Container | Line | Policy |
|---|---|---|
| `apacheWebServer` | ~17 | `restart: unless-stopped` |
| `tusd` | ~58 | `restart: unless-stopped` |
| MySQL (`{{ mysql_container_name }}`) | ~69 | `restart: unless-stopped` |

### Telemetry receiver stack

`ansible/roles/telemetry_receiver/templates/docker-compose.yml.j2` — two containers:

| Container | Line | Policy |
|---|---|---|
| `telemetry_receiver` | 8 | `restart: unless-stopped` |
| `telemetry_db` | 23 | `restart: unless-stopped` |

## Prerequisite: Docker Daemon Must Be Enabled

The Docker daemon itself must be enabled as a systemd service on the host VM. Without this,
Docker will not start after a host reboot and no containers will come up.

Verify:
```bash
systemctl is-enabled docker
# Expected output: enabled
```

If not enabled:
```bash
sudo systemctl enable docker
```

## Restart Sequence on Host Reboot

1. Host VM boots
2. systemd starts the Docker daemon (`docker.service`)
3. Docker daemon reads the restart policy for all known containers
4. Containers with `restart: unless-stopped` are started in dependency order:
   - `telemetry_db` starts before `telemetry_receiver` (enforced by `depends_on`)
   - Main stack containers start independently

## Verification After Reboot

```bash
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
```

All five containers should show `Up` status within ~30 seconds of host boot.
