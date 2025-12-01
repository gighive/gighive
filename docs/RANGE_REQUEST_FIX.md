# HTTP Range Request Support Fix

## Problem Discovered

Video files were returning `Accept-Ranges: none` instead of `Accept-Ranges: bytes`, which **completely breaks video streaming functionality**.

### Test Results (Before Fix)

```bash
# Local IP test
./testDownloadsSingle.sh 192.168.1.248 4 https
Accept-Ranges: none

# Cloudflare test  
./testDownloadsSingle.sh staging.gighive.app 4 https
Accept-Ranges: none
```

Both endpoints showed `Accept-Ranges: none`, indicating the problem was on the origin server, not Cloudflare.

## Impact of Missing Range Support

Without `Accept-Ranges: bytes`, video streaming is **severely degraded**:

### What Breaks:
1. **No video seeking** - Users cannot skip to different timestamps
2. **No progressive loading** - Must download entire file before playback starts
3. **Bandwidth waste** - Downloads full video even if user only watches 10 seconds
4. **Poor mobile experience** - Mobile browsers rely heavily on range requests
5. **Slow initial playback** - Cannot start playing while downloading
6. **No adaptive streaming** - Cannot adjust quality based on bandwidth

### Performance Comparison:

| Feature | With Range Support | Without Range Support |
|---------|-------------------|----------------------|
| Start playback | ~1-2 seconds | Wait for full download |
| Seek to timestamp | Instant | Not possible |
| Bandwidth usage | Only what's watched | Full file always |
| Mobile friendly | Yes | No |
| Adaptive quality | Possible | Not possible |

## Root Cause

Apache was serving video files as static content (mounted volumes), but the `Accept-Ranges` header was not being explicitly set. While Apache should enable this by default, something in the configuration stack was preventing it.

### Configuration Context:

```yaml
# docker-compose.yml.j2 (lines 21-22)
volumes:
  - "/home/{{ ansible_user }}/audio:/var/www/html/audio"
  - "/home/{{ ansible_user }}/video:/var/www/html/video"
```

Videos are served as static files, not through PHP, so Apache should handle range requests natively.

## The Fix

Added explicit `Accept-Ranges: bytes` header in `/home/sodo/scripts/gighive/ansible/roles/docker/templates/apache2.conf.j2`:

```apache
<FilesMatch "\.(mp4|mov|au|mp3|wav|aac)$">
    # Enable range requests for video/audio streaming
    Header set Accept-Ranges "bytes"
    
    # CORS headers for cross-origin playback
    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Headers "Range"
    Header set Access-Control-Expose-Headers "Content-Length, Content-Range"
</FilesMatch>
```

### Why This Works:

1. **Explicit declaration** - Forces Apache to advertise range support
2. **File-specific** - Only applies to media files (mp4, mp3, etc.)
3. **Works with CORS** - Maintains cross-origin access for browser playback
4. **HTTP spec compliant** - Follows RFC 7233 for range requests

## Testing the Fix

### Step 1: Deploy the Configuration

```bash
cd ~/scripts/gighive
ansible-playbook -i inventory.ini playbook.yml --tags docker
```

### Step 2: Verify Range Support

Use the diagnostic script:

```bash
cd ~/scripts/os
./diagnoseRangeSupport.sh 192.168.1.248 https
./diagnoseRangeSupport.sh staging.gighive.app https
```

### Expected Results (After Fix):

```
Accept-Ranges header: bytes
HTTP Status Code: 206
Content-Range header: bytes 0-1023/[filesize]
✅ SUCCESS: Server supports range requests (206 Partial Content)
```

### Step 3: Test Range Request Manually

```bash
# Should return 206 Partial Content
curl -I -H "Range: bytes=0-1023" https://192.168.1.248/video/test.mp4

# Expected headers:
HTTP/2 206 
accept-ranges: bytes
content-range: bytes 0-1023/12345678
content-length: 1024
```

## How Range Requests Work

### Client Request:
```http
GET /video/file.mp4 HTTP/1.1
Range: bytes=1000-2000
```

### Server Response (With Range Support):
```http
HTTP/1.1 206 Partial Content
Accept-Ranges: bytes
Content-Range: bytes 1000-2000/500000000
Content-Length: 1001

[1001 bytes of data]
```

### Server Response (Without Range Support):
```http
HTTP/1.1 200 OK
Accept-Ranges: none
Content-Length: 500000000

[entire 500MB file]
```

## Browser Behavior

### With Range Support:
1. Browser requests first few MB to start playback
2. User can seek to any timestamp
3. Browser requests only needed chunks
4. Efficient bandwidth usage
5. Fast initial playback

### Without Range Support:
1. Browser must download entire file
2. No seeking until download completes
3. Wastes bandwidth on unwatched content
4. Slow initial playback
5. Poor user experience

## Related Documentation

- **CONTENT_RANGE_CLOUDFLARE.md** - Previous fix for malformed Content-Range headers
- **VIDEO_PERFORMANCE_DEBUG.md** - Performance testing tools (in ~/scripts/os/)

## Performance Impact

The 227ms overhead between local IP and Cloudflare is **minor** compared to the impact of missing range support:

- **227ms overhead**: Acceptable for SSL/TLS and routing through Cloudflare
- **Missing range support**: Makes video streaming nearly unusable

### Priority:
1. ✅ **Fix range support** (this document) - CRITICAL
2. ⚠️ Optimize Cloudflare overhead - Nice to have

## Verification Checklist

After deploying the fix:

- [ ] `Accept-Ranges: bytes` header present in HEAD requests
- [ ] Range requests return `206 Partial Content` status
- [ ] `Content-Range` header shows correct byte ranges
- [ ] Video seeking works in browser
- [ ] Progressive loading starts playback quickly
- [ ] Mobile devices can stream videos efficiently

## Troubleshooting

### If range requests still don't work:

1. **Check Apache modules**:
   ```bash
   docker exec apacheWebServer apache2ctl -M | grep headers
   ```
   Should show: `headers_module (shared)`

2. **Check for conflicting headers**:
   ```bash
   curl -I https://192.168.1.248/video/test.mp4 | grep -i range
   ```

3. **Check Apache error logs**:
   ```bash
   docker logs apacheWebServer 2>&1 | grep -i range
   ```

4. **Verify file permissions**:
   ```bash
   docker exec apacheWebServer ls -la /var/www/html/video/
   ```

5. **Test without Cloudflare**:
   ```bash
   ./diagnoseRangeSupport.sh 192.168.1.248 https
   ```

## Additional Optimizations

Once range support is working, consider:

1. **Enable HTTP/2 Server Push** - Preload video metadata
2. **Configure video MIME types** - Ensure proper Content-Type headers
3. **Add video preload hints** - `<link rel="preload" as="video">`
4. **Implement adaptive bitrate** - Multiple quality versions
5. **Use video CDN** - Cloudflare Stream or similar service

## References

- [RFC 7233 - HTTP Range Requests](https://tools.ietf.org/html/rfc7233)
- [MDN - HTTP Range Requests](https://developer.mozilla.org/en-US/docs/Web/HTTP/Range_requests)
- [Apache mod_headers Documentation](https://httpd.apache.org/docs/2.4/mod/mod_headers.html)
