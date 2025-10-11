# PHPicker Automatic Transcoding Behavior

## Overview

When using `PHPickerViewController` to select videos from the Photos library, iOS automatically transcodes HEVC (H.265) videos to H.264 format by default. This document explains why this happens and how to control it.

## Default Behavior

When `preferredAssetRepresentationMode` is **not explicitly set**:
- iOS defaults to **`.automatic`** mode
- The system assumes maximum compatibility is needed
- HEVC videos are automatically transcoded to **H.264**
- File sizes reduce significantly (e.g., 11GB HEVC → 4.7GB H.264)
- Transcoding happens during the `loadFileRepresentation(forTypeIdentifier:)` call

## Why Apple Does This

1. **Compatibility**: Not all apps/systems support HEVC
2. **Privacy**: The picker runs in a separate process and provides a "safe" version
3. **User Experience**: Prevents app crashes from unsupported formats

## PHPickerConfigurationAssetRepresentationMode Options

### `.automatic` (Default)
- System chooses the most appropriate representation
- Typically transcodes HEVC → H.264 for compatibility
- Results in smaller file sizes but takes time to transcode

### `.current`
- Uses current representation and avoids transcoding, if possible
- Preserves original HEVC format and quality
- Larger file sizes (original format)
- Faster export (no transcoding overhead)

### `.compatible`
- Uses the most compatible representation
- Forces transcoding to ensure compatibility
- Similar behavior to `.automatic` but more explicit

## Implementation in This App

```swift
// Current implementation (PickerBridges.swift, line 13-15)
var config = PHPickerConfiguration()
config.filter = .videos
config.selectionLimit = 1
// preferredAssetRepresentationMode NOT set = defaults to .automatic
// Result: HEVC videos are transcoded to H.264
```

To preserve original HEVC format:
```swift
var config = PHPickerConfiguration()
config.filter = .videos
config.selectionLimit = 1
config.preferredAssetRepresentationMode = .current  // Preserve HEVC
```

## Trade-offs

### Using Default (.automatic) - Current Approach
**Pros:**
- Smaller file sizes (~60% reduction)
- Better compatibility (H.264 works everywhere)
- More likely to fit within upload size limits

**Cons:**
- Transcoding takes time during file copy
- Quality loss from re-encoding
- User sees "Preparing..." progress indicator

### Using .current
**Pros:**
- Preserves original quality
- Faster export (no transcoding)
- Original codec maintained

**Cons:**
- Much larger file sizes (may exceed 6GB limit)
- HEVC may not be compatible with all systems
- Requires more storage space

## Apple Documentation Sources

1. **PHPickerConfiguration API Reference**
   - https://developer.apple.com/documentation/photokit/phpickerconfiguration

2. **PHPickerConfigurationAssetRepresentationMode Enum**
   - https://developer.apple.com/documentation/photokit/phpickerconfigurationassetrepresentationmode

3. **WWDC 2020 Session 10652: "Meet the new Photos picker"**
   - https://developer.apple.com/videos/play/wwdc2020/10652/

4. **Apple Developer Forums - Key Quote from Apple Engineer:**
   > "Unless `.current` is specified, transcoding can happen if your app doesn't support the original file format. For example, if your app can only support JPEG but the original image is stored as HEIF, the system will transcode the image to JPEG for you."
   - https://developer.apple.com/forums/thread/736545

## Observed Behavior in This App

- **Original video**: 24 minutes, HEVC, 11GB (as shown in Photos app)
- **After PHPicker export**: H.264, 4.7GB, .mov container
- **Codec details** (from ffprobe):
  - Video: h264 (High), 3840x2160, 24,956 kb/s, 30 fps
  - Audio: aac (LC), 44100 Hz, stereo, 184 kb/s
  - Container: QuickTime (.mov)

## Recommendation

**Keep current default behavior** (automatic transcoding) because:
1. Smaller file sizes make uploads more feasible
2. H.264 has universal compatibility
3. Files are more likely to stay under the 6GB upload limit
4. Chrome and other browsers handle H.264 better

If users need original quality, they should use the Files app picker instead of Photos picker, or we could add a settings toggle to enable `.current` mode.
