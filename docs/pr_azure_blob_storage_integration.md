# PR: Azure Blob Storage Integration (Optional Media Backend)

## Summary

Add an optional Azure Blob Storage backend for GigHive media storage. Default behavior remains local filesystem storage for all deployments. Azure Blob access is performed via HTTPS REST (port 443) and is intended to work over an Azure Private Endpoint.

The design prioritizes:
- loose coupling (Azure-specific logic isolated in a worker / hook layer)
- resilience (uploads do not fail just because Azure is unavailable)
- portability (VirtualBox and Azure VM targets share the same app stack and Ansible model)

## Agreed Requirements

### Functional
- Default media storage backend is **local** for both VirtualBox VM and Azure VM deployments.
- Storage backend selection is configured in Ansible `group_vars`:
  - `MEDIA_STORAGE_BACKEND=local|azure_blob` (default: `local`)
- Azure Blob integration must use HTTPS REST calls over port 443.
- Azure Blob access must support **Private Endpoint** connectivity.
- Both deployment targets (VirtualBox VM and Azure VM) must be able to access the Azure Blob Private Endpoint:
  - Azure VM: native VNet access
  - VirtualBox VM: Point-to-Site VPN into Azure VNet

### Networking / Azure-native Requirements
- Use Azure-native components and guided docs; minimize custom servers.
- P2S VPN approach:
  - Azure VPN Gateway (P2S) using OpenVPN
- DNS for private endpoint must use Azure-native managed service:
  - Azure DNS Private Resolver
- Private endpoint DNS requirements:
  - `privatelink.blob.core.windows.net` private DNS zone linked to VNet
  - clients connected via P2S VPN must resolve `<account>.blob.core.windows.net` to the private endpoint IP (not public)

### Loose Coupling Boundary
- Client upload flow stays unchanged:
  - client uploads to existing GigHive `tusd` endpoint
  - `tusd` writes to local staging storage
- Azure is “behind” the upload hook worker:
  - after upload completion, a hook/worker optionally copies the file to Azure Blob
  - failures in Azure copy do not invalidate the completed upload (upload remains local; worker can retry)
- Azure is “behind” the download proxy:
  - because Blob is private, clients do not download directly from Azure
  - GigHive proxies/streams downloads from Blob to client

## Current System Fit (Ansible + Docker)

### Current relevant components
- Docker Compose template:
  - `ansible/roles/docker/templates/docker-compose.yml.j2`
  - includes `tusd` service:
    - `-upload-dir={{ tusd_upload_dir | default('/data') }}`
    - volume: `tusd_data:{{ tusd_upload_dir | default('/data') }}`
    - hooks directory mounted: `{{ docker_dir }}/tusd/hooks:{{ tusd_hooks_dir | default('/hooks') }}:ro`

### Proposed additions (high level)
- Add an optional “azure blob uploader worker” component that is only enabled when `MEDIA_STORAGE_BACKEND=azure_blob`.
- Add Ansible variables and templates to provide:
  - backend selection env (`MEDIA_STORAGE_BACKEND`)
  - Azure storage account/container settings
  - Azure auth settings (see Auth section)
- Add documentation and sanity checks for VPN + DNS resolution.

## Implementation Plan (Phased)

### Phase 0: Infrastructure prerequisites (Terraform-owned)
This repo should document required Azure resources (and optionally provide Terraform modules if desired):
- Storage Account
- Private Endpoint (Blob) in the VNet
- Private DNS Zone: `privatelink.blob.core.windows.net`
  - linked to the VNet
- Azure VPN Gateway configured for P2S (OpenVPN)
- Azure DNS Private Resolver:
  - inbound endpoint in VNet
  - forwarding ruleset as needed so P2S clients can resolve private zones

Verification steps:
- From Azure VM and from a P2S-connected VirtualBox VM:
  - `nslookup <account>.blob.core.windows.net` -> private IP
  - TCP connectivity to `<account>.blob.core.windows.net:443` succeeds

### Phase 1: Storage backend selection + config wiring (Ansible-owned)
- Add to `ansible/inventories/group_vars/...`:
  - `media_storage_backend: local` (default)
  - Azure blob variables (only required when backend is azure_blob)
- Ensure Docker services get env vars consistently (via existing env templating pattern).

### Phase 2: Upload path (tusd staging -> worker -> blob)
- Keep tusd writing to local staging volume (`tusd_data`).
- Post-finish hook emits a job / calls worker entrypoint.
- Worker responsibilities (when enabled):
  - determine local file path for completed upload
  - upload to Azure Blob over HTTPS (port 443) via private endpoint DNS
  - update DB metadata to indicate blob storage location
  - optionally delete local staging file after successful upload + verification

### Phase 3: Download path (proxy / stream)
- Update GigHive download/stream endpoint(s):
  - if provider is local: read from filesystem as today
  - if provider is azure_blob: read from Azure Blob and stream to client
- No SAS URLs required because blob is private and client cannot access it directly.

### Phase 4: Migration tooling (optional, later)
- A batch job/CLI tool to migrate existing local media to Azure Blob and flip DB pointers.

## Authentication Model (to be finalized)
- Azure VM: prefer Managed Identity + RBAC.
- VirtualBox VM: likely service principal credentials (stored as secrets, injected via env).
- All modes continue to use HTTPS REST (port 443).

## Ansible Changes (roles/files)

### group_vars additions
- Location:
  - `ansible/inventories/group_vars/all.yml` (defaults)
  - plus per-inventory overrides under:
    - `ansible/inventories/group_vars/<inventory>/...`
- Variables to add (names TBD, but expected shape):
  - `media_storage_backend: local|azure_blob`
  - `azure_storage_account_name: ...`
  - `azure_storage_container_name: ...`
  - `azure_auth_mode: managed_identity|service_principal`
  - `azure_tenant_id`, `azure_client_id`, `azure_client_secret` (if service principal)

### docker-compose template changes
- `ansible/roles/docker/templates/docker-compose.yml.j2`
  - Add an optional worker service (enabled only when backend is azure_blob).
  - Add any required volumes/mounts for worker access to the local staging area.
  - Ensure worker receives Azure config env vars.

### new Ansible role(s)
Proposed new role:
- `roles/media_storage_azure_blob` (name TBD)
  - responsibilities:
    - render env files / secrets for the worker
    - install any host prerequisites (if any are needed beyond container)
    - validate DNS resolution and connectivity to blob endpoint (optional health checks)

Optional additional role (if you want clean separation):
- `roles/media_storage_common`
  - defines shared media storage variables, validation, and templates

### files likely to be touched (non-exhaustive)
- `ansible/roles/docker/templates/docker-compose.yml.j2`
- `ansible/inventories/group_vars/all.yml`
- `ansible/inventories/group_vars/*/*` (inventory-specific overrides)
- `ansible/roles/docker/...` (env template locations; depends on existing pattern)
- `ansible/roles/<new role>/tasks/main.yml`
- `ansible/roles/<new role>/templates/*.j2`
- `docs/pr_azure_blob_storage_integration.md` (this doc)

## Open Questions (tracked)
- Exact DB schema changes to represent storage provider + key.
- Exact download endpoint(s) in PHP that must proxy Blob reads.
- Worker implementation language/runtime (bash+curl vs python vs php).
- Retry strategy for failed blob uploads (queue vs re-run hook vs periodic scan).
