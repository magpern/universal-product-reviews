# M10 — External AI Advisory Assessments — closure

**Verdict:** PASS — M10 documentation freeze, implementation (WP1–WP5), and corrective hardening (PRs **#55–#57**) completed on `main`. **Implementation is accepted only after PRs #55–#57.** Plugin SemVer remains **`0.8.0`**. No GitHub Release, ZIP, version tag (`v0.8.1` / `v0.9.0` / other), DEV/production WordPress access, deployment, email, real OpenAI customer-review traffic, bind mount, WordPress setting change to enable external AI, automatic moderation, M11, or movement of **`v0.8.0`** was performed as part of this closure.

External AI remains **disabled by default** (provider default `local`; external opt-in off) and still requires the separate privacy / governance / provider-limit **GO** before any enablement.

## Artifacts

| Item | Reference |
|------|-----------|
| Freeze plan | [`m10-external-ai-advisory-assessments.md`](m10-external-ai-advisory-assessments.md) (unchanged by this closure amendment; freeze tag intact) |
| Boundary ADR | [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md) |
| WP5 regression evidence | [`m10-wp5-regression-evidence.md`](m10-wp5-regression-evidence.md) |
| Freeze PR | [#48](https://github.com/magpern/universal-product-reviews/pull/48) |
| Freeze merge commit | `2785d53c80e45e7fa80b433fee242d363ea55208` |
| Freeze PR CI | [run 33241368909](https://github.com/magpern/universal-product-reviews/actions/runs/33241368909) — **success** |
| Freeze tag | `m10-external-ai-advisory-assessments-freeze` (annotated object `a2f10265ac0f89cbc5585da7aa82356eb7a7d9a1`) → peeled **`2785d53c80e45e7fa80b433fee242d363ea55208`** |
| Corrected baseline cited in freeze | `0625b215ae4a40511820c49f53e1e4fa30479cc9` (PR #47); **`v0.8.0`** left at that peel and never retargeted during M10 |

## Implementation PRs

| WP | PR | Merge commit | PR CI |
|----|----|--------------|-------|
| WP1 schema / quotas / typed models | [#49](https://github.com/magpern/universal-product-reviews/pull/49) | `024852c8a043328c844c5185eb7b2796a84bddc2` | [33241617604](https://github.com/magpern/universal-product-reviews/actions/runs/33241617604) success |
| WP2 OpenAI client / credentials / validators | [#50](https://github.com/magpern/universal-product-reviews/pull/50) | `ccae5effd2a38190cf5222acb3d3ab69f10b6f74` | [33241982785](https://github.com/magpern/universal-product-reviews/actions/runs/33241982785) success |
| WP3 worker lifecycle / fail-closed quotas | [#51](https://github.com/magpern/universal-product-reviews/pull/51) | `0ab5612ea8c4fa72ac38717d6e739e75ef4472d8` | [33242252542](https://github.com/magpern/universal-product-reviews/actions/runs/33242252542) success |
| WP4 admin controls / diagnostics / re-analysis gates | [#52](https://github.com/magpern/universal-product-reviews/pull/52) | `0e659045243638da7b6b26233ffff257996b74b3` | [33242554949](https://github.com/magpern/universal-product-reviews/actions/runs/33242554949) success |
| WP5 runbooks / regression policy | [#53](https://github.com/magpern/universal-product-reviews/pull/53) | `8fa2a03b6505b65014f4c318b744c8e7df9fd791` | [33244949066](https://github.com/magpern/universal-product-reviews/actions/runs/33244949066) success |

Implementation tip of `main` at WP5 merge: **`8fa2a03b6505b65014f4c318b744c8e7df9fd791`**. Initial closure documentation: PR [#54](https://github.com/magpern/universal-product-reviews/pull/54) → `669dfe45bb7a7e96a000d73fd63713154a1be60e`. Neither is a release tag target.

## Corrective PRs (required for acceptance)

Initial closure (#54) is **superseded for acceptance** by the following corrective merges. M10 is accepted only with all three on `main`:

| Finding | PR | Merge commit | PR CI | Push CI (merge) |
|---------|----|--------------|-------|-----------------|
| External-disable race + disabled re-analysis refusal | [#55](https://github.com/magpern/universal-product-reviews/pull/55) | `a6d4fdc9a18016ec27420850f7eeed59b282d74e` | [33246237723](https://github.com/magpern/universal-product-reviews/actions/runs/33246237723) success | [33246277858](https://github.com/magpern/universal-product-reviews/actions/runs/33246277858) success |
| Immutable `claim_provider_kind`; scoped openai-only clear | [#56](https://github.com/magpern/universal-product-reviews/pull/56) | `e3eca66fef1101c285b7078309879b873302c941` | [33247399089](https://github.com/magpern/universal-product-reviews/actions/runs/33247399089) success | [33247450626](https://github.com/magpern/universal-product-reviews/actions/runs/33247450626) success |
| Worker bound to stamped claim provider; finalize kind check | [#57](https://github.com/magpern/universal-product-reviews/pull/57) | `6206e3e6de966ce07dbd792991df584403ac117b` | [33247877523](https://github.com/magpern/universal-product-reviews/actions/runs/33247877523) success | [33247939796](https://github.com/magpern/universal-product-reviews/actions/runs/33247939796) success |

Accepted runtime tip of `main` after corrective hardening: **`6206e3e6de966ce07dbd792991df584403ac117b`**.

### Corrective behaviour locked by #55–#57

1. **External disable** silently clears **only** in-flight claims stamped `claim_provider_kind=openai` (no terminal assessment row, no AI audit for those clears). OpenAI re-analysis is refused while external AI is disabled.
2. **Local claims survive** provider-option changes (including later selection of `openai` and subsequent external disable).
3. **Every worker attempt** after acquisition uses the immutable `claim_provider_kind` stamped on the owned claim row for the entire attempt — it does **not** re-read the live selected-provider option.
4. **Transactional finalisation** verifies the locked claim’s provider kind matches the attempt before writing a terminal assessment; on mismatch, clear/recover without persisting a terminal row or AI audit.
5. **No** release, SemVer tag, DEV/production WordPress access, real OpenAI customer-review traffic, credential configuration, or external-AI enablement occurred in these corrective merges.

## Safeguards shipped

- Advisory only — AI never mutates comment/product/order/invite/token/session/email/moderation state
- Provider enum exactly `local` \| `openai`; no provider filter / class override
- OpenAI fail-closed (no silent local fallback); incomplete config → typed `failed`/`skipped`
- Responses API only; `store: false`; no tools / conversation / `previous_response_id`
- Host-only credentials (`UPR_OPENAI_API_KEY` constant then env); never displayed/logged/exported
- Server-side external enable confirms + privacy/retention/PII acks
- Atomic daily+monthly external quotas; test connection uses synthetic payload and does not touch M9 rate/circuit
- OpenAI re-analysis requires `manage_woocommerce` (`moderate_comments` alone denied); refused while external AI is off
- Typed `ProviderError` / allowlisted failure codes; diagnostics D16–D18 / Site Health aggregates only
- C19 `AiProvider::selected()`; CI path-scoped `wp_remote_post` under `src/Ai/OpenAi/`
- Claim acquisition stamps `claim_provider_kind`; worker + finalize bind to that stamp (#56–#57)

## Tests (representative)

Unit/integration suites added or extended: `M10AiProviderUnitTest`, `M10OpenAiClientUnitTest`, `M10ProviderResolverUnitTest`, `M10ExternalAiControlsUnitTest`, `M10RegressionPolicyUnitTest`, `M10ExternalQuotaIntegrationTest`, `M10WorkerOpenAiIntegrationTest` (including mid-assess provider-option interleaving), `M10ExternalControlsIntegrationTest` (local claim survives openai disable), plus M1–M9 regression coverage remaining green on each PR CI.

## Explicit non-actions

| Non-action | Status |
|------------|--------|
| Plugin SemVer bump / CHANGELOG release section | **Not done** — remains `0.8.0` |
| Tags `v0.8.1`, `v0.9.0`, or any new SemVer tag | **Not created** |
| Move / recreate / retarget `v0.8.0` | **Not done** (peel remains `0625b215ae4a40511820c49f53e1e4fa30479cc9`) |
| GitHub Release / ZIP | **Not created** |
| DEV or production WordPress access / deploy / enable external AI | **Not done** |
| Real OpenAI API key use with customer reviews | **Not done** |
| M11 auto-approval | **Not started** |

## Freeze acceptance checklist (evidence)

- [x] Enum `local`\|`openai`; no provider filter; fail-closed OpenAI
- [x] `store: false`; no tools/conversation chaining
- [x] Host-only credentials; never displayed/logged/exported
- [x] Server-side enablement confirms/acks; OpenAI re-analysis `manage_woocommerce`
- [x] Atomic dual quotas; test connection skips M9 rate/circuit
- [x] Typed failure map; injection tests; secret redaction
- [x] C19 registered; CI network allowlist path-scoped
- [x] External disable silently clears openai-stamped claims only; local claims survive (#55–#56)
- [x] Worker + finalize bound to immutable `claim_provider_kind` (#57)
- [x] No SemVer / Release / ZIP / DEV-prod / real customer OpenAI traffic in milestone merges

## Exact later release / enablement decision

Before external AI may be **enabled** in any environment (including DEV with customer-like review text):

1. Separately authorised SemVer / release metadata if a version bump is desired (optional vs enablement).
2. Documented processor/privacy terms with OpenAI.
3. Configured OpenAI project retention/privacy posture.
4. Dedicated OpenAI project/service account with **provider-side** spend and rate limits.
5. Operator acknowledgement that review text may contain personal data (Controls already gates enablement with server-side acks).
6. Maintainer explicit **GO**.

This closure does **not** authorise that GO. External AI remains disabled by default until that GO is granted.

## Related

- [`m10-external-ai-advisory-assessments.md`](m10-external-ai-advisory-assessments.md)
- [`m10-wp5-regression-evidence.md`](m10-wp5-regression-evidence.md)
- [`../runbooks/ai-outage.md`](../runbooks/ai-outage.md)
- [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)
