# WordPress and WooCommerce compatibility

## Minimum versions (compatibility floor)

| Component | Minimum | Notes |
|-----------|---------|-------|
| WordPress | 6.5 | Block editor and modern comment APIs |
| PHP | 8.1 | Strict types in plugin source |
| WooCommerce | 8.2 | Product reviews, order items, HPOS APIs |

## M1 mandatory integration coordinates (DEV-aligned)

M1 CI **must** run an integration leg matching the current host DEV stack:

| Component | M1 mandatory CI / DEV |
|-----------|----------------------|
| PHP | **8.4** |
| WordPress | **7.0.2** |
| WooCommerce | **11.0.1** |

Broader version-matrix expansion is deferred to **M7**.

## Optional compatibility-floor leg (non-blocking)

| Component | Floor smoke |
|-----------|-------------|
| PHP | 8.1 |
| WordPress | 6.5 |
| WooCommerce | 8.2 |

This leg may be marked `continue-on-error` until M7 proves stability.

## HPOS (High-Performance Order Storage)

UPR declares `custom_order_tables` compatibility from M1 via public `FeaturesUtil` API.

- Use `wc_get_order()` and CRUD APIs exclusively for order access (M2+).
- Do not query legacy `wp_posts` for shop orders.

## Action Scheduler

Required for invitation scheduling, reconciliation, and retention purge (M2+).

- Group name: `upr`
- Host must run Action Scheduler via WP-Cron or real cron.

## WooCommerce features explicitly not used

- `customer_review_request` internal scheduler — **disabled** on host; UPR owns invitations (M2+).
- `Automattic\WooCommerce\Internal\OrderReviews\*` — **never imported**.

## Test matrix

| Milestone | Coverage |
|-----------|----------|
| M1 | Mandatory DEV-aligned leg + unit tests; optional floor leg |
| M7+ | Full tested minor-version matrix |

## Related

- [`../milestones/M1-core-enablement.md`](../milestones/M1-core-enablement.md)
