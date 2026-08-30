# M12 — Simulation-GO implementation closure

**Status:** Closed as **Simulation-GO implementation record**. Runtime contract `auto_spam_held_technical` is present on `main` with masters **default off**. Production automatic moderation remains **prohibited**.  
**Date:** 2026-08-30  
**Runtime SemVer:** unchanged **`0.8.0`** (no bump, no Release, no ZIP, `v0.8.0` not moved).

## Verdict

Synthetic evidence authorised **implementation** and **controlled non-production testing only**.

It does **not** establish real-world precision or false-positive rates.

**Production automatic moderation remains prohibited** pending both:

1. real-world human-labelled **Calibration GO**; and  
2. a **separate production-enable approval**.

## Authority chain

| Gate | Artefact |
|------|----------|
| Documentation freeze | [`m12-guarded-auto-spam.md`](m12-guarded-auto-spam.md); tag `m12-guarded-auto-spam-freeze` → `79d9a94a5c82489e988e6543050c3b6b5182ef0c` (PR [#62](https://github.com/magpern/universal-product-reviews/pull/62)) |
| Simulation / Calibration amendment | Two-gate model in freeze + ADR-0004; harness/kit PRs [#63](https://github.com/magpern/universal-product-reviews/pull/63)–[#66](https://github.com/magpern/universal-product-reviews/pull/66) |
| Prior freeze-era closure | [`m12-guarded-auto-spam-closure.md`](m12-guarded-auto-spam-closure.md) (production NO-GO; pre-implementation) |
| Calibration posture | [`m12-calibration-nogo.md`](m12-calibration-nogo.md) — **Calibration GO still not issued** |
| ADR | [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md) |

## Synthetic corpus and harness

| Item | Value |
|------|--------|
| Corpus | `scripts/calibration/fixtures/m12-sim-v1.synthetic.json` |
| Evidence status | `synthetic_simulation` (privacy-safe; no customer review bodies / PII) |
| Generator | `scripts/calibration/bin/generate-simulation-corpus.php` |
| Command | `php scripts/calibration/evaluate.php scripts/calibration/fixtures/m12-sim-v1.synthetic.json` |
| Verdict | **`SIMULATION GO — implementation and non-production testing only`** |
| Production flag | Harness keeps `production_enablement_authorised: false` |

## Implementation merge

| Item | Reference |
|------|-----------|
| Feature PR | [#67](https://github.com/magpern/universal-product-reviews/pull/67) |
| Implementation head | `47a4c138b53a0c685f84dfa27abf6871bdcb4f3c` |
| Merge commit | `81fc6eabb70ea9a8155bdedcea2d44302e8a438b` |
| Contract | **`auto_spam_held_technical`** only (`hold` → `spam`) |
| Schema | DB version `20260830a`; additive `{prefix}upr_moderation_action_ledger` |

### Guarded design (as shipped)

- **Masters default off:** auto-spam master, policy master, Simulation-only environment guard, dry-run, kill switch — fail-closed when absent.
- **Strict scheduling boundary:** off→on master enable refreshes `upr_ai_auto_action_boundary_at`; live action requires `completed_at` **strictly greater than** boundary (equality abstains; missing boundary fails closed). No historical backfill.
- **Eligibility:** held top-level in-scope product review; completed assessment; calibrated Simulation tuple match; recommendation exactly `likely_spam` (risk ≥ 80, technical-spam allowlist, ¬mandatory-human); all masters + Simulation guard; kill off; not dry-run for live CAS.
- **CAS / ledger / lease:** durable key `comment_id + assessment_id + action_policy_version`; leased `processing` (TTL 60s); atomic compare-and-set `hold`→`spam` with ledger `cas_succeeded` in one DB transaction; happy-path public hook parity once under `AiActionOrigin` (`review.ai_auto_spam`).
- **Reversal / idempotency:** restore to hold does not re-act on the same assessment key (`acted` / `observed` / `abstained` / `unknown_after_crash` / `cas_succeeded` block).
- **Disable:** immediately prevents new action; clears active processing leases without inventing false terminal action/audit rows.
- **Crash after CAS:** never replay CAS or public WP transition hooks; terminalise `unknown_after_crash`; critical D20 / Site Health for manual reconciliation — do not infer normal completion from current spam status alone.
- **Explicitly never automatic:** approve, trash, delete, edit, email/reply, scoring-only action, sentiment/rating/criticism outcomes, or new provider calls at the action step.

## CI and local evidence (implementation head `47a4c13`)

| Check | Result | URL |
|-------|--------|-----|
| M1 lint and policy | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33318884884/job/99277212958 |
| Unit PHP 8.1 | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33318884884/job/99277212914 |
| Unit PHP 8.4 | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33318884884/job/99277212988 |
| Integration DEV (PHP 8.4 / WC 11.0.1) | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33318884884/job/99277212822 |
| Integration floor (non-blocking) | pass | https://github.com/magpern/universal-product-reviews/actions/runs/33318884884/job/99277212953 |
| Workflow run | | https://github.com/magpern/universal-product-reviews/actions/runs/33318884884 |

Local corroboration (pre-merge): unit suite OK; `scripts/ci/check.sh` OK; integration `--filter M12AutoSpam` OK; offline Simulation GO harness as above.

## Explicit non-actions (this closure and PR #67)

- No customer / production review data used or committed
- No provider credentials configured; no DEV or production WordPress access
- No email send; no auto-spam master enablement in DEV/prod
- No SemVer bump, version tag movement, GitHub Release, ZIP, or deployment
- No Calibration GO; no production enablement GO
- No claim that synthetic metrics equal real-world precision or false-positive performance

## Next explicit gates

1. Authorised real-world `authorised_labelled` corpus → **Calibration GO**.  
2. Separate **production-enable approval** (masters remain off until that GO).  
3. Optional: controlled DEV/pre-prod synthetic testing under Simulation GO (still not production).

## Related

- [`m12-guarded-auto-spam.md`](m12-guarded-auto-spam.md)
- [`m12-guarded-auto-spam-closure.md`](m12-guarded-auto-spam-closure.md)
- [`m12-calibration-nogo.md`](m12-calibration-nogo.md)
- [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)
