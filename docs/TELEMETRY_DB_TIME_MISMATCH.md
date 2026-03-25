# Telemetry DB Time Mismatch

## Findings

The schema and query output point to a timezone mismatch.

- `event_timestamp` is `DATETIME`
- `created_at` is `TIMESTAMP DEFAULT CURRENT_TIMESTAMP`
- MySQL session/global timezone is:
  - `SYSTEM`
  - `SYSTEM`

## What that means

### `event_timestamp`

- `DATETIME` stores the literal wall-clock value that was inserted.
- MySQL does not timezone-convert it on storage or retrieval.

So if the app inserts `2026-03-24 18:30:16`, that exact value stays there.

### `created_at`

- `TIMESTAMP` is timezone-aware in MySQL.
- It is stored internally in UTC and converted using the session/server timezone when displayed.
- Since MySQL is using `SYSTEM`, it follows the container or server OS timezone.

So `created_at` is being rendered in the server’s local timezone, while `event_timestamp` is just the raw inserted value.

## Why the difference is exactly 4 hours

These rows show values like:

- `event_timestamp`: `18:30:16`
- `created_at`: `14:30:16`

That is exactly UTC vs EDT on that date.

So the likely flow is:

- the app is sending or inserting `event_timestamp` in UTC
- MySQL is displaying `created_at` in system local time
- system local time is likely Eastern Daylight Time (UTC-4)

## Most likely root cause

This is a mixed-column design:

- event time is stored in a `DATETIME` that already contains a UTC-looking value
- ingest time is stored in a `TIMESTAMP` rendered in local time

That creates an apples-to-oranges comparison.

## Useful diagnostics

Check the container timezone:

```bash
docker exec telemetry_db date
docker exec telemetry_db sh -lc 'cat /etc/timezone 2>/dev/null || true; ls -l /etc/localtime'
```

Check local vs UTC time inside MySQL:

```bash
docker exec telemetry_db mysql -u telemetry_app -pmusiclibrary installation_telemetry -e "SELECT NOW() AS now_local, UTC_TIMESTAMP() AS now_utc;"
```

Inspect recent rows again if needed:

```bash
docker exec telemetry_db mysql -u telemetry_app -pmusiclibrary installation_telemetry -e "SELECT id, event_timestamp, created_at FROM installation_events ORDER BY id DESC LIMIT 5;"
```

## Interpretation of rows 9 and 10

For rows like 9 and 10, there is probably no real delay between event occurrence and insert.

They are likely the same moment expressed two different ways:

- `event_timestamp`: UTC-like literal
- `created_at`: local-time `TIMESTAMP`

## If consistency is desired later

A cleaner long-term approach would be to standardize on one timezone convention.

Common options:

- keep everything in UTC
- store in UTC and only convert to local time in the UI or reporting layer

A practical option is:

- keep event timestamps in UTC
- set the DB/session timezone to UTC
- only display local time in UI/reporting when needed

## Summary

The 4-hour difference is most likely caused by timezone handling differences between the two columns:

- `event_timestamp` is a raw `DATETIME` value, likely inserted in UTC
- `created_at` is a `TIMESTAMP`, displayed in MySQL’s `SYSTEM` timezone

So the rows likely represent the same actual time, not a delayed insert.
