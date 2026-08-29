# ADR-0004: AI moderation boundary

**Status:** Accepted (M8); **amended M11** (recommendation-only)  
**Date:** 2026-08-28  
**Amended:** 2026-08-30 (M11 freeze)  
**Context:** UPR may later offer optional AI-assisted review moderation. Human moderation via native Comments admin must remain authoritative. Prior forward notes (`docs/future/ai-review-scoring.md`, `ARCHITECTURE.md` §9) were non-binding. M8 freezes the product boundary before any runtime AI work. M11 freezes recommendation-only operator guidance; automatic status mutation remains deferred.

## Decision

1. **Advisory assessments (M9/M10).** AI produces a bounded publication-**risk** assessment (`publication_safety_score` = policy-violation / publication-risk likelihood: **higher = greater risk**, allowlisted reason codes, confidence/failure metadata). It must not approve, reject, spam-mark, rewrite, or otherwise mutate review content, identity, rating, product linkage, or comment status. M9 and M10 remain advisory shadow (or external advisory) modes with **zero** automated status changes.

2. **Milestone split.**  
   - **M8** — documentation freeze only (this ADR + roadmap).  
   - **M9** — local-only in-process shadow assessment.  
   - **M10** — external OpenAI advisory assessments per [`../roadmap/m10-external-ai-advisory-assessments.md`](../roadmap/m10-external-ai-advisory-assessments.md). Core enforces external classification and opt-in before any outbound call; fixed enum `local` \| `openai`; no replaceable provider filter.  
   - **M11** — **recommendation-only** operator guidance per [`../roadmap/m11-ai-moderation-recommendations.md`](../roadmap/m11-ai-moderation-recommendations.md): deterministic `suggested_action` labels in Comments (held-only actionable UI). **M11 never changes WordPress comment status.**  
   - **M12** — any automatic action (including guarded auto-approval or other named contracts) requires a **further ADR amendment**, governed calibration/evaluation, dry-run, separate master enable, kill switch, and explicit product approval. See [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md).

3. **Held-only assessment and actionable recommendations.** Auto-enqueue and re-analysis apply only to **currently held**, top-level, in-scope product reviews. Approved, spam, trash, deleted, replies, and out-of-scope comments must not receive new assessments or re-analysis. **Actionable** recommendation labels and reason badges show **only** while current status is `hold`. On transition away from `hold`, hide those labels/badges; retain assessment rows for audit/retention; do not offer re-analysis. Restore to hold re-enables eligibility based on current status (M9/M10 rules).

4. **Privacy and secrets.** Star rating is excluded from provider inputs. M11 adds **no** additional account, order, email, token, or URL fields to provider payloads. Review text is untrusted user content and **may contain personal data**; M10 enablement acknowledgements remain the controlling boundary for external transmission. Provider secrets are host-owned via `UPR_OPENAI_API_KEY` PHP constant or environment variable (resolution order defined in the M10 freeze). UPR must never store provider API keys in options (plain or encrypted), audit, diagnostics, or support export. Host DPIA is a human/process obligation, not a machine-enforced core gate. External transmission requires M10 freeze + external opt-in + live-enablement GO.

5. **Data model.** Terminal assessments live in `{prefix}upr_moderation_assessments` (not comment meta). Execution ownership uses a portable separate table `{prefix}upr_moderation_assessment_claims` with `PRIMARY KEY (comment_id, policy_version)` — **no** MySQL/MariaDB partial unique indexes. Site rate limit / circuit breaker use `{prefix}upr_moderation_ops` with atomic SQL, not option read-modify-write. Schema migrations are monotonic forward-only; disabling AI does not downgrade schema or drop tables. M11 recommendations are **derive-at-read**; no recommendation projection table in M11.

6. **Execution claim and completion.** Acquire a claim token atomically before provider invocation. Delivery of advisory assessment is **at-least-once**; AI never mutates comment status. Completion **must** be a single database transaction: lock the exact claim row; re-check matching token and current held eligibility; insert terminal assessment and clear claim in the same transaction; if token or eligibility fails, commit **no** assessment row. Ordered writes with a prior claim check are forbidden.

7. **Non-held revoke.** When a comment becomes approve/spam/trash while a claim is active, atomically insert terminal `skipped` / `ineligible_comment` (with `completed_at` and `retention_due_at`) and clear/rotate the claim token so the in-flight worker’s completion commits zero assessment rows. Expiring the claim alone is insufficient.

8. **Cooperative deadline.** A 15-second deadline is evaluated **after** synchronous `assess()` returns (or throws). Core cannot mark failure while the call is still running. Late success output is discarded.

9. **Public contracts.** Do not register unimplemented AI provider surfaces in `upr-public-contracts/v1`. M10 registers **C19** `AiProvider::selected(): string` (`local`\|`openai` only). **C18** remains `DeliveryStatus::has_confirmation`. M11 does not add a public contract.

10. **Negative-review parity.** Policy and reason-code allowlists must not encode sentiment or rating disadvantage. Structural tests prove rating exclusion and forbid sentiment reason codes; model fairness requires a separate calibration programme before **M12** auto-action.

11. **M11 recommendations.** Deterministic local mapping from validated assessment fields to allowlisted `suggested_action` values only. No provider-emitted actions. No free-form model explanations in UI. Recommendation display option: **absent = enabled**, independent of shadow masters. No native Comments attention filter/view in M11.

## Consequences

- Integrators treat M8–M10 as assessment foundations; M11 as recommendation UI only; [`docs/future/ai-review-scoring.md`](../future/ai-review-scoring.md) as an **M12** appendix for auto-action.
- M5 moderation audit, Comments-admin context, staff-reply rules, and M2/M3 submission security remain intact and authoritative for status.
- Support export and public-contract registry remain unchanged until a later freeze explicitly amends them.
- Implementation PRs that introduce auto-approval/auto-spam, option-stored secrets, provider filters, or partial-index claim uniqueness are out of policy for M11.
- M10 implementation must keep Responses `store: false`, typed fail-closed OpenAI errors, and atomic external quotas per the M10 freeze.

## Related

- [`../roadmap/m8-ai-assisted-moderation-planning.md`](../roadmap/m8-ai-assisted-moderation-planning.md) — authoritative freeze (D1–D17)
- [`../roadmap/m10-external-ai-advisory-assessments.md`](../roadmap/m10-external-ai-advisory-assessments.md) — M10 external OpenAI advisory freeze
- [`../roadmap/m11-ai-moderation-recommendations.md`](../roadmap/m11-ai-moderation-recommendations.md) — M11 recommendation-only freeze
- [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md) — M12 auto-action appendix
- [`../runbooks/ai-outage.md`](../runbooks/ai-outage.md)
- [`ADR-0003-public-contract-compatibility.md`](ADR-0003-public-contract-compatibility.md)
- [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md) §9
