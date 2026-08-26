# M3 — Catalogue-hidden product non-reviewable (UPR-core correction)

**Status:** Frozen implementation plan (authoritative after tag `m3-catalog-hidden-correction-freeze`).  
**Baseline:** `v0.2.0` @ `4eb1f965d5ab87d8d1e1479257fbb8721f25ce41`  
**Target release:** patch `v0.2.1` (no GitHub Release / ZIP from this milestone).

## Confirmed defect

M3 WP6 pilot verification (external host integration evidence, frozen 2026-08) proves:

- `ProductReviewability::is_reviewable()` only checks `post_status === 'publish'`.
- A WooCommerce product with `catalog_visibility = hidden` and `publish` status remains reviewable.
- `SuppressionService` listens to `transition_post_status` only; visibility changes do not suppress/revoke invitations.
- M2 spec (§5.2) treats hidden/discontinued products as not reviewable; runtime did not enforce catalogue-hidden.

## Generic-core design

Single source of truth: **`ProductReviewability::is_reviewable()`**.

1. Extend default reviewability: published **and** catalogue visibility not `hidden` (via `wc_get_product()->get_catalog_visibility()`).
2. Filter `upr_product_is_reviewable` remains the host extension point (M3 host adapter unchanged).
3. **`SuppressionService`:** register `woocommerce_product_set_visibility` → when product is not reviewable, call existing `suppress_product_not_reviewable()` (same code path as status transitions).
4. **Fallback:** `updated_post_meta` on `_visibility` for products when meta is written without object API (documented; reconciliation remains backstop).
5. **`ReviewAvailability`:** consult `ProductReviewability` before returning `can_submit: true` (closes logged-in native PDP gap).

No host tables, no `Internal\*` APIs, no frontend rewrite flushing.

## State-transition behaviour

When a product becomes **non-reviewable** (hidden, draft, private, trash, etc.):

| Asset | Behaviour |
|-------|-----------|
| Approved/completed reviews | **Retained** — comments unchanged |
| Scheduled AS actions | **Unscheduled** via `Jobs::unschedule_item()` |
| Invite tokens / form sessions | **Revoked** via `TokenRepository::revoke_for_item()` |
| Submit claims | **Cleared** atomically in `suppress_item()` |
| In-flight review comments (submitting) | **Rejected** (spam) via `CompletionService::reject_comment()` |
| New invitations | **Blocked** via `Eligibility` → `product_not_reviewable` |
| Exchange / form / submit | **Rejected** via existing lazy `is_reviewable()` gates |

When a product becomes **reviewable again** (e.g. visible):

- **No** automatic unsuppress, token reissue, session restore, or invitation reschedule.
- Future eligibility follows normal generic lifecycle only.

## Hooks and reconciliation

| Path | Mechanism |
|------|-----------|
| Admin / REST / `$product->save()` visibility | `woocommerce_product_set_visibility` → immediate suppress |
| Direct `_visibility` meta | `updated_post_meta` fallback |
| Post status draft/trash/private | Existing `transition_post_status` |
| Nightly / CLI reconcile | `Eligibility::evaluate_item()` → suppress ineligible (≤24h backstop) |
| Mid-submit race | Existing M2 suppression-race model (`abandon_lost_submission`) |

## Concurrency

- `suppress_item()` atomic UPDATE preserves M2 claim clearing.
- `CompletionService::finalize()` refuses suppressed rows.
- Reconciliation `repair_orphaned_reviews()` rejects comments for suppressed invites (unchanged).

## Test matrix (mandatory)

1. Published + hidden → not reviewable  
2. Hidden → no new invitation scheduled  
3. Visible → hidden: suppress, revoke, unschedule, block form/submit  
4. Interleave hidden + submit claim → no surviving approved/pending review  
5. Approved reviews remain after hide  
6. Reconcile idempotent across runs  
7. Hidden → visible does not resurrect invites/tokens/sessions  
8. Draft/private/trash unchanged  
9. Visible product flow unchanged  
10. No native comment-route bypass  

## Rollback boundary

Revert to `v0.2.0`. No schema migration. Host adapter requires no code change; pin UPR version only.

## M3 host compatibility

Completed M3 host adapter consumes public UPR contracts only. This correction is generic-core; host deployments must **pin** `v0.2.1+` and rerun WP6 before DEV pilot. No host plugin code changes in this milestone.
