# Problem: Admin Restore Status UI Does Not Refresh After Tab Backgrounding

## Symptom

After triggering a database restore via Admin → System & Recovery → Section B, the UI
remains stuck on "Restore Running…" even after the restore has completed successfully.
Refreshing the page or checking the log file from the command line confirms the restore
finished with `EXIT_CODE=0`.

## Root Cause

The restore status page polls `/db/restore_database_status.php` every 1.5 seconds via
`setInterval(tick, 1500)`. When the browser tab is moved to the background, modern browsers
(Chrome, Firefox) aggressively throttle background timers — the interval can fire as
infrequently as once per minute. If the restore completes while the tab is inactive, the
final `state: "ok"` response is never received in a reasonable time. When the user switches
back to the tab the interval has not yet fired since they returned, so the UI still shows
"Restore Running…".

## Workaround (before fix)

Check restore status from the command line on the server:

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

Added a `visibilitychange` listener to `admin_system.php` that fires an immediate status
poll when the tab regains focus, bypassing the throttled interval.

**File:** `ansible/roles/docker/files/apache/webroot/admin/admin_system.php`

Changes:
- Added `let __restorePollTick = null;` alongside the existing `__restorePollTimer`
- `pollRestoreLog()` stores `tick` into `__restorePollTick` at start and nulls it on
  completion (`ok`, `error`, or network failure)
- Added one `visibilitychange` listener at page scope:

```javascript
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && __restorePollTick) {
    __restorePollTick();
  }
});
```

**How it resolves the issue:** The `visibilitychange` event is not throttled — it fires
the instant the tab becomes active. The immediate `tick()` call hits the status endpoint,
reads the `.rc` file, and if the restore is done returns `state: "ok"`, causing the UI to
update immediately rather than waiting for the next sluggish interval tick.
