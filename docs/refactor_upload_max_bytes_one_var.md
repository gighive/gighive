{% raw %}
# Refactor: Single `upload_max_bytes` Source of Truth (incl. Dockerfile)

## Purpose

Eliminate `upload_max_mb` as a separate group_vars variable. After this refactor,
changing `upload_max_bytes` in one place propagates to **all** enforcement points:

| Enforcement point | Mechanism |
|---|---|
| ModSecurity body limits | `{{ upload_max_bytes }}` directly in `modsecurity.conf.j2` |
| `UploadValidator.php` runtime cap | `UPLOAD_MAX_BYTES={{ upload_max_bytes }}` in `.env.j2` |
| PHP `upload_max_filesize` / `post_max_size` | Derived in `Dockerfile.j2` from `upload_max_bytes` |

This works for **both** deployment paths:
- **Full Ansible build** (`site.yml`) — Ansible renders `Dockerfile.j2` → `{{ docker_dir }}/apache/Dockerfile` before `docker compose build`
- **One-shot-bundle (quickstart)** — `output_bundle.yml` renders `Dockerfile.j2` at bundle-creation time, baking the correct value into `apache/Dockerfile` in the tarball

---

## Why the current `replace`-task approach is insufficient

The interim fix added to `ansible/roles/docker/tasks/main.yml` uses
`ansible.builtin.replace` to patch `6390M` → `{{ upload_max_mb }}M` in the
Dockerfile at deploy time. This works for full Ansible runs but **does nothing for
one-shot-bundle installs**.

When a user installs via the quickstart bundle they extract a tarball and run
`install.sh` directly — no Ansible playbook runs on the target. The bundle
contains a static `apache/Dockerfile` copied verbatim from
`files/apache/Dockerfile`, with the MB limit hardcoded. Changing `upload_max_bytes`
in group_vars does not affect bundles already built, nor is any patching done at
install time.

Converting to `Dockerfile.j2` + rendering it during `output_bundle.yml` closes
this gap: the bundle tarball always contains a `Dockerfile` with the correct
value baked in at bundle-creation time.

---

## PHP ceiling derivation (no second variable needed)

PHP's `upload_max_filesize` and `post_max_size` must be set slightly **above**
`upload_max_bytes` so PHP never becomes the binding constraint before ModSecurity
or the app validator have a chance to enforce the limit.

The formula (4% headroom) reproduces the current production values exactly:

```jinja2
{{ (upload_max_bytes | int / 1048576 * 1.04) | round | int }}M
```

Verification:

| `upload_max_bytes` | → MB | × 1.04 | PHP ceiling |
|---|---|---|---|
| 6,442,450,944 (6 GiB) | 6,144 | 6,389.76 | **6,390M** ✅ (matches current Dockerfile) |
| 681,574,400 (650 MB test) | 650 | 676 | **676M** |
| 734,003,200 (700 MB test) | 700 | 728 | **728M** |
| 11,811,182,592 (11 GiB test) | 11,264 | 11,714.56 | **11,715M** |

---

## Files requiring changes (7 total)

| # | File | Change |
|---|---|---|
| 1 | `ansible/roles/docker/files/apache/Dockerfile` | Move → `templates/Dockerfile.j2`; replace `6390M` with Jinja2 derived expression |
| 2 | `ansible/roles/docker/tasks/main.yml` | Remove `replace` task; add `template` task to render `Dockerfile.j2` |
| 3 | `ansible/roles/one_shot_bundle/tasks/monitor.yml` | Add `Dockerfile.j2` → `apache/Dockerfile` mapping in 2 dest_file blocks |
| 4 | `ansible/roles/one_shot_bundle/tasks/output_bundle.yml` | Add same mapping in 2 dest_file blocks (Block 1 critical for `apache/` dir creation) |
| 5 | `ansible/inventories/group_vars/gighive/gighive.yml` | Remove `upload_max_mb`; remove explicit Dockerfile path from `one_shot_bundle_input_paths` |
| 6 | `ansible/inventories/group_vars/gighive2/gighive2.yml` | Same as #5 |
| 7 | `ansible/inventories/group_vars/prod/prod.yml` | Same as #5 |

---

### 1. `ansible/roles/docker/files/apache/Dockerfile`
**Action:** Rename/move → `ansible/roles/docker/templates/Dockerfile.j2`

Replace the two hardcoded `6390M` values on the `upload_max_filesize` and
`post_max_size` sed lines:

```dockerfile
# Before:
RUN sed -i 's/upload_max_filesize = .*/upload_max_filesize = 6390M/' ... && \
    sed -i 's/post_max_size = .*/post_max_size = 6390M/' ...

# After:
RUN sed -i 's/upload_max_filesize = .*/upload_max_filesize = {{ (upload_max_bytes | int / 1048576 * 1.04) | round | int }}M/' ... && \
    sed -i 's/post_max_size = .*/post_max_size = {{ (upload_max_bytes | int / 1048576 * 1.04) | round | int }}M/' ...
```

---

### 2. `ansible/roles/docker/tasks/main.yml`
**Action:** Remove the interim `replace` task; add a `template` task in its place.

Remove:
```yaml
- name: Patch PHP upload limits in Dockerfile from Ansible variable
  ansible.builtin.replace:
    path: "{{ docker_dir }}/apache/Dockerfile"
    regexp: '6390M'
    replace: "{{ upload_max_mb }}M"
  tags: docker, compose
```

Add (same position, just before "Render Docker Compose file"). Use the same short-form module name and `become` settings as the adjacent `docker-compose.yml.j2` render task:
```yaml
- name: Render Dockerfile from Jinja2 template
  template:
    src: Dockerfile.j2
    dest: "{{ docker_dir }}/apache/Dockerfile"
    mode: '0644'
  become: true
  become_user: "{{ ansible_user }}"
  tags: docker, compose
```

---

### 3. `ansible/roles/one_shot_bundle/tasks/monitor.yml`
**Action:** Add `Dockerfile.j2` → `apache/Dockerfile` mapping in **2 separate
`_one_shot_bundle_dest_file` blocks**.

**Block 1** (find-results loop, ~line 57): insert after the `dbDump.sh.j2` entry,
before the `_one_shot_bundle_files_prefix` catch-all:

```jinja2
      {% elif _p == (_one_shot_bundle_templates_prefix ~ 'Dockerfile.j2') %}
      apache/Dockerfile
      {% elif _p.startswith(_one_shot_bundle_files_prefix) %}
```

**Block 2** (individual stat loop, ~line 117): insert after the `crs-setup.conf.j2`
entry, before the `_one_shot_bundle_files_prefix` catch-all:

```jinja2
      {% elif _p == (_one_shot_bundle_templates_prefix ~ 'Dockerfile.j2') %}
      apache/Dockerfile
      {% elif _p.startswith(_one_shot_bundle_files_prefix) %}
```

---

### 4. `ansible/roles/one_shot_bundle/tasks/output_bundle.yml`
**Action:** Add the same `Dockerfile.j2` → `apache/Dockerfile` mapping in **2
separate `_one_shot_bundle_dest_file` blocks**.

**Block 1** ("Ensure destination directories exist" task, header at line 34,
dest_file block starting at line 46): insert after the `dbDump.sh.j2` entry,
before the `_one_shot_bundle_files_prefix` catch-all:

```jinja2
      {% elif _p == (_one_shot_bundle_templates_prefix ~ 'Dockerfile.j2') %}
      apache/Dockerfile
      {% elif _p.startswith(_one_shot_bundle_files_prefix) %}
```

> ⚠️ **This block is critical for directory creation.** The "Render template
> files" task (line 90) runs *before* the separate "Ensure apache/externalConfigs
> directory exists" task (line 146). Block 1 is the only thing that creates
> `apache/` in the output dir before the render task tries to write
> `apache/Dockerfile`. If this mapping is missing, the render task fails with
> "No such file or directory".

**Block 2** ("Render template files" task, ~line 102): insert after the
`dbDump.sh.j2` entry, before `{% else %}`:

```jinja2
      {% elif _p == (_one_shot_bundle_templates_prefix ~ 'Dockerfile.j2') %}
      apache/Dockerfile
      {% else %}
```

Note: The `when` condition on the "Render template files" task already excludes
only `gighive.htpasswd.j2` and `docker-compose.yml.j2`. `Dockerfile.j2` will be
rendered automatically without any `when` changes needed.

---

### 5. `ansible/inventories/group_vars/gighive/gighive.yml`
**Action:** Two changes.

Remove the `upload_max_mb` line:
```yaml
# Remove:
upload_max_mb: 6390
```

Remove the explicit Dockerfile entry from `one_shot_bundle_input_paths` (the
`templates/` directory is already in the list and will pick up `Dockerfile.j2`
automatically):
```yaml
# Remove:
  - "{{ repo_root }}/ansible/roles/docker/files/apache/Dockerfile"
```

---

### 6. `ansible/inventories/group_vars/gighive2/gighive2.yml`
**Action:** Same two changes as file 5.

---

### 7. `ansible/inventories/group_vars/prod/prod.yml`
**Action:** Same two changes as file 5.

---

## What does NOT need to change

| File | Reason |
|---|---|
| `templates/modsecurity.conf.j2` | Already uses `{{ upload_max_bytes }}` directly ✅ |
| `templates/.env.j2` | Already has `UPLOAD_MAX_BYTES={{ upload_max_bytes }}` ✅ |
| `templates/docker-compose.yml.j2` | References `dockerfile: Dockerfile` — output filename unchanged ✅ |
| `files/one_shot_bundle/docker-compose.yml` | Same — `dockerfile: Dockerfile` ✅ |
| `roles/base/tasks/main.yml` | rsync syncs the `templates/` dir normally; `Dockerfile.j2` just rides along ✅ |
| `roles/telemetry_receiver/` | Has its own separate Dockerfile unrelated to this change ✅ |
| `roles/switch_runtime/` | Works off extracted bundle contents; no Dockerfile reference ✅ |

---

## Implementation status

> ⚠️ **Sequencing constraint**: Steps 1 and 2 must be committed/applied
> atomically or in immediate succession. The interim `replace` task in
> `docker/tasks/main.yml` references `{{ upload_max_mb }}`. If `upload_max_mb`
> is removed from group_vars (steps 5–7) *before* the `replace` task is
> removed and the `template` task is in place, the next Ansible run will fail
> on an undefined variable. Remove `upload_max_mb` from group_vars **last**,
> only after the `template` task is live.

- [ ] Move `files/apache/Dockerfile` → `templates/Dockerfile.j2` + substitute Jinja2 expression
- [ ] Update `roles/docker/tasks/main.yml` — swap `replace` task for `template` task *(do atomically with step above)*
- [ ] Update `roles/one_shot_bundle/tasks/monitor.yml` — add mapping in 2 blocks
- [ ] Update `roles/one_shot_bundle/tasks/output_bundle.yml` — add mapping in 2 blocks (Block 1 is critical for `apache/` dir creation)
- [ ] Update `group_vars/gighive/gighive.yml` — remove `upload_max_mb`, remove Dockerfile path *(do last)*
- [ ] Update `group_vars/gighive2/gighive2.yml` — same *(do last)*
- [ ] Update `group_vars/prod/prod.yml` — same *(do last)*
{% endraw %}
