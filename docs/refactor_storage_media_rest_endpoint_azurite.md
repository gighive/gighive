# Storage Media REST Endpoint — Azurite Local Testing

> **Companion document.** Production architecture, deployment phases, PHP class
> skeletons, and Ansible role changes live in:
> - [`refactor_storage_media_rest_endpoint.md`](refactor_storage_media_rest_endpoint.md)
> - [`refactor_storage_media_rest_endpoint_implementation.md`](refactor_storage_media_rest_endpoint_implementation.md)
>
> This document covers everything needed to run the Azure Blob Storage code path
> locally using Azurite — including docker-compose wiring, auth configuration,
> required `AzureBlobRestClient` modifications, and a testing checklist.

---

## What Azurite Is

Azurite is Microsoft's official open-source Azure Storage emulator. It implements
the same Blob Storage REST API that the production code uses (`PUT Block`,
`PUT Block List`, `GET blob`, `HEAD blob`, `DELETE blob`, list blobs with prefix)
and runs as a Docker container on your local machine.

**What it replaces locally:** the real Azure Blob Storage service endpoint
(`https://{account}.blob.core.windows.net`).

**What it does not replace:** Azure Managed Identity / IMDS. Azurite uses a
fixed well-known shared key for auth — there is no VM identity concept locally.
See [Auth: SAS Token Mode](#auth-sas-token-mode) for how `AzureBlobRestClient`
handles this.

**Official image:** `mcr.microsoft.com/azure-storage/azurite`

---

## When to Use Azurite

See the full deployment-type table in the design doc. The short rule:

> **Is `GIGHIVE_MEDIA_STORAGE_BACKEND` set to `azure_blob` or
> `azure_blob_with_local_fallback`, but you are not on a real Azure VM?**
> If yes — use Azurite. If the backend is `local`, Azurite is irrelevant.

Specific scenarios:

- VirtualBox or baremetal host testing the full Azure upload / stream code path
  before committing to a real Azure deployment
- Developer machine (`azure_blob` mode) exercising `AzureBlobRestClient`,
  `AzureBlobTusBackend`, and `AzureBlobMediaBackend` against a real REST server
- Phase 10A transition testing (`azure_blob_with_local_fallback`,
  `FallbackMediaBackend`) — verifying the fallback logic before cutover

Not needed for:

- Local storage deployments (`GIGHIVE_MEDIA_STORAGE_BACKEND=local`) — bind
  mounts and `LocalMediaBackend` / `LocalFileTusBackend` are used instead
- OneShot bundle — filesystem-based; no Blob REST calls
- Real Azure VM deployments — IMDS and the real endpoint are used

---

## Azurite Differences from Real Azure Blob Storage

| Property | Real Azure | Azurite |
|---|---|---|
| Endpoint URL | `https://{account}.blob.core.windows.net/{container}/{key}` | `http://{host}:10000/devstoreaccount1/{container}/{key}` |
| Account name | Your storage account name | Always `devstoreaccount1` |
| Auth | Managed Identity Bearer token via IMDS | SAS token generated from fixed shared key (see below) |
| HTTPS | Required | Optional (HTTP only by default; HTTPS needs cert config) |
| Private endpoint | Required in production after Phase 1 | Not applicable — listens on localhost / LAN |
| Blob block limits | 50,000 blocks per blob, 4000 MiB per block | Same limits enforced |
| Container creation | Via Terraform / Azure portal | Must be created manually before first use (see [Container Setup](#container-setup)) |
| Durability / replication | Azure SLA | In-memory or local disk only; data lost if container removed without a volume |
| `x-ms-version` requirement | Enforced | Enforced unless `--skipApiVersionCheck` flag is used |

---

## Docker Setup

### Standalone (developer machine, outside docker-compose)

```bash
docker run -d \
  --name azurite \
  -p 10000:10000 \
  -v azurite-data:/data \
  mcr.microsoft.com/azure-storage/azurite \
  azurite-blob --blobHost 0.0.0.0 --location /data
```

- Port `10000` — Blob service only (Queue = 10001, Table = 10002; not needed)
- `-v azurite-data:/data` — named volume keeps blobs across container restarts

### Inside docker-compose (VirtualBox / baremetal dev environments)

Add a conditional service block to `ansible/roles/docker/templates/docker-compose.yml.j2`:

```yaml
{% if azurite_enabled | default(false) %}
  azurite:
    image: mcr.microsoft.com/azure-storage/azurite:latest
    container_name: azuriteServer
    command: azurite-blob --blobHost 0.0.0.0 --location /data --skipApiVersionCheck
    ports:
      - "10000:10000"
    volumes:
      - azurite_data:/data
    restart: unless-stopped
{% endif %}
```

Add the volume declaration in the `volumes:` block:

```yaml
{% if azurite_enabled | default(false) %}
  azurite_data:
{% endif %}
```

**Network access from the Apache container:**

The PHP container reaches Azurite via the Docker bridge network using the service
name `azuriteServer` as the hostname:

```
http://azuriteServer:10000/devstoreaccount1/{container}/{key}
```

Set `AZURE_BLOB_ENDPOINT_OVERRIDE` to this value in `.env.j2` when
`azurite_enabled` is true (see [Environment Variables](#environment-variables)).

---

## Auth: SAS Token Mode

Real Azure uses Managed Identity Bearer tokens fetched from IMDS. IMDS does not
exist locally. Azurite uses the Azure Storage **Shared Key** scheme, but
implementing Shared Key auth in PHP requires computing an HMAC-SHA256 signature
over a canonicalized string — complex and not worth building into production code.

The simpler approach is a **SAS token** generated from Azurite's well-known
fixed shared key. A SAS token is just a signed query string appended to the blob
URL — `AzureBlobRestClient` only needs to append it when in SAS mode, with no
new header logic.

### Generating the Azurite SAS token

Azurite's fixed account name and key are always:

```
Account name: devstoreaccount1
Account key:  Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==
```

Generate a container-scoped SAS token with full permissions using the Azure CLI
(install once; does not require an Azure subscription):

```bash
az storage container generate-sas \
  --account-name devstoreaccount1 \
  --account-key "Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==" \
  --name media \
  --permissions racwdl \
  --expiry 2099-01-01 \
  --https-only false \
  --connection-string "DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==;BlobEndpoint=http://127.0.0.1:10000/devstoreaccount1;"
```

Copy the output (a query string starting with `sv=...`) into `AZURE_BLOB_SAS_TOKEN`
in your local `.env`.

**The expiry date `2099-01-01` is intentional for a local dev token.** Do not use
this key or a long-lived token in any non-local environment.

---

## `AzureBlobRestClient` Modifications Required

Two additions needed. Both are gated on env vars so production code paths are
unchanged.

### 1. Endpoint override (`AZURE_BLOB_ENDPOINT_OVERRIDE`)

Azurite uses a different URL structure from real Azure. Add an override to
`blobUrl()`:

```php
public function blobUrl(string $key, string $queryString = ''): string
{
    $override = getenv('AZURE_BLOB_ENDPOINT_OVERRIDE');
    if ($override) {
        // Azurite: http://azuriteServer:10000/devstoreaccount1/{container}/{key}
        return rtrim($override, '/') . '/' . $this->container . '/' . $key . $queryString;
    }
    return "https://{$this->account}.blob.core.windows.net/{$this->container}/{$key}{$queryString}";
}
```

### 2. SAS token auth (`AZURE_BLOB_AUTH_MODE=sas`)

In SAS mode, skip IMDS entirely and append the SAS token as a query string
instead of adding a Bearer header:

```php
public function authHeaders(): array
{
    if (getenv('AZURE_BLOB_AUTH_MODE') === 'sas') {
        // SAS auth is handled in blobUrl() via query string — no auth header needed
        return [
            'x-ms-version: ' . self::API_VERSION,
            'x-ms-date: ' . gmdate('D, d M Y H:i:s T'),
        ];
    }
    // Production: Bearer token from IMDS via AzureIdentityTokenCache
    return [
        'Authorization: Bearer ' . $this->tokenCache->getToken(),
        'x-ms-version: ' . self::API_VERSION,
        'x-ms-date: ' . gmdate('D, d M Y H:i:s T'),
    ];
}
```

Update `blobUrl()` to append the SAS token when in SAS mode:

```php
public function blobUrl(string $key, string $queryString = ''): string
{
    $override = getenv('AZURE_BLOB_ENDPOINT_OVERRIDE');

    if (getenv('AZURE_BLOB_AUTH_MODE') === 'sas') {
        $sas = getenv('AZURE_BLOB_SAS_TOKEN') ?: '';
        // SAS token is the full query string (starts with sv=...)
        $sep = $queryString ? '&' : '?';
        $queryString = ($queryString ?: '?') . $sep . $sas;
        // Strip the double ? if queryString was empty
        $queryString = preg_replace('/^\?&/', '?', $queryString);
    }

    if ($override) {
        return rtrim($override, '/') . '/' . $this->container . '/' . $key . $queryString;
    }

    return "https://{$this->account}.blob.core.windows.net/{$this->container}/{$key}{$queryString}";
}
```

> **Note for implementors:** clean up the `$queryString` concatenation above into
> a `buildQueryString(string $existingQs, string $sasToken): string` private helper
> to keep `blobUrl()` readable. The sketch above shows the intent, not the
> final form.

---

## Container Setup

Azurite does not auto-create containers. Create the `media` container once after
first boot:

```bash
az storage container create \
  --name media \
  --connection-string "DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==;BlobEndpoint=http://127.0.0.1:10000/devstoreaccount1;"
```

Or via PHP (add as an Ansible smoke test or a one-shot setup script):

```php
$url = 'http://127.0.0.1:10000/devstoreaccount1/media?restype=container';
// PUT with shared key or SAS — see AzureBlobRestClient::curl()
```

If using the docker-compose setup, add a one-time container-create step to the
Ansible `docker` role tasks gated on `azurite_enabled`:

```yaml
- name: Create Azurite media container
  community.docker.docker_container_exec:
    container: azuriteServer
    command: >
      sh -c 'az storage container create
        --name {{ azure_blob_container }}
        --connection-string "{{ azurite_connection_string }}"'
  when: azurite_enabled | default(false)
  tags: [azurite]
```

---

## Environment Variables

Add to `.env.j2` under a `{% if azurite_enabled | default(false) %}` block:

```dotenv
# Azurite local blob storage — only set when azurite_enabled=true
AZURE_BLOB_ENDPOINT_OVERRIDE=http://azuriteServer:10000/devstoreaccount1
AZURE_BLOB_AUTH_MODE=sas
AZURE_BLOB_SAS_TOKEN={{ azurite_sas_token }}   # generated once; stored in group_vars secrets
```

And in production `.env.j2` (always):

```dotenv
# Production: do not set these — their absence enables the IMDS/Bearer path
# AZURE_BLOB_ENDPOINT_OVERRIDE=
# AZURE_BLOB_AUTH_MODE=
```

### group_vars additions

`ansible/inventories/group_vars/` — add to a dev-only vars file (e.g.,
`group_vars/devvm.yml` or an `azurite.yml` overlay):

```yaml
azurite_enabled: true
azurite_connection_string: "DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==;BlobEndpoint=http://azuriteServer:10000/devstoreaccount1;"
azurite_sas_token: "<output of az storage container generate-sas — store in secrets>"
```

Set `azurite_enabled: false` (or omit it) in `prod.yml`, `stagingvm.yml`, and
any real Azure group vars file — production never includes the Azurite service.

---

## Files Under Change (Azurite-specific)

| File | Repo | Change |
|---|---|---|
| `ansible/roles/docker/templates/docker-compose.yml.j2` | `gighiveinfra` | Add conditional `azurite` service block and `azurite_data` volume |
| `ansible/roles/docker/templates/.env.j2` | `gighiveinfra` | Add `AZURE_BLOB_ENDPOINT_OVERRIDE`, `AZURE_BLOB_AUTH_MODE`, `AZURE_BLOB_SAS_TOKEN` under `azurite_enabled` guard |
| `ansible/inventories/group_vars/` | `gighiveinfra` | Add `azurite_enabled`, `azurite_connection_string`, `azurite_sas_token` to dev group vars; `false` in prod |
| `ansible/roles/docker/files/apache/webroot/src/Services/AzureBlobRestClient.php` | `gighiveinfra` | Add `AZURE_BLOB_ENDPOINT_OVERRIDE` support to `blobUrl()`; add SAS token auth mode to `authHeaders()` |

---

## Known Limitations

- **No IMDS / Managed Identity.** `AzureIdentityTokenCache::getToken()` is never
  called in SAS mode — the IMDS code path is untested locally. Phase 7 (Managed
  Identity token acquisition) must be verified on a real Azure VM.
- **No private endpoint.** Azurite listens on the Docker bridge network — the
  private endpoint / VNet routing tested in Phase 1 cannot be validated locally.
- **HTTP only by default.** Azurite can be configured for HTTPS with `--cert` /
  `--key` flags but this requires certificate provisioning. HTTP is sufficient
  for local testing; `AzureBlobRestClient` must not enforce HTTPS when
  `AZURE_BLOB_ENDPOINT_OVERRIDE` is set.
- **`apcu.enable_cli=1` still required** for `run_probe_job.php` cron testing —
  this is independent of Azurite.
- **SAS token never expires in this config** (`--expiry 2099-01-01`). Never
  commit the Azurite SAS token to version control; treat the secrets file
  containing it as dev-only.
- **Data persistence depends on the named volume.** `docker volume rm azurite_data`
  destroys all blobs. This is expected behavior for a dev environment.

---

## Testing Checklist

Work through these after the Azurite container is up and the `media` container
has been created.

### Phase 3 — MediaStorageService

- [ ] `GIGHIVE_MEDIA_STORAGE_BACKEND=azure_blob`, `AZURE_BLOB_AUTH_MODE=sas`,
      `AZURE_BLOB_ENDPOINT_OVERRIDE` set — `MediaStorageService::make()` returns
      an `AzureBlobMediaBackend` instance
- [ ] `put()` uploads a test file; blob appears at
      `http://127.0.0.1:10000/devstoreaccount1/media/audio/{key}`
- [ ] `getMeta()` returns correct size and a non-empty ETag
- [ ] `stream()` pipes the correct bytes to stdout
- [ ] `streamRange()` returns the correct byte slice (verify with `Content-Range`
      header and byte-compare the output)
- [ ] `delete()` removes the blob; subsequent `exists()` returns false
- [ ] `list('audio/')` returns the key of the uploaded blob

### Phase 4 — TUS upload flow

- [ ] `POST /files/` with valid `Upload-Length` and `Upload-Metadata` headers →
      201 with `Location: /files/{uuid}`
- [ ] `PATCH /files/{uuid}` (multiple chunks) → 204 on each chunk; `tus_uploads`
      `block_count` increments correctly
- [ ] Final `PATCH` → 204; `tus_uploads.status = complete`; `assets` row inserted;
      `probe_jobs` row inserted with `status = queued`
- [ ] `HEAD /files/{uuid}` after completion → `Upload-Offset = upload_length`
- [ ] SHA-256 in `assets` row matches `sha256sum` of the original file
- [ ] Uncommitted blocks visible in Azurite during upload (use Azure Storage
      Explorer or `az storage blob list --include u`)
- [ ] Committed blob visible in Azurite after final PATCH

### Phase 4 — probe job

- [ ] `run_probe_job.php` claims the queued job; `probe_jobs.status → running → done`
- [ ] `assets.duration_seconds` updated
- [ ] For video: thumbnail blob present at `video/thumbnails/{sha256}.png`

### Phase 5 — media streaming

- [ ] `GET /media/audio/{key}` → 200 with correct `Content-Type` and byte count
- [ ] Range request → 206 with correct `Content-Range`
- [ ] Unauthenticated request → 401

### Phase 10A transition (FallbackMediaBackend)

- [ ] Asset present in Azurite only → `getMeta()` returns metadata (primary hit)
- [ ] Asset present in local bind mount only → `getMeta()` falls back to
      `LocalMediaBackend` and returns metadata (fallback hit)
- [ ] Asset present in both → primary (Azurite) is used; fallback not called
- [ ] `put()` writes to Azurite only; local bind mount unchanged
