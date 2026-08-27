# B1 — Native submission enforcement and PDP display helper

**Status:** Implemented on branch `feat/b1-native-submission-enforcement` (target patch `v0.2.2`; release tag deferred).  
**Baseline release:** `v0.2.1` @ `e5b9636a42db7aaf0837c7b6034a24b062fd4275`  
**Target release:** patch `v0.2.2` (additive security + helper; no schema migration).  
**Architecture decision:** [ADR-0002](../decisions/ADR-0002-productization-boundary.md).
**Freeze:** `upr-productization-boundary-freeze`.

## Scope

Ship in generic UPR core only:

1. **Availability-aligned native product-comment enforcement** for all identities on the native comment pipeline (`preprocess_comment`), so that when `upr_product_review_availability` reports `can_submit=false`, native product-review inserts are rejected (including logged-in users on non-reviewable products).
2. **Generic native-PDP form display helper** (read-only) for themes/hosts: whether the native PDP review form may be rendered. Guests always false (including active M2 form sessions — guests use `/upr-review/form/` only).
3. Unit and integration tests covering the acceptance matrix below.
4. Documentation updates under `docs/integration/` as needed for the new APIs.

**Out of scope for B1:** host companion changes, theme/storefront markup, mail/delivery/support adapters, `comments_open` filters, admin UI, third packages, production deploy.

## Design constraints

- Source of truth for deny: existing filter `upr_product_review_availability` (core default).
- Preserve M2 guest path: form session **and** request-local authorization arm; do not authorize native guest POSTs via cookie alone.
- Public WordPress / WooCommerce APIs only; no `Automattic\WooCommerce\Internal\*`.
- No host, theme, fulfillment, support-desk, mail-provider, site, or product names in code, docs, APIs, fixtures, or tests.
- Do **not** introduce a `comments_open` filter (or equivalent) for availability or submission gating.
- Review-scoped moderation hold remains unchanged.

## Frozen B1 acceptance matrix

| # | Requirement | Pass condition |
|---|-------------|----------------|
| A1 | Genericity | No host/product/site/provider names in UPR code, docs, APIs, fixtures, or tests (CI forbid-lists pass) |
| A2 | Guest native route denied | Unauthenticated native product-review POST without M2 arm → rejected (403); no comment created |
| A3 | M2 armed guest flow unchanged | Valid form session + request-local arm still allows invitation-form submit path |
| A4 | Logged-in non-purchaser denied | When verification required and user has not purchased → native POST rejected; availability `can_submit=false` |
| A5 | Verified purchaser on reviewable product allowed | Logged-in verified purchaser on reviewable product → native POST not rejected by UPR availability guard; moderation hold still applies |
| A6 | Catalogue-hidden / non-reviewable denied for every identity | Guest and logged-in (including verified purchaser) → native POST rejected; `reason_code` `product_not_reviewable` when applicable |
| A7 | Native PDP display helper | Returns `false` for all guests, including when availability reports `can_submit=true` with `context.authorization = form_session` |
| A8 | No `comments_open` filter | B1 introduces no `comments_open` (or equivalent) availability/submission gate |
| A9 | Public APIs only | No WooCommerce `Internal\*` usage |
| A10 | Moderation hold unchanged | New in-scope product reviews still enter review-scoped pending hold |

## Suggested implementation sketch (non-normative)

Exact class names may vary; behaviour must satisfy the matrix:

- Extend or complement `GuestSubmissionGuard` so logged-in native product reviews are also denied when availability `can_submit` is false (without weakening the M2 armed guest path).
- Add a small `Submission\` helper used by themes/hosts for native form visibility (display-only; not an authorization oracle).

## Host follow-on (not B1)

After `v0.2.2` ships:

- Host companion moves its **UPR dependency pin** to `v0.2.2`, removes unsafe `comments_open` gating, and consumes the core display helper (B2).
- Host PDP reviews section work resumes only after B1 **and** merged B2 (B4).

## Rollback

Revert to `v0.2.1`. No schema migration. Hosts that never pinned `v0.2.2` are unaffected.

## Related

- [ADR-0002](../decisions/ADR-0002-productization-boundary.md)
- [submission-availability.md](../integration/submission-availability.md)
- [ARCHITECTURE.md](../../ARCHITECTURE.md)
