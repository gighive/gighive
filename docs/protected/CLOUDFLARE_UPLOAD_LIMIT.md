# Cloudflare Upload Limit Issue

## Problem

Cloudflare has a **100MB upload limit** on Free and Pro plans. Files larger than 100MB will fail with HTTP 413 (Payload Too Large).

## Symptoms

- Small files (<100MB) upload successfully through `https://dev.gighive.app`
- Large files (>100MB) fail after uploading ~1-2MB
- Upload stops with no clear error message
- curl shows: `HTTP/2 413` and `abort upload after having sent 917230 bytes`

## How to Confirm It's Cloudflare (Not Origin Server)

When testing with curl, these indicators prove the 413 is from **Cloudflare**, not your origin server:

### 1. **Cloudflare Headers Present**
```
< cf-ray: 98b7162c2b937274-EWR
```
The `cf-ray` header is **only added by Cloudflare**. This proves the request went through Cloudflare's network.

### 2. **Empty Response Body**
```
< content-length: 0
```
Your origin server returns detailed responses (HTML or JSON). Cloudflare's 413 returns an **empty body** with zero content length.

### 3. **Immediate Rejection**
```
Content-Length: 368608404
...
* abort upload after having sent 917230 bytes
```
Only **917KB sent** out of 350MB. Cloudflare reads the `Content-Length` header, sees it exceeds 100MB, and **immediately returns 413** without waiting for the full upload or forwarding to your origin server.

### 4. **No Origin Server Headers**
Compare successful response (reaches origin):
```
< Server: cloudflare
< Content-Type: text/html; charset=utf-8    ← Origin server header
< x-frame-options: SAMEORIGIN              ← Origin server header
< x-content-type-options: nosniff          ← Origin server header
```

The 413 response only has:
```
< date: Wed, 08 Oct 2025 16:41:35 GMT
< content-length: 0
< cf-ray: 98b7162c2b937274-EWR
```

**No origin server headers** like `Content-Type`, `x-frame-options`, etc. Just Cloudflare's minimal 413 response.

### 5. **Early Abort Timing**
The upload is aborted after less than 1% is sent. Your origin server would only see the request **after** the full upload completes (or times out), not during the initial upload phase.

## Test Results

| File Size | Local Network | Cloudflare | Status |
|-----------|---------------|------------|--------|
| 38MB      | ✅ Works      | ✅ Works   | Under limit |
| 177MB     | ✅ Works      | ❌ Fails   | Over limit (413) |
| 241MB     | ✅ Works      | ❌ Fails   | Over limit (413) |
| 350MB     | ✅ Works      | ❌ Fails   | Over limit (413) |

## Solutions

### Option 1: Bypass Cloudflare for Uploads (Recommended)

Create a separate subdomain that bypasses Cloudflare proxy:

1. **Add DNS record in Cloudflare**:
   - Type: `A`
   - Name: `upload` (creates `upload.gighive.app`)
   - Content: Your origin server IP (e.g., `192.168.1.248` or public IP)
   - Proxy status: **DNS only** (gray cloud ☁️, NOT orange 🟠)
   - TTL: Auto

2. **Update iOS app** to use `https://upload.gighive.app` for uploads

3. **Keep main site protected**: Continue using `https://dev.gighive.app` for browsing/database

**Pros**: 
- No file size limits
- Simple implementation
- No code changes needed (just URL)

**Cons**: 
- Upload endpoint not protected by Cloudflare DDoS protection
- Need to expose origin server IP

### Option 2: Upgrade Cloudflare Plan

- **Business plan** ($200/month): 500MB limit
- **Enterprise plan** (custom pricing): No limit

### Option 3: Implement Chunked Uploads

Break files into <100MB chunks:

1. Split file into chunks on iOS
2. Upload each chunk separately
3. Server reassembles chunks
4. Requires significant backend changes

**Pros**: 
- Works through Cloudflare
- Resumable uploads

**Cons**: 
- Complex implementation
- Requires server-side changes
- Multiple HTTP requests per file

### Option 4: Use Cloudflare R2 + Workers

Upload directly to Cloudflare R2 storage:

1. Create R2 bucket
2. Use Cloudflare Workers for upload handling
3. No size limits on R2

**Pros**: 
- Scalable
- No size limits
- Stays within Cloudflare ecosystem

**Cons**: 
- Requires R2 subscription ($0.015/GB stored)
- Significant architecture changes

## Recommended Approach

**For immediate fix**: Use Option 1 (bypass Cloudflare for uploads)

1. Create `upload.gighive.app` DNS record (DNS-only mode)
2. Add server URL option in iOS app settings
3. Users can choose between:
   - `https://gighive` (local network, no limits)
   - `https://upload.gighive.app` (internet, no limits, no Cloudflare)
   - `https://dev.gighive.app` (internet, 100MB limit, Cloudflare protected)

## Implementation Status

- ✅ Issue diagnosed (HTTP 413 from Cloudflare)
- ✅ Better error messaging added to iOS app
- ✅ curl test script created
- ⏳ Pending: Implement bypass solution

## References

- [Cloudflare Upload Limits](https://developers.cloudflare.com/fundamentals/reference/upload-limits/)
- [Cloudflare R2 Documentation](https://developers.cloudflare.com/r2/)
- Test script: `test_cloudflare_upload.sh`
