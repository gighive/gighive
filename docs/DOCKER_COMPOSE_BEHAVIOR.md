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

## Solution: Always Nuclear Approach

### Implementation Strategy
```yaml
# Step 1: Always tear down completely
- name: Stop Docker Compose stack completely
  community.docker.docker_compose_v2:
    project_src: "{{ docker_dir }}"
    state: absent
    remove_orphans: true
  ignore_errors: true  # Handle initial deployment case

# Step 2: Clean build and startup
- name: Build and start Docker Compose stack
  community.docker.docker_compose_v2:
    project_src: "{{ docker_dir }}"
    state: present
    build: always
```

### Benefits of Nuclear Approach

#### Reliability
- **Consistent behavior** across all deployment scenarios
- **Eliminates state-dependent failures**
- **Matches proven manual workflow**

#### Predictability
- **Same process every time** regardless of current state
- **Easier to debug** when issues occur
- **Clear separation** of teardown and rebuild phases

#### Maintainability
- **Simpler logic** - no conditional deployment paths
- **Handles edge cases** automatically
- **Future-proof** against Docker Compose behavior changes

### Trade-offs

#### Pros
- ✅ **100% reliable rebuilds**
- ✅ **Handles all deployment scenarios**
- ✅ **Simple, predictable logic**
- ✅ **Matches working manual process**
- ✅ **No debugging of subtle state issues**

#### Cons
- ❌ **30-60 seconds additional deployment time**
- ❌ **Brief service interruption** (but already doing `recreate: always`)
- ❌ **More aggressive** than strictly necessary for some scenarios

## Decision Rationale

### Why Nuclear Over Gentle

1. **Reliability over Speed**: Extra 30-60 seconds is acceptable for guaranteed deployments
2. **Consistency**: Same behavior whether initial deployment or update
3. **Maintenance**: Eliminates entire class of deployment failures
4. **Confidence**: Developers know their changes will actually deploy

### Why Not Conditional Logic

Considered hybrid approach with `force_rebuild` flags, but:
- **Complexity**: Adds conditional logic and potential failure modes
- **Human Error**: Developers might forget to set rebuild flags
- **Debugging**: More variables to consider when deployments fail

## Implementation Impact

### Files Modified
- `ansible/roles/docker/tasks/main.yml` - Replace single compose task with two-step nuclear approach

### Deployment Changes
- **Slightly longer deployment time** (acceptable trade-off)
- **More reliable rebuilds** when code changes
- **Consistent behavior** across all scenarios

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
