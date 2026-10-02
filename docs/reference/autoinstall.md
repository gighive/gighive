## Why manual install works but autoinstall breaks

Ubuntu Server 24.04 uses the *live installer* (Subiquity). Manual mode and autoinstall use the **same kernel and initrd**; the difference is that autoinstall depends on **cloud-init successfully finding and parsing a datasource** before Subiquity starts.

Manual install works because Subiquity does not care whether cloud-init succeeds. Autoinstall fails early when:

- The kernel command line includes `autoinstall`, **and**
- cloud-init / casper attempts to enumerate block devices, **and**
- a removable block device reports `ENOMEDIUM`, **and**
- casper treats that error as fatal

This is why manual mode boots fine while autoinstall drops to `init: line 38`.

---

## The real bug

This is not a user-data or GRUB configuration mistake.

The real bug is in **casper’s early device scan logic**:

- casper enumerates `/dev/sd*`
- it attempts to open each device
- if a device exists but reports **No medium** (typical of internal multi-card readers)
- casper aborts instead of skipping the device

This behavior is triggered only when autoinstall is enabled because cloud-init/casper runs earlier and more strictly.

---

## Why initrd surgery happened

Once casper aborts in initramfs, **no kernel parameter can recover**. That’s why attempts such as:

- BIOS "Floppy" mode
- `usb-storage.quirks`
- `fsck.mode=skip`
- `ignore_loglevel`

were ineffective. The abort happens before Subiquity and before any user-space mitigation is possible.

---

## Supported solutions (in order of sanity)

### Option A – GRUB edit + NoCloud seed

Works *only if* casper reaches Subiquity. On hardware with a phantom `/dev/sdc`, this fails before Subiquity.

### Option B – CIDATA seed volume

Using a separate CIDATA volume avoids casper scanning the ISO filesystem for seed data and is the most reliable offline method. It is irritating, but it bypasses the buggy code path.

### Option C – HTTP NoCloud datasource

`ds=nocloud-net;s=http://.../`

This also avoids the failing block-device scan and is clean for repeated installs.

---

## What *actually* fixes the problem

Only two classes of fixes truly resolve the issue:

1. **Hardware/firmware suppression** of the empty reader (when possible)
2. **Patching casper** to ignore ENOMEDIUM instead of aborting

The second is the only universally reliable solution.

---

## Key takeaway

Autoinstall is not broken by configuration; it is broken by a casper bug triggered by removable devices that report `No medium`. Until Canonical fixes casper, any solution must either:

- prevent the device from appearing, or
- bypass the code path that treats it as fatal.

