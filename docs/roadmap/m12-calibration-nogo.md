# M12 calibration / simulation gates — current posture

**Status:** **Simulation GO issued** (synthetic corpus on `main`). **Simulation-GO implementation merged** (masters default off). **Calibration GO not issued.** Production automatic action remains prohibited.  
**Date:** 2026-08-30 (amended: Simulation GO + implementation closure)  
**Baseline freeze:** `m12-guarded-auto-spam-freeze` → `79d9a94a5c82489e988e6543050c3b6b5182ef0c` (PR [#62](https://github.com/magpern/universal-product-reviews/pull/62)).

## Two-gate model (exact verdicts)

| Verdict | Meaning |
|---------|---------|
| `SIMULATION GO — implementation and non-production testing only` | Privacy-safe **synthetic / AI-generated** corpus (`evidence_status: synthetic_simulation`) passed offline gates. May authorise implementation with masters **default-off** and DEV/pre-prod testing with controlled synthetic fixtures. **Must not** authorise production enablement, production customer-review action, or a claim of real-world precision/false-positive performance. |
| `CALIBRATION GO — production enablement decision may be considered` | Authorised **real-world** human-labelled privacy-safe corpus (`evidence_status: authorised_labelled`) passed frozen metrics. Required before any **production** automatic-action enablement may be considered. Does **not** itself enable production. |
| `NO-GO — automatic action deferred` | Empty, incomplete, template/example/bare-synthetic, contaminated, or threshold-failing evidence. |

No harness verdict sets `production_enablement_authorised`. Production automatic moderation remains forbidden without Calibration GO **and** a separate production enablement GO.

## Finding (current)

| Gate | Posture |
|------|---------|
| Simulation corpus | Committed: `scripts/calibration/fixtures/m12-sim-v1.synthetic.json` |
| Simulation harness verdict | `SIMULATION GO — implementation and non-production testing only` |
| Runtime `auto_spam_held_technical` | Implemented on `main` (PR [#67](https://github.com/magpern/universal-product-reviews/pull/67)); masters **default off** |
| Calibration corpus | **Not available** |
| Calibration GO | **Not issued** |

Canonical implementation record: [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md).

## Evidence workflows

### Simulation (synthetic / AI-generated)

1. Use [`../../scripts/calibration/evidence-kit/`](../../scripts/calibration/evidence-kit/) with `evidence_status: synthetic_simulation` and `provenance.source_class: synthetic_authorised`.
2. Privacy-safe opaque ids + labels + assessment maps only — no customer PII/bodies in Git.
3. Evaluate: `php scripts/calibration/evaluate.php <file.json>` → exit **10** on Simulation GO.
4. Current checked-in fixture: `scripts/calibration/fixtures/m12-sim-v1.synthetic.json`.

### Calibration (real-world)

1. Maintainer + legal/privacy authorisation; `evidence_status: authorised_labelled`; non-synthetic `source_class`.
2. Same privacy rules; frozen size/holdout/Wilson/precision gates.
3. Evaluate → exit **0** on Calibration GO only.

## Harness

Offline tooling under `scripts/calibration/` enforces both gates. Unit tests: `tests/unit/M12CalibrationHarnessUnitTest.php`.

## Explicit non-actions (this document)

- No Calibration GO and no production enablement
- No SemVer / Release / ZIP from this posture note
- Synthetic metrics must not be treated as real-world precision/false-positive proof

## Next gates

1. Optional: controlled DEV/pre-prod synthetic testing under Simulation GO (masters still default off until separately authorised).  
2. Mandatory before production: authorised real-world corpus → **Calibration GO** → separate production enablement GO.

## Verdict (repository posture)

**Simulation GO + implementation present; production automatic moderation still blocked** (Calibration GO not issued).
