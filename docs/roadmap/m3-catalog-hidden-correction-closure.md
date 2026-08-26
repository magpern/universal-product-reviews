# M3 catalogue-hidden UPR-core correction — closure

**Verdict:** PASS — generic-core correction shipped as **`v0.2.1`**. M3 DEV pilot remains blocked until the host adapter pins this release and WP6 checks rerun.

## Root cause

`ProductReviewability::is_reviewable()` treated any `publish` product as reviewable. WooCommerce `catalog_visibility = hidden` was not evaluated. `SuppressionService` only listened to post-status transitions, so visibility changes left invitations/tokens/sessions active.

## Final generic design

| Layer | Change |
|-------|--------|
| `ProductReviewability` | Published + not `catalog_visibility=hidden` (via `wc_get_product()->get_catalog_visibility()`) |
| `SuppressionService` | `woocommerce_product_set_visibility` + `_visibility` meta fallback → `suppress_product_not_reviewable()`; reject in-flight review comments on suppress |
| `ReviewAvailability` | Early `product_not_reviewable` gate for logged-in native availability |

Completed approved reviews are preserved. Restoring visibility does not resurrect suppressed invites/tokens/sessions/claims.

## Artifacts

| Item | Reference |
|------|-----------|
| Freeze plan | [`docs/roadmap/m3-catalog-hidden-product-non-reviewable.md`](roadmap/m3-catalog-hidden-product-non-reviewable.md) |
| Freeze PR | [#10](https://github.com/magpern/universal-product-reviews/pull/10) |
| Freeze merge commit | `be025941f040352700e457fb9467e9fab4a84249` |
| Freeze tag | `m3-catalog-hidden-correction-freeze` (annotated) → `be02594` |
| Implementation PR | [#11](https://github.com/magpern/universal-product-reviews/pull/11) |
| Implementation merge commit | `e5b9636a42db7aaf0837c7b6034a24b062fd4275` |
| Release tag | `v0.2.1` (annotated) → `e5b9636` |

## Validation

- CI run (freeze PR): [33011822703](https://github.com/magpern/universal-product-reviews/actions/runs/33011822703) — all required checks pass.
- CI run (implementation PR): [33012205155](https://github.com/magpern/universal-product-reviews/actions/runs/33012205155) — all required checks pass (M1 lint, unit PHP 8.1/8.4, integration WC 11.0.1).
- CI run (post-merge main): [33012366169](https://github.com/magpern/universal-product-reviews/actions/runs/33012366169) — all required checks pass.
- Integration matrix: `tests/integration/M3CatalogHiddenIntegrationTest.php` (11 cases covering mandatory matrix items).
- Unit coverage: `ProductReviewabilityTest`, `ReviewAvailabilityTest`.

## Non-actions (explicit)

- No GitHub Release or production ZIP
- No deployment, bind mount, host configuration, or DEV infrastructure change
- No host-adapter code or version pin (separate step)
- No production change

## Next step

Pin the M3 host integration to UPR **`v0.2.1`** and rerun the blocked DEV WP6 / pilot validation under the existing M3 host-integration freeze.
