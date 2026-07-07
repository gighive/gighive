#!/usr/bin/env python3
"""
load_test_guest_uploads.py — GigHive QR guest upload pipeline load tester.

Simulates N concurrent guest uploads against the TUS endpoint + finalize step.
Requires an active QR upload token (scan the QR code, copy the ?token= value).

Usage:
    python3 load_test_guest_uploads.py \
        --url https://your.server.com \
        --token <QR_TOKEN> \
        --concurrency 10 \
        --count 20 \
        [--size-kb 512] \
        [--real-file /tmp/test_clip.mp4] \
        [--tus-only] \
        [--no-ssl-verify] \
        [--timeout 130] \
        [--stagger 0.0] \
        [--chunk-size-mb 5] \
        [--log-dir load_test_runs]

    Run with increasing --concurrency (5, 10, 20, 30, 50) to find saturation.

Dependencies:
    pip install aiohttp
"""
import argparse
import asyncio
import base64
import datetime
import os
import sys
import time
from collections import Counter
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional
from urllib.parse import urlparse

try:
    import aiohttp
except ImportError:
    sys.exit("Missing dependency: pip install aiohttp")


# ---------------------------------------------------------------------------
# Synthetic test file — minimal MP4 ftyp box + random padding
# ---------------------------------------------------------------------------

_FTYP = b'\x00\x00\x00\x1cftypisom\x00\x00\x02\x00isomiso2avc1mp41'


def make_fake_video(size_bytes: int) -> bytes:
    padding = max(0, size_bytes - len(_FTYP))
    return _FTYP + os.urandom(padding)


def load_real_file(path: str) -> bytes:
    """Load an actual media file from disk for realistic ffprobe/ffmpeg testing."""
    with open(path, "rb") as f:
        data = f.read()
    if not data:
        sys.exit(f"Real file is empty: {path}")
    return data


def _b64(s: str) -> str:
    return base64.b64encode(s.encode()).decode()


# ---------------------------------------------------------------------------
# Per-upload result
# ---------------------------------------------------------------------------

@dataclass
class UploadResult:
    index: int
    success: bool
    total_s: float
    tus_s: float = 0.0
    finalize_s: float = 0.0
    error: Optional[str] = None


# ---------------------------------------------------------------------------
# Single upload coroutine
# ---------------------------------------------------------------------------

async def do_upload(
    session: aiohttp.ClientSession,
    base_url: str,
    token: str,
    index: int,
    file_data: bytes,
    sem: asyncio.Semaphore,
    tus_only: bool = False,
    finalize_retries: int = 6,
    stagger: float = 0.0,
    chunk_size: int = 5 * 1024 * 1024,
) -> UploadResult:
    if stagger > 0.0:
        await asyncio.sleep(index * stagger)
    async with sem:
        t0 = time.perf_counter()
        # Append a unique suffix so every upload has a distinct SHA-256.
        # Without this, the server deduplicates identical bytes (409 after the first ingest).
        file_data = file_data + os.urandom(8)
        label = f"LoadTest-{index:04d}"
        display_name = f"Tester-{index:04d}"

        tus_hdrs = {
            "Tus-Resumable": "1.0.0",
            "X-Upload-Token": token,
        }

        def _ts() -> str:
            return datetime.datetime.now().strftime("%H:%M:%S.%f")[:-3]
        tus_t = time.perf_counter()
        print(f"[{index:04d}] {_ts()} CREATE  start", file=sys.stderr, flush=True)
        # ── Step 1: TUS CREATE ──────────────────────────────────────────────
        create_hdrs = {
            **tus_hdrs,
            "Upload-Length": str(len(file_data)),
            "Content-Length": "0",
            "Upload-Metadata": ", ".join([
                f"filename {_b64(f'test-{index:04d}.mp4')}",
                f"label {_b64(label)}",
                f"display_name {_b64(display_name)}",
            ]),
        }
        try:
            async with session.post(
                f"{base_url}/files/",
                headers=create_hdrs,
                data=b"",
                allow_redirects=False,
            ) as r:
                if r.status != 201:
                    body = await r.text()
                    err = f"TUS-CREATE {r.status}: {body[:200]}"
                    print(f"[{index:04d}] {_ts()} FAIL   {err}", file=sys.stderr, flush=True)
                    return UploadResult(
                        index, False, time.perf_counter() - t0,
                        error=err
                    )
                location = r.headers.get("Location", "")
        except Exception as e:
            err = f"TUS-CREATE exception: {e}"
            print(f"[{index:04d}] {_ts()} FAIL   {err}", file=sys.stderr, flush=True)
            return UploadResult(index, False, time.perf_counter() - t0, error=err)

        if not location:
            return UploadResult(index, False, time.perf_counter() - t0,
                                error="TUS-CREATE: no Location header")

        # Make URL absolute if tusd returns a relative path
        if location.startswith("/"):
            p = urlparse(base_url)
            upload_url = f"{p.scheme}://{p.netloc}{location}"
        else:
            upload_url = location

        upload_id = upload_url.rstrip("/").split("/")[-1]

        patch_t = time.perf_counter()
        file_size = len(file_data)
        n_chunks = max(1, (file_size + chunk_size - 1) // chunk_size)
        print(f"[{index:04d}] {_ts()} PATCH   start  upload_id={upload_id}  size={file_size}  chunks={n_chunks}", file=sys.stderr, flush=True)
        # ── Step 2: TUS PATCH (chunked) ────────────────────────────────────
        offset = 0
        tus_s = 0.0
        try:
            while offset < file_size:
                chunk = file_data[offset:offset + chunk_size]
                chunk_hdrs = {
                    **tus_hdrs,
                    "Content-Type": "application/offset+octet-stream",
                    "Upload-Offset": str(offset),
                    "Content-Length": str(len(chunk)),
                }
                if n_chunks > 1:
                    chunk_num = offset // chunk_size + 1
                    print(f"[{index:04d}] {_ts()} PATCH   chunk {chunk_num}/{n_chunks}  offset={offset}", file=sys.stderr, flush=True)
                async with session.patch(
                    upload_url,
                    headers=chunk_hdrs,
                    data=chunk,
                ) as r:
                    tus_s = time.perf_counter() - tus_t
                    if r.status != 204:
                        body = await r.text()
                        err = f"TUS-PATCH {r.status}: {body[:200]}"
                        print(f"[{index:04d}] {_ts()} FAIL   {err}  tus_s={tus_s:.2f}s", file=sys.stderr, flush=True)
                        return UploadResult(
                            index, False, time.perf_counter() - t0,
                            tus_s=tus_s,
                            error=err
                        )
                    new_offset = int(r.headers.get("Upload-Offset", offset + len(chunk)))
                    offset = new_offset
            print(f"[{index:04d}] {_ts()} PATCH   done   tus_s={tus_s:.2f}s", file=sys.stderr, flush=True)
        except Exception as e:
            err = f"TUS-PATCH exception: {e}"
            patch_elapsed = time.perf_counter() - patch_t
            print(f"[{index:04d}] {_ts()} FAIL   {err}  patch_elapsed={patch_elapsed:.2f}s", file=sys.stderr, flush=True)
            return UploadResult(index, False, time.perf_counter() - t0, error=err)

        if tus_only:
            return UploadResult(index, True, time.perf_counter() - t0, tus_s=tus_s)

        print(f"[{index:04d}] {_ts()} FINALIZE start", file=sys.stderr, flush=True)
        # ── Step 3: FINALIZE (retry — hook file may take a moment to land) ─
        fin_err: Optional[str] = None
        fin_s = 0.0
        for attempt in range(finalize_retries):
            if attempt > 0:
                await asyncio.sleep(0.4 * attempt)  # 0, 0.4, 0.8, 1.2, 1.6, 2.0 s
            fin_t = time.perf_counter()
            try:
                async with session.post(
                    f"{base_url}/api/uploads/finalize",
                    headers={
                        "Content-Type": "application/json",
                        "X-Upload-Token": token,
                    },
                    json={
                        "upload_id": upload_id,
                        "label": label,
                        "display_name": display_name,
                        "tos_accepted": True,
                    },
                ) as r:
                    fin_s = time.perf_counter() - fin_t
                    body = await r.text()
                    if r.status == 201:
                        total = time.perf_counter() - t0
                        print(f"[{index:04d}] {_ts()} OK     total={total:.2f}s  tus={tus_s:.2f}s  fin={fin_s:.2f}s", file=sys.stderr, flush=True)
                        return UploadResult(
                            index, True, total,
                            tus_s=tus_s, finalize_s=fin_s
                        )
                    fin_err = f"Finalize {r.status}: {body[:300]}"
                    print(f"[{index:04d}] {_ts()} RETRY  attempt={attempt}  {fin_err}", file=sys.stderr, flush=True)
                    # Only retry on transient errors
                    if r.status not in (503, 429, 500, 502, 504):
                        break
            except Exception as e:
                fin_err = f"Finalize exception: {e}"
                print(f"[{index:04d}] {_ts()} RETRY  attempt={attempt}  {fin_err}", file=sys.stderr, flush=True)

        return UploadResult(
            index, False, time.perf_counter() - t0,
            tus_s=tus_s, finalize_s=fin_s, error=fin_err
        )


# ---------------------------------------------------------------------------
# Runner
# ---------------------------------------------------------------------------

async def run(
    base_url: str,
    token: str,
    concurrency: int,
    count: int,
    size_kb: int,
    tus_only: bool,
    ssl_verify: bool,
    real_file: Optional[str] = None,
    timeout_s: int = 130,
    log_dir: str = "load_test_runs",
    stagger: float = 0.0,
    chunk_size: int = 5 * 1024 * 1024,
) -> None:
    if real_file:
        file_data = load_real_file(real_file)
        file_label = f"{real_file} ({len(file_data):,} bytes, {len(file_data)/1024:.1f} KB) [REAL FILE]"
    else:
        file_data = make_fake_video(size_kb * 1024)
        file_label = f"{size_kb} KB synthetic (ftyp header + random bytes)"

    print(f"\nFile      : {file_label}")
    print(f"Concurrency: {concurrency}   Total uploads: {count}")
    chunk_mb = chunk_size // (1024 * 1024)
    print(f"TUS-only  : {tus_only}   SSL verify: {ssl_verify}   Stagger: {stagger}s   Chunk: {chunk_mb} MB")
    print(f"Endpoint  : {base_url}")
    print("─" * 60)

    sem = asyncio.Semaphore(concurrency)
    timeout = aiohttp.ClientTimeout(total=timeout_s)
    connector = aiohttp.TCPConnector(
        limit=concurrency + 10,
        ssl=None if ssl_verify else False,
    )

    async with aiohttp.ClientSession(connector=connector, timeout=timeout) as session:
        t_wall = time.perf_counter()
        tasks = [
            do_upload(session, base_url.rstrip("/"), token, i,
                      file_data, sem, tus_only, stagger=stagger,
                      chunk_size=chunk_size)
            for i in range(count)
        ]
        results: list[UploadResult] = await asyncio.gather(*tasks)
        wall = time.perf_counter() - t_wall

    ok   = [r for r in results if r.success]
    fail = [r for r in results if not r.success]

    def pct(lst: list[float], p: int) -> float:
        if not lst:
            return 0.0
        lst = sorted(lst)
        idx = max(0, min(int(len(lst) * p / 100), len(lst) - 1))
        return lst[idx]

    # ── Results: printed to stdout and saved to a timestamped log file ────
    _lines: list[str] = []
    def _p(msg: str = "") -> None:
        print(msg)
        _lines.append(msg)

    ts_now = datetime.datetime.now().strftime("%Y-%m-%dT%H:%M:%S")
    _p(f"\n{'='*60}")
    _p(f"  Run      : {base_url}  concurrency={concurrency}  count={count}")
    _p(f"  File     : {file_label}")
    _p(f"  Timestamp: {ts_now}  timeout={timeout_s}s  tus_only={tus_only}")
    _p(f"{'='*60}")
    _p(f"  Success : {len(ok)}/{count}   Failed: {len(fail)}")
    _p(f"  Wall time: {wall:.1f}s   "
       f"Throughput: {len(ok)/wall:.2f} ok-uploads/s")

    if ok:
        totals   = [r.total_s    for r in ok]
        tus_vals = [r.tus_s      for r in ok]
        fin_vals = [r.finalize_s for r in ok]
        _p(f"\n  End-to-end latency (successful):")
        _p(f"    p50={pct(totals,50):.2f}s  "
           f"p90={pct(totals,90):.2f}s  "
           f"p99={pct(totals,99):.2f}s  "
           f"max={max(totals):.2f}s")
        _p(f"  TUS phase:")
        _p(f"    p50={pct(tus_vals,50):.2f}s  "
           f"p90={pct(tus_vals,90):.2f}s  "
           f"max={max(tus_vals):.2f}s")
        if not tus_only:
            _p(f"  Finalize phase (PHP+MySQL):")
            _p(f"    p50={pct(fin_vals,50):.2f}s  "
               f"p90={pct(fin_vals,90):.2f}s  "
               f"max={max(fin_vals):.2f}s")

    if fail:
        _p(f"\n  Failure breakdown ({len(fail)} total):")
        err_counts = Counter(r.error or "unknown" for r in fail)
        for err, cnt in err_counts.most_common(8):
            _p(f"    [{cnt:3d}x] {err[:110]}")

    _p(f"{'='*60}\n")

    log_ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    log_path = Path(log_dir) / f"loadtest_{log_ts}_c{concurrency}.txt"
    log_path.parent.mkdir(parents=True, exist_ok=True)
    log_path.write_text("\n".join(_lines) + "\n")
    print(f"  Log saved → {log_path}")


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main() -> None:
    p = argparse.ArgumentParser(
        description="GigHive guest upload pipeline load tester",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Ramp from 5 to 20 concurrent (full pipeline, synthetic file)
  python3 load_test_guest_uploads.py --url https://dev.example.com --token abc123 --no-ssl-verify --concurrency 5 --count 20
  python3 load_test_guest_uploads.py --url https://dev.example.com --token abc123 --no-ssl-verify --concurrency 20 --count 40

  # TUS-only (skip finalize/DB) to isolate tusd + disk I/O
  python3 load_test_guest_uploads.py --url https://dev.example.com --token abc123 --no-ssl-verify \\
      --concurrency 20 --count 40 --tus-only

  # Real file — exercises ffprobe, ffmpeg, sha256 hash, full file copy (most realistic)
  python3 load_test_guest_uploads.py --url https://dev.example.com --token abc123 --no-ssl-verify \\
      --real-file /tmp/test_clip.mp4 --concurrency 5 --count 10

  # Generate a suitable real test file (requires ffmpeg locally):
  # ffmpeg -f lavfi -i color=c=black:s=640x480:r=24 -f lavfi -i anullsrc \\
  #        -t 5 -c:v libx264 -c:a aac -shortest /tmp/test_clip.mp4
""",
    )
    p.add_argument("--url",           required=True,        help="Server base URL, e.g. https://dev.example.com")
    p.add_argument("--token",         required=True,        help="Active QR upload token (?token= value from the QR URL)")
    p.add_argument("--concurrency",   type=int, default=10, help="Max simultaneous uploads (default: 10)")
    p.add_argument("--count",         type=int, default=20, help="Total uploads to run (default: 20)")
    p.add_argument("--size-kb",       type=int, default=512, help="Synthetic file size in KB (default: 512); ignored if --real-file is set")
    p.add_argument("--real-file",     default=None,         help="Path to a real media file (MP4 etc.); exercises ffprobe/ffmpeg on the server")
    p.add_argument("--tus-only",      action="store_true",  help="Skip finalize; only test TUS + disk write")
    p.add_argument("--no-ssl-verify", action="store_true",  help="Disable SSL certificate verification (needed for local self-signed cert)")
    p.add_argument("--timeout",       type=int, default=130, help="Per-request timeout in seconds; 130 covers CF's 100s upstream limit plus headroom (default: 130)")
    p.add_argument("--stagger",       type=float, default=0.0,  help="Seconds to wait between launching each upload (upload N waits N*stagger before starting; default: 0)")
    p.add_argument("--chunk-size-mb", type=int,   default=5,    help="TUS PATCH chunk size in MB (default: 5 — matches iOS TUSKit default; web forms use 8 MB via TUS_CLIENT_CHUNK_SIZE_BYTES)")
    p.add_argument("--log-dir",       default="load_test_runs", help="Directory to write per-run result logs (default: load_test_runs/)")
    args = p.parse_args()

    if args.real_file and not os.path.isfile(args.real_file):
        sys.exit(f"--real-file not found: {args.real_file}")

    asyncio.run(run(
        base_url=args.url,
        token=args.token,
        concurrency=args.concurrency,
        count=args.count,
        size_kb=args.size_kb,
        tus_only=args.tus_only,
        ssl_verify=not args.no_ssl_verify,
        real_file=args.real_file,
        timeout_s=args.timeout,
        log_dir=args.log_dir,
        stagger=args.stagger,
        chunk_size=args.chunk_size_mb * 1024 * 1024,
    ))


if __name__ == "__main__":
    main()
