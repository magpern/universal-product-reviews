# M13 — Operator AI Moderation Command Surface closure

**Status:** Closed as **operator command-surface implementation record**. Runtime remains **`0.8.0`**. M12 masters remain **default-off**. Production automatic moderation remains **prohibited**.  
**Date:** 2026-08-30  
**Runtime SemVer:** unchanged **`0.8.0`** (no bump, no Release, no ZIP, `v0.8.0` not moved).

## Verdict

M13 delivered a **privacy-safe operator command surface** for AI moderation posture, zero-write would-act preview, held recommendation triage filters, CLI inspection, and D21 assessment-retention purge health.

It does **not** enable auto-spam masters, issue Calibration GO, authorise production automatic moderation, or change SupportExport / SemVer.

## Authority chain

| Gate | Artefact |
|------|----------|
| Documentation freeze | [`m13-operator-ai-command-surface.md`](m13-operator-ai-command-surface.md); annotated tag `m13-operator-ai-command-surface-freeze` → **`c82b176f08d932c3f413a7b1cd1eb712ca8aa67b`** (PR [#69](https://github.com/magpern/universal-product-reviews/pull/69)) |
| Prior Simulation-GO baseline | [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md) (PR [#68](https://github.com/magpern/universal-product-reviews/pull/68) / merge `d4513bb…`) |
| Calibration posture | [`m12-calibration-nogo.md`](m12-calibration-nogo.md) — **Calibration GO still not issued** |
| ADR | [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md) |

## Implementation merge

| Item | Reference |
|------|-----------|
| Feature PR | [#70](https://github.com/magpern/universal-product-reviews/pull/70) |
| Implementation head | `1f7e191d194e0789edd08085af2fd5f177833fc1` |
| Merge commit | **`4fbe6068fe6e6ef8a737ffdd19004ab4954490fc`** |
| Freeze tag (unchanged) | `m13-operator-ai-command-surface-freeze` → `c82b176f08d932c3f413a7b1cd1eb712ca8aa67b` |

## Exact delivered scope

| WP | Delivered |
|----|-----------|
| **WP1** | Overview AI posture (masters / boundary / ledger counts / `unknown_after_crash` / 24h assessment aggregates); diagnostics **D21** assessment-retention purge health; Diagnostics UI D1–D21 |
| **WP2** | `AssessmentRepository::latest_actionable_assessment_for_comment()` (+ resolve reasons); `ActionPolicy::content_eligible_for_auto_spam` / `policy_match_pre_boundary`; zero-write `WouldActReport` + `admin_post`; ActionWorker abstain for stale/superseded assessment IDs (no CAS); last-purge option stamped only after successful `purge_due` |
| **WP3** | `RecommendationPolicy::compile_held_filter_sql` (allowlisted prepared fragments); held-only Comments recommendation filters for all five `suggested_action` values; primary `edit-comments.php` list only; `EXISTS`; `cache_domain` per filter for comment-query cache correctness |
| **WP4** | `wp upr ai-status`, `wp upr would-act`, `wp upr ledger-summary` — each requires `--user=<id\|login>` then `manage_woocommerce` after `wp_set_current_user` |
| **WP5** | **No** `SupportExport` runtime change; schema remains **`upr-support-export/v1`**; golden/shape tests guard drift |
| **WP6** | Operator/retention runbook updates; AS inventory in runbooks only (not `public-contracts.md`); unit + integration coverage |

## Guarantees (as shipped)

1. **Would-act** is **zero-write** (no audit / option / ledger / CAS / schedule / comment writes) and **fails closed** (no partial-as-valid candidates).
2. **Latest assessment of any state** (`MAX(assessment_id)`) **supersedes** older action candidates — no fallback to an older completed row when the latest is failed / skipped / indeterminate / tuple-mismatched / policy-drifted.
3. **ActionWorker** abstains for stale/superseded assessment IDs (clear/release owned claim per M12 rules; **never CAS**).
4. **Comments filters** are **held-only**, **primary-list-only**, pagination-safe **`EXISTS`** predicates via the RecommendationPolicy SQL compiler (no PHP page post-filter; no N+1 / prefetch recursion).
5. **CLI** requires explicit **`--user`** and **`manage_woocommerce`** (no shell/root trust bypass).
6. **Support export** remains **`upr-support-export/v1`**.
7. **M12 masters remain default-off** (absent option = off).
8. **Production automatic moderation remains prohibited** pending real-world **Calibration GO** and a **separate production-enable approval**.

Dual metrics: `would_act_*` requires the strict enablement boundary; `policy_match_pre_boundary_*` is explicitly **non-actionable** and must never be labelled “would act.”

Selection split (intentional): recommendation filters use latest assessment **of any state** (M11 advisory); would-act / ActionWorker use `latest_actionable_*` only.

## CI evidence (implementation head `1f7e191`)

| Check | Result | URL |
|-------|--------|-----|
| M1 lint and policy | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33336288816/job/99323653596 |
| Unit PHP 8.1 | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33336288816/job/99323653553 |
| Unit PHP 8.4 | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33336288816/job/99323653502 |
| Integration DEV (PHP 8.4 / WC 11.0.1) | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33336288816/job/99323653610 |
| Integration floor (non-blocking) | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33336288816/job/99323653544 |
| Workflow run | | https://github.com/magpern/universal-product-reviews/actions/runs/33336288816 |

Local corroboration (pre-merge): unit suite OK; focused integration (`M4|M5|M11|M12|M13`) OK; `scripts/ci/check.sh` OK.

Post-merge CI on merge commit `4fbe606…`: https://github.com/magpern/universal-product-reviews/actions/runs/33337005290 (all jobs success).

## Explicit non-actions (this closure and PR #70)

- No customer / production review data used or committed
- No provider credentials configured; no DEV or production WordPress access
- No email send; no auto-spam master enablement
- No SemVer bump, version tag movement, GitHub Release, ZIP, or deployment
- No Calibration GO; no production enablement GO
- No SupportExport runtime / schema v2 change
- No new public `upr_*` filters; AS job names remain runbook/internal only

## Next

1. **Next unstarted product-development milestone:** **M14 — Customer 7-day review edits** (ARCHITECTURE §12; freeze candidate named in [`m13-operator-ai-command-surface.md`](m13-operator-ai-command-surface.md) § non-goals / forward). Requires its own **plan → documentation freeze → implementation** cycle — **not** authorised by this closure.  
2. Separately governed (not M14): real-world **Calibration GO** → then **production-enable approval** before any auto-spam master enablement.  
3. Optional deferred SemVer / Release for shipped M10–M13 surface (still no enablement by tagging alone).

## Related

- [`m13-operator-ai-command-surface.md`](m13-operator-ai-command-surface.md)
- [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md)
- [`m12-calibration-nogo.md`](m12-calibration-nogo.md)
- [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)
- [`../runbooks/operator-controls.md`](../runbooks/operator-controls.md)
- [`../runbooks/retention.md`](../runbooks/retention.md)
