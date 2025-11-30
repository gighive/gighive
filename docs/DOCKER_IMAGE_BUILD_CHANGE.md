# Docker Image Build Process Change

## Date
October 25, 2025

## Problem Statement

The current Ansible deployment uses a **dual build approach** that causes issues with file updates not appearing in running containers:

1. **`community.docker.docker_image`** task builds `ubuntu22.04apache-img:1.00`
2. **`community.docker.docker_compose_v2`** task sees existing image and skips rebuild

### Specific Issue Encountered
- Updated `MediaController.php` with new `listJson()` method on VM host (Oct 25 09:27)
- Docker container still contained old version (Sep 27 18:00) 
- JSON API endpoint failed with "Call to undefined method" error
- Root cause: Docker Compose reused existing image instead of rebuilding with updated files

## Current Architecture Problems

### Dual Build Process
```yaml
# Step 1: Ansible builds image
- name: Build the GigHive Docker image on the VM
  community.docker.docker_image:
    name: ubuntu22.04apache-img
    tag: "1.00"
    # ... builds image with current files

# Step 2: Docker Compose sees existing image
- name: Bring up Docker Compose V2 stack
  community.docker.docker_compose_v2:
    build: always  # Ignored because image exists!
    recreate: always  # Recreates container but with old image
```

### Race Condition
- If files change between Ansible runs, the `docker_image` task may not detect changes
- Docker Compose assumes existing image is current
- `build: always` and `recreate: always` don't force image rebuilds when image name exists

## Solution: Single Build Process

### Approach
Use **only** `docker_compose_v2` for both building and container management.

### Changes Required

#### 1. Remove Standalone Image Build
Remove the `community.docker.docker_image` task that creates potential conflicts.

#### 2. Force Docker Compose Rebuilds
Add `pull_policy: build` to docker-compose.yml to ensure image rebuilds every deployment.

### Benefits

#### Consistency
- Single source of truth for image building
- Docker Compose handles entire service lifecycle
- No race conditions between build methods

#### Reliability  
- `pull_policy: build` forces rebuild regardless of existing image
- File changes always reflected in running containers
- Simplified deployment process

#### Maintainability
- Fewer moving parts in build process
- Standard Docker Compose patterns
- Easier troubleshooting

## Implementation

### Files Modified
1. `ansible/roles/docker/tasks/main.yml` - Remove `docker_image` task
2. `ansible/roles/docker/templates/docker-compose.yml.j2` - Add `pull_policy: build`

### Deployment Impact
- Next Ansible run will rebuild image with all current files
- No downtime beyond normal container recreation
- Backward compatible with existing infrastructure

## Validation

### Test Cases
1. Verify HTML endpoint continues working: `https://staging.gighive.app/db/database.php`
2. Verify JSON endpoint works: `https://staging.gighive.app/db/database.php?format=json`
3. Confirm file timestamps match between VM host and container
4. Validate authentication works for both endpoints

### Success Criteria
- Container file dates match VM host file dates
- JSON API returns proper MediaEntry array
- No "undefined method" errors
- Both HTML and JSON endpoints functional

## Rollback Plan

If issues arise:
1. Restore `community.docker.docker_image` task in `main.yml`
2. Remove `pull_policy: build` from docker-compose template
3. Re-run Ansible playbook

## Related Context

This change supports **Phase 1** of the Database Viewer Implementation Plan:
- Server-side JSON API capability for `/db/database.php?format=json`
- Required for native iOS database viewer in Phase 2
- Critical for user experience improvements in GigHive mobile app
