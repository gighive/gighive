# Known Issues: One-Shot Bundle on Windows

## Issue 1 — `Install-GigHive.bat` blocked with "Access is denied"

### Symptom
Running `.\Install-GigHive.bat` from a PowerShell prompt produces:

> Program 'Install-GigHive.bat' failed to run: Access is denied.
> (ResourceUnavailable / NativeCommandFailed)

### Root Cause
Software Restriction Policies (SRP) — active by default on corporate/managed Windows
machines — stamp **DENY Execute ACEs** onto downloaded `.bat`/`.cmd` files.
`Unblock-File` removes the Zone.Identifier stream but does **not** remove these DENY ACEs.
`icacls /remove:d` with trustee names also fails because SRP protects them.

Confirmed diagnostics:
- `icacls .\Install-GigHive.bat` shows `BUILTIN\Administrators:(DENY)(S,X)` and `NT AUTHORITY\SYSTEM:(DENY)(S,X)`
- `reg query "HKLM\Software\Policies\Microsoft\Windows\Safer\CodeIdentifiers"` shows SRP active

### Fix
```powershell
icacls .\Install-GigHive.bat /reset
.\Install-GigHive.bat
```

`/reset` restores inherited-only permissions, removing the SRP-stamped DENY ACEs. One-time
operation after extracting the bundle.

### Alternative (no fix needed)
Run the installer directly from a PowerShell terminal — `.ps1` files are not targeted by
SRP's script-blocking rules:

```powershell
.\install.ps1
```

---

## Issue 2 — Media import hashing fails for files in OneDrive / cloud-synced folders

> See also: `docs/problem_admin_upload_unreadable_directory_error.md`

### Symptom
In the admin media import page, after selecting a folder the status shows:

> Hashing error: The requested file could not be read, typically due to permission
> problems that have occurred after a reference to a file was acquired.

Files are listed and counted correctly, but hashing fails.

### Root Cause
SHA-256 hashing runs **entirely client-side** in a browser Web Worker. Chrome throws
`NotReadableError` when the worker tries to read file bytes from:

- OneDrive, Google Drive, Dropbox, or any cloud-synced folder
- Network shares / UNC paths

This has nothing to do with Docker, the container, or the installer.

### Fix
Copy the media files to a plain local folder (e.g. `C:\Music\`) and select from there.
