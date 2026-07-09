# Dazont Ecom

A modular WooCommerce toolkit. Modules ship to the live plugin only once they
are finished and fully tested.

## Live modules

- **Restock** — lists out-of-stock product-lines (simple & variable products)
  ranked by total sales, with per-variation drill-down and WPML-aware sales
  aggregation. See [`dazont-ecom/`](dazont-ecom/).

## Install

Download `dazont-ecom.zip` from the
[latest release](https://github.com/kenteush29/AI-suite-for-Woocommerce/releases)
(the ZIP asset attached to the release — not the "Source code" archive), then in
WordPress: **Plugins → Add New → Upload Plugin**.

Once installed, the plugin auto-updates from this repository's releases: every
new `dazont-v*` release surfaces a one-click update on every site.

## Releasing

1. Bump the version in `dazont-ecom/dazont-ecom.php`.
2. Push a matching tag, e.g. `git tag dazont-v1.1.0 && git push origin dazont-v1.1.0`.
3. The **Build & publish Dazont Ecom** workflow builds `dazont-ecom.zip` and
   attaches it to a new GitHub release automatically.
