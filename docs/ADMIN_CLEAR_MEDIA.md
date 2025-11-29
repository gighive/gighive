# Admin Page: Clear Media Data Feature

## Overview

The GigHive admin page (`admin.php`) now includes two sections for first-time setup:

1. **Section 1: Change Default Passwords** (Required)
2. **Section 2: Clear Sample Media Data** (Optional)

This allows users to easily remove demo content and prepare GigHive for their own media.

## Architecture

### Files Created/Modified

```
ansible/roles/docker/files/
├── mysql/dbScripts/
│   └── truncate_media_tables.sql          # SQL reference script
├── apache/overlays/gighive/
│   ├── admin.php                          # Modified: Renamed from changethepasswords.php, Added Section 2
│   └── clear_media.php                    # New: Backend endpoint
```

### Design Pattern: Option A2

**Simple Standalone with Consistent Response Structure**

- Standalone PHP endpoint (no MVC routing)
- Admin-only access (same auth as password page)
- Response structure matches upload API pattern
- AJAX call from admin page

## Implementation Details

### 1. SQL Script (`truncate_media_tables.sql`)

Reference script showing the truncation logic:

```sql
SET FOREIGN_KEY_CHECKS = 0;

-- Junction tables
TRUNCATE TABLE session_musicians;
TRUNCATE TABLE session_songs;
TRUNCATE TABLE song_files;

-- Core media tables
TRUNCATE TABLE files;
TRUNCATE TABLE songs;
TRUNCATE TABLE sessions;

-- Reference tables
TRUNCATE TABLE musicians;
TRUNCATE TABLE genres;
TRUNCATE TABLE styles;

SET FOREIGN_KEY_CHECKS = 1;
```

**Preserved:** `users` table only

### 2. Backend Endpoint (`clear_media.php`)

**Features:**
- Admin-only access check
- POST-only endpoint
- Transaction-based execution
- Consistent response structure (matches upload API)
- Proper error handling with rollback

**Response Format:**
```json
{
  "success": true,
  "message": "All media tables cleared successfully. Users table preserved.",
  "tables_cleared": {
    "junction": ["session_musicians", "session_songs", "song_files"],
    "media": ["files", "songs", "sessions"],
    "reference": ["musicians", "genres", "styles"]
  }
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "Database Error",
  "message": "Failed to clear media tables: [error details]"
}
```

### 3. Admin Page UI (`admin.php`)

**Section 2 Features:**
- Visual separation from Section 1
- Warning box highlighting irreversibility
- Red "danger" button styling
- JavaScript confirmation dialog
- AJAX call with status feedback
- Auto-redirect to database page on success
- Disabled button during operation

**User Flow:**
1. User clicks "Clear All Media Data"
2. JavaScript confirmation dialog appears
3. On confirm, button disables and shows "Clearing..."
4. AJAX POST to `clear_media.php`
5. Success: Shows green alert, redirects to `/db/database.php` after 2s
6. Error: Shows red alert, re-enables button

## Security

### Access Control
- ✅ Admin-only (same gate as password page)
- ✅ POST-only (prevents accidental GET requests)
- ✅ JavaScript confirmation required
- ✅ Transaction-based (atomic operation)

### Data Protection
- ✅ Users table preserved
- ✅ Rollback on error
- ✅ No external SQL file execution (prevents injection)
- ✅ All SQL hardcoded in PHP

## Usage

### For End Users

1. **Access the admin page:**
   ```
   https://your-gighive-domain/admin.php
   ```
   (Must be logged in as `admin`)

2. **Section 1: Change passwords first** (recommended)

3. **Section 2: Clear demo data**
   - Click "Clear All Media Data"
   - Confirm in dialog
   - Wait for success message
   - Automatically redirected to database page

### For Developers

**Testing the endpoint directly:**
```bash
curl -X POST https://your-domain/admin/clear_media.php \
  -u admin:your-password \
  -H "Content-Type: application/json"
```

**Expected response:**
```json
{
  "success": true,
  "message": "All media tables cleared successfully. Users table preserved.",
  "tables_cleared": {...}
}
```

## API Consistency

This endpoint follows the same response structure as the upload API:

```php
$response = [
    'status' => 200,
    'headers' => ['Content-Type' => 'application/json'],
    'body' => [...]
];
```

**Benefits:**
- Consistent error handling across codebase
- Same HTTP status code patterns
- Familiar structure for maintenance
- Easy to extend in future

## Tables Affected

### Truncated (Cleared)
- `session_musicians` - Junction table
- `session_songs` - Junction table
- `song_files` - Junction table
- `files` - Media files
- `songs` - Song metadata
- `sessions` - Session/event data
- `musicians` - Musician names
- `genres` - Genre reference
- `styles` - Style reference

### Preserved
- `users` - Authentication data

## Error Handling

| Scenario | HTTP Status | Response |
|----------|-------------|----------|
| Success | 200 | `{success: true, message: "..."}` |
| Not admin | 403 | `{success: false, error: "Forbidden"}` |
| Not POST | 405 | `{success: false, error: "Method Not Allowed"}` |
| DB error | 500 | `{success: false, error: "Database Error"}` |
| Other error | 500 | `{success: false, error: "Server Error"}` |

All database errors trigger transaction rollback.

## Future Enhancements

Potential improvements:
- [ ] Add confirmation checkbox in UI (in addition to JS confirm)
- [ ] Log truncation events to audit table
- [ ] Add "Export before clear" option
- [ ] Show table row counts before/after
- [ ] Add ability to clear individual tables
- [ ] Create backup before truncation

## Deployment

Files are deployed via Ansible as part of the docker role:
- SQL script: Reference only (not executed automatically)
- PHP files: Copied to Apache overlay directory
- No additional configuration needed

## Testing Checklist

- [ ] Admin can access page
- [ ] Non-admin gets 403
- [ ] Password change works (Section 1)
- [ ] Clear media button shows confirmation
- [ ] Canceling confirmation does nothing
- [ ] Confirming clears all media tables
- [ ] Users table remains intact
- [ ] Success message displays
- [ ] Redirect to database page works
- [ ] Database shows empty media tables
- [ ] Error handling works (simulate DB failure)
- [ ] Button re-enables on error

## Related Documentation

- `/docs/HTPASSWD_CHANGES.md` - Password management details
- `/docs/UPLOAD_OPTIONS.md` - Upload API structure
- `/docs/database-import-process.md` - Database schema details
