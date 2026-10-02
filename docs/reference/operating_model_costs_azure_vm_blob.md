# Azure Operating Cost Model (Virtual Machine + Blob Storage)

This document provides a simple monthly operating cost estimate for running a standard GigHive deployment on Microsoft Azure.

These figures are based on:

- A real GigHive deployment running for one week in Azure.
- The default GigHive Terraform configuration.
- Azure East US pricing.
- Continuous 24x7 operation.

> **Note**
>
> These are planning estimates only. Actual Azure charges vary by region, negotiated pricing, storage usage, and outbound bandwidth.

---

# Default GigHive Azure Configuration

The standard GigHive Azure deployment uses:

- **Virtual Machine**
  - Azure Standard_B2ms
  - 2 vCPUs
  - 8 GiB RAM
  - Ubuntu Server 24.04 LTS

- **Operating System Disk**
  - Premium SSD LRS
  - 64 GB

- **Media Storage**
  - Azure Blob Storage
  - Standard Hot LRS
  - Private blob container

- **Networking**
  - Virtual Network
  - Subnet
  - Network Security Group
  - Static Public IP Address

---

# Estimated Monthly Cost

| Azure Service | GigHive Configuration | Estimated Monthly Cost |
|----------------|----------------------|-----------------------:|
| **Virtual Machine** | Standard_B2ms Linux VM (2 vCPU / 8 GiB RAM) | **~$55** |
| **Bandwidth** | Internet uploads are generally free. Downloads and media streaming are usage-based. | **~$0 while idle** |
| **Storage** | 64 GB Premium LRS OS disk + approximately 246 GiB Standard Hot LRS Blob Storage | **~$13–14** |
| **Virtual Network** | VNet, subnet, NSG, NIC, Storage Service Endpoint, Static Public IP | **~$3–4** |

## Estimated Idle Monthly Total

**Approximately $72/month**

This estimate assumes:

- The server runs continuously.
- Approximately 246 GiB of media is stored.
- There is little or no user traffic.
- No significant outbound media streaming.

---

# Simple Cost Breakdown

For the default GigHive deployment:

- **Virtual Machine — approximately $55/month**

  Azure Standard_B2ms running Ubuntu Server 24.04 continuously.

- **Bandwidth — approximately $0/month while idle**

  Uploading media into Azure is generally free.

  Costs increase as users stream or download media.

- **Storage — approximately $13–14/month**

  Includes:

  - 64 GB Premium LRS operating system disk
  - Approximately 246 GiB Standard Hot LRS Blob Storage

- **Virtual Network — approximately $3–4/month**

  Includes:

  - Static Public IP Address
  - Virtual Network
  - Subnet
  - Network Security Group
  - Network Interface

---

# Estimated Cost by Media Library Size

As your media library grows, storage costs increase gradually while the VM cost remains largely unchanged.

| Blob Media Capacity | Estimated Monthly Cost |
|--------------------:|-----------------------:|
| **250 GiB** | **~$72/month** |
| **500 GiB** | **~$78–85/month** |
| **1 TiB** | **~$90–105/month** |
| **2 TiB** | **~$110–140/month** |

These estimates assume the same Azure VM size and configuration.

---

# What Increases Cost?

The largest factors affecting operating cost are:

1. A larger virtual machine.
2. More stored media.
3. Users downloading or streaming media.
4. Higher outbound bandwidth usage.

Most GigHive installations will spend far more on compute and outbound media traffic than on storage itself.

---

# Summary

The default GigHive Azure deployment is intentionally designed to provide a predictable and affordable operating cost while giving you complete ownership of your infrastructure and media.

For many personal, club, and community deployments, the complete Azure hosting cost is approximately:

> **Approximately $72 per month**

before significant user traffic or media streaming.

And when you're done, deleting the infrastructure stops the cost immediately — no contract to wait out.

---

# Azure Infrastructure vs. Traditional Web Hosting

It is natural to compare the monthly cost of running GigHive on Azure with the cost of a typical web hosting plan.

However, these are fundamentally different products.

| Traditional Hosted Website | GigHive on Your Own Azure Infrastructure |
|----------------------------|------------------------------------------|
| Rent an application or shared hosting | Rent your own cloud infrastructure |
| Limited to the provider's features | Full control over the software and infrastructure |
| Media and data are stored by the provider | You own your media and metadata |
| Upgrades are controlled by the provider | You decide when and how to upgrade |
| Features are limited to the provider's roadmap | Customize or extend GigHive however you like |
| Export options may be limited | Your data is always portable |
| Usually shared with many other customers | Dedicated virtual machine and private storage |
| Lower monthly cost | Greater ownership, flexibility, and control |
| Keep paying until end of contract | Immediate cost elimination by deleting the infrastructure |

---

# Why Is GigHive Different from Traditional Web Hosting?

Many website hosting plans advertise prices between **$5 and $20 per month**. Those services are inexpensive because hundreds or even thousands of customers typically share the same servers and infrastructure.

A GigHive deployment is fundamentally different because you are operating your own cloud infrastructure instead of renting space within someone else's platform.

Each installation runs on its own dedicated virtual machine with its own private Blob Storage account, networking, operating system, and database. Rather than renting space inside someone else's application, you are operating your own cloud infrastructure.

For many GigHive users, the additional monthly cost provides significant benefits:

- Complete ownership of your media and metadata
- Open source software with no vendor lock-in
- Freedom to customize and extend the application
- Full control over upgrades, backups, and security
- The ability to integrate GigHive with your own applications and workflows
- Predictable cloud pricing that scales with your media library

GigHive is designed for users who value ownership and control over their platform.

Although this document uses Microsoft Azure as the reference deployment, GigHive itself is not tied to Azure or to any proprietary hosting platform.

Because GigHive is open source, your media, database, and application remain under your control. If your needs change, you can migrate to another cloud provider or even run GigHive on your own hardware.

For many organizations, the monthly infrastructure cost is the price of owning the platform instead of renting it.

---

# SaaS Scale Cost Estimates

The sections below cover SaaS-specific operating costs beyond the baseline single-instance deployment described above. They are intended to inform tier pricing decisions during the initial planning phase. See `docs/feature_saas_pricing_model.md` for the full pricing tier logic and margin design.

Rates used throughout:

| Cost driver | Rate | Source |
|---|---|---|
| Azure Blob Hot LRS (East US) | $0.018/GB/month | Confirmed against real deployment |
| Azure Blob Cool LRS | $0.010/GB/month | Azure portal |
| Azure Blob Cold LRS | $0.0045/GB/month | Azure portal |
| Azure internet egress | $0.087/GB | Azure portal |
| Stripe processing | 2.9% + $0.30 per payment | Stripe standard rate |
| Cloudflare Pro | $25/month per zone | Cloudflare portal |

---

# Gallery Free vs. Gallery Starter: Launch Tier Decision

**Decision: GigHive SaaS will launch with Gallery Free and Gallery Starter only.** Gallery Pro, Gallery Max, Account Pro, and Account Studio are deferred until after beta validation and confirmed conversion metrics.

Gallery Free and Gallery Starter have fundamentally different cost structures because of how long data lives on the platform.

| | Gallery Free | Gallery Starter ($20/month) |
|---|---|---|
| **Revenue** | $0 | $20.00/month |
| **Max concurrent galleries** | 2 per account | Unlimited |
| **Data lifetime** | Max 17 days (hard-deleted) | Indefinite — persists while subscription is active |
| **Storage tier ever reached** | Hot only — deleted at Day 17, before Cool's 30-day minimum | **Cool from upload** → Cold via lifecycle policy (90+ days) |
| **Storage cap** | 100 GB per gallery | 500 GB per gallery |
| **Max file size** | 6 GB | 6 GB |
| **Blob cost at typical 80 GB event** | ~$0.82 per gallery (one-time, Hot) | **$0.80/mo (Cool, default)** → $0.36/mo (Cold) |
| **Blob cost at cap (if filled)** | ~$1.02 one-time (100 GB, Hot) | **$5.00/mo (Cool, default)** → $2.25/mo (Cold) |
| **Early-deletion penalty** | None — deleted at Day 17 before Cool (30-day) or Cold (90-day) minimums. Hot is the correct and cheaper tier for Gallery Free. | Not applicable — data is retained indefinitely while paying |
| **Lifecycle tiering benefit** | None — always Hot | Yes — starts at Cool ($5.00/mo per 500 GB); lifecycle policy moves to Cold ($2.25/mo) after 90 days |
| **Net per user/month** | −$0.82 to −$1.50 (cost center, zero revenue) | **+$12.12 (Cool, default)** to +$16.32 (Cold) |

> **Gallery Free data never exits Hot storage.** Azure's minimum retention for Cool is 30 days and Cold is 90 days. Gallery Free hard-deletes at Day 17 — always inside the Hot window. No early-deletion penalty applies; the blob simply stops billing at deletion. The lifecycle tiering analysis in the sections below applies only to paying Gallery Starter subscribers with persistent galleries.

---

# Storage Tier Performance: Hot, Cool, and Cold Are Identical to Users

Azure Blob Storage Hot, Cool, and Cold tiers are purely billing classifications on the same underlying infrastructure. They deliver identical access latency (sub-second) and identical streaming throughput. A user watching a video or downloading photos from a Cold-tier gallery has exactly the same experience as one accessing a Hot-tier gallery — there is no visible or measurable difference.

| Tier | User-visible latency | Streaming performance | GigHive use |
|---|---|---|---|
| Hot | Sub-second | Full | Gallery Free galleries; new Starter uploads in flight |
| Cool | Sub-second (identical) | Full (identical) | Gallery Starter paid galleries — default upload tier |
| Cold | Sub-second (identical) | Full (identical) | Long-retained Starter content via lifecycle policy (90+ days) |
| Archive | Hours (rehydration required before access) | Unavailable until rehydrated | **Not used — excluded from lifecycle policy** |

With Cloudflare fronting all media traffic, even the per-GB retrieval cost difference between tiers (Cool: $0.01/GB, Cold: $0.03/GB vs. Hot: free on reads) is largely absorbed by CDN cache hits. Most streaming requests never reach Azure after the first cache miss, so retrieval costs are minimal in practice.

## Tier assignment decisions

**Gallery Starter paid galleries upload directly to Cool tier.** Performance is indistinguishable from Hot and the storage cost drops from $9.00/month to $5.00/month per 500 GB subscriber immediately — a $4.00/month saving per paying user before the lifecycle policy further reduces the cost to $2.25/month (Cold) after 90 days.

**Gallery Free galleries remain on Hot tier.** The Cool tier carries a 30-day minimum retention requirement. Gallery Free galleries hard-delete at Day 17 — 13 days before that threshold — which would trigger an early-deletion penalty charging the full 30-day Cool rate regardless. Gallery Free is capped at 100 GB per gallery; the maximum blob cost per gallery at that cap is $1.02 (17 days at Hot). The effective cost comparison:

| | Storage cost | Retrieval cost | Total |
|---|---|---|---|
| Hot for 17 days (typical 80 GB event) | 80 GB × $0.018 × (17/30) = **$0.82** | Free | **$0.82** |
| Hot for 17 days (at 100 GB cap) | 100 GB × $0.018 × (17/30) = **$1.02** | Free | **$1.02** |
| Cool with 30-day penalty (100 GB) | 100 GB × $0.010 × (30/30) = **$1.00** | $0.01/GB on reads | **$1.00 + retrieval** |

Cool storage with the penalty is marginally cheaper on storage but adds a retrieval cost that Hot does not carry. For data that lives fewer than 30 days, Hot is the correct and cheaper tier.

## Architectural implications of split tier assignment

The Free (Hot) / Starter (Cool) decision is not free — it requires changes in three places in the application:

1. **Upload handler must be tier-aware.** The blob upload code (tusd post-receive hook or equivalent PHP upload path) must check the gallery's billing status before writing to Azure and pass the correct access tier header: `x-ms-access-tier: Hot` for Free galleries, `x-ms-access-tier: Cool` for Starter galleries. This is a PHP-level change; the tier cannot be set retroactively in bulk without per-blob API calls.

2. **Resurrection flow must transition blobs from Hot → Cool.** When a Free gallery is resurrected during the Day 14–17 grace period (user pays), all existing blobs in that gallery are in Hot tier. The payment confirmation / resurrection webhook must trigger a per-blob tier-change operation (`BlobClient::setAccessTier(AccessTier::COOL)`) before restoring access. Failure to do this leaves paid content on Hot and inflates costs without gaining the per-subscriber savings this model depends on.

3. **Azure lifecycle policy targets Cool blobs only — this is already correct for this model.** The lifecycle rule "move blobs in Cool tier older than 90 days to Cold" automatically applies to all Starter gallery content and leaves Gallery Free blobs (Hot) untouched. Gallery Free blobs are handled entirely by the application's Day-17 hard-delete cron job. No policy change is required for Gallery Free.

4. **Per-file size limit of 6 GB enforced at the tusd layer.** The tusd daemon's `max_size` configuration must be set to 6 GB across all tiers. This accommodates approximately 24 minutes of H.264 4K video at smartphone quality — sufficient for any guest or fan clip — while preventing a single upload from consuming the entire gallery allocation. The 6 GB limit applies equally to Gallery Free (100 GB cap) and Gallery Starter (500 GB cap). This is a tusd configuration change only; no PHP application changes are required to enforce it.

> **Container architecture note.** Both tiers can coexist in a single blob container since tier is assigned per-blob at upload time. If future lifecycle rules require finer targeting, add a blob tag (`gallery_type: free` / `gallery_type: starter`) at upload time so policy rules can filter by tag rather than requiring a container split. A container split is the simpler long-term architecture but adds deployment and IAM complexity now.

---

# Two-Year Ramp Cost Estimate

## Scenario assumptions

- Growth: 10 users (month 1) → 100 (month 2) → 1,000 (month 3) → 10,000 (month 4, stable)
- Storage per user: 500 GB — the Gallery Starter maximum cap; represents a user who has run approximately 5–8 full events and retained all content
- Total storage at 10,000 users: 5 PB
- Azure Blob lifecycle tiering applied progressively as data ages: Hot → Cool → Cold
- Architecture scales from a single Standard_B2ms VM (months 1–2) to Azure Container Apps + MySQL Flexible Server (month 3 onward)
- Cloudflare Pro ($25/month) fronts all media traffic from month 3 onward, eliminating most Azure egress cost
- Stripe processing fees excluded — not an Azure infrastructure cost

## Storage cost by phase

Storage is the dominant cost driver, accounting for over 98% of total operating cost at scale.

| Phase | Users | Total storage | Blended rate | Storage/month |
|---|---:|---:|---:|---:|
| Month 1 | 10 | 5 TB | $0.018/GB (all Hot) | $90 |
| Month 2 | 100 | 50 TB | $0.018/GB (all Hot) | $900 |
| Month 3 | 1,000 | 500 TB | $0.017/GB (mostly Hot) | $8,500 |
| Month 4 | 10,000 | 5 PB | $0.015/GB (lifecycle kicking in) | $75,000 |
| Months 5–8 | 10,000 | 5 PB | $0.012/GB (60% Hot/Cool, 40% Cold) | $60,000 |
| Months 9–12 | 10,000 | 5 PB | $0.010/GB (data aging further) | $52,000 |
| Months 13–24 | 10,000 | 5 PB | $0.009/GB (mostly Cold) | $45,000 |

> Without lifecycle tiering, month 4 onward would cost $90,000/month for storage alone. With Cold tiering fully in effect the same 5 PB costs approximately $22,500/month. Lifecycle tiering is an Azure portal configuration step — no code changes required. It is mandatory before the Gallery Max and Account Studio tiers can be offered at a healthy margin.

## Compute cost by phase

| Phase | Architecture | Compute/month |
|---|---|---:|
| Months 1–2 (10–100 users) | Single Standard_B2ms | ~$72 |
| Month 3 (1,000 users) | Larger VM or small Container Apps setup | ~$200 |
| Months 4–24 (10,000 users) | Azure Container Apps + MySQL Flexible Server | ~$500 |

At 10,000 users the $500/month compute overhead dilutes to $0.05 per user — negligible against the $20 Gallery Starter subscription fee.

## Full 24-month cost projection

| Period | Months | Storage/mo | Compute/mo | Cloudflare/mo | Egress + misc | Monthly total | Period total |
|---|---:|---:|---:|---:|---:|---:|---:|
| Month 1 | 1 | $90 | $72 | $0 | $4 | **$166** | $166 |
| Month 2 | 1 | $900 | $72 | $0 | $4 | **$976** | $976 |
| Month 3 | 1 | $8,500 | $200 | $25 | $115 | **$8,840** | $8,840 |
| Month 4 | 1 | $75,000 | $400 | $25 | $550 | **$75,975** | $75,975 |
| Months 5–8 | 4 | $60,000 | $500 | $25 | $850 | **$61,375** | $245,500 |
| Months 9–12 | 4 | $52,000 | $500 | $25 | $850 | **$53,375** | $213,500 |
| Months 13–24 | 12 | $45,000 | $500 | $25 | $850 | **$46,375** | $556,500 |
| **Total** | **24** | | | | | | **~$1,101,957** |

> **Cost breakdown by category over 24 months**: Storage ~98% / Compute ~1% / Cloudflare + egress + misc ~1%.

> **Month 4 cliff**: The 10x user jump from 1,000 to 10,000 users in a single month drives a cost spike from ~$8,840 to ~$75,975. Revenue must track ahead of this. At $20/user on Gallery Starter, 10,000 paying users generate $200,000/month — well above the $75,975 cost — but the ramp needs paid conversions to stay ahead of the infrastructure spike.

> **Sensitivity to the 500 GB assumption**: A typical GigHive event generates 60–112 GB. The 500 GB/user assumption is a heavy-user scenario. If average actual storage is 100–150 GB/user, the 24-month total falls to approximately $220,000–$350,000.

---

# Per-Plan Infrastructure Cost vs. Revenue

These tables show the break-even and margin position of each pricing tier. **Gallery Starter is the primary target tier at launch** and the figures below confirm it is well-calibrated even at worst-case utilization.

Cost components used in all tables:

- **Blob storage**: Hot LRS at $0.018/GB/month; see lifecycle tiering rows for reductions at 5 TB
- **Shared compute**: ~$1.50/user/month apportioned at moderate scale (dilutes to ~$0.05 at 10,000 users)
- **Stripe fees**: 2.9% + $0.30 per monthly payment
- **Cloudflare Pro**: ~$0.50/user/month amortized across the subscriber base

The Gallery Starter row is sourced directly from `feature_saas_pricing_model.md`: *"blob cost ~$9.00/month. Adding shared compute (~$1.50), Stripe fees (~$0.88), and Cloudflare amortized (~$0.50) gives a total cost of ~$11.88 against $20.00 revenue — approximately 40% net margin at worst-case utilization."*

## Table 1 — Maximum cap utilization, Hot LRS only (worst case, no lifecycle tiering)

| Tier | User pays/mo | Blob (Hot, max cap) | Shared compute | Stripe fees | Cloudflare | Total infra cost | Infra as % of revenue | Operator net | Net margin |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Gallery Starter (500 GB) | $20.00 | $9.00 | $1.50 | $0.88 | $0.50 | **$11.88** | 59% | +$8.12 | **41%** |
| Gallery Pro (1 TB) | $40.00 | $18.43 | $1.50 | $1.46 | $0.50 | **$21.89** | 55% | +$18.11 | **45%** |
| Gallery Max (5 TB) | $99.95 | $92.16 | $1.50 | $3.20 | $0.50 | **$97.36** | 97% | +$2.59 | **3%** |
| Account Pro (1 TB) | $40.00 | $18.43 | $1.50 | $1.46 | $0.50 | **$21.89** | 55% | +$18.11 | **45%** |
| Account Studio (5 TB) | $99.95 | $92.16 | $1.50 | $3.20 | $0.50 | **$97.36** | 97% | +$2.59 | **3%** |

> Gallery Max and Account Studio at Hot-only are barely solvent (+$2.59/user/month). A single Stripe dispute or chargeback wipes out 6 months of profit on that subscriber. **These tiers must not be offered without lifecycle tiering in place.** Gallery Starter and Gallery Pro are both healthy at 41–45% net margin even at maximum utilization with no tiering required.

## Table 2 — With Azure lifecycle tiering applied (required for 5 TB tiers)

The 5 TB blob cost figures — 80% Cool + 20% Hot → ~$59/month; 80% Cold + 20% Hot → ~$26/month — are derived from the Azure pricing tiers in the blob storage lifecycle tiering section of `feature_saas_pricing_model.md`. Gallery Starter and Gallery Pro data is mostly actively accessed and stays in Hot; lifecycle savings are marginal at those sizes.

"Other costs" = shared compute + Stripe fees + Cloudflare.

| Tier | User pays/mo | Blob cost (tiered) | Other costs | Total infra cost | Infra as % of revenue | Operator net | Net margin |
|---|---:|---:|---:|---:|---:|---:|---:|
| Gallery Starter (500 GB, Hot) | $20.00 | $9.00 | $2.88 | **$11.88** | 59% | +$8.12 | **41%** |
| Gallery Pro (1 TB, Hot) | $40.00 | $18.43 | $3.46 | **$21.89** | 55% | +$18.11 | **45%** |
| Gallery Max (5 TB, 80% Cool) | $99.95 | $59.00 | $5.20 | **$64.20** | 64% | +$35.75 | **36%** |
| Gallery Max (5 TB, 80% Cold) | $99.95 | $26.00 | $5.20 | **$31.20** | 31% | +$68.75 | **69%** |
| Account Pro (1 TB, Hot) | $40.00 | $18.43 | $3.46 | **$21.89** | 55% | +$18.11 | **45%** |
| Account Studio (5 TB, 80% Cool) | $99.95 | $59.00 | $5.20 | **$64.20** | 64% | +$35.75 | **36%** |
| Account Studio (5 TB, 80% Cold) | $99.95 | $26.00 | $5.20 | **$31.20** | 31% | +$68.75 | **69%** |

> Once data matures into Cold storage (content older than 90 days), Gallery Max and Account Studio flip from barely viable to the highest-margin tiers on the platform at 69%. The same lifecycle policy required for profitability at launch also creates the best long-term margins.

## Table 3 — Scale effect at 10,000 users (500 GB each, Gallery Starter max)

Per-user infrastructure cost from the 24-month projection divided by 10,000 users. At this scale, shared compute dilutes to $0.05/user — the cost floor is almost entirely blob storage.

| Phase | Total infra/mo | Per-user infra cost | User pays | Infra as % of revenue | Operator nets/user | Net margin |
|---|---:|---:|---:|---:|---:|---:|
| Month 4 (all Hot, new data) | $75,975 | $7.60 | $20.00 | 38% | +$12.40 | **62%** |
| Months 5–8 (lifecycle activating) | $61,375 | $6.14 | $20.00 | 31% | +$13.86 | **69%** |
| Months 9–12 (mix maturing) | $53,375 | $5.34 | $20.00 | 27% | +$14.66 | **73%** |
| Months 13–24 (mostly Cold) | $46,375 | $4.64 | $20.00 | 23% | +$15.36 | **77%** |

Total platform revenue at 10,000 users × $20/month = **$200,000/month** against **$46,375** stabilized infrastructure — **77% gross infrastructure margin at maturity**.

---

# Planning Notes for Initial Tier Design

1. **Gallery Starter is the right first target.** 41% net margin at worst-case max utilization; 77% gross infrastructure margin at 10,000-user scale. The pricing holds even if most subscribers are close to the 500 GB cap. No lifecycle tiering required for this tier to be profitable.

2. **Gallery Max and Account Studio must not launch without lifecycle tiering configured.** A single 5 TB subscriber at Hot-only leaves $2.59/month — one support incident or dispute wipes out 6+ months of profit. Lifecycle tiering is an Azure portal configuration step that takes the same tier from 3% to 36–69% margin.

3. **The financial risk window is months 2–4.** Infrastructure costs jump from $976 (month 2) to $75,975 (month 4) in two months. At $20/user, 10,000 paying Gallery Starter users return $200,000/month — costs are covered at scale — but the ramp needs paid conversions tracking ahead of the storage curve.

4. **Free galleries carry real cost.** Free-tier galleries that hard-delete at Day 17 still spend up to 17 days in Hot blob storage. At 500 GB that is ~$5.10 in storage cost per gallery, with zero revenue. Track the free-to-paid conversion rate carefully during beta using the metrics defined in `feature_saas_pricing_model.md` § Beta Release Strategy.

5. **Realistic average storage reduces costs significantly.** The 500 GB/user assumption produces a $1.1M two-year total. If actual average storage is 100–150 GB/user (consistent with the typical 60–112 GB single-event figure in the real-world storage example in `feature_saas_pricing_model.md`), the same 24-month total falls to approximately $220,000–$350,000.

---

# 90/10 Free/Starter Distribution: Monthly Cost and Margin

This table models operating costs and margin at each user-count milestone with a 90% Gallery Free / 10% Gallery Starter split. Gallery Free blobs are always Hot; Gallery Starter blobs start at Cool (the assigned upload tier). Cold rows are struck through — Cold is not an assigned upload tier; it is reached automatically via the Azure lifecycle policy after 90 days and is shown for reference only. Performance across Hot, Cool, and Cold is identical (all sub-second); the tier choice is purely a billing decision.

**Per-user blob cost assumptions:**
- Gallery Free (Hot, ~80 GB cycling galleries): ~$1.00/user/month
- Gallery Starter (Cool, 500 GB persistent): $5.00/user/month
- Gallery Starter (Cold, via lifecycle at 90+ days): $2.25/user/month
- Stripe: $0.88/Starter user/month
- Infrastructure overhead per phase: same as the ramp scenario above

| Total users | Free users | Starter users | Free blob (Hot) | Starter blob (tier) | Infra overhead | Stripe | **Total cost** | **Revenue** | **Net** | **Margin** |
|---:|---:|---:|---:|---|---:|---:|---:|---:|---:|---:|
| 10 | 9 | 1 | $9 | $5.00 (Cool) | $76 | $0.88 | **$90.88** | $20 | −$70.88 | revenue covers 22% of costs |
| ~~10~~ | ~~9~~ | ~~1~~ | ~~$9~~ | ~~$2.25 (Cold)~~ | ~~$76~~ | ~~$0.88~~ | ~~**$88.13**~~ | ~~$20~~ | ~~−$68.13~~ | ~~reference only~~ |
| 100 | 90 | 10 | $90 | $50.00 (Cool) | $76 | $8.80 | **$224.80** | $200 | −$24.80 | **−12%** |
| ~~100~~ | ~~90~~ | ~~10~~ | ~~$90~~ | ~~$22.50 (Cold)~~ | ~~$76~~ | ~~$8.80~~ | ~~**$197.30**~~ | ~~$200~~ | ~~+$2.70~~ | ~~+1% — reference only~~ |
| 1,000 | 900 | 100 | $900 | $500.00 (Cool) | $340 | $88 | **$1,828** | $2,000 | +$172 | **+9%** |
| ~~1,000~~ | ~~900~~ | ~~100~~ | ~~$900~~ | ~~$225.00 (Cold)~~ | ~~$340~~ | ~~$88~~ | ~~**$1,553**~~ | ~~$2,000~~ | ~~+$447~~ | ~~+22% — reference only~~ |
| 10,000 | 9,000 | 1,000 | $9,000 | $5,000.00 (Cool) | $1,375 | $880 | **$16,255** | $20,000 | +$3,745 | **+19%** |
| ~~10,000~~ | ~~9,000~~ | ~~1,000~~ | ~~$9,000~~ | ~~$2,250.00 (Cold)~~ | ~~$1,375~~ | ~~$880~~ | ~~**$13,505**~~ | ~~$20,000~~ | ~~+$6,495~~ | ~~+32% — reference only~~ |

> **Cold rows (struck through):** Cold tier is reached via the Azure lifecycle policy automatically after 90 days on Cool blobs — no code assigns a blob to Cold at upload time. These rows show what margins look like once mature Starter content has aged into Cold, which happens in the background. The active operating model uses Hot (Free) and Cool (Starter) only.

## Cost reduction from the Hot → Cool decision for Starter

Switching Gallery Starter's upload tier from Hot to Cool reduces infrastructure cost directly. Since revenue is fixed, every dollar of cost reduction flows through to the bottom line — so the cost reduction equals the improvement in profit (or reduction in loss) at that scale. It is not the total profit; the total profit at each scale is the **Net** column above.

| Milestone | Previous cost (Starter at Hot) | New cost (Starter at Cool) | Cost reduction vs. Hot |
|---|---:|---:|---:|
| 100 users | $264.80/month | $224.80/month | **$40/month** |
| 1,000 users | $2,228/month | $1,828/month | **$400/month** |
| 10,000 users | $20,255/month | $16,255/month | **$4,000/month** |

The most significant effect: at 1,000 users the business is profitable from day one at +9% (Cool), whereas with Hot it would have been −11% until Starter data aged into Cool over 2–3 months. The Cool-by-default decision eliminates that initial loss window entirely.

---

# Storage Sensitivity: 100 GB per Starter User Scenario

The 500 GB per Starter assumption is based on the tier cap. Actual event uploads are typically 60–112 GB per event. If Starter users average closer to 100 GB of accumulated content, the cost model improves significantly. This table should be compared against real usage data once available from the beta `billing_events` table.

Gallery Free blob cost ($1.00/user/month at ~80 GB cycling) is unchanged — it is determined by event upload size, not the Starter cap. Only the Starter blob cost changes.

**Gallery Starter at 100 GB (Cool/Cold):**
- Cool: 100 GB × $0.010 = $1.00/user/month
- Cold (via lifecycle, 90+ days): 100 GB × $0.0045 = $0.45/user/month

| Total users | Free users | Starter users | Free blob (Hot) | Starter blob (tier) | Infra overhead | Stripe | **Total cost** | **Revenue** | **Net** | **Margin** |
|---:|---:|---:|---:|---|---:|---:|---:|---:|---:|---:|
| 10 | 9 | 1 | $9 | $1.00 (Cool) | $76 | $0.88 | **$86.88** | $20 | −$66.88 | revenue covers 23% |
| ~~10~~ | ~~9~~ | ~~1~~ | ~~$9~~ | ~~$0.45 (Cold)~~ | ~~$76~~ | ~~$0.88~~ | ~~**$86.33**~~ | ~~$20~~ | ~~−$66.33~~ | ~~reference only~~ |
| 100 | 90 | 10 | $90 | $10.00 (Cool) | $76 | $8.80 | **$184.80** | $200 | **+$15.20** | **+8%** |
| ~~100~~ | ~~90~~ | ~~10~~ | ~~$90~~ | ~~$4.50 (Cold)~~ | ~~$76~~ | ~~$8.80~~ | ~~**$179.30**~~ | ~~$200~~ | ~~+$20.70~~ | ~~+10% — reference only~~ |
| 1,000 | 900 | 100 | $900 | $100.00 (Cool) | $340 | $88 | **$1,428** | $2,000 | **+$572** | **+29%** |
| ~~1,000~~ | ~~900~~ | ~~100~~ | ~~$900~~ | ~~$45.00 (Cold)~~ | ~~$340~~ | ~~$88~~ | ~~**$1,373**~~ | ~~$2,000~~ | ~~+$627~~ | ~~+31% — reference only~~ |
| 10,000 | 9,000 | 1,000 | $9,000 | $1,000.00 (Cool) | $1,375 | $880 | **$12,255** | $20,000 | **+$7,745** | **+39%** |
| ~~10,000~~ | ~~9,000~~ | ~~1,000~~ | ~~$9,000~~ | ~~$450.00 (Cold)~~ | ~~$1,375~~ | ~~$880~~ | ~~**$11,705**~~ | ~~$20,000~~ | ~~+$8,295~~ | ~~+41% — reference only~~ |

> **Cold rows (struck through):** reached via lifecycle policy after 90 days, not an assigned upload tier. Shown for reference. Performance is identical to Cool.

## Margin improvement vs. 500 GB scenario

| Milestone | 500 GB margin (Cool) | 100 GB margin (Cool) | Improvement |
|---|---:|---:|---:|
| 100 users | −12% (loss) | **+8% (profit)** | +20 pp |
| 1,000 users | +9% | **+29%** | +20 pp |
| 10,000 users | +19% | **+39%** | +20 pp |

The improvement is a consistent +20 percentage points across all milestones. The key shift: at 100 GB per Starter, the business is profitable from 100 users on day one. At 500 GB it required 1,000 users to reach profitability.

At 100 GB per Starter, the Starter blob cost in Cool ($1.00/user) equals the Gallery Free blob cost ($1.00/user). The entire margin difference between the two tiers is driven by the $20 subscription revenue — not meaningfully different storage costs. This makes the 90/10 Free/Starter distribution far less punishing than the 500 GB model suggested.
