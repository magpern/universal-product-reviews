# Production replay runbook

Generic host integration guide for Universal Product Reviews (UPR). Host-specific paths, credentials, and adapter code live **outside** this repository.

## Boundary

- **Staging first.** Do not activate on production until staging validation passes.
- **M0:** This plugin has no runtime capability. Do not install on any WordPress instance until M1+ releases exist.
- **Adapters:** Delivery, support, theme, storefront, and SEO validation adapters are maintained by the host — not shipped in the core ZIP.

## Prerequisites

| Requirement | Check |
|-------------|-------|
| WordPress | ≥ 6.5 (see [`docs/compatibility/wordpress-woocommerce.md`](compatibility/wordpress-woocommerce.md)) |
| PHP | ≥ 8.1 |
| WooCommerce | Active; HPOS status documented |
| Action Scheduler | Available (ships with WooCommerce) |
| Reliable cron | Host cron or guaranteed AS runner |
| Email transport | Working `wp_mail` for invitation emails (M2+) |
| Backups | Database + uploads before first activation |

## Compatibility verification

1. Confirm WooCommerce and WordPress versions meet minimums in `docs/compatibility/`.
2. Confirm HPOS mode and note in change log.
3. Confirm SEO plugin owns Product schema (UPR must not duplicate).
4. Verify no conflicting review-suite plugin is active.

## Deployment order

1. Deploy host adapters (delivery, support) to MU-plugins or companion plugins **before** or **with** UPR — adapters are required for precise delivery timing.
2. Install UPR plugin ZIP on **staging**.
3. Run database backup.
4. Activate plugin (M1+ only).
5. Apply WooCommerce settings per [`docs/integration/woocommerce-settings.md`](integration/woocommerce-settings.md).
6. Configure UPR settings (delays, retention, pilot flags) via admin or documented WP options.
7. Run reconciliation dry-run: `wp upr reconcile-invitations --dry-run` (M2+).
8. Validate invitation test order on staging.
9. Validate moderation queue and PDP review display with host theme adapters.
10. Run schema acceptance checks per [`docs/integration/schema-acceptance.md`](integration/schema-acceptance.md).

## Configuration checklist

- [ ] `woocommerce_enable_reviews = yes`
- [ ] `woocommerce_review_rating_verification_required = yes`
- [ ] `woocommerce_feature_customer_review_request_enabled = no`
- [ ] Global comment moderation **unchanged**
- [ ] Delivery adapter registered
- [ ] Support adapter registered
- [ ] UPR delays: 10d post-delivery, 14d fallback, 30d token TTL
- [ ] Pilot cohort configured (if applicable)

## Validation

| Area | Action |
|------|--------|
| PDP | Verified purchaser can submit; guest blocked without token |
| Invitation | Test order receives bundled email with per-item links (M2+) |
| Token | Open link without redeem; submit redeems; replay rejected |
| Moderation | New review enters hold queue |
| Partial refund | Only affected line item invitations revoked |
| Retention | Protected spam not deleted before retention window |
| Schema | One Product entity; visible review parity |

## Rollback

1. Deactivate UPR plugin.
2. Leave existing approved reviews in place (native WP comments).
3. Cancel pending AS jobs in group `upr` if M2+ was active.
4. Remove host adapters if they cause conflicts.
5. Restore WooCommerce review settings if needed.
6. Document rollback in host change log.

Rollback does **not** automatically delete review comments or audit tables. Purge decisions are operator-driven.

## Release process reference

See [`docs/compatibility/release-process.md`](compatibility/release-process.md) for versioning, ZIP build, checksum, and installation — documented for future milestones; not executed in M0.

## Support escalation

Use runbooks under [`docs/runbooks/`](runbooks/) for operational incidents.
