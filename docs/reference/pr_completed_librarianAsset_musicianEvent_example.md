# Concrete Examples: Librarian Asset vs Musician Event (Target Schema)

This document illustrates the target `assets / events / event_items` schema using two
concrete data examples. It is a companion to:

- `docs/pr_librarianAsset_musicianEvent.md` (product requirements)
- `docs/pr_librarianAsset_musicianEvent_implementation.md` (implementation plan)

---

## Target Schema (summary)

```
assets         — global dedupe; one row per unique binary (identified by checksum_sha256)
events         — one row per real-world capture event
event_items    — asset labeled and typed within an event context (FK to both assets and events)
```

The same `assets` row can be referenced by multiple `event_items` rows across different events
without duplicating the physical file or the asset record.

---

## Example 1: Librarian Import

**Scenario**: A media librarian imports `theband_live_19941026.mp3` from their local archive.
No event context — they are simply cataloging the file in the library.

### `assets`

| column | value |
|---|---|
| `asset_id` | `a1b2c3d4-…` |
| `checksum_sha256` | `e3b0c44298fc1c…` |
| `file_type` | `audio` |
| `file_ext` | `mp3` |
| `source_relpath` | `archive/1994/theband_live_19941026.mp3` |
| `size_bytes` | `42351820` |
| `mime_type` | `audio/mpeg` |
| `duration_seconds` | `283` |
| `media_info` | `{"format":"mp3","bitrate":"192k"}` |
| `created_at` | `2026-04-22 10:00:00` |

### `events`

*(no row — librarian import carries no event context)*

### `event_items`

*(no row — asset stands alone in the library)*

### Librarian view query

```sql
SELECT * FROM assets;
-- Returns 1 row per unique binary; no joins, no fan-out possible.
```

---

## Example 2: Musician / Band Show Upload

**Scenario**: Band "StormPigs" plays a show on 2026-03-18 in Austin. A fan uploads a video
of them playing "Purple Rain" — it is the 7th song of the night.

### `assets`

| column | value |
|---|---|
| `asset_id` | `f9e8d7c6-…` |
| `checksum_sha256` | `9a3bc4182de591…` |
| `file_type` | `video` |
| `file_ext` | `mp4` |
| `source_relpath` | `video/stormpigs/20260318/stormpigs_purplerain.mp4` |
| `size_bytes` | `187234567` |
| `mime_type` | `video/mp4` |
| `duration_seconds` | `421` |
| `media_info` | `{"codec":"h264","resolution":"1920x1080"}` |
| `created_at` | `2026-04-22 11:00:00` |

### `events`

| column | value |
|---|---|
| `event_id` | `c1d2e3f4-…` |
| `event_date` | `2026-03-18` |
| `org_name` | `stormpigs` |
| `event_type` | `band` |
| `title` | `StormPigs @ Stubb's` |
| `location` | `Stubb's Amphitheater, Austin TX` |
| `created_at` | `2026-04-22 11:00:00` |

### `event_items`

| column | value |
|---|---|
| `event_item_id` | `00112233-…` |
| `event_id` | `c1d2e3f4-…` ← FK → `events` |
| `asset_id` | `f9e8d7c6-…` ← FK → `assets` |
| `item_type` | `song` |
| `label` | `Purple Rain` |
| `position` | `7` |
| `created_at` | `2026-04-22 11:00:00` |

### Event view query

```sql
SELECT ei.item_type, ei.label, ei.position,
       a.file_type, a.duration_seconds, a.source_relpath,
       e.title, e.event_date, e.location
FROM   event_items ei
JOIN   assets a ON a.asset_id = ei.asset_id
JOIN   events e ON e.event_id = ei.event_id
WHERE  e.event_id = 'c1d2e3f4-…';
-- Returns 1 row per item at this event; no fan-out.
```

---

## Comparison: what rows exist per workflow

| Table | Librarian import | Musician/event upload |
|---|---|---|
| `assets` | ✅ one row | ✅ one row |
| `events` | ❌ no row | ✅ one row |
| `event_items` | ❌ no row | ✅ one row |
| Primary query surface | `assets` directly | join through `event_items` |

---

## Bonus: same binary uploaded to a second event

If the same "Purple Rain" video is uploaded at a *different StormPigs show* on a later date,
the result is:

- `assets` — **same row reused** (checksum matches; no new file written to disk)
- `events` — **new row** for the second show
- `event_items` — **new row** linking the same asset to the new event

One physical file. Two event contexts. Zero duplication in the asset table.

---

## Notes on naming

`event_items` rather than `event_assets` is the correct name for the combined link+label
table because each row carries typed, ordered annotation (`item_type`, `label`, `position`),
not just a bare foreign-key association. Think of it like tracks on an album: a track is more
than just "audio at an album" — it has a title, a type, and a position. The same semantic
applies here.
