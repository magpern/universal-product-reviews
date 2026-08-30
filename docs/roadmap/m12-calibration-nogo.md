# M12 calibration / simulation gates — current posture

**Status:** **Calibration GO not issued.** Production automatic action remains prohibited. **Simulation GO not yet issued** in this repository (no committed synthetic_simulation corpus). Automatic action runtime remains **unimplemented**.  
**Date:** 2026-08-30 (amended: two-gate Simulation / Calibration model)  
**Baseline freeze:** `m12-guarded-auto-spam-freeze` → `79d9a94a5c82489e988e6543050c3b6b5182ef0c` (PR [#62](https://github.com/magpern/universal-product-reviews/pull/62)).

## Two-gate model (exact verdicts)

| Verdict | Meaning |
|---------|---------|
| `SIMULATION GO — implementation and non-production testing only` | Privacy-safe **synthetic / AI-generated** corpus (`evidence_status: synthetic_simulation`) passed offline gates. May authorise implementation with masters **default-off** and DEV/pre-prod testing with controlled synthetic fixtures. **Must not** authorise production enablement, production customer-review action, or a claim of real-world precision/false-positive performance. |
| `CALIBRATION GO — production enablement decision may be considered` | Authorised **real-world** human-labelled privacy-safe corpus (`evidence_status: authorised_labelled`) passed frozen metrics. Required before any **production** automatic-action enablement may be considered. Does **not** itself enable production. |
| `NO-GO — automatic action deferred` | Empty, incomplete, template/example/bare-synthetic, contaminated, or threshold-failing evidence. |

No harness verdict sets `production_enablement_authorised`. Production automatic moderation remains forbidden without Calibration GO **and** a separate production enablement GO.

## Finding (current)

No Simulation GO corpus and no Calibration GO corpus are present in Git. Templates/examples evaluate to **NO-GO**.

## Evidence workflows

### Simulation (synthetic / AI-generated)

1. Use [`../../scripts/calibration/evidence-kit/`](../../scripts/calibration/evidence-kit/) with `evidence_status: synthetic_simulation` and `provenance.source_class: synthetic_authorised`.
2. Privacy-safe opaque ids + labels + assessment maps only — no customer PII/bodies in Git.
3. Evaluate: `php scripts/calibration/evaluate.php <file.json>` → exit **10** on Simulation GO.

### Calibration (real-world)

1. Maintainer + legal/privacy authorisation; `evidence_status: authorised_labelled`; non-synthetic `source_class`.
2. Same privacy rules; frozen size/holdout/Wilson/precision gates.
3. Evaluate → exit **0** on Calibration GO only.

## Harness

Offline tooling under `scripts/calibration/` enforces both gates. Unit tests: `tests/unit/M12CalibrationHarnessUnitTest.php`.

## Explicit non-actions (this document)

- No runtime auto-spam implementation in this amendment alone
- No SemVer / Release / ZIP / production enablement

## Next gates

1. Optional: supply synthetic_simulation corpus → **Simulation GO** → Implementation GO (masters off) → DEV/pre-prod synthetic testing.  
2. Mandatory before production: authorised real-world corpus → **Calibration GO** → separate production enablement GO.

## Verdict (repository posture)

**NO-GO — automatic action deferred** (neither Simulation GO nor Calibration GO issued on `main` evidence).
