# Architecture — Universal Product Reviews (Rev 6, frozen)

**Status:** Frozen at M0 (`plan-rev6-freeze`). Authoritative specification for M1–M9 product scope (M9 runtime authorised by its own freeze).

**Repository governance:** The repository is public and proprietary, as decided by [ADR-0001](docs/decisions/ADR-0001-repository-visibility.md). This later governance decision overrides Rev 6's private-repository assumption without changing the frozen technical scope.

**Productization boundary:** Two layers only — this WooCommerce core plus external host adapters — as decided by [ADR-0002](docs/decisions/ADR-0002-productization-boundary.md). Do not introduce a third reusable WooCommerce companion package.

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
- Availability contract (`upr_product_review_availability`) and availability-aligned native product-comment enforcement (see [ADR-0002](docs/decisions/ADR-0002-productization-boundary.md); B1 target `v0.2.2`)
- Review-scoped moderation for product reviews
- Deterministic spam rules, `upr_audit`, `PurgeContext` retention
- 7-day edit authorization and re-moderation
- Admin queue, invitations grid, settings, reconcile tool
- Public extension API (documented below)

Core **must not** use `comments_open` as an availability or submission gate.

### 3.2 External adapter roles

| Adapter | Contract | Purpose |
|---------|----------|---------|
| Delivery | `upr_order_delivery_confirmed`, `upr_order_delivery_invalidated`, `upr_is_order_delivered` | Confirmed-delivery trigger |
| Support | `upr_review_invitation_action` | Delay vs permanent suppress |
| Storefront | WooCommerce product rating APIs + C9/C10 | PDP summary and form gating (**C17 deferred**) |
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
| Customer edit (M14) | UPR | Token-free `/upr-review/edit/` + `wp_update_comment` under request-local arm; 7×86400s from `comment_date_gmt`; approve→hold via `ApproveToHoldCas`. Freeze: [`docs/roadmap/m14-customer-seven-day-review-edits.md`](docs/roadmap/m14-customer-seven-day-review-edits.md) |

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

### AI (M8 planning; M9 local shadow; M10 external advisory)

Authoritative planning: [`docs/roadmap/m8-ai-assisted-moderation-planning.md`](docs/roadmap/m8-ai-assisted-moderation-planning.md). M9 freeze: [`docs/roadmap/m9-local-ai-shadow-mode.md`](docs/roadmap/m9-local-ai-shadow-mode.md). M10 freeze: [`docs/roadmap/m10-external-ai-advisory-assessments.md`](docs/roadmap/m10-external-ai-advisory-assessments.md). Boundary ADR: [`docs/decisions/ADR-0004-ai-moderation-boundary.md`](docs/decisions/ADR-0004-ai-moderation-boundary.md).

- **M8** — documentation only; no runtime AI
- **M9** — local-only **built-in** shadow assessment; advisory only; disabled by default; no automated status changes; **no** replaceable provider filter
- **M10** — optional OpenAI advisory via fixed enum `local` \| `openai`; Responses API with `store: false`; credential resolution constant → environment → encrypted Controls-stored option (O9′); path-scoped `wp_remote_post` under `src/Ai/OpenAi/` only; fail-closed (no silent local fallback); C19 `AiProvider::selected()`
- **M11** — automatic approval requires ADR amendment + governed calibration
- Never suppress based on sentiment, rating, or criticism; rating excluded from assessor inputs
- Human moderation remains authoritative; AI never mutates comment body, author, rating, linkage, or status
- AI off → no new assessment row and no AI audit (including in-flight and disable-then-non-held-transition)
- Provider secrets: `UPR_OPENAI_API_KEY` constant then environment then encrypted option `upr_openai_api_key_ciphertext` per [`docs/roadmap/m10-o9-encrypted-openai-credential-amendment.md`](docs/roadmap/m10-o9-encrypted-openai-credential-amendment.md); never returned to UI/REST/CLI/diagnostics/export/logs (status/source only)
- Live external enablement requires separate privacy/ops GO (see M10 freeze §11) — not authorised by implementation merges alone

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
| `{prefix}upr_tokens` | Hashed tokens (`invite`, `form_session`, M14 `edit_session`) |
| `{prefix}upr_audit` | Append-only moderation/revision audit |
| `{prefix}upr_review_edit_claims` | M14 in-flight edit claims (see M14 freeze) |
| WP comments + `_upr_*` meta | Review body, rating, item linkage |

---

## 12. Review moderation operations (M5)

Authoritative freeze: [`docs/roadmap/m5-review-moderation-operations.md`](docs/roadmap/m5-review-moderation-operations.md).

- Native Comments-admin context (product reviews): columns, views, filters, bounded prefetch
- Deterministic audit of in-scope status transitions (`review.status_changed` / `review.system_spam` / `review.system_status_changed`)
- Nesting-safe `SystemStatusOrigin` for all UPR comment-status mutations
- Verified staff-reply hold exemption + `review.reply_posted`
- No parallel moderation queue; no UPR review-editing UX; no customer edits in M5

### Customer review edits (M14)

Authoritative freeze: [`docs/roadmap/m14-customer-seven-day-review-edits.md`](docs/roadmap/m14-customer-seven-day-review-edits.md).

- Body + star rating only; **7 × 86400 seconds after `comment_date_gmt` (UTC)** — not first approval (`_upr_approved_at` is unimplemented and is not the clock)
- Logged-in PDP: `comment.user_id` + `wc_customer_bought_product()`. Guests: original invite HMAC via **completed-invite lookup** (`redeemed_at` set, `revoked_at` NULL, matching item/comment/product); never M2 `find_active_by_raw`, never a second submit
- Token-free `/upr-review/edit/`; approve→hold only via `ApproveToHoldCas` (operator spam/trash wins)
- Audit event `review.customer_edited` (privacy-safe; **no** stored prior bodies)
- Customer cannot edit another customer's review

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

UPR core provides no theme templates. Host adapters implement tab CSS, purchase-panel summary via **WooCommerce public rating APIs** (**C17** `upr_product_rating_summary` remains deferred), and card ratings (feature flag + ≥3 approved reviews per product).

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

### M5 — Review Moderation Operations

- Native Comments-admin context; deterministic moderation audit; staff-reply hold exemption
- Freeze: [`docs/roadmap/m5-review-moderation-operations.md`](docs/roadmap/m5-review-moderation-operations.md)
- Customer 7-day edits are **not** M5 (M14)

### M6 — Integration and Developer Experience

- Public-contract registry, integrator onboarding, generic examples
- Integration-readiness diagnostics (I1–I5); fail-safe delivery event contracts
- Freeze: [`docs/roadmap/m6-integration-and-developer-experience.md`](docs/roadmap/m6-integration-and-developer-experience.md)
- AI is **not** M6 (later / post-DPIA)

### M7 — Storefront Compatibility and Quality

- Storefront compatibility docs and C9/C10/guard characterization; guest form a11y
- Freeze: [`docs/roadmap/m7-storefront-compatibility-and-quality.md`](docs/roadmap/m7-storefront-compatibility-and-quality.md)

### M8 — AI-Assisted Moderation Planning

- Planning freeze only: privacy, lifecycle, claims, retention, local-vs-external split; **no** runtime AI
- Freeze: [`docs/roadmap/m8-ai-assisted-moderation-planning.md`](docs/roadmap/m8-ai-assisted-moderation-planning.md)
- ADR: [`docs/decisions/ADR-0004-ai-moderation-boundary.md`](docs/decisions/ADR-0004-ai-moderation-boundary.md)
- First implementation is **M9 local shadow** under the M9 freeze; auto-approval is **M11**

### M9 — Local AI Shadow Mode

- Built-in-only local advisory shadow; disabled by default; zero status mutation; no provider filter / no C18
- Freeze: [`docs/roadmap/m9-local-ai-shadow-mode.md`](docs/roadmap/m9-local-ai-shadow-mode.md)
- Closure: [`docs/roadmap/m9-local-ai-shadow-mode-closure.md`](docs/roadmap/m9-local-ai-shadow-mode-closure.md)
- Runtime on `main`; SemVer / Release separately authorised

### M14 — Customer 7-day review edits

- Body + rating self-edit for 7 days after `comment_date_gmt`; completed-invite guest proof; serialized `edit_session` reissue; `ApproveToHoldCas`
- Freeze: [`docs/roadmap/m14-customer-seven-day-review-edits.md`](docs/roadmap/m14-customer-seven-day-review-edits.md)
- Closure: [`docs/roadmap/m14-customer-seven-day-review-edits-closure.md`](docs/roadmap/m14-customer-seven-day-review-edits-closure.md)
- Runtime remains `0.8.0` until a separately authorised SemVer

---

## 17. Rollout gates (host)

1. Manual moderation for first 50–100 reviews
2. Near-zero spam false-positive before expanding auto-spam
3. Card rating feature flag after calibration; ≥3 reviews per product
4. Host DPIA / process gate before enabling AI shadow (human obligation; not a core machine checkbox)

---

## 18. Deferred: reward/incentive programme

Review incentives (coupons, points, rating-conditioned rewards) are **out of scope** for M1–M9. Any future programme must reward honest reviews regardless of rating and require separate compliance review.

---

## 19. Freeze statement

Rev 6 is materialised in this repository at M0. [ADR-0001](docs/decisions/ADR-0001-repository-visibility.md) is the authoritative later decision on repository visibility. Implementation of runtime features begins at M1 only after `plan-rev6-freeze` tag on `main`.
