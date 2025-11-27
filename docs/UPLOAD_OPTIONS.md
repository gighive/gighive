# GigHive Upload Options

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
