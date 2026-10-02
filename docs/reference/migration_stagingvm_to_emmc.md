# Refactor: Move staging VM VDI from md0 to eMMC

## Problem

The VirtualBox staging VM (`gighive`, running at `192.168.1.248`) aborted unexpectedly on `2026-05-23 22:15:49` during a large overnight upload batch (~6000 videos) with concurrent AI worker processing.

---

## Root Cause: Disk I/O Starvation on md0

The VM's VDI file lives on `/dev/md0` (the RAID-5 NVMe array). During the upload + AI worker workload, md0 was under extreme write pressure. The VM guest kernel stalled waiting for I/O, stopped sending heartbeats to VirtualBox, and VirtualBox aborted the VM.

### Evidence from `sar` (22:00–22:20 on May 23)

**CPU:**

| Time | %user | %system | %iowait | %idle |
|------|-------|---------|---------|-------|
| 22:10 | 70.64% | 21.91% | 1.73% | 5.71% |
| 22:20 | 72.10% | 23.90% | 0.71% | 3.29% |

Host CPU was 94–96% busy. Only 3–5% idle headroom for VM vCPU scheduling.

**Memory:**

| Time | kbmemfree | kbavail | kbdirty |
|------|-----------|---------|---------|
| 22:10 | 1,867 MB | 9,009 MB | 840 MB |
| 22:20 | 284 MB | 8,962 MB | 766 MB |

No OOM — available memory stayed stable. Memory was not the cause.

**Disk I/O on md0 (the critical data):**

| Time | tps | rkB/s | wkB/s | aqu-sz | await | %util |
|------|-----|-------|-------|--------|-------|-------|
| 22:10 | 537 | 101 MB/s | 113 MB/s | **70.89** | **131.91ms** | 56% |
| 22:20 | 398 | 57 MB/s | 120 MB/s | **50.20** | **125.92ms** | 42% |

**aqu-sz of 70** (70 I/O requests queued simultaneously) and **await of 131ms** mean any I/O from the VM experienced 100ms+ latency. The VM guest kernel issued disk operations, got no response for 19 seconds, and froze.

**To check current md0 queue depth and latency in real time:**
```bash
sar -d 1 3 | grep md0
```
Healthy baseline: `aqu-sz` < 5, `await` < 10ms. During heavy upload load last night: `aqu-sz` 50–70, `await` 125–131ms.

### Why the Queue Stacked Up: Contributing Factors

Only 3 files were uploading concurrently (`UPLOAD_CONCURRENCY=3`) — the queue was not caused by thousands of simultaneous uploads. It was caused by multiple workloads each multiplying physical I/O operations on the same array.

The stack-up was caused by RAID-5 write amplification. Each TUS write of an 8MB chunk to md0 requires the array to perform 4 physical operations:

1. **Read** the existing parity stripe from the other drives
2. **Compute** the new parity
3. **Write** the data block
4. **Write** the updated parity block

So 1 logical write becomes 4 physical I/O operations across the NVMe drives. With 3 concurrent uploads each streaming 8MB chunks, the physical I/O queue fills up fast — especially when the AI worker's ffmpeg is simultaneously reading large video files from the same array for frame extraction. The 537 tps and aqu-sz of 70 was RAID-5 amplification + ffmpeg reads + TUS writes all competing for the same queue, not thousands of files uploading simultaneously. The individual drives each had 9–36ms await — modest on their own — but the md0 logical layer saw 131ms because requests were serialized behind each other.

| Factor | What it does | Physical I/O impact |
|--------|-------------|---------------------|
| **TUS uploads (3 concurrent × 8MB chunks)** | Each 8MB chunk written to md0 | ~14 chunk writes/sec → 113 MB/s logical write |
| **RAID-5 write amplification** | Each logical write triggers: read old parity + compute new parity + write data + write parity | **4× physical I/Os per logical write** — turns 113 MB/s writes into ~450 MB/s physical ops |
| **AI worker ffmpeg reads** | Frame extraction reads full video files sequentially from md0 | ~100 MB/s reads competing in the same queue as writes |
| **VM VDI I/O (small random)** | Linux kernel inside VM doing metadata, journaling, periodic flushes | Low throughput but high sensitivity — small random I/Os get stuck behind large sequential ones |
| **RAID-5 stripe size (512K)** | 8MB TUS chunk spans ~5–6 full stripes — mostly full-stripe writes, but partial stripes at boundaries still trigger read-modify-write | Minor amplification at chunk boundaries |

### The Calculation: Why aqu-sz Reached 70

This follows **Little's Law**: `queue depth = arrival rate × wait time`

```
L  =  λ  ×  W
70 = 537 × 0.130s   ✓  (checks out exactly from sar data)
```

Where:
- **λ = 537 req/s** — physical I/O arrival rate (TUS writes × RAID-5 amplification + ffmpeg reads + VM I/O)
- **W = 130ms** — average time each request spent waiting (measured `await`)
- **L = 70** — resulting average queue depth (measured `aqu-sz`)

The drives themselves are fast (<1ms native NVMe latency — confirmed by `await=0.91ms` today with an empty queue). The problem was purely arrival rate outpacing service rate, causing the queue to grow until every new request waited behind 70 others. The VM's small random I/Os got buried at the back of that queue, starving the guest OS until the heartbeat timer expired.

### VirtualBox Heartbeat Log (confirms freeze)

```
1058:43:47 VMMDev: vmmDevHeartbeatFlatlinedTimer: Guest seems to be unresponsive. Last heartbeat received 19 seconds ago
```
This is the last log entry. VirtualBox aborted immediately after.

### RAID Array Status (healthy — not a drive failure)

All 4 drives active, 0 failed, 0 spare. The freeze was purely from I/O queue contention, not a hardware fault.

---

## Current Storage Layout

```
/ → /dev/md0 (RAID-5, 4x NVMe, 5.5TB)
├── /home/sodo/gighive/ansible/roles/cloud_init/files/noble-server-cloudimg-amd64-gighive.vdi  (27 GB)
├── /var/lib/docker/  (all container overlay filesystems)
└── /srv/tusd-data/   (TUS upload staging)
```

`/dev/mmcblk0p2` (eMMC, 57.2GB ext4) is **unformatted and unmounted** — 0% I/O utilization during the entire incident.

---

## Plan

### Prerequisites
- Stop the VM: `VBoxManage controlvm gighive poweroff`
- Verify VM is stopped: `VBoxManage showvminfo gighive | grep State`

### Step 1 — Mount eMMC permanently

```bash
sudo mkdir -p /mnt/emmc
sudo mount /dev/mmcblk0p2 /mnt/emmc
sudo mkdir -p /mnt/emmc/vms
sudo chown sodo:sodo /mnt/emmc/vms

# Add to /etc/fstab for persistence across host reboots
echo "/dev/mmcblk0p2 /mnt/emmc ext4 defaults 0 2" | sudo tee -a /etc/fstab
```

### Step 2 — Move the VDI

```bash
mv /home/sodo/gighive/ansible/roles/cloud_init/files/noble-server-cloudimg-amd64-gighive.vdi \
   /mnt/emmc/vms/noble-server-cloudimg-amd64-gighive.vdi
```

### Step 3 — Re-register VDI location with VirtualBox

```bash
VBoxManage modifymedium disk \
  /mnt/emmc/vms/noble-server-cloudimg-amd64-gighive.vdi \
  --move /mnt/emmc/vms/noble-server-cloudimg-amd64-gighive.vdi
```

Verify VirtualBox sees the new path:
```bash
VBoxManage showmediuminfo disk /mnt/emmc/vms/noble-server-cloudimg-amd64-gighive.vdi
```

### Step 4 — Start the VM

```bash
VBoxManage startvm gighive --type headless
```

Verify it's up:
```bash
VBoxManage showvminfo gighive | grep State
ping 192.168.1.248
```

### Step 5 — Update Ansible cloud_init role path (if applicable)

The VDI path is referenced in the cloud_init Ansible role. After the move, update the path variable in the relevant group_vars or role task so future Ansible runs don't try to write to the old md0 location.

Check the current reference:
```bash
grep -r "noble-server-cloudimg" /home/sodo/gighive/ansible/ --include="*.yml" --include="*.j2"
```

---

## Expected Outcome

After the move, the VM's disk I/O will be fully isolated on eMMC (0% utilized during upload workloads). Upload + AI worker jobs on md0 will no longer contend with VM I/O. The VM will not abort under heavy upload load.

---

## Verification

After moving and starting the VM, run a repeat upload batch and confirm:

```bash
# Monitor eMMC I/O (should show activity) and md0 separately
sar -d 1 10 | grep -E 'mmcblk0|md0'
```

eMMC should show low, steady I/O from the VM. md0 should show only upload/AI workload with no VM I/O competing.
