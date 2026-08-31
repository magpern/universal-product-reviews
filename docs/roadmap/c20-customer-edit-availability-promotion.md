# C20 — `CustomerEditAvailability::resolve` promotion (P → S)

**Status:** **Promoted to Stable (S).** Independent of plugin SemVer / Release / ZIP. Runtime remains **`0.8.0`**. `v0.8.0` is not moved.  
**Date:** 2026-08-31  
**Baseline:** Universal Product Reviews `main` @ **`73d2f9a0033f1dfe5233f24f41612c5a170236c7`** (PR [#76](https://github.com/magpern/universal-product-reviews/pull/76)). M14 implementation merge **`b9f4a9596f41a5236e5488adf0461d7f4bea8ea2`** (PR [#73](https://github.com/magpern/universal-product-reviews/pull/73)).

This amendment **does not** bump SemVer, create a tag or GitHub Release, enable AI, install anything, or authorise operational gates.

---

## Frozen provisional contract

M14 freeze **E27** ([`m14-customer-seven-day-review-edits.md`](m14-customer-seven-day-review-edits.md)):

> Read-only `CustomerEditAvailability::resolve( int $comment_id, int $user_id ): array{ can_edit: bool, reason_code: string }`. **No** `apply_filters` that can force `can_edit=true`. No UPR theme/block UI. Guest edit UI is **only** `/upr-review/edit/` (M7 a11y). Sensitivity **none**. Not a write grant.

M14 implementation locked C20 as **provisional**. Promotion to Stable was an explicit M14 **non-goal** (§9) so it could be decided from merged behaviour, not assumed at freeze time.

---

## Promotion criteria (objective)

Criteria are taken from E27 plus M6 **L9** / [ADR-0003](../decisions/ADR-0003-public-contract-compatibility.md) rules for **S** (implemented, documented, CI-inventoried, not inert, SemVer-compatible for hosts). C10 (`NativePdpForm::should_render`) is the sibling display-only PHP helper already **S**.

| ID | Criterion | Verdict | Evidence |
|----|-----------|---------|----------|
| **P1** | Signature is exactly `resolve( int $comment_id, int $user_id ): array{ can_edit: bool, reason_code: string }` | **Pass** | `src/CustomerEdit/CustomerEditAvailability.php` |
| **P2** | No `apply_filters` that can force `can_edit=true` | **Pass** | Unit `test_c20_cannot_be_filter_forced`; integration hostile `upr_customer_edit_availability` filter ignored |
| **P3** | Display-only — `can_edit=true` does not grant writes | **Pass** | Integration: `CustomerEditAuthorization` remains unarmed; unarmed `wp_update_comment_data` still `WP_Error` |
| **P4** | Sensitivity none; return is bool + allowlisted reason code only | **Pass** | Implementation returns only `can_edit` / `reason_code`; no comment body, email, token, or hmac |
| **P5** | Not inert — hosts may show/hide a logged-in edit control; server remains fail-closed | **Pass** | Storefront matrix; `/upr-review/edit/` is the write UI |
| **P6** | Guest `user_id <= 0` is always `not_eligible` (E27 guest UI is the completed-invite route) | **Pass** | Integration: `user_id=0` on logged-in and guest comments |
| **P7** | Ineligible status / expired window deny | **Pass** | Integration: spam and `comment_date_gmt` older than `7 × 86400` → `not_eligible` |
| **P8** | Stable CI inventory (M6 L9) | **Pass** | `scripts/ci/m6-stable-contracts.tsv` row `C20` + registry **S** section |

**Reason-code vocabulary locked for S:** exactly `ok` or `not_eligible`. Generic denial; no existence oracle. Hosts must not treat other strings as part of this contract.

No criterion remains unsatisfied. C20 is promoted to **S**.

---

## Compatibility guarantees

- **S** under ADR-0003: breaking changes only in a minor release with CHANGELOG section “Breaking (public contracts)” and a registry doc version bump.
- Registry identifier remains **`upr-public-contracts/v1`** (same as C19 addition — promotion is not a v1→v2 break).
- No new public `upr_*` filter.
- C20 **never** arms `CustomerEditAuthorization` and **never** substitutes for E2/E3 write proof.
- Guest authors continue to re-enter via the original completed invite secret (E29/E30), not C20.
- Plugin SemVer remains **`0.8.0`** until a separately authorised release.

---

## Explicit non-actions

- No SemVer bump, version tag, GitHub Release, ZIP, or deployment
- No DEV or production WordPress access
- No C16/C17; C11 remains Provisional
- No host/theme UI in this repository

## Related

- [`m14-customer-seven-day-review-edits.md`](m14-customer-seven-day-review-edits.md)
- [`m14-customer-seven-day-review-edits-closure.md`](m14-customer-seven-day-review-edits-closure.md)
- [`../integration/public-contracts.md`](../integration/public-contracts.md)
- [`../decisions/ADR-0003-public-contract-compatibility.md`](../decisions/ADR-0003-public-contract-compatibility.md)
