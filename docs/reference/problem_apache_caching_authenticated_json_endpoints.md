# Problem: Apache Caches Authenticated JSON Polling Endpoints

## Date
2026-07-25

## Symptom
Long-running job progress counters on `admin_system.php` appeared frozen in the browser
(e.g. "2 / 661 uploaded") while the underlying worker was making real progress — confirmed
by observing files accumulating in Azure Blob Storage during an export job.

## Root Cause
Apache injects `Cache-Control: public, max-age=86400` on authenticated PHP responses that
do not explicitly set their own `Cache-Control` header.

The key diagnostic trap: curling the endpoint **without** credentials returns a `401`
response whose headers already include `Cache-Control: no-store` (Apache's default for
error responses). This hides the problem — you must test the **authenticated 200** response
to see the actual cache headers the browser receives.

Confirmed via Chrome DevTools → Network tab:
- Status showed **`200 OK (from disk cache)`**
- Response header: **`Cache-Control: public, max-age=86400`**

The browser cached the very first poll response (e.g. `2 / 661`) and served it from disk
cache for all subsequent polls — up to 24 hours — without ever hitting the server again.

## Affected Files
| File | Status |
|---|---|
| `admin/export_media_status.php` | Missing cache headers — **fixed** |
| `admin/import_media_zip_status.php` | Missing cache headers — **fixed** |
| `admin/run_backup_status.php` | Already had `Cache-Control: no-store` |
| `admin/restore_database_status.php` | Already had `Cache-Control: no-store` |

## Fix

### Server-side (each status endpoint)
Add explicit headers before every JSON response:

```php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');
```

### Client-side (`assets/import_progress.js` — `pollJobStatus`)
Add a timestamp cache-buster and `cache: 'no-store'` fetch option so the browser never
serves a stale response even if the server header slips through:

```js
fetch(statusUrl + '?job_id=' + encodeURIComponent(String(jobId)) + '&_ts=' + Date.now(), { cache: 'no-store' })
```

## Rule for New Polling Endpoints
Any PHP endpoint that is polled repeatedly by the browser (status, progress, heartbeat)
**must** include `Cache-Control: no-store` in its response headers. Do not rely on Apache
defaults — always set it explicitly in PHP.

## Debugging Checklist
1. Open Chrome DevTools → Network
2. Find a repeated poll request (e.g. `export_media_status.php?job_id=...`)
3. Check **Status Code** — if it says `(from disk cache)`, caching is the culprit
4. Check **Response headers** for `Cache-Control` value
5. Do **not** use unauthenticated `curl -I` to diagnose — it returns 401 headers which are
   always `no-store` and will mislead you
