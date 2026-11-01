# Refactor Database UTF-8 Enforcement — If Legacy Cleanup Is Needed

Copied from `docs/refactor_database_utf8_enforcement.md`.

### Phase 3: Legacy data cleanup
Run a controlled cleanup of already-persisted non-UTF-8 or mis-encoded text.
- scan current text-bearing tables for invalid or suspicious byte sequences
- repair cp1252-style punctuation where appropriate
- verify that validation and export tooling can safely read all rows afterward

### Phase 4: Test expansion
Add test fixtures and assertions so every ingestion path is verified against the same UTF-8 and canonicalization expectations, following the split defined in the Testing strategy section.

Files to change:
- `ansible/roles/upload_tests/tasks/assert_db_invariants.yml` — add text-content assertions alongside the existing row-count assertions; verify stored text fields contain valid UTF-8 and that canonical comparison behavior is stable (see Finding 9)
- `ansible/roles/validate_app/tasks/main.yml` — add new tasks per the testing strategy section of this doc
