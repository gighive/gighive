# GigHive iOS Setup Checklist (Novice-Friendly)

This page walks you through everything you need to do in Xcode to run the GigHive iOS app and Share Extension. Follow the steps in order.

The project lives here on your Mac:
- `/Volumes/scripts/gighive/ios/GigHive`

Useful commands (run these in Terminal on the Mac):
```bash
# 1) Generate/open the Xcode project (only needed the first time or after editing project.yml)
cd /Volumes/scripts/gighive/ios/GigHive
xcodegen generate
open GigHive.xcodeproj
```

---

## 1) Create an App Group in Apple Developer
- Open: https://developer.apple.com/account/resources/identifiers/list/applicationGroup
- Click the + button to create a new App Group.
- Use an ID like: `group.com.yourorg.gighive`
- Save it.

Why: The app and the Share Extension share settings (server URL, auth) using this App Group.

---

## 2) Set bundle identifiers in Xcode
- Open the project: `open /Volumes/scripts/gighive/ios/GigHive/GigHive.xcodeproj`
- In the left sidebar (Project Navigator), click the blue `GigHive` project icon.
- Under `Targets`, select `GigHive`.
  - General → Identity → Bundle Identifier: set to something unique, e.g. `com.yourorg.GigHive`
- Select `GigHiveShare` target.
  - General → Identity → Bundle Identifier: set to e.g. `com.yourorg.GigHive.Share`

Tip: You need a paid Apple Developer account to sign on a device. For simulator, automatic signing is usually fine.

---

## 3) Add the App Group capability to both targets
- Still in Xcode, select the `GigHive` target → `Signing & Capabilities` tab.
- Click `+ Capability` → choose `App Groups`.
- Check your newly created group (e.g., `group.com.yourorg.gighive`).
- Repeat the same for the `GigHiveShare` target.

This writes the App Group into the entitlements automatically.

Alternatively (manual edit of files):
- Edit these files and replace the placeholder with your real group:
  - `file:///Volumes/scripts/gighive/ios/GigHive/Configs/GigHive.entitlements`
  - `file:///Volumes/scripts/gighive/ios/GigHive/Configs/GigHiveShare.entitlements`
  - Use your group string: `group.com.yourorg.gighive`

---

## 4) Update the app to use your App Group ID
- Open `file:///Volumes/scripts/gighive/ios/GigHive/Sources/App/SettingsStore.swift`
- Change the line:
  ```swift
  static let appGroupId = "group.com.yourcompany.gighive"
  ```
  to your real group, e.g.:
  ```swift
  static let appGroupId = "group.com.yourorg.gighive"
  ```
- Build once so both the app and extension see the same group.

---

## 5) Verify required Info.plist keys are present
Already added for you:
- App target `Info.plist` includes:
  - `NSPhotoLibraryUsageDescription`
  - `NSPhotoLibraryAddUsageDescription`
- Share extension `Info.plist` includes the `NSExtension` block for Share Sheet.

Files:
- App: `file:///Volumes/scripts/gighive/ios/GigHive/Sources/App/Info.plist`
- Share: `file:///Volumes/scripts/gighive/ios/GigHive/Sources/ShareExtension/Info.plist`

Nothing to change here unless you want to customize the privacy text.

---

## 6) Build and run the app
- In Xcode, choose a simulator (or a real device) at the top bar.
- Select the `GigHive` scheme and click Run (▶).

In the running app:
- Go to the "Server & Defaults" section.
- Enter:
  - Base URL: `https://gighive` (or your reachable DNS/IP hostname)
  - Basic user: `admin`
  - Basic password: `secretadmin`
  - Default organization: e.g. `StormPigs`
  - Default event type: `band` or `wedding`
- These settings are saved to the App Group for the Share Extension.

---

## 7) Test an app-based upload
- In the app, under Metadata, fill fields as needed (or keep defaults).
- Tap "Pick video/audio" and choose a file.
- Tap "Upload".
- Expected:
  - Success → HTTP 201 with JSON body.
  - If you see HTTP 413, increase server upload size limits.

---

## 8) Test the Share Extension (auto-upload)
- On a device (recommended): open Photos.
- Select a video → Share → choose `GigHive`.
- The extension will auto-upload using your saved defaults (Default Org, Default Event Type, event date = today).
- You should see a brief uploading indicator then completion.

Notes:
- If you don’t see the GigHive extension:
  - Ensure `NSExtension` exists in `Sources/ShareExtension/Info.plist`.
  - Ensure the App Group is enabled for BOTH targets.
  - Make sure the Share Extension bundle identifier is unique.
  - Sometimes you need to build/run the app target once before the system recognizes the extension.

---

## 9) Troubleshooting
- 401 Unauthorized when uploading:
  - Re-check Basic Auth credentials in the app.
- 404 or can’t reach server:
  - Ensure your iPhone can reach the host (Wi‑Fi/VPN/DNS).
  - Use a hostname that matches the TLS certificate to avoid HTTPS issues.
- 413 Payload Too Large:
  - Increase PHP `upload_max_filesize` and `post_max_size` and any proxy/body limits.
- Share Extension doesn’t appear:
  - See step 8 notes; also try rebooting the device after first install.

---

## 10) Helpful open commands (macOS Terminal)
```bash
# Open the Xcode project
open "/Volumes/scripts/gighive/ios/GigHive/GigHive.xcodeproj"

# Open files directly
open "/Volumes/scripts/gighive/ios/GigHive/Configs/GigHive.entitlements"
open "/Volumes/scripts/gighive/ios/GigHive/Configs/GigHiveShare.entitlements"
open "/Volumes/scripts/gighive/ios/GigHive/Sources/App/SettingsStore.swift"
open "/Volumes/scripts/gighive/ios/GigHive/Sources/App/Info.plist"
open "/Volumes/scripts/gighive/ios/GigHive/Sources/ShareExtension/Info.plist"

# Apple Developer pages
open "https://developer.apple.com/account/resources/identifiers/list/applicationGroup"
open "https://developer.apple.com/account/resources/identifiers/list/bundleId"
```

---

## 11) Optional: Regenerate project after changes to `project.yml`
If you edit `ios/GigHive/project.yml`, regenerate the project:
```bash
cd /Volumes/scripts/gighive/ios/GigHive
xcodegen generate
```

---

## That’s it
You should now be able to:
- Upload from inside the app with full metadata controls.
- Auto-upload from the Share Sheet with your saved defaults.
