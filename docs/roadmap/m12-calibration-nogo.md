# M12 calibration — NO-GO / automatic action deferred

**Status:** **Calibration NO-GO.** Automatic action remains **unimplemented**.  
**Date:** 2026-08-30  
**Baseline:** `main` @ freeze tag `m12-guarded-auto-spam-freeze` → `79d9a94a5c82489e988e6543050c3b6b5182ef0c` (PR [#62](https://github.com/magpern/universal-product-reviews/pull/62)).

## Finding

A compliant labelled calibration corpus meeting the frozen gates in [`m12-guarded-auto-spam.md`](m12-guarded-auto-spam.md) §5 is **not available** in this repository or as authorised privacy-safe evidence supplied to this initiative.

Obtaining such a corpus would require either:

1. Fabricating human labels (forbidden), or  
2. Accessing WordPress / DEV / prod review bodies or credentials (out of scope and forbidden for this phase).

Therefore **Calibration GO is not satisfied**. Per the freeze, automatic action must not be implemented.

## Harness delivered (safe)

Offline, read-only tooling under `scripts/calibration/`:

- Privacy-safe evidence schema `m12-cal-v1`
- Deterministic would-act evaluator (M11 `RecommendationPolicy` conjunction)
- Wilson 95% UCB + precision / size / holdout / double-label gates
- Example empty fixture that evaluates to **NO-GO**
- Unit tests: `tests/unit/M12CalibrationHarnessUnitTest.php`

No providers, no comment status changes, no DEV/prod access, no credentials.

## Explicit non-actions

- No `ActionPolicy` runtime, CAS mutator, ledger, Controls masters, or enablement
- No SemVer / Release / ZIP / `v*` tag / movement of `v0.8.0`
- No dry-run or live auto-action in any environment

## Next explicit gate

Supply a privacy-safe `m12-cal-v1` evidence document (≥400 legitimate-negative, ≥200 technical-spam, holdout ≥20% per stratum locked before tuning, ≥20% double-label, Wilson false-spam UCB ≤ 1.0%, technical-spam precision ≥ 95%, zero mandatory-human would-act) for the exact calibrated tuple. Re-run `php scripts/calibration/evaluate.php <evidence.json>`. Only a genuine **Calibration GO** unlocks Implementation GO.

## Verdict

**NO-GO — AUTOMATIC ACTION DEFERRED**
