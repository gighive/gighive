# What is TUS?

## ⚔️ tus vs HTTP Multipart Uploads

  --------------------------------------------------------------------------------
  Feature          **tus (Resumable Upload        **HTTP Multipart / S3-style
                   Protocol)**                    Uploads**
  ---------------- ------------------------------ --------------------------------
  **Purpose**      Designed *specifically* for    General-purpose file upload
                   resumable, reliable uploads    mechanism

  **Resumable?**   ✅ Yes --- resumes from the    ❌ No (unless implemented
                   exact byte offset after        manually with multipart APIs)
                   failure                        

  **Protocol       Custom extension over HTTP/1.1 Standard HTTP POST or PUT, often
  Base**           & HTTP/2 using `PATCH`,        multipart/form-data
                   `HEAD`, and `OPTIONS`          

  **Upload Resume  Built-in via `Upload-Offset`   Requires custom logic or SDK
  Support**        header                         (e.g., AWS multipart API)

  **Client         Automatic --- upload can       Manual --- must track chunks or
  Recovery**       pause, reconnect, or continue  restart entire upload
                   later                          

  **Chunked        ✅ Native --- sends chunks     ✅ Possible but handled manually
  Uploads**        with byte offsets              or via SDK

  **Network Fault  ✅ Very high --- uploads       ⚠️ Limited --- typically
  Tolerance**      resume even after disconnects  restarts from zero or last chunk

  **Stateless      ✅ Supported --- tus server    ⚠️ Usually requires persistent
  Servers**        can be stateless if upload     sessions or temporary storage
                   metadata is stored elsewhere   

  **HTTP Methods   `POST` (create), `PATCH`       `POST` or `PUT`
  Used**           (upload), `HEAD` (check        
                   progress)                      

  **Ease of        🟡 Needs a tus server          🟢 Easy with web forms or REST
  Integration**    implementation                 endpoints

  **Upload from    ✅ Supported via tus-js-client ⚠️ Supported, but restarting
  Browser**        (even with dropped             large uploads is painful
                   connections)                   

  **Use Cases**    Large videos, media ingest     Simple file forms, smaller
                   pipelines, mobile uploads      uploads, APIs like S3 direct
                                                  upload
  --------------------------------------------------------------------------------

------------------------------------------------------------------------

## 📦 Example: tus Workflow (Simplified)

``` http
# Step 1: Create upload
POST /files
Upload-Length: 1073741824
→ 201 Created
→ Location: /files/abc123

# Step 2: Upload first chunk
PATCH /files/abc123
Upload-Offset: 0
Content-Type: application/offset+octet-stream
(First 1MB of data...)

# Step 3: Resume upload later
PATCH /files/abc123
Upload-Offset: 1048576
(Content continues...)
```

------------------------------------------------------------------------

## ☁️ Example: S3 Multipart Upload

With AWS SDK or REST API: 1. `CreateMultipartUpload` 2. Upload each part
(e.g. 5MB chunks) 3. `CompleteMultipartUpload` 4. Resume logic must be
manually managed in your code or SDK.

It's powerful, but far more complex to implement manually than tus.

------------------------------------------------------------------------

## 🎯 Summary

  -----------------------------------------------------------------------
  Scenario                        Best Choice
  ------------------------------- ---------------------------------------
  Uploading small files quickly   **HTTP multipart (simple form upload)**

  Uploading very large videos     **tus**
  reliably                        

  Client-side resume across       **tus**
  sessions                        

  Integration with cloud storage  **Multipart (or tus → S3 bridge)**
  (e.g., S3)                      
  -----------------------------------------------------------------------

------------------------------------------------------------------------

## 📚 References

-   Official spec: <https://tus.io/protocols/resumable-upload.html>
-   GitHub: <https://github.com/tus/tus-resumable-upload-protocol>
