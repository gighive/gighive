# Problem: Telemetry Receiver Not Collecting Data

## Symptoms

- `telemetry_receiver_app` container shows as `Up` and healthy in `docker ps`
- All `POST /` requests return `404 540` instead of `204`
- Ansible post-deploy checks from April 1 passed (204 responses), but curl tests from April 3+ all failed (404 responses)
- `docker exec telemetry_receiver_app ls /var/www/html/index.php` → `No such file or directory`
- `docker inspect` confirms bind mount source does not exist on host:

```
[{"Type":"bind","Source":"/home/ubuntu/gighive/telemetry_receiver/web","Destination":"/var/www/html","Mode":"ro","RW":false,"Propagation":"rprivate"}]
```

```
ls: cannot access '/home/ubuntu/gighive/telemetry_receiver/web': No such file or directory
```

## Root Cause (Hypothesis — Unconfirmed, Pending Log Review)

Running `ansible-playbook site.yml` (with the `base` role) is believed to have deleted the `telemetry_receiver/` directory from the staging VM.

The base role's rsync task syncs `scripts_home/` → `gighive_home` with `delete: yes`. The `telemetry_receiver/` directory lives under `gighive_home` on the VM but does not exist in the controller repo (`scripts_home`), and is not in the rsync exclude list. Any `site.yml` run that executes the base role's sync task will therefore silently delete it. Docker does not stop the container when the bind mount source is removed — it continues running against an empty directory, causing all PHP requests to 404. The fix is to add `--exclude=telemetry_receiver/` to the base role's `rsync_opts`. Confirmation requires grepping the Ansible log from the triggering run for `telemetry_receiver` in the rsync delete output.

### Supporting Evidence

The `base` role's sync task (`ansible/roles/base/tasks/main.yml`) uses rsync with `delete: yes`:

```yaml
- name: Sync scripts to the VM (excluding cloud-init)
  ansible.builtin.synchronize:
    src: "{{ scripts_home }}/"
    dest: "{{ gighive_home }}"
    delete: yes
    rsync_opts:
      - "--exclude=cloud-init/"
      - "--exclude=*.vdi"
      - "--exclude=*.vmdk"
      - "--exclude=ansible/roles/docker/files/apache/downloads/"
      - "--exclude=ansible/roles/docker/files/apache/externalConfigs/resizerequests/"
      - "--exclude=ansible/roles/docker/files/mysql/dbScripts/backups/"
      - "--exclude=ansible*.log"
```

`scripts_home` is the local controller repo. `gighive_home` is the staging VM's home directory (e.g., `/home/ubuntu/gighive`).

The `telemetry_receiver/` directory is:
- **deployed to the VM** by `telemetry_receiver.yml` under `{{ gighive_home }}/telemetry_receiver/`
- **not present** in the controller repo (`scripts_home`)
- **not excluded** from the rsync `--exclude` list

Therefore, any `site.yml` run that executes the base role's sync task will delete `telemetry_receiver/` from the VM — including `telemetry_receiver/web/index.php`.

### What the user ran (believed trigger)

```bash
script -q -c "ansible-playbook -i ansible/inventories/inventory_gighive.yml \
  ansible/playbooks/site.yml \
  --skip-tags vbox_provision,upload_tests,installation_tracking,one_shot_bundle,one_shot_bundle_archive" \
  ansible-playbook-gighive-20260404.log
```

Note: `base` role's sync task is tagged `sync_scripts`, which is **not skipped** by the above command.

### Why the container kept running

Docker does not stop a container when a bind mount source directory is deleted from the host. The container continues running, but the missing source directory causes the bind mount to appear as an empty directory inside the container. Apache starts fine; PHP files are simply absent → every request returns 404.

### Why it looked like the Apache rebuild was the cause

The `apacheWebServer` container was 8 hours old at diagnosis time (~11:30 AM April 4), while 404s began April 3. The Apache rebuild happened **after** the failure started and is unrelated.

### Misleading `docker exec` error

Running `docker exec` from `~` (home directory) produced:

```
OCI runtime exec failed: exec failed: unable to start container process:
current working directory is outside of container mount namespace root
-- possible container breakout detected
```

This is a Docker security restriction triggered by running `docker exec` from a directory that is part of the container's bind mount namespace on some kernel versions — not related to the bind mount failure itself. Switching to `~/gighive/` resolved it.

## Timeline

| Date/Time | Event |
|---|---|
| April 1, ~10:01 AM | `telemetry_receiver.yml` Ansible run: receiver deployed, directories created, containers started, all checks passed (204) |
| April 1–3 (exact time unknown) | `site.yml` run with base role rsync deletes `/home/ubuntu/gighive/telemetry_receiver/` from VM |
| April 3, ~1:58 PM | First observed 404 from curl |
| April 4, ~11:30 AM | Apache container rebuilt (unrelated) |
| April 4, ~7:40 PM | Diagnosed: bind mount source missing on host |
| April 4, ~7:40 PM | `telemetry_receiver.yml` rerun: directories recreated, index.php deployed, containers recreated, all checks pass |

## Resolution

Rerun `telemetry_receiver.yml`:

```bash
ansible-playbook -i ansible/inventories/inventory_staging_telemetry.yml \
  ansible/playbooks/telemetry_receiver.yml
```

This recreates the `telemetry_receiver/web/` directory, deploys `index.php`, and uses `recreate: always` to rebuild the containers cleanly.

## Fix Required

Add `telemetry_receiver/` to the rsync excludes in `ansible/roles/base/tasks/main.yml` so that `site.yml` runs do not delete it:

```yaml
rsync_opts:
  - "--exclude=cloud-init/"
  - "--exclude=*.vdi"
  - "--exclude=*.vmdk"
  - "--exclude=ansible/roles/docker/files/apache/downloads/"
  - "--exclude=ansible/roles/docker/files/apache/externalConfigs/resizerequests/"
  - "--exclude=ansible/roles/docker/files/mysql/dbScripts/backups/"
  - "--exclude=ansible*.log"
  - "--exclude=telemetry_receiver/"    # <-- add this
```

**Status: Not yet applied — awaiting confirmation of root cause.**

## Confirmation Steps

To confirm the hypothesis before applying the fix:

1. Check the `ansible-playbook-gighive-20260404.log` on the controller for the sync task output — look for `telemetry_receiver/web/index.php` in the rsync delete list.
2. Alternatively, check the VM's filesystem timestamps: if `telemetry_receiver/` was deleted and re-created around the time of the `site.yml` run, that confirms it.
