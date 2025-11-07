# API Architecture Cleanup Plan

## Overview

This document outlines the plan to migrate from the legacy `/api/` directory structure to a proper MVC-based API routing system using the existing `/src/` directory structure.

## Current State

### Existing API Structure
- **`/api/uploads.php`** - Direct API endpoint file that delegates to MVC controllers
- **`/src/`** - Modern MVC architecture with Controllers, Services, Repositories
- **`/src/.htaccess`** - Configured for routing but missing main router file

### Problem
- Inconsistent API architecture with both direct files and MVC structure
- `/src/` has proper MVC setup but no router to handle requests
- Forms currently point to `/api/uploads.php`

## Migration Plan

### Phase 1: Create Minimal Router (Uploads Only)

**Step 1: Create `/src/index.php`**
- Handle only uploads routes:
  - `POST /src/uploads` → `UploadController::post()`
  - `GET /src/uploads/{id}` → `UploadController::get()`
- Use existing UploadController (no changes to controller)
- Use existing error handling from UploadController
- Keep it minimal - just route parsing and controller delegation

**Router Implementation:**
```php
// /src/index.php - minimal router
<?php
require __DIR__ . '/../vendor/autoload.php';
use Production\Api\Controllers\UploadController;
use Production\Api\Infrastructure\Database;

// Parse route from REQUEST_URI
// Match only: uploads and uploads/{id}
// Delegate to existing UploadController
// Return response exactly as UploadController does
```

### Phase 2: Update Forms (Keep Legacy Working)

**Step 2: Update Upload Forms**
- Change `db/upload_form.php`: `action="/api/uploads.php"` → `action="/src/uploads"`
- Change `db/upload_form_admin.php`: `action="/api/uploads.php"` → `action="/src/uploads"`
- **Keep `/api/uploads.php` untouched** during transition

### Phase 3: Side-by-Side Testing

**Step 3: Verify Both Routes Work**
- Test new route: `POST /src/uploads`
- Test old route: `POST /api/uploads.php` 
- Ensure both produce identical results
- Test both JSON and HTML responses
- Verify error conditions work the same

### Phase 4: Cleanup (After Verification)

**Step 4: Remove Legacy (Only After Confirmation)**
- Confirm new route works perfectly
- Delete `/api/uploads.php`
- Remove `/api/` directory entirely

## Implementation Constraints

### What We're NOT Changing
- ✅ UploadController stays exactly the same
- ✅ All existing error handling stays the same  
- ✅ Database connections stay the same
- ✅ Response formats stay the same
- ✅ `/api/uploads.php` stays working during transition
- ✅ No additional routes beyond uploads
- ✅ No new error handling

### What We ARE Changing
- ➕ Add `/src/index.php` router
- ➕ Update form actions to use new route
- ➕ Test both routes work identically

## Benefits After Migration

- **Single API architecture** - all routes in `/src/`
- **Clean URLs** - `/src/uploads` vs `/api/uploads.php`
- **Consistent routing** - one system for all endpoints
- **Easier maintenance** - no duplicate endpoint logic
- **Future extensibility** - easy to add new API endpoints

## Route Mapping

| Old Route | New Route | Controller Method |
|-----------|-----------|-------------------|
| `POST /api/uploads.php` | `POST /src/uploads` | `UploadController::post($_FILES, $_POST)` |
| `GET /api/uploads.php/{id}` | `GET /src/uploads/{id}` | `UploadController::get($id)` |

## Testing Checklist

- [ ] `POST /src/uploads` works for file uploads
- [ ] `GET /src/uploads/{id}` works for file retrieval
- [ ] JSON responses match old format
- [ ] HTML responses match old format
- [ ] Error conditions handled identically
- [ ] Both old and new routes work simultaneously
- [ ] Upload forms work with new routes

## Rollback Plan

If issues arise:
1. Revert form actions back to `/api/uploads.php`
2. Remove `/src/index.php` if problematic
3. Keep existing `/api/uploads.php` as primary endpoint

## Implementation Date

**Started:** November 7, 2025  
**Target Completion:** TBD based on testing results
