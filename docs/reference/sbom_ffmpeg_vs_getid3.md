# SBOM: ffmpeg vs getID3 for media probing in the Apache container

## Decision (Apr 2026)

**Keep ffmpeg in the Apache container.** The research below documents why a migration to pure-PHP getID3 was evaluated and declined.

---

## Context

`ffmpeg` (which provides `ffprobe`) is installed in the Apache container via `ansible/roles/docker/templates/Dockerfile.j2`. It adds meaningful image size. The question was: could `james-heinrich/getid3` (already a Composer vendor dependency) fully replace it?

---

## What ffmpeg/ffprobe is used for

All probing is concentrated in `src/Services/MediaProbeService.php`, called from `UploadService.php` and `UnifiedIngestionCore.php`.

| Method | Purpose |
|---|---|
| `probeDuration` | Duration in seconds — **getID3 is already the primary**; ffprobe is fallback only |
| `probeMediaInfo` | Full JSON blob (format + streams + chapters + programs) stored in `files.media_info` |
| `probeMediaCreatedAt` | Parses `creation_time` tag from the `media_info` JSON |
| `generateVideoThumbnail` | Extracts a single video frame as PNG using ffmpeg |
| `ffprobeToolString` | Records tool version in `files.media_info_tool` |

The canonical ffprobe command used:

```bash
ffprobe -v error -print_format json -show_format -show_streams -show_chapters -show_programs <file>
```

---

## Why `media_info` was designed around ffprobe

See `docs/database_add_media_info_columns.md`. The feature was purpose-built for ffprobe JSON output. The `media_info` column stores the full JSON; `media_info_tool` records `ffprobe <version>`. Adding ffmpeg to the Dockerfile was an **explicit requirement** of that feature.

---

## Field-by-field coverage: ffprobe vs getID3

Fields currently displayed on `db/database.php` (rendered by `src/Controllers/MediaController.php`):

### Audio example
`MP2/3 (MPEG audio layer 2/3) • mp3 • ~258 kbps • 360s`
`A: mp3 • 2ch • 44kHz • ~258 kbps`

### Video example
`QuickTime / MOV • mov,mp4,m4a,3gp,3g2,mj2 • ~743 kbps • 786s`
`A: aac • 2ch • 48kHz • ~165 kbps`
`V: h264 • 720x480 • 29.97fps • yuv420p`

| Displayed field | ffprobe JSON key | getID3 equivalent | Verdict |
|---|---|---|---|
| "MP2/3 (MPEG audio layer 2/3)" | `format.format_long_name` | none | **Missing** |
| "mp3" / "mov,mp4,m4a,3gp,3g2,mj2" | `format.format_name` | `$info['fileformat']` → single token only | Partial |
| `~258 kbps` overall | `format.bit_rate` | `$info['bitrate']` | ✓ |
| `360s` duration | `format.duration` | `$info['playtime_seconds']` | ✓ (with VBR caveat — see below) |
| `mp3` / `aac` codec | `stream.codec_name` | `$info['audio']['dataformat']` | ✓ for common formats |
| `2ch` | `stream.channels` | `$info['audio']['channels']` | ✓ |
| `44kHz` | `stream.sample_rate` | `$info['audio']['sample_rate']` | ✓ |
| `~258 kbps` audio stream | `stream.bit_rate` | `$info['audio']['bitrate']` | ✓ (may be estimated) |
| `h264` video codec | `stream.codec_name` | `$info['video']['dataformat']` | Unreliable — QuickTime module often returns "quicktime" not "h264" |
| `720x480` | `stream.width` / `stream.height` | `$info['video']['resolution_x/y']` | ✓ |
| `29.97fps` | `stream.avg_frame_rate` ("30000/1001") | `$info['video']['frame_rate']` (float) | Partial — float not fraction; missing for some containers |
| **`yuv420p`** | `stream.pix_fmt` | **not provided** | **Missing** |

---

## Hard gaps (getID3 cannot provide)

1. **`pix_fmt`** (`yuv420p`, `yuv420p10le`, etc.) — getID3 does not decode frames; pixel format is a decoder-level detail unavailable from pure container parsing.
2. **`format_long_name`** — getID3 has no verbose container name equivalent ("QuickTime / MOV", "MP2/3 (MPEG audio layer 2/3)").

## Soft gaps

- **Video codec name** in MP4/MOV: the getID3 QuickTime module tends to return the container type ("quicktime", "mp4") rather than the stream codec ("h264", "hevc"). The `V:` line on `database.php` would lose the codec for most uploaded video files.
- **VBR MP3 duration**: getID3 computes duration from bitrate. For VBR MP3 files without a XING/VBRI header, this can be meaningfully inaccurate. ffprobe reads the actual container duration field and is reliable for all bitrate modes.
- **`format_name` multi-value mux list**: ffprobe returns "mov,mp4,m4a,3gp,3g2,mj2" for QuickTime containers; getID3 returns a single token ("mp4", "quicktime").

---

## Video thumbnail generation

`generateVideoThumbnail` requires the **ffmpeg encoder** (not just ffprobe) to extract a frame. getID3 is read-only metadata and cannot do this. The implementation is already guarded — it no-ops gracefully when ffmpeg is absent — but thumbnails would simply never be generated without ffmpeg in the container.

See `docs/how_are_thumbnails_calculated.md` for the full thumbnail algorithm and storage contract.

---

## Conclusion

Migrating to getID3-only would:

- Remove video thumbnails entirely from the web upload flow
- Drop `pix_fmt` from all video `media_info` records
- Drop `format_long_name` from all records
- Degrade video codec names for most MP4/MOV uploads
- Introduce potential VBR MP3 duration inaccuracies

The container size saving does not justify these display and data-quality regressions at this time. **ffmpeg stays.**

If this is revisited, the most viable path would be a **media processing sidecar container** (dedicated ffmpeg container called via HTTP), keeping the Apache container lean while preserving full probe and thumbnail capability.
