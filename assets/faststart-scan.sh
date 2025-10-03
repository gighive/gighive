#!/usr/bin/env bash
# Minimal, chatty faststart checker using the same pattern you ran manually.
# Usage: ./faststart-inplace.sh [DIR]

set -euo pipefail
DIR="${1:-.}"

command -v ffprobe >/dev/null || { echo "ERROR: ffprobe not found" >&2; exit 1; }
command -v timeout >/dev/null || { echo "ERROR: timeout not found (apt install coreutils)" >&2; exit 1; }

FAST="faststart_files.txt"
NONFAST="nonfaststart_files.txt"
UNK="unknown_files.txt"
: >"$FAST"; : >"$NONFAST"; : >"$UNK"

# discover files
mapfile -d '' FILES < <(find "$DIR" -type f \( -iname '*.mp4' -o -iname '*.m4v' \) -print0)

total=${#FILES[@]}
fast=0; nonfast=0; unknown=0

echo "Starting faststart scan in: $DIR"
echo "Found $total files"
echo "-----------------------------------------"

i=0
for f in "${FILES[@]}"; do
  i=$((i+1))
  printf '[%5d/%-5d] %s\n' "$i" "$total" "$f"

  # Grab the first occurrence of moov/mdat at the root level.
  # We keep the grep pattern flexible (handles either field order).
  line="$(timeout 10s ffprobe -v trace "$f" 2>&1 \
    | grep -E -m1 "type:'(moov|mdat)'.*parent:'root'|parent:'root'.*type:'(moov|mdat)'" || true)"

  if [[ -z "$line" ]]; then
    echo "  -> UNKNOWN (no moov/mdat line found)"
    echo "$f" >> "$UNK"
    unknown=$((unknown+1))
    continue
  fi

  if [[ "$line" == *"type:'moov'"* ]]; then
    echo "  -> FASTSTART"
    echo "$f" >> "$FAST"
    fast=$((fast+1))
  elif [[ "$line" == *"type:'mdat'"* ]]; then
    echo "  -> NON-FASTSTART"
    echo "$f" >> "$NONFAST"
    nonfast=$((nonfast+1))
  else
    echo "  -> UNKNOWN (unmatched first box: $line)"
    echo "$f" >> "$UNK"
    unknown=$((unknown+1))
  fi
done

echo
echo "===== MP4 Faststart Report ====="
echo "Directory:       $DIR"
echo "Total files:     $total"
pf=$(( total>0 ? fast*100/total : 0 ))
pn=$(( total>0 ? nonfast*100/total : 0 ))
echo "FASTSTART:       $fast (${pf}%)        -> $FAST"
echo "NON-FASTSTART:   $nonfast (${pn}%)     -> $NONFAST"
echo "UNKNOWN:         $unknown              -> $UNK"
echo "================================"
