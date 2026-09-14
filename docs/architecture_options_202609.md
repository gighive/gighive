# Architecture Options: GigHive SaaS and Self-Hosted Evolution

## Status — 2026-09-09
Planning / options assessment — architectures ranked for fit; no architecture decision has been made.

**Scope:** Long-term application architecture for GigHive as an event-media platform serving event planners, media librarians, guest contributors, platform operators, iOS users, and self-hosted installations.  
**Related documents:**
- `docs/feature_security_authentication_migration_jwt.md`
- `docs/feature_security_authentication_migration_jwt_implementation.md`
- `docs/refactor_security_authentication_shared_auth_function.md`
- `docs/feature_completed_saas_model_changes.md`
- `docs/ui_role_matrix.html`

---

## Elevator Pitch

GigHive can evolve without committing immediately to a large rewrite. The strongest options preserve today’s working event, upload, gallery, and media workflows while creating stable boundaries for richer web experiences, mobile clients, SaaS tenant isolation, and an eventual Java backend. This document ranks the viable architectures but intentionally leaves the final decision open.

---

## Purpose

Compare long-term architecture options against GigHive’s actual product requirements rather than choosing a framework in isolation. The ranking reflects fit, migration risk, operating burden, self-hosted compatibility, security boundaries, and ability to evolve from the existing PHP application.

---

## Current Architecture

GigHive currently combines:

- Server-rendered PHP pages under `admin/`, `db/`, and `src/Views/`.
- Inline JavaScript for AJAX polling, imports, exports, tagging, uploads, backup/restore, and AI-job status.
- PHP JSON endpoints under `api/`, `admin/`, and `db/`.
- Apache routing and Basic Auth, with a JWT migration planned.
- MySQL metadata storage.
- Local and Azure media-storage backends.
- TUS resumable uploads.
- Background import, media-probe, AI, backup, and export jobs.
- Public and QR-token/nonce guest workflows.
- An iOS client consuming HTTP APIs and media streams.
- A requirement to preserve both self-hosted and multi-tenant SaaS operation.

---

## Architecture Requirements

Any selected architecture must support:

1. Tenant-scoped event, catalog, media, user, role, quota, and billing boundaries.
2. Public QR contribution and gallery access without weakening authenticated administration.
3. Large resumable uploads and direct/object-storage media delivery.
4. Protected downloads, image rendering, and audio/video range requests.
5. Long-running imports, exports, backups, probes, AI jobs, and status reporting.
6. Browser, iOS, administrative, and future integration clients.
7. Local Admin versus Platform Admin authorization.
8. Incremental migration without a high-risk all-at-once rewrite.
9. Operational observability, rollback, backup, and recovery.
10. Continued self-hosted deployment where practical.

---

## Evaluation Criteria

| Criterion | Meaning |
|---|---|
| Product fit | Supports event planning, media librarianship, guest contribution, streaming, and long-running jobs |
| Incremental migration | Can be introduced without replacing every existing workflow at once |
| SaaS tenancy | Supports enforceable tenant boundaries and platform administration |
| Self-hosted compatibility | Can remain deployable outside the managed SaaS environment |
| Browser security | Supports secure sessions, forms, downloads, media, and API calls |
| Mobile/API support | Provides a stable contract for iOS and integrations |
| Java migration | Allows PHP components to be replaced without another client migration |
| Operational burden | Deployment, monitoring, debugging, scaling, and failure-recovery complexity |
| UX flexibility | Supports richer catalog, moderation, upload, tagging, and progress experiences |

No numeric score is assigned because there is not yet production usage or scaling evidence sufficient to justify precise weighting.

---

## Ranked Options

| Rank | Option | Overall fit | Primary reason for rank |
|---:|---|---|---|
| 1 | Hybrid Web/BFF + Modular Application Core | Strongest | Preserves current workflows while creating stable browser and API boundaries for incremental modernization |
| 2 | Modular Server-Rendered Monolith | Strong | Lowest migration and operational risk; can move from PHP to Java MVC while retaining the same product model |
| 3 | Full SPA + API Backend | Moderate/strong | Rich UX and clean client/API separation, but requires a broad rewrite of working browser flows |
| 4 | Headless API-First Platform | Moderate | Strong integration model, but every web workflow becomes a separately maintained API-client concern |
| 5 | Selective Serverless Media Pipeline | Supplementary | Useful for elastic object-storage and media-processing workloads, but incomplete as the primary application architecture |
| 6 | Microservices/Event-Driven Platform | Weak at current scale | Independent scaling is attractive, but current evidence does not justify the operational and consistency costs |

The ranking is a planning assessment, not a decision. It should be revisited when production tenant counts, upload volume, media-processing load, team size, and availability requirements provide measurable evidence.

---

## Rank 1 — Hybrid Web/BFF + Modular Application Core

### Shape

```text
Browser
  -> Web/BFF layer
       -> secure HttpOnly browser session/JWT cookie
       -> server-rendered pages and focused interactive components

iOS / integrations
  -> versioned API
       -> Authorization: Bearer JWT

Modular application core
  -> tenant-scoped events, catalog, users, roles, and media metadata
  -> MySQL
  -> local/object storage and CDN
  -> queued media, AI, import, export, and backup workers
```

### Benefits

- Existing PHP pages can continue while high-value screens modernize incrementally.
- Browser sessions and API tokens use transports appropriate to each client.
- Stable API contracts support iOS and future integrations.
- PHP modules can later be replaced behind the same boundaries by Java services or a Java modular monolith.
- Self-hosted deployments can retain a combined application package.
- Focused interactive components suit upload progress, catalog editing, tagging, moderation, and job dashboards.

### Drawbacks

- Two presentation styles must follow explicit boundaries.
- The BFF and API cannot duplicate authorization or business logic.
- Requires disciplined module ownership and shared tenant-scoping rules.

### Best fit

Incremental SaaS migration with continued self-hosted support and an eventual backend-language transition.

---

## Rank 2 — Modular Server-Rendered Monolith

### Shape

A single deployable application owns page rendering, APIs, authentication, and domain modules. Background workers remain separate processes where required. The implementation could remain PHP initially or move to Java/Spring MVC later.

### Benefits

- Lowest migration and deployment complexity.
- Browser cookies, native forms, downloads, and media routes fit naturally.
- Transactions and tenant-scoped database operations remain straightforward.
- A well-defined modular monolith avoids premature distributed-system complexity.
- Strong self-hosted fit.

### Drawbacks

- Rich interactive experiences require progressive enhancement or embedded frontend components.
- Poor module discipline can recreate the current page/endpoint coupling in another language.
- Independent scaling is limited until specific workloads are extracted.

### Best fit

A small engineering team prioritizing reliability and incremental restructuring over frontend independence.

---

## Rank 3 — Full SPA + API Backend

### Shape

A separately built browser application consumes a versioned API. The backend supplies data and media authorization but does not render application pages. Browser authentication should still use an HttpOnly session/refresh cookie or same-origin BFF rather than persistent localStorage tokens.

### Benefits

- Strong fit for interactive catalog, tagging, moderation, dashboards, and progress views.
- Clear frontend/backend contract.
- Frontend can be delivered independently through static hosting/CDN.
- Java API migration aligns naturally with the architecture.

### Drawbacks

- Requires redesigning every current server-rendered page and native form.
- Downloads, media, refresh, and session behavior still require server coordination.
- Larger testing and accessibility surface.
- Increased build, dependency, and deployment complexity.
- Broad rewrite risks delaying SaaS tenancy and authorization work.

### Best fit

A future point where measured UX needs justify a dedicated frontend and sufficient engineering capacity exists.

---

## Rank 4 — Headless API-First Platform

### Shape

All product capabilities are expressed as versioned APIs. Browser, iOS, partner portals, automation, and future clients are independently developed consumers.

### Benefits

- Strongest external integration and automation model.
- Client implementations remain independent of backend language.
- Supports multiple branded portals or specialized librarian/event-planner interfaces.

### Drawbacks

- Every browser workflow requires explicit API, state, error, and authorization design.
- API compatibility becomes a permanent product commitment.
- Existing PHP pages cannot simply evolve; they become clients or are replaced.
- Operational and testing scope grows before external integration demand has been demonstrated.

### Best fit

A later platform phase with demonstrated partner/integration requirements and multiple independently maintained clients.

---

## Rank 5 — Selective Serverless Media Pipeline

### Shape

The primary application remains modular, while selected workloads use object-storage events, functions, or managed queues for thumbnails, probes, AI classification, and notifications.

### Benefits

- Elastic processing for bursty event uploads.
- Direct-to-object-storage uploads can reduce application-server bandwidth.
- Strong isolation for specific media-processing tasks.

### Drawbacks

- Not a complete architecture for catalog, tenancy, billing, or administration.
- Vendor coupling and local/self-hosted parity become harder.
- Long-running imports, exports, and backup jobs may not fit function limits.
- Distributed tracing and retry behavior add operational complexity.

### Best fit

A selective extension after workload evidence identifies individual bottlenecks—not as the primary GigHive architecture.

---

## Rank 6 — Microservices/Event-Driven Platform

### Shape

Authentication, tenancy, catalog, upload, streaming, processing, AI, search, billing, and notification capabilities become independently deployed services communicating through APIs and events.

### Benefits

- Independent ownership, deployment, and scaling.
- Strong isolation between specialized workloads.
- Technology choices can vary by service.

### Drawbacks

- Distributed transactions and eventual consistency complicate media/catalog correctness.
- Tenant scope and authorization must be propagated and enforced across every service.
- Requires mature observability, tracing, queue management, retries, idempotency, and incident response.
- Self-hosted deployment becomes significantly more difficult.
- Current production scale and team evidence do not justify the cost.

### Best fit

Only after measured load, organizational scale, or availability boundaries prove that a modular monolith cannot meet requirements.

---

## Cross-Cutting Recommendation Independent of Architecture Choice

The following boundaries are useful under every option:

1. Stable Bearer JWT contract for iOS and programmatic API clients.
2. Secure HttpOnly browser authentication for page navigation and same-origin requests.
3. Central credential resolution and role enforcement.
4. Tenant scope enforced inside application services and repositories, not only in UI routes.
5. Versioned API contracts where iOS or external consumers depend on them.
6. Object-storage abstraction and media-stream authorization.
7. Queue-backed, idempotent long-running jobs with observable status and bounded retries.
8. Clear module boundaries for identity, tenants, events, catalog, uploads, media, processing, and platform operations.
9. A strangler migration path that replaces one bounded capability at a time.

---

## Decision Status

No long-term architecture option is selected by this document. Rank 1 is the current best-fit assessment, not approval to implement it. A future decision should be based on measurable production evidence and must receive its own implementation plan and PPRR before code changes begin.

---

## Files Under Change

None. This is an architecture-options assessment only; it authorizes no implementation or configuration changes.
