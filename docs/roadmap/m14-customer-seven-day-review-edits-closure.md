# M14 — Customer 7-day review edits closure

**Status:** **Accepted** (customer-edit implementation + post-merge acceptance). Runtime remains **`0.8.0`**. SupportExport remains **`upr-support-export/v1`**. M12 masters remain **default-off**.  
**Date:** 2026-08-31  
**Runtime SemVer:** unchanged **`0.8.0`** (no bump, no Release, no ZIP, `v0.8.0` not moved).

Implementation-record merge: PR [#74](https://github.com/magpern/universal-product-reviews/pull/74) → `4ac164ca242106b29163a0f31f85f760789e7b09`.  
This document is the **acceptance** record against the frozen specification.

C20 promotion to Stable is a **separate** decision: [`c20-customer-edit-availability-promotion.md`](c20-customer-edit-availability-promotion.md). Guest-edit alternative: [`m14-guest-edit-reentry-decision.md`](m14-guest-edit-reentry-decision.md).

## Verdict

M14 delivered a **privacy-safe 7-day body+rating self-edit** for logged-in verified-purchaser authors and invited guests who already completed a review, through one UPR-owned token-free route, returning successful approve edits to moderation hold.

**No release was made.** This closure does **not** bump SemVer, move `v0.8.0`, create a GitHub Release or ZIP, enable AI, or authorise Calibration GO / production enablement.

## Authority chain

| Gate | Artefact |
|------|----------|
| Documentation freeze | [`m14-customer-seven-day-review-edits.md`](m14-customer-seven-day-review-edits.md); annotated tag `m14-customer-seven-day-review-edits-freeze` → **`c3f8b5d3cbf6810a6631ed2c55ae4580c1feec21`** (PR [#72](https://github.com/magpern/universal-product-reviews/pull/72)) |
| Prior command-surface baseline | [`m13-operator-ai-command-surface-closure.md`](m13-operator-ai-command-surface-closure.md) (PR [#71](https://github.com/magpern/universal-product-reviews/pull/71) / merge `2f81225…`) |
| M2 invitation boundary | [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md) — `find_active_by_raw` unchanged; completed-invite edit never clears `redeemed_at` |
| Current `main` tip at acceptance | **`73d2f9a0033f1dfe5233f24f41612c5a170236c7`** (PR [#76](https://github.com/magpern/universal-product-reviews/pull/76) — M10 O9′; does not amend M14 runtime) |

## Implementation merge

| Item | Reference |
|------|-----------|
| Feature PR | [#73](https://github.com/magpern/universal-product-reviews/pull/73) |
| Implementation head | `e72e505a3006508eee5e97ce87911b261dbaaaa8` |
| Merge commit | **`b9f4a9596f41a5236e5488adf0461d7f4bea8ea2`** |
| Freeze tag (unchanged) | `m14-customer-seven-day-review-edits-freeze` → `c3f8b5d3cbf6810a6631ed2c55ae4580c1feec21` |
| Implementation-record docs | PR [#74](https://github.com/magpern/universal-product-reviews/pull/74) → `4ac164ca242106b29163a0f31f85f760789e7b09` |

Corrective commits on #73 before merge: transactional `writing` checkpoint + honest audit flags (`bcdf472`); writing-mismatch recovery without status write (`e72e505`).

## Exact delivered scope

| WP | Delivered |
|----|-----------|
| **WP1** | `upr_review_edit_claims` (`claimed` / `writing` / `content_written`; prior fingerprints; stored `content_changed` / `rating_changed`); `source_op_id` UNIQUE; `UNIQUE(event_type, correlation_id)`; acquire never replaces in-flight `writing` or `content_written` |
| **WP2** | Eligibility E2/E3; `CompletedInviteLookup`; E7 body + rating-meta guards; REST/admin extras; customers never gain `edit_comment` |
| **WP3** | `/upr-review/edit/` rewrite before token catch-all; E29 one HMAC `SELECT` then dispatch; E30 parent-row `FOR UPDATE` serialised `edit_session` (cap 10/hour); M7-class a11y form |
| **WP4** | Durable `writing` checkpoint; transactional body + rating + `content_written` CAS; `ApproveToHoldCas` + E33 lease; AI claim-clear before skip; insert-or-detect skip/audit |
| **WP5** | Per-step E33 crash tests; write-unit crash after body / rating / before CAS; operator-spam interleave; E30 concurrency; C20 display helper; SupportExport golden unchanged |

## Acceptance (frozen specification)

| Freeze requirement | Verdict | Evidence |
|--------------------|---------|----------|
| Completed-invite bearer can enter the seven-day edit flow | **Pass** | `test_completed_invite_secret_edits_only_and_cannot_resubmit`; `test_token_exchange_completed_secret_303_to_edit` |
| No M2 invite reactivation, guest submit arm, or second review | **Pass** | same; `find_active_by_raw(..., 'invite')` null; `GuestSubmitAuthorization` unarmed; review count stays 1 |
| Only body and star rating may change | **Pass** | `test_payload_allowlist_body_and_rating_only`; `test_identity_stripping_without_changing_author_fields`; `test_guest_edit_post_hold_and_identity_unchanged` |
| Window is exactly `7 × 86400` seconds from `comment_date_gmt` (inclusive) | **Pass** | `CustomerEditClock::WINDOW_SECONDS`; `test_clock_inclusive_expiry` |
| Approved edit returns to hold | **Pass** | `test_guest_edit_post_hold_and_identity_unchanged`; `test_logged_in_edit_and_c20` |
| Audit flags represent body/rating changes | **Pass** | `test_body_only_edit_audit_flags`; `test_rating_only_edit_audit_flags`; `test_finaliser_uses_stored_change_flags` |
| Crash recovery is transactional; mismatch recovery never changes status | **Pass** | `test_crash_after_body_write_rolls_back_and_is_not_unwritten` (and rating / pre-CAS); `test_writing_mismatch_abandons_without_status_write`; `test_fingerprint_mismatch_abandons_on_reconcile` |
| Reissue is bounded and serialised | **Pass** | `test_e30_ten_per_hour_serialized_reissue`; `test_e30_concurrent_from_zero_at_most_one_active` |
| Reconciliation is exactly-once | **Pass** | `test_crash_after_each_e33_step_is_exactly_once` |
| No customer data enters SupportExport | **Pass** | `test_support_export_schema_unchanged`; `test_support_export_contract_unchanged` |

No browser-specific M14 acceptance gap was found; browser suites were **not** run.

## Guarantees (as shipped)

1. **Guest edit** is a scoped completed-invite HMAC lookup (`redeemed_at IS NOT NULL`, `revoked_at IS NULL`, matching product/item/`review_comment_id`). It never uses `find_active_by_raw`, never clears `redeemed_at`, never mints `form_session`, and never arms `GuestSubmitAuthorization`.
2. **E29** is one HMAC `SELECT` by `token_hash` + `purpose='invite'`, then dispatch. Denial copy is generic.
3. **E30** serialises mint per parent: `SELECT … FOR UPDATE` → re-check E3 → rolling-hour count including revoked → deny without revoke at cap 10 → else revoke only `edit_session` children, insert one, `COMMIT`, then cookie.
4. **Approve→hold** is only via `ApproveToHoldCas` under `SystemStatusOrigin`. Operator spam/trash always wins; hold is never restored over spam/trash.
5. **Write unit:** `writing` checkpoint is committed **before** mutation. Body, rating, and `content_written` CAS are one InnoDB transaction; rollback leaves no persisted edit. Recovery never classifies `writing` as safely unwritten.
6. **Fingerprint mismatch** on `writing` (live is neither target nor prior) **abandons the generation only** — no comment-status write, hooks, skip, or `review.customer_edited`.
7. **Audit flags** `content_changed` / `rating_changed` are computed before write, stored on the claim, and copied into `review.customer_edited` (never hardcoded true).
8. **Support export** remains **`upr-support-export/v1`**. **No** new public `upr_*` filter. **No** new Action Scheduler hook name.

## CI evidence (implementation head `e72e505`)

| Check | Result | URL |
|-------|--------|-----|
| M1 lint and policy | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33366142682/job/99407078491 |
| Unit PHP 8.1 | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33366142682/job/99407078688 |
| Unit PHP 8.4 | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33366142682/job/99407078879 |
| Integration DEV (PHP 8.4 / WC 11.0.1) | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33366142682/job/99407078749 |
| Integration floor (non-blocking) | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33366142682/job/99407078591 |
| Workflow run | | https://github.com/magpern/universal-product-reviews/actions/runs/33366142682 |

Local corroboration (pre-merge): unit 8.1/8.4; `scripts/ci/check.sh`; focused integration (`M14CustomerEdit\|M2GuestAuthContext\|M2SubmitClaim`).

Post-merge CI on merge commit `b9f4a95…`: https://github.com/magpern/universal-product-reviews/actions/runs/33367481927 (all jobs success).

Acceptance re-run on `main` @ `73d2f9a…` plus this remaining-dev PR (isolated PHPUnit; no DEV/production WordPress): M14 unit 16/16; M14 integration 28/28; full unit 240/240 (PHP 8.1); `scripts/ci/check.sh`.

## Rollback posture

M14 is schema-bearing (`upr_db_version` `20260831b`, `upr_review_edit_claims`). Rollback is **deactivate / previous installable ZIP**, not a documentation revert. No installable release was produced in this milestone, so there is no new ZIP to roll back from. Freeze tag `m14-customer-seven-day-review-edits-freeze` is unchanged.

## Explicit non-actions (PR #73, #74, and this acceptance)

- No customer / production review data used or committed
- No DEV or production WordPress access; no credentials; no email send
- No SemVer bump, version tag movement, GitHub Release, ZIP, or deployment — **no release was made**
- No Calibration GO; no auto-spam master enablement; no external-AI enablement
- No SupportExport runtime / schema v2 change
- No C16/C17; no new public `upr_*` filters; no new AS hook
- No revision-body store; no customer deletion; no second customer-visible edit secret
- M14 implementation (#73/#74) did **not** promote C20; promotion is the companion amendment in this remaining-dev cycle

## Next

1. **No next numbered product-development freeze is open.** Optional unfrozen candidates (C11/C16–C17; WC importer) still need their own **plan → documentation freeze → implementation** — **not** authorised here.
2. **Operational only** (none complete): see [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md).

## Related

- [`m14-customer-seven-day-review-edits.md`](m14-customer-seven-day-review-edits.md)
- [`c20-customer-edit-availability-promotion.md`](c20-customer-edit-availability-promotion.md)
- [`m14-guest-edit-reentry-decision.md`](m14-guest-edit-reentry-decision.md)
- [`m13-operator-ai-command-surface-closure.md`](m13-operator-ai-command-surface-closure.md)
- [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)
- [`../runbooks/moderation-capabilities.md`](../runbooks/moderation-capabilities.md)
- [`../runbooks/reconciliation.md`](../runbooks/reconciliation.md)
- [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md)
