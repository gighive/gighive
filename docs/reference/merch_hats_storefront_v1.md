# GigHive Merchandise Store (v1)

## Objective

Create the simplest possible merchandise storefront for GigHive.

The goal is **not** to build an ecommerce platform. The goal is to sell
three hats with as little operational overhead as possible.

## Assumptions

-   The storefront is a **single Markdown (`.md`) file** in the
    `gighiveinfra/docs/` directory, rendered to HTML by Jekyll/GitLab Pages.
-   No custom backend code is required.
-   No database is required.
-   Stripe Checkout is used via **Stripe Payment Links** — one per hat.
-   The page lives at `https://gighive.app/hats` via Jekyll `permalink: /hats/`.
-   Existing GigHive site styling (dark theme, hamburger nav) is reused inline.

## Technology

-   Existing `gighive.app` website (Jekyll / GitLab Pages)
-   Source file: `gighiveinfra/docs/hats_checkout.md`
-   Stripe Payment Links (one per hat SKU)
-   Pirate Ship for shipping labels
-   No WooCommerce, No Shopify, No shopping cart

------------------------------------------------------------------------

# URL

    https://gighive.app/hats

Jekyll front matter in `hats_checkout.md`:

    ---
    permalink: /hats/
    ---

Jekyll ignores the source filename (`hats_checkout.md`) entirely and serves
the page at `/hats/`. The URL `/hats_checkout.html` does not exist.

------------------------------------------------------------------------

# Products

Three hat SKUs. Two are available now; one is coming soon.

| SKU | Name | Status |
|-----|------|--------|
| 1 | Modern Bee Hat | **Available** |
| 2 | Futuristic Hat | **Available** |
| 3 | Retro Bee Hat | **Coming Soon** |

------------------------------------------------------------------------

# Stripe Payment Links

One Stripe Product and one Stripe Payment Link per available hat.
Each **Buy Now →** button links directly to its Stripe Payment Link (no cart, no backend).

## Modern Bee Hat

| Environment | URL |
|-------------|-----|
| **Production** | `https://buy.stripe.com/bJedR3e0DaHlbZO6791wY01` |
| **Test** | `https://buy.stripe.com/test_fZu14hg3R6bm6cWdbia7C01` |

## Futuristic Hat

| Environment | URL |
|-------------|-----|
| **Production** | `https://buy.stripe.com/8x28wJ3lZg1Fe7Wbrt1wY00` |
| **Test** | `https://buy.stripe.com/test_fZu6oB9Ft2Za9p8b3aa7C00` |

## Retro Bee Hat

No Stripe link yet. Button is disabled ("Coming Soon") until inventory is ready.

## Current link state in hats_checkout.md

Both hats point to **production links**. End-to-end purchase flow verified via test links.

------------------------------------------------------------------------

# Page Layout

## Header

**GigHive Hats**

Support GigHive and help preserve shared experiences.

------------------------------------------------------------------------

## Product Cards

Display three products as cards — 3 columns on desktop, stacked on mobile
(breakpoint: 860 px).

Each available card contains:

-   Hero photo (clickable thumbnails swap it — left side, right side, back)
-   Product name
-   Short description with bullet specs
-   Price: **$39.95**
-   FREE U.S. SHIPPING badge (green)
-   **Buy Now →** button (links directly to Stripe Payment Link)

Coming-soon card:

-   Design-sheet image (contained, not cropped)
-   Product name + "Coming Soon" label
-   Muted price and shipping badge
-   Disabled "Coming Soon" button (non-clickable, `<span>` not `<a>`)

------------------------------------------------------------------------

# Hat Descriptions

## Modern Bee Hat

Embroidered cartoon bee mascot — camera in one hand, mic in the other.
*FOUNDED —2026—* stitched on the side panel.

-   Premium washed cotton dad cap
-   Khaki crown / navy bill
-   Adjustable leather strap
-   One size fits most

## Futuristic Hat

Clean wireframe bee with *GIGHIVE* text. Minimalist embroidery
on both the front crown and right side panel. *FOUNDED —2026—* on left panel.

-   Premium washed cotton dad cap
-   Khaki crown / navy bill
-   Adjustable leather strap
-   One size fits most

## Retro Bee Hat *(Coming Soon)*

Classic retro-style bee mascot. Design preview shown; production photos
coming when inventory is ready.

-   Premium washed cotton dad cap
-   Khaki crown / navy bill
-   Adjustable leather strap
-   One size fits most

------------------------------------------------------------------------

# Photography

Use authentic photos. All images are stored in `gighiveinfra/docs/images/`
and referenced with the `/images/` relative path on the site.

| File | Used in |
|------|---------|
| `hat_modern_left_side.jpeg` | Modern — hero (default) + thumbnail |
| `hat_modern_right_side.jpeg` | Modern — thumbnail |
| `hat_both_back.jpeg` | Modern + Futuristic — back thumbnail |
| `hat_futuristic_left_side.jpeg` | Futuristic — hero (default) + thumbnail |
| `hat_futuristic_right_side.jpeg` | Futuristic — thumbnail |
| `hat_retro_design_sheet.png` | Retro — design preview (contained fit) |

Thumbnails are interactive: clicking a thumbnail swaps the hero image
(vanilla JS, no dependencies).

------------------------------------------------------------------------

# Pricing

-   Price: **$39.95**
-   Shipping: **FREE (Continental U.S.)**

Shipping cost is included in the selling price.

------------------------------------------------------------------------

# Inventory

Manage inventory in Stripe.

Enable:

-   Inventory tracking
-   Out-of-stock protection

When inventory reaches zero in Stripe, the Payment Link itself will block
purchase. To reflect this on the storefront, manually change the button
from `buy-btn-active` to `buy-btn-soon` and label it **Sold Out**.

------------------------------------------------------------------------

# Shipping Workflow

Stripe Payment

↓

Email notification to seller

↓

Open Pirate Ship

↓

Print USPS Ground Advantage label

↓

Ship hat

No fulfillment automation required for V1.

------------------------------------------------------------------------

# About Section

Every hat helps fund the continued development of GigHive, an
open-source platform dedicated to preserving shared experiences.

Contact: `contact@gighive.app`

------------------------------------------------------------------------

# Navigation

`index.md` hamburger nav includes a **"Wear the Hive"** link under the
Links section pointing to `/hats`. `hats_checkout.md` hamburger nav
mirrors the same structure with a matching Hats entry.

------------------------------------------------------------------------

# Future Enhancements

-   Retro Bee Hat (production run)
-   Stickers
-   T-shirts
-   Hoodies
-   Limited editions / Founder editions
-   International shipping
-   Coupon codes
-   Gift cards
-   Sold-out automation (Stripe webhook → update page)

------------------------------------------------------------------------

# Non-Goals

Do **not** implement:

-   WooCommerce
-   Shopify
-   Shopping cart
-   Customer accounts
-   Reviews / wish lists / product search
-   Complex inventory automation (V1)

Keep the experience intentionally simple.

------------------------------------------------------------------------

# Success Criteria

A visitor should be able to:

1.  Open `gighive.app/hats`
2.  Browse hat photos using the thumbnail gallery
3.  Choose a hat
4.  Click **Buy Now →**
5.  Complete payment via Stripe
6.  Receive email confirmation from Stripe
7.  Receive shipment within a few days

Target purchase time: **under two minutes**.

------------------------------------------------------------------------

# Implementation Notes

-   Source file: `gighiveinfra/docs/hats_checkout.md`
-   Jekyll permalink: `/hats/` → served at `https://gighive.app/hats`
-   All CSS and JS is inline in the `.md` file (no external dependencies)
-   Dark theme (`#121a33` background) matches the rest of `gighive.app`
-   Hamburger navigation matches `index.md` (colors, fonts, z-index, behavior)
-   Image paths use `/images/` (maps to `gighiveinfra/docs/images/` on the served site)
-   Retro hat image: `hat_retro_design_sheet.png` (no space in filename)
-   Contact email: `contact@gighive.app`
-   External links use `rel="noopener noreferrer"` on `target="_blank"` anchors
-   Coming-soon button uses `<span>` (not `<a>`) — non-focusable, non-clickable by default
