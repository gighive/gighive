# COMMON CONTENT REFERENCE

This file contains shared content that should be kept synchronized between `index.md` and `README.md`. When updating these sections, make sure to update both files consistently.

---

## SHARED SECTIONS TO MAINTAIN:

### 1. CORE DESCRIPTION
**Used in:** Both files (with slight variations)
**Content:** What GigHive is - a media database for musicians, fans, wedding guests

### 2. USE CASES
**Used in:** Both files
**Content:**
- **Musicians**: Library for band sessions, fan video uploads
- **Wedding photographers**: Guest media collection for compilation videos
- **Media librarians**: Historical media file management

### 3. REQUIREMENTS SECTION
**Used in:** Both files (IDENTICAL TEXT)
**Exact content to maintain:**
```
REQUIREMENTS
- Tested on Ubuntu 22.04, so the requirements are **any flavor of Ubuntu 22.04 or Pop-OS, installed on bare metal.**
- Your choice of virtualbox, Azure or bare metal deployment targets for the vm and containerized environment.
```

### 4. DEPLOYMENT OPTIONS
**Used in:** Both files
**Content:** Azure, VirtualBox, bare metal deployment flexibility

### 5. PHILOSOPHY/POSITIONING
**Used in:** Both files
**Content:**
- Alternative to Big Tech platforms (YouTube, etc.)
- Freedom from content limitations
- Do-it-yourself approach
- Master of your own destiny

### 6. SIMPLICITY THEME
**Used in:** Both files (with variations)
**Core message:** Simple interface with minimal components
- index.md: "one page for the media library and one upload utility..that's all"
- README.md: "a splash page, a single database of stored videos and an upload utility"

### 7. CONTACT INFORMATION
**Used in:** Both files (IDENTICAL TEXT)
**Exact content to maintain:**
```
👉 [Contact us](mailto:contactus@gighive.app) for commercial licensing or for any other questions regarding Gighive. <img src="images/beelogo.png" alt="GigHive bee mascot" style="height: 1em; vertical-align: middle;">
```

---

## MAINTENANCE GUIDELINES:

### When editing index.md:
- Check if changes affect sections marked as "SHARED" below
- If yes, apply same changes to corresponding sections in README.md

### When editing README.md:
- Check if changes affect sections marked as "SHARED" below  
- If yes, apply same changes to corresponding sections in index.md

### Section Mapping:

#### index.md → README.md
- "Gighive is a media database..." → Lines 7-9 in README.md
- "If you're a musician" section → Implied in README.md features
- "If you're a wedding photographer" → Implied in README.md features  
- "REQUIREMENTS" section → Lines 15-17 in README.md
- "Why not just use YouTube?" → Philosophy reflected in README.md intro

#### README.md → index.md
- Lines 7-9 (core description) → "Gighive is a media database..." in index.md
- Lines 15-17 (requirements) → "REQUIREMENTS" section in index.md
- Features section → Use cases in index.md

---

## CHANGE LOG:
- 2025-09-30: Initial creation of common content reference
- 2025-10-01: Added section 7 - Contact Information (shared contact line with email and bee logo)
- Add future changes here with dates

---

**NOTE:** This is a reference document only. The actual content lives in index.md and README.md and must be manually synchronized when changes are made to shared sections.
