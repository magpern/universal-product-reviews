# Architecture — Universal Product Reviews (Rev 6, frozen)

**Status:** Frozen at M0 (`plan-rev6-freeze`). Authoritative specification for M1–M8 implementation.

**Repository governance:** The repository is public and proprietary, as decided by [ADR-0001](docs/decisions/ADR-0001-repository-visibility.md). This later governance decision overrides Rev 6's private-repository assumption without changing the frozen technical scope.

**Product scope:** Portable WooCommerce product-review operations. Site-, theme-, fulfillment-, support-desk-, and SEO-plugin-specific wiring is **external** — host adapters documented in [`docs/integration/`](docs/integration/).

---

## 1. Design principles

| Principle | Implication |
|-----------|-------------|
| Native review data | WP comments + WC star rating meta; UPR adds operations, not a parallel review store |
| Public APIs only | No `Automattic\\WooCommerce\\Internal\\OrderReviews\\*` dependencies |
| Line-item granularity | Invitation state keyed by immutable WC order item ID |
| Token session exchange | Tokens are not consumed on page view; redeem on successful submit only |
| Review-scoped moderation | Global WordPress comment settings unchanged |
| Single invitation owner | WC `customer_review_request` feature stays disabled |
| Schema external | UPR does not emit Product JSON-LD |
| Adapters external | Delivery, support, storefront, theme, card, SEO validation live outside core |
| Conservative automation | Deterministic spam at launch; AI post-DPIA; near-zero false-positive gate for auto-spam |
| Deferred incentives | No review rewards/incentives in initial releases |

---

## 2. Naming conventions

| Surface | Convention | Examples |
|---------|------------|----------|
| Plugin slug | `universal-product-reviews` | — |
| PHP namespace | `UniversalProductReviews\\` (`UPR`) | `UniversalProductReviews\\Retention\\PurgeContext` |
| DB tables | `{prefix}upr_*` | `upr_invite_items`, `upr_tokens`, `upr_audit` |
| AS group | `upr` | — |
| AS hooks | `upr_*` | `upr_send_initial_bundle`, `upr_purge_spam`, `upr_reconcile_invitations` |
| WP-CLI | `wp upr` | `wp upr reconcile-invitations` |
| Public hooks | `upr_*` | `upr_review_invitation_action`, `upr_is_order_delivered` |
| Comment meta | `_upr_*` | `_upr_order_item_id`, `_upr_approved_at` |
| Logger source | `universal-product-reviews` | — |

---

## 3. Core vs host adapters

### 3.1 Portable core (this repository)

- Per–order-line-item invitation state (`upr_invite_items`)
- Signed tokens + session exchange + guest/account submission handlers
- Review-scoped moderation for product reviews
- Deterministic spam rules, `upr_audit`, `PurgeContext` retention
- 7-day edit authorization and re-moderation
- Admin queue, invitations grid, settings, reconcile tool
- Public extension API (documented below)

### 3.2 External adapter roles

| Adapter | Contract | Purpose |
|---------|----------|---------|
| Delivery | `upr_order_delivery_confirmed`, `upr_order_delivery_invalidated`, `upr_is_order_delivered` | Confirmed-delivery trigger |
| Support | `upr_review_invitation_action` | Delay vs permanent suppress |
| Storefront | `upr_product_rating_summary` | Optional PDP summary |
| Theme | CSS on native WC reviews tab | Presentation |
| Card | Host-owned product-card filter | Optional stars (≥3 reviews, post-calibration) |
| SEO validation | Host acceptance tests | One Product entity; visible review parity |

See [`docs/integration/adapters.md`](docs/integration/adapters.md).

---

## 4. WooCommerce site configuration

Hosts enable via replay checklist (not hard-coded in M0):

- `woocommerce_enable_reviews = yes`
- `woocommerce_review_rating_verification_required = yes`
- `woocommerce_feature_customer_review_request_enabled = no`
- Global `comment_moderation` / `comment_whitelist` — **unchanged**
- UPR review-scoped hold on new product reviews

---

## 5. Submission paths

| Path | Owner | Mechanism |
|------|-------|-----------|
| Account PDP | Native WC + UPR moderation filters | `wc_customer_bought_product()` |
| Guest invitation | UPR | Token → session → form → `wp_insert_comment` + meta |
| Edit (M5) | UPR | `wp_update_comment` + audit; return to hold |

**Allowed public APIs:** `wp_insert_comment`, `wp_update_comment`, comment meta, `wc_customer_bought_product`, core `pre_comment_on_post` verification, standard WC/WP filters.

**Forbidden:** `Automattic\\WooCommerce\\Internal\\OrderReviews\\*`

### Guest vs account policy

- Logged-in verified purchasers submit on PDP when purchase verified.
- Guests submit only via signed invitation token flow (not order key).
- Guests who later create an account with matching billing email may use PDP once WC recognizes the purchase.

---

## 6. Token → session → submit

1. **Open link:** Validate token (hash, expiry, not revoked, `redeemed_at` IS NULL). Create **short-lived item-scoped form session** (30–60 minutes). **Do not** set `redeemed_at`.
2. **Form:** Plugin-owned review form bound to session + order item.
3. **Submit:** Validate session + nonce + verified purchase + duplicate guards. Insert comment via public APIs. **Atomically:** set `redeemed_at`, revoke token, update `upr_invite_items.review_completed_at`, invalidate session.
4. **Concurrent/replay:** Idempotency per session; failed submit leaves token unredeemed.
5. **Reminder:** Fresh 30-day token; revoke prior active invite token for same item.

Endpoint: `/upr-review/{token}/` — overridable via `upr_review_page_url`.

---

## 7. Line-item invitation lifecycle

### Table `{prefix}upr_invite_items`

Primary key: `order_item_id`. Fields include: `order_id`, `product_id`, `variation_id`, `eligible_at`, `initial_sent_at`, `reminder_sent_at`, `review_completed_at`, `schedule_state`, `suppression_code`, `delay_until`.

Partial refund revokes **affected items only**. One email may bundle multiple item links.

### Delivery triggers

```php
do_action( 'upr_order_delivery_confirmed', int $order_id, array $context );
do_action( 'upr_order_delivery_invalidated', int $order_id, string $reason );
apply_filters( 'upr_is_order_delivered', false, $order_id );
```

**Fallback:** `woocommerce_order_status_completed` + configurable delay (default **14 days**) when no delivery adapter.

### Defaults

| Setting | Default |
|---------|---------|
| Delay after confirmed delivery | **10 days** |
| Fallback delay after completed | **14 days** |
| Token TTL | **30 days**; reminder issues fresh token |
| Third-party spam API | **Off** until host privacy review |
| Spam/trash retention | **30 days** before authorized purge |

### Reconciliation

```bash
wp upr reconcile-invitations [--lookback-days=90] [--dry-run]
```

Nightly AS: `upr_reconcile_invitations`.

### Suppression vs delay

```php
apply_filters(
  'upr_review_invitation_action',
  [ 'action' => 'none' ],
  $order_id,
  $order_item_id
);
```

- **`suppress`:** cancel, refund, opt-out, active compliance/safety case
- **`delay`:** open support tickets, dissatisfaction without compliance tag (avoids review-sample bias)
- **`none`:** proceed

---

## 8. Moderation

- Review-scoped hold via `pre_comment_approved` when `comment_type=review` on `product` posts
- All new product reviews held at launch; repeat reviewers still held unless policy changed post-calibration
- States: `hold`, `approve`, `spam` (retained until purge job)
- Never auto-delete uncertain or negative reviews

---

## 9. Spam, abuse, AI

### Launch (M1–M4)

- Deterministic rules only
- Regulatory keyword holds via `upr_regulatory_hold_patterns` filter
- PII detection: hold pending; moderator-approved edit with audit — **no auto-redact**

### AI (M6+, post-DPIA)

- Prerequisites: DPIA, provider retention decision, maintainer GO
- Shadow mode first; flags only; no external raw review text until approved
- Never suppress based on sentiment, rating, or criticism
- Auto-spam from AI only after large calibration sample and near-zero false-positive rate

---

## 10. Retention — `UniversalProductReviews\\Retention\\PurgeContext`

```php
final class PurgeContext {
  public static function run( callable $callback ): void;
  public static function is_purging(): bool;
}
```

- `pre_delete_comment`: block product-review spam/trash deletes unless `PurgeContext::is_purging()` and age ≥ retention
- Future spam-service integration must not bypass guard
- Sole authorized delete path: AS hook `upr_purge_spam`

---

## 11. Data model

| Store | Purpose |
|-------|---------|
| `{prefix}upr_invite_items` | Line-item invitation lifecycle |
| `{prefix}upr_tokens` | Hashed tokens |
| `{prefix}upr_audit` | Append-only moderation/revision audit |
| WP comments + `_upr_*` meta | Review body, rating, item linkage |

---

## 12. Customer edits (M5)

- One review per order line item
- Edits allowed **within 7 days** of approval → return to `hold`
- After 7 days: edits disallowed except exceptional support handling
- Authenticated and guest-token authorization paths
- Audit row per revision; customer cannot edit another customer's review

---

## 13. Product lifecycle

| Event | Behavior |
|-------|----------|
| Product discontinued | Approved reviews remain visible; close new submissions; stop invitations per line item |
| Product renamed | Reviews stay on post ID |
| Product merged | Manual migration script; audit event |
| Variation change | Retain variation meta in display |

---

## 14. Storefront UI (external)

UPR core provides no theme templates. Host adapters implement tab CSS, purchase-panel summary (`upr_product_rating_summary`), and card ratings (feature flag + ≥3 approved reviews per product).

---

## 15. SEO / schema (host)

UPR does not emit Product JSON-LD. Host tests assert one canonical Product entity and visible review parity. See [`docs/integration/schema-acceptance.md`](docs/integration/schema-acceptance.md).

---

## 16. Milestones

### M0 — Repository foundation (complete at freeze)

- Public proprietary repository governed by [ADR-0001](docs/decisions/ADR-0001-repository-visibility.md), inert scaffold `0.0.0`, frozen docs, CI, tag `plan-rev6-freeze`
- **No** runtime review features, host bind-mount, or production change

### M1 — Core enablement

- UPR bootstrap; review-scoped hold; guest messaging filters
- HPOS compatibility declaration
- WC review options via host replay

### M2 — Invitations

- `upr_*` tables; token/session/submit; `wp upr reconcile-invitations`
- Delivery/suppression hook contracts; email bundling

### M3 — Integration documentation + host UI

- Adapter doc finalisation; host implements theme/storefront externally

### M4 — Spam + retention

- Deterministic spam; `PurgeContext` + conflict tests

### M5 — Edits

- 7-day policy; audit rows; auth tests

### M6 — AI shadow (post-DPIA, optional)

- Off by default

### M7 — Test matrix

- Plugin unit/integration; integration acceptance criteria

### M8 — Pilot configuration

- Host pilot cohort via WP options / env config

---

## 17. Rollout gates (host)

1. Manual moderation for first 50–100 reviews
2. Near-zero spam false-positive before expanding auto-spam
3. Card rating feature flag after calibration; ≥3 reviews per product
4. DPIA before AI shadow mode

---

## 18. Deferred: reward/incentive programme

Review incentives (coupons, points, rating-conditioned rewards) are **out of scope** for M1–M8. Any future programme must reward honest reviews regardless of rating and require separate compliance review.

---

## 19. Freeze statement

Rev 6 is materialised in this repository at M0. [ADR-0001](docs/decisions/ADR-0001-repository-visibility.md) is the authoritative later decision on repository visibility. Implementation of runtime features begins at M1 only after `plan-rev6-freeze` tag on `main`.
