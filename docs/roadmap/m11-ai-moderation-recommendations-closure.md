# M11 — AI Moderation Recommendations — closure

**Verdict:** PASS — M11 documentation freeze, recommendation-only implementation, and D19 diagnostics-test correction completed on `main`. Plugin SemVer remains **`0.8.0`**. No GitHub Release, ZIP, version tag, DEV/production WordPress access, deployment, email, real OpenAI customer-review traffic, credentials configuration, external-AI enablement, automatic moderation action, attention view/filter, or movement of **`v0.8.0`** was performed as part of this initiative.

M11 is **recommendation-only**. Automatic status mutation remains deferred to **M12** and is not authorised by this closure.

## Artifacts

| Item | Reference |
|------|-----------|
| Freeze plan | [`m11-ai-moderation-recommendations.md`](m11-ai-moderation-recommendations.md) |
| Boundary ADR | [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md) |
| Freeze PR | [#59](https://github.com/magpern/universal-product-reviews/pull/59) |
| Freeze merge commit | `b785784c9442b30b8788d01aa1107092a5eefebe` |
| Freeze PR CI | [run 33278962591](https://github.com/magpern/universal-product-reviews/actions/runs/33278962591) — **success** (head `6e9e367`) |
| Freeze push CI | [run 33279073712](https://github.com/magpern/universal-product-reviews/actions/runs/33279073712) — **success** |
| Freeze tag | `m11-ai-moderation-recommendations-freeze` (annotated object `e3a8fb659043ed8839d111f2701632d97898eeca`) → peeled **`b785784c9442b30b8788d01aa1107092a5eefebe`** |
| Baseline cited in freeze | `22918f19f89d350de765edd0641f8d2a0aaa5920` (PR [#58](https://github.com/magpern/universal-product-reviews/pull/58)); **`v0.8.0`** left untouched |

## Implementation PRs

| Deliverable | PR | Merge commit | Head commits | PR CI |
|-------------|----|--------------|--------------|-------|
| RecommendationPolicy, held-only Comments UX, display option, D19 diagnostics / Site Health, M11 tests | [#60](https://github.com/magpern/universal-product-reviews/pull/60) | `c86e289d66044f8bde2805ba1389377f555f7cde` | `40a124d` (feature) + `73455fb` (D19 diagnostics expectation correction) | [run 33279265552](https://github.com/magpern/universal-product-reviews/actions/runs/33279265552) — **success** on `73455fb` |

Accepted runtime tip of `main` after #60: **`c86e289d66044f8bde2805ba1389377f555f7cde`**. Post-merge push CI: [run 33279690233](https://github.com/magpern/universal-product-reviews/actions/runs/33279690233) — **success**.

### D19 correction (`73455fb`)

PR #60’s corrective tip `73455fbd372fbae16d25e7b4b44570483470a139` only updates `tests/unit/M4DiagnosticsUnitTest.php`:

- expected diagnostics catalogue size **19** (was 18);
- explicit `assertArrayHasKey( 'D19', $by_id )`;
- no runtime behaviour, release metadata, SemVer, schema, or unrelated product change.

Prior tip `40a124d` failed unit CI ([run 33279219868](https://github.com/magpern/universal-product-reviews/actions/runs/33279219868)) solely on that catalogue-size assertion.

## Boundaries locked by M11

1. **Recommendation-only** — AI / recommendation code never calls `wp_set_comment_status` or equivalent; no auto-approve / auto-spam / auto-trash / auto-hold.
2. **Held-only actionable labels** — actionable labels and reason badges only while comment status is `hold`; leave-hold hides labels and retains the assessment row; restore follows M9/M10 eligibility.
3. **No attention view/filter** — column-only Comments UX; no queryable projection table; no page-only post-filter.
4. **No automatic moderation action** — auto-action deferred to **M12**; UI treats auto-action as unavailable.
5. **No external-AI enablement** — M10 defaults and governance GO unchanged; M11 adds no provider payload fields and no enablement.
6. **Display option** — `upr_ai_recommendations_display`; absent = enabled; independent of shadow masters.

## Safeguards shipped

- Deterministic `RecommendationPolicy` (`RECOMMENDATION_POLICY_VERSION=2026-08-rec-v1`); risk score direction higher = greater publication risk
- Allowlisted `suggested_action` values only; mandatory-human reason-code precedence
- Comments AI advisory column formatting for held vs historical vs display-off
- Diagnostics **D19** + Site Health aggregate for recommendations
- Unit coverage: `M11RecommendationPolicyUnitTest`, `M11RegressionUnitTest`; M4 diagnostics count/D19 key

## Explicit non-actions

| Non-action | Status |
|------------|--------|
| Plugin SemVer bump / CHANGELOG release section | **Not done** — remains `0.8.0` |
| Tags `v0.8.1`, `v0.9.0`, or any new SemVer / `v*` tag | **Not created** |
| Move / recreate / retarget `v0.8.0` | **Not done** |
| GitHub Release / ZIP | **Not created** |
| DEV or production WordPress access / deploy | **Not done** |
| Credentials / provider configuration / real OpenAI traffic | **Not done** |
| External AI enablement | **Not done** |
| Automatic moderation / M12 | **Not started** |
| Attention view / filter / projection table | **Not built** |
| Email | **Not sent** |

## Freeze acceptance checklist (evidence)

- [x] Recommendation-only; no comment-status mutation from AI path
- [x] Held-only actionable labels; leave-hold hide; assessment retained
- [x] No attention view/filter/projection table
- [x] Display option absent = enabled; independent of shadow masters
- [x] Risk-score direction and mandatory-human precedence covered by unit matrix
- [x] D19 diagnostics + Site Health; catalogue size 19 with D19 key asserted
- [x] No SemVer / Release / ZIP / DEV-prod / real OpenAI / external enablement / M12

## Related

- [`m11-ai-moderation-recommendations.md`](m11-ai-moderation-recommendations.md)
- [`../runbooks/ai-outage.md`](../runbooks/ai-outage.md)
- [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)
- [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md) (M12 appendix only)
