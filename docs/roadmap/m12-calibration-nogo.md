# M12 calibration — NO-GO / automatic action deferred

**Status:** **Calibration NO-GO.** Automatic action remains **unimplemented**.  
**Date:** 2026-08-30 (updated with evidence kit)  
**Baseline:** `main` @ freeze tag `m12-guarded-auto-spam-freeze` → `79d9a94a5c82489e988e6543050c3b6b5182ef0c` (PR [#62](https://github.com/magpern/universal-product-reviews/pull/62)).

## Finding

A compliant labelled calibration corpus meeting the frozen gates in [`m12-guarded-auto-spam.md`](m12-guarded-auto-spam.md) §5 is **not available** in this repository. Fabricating labels is forbidden. Unauthorised WordPress / DEV / prod review access is out of scope.

Therefore **Calibration GO is not satisfied**. Automatic action must not be implemented.

## Evidence-collection workflow (preparation)

Authorised humans may later supply privacy-safe evidence using the kit:

1. Read [`../../scripts/calibration/evidence-kit/README.md`](../../scripts/calibration/evidence-kit/README.md) and [`../../scripts/calibration/evidence-kit/taxonomy.md`](../../scripts/calibration/evidence-kit/taxonomy.md).
2. Obtain maintainer + legal/privacy authorisation (see kit “Authority required”).
3. Lock holdout assignments and hashes **before** final labelling / tuning.
4. Export **only** opaque ids + labels + assessment field maps into `m12-cal-v1` (`evidence_status: authorised_labelled`).
5. Evaluate offline: `php scripts/calibration/evaluate.php <evidence.json>`.

Templates and examples **must** evaluate to **NO-GO**. Incomplete, synthetic, or undocumented evidence **cannot** become Calibration GO.

## Harness delivered (safe)

Offline tooling under `scripts/calibration/`:

- Privacy-safe schema `m12-cal-v1` + structural parser (provenance, hashes, holdout lock, privacy, non-placeholder tuple)
- Deterministic would-act evaluator (M11 `RecommendationPolicy` conjunction)
- Wilson 95% UCB + precision / size / holdout / double-label gates
- Evidence kit templates (empty / example-safe only)
- Unit tests: `tests/unit/M12CalibrationHarnessUnitTest.php`

No providers, no comment status changes, no DEV/prod access, no credentials, no customer corpus in Git.

## Explicit non-actions

- No `ActionPolicy` runtime, CAS mutator, ledger, Controls masters, or enablement
- No SemVer / Release / ZIP / `v*` tag / movement of `v0.8.0`
- No dry-run or live auto-action in any environment

## Next explicit gate

After an **authorised** privacy-safe `m12-cal-v1` corpus passes the offline harness (**Calibration GO**), Implementation GO may be considered. Until then M12 remains deferred.

## Verdict

**NO-GO — AUTOMATIC ACTION DEFERRED**
