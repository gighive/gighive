# iPhone Bulk Import (USB Catalog Ingestion)

Date: 2026-05-02

---

## Deployment Applicability

**Current scope: One-Shot-Bundle (OSB) only.**

| Deployment | Host OS | Status |
|---|---|---|
| One-shot-bundle (OSB) | Linux | Primary path — fully supported |
| One-shot-bundle (OSB) | macOS | Supported with differences — see [Mac section](#mac-host-differences) |
| One-shot-bundle (OSB) | Windows | Supported — uses iTunes driver + robocopy instead of libimobiledevice |
| Azure VM | Any | **Not supported.** Azure VMs have no physical USB ports. |

---

## Why This Feature Exists

Video files from an iPhone are large (often 500 MB–4 GB each), and the existing browser-based bulk import (Sections A/B of the admin import page) requires the user to select a folder from their **browser's local machine**. When recording at a live event, all the video is on the iPhone — the user's laptop may not have a copy. Plugging the iPhone directly into the GigHive server and importing without an intermediate copy-to-laptop step is the most direct path.

---

## Architecture

### Linux OSB (bare metal)

```
iPhone (USB Lightning or USB-C)
    │
    ▼
Host Linux OS
    ├── usbmuxd (USB multiplexer daemon — detects device)
    ├── idevicepair pair  (one-time trust pairing)
    └── ifuse /mnt/iphone-dcim  (FUSE mount of iPhone DCIM)
            │
            ▼ rsync
    gighive-one-shot-bundle/_host_iphone/   ← bundle-local staging (user-owned)
            │
            ▼ Docker bind mount (./_host_iphone:/var/iphone-import)
    /var/iphone-import/                     ← container-visible path
            │
            ▼
    Section D admin UI
    → PHP server-side scan + hash
    → Existing DB import pipeline (no TUS upload — files already on server)
    → Media moved to /srv/tusd-data/data/  (or equivalent asset store)
```

### macOS OSB (Docker Desktop) — see also [Mac section](#mac-host-differences)

```
iPhone (USB)
    │
    ▼ macFUSE + Homebrew libimobiledevice + ifuse
macOS host — rsync to gighive-one-shot-bundle/_host_iphone/
    │
    ▼ Docker Desktop VirtioFS (bundle dir is within ~, covered automatically)
Container sees _host_iphone as bind-mounted /var/iphone-import
    │
    ▼ (same from here)
```

### Windows OSB (Docker Desktop)

```
iPhone (USB)
    │
    ▼ iTunes / Apple Devices (provides USB driver)
Windows host — robocopy to gighive-one-shot-bundle\_host_iphone\
    │
    ▼ Docker Desktop file sharing (bundle dir already shared to run docker compose)
Container sees _host_iphone as bind-mounted /var/iphone-import
    │
    ▼ (same from here)
```

---

## What Needs to Be Built

### 1. Ansible / Docker Compose — staging directory bind mount

The staging directory lives **inside the bundle directory** — same pattern as `_host_audio` and `_host_video`. The **container-side path** is always `/var/iphone-import`. The **host-side path** is always `./_host_iphone` relative to the bundle root — identical on Linux, macOS, and Windows.

Add to `docker-compose.yml` (OSB static file):

```yaml
volumes:
  - ${IPHONE_STAGING_DIR:-./_host_iphone}:/var/iphone-import
```

Add to `install.sh` alongside the existing audio/video dir creation (line 129):

```bash
IPHONE_STAGING_DIR="${IPHONE_STAGING_DIR:-./_host_iphone}"
mkdir -p "$AUDIO_DIR" "$VIDEO_DIR" "$IPHONE_STAGING_DIR"
```

`install.sh` creates `_host_iphone` before `docker compose up`, so it is owned by the current user — no permission issues. No OS detection needed. No system-level directories touched.

### 2. New PHP endpoint — `admin/iphone_import_status.php`

Checks the staging bind mount and host prerequisites. Returns:
- Whether `/var/iphone-import` exists and is writable (bind mount working)
- Whether `/var/iphone-import/.prerequisites_ok` sentinel file is present (host libraries confirmed installed)
- Count and total size of media files found (video/audio extensions only)
- List of filenames (for the review step in the UI)

The sentinel file approach is necessary because PHP runs inside Docker and cannot inspect the host's package manager directly. Writing `.prerequisites_ok` into the staging directory from the host is the contract between host setup and container status check.

### 3. New PHP endpoint — `admin/iphone_import_server_scan.php`

Unlike Sections A/B (which hash files client-side in the browser), files are already on the server here. This endpoint:
- Walks `/var/iphone-import` recursively
- Hashes each media file server-side (SHA-256)
- Submits the manifest to the existing `import_manifest_prepare.php` + `import_manifest_finalize.php` pipeline
- Skips the TUS upload step — instead moves/copies files directly from `/var/iphone-import` to the asset store
- Returns a `job_id` for status polling via the existing `import_manifest_status.php`

### 4. Stop — reuse existing `admin/import_manifest_cancel.php`

The existing `import_manifest_cancel.php` already handles job cancellation for the manifest pipeline. Since the iPhone import feeds into the same pipeline, the Stop button calls this existing endpoint — no new file needed.

### 5. New Section D in `admin/admin_database_load_import_media_from_folder.php`

A guided 4-step wizard panel added below Section C, matching the existing dark card style. See [Section D UI Design](#section-d-ui-design) below.

---

## Section D UI Design

### Step 1 — Host Prerequisites *(one-time setup)*

Explanation: "These commands must be run on the GigHive host machine (not inside a Docker container). You only need to do this once."

> The `_host_iphone` staging folder is created automatically by `install.sh` inside your `gighive-one-shot-bundle` directory. Run the commands below from that directory.

**Linux:**
```bash
# Install tools:
sudo apt-get install -y libimobiledevice-utils ifuse usbutils
# Signal GigHive (run from gighive-one-shot-bundle/):
ideviceinfo --version >/dev/null 2>&1 && ifuse --version >/dev/null 2>&1 \
  && touch _host_iphone/.prerequisites_ok \
  && echo "Prerequisites confirmed" || echo "ERROR: one or more tools not found"
```

**macOS:**
```bash
# Install tools:
brew install libimobiledevice ifuse
brew install --cask macfuse
# Signal GigHive (run from gighive-one-shot-bundle/):
ideviceinfo --version >/dev/null 2>&1 && ifuse --version >/dev/null 2>&1 \
  && touch _host_iphone/.prerequisites_ok \
  && echo "Prerequisites confirmed" || echo "ERROR: one or more tools not found"
```

**Windows** *(PowerShell — run from the gighive-one-shot-bundle directory):*
```powershell
# iTunes must be installed — download from https://www.apple.com/itunes/ or the Microsoft Store.
# Signal GigHive:
New-Item -ItemType File -Force -Path "_host_iphone\.prerequisites_ok" | Out-Null
Write-Host "Prerequisites confirmed"
```

Button: **Check Ready** → calls `iphone_import_status.php`, which checks **both** (a) whether the staging bind mount is visible to the container and (b) whether the `.prerequisites_ok` sentinel file is present.

The UI reports each check independently:
- **✓ Staging directory accessible** / **✗ Staging directory not found** — bind mount working?
- **✓ Host prerequisites confirmed** / **✗ Prerequisites not confirmed** — were the install commands run and the sentinel written?

PHP cannot directly inspect host packages (`dpkg`, `brew`) because it runs inside Docker. The sentinel file written into the shared staging directory is the contract between host setup and container status check.

---

### Step 2 — Connect & Stage Files

Numbered instructions displayed in the UI:

1. Plug your iPhone into a USB port on the GigHive server
2. On your iPhone, tap **Trust** when prompted (one-time per computer)
3. Run on the host:

**Linux:**
```bash
idevicepair pair
mkdir -p /mnt/iphone-dcim
ifuse /mnt/iphone-dcim
rsync -av --include="*.mp4" --include="*.mov" --include="*.mp3" \
  --include="*.m4v" --include="*.m4a" --exclude="*" \
  /mnt/iphone-dcim/DCIM/ ~/gighive-one-shot-bundle/_host_iphone/
fusermount -u /mnt/iphone-dcim
```

**macOS:**
```bash
idevicepair pair
mkdir -p ~/iphone-dcim
ifuse ~/iphone-dcim
rsync -av --include="*.mp4" --include="*.mov" --include="*.mp3" \
  --include="*.m4v" --include="*.m4a" --exclude="*" \
  ~/iphone-dcim/DCIM/ ~/gighive-one-shot-bundle/_host_iphone/
umount ~/iphone-dcim
```

**Windows** *(PowerShell):*
```powershell
# iPhone appears in File Explorer after tapping Trust.
robocopy "\\Apple iPhone\Internal Storage\DCIM" `
  "$env:USERPROFILE\gighive-one-shot-bundle\_host_iphone" `
  *.mp4 *.mov *.mp3 *.m4v *.m4a /S /NFL /NDL
```

Button: **Detect Staged Files** → polls `iphone_import_status.php` for file count.

---

### Step 3 — Review

Displays:
- `X video files, Y audio files detected (Z GB total)`
- Non-destructive add mode only (mirrors Section B — duplicate checksums skipped)

Button: **Start iPhone Import**

---

### Step 4 — Progress

- Triggers `iphone_import_server_scan.php` (server-side hash + manifest + direct file move)
- Same step/status polling as Sections A/B via `import_manifest_status.php`
- Shows per-file progress rows

Buttons during import:
- **Stop** — POSTs `{ job_id }` to the existing `import_manifest_cancel.php`; server finishes the current file then halts; button disables itself and shows "Cancellation requested…". Mirrors the `sectionStop()` pattern from Sections A/B.
- **Refresh Log** — manually re-polls `import_manifest_status.php` for the latest step/file state

Buttons on completion:
- **View Database →** — link to `/db/database.php?view=librarian`
- **Clear Staging Folder** — prompts confirmation, then deletes all media files from `/var/iphone-import/` to free disk space; the `.prerequisites_ok` sentinel is explicitly preserved so Step 1 does not need to be re-run. Uses `find /var/iphone-import -maxdepth 1 ! -name '.prerequisites_ok' -delete` internally.

---

## Mac Host Differences

### One-Shot-Bundle on macOS

**Key constraint:** Docker Desktop for Mac does **not** support USB passthrough to containers. The workaround — mounting the iPhone on the Mac host and rsyncing to `_host_iphone` — works naturally with the bundle-relative staging approach:

1. Mount the iPhone on the **Mac host** using `ifuse` (requires macFUSE + Homebrew `libimobiledevice`)
2. rsync to `~/gighive-one-shot-bundle/_host_iphone/` — user-owned, no sudo needed
3. Docker Desktop's VirtioFS shares the entire `~` directory — `_host_iphone` is covered automatically, no extra file-sharing configuration required

**macFUSE note:** macFUSE requires a kernel extension. On macOS Ventura and later, this may require a restart and approval in **System Settings → Privacy & Security** after first install.


---

## File Change Summary

| File | Change |
|---|---|
| `gighive-one-shot-bundle/docker-compose.yml` | Add `./_host_iphone:/var/iphone-import` bind mount |
| `gighive-one-shot-bundle/install.sh` | Add `IPHONE_STAGING_DIR` + `mkdir -p` alongside audio/video dirs |
| `admin/iphone_import_status.php` | New — checks staging dir + `.prerequisites_ok` sentinel, returns file count |
| `admin/iphone_import_server_scan.php` | New — async server-side hash, manifest submit, direct file move |
| `admin/import_manifest_cancel.php` | Existing — reused for Stop button, no changes needed |
| `admin/admin_database_load_import_media_from_folder.php` | Add Section D button linking to new iPhone import page |
| `admin/admin_database_load_import_media_from_iphone.php` | New — full 4-step wizard page |

---

## Known Limitations

- **Azure:** Not supported. No USB ports on cloud VMs.
- **Remote access:** The host-side mount commands (Steps 1–2) must be run directly on the GigHive server machine, either via SSH or a local terminal. There is no remote-trigger mechanism in scope.
- **iPhone trust:** The "Trust This Computer" pairing is per-computer. If the iPhone has never been paired with this machine, the user must tap Trust on the iPhone screen — it cannot be done remotely.
- **Large libraries:** Phones with thousands of videos (200+ GB) will take significant time to copy to staging. The `rsync` command is resumable if interrupted.
- **HEVC/HEIC:** `.mov` files from newer iPhones are often HEVC (H.265). GigHive will ingest them; browser playback depends on the client's codec support. No transcoding is in scope for this feature.
- **Live Photos:** `.mov` files accompanying Live Photos (short 3-second clips) will be included in the rsync. These are typically very small. No filtering is in scope.
- **macFUSE stability:** macFUSE is a third-party kernel extension and has historically had stability issues on macOS major version upgrades. If mounting fails after a macOS update, reinstall macFUSE.
- **Windows — iTunes required:** Windows uses iTunes (or Apple Devices from the Microsoft Store) as the iPhone USB driver. File staging uses `robocopy` instead of `rsync` (see Step 2). No Docker Desktop file-sharing configuration is needed — the bundle directory is already shared in order to run `docker compose up`.
- **Cleanup:** To fully remove GigHive including all staged iPhone files, run `docker compose down -v` then `rm -rf gighive-one-shot-bundle`. The `_host_iphone` folder is removed as part of the bundle directory.

---

## Out of Scope (Deferred)

- **Automatic USB detection / udev trigger** — auto-mount on plug-in without manual host commands
- **Wireless import** — Wi-Fi based iPhone sync (requires iOS app changes; see separate discussion)
- **Progress on the iPhone screen** — no GigHive iOS app changes are needed for this feature
- **Selective import** — choosing specific albums or date ranges before staging (currently all DCIM media is staged)
- **HEVC transcoding** — converting to H.264 for broader browser compatibility

---

## Roadmap / Future Enhancements

- **Selective staging UI** — show iPhone album list before rsync, let user pick which albums to include
- **udev auto-mount** (Linux OSB) — automatically detect iPhone plug-in and trigger staging without SSH
- **GigHive iOS app "Bulk Upload" section** — trigger Wi-Fi based upload from within the app (separate feature; requires PhotoKit integration, no USB required)
