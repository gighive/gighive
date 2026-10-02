# What is TUS?

**tus** is an open protocol for **resumable file uploads** over HTTP(S), purpose-built for large files like videos. It lets clients pause, resume, and recover uploads reliably after network interruptions or app restarts.

---

## ⚔️ tus vs HTTP Multipart Uploads (Side‑by‑Side)

| Feature | **tus (Resumable Upload Protocol)** | **HTTP Multipart / S3‑style Uploads** |
|---|---|---|
| **Primary goal** | Resumable, reliable uploads for large files | General‑purpose file uploads |
| **Resumable by design** | ✅ Yes — resumes from exact byte offset (`Upload-Offset`) | ⚠️ Only with custom logic/SDKs (e.g., S3 Multipart) |
| **Tolerance to network drops** | ✅ High — pause/retry built in | ⚠️ Varies — often restart or rebuild state manually |
| **Chunking** | ✅ Native & offset‑addressed | ✅ Via multipart parts or range PUTs |
| **Client recovery** | ✅ Automatic with `HEAD`/offset checks | 🔧 Manual bookkeeping (part numbers, ETags) |
| **HTTP methods** | `POST` (create), `PATCH` (upload), `HEAD` (query), `OPTIONS` | `POST`/`PUT` (form/multipart or single PUT) |
| **Server statefulness** | Works with stateless app + external metadata store | Often requires session and temporary state |
| **Browser support** | Via `tus-js-client` (robust resume) | Forms/Fetch work; resumability requires custom code/SDK |
| **Simplicity to start** | 🟡 Needs a tus server implementation | 🟢 Easiest for simple/small uploads |
| **Best for** | Very large videos, mobile/spotty networks, creator apps | Small/medium files; direct cloud storage APIs |
| **Typical storage flow** | App receives chunks, can stream to storage; resume anytime | App or SDK assembles parts and completes upload |

> **Quick take:** Use **tus** when reliability and resumability matter (e.g., video ingest). Use **multipart** for simple, small, or existing cloud‑native flows when you don’t need seamless resume.

---

## 📦 Example: tus Workflow (Simplified)

```http
# 1) Create an upload
POST /files
Upload-Length: 1073741824
→ 201 Created
→ Location: /files/abc123

# 2) Upload first chunk
PATCH /files/abc123
Upload-Offset: 0
Content-Type: application/offset+octet-stream
(binary chunk...)

# 3) Resume later from server-reported offset
HEAD /files/abc123
→ Upload-Offset: 1048576

PATCH /files/abc123
Upload-Offset: 1048576
(next binary chunk...)
```

---

## ☁️ Example: S3 Multipart Upload (Conceptual)

1. `CreateMultipartUpload`
2. Upload parts (e.g., 5–15 MB each), track **part numbers** and returned **ETags**
3. `CompleteMultipartUpload` with the part list

Resumability is possible but you must persist and reconstruct multipart state in your app or rely on the cloud SDK to do it for you.

---

## 🎯 Summary

- **Choose tus** for: large videos, flaky networks, mobile clients, or when you need bulletproof **pause/resume**.
- **Choose multipart** for: small/simple uploads or when you already rely on cloud SDKs and do not require seamless resume.

---

## 📚 References
- Official spec: https://tus.io/protocols/resumable-upload.html
- Protocol repository: https://github.com/tus/tus-resumable-upload-protocol
- JavaScript client: https://github.com/tus/tus-js-client
