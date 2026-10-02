# MIME types and download behavior (Apache)

This repo’s Apache configuration is generated from Ansible templates. When you need to make additional file extensions serve with the correct `Content-Type` (and optionally force download in browsers like Chrome), follow the steps below.

## Where to make changes

- **Per-site / SSL vhost rules**: `ansible/roles/docker/templates/default-ssl.conf.j2`
  - Best place for MIME overrides and header behavior that should apply to the GigHive site.
- **Global Apache config**: `ansible/roles/docker/templates/apache2.conf.j2`
  - Primarily includes `mods-enabled/*` and `sites-enabled/*`. It is not where module `LoadModule` lines live in this stack.
- **Module enablement (container build)**: `ansible/roles/docker/files/apache/Dockerfile`
  - Modules are enabled using `a2enmod ...` at build time.

## Confirm required modules are loaded

This stack uses Debian/Ubuntu module loading (`mods-enabled/*.load`), not manual `LoadModule ...` lines.

To confirm module availability in the running container:

```bash
docker exec -it apacheWebServer apache2ctl -M | egrep 'headers|mime'
```

Expected:
- `headers_module (shared)`
- `mime_module (shared)`

If you ever need to enable a missing module, the preferred approach in this repo is to add it to the `a2enmod ...` list in `ansible/roles/docker/files/apache/Dockerfile`.

## Add/override a MIME type for an extension

Add an `AddType` directive (preferably guarded with `<IfModule mod_mime.c>`) in `default-ssl.conf.j2` inside the `VirtualHost *:443` block:

```apache
<IfModule mod_mime.c>
    AddType video/mp2t .m2t .ts
</IfModule>
```

Notes:
- Put these in the SSL vhost so the behavior is clearly scoped to the site.
- Guarding with `<IfModule>` prevents config failures if a module is missing.

## Force “download” instead of inline display

To force browsers to download certain extensions, set `Content-Disposition: attachment` via `mod_headers`:

```apache
<IfModule mod_headers.c>
    <FilesMatch "\.(m2t|ts)$">
        Header set Content-Disposition "attachment"
        Header set X-Content-Type-Options "nosniff"
    </FilesMatch>
</IfModule>
```

Notes:
- `X-Content-Type-Options: nosniff` helps prevent MIME sniffing.
- If `X-Content-Type-Options` is already set globally, adding it again here is harmless but may result in duplicate headers.

## Common MIME types

These are common values you may want (always verify what your clients expect):

- `.m2t`, `.ts`
  - `video/mp2t`
- `.mkv`
  - `video/x-matroska`

Example for MKV:

```apache
<IfModule mod_mime.c>
    AddType video/x-matroska .mkv
</IfModule>

<IfModule mod_headers.c>
    <FilesMatch "\.mkv$">
        Header set Content-Disposition "attachment"
        Header set X-Content-Type-Options "nosniff"
    </FilesMatch>
</IfModule>
```

## Validate behavior

After deploying/restarting Apache, validate headers:

```bash
curl -ikI https://<host>/<path-to-file.ext>
```

You should see:
- `Content-Type: ...` matching your `AddType`
- `Content-Disposition: attachment` if you added the forced-download rule

For auth-protected paths, you can include credentials:

```bash
curl -ikI https://user:pass@<host>/<path-to-file.ext>
```
