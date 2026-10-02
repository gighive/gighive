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

---

# Change Default Video Encoding on iPhone (H.264 vs HEVC)

Yes --- it **is possible** to set your iPhone to record videos using
**H.264 (Most Compatible)** instead of **HEVC (High Efficiency)**.
Here's how to do it:

------------------------------------------------------------------------

## 🎥 Steps to Change the Default Encoding

1.  Open **⚙️ Settings**
2.  Scroll down and tap **Camera**
3.  Tap **Formats**
4.  Under **Camera Capture**, choose:
    -   **✅ Most Compatible** → uses **H.264** for videos and JPEG for
        photos
    -   **High Efficiency** → uses **HEVC (H.265)** for videos and HEIF
        for photos

------------------------------------------------------------------------

## ⚙️ What Happens When You Choose "Most Compatible"

-   New videos you record will use **H.264 (.MOV)** encoding.
-   File sizes will be larger, but compatibility improves with:
    -   Older Macs and PCs
    -   Web browsers
    -   Video editing software that doesn't support HEVC natively

------------------------------------------------------------------------

## ⚠️ Notes

-   **HEVC (High Efficiency)** is *on by default* on newer iPhones (like
    iPhone 15, 15 Pro, and upcoming M4 models).
-   **H.264** takes more storage space --- expect roughly **2× larger
    files**.
-   The setting affects *new captures only* --- existing HEVC videos
    remain in that format.

------------------------------------------------------------------------

## 📦 Optional: Converting Existing HEVC Videos to H.264

If you want to convert older HEVC videos to H.264 for compatibility:

### On macOS (using QuickTime Player)

1.  Open the video in QuickTime.
2.  Choose **File → Export As → \[resolution\]**.
3.  Pick **"Greater Compatibility (H.264)"** when prompted.

### On the Command Line (using ffmpeg)

``` bash
ffmpeg -i input.mov -c:v libx264 -c:a aac output_h264.mov
```

This command re-encodes the video using H.264 and AAC for audio.
