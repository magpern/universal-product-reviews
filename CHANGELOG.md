# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.3.0] - 2026-08-27

### Added

- Master setting **Enable review invitation emails** (`upr_invitation_emails_enabled`), default disabled (fail-closed for fresh installs and upgrades without an explicit store).
- **Emergency pause invitations** (`upr_invitation_emergency_pause`): blocks schedule/send, revokes outstanding invite tokens and form sessions, cancels pending invitation Action Scheduler work when possible.
- Scheduling boundary `upr_invitation_scheduling_boundary_at` refreshed on enable and unpause so reconciliation cannot retro-send pre-enable/pre-unpause source events.
- Public host contract `upr_invitation_send_authorisation` with decisions `allow` | `email_disabled` | `paused` | `not_authorised` (context includes `source_event_unix`).
- Generic WooCommerce admin settings page for the new controls (`manage_woocommerce`).
- WP-CLI `wp upr invitation-controls` status (flags / pause meta / boundary only; no PII).

### Changed

- Invitation scheduling, initial/reminder send, sent-state success, and reconciliation now enforce core controls and the host authorisation filter fail-closed.
- Deliberate behaviour change from ≤0.2.2: invitation email work no longer proceeds unless the master enable setting is explicitly on (and not emergency-paused / host-denied).

### Documentation

- Freeze: [`docs/roadmap/m3-invitation-email-controls.md`](docs/roadmap/m3-invitation-email-controls.md) (`upr-invitation-email-controls-freeze`).
- Adapter contract documented in [`docs/integration/adapters.md`](docs/integration/adapters.md).

## [0.2.2] - 2026-08-27

### Added

- Availability-aligned native product-review enforcement for logged-in identities via `NativeSubmissionGuard` (`preprocess_comment` priority 15).
- Display-only native PDP form helper `NativePdpForm::should_render()` for theme/storefront integrations.
- `ReviewAvailability::resolve()` / `allows_submit()` fail-closed helpers for shared availability reads.

### Security

- Native product-review inserts are rejected when `upr_product_review_availability` reports `can_submit=false` (including non-purchasers and catalogue-hidden products).
- Guests remain gated by `GuestSubmissionGuard` (session + request-local arm); no native guest PDP submit route.
- UPR does not register a `comments_open` availability/submission gate.

### Documentation

- Productization boundary ADR-0002 and B1 roadmap remain authoritative; integration docs updated for the display helper and enforcement alignment.

## [0.2.1] - 2026-08-26

### Fixed

- Treat WooCommerce catalogue-hidden (`catalog_visibility = hidden`) published products as non-reviewable in `ProductReviewability`.
- Suppress/revoke outstanding invitation state when catalogue visibility changes via `woocommerce_product_set_visibility` and `_visibility` meta fallback.
- Block logged-in native submission availability for non-reviewable products via `ReviewAvailability`.
- Reject in-flight review comments immediately when suppression wins (catalogue-hidden and other non-reviewable transitions).

### Documentation

- M3 catalogue-hidden correction plan frozen at tag `m3-catalog-hidden-correction-freeze`.
- [`docs/roadmap/m3-catalog-hidden-product-non-reviewable.md`](docs/roadmap/m3-catalog-hidden-product-non-reviewable.md).

## [0.2.0]

### Fixed

- Prevent concurrent duplicate guest reviews via durable per-item submit claim acquired before `wp_new_comment`.
- Require request-local M2 handler authorization in addition to form-session cookie (block native comment-route bypass).
- Replace migrator read-then-update lock with atomic option lease (`add_option` + CAS delete).
- Paginate reconciliation beyond 200 orders; derive `eligible_at` from source event time (not reconcile-now).
- Flush rewrite rules only on activation / controlled admin-CLI paths (never ordinary frontend `init`).

### Added

- M2 invitations runtime: line-item invite state, HMAC tokens, form sessions, Action Scheduler jobs, guest invitation submit, mail transport abstraction, CLI reconcile/db-upgrade.

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
