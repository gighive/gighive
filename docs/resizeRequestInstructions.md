## Resize Request Instructions (3 steps)

1) In the GigHive admin page (`admin.php`), use **Section 6: Write Disk Resize Request (Optional)** to create a disk resize request.
<img src="/images/diskResizeRequest.png" alt="Database Import Process" style="height: 400px; width: auto;">

2) From the Ansible controller machine, run a dry-run to confirm what would happen:

```bash
cd $GIGHIVE_HOME
./ansible/roles/docker/files/apache/webroot/tools/run_resize_request.sh -i ansible/inventories/inventory_gighive2.yml --request-host gighive2 --latest --dry-run
```

You'll get output that looks like this:
```bash
sodo@pop-os:~/scripts/gighive$ ~/scripts/gighive/ansible/roles/docker/files/apache/webroot/tools/run_resize_request.sh -i ~/scripts/gighive/ansible/inventories/inventory_gighive.yml --request-host gighive --latest --dry-run
Request: /tmp/gighive-resize-req.1TBfXU.json
RequestId: req-20251231-133316-3403f6e20a07.json
Host:    gighive2
SizeMB:  131072
SizeGiB: 128
SizeGB:  131
VDI:     /home/sodo/scripts/gighive/ansible/roles/cloud_init/files/noble-server-cloudimg-amd64-gighive2.vdi
VDI_MB:  64000
DRY RUN: would execute:
  ansible-playbook  -i  /home/sodo/scripts/gighive/ansible/inventories/inventory_gighive2.yml  ansible/playbooks/resize_vdi.yml  --limit  gighive2  -e  disk_size_mb=131072
  ssh  ubuntu@gighive2  sudo\ growpart\ /dev/sda\ 1\ \&\&\ sudo\ resize2fs\ /dev/sda1

--- Guest disk/filesystem summary ---
Filesystem      Size  Used Avail Use% Mounted on
/dev/sda1        60G   59G  1.1G  99% /

NAME    MAJ:MIN RM  SIZE RO TYPE MOUNTPOINTS
sda       8:0    0 62.5G  0 disk 
├─sda1    8:1    0 61.5G  0 part /
├─sda14   8:14   0    4M  0 part 
├─sda15   8:15   0  106M  0 part /boot/efi
└─sda16 259:0    0  913M  0 part /boot
sr0      11:0    1  368K  0 rom  
```

3) If the dry-run output looks correct, run the real resize:

```bash
cd $GIGHIVE_HOME
./ansible/roles/docker/files/apache/webroot/tools/run_resize_request.sh -i ansible/inventories/inventory_gighive2.yml --request-host gighive2 --latest
```

At the end of the Ansible script run, you'll get output that looks like this showing the resized partition and filesystem.  Note that the size of the virtual disc (normally sda) should be increased to the size you specified and you'll have more available disk:
```bash
Playbook run took 0 days, 0 hours, 0 minutes, 26 seconds
Wednesday 31 December 2025  13:43:26 -0500 (0:00:24.203)       0:00:26.289 **** 
=============================================================================== 
Wait for SSH availability -------------------------------------------------------------------------------------- 24.20s
Power off VM if running ----------------------------------------------------------------------------------------- 0.56s
Start VM headless (if powered off) ------------------------------------------------------------------------------ 0.40s
Gather VM info (machinereadable) -------------------------------------------------------------------------------- 0.20s
Attach VDI disk ------------------------------------------------------------------------------------------------- 0.16s
Wait for VM to reach poweroff state ----------------------------------------------------------------------------- 0.15s
Resize VDI disk (byte-precise) ---------------------------------------------------------------------------------- 0.15s
Gather VM info (machinereadable) after resize ------------------------------------------------------------------- 0.14s
Check if disk is attached at SATA Controller port 0 ------------------------------------------------------------- 0.13s
Wait until VM session is unlocked (SessionName == "") ----------------------------------------------------------- 0.13s
Validate required variables are present ------------------------------------------------------------------------- 0.02s
Power off VM and wait for unlock -------------------------------------------------------------------------------- 0.02s
Compute target disk size in bytes ------------------------------------------------------------------------------- 0.01s
Set controller-side VirtualBox env vars ------------------------------------------------------------------------- 0.01s
Detach VDI before resizing (only if attached) ------------------------------------------------------------------- 0.01s
Set root_dir used by included poweroff task (controller HOME) --------------------------------------------------- 0.01s
CHANGED: partition=1 start=2099200 old: size=128972767 end=131071966 new: size=266336223 end=268435422
resize2fs 1.47.0 (5-Feb-2023)
Filesystem at /dev/sda1 is mounted on /; on-line resizing required
old_desc_blocks = 8, new_desc_blocks = 16
The filesystem on /dev/sda1 is now 33292027 (4k) blocks long.


--- Guest disk/filesystem summary ---
Filesystem      Size  Used Avail Use% Mounted on
/dev/sda1       123G   59G   65G  48% /

NAME    MAJ:MIN RM  SIZE RO TYPE MOUNTPOINTS
sda       8:0    0  128G  0 disk 
├─sda1    8:1    0  127G  0 part /
├─sda14   8:14   0    4M  0 part 
├─sda15   8:15   0  106M  0 part /boot/efi
└─sda16 259:0    0  913M  0 part /boot
sr0      11:0    1  368K  0 rom  
```

## What the runner does (less than 10 steps)
 
 1) Parse CLI args (`-i <inventory_file>`, optional `--request-host`, `--request-dir`, `--latest`).
 2) Validate the inventory file exists.
 3) Decide where to read the request JSON from:
    - local file path, or
    - remote VM via `--request-host` (either `--latest` or a specific filename).
 4) If remote mode is used, SSH to the VM and `cat` the request JSON into a local temp file.
 5) Ensure `jq` is installed.
 6) Extract `inventory_host` and `disk_size_mb` from the request JSON.
 7) Validate extracted values.
 8) Run `ansible-playbook` to perform the VirtualBox disk resize (`disk_size_mb=...`, `--skip-tags vbox_provision`).
 9) SSH into the guest VM and run `sudo growpart /dev/sda 1 && sudo resize2fs /dev/sda1`.
 10) Cleanup temp file and exit.
