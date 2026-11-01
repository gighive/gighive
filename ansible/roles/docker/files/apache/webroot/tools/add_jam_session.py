#!/usr/bin/env python3
"""
add_jam_session.py - add one StormPigs jam to the live DB AND to the canonical
prepped CSVs (the source of truth for a rebuild_mysql_data rebuild) in one command.

Run it on the machine that holds the final StormPigsYYYYMMDD_N_slug.mp4 files,
from an up-to-date gighive checkout:

    python3 ansible/roles/docker/files/apache/webroot/tools/add_jam_session.py \\
        --dir ~/Media/Proxy/20261019 --ssh prod --dry-run

Inputs (in --dir unless overridden):
    StormPigsYYYYMMDD_N_slug.mp4        the singles; N = song position
    songlist.txt                        one line per song, in order; any of:
                                          N HH:MM:SS-HH:MM:SS title [*]
                                          SOURCE.mov HH:MM:SS-HH:MM:SS title [*]
                                          title [*]
    StormPigsYYYYMMDD_metadata.txt      created interactively on first run

See docs/reference/database_append_to_preexisting_csvs.md for details.
"""

import argparse
import csv
import hashlib
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from datetime import date, datetime
from pathlib import Path
from typing import Dict, List, Optional, Tuple

SCRIPT_PATH = Path(__file__).resolve()
WEBROOT = SCRIPT_PATH.parents[1]
DEFAULT_CSV_DIR = SCRIPT_PATH.parents[3] / 'mysql' / 'externalConfigs' / 'prepped_csvs' / 'full'
CACHE_DIR = Path(os.environ.get('XDG_CACHE_HOME', str(Path.home() / '.cache'))) / 'add_jam_session'

VIDEO_EXTS = {'mp4', 'mov', 'mkv', 'avi', 'webm', 'm4v'}
AUDIO_EXTS = {'mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a'}
MEDIA_EXTS = VIDEO_EXTS | AUDIO_EXTS
SONG_FILE_RE = re.compile(
    r'^[A-Za-z]*(?P<date>\d{8})_0*(?P<seq>\d+)(?:_(?P<slug>[^.]+))?\.(?P<ext>[A-Za-z0-9]+)$')
TS = r'(\d{1,2}:\d{2}(?::\d{2})?)'
META_FIELDS = ('crew', 'location', 'rating', 'summary', 'published at', 'explicit', 'keywords')


# ── Output / prompt helpers ────────────────────────────────────────────────────

def die(msg: str) -> None:
    print(f"\nERROR: {msg}", file=sys.stderr)
    sys.exit(1)


def info(msg: str) -> None:
    print(f"     {msg}")


def warn(msg: str) -> None:
    print(f"  !! {msg}")


def section(title: str) -> None:
    print(f"\n── {title}")


def interactive() -> bool:
    return sys.stdin.isatty()


def ask(text: str, default: str = '', required: bool = False) -> str:
    while True:
        val = input(f"{text}{f' [{default}]' if default else ''}: ").strip()
        if not val:
            val = default
        if val or not required:
            return val
        print("  (required)")


def ask_yes_no(text: str, default: bool = False) -> bool:
    while True:
        val = input(f"{text} ({'Y/n' if default else 'y/N'}): ").strip().lower()
        if not val:
            return default
        if val in ('y', 'yes'):
            return True
        if val in ('n', 'no'):
            return False


# ── Value parsing ──────────────────────────────────────────────────────────────

def slugify(s: str) -> str:
    return re.sub(r'[^a-z0-9]', '', s.lower())


def parse_hms(s: str) -> Optional[int]:
    parts = [int(p) for p in s.split(':')]
    if len(parts) == 2:
        parts = [0] + parts
    if len(parts) != 3:
        return None
    return parts[0] * 3600 + parts[1] * 60 + parts[2]


def fmt_hms(sec: int) -> str:
    return f"{sec // 3600:02d}:{sec % 3600 // 60:02d}:{sec % 60:02d}"


def parse_rating(raw: str) -> Tuple[str, Optional[float]]:
    """'***', '** 1/2', '2.5', '3', '' -> (stars string for CSV, decimal for DB)."""
    s = raw.strip().replace('★', '*').replace('½', ' 1/2').lower()
    if not s:
        return '', None
    if re.fullmatch(r'\d+(\.\d+)?', s):
        val = float(s)
    else:
        stars = s.count('*')
        half = bool(re.search(r'(^|[^0-9])1/2([^0-9]|$)|half', s))
        if not stars and not half:
            die(f"Can't read rating {raw!r}; use e.g. '***', '** 1/2' or 2.5")
        val = stars + (0.5 if half else 0.0)
    if not 1 <= val <= 5 or (val * 2) % 1:
        die(f"Rating must be 1-5 in half steps, got {raw!r}")
    stars_str = '*' * int(val) + (' 1/2' if val % 1 else '')
    return stars_str, val


def parse_published(raw: str) -> str:
    s = raw.strip().rstrip('.')
    for fmt in ('%Y-%m-%d %H:%M:%S', '%Y-%m-%d %H:%M', '%Y-%m-%d', '%Y%m%d'):
        try:
            return datetime.strptime(s, fmt).strftime('%Y-%m-%d %H:%M:%S')
        except ValueError:
            pass
    die(f"Published date must be YYYY-MM-DD [HH:MM:SS], got {raw!r}")
    return ''


def parse_explicit(raw: str) -> int:
    return 1 if raw.strip().lower().startswith(('y', '1', 't')) else 0


# ── Metadata file ──────────────────────────────────────────────────────────────

def read_metadata_file(path: Path) -> Dict[str, str]:
    raw: Dict[str, str] = {}
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line:
            continue
        m = re.match(r'for\s+(\d{8})\s+jam', line, re.IGNORECASE)
        if m:
            raw['date'] = m.group(1)
            continue
        m = re.match(r'published\s+at\s*:?\s*(.+)$', line, re.IGNORECASE)
        if m:
            raw['published at'] = m.group(1).strip().rstrip('.')
            continue
        if ':' in line:
            key, _, val = line.partition(':')
            raw[key.strip().lower()] = val.strip()
    for k in ('crew', 'location', 'rating', 'explicit', 'keywords', 'duration'):
        if k in raw:
            raw[k] = raw[k].rstrip('.').strip()
    return raw


def write_metadata_file(path: Path, date_compact: str, m: Dict[str, str]) -> None:
    text = (
        f"for {date_compact} jam:\n"
        f"crew: {m['crew']}.\n"
        f"location: {m['location']}.\n"
        f"Rating: {m['rating']}.\n"
        f"Summary: {m['summary']}\n"
        f"Published at {m['published at']}.\n"
        f"Explicit: {m['explicit']}.\n"
        f"Keywords: {m['keywords']}.\n"
    )
    path.write_text(text)


def load_or_prompt_metadata(path: Path, date_compact: str, known_names: List[str]) -> Dict[str, str]:
    raw = read_metadata_file(path) if path.exists() else {}
    if raw.get('date') and raw['date'] != date_compact:
        die(f"{path.name} is for {raw['date']} but the files are for {date_compact}")
    missing = [k for k in META_FIELDS if k not in raw]
    if not missing:
        info(f"Using {path}")
        return raw
    if not interactive():
        die(f"{path} is missing {', '.join(missing)} (run interactively to be asked)")

    print(f"\n  Jam details for {date_compact} (saved to {path} for re-runs)")
    print(f"  Known musicians: {', '.join(known_names)}")
    defaults = {'explicit': 'No', 'keywords': 'stormpigs, rock, jam',
                'published at': date.today().isoformat()}
    labels = {
        'crew': 'Musicians, comma-separated (e.g. "Pchoff, Snuffler, Stu, Tbonk")',
        'location': 'Location (e.g. "Smash Studios, NYC")',
        'rating': 'Rating (e.g. "***", "** 1/2", 2.5; blank for none)',
        'summary': 'Summary',
        'published at': 'Publish date (YYYY-MM-DD)',
        'explicit': 'Explicit? (Yes/No)',
        'keywords': 'Keywords, comma-separated',
    }
    for k in missing:
        val = ask('  ' + labels[k], defaults.get(k, ''), required=k in ('crew', 'location', 'summary'))
        if k == 'rating':
            val = parse_rating(val)[0]
        elif k == 'explicit':
            val = 'Yes' if parse_explicit(val) else 'No'
        elif k == 'published at':
            val = parse_published(val)[:10]
        raw[k] = val.strip().rstrip('.') if k != 'summary' else val.strip()
    write_metadata_file(path, date_compact, raw)
    info(f"Saved {path}")
    return raw


# ── Songlist + media files ─────────────────────────────────────────────────────

def parse_songlist(path: Path) -> List[Dict]:
    songs: List[Dict] = []
    for n, line in enumerate(path.read_text().splitlines(), 1):
        line = re.sub(r'<br\s*/?>\s*$', '', line.strip(), flags=re.IGNORECASE).strip()
        if not line:
            continue
        lead = start = end = None
        m = re.match(rf'^(?:(\S+)\s+)?{TS}\s*-\s*{TS}\s+(.+)$', line)
        if m:
            lead, start, end, title = m.groups()
        else:
            m = re.match(r'^(\d+)\s+(.+)$', line)
            lead, title = (m.group(1), m.group(2)) if m else (None, line)
        star = title.rstrip().endswith('*')
        base = title.strip().rstrip('*').strip()
        if not base:
            die(f"{path.name} line {n}: no song title")
        if '\\' in base:
            die(f"{path.name} line {n}: backslashes aren't allowed in titles")
        seg = None
        if start and end:
            s, e = parse_hms(start), parse_hms(end)
            seg = e - s if s is not None and e is not None else None
        songs.append({
            'line': n, 'pos': int(lead) if lead and lead.isdigit() else None,
            'source': lead if lead and not lead.isdigit() else '',
            'title': f"{base} *" if star else base, 'base': base, 'star': star, 'segment': seg,
        })
    if not songs:
        die(f"No songs found in {path}")
    numbered = [s['pos'] is not None for s in songs]
    if any(numbered) and not all(numbered):
        die(f"{path.name}: some lines start with a position number and some don't")
    if not any(numbered):
        for i, s in enumerate(songs, 1):
            s['pos'] = i
    dupes = sorted({s['pos'] for s in songs if [x['pos'] for x in songs].count(s['pos']) > 1})
    if dupes:
        die(f"{path.name}: duplicate positions {dupes}")
    return songs


def scan_dir(dir_path: Path) -> Tuple[str, Dict[int, Path], List[str]]:
    files: Dict[int, Path] = {}
    dates = set()
    ignored: List[str] = []
    for p in sorted(dir_path.iterdir()):
        if not p.is_file() or p.name.startswith('.'):
            continue
        m = SONG_FILE_RE.match(p.name)
        if not m or m.group('ext').lower() not in MEDIA_EXTS:
            if p.suffix.lower().lstrip('.') in MEDIA_EXTS | {'ts'}:
                ignored.append(p.name)
            continue
        seq = int(m.group('seq'))
        if seq in files:
            die(f"Two files for position {seq}: {files[seq].name} and {p.name}")
        files[seq] = p
        dates.add(m.group('date'))
    if not files:
        die(f"No StormPigsYYYYMMDD_N_slug media files in {dir_path}")
    if len(dates) > 1:
        die(f"Files in {dir_path} span several dates: {sorted(dates)}")
    return dates.pop(), files, ignored


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(8 * 1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()


def ffprobe_version() -> str:
    if not shutil.which('ffprobe'):
        die("ffprobe not found on this machine (install ffmpeg)")
    out = subprocess.run(['ffprobe', '-version'], capture_output=True, text=True).stdout
    return out.splitlines()[0].strip() if out else 'ffprobe'


def probe(path: Path) -> Dict:
    r = subprocess.run(['ffprobe', '-v', 'quiet', '-print_format', 'json',
                        '-show_streams', '-show_format', str(path)], capture_output=True, text=True)
    try:
        return json.loads(r.stdout)
    except json.JSONDecodeError:
        die(f"ffprobe failed on {path}")
    return {}


def file_facts(paths: Dict[str, Path], cache_name: str) -> Dict[str, Dict]:
    """sha256 + ffprobe per file, cached by name/size/mtime so re-runs are fast."""
    cache_path = CACHE_DIR / f"{cache_name}.json"
    try:
        cache = json.loads(cache_path.read_text())
    except (OSError, ValueError):
        cache = {}
    tool = ffprobe_version()
    facts: Dict[str, Dict] = {}
    for key, p in paths.items():
        st = p.stat()
        c = cache.get(p.name)
        if not (c and c['size'] == st.st_size and c['mtime'] == st.st_mtime_ns):
            print(f"     hashing + probing {p.name} ...", flush=True)
            data = probe(p)
            dur = data.get('format', {}).get('duration')
            c = {'size': st.st_size, 'mtime': st.st_mtime_ns, 'sha256': sha256_file(p),
                 'duration': int(float(dur)) if dur else None, 'probe': data, 'tool': tool}
            cache[p.name] = c
        facts[key] = c
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    cache_path.write_text(json.dumps(cache))
    return facts


def thumbnail_timestamp(dur: Optional[int], sha: str) -> float:
    """Same frame choice as MediaProbeService::pickThumbnailTimestamp()."""
    dur = dur or 0
    if dur <= 0:
        return 0.0
    if dur < 4:
        return dur / 2.0
    start = min(2.0, dur * 0.10)
    end = max(dur - 2.0, start)
    t = start + (int(sha[:8], 16) / 0xFFFFFFFF) * (end - start)
    return float(min(max(t, 0.0), dur))


# ── CSV helpers ────────────────────────────────────────────────────────────────

def load_csv(path: Path) -> List[Dict]:
    with open(path, newline='') as f:
        return list(csv.DictReader(f))


def max_id(rows: List[Dict], field: str) -> int:
    return max([int(r[field]) for r in rows if (r.get(field) or '').strip().isdigit()] or [0])


def append_csv_rows(path: Path, rows: List[List]) -> None:
    """Append rows with \\r\\n line endings (required by LOAD DATA in load_and_transform.sql)."""
    if not rows:
        return
    with open(path, 'rb') as f:
        f.seek(0, 2)
        needs_newline = f.tell() > 0 and (f.seek(-1, 2) or True) and f.read(1) != b'\n'
    buf = io.StringIO()
    csv.writer(buf, lineterminator='\r\n').writerows(rows)
    with open(path, 'a', newline='') as f:
        if needs_newline:
            f.write('\r\n')
        f.write(buf.getvalue())


# ── Remote (prod) helpers ──────────────────────────────────────────────────────

def sql_lit(v) -> str:
    if v is None:
        return 'NULL'
    if isinstance(v, (int, float)):
        return str(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"


class Remote:
    def __init__(self, target: str, mysql_container: str, apache_container: str, video_dir: str):
        self.target = target
        self.mysql_container = mysql_container
        self.apache_container = apache_container
        self.video_dir = video_dir.rstrip('/')

    def ssh(self, command: str, input_text: Optional[str] = None) -> str:
        r = subprocess.run(['ssh', self.target, command], input=input_text,
                           capture_output=True, text=True)
        if r.returncode != 0:
            die(f"ssh {self.target} failed: {command[:120]}\n{r.stderr.strip()}")
        return r.stdout

    def mysql(self, sql: str) -> str:
        cmd = (f"docker exec -i {self.mysql_container} sh -c "
               "'MYSQL_PWD=\"$MYSQL_PASSWORD\" mysql --default-character-set=utf8mb4 -N -B "
               "-u\"${MYSQL_USER:-appuser}\" \"${MYSQL_DATABASE:-media_db}\"'")
        r = subprocess.run(['ssh', self.target, cmd], input=sql, capture_output=True, text=True)
        errors = [ln for ln in r.stderr.splitlines()
                  if ln.strip() and 'Using a password' not in ln]
        if r.returncode != 0:
            die("MySQL on prod failed:\n" + '\n'.join(errors or [r.stderr.strip()]))
        return r.stdout

    def rsync(self, src_dir: Path, dest_subdir: str = '') -> None:
        dest = f"{self.target}:{self.video_dir}/{dest_subdir}"
        cmd = ['rsync', '-rtL', '--ignore-existing', '--partial-dir=.rsync-partial',
               '--chmod=F644', '--info=progress2', str(src_dir) + '/', dest]
        if subprocess.run(cmd).returncode != 0:
            die("rsync failed; re-running the script resumes the upload")


def rows_by_tag(output: str) -> Dict[str, List[List[str]]]:
    tagged: Dict[str, List[List[str]]] = {}
    for line in output.splitlines():
        cols = line.split('\t')
        tagged.setdefault(cols[0], []).append(cols[1:])
    return tagged


def read_live_state(remote: Remote, date_str: str, org: str, shas: List[str]) -> Dict:
    sha_list = ', '.join(sql_lit(s) for s in shas)
    sql = f"""
SELECT 'count', 'events', COUNT(*), IFNULL(MAX(event_id), 0) FROM events;
SELECT 'count', 'assets', COUNT(*), IFNULL(MAX(asset_id), 0) FROM assets;
SELECT 'count', 'participants', COUNT(*), IFNULL(MAX(participant_id), 0) FROM participants;
SELECT 'count', 'event_participants', COUNT(*), 0 FROM event_participants;
SELECT 'count', 'event_items', COUNT(*), 0 FROM event_items;
SELECT 'event', event_id, org_name FROM events WHERE event_date = {sql_lit(date_str)} AND org_name = {sql_lit(org)};
SELECT 'asset', asset_id, checksum_sha256 FROM assets WHERE checksum_sha256 IN ({sha_list});
SELECT 'participant', participant_id, name FROM participants;
"""
    t = rows_by_tag(remote.mysql(sql))
    return {
        'counts': {r[0]: (int(r[1]), int(r[2])) for r in t.get('count', [])},
        'event_on_date': t.get('event', []),
        'assets_with_sha': {r[1]: int(r[0]) for r in t.get('asset', [])},
        'participants': {r[1]: int(r[0]) for r in t.get('participant', [])},
    }


def csv_state(csv: Dict[str, List[Dict]]) -> Dict[str, Tuple[int, int]]:
    song_to_session = {r['song_id']: r['session_id'] for r in csv['session_songs']}
    items = {(song_to_session[r['song_id']], r['file_id'])
             for r in csv['song_files'] if r['song_id'] in song_to_session}
    return {
        'events': (len(csv['sessions']), max_id(csv['sessions'], 'session_id')),
        'assets': (len(csv['files']), max_id(csv['files'], 'file_id')),
        'participants': (len(csv['participants']), max_id(csv['participants'], 'participant_id')),
        'event_participants': (len(csv['event_participants']), 0),
        'event_items': (len(items), 0),
    }


# ── git helpers ────────────────────────────────────────────────────────────────

def git(repo: Path, *args: str, check: bool = True) -> str:
    r = subprocess.run(['git', *args], cwd=str(repo), capture_output=True, text=True)
    if check and r.returncode != 0:
        die(f"git {' '.join(args)} failed:\n{r.stderr.strip()}")
    return r.stdout.strip()


# ── Main ───────────────────────────────────────────────────────────────────────

def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--dir', required=True, help='Folder with the StormPigsYYYYMMDD_N_slug.mp4 singles')
    ap.add_argument('--ssh', required=True, help='SSH target for prod (e.g. prod or ubuntu@host)')
    ap.add_argument('--songs', help='Songlist (default: <dir>/songlist.txt)')
    ap.add_argument('--meta', help='Metadata file (default: <dir>/StormPigsYYYYMMDD_metadata.txt; created if missing)')
    ap.add_argument('--image', help='Jam image (default: webroot/images/jam/YYYYMMDD.jpg, then <dir>/YYYYMMDD.jpg)')
    ap.add_argument('--whole-jam', help='Optional whole-jam file, added as an unpositioned clip')
    ap.add_argument('--org', default='StormPigs', help='events.org_name (default: StormPigs)')
    ap.add_argument('--csv-dir', default=str(DEFAULT_CSV_DIR), help='prepped_csvs/full directory')
    ap.add_argument('--remote-video-dir', default='/home/ubuntu/video', help='Prod host video dir (default: /home/ubuntu/video)')
    ap.add_argument('--mysql-container', default='mysqlServer')
    ap.add_argument('--apache-container', default='apacheWebServer')
    ap.add_argument('--dry-run', action='store_true', help='Read-only: check everything and print the plan')
    ap.add_argument('--yes', action='store_true', help="Don't ask for confirmation; add unknown musicians")
    ap.add_argument('--allow-drift', action='store_true', help='Continue even if prod DB and CSVs disagree')
    ap.add_argument('--allow-title-mismatch', action='store_true', help="Continue if songlist titles don't match file names")
    ap.add_argument('--no-push', action='store_true', help='Commit the CSVs but do not push')
    ap.add_argument('--no-commit', action='store_true', help='Leave CSV/image changes uncommitted')
    args = ap.parse_args()

    dry = args.dry_run
    dir_path = Path(args.dir).expanduser().resolve()
    csv_dir = Path(args.csv_dir).expanduser().resolve()
    if not dir_path.is_dir():
        die(f"Not a folder: {dir_path}")
    repo = Path(git(csv_dir, 'rev-parse', '--show-toplevel'))
    remote = Remote(args.ssh, args.mysql_container, args.apache_container, args.remote_video_dir)
    if dry:
        print("\n── DRY RUN: nothing is uploaded, inserted, written to CSVs or committed")

    # 1. Files + songlist ------------------------------------------------------
    section("1. Song files and songlist")
    date_compact, files, ignored = scan_dir(dir_path)
    d = datetime.strptime(date_compact, '%Y%m%d').date()
    date_str = d.isoformat()
    title = f"{d.strftime('%b')} {d.day}"
    songs = parse_songlist(Path(args.songs).expanduser() if args.songs else dir_path / 'songlist.txt')
    info(f"Jam date {date_str}: {len(files)} song files, {len(songs)} songlist lines")
    if ignored:
        info(f"Ignoring {len(ignored)} other media files (e.g. {ignored[0]})")
    song_pos = {s['pos'] for s in songs}
    if song_pos != set(files):
        die(f"Songlist positions and file numbers differ. Only in songlist: "
            f"{sorted(song_pos - set(files))}; only files: {sorted(set(files) - song_pos)}")
    mismatched = []
    for s in songs:
        m = SONG_FILE_RE.match(files[s['pos']].name)
        if m.group('slug') and slugify(m.group('slug')) != slugify(s['base']):
            mismatched.append(f"{s['pos']}: '{s['title']}' vs {files[s['pos']].name}")
    if mismatched:
        msg = "Songlist titles don't match file names:\n       " + '\n       '.join(mismatched)
        if not args.allow_title_mismatch:
            die(msg + "\n  (fix songlist.txt, or pass --allow-title-mismatch)")
        warn(msg)
    whole_jam = Path(args.whole_jam).expanduser().resolve() if args.whole_jam else None
    if whole_jam and not whole_jam.is_file():
        die(f"--whole-jam file not found: {whole_jam}")

    # 2. CSVs + metadata -------------------------------------------------------
    section("2. Jam details")
    names = ['sessions', 'songs', 'session_songs', 'song_files', 'files', 'participants', 'event_participants']
    csvs = {n: load_csv(csv_dir / f"{n}.csv") for n in names}
    meta_path = Path(args.meta).expanduser() if args.meta else dir_path / f"StormPigs{date_compact}_metadata.txt"
    meta = load_or_prompt_metadata(meta_path, date_compact, [r['name'] for r in csvs['participants']])
    rating_stars, rating_val = parse_rating(meta['rating'])
    published = parse_published(meta['published at'])
    explicit = parse_explicit(meta['explicit'])
    crew = [n.strip() for n in meta['crew'].split(',') if n.strip()]
    for k in ('location', 'summary', 'keywords', 'crew'):
        if '\\' in meta[k]:
            die(f"Backslashes aren't allowed in {k}")

    # 3. Hash + probe ----------------------------------------------------------
    section("3. Hashing and probing files (cached after the first run)")
    to_probe = {str(seq): p for seq, p in files.items()}
    if whole_jam:
        to_probe['wj'] = whole_jam
    facts = file_facts(to_probe, date_compact)
    by_sha: Dict[str, str] = {}
    for key, f in facts.items():
        if f['sha256'] in by_sha:
            die(f"{to_probe[by_sha[f['sha256']]].name} and {to_probe[key].name} are the same file")
        by_sha[f['sha256']] = key
    for s in songs:
        dur = facts[str(s['pos'])]['duration']
        if s['segment'] is not None and dur is not None and abs(s['segment'] - dur) > 2:
            warn(f"{s['pos']} '{s['title']}': songlist range is {s['segment']}s but file is {dur}s")
    total_sec = sum(facts[str(seq)]['duration'] or 0 for seq in files)
    info(f"Total song duration {fmt_hms(total_sec)}, "
         f"{sum(f['size'] for f in facts.values()) / 1e9:.1f} GB")

    # 4. Prod state + IDs ------------------------------------------------------
    section(f"4. Checking prod ({args.ssh}) against the CSVs")
    live = read_live_state(remote, date_str, args.org, [f['sha256'] for f in facts.values()])
    expected = csv_state(csvs)
    drift = [f"{t}: prod {live['counts'].get(t)} vs CSV {expected[t]}  (rows, max id)"
             for t in expected if live['counts'].get(t) != expected[t]]
    if drift:
        msg = "Prod DB and CSVs have drifted apart:\n       " + '\n       '.join(drift)
        if not args.allow_drift:
            die(msg + "\n  Bring them back in line first (or pass --allow-drift).")
        warn(msg)
    else:
        info("Prod DB matches the CSVs (row counts and max IDs)")
    if any(r['date'] == date_str and r['org_name'] == args.org for r in csvs['sessions']):
        die(f"{args.org} {date_str} is already in sessions.csv")
    if live['event_on_date']:
        die(f"Prod already has a {args.org} event on {date_str} (event_id {live['event_on_date'][0][0]}); "
            "delete it in db/database.php first if it's a leftover upload")
    dup = live['assets_with_sha']
    if dup:
        die("These files are already in prod's assets table (from an earlier upload?): " +
            ', '.join(f"asset {aid}" for aid in dup.values()))

    event_id = max(expected['events'][1], live['counts']['events'][1]) + 1
    asset_id = max(expected['assets'][1], live['counts']['assets'][1]) + 1
    next_pid = max(expected['participants'][1], live['counts']['participants'][1]) + 1
    song_id = max_id(csvs['songs'], 'song_id') + 1
    info(f"New IDs: event {event_id}, assets from {asset_id}, songs from {song_id}")

    # 5. Participants ----------------------------------------------------------
    section("5. Musicians")
    known = {r['name'].lower(): (int(r['participant_id']), r['name']) for r in csvs['participants']}
    for name, pid in live['participants'].items():
        known.setdefault(name.lower(), (pid, name))
    participants: List[Tuple[int, str]] = []
    new_participants: List[Tuple[int, str]] = []
    for name in crew:
        if name.lower() in known:
            pid, canonical = known[name.lower()]
            participants.append((pid, canonical))
            info(f"{canonical} -> participant {pid}")
            continue
        if not dry and not args.yes:
            if not interactive() or not ask_yes_no(f"  '{name}' is a new musician. Add them?"):
                die(f"Unknown musician '{name}' (fix the crew line in {meta_path.name}, or add them)")
        new_participants.append((next_pid, name))
        participants.append((next_pid, name))
        info(f"{name} -> NEW participant {next_pid}")
        known[name.lower()] = (next_pid, name)
        next_pid += 1
    crew_display = ', '.join(n for _, n in participants)

    # Image
    repo_image = WEBROOT / 'images' / 'jam' / f"{date_compact}.jpg"
    image = Path(args.image).expanduser().resolve() if args.image else None
    if image is None:
        image = repo_image if repo_image.exists() else dir_path / f"{date_compact}.jpg"
    if not image.exists():
        warn(f"No jam image found ({image}); using images/jam/blank.jpg")
        image = None
    cover_url = f"images/jam/{date_compact}.jpg" if image else 'images/jam/blank.jpg'

    # 6. Build rows ------------------------------------------------------------
    section("6. Plan")
    files_rows, songs_rows, ss_rows, sf_rows, assets_sql, items_sql = [], [], [], [], [], []
    entries = [(s['pos'], s['title'], 'song', files[s['pos']], facts[str(s['pos'])]) for s in songs]
    entries.sort(key=lambda e: e[0])
    if whole_jam:
        entries.append((None, f"{date_str} Entire Jam", 'event_label', whole_jam, facts['wj']))
    uploads: Dict[str, Tuple[Path, Dict]] = {}
    for pos, label, stype, path, f in entries:
        ext = path.suffix.lower().lstrip('.')
        ftype = 'video' if ext in VIDEO_EXTS else 'audio'
        probe_data = json.loads(json.dumps(f['probe']))
        probe_data.setdefault('format', {})['filename'] = f"{f['sha256']}.{ext}"
        media_info = json.dumps(probe_data)
        relpath = f"{date_compact}/{path.name}"
        files_rows.append([asset_id, path.name, relpath, f['sha256'], ftype,
                           f['duration'] if f['duration'] is not None else '', media_info, f['tool']])
        songs_rows.append([song_id, label, stype, '', '', ''])
        ss_rows.append([event_id, song_id, pos if pos is not None else ''])
        sf_rows.append([song_id, asset_id])
        assets_sql.append((asset_id, f['sha256'], ftype, ext, relpath, f['duration'], media_info, f['tool']))
        items_sql.append((event_id, asset_id, 'song' if stype == 'song' else 'clip', label, pos))
        uploads[f"{f['sha256']}.{ext}"] = (path, f)
        print(f"  {pos if pos is not None else '-':>3}  {label[:40]:<40} {fmt_hms(f['duration'] or 0)}  "
              f"asset {asset_id}  {path.name}")
        asset_id += 1
        song_id += 1
    session_row = [event_id, title, date_str, args.org, 'band', '', cover_url, crew_display,
                   meta['location'], rating_stars, meta['summary'], published, explicit,
                   fmt_hms(total_sec), meta['keywords']]
    ep_rows = [[event_id, pid] for pid, _ in participants]
    pp_rows = [[pid, name] for pid, name in new_participants]

    print(f"\n  Event {event_id}: {args.org} {date_str} '{title}'  rating {rating_stars or '-'} "
          f"({rating_val})  explicit {explicit}")
    print(f"  Location : {meta['location']}\n  Crew     : {crew_display}\n  Keywords : {meta['keywords']}")
    print(f"  Summary  : {meta['summary']}\n  Published: {published}\n  Image    : {image or 'none'}")

    sql = ["SET NAMES utf8mb4;", "START TRANSACTION;"]
    for pid, name in new_participants:
        sql.append(f"INSERT INTO participants (participant_id, name) VALUES ({pid}, {sql_lit(name)});")
    sql.append(
        "INSERT INTO events (event_id, event_key, event_date, org_name, event_type, title, description, "
        "cover_image_url, location, rating, summary, published_at, explicit, duration_seconds, keywords) "
        f"VALUES ({event_id}, UUID(), {sql_lit(date_str)}, {sql_lit(args.org)}, 'band', {sql_lit(title)}, '', "
        f"{sql_lit(cover_url)}, {sql_lit(meta['location'])}, {sql_lit(rating_val)}, {sql_lit(meta['summary'])}, "
        f"{sql_lit(published)}, {explicit}, {total_sec}, {sql_lit(meta['keywords'])});")
    for a in assets_sql:
        sql.append("INSERT INTO assets (asset_id, checksum_sha256, file_type, file_ext, source_relpath, "
                   "duration_seconds, media_info, media_info_tool) VALUES (" + ', '.join(sql_lit(v) for v in a) + ");")
    for it in items_sql:
        sql.append("INSERT INTO event_items (event_id, asset_id, item_type, label, position) VALUES ("
                   + ', '.join(sql_lit(v) for v in it) + ");")
    for pid, _ in participants:
        sql.append(f"INSERT INTO event_participants (event_id, participant_id) VALUES ({event_id}, {pid});")
    sql.append("COMMIT;")
    first_asset = assets_sql[0][0]
    sql += [
        f"SELECT 'events', COUNT(*) FROM events WHERE event_id = {event_id};",
        f"SELECT 'assets', COUNT(*) FROM assets WHERE asset_id BETWEEN {first_asset} AND {asset_id - 1};",
        f"SELECT 'event_items', COUNT(*) FROM event_items WHERE event_id = {event_id};",
        f"SELECT 'event_participants', COUNT(*) FROM event_participants WHERE event_id = {event_id};",
    ]
    sql_text = '\n'.join(sql) + '\n'

    if dry:
        print("\n── SQL that would run on prod (media_info JSON elided):\n")
        print(re.sub(r"'\{\"(?:[^'\\]|''|\\.)*'", "'<media_info>'", sql_text))
        print(f"── Would upload {len(uploads)} files to {args.ssh}:{remote.video_dir}/<sha256>.<ext> "
              f"(+ thumbnails/<sha256>.png), append to the CSVs in {csv_dir}, "
              f"{'copy the image into the repo and the running site, ' if image else ''}and commit.")
        print(f"\nDRY RUN OK: event {event_id}, {len(entries)} items. Re-run without --dry-run to apply.\n")
        return

    # Repo must be clean and current before we append and commit
    if git(repo, 'status', '--porcelain', '--', str(csv_dir)):
        die(f"Uncommitted changes in {csv_dir}; commit or discard them first")
    git(repo, 'fetch', '-q', check=False)
    behind = git(repo, 'rev-list', '--count', 'HEAD..@{u}', check=False)
    if behind.isdigit() and int(behind) > 0:
        die(f"Your checkout is {behind} commits behind; run git pull first")
    if not args.yes and not (interactive() and ask_yes_no(
            f"\nAdd event {event_id} to prod and the CSVs (uploads {len(uploads)} files)?")):
        die("Cancelled; nothing changed")

    # 7. Upload media + thumbnails --------------------------------------------
    section("7. Uploading media to prod")
    with tempfile.TemporaryDirectory(prefix='add_jam_') as tmp:
        stage = Path(tmp) / 'media'
        thumbs = Path(tmp) / 'thumbs'
        stage.mkdir()
        thumbs.mkdir()
        for name, (path, _) in uploads.items():
            (stage / name).symlink_to(path)
        remote.rsync(stage)
        listing = remote.ssh(f"cd {remote.video_dir} && stat -c '%n %s' " + ' '.join(uploads))
        sizes = dict(line.rsplit(' ', 1) for line in listing.splitlines())
        bad = [n for n, (_, f) in uploads.items() if int(sizes.get(n, -1)) != f['size']]
        if bad:
            die(f"Size mismatch on prod for {bad}; delete those files on prod and re-run")
        info(f"{len(uploads)} files on prod, sizes verified")
        if shutil.which('ffmpeg'):
            for name, (path, f) in uploads.items():
                if name.rsplit('.', 1)[1] not in VIDEO_EXTS:
                    continue
                t = thumbnail_timestamp(f['duration'], f['sha256'])
                subprocess.run(['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                                '-ss', f"{t:.3f}", '-i', str(path), '-frames:v', '1',
                                '-vf', 'scale=320:-1', '-an', '-sn', str(thumbs / f"{f['sha256']}.png")])
            remote.rsync(thumbs, 'thumbnails/')
            info("Thumbnails uploaded")
        else:
            warn("ffmpeg not found locally; skipping thumbnails")

    # 8. Database --------------------------------------------------------------
    section("8. Inserting into the prod database (one transaction)")
    counts = {tag: int(rows[0][0]) for tag, rows in rows_by_tag(remote.mysql(sql_text)).items()}
    want = {'events': 1, 'assets': len(entries), 'event_items': len(entries), 'event_participants': len(participants)}
    for k, v in want.items():
        info(f"{k:<20} {counts.get(k)} / {v} {'ok' if counts.get(k) == v else 'MISMATCH'}")
    if counts != want:
        die("Row counts on prod don't match; check db/database.php before re-running")

    # 9. CSVs + image ----------------------------------------------------------
    section("9. Appending to the CSVs")
    append_csv_rows(csv_dir / 'participants.csv', pp_rows)
    append_csv_rows(csv_dir / 'sessions.csv', [session_row])
    append_csv_rows(csv_dir / 'songs.csv', songs_rows)
    append_csv_rows(csv_dir / 'session_songs.csv', ss_rows)
    append_csv_rows(csv_dir / 'song_files.csv', sf_rows)
    append_csv_rows(csv_dir / 'files.csv', files_rows)
    append_csv_rows(csv_dir / 'event_participants.csv', ep_rows)
    info("CSVs updated")
    commit_paths = [str(csv_dir)]
    if image:
        if image != repo_image:
            repo_image.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(image, repo_image)
        commit_paths.append(str(repo_image))
        remote_tmp = f"/tmp/{date_compact}.jpg"
        if subprocess.run(['scp', '-q', str(repo_image), f"{args.ssh}:{remote_tmp}"]).returncode == 0:
            remote.ssh(f"docker cp {remote_tmp} {remote.apache_container}:/var/www/html/images/jam/{date_compact}.jpg"
                       f" && rm -f {remote_tmp}")
            info("Jam image copied into the running site (it's baked in on the next deploy too)")
        else:
            warn("Couldn't copy the image to prod; it will appear after the next deploy")

    # 10. Commit ---------------------------------------------------------------
    if not args.no_commit:
        section("10. Committing")
        git(repo, 'add', '--', *commit_paths)
        git(repo, 'commit', '-q', '-m', f"Changes: add {args.org} jam {date_str} (event {event_id})",
            '--', *commit_paths)
        info(f"Committed on {git(repo, 'branch', '--show-current')}")
        if not args.no_push:
            git(repo, 'push', '-q')
            info("Pushed")

    print(f"\nDone: event {event_id} ({args.org} {date_str}), {len(entries)} items.\n"
          f"Check https://www.stormpigs.com/db/database.php?view=event&date={date_str}\n")


if __name__ == '__main__':
    main()
