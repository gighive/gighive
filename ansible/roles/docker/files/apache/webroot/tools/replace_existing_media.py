#!/usr/bin/env python3

import argparse
import base64
import csv
import getpass
import hashlib
import json
import os
import re
import shlex
import shutil
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Dict, List, Optional, Sequence, Tuple


DEFAULT_ALLOWED_MIMES = {
    "audio/mpeg", "audio/mp3", "audio/wav", "audio/x-wav", "audio/aac", "audio/flac", "audio/mp4",
    "video/mp4", "video/quicktime", "video/x-matroska", "video/webm", "video/x-msvideo",
}
DEFAULT_AUDIO_EXTS = {"mp3", "wav", "aac", "flac", "m4a"}
DEFAULT_VIDEO_EXTS = {"mp4", "mov", "mkv", "webm", "avi"}
ALLOWED_MIMES = set(DEFAULT_ALLOWED_MIMES)
AUDIO_EXTS = set(DEFAULT_AUDIO_EXTS)
VIDEO_EXTS = set(DEFAULT_VIDEO_EXTS)
FILENAME_RE = re.compile(r"^(?P<org>[A-Za-z0-9]+)(?P<ymd>\d{8})_(?P<seq>\d+)_(?P<label>.+)\.(?P<ext>[A-Za-z0-9]+)$")


@dataclass(frozen=True)
class LocalReplacement:
    local_path: Path
    source_relpath: str
    file_name: str
    seq: int
    ext: str
    file_type: str
    size_bytes: int
    checksum_sha256: str


@dataclass(frozen=True)
class ExistingRow:
    file_id: int
    session_id: Optional[int]
    seq: Optional[int]
    file_type: str
    file_name: str
    source_relpath: str
    checksum_sha256: str
    size_bytes: Optional[int]
    event_date: str
    org_name: str
    match_mode: str


@dataclass(frozen=True)
class PlanItem:
    local: LocalReplacement
    existing: ExistingRow
    remote_dest_abs: str
    remote_old_abs: Optional[str]
    remote_thumb_new: Optional[str]
    remote_thumb_old: Optional[str]


@dataclass(frozen=True)
class ParsedName:
    org_slug: str
    event_date: str
    seq: int
    ext: str
    basename: str


@dataclass(frozen=True)
class MediaConfig:
    allowed_mimes: set
    audio_exts: set
    video_exts: set
    source: str
    message: str


def eprint(*args: object) -> None:
    print(*args, file=sys.stderr)


def run(
    cmd: Sequence[str], *, env: Optional[dict] = None, check: bool = True, timeout_seconds: Optional[int] = None
) -> subprocess.CompletedProcess:
    return subprocess.run(
        list(cmd),
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


def sql_quote(s: str) -> str:
    return "'" + s.replace("'", "''") + "'"


def sql_quote_nullable(s: Optional[str]) -> str:
    if s is None:
        return "NULL"
    return sql_quote(s)


def validate_sha256(x: str) -> bool:
    if len(x) != 64:
        return False
    try:
        int(x, 16)
        return True
    except ValueError:
        return False


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def infer_type_from_ext(ext: str) -> str:
    v = ext.lower().lstrip(".")
    if v in AUDIO_EXTS:
        return "audio"
    if v in VIDEO_EXTS:
        return "video"
    return ""


def _normalize_string_set(items: object) -> Optional[set]:
    if not isinstance(items, list):
        return None
    out = set()
    for x in items:
        if not isinstance(x, str):
            continue
        v = x.strip().lower()
        if v:
            out.add(v)
    return out


def _normalize_ext_set(items: object) -> Optional[set]:
    raw = _normalize_string_set(items)
    if raw is None:
        return None
    return {x.lstrip(".") for x in raw if x.lstrip(".")}


def load_media_config_from_env() -> Tuple[Optional[MediaConfig], str]:
    raw_allowed = os.getenv("UPLOAD_ALLOWED_MIMES_JSON")
    raw_audio = os.getenv("UPLOAD_AUDIO_EXTS_JSON")
    raw_video = os.getenv("UPLOAD_VIDEO_EXTS_JSON")

    if not raw_allowed and not raw_audio and not raw_video:
        return None, "env media config not set"

    if not raw_allowed or not raw_audio or not raw_video:
        return None, "env media config is partial; expected UPLOAD_ALLOWED_MIMES_JSON, UPLOAD_AUDIO_EXTS_JSON, and UPLOAD_VIDEO_EXTS_JSON"

    try:
        allowed = _normalize_string_set(json.loads(raw_allowed))
        audio = _normalize_ext_set(json.loads(raw_audio))
        video = _normalize_ext_set(json.loads(raw_video))
    except Exception as e:
        return None, f"failed to parse env media config: {e}"

    if allowed is None or audio is None or video is None or not audio or not video:
        return None, "env media config missing/invalid arrays"

    return MediaConfig(allowed_mimes=allowed, audio_exts=audio, video_exts=video, source="env", message="ok"), "ok"


def load_media_config_from_group_vars(group_vars_path: str) -> Tuple[Optional[MediaConfig], str]:
    try:
        import yaml  # type: ignore
    except Exception:
        return None, "PyYAML not installed"

    try:
        with open(group_vars_path, "r", encoding="utf-8") as f:
            data = yaml.safe_load(f) or {}
    except FileNotFoundError:
        return None, f"group_vars file not found: {group_vars_path}"
    except Exception as e:
        return None, f"failed to parse group_vars: {e}"

    if not isinstance(data, dict):
        return None, "group_vars did not parse to a mapping"

    allowed = _normalize_string_set(data.get("gighive_upload_allowed_mimes"))
    audio = _normalize_ext_set(data.get("gighive_upload_audio_exts"))
    video = _normalize_ext_set(data.get("gighive_upload_video_exts"))

    if audio is None or video is None or not audio or not video:
        return None, "missing/invalid gighive_upload_audio_exts or gighive_upload_video_exts"

    if allowed is None:
        allowed = set(DEFAULT_ALLOWED_MIMES)

    return MediaConfig(
        allowed_mimes=allowed,
        audio_exts=audio,
        video_exts=video,
        source=f"group_vars:{group_vars_path}",
        message="ok",
    ), "ok"


def resolve_media_config(group_vars_path: str, no_group_vars: bool) -> MediaConfig:
    env_cfg, env_msg = load_media_config_from_env()
    if env_cfg is not None:
        return env_cfg

    if not no_group_vars and group_vars_path:
        yaml_cfg, yaml_msg = load_media_config_from_group_vars(group_vars_path)
        if yaml_cfg is not None:
            return yaml_cfg
        return MediaConfig(
            allowed_mimes=set(DEFAULT_ALLOWED_MIMES),
            audio_exts=set(DEFAULT_AUDIO_EXTS),
            video_exts=set(DEFAULT_VIDEO_EXTS),
            source="defaults",
            message=f"using built-in media defaults ({yaml_msg}; env fallback reason: {env_msg})",
        )

    return MediaConfig(
        allowed_mimes=set(DEFAULT_ALLOWED_MIMES),
        audio_exts=set(DEFAULT_AUDIO_EXTS),
        video_exts=set(DEFAULT_VIDEO_EXTS),
        source="defaults",
        message=f"using built-in media defaults (--no-group-vars; env fallback reason: {env_msg})",
    )


def default_group_vars_path() -> str:
    here = Path(__file__).resolve()
    for parent in [here.parent, *here.parents]:
        cand = parent / "inventories" / "group_vars" / "gighive" / "gighive.yml"
        if cand.is_file():
            return str(cand)
    return str(here.parents[1] / "inventories" / "group_vars" / "gighive" / "gighive.yml")


def normalize_org_slug(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", value.lower())


def parse_media_filename(name: str) -> Optional[ParsedName]:
    m = FILENAME_RE.match(name)
    if not m:
        return None
    ymd = m.group("ymd")
    return ParsedName(
        org_slug=normalize_org_slug(m.group("org")),
        event_date=f"{ymd[0:4]}-{ymd[4:6]}-{ymd[6:8]}",
        seq=int(m.group("seq")),
        ext=m.group("ext").lower(),
        basename=name,
    )


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


def parse_args(argv: Optional[Sequence[str]] = None) -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Replace existing media rows in files while preserving file_id relationships.")
    p.add_argument("--source-dir", required=True, help="Directory containing replacement media files")
    p.add_argument(
        "--group-vars",
        default=os.getenv("GIGHIVE_GROUP_VARS") or default_group_vars_path(),
        help="Path to Ansible group_vars file used as YAML fallback for media config",
    )
    p.add_argument(
        "--no-group-vars",
        action="store_true",
        help="Skip YAML fallback and use env-configured or built-in defaults for media config",
    )
    p.add_argument("--org-name", required=True, help="Exact sessions.org_name to target")
    p.add_argument("--event-date", required=True, help="Target event date in YYYY-MM-DD format")
    p.add_argument("--file-type", choices=["audio", "video"], default="video")
    p.add_argument("--ssh-target", default="ubuntu@gighive2")
    p.add_argument("--dest-audio", default="/home/ubuntu/audio")
    p.add_argument("--dest-video", default="/home/ubuntu/video")
    p.add_argument("--db-host", default="gighive2")
    p.add_argument("--db-port", type=int, default=3306)
    p.add_argument("--db-user", default=os.getenv("MYSQL_USER") or os.getenv("DB_USER") or "root")
    p.add_argument("--db-password", default=os.getenv("MYSQL_PASSWORD") or os.getenv("DB_PASSWORD") or "")
    p.add_argument("--db-name", default=os.getenv("MYSQL_DATABASE") or os.getenv("DB_NAME") or "music_db")
    p.add_argument("--backup-csv", default="")
    p.add_argument("--dry-run", action="store_true")
    p.add_argument("--delete-old-remote-blobs", action="store_true")
    p.add_argument("--thumbs", action="store_true", default=True)
    p.add_argument("--no-thumbs", dest="thumbs", action="store_false")
    p.add_argument("--force-thumb", action="store_true")
    p.add_argument("--thumb-width", type=int, default=320)
    p.add_argument("--thumb-timeout", type=int, default=30)
    p.add_argument("--no-progress", action="store_true")
    args = p.parse_args(argv)
    if not re.match(r"^\d{4}-\d{2}-\d{2}$", args.event_date):
        raise SystemExit("--event-date must be YYYY-MM-DD")
    if not args.dry_run and not args.backup_csv:
        stamp = time.strftime("%Y%m%d-%H%M%S")
        args.backup_csv = f"/tmp/replace-existing-media-{normalize_org_slug(args.org_name)}-{args.event_date}-{stamp}.csv"
    return args


def mysql_lines(*, host: str, port: int, user: str, password: str, dbname: str, query: str) -> List[str]:
    require_cmd("mysql")
    env = os.environ.copy()
    env["MYSQL_PWD"] = password
    cmd = ["mysql", "-N", "-B", "--show-warnings", "-h", host, "-P", str(port), "-u", user, dbname, "-e", query]
    cp = run(cmd, env=env, check=False)
    if cp.returncode != 0:
        raise RuntimeError(
            "mysql query failed\n"
            f"cmd: {shlex.join(cmd[:-2])} -e <query>\n"
            f"stderr: {cp.stderr.strip()}"
        )
    return [line for line in (cp.stdout or "").splitlines() if line.strip() != ""]


def mysql_fetch_existing_rows(
    *, host: str, port: int, user: str, password: str, dbname: str, org_name: str, event_date: str, file_type: str
) -> List[ExistingRow]:
    q = (
        "SELECT "
        "f.file_id, f.session_id, COALESCE(f.seq,0), COALESCE(f.file_type,''), "
        "COALESCE(f.file_name,''), COALESCE(f.source_relpath,''), COALESCE(f.checksum_sha256,''), "
        "COALESCE(f.size_bytes,''), COALESCE(s.date,''), COALESCE(s.org_name,'') "
        "FROM files f "
        "JOIN sessions s ON s.session_id = f.session_id "
        f"WHERE s.date = {sql_quote(event_date)} "
        f"AND s.org_name = {sql_quote(org_name)} "
        f"AND f.file_type = {sql_quote(file_type)} "
        "ORDER BY f.seq ASC, f.file_id ASC;"
    )
    rows: List[ExistingRow] = []
    for line in mysql_lines(host=host, port=port, user=user, password=password, dbname=dbname, query=q):
        parts = line.split("\t")
        if len(parts) != 10:
            continue
        size_bytes: Optional[int] = None
        if parts[7].strip() != "":
            try:
                size_bytes = int(parts[7].strip())
            except Exception:
                size_bytes = None
        rows.append(
            ExistingRow(
                file_id=int(parts[0]),
                session_id=int(parts[1]),
                seq=int(parts[2]),
                file_type=parts[3].strip(),
                file_name=parts[4].strip(),
                source_relpath=parts[5].strip(),
                checksum_sha256=parts[6].strip(),
                size_bytes=size_bytes,
                event_date=parts[8].strip(),
                org_name=parts[9].strip(),
                match_mode="canonical",
            )
        )
    return rows


def mysql_fetch_legacy_rows(
    *, host: str, port: int, user: str, password: str, dbname: str, org_name: str, event_date: str, file_type: str
) -> List[ExistingRow]:
    q = (
        "SELECT "
        "f.file_id, f.session_id, COALESCE(f.seq,''), COALESCE(f.file_type,''), "
        "COALESCE(f.file_name,''), COALESCE(f.source_relpath,''), COALESCE(f.checksum_sha256,''), "
        "COALESCE(f.size_bytes,''), COALESCE(s.date,''), COALESCE(s.org_name,'') "
        "FROM files f "
        "LEFT JOIN sessions s ON s.session_id = f.session_id "
        f"WHERE f.file_type = {sql_quote(file_type)} "
        "ORDER BY f.file_id ASC;"
    )
    expected_org = normalize_org_slug(org_name)
    rows: List[ExistingRow] = []
    for line in mysql_lines(host=host, port=port, user=user, password=password, dbname=dbname, query=q):
        parts = line.split("\t")
        if len(parts) != 10:
            continue
        file_name = parts[4].strip()
        source_relpath = parts[5].strip()
        parsed = parse_media_filename(file_name) or parse_media_filename(os.path.basename(source_relpath))
        if parsed is None:
            continue
        if parsed.event_date != event_date:
            continue
        if parsed.org_slug != expected_org:
            continue
        size_bytes: Optional[int] = None
        if parts[7].strip() != "":
            try:
                size_bytes = int(parts[7].strip())
            except Exception:
                size_bytes = None
        session_id: Optional[int] = None
        if parts[1].strip() != "":
            try:
                session_id = int(parts[1].strip())
            except Exception:
                session_id = None
        rows.append(
            ExistingRow(
                file_id=int(parts[0]),
                session_id=session_id,
                seq=parsed.seq,
                file_type=parts[3].strip(),
                file_name=file_name,
                source_relpath=source_relpath,
                checksum_sha256=parts[6].strip(),
                size_bytes=size_bytes,
                event_date=event_date,
                org_name=org_name,
                match_mode="legacy",
            )
        )
    return rows


def mysql_find_file_id_by_checksum(
    *, host: str, port: int, user: str, password: str, dbname: str, checksum_sha256: str
) -> Optional[int]:
    q = f"SELECT file_id FROM files WHERE checksum_sha256 = {sql_quote(checksum_sha256)} LIMIT 1;"
    lines = mysql_lines(host=host, port=port, user=user, password=password, dbname=dbname, query=q)
    if not lines:
        return None
    try:
        return int(lines[0].strip().split("\t")[0])
    except Exception:
        return None


def mysql_update_existing_row(
    *, host: str, port: int, user: str, password: str, dbname: str, file_id: int, local: LocalReplacement,
    duration_seconds: Optional[int], media_info_json: str, media_info_tool: Optional[str]
) -> None:
    media_info_b64 = base64.b64encode(media_info_json.encode("utf-8")).decode("ascii")
    dur_sql = "NULL" if duration_seconds is None else str(int(duration_seconds))
    q = (
        "UPDATE files SET "
        f"file_name = {sql_quote(local.file_name)}, "
        f"source_relpath = {sql_quote(local.source_relpath)}, "
        f"checksum_sha256 = {sql_quote(local.checksum_sha256)}, "
        f"size_bytes = {int(local.size_bytes)}, "
        f"duration_seconds = {dur_sql}, "
        f"media_info = CAST(CONVERT(FROM_BASE64({sql_quote(media_info_b64)}) USING utf8mb4) AS JSON), "
        f"media_info_tool = {sql_quote_nullable(media_info_tool)} "
        f"WHERE file_id = {int(file_id)} LIMIT 1;"
    )
    mysql_lines(host=host, port=port, user=user, password=password, dbname=dbname, query=q)


def ensure_remote_dir(ssh_target: str, remote_dir: str) -> None:
    require_cmd("ssh")
    cmd = (
        "set -euo pipefail; "
        + f"(sudo -n install -d -o www-data -g www-data -m 0775 {shlex.quote(remote_dir)} 2>/dev/null || true); "
        + f"mkdir -p {shlex.quote(remote_dir)}; "
        + f"chmod 0775 {shlex.quote(remote_dir)} 2>/dev/null || true"
    )
    cp = run(["ssh", "-o", "BatchMode=yes", ssh_target, "bash", "-lc", cmd], check=False)
    if cp.returncode != 0:
        raise RuntimeError(f"Failed to ensure remote dir {remote_dir}: {(cp.stderr or cp.stdout).strip()}")


def remote_path_exists(ssh_target: str, remote_path: str) -> bool:
    require_cmd("ssh")
    cp = run(["ssh", "-o", "BatchMode=yes", ssh_target, "test", "-f", remote_path], check=False)
    return cp.returncode == 0


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


def remote_delete_file(ssh_target: str, remote_path: str) -> bool:
    require_cmd("ssh")
    cmd = (
        "set -euo pipefail; "
        f"if [ -f {shlex.quote(remote_path)} ]; then "
        f"  rm -f {shlex.quote(remote_path)} 2>/dev/null || sudo -n rm -f {shlex.quote(remote_path)} 2>/dev/null || exit 1; "
        "fi"
    )
    cp = run(["ssh", "-o", "BatchMode=yes", ssh_target, "bash", "-lc", cmd], check=False)
    return cp.returncode == 0


def rsync_copy(src: str, ssh_target: str, dest_abs: str, *, show_progress: bool) -> Tuple[bool, str]:
    require_cmd("rsync")
    cmd = ["rsync", "-a", "--protect-args", "--partial", "--info=progress2" if show_progress else "--info=none", src, f"{ssh_target}:{dest_abs}"]
    if not show_progress:
        cp = run(cmd, check=False)
        if cp.returncode == 0:
            remote_fix_ownership(ssh_target, dest_abs)
            return True, ""
        return False, (cp.stderr.strip() or cp.stdout.strip() or f"rsync failed rc={cp.returncode}")
    proc = subprocess.Popen(cmd, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, bufsize=1)
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
    return False, ("\n".join(err_lines).strip() or f"rsync failed rc={rc}")


def remote_ffprobe_json(ssh_target: str, remote_path: str) -> Tuple[Optional[int], str, str]:
    require_cmd("ssh")
    cp = run(
        [
            "ssh", "-o", "BatchMode=yes", ssh_target,
            "ffprobe", "-v", "error", "-print_format", "json", "-show_format", "-show_streams", "-show_chapters", "-show_programs", remote_path,
        ],
        check=False,
    )
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
        if isinstance(fmt, dict) and isinstance(fmt.get("filename"), str):
            fmt["filename"] = os.path.basename(fmt["filename"])
        json_text = json.dumps(obj, ensure_ascii=False, separators=(",", ":"))
    except Exception:
        duration_seconds = None
    vcp = run(["ssh", "-o", "BatchMode=yes", ssh_target, "ffprobe", "-version"], check=False)
    tool_line = (vcp.stdout or "").splitlines()[0].strip() if vcp.returncode == 0 and (vcp.stdout or "").splitlines() else ""
    return duration_seconds, json_text, (tool_line or "ffprobe")


def remote_ffmpeg_thumbnail(
    *, ssh_target: str, video_path: str, thumb_path: str, seek_seconds: float, width_px: int, timeout_seconds: int
) -> None:
    require_cmd("ssh")
    ensure_remote_dir(ssh_target, str(Path(thumb_path).parent))
    tmp_path = thumb_path + ".tmp.png"
    vf = f"scale={int(width_px)}:-1"
    cmd = (
        "set -euo pipefail; "
        f"ffmpeg -nostdin -hide_banner -loglevel error -y -ss {seek_seconds:.3f} -i {shlex.quote(video_path)} "
        f"-frames:v 1 -vf {shlex.quote(vf)} -an -sn {shlex.quote(tmp_path)}; "
        f"mv -f {shlex.quote(tmp_path)} {shlex.quote(thumb_path)}"
    )
    cp = run(["ssh", "-o", "BatchMode=yes", ssh_target, "bash", "-lc", cmd], check=False, timeout_seconds=timeout_seconds)
    if cp.returncode != 0:
        raise RuntimeError((cp.stderr or cp.stdout or "ffmpeg thumbnail failed").strip())
    remote_fix_ownership(ssh_target, thumb_path)


def parse_local_replacements(source_dir: Path, expected_type: str, org_name: str, event_date: str) -> List[LocalReplacement]:
    if not source_dir.is_dir():
        raise RuntimeError(f"--source-dir is not a directory: {source_dir}")
    items: List[LocalReplacement] = []
    seen_seq: Dict[int, Path] = {}
    expected_org = normalize_org_slug(org_name)
    expected_ymd = event_date.replace("-", "")
    for path in sorted(source_dir.iterdir()):
        if not path.is_file():
            continue
        m = FILENAME_RE.match(path.name)
        if not m:
            continue
        org_slug = normalize_org_slug(m.group("org"))
        ymd = m.group("ymd")
        if org_slug != expected_org:
            raise RuntimeError(f"Replacement file org slug does not match --org-name: {path.name}")
        if ymd != expected_ymd:
            raise RuntimeError(f"Replacement file date does not match --event-date: {path.name}")
        ext = m.group("ext").lower()
        file_type = infer_type_from_ext(ext)
        if file_type == "":
            raise RuntimeError(f"Unsupported media extension for replacement file: {path.name}")
        if file_type != expected_type:
            continue
        seq = int(m.group("seq"))
        if seq in seen_seq:
            raise RuntimeError(f"Duplicate local seq {seq} for files {seen_seq[seq].name} and {path.name}")
        seen_seq[seq] = path
        items.append(
            LocalReplacement(
                local_path=path,
                source_relpath=path.name,
                file_name=path.name,
                seq=seq,
                ext=ext,
                file_type=file_type,
                size_bytes=path.stat().st_size,
                checksum_sha256=sha256_file(path),
            )
        )
    if not items:
        raise RuntimeError(f"No matching {expected_type} replacement files found in {source_dir}")
    return items


def build_plan(args: argparse.Namespace) -> List[PlanItem]:
    local_files = parse_local_replacements(Path(args.source_dir), args.file_type, args.org_name, args.event_date)
    existing_rows = mysql_fetch_existing_rows(
        host=args.db_host,
        port=args.db_port,
        user=args.db_user,
        password=args.db_password,
        dbname=args.db_name,
        org_name=args.org_name,
        event_date=args.event_date,
        file_type=args.file_type,
    )
    if not existing_rows:
        existing_rows = mysql_fetch_legacy_rows(
            host=args.db_host,
            port=args.db_port,
            user=args.db_user,
            password=args.db_password,
            dbname=args.db_name,
            org_name=args.org_name,
            event_date=args.event_date,
            file_type=args.file_type,
        )
    if not existing_rows:
        raise RuntimeError(f"No existing {args.file_type} rows found for org_name={args.org_name!r} event_date={args.event_date!r}")
    by_seq: Dict[int, ExistingRow] = {}
    for row in existing_rows:
        if row.seq is None:
            raise RuntimeError(f"Matched row file_id={row.file_id} does not have a usable seq for replacement planning")
        if row.seq in by_seq:
            raise RuntimeError(f"Ambiguous DB state: multiple rows found for seq={row.seq} on {args.event_date} / {args.org_name}")
        by_seq[row.seq] = row
    plan: List[PlanItem] = []
    for local in local_files:
        existing = by_seq.get(local.seq)
        if existing is None:
            raise RuntimeError(f"No existing DB row found for seq={local.seq} on {args.event_date} / {args.org_name}")
        conflict_id = mysql_find_file_id_by_checksum(
            host=args.db_host,
            port=args.db_port,
            user=args.db_user,
            password=args.db_password,
            dbname=args.db_name,
            checksum_sha256=local.checksum_sha256,
        )
        if conflict_id is not None and conflict_id != existing.file_id:
            raise RuntimeError(
                f"Checksum collision for local file {local.file_name}: sha {local.checksum_sha256} already belongs to file_id={conflict_id}"
            )
        dest_dir = args.dest_audio if local.file_type == "audio" else args.dest_video
        remote_dest_abs = f"{dest_dir.rstrip('/')}/{local.checksum_sha256}.{local.ext}"
        remote_old_abs = None
        if validate_sha256(existing.checksum_sha256):
            old_ext = Path(existing.file_name or existing.source_relpath).suffix.lower().lstrip(".") or local.ext
            remote_old_abs = f"{dest_dir.rstrip('/')}/{existing.checksum_sha256}.{old_ext}"
        remote_thumb_new = None
        remote_thumb_old = None
        if local.file_type == "video":
            thumb_dir = f"{args.dest_video.rstrip('/')}/thumbnails"
            remote_thumb_new = f"{thumb_dir}/{local.checksum_sha256}.png"
            if validate_sha256(existing.checksum_sha256):
                remote_thumb_old = f"{thumb_dir}/{existing.checksum_sha256}.png"
        plan.append(
            PlanItem(
                local=local,
                existing=existing,
                remote_dest_abs=remote_dest_abs,
                remote_old_abs=remote_old_abs,
                remote_thumb_new=remote_thumb_new,
                remote_thumb_old=remote_thumb_old,
            )
        )
    return sorted(plan, key=lambda x: x.local.seq)


def write_backup_csv(path: Path, plan: List[PlanItem]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow([
            "file_id", "session_id", "seq", "event_date", "org_name", "file_type", "match_mode",
            "old_file_name", "old_source_relpath", "old_checksum_sha256", "old_size_bytes",
            "new_file_name", "new_source_relpath", "new_checksum_sha256", "new_size_bytes", "local_path",
        ])
        for item in plan:
            w.writerow([
                item.existing.file_id,
                item.existing.session_id,
                item.existing.seq,
                item.existing.event_date,
                item.existing.org_name,
                item.existing.file_type,
                item.existing.match_mode,
                item.existing.file_name,
                item.existing.source_relpath,
                item.existing.checksum_sha256,
                "" if item.existing.size_bytes is None else item.existing.size_bytes,
                item.local.file_name,
                item.local.source_relpath,
                item.local.checksum_sha256,
                item.local.size_bytes,
                str(item.local.local_path),
            ])


def print_plan(plan: List[PlanItem]) -> None:
    print("STATUS\tMODE\tSEQ\tFILE_ID\tOLD_SHA\tNEW_SHA\tSOURCE_RELPATH\tDEST")
    for item in plan:
        print(
            "PLAN"
            f"\t{item.existing.match_mode}"
            f"\t{item.local.seq}"
            f"\t{item.existing.file_id}"
            f"\t{item.existing.checksum_sha256}"
            f"\t{item.local.checksum_sha256}"
            f"\t{item.local.source_relpath}"
            f"\t{item.remote_dest_abs}"
        )


def apply_plan(args: argparse.Namespace, plan: List[PlanItem]) -> int:
    ensure_remote_dir(args.ssh_target, args.dest_audio)
    ensure_remote_dir(args.ssh_target, args.dest_video)
    if args.thumbs and args.file_type == "video":
        ensure_remote_dir(args.ssh_target, f"{args.dest_video.rstrip('/')}/thumbnails")
    print("STATUS\tSEQ\tFILE_ID\tSOURCE_RELPATH\tDETAIL")
    copied = 0
    updated = 0
    thumbs_created = 0
    thumbs_exists = 0
    old_deleted = 0
    failed = 0
    for item in plan:
        ok, err = rsync_copy(str(item.local.local_path), args.ssh_target, item.remote_dest_abs, show_progress=(not args.no_progress))
        if not ok:
            failed += 1
            print(f"FAILED\t{item.local.seq}\t{item.existing.file_id}\t{item.local.source_relpath}\tcopy: {err}")
            continue
        copied += 1
        print(f"COPIED\t{item.local.seq}\t{item.existing.file_id}\t{item.local.source_relpath}\t{item.remote_dest_abs}")
        try:
            duration_seconds, media_info_json, media_info_tool = remote_ffprobe_json(args.ssh_target, item.remote_dest_abs)
            mysql_update_existing_row(
                host=args.db_host,
                port=args.db_port,
                user=args.db_user,
                password=args.db_password,
                dbname=args.db_name,
                file_id=item.existing.file_id,
                local=item.local,
                duration_seconds=duration_seconds,
                media_info_json=media_info_json,
                media_info_tool=media_info_tool,
            )
            updated += 1
            print(f"UPDATED\t{item.local.seq}\t{item.existing.file_id}\t{item.local.source_relpath}\tsha={item.local.checksum_sha256}")
            if args.thumbs and item.local.file_type == "video" and item.remote_thumb_new is not None:
                if args.force_thumb or not remote_path_exists(args.ssh_target, item.remote_thumb_new):
                    t = pick_thumbnail_timestamp(duration_seconds, item.local.checksum_sha256)
                    remote_ffmpeg_thumbnail(
                        ssh_target=args.ssh_target,
                        video_path=item.remote_dest_abs,
                        thumb_path=item.remote_thumb_new,
                        seek_seconds=t,
                        width_px=max(32, int(args.thumb_width)),
                        timeout_seconds=max(5, int(args.thumb_timeout)),
                    )
                    thumbs_created += 1
                    print(f"THUMBNAIL_CREATED\t{item.local.seq}\t{item.existing.file_id}\t{item.local.source_relpath}\t{item.remote_thumb_new}")
                else:
                    thumbs_exists += 1
                    print(f"THUMBNAIL_EXISTS\t{item.local.seq}\t{item.existing.file_id}\t{item.local.source_relpath}\t{item.remote_thumb_new}")
            if args.delete_old_remote_blobs:
                if item.remote_old_abs and item.remote_old_abs != item.remote_dest_abs and remote_delete_file(args.ssh_target, item.remote_old_abs):
                    old_deleted += 1
                    print(f"OLD_REMOTE_DELETED\t{item.local.seq}\t{item.existing.file_id}\t{item.local.source_relpath}\t{item.remote_old_abs}")
                if item.remote_thumb_old and item.remote_thumb_new and item.remote_thumb_old != item.remote_thumb_new and remote_delete_file(args.ssh_target, item.remote_thumb_old):
                    old_deleted += 1
                    print(f"OLD_REMOTE_DELETED\t{item.local.seq}\t{item.existing.file_id}\t{item.local.source_relpath}\t{item.remote_thumb_old}")
        except Exception as e:
            failed += 1
            print(f"FAILED\t{item.local.seq}\t{item.existing.file_id}\t{item.local.source_relpath}\t{str(e)}")
    eprint(
        "SUMMARY "
        f"matched={len(plan)} copied={copied} updated={updated} thumbnails_created={thumbs_created} "
        f"thumbnails_exists={thumbs_exists} cleanup_actions={old_deleted} failures={failed}"
    )
    return 0 if failed == 0 else 1


def main(argv: Optional[Sequence[str]] = None) -> int:
    global ALLOWED_MIMES, AUDIO_EXTS, VIDEO_EXTS
    args = parse_args(argv)
    for cmd in ("mysql", "ssh", "rsync"):
        require_cmd(cmd)
    if args.file_type == "video" and args.thumbs:
        require_cmd("ssh")
    cfg = resolve_media_config(args.group_vars, args.no_group_vars)
    ALLOWED_MIMES = set(cfg.allowed_mimes)
    AUDIO_EXTS = set(cfg.audio_exts)
    VIDEO_EXTS = set(cfg.video_exts)
    eprint(
        f"Media config source: {cfg.source}; allowed_mimes={len(ALLOWED_MIMES)} audio_exts={len(AUDIO_EXTS)} video_exts={len(VIDEO_EXTS)}"
    )
    if cfg.message != "ok":
        eprint(cfg.message)
    if not args.db_password:
        args.db_password = getpass.getpass("MySQL password: ")
    plan = build_plan(args)
    if args.backup_csv:
        write_backup_csv(Path(args.backup_csv), plan)
    print_plan(plan)
    if args.dry_run:
        eprint(f"SUMMARY matched={len(plan)} apply=0 dry_run=1")
        return 0
    return apply_plan(args, plan)


if __name__ == "__main__":
    raise SystemExit(main())
