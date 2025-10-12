#!/usr/bin/env python3
"""
Preprocessing script for GigHive CSV -> normalized CSVs ready for MySQL LOAD DATA INFILE,
aligned with the standardized schema and column names.

Adds required event context columns for sessions:
  - org_name (e.g., 'StormPigs' for StormPigs environment)
  - event_type ('band' | 'wedding')

Outputs:
  - prepped_csvs/sessions.csv        (includes org_name, event_type; loader expects them after date)
  - prepped_csvs/musicians.csv
  - prepped_csvs/session_musicians.csv
  - prepped_csvs/songs.csv           (includes duration, genre_id, style_id columns for loader compatibility)
  - prepped_csvs/session_songs.csv
  - prepped_csvs/files.csv
  - prepped_csvs/song_files.csv
  - prepped_csvs/database_augmented.csv (source CSV with added columns for reference)
"""

import pandas as pd
import os
import csv
import re
import shlex
import subprocess

# Configuration
INPUT_CSV  = "database.csv"
OUTPUT_DIR = "prepped_csvs"
os.makedirs(OUTPUT_DIR, exist_ok=True)

# Map file extensions to the media category your files.file_type column expects
MEDIA_TYPE = {
    'mp3': 'audio', 'wav': 'audio', 'flac': 'audio',
    'aac': 'audio', 'm4a': 'audio',
    'mp4': 'video', 'mov': 'video', 'avi': 'video',
    'mkv': 'video',
}

# Load data
df = pd.read_csv(INPUT_CSV, dtype=str).fillna("")

# ——— Filter to just two jam sessions ———
# keep only sessions whose ISO date (YYYY-MM-DD) matches 2002-10-24 or 2005-03-03
dates_to_keep = {"20021024", "20050303"}
df = df[df["d_date"].str.replace("-", "").isin(dates_to_keep)].reset_index(drop=True)

# Ensure new columns exist in working DataFrame
if 'org_name' not in df.columns:
    df['org_name'] = 'StormPigs'
if 'event_type' not in df.columns:
    df['event_type'] = 'band'
if 'session_id' not in df.columns:
    # Blank session_id column for compatibility; we still generate our own session IDs for outputs
    df['session_id'] = ''

# Containers
sessions          = []
musicians         = {}
session_musicians = []
songs             = {}
session_songs     = []
files             = {}
song_files        = []

def parse_bool(val: str) -> str:
    v = val.strip().lower()
    return "1" if v in ("1", "true", "yes", "y", "t") else "0"

def track_num(fname: str) -> int:
    """Extract integer track number from filename, if present."""
    base = os.path.basename(fname)
    m = re.search(r'_(\d+)(?:\D|$)', base)
    return int(m.group(1)) if m else 0

def which(cmd: str) -> bool:
    try:
        subprocess.run([cmd, "-version"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
        return True
    except Exception:
        return False

FFPROBE_AVAILABLE = which("ffprobe")

def probe_duration_seconds(filepath: str) -> str:
    """
    Return duration in integer seconds as a string, or empty string if unknown.
    Uses ffprobe if available and file exists.
    """
    if not FFPROBE_AVAILABLE:
        return ""
    if not os.path.isfile(filepath):
        return ""
    try:
        cmd = f"ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 {shlex.quote(filepath)}"
        result = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, check=False)
        out = (result.stdout or "").strip()
        if out and re.match(r"^[0-9]+(\.[0-9]+)?$", out):
            return str(int(round(float(out))))
        return ""
    except Exception:
        return ""

def resolve_media_path(name: str) -> str:
    """
    Try to resolve a media filename to a local path for probing.
    - If name is absolute and exists, use it
    - Else search directories specified by env MEDIA_SEARCH_DIRS (colon-separated)
    - Else return input name (likely non-existent)
    """
    if os.path.isabs(name) and os.path.isfile(name):
        return name
    # Candidate search dirs
    search_dirs = []
    env_dirs = os.getenv("MEDIA_SEARCH_DIRS", "").split(":") if os.getenv("MEDIA_SEARCH_DIRS") else []
    search_dirs.extend([d for d in env_dirs if d])
    # Add some common relative locations (repo assets)
    search_dirs.extend([
        os.path.abspath(os.path.join(os.getcwd(), "/home/sodo/scripts/stormpigsCode/production/audio/")),
        os.path.abspath(os.path.join(os.getcwd(), "/home/sodo/videos/stormpigs/finals/singles/")),
        os.path.abspath(os.path.join(os.getcwd(), "/home/sodo/scripts/gighive/assets/audio")),
        os.path.abspath(os.path.join(os.getcwd(), "/home/sodo/scripts/gighive/assets/video")),
    ])
    base = os.path.basename(name)
    for d in search_dirs:
        candidate = os.path.join(d, base)
        if os.path.isfile(candidate):
            return candidate
    return name

# Single-pass extraction
for idx, row in df.iterrows():
    session_id = idx + 1

    # 1) Sessions (standardized headers; keep 'crew' only for normalization step)
    sessions.append({
        "session_id":      session_id,
        "title":           row.get("t_title", ""),
        "date":            row.get("d_date", ""),
        # New event context columns
        "org_name":        row.get("org_name", "StormPigs"),
        "event_type":      row.get("event_type", "band"),
        "description":     row.get("t_description_x", ""),
        "cover_image_url": row.get("t_image", ""),
        "crew":            row.get("d_crew_merged", ""),    # kept for session_musicians.csv
        "location":        row.get("v_location", ""),
        "rating":          row.get("v_rating", ""),
        "summary":         row.get("v_jam summary", ""),
        "published_at":    row.get("v_pubDate", ""),
        "explicit":        parse_bool(row.get("v_explicit", "")),
        "duration":        row.get("v_duration", ""),       # raw string; loader converts -> duration_seconds
        "keywords":        row.get("v_keywords", ""),
    })

    # 2) Musicians (normalize & dedupe names)
    for raw in row.get("d_crew_merged", "").split(","):
        m_clean = re.sub(r"\s+", " ", raw.strip())
        if not m_clean:
            continue
        m_norm  = m_clean.title()
        if m_norm not in musicians:
            musicians[m_norm] = len(musicians) + 1
        session_musicians.append({
            "session_id":  session_id,
            "musician_id": musicians[m_norm],
        })

    # 3) Songs (map to 'song' or 'loop')
    raw_songs = [x.strip() for x in row.get("d_merged_song_lists", "").split(",") if x.strip()]
    # dedupe song titles per session order
    seen = set()
    song_list = []
    for s in raw_songs:
        if s not in seen:
            seen.add(s)
            song_list.append(s)
    has_loops = bool(row.get("l_loops", "").strip())

    for title in song_list:
        # Create unique key combining session_id and title to prevent cross-session conflicts
        song_key = f"{session_id}_{title}"
        if song_key not in songs:
            songs[song_key] = {
                "song_id": len(songs) + 1,
                "type":    "loop" if has_loops else "song",
                "title":   title  # Store original title for CSV output
            }
        session_songs.append({
            "session_id": session_id,
            "song_id":    songs[song_key]["song_id"],
        })

    # 4) Files + song_files (positional mapping by track number in filename)
    file_list = [x.strip() for x in row.get("f_singles", "").split(",") if x.strip()]
    file_list.sort(key=track_num)
    for i, fname in enumerate(file_list):
        if fname not in files:
            ext   = os.path.splitext(fname)[1].lower().lstrip(".")
            media = MEDIA_TYPE.get(ext, 'audio')
            # Try to resolve a local path and probe duration
            resolved = resolve_media_path(fname)
            dur = probe_duration_seconds(resolved)
            files[fname] = {
                "file_id":   len(files) + 1,
                "file_type": media,
                "duration_seconds": dur
            }
        fid = files[fname]["file_id"]
        if i < len(song_list):
            # Use the same composite key pattern as in song creation
            song_key = f"{session_id}_{song_list[i]}"
            sid = songs[song_key]["song_id"]
            song_files.append({
                "song_id": sid,
                "file_id": fid,
            })

# Helper to write out CSVs
def write_csv(rows, cols, fname):
    path = os.path.join(OUTPUT_DIR, fname)
    with open(path, "w", newline="", encoding="utf8") as f:
        writer = csv.DictWriter(f, fieldnames=cols)
        writer.writeheader()
        for r in rows:
            writer.writerow({c: r.get(c, "") for c in cols})

# Emit CSVs (headers standardized where applicable)
# Also emit an augmented copy of the original CSV with org_name, event_type, session_id present
df.to_csv(os.path.join(OUTPUT_DIR, "database_augmented.csv"), index=False)
write_csv(
    sessions,
    [
        # Order aligned with loader: session_id, title(@name), date, org_name, event_type, then remaining fields
        "session_id","title","date","org_name","event_type",
        "description","cover_image_url","crew",
        "location","rating","summary","published_at","explicit","duration",
        "keywords"
    ],
    "sessions.csv"
)

write_csv(
    [{"musician_id": mid, "name": name} for name, mid in musicians.items()],
    ["musician_id","name"],
    "musicians.csv"
)

write_csv(
    session_musicians,
    ["session_id","musician_id"],
    "session_musicians.csv"
)

# Provide duration/genre/style columns (empty if unknown) for loader compatibility
write_csv(
    [
        {
            "song_id": info["song_id"],
            "title": info["title"],  # Use stored original title instead of the composite key
            "type": info["type"],
            "duration": "",      # optional per-song raw duration (leave empty; loader handles if present)
            "genre_id": "",
            "style_id": ""
        }
        for song_key, info in songs.items()
    ],
    ["song_id","title","type","duration","genre_id","style_id"],
    "songs.csv"
)

write_csv(
    session_songs,
    ["session_id","song_id"],
    "session_songs.csv"
)

write_csv(
    [{
        "file_id": info["file_id"],
        "file_name": name,
        "file_type": info["file_type"],
        "duration_seconds": info.get("duration_seconds", "")
     }
     for name, info in files.items()],
    ["file_id","file_name","file_type","duration_seconds"],
    "files.csv"
)

write_csv(
    song_files,
    ["song_id","file_id"],
    "song_files.csv"
)

print("✅ Preprocessing complete — all CSVs in 'prepped_csvs/' ready for MySQL load.")

