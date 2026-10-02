# TUS post_build_checks logging (`tus_checks_no_log`)

## Summary
The post-build checks include a TUS upload/finalize/delete smoke test.

Those tasks can include sensitive information in their inputs/outputs:
- Basic Auth credentials (uploader/admin)
- `delete_token` capability tokens
- Endpoint responses that may contain internal details

To prevent secrets from appearing in normal `ansible-playbook` output, these TUS-related tasks use `no_log: "{{ tus_checks_no_log }}"`.

## Default behavior
In `ansible/roles/post_build_checks/tasks/main.yml`, `tus_checks_no_log` is set automatically based on Ansible verbosity:

- Normal runs (no `-v`/`-vv`):
  - `tus_checks_no_log = true`
  - TUS task outputs are censored.

- Debug runs with `-vv` or higher:
  - `tus_checks_no_log = false`
  - TUS task outputs are shown to help diagnose failures.

This is implemented as:
- `tus_checks_no_log: "{{ tus_checks_no_log | default((ansible_verbosity | int) < 2) }}"`

## How to debug a failing TUS post-build check
- Re-run the playbook with extra verbosity:
  - `ansible-playbook -vv ...`

This will automatically disable `no_log` for the TUS checks so you can see the HTTP status codes and JSON responses.

## Override (optional)
You can always force either behavior explicitly:
- Force censoring:
  - `-e tus_checks_no_log=true`
- Force uncensored debugging:
  - `-e tus_checks_no_log=false`
