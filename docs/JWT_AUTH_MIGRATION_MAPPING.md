# JWT Authentication Migration - Apache to Application Layer Mapping

**Created**: 2025-11-09  
**Purpose**: Map existing Apache HTTP Basic Auth rules to new JWT-based application authentication

---

## Current Apache Configuration Analysis

**Source**: `ansible/roles/docker/templates/default-ssl.conf.j2`

### Current Three-User System

The `.htpasswd` file currently supports three users:
- `admin` - Full administrative access
- `uploader` - Can upload files
- `viewer` - Read-only access

### Current Access Control Rules

#### **1. Public Access (No Authentication)**
```apache
Location: /
Location: /index.php
```
**Access**: Anyone  
**JWT Mapping**: No auth required

---

#### **2. General Protected Areas (Any Valid User)**
```apache
<LocationMatch "^/(?:app/(?!cache(?:/|$)).*|api|db|debug|src|vendor|video|audio)(?:/|$)">
    Require valid-user
</LocationMatch>
```

**Paths Protected**:
- `/app/*` (except `/app/cache`)
- `/api/*`
- `/db/*`
- `/debug/*`
- `/src/*`
- `/vendor/*`
- `/video/*` (media files)
- `/audio/*` (media files)

**Current Access**: Any authenticated user (admin, uploader, OR viewer)  
**JWT Mapping**: `requireRole('viewer')` - Minimum role required

---

#### **3. Upload Forms (Admin + Uploader Only)**
```apache
<Location "/db/upload_form.php">
    Require user admin uploader
</Location>
```

**Current Access**: Only `admin` OR `uploader`  
**JWT Mapping**: `requireRole('uploader')` - Uploader or higher

---

#### **4. Admin Upload Form (Admin Only)**
```apache
<Location "/db/upload_form_admin.php">
    Require user admin
</Location>
```

**Current Access**: Only `admin`  
**JWT Mapping**: `requireRole('admin')` - Admin only

---

#### **5. Upload API Endpoint (Admin + Uploader Only)**
```apache
<Location "/api/uploads.php">
    Require user admin uploader
</Location>
```

**Current Access**: Only `admin` OR `uploader`  
**JWT Mapping**: `requireRole('uploader')` - Uploader or higher

---

#### **6. Change Password Page (Admin Only)**
```apache
<Files "changethepasswords.php">
    Require user admin
</Files>
```

**Current Access**: Only `admin`  
**JWT Mapping**: `requireRole('admin')` - Admin only

---

#### **7. Denied Paths (No Access)**
```apache
<LocationMatch "^/app/cache(/|$)">
    Require all denied
</LocationMatch>

<FilesMatch "^(composer\.(json|lock)|config\.php)$">
    Require all denied
</FilesMatch>

<FilesMatch "^\.">
    Require all denied
</FilesMatch>
```

**Current Access**: Denied to everyone  
**JWT Mapping**: Keep Apache-level denial (security in depth)

---

## Access Control Summary

| Resource | Path/File | Current Apache Auth | JWT Role Required |
|----------|-----------|---------------------|-------------------|
| Home page | `/index.php` | Public | None |
| Database viewer | `/db/database.php` | Any valid user | `viewer` |
| Database list | `/db/list.php` | Any valid user | `viewer` |
| Media files (video) | `/video/*` | Any valid user | `viewer` |
| Media files (audio) | `/audio/*` | Any valid user | `viewer` |
| Upload form | `/db/upload_form.php` | `admin` or `uploader` | `uploader` |
| Admin upload form | `/db/upload_form_admin.php` | `admin` only | `admin` |
| Upload API | `/api/uploads.php` | `admin` or `uploader` | `uploader` |
| Change passwords | `/changethepasswords.php` | `admin` only | `admin` |

---

## JWT Migration Mapping Table (Detailed)

| Path/File | Current Apache Auth | JWT Role Required | Implementation File |
|-----------|-------------------|------------------|-------------------|
| `/` | Public | None | N/A |
| `/index.php` | Public | None | N/A |
| `/db/database.php` | Any valid user | `viewer` | Add `requireRole('viewer')` |
| `/db/list.php` | Any valid user | `viewer` | Add `requireRole('viewer')` |
| `/db/upload_form.php` | admin, uploader | `uploader` | Add `requireRole('uploader')` |
| `/db/upload_form_admin.php` | admin only | `admin` | Add `requireRole('admin')` |
| `/api/uploads.php` | admin, uploader | `uploader` | Add `requireRole('uploader')` |
| `/changethepasswords.php` | admin only | `admin` | Add `requireRole('admin')` |
| `/video/*` | Any valid user | `viewer` | Add `requireRole('viewer')` |
| `/audio/*` | Any valid user | `viewer` | Add `requireRole('viewer')` |
| `/debug/*` | Any valid user | `viewer` | Add `requireRole('viewer')` |
| `/src/*` | Any valid user | N/A | Keep Apache-level protection |
| `/vendor/*` | Any valid user | N/A | Keep Apache-level protection |
| `/app/*` | Any valid user | N/A | Keep Apache-level protection |

---

## Role Hierarchy

```
viewer (level 1)
  ├─ View database (/db/database.php, /db/list.php)
  ├─ Stream media (/video/*, /audio/*)
  └─ Access debug info (/debug/*)

uploader (level 2) - Inherits all viewer permissions PLUS:
  ├─ Upload files (/db/upload_form.php)
  └─ Upload via API (/api/uploads.php)

admin (level 3) - Inherits all uploader permissions PLUS:
  ├─ Admin upload form (/db/upload_form_admin.php)
  ├─ Change passwords (/changethepasswords.php)
  └─ Manage users (future: /admin/users.php)
```

---

## Implementation Checklist

### Phase 1: Database & Core Auth

- [ ] Create `users` table with roles (admin, uploader, viewer)
- [ ] Create `sessions` table (optional for tracking)
- [ ] Implement JWT generation/validation functions (`auth/jwt.php`)
- [ ] Create login API endpoint (`api/login.php`)
- [ ] Create login page (`auth/login.html`)
- [ ] Create role checking functions (`requireRole()`, `hasRole()`)

### Phase 2: Protect PHP Pages

- [ ] `/db/database.php` - Add `requireRole('viewer')`
- [ ] `/db/list.php` - Add `requireRole('viewer')`
- [ ] `/db/upload_form.php` - Add `requireRole('uploader')`
- [ ] `/db/upload_form_admin.php` - Add `requireRole('admin')`
- [ ] `/api/uploads.php` - Add `requireRole('uploader')`
- [ ] `/changethepasswords.php` - Replace with JWT-based user management

### Phase 3: Media File Protection

**Option A**: Keep Apache Basic Auth for media files (simplest)
- Media files continue using Apache auth
- PHP pages use JWT

**Option B**: PHP-based media proxy (enables Cloudflare caching)
- Create `/media.php?file=video/file.mp4&token=xyz`
- Validate JWT token
- Stream file with proper headers
- Enables signed URLs for Cloudflare

### Phase 4: Remove Apache Basic Auth

- [ ] Remove `AuthType Basic` directives from `default-ssl.conf.j2`
- [ ] Keep `Require all denied` for sensitive paths
- [ ] Update documentation
- [ ] Test all access patterns

### Phase 5: iOS App Integration

- [ ] Add login screen to iOS app
- [ ] Store JWT in Keychain
- [ ] Send JWT in `Authorization: Bearer` header
- [ ] Decode JWT to determine user role/capabilities
- [ ] Show/hide upload feature based on role

---

## Migration Strategy

### Transition Period (Dual Auth)

1. **Deploy JWT system** alongside existing Apache Basic Auth
2. **Test thoroughly** with both auth methods active
3. **Migrate users** from `.htpasswd` to `users` table
4. **Verify all functionality** works with JWT
5. **Remove Apache Basic Auth** from configuration
6. **Update documentation** and deployment guides

### User Migration Script

```sql
-- Migrate existing htpasswd users to database
-- Passwords will need to be reset (can't extract from bcrypt hash)

INSERT INTO users (username, password_hash, role) VALUES
('admin', '$2y$10$...', 'admin'),      -- Set new password
('uploader', '$2y$10$...', 'uploader'), -- Set new password
('viewer', '$2y$10$...', 'viewer');     -- Set new password
```

---

## Security Considerations

### Keep Apache-Level Protection For:

1. **Sensitive source files** (`/src/*`, `/vendor/*`, `/app/*`)
2. **Configuration files** (`composer.json`, `config.php`)
3. **Hidden files** (`.htpasswd`, `.env`)
4. **Cache directories** (`/app/cache`)

**Reason**: Defense in depth - even if PHP auth fails, Apache blocks access

### Move to JWT For:

1. **User-facing pages** (database viewer, upload forms)
2. **API endpoints** (upload API, future REST API)
3. **Media files** (if implementing signed URLs)

**Reason**: Enables fine-grained role control, API access, and Cloudflare caching

---

## Testing Matrix

| User Role | Path | Expected Result |
|-----------|------|----------------|
| **No Auth** | `/` | ✅ Access granted |
| **No Auth** | `/db/database.php` | ❌ Redirect to login |
| **viewer** | `/db/database.php` | ✅ Access granted |
| **viewer** | `/db/upload_form.php` | ❌ 403 Forbidden |
| **uploader** | `/db/database.php` | ✅ Access granted |
| **uploader** | `/db/upload_form.php` | ✅ Access granted |
| **uploader** | `/db/upload_form_admin.php` | ❌ 403 Forbidden |
| **admin** | `/db/upload_form_admin.php` | ✅ Access granted |
| **admin** | `/changethepasswords.php` | ✅ Access granted |
| **Expired JWT** | Any protected path | ❌ Redirect to login |

---

## Files to Create/Modify

### New Files
- `auth/jwt.php` - JWT generation/validation
- `auth/config.php` - Auth configuration
- `auth/login.html` - Login page
- `auth/check.js` - Client-side auth check
- `api/login.php` - Login endpoint
- `api/verify.php` - Token verification endpoint
- `docs/codingChanges/JWT_AUTH_MIGRATION_MAPPING.md` - This file

### Modified Files
- `db/database.php` - Add `requireRole('viewer')`
- `db/list.php` - Add `requireRole('viewer')`
- `db/upload_form.php` - Add `requireRole('uploader')`
- `db/upload_form_admin.php` - Add `requireRole('admin')`
- `api/uploads.php` - Add `requireRole('uploader')`
- `changethepasswords.php` - Convert to JWT-based user management
- `ansible/roles/docker/templates/default-ssl.conf.j2` - Remove Basic Auth (Phase 4)
- `ansible/roles/docker/files/mysql/dbScripts/migrations/001_add_auth_tables.sql` - New migration

---

## Next Steps

1. Review this mapping for accuracy
2. Confirm role assignments match intended access control
3. Decide on media file protection strategy (Option A or B)
4. Begin Phase 1 implementation
5. Create test plan based on testing matrix

---

**Questions to Answer:**

1. Should media files (`/video/*`, `/audio/*`) use JWT or stay with Apache auth?
2. Do we need session tracking in database or is stateless JWT sufficient?
3. Should we implement token refresh mechanism or rely on 30-day expiration?
4. Do we want to add audit logging for authentication events?
