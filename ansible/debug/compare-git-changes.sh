#!/usr/bin/env bash
#
# compare-changes.sh
#
# Part I: Quickly compare what changed between a known-good commit and your current state.
# Shows high-signal diffs (word-level for identifiers) and file-level changes.
#
# Usage:
#   ./compare-changes.sh <GOOD_COMMIT> [BAD_REF]
#
# Examples:
#   ./compare-changes.sh 01f5f60038af32a29b58b9765ef60e7f6a35047d
#   ./compare-changes.sh 01f5f60038af32a29b58b9765ef60e7f6a35047d HEAD
#
# Notes:
# - GOOD_COMMIT is required (your last known working commit).
# - BAD_REF defaults to HEAD.
# - If you rebased, `git range-diff` helps compare patch series.

set -euo pipefail

if ! command -v git >/dev/null 2>&1; then
  echo "❌ git not found in PATH" >&2
  exit 1
fi

if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Usage: $0 <GOOD_COMMIT> [BAD_REF]" >&2
  exit 2
fi

GOOD_COMMIT="$1"
BAD_REF="${2:-HEAD}"

# Ensure we're in a git repo
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "❌ Not inside a git repository" >&2
  exit 3
fi

# Resolve to full SHAs (and validate they exist)
if ! GOOD_SHA=$(git rev-parse --verify "$GOOD_COMMIT" 2>/dev/null); then
  echo "❌ Cannot resolve GOOD_COMMIT: $GOOD_COMMIT" >&2
  exit 4
fi
if ! BAD_SHA=$(git rev-parse --verify "$BAD_REF" 2>/dev/null); then
  echo "❌ Cannot resolve BAD_REF: $BAD_REF" >&2
  exit 5
fi

echo "🔎 Comparing:"
echo "  GOOD: $GOOD_COMMIT -> $GOOD_SHA"
echo "  BAD : $BAD_REF     -> $BAD_SHA"
echo

# Basic sanity: list commits between the two (reverse so oldest first)
echo "===== Commit log (GOOD..BAD) ====="
git --no-pager log --oneline --decorate --reverse "${GOOD_SHA}..${BAD_SHA}" || true
echo

# File-level change summary (what changed where)
echo "===== Name-status (GOOD..BAD) ====="
git --no-pager diff --name-status "${GOOD_SHA}..${BAD_SHA}" || true
echo

# Stats (insertions/deletions per file)
echo "===== Diffstat (GOOD..BAD) ====="
git --no-pager diff --stat "${GOOD_SHA}..${BAD_SHA}" || true
echo

# Identifier-friendly, word-level diff (great for variable renames)
echo "===== Word-level diff (identifier-friendly) ====="
git --no-pager diff \
  --word-diff \
  --word-diff-regex='[A-Za-z0-9_\.]+' \
  "${GOOD_SHA}..${BAD_SHA}" || true
echo

# If a rebase happened, show a patch-series comparison
echo "===== range-diff (GOOD...BAD) ====="
git --no-pager range-diff "${GOOD_SHA}...${BAD_SHA}" || true
echo

# Optional: show only file paths that changed under ansible/ (uncomment if useful)
# echo "===== Changed files under ansible/ ====="
# git --no-pager diff --name-only "${GOOD_SHA}..${BAD_SHA}" -- ansible/ || true
# echo

echo "✅ Done."
