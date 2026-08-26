# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added

- M2 invitations runtime: line-item invite state, HMAC tokens, form sessions, Action Scheduler jobs, guest invitation submit, mail transport abstraction, CLI reconcile/db-upgrade (plugin version `0.2.0` unreleased; no `v0.2.0` git tag yet).

### Documentation

- M2 invitations plan frozen at tag `m2-invitations-freeze`.
- [`docs/milestones/M2-invitations.md`](docs/milestones/M2-invitations.md) — authoritative M2 specification.

### Fixed

- Remove `upr_test_guest_block_without_die` production test seam from the guest submission guard.
- Strengthen guest-guard integration coverage via the real `wp_new_comment` / `preprocess_comment` pipeline, including WC type-normalisation ordering and non-persistence assertions.
- Fix integration bootstrap so HPOS declaration and wp-phpunit config load correctly under WooCommerce 11.0.1.

## [0.1.0] - M1 core enablement

### Added

- Safe WooCommerce-gated bootstrap (`woocommerce_loaded`).
- HPOS `custom_order_tables` compatibility declaration.
- Review-scoped moderation hold via `pre_comment_approved` (approve → pending only).
- Guest product-review submission guard via `preprocess_comment` (priority 5).
- Data-only availability filters: `upr_product_review_availability`, `upr_product_review_unavailable_message`.
- PHPUnit unit and integration test harness; CI mandatory leg WC 11.0.1 / PHP 8.4.

### Documentation

- M1 plan frozen at tag `m1-core-enablement-freeze`.
- [`docs/milestones/M1-core-enablement.md`](docs/milestones/M1-core-enablement.md), [`docs/integration/submission-availability.md`](docs/integration/submission-availability.md).

## [0.0.0] - M0 foundation

- Planning baseline only. No runtime review features.
