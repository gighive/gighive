# SonarQube Recommendations — Applied Fixes

Quick reference of SonarQube security rule violations encountered in this project and the fix pattern used to resolve each.

---

## `phpsecurity:S2083` — Path constructed from user-controlled data

**Rule:** I/O function calls should not be vulnerable to path injection attacks.

| Date | File(s) | Fix applied | Detail doc |
|------|---------|-------------|------------|
| 2026-06-22 | `export_media_download.php`, `export_media_status.php`, `import_media_zip.php`, `import_media_zip_status.php` | Wrap user-supplied `$jobId` / `$prepareToken` in `basename()` before concatenating into a filesystem path — `basename()` is a confirmed SonarQube PHP path sanitizer. | *(this doc)* |
| 2026 (earlier) | `import_manifest_status.php` | Replace direct `$jobId` path concatenation with a filesystem allowlist (`glob` + exact `basename()` match) plus `realpath()` containment check, so the path used is derived from the filesystem rather than directly from user input. | `docs/problem_security_sonarqube_phpsecurityS2083.md` |

### Key takeaway
`basename()` is the simplest confirmed SonarQube PHP sanitizer for S2083.  For endpoints where the job directory must be verified to exist and be contained within a trusted root, the allowlist + `realpath()` pattern is more thorough.
