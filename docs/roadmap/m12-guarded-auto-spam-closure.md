# M12 — Guarded auto-spam closure

**Status:** Closed as **NO-GO — AUTOMATIC ACTION DEFERRED**.  
**Date:** 2026-08-30  
**Runtime SemVer:** unchanged **`0.8.0`** (no bump, no Release, no ZIP, `v0.8.0` not moved).

## Verdict

**NO-GO — AUTOMATIC ACTION DEFERRED**

Calibration GO was not objectively satisfied. Automatic action (`auto_spam_held_technical`) remains **unimplemented**. Masters were never added or enabled.

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
| Evidence kit (preparation) | `scripts/calibration/evidence-kit/` — templates + procedure; **no** customer corpus; M12 remains NO-GO |
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

## Implementation status

**Not started** (correct under freeze: Implementation GO requires Calibration GO).

## Explicit non-actions

- No SemVer / version metadata PR / version tag / GitHub Release / ZIP
- No DEV/prod deployment, credentials, provider configuration, external-AI enablement, email
- No host-specific code; `v0.8.0` unchanged
- No dry-run or live auto-action enablement
- No public-hook-replay design; crash rule remains as frozen for any future implementation
- No tags beyond `m12-guarded-auto-spam-freeze`

## Next explicit gate

1. Authorised privacy-safe labelled evidence (`m12-cal-v1`) meeting frozen thresholds for a concrete calibrated tuple.  
2. **Calibration GO** via harness exit 0.  
3. Only then: Implementation GO (masters default off) → Dry-run GO → DEV enablement GO → (later) production enablement GO.

## Related

- [`m12-guarded-auto-spam.md`](m12-guarded-auto-spam.md)
- [`m12-calibration-nogo.md`](m12-calibration-nogo.md)
- [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)
