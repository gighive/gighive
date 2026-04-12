# VirtIO Network Stability Investigation & Mitigation

**Date:** 2026-01-19  
**System:** VirtualBox VM (`gighive2`) running Ubuntu with Docker (Apache container)  
**Primary workload:** Large-scale media uploads over SSH (hundreds of GB, thousands of files)

---

## 1. Problem Summary

The VM experienced repeated **hard hangs / loss of SSH connectivity** during heavy upload workloads. Symptoms included:

- SSH becoming unreachable (`No route to host`)
- VM console becoming unresponsive
- Recovery only possible via reboot

Kernel logs showed severe **virtio-net** related failures, including:

- `virtio_net ... output... is not a head`
- `watchdog: BUG: soft lockup`
- `rcu: INFO: rcu_preempt self-detected stall`
- CPUs stuck for hundreds of seconds (notably in `sshd` and `swapper`)

The failures correlated strongly with **sustained, high-throughput network uploads**.

---

## 2. Root Cause Assessment (Most Likely)

Evidence strongly indicates a **VirtIO network offload instability** under sustained load in this environment:

- Stack traces consistently implicated VirtIO TX/RX paths  
  (`virtqueue_*`, `virtnet_poll`, `free_old_xmit_skbs`)
- Failures occurred during high network write pressure, not during idle or CPU-bound work
- Disk, memory, and CPU headroom were all adequate
- Similar historical reports exist of VirtIO offload bugs causing soft lockups under heavy I/O

This points to offload features (**TSO / GSO / GRO**) interacting badly with the host/guest combination.

---

## 3. Mitigation Implemented (Test #1)

### Action

Disabled VirtIO network offloads on the VM interface:

```bash
sudo ethtool -K eth0 tso off gso off gro off
```

### Result

✅ **Successful**

- Uploaded **8,200+ MP3/MP4 files** over ~4 hours with no hangs
- Follow-on test: uploading **~641 GB** of data with the database wiped
- No recurrence of:
  - soft lockups
  - RCU stalls
  - `virtio_net` errors
- SSH remained stable throughout

This mitigation is considered **proven effective** in this environment.

---

## 4. Supporting Observations

### CPU
- Idle typically **85–92%**
- No CPU starvation or steal time

### Disk (iostat)
- Write-heavy workload
- `%util` mostly **<30%**
- No disk saturation

### Network (`ss -s`)
- Stable TCP footprint
- No socket explosion

### Kernel
- Benign message observed:
  - `clocksource: Long readout interval, skipping watchdog check`

---

## 5. Disk Capacity Considerations

- Initial free space: **~658 GB**
- Upload size: **~641 GB**
- Effective headroom: **~17 GB**

Notes:
- Upload progress is file-count based
- Thumbnails and metadata consume additional space
- ext4 reserved blocks = **0**

Disk pressure was **not** the cause of instability.

---

## 6. Ongoing Monitoring Procedures

### Kernel
```bash
dmesg -w
```

### SSH responsiveness
```bash
while true; do date; sleep 15; done
```

### Network
```bash
watch -n1 'ss -s'
```

### Disk
```bash
df -B1 /
sudo du -sb /home/ubuntu/audio /home/ubuntu/video
```

---

## 7. Conclusions

- Issue was **kernel-level VirtIO networking**
- Application stack was not at fault
- Disabling TSO/GSO/GRO stabilized the system
- This configuration should be baseline for this VM

Future options:
- Persist offload disablement
- Test Intel `e1000` NIC
- Reduce upload parallelism

**Status:** stable, reproducible fix confirmed.

---

## 8. Recurrence — April 2026 (Docker Bridge Path)

**Date:** 2026-04-12  
**Workload:** 4K video playback served by the Apache Docker container  
**Symptom:** VM became fully unresponsive; host reported `Destination Host Unreachable`; TTY and ACPI shutdown unresponsive; recovered via hard reboot

### Kernel log signature (boot -1)

```
Apr 12 15:22:27 gighive2 kernel: virtio_net virtio0: output.0:id 810 is not a head!
Apr 12 15:22:55 gighive2 kernel: watchdog: BUG: soft lockup - CPU#2 stuck for 26s! [apache2:70024]
Apr 12 15:22:55 gighive2 kernel: RIP: 0010:virtqueue_enable_cb_delayed+0x2f/0x1f0
Apr 12 15:22:55 gighive2 kernel: virtnet_poll / __napi_poll / net_rx_action
```

### Why the January fix did not prevent this

The January fix (`ethtool -K eth0 tso off gso off gro off`) targets `eth0` — the VM's physical-facing virtio NIC.  
The January workload (SSH uploads) hits `eth0` directly.

The April workload (Apache container serving video) travels a different path:

```
Apache container → veth pair → Docker bridge (br-ce1f7bbd9ee2) → eth0 → virtio
```

The Docker bridge and default bridge (`docker0`) were never included in the ethtool fix, so they continued to have TSO/GSO/GRO **enabled**. Packets from the container could carry GSO flags through the bridge to the virtio TX ring, corrupting ring state and producing the `is not a head` error — even though `eth0` itself had offloads disabled.

### Confirmation

```bash
# eth0 — January fix is active
sudo ethtool -k eth0 | grep -E "tcp-segmentation-offload|generic-segmentation-offload|generic-receive-offload"
tcp-segmentation-offload: off
generic-segmentation-offload: off
generic-receive-offload: off

# Docker bridges — offloads still on (root cause)
sudo ethtool -k br-ce1f7bbd9ee2 | grep -E "tcp-segmentation-offload|generic-segmentation-offload|generic-receive-offload"
tcp-segmentation-offload: on
generic-segmentation-offload: on
generic-receive-offload: on

sudo ethtool -k docker0 | grep -E "tcp-segmentation-offload|generic-segmentation-offload|generic-receive-offload"
tcp-segmentation-offload: on
generic-segmentation-offload: on
generic-receive-offload: on
```

### Live test

Manually disabled offloads on both bridges while the server was running (no traffic disruption):

```bash
sudo ethtool -K br-ce1f7bbd9ee2 tso off gso off gro off
sudo ethtool -K docker0 tso off gso off gro off
```

Played the full 10 GB 4K video — no lockup, no `output.0:id ... is not a head` messages in `journalctl -kf`.

**Fix confirmed effective.**

---

## 9. Permanent Fix — Ansible (Pending Application)

### Rationale

Docker bridge offload disablement belongs in `ansible/roles/docker` because it is causally tied to Docker's bridge networking, not the VM's physical NIC.  
A dedicated systemd unit is used (matching the pattern established by `cloud_init_disable`) so the fix survives reboots.  
The unit runs `After=docker.service`; Docker restores all named networks (including `br-ce1f7bbd9ee2`) on daemon start, so the bridges exist before the unit fires.  
The shell loop discovers all `br-*` interfaces dynamically, so the fix survives Docker Compose network recreation (which would produce a new bridge name).

### File changed

**`ansible/roles/docker/tasks/main.yml`** — three tasks added immediately after the `Start Docker Compose stack` task:

```yaml
- name: Ensure ethtool is installed (for Docker bridge offload fix)
  become: yes
  ansible.builtin.apt:
    name: ethtool
    state: present
    update_cache: yes

- name: Install Docker bridge offloads disable systemd unit
  become: yes
  ansible.builtin.copy:
    dest: /etc/systemd/system/docker-bridge-offloads-disable.service
    mode: "0644"
    content: |
      [Unit]
      Description=Disable offloads (TSO/GSO/GRO) on Docker bridge interfaces
      After=docker.service
      Wants=docker.service

      [Service]
      Type=oneshot
      ExecStart=/bin/bash -c 'for iface in docker0 $(ls /sys/class/net/ | grep "^br-"); do /usr/sbin/ethtool -K "$iface" tso off gso off gro off 2>/dev/null || true; done'
      RemainAfterExit=yes

      [Install]
      WantedBy=multi-user.target

- name: Enable and start Docker bridge offloads disable service
  become: yes
  ansible.builtin.systemd:
    name: docker-bridge-offloads-disable.service
    enabled: yes
    state: started
    daemon_reload: yes
```

### Design notes

- Placement after `docker compose up` ensures `br-ce1f7bbd9ee2` exists when `state: started` runs on the initial Ansible run
- `2>/dev/null || true` per interface — tolerates any bridge that disappears between discovery and the ethtool call
- `RemainAfterExit=yes` — systemd keeps the unit active so Ansible's `state: started` is idempotent on re-runs
- `daemon_reload: yes` — picks up the newly written unit file before enable/start

**Status:** implemented in `ansible/roles/docker/tasks/main.yml`.
