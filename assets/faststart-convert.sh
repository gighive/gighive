#!/usr/bin/env bash
# convert-faststart.sh
# Rewrap MP4s listed in nonfaststart_files.txt to "faststart" (moov before mdat)

set -euo pipefail
LIST="${1:-nonfaststart_files.txt}"

command -v ffmpeg  >/dev/null || { echo "ERROR: ffmpeg not found"  >&2; exit 1; }
command -v ffprobe >/dev/null || { echo "ERROR: ffprobe not found" >&2; exit 1; }
command -v timeout >/dev/null || { echo "ERROR: timeout not found (apt install coreutils)" >&2; exit 1; }

if [[ ! -s "$LIST" ]]; then
  echo "ERROR: list file not found or empty: $LIST" >&2
  exit 2
fi

# Basic faststart check: first top-level box among {moov, mdat}
is_faststart() {
  local f="$1" line
  line="$(timeout 10s ffprobe -v trace "$f" 2>&1 \
    | grep -E -m1 "type:'(moov|mdat)'.*parent:'root'|parent:'root'.*type:'(moov|mdat)'" || true)"
  [[ -n "$line" && "$line" == *"type:'moov'"* ]]
}

cleanup() { [[ -n "${tmp:-}" && -e "$tmp" ]] && rm -f -- "$tmp"; }
trap cleanup EXIT

mapfile -t FILES < "$LIST"
total=${#FILES[@]}
fixed=0; skipped=0; failed=0
i=0

for f in "${FILES[@]}"; do
  i=$((i+1))
  [[ -z "${f// }" || "$f" == \#* ]] && continue
  if [[ ! -f "$f" ]]; then
    printf '[%5d/%-5d] MISSING        %s\n' "$i" "$total" "$f"
    failed=$((failed+1)); continue
  fi

  printf '[%5d/%-5d] Checking       %s\n' "$i" "$total" "$f"
  if is_faststart "$f"; then
    echo "  -> already FASTSTART, skipping"
    skipped=$((skipped+1)); continue
  fi

  dir="$(dirname -- "$f")"
  base="$(basename -- "$f")"
  # ensure .mp4 in temp name so ffmpeg picks mp4 muxer; also pass -f mp4 explicitly
  tmp="$dir/.${base}.faststart.mp4.tmp"
  ts="$dir/.${base}.ts"

  touch -r "$f" "$ts" || true
  echo "  -> converting..."
  if ffmpeg -v error -y -i "$f" -map 0 -c copy -movflags +faststart -f mp4 "$tmp"; then
    if is_faststart "$tmp"; then
      touch -r "$ts" "$tmp" || true
      mv -f -- "$tmp" "$f"
      rm -f -- "$ts"
      echo "  -> ✔ converted & replaced"
      fixed=$((fixed+1))
    else
      echo "  -> ✖ verification failed (moov not first); keeping original"
      rm -f -- "$tmp" "$ts"
      failed=$((failed+1))
    fi
  else
    echo "  -> ✖ ffmpeg failed; keeping original"
    rm -f -- "$tmp" "$ts"
    failed=$((failed+1))
  fi
done

trap - EXIT
cleanup

echo
echo "===== Convert Faststart Summary ====="
echo "List file:        $LIST"
echo "Total listed:     $total"
echo "Converted OK:     $fixed"
echo "Skipped (OK):     $skipped"
echo "Failed:           $failed"
echo "====================================="
