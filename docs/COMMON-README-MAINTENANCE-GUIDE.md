# Documentation Maintenance Guide

## Overview
The `index.md` and `README.md` files share common content that must be kept synchronized manually. This guide explains how to maintain consistency.

## Files Involved
- **`index.md`** - User-facing marketing introduction
- **`README.md`** - Technical documentation for developers
- **`COMMON.md`** - Reference document listing shared content

## Shared Content Sections

### 1. Requirements Section (MUST BE IDENTICAL)
**Location in index.md:** Lines 29-31
**Location in README.md:** Lines 15-17
**Content:** Ubuntu 22.04 requirements and deployment options

### 2. Core Description (Similar but adapted)
**Location in index.md:** Line 5
**Location in README.md:** Lines 7-9
**Content:** What GigHive is and its purpose

### 3. Use Cases (Adapted for audience)
**Location in index.md:** Lines 7-16 (musician, wedding, librarian sections)
**Location in README.md:** Implied in features section
**Content:** Target user scenarios

## Maintenance Workflow

### When Editing index.md:
1. Make your changes
2. Check if any changes affect sections listed in `COMMON.md`
3. If yes, open `README.md` and apply corresponding changes
4. Update the change log in `COMMON.md`

### When Editing README.md:
1. Make your changes  
2. Check if any changes affect sections listed in `COMMON.md`
3. If yes, open `index.md` and apply corresponding changes
4. Update the change log in `COMMON.md`

## Quick Reference Checklist

Before committing changes to either file:
- [ ] Did I change the requirements section? → Update both files identically
- [ ] Did I change the core description? → Update both files (adapt for audience)
- [ ] Did I change use cases? → Update both files (adapt for audience)
- [ ] Did I update the change log in `COMMON.md`?

## Benefits of This Approach
- ✅ Simple - no build tools required
- ✅ Transparent - all content visible in source files
- ✅ Flexible - allows audience-specific adaptations
- ✅ Git-friendly - clear diff history

## Risks to Avoid
- ❌ Don't forget to check `COMMON.md` when making changes
- ❌ Don't let shared sections drift apart over time
- ❌ Don't make requirements section changes without updating both files
