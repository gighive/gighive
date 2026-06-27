# Problem: Admin Restore Status UI Does Not Refresh After Tab Backgrounding

## Symptom

After triggering a database restore via Admin → System & Recovery → Section B, the UI
remains stuck on "Restore Running…" even after the restore has completed successfully.
Refreshing the page or checking the log file from the command line confirms the restore
finished with `EXIT_CODE=0`.

## Root Cause

**Cloudflare caching the GET polling response.**

The restore status page polls `/db/restore_database_status.php` (a GET request) every
1.5 seconds. The production domain (`www.stormpigs.com`) is proxied through Cloudflare.
The status endpoint did not emit a `Cache-Control: no-store` header, so Cloudflare cached
the first `state: "running"` response and served it to all subsequent browser polls —
even after the restore had completed and the server was returning `state: "ok"`.

The server-side restore itself completes correctly (confirmed via `EXIT_CODE=0` in the log
file and by curling the status endpoint directly from `localhost`, which bypasses
Cloudflare). The problem is exclusively in the browser → Cloudflare → origin path.

**Confirmed** by accessing the admin page via the LAN IP (`192.168.1.227`) instead of the
public hostname: bypassing Cloudflare allowed the browser to receive the real `state: "ok"`
response and the UI updated correctly.

Browser tab throttling (initially suspected) is **not** the cause — Chrome's minimal
throttling rules do not throttle timers on a visible foreground tab.

## Workaround (before fix)

Access the admin page via the server's **local IP address** to bypass Cloudflare, or check
restore status from the command line:

```bash
# View the full restore log
docker exec apacheWebServer cat \
  $(docker exec apacheWebServer printenv GIGHIVE_MYSQL_RESTORE_LOG_DIR)/restore-<JOB_ID>.log

# Check exit code — 0 = success, no output = still running
docker exec apacheWebServer cat \
  $(docker exec apacheWebServer printenv GIGHIVE_MYSQL_RESTORE_LOG_DIR)/restore-<JOB_ID>.rc
```

The Job ID is shown in the UI banner: `Restore started. Job: <JOB_ID>`.

## Fix

Added `Cache-Control: no-store` header to both polling endpoints so Cloudflare never
caches their responses.

**Files changed:**
- `ansible/roles/docker/files/apache/webroot/db/restore_database_status.php`
- `ansible/roles/docker/files/apache/webroot/db/run_backup_status.php`

```php
http_response_code(200);
header('Content-Type: application/json');
header('Cache-Control: no-store');   // ← added
echo json_encode([...]);
```

**Also added** (not the root cause fix, but a useful resilience improvement):

A `visibilitychange` listener in `admin_system.php` that fires an immediate status poll
when the browser tab regains focus, in case the timer has been throttled for any reason:

```javascript
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && __restorePollTick) {
    __restorePollTick();
  }
});
```
