# M12 — Guarded auto-spam closure

**Status:** Closed as **NO-GO — automatic action deferred** for **production** at freeze/harness time. Superseded for Simulation-GO **implementation** by [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md). Runtime `auto_spam_held_technical` is now on `main` (PR #67) with masters default off; **Calibration GO** still not issued.  
**Date:** 2026-08-30 (freeze-era record; see implementation closure for post-#67 state)  
**Runtime SemVer:** unchanged **`0.8.0`** (no bump, no Release, no ZIP, `v0.8.0` not moved).

## Verdict

**NO-GO — automatic action deferred** *(production; freeze-era)*

At the time of this freeze-era closure, neither Simulation GO nor Calibration GO had been issued against committed evidence. Production automatic moderation remains prohibited without real-world **Calibration GO** plus a separate production enablement GO.

**Later:** Simulation GO + implementation were recorded in [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md). That record does **not** lift the production prohibition.

## Exact artefacts

| Item | Reference |
|------|-----------|
| Baseline before freeze | `origin/main` @ `df6521201a7b30f89efa3755e1fcca663f1900cd` |
| Documentation freeze PR | [#62](https://github.com/magpern/universal-product-reviews/pull/62) |
| Freeze merge commit | `79d9a94a5c82489e988e6543050c3b6b5182ef0c` |
| Freeze tag | `m12-guarded-auto-spam-freeze` (annotated; peels to `79d9a94…`) |
| Freeze CI | PR #62 — lint, unit 8.1/8.4, integration DEV + floor: **pass** |
| Calibration harness + NO-GO PR | [#63](https://github.com/magpern/universal-product-reviews/pull/63) |
| Calibration merge commit | `5a55e759bd04730af175ef29182c0f55c8a2150d` |
| Calibration CI | PR #63 — lint, unit 8.1/8.4, integration DEV + floor: **pass** |
| Evidence kit (preparation) | `scripts/calibration/evidence-kit/` — templates + procedure |
| Simulation implementation closure | [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md) |
| Authoritative freeze | [`m12-guarded-auto-spam.md`](m12-guarded-auto-spam.md) |
| ADR | [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md) (amended M12) |
| Calibration NO-GO | [`m12-calibration-nogo.md`](m12-calibration-nogo.md) |
| Offline harness | `scripts/calibration/` |

## Calibration status

| Gate | Result |
|------|--------|
| ≥400 legitimate-negative labelled | **Not available** |
| ≥200 technical-spam labelled | **Not available** |
| Holdout / Wilson / precision floors | **Not evaluable on real corpus** |
| Empty example fixture | Evaluates **NO-GO** (as designed) |
| Calibration GO | **Not issued** |
| Simulation GO | **Issued later** — see [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md) |

## Implementation status

**Freeze-era:** not started.  
**Current:** Simulation-GO implementation merged — see [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md). Production enablement still requires **Calibration GO** plus a separate production enablement GO.

## Explicit non-actions

- No SemVer / version metadata PR / version tag / GitHub Release / ZIP
- No DEV/prod deployment, credentials, provider configuration, external-AI enablement, email
- No host-specific code; `v0.8.0` unchanged
- No dry-run or live auto-action enablement
- No public-hook-replay design; crash rule remains as frozen for any future implementation
- No tags beyond `m12-guarded-auto-spam-freeze`

## Next explicit gate

1. Done later: synthetic corpus → Simulation GO → implementation (PR #67) — [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md).  
2. Mandatory before production: authorised real-world `authorised_labelled` corpus → **Calibration GO** → separate production enablement GO.

## Related

- [`m12-guarded-auto-spam.md`](m12-guarded-auto-spam.md)
- [`m12-calibration-nogo.md`](m12-calibration-nogo.md)
- [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md)
- [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)
