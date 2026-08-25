# WooCommerce settings (host checklist)

Apply on staging before enabling invitations (M2+).

| Option | Value |
|--------|-------|
| `woocommerce_enable_reviews` | `yes` |
| `woocommerce_enable_review_rating` | `yes` |
| `woocommerce_review_rating_required` | `yes` |
| `woocommerce_review_rating_verification_required` | `yes` |
| `woocommerce_review_rating_verification_label` | `yes` (optional label) |
| `woocommerce_feature_customer_review_request_enabled` | **`no`** |

**Do not change:**

- `comment_moderation`
- `comment_whitelist`

UPR applies review-scoped hold via plugin filters.

Verify with WP-CLI (host):

```bash
wp option get woocommerce_enable_reviews
wp option get woocommerce_feature_customer_review_request_enabled
```
