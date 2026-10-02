---
description: Root-cause analysis of slow and highly variable browser uploads from a MacBook through a USB KVM to labvm.gighive.internal.
---

# Problem: Slow Uploads Caused by ModSecurity Request-Body Spooling to Saturated VirtualBox Storage

**Status:** Root cause proven; remediation not yet implemented  
**Date:** 2026-09-05 through 2026-09-06  
**Environment:** MacBook client, `labvm.gighive.internal` (`192.168.1.252`), VirtualBox Ubuntu VM, Docker `apacheWebServer` container

## Business Summary

A 129.9 GB archive upload continued successfully but progressed very slowly and at a highly variable rate. The receiving server's virtual storage was saturated while its security layer cached the complete upload body to disk. The corrective direction is to avoid full-body disk inspection for the narrowly scoped trusted upload route or provide substantially faster temporary storage.

## Summary

The MacBook uploaded a 129.9 GB archive to the GigHive administrative import workflow at `https://labvm.gighive.internal/admin/admin_system.php`. Activity Monitor showed a varying upload rate and initially raised concern about the MacBook's USB 3.0 KVM network path.

Layer-by-layer diagnostics proved that the KVM Ethernet link and LAN could sustain near line-rate gigabit traffic in both directions. During the real browser upload, Ubuntu reported high I/O wait and `/dev/sda` remained approximately 99–100% utilized with write latency commonly between 400 ms and 1.2 seconds. Process-level evidence then showed Apache writing about 20 MB/s into one growing ModSecurity request-body file under `/var/cache/modsecurity`.

## Impact

- The browser upload continued, but its displayed rate varied significantly.
- The network could deliver approximately 118 MB/s, while the receiving storage sustained only approximately 14–29 MB/s during the captured workload.
- The 129.9 GB archive required a long-running HTTP request.
- ModSecurity temporary storage consumed 18 GB while the upload was still in progress and was expected to grow with the request body.
- With 278 GB free at the time measured, the upload itself had space to continue, but later archive copying or extraction could exhaust the remaining capacity depending on application cleanup order and expanded archive size.

## Symptoms

- macOS Activity Monitor showed Google Chrome Helper as the dominant sender.
- The current aggregate send rate shown in the initial screenshot was approximately 3.2 MB/s.
- The admin page reported `17.5 GB / 129.9 GB uploaded` and continued progressing.
- Ubuntu `top` showed 31.6% I/O wait while the system remained 59.8% idle.
- Apache consumed approximately 65% of one CPU and the kernel flush worker was active.
- Browser upload throughput varied while direct network tests remained stable.

## Diagnostic Decision Tree

1. **Determine whether the Mac used the USB KVM Ethernet interface.** It did: the default route used `en7`, identified as `USB 10/100/1000 LAN`.
2. **Determine whether the Ethernet link was mis-negotiated or reporting physical errors.** It was not: `en7` negotiated 1000baseT full-duplex and reported zero input errors, output errors, and collisions.
3. **Determine whether the local path had packet loss or unstable latency.** It did not during the capture: the gateway returned 0% loss with 1.573 ms average latency, and `labvm` returned 0% loss with 1.702 ms average latency.
4. **Determine whether the LAN path could sustain expected bandwidth.** It could: Mac-to-VM throughput was approximately 940 Mbps and VM-to-Mac throughput was approximately 948 Mbps.
5. **Determine whether the receiver was resource constrained.** It was: Ubuntu showed high I/O wait and `sda` was repeatedly 98.8–100% utilized.
6. **Determine which process generated the writes.** Apache PID 2053 averaged 20,856.17 kB/s writes; MySQL averaged only 13.05 kB/s.
7. **Determine what Apache was writing.** Apache held an open, growing ModSecurity request-body cache file under `/var/cache/modsecurity`; it grew from approximately 11.6 GB to 15.1 GB and then the cache directory measured 18 GB while the upload continued.

## Problems Encountered

### 1. Suspected KVM or USB Ethernet instability

The default network route used `en7`, the USB gigabit LAN interface associated with the KVM path. The interface was active at 1000baseT full-duplex and had no recorded Ethernet errors or collisions.

Gateway testing returned 20 of 20 packets with no loss:

```text
round-trip min/avg/max/stddev = 1.142/1.573/2.165/0.274 ms
```

This evidence did not support a defective or poorly negotiated Ethernet link.

### 2. Tested direct connectivity to `labvm`

DNS resolved `labvm.gighive.internal` to `192.168.1.252`. Traceroute showed it as a direct one-hop LAN destination. The captured ping returned 32 of 32 packets with no loss:

```text
round-trip min/avg/max/stddev = 0.569/1.702/14.856/2.394 ms
```

One 14.856 ms response occurred, but there was no packet loss and the other observed responses were generally near 1–2 ms.

### 3. Tested bandwidth in both directions

The Mac-to-VM test transferred 3.28 GB at approximately 940 Mbps from the sender. It recorded 774 retransmissions, concentrated in a few bursts, but sustained near-gigabit throughput.

An initially labeled reverse test was executed from Ubuntu with `-R`; this caused the remote Mac to send and therefore repeated the Mac-to-VM direction. It averaged 944 Mbps at the receiver and showed one one-second dip to 533 Mbps plus 3,180 sender retransmissions.

The true Ubuntu-to-Mac test omitted `-R`. It transferred 6.62 GB at 948 Mbps from the sender, with only 22 retransmissions over 60 seconds. Per-second throughput remained approximately 897–968 Mbps.

These results proved that the network path could carry near line-rate gigabit traffic in both directions.

### 4. Found storage saturation on the Ubuntu VM

During the real upload, `top` showed 31.6% I/O wait, 59.8% idle CPU, active kernel flushing, and a process in uninterruptible I/O sleep. Memory remained healthy and swap was unused.

`iostat` then showed the following repeated behavior on `sda`:

- Write throughput: approximately 13.8–25.9 MB/s in the displayed one-second samples.
- Write latency: approximately 410–1,163 ms.
- Flush latency: as high as 1,617 ms.
- Average queue size: approximately 10–22 requests.
- Utilization: approximately 98.8–100%.
- CPU I/O wait: approximately 27–48% in the displayed samples.

The device was identified as a 320 GB VirtualBox virtual hard disk. The upload path and root filesystem both resolved to the ext4 filesystem on `/dev/sda1`.

### 5. Identified Apache and ModSecurity as the write source

`pidstat` showed Apache PID 2053 writing between approximately 12 and 29 MB/s, averaging 20,856.17 kB/s. MySQL averaged only 13.05 kB/s and was not a material competing writer in that capture.

`lsof` identified Apache file descriptor 21 as an open ModSecurity request-body cache file:

```text
/var/cache/modsecurity/20260905-191250-apyh8gZa4Pcd_hMXiUVhlgAARRE-request_body-V1bzDI
```

The file was first observed at 11,576,016,896 bytes and later at 15,135,436,800 bytes. The complete ModSecurity cache directory subsequently measured 18 GB while the browser upload continued.

### 6. Confirmed the configuration that caused disk spooling

Apache loaded the shared `security2_module`. Its effective include chain loaded `/etc/modsecurity/modsecurity.conf` and `/etc/modsecurity/crs-setup.conf`.

The active configuration included:

```text
SecRuleEngine On
SecRequestBodyAccess On
SecTmpDir /var/cache/modsecurity
SecDataDir /var/cache/modsecurity
SecRequestBodyLimit 161061273600
SecRequestBodyNoFilesLimit 161061273600
SecRequestBodyInMemoryLimit 1048576
SecAuditEngine RelevantOnly
```

The request-body limits were 150 GiB, while the in-memory limit was only 1 MiB. The observed growing request-body file proved that ModSecurity spooled the large upload body to `/var/cache/modsecurity` on the saturated virtual disk.

The diagnostics also found that `/etc/modsecurity/modsecurity.conf` was included by both `/etc/apache2/mods-enabled/security2.conf` and line 96 of `/etc/apache2/apache2.conf`. Investigation or correction of that duplicate include was explicitly deferred by the user and was not required to prove the storage bottleneck.

## Root Cause

The proven root cause of the slow, variable browser upload was **ModSecurity spooling the large HTTP request body to `/var/cache/modsecurity` on the saturated VirtualBox system disk**.

The causal evidence is direct:

1. The LAN sustained approximately 940–948 Mbps in both directions.
2. The virtual disk stayed approximately 99–100% utilized with write latency reaching roughly one second.
3. Apache's measured write rate aligned with the slow upload rate.
4. Apache held a single growing ModSecurity request-body cache file for the active upload.
5. ModSecurity was configured to inspect request bodies up to 150 GiB, keep only 1 MiB in memory, and place temporary data on the affected disk.

This document does not claim which physical host-storage component caused the VirtualBox disk's poor latency; no host-side storage evidence was collected in this session.

## Resolution

No configuration or code change was implemented during this diagnostic session. The user elected to let the active upload continue and deferred cleanup of the duplicate ModSecurity include.

The evidence supports these remediation directions for a separate reviewed and approved change:

1. Narrowly exempt the authenticated large-archive upload route from ModSecurity request-body inspection, subject to a security review of the exact method, path, authentication, and source restrictions.
2. Alternatively, retain inspection but place `SecTmpDir` and related temporary data on substantially faster storage sized for the configured maximum request body.
3. Consider a chunked upload design so the security layer and application do not handle a single request body approaching 130 GB.
4. Investigate the VirtualBox host's datastore, snapshot chain, controller type, cache settings, and concurrent host I/O before changing storage configuration.

Moving a request of this size to a RAM-backed filesystem is unsuitable for the observed VM because it had 8 GB RAM while the request body already exceeded 15 GB.

## Verification

The diagnosis was verified by independent measurements at each layer:

- Interface state and counters cleared the Mac USB Ethernet link.
- Gateway and endpoint pings showed no packet loss in the captured samples.
- `iperf3` demonstrated near-gigabit TCP throughput in both directions.
- `top` and `iostat` demonstrated I/O wait, long write latency, deep queues, and full virtual-disk utilization during the browser upload.
- `pidstat` attributed the significant writes to Apache.
- `lsof` identified the exact growing ModSecurity request-body cache file.
- Apache and ModSecurity configuration values explained why a large request body was written to that location.

No remediation verification was performed because no fix was implemented.

## Tests

No product functionality or configuration was changed in this session, so no automated test was added. A future remediation should include a permanent upload-flow test in the `upload_tests` Ansible role that verifies the chosen behavior for large authenticated archive uploads without requiring a production-sized payload.

## Preventative Actions

- Add monitoring for virtual-disk write latency, queue depth, utilization, and free space during imports.
- Monitor `/var/cache/modsecurity` for unexpectedly large or orphaned request-body files.
- Define a documented maximum supported archive size and corresponding temporary-space requirement.
- Ensure future large-upload designs account for security-layer spooling, application staging, final archive storage, and extraction space.
- Add a targeted upload test when remediation is designed and approved.
- Review the duplicate `modsecurity.conf` include separately, as deferred by the user.

## Assumptions and Limits

- Results apply to the captured `labvm.gighive.internal` environment and active upload workload.
- The network tests prove available TCP bandwidth during their test windows; they do not prove that every future packet will be loss-free.
- The VirtualBox guest identified `sda` as rotational, but that flag alone does not establish the physical medium used by the host.
- The archive's expanded size and the application's exact cleanup/copy order were not measured, so post-upload capacity risk was identified but not quantified as a certainty.

## Commands Executed During the Session

The following commands are listed in the order they were shown as executed. Commands that were only proposed but for which no execution output was supplied are omitted.

1. Identify the Mac's default-route interface:

   ```bash
   route -n get default | grep interface
   ```

2. List macOS hardware ports and interface mappings:

   ```bash
   networksetup -listallhardwareports
   ```

3. Inspect the USB Ethernet link state and negotiated media:

   ```bash
   ifconfig en7 | grep -E "status:|media:"
   ```

4. Read interface counters for `en7`:

   ```bash
   netstat -ibnI en7
   ```

5. Resolve the default gateway and ping it; the run was interrupted after 20 packets:

   ```bash
   GW=$(route -n get default | awk '/gateway:/{print $2}')
   ping -c 100 "$GW"
   ```

6. Resolve `labvm.gighive.internal`:

   ```bash
   dig +short labvm.gighive.internal
   ```

7. Ping `labvm`; the run was interrupted after 32 packets:

   ```bash
   ping -c 100 labvm.gighive.internal
   ```

8. Trace the route from the Mac to `labvm`:

   ```bash
   traceroute labvm.gighive.internal
   ```

9. Test Mac-to-VM TCP bandwidth:

   ```bash
   iperf3 -c labvm.gighive.internal -t 30 -i 1
   ```

10. Run an Ubuntu-side `-R` test, which also tested Mac-to-VM transfer because the remote Mac became the sender:

    ```bash
    iperf3 -c 192.168.1.210 -R -t 30 -i 1
    ```

11. Test the true Ubuntu-to-Mac direction:

    ```bash
    iperf3 -c 192.168.1.210 -t 60 -i 1
    ```

12. Observe Ubuntu CPU, process, memory, and I/O-wait state:

    ```bash
    top
    ```

13. Capture extended per-device I/O statistics; the run was interrupted before all 20 requested samples:

    ```bash
    iostat -xz 1 20
    ```

14. Identify block devices, filesystems, rotational flags, models, and mount points:

    ```bash
    lsblk -o NAME,TYPE,SIZE,FSTYPE,ROTA,MODEL,MOUNTPOINTS
    ```

15. Resolve the filesystem containing the Ubuntu home path:

    ```bash
    findmnt -T /home/ubuntu/
    ```

16. Read the kernel's rotational classification for `sda`:

    ```bash
    cat /sys/block/sda/queue/rotational
    ```

17. Read the active I/O scheduler for `sda`:

    ```bash
    cat /sys/block/sda/queue/scheduler
    ```

18. Attribute disk I/O to processes; the run was interrupted after several samples:

    ```bash
    sudo pidstat -d 1 30
    ```

19. Enter the Apache Docker container:

    ```bash
    docker exec -it apacheWebServer bash
    ```

20. Search the initially expected Apache and ModSecurity paths for request-body and audit directives; this invocation returned no matches:

    ```bash
    sudo grep -RniE \
    'SecRequestBodyAccess|SecRequestBodyLimit|SecRequestBodyInMemoryLimit|SecTmpDir|SecDataDir|SecAuditEngine' \
    /etc/modsecurity /etc/apache2 2>/dev/null
    ```

21. Attempt to measure ModSecurity cache usage with `sudo`; the container did not contain `sudo`:

    ```bash
    sudo du -sh /var/cache/modsecurity
    ```

22. Measure the ModSecurity cache as the container's root user:

    ```bash
    du -sh /var/cache/modsecurity
    ```

23. List the largest ModSecurity cache files:

    ```bash
    find /var/cache/modsecurity -type f -printf '%s %p\n' |
      sort -rn | head
    ```

24. Confirm that Apache loaded ModSecurity:

    ```bash
    apache2ctl -M | grep -i security
    ```

25. Dump Apache's effective include chain:

    ```bash
    apache2ctl -t -D DUMP_INCLUDES
    ```

26. Display Apache's configuration root and primary configuration filename:

    ```bash
    apache2ctl -V | grep -E 'SERVER_CONFIG_FILE|HTTPD_ROOT'
    ```

27. Display the configured request-body, temporary-data, and audit directives:

    ```bash
    grep -nE \
    '^[[:space:]]*(SecRuleEngine|SecRequestBodyAccess|SecRequestBodyLimit|SecRequestBodyNoFilesLimit|SecRequestBodyInMemoryLimit|SecTmpDir|SecDataDir|SecAuditEngine)' \
    /etc/modsecurity/modsecurity.conf
    ```

28. Display the ModSecurity include in the enabled module configuration:

    ```bash
    nl -ba /etc/apache2/mods-enabled/security2.conf
    ```

29. Display the second ModSecurity include in Apache's primary configuration:

    ```bash
    nl -ba /etc/apache2/apache2.conf | sed -n '88,102p'
    ```

30. Check root-filesystem capacity and remeasure the ModSecurity cache:

    ```bash
    df -h /
    du -sh /var/cache/modsecurity
    ```
