# M9 — Local AI Shadow Mode — closure

**Verdict:** PASS — M9 documentation freeze and implementation completed. No GitHub Release, ZIP, plugin SemVer / `v0.8.0` tag, DEV/production WordPress access, deployment, email, external provider call, host adapter, provider filter, automatic moderation action, or support-export schema change was performed as part of this closure.

## Artifacts

| Item | Reference |
|------|-----------|
| Freeze plan | [`docs/roadmap/m9-local-ai-shadow-mode.md`](m9-local-ai-shadow-mode.md) |
| Boundary ADR | [`docs/decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md) |
| Freeze PR | [#38](https://github.com/magpern/universal-product-reviews/pull/38) |
| Freeze merge commit | `a3f879321041524831a16bac22be8562223c8d0b` |
| Freeze PR CI | [run 33211960492](https://github.com/magpern/universal-product-reviews/actions/runs/33211960492) — **success** on head `0fddba3` |
| Freeze tag | `m9-local-ai-shadow-mode-freeze` (annotated object `6a6a9942c424ae0a02c3495c115cb130035a90d3`) → peeled **`a3f8793`** |

## Implementation PRs

| WP | PR | Merge commit | PR CI |
|----|----|--------------|-------|
| WP1 schema | [#39](https://github.com/magpern/universal-product-reviews/pull/39) | `0364700c4be7858a8d85ac4dbd09ef63e541bcc1` | [33212457970](https://github.com/magpern/universal-product-reviews/actions/runs/33212457970) success on `38f4d76` |
| WP2 built-in assessor | [#40](https://github.com/magpern/universal-product-reviews/pull/40) | `3bbe401f8b27100fb9cf6cd56543eabd4adce6e3` | [33212585895](https://github.com/magpern/universal-product-reviews/actions/runs/33212585895) success on `57df9c6` |
| WP3–WP6 runtime | [#41](https://github.com/magpern/universal-product-reviews/pull/41) | `7e6d90407936a1299328890179b529688069dbb5` | [33213033541](https://github.com/magpern/universal-product-reviews/actions/runs/33213033541) success on `519a4df` |

Post-merge CI on runtime merge: [run 33213441841](https://github.com/magpern/universal-product-reviews/actions/runs/33213441841) on `7e6d904` — **success**.

## Tag target proof

```text
m9-local-ai-shadow-mode-freeze (annotated) → a3f879321041524831a16bac22be8562223c8d0b
```

The peeled commit is the **documentation-freeze merge** of PR #38 (not an implementation or version-metadata merge).

## Privacy and local-only enforcement

- Built-in-only assessor under `src/Ai/`; **no** `upr_local_moderation_assessment_provider` / replaceable filter
- CI forbids network primitives (`wp_remote_*`, `wp_safe_remote_*`, `curl_*`, sockets) in `src/Ai/` and forbids provider-filter registration in `src/`
- Fingerprint: plain SHA-256 of canonical non-secret inputs (salt-stable)
- Audit payloads allowlisted only (no score/reason codes/body/PII)
- `upr-support-export/v1` and `upr-public-contracts/v1` **unchanged** (no C18)

## Disabled-state evidence

Locked and tested:

- Shadow off → Point A silent (no job/row/audit)
- Disable after claim / during assess → clear claim; no row; no AI audit; never `provider_unavailable` for disable
- Completion re-checks token + held eligibility + **enabled**
- Interleave: claim active → disable → approve/spam/trash → no new row, no AI audit, claim cleared (`M9ShadowLifecycleIntegrationTest`)

## Confirmed non-actions

- **No** `v0.8.0` / plugin version metadata change (runtime remains **0.6.0**)
- **No** GitHub Release or ZIP
- **No** DEV/production WordPress access, install/activate/enable of shadow mode, or deployment
- **No** external provider calls; **no** host adapter / provider filter
- **No** automatic approve / reject / spam from AI
- **M10 / M11 remain unimplemented** and require separate freeze / ADR amendment

## Delivered (runtime)

- Schema `20260828a`: `upr_moderation_assessments`, `_assessment_claims`, `_moderation_ops`
- AS `upr_assess_review` + purge; claim-before-rate; 60s claim; 15s cooperative deadline
- Controls default-off; Comments `upr_ai` advisory + held re-analysis; diagnostics D12–D15 + Site Health
- Allowlisted AI audit events

## Next step

Separately authorise **M9 release metadata / tagging** (proposed `v0.8.0`, after any authorised M7 `v0.7.0`) only after reviewing this implementation closure. Do **not** treat this closure as Release/ZIP authorisation.
