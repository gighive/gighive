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
