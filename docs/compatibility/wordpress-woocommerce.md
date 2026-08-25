# WordPress and WooCommerce compatibility

## Minimum versions (M1+ target)

| Component | Minimum | Notes |
|-----------|---------|-------|
| WordPress | 6.5 | Block editor and modern comment APIs |
| PHP | 8.1 | Strict types in plugin source |
| WooCommerce | 8.2 | Product reviews, order items, HPOS APIs |

## HPOS (High-Performance Order Storage)

UPR must declare `custom_order_tables` compatibility on the main plugin file from M1.

- Use `wc_get_order()` and CRUD APIs exclusively for order access.
- Do not query legacy `wp_posts` for shop orders.

## Action Scheduler

Required for invitation scheduling, reconciliation, and retention purge (M2+).

- Group name: `upr`
- Host must run Action Scheduler via WP-Cron or real cron.

## WooCommerce features explicitly not used

- `customer_review_request` internal scheduler — **disabled** on host; UPR owns invitations.
- `Automattic\WooCommerce\Internal\OrderReviews\*` — **never imported**.

## Test matrix (future)

M7+ will document tested WooCommerce minor versions. M0 establishes floors only.
