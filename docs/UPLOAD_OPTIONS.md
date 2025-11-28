# SSL Cert Requirements re:GigHive Upload Options

**Users of GigHive have two deployment options, each with different upload capabilities:**

## Option 1: Direct IP Address (No TLS Certificate)
- **Access:** Web browser only
- **Upload method:** `/db/upload_form.php` (web form)
- **Limitation:** iOS app will NOT work due to Apple's App Transport Security (ATS) requirements
- **Setup:** Point users directly to `https://YOUR_IP/db/upload_form.php`

## Option 2: Domain + Cloudflare (Free TLS Certificate)
- **Access:** Web browser AND iOS app
- **Upload method:** Both web form and native iOS app
- **Requirement:** Domain name + Cloudflare free tier
- **Setup:** 
  1. Create a free Cloudflare account
  2. Add your domain to Cloudflare
  3. Point DNS A record (e.g., `gighive.yourdomain.com`) to your Azure VM IP
  4. Cloudflare automatically provisions a valid TLS certificate
  5. Users can access via `https://gighive.yourdomain.com` in browser or iOS app

**Recommendation:** Use Option 2 (Cloudflare) to enable the full GigHive experience, including the native iOS app for your users.

## Justification for "Disable Certificate Checking" Feature in Iphone App

GigHive is an open-source, self-hosted video management application. Users deploy their own GigHive servers on their own infrastructure (e.g., Azure VMs, private servers).

The "Disable Certificate Checking" toggle is provided for users who are connecting to their own self-hosted servers during initial setup or testing phases. This feature:

- **Is user-controlled and opt-in** - disabled by default
- **Only affects connections to user-specified servers** - not our production infrastructure
- **Is documented as temporary** - our setup guide (UPLOAD_OPTIONS.md) recommends users configure Cloudflare's free TLS certificates for production use
- **Follows security best practices** - users are explicitly warned about the security implications

For production deployments, we recommend users place their servers behind Cloudflare (free tier), which provides valid TLS certificates that work without this toggle.

This feature is essential for the open-source, self-hosted nature of GigHive, allowing users flexibility during setup while encouraging secure configurations for production use.
