# Refactor: SSL Certificate Lifetime — Internal Dev Server (`gighive2.gighive.internal`)

## Problem

The self-signed TLS certificate for internal dev servers is generated **inside the Docker container** at startup (entrypoint.sh.j2):

```bash
if [ ! -f "${CERT_FILE}" ]; then
  openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "${KEY_FILE}" -out "${CERT_FILE}" \
    -config "${SAN_CFG}" -extensions v3_req
fi
```

- `CERT_FILE` = `/etc/ssl/certs/origin_cert.pem` (inside container)
- `KEY_FILE`  = `/etc/ssl/private/origin_key.pem` (inside container)

**Consequence:** Every Ansible rebuild that recreates the container (`docker rm` + new container) generates a fresh key pair. Any iPhone profile trusting the old cert becomes invalid. swcd will fail to fetch the AASA with `TrustResultType=5` until the new cert is re-extracted, re-installed, and re-trusted on the device.

`docker restart` alone is safe — the file exists so the entrypoint skips generation.

---

## Context: Why `CA:TRUE` Is Required

`swcd` (the iOS Universal Links daemon) uses `SWCSecurityGuard` to validate TLS when fetching AASA files directly from `?mode=developer` servers. It explicitly rejects `kSecTrustResultRecoverableTrustFailure` (type 5), which is what trustd returns for a self-signed leaf certificate even when that cert is installed and trusted on the device.

The cert must have `basicConstraints = CA:TRUE, pathlen:0` so trustd treats it as a root CA anchor and returns an accepted trust result. See `docs/problem_iphone_qr_code_redirect.md` Problem 10 for the full log signature and diagnosis.

This was fixed in `openssl_san.cnf.j2` (Jul 2026).

---

## Options

### Option 1 — Do nothing; re-extract after each rebuild *(current approach)*

After any Ansible rebuild of the Apache container:

```bash
# On gighive2
docker cp apacheWebServer:/etc/ssl/certs/origin_cert.pem ~/gighive2_cert.pem
scp ~/gighive2_cert.pem sodo@macbook2025:/Users/sodo/Downloads/gighive2_cert.pem
mv /Users/sodo/Downloads/gighive2_cert.pem /Users/sodo/Downloads/gighive2_cert.crt
```

Then on iPhone: remove old profile → AirDrop new `.crt` → install → Certificate Trust Settings → toggle ON.

**Tradeoff:** ~2 min overhead after each rebuild. Acceptable if rebuilds are infrequent.

---

### Option 2 — Persist cert on host via Docker bind mount *(recommended if rebuilds are frequent)*

Generate the cert once on the host via Ansible using the `openssl_privatekey` + `openssl_certificate` modules (or a `command` task), then mount it into the container read-only. The entrypoint already skips generation if the file exists.

**Ansible task sketch (in the `docker` role):**
```yaml
- name: Generate persistent self-signed CA cert for internal TLS (once)
  community.crypto.x509_certificate:
    path: "{{ docker_dir }}/apache/ssl/origin_cert.pem"
    privatekey_path: "{{ docker_dir }}/apache/ssl/origin_key.pem"
    provider: selfsigned
    selfsigned_not_after: "+3650d"  # 10 years
  register: cert_generated

- name: Generate private key for internal TLS cert
  community.crypto.openssl_privatekey:
    path: "{{ docker_dir }}/apache/ssl/origin_key.pem"
    size: 2048
```

**docker-compose.yml.j2 volume addition:**
```yaml
volumes:
  - "{{ docker_dir }}/apache/ssl/origin_cert.pem:/etc/ssl/certs/origin_cert.pem:ro"
  - "{{ docker_dir }}/apache/ssl/origin_key.pem:/etc/ssl/private/origin_key.pem:ro"
```

The cert now survives container rebuilds. iPhone profile only needs to be reinstalled once (or when the cert is intentionally regenerated).

**Tradeoff:** Requires Ansible role changes + `community.crypto` collection. The `openssl_san.cnf.j2` SAN config also needs to be replicated or templated for the Ansible task.

---

### Option 3 — Use `dev.gighive.app` for Universal Links testing *(lowest friction)*

`dev.gighive.app` has a Cloudflare-issued certificate that:
- Never rotates unexpectedly
- Requires no profile installation on any device
- Is already confirmed working end-to-end for Universal Links

Use `dev.gighive.app` for all QR / Universal Link testing. Reserve `gighive2.gighive.internal` for load testing, database testing, and scenarios that specifically require the internal server.

**Tradeoff:** Cannot test Universal Links against internal-only data or configurations that differ from `dev.gighive.app`.

---

## Recommendation

| Scenario | Recommendation |
|----------|---------------|
| Day-to-day Universal Links / QR dev | **Option 3** — use `dev.gighive.app` |
| Need Universal Links against internal server specifically | **Option 1** — re-extract cert after each rebuild |
| Rebuilds become frequent enough to be disruptive | **Option 2** — persist cert on host |

---

## Quick Reference: Re-extract cert after rebuild

```bash
# 1. On gighive2 — extract new cert
docker cp apacheWebServer:/etc/ssl/certs/origin_cert.pem ~/gighive2_cert.pem

# 2. SCP to Mac
scp ~/gighive2_cert.pem sodo@macbook2025:/Users/sodo/Downloads/gighive2_cert.pem

# 3. On Mac — rename for iOS
mv /Users/sodo/Downloads/gighive2_cert.pem /Users/sodo/Downloads/gighive2_cert.crt

# 4. Verify CA:TRUE in new cert
echo | openssl s_client -connect gighive2.gighive.internal:443 2>/dev/null \
  | openssl x509 -noout -text | grep -A2 "Basic Constraints"
# Must show: CA:TRUE, pathlen:0

# 5. AirDrop gighive2_cert.crt to iPhone
# 6. iPhone: Settings → General → VPN & Device Management → install profile
# 7. iPhone: Settings → General → About → Certificate Trust Settings → gighive.internal → ON
# 8. Delete app → Xcode ▶ reinstall
```
