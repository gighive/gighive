#!/usr/bin/env python3
"""
add_jam_session.py — Automate adding a new StormPigs jam session to CSVs + prod DB.

See docs/database_append_to_preexisting_csvs.md for full documentation.

Usage:
    python3 add_jam_session.py \\
        --dir     ~/videos/stormpigs/finals/20260318/ \\
        --songs   ~/videos/stormpigs/finals/songlists/StormPigs20260318.txt \\
        --meta    ~/videos/stormpigs/finals/metadata/StormPigs20260318_metadata.txt \\
        --ssh     ubuntu@prod.gighive.internal \\
        --csv-dir ansible/roles/docker/files/mysql/externalConfigs/prepped_csvs/full \\
        [--repo-dir .]  [--org StormPigs]  [--dry-run]  [--no-push]
"""

import argparse
import csv
import hashlib
import io
import json
import os
import re
import subprocess
import sys
import tempfile
from datetime import date
from pathlib import Path
from typing import Dict, List, Optional, Tuple


# ── Output helpers ─────────────────────────────────────────────────────────────

def die(msg: str) -> None:
    print(f"\n\u274c  {msg}", file=sys.stderr)
    sys.exit(1)


def info(msg: str) -> None:
    print(f"     {msg}")


def section(title: str) -> None:
    print(f"\n\u2500\u2500 {title}")


# ── SQL helper ─────────────────────────────────────────────────────────────────

def sql_esc(s: str) -> str:
    """Escape a string for use inside single-quoted MySQL literals."""
    return str(s).replace("\\", "\\\\").replace("'", "''")


# ── File helpers ───────────────────────────────────────────────────────────────

def extract_seq(filename: str) -> Optional[int]:
    """Extract sequence number N from YYYYMMDD_N_Title.ext or YYYYMMDD_N.ext."""
    base = Path(filename).name
    m = re.search(r'\d{8}_0*(\d+)_', base)
    if m:
        return int(m.group(1))
    m = re.search(r'\d{8}_0*(\d+)\.', base)
    if m:
        return int(m.group(1))
    return None


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        while chunk := f.read(8 * 1024 * 1024):
            h.update(chunk)
    return h.hexdigest()


# ── CSV helpers ────────────────────────────────────────────────────────────────

def load_csv(path: Path) -> List[Dict]:
    with open(path, newline='') as f:
        return list(csv.DictReader(f))


def append_csv_rows(path: Path, rows: List[List]) -> None:
    """Append rows to an existing CSV using \\r\\n line terminator (required by MySQL LOAD DATA)."""
    buf = io.StringIO()
    csv.writer(buf, lineterminator='\r\n').writerows(rows)
    with open(path, 'a', newline='') as f:
        f.write(buf.getvalue())


def next_id(rows: List[Dict], field: str) -> int:
    if not rows:
        return 1
    valid = [int(r[field]) for r in rows if r.get(field, '').strip().isdigit()]
    return (max(valid) + 1) if valid else 1


# ── SSH / remote helpers ───────────────────────────────────────────────────────

def run_ssh(target: str, command: str, check: bool = True) -> str:
    result = subprocess.run(['ssh', target, command], capture_output=True, text=True)
    if check and result.returncode != 0:
        die(f"SSH command failed:\n  cmd: {command}\n  stderr: {result.stderr.strip()}")
    return result.stdout


def ffprobe_remote(target: str, remote_path: str) -> Tuple[Optional[int], str, str]:
    """Returns (duration_seconds, media_info_json, tool_str)."""
    ver = run_ssh(target, "ffprobe -version 2>&1 | head -1").strip()

    probe_out = run_ssh(
        target,
        f"ffprobe -v quiet -print_format json -show_streams -show_format '{remote_path}'"
    )
    if not probe_out.strip():
        die(f"ffprobe returned empty output for: {remote_path}")

    try:
        data = json.loads(probe_out)
    except json.JSONDecodeError as exc:
        die(f"ffprobe JSON parse error for {remote_path}: {exc}")

    duration_sec: Optional[int] = None
    fmt = data.get('format', {})
    if 'duration' in fmt:
        try:
            duration_sec = int(float(fmt['duration']))
        except (ValueError, TypeError):
            pass

    return duration_sec, probe_out.strip(), ver


def rsync_to_prod(local_dir: Path, target: str, date_compact: str,
                  dry_run: bool) -> None:
    cmd = [
        'rsync', '-avz', '--progress', '--ignore-existing',
        str(local_dir) + '/',
        f"{target}:/home/ubuntu/video/{date_compact}/"
    ]
    if dry_run:
        info(f"[dry-run] would run: {' '.join(cmd)}")
        return
    result = subprocess.run(cmd)
    if result.returncode != 0:
        die("rsync failed — see output above")


# ── Input parsers ──────────────────────────────────────────────────────────────

def parse_metadata(path: Path) -> Dict:
    """Parse metadata.txt into a session dict."""
    raw: Dict[str, str] = {}

    with open(path) as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            # "for YYYYMMDD jam:"
            m = re.match(r'for\s+(\d{8})\s+jam', line, re.IGNORECASE)
            if m:
                raw['_date_compact'] = m.group(1)
                continue
            # "Key: value."  or "Published at YYYY-MM-DD."
            if ':' in line:
                key, _, val = line.partition(':')
                raw[key.strip().lower()] = val.strip().rstrip('.')

    missing = [k for k in ('_date_compact', 'crew', 'location', 'rating',
                            'summary', 'published at', 'explicit', 'duration', 'keywords')
               if k not in raw]
    if missing:
        die(f"metadata.txt missing required fields: {', '.join(missing)}")

    ds = raw['_date_compact']
    session_date = date(int(ds[:4]), int(ds[4:6]), int(ds[6:8]))

    try:
        rating_int = int(raw['rating'])
    except ValueError:
        die(f"Rating must be an integer 1-5, got: {raw['rating']!r}")
    if not 1 <= rating_int <= 5:
        die(f"Rating must be 1-5, got: {rating_int}")

    pub_raw = raw['published at'].strip()
    if re.match(r'^\d{8}$', pub_raw):
        pub_date = f"{pub_raw[:4]}-{pub_raw[4:6]}-{pub_raw[6:8]}"
    elif re.match(r'^\d{4}-\d{2}-\d{2}$', pub_raw):
        pub_date = pub_raw
    else:
        die(f"published_at must be YYYY-MM-DD or YYYYMMDD, got: {pub_raw!r}")

    dur_str = raw['duration'].strip()
    m = re.match(r'^(\d{1,2}):(\d{2}):(\d{2})$', dur_str)
    if not m:
        die(f"Duration must be HH:MM:SS, got: {dur_str!r}")
    dur_seconds = int(m.group(1)) * 3600 + int(m.group(2)) * 60 + int(m.group(3))

    crew_names = [n.strip().rstrip('.') for n in raw['crew'].split(',') if n.strip()]

    return {
        'date':          session_date,
        'date_str':      session_date.strftime('%Y-%m-%d'),
        'date_compact':  ds,
        'title':         session_date.strftime('%b') + ' ' + str(session_date.day),
        'crew_names':    crew_names,
        'crew_display':  ', '.join(crew_names),
        'location':      raw['location'],
        'rating_int':    rating_int,
        'rating_stars':  '*' * rating_int,
        'rating_dec':    float(rating_int),
        'summary':       raw['summary'],
        'published_at':  f"{pub_date} 00:00:00",
        'explicit':      1 if raw['explicit'].lower().startswith('y') else 0,
        'duration_str':  dur_str,
        'duration_sec':  dur_seconds,
        'keywords':      raw['keywords'],
    }


def parse_songlist(path: Path) -> List[Dict]:
    """Parse songlist.txt → [{seq, title}, ...] in position order."""
    songs = []
    with open(path) as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            # Format: N HH:MM:SS-HH:MM:SS Title [*]<br>
            m = re.match(r'^(\d+)\s+[\d:]+\s*-\s*[\d:]+\s+(.*)', line)
            if not m:
                m = re.match(r'^(\d+)\s+(.*)', line)
            if not m:
                continue
            seq = int(m.group(1))
            title = re.sub(r'\*?\s*<br>.*$', '', m.group(2), flags=re.IGNORECASE).strip()
            title = title.rstrip('*').strip()
            songs.append({'seq': seq, 'title': title})

    if not songs:
        die(f"No songs parsed from songlist: {path}")
    return songs


def scan_dir(dir_path: Path) -> Tuple[Dict[int, Path], Optional[Path]]:
    """
    Classify files in dir_path.
    Returns ({seq: path} for sequenced songs, whole_jam_path or None).
    """
    EXTS = {'.mp4', '.mov', '.mkv', '.avi', '.webm', '.m4v',
            '.mp3', '.wav', '.flac', '.aac', '.ogg', '.m4a'}
    songs: Dict[int, Path] = {}
    whole_jam: Optional[Path] = None

    for p in sorted(dir_path.iterdir()):
        if p.suffix.lower() not in EXTS:
            continue
        seq = extract_seq(p.name)
        if seq is not None:
            songs[seq] = p
        else:
            whole_jam = p

    if not songs:
        die(f"No sequenced song files found in {dir_path}")
    return songs, whole_jam


def resolve_participants(crew_names: List[str],
                         participants_csv: Path) -> Dict[str, int]:
    """Resolve crew display names → participant_ids. Aborts on unknown names."""
    rows = load_csv(participants_csv)
    lookup = {r['name'].lower().strip(): int(r['participant_id']) for r in rows}

    resolved: Dict[str, int] = {}
    unknown: List[str] = []

    for name in crew_names:
        pid = lookup.get(name.lower().strip())
        if pid is not None:
            resolved[name] = pid
        else:
            unknown.append(name)

    if unknown:
        next_pid = max(int(r['participant_id']) for r in rows) + 1
        print("\n\u274c  Unknown participants — add these rows to participants.csv first:\n",
              file=sys.stderr)
        for i, name in enumerate(unknown):
            print(f"     {next_pid + i},{name}", file=sys.stderr)
        sys.exit(1)

    return resolved


# ── SQL builder ────────────────────────────────────────────────────────────────

def build_sql(session_id: int, meta: Dict,
              file_data: List[Dict], song_data: List[Dict]) -> str:
    lines = [
        "-- Generated by add_jam_session.py",
        f"-- Session {session_id} ({meta['date_str']})",
        "",
        "USE music_db;",
        "",
    ]

    # 1) Event — ON DUPLICATE KEY UPDATE handles auto-created placeholder rows
    lines += [
        "-- 1) Insert/update event",
        "INSERT INTO events",
        "  (event_id, event_date, org_name, event_type, title,",
        "   cover_image_url, location, rating, summary, published_at,",
        "   explicit, duration_seconds, keywords)",
        "VALUES (",
        f"  {session_id},",
        f"  '{meta['date_str']}',",
        f"  '{sql_esc(meta['org'])}',",
        f"  'band',",
        f"  '{sql_esc(meta['title'])}',",
        f"  'images/jam/{meta['date_compact']}.jpg',",
        f"  '{sql_esc(meta['location'])}',",
        f"  {meta['rating_dec']},",
        f"  '{sql_esc(meta['summary'])}',",
        f"  '{meta['published_at']}',",
        f"  {meta['explicit']},",
        f"  {meta['duration_sec']},",
        f"  '{sql_esc(meta['keywords'])}'",
        ")",
        "ON DUPLICATE KEY UPDATE",
        "  event_date=VALUES(event_date), org_name=VALUES(org_name),",
        "  event_type=VALUES(event_type), title=VALUES(title),",
        "  cover_image_url=VALUES(cover_image_url), location=VALUES(location),",
        "  rating=VALUES(rating), summary=VALUES(summary),",
        "  published_at=VALUES(published_at), explicit=VALUES(explicit),",
        "  duration_seconds=VALUES(duration_seconds), keywords=VALUES(keywords);",
        "",
    ]

    # 2) Assets (one per file)
    lines.append("-- 2) Insert assets")
    for fd in file_data:
        sha_val  = f"'{sql_esc(fd['checksum_sha256'])}'" if fd['checksum_sha256'] else 'NULL'
        dur_val  = str(fd['duration_seconds']) if fd['duration_seconds'] is not None else 'NULL'
        info_val = f"'{sql_esc(fd['media_info'])}'"      if fd['media_info']     else 'NULL'
        tool_val = f"'{sql_esc(fd['media_info_tool'])}'" if fd['media_info_tool'] else 'NULL'
        lines.append(
            f"INSERT IGNORE INTO assets"
            f" (asset_id, file_name, source_relpath, checksum_sha256,"
            f" file_type, duration_seconds, media_info, media_info_tool)"
            f" VALUES ({fd['asset_id']}, '{sql_esc(fd['file_name'])}',"
            f" '{sql_esc(fd['source_relpath'])}', {sha_val},"
            f" '{fd['file_type']}', {dur_val}, {info_val}, {tool_val});"
        )
    lines.append("")

    # 3) Event items (one per song, with position)
    lines.append("-- 3) Insert event_items")
    for sd in song_data:
        pos_val = str(sd['position']) if sd['position'] is not None else 'NULL'
        lines.append(
            f"INSERT IGNORE INTO event_items"
            f" (event_id, asset_id, item_type, label, position)"
            f" VALUES ({session_id}, {sd['asset_id']},"
            f" '{sd['item_type']}', '{sql_esc(sd['title'])}', {pos_val});"
        )
    lines.append("")

    # 4) Event participants
    lines.append("-- 4) Insert event_participants")
    for name, pid in meta['participants'].items():
        lines.append(
            f"INSERT IGNORE INTO event_participants (event_id, participant_id)"
            f" VALUES ({session_id}, {pid});"
        )
    lines.append("")

    # 5) Verification query
    asset_ids = ', '.join(str(fd['asset_id']) for fd in file_data)
    lines += [
        "-- 5) Verify counts",
        f"SELECT 'events'            AS tbl, COUNT(*) AS cnt FROM events WHERE event_id = {session_id}",
        f"UNION ALL",
        f"SELECT 'assets',                   COUNT(*) FROM assets WHERE asset_id IN ({asset_ids})",
        f"UNION ALL",
        f"SELECT 'event_items',              COUNT(*) FROM event_items WHERE event_id = {session_id}",
        f"UNION ALL",
        f"SELECT 'event_participants',        COUNT(*) FROM event_participants WHERE event_id = {session_id}",
        f"UNION ALL",
        f"SELECT 'positions_set',             COUNT(*) FROM event_items WHERE event_id = {session_id} AND position IS NOT NULL;",
    ]

    return '\n'.join(lines) + '\n'


def apply_sql(target: str, sql: str, dry_run: bool) -> str:
    if dry_run:
        print("\n\u2500\u2500 [dry-run] SQL that would be applied:\n")
        print(sql)
        return ""

    # Write locally, scp, execute via docker exec
    with tempfile.NamedTemporaryFile(mode='w', suffix='.sql',
                                     prefix='add_session_', delete=False) as f:
        f.write(sql)
        tmp_local = f.name

    try:
        scp = subprocess.run(
            ['scp', tmp_local, f"{target}:/tmp/add_session.sql"],
            capture_output=True, text=True
        )
        if scp.returncode != 0:
            die(f"scp failed: {scp.stderr.strip()}")

        result = subprocess.run(
            ['ssh', target,
             "docker exec -i mysqlServer sh -c "
             "'MYSQL_PWD=\"$MYSQL_PASSWORD\" mysql -u appuser music_db < /tmp/add_session.sql'"],
            capture_output=True, text=True
        )
        output = result.stdout + result.stderr
        # mysql prints a password warning to stderr — not a real error
        real_error = [ln for ln in result.stderr.splitlines()
                      if ln and 'Warning' not in ln and 'password' not in ln.lower()]
        if result.returncode != 0 and real_error:
            die(f"MySQL execution failed:\n" + '\n'.join(real_error))
        return output
    finally:
        os.unlink(tmp_local)


# ── Main ───────────────────────────────────────────────────────────────────────

def main() -> None:
    ap = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter
    )
    ap.add_argument('--dir',      required=True,        help='Local directory of final video files')
    ap.add_argument('--songs',    required=True,        help='Path to songlist.txt')
    ap.add_argument('--meta',     required=True,        help='Path to metadata.txt')
    ap.add_argument('--ssh',      required=True,        help='SSH target (user@host)')
    ap.add_argument('--csv-dir',  required=True,        help='Path to prepped_csvs/full/')
    ap.add_argument('--repo-dir', default='.',          help='Git repo root (default: cwd)')
    ap.add_argument('--org',      default='StormPigs',  help='org_name for events table (default: StormPigs)')
    ap.add_argument('--dry-run',  action='store_true',  help='Print plan; make no changes')
    ap.add_argument('--no-push',  action='store_true',  help='Skip git commit/push')
    args = ap.parse_args()

    dry_run  = args.dry_run
    csv_dir  = Path(args.csv_dir)
    repo_dir = Path(args.repo_dir).resolve()
    dir_path = Path(args.dir).expanduser().resolve()

    if dry_run:
        print("\n\u2500\u2500 DRY RUN \u2014 no files, CSVs, or DB will be modified \u2500\u2500")

    # ── Step 1: Parse inputs ───────────────────────────────────────────────────
    section("Step 1 \u2014 Parsing inputs")
    meta = parse_metadata(Path(args.meta).expanduser())
    meta['org'] = args.org
    songs_list   = parse_songlist(Path(args.songs).expanduser())
    song_files_map, whole_jam_path = scan_dir(dir_path)

    info(f"Session date  : {meta['date_str']}")
    info(f"Org           : {meta['org']}")
    info(f"Title         : {meta['title']}")
    info(f"Crew          : {meta['crew_display']}")
    info(f"Song files    : {len(song_files_map)} sequenced + {'1 whole-jam' if whole_jam_path else 'no whole-jam'}")
    info(f"Songlist lines: {len(songs_list)}")
    if len(song_files_map) != len(songs_list):
        print(f"  \u26a0\ufe0f  File count ({len(song_files_map)}) \u2260 songlist count ({len(songs_list)}) \u2014 unmatched entries will be skipped")

    # ── Step 2: Determine next IDs ─────────────────────────────────────────────
    section("Step 2 \u2014 Determining next IDs from CSVs")
    sessions_rows = load_csv(csv_dir / 'sessions.csv')
    songs_rows    = load_csv(csv_dir / 'songs.csv')
    files_rows    = load_csv(csv_dir / 'files.csv')

    next_session_id = next_id(sessions_rows, 'session_id')
    next_song_id    = next_id(songs_rows,    'song_id')
    next_file_id    = next_id(files_rows,    'file_id')

    info(f"next_session_id : {next_session_id}")
    info(f"next_song_id    : {next_song_id}")
    info(f"next_file_id    : {next_file_id}")

    # Guard: session date must not already exist in sessions.csv
    existing_dates = {r['date'].strip() for r in sessions_rows}
    if meta['date_str'] in existing_dates:
        die(f"Session date {meta['date_str']} already exists in sessions.csv — aborting to prevent duplicate")

    # ── Step 3: Resolve participants ───────────────────────────────────────────
    section("Step 3 \u2014 Resolving participants")
    participants = resolve_participants(meta['crew_names'], csv_dir / 'participants.csv')
    meta['participants'] = participants
    for name, pid in participants.items():
        info(f"  {name} \u2192 participant_id {pid}")

    # ── Step 4: Compute SHA256 locally ─────────────────────────────────────────
    section("Step 4 \u2014 Computing SHA256 locally")
    sha256_map: Dict[int, str] = {}
    for seq, path in sorted(song_files_map.items()):
        sha = sha256_file(path) if not dry_run else f"<sha256:{path.name}>"
        sha256_map[seq] = sha
        info(f"  [{seq}] {path.name} \u2192 {sha[:16]}\u2026")

    wj_sha: Optional[str] = None
    if whole_jam_path:
        wj_sha = sha256_file(whole_jam_path) if not dry_run else f"<sha256:{whole_jam_path.name}>"
        info(f"  [wj] {whole_jam_path.name} \u2192 {wj_sha[:16]}\u2026")

    # ── Step 5: rsync to prod ──────────────────────────────────────────────────
    section("Step 5 \u2014 rsyncing files to production")
    rsync_to_prod(dir_path, args.ssh, meta['date_compact'], dry_run)

    # ── Step 6: ffprobe remotely ───────────────────────────────────────────────
    section("Step 6 \u2014 Running ffprobe on production")
    video_root = f"/home/ubuntu/video/{meta['date_compact']}"
    ffprobe_map: Dict[int, Tuple] = {}

    for seq, path in sorted(song_files_map.items()):
        remote = f"{video_root}/{path.name}"
        if not dry_run:
            dur, info_json, tool = ffprobe_remote(args.ssh, remote)
        else:
            dur, info_json, tool = None, '{}', 'ffprobe dry-run'
        ffprobe_map[seq] = (dur, info_json, tool)
        info(f"  [{seq}] {path.name} \u2192 {dur}s")

    wj_ffprobe: Optional[Tuple] = None
    if whole_jam_path:
        remote_wj = f"{video_root}/{whole_jam_path.name}"
        if not dry_run:
            wj_ffprobe = ffprobe_remote(args.ssh, remote_wj)
        else:
            wj_ffprobe = (None, '{}', 'ffprobe dry-run')
        info(f"  [wj] {whole_jam_path.name} \u2192 {wj_ffprobe[0]}s")

    # ── Step 7: Build rows ─────────────────────────────────────────────────────
    section("Step 7 \u2014 Building CSV + SQL rows")
    VIDEO_EXTS = {'mp4', 'mov', 'mkv', 'avi', 'webm', 'm4v'}

    files_csv_rows  : List[List] = []
    songs_csv_rows  : List[List] = []
    ss_csv_rows     : List[List] = []  # session_songs
    sf_csv_rows     : List[List] = []  # song_files
    ep_csv_rows     : List[List] = []  # event_participants

    file_data_rows  : List[Dict] = []  # for SQL
    song_data_rows  : List[Dict] = []  # for SQL

    fid      = next_file_id
    song_id  = next_song_id
    sess_id  = next_session_id

    # Process songs in songlist order (matched by sequence number N)
    for song_entry in sorted(songs_list, key=lambda x: x['seq']):
        seq  = song_entry['seq']
        path = song_files_map.get(seq)
        if path is None:
            print(f"  \u26a0\ufe0f  No file for songlist seq={seq} ('{song_entry['title']}') \u2014 skipping")
            continue

        sha            = sha256_map[seq]
        dur, mjson, mtool = ffprobe_map[seq]
        ftype          = 'video' if path.suffix.lower().lstrip('.') in VIDEO_EXTS else 'audio'
        source_relpath = f"{meta['date_compact']}/{path.name}"

        files_csv_rows.append([
            fid, path.name, source_relpath, sha, ftype,
            dur if dur is not None else '', mjson, mtool,
        ])
        file_data_rows.append({
            'asset_id': fid, 'file_name': path.name, 'source_relpath': source_relpath,
            'checksum_sha256': sha, 'file_type': ftype,
            'duration_seconds': dur, 'media_info': mjson, 'media_info_tool': mtool,
        })
        songs_csv_rows.append([song_id, song_entry['title'], 'song', '', '', ''])
        ss_csv_rows.append([sess_id, song_id, seq])
        sf_csv_rows.append([song_id, fid])
        song_data_rows.append({
            'asset_id': fid, 'title': song_entry['title'],
            'item_type': 'song', 'position': seq,
        })
        info(f"  [{seq}] song_id={song_id}  file_id={fid}  '{song_entry['title']}'")
        fid     += 1
        song_id += 1

    # Whole-jam file (event_label, position=NULL)
    if whole_jam_path and wj_sha and wj_ffprobe:
        dur, mjson, mtool = wj_ffprobe
        ftype          = 'video' if whole_jam_path.suffix.lower().lstrip('.') in VIDEO_EXTS else 'audio'
        source_relpath = f"{meta['date_compact']}/{whole_jam_path.name}"
        wj_label       = f"{meta['date_str']} Entire Jam"

        files_csv_rows.append([
            fid, whole_jam_path.name, source_relpath, wj_sha, ftype,
            dur if dur is not None else '', mjson, mtool,
        ])
        file_data_rows.append({
            'asset_id': fid, 'file_name': whole_jam_path.name,
            'source_relpath': source_relpath,
            'checksum_sha256': wj_sha, 'file_type': ftype,
            'duration_seconds': dur, 'media_info': mjson, 'media_info_tool': mtool,
        })
        songs_csv_rows.append([song_id, wj_label, 'event_label', '', '', ''])
        ss_csv_rows.append([sess_id, song_id, ''])   # no position
        sf_csv_rows.append([song_id, fid])
        song_data_rows.append({
            'asset_id': fid, 'title': wj_label,
            'item_type': 'clip', 'position': None,
        })
        info(f"  [wj] song_id={song_id}  file_id={fid}  '{wj_label}'")
        fid     += 1
        song_id += 1

    # Sessions.csv row (column order from load_and_transform.sql step 1)
    session_csv_row = [
        sess_id, meta['title'], meta['date_str'], meta['org'], 'band', '',
        f"images/jam/{meta['date_compact']}.jpg",
        meta['crew_display'], meta['location'], meta['rating_stars'],
        meta['summary'], meta['published_at'], meta['explicit'],
        meta['duration_str'], meta['keywords'],
    ]

    # Event participants
    for name, pid in participants.items():
        ep_csv_rows.append([sess_id, pid])

    if dry_run:
        print("\n\u2500\u2500 [dry-run] sessions.csv row:")
        print("  ", session_csv_row)
        print("\n\u2500\u2500 [dry-run] songs.csv rows:")
        for r in songs_csv_rows:
            print("  ", r)
        print("\n\u2500\u2500 [dry-run] session_songs.csv rows:")
        for r in ss_csv_rows:
            print("  ", r)
        print("\n\u2500\u2500 [dry-run] song_files.csv rows:")
        for r in sf_csv_rows:
            print("  ", r)
        print("\n\u2500\u2500 [dry-run] files.csv rows (sha/json truncated):")
        for r in files_csv_rows:
            print("  ", r[:5], "…")
        print("\n\u2500\u2500 [dry-run] event_participants.csv rows:")
        for r in ep_csv_rows:
            print("  ", r)

    # ── Step 8: Write CSVs ─────────────────────────────────────────────────────
    if not dry_run:
        section("Step 8 \u2014 Writing CSVs")
        append_csv_rows(csv_dir / 'sessions.csv',          [session_csv_row])
        append_csv_rows(csv_dir / 'songs.csv',             songs_csv_rows)
        append_csv_rows(csv_dir / 'session_songs.csv',     ss_csv_rows)
        append_csv_rows(csv_dir / 'song_files.csv',        sf_csv_rows)
        append_csv_rows(csv_dir / 'files.csv',             files_csv_rows)
        append_csv_rows(csv_dir / 'event_participants.csv', ep_csv_rows)
        info("All 6 CSVs updated \u2713")

    # ── Step 9: Apply SQL ──────────────────────────────────────────────────────
    section("Step 9 \u2014 Applying SQL to production")
    sql    = build_sql(sess_id, meta, file_data_rows, song_data_rows)
    output = apply_sql(args.ssh, sql, dry_run)

    # ── Step 10: Print verification ────────────────────────────────────────────
    if not dry_run and output:
        section("Step 10 \u2014 Verification")
        for line in output.splitlines():
            if line.strip():
                print(f"  {line}")

    # ── Step 11: Git commit + push ─────────────────────────────────────────────
    if not dry_run and not args.no_push:
        section("Step 11 \u2014 Git commit + push")
        subprocess.run(
            ['git', 'add', str(csv_dir.resolve())],
            cwd=str(repo_dir), check=True
        )
        subprocess.run(
            ['git', 'commit', '-m',
             f"data: add session {sess_id} ({meta['date_str']}) to all CSVs"],
            cwd=str(repo_dir), check=True
        )
        subprocess.run(['git', 'push'], cwd=str(repo_dir), check=True)
        info("Committed and pushed \u2713")
    elif dry_run:
        info("[dry-run] git commit/push skipped")

    print(f"\n\u2705  Session {sess_id} ({meta['date_str']}) \u2014 "
          f"{'dry run complete' if dry_run else 'done!'}\n")


if __name__ == '__main__':
    main()
