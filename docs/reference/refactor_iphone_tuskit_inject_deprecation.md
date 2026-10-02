# TUSKit Deprecated Init: Custom URLSession Delegate Injection

## Problem

`TUSUploadClient` triggers a Swift deprecation warning:

```
'init(server:sessionIdentifier:storageDirectory:session:chunkSize:supportedExtensions:
reportingQueue:generateHeaders:)' is deprecated: Use the
init(server:sessionIdentifier:sessionConfiguration:...) initializer instead.
```

The deprecated `session:`-based initializer is **intentionally kept** for the `allowInsecure`
(local dev) path. The reason is documented below.

---

## Why the Deprecated Init Is Required

TUSKit's preferred initializer is:

```swift
TUSClient(server:sessionIdentifier:sessionConfiguration:storageDirectory:
          chunkSize:supportedExtensions:reportingQueue:generateHeaders:)
```

This init constructs its own `URLSession` internally from the provided
`URLSessionConfiguration`. **It does not accept a `URLSessionDelegate`**, so there is no way
to inject `InsecureTrustDelegate` through it.

The deprecated `session:`-based init does accept a fully constructed `URLSession`, which means
a custom delegate (`InsecureTrustDelegate.shared`) can be passed in to bypass TLS certificate
validation on local dev instances with self-signed certificates.

---

## Current Implementation (as of Jun 2026)

```swift
if allowInsecure {
    // Uses deprecated init so InsecureTrustDelegate can be injected.
    let session = URLSession(configuration: cfg,
                             delegate: InsecureTrustDelegate.shared,
                             delegateQueue: nil)
    self.tusClient = try TUSClient(
        server: tusBaseURL,
        sessionIdentifier: "GigHiveTUS",
        storageDirectory: URL(string: "TUS"),
        session: session,           // deprecated path
        ...
    )
} else {
    // Production path: uses the preferred sessionConfiguration: init.
    self.tusClient = try TUSClient(
        server: tusBaseURL,
        sessionIdentifier: "GigHiveTUS",
        sessionConfiguration: cfg,  // preferred path, no deprecation warning
        storageDirectory: URL(string: "TUS"),
        ...
    )
}
```

The `else` (production) path already uses the preferred init and produces no warning.
The `if allowInsecure` path is local-dev-only and keeps the deprecated init.

---

## Refactor Triggers

Migrate away from the deprecated init when **any one** of the following is true:

1. **TUSKit adds delegate support to the new init** — if a future TUSKit release adds a
   `sessionDelegate:` parameter to `init(server:sessionIdentifier:sessionConfiguration:...)`,
   the insecure path can be updated to pass `InsecureTrustDelegate.shared` there.
   Watch: https://github.com/tus/TUSKit/releases

2. **Local dev servers get a trusted certificate** — if `*.gighive.internal` is signed by a CA
   that is trusted by dev devices (see `refactor_iphone_security_insecure_tls_breaks_on_names.md`
   Option 3), `InsecureTrustDelegate` becomes unnecessary and the entire `allowInsecure` TUSKit
   path can be removed.

3. **TUSKit is replaced** — if the upload library changes, redesign without the deprecated path.

---

## Why Not Suppress the Warning Another Way

Swift has no per-call-site deprecation suppression mechanism (no equivalent to Clang's
`#pragma diagnostic push/pop`). The only suppression options are:

- Mark the calling code itself as `@available(*, deprecated)` — propagates the deprecation
  outward, which is worse.
- Per-file `-suppress-warnings` build flag — silences all warnings for the file, reduces signal.
- Accept the warning — chosen approach; it is accurate, intentional, and documented here.

---

## Files Involved

- `GigHive/Sources/App/TUSUploadClient.swift` — contains the conditional init with comment
  referencing this document
- `GigHive/Sources/App/UploadClient.swift` — `InsecureTrustDelegate` definition
