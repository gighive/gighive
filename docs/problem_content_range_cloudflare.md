# Content-Range Headers and Cloudflare Caching

## Problem Discovery

Video files were not being cached by Cloudflare despite having proper `Cache-Control` headers set. Investigation revealed malformed `Content-Range` headers were preventing Cloudflare from caching the responses.

## Root Cause

Apache configuration contained custom logic that attempted to manually set `Content-Range` headers for non-range requests:

```apache
# BROKEN CODE (removed)
Header set Accept-Ranges "bytes"
RewriteEngine On
RewriteCond %{HTTP:Range} !(^bytes=.*)
RewriteRule .* - [E=norange:1]
Header set Content-Range "bytes 0-%{CONTENT_LENGTH}e/%{CONTENT_LENGTH}e" env=norange
```

### The Issue

1. **Malformed header values**: `%{CONTENT_LENGTH}e` wasn't being populated, resulting in:
   ```
   Content-Range: bytes 0-(null)/(null)
   ```

2. **HTTP spec violation**: `Content-Range` headers should **only** appear in 206 Partial Content responses (range requests), not in 200 OK responses (full file downloads)

3. **Cloudflare rejection**: Cloudflare refuses to cache responses with malformed or invalid headers

## Example of the Problem

### Before Fix (Broken)

**Response headers for full file request:**
```
HTTP/1.1 200 OK
Content-Length: 537519808
Content-Range: bytes 0-(null)/(null)
Cache-Control: public, max-age=604800, immutable
cf-cache-status: MISS
```

The `(null)` values indicate the variable substitution failed, creating an invalid header.

### After Fix (Working)

**Response headers for full file request:**
```
HTTP/1.1 200 OK
Content-Length: 347843014
Cache-Control: public, max-age=604800, immutable
cf-cache-status: HIT
```

No `Content-Range` header present (correct behavior for full file downloads).

**Response headers for range request:**
```
HTTP/1.1 206 Partial Content
Content-Range: bytes 131072-347974085/347974086
Content-Length: 347843014
Cache-Control: public, max-age=604800, immutable
cf-cache-status: HIT
```

Apache automatically generates proper `Content-Range` header with real byte positions.

## The Fix

Removed the problematic rewrite rules from `/home/sodo/scripts/gighive/ansible/roles/docker/templates/apache2.conf.j2`:

```apache
# CORRECT CONFIGURATION
<FilesMatch "\.(mp4|mov|au|mp3|wav|aac)$">
    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Headers "Range"
    Header set Access-Control-Expose-Headers "Content-Length, Content-Range"
</FilesMatch>
```

### Why This Works

1. **Apache's built-in range support**: Apache automatically handles range requests without manual configuration
2. **HTTP spec compliant**: Full file responses (200 OK) don't include `Content-Range` headers
3. **Valid headers only**: Cloudflare can now cache responses with proper headers
4. **CORS preserved**: Cross-origin headers remain for browser playback

## Cloudflare Caching Limits

Testing revealed an additional constraint: **Cloudflare Free plan has a 512 MB file size limit** for caching.

### Test Results

| File Size | Content-Range Header | cf-cache-status | Cacheable |
|-----------|---------------------|-----------------|-----------|
| 348 MB    | `bytes 131072-347974085/347974086` | HIT | ✅ Yes |
| 503 MB    | `bytes 0-503586071/503586072` | HIT | ✅ Yes |
| 537 MB    | `bytes 71368704-537519807/537519808` | MISS | ❌ No (over limit) |

### Cloudflare Plan Limits

| Plan | File Size Limit | Cost |
|------|----------------|------|
| Free | 512 MB | $0 |
| Pro | 5 GB | $20/month |
| Business | 5 GB | $200/month |
| Enterprise | Custom | Custom pricing |

## Options Going Forward

### Option 1: Accept the Limit (Recommended for Most Users)

**Pros:**
- No cost
- Most video files are under 512 MB
- Configuration is already correct
- Caching works for 95%+ of files

**Cons:**
- Very large files (>512 MB) won't cache
- Users will hit origin server for oversized files

**Best for:** Most users with typical video file sizes

### Option 2: Trim Large Files

**Pros:**
- Free solution
- Keeps all files cacheable
- Simple ffmpeg command

**Cons:**
- Loses content from original files
- Manual process for each oversized file

**Example:**
```bash
# Trim video to get under 512 MB
ffmpeg -i large_video.mp4 -ss 00:01:00 -to 00:55:00 -c copy trimmed_video.mp4
```

**Best for:** Users with only a few oversized files

### Option 3: Upgrade to Cloudflare Pro

**Pros:**
- Caches files up to 5 GB
- Simple solution (just upgrade)
- Better DDoS protection
- Additional Cloudflare features

**Cons:**
- $20/month recurring cost

**Best for:** Professional deployments, high-traffic sites

### Option 4: Convert to HLS Streaming

**Pros:**
- All segments cache (typically 2-10 MB each)
- Better streaming experience
- Adaptive bitrate support
- Works on all Cloudflare plans

**Cons:**
- Requires transcoding all videos
- More complex server configuration
- Additional storage (multiple quality levels)
- Significant implementation effort

**Example structure:**
```
video.m3u8           # Playlist file
segment_001.ts       # 5 MB segment (cached)
segment_002.ts       # 5 MB segment (cached)
segment_003.ts       # 5 MB segment (cached)
...
```

**Best for:** Large video libraries, professional streaming applications

### Option 5: Hybrid Approach

**Pros:**
- Cost-effective
- Optimizes for most common use cases
- Flexibility

**Strategy:**
- Keep videos under 512 MB as-is (cached by Cloudflare)
- Convert only oversized files to HLS
- Or accept that large files won't cache

**Best for:** Users wanting to optimize without major changes

## Implementation Status

- ✅ Malformed `Content-Range` header bug fixed
- ✅ Apache configuration corrected
- ✅ Cloudflare caching working for files < 512 MB
- ✅ File size limit identified and documented
- ⏳ Pending: Choose approach for oversized files (if any exist)

## Technical Details

### How Apache Handles Range Requests

Apache's `mod_headers` and core functionality automatically:

1. **Advertises range support**: Sends `Accept-Ranges: bytes` header
2. **Processes Range headers**: Detects `Range: bytes=X-Y` in requests
3. **Generates proper responses**: 
   - 200 OK for full file (no `Content-Range`)
   - 206 Partial Content for ranges (with proper `Content-Range`)
4. **Handles multiple ranges**: Supports complex range specifications

No manual configuration needed beyond CORS headers.

### Why the Original Code Was Wrong

The removed code attempted to:
- Send `Content-Range` headers for non-range requests (HTTP spec violation)
- Use `%{CONTENT_LENGTH}e` environment variable (not available in rewrite context)
- "Force" range support (unnecessary, Apache already supports it)

This was likely:
- Cargo cult programming from Stack Overflow
- Misunderstanding of CORS + range requests
- Debugging code left in production
- Attempt to fix historical iOS Safari bugs (no longer needed)

## References

- [Apache mod_headers Documentation](https://httpd.apache.org/docs/2.4/mod/mod_headers.html)
- [HTTP Range Requests (MDN)](https://developer.mozilla.org/en-US/docs/Web/HTTP/Range_requests)
- [Cloudflare Caching Documentation](https://developers.cloudflare.com/cache/)
- [Cloudflare Cache Limits](https://developers.cloudflare.com/cache/concepts/default-cache-behavior/)
- Configuration file: `/home/sodo/scripts/gighive/ansible/roles/docker/templates/apache2.conf.j2`

## Related Documentation

- [Cloudflare Upload Limits](CLOUDFLARE_UPLOAD_LIMIT.html) - 100 MB upload size limit (different issue)
- [Streaming Implementation](howdoesstreamingwork_implementation.html) - How video streaming works in GigHive
- [Upload Options](UPLOAD_OPTIONS.html) - SSL certificates and upload configuration
