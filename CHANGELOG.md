# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.8.0] - 2026-08-28

Combined core release of merged **M7 Storefront Compatibility and Quality** and **M9 Local AI Shadow Mode**, including corrective hardening from PR #44. **`v0.7.0` was intentionally not published** (no matching M7-only version-metadata commit; `main` already contained both M7 and M9).

### Added

- **M7:** Storefront compatibility matrix and integration-boundary documentation; contract-only C9/C10/enforcement characterization for classic and block/FSE storefronts (no visual theme suite in UPR).
- **M7:** Mandatory accessibility hardening on the core-owned `/upr-review/form/` guest invitation form, with markup/security regression coverage.
- **M7:** Native route and submission-guard guarantees preserved (fail-closed availability resolve; guest@5 / native@15 preprocess order; no UPR `comments_open` gating).
- **M9:** Local-only, default-off advisory shadow assessments (`upr_local_ai_shadow_enabled`); built-in assessor only; Comments-admin `upr_ai` display; diagnostics D12–D15 + Site Health.
- **M9:** Portable assessments / claims / ops schema; Action Scheduler `upr_assess_review`; claim-before-rate; disable-silent precedence; allowlisted AI audit events.
- **M9:** No external provider, no replaceable provider filter, no automatic moderation, and no AI-driven comment status changes.

### Fixed

- M9 ops rate window: compute `rate_count` before resetting `rate_window_started_at` so an expired full window resets to `1` instead of incrementing to `61`.
- M9 assessment finalize: roll back and keep the owned claim when terminal insert fails so a write error cannot leave neither a row nor a recoverable claim.
- M9 assessment finalize: require claim-clear `UPDATE` to affect exactly one row before commit; otherwise roll back the terminal insert so an active claim cannot coexist with a persisted assessment.

### Documentation

- Freeze: [`docs/roadmap/m7-storefront-compatibility-and-quality.md`](docs/roadmap/m7-storefront-compatibility-and-quality.md) (`m7-storefront-compatibility-and-quality-freeze`).
- Freeze: [`docs/roadmap/m9-local-ai-shadow-mode.md`](docs/roadmap/m9-local-ai-shadow-mode.md) (`m9-local-ai-shadow-mode-freeze`).

## [0.6.0] - 2026-08-28

### Added

- Canonical public contracts registry (`docs/integration/public-contracts.md`, docs/CI label `upr-public-contracts/v1`) with ADR-0003 compatibility policy and integrator onboarding.
- Uncached integration-readiness diagnostics **I1–I5** in Diagnostics and Site Health (`IntegrationReadiness`); support export schema unchanged (`upr-support-export/v1`).
- Fail-safe untyped receivers and runtime normalisation for **C1** `upr_order_delivery_confirmed` and **C2** `upr_order_delivery_invalidated` (`DeliveryEventNormaliser`).
- Thin stable helper `DeliveryStatus::has_confirmation( int $order_id ): bool`.
- Generic adapter example privacy tests for sensitive stable contracts; CI registry completeness guard for stable **S** entries.
- Docs-only WooCommerce review import strategy (`docs/integration/wc-review-import-strategy.md`).

### Changed

- C2 invalidation reason codes normalised to at most **43** ASCII characters before persistence so `delivery_invalidated:<code>` fits `suppression_code varchar(64)`; invalid or free-text input becomes `unspecified` (never stored raw).
- Operator runbooks expanded for integration readiness; expanded generic `site-upr-adapters.php.example`.

### Documentation

- Freeze: [`docs/roadmap/m6-integration-and-developer-experience.md`](docs/roadmap/m6-integration-and-developer-experience.md) (`m6-integration-and-developer-experience-freeze`).

## [0.5.0] - 2026-08-28

### Added

- Native Comments-admin context for UPR product reviews: Product, Rating, Source, and Order columns with bounded per-page prefetch.
- UPR product-review / pending views and invitation-linked / all source filters (positive linkage only; no native-only inference).
- Deterministic moderation audit on in-scope status transitions: `review.status_changed`, `review.system_spam`, `review.system_status_changed`, plus `review.reply_posted` for validated staff replies.
- Nesting-safe `SystemStatusOrigin` for all UPR comment-status mutations; CI forbids direct status APIs outside the wrapper.
- Verified native staff-reply hold exemption (`replyto-comment` nonce + caps + depth-one); never force-approve.
- Runbooks: rewritten [`docs/runbooks/moderation.md`](docs/runbooks/moderation.md), new [`docs/runbooks/moderation-capabilities.md`](docs/runbooks/moderation-capabilities.md).

### Changed

- Support export and invitation controls unchanged from M4.1; M5 adds no audit TTL/purge and no UPR review-editing UX.

## [0.4.0] - 2026-08-28

### Added

- M4.1 operator Overview / Diagnostics / Controls tabs under WooCommerce → Product Reviews (`manage_woocommerce`).
- Diagnostics catalogue D1–D11 with pass / warning / information / unavailable and ≤60s site transient cache.
- Site Health tests (schema, WooCommerce, Action Scheduler APIs, pause, invitation email status).
- Controlled database-upgrade admin action (never on page load) with confirm + audit.
- Allowlisted local support export (`upr-support-export/v1`, 7-day window; no order IDs / PII).
- Audit events `invite.emails_enabled` / `invite.emails_disabled`.
- Runbooks: [`docs/runbooks/operator-controls.md`](docs/runbooks/operator-controls.md), [`docs/runbooks/support-export.md`](docs/runbooks/support-export.md).

### Changed

- Admin settings experience evolved into the Controls tab (same options model); page-load / `admin_init` auto-migration hooks removed from bootstrap.

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
