## Quickstart (3 steps)

1) In the GigHive admin page (`admin.php`), use **Section 6: Write Disk Resize Request (Optional)** to create a disk resize request.

2) On the VirtualBox host, run a dry-run to confirm what would happen:

```bash
./ansible/tools/run_resize_request.sh -i ansible/inventories/inventory_gighive2.yml --request-host gighive2 --latest --dry-run
```

3) If the dry-run output looks correct, run the real resize:

```bash
./ansible/tools/run_resize_request.sh -i ansible/inventories/inventory_gighive2.yml --request-host gighive2 --latest
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
