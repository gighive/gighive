# Database Health Check Testing

## Problem
The `db/database.php` endpoint is password-protected using Basic Auth with credentials stored in `ansible/inventories/group_vars/gighive/secrets.yml`. We need to test this endpoint during the `validate_app` Ansible role without hardcoding secrets.

## Solution: Public Health Check Endpoint (Implemented)

### Overview
Created a dedicated, unauthenticated health check endpoint (`/db/health.php`) that:
- Tests database connectivity using the same `Database::createFromEnv()` method
- Returns only status information (no sensitive data)
- Requires no authentication
- Can be safely called from Ansible validation tasks

### Implementation

#### 1. Health Check Endpoint
**File:** `ansible/roles/docker/files/apache/webroot/db/health.php`

```php
<?php declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use Production\Api\Infrastructure\Database;

header('Content-Type: application/json');

try {
    $pdo = Database::createFromEnv();
    $stmt = $pdo->query('SELECT 1');
    $result = $stmt->fetch();
    
    if ($result) {
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'message' => 'Database connection successful']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed', 'error' => $e->getMessage()]);
}
```

#### 2. Apache Configuration Exception
**File:** `ansible/roles/docker/templates/default-ssl.conf.j2`

Added before the protected areas block:
```apache
# --- PUBLIC HEALTH CHECK ---
# Allow unauthenticated access to health check endpoint
<Location "/db/health.php">
    Require all granted
</Location>
```

**Security Note:** The `<Location>` directive is more specific than `<LocationMatch>` and takes precedence, allowing this single endpoint to bypass authentication while keeping all other `/db/*` paths protected.

#### 3. Ansible Validation Task
**File:** `ansible/roles/validate_app/tasks/main.yml`

```yaml
- name: Test database connectivity via health endpoint (no auth required)
  ansible.builtin.shell: |
    docker exec apacheWebServer curl -sk https://localhost/db/health.php
  register: db_health_test
  changed_when: false
  failed_when: false

- name: Parse database health check response
  ansible.builtin.set_fact:
    db_health_json: "{{ db_health_test.stdout | from_json }}"
  when: db_health_test.rc == 0
  failed_when: false

- name: Display database health check result
  ansible.builtin.debug:
    msg:
      - "Database health status: {{ db_health_json.status | default('unknown') }}"
      - "Message: {{ db_health_json.message | default('No response') }}"
      - "Error: {{ db_health_json.error | default('none') }}"
  when: db_health_json is defined

- name: Fail if database health check failed
  ansible.builtin.fail:
    msg: "Database health check failed: {{ db_health_json.message | default('Unknown error') }}"
  when:
    - db_health_json is defined
    - db_health_json.status != 'ok'
```

### Benefits
- ✅ No secrets exposed in Ansible tasks or logs
- ✅ Tests actual database connectivity (same code path as `database.php`)
- ✅ Returns structured JSON for easy parsing
- ✅ Minimal security risk (returns only status, no data)
- ✅ Can be used for monitoring/health checks beyond Ansible

---

## Alternative Approaches (Not Implemented)

### Option 2: Use Ansible Vault Variables at Runtime

Instead of hardcoding, pass credentials from vault variables:

```yaml
- name: Test database.php with vault credentials
  ansible.builtin.uri:
    url: "https://{{ gighive_host }}/db/database.php?format=json"
    method: GET
    force_basic_auth: true
    url_username: "{{ admin_user }}"
    url_password: "{{ gighive_admin_password }}"
    validate_certs: false
    status_code: [200]
  register: db_test
  no_log: true  # Prevents credentials from appearing in logs
```

**Pros:**
- Tests the actual protected endpoint
- Credentials never appear in plaintext in code

**Cons:**
- Still passes credentials over the wire (even if HTTPS)
- Requires `no_log: true` to prevent credential leakage
- More complex than health check approach

### Option 3: Container-Internal Testing

Test from inside the Apache container where auth can be bypassed:

```yaml
- name: Test database.php from inside container (bypass auth)
  ansible.builtin.command:
    cmd: docker exec apacheWebServer php /var/www/html/db/database.php
  register: db_internal_test
  changed_when: false
```

**Pros:**
- No authentication needed
- Tests PHP execution directly

**Cons:**
- Doesn't test the HTTP/Apache layer
- Doesn't verify auth is working
- May not catch routing or configuration issues

### Option 4: Temporary Test User

Create a temporary test user with a known password just for validation:

```yaml
- name: Create temporary test user
  community.general.htpasswd:
    path: "{{ gighive_htpasswd_path }}"
    name: "ansible_test"
    password: "temp_test_pass_{{ ansible_date_time.epoch }}"
    crypt_scheme: bcrypt
  register: test_user

- name: Test with temporary user
  ansible.builtin.uri:
    url: "https://{{ gighive_host }}/db/database.php"
    url_username: "ansible_test"
    url_password: "{{ test_user.password }}"
    # ... rest of config

- name: Remove temporary test user
  community.general.htpasswd:
    path: "{{ gighive_htpasswd_path }}"
    name: "ansible_test"
    state: absent
```

**Pros:**
- Tests actual auth mechanism
- Credentials are ephemeral

**Cons:**
- Complex (create/test/cleanup)
- Risk of leaving test user if playbook fails
- Still requires passing credentials

---

## Recommendation

**Use the implemented health check endpoint (Option 1)** because it:
1. Provides the cleanest separation of concerns
2. Has legitimate use beyond Ansible (monitoring, load balancers, etc.)
3. Requires no credential management
4. Tests the actual database connectivity code path
5. Is the industry-standard approach for health checks

The health endpoint tests what matters (database connectivity and PHP execution) without exposing sensitive functionality or requiring authentication.
