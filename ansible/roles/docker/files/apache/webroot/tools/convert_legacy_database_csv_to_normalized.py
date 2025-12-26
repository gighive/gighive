#!/usr/bin/env python3

import argparse
import csv
import hashlib
import json
import os
import sys
import time
import unicodedata
from dataclasses import dataclass
from typing import Dict, Iterable, List, Optional, Sequence, Tuple


DEFAULT_AUDIO_EXTS = {
    "mp3", "wav", "aac", "flac", "m4a",
}

DEFAULT_VIDEO_EXTS = {
    "mp4", "mov", "mkv", "webm", "avi",
}


def _norm(s: str) -> str:
    return unicodedata.normalize("NFC", (s or "").strip())


def _sha1_hex(s: str) -> str:
    return hashlib.sha1(s.encode("utf-8")).hexdigest()


def session_key_for_legacy_row(*, legacy_csv_basename: str, legacy_row_index: int, d_date: str, t_title: str) -> str:
    canonical = "|".join(
        [
            f"legacy_csv={_norm(legacy_csv_basename)}",
            f"row={legacy_row_index}",
            f"d_date={_norm(d_date)}",
            f"t_title={_norm(t_title)}",
        ]
    )
    return _sha1_hex(canonical)


def file_ext_lower(path: str) -> str:
    p = (path or "").strip()
    dot = p.rfind(".")
    if dot < 0:
        return ""
    return p[dot + 1 :].lower()


def sha256_file(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


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


def infer_file_type_from_ext(ext: str, audio_exts: set, video_exts: set) -> str:
    e = (ext or "").strip().lower().lstrip(".")
    if e in audio_exts:
        return "audio"
    if e in video_exts:
        return "video"
    return ""


def resolve_media_path(
    *,
    token: str,
    source_roots: Sequence[str],
    media_search_dirs: Sequence[str],
) -> Optional[str]:
    tok = (token or "").strip()
    if tok == "":
        return None

    if os.path.isabs(tok) and os.path.isfile(tok):
        return tok

    for root in source_roots:
        if not root:
            continue
        candidate = os.path.normpath(os.path.join(root, tok.lstrip("/\\")))
        if os.path.isfile(candidate):
            return candidate

    base = os.path.basename(tok)
    for d in media_search_dirs:
        if not d:
            continue
        candidate = os.path.join(d, base)
        if os.path.isfile(candidate):
            return candidate

    return None


def split_legacy_f_singles(value: str) -> List[str]:
    """
    Best-effort split for legacy f_singles which historically used comma-separated lists.

    This cannot perfectly recover from delimiter collisions (commas inside folder names).
    So we:
    - split on commas
    - trim
    - drop empties
    """
    if not value:
        return []
    parts = [p.strip() for p in value.split(",")]
    return [p for p in parts if p]


@dataclass
class Report:
    sessions_total: int = 0
    sessions_with_files: int = 0
    file_tokens_total: int = 0
    files_emitted: int = 0
    files_hashed: int = 0
    files_missing_on_disk: int = 0
    ignored_non_media: int = 0
    ignored_suspicious: int = 0
    warnings: List[str] = None

    def __post_init__(self) -> None:
        if self.warnings is None:
            self.warnings = []

    def warn(self, msg: str) -> None:
        if len(self.warnings) < 200:
            self.warnings.append(msg)


def write_csv(path: str, fieldnames: Sequence[str], rows: Iterable[Dict[str, str]]) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(fieldnames))
        w.writeheader()
        for r in rows:
            w.writerow({k: r.get(k, "") for k in fieldnames})


def write_json(path: str, obj: object) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        json.dump(obj, f, ensure_ascii=False, indent=2)


def count_csv_rows(path: str) -> int:
    with open(path, "r", newline="", encoding="utf-8") as f:
        reader = csv.reader(f)
        # Skip header
        try:
            next(reader)
        except StopIteration:
            return 0
        return sum(1 for _ in reader)


def convert_legacy_csv(
    input_csv: str,
    output_dir: str,
    *,
    source_roots: Sequence[str],
    media_search_dirs: Sequence[str],
    audio_exts: set,
    video_exts: set,
    org_name: str,
    event_type: str,
    total_sessions: int,
    show_progress: bool,
    progress_every_seconds: float,
    manifest_include_missing: bool,
) -> Tuple[str, str, str, str]:
    legacy_csv_basename = os.path.basename(input_csv)

    sessions_rows: List[Dict[str, str]] = []
    session_files_rows: List[Dict[str, str]] = []
    manifest_items: List[Dict[str, object]] = []
    report = Report()

    with open(input_csv, "r", newline="", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        if not reader.fieldnames:
            raise RuntimeError("Input CSV appears to have no header row")

        required = {"t_title", "d_date", "f_singles"}
        missing = [c for c in required if c not in set(reader.fieldnames)]
        if missing:
            raise RuntimeError(f"Missing required legacy headers: {', '.join(missing)}")

        # Preserve all legacy session metadata columns in sessions.csv, except f_singles
        # (file lists move to session_files.csv). We also add session_key.
        legacy_session_cols = [c for c in reader.fieldnames if c != "f_singles"]
        sessions_cols = ["session_key"] + legacy_session_cols

        last_progress_at = 0.0
        last_progress_printed = ""

        for row_index_0, row in enumerate(reader):
            legacy_row_index = row_index_0 + 1
            report.sessions_total += 1

            if show_progress and total_sessions > 0:
                now = time.monotonic()
                if (now - last_progress_at) >= progress_every_seconds:
                    pct = int((legacy_row_index / total_sessions) * 100)
                    msg = f"Progress: {legacy_row_index}/{total_sessions} sessions ({pct}%)"
                    if msg != last_progress_printed:
                        print("\r" + msg, end="", file=sys.stderr, flush=True)
                        last_progress_printed = msg
                    last_progress_at = now

            t_title = row.get("t_title", "")
            d_date = row.get("d_date", "")

            skey = session_key_for_legacy_row(
                legacy_csv_basename=legacy_csv_basename,
                legacy_row_index=legacy_row_index,
                d_date=d_date,
                t_title=t_title,
            )

            # Lossless session row: include session_key + all legacy columns (except f_singles)
            srow: Dict[str, str] = {"session_key": skey}
            for c in legacy_session_cols:
                srow[c] = row.get(c, "")
            sessions_rows.append(srow)

            raw = row.get("f_singles", "")
            tokens = split_legacy_f_singles(raw)
            report.file_tokens_total += len(tokens)

            if tokens:
                report.sessions_with_files += 1

            seq = 0
            for tok in tokens:
                ext = file_ext_lower(tok)
                file_type = infer_file_type_from_ext(ext, audio_exts, video_exts)
                if file_type:
                    seq += 1

                    checksum_sha256 = ""
                    size_bytes: Optional[int] = None
                    resolved = resolve_media_path(
                        token=tok,
                        source_roots=source_roots,
                        media_search_dirs=media_search_dirs,
                    )
                    if resolved:
                        try:
                            checksum_sha256 = sha256_file(resolved)
                            report.files_hashed += 1
                            try:
                                size_bytes = int(os.path.getsize(resolved))
                            except Exception:
                                size_bytes = None
                        except Exception as e:
                            report.warn(f"Row {legacy_row_index}: failed to hash {tok!r} at {resolved!r}: {e}")
                    else:
                        report.files_missing_on_disk += 1
                        if source_roots or media_search_dirs:
                            report.warn(f"Row {legacy_row_index}: media file not found on disk for token: {tok!r}")

                    if checksum_sha256 or manifest_include_missing:
                        manifest_items.append(
                            {
                                "file_name": os.path.basename(tok),
                                "source_relpath": tok,
                                "file_type": file_type,
                                "event_date": d_date,
                                "size_bytes": size_bytes if size_bytes is not None else 0,
                                "checksum_sha256": checksum_sha256,
                            }
                        )

                    session_files_rows.append(
                        {
                            "session_key": skey,
                            "source_relpath": tok,
                            "checksum_sha256": checksum_sha256,
                            "seq": str(seq),
                        }
                    )
                    report.files_emitted += 1
                    continue

                # Not a supported media extension.
                report.ignored_non_media += 1

                # Flag entries that look like likely corruption artifacts (e.g., "Music/Panneton" fragments)
                # heuristic: contains a slash but has no extension
                if ("/" in tok or "\\" in tok) and ext == "":
                    report.ignored_suspicious += 1
                    report.warn(
                        f"Row {legacy_row_index}: suspicious non-media token in f_singles: {tok!r} (possible comma collision artifact)"
                    )

        if show_progress and total_sessions > 0:
            msg = f"Progress: {min(report.sessions_total, total_sessions)}/{total_sessions} sessions (100%)"
            print("\r" + msg, file=sys.stderr, flush=True)
            print("", file=sys.stderr, flush=True)

    sessions_path = os.path.join(output_dir, "sessions.csv")
    session_files_path = os.path.join(output_dir, "session_files.csv")
    manifest_path = os.path.join(output_dir, "manifest.json")
    report_path = os.path.join(output_dir, "conversion_report.txt")

    write_csv(
        sessions_path,
        sessions_cols,
        sessions_rows,
    )

    write_csv(
        session_files_path,
        ["session_key", "source_relpath", "checksum_sha256", "seq"],
        session_files_rows,
    )

    write_json(
        manifest_path,
        {
            "org_name": org_name,
            "event_type": event_type,
            "items": manifest_items,
        },
    )

    os.makedirs(output_dir, exist_ok=True)
    with open(report_path, "w", encoding="utf-8") as rf:
        rf.write(f"input_csv: {input_csv}\n")
        rf.write(f"output_dir: {output_dir}\n")
        rf.write(f"manifest_path: {manifest_path}\n")
        rf.write(f"manifest_items: {len(manifest_items)}\n")
        rf.write("\n")
        rf.write(f"sessions_total: {report.sessions_total}\n")
        rf.write(f"sessions_with_files: {report.sessions_with_files}\n")
        rf.write(f"file_tokens_total (post-split): {report.file_tokens_total}\n")
        rf.write(f"files_emitted (supported media): {report.files_emitted}\n")
        rf.write(f"files_hashed: {report.files_hashed}\n")
        rf.write(f"files_missing_on_disk: {report.files_missing_on_disk}\n")
        rf.write(f"ignored_non_media: {report.ignored_non_media}\n")
        rf.write(f"ignored_suspicious: {report.ignored_suspicious}\n")
        rf.write("\n")
        if report.warnings:
            rf.write("Warnings (first 200):\n")
            for w in report.warnings:
                rf.write(f"- {w}\n")

    return sessions_path, session_files_path, manifest_path, report_path


def main(argv: Optional[Sequence[str]] = None) -> int:
    ap = argparse.ArgumentParser(
        description=(
            "One-time converter: legacy database.csv (embedded f_singles) -> normalized sessions.csv + session_files.csv."
        )
    )
    ap.add_argument("input_csv", help="Path to legacy database.csv")
    ap.add_argument(
        "--output-dir",
        default="normalized_csvs",
        help="Output directory to write sessions.csv, session_files.csv, and conversion_report.txt",
    )
    ap.add_argument(
        "--source-root",
        default=os.getenv("GIGHIVE_SOURCE_ROOT") or "",
        help="Optional: base directory to resolve source_relpath tokens for hashing",
    )
    ap.add_argument(
        "--source-roots",
        default=os.getenv("GIGHIVE_SOURCE_ROOTS") or "",
        help="Optional: colon-separated base directories to resolve source_relpath tokens for hashing (tried in order)",
    )
    ap.add_argument(
        "--media-search-dirs",
        default=os.getenv("MEDIA_SEARCH_DIRS") or "",
        help="Optional: colon-separated directories to search by basename when hashing",
    )
    ap.add_argument(
        "--group-vars",
        default=os.getenv("GIGHIVE_GROUP_VARS")
        or os.path.join(
            os.path.dirname(__file__),
            "..",
            "..",
            "..",
            "..",
            "..",
            "..",
            "..",
            "inventories",
            "group_vars",
            "gighive2",
            "gighive2.yml",
        ),
        help="Path to Ansible group_vars (for canonical extension lists)",
    )
    ap.add_argument("--org-name", default="default")
    ap.add_argument("--event-type", default="band")
    ap.add_argument(
        "--no-progress",
        action="store_true",
        help="Disable live progress meter",
    )
    ap.add_argument(
        "--progress-every-seconds",
        type=float,
        default=0.5,
        help="Progress meter update interval in seconds (default: 0.5)",
    )
    ap.add_argument(
        "--manifest-include-missing",
        action="store_true",
        help="Include items with missing checksum_sha256 in manifest.json (not compatible with manifest reload endpoint)",
    )

    args = ap.parse_args(argv)

    input_csv = args.input_csv
    output_dir = args.output_dir

    source_root_single = str(args.source_root or "").strip() or ""
    source_roots_multi = [p for p in str(args.source_roots or "").split(":") if p.strip()]
    source_roots: List[str] = []
    if source_root_single:
        source_roots.append(source_root_single)
    source_roots.extend(source_roots_multi)
    media_search_dirs = [p for p in str(args.media_search_dirs or "").split(":") if p.strip()]

    group_vars_path = str(args.group_vars or "").strip()
    audio_exts, video_exts, _msg = load_ext_sets_from_group_vars(group_vars_path)
    if audio_exts is None or video_exts is None:
        audio_exts = set(DEFAULT_AUDIO_EXTS)
        video_exts = set(DEFAULT_VIDEO_EXTS)

    if not os.path.isfile(input_csv):
        print(f"error: input_csv does not exist: {input_csv}", file=sys.stderr)
        return 2

    total_sessions = count_csv_rows(input_csv)

    sessions_path, session_files_path, manifest_path, report_path = convert_legacy_csv(
        input_csv,
        output_dir,
        source_roots=source_roots,
        media_search_dirs=media_search_dirs,
        audio_exts=audio_exts,
        video_exts=video_exts,
        org_name=str(args.org_name or "default").strip() or "default",
        event_type=str(args.event_type or "band").strip() or "band",
        total_sessions=total_sessions,
        show_progress=(not bool(args.no_progress)),
        progress_every_seconds=float(args.progress_every_seconds),
        manifest_include_missing=bool(args.manifest_include_missing),
    )
    print("✅ Conversion complete")
    print(f"- sessions.csv: {sessions_path}")
    print(f"- session_files.csv: {session_files_path}")
    print(f"- manifest.json: {manifest_path}")
    print(f"- report: {report_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
