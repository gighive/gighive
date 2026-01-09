#!/usr/bin/env python3

# Purpose of the script is to copy files over from source media directory to gighive based on the presence of those files listed in the FILES table of the gighive media database
#
# Default usage:
#
#   python3 upload_media_by_hash.py \
#     --source-root /mnt/scottsfiles/videos \
#     --ssh-target ubuntu@gighive2 \
#     --db-host gighive2 \
#     --db-user root \
#     --db-name music_db \
#     --limit 5000000

import argparse
import base64
import json
import os
import shlex
import shutil
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import List, Optional, Tuple


DEFAULT_AUDIO_EXTS = {"mp3", "wav", "aac", "flac", "m4a"}
DEFAULT_VIDEO_EXTS = {"mp4", "mov", "mkv", "webm", "avi"}

AUDIO_EXTS = set(DEFAULT_AUDIO_EXTS)
VIDEO_EXTS = set(DEFAULT_VIDEO_EXTS)


@dataclass(frozen=True)
class FileRow:
    checksum_sha256: str
    file_type: str
    source_relpath: str


def eprint(*args: object) -> None:
    print(*args, file=sys.stderr)


def run(
    cmd: List[str], *, env: Optional[dict] = None, check: bool = True, timeout_seconds: Optional[int] = None
) -> subprocess.CompletedProcess:
    return subprocess.run(
        cmd,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=env,
        check=check,
        timeout=timeout_seconds,
    )


def require_cmd(cmd: str) -> None:
    if shutil.which(cmd) is None:
        raise RuntimeError(f"Required command not found in PATH: {cmd}")


def validate_sha256(x: str) -> bool:
    if len(x) != 64:
        return False
    try:
        int(x, 16)
        return True
    except ValueError:
        return False


def pick_thumbnail_timestamp(duration_seconds: Optional[int], checksum_sha256: str) -> float:
    dur = int(duration_seconds) if isinstance(duration_seconds, int) else -1
    if dur <= 0:
        return 0.0

    if dur < 4:
        return max(0.0, float(dur) / 2.0)

    start = min(2.0, float(dur) * 0.10)
    end = max(float(dur) - 2.0, start)

    try:
        n = int(checksum_sha256[:8], 16)
    except Exception:
        n = 0
    r = float(n) / float(0xFFFFFFFF)

    t = start + r * (end - start)
    if t < 0.0:
        return 0.0
    if t > float(dur):
        return float(dur)
    return t


def file_ext_lower(relpath: str) -> str:
    return Path(relpath).suffix.lower().lstrip(".")


def infer_type_from_ext(ext: str) -> str:
    if ext in AUDIO_EXTS:
        return "audio"
    if ext in VIDEO_EXTS:
        return "video"
    return ""


def _normalize_ext_set(items: object) -> Optional[set]:
    if not isinstance(items, list):
        return None
    out = set()
    for x in items:
        if not isinstance(x, str):
            continue
        v = x.strip().lower().lstrip(".")
        if v:
            out.add(v)
    return out


def load_ext_sets_from_group_vars(group_vars_path: str) -> Tuple[Optional[set], Optional[set], str]:
    """Return (audio_exts, video_exts, message). None values indicate failure."""
    try:
        import yaml  # type: ignore
    except Exception:
        return None, None, "PyYAML not installed"

    try:
        with open(group_vars_path, "r", encoding="utf-8") as f:
            data = yaml.safe_load(f) or {}
    except FileNotFoundError:
        return None, None, f"group_vars file not found: {group_vars_path}"
    except Exception as e:
        return None, None, f"failed to parse group_vars: {e}"

    if not isinstance(data, dict):
        return None, None, "group_vars did not parse to a mapping"

    audio = _normalize_ext_set(data.get("gighive_upload_audio_exts"))
    video = _normalize_ext_set(data.get("gighive_upload_video_exts"))

    if audio is None or video is None or not audio or not video:
        return None, None, "missing/invalid gighive_upload_audio_exts or gighive_upload_video_exts"

    return audio, video, "ok"


def default_group_vars_path() -> str:
    here = Path(__file__).resolve()
    for parent in [here.parent, *here.parents]:
        cand = parent / "inventories" / "group_vars" / "gighive" / "gighive.yml"
        if cand.is_file():
            return str(cand)
    return str(here.parents[1] / "inventories" / "group_vars" / "gighive" / "gighive.yml")


def sql_quote(s: str) -> str:
    return "'" + s.replace("'", "''") + "'"


def sql_quote_nullable(s: Optional[str]) -> str:
    if s is None:
        return "NULL"
    return sql_quote(s)


def mysql_query_rows(
    *,
    host: str,
    port: int,
    user: str,
    password: str,
    dbname: str,
    limit: int,
    where_file_type: Optional[str],
    where_source_relpath: Optional[str],
    where_checksum_sha256: Optional[str],
) -> List[FileRow]:
    require_cmd("mysql")

    where: List[str] = ["checksum_sha256 IS NOT NULL", "checksum_sha256 <> ''"]
    if where_file_type:
        where.append("file_type = %s" % sql_quote(where_file_type))

    if where_source_relpath:
        where.append("source_relpath = %s" % sql_quote(where_source_relpath))

    if where_checksum_sha256:
        where.append("checksum_sha256 = %s" % sql_quote(where_checksum_sha256))

    where.append("source_relpath IS NOT NULL")
    where.append("source_relpath <> ''")

    where_sql = " AND ".join(where)

    q = (
        "SELECT checksum_sha256, file_type, source_relpath "
        "FROM files "
        f"WHERE {where_sql} "
        "ORDER BY file_id ASC "
        f"LIMIT {int(limit)};"
    )

    env = os.environ.copy()
    env["MYSQL_PWD"] = password

    cmd = [
        "mysql",
        "-N",
        "-B",
        "--show-warnings",
        "-h",
        host,
        "-P",
        str(port),
        "-u",
        user,
        dbname,
        "-e",
        q,
    ]

    cp = run(cmd, env=env, check=False)
    if cp.returncode != 0:
        raise RuntimeError(
            "mysql query failed\n"
            f"cmd: {shlex.join(cmd[:-2])} -e <query>\n"
            f"stderr: {cp.stderr.strip()}"
        )

    rows: List[FileRow] = []
    for line in (cp.stdout or "").splitlines():
        parts = line.split("\t")
        if len(parts) != 3:
            continue
        checksum, file_type, source_relpath = (p.strip() for p in parts)
        if not validate_sha256(checksum):
            continue
        rows.append(FileRow(checksum_sha256=checksum, file_type=file_type, source_relpath=source_relpath))

    return rows


def mysql_query_file_counts(*, host: str, port: int, user: str, password: str, dbname: str) -> Tuple[int, int, int]:
    require_cmd("mysql")

    q = (
        "SELECT "
        "COUNT(*) AS total, "
        "SUM(source_relpath IS NOT NULL AND source_relpath <> '') AS with_relpath, "
        "SUM(checksum_sha256 IS NOT NULL AND checksum_sha256 <> '') AS with_sha "
        "FROM files;"
    )

    env = os.environ.copy()
    env["MYSQL_PWD"] = password

    cmd = [
        "mysql",
        "-N",
        "-B",
        "--show-warnings",
        "-h",
        host,
        "-P",
        str(port),
        "-u",
        user,
        dbname,
        "-e",
        q,
    ]

    cp = run(cmd, env=env, check=False)
    if cp.returncode != 0:
        raise RuntimeError(
            "mysql count query failed\n"
            f"cmd: {shlex.join(cmd[:-2])} -e <query>\n"
            f"stderr: {cp.stderr.strip()}"
        )

    line = (cp.stdout or "").strip().splitlines()
    if not line:
        return 0, 0, 0
    parts = line[0].split("\t")
    if len(parts) != 3:
        return 0, 0, 0
    try:
        return int(parts[0]), int(parts[1]), int(parts[2])
    except Exception:
        return 0, 0, 0


def mysql_query_sample_files(
    *,
    host: str,
    port: int,
    user: str,
    password: str,
    dbname: str,
    limit: int,
) -> List[Tuple[str, str, str]]:
    require_cmd("mysql")

    q = (
        "SELECT "
        "COALESCE(checksum_sha256,''), "
        "COALESCE(file_type,''), "
        "COALESCE(source_relpath,'') "
        "FROM files ORDER BY file_id ASC LIMIT %d;" % int(limit)
    )

    env = os.environ.copy()
    env["MYSQL_PWD"] = password

    cmd = [
        "mysql",
        "-N",
        "-B",
        "--show-warnings",
        "-h",
        host,
        "-P",
        str(port),
        "-u",
        user,
        dbname,
        "-e",
        q,
    ]

    cp = run(cmd, env=env, check=False)
    if cp.returncode != 0:
        raise RuntimeError(
            "mysql sample query failed\n"
            f"cmd: {shlex.join(cmd[:-2])} -e <query>\n"
            f"stderr: {cp.stderr.strip()}"
        )

    out: List[Tuple[str, str, str]] = []
    for line in (cp.stdout or "").splitlines():
        parts = line.split("\t")
        if len(parts) != 3:
            continue
        out.append((parts[0].strip(), parts[1].strip(), parts[2].strip()))
    return out


def mysql_update_media_info(
    *,
    host: str,
    port: int,
    user: str,
    password: str,
    dbname: str,
    checksum_sha256: str,
    duration_seconds: Optional[int],
    media_info_json: str,
    media_info_tool: Optional[str],
) -> None:
    require_cmd("mysql")

    if not validate_sha256(checksum_sha256):
        raise ValueError("Invalid checksum_sha256")

    # Avoid complex escaping by base64-encoding JSON and decoding in SQL.
    media_info_b64 = base64.b64encode(media_info_json.encode("utf-8")).decode("ascii")

    dur_sql = "NULL" if duration_seconds is None else str(int(duration_seconds))
    tool_sql = sql_quote_nullable(media_info_tool)

    q = (
        "UPDATE files SET "
        f"duration_seconds = {dur_sql}, "
        f"media_info = CAST(CONVERT(FROM_BASE64({sql_quote(media_info_b64)}) USING utf8mb4) AS JSON), "
        f"media_info_tool = {tool_sql} "
        f"WHERE checksum_sha256 = {sql_quote(checksum_sha256)};"
    )

    env = os.environ.copy()
    env["MYSQL_PWD"] = password

    cmd = [
        "mysql",
        "-N",
        "-B",
        "-h",
        host,
        "-P",
        str(port),
        "-u",
        user,
        dbname,
        "-e",
        q,
    ]

    cp = run(cmd, env=env, check=False)
    if cp.returncode != 0:
        raise RuntimeError(f"mysql update failed: {cp.stderr.strip()}")


def remote_path_exists(ssh_target: str, remote_path: str) -> bool:
    require_cmd("ssh")
    cp = run(["ssh", "-o", "BatchMode=yes", ssh_target, "test", "-f", remote_path], check=False)
    return cp.returncode == 0


def remote_ffmpeg_thumbnail(
    *,
    ssh_target: str,
    video_path: str,
    thumb_path: str,
    seek_seconds: float,
    width_px: int,
    timeout_seconds: int,
) -> None:
    require_cmd("ssh")

    thumb_dir = str(Path(thumb_path).parent)
    ensure_remote_dir(ssh_target, thumb_dir)

    tmp_path = thumb_path + ".tmp.png"

    vf = f"scale={int(width_px)}:-1"
    cmd = (
        "set -euo pipefail; "
        f"ffmpeg -nostdin -hide_banner -loglevel error -y -ss {seek_seconds:.3f} -i {shlex.quote(video_path)} "
        f"  -frames:v 1 -vf {shlex.quote(vf)} -an -sn {shlex.quote(tmp_path)}; "
        f"mv -f {shlex.quote(tmp_path)} {shlex.quote(thumb_path)}"
    )

    cp = run(
        ["ssh", "-o", "BatchMode=yes", ssh_target, "bash", "-lc", cmd],
        check=False,
        timeout_seconds=timeout_seconds,
    )
    if cp.returncode != 0:
        raise RuntimeError((cp.stderr or cp.stdout or "ffmpeg thumbnail failed").strip())

    remote_fix_ownership(ssh_target, thumb_path)


def remote_ffprobe_json(ssh_target: str, remote_path: str) -> Tuple[Optional[int], str, str]:
    """Return (duration_seconds, json_text, tool_string)."""
    require_cmd("ssh")

    # Probe JSON
    cmd = [
        "ssh",
        "-o",
        "BatchMode=yes",
        ssh_target,
        "ffprobe",
        "-v",
        "error",
        "-print_format",
        "json",
        "-show_format",
        "-show_streams",
        "-show_chapters",
        "-show_programs",
        remote_path,
    ]
    cp = run(cmd, check=False)
    if cp.returncode != 0:
        raise RuntimeError(cp.stderr.strip() or f"ffprobe failed rc={cp.returncode}")

    json_text = (cp.stdout or "").strip()
    if json_text == "":
        raise RuntimeError("ffprobe returned empty output")

    duration_seconds: Optional[int] = None
    try:
        obj = json.loads(json_text)
        fmt = obj.get("format") if isinstance(obj, dict) else None
        dur = fmt.get("duration") if isinstance(fmt, dict) else None
        if isinstance(dur, str) and dur.strip() != "":
            duration_seconds = int(round(float(dur)))
        elif isinstance(dur, (int, float)):
            duration_seconds = int(round(float(dur)))
        # Normalize format.filename to basename to avoid leaking paths
        if isinstance(fmt, dict) and isinstance(fmt.get("filename"), str):
            fmt["filename"] = os.path.basename(fmt["filename"])
        json_text = json.dumps(obj, ensure_ascii=False, separators=(",", ":"))
    except Exception:
        # Keep json_text as-is if parsing/normalization fails
        duration_seconds = None

    # Tool string
    vcp = run(["ssh", "-o", "BatchMode=yes", ssh_target, "ffprobe", "-version"], check=False)
    tool_line = (vcp.stdout or "").splitlines()[0].strip() if vcp.returncode == 0 else ""
    tool = tool_line or "ffprobe"

    return duration_seconds, json_text, tool


def ensure_remote_dir(ssh_target: str, remote_dir: str) -> None:
    require_cmd("ssh")
    # Prefer creating with www-data ownership + group-writable perms when possible.
    # This matters for paths like /home/ubuntu/video/thumbnails which are typically owned by www-data.
    cmd = (
        "set -euo pipefail; "
        + f"(sudo -n install -d -o www-data -g www-data -m 0775 {shlex.quote(remote_dir)} 2>/dev/null || true); "
        + f"mkdir -p {shlex.quote(remote_dir)}; "
        + f"chmod 0775 {shlex.quote(remote_dir)} 2>/dev/null || true"
    )
    cp = run(["ssh", "-o", "BatchMode=yes", ssh_target, "bash", "-lc", cmd], check=False)
    if cp.returncode != 0:
        raise RuntimeError(f"Failed to ensure remote dir {remote_dir}: {(cp.stderr or cp.stdout).strip()}")


def remote_fix_ownership(ssh_target: str, remote_path: str) -> None:
    require_cmd("ssh")
    cmd = (
        "set -euo pipefail; "
        "if command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then "
        f"  sudo -n chown www-data:www-data {shlex.quote(remote_path)} 2>/dev/null || true; "
        f"  sudo -n chmod 0664 {shlex.quote(remote_path)} 2>/dev/null || true; "
        "fi"
    )
    run(["ssh", "-o", "BatchMode=yes", ssh_target, "bash", "-lc", cmd], check=False)


def rsync_copy(src: str, ssh_target: str, dest_abs: str, *, show_progress: bool) -> Tuple[bool, str]:
    require_cmd("rsync")
    cmd = [
        "rsync",
        "-a",
        "--protect-args",
        "--partial",
        "--info=progress2" if show_progress else "--info=none",
        src,
        f"{ssh_target}:{dest_abs}",
    ]

    if not show_progress:
        cp = run(cmd, check=False)
        if cp.returncode == 0:
            remote_fix_ownership(ssh_target, dest_abs)
            return True, ""
        return False, (cp.stderr.strip() or cp.stdout.strip() or f"rsync failed rc={cp.returncode}")

    # Stream rsync progress to stderr so the STATUS table on stdout stays parseable.
    # Note: rsync progress output may go to stdout depending on version/options, so
    # we merge stderr into stdout and stream the combined output.
    proc = subprocess.Popen(
        cmd,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        bufsize=1,
    )

    err_lines: List[str] = []
    assert proc.stdout is not None
    for line in proc.stdout:
        sys.stderr.write(line)
        sys.stderr.flush()
        s = line.strip()
        if s:
            err_lines.append(s)
            if len(err_lines) > 50:
                err_lines = err_lines[-50:]

    rc = proc.wait()
    if rc == 0:
        remote_fix_ownership(ssh_target, dest_abs)
        return True, ""
    err_text = "\n".join(err_lines).strip()
    return False, (err_text or f"rsync failed rc={rc}")


def log_row(status: str, file_type: str, source_relpath: str, dest: str) -> None:
    print(f"{status}\t{file_type}\t{source_relpath}\t{dest}")


def main(argv: Optional[List[str]] = None) -> int:
    p = argparse.ArgumentParser(description="Migrate media files by sha256 from Pop-OS source to gighive2 VM bind mounts.")
    p.add_argument("--source-root", required=True, help="Absolute directory that contains source_relpath (e.g. /mnt/scottsfiles/videos)")
    p.add_argument(
        "--source-roots",
        default=os.getenv("GIGHIVE_SOURCE_ROOTS") or "",
        help="Optional colon-separated list of additional source roots to search (tried in order)",
    )
    p.add_argument(
        "--group-vars",
        default=os.getenv("GIGHIVE_GROUP_VARS") or default_group_vars_path(),
        help="Path to Ansible group_vars file (default: gighive2 group_vars; override with GIGHIVE_GROUP_VARS)",
    )
    p.add_argument(
        "--no-group-vars",
        action="store_true",
        help="Disable reading extension lists from group_vars; use built-in defaults",
    )
    p.add_argument("--ssh-target", default="ubuntu@gighive2", help="SSH target for destination VM (default: ubuntu@gighive2)")
    p.add_argument("--dest-audio", default="/home/ubuntu/audio", help="Destination host directory for audio (default: /home/ubuntu/audio)")
    p.add_argument("--dest-video", default="/home/ubuntu/video", help="Destination host directory for video (default: /home/ubuntu/video)")
    p.add_argument("--db-host", default="gighive2", help="MySQL host (default: gighive2)")
    p.add_argument("--db-port", type=int, default=3306, help="MySQL port (default: 3306)")
    p.add_argument("--db-user", default=os.getenv("MYSQL_USER") or os.getenv("DB_USER") or "root")
    p.add_argument("--db-password", default=os.getenv("MYSQL_PASSWORD") or os.getenv("DB_PASSWORD") or "")
    p.add_argument("--db-name", default=os.getenv("MYSQL_DATABASE") or os.getenv("DB_NAME") or "music_db")
    p.add_argument("--limit", type=int, default=5000000, help="Max rows to process per run (default: 500000)")
    p.add_argument("--only-type", choices=["audio", "video"], default=None, help="Only process one file_type")
    p.add_argument(
        "--single-source-relpath",
        default=None,
        help="Only process DB rows whose files.source_relpath matches exactly (skips scanning many DB rows)",
    )
    p.add_argument(
        "--single-checksum",
        default=None,
        help="Only process DB rows whose files.checksum_sha256 matches exactly (skips scanning many DB rows)",
    )
    p.add_argument("--infer-type-if-missing", action="store_true", help="If DB file_type is not audio/video, infer from extension")
    p.add_argument("--dry-run", action="store_true", help="Do not transfer; only report what would happen")
    p.add_argument(
        "--force-recopy",
        action="store_true",
        help="If destination exists, re-copy (overwrite) the file via rsync before probing/DB refresh",
    )

    p.add_argument("--thumbs", action="store_true", default=True, help="Generate video thumbnails (default: enabled)")
    p.add_argument("--no-thumbs", dest="thumbs", action="store_false", help="Disable thumbnail generation")
    p.add_argument("--thumb-width", type=int, default=320, help="Thumbnail width in pixels (default: 320)")
    p.add_argument("--thumb-timeout", type=int, default=30, help="Thumbnail ffmpeg timeout in seconds (default: 30)")
    p.add_argument("--force-thumb", action="store_true", help="Re-generate thumbnail even if it already exists")

    p.add_argument(
        "--no-progress",
        action="store_true",
        help="Disable live progress meter",
    )
    p.add_argument(
        "--progress-every-seconds",
        type=float,
        default=0.5,
        help="Progress meter update interval in seconds (default: 0.5)",
    )

    args = p.parse_args(argv)

    global AUDIO_EXTS, VIDEO_EXTS
    if not args.no_group_vars and args.group_vars:
        a, v, msg = load_ext_sets_from_group_vars(args.group_vars)
        if a is not None and v is not None:
            AUDIO_EXTS = a
            VIDEO_EXTS = v
            eprint(f"INFO: loaded extension lists from group_vars: {args.group_vars}")
        else:
            AUDIO_EXTS = set(DEFAULT_AUDIO_EXTS)
            VIDEO_EXTS = set(DEFAULT_VIDEO_EXTS)
            eprint(f"INFO: using built-in extension defaults ({msg})")
    else:
        AUDIO_EXTS = set(DEFAULT_AUDIO_EXTS)
        VIDEO_EXTS = set(DEFAULT_VIDEO_EXTS)
        eprint("INFO: using built-in extension defaults (--no-group-vars)")

    if not args.db_password:
        eprint("ERROR: missing DB password. Provide --db-password or set MYSQL_PASSWORD / DB_PASSWORD in env.")
        return 2

    if args.single_checksum and not validate_sha256(args.single_checksum.strip()):
        eprint("ERROR: --single-checksum must be a 64-character lowercase hex sha256")
        return 2

    if args.single_source_relpath or args.single_checksum:
        args.limit = 1

    source_roots: List[Path] = []
    source_root = Path(args.source_root)
    source_roots.append(source_root)
    for raw in str(args.source_roots or "").split(":"):
        v = raw.strip()
        if v:
            source_roots.append(Path(v))

    for r in source_roots:
        if not r.is_dir():
            eprint(f"ERROR: --source-root(s) contains a non-directory path: {r}")
            return 2

    try:
        where_type = args.only_type
        rows = mysql_query_rows(
            host=args.db_host,
            port=args.db_port,
            user=args.db_user,
            password=args.db_password,
            dbname=args.db_name,
            limit=args.limit,
            where_file_type=where_type,
            where_source_relpath=(args.single_source_relpath.strip() if args.single_source_relpath else None),
            where_checksum_sha256=(args.single_checksum.strip() if args.single_checksum else None),
        )
    except Exception as e:
        eprint(str(e))
        return 2

    if len(rows) == 0:
        if args.single_source_relpath or args.single_checksum:
            eprint("ERROR: no DB rows matched your single-file filter(s).")
            if args.single_source_relpath:
                eprint(f"- --single-source-relpath={args.single_source_relpath!r}")
            if args.single_checksum:
                eprint(f"- --single-checksum={args.single_checksum!r}")
            if args.single_source_relpath:
                needle = os.path.basename(args.single_source_relpath.strip())
                if needle:
                    eprint("Try locating the correct source_relpath in the DB with:")
                    eprint(
                        "  MYSQL_PWD=... mysql -h <dbhost> -u <dbuser> <dbname> -e "
                        + sql_quote(
                            "SELECT file_id,file_type,checksum_sha256,source_relpath "
                            f"FROM files WHERE source_relpath LIKE '%{needle}%' LIMIT 20;"
                        )
                    )

        try:
            total, with_relpath, with_sha = mysql_query_file_counts(
                host=args.db_host,
                port=args.db_port,
                user=args.db_user,
                password=args.db_password,
                dbname=args.db_name,
            )
            eprint(f"DB diagnostics: files.total={total} files.with_relpath={with_relpath} files.with_sha={with_sha}")
            sample = mysql_query_sample_files(
                host=args.db_host,
                port=args.db_port,
                user=args.db_user,
                password=args.db_password,
                dbname=args.db_name,
                limit=5,
            )
            if sample:
                eprint("DB sample rows (checksum_sha256, file_type, source_relpath):")
                for cs, ft, rp in sample:
                    eprint(f"- {cs!r}\t{ft!r}\t{rp!r}")
        except Exception as e:
            eprint(f"DB diagnostics failed: {e}")

        eprint(
            "Hint: this uploader only processes rows where files.checksum_sha256 is non-empty. "
            "If files.with_sha=0, your import path did not populate checksum_sha256."
        )

    print("STATUS\tFILE_TYPE\tSOURCE_RELPATH\tDEST")

    copied = 0
    recopied = 0
    skipped_exists = 0
    missing_src = 0
    failed = 0
    skipped_type = 0
    probed = 0
    probe_failed = 0
    thumbs_created = 0
    thumbs_exists = 0
    thumbs_failed = 0

    if not args.dry_run:
        try:
            ensure_remote_dir(args.ssh_target, args.dest_audio)
            ensure_remote_dir(args.ssh_target, args.dest_video)
        except Exception as e:
            eprint(str(e))
            return 2

    def resolve_src_abs(source_relpath: str) -> Optional[Path]:
        rel = str(source_relpath or "")
        for root in source_roots:
            cand = root / rel
            if cand.is_file():
                return cand

            stripped = rel.lstrip("/")
            if stripped != rel:
                cand2 = root / stripped
                if cand2.is_file():
                    return cand2

            root_base = root.name
            if root_base and stripped.startswith(root_base + "/"):
                alt_rel = stripped[len(root_base) + 1 :]
                if alt_rel:
                    cand3 = root / alt_rel
                    if cand3.is_file():
                        return cand3

        return None

    total_rows = len(rows)
    show_progress = (not bool(args.no_progress))
    progress_every_seconds = float(args.progress_every_seconds)
    started_at = time.monotonic()
    last_progress_at = 0.0
    last_progress_printed = ""
    progress_line_active = False

    def flush_progress_line() -> None:
        nonlocal progress_line_active
        if show_progress and progress_line_active:
            print("", file=sys.stderr)
            progress_line_active = False

    for row_index_0, r in enumerate(rows):
        row_index = row_index_0 + 1
        if show_progress and total_rows > 0:
            now = time.monotonic()
            if (now - last_progress_at) >= progress_every_seconds:
                elapsed = max(0.0, now - started_at)
                rate = (float(row_index) / elapsed) if elapsed > 0.0 else 0.0
                remaining = max(0, total_rows - row_index)
                eta_seconds = int((float(remaining) / rate)) if rate > 0.0 else 0
                pct = int((row_index / total_rows) * 100)
                msg = (
                    f"Progress: {row_index}/{total_rows} rows ({pct}%) "
                    f"elapsed={int(elapsed)}s eta={eta_seconds}s rate={rate:.2f} rows/s"
                )
                if msg != last_progress_printed:
                    print("\r" + msg, end="", file=sys.stderr, flush=True)
                    last_progress_printed = msg
                    progress_line_active = True
                last_progress_at = now

        ext = file_ext_lower(r.source_relpath)
        dest_type = r.file_type

        if dest_type not in ("audio", "video") and args.infer_type_if_missing:
            inferred = infer_type_from_ext(ext)
            if inferred:
                dest_type = inferred

        if dest_type not in ("audio", "video"):
            skipped_type += 1
            flush_progress_line()
            log_row("SKIP_TYPE", r.file_type or "", r.source_relpath, "")
            continue

        dest_dir = args.dest_audio if dest_type == "audio" else args.dest_video

        dest_filename = r.checksum_sha256
        if ext:
            dest_filename = f"{dest_filename}.{ext}"

        dest_abs = f"{dest_dir.rstrip('/')}/{dest_filename}"

        dest_exists = remote_path_exists(args.ssh_target, dest_abs)
        if dest_exists:
            if args.force_recopy:
                flush_progress_line()
                log_row("FORCE_RECOPY", dest_type, r.source_relpath, dest_abs)
            else:
                skipped_exists += 1
                flush_progress_line()
                log_row("SKIP_COPY_EXISTS", dest_type, r.source_relpath, dest_abs)

        if args.dry_run:
            if dest_exists and args.force_recopy:
                flush_progress_line()
                log_row("DRY_RUN_RECOPY", dest_type, r.source_relpath, dest_abs)
            else:
                flush_progress_line()
                log_row("DRY_RUN", dest_type, r.source_relpath, dest_abs)
            continue

        if (not dest_exists) or args.force_recopy:
            src_abs = resolve_src_abs(r.source_relpath)
            if src_abs is None:
                missing_src += 1
                flush_progress_line()
                log_row("MISSING_SRC", dest_type, r.source_relpath, dest_abs)
                continue

            flush_progress_line()
            log_row("START_COPY", dest_type, r.source_relpath, dest_abs)
            ok, err = rsync_copy(str(src_abs), args.ssh_target, dest_abs, show_progress=show_progress)
            if ok:
                if dest_exists and args.force_recopy:
                    recopied += 1
                    flush_progress_line()
                    log_row("RECOPIED", dest_type, r.source_relpath, dest_abs)
                else:
                    copied += 1
                    flush_progress_line()
                    log_row("COPIED", dest_type, r.source_relpath, dest_abs)
            else:
                failed += 1
                flush_progress_line()
                log_row("FAILED", dest_type, r.source_relpath, dest_abs)
                if err:
                    flush_progress_line()
                    eprint(f"FAILED\t{dest_type}\t{r.source_relpath}\t{dest_abs}\t{err}")
                continue

            dest_exists = True

        if not dest_exists:
            failed += 1
            flush_progress_line()
            log_row("FAILED", dest_type, r.source_relpath, dest_abs)
            flush_progress_line()
            eprint(f"FAILED\t{dest_type}\t{r.source_relpath}\t{dest_abs}\tmissing destination file after copy")
            continue

        try:
            dur, jtxt, tool = remote_ffprobe_json(args.ssh_target, dest_abs)
            mysql_update_media_info(
                host=args.db_host,
                port=args.db_port,
                user=args.db_user,
                password=args.db_password,
                dbname=args.db_name,
                checksum_sha256=r.checksum_sha256,
                duration_seconds=dur,
                media_info_json=jtxt,
                media_info_tool=tool,
            )
            probed += 1

            if args.thumbs and dest_type == "video":
                try:
                    thumb_dir = f"{args.dest_video.rstrip('/')}/thumbnails"
                    thumb_path = f"{thumb_dir}/{r.checksum_sha256}.png"
                    if args.force_thumb or not remote_path_exists(args.ssh_target, thumb_path):
                        t = pick_thumbnail_timestamp(dur, r.checksum_sha256)
                        remote_ffmpeg_thumbnail(
                            ssh_target=args.ssh_target,
                            video_path=dest_abs,
                            thumb_path=thumb_path,
                            seek_seconds=t,
                            width_px=max(32, int(args.thumb_width)),
                            timeout_seconds=max(5, int(args.thumb_timeout)),
                        )
                        thumbs_created += 1
                        flush_progress_line()
                        log_row("THUMBNAIL_CREATED", dest_type, r.source_relpath, thumb_path)
                    else:
                        thumbs_exists += 1
                        flush_progress_line()
                        log_row("THUMBNAIL_EXISTS", dest_type, r.source_relpath, thumb_path)
                except Exception as e:
                    thumbs_failed += 1
                    flush_progress_line()
                    log_row("THUMBNAIL_FAILED", dest_type, r.source_relpath, dest_abs)
                    flush_progress_line()
                    eprint(f"THUMBNAIL_FAILED\t{dest_type}\t{r.source_relpath}\t{dest_abs}\t{str(e)}")
            elif args.thumbs and dest_type == "audio":
                flush_progress_line()
                log_row("AUDIO_FILE_NO_THUMBNAIL_CREATED", dest_type, r.source_relpath, dest_abs)

            flush_progress_line()
            log_row("DB_REFRESHED", dest_type, r.source_relpath, dest_abs)
        except Exception as e:
            probe_failed += 1
            flush_progress_line()
            log_row("DB_REFRESH_FAILED", dest_type, r.source_relpath, dest_abs)
            flush_progress_line()
            eprint(f"PROBE_FAILED\t{dest_type}\t{r.source_relpath}\t{dest_abs}\t{str(e)}")

    if show_progress and total_rows > 0 and last_progress_printed:
        print("", file=sys.stderr)

    eprint(
        "SUMMARY "
        f"copied={copied} "
        f"recopied={recopied} "
        f"skip_exists={skipped_exists} "
        f"missing_src={missing_src} "
        f"failed={failed} "
        f"skip_type={skipped_type} "
        f"probed={probed} "
        f"probe_failed={probe_failed} "
        f"thumbs_created={thumbs_created} "
        f"thumbs_exists={thumbs_exists} "
        f"thumbs_failed={thumbs_failed} "
        f"rows={len(rows)}"
    )

    return 0 if failed == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
