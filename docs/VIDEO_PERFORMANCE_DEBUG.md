# Video Performance Debugging Guide

## Overview

Tools to debug performance differences between local IP access (192.168.1.248) and Cloudflare access (staging.gighive.app) for GigHive video streaming.

## ⚠️ CRITICAL ISSUE FOUND: Accept-Ranges is "none"

**This is a MAJOR problem** - much more significant than the 227ms Cloudflare overhead!

### Impact:
- ❌ No video seeking/scrubbing
- ❌ No progressive loading (must download entire file)
- ❌ Massive bandwidth waste
- ❌ Poor mobile experience
- ❌ Slow initial playback

### Fix Applied:
Added `Header set Accept-Ranges "bytes"` to Apache config. See `RANGE_REQUEST_FIX.md` for details.

### Deploy Fix:
```bash
cd ~/scripts/gighive
ansible-playbook -i inventory.ini playbook.yml --tags docker
```

## Initial Findings

From your test runs:
- **Local IP**: 0.015s total (0.0017s connect, 0.015s transfer start)
- **Cloudflare**: 0.242s total (0.177s connect, 0.242s transfer start)
- **Overhead**: ~227ms (~16x slower), primarily in connection establishment
- **Accept-Ranges**: none (CRITICAL - breaks video streaming!)

## Available Scripts

### 1. `debugVideoPerformance.sh` - Detailed Single-Site Analysis

Performs comprehensive diagnostics on a single endpoint.

**Usage:**
```bash
~/scripts/os/debugVideoPerformance.sh <site> [protocol] [username] [password]
```

**Examples:**
```bash
~/scripts/os/debugVideoPerformance.sh 192.168.1.248 https
~/scripts/os/debugVideoPerformance.sh staging.gighive.app https viewer secretviewer
```

**What it measures:**
- DNS resolution time
- TCP connection time
- SSL/TLS handshake time (time_appconnect)
- Time to first byte (TTFB)
- Total transfer time
- Response headers (including Cloudflare headers)
- Network path (traceroute)
- Ping latency
- Connection reuse (3 sequential requests)
- Range request support

**Output files:**
- `video_perf_debug_<site>_<timestamp>.csv` - Structured metrics
- `video_perf_verbose_<site>_<timestamp>.log` - Detailed log

### 2. `compareVideoPerformance.sh` - Side-by-Side Comparison

Automatically runs both tests and generates a comparison report.

**Usage:**
```bash
~/scripts/os/compareVideoPerformance.sh [username] [password]
```

**Examples:**
```bash
~/scripts/os/compareVideoPerformance.sh
~/scripts/os/compareVideoPerformance.sh viewer secretviewer
```

**What it does:**
- Tests local IP (192.168.1.248)
- Tests Cloudflare (staging.gighive.app)
- Generates side-by-side comparison table
- Calculates overhead and percentage differences
- Identifies Cloudflare-specific behavior

**Output:**
- `video_perf_comparison_<timestamp>.txt` - Comparison report
- Individual CSV files for each test

### 3. `testDownloadsSingle.sh` - Quick Test (Your Original)

Simple, fast test for basic metrics.

**Usage:**
```bash
~/scripts/os/testDownloadsSingle.sh <site> [max_concurrent] [protocol]
```

## Key Metrics Explained

| Metric | Description | What to Look For |
|--------|-------------|------------------|
| `time_namelookup` | DNS resolution | Should be ~0 for IP addresses |
| `time_connect` | TCP connection | High values indicate network latency |
| `time_appconnect` | SSL/TLS handshake | Cloudflare adds overhead here |
| `time_starttransfer` | Time to first byte (TTFB) | Includes server processing time |
| `time_total` | Complete download | Overall performance |
| `CF-Ray` header | Cloudflare trace ID | Present only when going through CF |
| `CF-Cache-Status` | Cache hit/miss | HIT = served from cache, MISS = origin |

## Performance Analysis Workflow

### Step 1: Quick Comparison
```bash
cd ~/scripts/os
./compareVideoPerformance.sh
```

Review the comparison report to identify where overhead occurs.

### Step 2: Detailed Investigation

If you need more details on a specific endpoint:
```bash
cd ~/scripts/os
./debugVideoPerformance.sh staging.gighive.app https viewer secretviewer
```

### Step 3: Analyze Results

Look for:
1. **DNS overhead** - Should be minimal for both
2. **TCP connection time** - Cloudflare adds routing overhead
3. **SSL handshake time** - Cloudflare terminates SSL, adds latency
4. **TTFB** - Check if origin server is slow
5. **Cloudflare cache status** - HIT should be faster than MISS

## Common Performance Issues

### Issue: High `time_connect`
**Cause:** Network routing, geographic distance to Cloudflare edge
**Solution:** 
- Check traceroute hops
- Verify Cloudflare edge location
- Consider Argo Smart Routing (paid feature)

### Issue: High `time_appconnect`
**Cause:** SSL/TLS handshake overhead
**Solution:**
- Cloudflare always terminates SSL
- Consider HTTP/2 or HTTP/3 for better performance
- Check if local IP can use HTTP instead of HTTPS

### Issue: High `time_starttransfer` but low `time_connect`
**Cause:** Origin server processing time
**Solution:**
- Check nginx/backend performance
- Review video file serving configuration
- Verify disk I/O on server

### Issue: Cloudflare cache MISS
**Cause:** Video not cached or cache expired
**Solution:**
- Check Cache-Control headers
- Configure Cloudflare Page Rules for video caching
- Verify video file size (CF has limits)

## Expected Overhead

**Normal Cloudflare overhead:**
- DNS: +0-50ms (cached after first lookup)
- TCP: +20-100ms (depends on edge location)
- SSL: +50-150ms (TLS handshake)
- Total: +100-300ms for first request

**Your current overhead (~227ms) is within normal range for:**
- SSL termination at Cloudflare edge
- Routing through Cloudflare network
- Geographic distance to edge server

## Optimization Recommendations

1. **Enable HTTP/2 or HTTP/3** - Better connection reuse
2. **Configure Cloudflare caching** - Reduce origin hits
3. **Use Range Requests** - For video seeking/streaming
4. **Enable Cloudflare Argo** - Optimized routing (paid)
5. **Consider direct IP for LAN clients** - Bypass CF for local network

## Troubleshooting Commands

### Check if Cloudflare is being used
```bash
curl -I https://staging.gighive.app/video/test.mp4 | grep -i cf-
```

### Test without SSL verification (debugging only)
```bash
curl -k -o /dev/null -w "@curl-format.txt" https://192.168.1.248/video/test.mp4
```

### Compare HTTP vs HTTPS on local IP
```bash
cd ~/scripts/os
./debugVideoPerformance.sh 192.168.1.248 http
./debugVideoPerformance.sh 192.168.1.248 https
```

### Test from different network location
Run the scripts from outside your LAN to see if routing changes.

## Next Steps

Based on your results, you can:
1. Accept the overhead as normal for Cloudflare
2. Configure local clients to use direct IP (192.168.1.248)
3. Optimize Cloudflare settings (caching, HTTP/3, etc.)
4. Investigate if the overhead is acceptable for your use case

## Files Generated

All scripts generate timestamped files to avoid overwriting previous results:
- CSV files: Machine-readable metrics
- Log files: Human-readable verbose output
- Comparison files: Side-by-side analysis
