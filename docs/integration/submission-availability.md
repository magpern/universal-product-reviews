# Submission availability contract (M1+)

UPR exposes **read-only, data-only** eligibility information for host adapters. The generic core **does not** render HTML, call `wc_add_notice`, or hook theme templates for PDP messaging. Guest invitation submission is specified in M2 ([`../milestones/M2-invitations.md`](../milestones/M2-invitations.md)).

**Productization boundary:** [ADR-0002](../decisions/ADR-0002-productization-boundary.md). Filter `upr_product_review_availability` is the **core source of truth** for whether a product review may be submitted for a given `(product_id, user_id)`. Future native product-comment enforcement in core **must align** with that contract (`can_submit=false` → native product-review insert denied for all identities, without weakening the M2 armed guest invitation path).

## Policy by milestone

| Actor | M1 | M2 (frozen; runtime post-freeze) | B1+ (`v0.2.2`) |
|-------|----|----------------------------------|----------------|
| Logged-in verified purchaser | Native WC PDP | Unchanged | Native PDP when reviewable; `NativeSubmissionGuard` |
| Logged-in non-verified purchaser | Blocked by WC | Unchanged | Also denied by core when availability `can_submit=false` |
| Guest (native PDP) | Blocked | **Default-deny** unless active form-session cookie for that product | Unchanged; `NativePdpForm::should_render()` always false for guests |
| Guest (invitation form) | N/A | Allowed via token exchange → session → form; M1 hold still applies | Unchanged — exclusively `/upr-review/form/` |

**M3 UI:** Host storefront/theme adapter renders polished unavailable-form messaging using these filters. Hosts **must not** close WordPress `comments_open` to express unavailable submission: that hides approved review lists in stock WooCommerce templates. Keep `comments_open` open for list display; gate the native form via `NativePdpForm::should_render()` and host/theme markup.

**UPR does not use `comments_open` as an availability gate.** Native product-comment enforcement aligns to `upr_product_review_availability` via `NativeSubmissionGuard`.

---

## Native enforcement (`NativeSubmissionGuard`)

Hook: `preprocess_comment` priority **15** (after `GuestSubmissionGuard` at 5 and WC type normalisation at 1).

- Scope: WooCommerce **product** posts with comment type `review` (after WC normalisation).
- When `ReviewAvailability::allows_submit( $product_id, $user_id )` is false → `wp_die` HTTP 403 (fail closed on malformed availability).
- Guests still require M2 form session **and** request-local arm (`GuestSubmissionGuard`); this guard does not create a native guest PDP route.
- M2 guest forms remain exclusively on `/upr-review/form/`.

---

## Display helper: `NativePdpForm::should_render( int $product_id ): bool`

**Display-only** — not a submission authorization API. Themes/storefronts use this to decide whether to render the native WooCommerce review form.

| Condition | Result |
|-----------|--------|
| `$product_id <= 0` | `false` |
| Guest (`user_id === 0`), including valid M2 form session | `false` |
| Availability `can_submit` false / malformed | `false` |
| `context.authorization === form_session` | `false` |
| Logged-in and availability allows | `true` |

```php
use UniversalProductReviews\Submission\NativePdpForm;

if ( NativePdpForm::should_render( $product_id ) ) {
    // Render native #review_form_wrapper
}
```

---

## Filter: `upr_product_review_availability`

```php
/**
 * @param array{
 *   can_submit: bool,
 *   reason_code: string|null,
 *   context: array<string, mixed>
 * } $availability
 * @return array{can_submit: bool, reason_code: string|null, context: array<string, mixed>}
 */
apply_filters(
    'upr_product_review_availability',
    $availability,
    int $product_id,
    int $user_id
);
```

### Parameters

| Parameter | Description |
|-----------|-------------|
| `$product_id` | WooCommerce product post ID |
| `$user_id` | Current user ID (`0` for guest) |

### Default computation (read-only WC options)

UPR reads WooCommerce review settings; it **never** writes options.

| Condition | `can_submit` | `reason_code` |
|-----------|--------------|---------------|
| `woocommerce_enable_reviews !== 'yes'` | `false` | `reviews_disabled` |
| Product not reviewable (e.g. catalogue-hidden) | `false` | `product_not_reviewable` |
| Guest (`user_id === 0`) without active M2 form session for product | `false` | `guest_requires_invitation` |
| Guest with active M2 form session for product | `true` | `null` (context may include `authorization: form_session`) |
| Logged-in, verification required, not purchased | `false` | `not_verified_purchaser` |
| Logged-in, verification required, purchased | `true` | `null` |
| Logged-in, verification not required | `true` | `null` |

When verification is required, UPR uses public `wc_customer_bought_product()` against the current user's email and ID.

### Reason codes

| Code | Meaning |
|------|---------|
| `reviews_disabled` | Product reviews disabled in WooCommerce settings |
| `product_not_reviewable` | Product fails `ProductReviewability` (e.g. catalogue-hidden) |
| `guest_requires_invitation` | Guest must use invitation flow; blocked on native PDP without session |
| `not_verified_purchaser` | Logged-in user has not verified purchase of this product |

Hosts may extend `$availability['context']` via filter callbacks for adapter-specific data (without site-specific identifiers in the generic core).

---

## Filter: `upr_product_review_unavailable_message` (optional)

```php
apply_filters(
    'upr_product_review_unavailable_message',
    null,
    string $reason_code,
    int $product_id,
    int $user_id
);
```

| Return | Meaning |
|--------|---------|
| `null` | Host supplies all copy (default) |
| `array{ text: string, type: 'info'|'notice' }` | Optional hint for host rendering |

UPR returns `null` by default. Host adapters decide presentation (storefront module, theme hook, etc.).

---

## Host adapter responsibilities

| Milestone | Adapter role |
|-----------|--------------|
| **M1** | May consume filters for diagnostics or minimal messaging; enforcement is in core guards |
| **M2** | Guest invitation path: session cookie authorizes submit for exact product; hosts redact `/upr-review/{token}/` from access logs |
| **M3** | Polished PDP unavailable-form UI using reason codes; **do not** use `comments_open=false` as the submit gate |
| **B1+** | Consume core native-PDP display helper for form visibility; do not reimplement availability-aligned native POST denial in host code |

See [`adapters.md`](adapters.md) — Storefront / availability messaging adapter.

---

## What UPR must not do

- Echo HTML or JavaScript for native PDP review forms (invitation form markup is M2 core, not theme PDP)
- Call `wc_add_notice` or WooCommerce template hooks for review UI messaging
- Expose raw invite/session secrets via public filters
- Mutate WooCommerce or WordPress comment-policy options
- Use `comments_open` (or equivalent) as an availability or submission gate

---

## Related

- [`woocommerce-settings.md`](woocommerce-settings.md) — host replay checklist
- [`../milestones/M1-core-enablement.md`](../milestones/M1-core-enablement.md) — frozen M1 specification
- [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md) — frozen M2 specification
- [`../decisions/ADR-0002-productization-boundary.md`](../decisions/ADR-0002-productization-boundary.md) — two-layer productization boundary
- [`../roadmap/b1-native-submission-enforcement.md`](../roadmap/b1-native-submission-enforcement.md) — B1 acceptance matrix
