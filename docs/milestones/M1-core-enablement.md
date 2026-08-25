# M1 — Core Enablement (frozen specification)

**Status:** Frozen at `m1-core-enablement-freeze`. Authoritative specification for M1 implementation only.

**Baseline:** [`ARCHITECTURE.md`](../../ARCHITECTURE.md) Rev 6 (`plan-rev6-freeze`); repository governance per [ADR-0001](../decisions/ADR-0001-repository-visibility.md).

**Implementation tag:** None at freeze. Release tag `v0.1.0` is created only after M1 validation is accepted (post-closure).

---

## 1. Scope summary

M1 delivers the smallest safe runtime foundation for native WooCommerce product reviews with UPR-controlled, review-scoped behaviour.

### In scope

1. Safe bootstrap and WooCommerce dependency gate (no fatal when WC inactive).
2. HPOS `custom_order_tables` compatibility declaration (public `FeaturesUtil` API).
3. Review-scoped moderation via `pre_comment_approved` (approve → hold only).
4. Interim guest submission guard via `preprocess_comment` (after WC type normalisation).
5. Data-only submission availability filters for host adapters.
6. Unit and integration tests with mandatory CI leg matching host DEV stack coordinates.
7. Generic documentation updates listed in the freeze PR.

### Out of scope (M2+)

Invitations, reminders, Action Scheduler jobs, custom tables/migrations, signed tokens, form sessions, guest token endpoint, email, WP-CLI commands, delivery/support adapters, spam classification, retention/`PurgeContext`, AI, customer edits, schema/JSON-LD, PDP UI, theme CSS, card ratings, WooCommerce option writes, admin settings-drift diagnostics, production deployment, GitHub Release.

---

## 2. Interim submission policy (M1)

| Actor | M1 behaviour | Mechanism |
|-------|--------------|-----------|
| Logged-in verified purchaser | May submit via native WC PDP | WC verified-owner check + UPR hold |
| Logged-in non-verified purchaser | Blocked | WC `pre_comment_on_post` when verification required |
| Guest | **Blocked** from product reviews | UPR `preprocess_comment` guard |
| Guest UX messaging | Informational only | `upr_product_review_availability` filter; polished UI in **M3** |
| Guest invitation submit | **M2+** | Signed-token flow replaces native guest path for invite holders |

**Enforcement + contract:** Availability filters alone are insufficient (guests may enter purchaser email in native form). M1 **must** block guest product-review inserts at `preprocess_comment`.

---

## 3. Review scope

A comment is **in scope** when **both**:

- parent post type is `product`; and
- normalised `comment_type` is `review`.

Implemented in `UniversalProductReviews\Moderation\ReviewScope::isProductReview( array $commentdata ): bool`.

Out-of-scope comments retain existing WordPress approval behaviour unchanged.

---

## 4. Runtime architecture

### 4.1 Bootstrap sequence

1. Plugin bootstrap: constants, PHP ≥8.1 gate, Composer autoload.
2. `before_woocommerce_init`: HPOS compatibility declaration (guarded if WC absent).
3. `woocommerce_loaded`: `Plugin::init()` registers M1 services.

When WooCommerce is inactive or unavailable: no M1 hooks; no fatal error.

**Forbidden in M1:** `register_activation_hook`, option writes, database tables, cron, REST routes, frontend HTML, `admin_notices` for settings drift.

### 4.2 HPOS compatibility

```php
\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
    'custom_order_tables',
    UPR_PLUGIN_FILE,
    true
);
```

Registered on `before_woocommerce_init`. No order queries in M1.

### 4.3 Review-scoped moderation

**Hook:** `pre_comment_approved` (filter, priority **10**).

| Condition | Result |
|-----------|--------|
| In-scope review + `$approved === 1` | Return **`0`** (pending) |
| In-scope review + `$approved` is `0`, `'spam'`, `'trash'`, or `WP_Error` | Return **unchanged** |
| Out-of-scope | Return **`$approved` unchanged** |

**Must not** read or write `comment_moderation`, `comment_whitelist`, or other global comment-policy options.

**Class:** `UniversalProductReviews\Moderation\ReviewModeration`.

### 4.4 Guest submission guard

**Hook:** `preprocess_comment` (filter, priority **5**) — **not** `pre_comment_on_post`.

WC `WC_Comments::update_comment_type` runs at priority **1** on the same filter. UPR runs after type normalisation so `ReviewScope` can evaluate full comment data.

| Condition | Result |
|-----------|--------|
| In-scope review + guest (not logged in) | Reject before persistence (`wp_die` with generic message) |
| In-scope review + logged-in | Pass through; WC verification applies |
| Non-review product comment or non-product comment | Pass through unchanged |

**Class:** `UniversalProductReviews\Submission\GuestSubmissionGuard`.

### 4.5 Availability contract

**Filters (data-only; UPR emits no HTML):**

- `upr_product_review_availability` — see [`submission-availability.md`](../integration/submission-availability.md)
- `upr_product_review_unavailable_message` — optional host copy hints; default `null`

**Class:** `UniversalProductReviews\Submission\ReviewAvailability`.

Default reason codes: `reviews_disabled`, `guest_requires_invitation`, `not_verified_purchaser`.

---

## 5. Class responsibilities

| Class | Namespace | Responsibility |
|-------|-----------|----------------|
| `Plugin` | `UniversalProductReviews` | Wire M1 services on `woocommerce_loaded` |
| `WooCommerceGate` | `UniversalProductReviews\WooCommerce` | Detect WC availability |
| `FeatureCompatibility` | `UniversalProductReviews\WooCommerce` | HPOS declare (optional split from bootstrap) |
| `ReviewScope` | `UniversalProductReviews\Moderation` | Product-review scope detection |
| `ReviewModeration` | `UniversalProductReviews\Moderation` | `pre_comment_approved` hold policy |
| `GuestSubmissionGuard` | `UniversalProductReviews\Submission` | Guest block on `preprocess_comment` |
| `ReviewAvailability` | `UniversalProductReviews\Submission` | Availability filter defaults |

---

## 6. Supported public APIs

| API | Use |
|-----|-----|
| `pre_comment_approved` | Hold approved in-scope reviews |
| `preprocess_comment` | Block guest in-scope reviews |
| `before_woocommerce_init` | HPOS declare hook point |
| `FeaturesUtil::declare_compatibility()` | HPOS |
| `woocommerce_loaded` | Bootstrap gate |
| `get_post_type()`, `is_user_logged_in()` | Scope / actor |
| `wc_customer_bought_product()` | Verified purchaser (availability) |
| `apply_filters( 'upr_*' )` | Host extension |

**Forbidden:** `Automattic\WooCommerce\Internal\OrderReviews\*`.

---

## 7. Host WooCommerce configuration (generic)

Hosts apply via replay checklist — **not** via plugin code. See [`woocommerce-settings.md`](../integration/woocommerce-settings.md).

---

## 8. Test requirements

### Unit tests

- `ReviewScope` matrix (product+review, product+comment, post+review, etc.).
- `ReviewModeration` hold and pass-through (including spam/trash preservation).
- `ReviewAvailability` reason codes.
- `GuestSubmissionGuard` logic with stubbed WP functions.

### Integration tests (mandatory CI leg)

Coordinates: **PHP 8.4**, **WordPress 7.0.2**, **WooCommerce 11.0.1**.

| ID | Criterion |
|----|-----------|
| AC-1 | Verified purchaser product review → pending (`comment_approved = 0`) |
| AC-1b | Pre-existing spam/trash on in-scope review → unchanged by UPR |
| AC-2 | Guest product review → rejected, not persisted |
| AC-2b | UPR `preprocess_comment` runs after WC type normalisation |
| AC-3 | Ordinary blog comment → unchanged approval |
| AC-4 | Non-review comment on product → not blocked; approval unchanged |
| AC-5 | Global `comment_moderation` / `comment_whitelist` unchanged |
| AC-6 | WC deactivated → no fatal; M1 hooks not registered |
| AC-7 | HPOS compatibility declared |
| AC-8 | No M2+ artefacts (CI grep) |
| AC-9 | Availability reason codes |
| AC-10 | No frontend HTML from UPR |
| AC-11 | CI config asserts mandatory integration coordinates |

Optional non-blocking floor leg: PHP 8.1, WP 6.5, WC 8.2 (M7 expands matrix).

---

## 9. Acceptance criteria (DEV validation themes)

Host DEV replay (documented outside this repository) must demonstrate:

- Eligible logged-in verified purchaser submits review → **Pending**.
- Guest product-review submission **rejected**.
- Blog comment behaviour unchanged.
- Global comment settings unchanged.
- HPOS compatibility confirmed in WooCommerce Features.
- No M2+ capability present.

---

## 10. Rollback requirements (generic)

1. Deactivate UPR plugin.
2. Restore host WooCommerce review settings from pre-M1 snapshot.
3. Leave existing approved reviews in place (native WP comments).
4. Confirm comment behaviour matches pre-M1 baseline.

Host-specific rollback steps live in host-side documentation only.

---

## 11. Explicit exclusions checklist

Implementation must **not** introduce:

- Custom database tables or migrations
- Action Scheduler hooks or `as_schedule_*`
- `WP_CLI::add_command` / `wp upr`
- Token/session endpoints or rewrite rules
- Email sending
- `wc_add_notice`, template hooks, or enqueued assets for review UI
- WooCommerce `update_option` for review settings
- Imports from `Automattic\WooCommerce\Internal\*`

---

## 12. Freeze statement

This document is materialised at M1 plan freeze (`m1-core-enablement-freeze`). Runtime implementation begins only after this tag exists on generic `main` and host-side M1 freeze documentation is merged.
