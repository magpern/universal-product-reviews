# M15 — Operator AI Moderation Queue closure

**Status:** Closed as **operator AI moderation queue implementation record**. Runtime remains **`0.8.0`** at this closure. SupportExport remains **`upr-support-export/v1`**. M12 masters and external AI remain **default-off**. Production automatic moderation remains **prohibited**.  
**Date:** 2026-08-31  
**Runtime SemVer at closure:** unchanged **`0.8.0`** (this docs PR does not bump SemVer, move `v0.8.0`, create a GitHub Release, ZIP, or final `v0.9.0`).

## Verdict

M15 delivered an **enhanced native Comments held queue** (`edit-comments.php?upr_view=pending`) with a privacy-safe derive-at-read assessment presenter, relabeled native Publish / Mark as spam / Move to trash, and a **non-mutating Keep on hold** UPR `admin-post` that emits **`review.operator_deferred` only**.

AI never mutates comment status. There is **no** parallel queue, SPA, or companion `review.operator_queue_decision` event.

**No release was made in this closure.** Any DEV test-candidate SemVer / annotated RC tag is a **separately authorised** follow-up and is **not** part of this record.

## Authority chain

| Gate | Artefact |
|------|----------|
| Documentation freeze | [`m15-operator-ai-moderation-queue.md`](m15-operator-ai-moderation-queue.md); annotated tag `m15-operator-ai-moderation-queue-freeze` → tag object **`b25a7a723a52ce6779c89a83f567ce7db9475ea8`**, peeled **`a1e083fc17597fe629fc0ec04764086971217c68`** (PR [#78](https://github.com/magpern/universal-product-reviews/pull/78)) |
| Prior acceptance baseline | [`m14-customer-seven-day-review-edits-closure.md`](m14-customer-seven-day-review-edits-closure.md) / C20 promotion (PR [#77](https://github.com/magpern/universal-product-reviews/pull/77)) |
| ADR | [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md) |
| Current `main` tip at implementation merge | **`6a09ce4bf24d96f9bb8b773db6f5205d9a2b538d`** (PR [#79](https://github.com/magpern/universal-product-reviews/pull/79)) |

## Implementation merge

| Item | Reference |
|------|-----------|
| Feature PR | [#79](https://github.com/magpern/universal-product-reviews/pull/79) |
| Implementation head (approved) | `7f3b7c6143acc2a4c5d35ac00d94774038714e1a` |
| Merge commit | **`6a09ce4bf24d96f9bb8b773db6f5205d9a2b538d`** |
| Freeze tag (unchanged) | `m15-operator-ai-moderation-queue-freeze` → peeled `a1e083fc17597fe629fc0ec04764086971217c68` |

Corrective commit on #79 before merge: Keep on hold outside row-action spans (`7f3b7c6`) — phrasing-content submit button + admin-footer POST form (valid inside core `<span>` wrappers; POST-only; nonce-protected).

## Exact delivered scope

| WP | Delivered |
|----|-----------|
| **WP1** | `QueueAssessmentPresenter` derive-at-read DTOs + escaped `<dl>` inside existing `upr_ai` on pending+held only; presenter-only “Likely acceptable (advisory — human must publish)” for `likely_publishable`; `RecommendationPolicy::action_label()` **unchanged** |
| **WP2** | Native Publish / Mark as spam / Move to trash **relabel-only** on pending view; Keep on hold = sole UPR `admin-post` (no status write; `stale_status` if non-held); plugin-row “Product reviews” → `upr_view=pending`; Overview held count for `manage_woocommerce` with deep link only when also `moderate_comments` |
| **WP3** | Audit **`review.operator_deferred` only** via `AuditLogger`, `actor_type=moderator`; payload allowlist per freeze Q5a (no `assessment_id` / `policy_version` / text / reason codes); request-local dedupe; M5-style order IDs on audit **row** only |
| **WP4** | Unit + integration matrix including native row-actions lifecycle (core-like + `WP_Comments_List_Table` wrap; footer form nonce + `comment_id`; visible keyboard label; no status mutation + deferred audit) |

## Guarantees (as shipped)

1. **Native queue only** — enhanced Comments pending view; no parallel dashboard/SPA/datastore.
2. **Capability split** — Overview count: `manage_woocommerce`; pending deep link and Keep on hold: `moderate_comments` (+ nonce + in-scope held review).
3. **Native action ownership** — Publish / Spam / Trash remain core actions (relabel-only); UPR does not harden their caps/nonces/races.
4. **Keep on hold** — POST `admin-post` only; zero `wp_set_comment_status` / spam / trash APIs; valid phrasing button in row actions; associated POST form in `admin_footer`.
5. **Presenter-only wording** — queue “Likely acceptable…” mapping lives only in `QueueAssessmentPresenter`; M11/CLI/`action_label()` strings unchanged; never “approved”/“approve” for AI output.
6. **Audit** — only `review.operator_deferred` for Keep on hold; native transitions remain `review.status_changed`; no companion queue-decision event.
7. **Support export** remains **`upr-support-export/v1`**.
8. **M12 masters / external AI remain default-off**; no AI status mutation APIs under `src/Ai/`.

## CI evidence (implementation head `7f3b7c6`)

| Check | Result | URL |
|-------|--------|-----|
| M1 lint and policy | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33379122250/job/99447195520 |
| Unit PHP 8.1 | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33379122250/job/99447195728 |
| Unit PHP 8.4 | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33379122250/job/99447195675 |
| Integration DEV (PHP 8.4 / WC 11.0.1) | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33379122250/job/99447195752 |
| Integration floor (non-blocking) | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33379122250/job/99447195651 |
| Workflow run | | https://github.com/magpern/universal-product-reviews/actions/runs/33379122250 |

Post-merge CI on merge commit `6a09ce4…`: https://github.com/magpern/universal-product-reviews/actions/runs/33379727942 (all jobs success).

| Check | Result | URL |
|-------|--------|-----|
| M1 lint and policy | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33379727942/job/99449106818 |
| Unit PHP 8.1 | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33379727942/job/99449106825 |
| Unit PHP 8.4 | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33379727942/job/99449106924 |
| Integration DEV (PHP 8.4 / WC 11.0.1) | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33379727942/job/99449106803 |
| Integration floor (non-blocking) | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33379727942/job/99449106731 |

## Explicit non-actions (PR #79 and this closure)

- No customer / production review data used or committed
- No DEV or production WordPress access; no credentials; no email send
- No SemVer bump, version tag movement, GitHub Release, ZIP, or deployment in this closure
- No Calibration GO; no auto-spam master enablement; no external-AI enablement
- No SupportExport runtime / schema v2 change
- No AI status mutation; no `review.operator_queue_decision`
- No global change to `RecommendationPolicy::action_label()`
- No parallel queue / SPA / new public `upr_*` filter / new AS hook

## Next

1. **No next numbered product-development freeze is open.** Optional unfrozen candidates (C11/C16–C17; WC importer) still need their own **plan → documentation freeze → implementation** — **not** authorised here.
2. **Operational only** (none complete): see [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md).
3. Separately governed: DEV test-candidate release metadata / annotated RC tag (not this closure); Calibration GO; production enablement.

## Related

- [`m15-operator-ai-moderation-queue.md`](m15-operator-ai-moderation-queue.md)
- [`m14-customer-seven-day-review-edits-closure.md`](m14-customer-seven-day-review-edits-closure.md)
- [`m13-operator-ai-command-surface-closure.md`](m13-operator-ai-command-surface-closure.md)
- [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)
- [`../runbooks/moderation.md`](../runbooks/moderation.md)
- [`../runbooks/moderation-capabilities.md`](../runbooks/moderation-capabilities.md)
- [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md)
- [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md)
