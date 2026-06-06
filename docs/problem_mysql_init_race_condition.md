# Problem: MySQL init race condition — "Access denied" with correct password

## Symptom

After a full Ansible build (`changed=70+`), any task that connects to MySQL via `docker exec mysql -u root` immediately after the stack starts will fail with:

```
ERROR 1045 (28000): Access denied for user 'root'@'localhost' (using password: YES)
```

The password IS correct. The container IS running. The error is transient.

## Root cause

The MySQL Docker image initializes the root account **asynchronously** after the container starts. `mysqladmin ping` (used in `validate_app` to gate readiness) only confirms the Unix socket is accepting connections — it does not confirm that the grant tables and root credentials are fully committed. On this host, full root auth readiness takes up to ~60 seconds after socket availability.

Any validation task that runs immediately after `validate_app`'s ping check can hit a window where MySQL is reachable but root login is not yet accepted.

## Mitigation pattern

Add `retries`/`delay`/`until` to any `shell` task that connects as root, so it waits out the initialization window:

```yaml
- name: Example | wait for MySQL and verify something
  ansible.builtin.shell: |
    CID=$(docker ps --format "{{.Names}}" | grep mysql | head -1)
    docker exec -e "MYSQL_PWD=$MYSQL_ROOT_PW" "$CID" mysql -u root mydb -sN -e "SELECT 1;"
  environment:
    MYSQL_ROOT_PW: "{{ __mysql_root_pw }}"
  register: __result
  until: __result.rc == 0
  retries: 12
  delay: 10
  changed_when: false
```

`retries: 12, delay: 10` gives a 2-minute ceiling, which covers the observed worst case.

## Password extraction pattern

Use Ansible `slurp` + `set_fact` (not shell `grep | cut`) to extract the root password. This is immune to CRLF line endings and passwords containing `=`:

```yaml
- name: Read MySQL env file
  ansible.builtin.slurp:
    src: "{{ configs_dir }}/.env.mysql"
  register: __mysql_env

- name: Extract MySQL root password
  ansible.builtin.set_fact:
    __mysql_root_pw: >-
      {{ (__mysql_env.content | b64decode).splitlines()
         | select('search', '^MYSQL_ROOT_PASSWORD=')
         | map('regex_replace', '^MYSQL_ROOT_PASSWORD=', '')
         | list | first }}
```

Pass the password to `docker exec` via `environment:` + `-e MYSQL_PWD=` — never inline on the command line.

## Where this is implemented

- `ansible/roles/ai_worker/tasks/validate.yml` — slurp + set_fact + retries pattern
- `ansible/roles/validate_app/tasks/main.yml` — slurp + set_fact pattern (no retry needed; it does not connect to MySQL directly)
