# Feature: GigHive SaaS Pricing Model

**Status**: Design locked — beta release defers tier cap enforcement; full enforcement at Step 14 of `feature_saas_model_changes.md`  
**Date**: 2026-08-31  
**Related docs**: `docs/feature_saas_model_changes.md` (Steps 13, 14, 15, 17), `docs/operating_model_costs_azure_vm_blob.md`  
**Depends on**: `docs/feature_security_authentication_migration_jwt_oidc_phase5.md` — federated OIDC auth (Steps 6–8 of `feature_saas_model_changes.md`) must be complete before billing can be enforced. Tenant identity, the `users` table, and the RBAC session gate are all prerequisites for per-tenant subscription tracking.

---

## Elevator Pitch

Right now GigHive requires its own server to run — most event organizers will never set that up. The SaaS version lets anyone create a QR code for their event in minutes, have attendees upload videos on the spot, and then decide whether to keep the gallery. The first 14 days are completely free. After that, organizers who want to keep their memories pay a predictable monthly fee based on how much they store. Occasional users pay per gallery; venues and repeat organizers pay one flat account subscription that covers everything. No technical setup, no servers, just scan and upload.

---

## Overview

GigHive's SaaS pricing is built around the QR code gallery feature: event organizers generate a per-event QR code that allows attendees to upload videos. Every gallery gets a 14-day free trial driven by the exact UTC timestamp of the QR code creation. After 14 days, galleries enter a soft-delete state with a 3-day grace period before permanent deletion. Organizers who pay during the grace period get immediate access restored.

Pricing is **per gallery** for casual users (weddings, graduations, one-off events) and **per account** for frequent organizers (venues, promoters, event businesses).

---

## Beta Release Strategy

The pricing tiers are defined and the lifecycle (14-day trial, soft-delete, hard-delete)
will be enforced from launch. However, **storage tier caps are not enforced during the
beta period** — every paying gallery subscription is billed at a single flat rate
regardless of which tier the usage would eventually fall into.

**What is enforced in beta:**

- 14-day free trial per gallery (Day 11 notification, Day 14 soft-delete, Day 17
  hard-delete) — enforced from day one
- Stripe recurring billing on resurrection or direct upgrade — enforced from day one
- Account lapse wind-down (7-day grace → soft-delete → hard-delete) — enforced

**What is deferred until post-beta:**

- Storage tier differentiation ($20 / $40 / $99.95 per-gallery tiers) — deferred;
  **beta gallery subscription price: $20.00/month for all paying gallery plans** regardless
  of storage used; account-level plans (Account Pro $40.00, Account Studio $99.95) are not
  collapsed and bill at their full published prices
- Storage cap enforcement at upload time (Step 13 quota tracking) — deferred
- Concurrent free gallery cap (2 per tenant) — deferred; relax during beta to reduce
  friction and observe natural usage patterns
- Gallery creation rate limit — deferred for same reason

**What to measure during beta:**

- **[billing_events table]** Actual storage per gallery at the moment of conversion — query `storage_bytes WHERE transition = 'subscription_created'`

- **[billing_events table]** Distribution of gallery sizes at hard delete — query `storage_bytes WHERE transition = 'hard_deleted'`; reveals whether tier boundaries ($20/<500 GB, $40/<1 TB, $99.95/<5 TB) are set at the right breakpoints

- **[billing_events table]** Resurrection rate — `COUNT(*) WHERE transition = 'resurrected'` divided by `COUNT(*) WHERE transition = 'soft_deleted'`; what fraction of lapsed galleries are recovered during the grace period

- **[billing_events table]** Notification-to-conversion lag — join `transition = 'notification_sent'` with `transition = 'subscription_created'` on `event_id`; how many days after the Day 11 notification do users typically pay

- **[DB query — events.billing_status]** Number of concurrent active free galleries per tenant — `SELECT tenant_id, COUNT(*) FROM events WHERE billing_status = 'active' GROUP BY tenant_id`; informs whether the cap of 2 is too tight or too loose

- **[Stripe dashboard]** Conversion rate — free galleries that become paid subscriptions before Day 17; Stripe subscription `created_at` cross-referenced with `events.created_at`

- **[Stripe dashboard]** MRR, churn rate, payment failure rate, and failed payment recovery rate — native Stripe analytics, no DB work required

- **[Azure Monitor / Blob Storage Metrics]** Total aggregate storage consumed across the platform — available natively in Azure Monitor; use to track infrastructure cost trajectory against revenue

This data directly informs whether the tier boundaries ($20/<500 GB, $40/<1 TB,
$99.95/<5 TB) are set at the right breakpoints, and whether the concurrent gallery cap
of 2 needs to be tighter or looser before full enforcement begins.

---

## Primary Use Case and Scope

**In scope:**

- Per-gallery recurring subscriptions for casual event organizers
- Account-level recurring subscriptions for frequent organizers
- Automatic 14-day free trial per QR code gallery with expiry lifecycle
- Grace period resurrection (Days 14–17)
- Account lapse wind-down sequence
- TAR download (free) and Azure Blob export (paid add-on / included in top tier)

**Out of scope:**

- Custom domain support (deferred — `feature_saas_model_changes.md` Step 20)
- Per-event public/private visibility toggle (Step 11)
- Attendee-facing billing or payments — pricing applies to organizers only
- Self-hosted (`SAAS_MODE=false`) installs — billing is not enforced in self-hosted mode

---

## Dependencies

The billing lifecycle cannot be implemented before the following SaaS steps from
`feature_saas_model_changes.md` are in place:

| Step | What it provides | Why required |
|---|---|---|
| Step 6 | Wildcard subdomain routing | Tenant identity in every request; billing must know which tenant owns a gallery |
| Step 7 | OIDC federation (Google, Microsoft, Apple) | Verified identity per tenant; JIT user provisioning; ToS acceptance timestamp |
| Step 8 | RBAC middleware; `SAAS_MODE` gating | Session-enforced `tenant_id`; per-request auth context that billing state checks depend on |
| Step 13 | Storage quota tracking | Per-tenant bytes used; required to enforce tier caps at upload time |

**Stripe** is already provisioned — the same account used for the hats storefront (Chase
business checking) will be used for gallery and account subscriptions. No new merchant
account setup required.

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Billing unit | Per gallery OR per account | Casual users pay only for galleries they keep; frequent organizers pay one flat rate |
| Trial period | 14 days from QR code `created_at` (UTC) | Covers the full post-event window when video value is highest; timestamp precision eliminates end-of-day ambiguity |
| Notification trigger | Day 11 | Gives 3 full days to decide before access cuts off; last-day notices get ignored |
| Soft delete before hard delete | Yes — 3-day grace period | Reduces churn from users who miss the Day 11 notification; resurrection revenue justifies the 3-day blob storage carry cost |
| Resurrection billing | Cycle starts from payment date | Simpler Stripe integration; user gets a clean monthly anchor date |
| Account lapse grace | 7 days before galleries enter soft-delete | Long enough to recover from a failed payment card without losing content; short enough to limit unpaid storage carry |
| Hard delete timing | Day 17 from `created_at` (gallery) / Day 10 from lapse (account) | Deterministic, operator-runnable as a nightly cron against UTC timestamps |
| TAR download | Free | Basic data portability right; large libraries make browser download impractical anyway, creating a natural upsell to Azure Blob export |
| Azure Blob export | Included in Account Studio and Gallery Max; +$5/month optional add-on for Gallery Starter, Gallery Pro, and Account Pro | Already implemented (`feature_azure_blob_export.md`); professional workflow feature; Gallery Max and Account Studio both at the $99.95 price point — including it at both is the consistent choice; add-on is a Stripe subscription item, cancellable after a single export session |

---

## Pricing Tiers

### Per-Gallery Plans

Each QR code gallery billed independently. Recurring monthly, cancel any gallery individually.
Storage cap applies per gallery.

| Plan | Price/month | Storage cap | Constraints | Best for |
|---|---|---|---|---|
| Gallery Free | Free | None enforced (beta) | Max 2 concurrent active galleries per account; 14-day active window per gallery | Trying GigHive; events where content is only needed short-term |
| Gallery Starter | $20.00 | < 500 GB | — | Small events, personal use |
| Gallery Pro | $40.00 | < 1 TB | — | Large single events |
| Gallery Max | $99.95 | < 5 TB | — | High-volume events |

> **Gallery Free is the product, not a trial.** Organizers on Gallery Free are not on
> a countdown to a required paid plan — they are using GigHive's free tier. The 14-day
> expiry is the built-in constraint of that tier. Upgrading to a paid plan is the
> organizer's choice if they want to keep the gallery beyond 14 days; it is never
> mandatory.

> **TAR download on Gallery Free is available during the active window only (Days 0–14).**
> Once the gallery enters soft-delete at Day 14, files are inaccessible and the TAR
> download is unavailable until the gallery is resurrected via a paid subscription.
> There is no download path during the grace period (Days 14–17) without paying first.

### Account Plans

One subscription covers all galleries on the account. Storage cap is the total across all
active galleries. Individual galleries cannot be cancelled separately — they live and expire
with the account subscription.

| Plan | Price/month | Total storage cap | Azure Blob export | Best for |
|---|---|---|---|---|
| Account Pro | $40.00 | 1 TB total | +$5.00/month add-on | Frequent organizers, small venues |
| Account Studio | $99.95 | 5 TB total | Included | Venues, promoters, event businesses |

### Data Export

| Feature | Price |
|---|---|
| TAR archive download | Free |
| Azure Blob Storage export | Included in Gallery Max and Account Studio; +$5.00/month add-on for Gallery Starter, Gallery Pro, and Account Pro; not available on Gallery Free |

---

## Plan Comparison

| | Gallery Free | Gallery Starter | Gallery Pro | Gallery Max | Account Pro | Account Studio |
|---|---|---|---|---|---|---|
| **Price/month** | Free | $20 | $40 | $99.95 | $40 | $99.95 |
| **Storage cap** | None enforced (beta) | 500 GB per gallery | 1 TB per gallery | 5 TB per gallery | 1 TB total | 5 TB total |
| **Concurrent gallery limit** | 2 active at any time | Unlimited | Unlimited | Unlimited | Unlimited | Unlimited |
| **Active window** | 14 days per gallery | Unlimited (paid) | Unlimited (paid) | Unlimited (paid) | Unlimited (paid) | Unlimited (paid) |
| **Free trial per gallery** | This IS the plan | Yes — 14 days | Yes — 14 days | Yes — 14 days | Yes — 14 days† | Yes — 14 days† |
| **Cancel granularity** | N/A | Per gallery | Per gallery | Per gallery | Whole account | Whole account |
| **Expiry on cancel** | Day 14 soft-delete; Day 17 hard-delete | Gallery stops billing; content persists until next renewal lapses | Same | Same | 7-day account grace then all galleries soft-delete | Same |
| **Azure Blob export** | Not available | +$5/month add-on | +$5/month add-on | Included | +$5/month add-on | Included |
| **TAR download** | Free (within 14-day window only) | Free | Free | Free | Free | Free |
| **Billing model** | None | Per Stripe subscription per gallery | Same | Same | Single Stripe subscription | Same |

> †**Account plan free trial clarification**: the 14-day trial applies to galleries created
> *before* an account subscription is established (i.e., the organizer had a free gallery
> and decided to upgrade). Galleries created *under an active account subscription* are
> covered from Day 0 and do not have individual expiry timers — they remain active as long
> as the account subscription is active. On account lapse, the 7-day account grace period
> takes precedence over any individual gallery timer regardless of each gallery's age.

---

## Azure Blob Storage Export Add-on

The Azure Blob export feature streams media files from GigHive's storage directly into the
customer's own Azure Blob Storage account using a SAS token the customer provides. GigHive
is the transfer agent — the files land in the customer's account, not in a GigHive-managed
destination. See `feature_azure_blob_export.md` for the full implementation.

### Add-on pricing summary

| Plan | Azure Blob export |
|---|---|
| Gallery Free | Not available |
| Gallery Starter ($20/month) | +$5.00/month optional add-on |
| Gallery Pro ($40/month) | +$5.00/month optional add-on |
| Gallery Max ($99.95/month) | Included |
| Account Pro ($40/month) | +$5.00/month optional add-on |
| Account Studio ($99.95/month) | Included |

### Why Gallery Max includes it

Gallery Max and Account Studio are priced identically at $99.95/month. Charging a separate
add-on for Azure export on Gallery Max while including it in Account Studio at the same
price point would be an inconsistency that is difficult to explain to customers. Including
it in Gallery Max is the correct alignment at the $99.95 price point.

### Add-on lifecycle (Stripe model)

The Azure export add-on is a Stripe subscription item attached to the existing gallery or
account subscription. Customers activate it when they want cloud export capability and may
cancel it independently at any time.

Because a full export of a typical gallery completes in a single session, most users will:
1. Activate the add-on ($5 added to next billing cycle)
2. Configure their Azure SAS token in tenant settings
3. Run the export
4. Cancel the add-on

This results in a total cost of $5 for one full export session — intentionally low-friction.
The monthly model is preferred over a one-time charge because Stripe handles it as a
standard subscription item with no custom invoicing logic required.

### Infrastructure cost reference

GigHive's cost is Azure outbound bandwidth. The rate depends on whether GigHive's own
storage has migrated to Azure (Step 19 of `feature_saas_model_changes.md`) or still runs
on local VMs:

| Transfer scenario | Cost per GB | Typical gallery (60 GB) | Large gallery (400 GB) |
|---|---|---|---|
| Same Azure region (post-Step 19) | ~$0 (intra-datacenter) | ~$0 | ~$0 |
| Cross-region within Azure (post-Step 19) | ~$0.02/GB | ~$1.20 | ~$8.00 |
| Local VM → Azure internet egress (**pre-Step 19 — current default**) | ~$0.087/GB | ~$5.22 | ~$34.80 |

> **Pre-Step 19 note**: GigHive's media files currently live on local VMs, not Azure. Until
> Step 19 migrates storage to Azure Blob, every export goes out over the internet at the
> $0.087/GB egress rate — the worst-case row in the table above is the *current* default.
> At this rate, a 60 GB Gallery Starter export costs ~$5.22 in egress against $5.00 in
> add-on revenue — already at break-even. **The $5/month add-on price is financially sound
> post-Step 19 only.** Options: (a) defer offering the add-on until Step 19 is complete,
> or (b) offer it pre-Step 19 as a subsidised beta feature and accept the thin margin,
> tracking real egress costs via Azure Monitor to inform the post-Step 19 pricing review.

Cloudflare fronts GigHive's media layer for streaming (reducing CDN egress), but a bulk
export bypasses Cloudflare and draws directly from blob storage — the Azure egress rate
applies in full.

**Margin analysis at $5/month add-on (post-Step 19, cross-region):**
- Gallery Starter typical export (60 GB): ~$1.20 cost → ~$3.80 margin
- Gallery Pro typical export (200–400 GB): ~$4–$8 cost → break-even to slight loss
- Gallery Pro worst case (near 1 TB): ~$20 cost → ~$15 loss — if observed in practice, raise Gallery Pro add-on to $8–$10
- Gallery Max and Account Studio: included in base plan; Azure lifecycle tiering (Cool/Cold)
  is already required for those tiers (see Infrastructure Cost Notes), which also reduces
  export egress cost since files are served from their current storage tier

**If egress costs on Gallery Pro prove problematic in practice**, the add-on price for
Gallery Pro can be raised to $8–$10/month independently without touching other plans. Track
real export egress costs via Azure Monitor once the feature is live under SaaS billing.

### SaaS implementation delta vs. current self-hosted implementation

The current Azure export (`feature_azure_blob_export.md`) reads credentials from `.env` —
a single server-level credential set. In SaaS mode, credentials are per-tenant. Required
changes at Step 14 of `feature_saas_model_changes.md`:

- Azure credentials (`account_name`, `container`, `sas_token`) stored encrypted per tenant
  (new columns on `tenants` or a `tenant_settings` JSON column)
- Export feature availability gated by a per-tenant flag set by the Stripe webhook when the
  add-on subscription activates or deactivates
- Export worker receives `tenant_id`, fetches credentials from DB rather than `.env`
- The customer enters their SAS token via the tenant settings page (Step 12); GigHive never
  displays it back in plaintext after save

These are deferred data model items not in the Phase 1 DDL. They are tracked as Step 14
implementation tasks.

---

## Gallery Lifecycle (Free Trial)

Every new QR code gallery starts a free 14-day trial. All lifecycle transitions are
evaluated against the gallery's `created_at` timestamp (UTC) stored on
`event_upload_tokens`. A nightly scheduled worker evaluates `NOW() >= created_at + INTERVAL N DAY`
for all galleries without an active paid subscription.

```
Day 0       QR code created — lifecycle clock starts from exact created_at (UTC)
            Gallery fully active; attendees can upload

Day 11      Notification to organizer:
            "Your [Event Name] gallery expires in 3 days — X videos uploaded.
             Upgrade to keep them."

Day 14      Access cut off → soft-delete state
            · Gallery visible in organizer UI: "Johnson Wedding — expired"
            · Files inaccessible; still present in blob storage
            · Prominent upgrade CTA shown

Days 14–17  Grace period (3 days)
            · Organizer can pay to resurrect at any point
            · Access restored immediately on successful payment
            · Stripe billing cycle starts from the payment date

Day 17      Hard delete
            · Blob storage purged; all files permanently removed
            · Gallery record tombstoned in the database
            · No further resurrection possible
```

### Lifecycle Timing Reference

| Transition | Condition |
|---|---|
| Notification | `NOW() >= created_at + INTERVAL 11 DAY` AND no active subscription AND `NOT EXISTS (billing_events row with transition = 'notification_sent' for this event_id)` |
| Soft delete | `NOW() >= created_at + INTERVAL 14 DAY` AND no active subscription |
| Hard delete (free-trial / paid-subscription expiry) | `billing_status = 'soft_deleted'` AND `soft_deleted_at <= NOW() - INTERVAL 3 DAY` |
| Hard delete (account lapse) | `billing_status = 'soft_deleted'` AND `soft_deleted_at <= NOW() - INTERVAL 3 DAY` |

> **Note**: both hard-delete paths use the same condition — `soft_deleted_at + 3 days` —
> which is correct for all cases: free-trial expiry soft-deletes at Day 14 (hard-delete at
> Day 17), account lapse soft-deletes at lapse Day 7 (hard-delete at lapse Day 10), and
> paid-subscription expiry soft-deletes at subscription end (hard-delete 3 days later).
> Using `created_at + INTERVAL 17 DAY` as the hard-delete anchor is only valid for the
> free-trial path; for any gallery that survived past Day 17 on a paid plan, that condition
> would fire immediately on soft-delete with no grace period.
>
> `soft_deleted_at` is a new `datetime` column added to `events` (see Data Model
> Requirements below). The nightly worker sets it when writing `billing_status = 'soft_deleted'`.

---

## Account Plan Lapse

When an Account Pro or Account Studio subscription lapses (cancellation or payment failure),
all galleries under the account enter a wind-down sequence. The 7-day grace IS the warning
period — galleries do not receive a fresh 14-day free clock on lapse.

```
Day 0       Subscription lapses; full access continues

Days 0–7    Grace period — organizer notified to update billing
            All galleries remain fully accessible

Day 7       All galleries → soft-delete simultaneously
            Access cut off; files still in blob storage

Days 7–10   3-day resurrection window
            Reinstate account subscription → all galleries immediately restored

Day 10      Hard delete
            Blob storage purged for all galleries under the account
```

---

## Abuse Vectors and Mitigations

Understanding how the free trial and resurrection mechanics can be exploited is required
before billing implementation begins. Each vector below is rated by likelihood and
carries a concrete mitigation.

### 1. Free Storage Cycling (High Likelihood)

**Vector**: Create a gallery, upload content, let it expire at Day 17, immediately create
a new gallery and re-upload the same content. Repeat indefinitely to maintain perpetual
free storage. The attacker needs only to retain their downloaded content between cycles.

**Impact**: Unbounded free blob storage consumption in 17-day rolling windows; nightly
hard-delete cleanup keeps per-cycle cost bounded but aggregate cost across many such
accounts is real.

**Mitigations**:
- **Concurrent active free gallery cap: 2 per tenant** (locked). Prevents accumulation
  without blocking legitimate multi-event use.
- Rate-limit gallery creation per account (max 3 new galleries per rolling 30 days on
  a free tenant).
- OIDC federated login (Step 7) significantly raises the barrier — see SSO Impact on
  Abuse Vectors below.

### 2. Multi-Account Farming (Medium Likelihood → Low with OIDC)

**Vector**: Register unlimited free accounts with throwaway email addresses to multiply
the free storage allowance across accounts. Each account gets its own concurrent gallery
cap and creation rate limit.

**Impact**: Multiplies the per-account caps by however many accounts the attacker creates.

**Mitigations**:
- **OIDC federated login (Step 7) is the primary mitigation.** When sign-up requires a
  Google, Microsoft, or Apple account, there are no throwaway email addresses — the
  attacker must create real identity provider accounts. Google, Microsoft, and Apple all
  apply their own fraud detection, phone verification, and rate limits to new account
  creation. This collapses the farming vector from "trivial with a temp email service"
  to "requires meaningful effort per account."
- Rate-limit gallery creation per IP address and device fingerprint during the free tier.
- Monitor for accounts with identical upload fingerprints (same file hashes, same
  upload IP) across multiple tenants — flag for manual review.

### 3. QR Code Upload Flooding (Medium Likelihood)

**Vector**: The QR code upload endpoint is publicly accessible by design — attendees do not
need accounts. A bad actor with a valid QR code URL (obtained legitimately or leaked) can
programmatically POST large files to flood a gallery's storage, burning through the tier
cap or degrading service for the legitimate owner.

**Impact**: Gallery storage exhausted; organizer hits their cap or incurs overage costs;
potential for blob storage cost spike.

**Mitigations**:
- Enforce maximum file size per upload (already expected from the upload implementation).
- Enforce a per-gallery total upload cap (equal to the gallery's storage tier cap).
- Rate-limit uploads per QR token — max N uploads per minute per token.
- Require ToS checkbox on the anonymous upload form (already designed in
  `feature_completed_iphone_qr_code_support.md`) — does not stop bots but establishes
  legal accountability.
- Organizer can revoke the QR token at any time, cutting off further uploads immediately.

### 4. Grace Period Download + Cancel (Low Concern)

**Vector**: Organizer lets gallery expire (Day 14), pays to resurrect on Day 16,
immediately downloads all content via TAR, then cancels the subscription. Total spend:
one month's subscription.

**Impact**: Minimal — the organizer paid for a full billing month. TAR download is
intentionally free. This is by design, not abuse.

**Mitigation**: None required. This is an acceptable use of the system. The minimum
spend for content retrieval is one month's subscription fee.

### 5. Chargeback Fraud (Low Likelihood)

**Vector**: Organizer pays to resurrect a gallery (Day 14–17), downloads all content,
then files a credit card chargeback ("did not recognize charge" or "service not rendered").

**Impact**: Revenue loss on the resurrected gallery; Stripe chargeback fee (~$15).

**Mitigations**:
- Stripe's built-in dispute management provides evidence submission.
- Log the resurrection event, payment timestamp, and subsequent file access events —
  these form the evidence package for dispute resolution.
- Consider a terms-of-service clause explicitly stating that resurrection is a
  non-refundable digital content access event.
- Repeated chargebacks from the same tenant trigger account suspension (manual operator
  action via the superadmin console, Step 16).

### 6. Free CDN / Streaming Abuse During Trial (Low-Medium Likelihood)

**Vector**: A gallery is created on Day 0. The organizer (or a third party) embeds the
gallery link publicly and drives heavy repeated streaming traffic during the free 14-day
window. Azure egress costs are absorbed by GigHive at no charge to the user.

**Impact**: Egress cost spike on galleries that never convert to paid subscriptions.

**Mitigations**:
- Cloudflare in front of blob storage (Step 6 of `feature_saas_model_changes.md`) absorbs
  most streaming egress cost; this is the primary mitigation and should be in place
  before launch.
- Rate-limit streaming requests per gallery per hour on the application layer.
- Monitor per-gallery egress during the free period; flag outliers for review.

### 7. Storage Tier Straddling (Structural Pricing Pressure)

**Vector**: A customer consistently stores 499 GB in a Gallery Starter subscription
($20/month), staying permanently just under the 500 GB cap.

**Impact**: At full 499 GB utilization, Azure Hot LRS blob cost is ~$9.00/month. Adding
shared compute (~$1.50), Stripe fees (~$0.88), and Cloudflare amortized (~$0.50) gives
a total cost of ~$11.88 against $20.00 revenue — approximately **40% net margin at
worst-case utilization**. This is a healthy margin, not a thin one. The average Gallery
Starter customer will have 10–50 GB of event video (single event), where margin exceeds
85%. The Gallery Starter tier is economically solid.

The tier that actually requires lifecycle tiering is **Gallery Max / Account Studio at
5 TB on Hot LRS only** — blob cost alone reaches ~$92 against $99.95 revenue, leaving
~$8 before all other costs. That tier is unprofitable without Cool/Cold lifecycle policy.

**Mitigation**: Azure lifecycle management policy (Cool/Cold tiering) is required before
launch for Gallery Max and Account Studio tiers only. Gallery Starter and Gallery Pro
are profitable at full utilization without it. See Infrastructure Cost Notes.

### SSO / Federated Auth Impact on Abuse Vectors

OIDC federated login (Step 7 — Google, Microsoft, Apple) is not just an auth upgrade;
it is a meaningful fraud control layer. Summary of impact per vector:

| Vector | Without OIDC | With OIDC |
|---|---|---|
| Free storage cycling (§1) | Easy — any email creates an account | Still possible with one real account; mitigated by concurrent gallery cap + rate limit |
| Multi-account farming (§2) | Trivial with disposable email | Hard — requires real Google/Microsoft/Apple accounts with IdP-enforced verification |
| Chargeback fraud (§5) | Pseudonymous attacker | Real IdP identity tied to payment; stronger dispute evidence for Stripe |
| Bot registration | Automated with temp email APIs | Requires real IdP accounts; IdP bot detection applies |

OIDC does **not** help with QR upload flooding (§3) or free CDN streaming abuse (§6) —
those vectors originate from the intentionally anonymous attendee upload path and are
mitigated by rate limiting and Cloudflare respectively.

---

## Infrastructure Cost Notes

These inform margin and backend architecture decisions. Not customer-facing.

### Azure Blob Storage cost reference (Hot LRS, East US, 2026)

| Storage | Blob-only cost/month |
|---|---|
| 500 GB | ~$9.22 |
| 1 TB | ~$18.43 |
| 5 TB | ~$92.16 |

### Gross blob margin at max utilization (before compute and egress)

| Plan | Price | Max blob cost (Hot LRS) | Gross blob margin |
|---|---|---|---|
| Gallery Starter | $20.00 | ~$9.22 | ~$10.78 (54%) |
| Gallery Pro / Account Pro | $40.00 | ~$18.43 | ~$21.57 (54%) |
| Gallery Max / Account Studio | $99.95 | ~$92.16 | ~$7.79 (8%) — requires lifecycle tiering |

### Real-World Storage Example

**Scenario**: 200 attendees at an event; 15 people upload 5 videos each; each video is
3 minutes long at 4K resolution.

4K 30fps file size varies by codec and device:

| Codec | MB/min | Typical device |
|---|---|---|
| HEVC / H.265 | ~170 MB/min | iPhone 11+ (High Efficiency default) |
| H.264 | ~375 MB/min | Older iPhones, many Androids |
| Practical average (mixed crowd) | ~250 MB/min | Blended estimate |

**Per video (3 minutes):** 510 MB (HEVC) → 750 MB (average) → ~1.1 GB (H.264)

**Per person (5 videos):** 2.55 GB → 3.75 GB → ~5.5 GB

**Total for 15 uploaders:**

| Case | Raw uploaded | With transcoded copy stored alongside |
|---|---|---|
| All HEVC (low) | ~38 GB | ~76 GB |
| Mixed (mid) | ~56 GB | ~112 GB |
| All H.264 (high) | ~83 GB | ~166 GB |

This event fits comfortably inside the **Gallery Starter tier ($20/month, < 500 GB)**.

Actual Azure blob cost at 60 GB (typical mid case): 60 × $0.018 = **$1.08/month**.
Revenue: $20.00. Net margin on this gallery after all costs: **~$17**.

The 500 GB cap is not a constraint for a typical event — it represents headroom for
roughly 7–13 events worth of uploads at this scale before the tenant would need to
upgrade. The Gallery Starter tier is priced conservatively well relative to real-world
event video volume.

---

### Required infrastructure optimizations before launch

#### 1. Azure Blob Storage Lifecycle Tiering (Cool / Cold)

Azure Blob Storage has five tiers. The tiers relevant to GigHive are:

| Tier | Cost/GB/month | Min retention | Retrieval cost | Use |
|---|---|---|---|---|
| Hot | $0.018 | None | Free | Active galleries — current default |
| Cool | $0.010 | 30 days* | $0.01/GB | Content 30–90 days old |
| Cold | $0.0045 | 90 days* | $0.03/GB | Content 90+ days old |
| Archive | $0.00099 | 180 days | Hours to retrieve | Not suitable for live galleries |

*Minimum retention means: if a blob in Cool is deleted before 30 days, you are charged
as if it stayed for the full 30 days. Same logic applies to Cold at 90 days. For
free-trial galleries hard-deleted at Day 17, content will always be in Hot (< 30 days
old) so no early-deletion penalty applies.

**Recommended lifecycle policy:**

- Day 0–30: Hot (active post-event viewing window)
- Day 31–90: transition to Cool automatically via Azure lifecycle management rule
- Day 91+: transition to Cold

At 80% Cool + 20% Hot, the 5 TB worst-case cost drops from ~$92 to ~$59/month.
At 80% Cold + 20% Hot, it drops further to ~$26/month. No user-visible impact — the
Azure lifecycle policy moves blobs transparently and retrieval from Cool/Cold is fast
(sub-second, unlike Archive which takes hours).

**This optimization is required before launch for Gallery Max and Account Studio tiers.**
Implement as an Azure lifecycle management policy rule on the blob container — no code
changes needed, just an Azure portal configuration step.

#### 2. Cloudflare Plan and Cost

Cloudflare pricing is **per zone (domain)**, not per account. `gighive.app` is one zone.

| Plan | Monthly cost (per zone) | Annual cost |
|---|---|---|
| Free | $0 | $0 |
| Pro | $25/month | ~$240/year (~20% off) |
| Business | $250/month | ~$2,400/year (~20% off) |

**What each plan provides for GigHive SaaS:**

- **Free** — wildcard DNS, shared SSL certificate (covers `*.gighive.app`), CDN caching,
  DDoS protection, Bot Fight Mode. Sufficient for early launch.
- **Pro ($20–25/month)** — Super Bot Fight Mode, 20 custom WAF rules, image optimization.
  Recommended once paying customers are active.
- **Business ($200–250/month)** — Advanced WAF (100 custom rules), PCI DSS 4.0
  compliance, 100% uptime SLA with service credits, advanced bot analytics. Required
  if PCI compliance becomes a contractual obligation or if bot abuse (Vector §3) becomes
  a production problem.

**Recommendation**: Launch on Free, upgrade to Pro as the first paid tier (~$25/month
or ~$240/year). Business is a 10x price jump — only warranted when either (a) SaaS
revenue justifies the PCI/SLA requirements, or (b) a specific abuse pattern requires
the advanced WAF or bot management features.

Cloudflare is already planned for subdomain routing and wildcard TLS in Step 6 of
`feature_saas_model_changes.md`. Extending it to front media streaming (CDN caching of
video files) is the same zone configuration — no additional cost above the plan tier.
This also eliminates most Azure egress charges (~$0.087/GB) for video streaming.

---

## Data Model Requirements

The current SaaS schema (`feature_saas_model_changes.md` Phase 1) assumes one `plan`
and one `stripe_subscription_id` on the `tenants` row. Per-gallery billing requires a
separate billing table and a lifecycle state column on events.

These DDL sketches are design references. Final DDL will be applied via the BABRR
process (`docs/process_backup_alter_backup_rebuild_restore.md`) when Step 14
implementation begins. Both `create_media_db.sql` and the live `docker exec` ALTER
commands must be updated together per `SKILL.md` DDL rules.

### New table: `gallery_subscriptions`

One row per paid per-gallery subscription. Account-level billing is tracked on the
`tenants` row (`stripe_subscription_id` already reserved in Phase 1 schema).

```sql
CREATE TABLE gallery_subscriptions (
  subscription_id        int unsigned    NOT NULL AUTO_INCREMENT,
  tenant_id              int unsigned    NOT NULL,
  event_id               int unsigned    NOT NULL,
  stripe_subscription_id varchar(64)     NOT NULL,
  plan                   enum('starter','pro','max') NOT NULL  -- maps to Gallery Starter / Gallery Pro / Gallery Max
  status                 enum('active','cancelled','past_due') NOT NULL DEFAULT 'active',
  billing_started_at     datetime        NOT NULL,
  next_billing_date      datetime        NOT NULL,
  created_at             datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (subscription_id),
  UNIQUE KEY uq_gallery_subscriptions_event (event_id),
  KEY idx_gallery_subscriptions_tenant (tenant_id),
  CONSTRAINT fk_gallery_sub_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_gallery_sub_event
    FOREIGN KEY (event_id) REFERENCES events (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### New columns: `billing_status` and `soft_deleted_at` on `events`

`billing_status` drives the lifecycle state machine. `soft_deleted_at` is the grace-period
anchor for hard-delete: the nightly worker sets it at the same time it sets
`billing_status = 'soft_deleted'`. Hard-delete fires 3 days after `soft_deleted_at`
regardless of how the gallery was soft-deleted (free-trial expiry, account lapse, or
paid-subscription expiry). Using `soft_deleted_at` rather than `created_at + 17 days`
as the hard-delete anchor ensures the 3-day grace window is always correct; the
`created_at`-based approach collapsed to zero grace for any gallery already past Day 17.

```sql
ALTER TABLE events
  ADD COLUMN billing_status
    enum('active','soft_deleted','hard_deleted')
    NOT NULL DEFAULT 'active',
  ADD COLUMN soft_deleted_at datetime DEFAULT NULL
    COMMENT 'Set when billing_status transitions to soft_deleted; hard-delete fires at soft_deleted_at + 3 days';
```

### Updated `tenants.plan` enum

The Phase 1 schema reserves `enum('free','pro','enterprise')`. Expand to cover
account-level plans before billing wiring begins at Step 14.

```sql
ALTER TABLE tenants
  MODIFY COLUMN plan
    enum('free','account_pro','account_studio')
    NOT NULL DEFAULT 'free';
```

Per-gallery billing state lives in `gallery_subscriptions`. `tenants.plan` tracks only
whether the tenant holds an account-level subscription.

### New column: `file_size_bytes` on `assets`

Required for per-gallery storage accounting (Step 13 quota tracking) and for the
`billing_events.storage_bytes` snapshot. Without this column, storage per gallery
cannot be summed.

```sql
ALTER TABLE assets
  ADD COLUMN file_size_bytes bigint unsigned NOT NULL DEFAULT 0;
```

Storage per gallery is then:
```sql
SELECT e.event_id, e.org_name, SUM(a.file_size_bytes) AS total_bytes
FROM events e
JOIN assets a USING (event_id)
GROUP BY e.event_id;
```

### New table: `billing_events`

Audit log of every lifecycle and billing state transition. The `storage_bytes` column
captures a point-in-time snapshot of gallery storage at the moment of each transition —
this is the source of all beta measurement metrics. The nightly billing worker writes
one row per transition; Stripe webhook handlers write subscription and payment rows.

```sql
CREATE TABLE billing_events (
  event_log_id   bigint unsigned  NOT NULL AUTO_INCREMENT,
  tenant_id      int unsigned     NOT NULL,
  event_id       int unsigned     NOT NULL,
  transition     enum(
                   'notification_sent',
                   'soft_deleted',
                   'resurrected',
                   'hard_deleted',
                   'subscription_created',
                   'subscription_cancelled',
                   'payment_failed',
                   'payment_recovered',
                   'account_lapse_started'   -- written per-tenant (event_id = 0 sentinel) when account sub lapses and 7-day clock starts
                 )                NOT NULL,
  storage_bytes  bigint unsigned  DEFAULT NULL,
  recorded_at    datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY    (event_log_id),
  KEY idx_billing_events_event    (event_id),
  KEY idx_billing_events_tenant   (tenant_id),
  KEY idx_billing_events_ts       (recorded_at),
  CONSTRAINT fk_billing_events_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_billing_events_event
    FOREIGN KEY (event_id) REFERENCES events (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Scheduled worker (nightly cron)

Evaluates all galleries and drives lifecycle transitions. At each transition the worker
writes one row to `billing_events` with `storage_bytes` set to the gallery's current
total from `assets.file_size_bytes`.

| Check | Condition | Action |
|---|---|---|
| Notification | `NOW() >= created_at + INTERVAL 11 DAY` AND `billing_status = 'active'` AND no active `gallery_subscriptions` row AND `NOT EXISTS (SELECT 1 FROM billing_events WHERE event_id = e.event_id AND transition = 'notification_sent')` | Send expiry notification email/push; write `billing_events` row (`notification_sent`) |
| Soft delete | `NOW() >= created_at + INTERVAL 14 DAY` AND `billing_status = 'active'` AND no active subscription | Set `billing_status = 'soft_deleted'`; set `soft_deleted_at = NOW()`; write `billing_events` row (`soft_deleted`) |
| Hard delete | `billing_status = 'soft_deleted'` AND `soft_deleted_at <= NOW() - INTERVAL 3 DAY` | Purge blobs; cancel Stripe subscription if one exists (`stripe_subscription_id` on `gallery_subscriptions`); set `billing_status = 'hard_deleted'`; write `billing_events` row (`hard_deleted`) |

Account-level lapse drives an equivalent sequence keyed on `tenants.plan_expires_at`
(column already reserved in Phase 1 schema). The account lapse soft-delete sets
`soft_deleted_at` on each gallery row; the same hard-delete condition (`soft_deleted_at +
3 days`) then fires correctly on those galleries — no separate account-lapse hard-delete
condition is needed in the worker.

---

## Tests

Tests will be assigned T-numbers and added to `post_build_checks/tasks/main.yml`
(or `validate_app`) when Step 14 implementation begins. Before assigning T-numbers,
grep all `gighiveinfra/docs/*.md` for the candidate range to avoid namespace conflicts
(`SKILL.md` — T-numbers are a shared namespace).

Required test coverage at minimum:

- Unauthenticated requests to all new billing endpoints return HTTP 401
- Gallery `billing_status` transitions correctly from `active` → `soft_deleted` →
  `hard_deleted` at the correct UTC intervals
- Soft-deleted gallery returns HTTP 403 on file access attempts
- Hard-deleted gallery has no blobs remaining in the storage layer
- Stripe webhook handling sets subscription status correctly on payment success,
  cancellation, and payment failure
- Resurrection restores `billing_status = 'active'` and file access immediately
- Account lapse moves all tenant galleries to `soft_deleted` on Day 7, and `soft_deleted_at` is set on each gallery row
- Account lapse hard-delete fires at `soft_deleted_at + 3 days`, not at `created_at + 17 days` — verify a gallery that is 30 days old when lapsed is NOT hard-deleted until 3 days after soft-delete
- Notification is sent exactly once per gallery — a second nightly worker run does not re-send if `billing_events` already has a `notification_sent` row for the gallery
- Hard-deleted gallery has its Stripe subscription cancelled (verify no active `gallery_subscriptions.stripe_subscription_id` is left open)
- Gallery creation rate-limit enforced at the configured threshold

---

## Files Under Change

Preliminary list. Final scope confirmed when Step 14 implementation begins.

### New (gighiveinfra repo)

1. `ansible/roles/docker/files/apache/webroot/api/billing_gallery.php` — Stripe per-gallery
   subscription create, cancel, and webhook handler
2. `ansible/roles/docker/files/apache/webroot/api/billing_account.php` — Stripe account
   subscription create, cancel, and webhook handler
3. `ansible/roles/docker/files/apache/webroot/admin/billing_worker.php` — nightly CLI
   worker that evaluates lifecycle transitions (notification / soft-delete / hard-delete)
   for all galleries; invoked by cron
4. `ansible/roles/docker/files/apache/webroot/admin/billing_status.php` — polling
   endpoint for billing job status (mirrors existing job-status pattern)

### Modified (gighiveinfra repo)

5. `db/create_media_db.sql` — add `gallery_subscriptions` table; add `billing_events` table; add `file_size_bytes` column on `assets`; add `billing_status`
   column on `events`; update `tenants.plan` enum
6. `ansible/roles/docker/files/apache/webroot/admin/admin_system.php` — surface
   gallery billing status and upgrade CTA in the owner dashboard
7. `ansible/roles/post_build_checks/tasks/main.yml` — new `[smoke, billing]` tagged
   block with 401 auth checks for all new billing endpoints and lifecycle state assertions
8. `ansible/roles/docker/templates/.env.j2` — Stripe publishable key and webhook
   secret env vars
9. `ansible/inventories/group_vars/gighive/secrets.yml` (and `gighive2`, `prod`) —
   Stripe key vars (empty string defaults)
10. `ansible/inventories/group_vars/gighive/secrets.example.yml` — same with comment block

**Unchanged**: `export_media_worker_azure.php`, `export_media_worker.php`,
`export_media.php` — existing export feature is not modified by billing

---

## Open Items

- ~~**Azure Blob export add-on price** for Gallery Starter, Gallery Pro, and Account Pro — TBD~~ **Resolved 2026-08-31**: +$5.00/month add-on for Gallery Starter, Gallery Pro, and Account Pro; Gallery Max upgraded to Included (aligned with Account Studio at same price point); see "Azure Blob Storage Export Add-on" section above for full rationale and infrastructure cost analysis
- **Account plan migration mechanics** — how a user upgrades from N per-gallery
  subscriptions to an account plan (cancel per-gallery subscriptions, absorb galleries
  into account storage cap; Stripe proration)
- **Concurrent free gallery cap** — exact number TBD; recommend 2 active free galleries
  per tenant as the starting value (see Abuse Vectors §1)
- **Gallery creation rate limit** — exact threshold TBD; recommend 3 new galleries per
  rolling 30 days on a free tenant as the starting value (see Abuse Vectors §2)
- **Stripe webhook secret and key vars** — need to be added to `secrets.yml` and
  `secrets.example.yml` before Step 14 begins
- **Tenant owner admin page scoping** — each tenant owner must be able to monitor the billing
  status, gallery lifecycle, storage consumption, and subscription state of their own
  "mini-GigHive" instance via `<slug>.gighive.app/admin`; the existing admin pages must be
  modified to scope all queries and views through `AuthContext::tenantId()` (populated by the
  Cloudflare wildcard DNS + step 6 subdomain resolver) so no cross-tenant data leaks are
  possible; the platform superadmin (GigHive operator) has a separate cross-tenant operator
  console at step 16 of `feature_saas_model_changes.md`; no new data model columns are expected
  for this since `tenants`, `gallery_subscriptions`, and `billing_events` already carry the
  required status fields — the work is admin page query scoping; full design note is in the
  Two-Tier Admin Structure note under step 16 of `feature_saas_model_changes.md`
