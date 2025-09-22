# GigHive iOS App + Share Extension (XcodeGen)

This folder contains an XcodeGen project template for a SwiftUI iOS app and a Share Extension that uploads to your existing `/api/uploads.php` endpoint.

## Requirements (Mac)
- Xcode 15+
- [XcodeGen](https://github.com/yonaskolb/XcodeGen): `brew install xcodegen`

## Configure identifiers
- Edit `ios/GigHive/project.yml` and change:
  - `PRODUCT_BUNDLE_IDENTIFIER` values
- Create an App Group in your Apple Developer account, then edit:
  - `Configs/GigHive*.entitlements` to use your real App Group, e.g., `group.com.yourorg.gighive`.
- In `Sources/App/SettingsStore.swift`, update `appGroupId` if you changed the group.

## Generate the Xcode project
```bash
cd ios/GigHive
xcodegen generate
open GigHive.xcodeproj
```

## Run
- In the app, set the Base URL to something like `https://gighive` (or a DNS name/IP reachable from your iPhone).
- Set Basic Auth user/password (defaults: admin/secretadmin) if your `/api` is protected.
- Pick a video/audio and tap Upload.

## Share Extension
- From Photos or Files, use the Share sheet, pick "GigHive".
- It uploads using the same settings stored in the App Group.

## Endpoint contract
- POST `multipart/form-data` to `/api/uploads.php`
- Fields: `file` (required), `event_date` (yyyy-MM-dd), `org_name`, `event_type` (band|wedding), `label`, `participants`, `keywords`, `location`, `rating`, `notes`.
- On success: HTTP 201 with JSON.
- If limits exceeded: HTTP 413.

## Notes
- Background uploads use a background `URLSessionConfiguration`.
- If you test against non-HTTPS, you must add ATS exceptions (not recommended). Use HTTPS.
