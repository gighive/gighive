#!/usr/bin/env python3

import csv
import json
import os
import re
import shlex
import subprocess
from typing import Dict, List, Tuple

import pandas as pd


SESSIONS_CSV = "sessions.csv"
SESSION_FILES_CSV = "session_files.csv"
OUTPUT_DIR = "prepped_csvs"

os.makedirs(OUTPUT_DIR, exist_ok=True)

MEDIA_TYPE = {
    'mp3': 'audio', 'wav': 'audio', 'flac': 'audio',
    'aac': 'audio', 'm4a': 'audio',
    'mp4': 'video', 'mov': 'video', 'avi': 'video',
    'mkv': 'video', 'webm': 'video',
}


def parse_bool(val: str) -> str:
    v = (val or "").strip().lower()
    return "1" if v in ("1", "true", "yes", "y", "t") else "0"


def track_num(fname: str) -> int:
    base = os.path.basename(fname or "")
    m = re.search(r'_(\d+)(?:\D|$)', base)
    return int(m.group(1)) if m else 0


def which(cmd: str) -> bool:
    try:
        subprocess.run([cmd, "-version"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
        return True
    except Exception:
        return False


FFPROBE_AVAILABLE = which("ffprobe")
_FFPROBE_TOOL = None


def probe_duration_seconds(filepath: str) -> str:
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


def ffprobe_tool_string() -> str:
    global _FFPROBE_TOOL
    if _FFPROBE_TOOL is not None:
        return _FFPROBE_TOOL
    if not FFPROBE_AVAILABLE:
        _FFPROBE_TOOL = ""
        return _FFPROBE_TOOL
    try:
        result = subprocess.run(["ffprobe", "-version"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, check=False)
        first = (result.stdout or "").splitlines()[0].strip() if result.stdout else ""
        m = re.search(r"\bversion\s+([^\s]+)", first)
        _FFPROBE_TOOL = f"ffprobe {m.group(1)}" if m else ""
        return _FFPROBE_TOOL
    except Exception:
        _FFPROBE_TOOL = ""
        return _FFPROBE_TOOL


def probe_media_info_json(filepath: str) -> str:
    if not FFPROBE_AVAILABLE:
        return ""
    if not os.path.isfile(filepath):
        return ""
    try:
        result = subprocess.run(
            [
                "ffprobe",
                "-v", "error",
                "-print_format", "json",
                "-show_format",
                "-show_streams",
                "-show_chapters",
                "-show_programs",
                filepath,
            ],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            check=False,
        )
        out = (result.stdout or "").strip()
        if out == "":
            return ""
        obj = json.loads(out)
        if isinstance(obj, dict) and isinstance(obj.get("format"), dict) and isinstance(obj["format"].get("filename"), str):
            obj["format"]["filename"] = os.path.basename(obj["format"]["filename"])
        return json.dumps(obj, ensure_ascii=False, separators=(",", ":"))
    except Exception:
        return ""


def resolve_media_path(name: str) -> str:
    if os.path.isabs(name) and os.path.isfile(name):
        return name

    search_dirs: List[str] = []
    env_dirs = os.getenv("MEDIA_SEARCH_DIRS", "").split(":") if os.getenv("MEDIA_SEARCH_DIRS") else []
    search_dirs.extend([d for d in env_dirs if d])

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


def resolve_media_path_with_checksum(source_relpath: str, checksum_sha256: str) -> str:
    resolved = resolve_media_path(source_relpath)
    if os.path.isfile(resolved):
        return resolved

    src = (source_relpath or "").strip()
    if src == "":
        return resolved

    _, ext = os.path.splitext(src)
    if ext == "":
        return resolved

    chk = (checksum_sha256 or "").strip().lower()
    if chk == "":
        return resolved

    return resolve_media_path(f"{chk}{ext}")


def write_csv(rows, cols, fname):
    path = os.path.join(OUTPUT_DIR, fname)
    with open(path, "w", newline="", encoding="utf8") as f:
        writer = csv.DictWriter(f, fieldnames=cols)
        writer.writeheader()
        for r in rows:
            writer.writerow({c: r.get(c, "") for c in cols})


def _require_cols(df: pd.DataFrame, required: List[str], fname: str) -> None:
    missing = [c for c in required if c not in df.columns]
    if missing:
        raise RuntimeError(f"{fname} missing required columns: {', '.join(missing)}")


def _as_int(s: str) -> int:
    try:
        return int(str(s).strip())
    except Exception:
        return 0


def load_inputs() -> Tuple[pd.DataFrame, pd.DataFrame]:
    if not os.path.isfile(SESSIONS_CSV):
        raise RuntimeError(f"Missing input file: {SESSIONS_CSV}")
    if not os.path.isfile(SESSION_FILES_CSV):
        raise RuntimeError(f"Missing input file: {SESSION_FILES_CSV}")

    sessions_df = pd.read_csv(SESSIONS_CSV, dtype=str).fillna("")
    files_df = pd.read_csv(SESSION_FILES_CSV, dtype=str).fillna("")

    _require_cols(sessions_df, ["session_key", "t_title", "d_date"], SESSIONS_CSV)
    _require_cols(files_df, ["session_key", "source_relpath"], SESSION_FILES_CSV)

    if 'checksum_sha256' not in files_df.columns:
        files_df['checksum_sha256'] = ''

    if 'd_merged_song_lists' not in sessions_df.columns:
        sessions_df['d_merged_song_lists'] = ''
    if 'd_crew_merged' not in sessions_df.columns:
        sessions_df['d_crew_merged'] = ''

    if 'org_name' not in sessions_df.columns:
        sessions_df['org_name'] = 'StormPigs'
    if 'event_type' not in sessions_df.columns:
        sessions_df['event_type'] = 'band'

    if 'seq' not in files_df.columns:
        files_df['seq'] = ''

    return sessions_df, files_df


sessions_df, session_files_df = load_inputs()

# Map session_key -> sequential session_id for loader
session_key_to_id: Dict[str, int] = {}
for i, skey in enumerate(sessions_df['session_key'].tolist()):
    session_key_to_id[str(skey)] = i + 1

sessions: List[Dict[str, str]] = []
musicians: Dict[str, int] = {}
session_musicians: List[Dict[str, str]] = []
songs: Dict[str, Dict[str, str]] = {}
session_songs: List[Dict[str, str]] = []
files: Dict[str, Dict[str, str]] = {}
song_files: List[Dict[str, str]] = []

# Pre-index files by session_key
by_session: Dict[str, List[Dict[str, str]]] = {}
for _, r in session_files_df.iterrows():
    skey = str(r.get('session_key', '')).strip()
    if skey == '' or skey not in session_key_to_id:
        continue
    by_session.setdefault(skey, []).append({
        'source_relpath': str(r.get('source_relpath', '')).strip(),
        'seq': str(r.get('seq', '')).strip(),
        'checksum_sha256': str(r.get('checksum_sha256', '')).strip().lower(),
    })

for _, row in sessions_df.iterrows():
    skey = str(row.get('session_key', '')).strip()
    if skey == '' or skey not in session_key_to_id:
        continue

    session_id = session_key_to_id[skey]

    sessions.append({
        'session_id': session_id,
        'title': row.get('t_title', ''),
        'date': row.get('d_date', ''),
        'org_name': row.get('org_name', 'StormPigs'),
        'event_type': row.get('event_type', 'band'),
        'description': row.get('t_description_x', ''),
        'cover_image_url': row.get('t_image', ''),
        'crew': row.get('d_crew_merged', ''),
        'location': row.get('v_location', ''),
        'rating': row.get('v_rating', ''),
        'summary': row.get('v_jam summary', ''),
        'published_at': row.get('v_pubDate', ''),
        'explicit': parse_bool(row.get('v_explicit', '')),
        'duration': row.get('v_duration', ''),
        'keywords': row.get('v_keywords', ''),
    })

    # Musicians
    for raw in str(row.get('d_crew_merged', '')).split(','):
        m_clean = re.sub(r"\s+", " ", raw.strip())
        if not m_clean:
            continue
        m_norm = m_clean.title()
        if m_norm not in musicians:
            musicians[m_norm] = len(musicians) + 1
        session_musicians.append({'session_id': session_id, 'musician_id': musicians[m_norm]})

    # Songs
    raw_songs = [x.strip() for x in str(row.get('d_merged_song_lists', '')).split(',') if x.strip()]
    seen = set()
    song_list: List[str] = []
    for s in raw_songs:
        if s not in seen:
            seen.add(s)
            song_list.append(s)

    has_loops = bool(str(row.get('l_loops', '')).strip())

    for title in song_list:
        song_key = f"{session_id}_{title}"
        if song_key not in songs:
            songs[song_key] = {
                'song_id': str(len(songs) + 1),
                'type': 'loop' if has_loops else 'song',
                'title': title,
            }
        session_songs.append({'session_id': session_id, 'song_id': songs[song_key]['song_id']})

    # Files in this session
    session_file_list = by_session.get(skey, [])

    def _file_sort_key(it: Dict[str, str]):
        p = it.get('source_relpath', '')
        tn = track_num(p)
        if tn > 0:
            return (0, tn, os.path.basename(p))

        s = it.get('seq', '')
        if str(s).strip() != '':
            return (1, _as_int(s), os.path.basename(p))

        return (2, 10**9, os.path.basename(p))

    session_file_list.sort(key=_file_sort_key)

    for i, it in enumerate(session_file_list):
        fname = it.get('source_relpath', '')
        if not fname:
            continue

        chk = (it.get('checksum_sha256', '') or '').strip().lower()

        if fname not in files:
            ext = os.path.splitext(fname)[1].lower().lstrip('.')
            media = MEDIA_TYPE.get(ext, 'audio')
            canonical_name = os.path.basename(fname)

            resolved = resolve_media_path_with_checksum(fname, chk)
            dur = probe_duration_seconds(resolved)
            media_info = probe_media_info_json(resolved)
            media_info_tool = ffprobe_tool_string() if media_info != "" else ""

            files[fname] = {
                'file_id': str(len(files) + 1),
                'file_type': media,
                'file_name': canonical_name,
                'source_relpath': fname,
                'checksum_sha256': chk,
                'duration_seconds': dur,
                'media_info': media_info,
                'media_info_tool': media_info_tool,
            }
        else:
            if chk and not str(files[fname].get('checksum_sha256', '')).strip():
                files[fname]['checksum_sha256'] = chk

        fid = files[fname]['file_id']
        if i < len(song_list):
            song_key = f"{session_id}_{song_list[i]}"
            sid = songs[song_key]['song_id']
            song_files.append({'song_id': sid, 'file_id': fid})

write_csv(
    sessions,
    [
        'session_id', 'title', 'date', 'org_name', 'event_type',
        'description', 'cover_image_url', 'crew',
        'location', 'rating', 'summary', 'published_at', 'explicit', 'duration',
        'keywords'
    ],
    'sessions.csv'
)

write_csv(
    [{'musician_id': mid, 'name': name} for name, mid in musicians.items()],
    ['musician_id', 'name'],
    'musicians.csv'
)

write_csv(
    session_musicians,
    ['session_id', 'musician_id'],
    'session_musicians.csv'
)

write_csv(
    [
        {
            'song_id': info['song_id'],
            'title': info['title'],
            'type': info['type'],
            'duration': '',
            'genre_id': '',
            'style_id': '',
        }
        for _, info in songs.items()
    ],
    ['song_id', 'title', 'type', 'duration', 'genre_id', 'style_id'],
    'songs.csv'
)

write_csv(
    session_songs,
    ['session_id', 'song_id'],
    'session_songs.csv'
)

write_csv(
    [
        {
            'file_id': info['file_id'],
            'file_name': info.get('file_name', os.path.basename(name)),
            'source_relpath': info.get('source_relpath', name),
            'checksum_sha256': info.get('checksum_sha256', ''),
            'file_type': info['file_type'],
            'duration_seconds': info.get('duration_seconds', ''),
            'media_info': info.get('media_info', ''),
            'media_info_tool': info.get('media_info_tool', ''),
        }
        for name, info in files.items()
    ],
    ['file_id', 'file_name', 'source_relpath', 'checksum_sha256', 'file_type', 'duration_seconds', 'media_info', 'media_info_tool'],
    'files.csv'
)

write_csv(
    song_files,
    ['song_id', 'file_id'],
    'song_files.csv'
)

print("✅ Preprocessing complete — all CSVs in 'prepped_csvs/' ready for MySQL load.")
