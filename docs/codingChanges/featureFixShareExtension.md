# Fix Share Extension Implementation Plan

## Current Status
The iOS Share Extension target exists but is **not functional**. The extension will not appear in the Share sheet and cannot upload files.

## Issues Found

### 1. Missing NSExtension Configuration
- **File**: `ios/GigHive/Sources/ShareExtension/Info.plist`
- **Problem**: No `NSExtension` dictionary present
- **Impact**: Share sheet will not recognize or display the extension

### 2. Empty Entitlements
- **Files**: 
  - `ios/GigHive/Configs/GigHive.entitlements`
  - `ios/GigHive/Configs/GigHiveShare.entitlements`
- **Problem**: Both files are empty `<dict/>`
- **Impact**: Cannot share settings between app and extension

### 3. Stubbed Upload Implementation
- **File**: `ios/GigHive/Sources/ShareExtension/ShareViewController.swift`
- **Problem**: Upload method only simulates with 1-second delay
- **Impact**: No actual file upload occurs

### 4. Hardcoded Settings
- **File**: `ios/GigHive/Sources/ShareExtension/ShareViewController.swift`
- **Problem**: Uses placeholder values for server URL and credentials
- **Impact**: Cannot connect to real server

## Implementation Plan

### Phase 1: Enable Share Extension Appearance

#### Step 1.1: Add NSExtension to Info.plist
Add to `ios/GigHive/Sources/ShareExtension/Info.plist`:
```xml
<key>NSExtension</key>
<dict>
    <key>NSExtensionPointIdentifier</key>
    <string>com.apple.share-services</string>
    <key>NSExtensionPrincipalClass</key>
    <string>$(PRODUCT_MODULE_NAME).ShareViewController</string>
    <key>NSExtensionAttributes</key>
    <dict>
        <key>NSExtensionActivationRule</key>
        <dict>
            <key>NSExtensionActivationSupportsMovieWithMaxCount</key>
            <integer>1</integer>
            <key>NSExtensionActivationSupportsAudioWithMaxCount</key>
            <integer>1</integer>
        </dict>
    </dict>
</dict>
```

#### Step 1.2: Configure App Group Entitlements
Add to both entitlement files:
```xml
<key>com.apple.security.application-groups</key>
<array>
    <string>group.com.gighive.shared</string>
</array>
```

### Phase 2: Enable Settings Sharing

#### Step 2.1: Verify SettingsStore App Group ID
Ensure `ios/GigHive/Sources/App/SettingsStore.swift` uses the same App Group:
```swift
static let appGroupId = "group.com.gighive.shared"
```

#### Step 2.2: Update ShareViewController Settings
Replace hardcoded values in `ShareViewController.swift` with shared settings from App Group.

### Phase 3: Implement Real Upload

#### Step 3.1: Replace Stub Upload Method
- Remove the fake 1-second delay
- Implement actual HTTP upload to your `/api/uploads.php` endpoint
- Use the same upload logic as the main app

#### Step 3.2: Add Error Handling
- Handle network errors gracefully
- Show appropriate user feedback
- Log errors for debugging

## Testing Checklist

### Pre-Testing Setup
- [ ] Create App Group in Apple Developer Console: `group.com.gighive.shared`
- [ ] Update bundle identifiers if needed
- [ ] Ensure both targets have App Group capability enabled in Xcode

### Functional Testing
- [ ] Build and run main app on device
- [ ] Configure server settings in main app
- [ ] Open Photos app
- [ ] Select a video file
- [ ] Tap Share button
- [ ] Verify "GigHive" appears in share options
- [ ] Select GigHive extension
- [ ] Verify extension UI appears
- [ ] Test actual upload functionality
- [ ] Verify upload appears on server

### Edge Case Testing
- [ ] Test with large files (>100MB)
- [ ] Test with unsupported file types
- [ ] Test with no network connection
- [ ] Test with invalid server credentials
- [ ] Test cancellation during upload

## Dependencies

### Apple Developer Account
- App Group creation required
- Proper bundle ID configuration
- Code signing certificates

### Server Configuration
- `/api/uploads.php` endpoint must be accessible
- Basic auth credentials configured
- File size limits appropriate for mobile uploads

### Xcode Project
- XcodeGen regeneration after `project.yml` changes
- Clean build after entitlements changes
- Device testing (Share Extensions don't work in Simulator for real files)

## Success Criteria

1. **Discoverability**: GigHive appears in Share sheet for video/audio files
2. **Functionality**: Successfully uploads files using shared app settings
3. **User Experience**: Smooth operation with appropriate feedback
4. **Reliability**: Handles errors gracefully without crashes
5. **Integration**: Uses same server settings as main app

## Notes

- Share Extensions require device testing; iOS Simulator has limitations
- App Group must be created in Apple Developer Console before use
- Both app and extension must be signed with same team/certificate
- Consider adding upload progress indication for better UX
- Extension should handle background app refresh limitations

## Related Files

### Configuration Files
- `ios/GigHive/project.yml` - Target definitions
- `ios/GigHive/Sources/ShareExtension/Info.plist` - Extension metadata
- `ios/GigHive/Configs/GigHive.entitlements` - Main app entitlements  
- `ios/GigHive/Configs/GigHiveShare.entitlements` - Extension entitlements

### Source Files
- `ios/GigHive/Sources/ShareExtension/ShareViewController.swift` - Extension logic
- `ios/GigHive/Sources/App/SettingsStore.swift` - Shared settings storage
- `ios/GigHive/Sources/App/UploadClient.swift` - Upload implementation reference

### Documentation
- `ios/GigHive/CHECKLIST.md` - Setup instructions
- `ios/GigHive/README.md` - Project overview
