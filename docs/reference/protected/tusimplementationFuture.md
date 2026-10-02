# tusd Server Setup Plan for GigHive

This document outlines how to deploy `tusd` as its own container, reverse‑proxied by Apache, with uploads landing in your existing Apache-served directories.

Apache currently serves:
- `/home/{{ ansible_user }}/audio` -> `/var/www/html/audio`
- `/home/{{ ansible_user }}/video` -> `/var/www/html/video`

`tusd` will write into a staging directory and, on completion, move files into either `audio/` or `video/` based on client-provided metadata.

## High-level Design

- `tusd` runs as its own container, listening on port 1080.
- Apache proxies the path `/uploads/` to the `tusd` container.
- `tusd` stores in a shared host volume under `uploads/tmp/` during upload.
- A `post-finish` hook moves the completed file to `/audio` or `/video` based on `Upload-Metadata` (e.g., `mediaType=audio|video`).
- Optional HTTP webhook notifies the PHP app to register/process the asset.

## Prerequisites and Decisions

- **Metadata convention from iOS** (sent via `Upload-Metadata` header, base64-encoded values):
  - `filename`
  - `mediaType` (required): `audio` or `video`
  - `contentType` (optional)
  - `userId` (optional)
- **Hook strategy**:
  - Command hooks (recommended for local moves): run a script on `post-finish` to place the file.
  - Optional HTTP webhook: notify PHP app after moving the file.

## Step-by-Step Plan

1) **Prepare host directories and permissions**
- Ensure the following exist on the host:
  - `/home/{{ ansible_user }}/audio`
  - `/home/{{ ansible_user }}/video`
  - `/home/{{ ansible_user }}/uploads/tmp` (new for tusd staging)
- Make sure the `tusd` container user can write and move files (UID/GID or group perms).

2) **Add tusd service to docker-compose**
- Add a new service `tusd` with:
  - Image: `tusproject/tusd:latest`
  - Ports: expose 1080 internally (Apache will proxy; no public exposure needed)
  - Volumes:
    - `/home/{{ ansible_user }}/uploads:/data` (contains `tmp/`)
    - `/home/{{ ansible_user }}/audio:/data/audio`
    - `/home/{{ ansible_user }}/video:/data/video`
    - Hooks directory: `{{ role_path }}/files/tusd/hooks:/hooks:ro`
  - Command flags:
    - `-host=0.0.0.0`
    - `-port=1080`
    - `-base-path=/uploads/`
    - `-dir=/data/tmp`
    - `-behind-proxy`
    - `-hooks-dir=/hooks` (enables command hooks)
  - Optional (if using HTTP hooks): `-hooks-http <URL>` and set a shared secret header.

3) **Implement post-finish command hook**
- Create `{{ role_path }}/files/tusd/hooks/post-finish` (POSIX shell):
  - Parse `TUS_UPLOAD_METADATA` (key base64-value pairs).
  - Read `mediaType` and `filename`; sanitize `filename`.
  - Determine destination:
    - `audio` -> `/data/audio/`
    - `video` -> `/data/video/`
  - Move completed file from `/data/tmp/{upload-id}` to the destination, using an atomic move if possible.
  - Set file permissions/ownership appropriately.
  - Optional: call a PHP webhook to register the asset.
- Ensure the script is executable and compatible with the `tusd` image environment.

4) **Configure Apache reverse proxy**
- In the Apache vhost config, proxy `/uploads/` to the tusd container:
  - `ProxyPass /uploads/ http://tusd:1080/uploads/`
  - `ProxyPassReverse /uploads/ http://tusd:1080/uploads/`
- Allow methods and increase timeouts as needed:
  - Methods: `PATCH, POST, HEAD, OPTIONS, GET`
  - Timeouts: increase `ProxyTimeout`, `Timeout`, and/or `RequestReadTimeout` for long uploads.
- CORS (if needed): allow `PATCH`, `Tus-Resumable`, `Upload-*` headers and expose relevant response headers (`Location`, `Upload-Offset`, etc.).
- Pass through auth headers if uploads are authenticated at Apache.

5) **iOS client endpoint and metadata**
- Point the iOS tus client to `https(s)://<domain>/uploads/`.
- Send `Upload-Metadata` with at least `mediaType` and `filename`.
- Consider background uploads using a background `URLSession`.

6) **Naming and collision policy**
- Preferred: generate a server-side UUID and keep the original extension, e.g., `uuid.ext`.
- Alternative: use sanitized client `filename` and append a numeric suffix on collision.
- Implement in the `post-finish` hook.

7) **Optional: HTTP webhook for registration**
- Create a secure PHP endpoint (e.g., `/api/upload-complete`) to record the asset in DB.
- Include metadata (userId, path, size, contentType, duration if known).
- Protect with a shared secret header.

8) **Resource limits and logging**
- `tusd`:
  - Configure container resource limits (CPU/memory) as appropriate.
  - Enable logging to stdout; capture with your stack.
- Apache:
  - Ensure logs include proxied `/uploads/` requests.

9) **Security**
- Enforce HTTPS at Apache.
- If using auth, ensure only authenticated users can access `/uploads/`.
- Sanitize `filename` in hooks; prevent path traversal.
- Validate `mediaType` to only `audio` or `video`.

10) **Test plan**
- Small file uploads for both `audio` and `video`; verify final placement.
- Large multi‑GB uploads to validate resilience and timeouts.
- Pause/resume and cancel from iOS.
- Backgrounding tests (app in background, relaunch).
- Verify permissions and ownership of final files.
- If using webhook, verify DB entries and downstream processing.

## Future-proofing and Scalability

- Because `tusd` is its own container:
  - You can move it to another host without changing iOS (Apache keeps proxying `/uploads/`).
  - You can switch storage to S3 using `tusd` S3 backend with minimal application changes.
- The iOS endpoint remains stable: `https://<domain>/uploads/`.

## Quick Checklist

- [ ] Host directories exist: `audio/`, `video/`, `uploads/tmp/`
- [ ] `tusd` service added to compose with volumes and command flags
- [ ] `post-finish` hook implemented and executable
- [ ] Apache proxy configured for `/uploads/` with methods, timeouts, and CORS
- [ ] iOS client points to `/uploads/` and sends correct metadata
- [ ] Naming/collision policy defined
- [ ] Optional webhook endpoint ready and secured
- [ ] Logs and metrics reviewed after initial tests
