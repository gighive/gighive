# Windows Installer: install.ps1 (PowerShell)

## Decision Summary

**Chosen format:** PowerShell script (`.ps1`) rendered from a Jinja2 template (`install.ps1.j2`), plus a thin `.bat` launcher to bypass execution policy.

**Why not .msi / .exe / NSIS:**
- Docker Desktop for Windows exposes `docker` and `docker compose` as standard CLI tools — same commands as Linux
- PowerShell is built into Win 10/11 — zero build tooling, fully auditable
- Jinja2 baked-in vars carry over unchanged
- Maintenance stays in sync with `install.sh.j2` — same logic surface

---

## Parallel Construct Map

| `install.sh.j2` | `install.ps1.j2` equivalent |
|---|---|
| `set -euo pipefail` | `$ErrorActionPreference = 'Stop'` + `Set-StrictMode -Version Latest` |
| `require_cmd docker` | `Get-Command docker -ErrorAction Stop` |
| `read -r -p "..."` | `Read-Host -Prompt "..."` |
| `read -r -s -p "..."` (secret) | `Read-Host -AsSecureString` → `[Runtime.InteropServices.Marshal]::PtrToStringAuto(...)` |
| `mkdir -p` | `New-Item -ItemType Directory -Force` |
| `/proc/sys/kernel/random/uuid` | `[System.Guid]::NewGuid().ToString()` |
| `cat VERSION` | `Get-Content VERSION -Raw -ErrorAction SilentlyContinue` |
| `mktemp` | not used — `_PatchEnvKey` uses read-all/write-all instead (see Issue #5) |
| `export VAR=val` | `$env:VAR = "val"` |
| `_patch_env_key KEY val FILE` | `function _PatchEnvKey($Key,$Value,$File)` using `ForEach-Object` pipeline |
| `cat > FILE <<EOF ... EOF` | `[System.IO.File]::WriteAllText($File, $content, UTF8-no-BOM)` (see Issue #6) |
| `docker run --rm httpd:2.4 htpasswd` | identical — Docker Desktop handles it |
| `"${DOCKER_COMPOSE[@]}" up -d --build` | `Invoke-Compose up -d --build` (see Issue #7) |
| `curl --silent -d payload URL` | `curl.exe` explicitly — `curl` in PS is an alias for `Invoke-WebRequest` (see Issue #1) |
| All `{{ jinja2 }}` expressions | identical — render identically at bundle-build time |

---

## Jinja2 Baked-In Variables (Unchanged from install.sh.j2)

| PS variable | Jinja2 source | Typical value |
|---|---|---|
| `$_trackingEnabled` | `gighive_enable_installation_tracking` | `true` |
| `$_trackingEndpoint` | `gighive_installation_tracking_endpoint` | `https://telemetry.gighive.app` |
| `$_trackingTimeout` | `gighive_installation_tracking_timeout_seconds` | `3` |
| `$_appFlavor` | `app_flavor` | `gighive` |
| `$_installChannel` | hard-coded `"quickstart"` | `quickstart` |
| `$_installMethod` | hard-coded `"docker"` | `docker` |

---

## Execution Policy: The .bat Launcher

Execution policy is the only meaningful difference from Linux. Solution: ship a thin `.bat` alongside the `.ps1` in the bundle root.

**`Install-GigHive.bat`** (static file, no Jinja2 needed):
```bat
@echo off
powershell.exe -ExecutionPolicy Bypass -NoProfile -File "%~dp0install.ps1" %*
pause
```

- Users can double-click it — no policy changes required on their system
- Power users can still run `install.ps1` directly if they have `RemoteSigned` already set
- `%*` passes through any CLI arguments unchanged

---

## Password Handling (SecureString)

PowerShell's `Read-Host -AsSecureString` returns a `SecureString`. To pass the value to Docker CLI args it must be temporarily converted to plaintext in memory — same security posture as bash `read -r -s`.

**Note:** The BSTR allocated by `SecureStringToBSTR` must be freed with `ZeroFreeBSTR` immediately after use to prevent plaintext lingering in unmanaged memory (Issue #4). See the corrected implementation in Step 1d of the Implementation Plan.

---

## Logic Errors and Corrections

Found by cross-referencing `install.sh.j2` line-by-line against PowerShell runtime behavior.
Items are grouped by severity.

---

### Critical — Will Cause Incorrect Runtime Behavior

**1. `curl` alias in PowerShell shadows `curl.exe`**

In PowerShell, `curl` is a built-in alias for `Invoke-WebRequest`, not the `curl.exe` binary.
Writing `curl --silent -d $payload $endpoint` in the `.ps1` would invoke `Invoke-WebRequest`
with bash-style flags it does not understand — silently producing a different result or throwing.

**Fix:** Always write `curl.exe` (with the `.exe` suffix) in the telemetry block to force the
real Win32 binary, exactly matching the flag signature used in `install.sh.j2`.
The doc entry "curl.exe (ships with Win 10+) or Invoke-RestMethod" must be resolved to one
canonical choice — `curl.exe` is preferred for flag parity.

---

**2. `$ErrorActionPreference = 'Stop'` does NOT trap non-zero exit codes from external commands**

Bash `set -e` aborts on any non-zero exit. PowerShell's `$ErrorActionPreference = 'Stop'` only
applies to cmdlet/function terminating errors, NOT external process exit codes (`$LASTEXITCODE`).
If `docker compose up -d --build` fails with exit 1, the script will **continue to the
`_send_telemetry install_success` call** and report a false success.

**Fix:** After every Docker call that must succeed, add an explicit guard:
```powershell
& docker compose up -d --build
if ($LASTEXITCODE -ne 0) { throw "docker compose up failed (exit $LASTEXITCODE)" }
```
Same pattern needed after the htpasswd `docker run` block.
The construct map entry `set -euo pipefail → $ErrorActionPreference + Set-StrictMode` is
**incomplete** — it must also include explicit `$LASTEXITCODE` checks after each external command.

---

**3. Volume mount path in `docker run` htpasswd block passes Windows backslashes**

`$PWD` in PowerShell returns a `PathInfo` object; `.Path` yields `C:\Users\...\apache\externalConfigs`.
Passing this to `docker run -v C:\...\apache\externalConfigs:/work` will fail or be misinterpreted
depending on the Docker Desktop backend (WSL2 vs Hyper-V).

**Fix:** Normalize before passing to `-v`:
```powershell
$mountPath = (Resolve-Path "apache/externalConfigs").Path.Replace('\', '/')
# Results in: C:/Users/.../apache/externalConfigs
& docker run --rm -v "${mountPath}:/work" ...
```
This must also apply to `GIGHIVE_AUDIO_DIR` and `GIGHIVE_VIDEO_DIR` if those paths are passed
as bind mounts in `docker-compose.yml`.

---

### Important — Security or Data Correctness Concerns

**4. `SecureStringToBSTR` allocates unmanaged memory that is never freed**

The `Prompt-Secret` function calls `SecureStringToBSTR` which allocates a BSTR in unmanaged
memory containing the plaintext password. The current plan never calls `ZeroFreeBSTR` to clear
and release it. The plaintext lingers in the process memory until GC eventually reclaims it.

**Fix:** Zero-free the BSTR pointer immediately after extracting the string:
```powershell
$bstr1 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($ss1)
$v1 = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr1)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr1)
```
Same for `$bstr2` / `$v2`.

---

**5. `_PatchEnvKey` temp file may be on a different drive than the `.env` file**

`[System.IO.Path]::GetTempFileName()` creates the temp file in `%TEMP%` (typically `C:\Users\...\AppData\Local\Temp`).
If the bundle is extracted to a different drive (e.g., `D:\gighive`), `Move-Item` must do a
cross-drive copy+delete instead of an atomic rename — and will throw if the destination is locked.

**Fix:** Create the temp file in the same directory as the target `.env`:
```powershell
$tmp = [System.IO.Path]::Combine([System.IO.Path]::GetDirectoryName($File),
                                   [System.IO.Path]::GetRandomFileName())
```

---

**6. Line endings: PS here-strings write CRLF; Docker containers expect LF in `.env` files**

PowerShell here-strings (`@"..."@`) on Windows write CRLF (`\r\n`). The `.env.mysql` file written
from a here-string, and lines written by `_PatchEnvKey`, will have CRLF if `Set-Content` is used
with default encoding. MySQL and the Apache container parse these on Linux — a trailing `\r` on
`MYSQL_PASSWORD=secret\r` will cause MySQL auth failures.

**Fix:** Use `[System.IO.File]::WriteAllText($path, $content, [System.Text.Encoding]::UTF8)` for the
`.env.mysql` write, with explicit `\n` line endings (not `\r\n`). For `_PatchEnvKey`, accumulate
lines as an array and write with `[System.IO.File]::WriteAllLines($path, $lines, (New-Object System.Text.UTF8Encoding $false))`
which writes LF-only on all platforms.

---

### Design Gaps — Must Be Resolved Before Phase 1

**7. `docker compose` vs `docker-compose` fallback: bash array trick has no PS equivalent**

The bash script stores the compose command as an array: `DOCKER_COMPOSE=(docker compose)` or
`DOCKER_COMPOSE=(docker-compose)`, then invokes with `"${DOCKER_COMPOSE[@]}"`.
PowerShell has no equivalent of a command-fragment array. The implementation plan does not
define how the fallback is stored and invoked.

**Proposed design:**
```powershell
$useComposePlugin = $false
& docker compose version 2>$null | Out-Null
if ($LASTEXITCODE -eq 0) { $useComposePlugin = $true }

function Invoke-Compose {
    if ($useComposePlugin) { & docker compose @args }
    else                   { & docker-compose @args }
    # caller must check $LASTEXITCODE
}
```
Then call `Invoke-Compose up -d --build` etc. throughout.

---

**8. `VERSION` file needs `.Trim()` — `Get-Content -Raw` preserves trailing newline**

The bash version pipes through `tr -d '[:space:]'` to strip all whitespace including the trailing
newline. `Get-Content VERSION -Raw` preserves the newline. Without `.Trim()`, `$_appVersion`
embeds a newline inside the JSON telemetry payload, producing malformed JSON.

**Fix:** `$_appVersion = (Get-Content VERSION -Raw -ErrorAction SilentlyContinue).Trim()`

---

**9. `rm -f gighive.htpasswd` before `docker run` not called out in plan**

`install.sh.j2` line 185: `rm -f "${HTPASSWD_HOST_FILE}"` removes any stale htpasswd before
regenerating it. The implementation plan's Phase 1 step 6 ("translate htpasswd generation block")
does not explicitly call this out. Without it, `htpasswd -bc` (which truncates anyway) is fine,
but the explicit delete-first pattern should be preserved for consistency:
```powershell
Remove-Item $htpasswdFile -Force -ErrorAction SilentlyContinue
```

---

**10. `.bat` launcher `%*` does not properly quote space-containing paths**

`%*` in a `.bat` file passes all arguments as-is without re-quoting. If a user runs
`Install-GigHive.bat --audio-dir "C:\My Music Files"`, the space causes argument splitting.
This matches the existing limitation in the bash installer (path args are accepted but
passwords reject CLI args). It should be documented as a known limitation, not implied as
"passes through CLI arguments unchanged."

---

**11. `Set-StrictMode -Version Latest` is stricter than bash `set -u`**

Bash `set -u` errors on unset variables. PS `Set-StrictMode -Version Latest` additionally
errors on: calling methods on `$null`, using uninitialized properties, and several other
patterns. Combined with `$ErrorActionPreference = 'Stop'` this can cause unexpected aborts.
All script-level variables must be explicitly initialized to `""` or `$null` before use —
the same discipline as the bash defaults (`SITE_URL="${SITE_URL:-}"` etc.) but more rigorously
enforced at runtime.

---

## Implementation Plan

All steps incorporate the fixes from **Logic Errors and Corrections** above.
Issue numbers are cited inline so each step is traceable to its root cause.

---

### Phase 1 — Create `install.ps1.j2` template
**File:** `ansible/roles/docker/templates/install.ps1.j2`

#### 1a. Script header and error mode
```powershell
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
```
- `$ErrorActionPreference = 'Stop'` handles cmdlet errors only — **NOT** external command failures.
  Every `docker` / `curl.exe` call must be followed by an explicit `$LASTEXITCODE` guard (Issue #2).
- `Set-StrictMode -Version Latest` is stricter than `set -u`; all script-level variables must be
  explicitly initialized before use (Issue #11). Initialize every variable to `""` or `$false`
  at the top of the script before argument parsing, mirroring the bash defaults block.

#### 1b. Argument parsing and defaults
Translate the bash `while [[ $# -gt 0 ]]` block using a `switch` on `$args` or `param()`.
Initialize all vars explicitly first. Use PS 5.1-compatible `if/else` — the `??` null-coalescing operator requires PS 7+ and is **not** available in the Windows built-in PS 5.1 (Issue #A):
```powershell
$siteUrl           = if ($env:SITE_URL)       { $env:SITE_URL }       else { "" }
$audioDir          = if ($env:AUDIO_DIR)      { $env:AUDIO_DIR }      else { "./_host_audio" }
$videoDir          = if ($env:VIDEO_DIR)      { $env:VIDEO_DIR }      else { "./_host_video" }
$mysqlDatabase     = if ($env:MYSQL_DATABASE) { $env:MYSQL_DATABASE } else { "music_db" }
$mysqlUser         = if ($env:MYSQL_USER)     { $env:MYSQL_USER }     else { "appuser" }
$mysqlPassword     = ""
$mysqlRootPassword = ""
$adminPassword     = ""
$uploaderPassword  = ""
$viewerPassword    = ""
$tz                = if ($env:TZ)             { $env:TZ }             else { "America/New_York" }
$mysqlDataset      = if ($env:MYSQL_DATASET)  { $env:MYSQL_DATASET }  else { "sample" }
$nonInteractive    = $false
```

#### 1c. `Invoke-Compose` helper — resolves Issues #7 and #D
**Must execute after the docker prereq check in step 1f** (1f catches missing docker with a clean error; running `docker compose version` before that gives cryptic output).
```powershell
$_useComposePlugin = $false
& docker compose version 2>$null | Out-Null
if ($LASTEXITCODE -eq 0) {
    $_useComposePlugin = $true
} elseif (-not (Get-Command docker-compose -ErrorAction SilentlyContinue)) {
    throw "Missing Docker Compose (need 'docker compose' plugin or 'docker-compose' binary)"
}

function Invoke-Compose {
    if ($_useComposePlugin) { & docker compose @args }
    else                    { & docker-compose @args }
    if ($LASTEXITCODE -ne 0) { throw "Compose command failed (exit $LASTEXITCODE)" }
}
```

#### 1d. `Prompt` and `Prompt-Secret` functions

**`Prompt`** — plain `Read-Host`, skip if already set (same contract as bash `prompt()`):
```powershell
function Prompt-Value($VarName, [ref]$Var, $PromptText) {
    if ($Var.Value -ne "") { return }
    $Var.Value = Read-Host -Prompt $PromptText
    if ($Var.Value -eq "") { throw "Value required: $VarName" }
}
```

**`Prompt-Secret`** — resolves Issue #4 (ZeroFreeBSTR):
```powershell
function Prompt-Secret($Label, $MinLen) {
    for ($i = 0; $i -lt 5; $i++) {
        $ss1 = Read-Host -Prompt $Label -AsSecureString
        $ss2 = Read-Host -Prompt "$Label (confirm)" -AsSecureString
        $bstr1 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($ss1)
        $bstr2 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($ss2)
        $v1 = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr1)
        $v2 = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr2)
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr1)
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr2)
        if ($v1 -eq "") { Write-Host "Value required."; $v1 = ""; $v2 = ""; continue }
        if ($v1 -ne $v2) { Write-Host "Values did not match."; $v1 = ""; $v2 = ""; continue }
        if ($v1.Length -lt $MinLen) { Write-Host "Must be >= $MinLen chars."; $v1 = ""; $v2 = ""; continue }
        return $v1
    }
    throw "Too many attempts for $Label"
}
```

#### 1e. `_PatchEnvKey` function — resolves Issues #5 and #6
```powershell
function _PatchEnvKey($Key, $Value, $File) {
    $lines = [System.IO.File]::ReadAllLines($File)
    # Use ForEach-Object pipeline — 'foreach' statement does NOT return output (Issue #B)
    $out = $lines | ForEach-Object {
        if ($_ -match "^${Key}=") { "${Key}=${Value}" } else { $_ }
    }
    # Write with LF line endings (UTF-8 no BOM) — Issue #6
    $enc = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllLines($File, $out, $enc)
}
```
Temp file eliminated — read-all / write-all avoids the cross-drive atomicity issue (Issue #5).

#### 1f. Prereq checks
**Execute this block before step 1c** — docker must be confirmed present and running before `docker compose version` is attempted.

The bash `install.sh.j2` only checks that the `docker` binary exists. Windows needs three additional checks because Docker Desktop is a user-launched application that can be installed but not started, `curl.exe` may be absent on older Win 10 builds, and the script uses PS 5.1 syntax that will silently misbehave on PS 4.

```powershell
# 1. PowerShell version — script requires PS 5.1+; fail clearly rather than misbehave
if ($PSVersionTable.PSVersion.Major -lt 5 -or
    ($PSVersionTable.PSVersion.Major -eq 5 -and $PSVersionTable.PSVersion.Minor -lt 1)) {
    throw "PowerShell 5.1 or later is required. Current: $($PSVersionTable.PSVersion)"
}

# 2. Docker CLI installed
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw "Docker is not installed or not on PATH.`nInstall Docker Desktop from https://www.docker.com/products/docker-desktop/"
}

# 3. Docker daemon is actually running — critical on Windows where Docker Desktop must be
#    manually started. 'docker info' exits non-zero when the daemon is unreachable.
Write-Host "Checking Docker daemon..."
& docker info 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw "Docker daemon is not running. Please start Docker Desktop and try again."
}

# 4. docker-compose.yml present in working directory
if (-not (Test-Path docker-compose.yml)) {
    throw "Run this from the directory containing docker-compose.yml"
}

# 5. curl.exe available — used for telemetry (best-effort, non-fatal warn only)
if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) {
    Write-Warning "curl.exe not found — installation telemetry will be skipped. Upgrade to Windows 10 1803+ to enable it."
    $script:_curlAvailable = $false
} else {
    $script:_curlAvailable = $true
}

# Compose detection follows in 1c
```

**Notes:**
- Check #3 (`docker info`) is the most important Windows-specific addition — it catches the "installed but not started" case with a user-friendly message instead of a mid-run failure inside the htpasswd `docker run` block
- Check #5 sets `$script:_curlAvailable` which `_SendTelemetry` (step 1k) must honour — if `$false`, skip the `curl.exe` call rather than throwing
- Update `_SendTelemetry` (step 1k) accordingly:
```powershell
function _SendTelemetry($EventName) {
    if ($_trackingEnabled -ne "true") { return }
    if (-not $script:_curlAvailable)  { return }
    ...
}
```

#### 1g. Prompt for inputs (same order as install.sh.j2)
```powershell
Prompt-Value "SITE_URL" ([ref]$siteUrl)  "SITE_URL, use IP of local machine (example: https://192.168.1.252)"
Prompt-Value "AUDIO_DIR" ([ref]$audioDir) "Host path for audio dir (will be created if missing)"
Prompt-Value "VIDEO_DIR" ([ref]$videoDir) "Host path for video dir (will be created if missing)"
$adminPassword     = Prompt-Secret "BasicAuth password for user 'admin'"    12
$uploaderPassword  = Prompt-Secret "BasicAuth password for user 'uploader'" 12
$viewerPassword    = Prompt-Secret "BasicAuth password for user 'viewer'"   12
$mysqlPassword     = Prompt-Secret "MYSQL_PASSWORD"                         12
$mysqlRootPassword = Prompt-Secret "MYSQL_ROOT_PASSWORD"                    12
```

#### 1h. Create directories and config dirs
```powershell
New-Item -ItemType Directory -Force -Path $audioDir | Out-Null
New-Item -ItemType Directory -Force -Path $videoDir | Out-Null
New-Item -ItemType Directory -Force -Path "apache/externalConfigs" | Out-Null
New-Item -ItemType Directory -Force -Path "mysql/externalConfigs"  | Out-Null
```

#### 1i. htpasswd generation — resolves Issues #2 and #3
```powershell
$htpasswdFile = "apache/externalConfigs/gighive.htpasswd"
Remove-Item $htpasswdFile -Force -ErrorAction SilentlyContinue   # Issue #9

# Normalize path for docker -v (Issue #3)
$mountPath = (Resolve-Path "apache/externalConfigs").Path.Replace('\', '/')

& docker run --rm `
    -e "ADMIN_PASSWORD=$adminPassword" `
    -e "UPLOADER_PASSWORD=$uploaderPassword" `
    -e "VIEWER_PASSWORD=$viewerPassword" `
    -v "${mountPath}:/work" `
    httpd:2.4 sh -lc '
        set -e
        /usr/local/apache2/bin/htpasswd -bc /work/gighive.htpasswd admin "$ADMIN_PASSWORD"
        /usr/local/apache2/bin/htpasswd -b  /work/gighive.htpasswd uploader "$UPLOADER_PASSWORD"
        /usr/local/apache2/bin/htpasswd -b  /work/gighive.htpasswd viewer "$VIEWER_PASSWORD"
        chown 33:33 /work/gighive.htpasswd
        chmod 640   /work/gighive.htpasswd
    '
if ($LASTEXITCODE -ne 0) { throw "htpasswd docker run failed (exit $LASTEXITCODE)" }  # Issue #2
```

#### 1j. Patch `.env` and write `.env.mysql` — resolves Issues #6 and #8
```powershell
$apacheEnvFile = "apache/externalConfigs/.env"
$mysqlEnvFile  = "mysql/externalConfigs/.env.mysql"

_PatchEnvKey "SITE_URL"            $siteUrl            $apacheEnvFile
_PatchEnvKey "MYSQL_PASSWORD"      $mysqlPassword      $apacheEnvFile
_PatchEnvKey "MYSQL_ROOT_PASSWORD" $mysqlRootPassword  $apacheEnvFile

# Write .env.mysql with LF line endings (Issue #6)
$mysqlEnvContent = "MYSQL_ROOT_PASSWORD=$mysqlRootPassword`nMYSQL_DATABASE=$mysqlDatabase`nMYSQL_USER=$mysqlUser`nMYSQL_PASSWORD=$mysqlPassword`nDB_HOST=mysqlServer`nMYSQL_ROOT_HOST=%`n"
$enc = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllText($mysqlEnvFile, $mysqlEnvContent, $enc)
```
Note: PS backtick-n (`` `n ``) produces `\n` (LF), not `\r\n`.

#### 1k. Jinja2 baked-in vars and telemetry — resolves Issues #1 and #8
Copy Jinja2 expressions verbatim from `install.sh.j2` lines 235–242, translated to PS:
```powershell
$_trackingEnabled = "{{ (gighive_enable_installation_tracking | default(true)) | ternary('true','false') }}"
$_trackingEndpoint = "{{ gighive_installation_tracking_endpoint | default('https://telemetry.gighive.app') }}"
$_trackingTimeout = {{ gighive_installation_tracking_timeout_seconds | default(3) }}
$_versionRaw = Get-Content VERSION -Raw -ErrorAction SilentlyContinue
$_appVersion = if ($_versionRaw) { $_versionRaw.Trim() } else { "" }   # Issue #8 + null-safe
$_installId  = [System.Guid]::NewGuid().ToString()
$_installChannel = "quickstart"
$_installMethod  = "docker"
$_appFlavor = "{{ app_flavor | default('gighive') }}"
_PatchEnvKey "GIGHIVE_INSTALL_CHANNEL" $_installChannel $apacheEnvFile

function _SendTelemetry($EventName) {
    if ($_trackingEnabled -ne "true") { return }
    $ts = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    $payload = "{`"event_name`":`"$EventName`",`"app_version`":`"$_appVersion`",`"install_channel`":`"$_installChannel`",`"install_method`":`"$_installMethod`",`"app_flavor`":`"$_appFlavor`",`"timestamp`":`"$ts`",`"install_id`":`"$_installId`"}"
    # Use curl.exe explicitly — 'curl' in PS is an alias for Invoke-WebRequest (Issue #1)
    curl.exe --silent --max-time $_trackingTimeout -H "Content-Type: application/json" -d $payload $_trackingEndpoint 2>$null
    # Telemetry is best-effort; do not check $LASTEXITCODE here (matches bash `|| true`)
}
```

#### 1l. Export env vars and run compose — resolves Issue #2
```powershell
_SendTelemetry "install_attempt"

$env:GIGHIVE_AUDIO_DIR = $audioDir
$env:GIGHIVE_VIDEO_DIR = $videoDir

Write-Host "Bringing stack up..."
Invoke-Compose up -d --build   # throws on non-zero exit (Issue #2 handled inside Invoke-Compose)

_SendTelemetry "install_success"

Write-Host "Done."
Write-Host "Next checks:"
Write-Host "  - docker compose ps"
Write-Host "  - docker compose logs -n 200 mysqlServer"
Write-Host "  - docker compose logs -n 200 apacheWebServer"
```

---

### Phase 2 — Create `Install-GigHive.bat` static launcher
**File:** `ansible/roles/docker/files/Install-GigHive.bat`

```bat
@echo off
powershell.exe -ExecutionPolicy Bypass -NoProfile -File "%~dp0install.ps1" %*
pause
```

**Known limitation (Issue #10):** `%*` does not re-quote arguments containing spaces.
Document in README: users with space-containing paths should run `install.ps1` directly
from a PowerShell terminal rather than passing path args via the `.bat` file.

**Known limitation (Issue #11): `.bat` execution blocked on some Windows configurations.**
Running `.\Install-GigHive.bat` from a PowerShell prompt may produce:

> Failed to run: Access is denied. (ApplicationFailedException / NativeCommandFailed)

Root cause (confirmed): **Software Restriction Policies (SRP) stamp DENY Execute ACEs** onto downloaded `.bat`/`.cmd` files. `Unblock-File` removes the Zone.Identifier alternate data stream but does NOT remove these DENY ACEs. `icacls /remove:d` with trustee names also fails because SRP protects the ACEs.

Diagnostics that confirmed this:
- `icacls .\Install-GigHive.bat` showed `BUILTIN\Administrators:(DENY)(S,X)` and `NT AUTHORITY\SYSTEM:(DENY)(S,X)`
- `reg query "HKLM\Software\Policies\Microsoft\Windows\Safer\CodeIdentifiers"` showed SRP active (`authenticodeenabled REG_DWORD 0x0`)
- A freshly created `test.bat` ran fine (no DENY ACEs) — confirming the block is file-specific, not directory-wide

**Fix (confirmed working):**
```powershell
icacls .\Install-GigHive.bat /reset
.\Install-GigHive.bat
```

`/reset` restores inherited-only permissions, removing the SRP-stamped DENY ACEs. Run once after extracting the bundle.

**Alternative — direct PS execution (no fix needed):**
```powershell
.\install.ps1
```

The `.ps1` is not targeted by SRP's script-blocking rules and runs without any ACL manipulation.

---

### Phase 3 — Wire into one_shot_bundle role
**File:** `ansible/roles/one_shot_bundle/tasks/main.yml` (alongside existing `install.sh` render task)

Add:
1. `ansible.builtin.template` — render `install.ps1.j2` → `install.ps1` at bundle root, mode `0644`
2. `ansible.builtin.copy` — copy `Install-GigHive.bat` → bundle root, mode `0644`

---

### Phase 4 — Update bundle docs
**File:** `docs/process_one_shot_bundle_install_sh.md`

Add section "Windows Entry Points" covering:
- `install.ps1` is the Windows counterpart to `install.sh`, rendered from `install.ps1.j2`
- `Install-GigHive.bat` is the double-click launcher; known limitation with space-containing paths
- Both are assembled in the same bundle step as `install.sh`

---

## Windows-Specific Gaps / Known Differences

| Gap | Notes |
|---|---|
| Path separators | Docker Desktop accepts forward slashes; PS paths with backslashes must be normalized before passing to `docker run -v` |
| `chown 33:33` in htpasswd block | Not meaningful on Windows host; the `docker run` still sets ownership inside the container — harmless |
| Line endings | `Set-Content` defaults to CRLF; `.env` files must be written with `-NoNewline` and explicit `\n` to stay Linux-compatible inside the container |
| WSL2 vs Hyper-V backend | No installer difference — both expose the same `docker` CLI |
| UUID fallback | `/proc/sys/kernel/random/uuid` is Linux-only; PS uses `[System.Guid]::NewGuid()` — no fallback chain needed |
| `date -u` | Replace with `(Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')` |

---

## Files Created / Modified by This Feature

| Action | Path |
|---|---|
| **Create** | `ansible/roles/docker/templates/install.ps1.j2` |
| **Create** | `ansible/roles/docker/files/Install-GigHive.bat` |
| **Modify** | `ansible/roles/one_shot_bundle/tasks/main.yml` — add render + copy tasks |
| **Modify** | `docs/process_one_shot_bundle_install_sh.md` — add Windows entry point section |

---

## User Experience vs Linux Install

From the user's perspective the Windows install is **identical** to Linux — same prompts, same order, same browser URL afterward. The only visible difference is the launcher:

| Step | Linux | Windows |
|---|---|---|
| Extract bundle | same | same |
| Launch installer | `./install.sh` | double-click `Install-GigHive.bat` |
| Answer prompts | same questions, same order | same questions, same order |
| Open browser | same URL | same URL |

Execution policy is the only user-facing structural difference; the `.bat` launcher handles it transparently. All Windows-specific complexity (path normalization, PS 5.1 compatibility, `.NET` directory sync, secure password handling, daemon check, etc.) is encapsulated inside `install.ps1` and invisible to the user.

---

## Out of Scope

- GUI wizard / WinForms UI
- Auto-installing Docker Desktop (user prerequisite, same as Linux)
- Code-signing the `.ps1` (nice-to-have; the `.bat` launcher removes the practical need for Phase 1)
- Windows-native htpasswd (Docker-based generation is portable and already works)
