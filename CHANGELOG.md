# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Fixed

- Prevent concurrent duplicate guest reviews via durable per-item submit claim acquired before `wp_new_comment`.
- Require request-local M2 handler authorization in addition to form-session cookie (block native comment-route bypass).
- Replace migrator read-then-update lock with atomic option lease (`add_option` + CAS delete).
- Paginate reconciliation beyond 200 orders; derive `eligible_at` from source event time (not reconcile-now).
- Flush rewrite rules only on activation / controlled admin-CLI paths (never ordinary frontend `init`).

### Added

- M2 invitations runtime: line-item invite state, HMAC tokens, form sessions, Action Scheduler jobs, guest invitation submit, mail transport abstraction, CLI reconcile/db-upgrade (plugin version `0.2.0` unreleased; no `v0.2.0` git tag yet).

### Documentation

- M2 invitations plan frozen at tag `m2-invitations-freeze`.
- [`docs/milestones/M2-invitations.md`](docs/milestones/M2-invitations.md) — authoritative M2 specification.

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
