# Docker Compose Deployment Behavior Analysis

## Date
October 25, 2025

## Problem Statement

The Ansible `community.docker.docker_compose_v2` task with "gentle" approach (`state: present`, `build: always`, `recreate: always`) was **not reliably rebuilding containers** when code changes were made.

### Observed Issue
- Updated `MediaController.php` with `listJson()` method on VM host (Oct 25 09:27)
- After Ansible deployment, container still contained old version (Sep 27 18:00)
- Manual rebuild script worked correctly, but Ansible task did not

## Root Cause Analysis

### Manual Script (Working)
```bash
docker-compose down -v     # Nuclear: Complete removal
docker-compose build       # Explicit build step
docker-compose up -d       # Clean startup
```

### Ansible Task (Failing)
```yaml
community.docker.docker_compose_v2:
  state: present            # Gentle: Try to maintain state
  build: always
  recreate: always
  build_args: ["--no-cache"]
```

## Why Gentle Approach Fails

### 1. State Management Conflicts
- `state: present` tries to be "smart" about what needs rebuilding
- Docker's internal state tracking can become inconsistent
- Cached layers and metadata may not reflect actual file changes

### 2. Volume Persistence
- Gentle approach doesn't remove volumes (`-v` flag equivalent)
- Persistent data can interfere with clean rebuilds
- Container filesystem state may be preserved incorrectly

### 3. Build Order Dependencies
- Simultaneous build + recreate can have race conditions
- Sequential operations (down → build → up) are more reliable
- Docker Compose module may not handle complex state transitions well

### 4. Image Caching Issues
- Even with `--no-cache`, Docker may use cached intermediate layers
- `pull_policy: build` may not override all caching mechanisms
- Existing image tags can interfere with rebuild detection

## Deployment Scenarios

### Scenario 1: Initial Deployment
- **No containers exist**
- **Gentle approach**: ✅ Works fine
- **Nuclear approach**: ✅ Works fine (`ignore_errors` handles non-existent containers)

### Scenario 2: Code Updates
- **Containers exist, code changed**
- **Gentle approach**: ❌ Often fails to rebuild properly
- **Nuclear approach**: ✅ Always rebuilds correctly

### Scenario 3: Configuration Changes
- **Environment variables, volumes, networking changes**
- **Gentle approach**: ❌ May not detect all changes
- **Nuclear approach**: ✅ Ensures clean state

### Scenario 4: Regular Restarts
- **No code/config changes, just restart**
- **Gentle approach**: ✅ Works fine
- **Nuclear approach**: ✅ Works fine (slightly slower)

## Solution: Selective Container Rebuild Approach

### Problem with Full Nuclear Approach
The initial "always nuclear" approach (`state: absent`) had a critical flaw:
- **Destroys ALL volumes** including the MySQL database (`mysql_data`)
- **Catastrophic for production updates** - would delete customer databases
- **Too aggressive** for routine application updates

### Refined Strategy: Container-Specific Rebuild
```yaml
# Step 1: Stop only Apache container (preserve MySQL)
- name: Stop Apache container for rebuild
  community.docker.docker_container:
    name: apacheWebServer
    state: absent
  ignore_errors: true

# Step 2: Conditionally stop MySQL (only when explicitly requested)
- name: Stop MySQL container for rebuild (when requested)
  community.docker.docker_container:
    name: mysqlServer
    state: absent
  when: rebuild_mysql | default(false)
  ignore_errors: true

# Step 3: Remove Apache image to force rebuild
- name: Remove Apache image to force rebuild
  community.docker.docker_image:
    name: ubuntu22.04apache-img:1.00
    state: absent
  ignore_errors: true

# Step 4: Start full stack (MySQL preserved, Apache rebuilt)
- name: Start Docker Compose stack
  community.docker.docker_compose_v2:
    project_src: "{{ docker_dir }}"
    state: present
    build: always
```

### Configuration Control
**In `group_vars/gighive.yml`:**
```yaml
rebuild_mysql: false       # Rebuild MySQL container (preserve data)
rebuild_mysql_data: false  # Rebuild MySQL container + wipe database (nuclear)
```

**Command-line usage:**
```yaml
# Default: Apache only
ansible-playbook site.yml

# MySQL container rebuild (data preserved)
ansible-playbook site.yml -e "rebuild_mysql=true"

# MySQL nuclear rebuild (data wiped, CSV reimported)
ansible-playbook site.yml -e "rebuild_mysql_data=true"
```

**In docker role tasks:**
```yaml
when: rebuild_mysql | default(false) or rebuild_mysql_data | default(false)
when: rebuild_mysql_data | default(false)  # Volume removal
```

### Flag Hierarchy and Logic

**Important**: `rebuild_mysql_data: true` is **self-sufficient** and implies container rebuild:

- **`rebuild_mysql_data: true`** → Nuclear rebuild (container + data wiped)
- **`rebuild_mysql: true`** → Container rebuild only (data preserved)  
- **Both `false`** → No MySQL changes

**You do NOT need both flags set to `true`**. The logic works as follows:

```yaml
# Container stop condition
rebuild_mysql: false + rebuild_mysql_data: true  
# Result: false OR true = TRUE (container stops)

# Volume removal condition  
rebuild_mysql_data: true
# Result: TRUE (volume removed)
```

### Benefits of Selective Container Approach

#### Safety
- **Database preservation by default** - no accidental data loss
- **Targeted rebuilds** - only rebuild what needs updating
- **Production-safe** - appropriate for live customer systems

#### Flexibility
- **Granular control** - rebuild Apache, MySQL, or both
- **Command-line override** - `rebuild_mysql=true` when needed
- **Environment-specific** - different defaults per environment

#### Reliability
- **Consistent behavior** across deployment scenarios
- **Eliminates state-dependent failures**
- **Handles fresh deployments** automatically

### Deployment Scenarios Handled

#### Scenario 1: Routine Application Updates (90% of cases)
- **Apache**: Always rebuilt with latest code
- **MySQL**: Untouched (container + data preserved)
- **Usage**: `ansible-playbook site.yml`

#### Scenario 2: MySQL Container Updates
- **Apache**: Rebuilt with latest code
- **MySQL**: Container rebuilt, **data preserved**
- **Usage**: `ansible-playbook site.yml -e "rebuild_mysql=true"`
- **Use cases**: MySQL version upgrades, configuration changes, container corruption

#### Scenario 3: MySQL Nuclear Rebuild
- **Apache**: Rebuilt with latest code
- **MySQL**: Container + volume destroyed, **fresh database with CSV import**
- **Usage**: `ansible-playbook site.yml -e "rebuild_mysql_data=true"`
- **Use cases**: Database corruption, schema changes, fresh development environment

#### Scenario 4: Fresh VM Deployment
- **Apache**: Built fresh
- **MySQL**: Built fresh with automatic CSV import
- **Usage**: `ansible-playbook site.yml` (works automatically)

#### Scenario 5: Manual Nuclear Option
- **Both**: Complete teardown including volumes
- **Usage**: Manual `rebuildContainers.sh` script

### Trade-offs

#### Pros
- ✅ **Database safety by default** (three levels of protection)
- ✅ **Reliable Apache rebuilds** (always rebuilt)
- ✅ **Granular MySQL control** (preserve, rebuild container, or nuclear)
- ✅ **Production-appropriate** (safe defaults)
- ✅ **Automatic CSV reimport** (when volume destroyed)
- ✅ **Handles all deployment states** (fresh VM + existing containers)

#### Cons
- ❌ **Two flags to understand** (rebuild_mysql vs rebuild_mysql_data)
- ❌ **Requires explicit flags** for MySQL operations

## Decision Rationale

### Why Selective Container Over Full Nuclear

1. **Database Safety**: Preserving customer data is paramount
2. **Production Readiness**: Appropriate for live systems with valuable data
3. **Flexibility**: Can handle both routine updates and major changes
4. **Performance**: MySQL doesn't restart unnecessarily

### Why Selective Container Over Gentle

1. **Reliability**: Apache always rebuilds with latest code changes
2. **Predictability**: Consistent behavior across deployment scenarios
3. **Debugging**: Clear understanding of what gets rebuilt
4. **Proven Pattern**: Matches successful manual rebuild workflow

### Configuration Design

- **Safe Default**: `rebuild_mysql: false` prevents accidental data loss
- **Explicit Override**: Requires intentional action to rebuild database
- **Centralized Control**: Configuration in `group_vars` with command-line override
- **Environment Flexibility**: Different defaults per environment possible

## Implementation Impact

### Files Modified
- `ansible/inventories/group_vars/gighive.yml` - Add `rebuild_mysql: false` flag
- `ansible/roles/docker/tasks/main.yml` - Replace compose tasks with selective container approach

### Variable Resolution
- **Default value**: Defined in `group_vars/gighive.yml`
- **Command-line override**: `ansible-playbook site.yml -e "rebuild_mysql=true"`
- **Task condition**: Uses `rebuild_mysql | default(false)` for safety

### Deployment Changes
- **Database preserved by default** (critical for production safety)
- **Reliable Apache rebuilds** when code changes
- **Flexible MySQL handling** via command-line override
- **Consistent behavior** across fresh and update deployments

### Operational Benefits
- **Reduced debugging time** for failed deployments
- **Increased developer confidence** in deployment process
- **Simplified troubleshooting** - always starts from clean state

## Validation Criteria

### Success Metrics
1. **File timestamps match** between VM host and container after deployment
2. **Code changes always reflected** in running containers
3. **Initial deployments work** on clean systems
4. **Configuration changes properly applied**

### Test Cases
1. Initial deployment on clean VM
2. Code update deployment (MediaController.php changes)
3. Configuration-only changes (environment variables)
4. No-change redeployment (should still work)

## Related Context

This change supports the **Database Viewer Implementation Plan Phase 1**:
- Ensures updated `MediaController.php` with `listJson()` method deploys correctly
- Critical for JSON API functionality at `/db/database.php?format=json`
- Foundation for Phase 2 iOS native database viewer

## Future Considerations

### Monitoring
- Track deployment times to ensure nuclear approach remains acceptable
- Monitor for any Docker Compose module behavior changes

### Optimization Opportunities
- Could implement smart detection of when nuclear approach is needed
- Consider Docker layer caching optimizations
- Evaluate alternative deployment strategies as Docker ecosystem evolves
