# iOS Insecure TLS: Hostname Connections Fail, IP Connections Work

## Problem

When the app is in **insecure mode** (local dev toggle), connecting to a server by **hostname**
(e.g. `gighive2.gighive.internal`) fails with TLS errors, while connecting to the same server
by **IP address** succeeds. `InsecureTrustDelegate` is active in both cases.

### Log evidence (hostname failure)

```
[TLS][InsecureTrustDelegate][session] usingCredential for host=gighive2.gighive.internal
ATS failed system trust
Connection 67: system TLS Trust evaluation failed(-9802)
TLS Trust encountered error 3:-9802
NSURLErrorDomain Code=-1200 "A TLS error caused the secure connection to fail."
```

Note that `InsecureTrustDelegate` **does** fire and returns `.useCredential` — it is ATS that
independently rejects the connection afterward.

---

## Root Cause: Two Independent Trust Layers

There are two separate systems evaluating TLS trust, and they behave differently for hostnames
vs IP addresses.

### IP address — only one layer runs

Apple explicitly documents that **ATS does not apply to connections made directly to IP
addresses**. So only the URLSession challenge layer is evaluated:

1. URLSession trust challenge → `InsecureTrustDelegate` → `.useCredential` → ✅

### Hostname — both layers run independently

For domain-name connections ATS is active and evaluates the certificate on its own, regardless
of what the URLSession delegate returns:

1. URLSession trust challenge → `InsecureTrustDelegate` → `.useCredential` → ✅
2. **ATS system trust evaluation** → cert root not in trusted CA store → ❌

`InsecureTrustDelegate` cannot override ATS. ATS operates above the `URLSession` callback
layer for named hosts.

---

## Fix Options

### Option 1 — `Info.plist` ATS exception domain (recommended)

Add an `NSExceptionDomains` entry for `gighive.internal` in the app's `Info.plist`:

```xml
<key>NSAppTransportSecurity</key>
<dict>
    <key>NSExceptionDomains</key>
    <dict>
        <key>gighive.internal</key>
        <dict>
            <key>NSExceptionAllowsInsecureHTTPLoads</key>
            <true/>
            <key>NSIncludesSubdomains</key>
            <true/>
        </dict>
    </dict>
</dict>
```

`NSExceptionAllowsInsecureHTTPLoads: true` on an exception domain disables ATS certificate
validation for that domain (not just HTTP — it also relaxes cert checks for HTTPS). Since
`.internal` is unambiguously a private/local network domain, App Store review will not flag it.

**Tradeoff:** The exception is always present in the binary, not just in DEBUG builds.
`Info.plist` does not support conditional compilation. If this is a concern, maintain separate
Debug/Release `Info.plist` files via Xcode build configurations.

### Option 2 — Continue using IP addresses

Works today with zero code or config changes. Only limitation: the friendly hostname cannot be
used; users must enter raw IPs for local dev instances.

### Option 3 — Install a trusted CA on dev devices (proper network solution)

1. Create a self-signed CA.
2. Sign `*.gighive.internal` with it.
3. Install the CA profile on simulator / physical device via Settings → General → VPN & Device
   Management → Trust.

No `Info.plist` changes needed. The OS trusts the cert chain normally so ATS passes without any
exception. Requires infra work to provision and distribute the CA profile to all devs and test
devices.

---

## Recommended Action

Implement **Option 1** (`Info.plist` exception domain) for the next release. It is the lowest
effort, lowest risk fix, and is scoped to the `gighive.internal` domain only.

**Long-term:** consider Option 3 (trusted CA on dev devices) to remove the `Info.plist`
exception entirely and align dev TLS behaviour with production.

---

## Files Involved

- `GigHive/Sources/App/UploadClient.swift` — `InsecureTrustDelegate`
- `GigHive/Sources/App/TUSUploadClient.swift` — TUSKit insecure path
- `GigHive/Sources/App/DatabaseAPIClient.swift` — `makeSession()` insecure path
- `GigHive/Sources/App/MediaPlayerView.swift` — `headDiagnostics` insecure path
- `GigHive/Configs/GigHive.entitlements` / `Info.plist` — where the ATS exception would live
