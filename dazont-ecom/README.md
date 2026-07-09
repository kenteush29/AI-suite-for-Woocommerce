# Dazont Ecom

A modular WooCommerce toolkit. Modules are shipped as they are finished and
fully tested.

## Modules

### Restock (live)

Restock backlog manager. Lists every product-line (simple product or variable
parent) that has **at least one out-of-stock item**, ranked by **total sales**,
so you can prioritise restocking by real demand.

## Restock — features

- One row per product-line — never one row per variation in the main view.
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
`restock-v*` release is published, every site shows a one-click update under
**Plugins**, exactly like a wordpress.org plugin — no manual ZIP, no FTP.
