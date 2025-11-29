# Apache Directive Matching Order and Debugging

## Problem Discovery

When implementing the `/db/health.php` unauthenticated health check endpoint, we encountered an issue where the endpoint returned `401 Unauthorized` despite having a `<Location>` directive with `Require all granted`.

## Root Cause: Directive Merging in Apache 2.4+

In Apache 2.4+, when multiple configuration sections (`<Location>`, `<LocationMatch>`, `<Directory>`, etc.) apply to the same path, they **merge** rather than override each other. This is different from earlier Apache versions.

### Initial Configuration (Broken)

```apache
# Attempt to allow unauthenticated access
<Location "/db/health.php">
    Require all granted
</Location>

# Broader pattern that also matches /db/health.php
<LocationMatch "^/(?:app/(?!cache(?:/|$)).*|api|db|debug|src|vendor|video|audio)(?:/|$)">
    AuthType Basic
    AuthName "GigHive Protected"
    AuthBasicProvider file
    AuthUserFile /var/www/private/gighive.htpasswd
    Require valid-user
</LocationMatch>
```

**Why it failed:**
- Both directives apply to `/db/health.php`
- Apache merges the configurations
- The `Require valid-user` from `<LocationMatch>` is applied **in addition to** `Require all granted` from `<Location>`
- Result: Authentication is still required (401 Unauthorized)

## Debugging Process

### Step 1: Verify Apache Configuration is Loaded

```bash
ansible gighive_vm -i ansible/inventories/inventory_bootstrap.yml -m shell \
  -a "docker exec apacheWebServer apachectl -t -D DUMP_VHOSTS -D DUMP_RUN_CFG 2>&1 | grep -A20 'VirtualHost configuration'"
```

**Output:**
```
VirtualHost configuration:
*:443                  gighive (/etc/apache2/sites-enabled/default-ssl.conf:16)
ServerRoot: "/etc/apache2"
Main DocumentRoot: "/var/www/html"
...
```

✅ Confirms Apache is loading the config file.

### Step 2: Check Active Configuration in Container

```bash
ansible gighive_vm -i ansible/inventories/inventory_bootstrap.yml -m shell \
  -a "docker exec apacheWebServer cat /etc/apache2/sites-enabled/default-ssl.conf | grep -A10 'PUBLIC HEALTH\\|EXISTING PROTECTED'"
```

**Output:**
```apache
# --- PUBLIC HEALTH CHECK ---
<Location "/db/health.php">
    Require all granted
</Location>

# --- EXISTING PROTECTED AREAS (any valid user) ---
<LocationMatch "^/(?:app/(?!cache(?:/|$)).*|api|db|debug|src|vendor|video|audio)(?:/|$)">
    AuthType Basic
    ...
    Require valid-user
</LocationMatch>
```

✅ Confirms both directives are present in the active config.

### Step 3: Verify File Exists

```bash
ansible gighive_vm -i ansible/inventories/inventory_bootstrap.yml -m shell \
  -a "docker exec apacheWebServer ls -la /var/www/html/db/health.php"
```

**Output:**
```
-rw-r--r-- 1 www-data www-data 899 Nov 29 22:20 /var/www/html/db/health.php
```

✅ File exists and has correct permissions.

### Step 4: Check Apache Modules

```bash
ansible gighive_vm -i ansible/inventories/inventory_bootstrap.yml -m shell \
  -a "docker exec apacheWebServer apache2ctl -M | grep -i 'authz\\|auth_basic'"
```

**Output:**
```
auth_basic_module (shared)
authz_core_module (shared)
authz_host_module (shared)
authz_user_module (shared)
```

✅ Required auth modules are loaded.

### Step 5: Test Actual HTTP Response

```bash
ansible gighive_vm -i ansible/inventories/inventory_bootstrap.yml -m shell \
  -a "docker exec apacheWebServer curl -sk -o /dev/null -w '%{http_code}' https://localhost/db/health.php"
```

**Output:**
```
401
```

❌ Confirms the endpoint requires authentication despite `Require all granted`.

### Step 6: Test Regex Pattern

Verify the proposed fix will correctly exclude `/db/health.php`:

```bash
python3 -c "import re; \
pattern = r'^/(?:app/(?!cache(?:/|$)).*|api|db/(?!health\.php$).*|debug|src|vendor|video|audio)(?:/|$)'; \
tests = ['/db/health.php', '/db/database.php', '/db/test.php', '/api/test', '/db/health.php/']; \
[print(f'{t}: {bool(re.match(pattern, t))}') for t in tests]"
```

**Output:**
```
/db/health.php: False      ✅ No match = no auth required
/db/database.php: True     ✅ Match = auth required
/db/test.php: True         ✅ Match = auth required
/api/test: True            ✅ Match = auth required
/db/health.php/: True      ✅ Match = auth required (trailing slash)
```

✅ Regex correctly excludes only `/db/health.php` (exact match).

## Solution: Exclude from LocationMatch Pattern

Instead of trying to override with `<Location>`, exclude the health check endpoint from the `<LocationMatch>` pattern using a negative lookahead.

### Fixed Configuration

```apache
# --- EXISTING PROTECTED AREAS (any valid user) ---
# Excludes /db/health.php from authentication requirement
<LocationMatch "^/(?:app/(?!cache(?:/|$)).*|api|db/(?!health\.php$).*|debug|src|vendor|video|audio)(?:/|$)">
    AuthType Basic
    AuthName "GigHive Protected"
    AuthBasicProvider file
    AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
    Require valid-user
</LocationMatch>
```

**Key change:** `db` → `db/(?!health\.php$).*`

### Regex Breakdown

```regex
db/(?!health\.php$).*
```

- `db/` - Match literal `/db/`
- `(?!health\.php$)` - Negative lookahead: NOT followed by `health.php` at end of string
- `.*` - Match any remaining characters

This pattern matches:
- ✅ `/db/database.php`
- ✅ `/db/upload_form.php`
- ✅ `/db/anything_else.php`
- ❌ `/db/health.php` (excluded)

## Apache Directive Precedence Rules

### Processing Order (Apache 2.4+)

1. `<Directory>` and `.htaccess` (merged)
2. `<DirectoryMatch>` and `<Directory "~">`
3. `<Files>` and `<FilesMatch>` (merged)
4. `<Location>` and `<LocationMatch>` (merged)

**Important:** Within each category, directives are **merged**, not overridden!

### Merge Behavior

When multiple sections apply to the same resource:
- All matching sections are processed
- Directives are merged according to their merge rules
- `Require` directives use implicit `RequireAny` logic (any one must pass)
- BUT: If `AuthType` is set, authentication is enforced even if `Require all granted` is also present

### Alternative Solutions (Not Used)

#### Option 1: Use RequireAny/RequireAll Logic

```apache
<Location "/db/health.php">
    <RequireAny>
        Require all granted
    </RequireAny>
</Location>

<LocationMatch "^/(?:app/(?!cache(?:/|$)).*|api|db|debug|src|vendor|video|audio)(?:/|$)">
    <RequireAll>
        AuthType Basic
        AuthName "GigHive Protected"
        AuthBasicProvider file
        AuthUserFile /var/www/private/gighive.htpasswd
        Require valid-user
    </RequireAll>
</LocationMatch>
```

**Why not used:** More complex and still requires careful ordering.

#### Option 2: Use Satisfy Directive (Apache 2.2 style)

Not available in Apache 2.4+ with the same semantics.

#### Option 3: Separate Location Blocks

```apache
<Location "/db">
    AuthType Basic
    ...
    Require valid-user
</Location>

<Location "/db/health.php">
    AuthType None
    Require all granted
</Location>
```

**Why not used:** `AuthType None` doesn't reliably override in all Apache versions.

## Testing the Fix

After deploying the updated configuration:

```bash
# Deploy changes
ansible-playbook -i ansible/inventories/inventory_bootstrap.yml \
  ansible/playbooks/site.yml --tags docker,validate_app

# Test health endpoint (should return 200)
curl -k https://gighive.local/db/health.php

# Test protected endpoint (should return 401)
curl -k https://gighive.local/db/database.php

# Test with credentials (should return 200)
curl -k -u admin:password https://gighive.local/db/database.php
```

## Key Takeaways

1. **Apache 2.4+ merges directives** - Multiple matching sections don't override, they combine
2. **LocationMatch + Location = Both Apply** - Even if Location is more specific
3. **Exclude from pattern** - Cleanest solution is to exclude exceptions from the regex pattern
4. **Test with curl** - Always verify actual HTTP behavior, not just config syntax
5. **Use python regex testing** - Validate complex patterns before deploying
6. **Check loaded config** - Verify the container has the latest config file

## References

- [Apache 2.4 Configuration Sections](https://httpd.apache.org/docs/2.4/sections.html)
- [Apache 2.4 Authentication and Authorization](https://httpd.apache.org/docs/2.4/howto/auth.html)
- [Apache Require Directive](https://httpd.apache.org/docs/2.4/mod/mod_authz_core.html#require)
