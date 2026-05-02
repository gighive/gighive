# iPhone Bulk Import — Rollout Reservations

Date: 2026-05-02

See also: [feature_iphone_upload_catalog.md](feature_iphone_upload_catalog.md) · [feature_iphone_upload_catalog_caveats.md](feature_iphone_upload_catalog_caveats.md)

---

## Status

**Section D (iPhone Import Wizard) is implemented and macOS end-to-end tested, but hidden from the admin UI pending cross-platform testing.**

To re-enable: uncomment the Section D block in `admin/admin_database_load_import_media_from_folder.php`.

---

## What's Solid

- **Server-side pipeline** is fully tested and working — scan, hash, ingest, copy, thumbnails all verified on macOS one-shot-bundle
- **Admin-only** — regular users never see it, so the blast radius of confusion is limited
- **Duplicate checksum detection** means re-runs are safe; already-imported files are skipped automatically

---

## Rollout Reservations

### macOS tooling fragility

`pymobiledevice3` CLI changed its command structure between minor versions mid-session during initial testing (the `pair` subcommand moved from `usbmux pair` to `lockdown pair` in v9). Future `pipx` upgrades could silently break documented commands without any visible error at the UI level.

### Windows untested

The `robocopy` staging path has never been run end-to-end. The server-side import pipeline is platform-agnostic but the host-side Windows staging commands are unverified.

### Linux untested

The `ifuse` / `rsync` staging path on Linux has not been tested with the new server-side code. It is expected to work but is unverified.

### iCloud gotcha will catch most first-time users

**"Optimize iPhone Storage"** is ON by default for most iPhone users. Nearly every new user will stage proxy files on their first attempt, import them, and wonder why their gig footage is missing. The proxy detection (`ffprobe` height check) helps, but only if the proxies are below 480px — some proxy formats may not be flagged.

The caveats doc covers this thoroughly but requires the user to read it before starting.

### Browser playback gap

Imported `.mov` and HEVC files cannot preview in the browser — only download. This creates a confusing experience for non-technical admins who expect to see a video player.

---

## Unblocking Checklist

- [ ] Test Windows robocopy staging path end-to-end
- [ ] Test Linux ifuse/rsync staging path end-to-end
- [ ] Test against a real gig footage library (large MP4/MOV files, not personal iPhone snaps)
- [ ] Verify proxy detection correctly flags iCloud-optimized proxy files
- [ ] Decide on browser playback gap — accept download-only or scope a transcoding step
- [ ] Re-enable Section D in `admin_database_load_import_media_from_folder.php`
