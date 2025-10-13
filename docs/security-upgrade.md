
# GigHive Security Upgrade Plan

This document outlines the **next-level security enhancements** planned for the GigHive open-source platform.  
It describes how GigHive **will support** multiple authentication and security options going forward, allowing developers who deploy GigHive locally or in production to choose the best approach for their environment.

---

## 🧭 High-Level Overview

In the near future, GigHive **will support** **two new authentication modes**, each suitable for different deployment needs.  

### 1. **Basic Authentication (Current)**
- Uses `.htpasswd` files to manage usernames and passwords.  
- Best for quick local installs and single-user setups.

### 2. **Local User Authentication (Future)**
- Will store user credentials (bcrypt/argon2) and roles (`viewer`, `uploader`, `admin`) directly in the GigHive database.  
- Will offer a simple web or CLI interface for user management.  
- Ideal for small teams that want to manage accounts securely without an external identity provider.

### 3. **OpenID Connect (OIDC) / OAuth2 (Future)**
- Will allow GigHive to connect to **Google, Microsoft, GitHub**, or a **self-hosted IdP** such as **Keycloak** or **Authentik**.  
- Will provide modern authentication features like **Single Sign-On (SSO)**, **MFA**, and **Passkeys**.  
- Recommended for organizations and multi-user environments.

Each mode will be selectable using environment variables, and GigHive will automatically configure Apache and the backend accordingly.

---

## 🔧 Configuration Overview

You will be able to select and configure the authentication mode using environment variables. Example:

```bash
# Authentication mode: basic | local | oidc
GIGHIVE_AUTH_MODE=basic

# Local-users mode (if chosen)
GIGHIVE_LOCAL_HASH_SCHEME=bcrypt
GIGHIVE_LOCAL_PASSWORD_MINLEN=12

# OIDC mode (if chosen)
OIDC_ISSUER=https://accounts.google.com
OIDC_CLIENT_ID=xxxxxxxxxxxxxxxx
OIDC_CLIENT_SECRET=xxxxxxxxxxxxxxxx
OIDC_SCOPE="openid email profile"
OIDC_REMOTE_USER_CLAIM=email
OIDC_GROUPS_CLAIM=groups
OIDC_ROLE_MAP='{"gighive-admins":"admin","gighive-uploaders":"uploader"}'
OIDC_DEFAULT_ROLE=viewer
```

---

## 🧩 Database Schema Additions (for Local Users and OIDC)

### **users**
| Column | Type | Description |
|---------|------|-------------|
| `id` | INT (PK) | User ID |
| `sub` | VARCHAR(255) | OIDC Subject Identifier (nullable) |
| `email` | VARCHAR(255) | Unique email or username |
| `password_hash` | VARCHAR(255) | bcrypt/argon2 hash (nullable for OIDC users) |
| `created_at` | DATETIME | Timestamp of account creation |
| `disabled` | BOOLEAN | Account status flag |

### **user_roles**
| Column | Type | Description |
|---------|------|-------------|
| `user_id` | INT (FK → users.id) | Linked user ID |
| `role` | ENUM('admin', 'uploader', 'viewer') | User role |

**Role enforcement:**  
The same role-based access model will apply to all authentication modes, ensuring consistent permissions for uploads, admin functions, and viewing.

---

## 🧱 Apache Integration (OIDC Example)

```apache
LoadModule auth_openidc_module modules/mod_auth_openidc.so

OIDCProviderMetadataURL ${OIDC_ISSUER}/.well-known/openid-configuration
OIDCClientID ${OIDC_CLIENT_ID}
OIDCClientSecret ${OIDC_CLIENT_SECRET}
OIDCRedirectURI https://YOUR_HOST/oidc/callback
OIDCScope ${OIDC_SCOPE}
OIDCRemoteUserClaim ${OIDC_REMOTE_USER_CLAIM}
OIDCCryptoPassphrase "replace-with-strong-secret"

<Location "/admin">
  AuthType openid-connect
  Require valid-user
</Location>

<Location "/upload">
  AuthType openid-connect
  Require valid-user
</Location>
```

The GigHive backend will read the following headers:
- `REMOTE_USER` → authenticated email or username
- `OIDC_CLAIM_groups` (or `OIDC_CLAIM_roles`) → maps to internal roles

---

## 🗝️ Minimal Keycloak Realm Export

Below is a minimal **Keycloak realm export** that will be included with GigHive (e.g., `infra/keycloak/realm-gighive.json`).  
This will allow self-hosted users to deploy Keycloak easily and integrate authentication out of the box.

```json
{
  "realm": "gighive",
  "enabled": true,
  "users": [],
  "clients": [
    {
      "clientId": "gighive-web",
      "enabled": true,
      "redirectUris": ["https://YOUR_HOST/oidc/callback"],
      "publicClient": false,
      "protocol": "openid-connect",
      "standardFlowEnabled": true,
      "directAccessGrantsEnabled": false,
      "clientAuthenticatorType": "client-secret",
      "secret": "CHANGE_ME_CLIENT_SECRET"
    }
  ],
  "groups": [
    {"name": "gighive-admins"},
    {"name": "gighive-uploaders"},
    {"name": "gighive-viewers"}
  ],
  "roles": {
    "realm": [
      {"name": "admin"},
      {"name": "uploader"},
      {"name": "viewer"}
    ]
  }
}
```

### Quick start for operators

1. Run Keycloak (Docker or local).  
2. Import `realm-gighive.json`.  
3. Edit client redirect URL and secret.  
4. Set environment variables in `.env`.  
5. Restart GigHive.  

Operators will then have a full OIDC-capable local identity provider with groups and roles.

---

## 🚀 Recommended Default Setup (for future deployments)

| Deployment Type | Recommended Auth Mode | Notes |
|------------------|-----------------------|-------|
| Local developer testing | Basic | No external dependencies |
| Small self-hosted setup | Local Users | DB-managed accounts |
| Team / Corporate environment | OIDC | SSO, MFA, and RBAC from IdP |
| Community demo instance | OIDC (Google/GitHub) | Easy logins for public users |

---

## ✅ Next Steps

- [ ] Add security headers (CSP, Referrer-Policy, etc.)  
- [ ] Enforce HTTPS and enable HSTS in production  
- [ ] Add MFA enforcement for `admin` and `uploader` roles  
- [ ] Provide a CLI command for creating first admin users  
- [ ] Integrate with Keycloak for demo deployments
