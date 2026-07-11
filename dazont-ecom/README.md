# Dazont Ecom

A modular WooCommerce toolkit. Modules are shipped as they are finished and
fully tested.

## Modules

### Restock (live)

Restock backlog manager. Lists every product-line (simple product or variable
parent) that has **at least one out-of-stock item**, ranked by **total sales**,
so you can prioritise restocking by real demand.

### Trending Products (live)

`[dze_trending_products]` shortcode: renders WooCommerce's native product grid
filled with the best-selling products over a configurable time window (e.g.
last 7/30/90 days), ranked by units ordered (WooCommerce Analytics data).

### Trending Products — features

- Shortcode `[dze_trending_products]` (alias `[time_bestsellers]` for
  backward compatibility), attributes `time_period`, `limit`, `columns`.
- Settings page with defaults for all three + cache duration, and a
  **Clear cache** button to force a fresh computation on demand.
- Cached via transients (real persistence, not the non-persistent object
  cache) with a version-based invalidation — no wildcard-delete queries.
- Rendering is fully delegated to WooCommerce's own `[products]` shortcode —
  no custom markup/CSS to maintain, and the ranking order is preserved.
- Fails gracefully (renders nothing) if the WooCommerce Analytics lookup
  table isn't present/populated yet, instead of erroring on the live site.
- Same admin-only rule: only `add_shortcode()` runs on the front end; the
  query, cache and settings/AJAX code never load outside of admin or an
  actual shortcode render.

## Restock — features

- One row per product-line — never one row per variation in the main view.
- **Product & variation thumbnails**, click to open the full-size image in a
  lightbox (full image loaded only on click) — handy for sourcing without
  leaving the page.
- **Admin-only & zero front-end cost**: the module wires nothing on front-end
  requests except the weekly cron hook. Future modules follow the same rule —
  front-end code loads only where it's needed.
- **One-click Restock** per row + **Bulk restock** (top & bottom): sets the
  product (and its out-of-stock variations) back to `instock` and clears any
  tracked quantity, so status is the single source of truth (no stock/status
  conflict — for stores that don't track numeric stock).
- Columns: Product · Category · Price · OOS variations (`x/y`) · Total Sales.
- Click a variable product to reveal its out-of-stock variations (AJAX,
  lazy-loaded — no slowdown on large catalogues).
- **Total Sales** = total units ordered across *all* orders (including
  refunded / cancelled / failed, never reduced by refunds) — a demand signal,
  not revenue.
- **WPML-aware**: only default-language products are listed, and sales are
  aggregated across every translation of the product.
- Sales are cached (weekly WP-Cron) for speed; a **Recalculate sales now**
  button populates them on demand.
- Sort by sales/title, filter by category, search, and a per-page selector
  (up to 200).

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce (HPOS-compatible — uses the WooCommerce CRUD API, no raw SQL on
  order tables).

## Installation

1. Download `dazont-ecom.zip` from the
   [latest release](https://github.com/kenteush29/Dazont-Ecom-for-WooCommerce/releases).
   *(Use the ZIP asset attached to the release, not the "Source code" archive.)*
2. In WordPress: **Plugins → Add New → Upload Plugin** → select the ZIP →
   **Install** → **Activate**.
3. Open the **Restock** menu and click **Recalculate sales now** once to build
   the sales cache.

## Updates

The plugin checks its GitHub releases automatically. When a newer
`dazont-v*` release is published, every site shows a one-click update under
**Plugins**, exactly like a wordpress.org plugin — no manual ZIP, no FTP.
