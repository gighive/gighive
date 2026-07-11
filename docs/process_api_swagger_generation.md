# API Swagger / OpenAPI Generation Process

## Overview

`docs/openapi.yaml` (Swagger spec for Swagger UI) is a **pre-generated artifact** — it is produced by annotating PHP source files with `zircote/swagger-php` attributes and running a generator script locally, then committing the result. It is **not** hand-edited and is **not** regenerated at deploy or runtime.

---

## Two separate `docs/` directories — do not confuse them

| Directory | Served by | Purpose |
|---|---|---|
| `/home/sodo/gighive/docs/` | GitHub Pages at `gighive.app` | User-facing Jekyll docs (README, setup guides, etc.) |
| `ansible/roles/docker/files/apache/webroot/docs/` | Apache container on the deployed VM | Swagger UI (`api-docs.html`) and `openapi.yaml` |

Protecting the Apache `/docs/` path has **no effect** on `gighive.app` — that site is served entirely by GitHub's infrastructure and reads directly from the repo's root `docs/` folder.

---

## Security decision: protect the Swagger UI in Apache

`/docs/openapi.yaml` is served publicly by default (no Apache auth block covers `/docs/`). Since the spec documents admin-only endpoints (paths, required fields, error codes), it should be behind auth.

**Action:** Add a `<Location "/docs/">` block to `ansible/roles/docker/templates/default-ssl.conf.j2` requiring `valid-user`. This gates the Swagger UI and the raw YAML behind the same htpasswd file used for the rest of the app. The admin endpoints themselves remain doubly protected (Apache `Require user admin` on `/admin/` + PHP-level check), but exposing their paths publicly in a readable spec is unnecessary.

---

## Tooling: `zircote/swagger-php` v5

Already a declared dependency in `ansible/roles/docker/files/apache/webroot/composer.json`:

```json
"zircote/swagger-php": "^5.7"
```

Binary available at `vendor/zircote/swagger-php/bin/openapi`.

---

## What gets annotated

PHP 8 `#[OA\...]` attributes are added at the **HTTP boundary** only (controllers and admin endpoint scripts). The service layer is not annotated.

| File | Annotations |
|---|---|
| `src/OpenApi.php` | `#[OA\Info]`, all `#[OA\Schema]` components, plus phantom route annotations for all endpoints not defined in `UploadController.php` |
| `src/Controllers/UploadController.php` | `#[OA\Post]` on `post()` and `finalize()`, `#[OA\Get]` on `get()` |

Note: all endpoints not in `UploadController.php` are documented via phantom route annotations on the `OpenApi` class in `src/OpenApi.php` rather than inline in their respective PHP scripts. This keeps the procedural scripts clean and the generator command simple. Phantom routes covered: `/media-files`, `/database.php`, `/import_manifest_upload_finalize.php`, `/ai_jobs.php`, `/tags.php`, `/taggings.php`, `/upload-token.php`, `/guest-status.php`, `/guest-gallery.php`, `/guest-stream.php`, `/guest-report.php`, `/guest-delete.php`, `/admin_system_stats.php`.

Schema components defined in `src/OpenApi.php`:
- `File` — base file record
- `UploadResult` — response from `POST /uploads` and `POST /uploads/finalize`
- `MediaEntry` — entry in `GET /db/database.php` list
- `Error` — generic error
- `DuplicateError` — 409 duplicate checksum response
- `ManifestFinalizeResult` — 200 response from admin manifest finalize
- `ManifestFinalizeError` — 400 response from admin manifest finalize (includes `failure_code`, `retryable`, `diagnostics`)
- `AiJob` — AI job queue record
- `Tag` — tag with namespace, name, and usage count
- `Tagging` — tagging record (confidence, source, timestamps)
- `UploadToken` — event context returned by `GET /upload-token.php`
- `GuestVideo` — approved video entry in guest gallery
- `SystemStats` — system stats response from `GET /admin_system_stats.php`

---

## Regeneration workflow

### Setup (once)

Add a `composer` script to `composer.json`:

```json
"scripts": {
    "openapi": "vendor/bin/openapi src/ --output docs/openapi.yaml --format yaml --exclude src/Services/UploadTokenValidator.php"
}
```

### Regenerate

Run from the `ansible/roles/docker/files/apache/webroot/` directory:

```bash
composer openapi
```

The generator scans `src/` only. All specs (including admin and database endpoints) are in `src/OpenApi.php`.

Then commit the updated `docs/openapi.yaml`.

### When to regenerate

Regenerate any time you change:
- Request fields or required/optional status on any endpoint
- Response fields or HTTP status codes
- Error shapes or new error types
- New endpoints or removed endpoints
- Component schema definitions

---

## Known pitfall: duplicate server entries

swagger-php merges global servers with per-operation server overrides rather than replacing them. If a `#[OA\Server]` attribute is placed at the class/file level in `src/OpenApi.php` (alongside `#[OA\Info]`), swagger-php registers it as a global top-level server list. Any path annotation on that same class that also specifies `servers: [...]` ends up with the per-path server **plus** all global servers in the generated YAML — producing 4 server entries per path instead of 1.

**Rule:** do **not** add any `#[OA\Server]` attributes at the class level in `src/OpenApi.php`. Every path annotation must specify its own `servers:` array, and no global server list should be defined. Operations in `UploadController.php` are unaffected because that class carries no class-level `#[OA\Server]` attributes.

**Symptom to watch for:** after regenerating, if any path in `openapi.yaml` shows more than one entry under its `servers:` block, a global `#[OA\Server]` was accidentally re-added to the class.

---

## Accessing the Swagger UI

`api-docs.html` is a static HTML page that loads the Swagger UI JavaScript bundle and fetches `./openapi.yaml` from the same directory. It renders the raw YAML as interactive documentation in the browser. The two files are distinct:

| URL | What you get |
|---|---|
| `https://stagingvm.gighive.internal/docs/api-docs.html` | Interactive Swagger UI — the correct URL to share with developers |
| `https://stagingvm.gighive.internal/docs/openapi.yaml` | Raw YAML download — not useful in a browser |

Both paths are behind `valid-user` auth (see `default-ssl.conf.j2`).

---

## Endpoints documented in the spec

| Method | Path (relative to server) | Server prefix | Auth |
|---|---|---|---|
| `POST` | `/uploads` | `/api` | `admin`, `uploader` |
| `POST` | `/uploads/finalize` | `/api` | `admin`, `uploader` |
| `GET` | `/uploads/{id}` | `/api` | `admin`, `uploader` |
| `POST` | `/media-files` | `/api` | `admin`, `uploader` (iOS alias) |
| `GET` | `/media-files` | `/api` | — (501 Not Implemented) |
| `GET` | `/database.php` | `/db` | `valid-user` |
| `POST` | `/import_manifest_upload_finalize.php` | `/admin` | `admin` only |
| `GET` | `/upload-token.php` | `/api` | `valid-user` |
| `GET`, `POST` | `/ai_jobs.php` | `/api` | `admin` only |
| `GET` | `/tags.php` | `/api` | `admin` only |
| `POST`, `PATCH`, `DELETE` | `/taggings.php` | `/api` | `admin` only |
| `GET` | `/guest-status.php` | `/api` | none (nonce-validated) |
| `GET` | `/guest-gallery.php` | `/api` | none (nonce-validated) |
| `GET` | `/guest-stream.php` | `/api` | none (nonce-validated) |
| `POST` | `/guest-report.php` | `/api` | none (nonce-validated) |
| `POST` | `/guest-delete.php` | `/api` | none (nonce-validated) |
| `GET` | `/admin_system_stats.php` | `/admin` | `admin` only |
