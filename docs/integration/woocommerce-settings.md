# WooCommerce settings (host checklist)

Apply on **staging/DEV** before activating UPR M1 runtime. Re-apply before enabling invitations (M2+).

UPR **does not** write these options. Hosts configure via replay tooling (WP admin or WP-CLI on the host).

## Target settings

| Option | Target value | Notes |
|--------|--------------|-------|
| `woocommerce_enable_reviews` | `yes` | Enables product reviews |
| `woocommerce_enable_review_rating` | `yes` | Star ratings on reviews |
| `woocommerce_review_rating_required` | `yes` | Rating required on submit |
| `woocommerce_review_rating_verification_required` | `yes` | Verified purchaser only |
| `woocommerce_review_rating_verification_label` | host choice (`yes` / `no`) | Verified-owner label toggle |
| `woocommerce_feature_customer_review_request_enabled` | **`no`** | UPR owns invitations (M2+) |

## Do not change

| Option | Policy |
|--------|--------|
| `comment_moderation` | Leave unchanged — UPR applies review-scoped hold via filters |
| `comment_whitelist` | Leave unchanged |

## UPR runtime behaviour (M1+)

- New product reviews (`comment_type = review` on `product` posts) enter **pending** moderation via `pre_comment_approved`.
- Guest product-review submissions are **blocked** until M2 signed-token flow (`preprocess_comment` guard).
- Global WordPress comment policy options are **not** modified by UPR.

## Verification (host tooling)

Hosts verify options with their standard WP-CLI or admin workflow. Example option names:

```
woocommerce_enable_reviews
woocommerce_enable_review_rating
woocommerce_review_rating_required
woocommerce_review_rating_verification_required
woocommerce_feature_customer_review_request_enabled
```

Host-specific replay commands and snapshots are documented outside this repository.

## Related

- [`submission-availability.md`](submission-availability.md)
- [`../milestones/M1-core-enablement.md`](../milestones/M1-core-enablement.md)
- [`../production-replay.md`](../production-replay.md)
