# Submission availability contract (M1+)

UPR exposes **read-only, data-only** eligibility information for host adapters. The generic core **does not** render HTML, call `wc_add_notice`, hook theme templates, or implement guest-token submission in M1.

## Interim M1 policy

| Actor | Can submit product review (M1) | Enforcement |
|-------|-------------------------------|-------------|
| Logged-in verified purchaser | Yes (native WC PDP) | WC verification + UPR moderation hold |
| Logged-in non-verified purchaser | No | WC verified-owner check |
| Guest | No | UPR `preprocess_comment` guard (until M2 token flow) |

**M2:** Signed-token guest submission for invitation holders.

**M3:** Host storefront/theme adapter renders polished unavailable-form messaging using these filters.

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
| Guest (`user_id === 0`) | `false` | `guest_requires_invitation` |
| Logged-in, verification required, not purchased | `false` | `not_verified_purchaser` |
| Logged-in, verification required, purchased | `true` | `null` |
| Logged-in, verification not required | `true` | `null` |

When verification is required, UPR uses public `wc_customer_bought_product()` against the current user's email and ID.

### Reason codes

| Code | Meaning |
|------|---------|
| `reviews_disabled` | Product reviews disabled in WooCommerce settings |
| `guest_requires_invitation` | Guest must use invitation flow (M2+); blocked in M1 |
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
| **M3** | Polished PDP unavailable-form UI using reason codes |
| **M2** | Guest invitation path bypasses native guest block for valid token holders |

See [`adapters.md`](adapters.md) — Storefront / availability messaging adapter.

---

## What UPR must not do (M1)

- Echo HTML or JavaScript for review forms
- Call `wc_add_notice` or WooCommerce template hooks for review UI
- Register rewrite rules for `/upr-review/{token}/` (M2+)
- Mutate WooCommerce or WordPress comment-policy options

---

## Related

- [`woocommerce-settings.md`](woocommerce-settings.md) — host replay checklist
- [`../milestones/M1-core-enablement.md`](../milestones/M1-core-enablement.md) — frozen M1 specification
