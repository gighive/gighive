# Feature: MCP Server — Documentation Search and Retrieval Tools

**Status:** Planned — not yet implemented  
**Date:** 2026-06-22  
**Parent feature:** `docs/feature_completed_mcp_server.md`

---

## Overview

The GigHive MCP server currently exposes fourteen tools, all of which are database-oriented:
the AI pipeline queue, media library corpus, upload job state, host environment inspection,
and schema inspection. The `docs/` directory — approximately 200 markdown files covering
feature rationales, architectural diagrams, RCAs, refactor decisions, and process guides —
is completely invisible to the MCP server.

The primary consumer of this feature is an AI assistant (Windsurf/Cascade, Claude Desktop)
helping the GigHive administrator answer questions about how the system is built: which files
implement a feature, why a design decision was made, what the async worker pattern looks like,
how streaming is architected, etc. GigHive has a small database footprint but a robust and
well-documented feature set; the documentation is the authoritative source of architectural
knowledge that is otherwise unavailable to the AI at query time.

This feature adds three new tools to a new `tools/docs.py` module:

| Tool | Purpose |
|---|---|
| `list_docs` | List doc files, optionally filtered by category prefix |
| `search_docs` | Full-text search across doc files, returns filename + snippet |
| `read_doc` | Return full content of a named doc file |

---

## Use Cases

1. **Feature archaeology** — "How does the async worker + polling pattern work?" →
   `search_docs("async worker polling")` → finds `patterns_async_worker.md`,
   `feature_completed_import_media_from_zip.md` → `read_doc()` returns full rationale,
   pattern boilerplate, and JSON schema.

2. **File list for a feature** — "Which files implement the AI video tagger?" →
   `read_doc("feature_completed_ai_video_tagger.md")` → returns the full feature doc
   including the files-modified table and design decisions.

3. **RCA lookup** — "Why do we bypass Cloudflare cache for audio/video?" →
   `search_docs("cloudflare cache audio video")` → surfaces
   `problem_cloudflare_caching_media.md` and `problem_cloudflare_cached_error_messages.md`.

4. **Architecture diagrams** — "How does the autoinstall boot process work?" →
   `search_docs("autoinstall boot")` → finds `autoinstall.mermaidchart` (Mermaid diagram
   of the Ubuntu autoinstall/cloud-init boot sequence).

5. **Category browsing** — "What refactor docs exist?" →
   `list_docs(category="refactor")` → returns all `refactor_*` and `refactored_*` docs
   with sizes.

---

## Docs Directory Structure

The `docs/` directory is at `{{ gighive_home }}/docs` on each VM. Files follow a naming
convention where the prefix determines the category:

| Prefix | Category label | Example |
|---|---|---|
| `feature_completed_` | `feature_completed` | `feature_completed_ai_video_tagger.md` |
| `feature_` | `feature` | `feature_ai_intelligence_platform.md` |
| `problem_` | `problem` | `problem_cloudflare_caching_media.md` |
| `refactored_` | `refactored` | `refactored_upload_jobs_from_json_to_db.md` |
| `refactor_` | `refactor` | `refactor_security.md` |
| `process_` | `process` | `process_mysql_init.md` |
| `guide_` | `guide` | `guide_ai_worker_tagging.md` |
| `security_` | `security` | `security_auth_jwt_token_migration.md` |
| `telemetry_` | `telemetry` | `telemetry_highlevel.md` |
| `admin_` | `admin` | `admin_export_media.md` |
| *(other)* | `other` | `DEPENDENCIES.md`, `README.md`, etc. |

The directory also contains two subdirectories with searchable docs:

- `docs/protected/` — 5 files including `STREAMING_ARCHITECTURE_20251008.md` (28 KB),
  `tusimplementation.md`, `tusimplementationFuture.md`, `CLOUDFLARE_UPLOAD_LIMIT.md`
- `docs/codingChanges/` — 6 files: security auth change notes, feature change notes

Both subdirectories are included in the recursive search. Files beginning with `.` or `_`
are excluded (macOS resource forks, swap files, `_config.yml`).

The directory also contains two `.mermaidchart` files — Mermaid architectural diagrams —
which are included in the search:

- `autoinstall.mermaidchart` — Ubuntu autoinstall/cloud-init boot sequence diagram
- `database_schema.mermaidchart` — database ER diagram

---

## Design Decisions and Bug-Fix Rationale

Four issues were identified during design review before implementation. Each is addressed
below with the rationale for the fix chosen.

### Issue 1 — Subdirectory blindness (Bug — silent data loss)

**Problem:** The original proposal used `os.listdir()` — flat scan only. The two most
architecturally significant subdirectories (`docs/protected/`, `docs/codingChanges/`) and
all their files would be silently excluded from all three tools.

**Fix:** Replace `os.listdir()` with `os.walk()` (recursive). Subdirectory names beginning
with `.` are excluded from recursion (prevents `.git` etc.). The `category` prefix filter
applies to the entry filename only, not to the subdirectory path — a file at
`docs/protected/STREAMING_ARCHITECTURE_20251008.md` is labelled category `other` and is
included in an unfiltered search or a `category=None` `list_docs` call.

### Issue 2 — `.mermaidchart` files excluded (Bug — missing architectural content)

**Problem:** `_DOCS_EXTENSIONS` was `('.md', '.txt')`. The two Mermaid diagram files
(`autoinstall.mermaidchart`, `database_schema.mermaidchart`) would be silently excluded.
Mermaid diagrams were explicitly named as part of the use case ("architectural diagrams of
how they work").

**Fix:** Add `'.mermaidchart'` to `_DOCS_EXTENSIONS`.

### Issue 3 — `musiclibrary.txt` (792 KB) scanned on every content search (Performance)

**Problem:** `docs/musiclibrary.txt` is a 792 KB raw music catalogue data dump, not
documentation. It would be content-searched on every `search_docs` call, generating noisy
matches and wasting time.

**Fix:** Add a per-file size guard `_MAX_FILE_SEARCH = 200_000` bytes. Files above this
threshold are skipped for content search but still appear in filename-match results and
`list_docs` output. The 200 KB threshold passes all genuine docs (the largest is
`pr_librarianAsset_musicianEvent_completed_implementation.md` at ~87 KB) and excludes only
`musiclibrary.txt`.

### Issue 4 — Duplicate results for filename + content matches (Minor — noise)

**Problem:** When a filename matches the query AND the same file has content-line matches,
the same file would appear multiple times in results: once as `[filename match]` and again
for each matching line.

**Fix:** Track filenames that have already produced a filename-match result in a
`seen_filename_match` set. If a filename match was already appended, skip the content search
for that file entirely. An AI assistant querying for a specific document by name gets a
clean single result for the filename match; a content search (where the filename does not
match) still returns all content-line snippets from that file.

---

## Files

### New (1):

1. `ansible/roles/mcp_server/files/mcp-server/tools/docs.py` — three tools: `list_docs`,
   `search_docs`, `read_doc`; module-private helpers `_category_from_filename` and
   `_iter_docs`

### Modified (4):

2. `ansible/roles/mcp_server/files/mcp-server/server.py` — add `import tools.docs as
   docs_mod` and `docs_mod.register(mcp)`

3. `ansible/roles/mcp_server/templates/config.py.j2` — add `DOCS_PATH = "{{ mcp_docs_path }}"`

4. `ansible/inventories/group_vars/gighive2/gighive2.yml` — add
   `mcp_docs_path: "{{ gighive_home }}/docs"` under the MCP server section

5. `ansible/inventories/group_vars/gighive/gighive.yml` — same (covers lab + staging)

6. `ansible/inventories/group_vars/prod/prod.yml` — same

---

## Implementation Checklist

### 1. `tools/docs.py`

- [ ] Module docstring listing all three tools
- [ ] `_DOCS_EXTENSIONS = ('.md', '.txt', '.mermaidchart')`
- [ ] `_MAX_READ_CHARS = 100_000` — hard cap on `read_doc` response size
- [ ] `_MAX_FILE_SEARCH = 200_000` — per-file content-search size guard
- [ ] `_CONTEXT_LINES = 2` — lines of context above/below a content match
- [ ] `_category_from_filename(filename)` — prefix-ordered lookup; most specific first
  (`feature_completed_` before `feature_`); returns bare label (`rstrip('_')`)
- [ ] `_iter_docs(docs_path, category)` — `os.walk` recursive; skip `.`-prefixed dirs;
  skip `.`/`_`-prefixed filenames; apply `category` prefix filter on filename only; yield
  `(filename, full_path)` sorted within each directory
- [ ] `list_docs(category=None)` — return `[{filename, category, size_bytes}]`
- [ ] `search_docs(query, category=None, filename_only=False, limit=20)`:
  - [ ] Compile `re.compile(re.escape(query), re.IGNORECASE)` — no regex injection
  - [ ] Track `seen_filename_match: set[str]` — dedup filename vs content results
  - [ ] Filename match: append `{filename, category, line_number: None, snippet: "[filename match] {filename}"}`;
    add to `seen_filename_match`
  - [ ] Skip content search if `filename_only` or filename already in `seen_filename_match`
  - [ ] Skip content search if `os.path.getsize(full_path) > _MAX_FILE_SEARCH`
  - [ ] Content search: read lines; for each match append `{filename, category, line_number, snippet}`
    where snippet is `±_CONTEXT_LINES` lines stripped
  - [ ] Enforce `limit` at the top of the file loop and after each append
- [ ] `read_doc(filename, max_chars=50_000)`:
  - [ ] `os.path.basename(filename)` — path traversal guard
  - [ ] `os.path.isfile(full_path)` check → `{error: "Doc not found: ..."}`
  - [ ] `fh.read(cap + 1)` — read one extra byte to detect truncation
  - [ ] Return `{filename, content, char_count, truncated}` or `{error}`

### 2. `server.py`

- [ ] `import tools.docs as docs_mod` — after existing tool imports
- [ ] `docs_mod.register(mcp)` — after `schema_readonly.register(mcp)`

### 3. `config.py.j2`

- [ ] `DOCS_PATH  = "{{ mcp_docs_path }}"` — after `ENV_FILE` line

### 4–6. `group_vars` (three files)

- [ ] `mcp_docs_path: "{{ gighive_home }}/docs"` — after `mcp_env_file` line in each file

---

## No New Dependencies

`os`, `re`, and `config` are already available in the MCP server environment. No `pip`
changes to `requirements.txt` are needed.

---

## Tool Reference

### `list_docs`

```
list_docs(category=None) -> list[dict]
```

Returns `[{filename, category, size_bytes}]` for all searchable files, sorted
alphabetically within each directory. Subdirectory files are included and indistinguishable
from top-level files (filename only, no path prefix).

`category` is a simple `startswith` prefix on the filename. Pass `"feature"` to get both
`feature_*` and `feature_completed_*` files. Pass `"feature_completed"` to restrict to
completed feature docs only.

---

### `search_docs`

```
search_docs(query, category=None, filename_only=False, limit=20) -> list[dict]
```

Returns `[{filename, category, line_number, snippet}]`. Filename matches set `line_number`
to `null` and prefix the snippet with `[filename match]`. Content matches set `line_number`
to the 1-indexed line number and set `snippet` to the matching line ± 2 lines of context,
stripped of trailing whitespace.

`re.escape` is applied to `query` — the caller cannot inject regex patterns; this is
intentional for a documentation search tool.

Files above `_MAX_FILE_SEARCH` (200 KB) are excluded from content search. They still
appear as filename matches if the query matches their name.

---

### `read_doc`

```
read_doc(filename, max_chars=50000) -> dict
```

`filename` is the bare filename only (e.g. `feature_completed_ai_video_tagger.md`).
`os.path.basename()` strips any directory components — path traversal is not possible.

The hard cap `_MAX_READ_CHARS = 100_000` cannot be exceeded regardless of `max_chars`.
`truncated: true` is returned when the file was cut. Increase `max_chars` toward `100000`
for large architecture docs. The largest docs file is
`pr_librarianAsset_musicianEvent_completed_implementation.md` at ~87 KB; the default
`max_chars=50000` covers the majority of files without truncation.

Returns `{error}` if the file does not exist or cannot be read.
