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
    ▼ macFUSE + Homebrew libimobiledevice + gromgit/fuse/ifuse-mac
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

Add to `ansible/roles/docker/files/one_shot_bundle/docker-compose.yml` under `apacheWebServer` volumes, after the `_host_video` line:

```yaml
      - "${GIGHIVE_IPHONE_DIR:-./_host_iphone}:/var/iphone-import"
```

Add to `ansible/roles/docker/templates/install.sh.j2` (Linux + macOS) — declare and export near the top alongside `AUDIO_DIR`/`VIDEO_DIR`:

```bash
export GIGHIVE_IPHONE_DIR="${GIGHIVE_IPHONE_DIR:-./_host_iphone}"
```

Add to the existing `mkdir -p` line:

```bash
mkdir -p "$AUDIO_DIR" "$VIDEO_DIR" "$GIGHIVE_IPHONE_DIR"
```

Add to `ansible/roles/docker/templates/install.ps1.j2` (Windows) — alongside existing `_host_audio`/`_host_video` directory creation:

```powershell
New-Item -ItemType Directory -Force -Path "_host_iphone" | Out-Null
```

The installer templates (`install.sh.j2` on Linux/macOS, `install.ps1.j2` on Windows) create `_host_iphone` before `docker compose up`, so it is owned by the current user — no permission issues. No OS detection needed. No system-level directories touched.

### 2. New PHP endpoint — `admin/iphone_import_status.php`

Checks the staging bind mount and host prerequisites. Returns:
- Whether `/var/iphone-import` exists and is writable (bind mount working)
- Whether `/var/iphone-import/.prerequisites_ok` sentinel file is present (host libraries confirmed installed)
- Count and total size of media files found (video/audio extensions only)
- List of filenames (for the review step in the UI)
- **Proxy detection:** samples up to 10 video files via `ffprobe` and checks resolution; flags a warning if any sampled file has height < 480px (indicating an iCloud optimized proxy rather than a full-resolution original)

The sentinel file approach is necessary because PHP runs inside Docker and cannot inspect the host's package manager directly. Writing `.prerequisites_ok` into the staging directory from the host is the contract between host setup and container status check.

`ffprobe` is already available in the GigHive Docker image. The proxy check runs only on video files (`.mp4`, `.mov`, `.m4v`) and is a sample — not exhaustive — to keep response time short.

### 3. New PHP endpoint — `admin/iphone_import_server_scan.php`

Unlike Sections A/B (which hash files client-side in the browser), files are already on the server here. This endpoint:
- Walks `/var/iphone-import` recursively
- Hashes each media file server-side (SHA-256)
- Submits the manifest to the existing `import_manifest_prepare.php` + `import_manifest_finalize.php` pipeline
- Skips the TUS upload step — instead copies files directly from `/var/iphone-import` to the asset store (source files remain in place until the user runs Clear Staging Folder)
- Returns a `job_id` for status polling via the existing `import_manifest_status.php`

### 4. Stop — reuse existing `admin/import_manifest_cancel.php`

The existing `import_manifest_cancel.php` already handles job cancellation for the manifest pipeline. Since the iPhone import feeds into the same pipeline, the Stop button calls this existing endpoint — no new file needed.

### 5. Changes to `admin/admin_database_load_import_media_from_folder.php`

Add a **Section D button** below Section C that links to `admin_database_load_import_media_from_iphone.php`. The 4-step wizard lives on that separate page, not inline on the folder import page.

---

### Idempotency Reference

| Operation | Safe to re-run? | Notes |
|---|---|---|
| `apt-get install -y` | ✓ | Skips already-installed packages |
| `brew install` | ✓ | Prints "already installed", exits 0 |
| `touch _host_iphone/.prerequisites_ok` | ✓ | No-op if file exists |
| `New-Item -Force` (PowerShell) | ✓ | `-Force` suppresses "already exists" error |
| `mkdir -p` / `New-Item -ItemType Directory -Force` | ✓ | No-op if dir exists |
| `export GIGHIVE_IPHONE_DIR=...` in install.sh.j2 | ✓ | Re-running installer recreates dir safely |
| `idevicepair pair` | ✓ | Returns success if already paired |
| `fusermount -u ... \|\| true` / `diskutil unmount ... \|\| true` | ✓ | `\|\| true` absorbs "not mounted" error |
| `rsync` | ✓ | Only copies files that differ; resumes interrupted transfers |
| `robocopy` | ✓ | Skips files with matching timestamp+size |
| `iphone_import_status.php` | ✓ | Read-only; safe to call repeatedly |
| `iphone_import_server_scan.php` | ✓ | Manifest pipeline deduplicates by SHA-256 checksum |
| Clear Staging Folder (PHP) | ✓ | `RecursiveIteratorIterator` on empty dir finds nothing to delete |
| **Sentinel after macFUSE breaks** | ⚠️ | `.prerequisites_ok` persists across macOS upgrades — Check Ready shows confirmed even if macFUSE is broken; user must re-run Step 1 manually after a macOS major version upgrade to verify |

---

## Section D UI Design

### Step 1 — Host Prerequisites *(one-time setup)*

Explanation: "These commands must be run on the GigHive host machine (not inside a Docker container). You only need to do this once."

> The `_host_iphone` staging folder is created automatically by the GigHive installer inside your `gighive-one-shot-bundle` directory. Run the commands below from that directory.

**Linux — Step A: install tools (run from anywhere):**
```bash
sudo apt-get install -y libimobiledevice-utils ifuse usbutils
```

**Linux — Step B: signal GigHive (run from `gighive-one-shot-bundle/`):**
```bash
cd ~/gighive-one-shot-bundle
[ -d _host_iphone ] || { echo "ERROR: run this from your gighive-one-shot-bundle/ directory"; exit 1; }
command -v ideviceinfo >/dev/null 2>&1 && command -v ifuse >/dev/null 2>&1 \
  && touch _host_iphone/.prerequisites_ok \
  && echo "Prerequisites confirmed" || echo "ERROR: one or more tools not found"
```

**macOS — Step A: install tools (run from anywhere):**
```bash
brew install libimobiledevice
brew install --cask macfuse
```
> ⚠️ After macfuse installs: go to **System Settings → Privacy & Security**, allow the macFUSE kernel extension, then **restart your Mac** before continuing.
```bash
brew install gromgit/fuse/ifuse-mac
```

**macOS — Step B: signal GigHive (run from `gighive-one-shot-bundle/`):**
```bash
cd ~/gighive-one-shot-bundle
[ -d _host_iphone ] || { echo "ERROR: run this from your gighive-one-shot-bundle/ directory"; exit 1; }
command -v ideviceinfo >/dev/null 2>&1 && command -v ifuse >/dev/null 2>&1 \
  && touch _host_iphone/.prerequisites_ok \
  && echo "Prerequisites confirmed" || echo "ERROR: one or more tools not found"
```
> **Note:** `ifuse` on macOS requires the `gromgit/fuse` tap — the standard `brew install ifuse` is Linux-only and will fail. macFUSE must be approved in System Settings and the Mac restarted before `gromgit/fuse/ifuse-mac` can be installed.
>
> **After a macOS major version upgrade**, re-run these commands even if Check Ready shows confirmed — macFUSE may have stopped working and the sentinel will not reflect that.

**Windows** *(PowerShell — run from the gighive-one-shot-bundle directory):*
```powershell
# iTunes must be installed — download from https://www.apple.com/itunes/ or the Microsoft Store.
# Signal GigHive:
if (-not (Test-Path "_host_iphone")) { Write-Error "Run this from your gighive-one-shot-bundle directory"; exit 1 }
New-Item -ItemType File -Force -Path "_host_iphone\.prerequisites_ok" | Out-Null
Write-Host "Prerequisites confirmed"
```

Button: **Check Ready** → calls `iphone_import_status.php`, which checks **both** (a) whether the staging bind mount is visible to the container and (b) whether the `.prerequisites_ok` sentinel file is present.

The UI reports each check independently:
- **✓ Staging directory accessible** / **✗ Staging directory not found** — bind mount working?
- **✓ Host prerequisites confirmed** / **✗ Prerequisites not confirmed** — were the install commands run and the sentinel written?

PHP cannot directly inspect host packages (`dpkg`, `brew`) because it runs inside Docker. The sentinel file written into the shared staging directory is the contract between host setup and container status check.

*Before staging files, review [iPhone Import Caveats](https://gighive.app/feature_iphone_upload_catalog_caveats) for known limitations — especially iCloud Storage Optimization, which can cause proxy files to be staged silently.*

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
fusermount -u /mnt/iphone-dcim 2>/dev/null || true  # unmount stale mount if present
ifuse /mnt/iphone-dcim
rsync -av --include="*/" --include="*.mp4" --include="*.mov" --include="*.mp3" \
  --include="*.m4v" --include="*.m4a" --exclude="*" \
  /mnt/iphone-dcim/DCIM/ ~/gighive-one-shot-bundle/_host_iphone/
fusermount -u /mnt/iphone-dcim
```
*(Adjust `~/gighive-one-shot-bundle/` if you installed in a different directory.)*

**macOS:**
```bash
idevicepair pair
mkdir -p ~/iphone-dcim
diskutil unmount ~/iphone-dcim 2>/dev/null || true  # unmount stale mount if present
ifuse ~/iphone-dcim
rsync -av --include="*/" --include="*.mp4" --include="*.mov" --include="*.mp3" \
  --include="*.m4v" --include="*.m4a" --exclude="*" \
  ~/iphone-dcim/DCIM/ ~/gighive-one-shot-bundle/_host_iphone/
diskutil unmount ~/iphone-dcim
```
*(Adjust `~/gighive-one-shot-bundle/` if you installed in a different directory.)*

**Windows** *(PowerShell):*
```powershell
# iPhone appears in File Explorer after tapping Trust.
# Note: if robocopy fails to find the source, open File Explorer under 'This PC'
# to confirm your iPhone's exact device name (may differ from 'Apple iPhone').
robocopy "\\Apple iPhone\Internal Storage\DCIM" `
  "$env:USERPROFILE\gighive-one-shot-bundle\_host_iphone" `
  *.mp4 *.mov *.mp3 *.m4v *.m4a /S /NFL /NDL
```
*(Adjust `$env:USERPROFILE\gighive-one-shot-bundle\` if you installed in a different directory.)*

Button: **Detect Staged Files** → calls `iphone_import_status.php`, which counts files AND runs `ffprobe` proxy detection on a sample.

Result states:
- **✓** `14 videos, 3 audio files (22.4 GB) — resolutions look correct (1080p/4K).` → Step 3 unlocks normally
- **⚠️** `14 videos detected but 8 of 10 sampled files appear low-resolution (240×135). Your iPhone may have iCloud Storage Optimization enabled. Check iPhone → Settings → Photos → Download and Keep Originals before importing. [Learn more](https://gighive.app/feature_iphone_upload_catalog_caveats#icloud-storage-optimization-most-common-issue) [Dismiss and continue anyway]` → Step 3 button shown but de-emphasised until dismissed

---

### Step 3 — Review

Displays:
- `X video files, Y audio files detected (Z GB total)`
- Proxy warning banner if flagged in Step 2 (dismissible)
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
- **Clear Staging Folder** — prompts confirmation, then recursively deletes all staged files and subdirectories from `/var/iphone-import/` to free disk space; the `.prerequisites_ok` sentinel at the root is explicitly preserved so Step 1 does not need to be re-run. Uses a recursive PHP directory iterator (`RecursiveIteratorIterator` / `CHILD_FIRST`) internally — not a simple `find -maxdepth 1`, which would leave DCIM subdirectories (`100APPLE/` etc.) in place.

---

## Mac Host Differences

### One-Shot-Bundle on macOS

**Key constraint:** Docker Desktop for Mac does **not** support USB passthrough to containers. The workaround — mounting the iPhone on the Mac host and rsyncing to `_host_iphone` — works naturally with the bundle-relative staging approach:

1. Mount the iPhone on the **Mac host** using `ifuse` (requires macFUSE + `brew install libimobiledevice` + `brew install gromgit/fuse/ifuse-mac`)
2. rsync to `~/gighive-one-shot-bundle/_host_iphone/` — user-owned, no sudo needed
3. Docker Desktop's VirtioFS shares the entire `~` directory — `_host_iphone` is covered automatically, no extra file-sharing configuration required

**macFUSE note:** macFUSE requires a kernel extension. On macOS Ventura and later, this may require a restart and approval in **System Settings → Privacy & Security** after first install.


---

## File Change Summary

| File | Change |
|---|---|
| `ansible/roles/docker/files/one_shot_bundle/docker-compose.yml` | Add `${GIGHIVE_IPHONE_DIR:-./_host_iphone}:/var/iphone-import` to `apacheWebServer` volumes |
| `ansible/roles/docker/templates/install.sh.j2` | Declare `GIGHIVE_IPHONE_DIR` near top alongside `AUDIO_DIR`/`VIDEO_DIR`; add to existing `mkdir -p` line (Linux + macOS) |
| `ansible/roles/docker/templates/install.ps1.j2` | Add `_host_iphone` directory creation alongside existing `_host_audio`/`_host_video` mkdir steps (Windows) |
| `admin/iphone_import_status.php` | New — checks staging dir + `.prerequisites_ok` sentinel, file count + ffprobe proxy detection (sample 10 videos, flag height < 480px) |
| `admin/iphone_import_server_scan.php` | New — async server-side hash, manifest submit, direct file move |
| `admin/import_manifest_cancel.php` | Existing — reused for Stop button, no changes needed |
| `admin/admin_database_load_import_media_from_folder.php` | Add Section D button linking to new iPhone import page |
| `admin/admin_database_load_import_media_from_iphone.php` | New — full 4-step wizard page |
| `docs/feature_iphone_upload_catalog_caveats.md` | New — detailed caveats reference, linked from admin UI |

---

## Known Limitations

See **[feature_iphone_upload_catalog_caveats.md](feature_iphone_upload_catalog_caveats.md)** for full detail on all caveats.

Summary:
- **iCloud Storage Optimization** ⚠️ — if enabled, DCIM contains low-res proxies, not originals. User must switch to "Download and Keep Originals" and wait for sync before staging. Proxies copy silently with no errors — file size is the only tell.
- **iPhone trust pairing** — must be tapped on the device screen; cannot be done remotely.
- **Large libraries** — rsync/robocopy are resumable if interrupted.
- **macFUSE stability** (macOS) — reinstall after macOS major version upgrades.
- **HEVC playback** — ingested fine; browser playback depends on client codec support.
- **Live Photos** — companion `.mov` clips will be staged; no filtering in scope.
- **Windows** — iTunes required for USB driver; no extra Docker Desktop file-sharing config needed.
- **Azure** — not supported; no USB ports on cloud VMs.
- **Cleanup** — `docker compose down -v && rm -rf gighive-one-shot-bundle` removes everything including `_host_iphone`.

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
