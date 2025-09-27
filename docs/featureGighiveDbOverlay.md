# GigHive Database Overlay Feature

## Overview

This document describes the implementation plan for creating two versions of the database interface - a full version for stormpigs and a simplified version for gighive using the overlay system.

## Architecture

The system uses a **base + overlay** architecture where:
- **stormpigs** serves as the base application with full database functionality
- **gighive** uses an overlay to provide a simplified database interface

## Current Configuration

### App Flavors

| App Flavor | Config File | Database Fields | Use Case |
|------------|-------------|-----------------|----------|
| `stormpigs` | `ubuntu.yml` | Full (12 fields) | Complete media management |
| `gighive` | `gighive.yml` | Simplified (7 fields) | Mobile app, clean interface |

### Configuration Files

**ubuntu.yml (stormpigs)**:
```yaml
app_flavor: stormpigs
database_full: true
```

**gighive.yml (gighive)**:
```yaml
app_flavor: gighive
database_full: true  # Note: This will be simplified via overlay
```

## Database Fields Comparison

### Full Version (stormpigs)
Fields displayed in the database interface:
1. # (Index)
2. Date
3. Org (Organization)
4. **Rating** ⭐
5. **Keywords** ⭐
6. Duration
7. **Location** ⭐
8. **Summary** ⭐
9. **Crew** ⭐
10. Song Name
11. File Type
12. File (Download link)

### Simplified Version (gighive)
Fields displayed in the database interface:
1. # (Index)
2. Date
3. Org (Organization)
4. Duration
5. Song Name
6. File Type
7. File (Download link)

**Removed fields** (marked with ⭐): Rating, Keywords, Location, Summary, Crew

## Implementation Plan

### Phase 1: Verify Base Layer
- ✅ Confirm `/webroot/src/Controllers/MediaController.php` contains full version
- ✅ Confirm `/webroot/src/Views/media/list.php` contains full version
- ✅ Verify stormpigs deployment uses base files correctly

### Phase 2: Create Gighive Overlay
- Create simplified `MediaController.php` in `/overlays/gighive/src/Controllers/`
- Create simplified `list.php` view in `/overlays/gighive/src/Views/media/`
- Remove fields: Rating, Keywords, Location, Summary, Crew
- Adjust table layout and column indices for proper sorting

### Phase 3: Testing
- Test stormpigs deployment (should use base files)
- Test gighive deployment (should use base + overlay files)
- Verify field removal works correctly
- Confirm sorting and filtering still function

## File Structure

```
ansible/roles/docker/files/apache/
├── webroot/                                    # BASE LAYER (stormpigs)
│   ├── db/
│   │   └── database.php                        # ✅ NO CHANGES NEEDED (entry point only)
│   └── src/
│       ├── Controllers/
│       │   └── MediaController.php             # Full version (12 fields)
│       └── Views/
│           └── media/
│               └── list.php                    # Full version (12 fields)
└── overlays/
    └── gighive/                               # OVERLAY LAYER (gighive)
        └── src/
            ├── Controllers/
            │   └── MediaController.php         # Simplified version (7 fields)
            └── Views/
                └── media/
                    └── list.php                # Simplified version (7 fields)
```

## Important: Why database.php Doesn't Need Changes

The `db/database.php` file is a **thin entry point** that only:
1. Sets up database connection and dependencies
2. Creates MediaController instance  
3. Calls `$controller->list()` method
4. Outputs the HTTP response

It contains **no field selection or display logic**. The actual data processing and visualization happens in:
- **MediaController.php** - processes data and decides which fields to include
- **list.php** - renders HTML table with specific columns

This clean separation means we only need to create overlay versions of the two files that handle field logic.

## Deployment Flow

### Stormpigs Deployment
```
Source: Base webroot files only
Result: Full database interface with all 12 fields
Files Used:
- /webroot/src/Controllers/MediaController.php
- /webroot/src/Views/media/list.php
```

### Gighive Deployment
```
Source: Base webroot + gighive overlay
Result: Simplified database interface with 7 fields
Files Used:
- /webroot/src/Controllers/MediaController.php (BASE)
- /overlays/gighive/src/Controllers/MediaController.php (OVERRIDE)
- /webroot/src/Views/media/list.php (BASE)
- /overlays/gighive/src/Views/media/list.php (OVERRIDE)
```

## Benefits

1. **Clean Separation**: Each app flavor gets appropriate functionality
2. **Maintainable**: Base version remains untouched, overlays are isolated
3. **Flexible**: Easy to add more app flavors or modify existing ones
4. **Consistent**: Uses existing overlay and group_vars pattern
5. **Mobile Optimized**: Gighive gets streamlined interface suitable for mobile apps

## Technical Details

### MediaController Changes (Gighive)
- Remove data extraction for: rating, keywords, location, summary, crew
- Keep core functionality: media listing, duration formatting, URL generation
- Maintain compatibility with existing SessionRepository

### View Template Changes (Gighive)
- Remove table columns for excluded fields
- Adjust table width for fewer columns
- Update column indices in sorting JavaScript
- Maintain search and filter functionality for remaining fields

## Future Considerations

- Additional app flavors can be added using the same overlay pattern
- Individual field visibility could be made configurable via group_vars
- API endpoints could also be flavor-specific if needed
- Mobile-specific styling could be added to gighive overlay

## Status

- [ ] Phase 1: Verify Base Layer
- [ ] Phase 2: Create Gighive Overlay  
- [ ] Phase 3: Testing
- [ ] Documentation Complete

---

*Created: 2025-09-27*  
*Last Updated: 2025-09-27*
