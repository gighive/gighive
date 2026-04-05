# One-Shot Bundle Assembly Flow

## Plain English Summary

`ansible/roles/one_shot_bundle/tasks/main.yml` (via `output_bundle.yml`) renders all the `.j2` templates from `roles/docker/templates/` and dumps them into `/tmp/gighive-one-shot-bundle/`, and also does a direct copy of the static files from `roles/docker/files/one_shot_bundle/` (and `roles/docker/files/apache/Dockerfile`) into that same `/tmp/gighive-one-shot-bundle/` folder.

The two passes are:

1. **`ansible.builtin.template`** — loops over everything under `roles/docker/templates/`, renders each `.j2` with Jinja2 variable substitution from `group_vars`, writes to `/tmp/gighive-one-shot-bundle/` with the mapped destination path. Runs **first**.

2. **`ansible.builtin.copy`** — loops over everything that is NOT under `templates/` (i.e., `roles/docker/files/one_shot_bundle/` static files + `roles/docker/files/apache/Dockerfile`), copies them as-is to `/tmp/gighive-one-shot-bundle/`. Runs **second**.

---

Shows how `/tmp/gighive-one-shot-bundle` is assembled by the `one_shot_bundle` Ansible role.

```mermaid
---
config:
  layout: elk
---
flowchart LR
 subgraph OSB["roles/docker/files/one_shot_bundle/"]
        s1["docker-compose.yml"]
        s2["LICENSE"]
        s3["VERSION"]
        s4["backup_and_replace.sh"]
        s5["rotate_basic_auth.sh"]
        s6["instructions_quickstart.sh"]
  end
 subgraph TMPLSRC["roles/docker/templates/"]
        t1["install.sh.j2"]
        t3["entrypoint.sh.j2"]
        t4[".env.j2"]
        t5[".env.mysql.j2"]
        t6["apache2.conf.j2"]
        t7["default-ssl.conf.j2"]
        t8["modsecurity.conf.j2"]
        t9["openssl_san.cnf.j2"]
        t10["php-fpm.conf.j2"]
        t11["security2.conf.j2"]
        t12["www.conf.j2"]
        t13["crs-setup.conf.j2"]
  end
 subgraph APACHESRC["roles/docker/files/apache/"]
        s7["Dockerfile"]
  end
 subgraph COL1["Sources"]
    direction TB
        OSB
        TMPLSRC
        APACHESRC
  end
 subgraph COL2["Ansible Processing"]
    direction TB
        RENDER["template / Jinja2"]
        COPY["copy"]
  end
 subgraph COL3["/tmp/gighive-one-shot-bundle/"]
    direction TB
        d1["install.sh"]
        d2["docker-compose.yml"]
        d3["apache/Dockerfile"]
        d4["apache/externalConfigs/entrypoint.sh"]
        d5["apache/externalConfigs/.env"]
        d6["mysql/externalConfigs/.env.mysql"]
        d7["apache/externalConfigs/apache2.conf"]
        d8["apache/externalConfigs/default-ssl.conf"]
        d9["apache/externalConfigs/modsecurity.conf"]
        d10["apache/externalConfigs/openssl_san.cnf"]
        d11["apache/externalConfigs/php-fpm.conf"]
        d12["apache/externalConfigs/security2.conf"]
        d13["apache/externalConfigs/www.conf"]
        d14["apache/externalConfigs/crs/crs-setup.conf"]
        d15["LICENSE"]
        d16["VERSION"]
        d17["backup_and_replace.sh"]
        d18["rotate_basic_auth.sh"]
        d19["instructions_quickstart.sh"]
  end
    t1 --> RENDER
    RENDER --> d1 & d4 & d5 & d6 & d7 & d8 & d9 & d10 & d11 & d12 & d13 & d14
    t3 --> RENDER
    t4 --> RENDER
    t5 --> RENDER
    t6 --> RENDER
    t7 --> RENDER
    t8 --> RENDER
    t9 --> RENDER
    t10 --> RENDER
    t11 --> RENDER
    t12 --> RENDER
    t13 --> RENDER
    s1 --> COPY
    COPY --> d2 & d15 & d16 & d17 & d18 & d19 & d3
    s2 --> COPY
    s3 --> COPY
    s4 --> COPY
    s5 --> COPY
    s6 --> COPY
    s7 --> COPY
```

[![Bundle Assembly Flow](images/one-shot-bundle-sources.png)](images/one-shot-bundle-sources.png)

## Notes

### docker-compose.yml source

The authoritative bundle compose file is `ansible/roles/docker/files/one_shot_bundle/docker-compose.yml` (static copy). `docker-compose.yml.j2` is explicitly excluded from the bundle template render step (same as `gighive.htpasswd.j2`) because it is designed for a deployed VM (absolute paths, hardcoded TZ) and is not portable for the self-contained bundle. The static file uses relative paths and shell env var defaults (`${TZ:-America/New_York}`, `${GIGHIVE_AUDIO_DIR:-./_host_audio}`, etc.).

### gighive.htpasswd.j2 is excluded

`gighive.htpasswd.j2` is explicitly skipped during template rendering. Instead, the role copies the existing `gighive.htpasswd` from the previously-deployed bundle directory (`one_shot_bundle_bundle_dir`).

### Previously stale: gighive-one-shot-bundle/ in repo root

The repo root's `gighive-one-shot-bundle/` directory was a manually-maintained static copy — it was **not** read or written by the assembly role. The install.sh there has been removed. The real sources are the two directories shown in column 1 above.
