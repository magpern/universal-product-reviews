# ADR-0004: AI moderation boundary

**Status:** Accepted (M8)  
**Date:** 2026-08-28  
**Context:** UPR may later offer optional AI-assisted review moderation. Human moderation via native Comments admin must remain authoritative. Prior forward notes (`docs/future/ai-review-scoring.md`, `ARCHITECTURE.md` §9) were non-binding. M8 freezes the product boundary before any runtime AI work.

## Decision

1. **Advisory only until M11.** AI produces a bounded publication-safety assessment (score, allowlisted reason codes, confidence/failure metadata). It must not approve, reject, spam-mark, rewrite, or otherwise mutate review content, identity, rating, product linkage, or comment status. M9 and M10 remain advisory shadow (or external advisory) modes with **zero** automated status changes.

2. **Milestone split.**  
   - **M8** — documentation freeze only (this ADR + roadmap).  
   - **M9** — local-only in-process shadow assessment (separate implementation authorisation).  
   - **M10** — external OpenAI advisory assessments per [`../roadmap/m10-external-ai-advisory-assessments.md`](../roadmap/m10-external-ai-advisory-assessments.md) (separate freeze). Core enforces external classification and opt-in before any outbound call; fixed enum `local` \| `openai`; no replaceable provider filter.  
   - **M11** — automatic approval requires an **ADR amendment**, governed calibration/evaluation, and explicit product approval.

3. **Held-only assessment.** Auto-enqueue and re-analysis apply only to **currently held**, top-level, in-scope product reviews. Approved, spam, trash, deleted, replies, and out-of-scope comments must not receive new assessments or re-analysis. Historical advisory may remain visible; re-analysis controls must be absent for non-held comments. Restore to hold re-enables eligibility based on current status.

4. **Privacy and secrets.** Star rating is excluded from provider inputs. Provider secrets are host-owned via `UPR_OPENAI_API_KEY` PHP constant or environment variable (resolution order defined in the M10 freeze). UPR must never store provider API keys in options (plain or encrypted), audit, diagnostics, or support export. Host DPIA is a human/process obligation, not a machine-enforced core gate. External transmission requires M10 freeze + external opt-in + live-enablement GO.

5. **Data model.** Terminal assessments live in `{prefix}upr_moderation_assessments` (not comment meta). Execution ownership uses a portable separate table `{prefix}upr_moderation_assessment_claims` with `PRIMARY KEY (comment_id, policy_version)` — **no** MySQL/MariaDB partial unique indexes. Site rate limit / circuit breaker use `{prefix}upr_moderation_ops` with atomic SQL, not option read-modify-write. Schema migrations are monotonic forward-only; disabling AI does not downgrade schema or drop tables.

6. **Execution claim and completion.** Acquire a claim token atomically before provider invocation. Delivery of advisory assessment is **at-least-once**; AI never mutates comment status. Completion **must** be a single database transaction: lock the exact claim row; re-check matching token and current held eligibility; insert terminal assessment and clear claim in the same transaction; if token or eligibility fails, commit **no** assessment row. Ordered writes with a prior claim check are forbidden.

7. **Non-held revoke.** When a comment becomes approve/spam/trash while a claim is active, atomically insert terminal `skipped` / `ineligible_comment` (with `completed_at` and `retention_due_at`) and clear/rotate the claim token so the in-flight worker’s completion commits zero assessment rows. Expiring the claim alone is insufficient.

8. **Cooperative deadline.** A 15-second deadline is evaluated **after** synchronous `assess()` returns (or throws). Core cannot mark failure while the call is still running. Late success output is discarded.

9. **Public contracts.** Do not register unimplemented AI provider surfaces in `upr-public-contracts/v1`. M10 registers **C19** `AiProvider::selected(): string` (`local`\|`openai` only). **C18** remains `DeliveryStatus::has_confirmation`.

10. **Negative-review parity.** Policy and reason-code allowlists must not encode sentiment or rating disadvantage. Structural tests prove rating exclusion and forbid sentiment reason codes; model fairness requires a separate calibration programme before M11.

## Consequences

- Integrators and maintainers treat M8 as planning authority for near-term AI; `docs/future/ai-review-scoring.md` is an M11 appendix only.
- M5 moderation audit, Comments-admin context, staff-reply rules, and M2/M3 submission security remain intact and authoritative for status.
- Support export and public-contract registry remain unchanged until a later freeze explicitly amends them.
- Implementation PRs that introduce auto-approval, option-stored secrets, provider filters, or partial-index claim uniqueness are out of policy.
- M10 implementation must keep Responses `store: false`, typed fail-closed OpenAI errors, and atomic external quotas per the M10 freeze.

## Related

- [`../roadmap/m8-ai-assisted-moderation-planning.md`](../roadmap/m8-ai-assisted-moderation-planning.md) — authoritative freeze (D1–D17)
- [`../roadmap/m10-external-ai-advisory-assessments.md`](../roadmap/m10-external-ai-advisory-assessments.md) — M10 external OpenAI advisory freeze
- [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md) — M11 auto-approval appendix
- [`../runbooks/ai-outage.md`](../runbooks/ai-outage.md)
- [`ADR-0003-public-contract-compatibility.md`](ADR-0003-public-contract-compatibility.md)
- [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md) §9
