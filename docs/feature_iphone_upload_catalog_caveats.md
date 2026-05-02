# iPhone Bulk Import — Caveats & Known Limitations

Date: 2026-05-02

See also: [feature_iphone_upload_catalog.md](feature_iphone_upload_catalog.md)

---

## iCloud Storage Optimization (Most Common Issue)

### What happens

When **"Optimize iPhone Storage"** is enabled, iOS frees up local storage by replacing full-resolution originals in the DCIM folder with **small low-resolution proxy files**. The originals are stored in iCloud.

The staging tools (`pymobiledevice3 afc pull`, `robocopy`) access the iPhone's **raw local DCIM filesystem over USB** — they have no iCloud access. When you run the staging commands, the proxies copy successfully with no errors or warnings. The result is a `_host_iphone` folder full of files that appear correct but are the wrong data.

**Where to find this setting:**
- iOS 17 and earlier: **Settings → Photos → iPhone Storage**
- iOS 18 and later: **Settings → Apps → Photos → iPhone Storage**

The setting will show either **"Optimize iPhone Storage"** (⚠️ originals are in iCloud) or **"Download and Keep Originals"** (✓ full files on device).

### GigHive auto-detects proxies

The **Detect Staged Files** button in Step 2 automatically samples up to 10 video files using `ffprobe` and warns you if any appear to be low-resolution proxies before you start the import. You can dismiss the warning and proceed anyway, but the warning will persist into Step 3.

### How to spot proxy files manually

Proxy video files are dramatically smaller than originals:

| Scenario | Expected size | Proxy size |
|---|---|---|
| 1-min 4K iPhone video | 500 MB – 1 GB | 5 – 50 MB |
| 1-min 1080p iPhone video | 150 – 300 MB | 2 – 15 MB |

If all your staged files are unexpectedly small, you have proxies.

### How to fix it before staging

1. On the iPhone: navigate to the Photos settings (see path above for your iOS version)
2. Switch to **"Download and Keep Originals"**
3. Connect to **Wi-Fi + power** and wait — iOS will download all iCloud originals back to the device
4. The Photos app shows a progress indicator at the bottom; wait for it to complete
5. For large libraries (100+ GB) this can take **hours to days** depending on connection speed
6. **Verify the setting stuck** — re-open Photos settings and confirm it still shows "Download and Keep Originals" before staging
7. Proceed with USB staging (`pymobiledevice3 afc pull`)

> **Re-running after a previous partial import:** If you already ran an import with proxies or a subset of files, just re-run the `afc pull` and import wizard — duplicate checksums are automatically skipped, so only new full-resolution originals will be added.

> **Tip:** Check **Settings → General → iPhone Storage → Photos** to see how much iCloud storage is being used vs. on-device. If "iCloud Photos" shows a large number and "On This iPhone" shows a small number, most of your originals are in iCloud.

### After staging is complete

You can switch back to "Optimize iPhone Storage" after staging finishes — the staged files in `_host_iphone` are already on the GigHive server and are unaffected by any subsequent iPhone setting changes.

---

## iPhone Trust Pairing

The **"Trust This Computer?"** prompt on the iPhone is per-device pairing. It must be tapped on the iPhone screen itself:

- Cannot be triggered remotely
- Must be repeated if the iPhone was factory reset or if the pairing was revoked
- On some iOS versions, the iPhone must be unlocked before the prompt appears
- If the prompt does not appear, unplug and replug the USB cable with the iPhone unlocked

---

## Large Libraries and Staging Time

Copying a large library over USB takes significant time:

| Library size | USB 2.0 (~25 MB/s) | USB 3.0 (~100 MB/s) |
|---|---|---|
| 10 GB | ~7 min | ~2 min |
| 50 GB | ~33 min | ~8 min |
| 200 GB | ~2 hr 20 min | ~33 min |

- The `rsync` command is **resumable** — if interrupted, re-run the same command and it will skip already-copied files
- `robocopy` on Windows is also resumable with the `/Z` flag (add to the command)
- Staging uses the `_host_iphone` folder inside the bundle directory — ensure the host machine has enough free disk space before starting

---

## macOS Tooling — pymobiledevice3

GigHive uses `pymobiledevice3` (installed via `pipx`) for macOS staging. This is a pure Python implementation of the Apple AFC protocol — **no kernel extension or system restart required**.

Known issues:

- After a macOS major version upgrade, re-run `pipx install --upgrade pymobiledevice3` if pairing stops working
- If `pymobiledevice3 lockdown pair` fails, unplug and replug the iPhone with it unlocked and try again
- `pymobiledevice3 afc pull` shows per-directory file count progress but **does not show bytes transferred** — use `du -sh _host_iphone/` after the pull to check total size
- The pull is **not resumable** — if interrupted, re-run the full `afc pull` command; files already present will be overwritten but the import wizard's duplicate checksum detection will skip them in the database

---

## HEVC / HEIC Compatibility

Newer iPhones (XS and later) record video in **HEVC (H.265)** by default. GigHive ingests these files without modification. However:

- Browser playback of HEVC depends on the **client's codec support** — Chrome on Windows does not play HEVC natively; Safari and Edge do
- No transcoding is in scope for this feature
- Users can force iPhone to record in H.264 via **Settings → Camera → Formats → Most Compatible** before recording

---

## Live Photos

Live Photos generate a companion `.mov` file (a short 3-second clip) alongside the `.jpg`. The staging commands (`rsync`/`robocopy`) include `*.mov` and will copy these clips. They are typically 1–3 MB each. No filtering is in scope — they will be ingested as audio/video assets.

---

## Azure

Not supported. Azure VMs have no physical USB ports. This feature is OSB-only.

---

## Windows — iTunes Required

Windows requires **iTunes** (or **Apple Devices** from the Microsoft Store) to be installed for the iPhone USB driver. Without it, the iPhone will not be recognized when plugged in.

- iTunes from the Microsoft Store and iTunes from apple.com both provide the required driver
- The driver is installed automatically as part of the iTunes installation
- No Docker Desktop file-sharing configuration is needed — the bundle directory (`gighive-one-shot-bundle`) is already shared to run `docker compose up`

---

## Remote Access Limitation

The host-side staging commands (Steps 1–2) must be run **directly on the GigHive server machine** — either at a local terminal or via SSH from the same network. There is no mechanism to trigger host-side USB operations remotely through the GigHive admin UI.
