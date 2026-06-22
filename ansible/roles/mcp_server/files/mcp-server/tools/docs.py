"""
tools/docs.py — Documentation search and retrieval tools.

Tools:
  list_docs   — list doc files, optionally filtered by category prefix
  search_docs — full-text search across doc files, returns snippets
  read_doc    — return full content of a single doc file
"""

from __future__ import annotations

import os
import re

import config

_DOCS_EXTENSIONS  = ('.md', '.txt', '.mermaidchart')
_MAX_READ_CHARS   = 100_000
_MAX_FILE_SEARCH  = 200_000
_CONTEXT_LINES    = 2


def _category_from_filename(filename: str) -> str:
    """Derive a category label from the filename prefix convention."""
    for pfx in (
        'feature_completed_', 'feature_',
        'problem_', 'refactored_', 'refactor_',
        'process_', 'guide_', 'security_',
        'telemetry_', 'admin_',
    ):
        if filename.lower().startswith(pfx):
            return pfx.rstrip('_')
    return 'other'


def _iter_docs(docs_path: str, category: str | None):
    """Yield (filename, full_path) recursively for each searchable file.

    Recurses into subdirectories, skipping any whose name starts with '.'.
    Skips files whose name starts with '.' or '_'.
    Applies category as a startswith filter on the filename only (not the path).
    Files are yielded in sorted order within each directory.
    """
    if not os.path.isdir(docs_path):
        return
    for dirpath, dirnames, filenames in os.walk(docs_path):
        dirnames[:] = sorted(d for d in dirnames if not d.startswith('.'))
        for entry in sorted(filenames):
            if not any(entry.endswith(ext) for ext in _DOCS_EXTENSIONS):
                continue
            if entry.startswith('.') or entry.startswith('_'):
                continue
            if category is not None and not entry.lower().startswith(category.lower()):
                continue
            yield entry, os.path.join(dirpath, entry)


def register(mcp) -> None:

    @mcp.tool()
    def list_docs(category: str | None = None) -> list[dict]:
        """List documentation files, optionally filtered by category prefix.

        category examples: "feature", "feature_completed", "problem", "refactor",
        "refactored", "process", "guide", "security", "telemetry", "admin".
        Searches recursively including docs/protected/ and docs/codingChanges/.
        Returns filename, category label, and size_bytes for each file.
        """
        docs_path = config.DOCS_PATH
        return [
            {
                'filename':   filename,
                'category':   _category_from_filename(filename),
                'size_bytes': os.path.getsize(full_path),
            }
            for filename, full_path in _iter_docs(docs_path, category)
        ]

    @mcp.tool()
    def search_docs(
        query: str,
        category: str | None = None,
        filename_only: bool = False,
        limit: int = 20,
    ) -> list[dict]:
        """Full-text search across documentation files.

        Searches filenames and (unless filename_only=True) file contents.
        Searches recursively including docs/protected/ and docs/codingChanges/.
        Files larger than 200 KB are skipped for content search (still appear as
        filename matches if the name matches). A file that produces a filename match
        is not separately scanned for content matches in the same call.

        Returns up to limit matches with filename, category, line_number (null for
        filename matches), and a snippet showing the matching line ±2 lines of context.

        category examples: "feature", "problem", "refactor", "process", "guide".
        re.escape is applied to query — regex patterns are not supported.
        """
        pattern = re.compile(re.escape(query), re.IGNORECASE)
        matches: list[dict] = []
        seen_filename_match: set[str] = set()

        for filename, full_path in _iter_docs(config.DOCS_PATH, category):
            if len(matches) >= limit:
                break

            if pattern.search(filename):
                matches.append({
                    'filename':    filename,
                    'category':    _category_from_filename(filename),
                    'line_number': None,
                    'snippet':     f'[filename match] {filename}',
                })
                seen_filename_match.add(filename)
                if len(matches) >= limit:
                    break

            if filename_only or filename in seen_filename_match:
                continue

            if os.path.getsize(full_path) > _MAX_FILE_SEARCH:
                continue

            try:
                with open(full_path, 'r', encoding='utf-8', errors='replace') as fh:
                    lines = fh.readlines()
            except OSError:
                continue

            for i, line in enumerate(lines):
                if len(matches) >= limit:
                    break
                if pattern.search(line):
                    start   = max(0, i - _CONTEXT_LINES)
                    end     = min(len(lines), i + _CONTEXT_LINES + 1)
                    snippet = ''.join(lines[start:end]).rstrip()
                    matches.append({
                        'filename':    filename,
                        'category':    _category_from_filename(filename),
                        'line_number': i + 1,
                        'snippet':     snippet,
                    })

        return matches

    @mcp.tool()
    def read_doc(filename: str, max_chars: int = 50_000) -> dict:
        """Return the full content of a named documentation file.

        filename is the bare filename, e.g. "feature_completed_ai_video_tagger.md".
        os.path.basename() is applied — path traversal is not possible.
        max_chars caps the response size (default 50000; hard cap 100000).
        Increase max_chars toward 100000 for large architecture docs.

        Returns {filename, content, char_count, truncated} or {error}.
        """
        safe_name = os.path.basename(filename)
        full_path = os.path.join(config.DOCS_PATH, safe_name)

        if not os.path.isfile(full_path):
            return {'error': f"Doc not found: {safe_name!r}"}

        cap = min(max_chars, _MAX_READ_CHARS)
        try:
            with open(full_path, 'r', encoding='utf-8', errors='replace') as fh:
                content = fh.read(cap + 1)
        except OSError as exc:
            return {'error': str(exc)}

        truncated = len(content) > cap
        if truncated:
            content = content[:cap]

        return {
            'filename':   safe_name,
            'content':    content,
            'char_count': len(content),
            'truncated':  truncated,
        }
