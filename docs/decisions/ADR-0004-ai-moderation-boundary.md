# ADR-0004: AI moderation boundary

**Status:** Accepted (M8); **amended M11** (recommendation-only); **amended M12** (named auto-spam contract; enablement separately gated)  
**Date:** 2026-08-28  
**Amended:** 2026-08-30 (M11 freeze); 2026-08-30 (M12 freeze)  
**Context:** UPR may later offer optional AI-assisted review moderation. Human moderation via native Comments admin must remain authoritative. Prior forward notes (`docs/future/ai-review-scoring.md`, `ARCHITECTURE.md` §9) were non-binding. M8 freezes the product boundary before any runtime AI work. M11 freezes recommendation-only operator guidance. M12 freezes the sole named automatic-action contract `auto_spam_held_technical` as design only; runtime action remains gated by Calibration / Implementation / Dry-run / enablement GOs.

## Decision

1. **Advisory assessments (M9/M10).** AI produces a bounded publication-**risk** assessment (`publication_safety_score` = policy-violation / publication-risk likelihood: **higher = greater risk**, allowlisted reason codes, confidence/failure metadata). It must not approve, reject, spam-mark, rewrite, or otherwise mutate review content, identity, rating, product linkage, or comment status **except** under the separately gated M12 contract below. M9 and M10 remain advisory shadow (or external advisory) modes with **zero** automated status changes until M12 enablement GOs.

2. **Milestone split.**  
   - **M8** — documentation freeze only (this ADR + roadmap).  
   - **M9** — local-only in-process shadow assessment.  
   - **M10** — external OpenAI advisory assessments per [`../roadmap/m10-external-ai-advisory-assessments.md`](../roadmap/m10-external-ai-advisory-assessments.md). Core enforces external classification and opt-in before any outbound call; fixed enum `local` \| `openai`; no replaceable provider filter.  
   - **M11** — **recommendation-only** operator guidance per [`../roadmap/m11-ai-moderation-recommendations.md`](../roadmap/m11-ai-moderation-recommendations.md): deterministic `suggested_action` labels in Comments (held-only actionable UI). **M11 never changes WordPress comment status.**  
   - **M12** — sole automatic-action contract **`auto_spam_held_technical`**: reversible native **`hold` → `spam`** via UPR-owned CAS + proven public-hook parity, leased action ledger, calibrated tuple, and strict post-enablement boundary. Authoritative freeze: [`../roadmap/m12-guarded-auto-spam.md`](../roadmap/m12-guarded-auto-spam.md). **Auto-approve is permanently excluded.** Documentation freeze does **not** authorise runtime action.

3. **Held-only assessment and actionable recommendations.** Auto-enqueue and re-analysis apply only to **currently held**, top-level, in-scope product reviews. Approved, spam, trash, deleted, replies, and out-of-scope comments must not receive new assessments or re-analysis. **Actionable** recommendation labels and reason badges show **only** while current status is `hold`. On transition away from `hold`, hide those labels/badges; retain assessment rows for audit/retention; do not offer re-analysis. Restore to hold re-enables eligibility based on current status (M9/M10 rules). M12 action eligibility is likewise held-only and assessment-bound.

4. **Privacy and secrets.** Star rating is excluded from provider inputs. M11/M12 add **no** additional account, order, email, token, or URL fields to provider payloads. Review text is untrusted user content and **may contain personal data**; M10 enablement acknowledgements remain the controlling boundary for external transmission. Provider secrets are host-owned via `UPR_OPENAI_API_KEY` PHP constant or environment variable (resolution order defined in the M10 freeze). UPR must never store provider API keys in options (plain or encrypted), audit, diagnostics, or support export. Host DPIA is a human/process obligation, not a machine-enforced core gate. External transmission requires M10 freeze + external opt-in + live-enablement GO. M12 action step makes **no new provider call**.

5. **Data model.** Terminal assessments live in `{prefix}upr_moderation_assessments` (not comment meta). Execution ownership uses a portable separate table `{prefix}upr_moderation_assessment_claims` with `PRIMARY KEY (comment_id, policy_version)` — **no** MySQL/MariaDB partial unique indexes. Site rate limit / circuit breaker use `{prefix}upr_moderation_ops` with atomic SQL, not option read-modify-write. Schema migrations are monotonic forward-only; disabling AI does not downgrade schema or drop tables. M11 recommendations are **derive-at-read**; no recommendation projection table in M11. M12 adds an additive `{prefix}upr_moderation_action_ledger` (leased states) only after Implementation GO.

6. **Execution claim and completion.** Acquire a claim token atomically before provider invocation. Delivery of advisory assessment is **at-least-once**; AI never mutates comment status in M9–M11. Completion **must** be a single database transaction: lock the exact claim row; re-check matching token and current held eligibility; insert terminal assessment and clear claim in the same transaction; if token or eligibility fails, commit **no** assessment row. Ordered writes with a prior claim check are forbidden.

7. **Non-held revoke.** When a comment becomes approve/spam/trash while a claim is active, atomically insert terminal `skipped` / `ineligible_comment` (with `completed_at` and `retention_due_at`) and clear/rotate the claim token so the in-flight worker’s completion commits zero assessment rows. Expiring the claim alone is insufficient.

8. **Cooperative deadline.** A 15-second deadline is evaluated **after** synchronous `assess()` returns (or throws). Core cannot mark failure while the call is still running. Late success output is discarded.

9. **Public contracts.** Do not register unimplemented AI provider surfaces in `upr-public-contracts/v1`. M10 registers **C19** `AiProvider::selected(): string` (`local`\|`openai` only). **C18** remains `DeliveryStatus::has_confirmation`. M11/M12 do not add a public contract. Support export `upr-support-export/v1` remains unchanged in M12 unless a later freeze amends it.

10. **Negative-review parity.** Policy and reason-code allowlists must not encode sentiment or rating disadvantage. Structural tests prove rating exclusion and forbid sentiment reason codes; model fairness requires a separate calibration programme before **M12** auto-action enablement (Wilson false-spam bound on legitimate-negative holdout).

11. **M11 recommendations.** Deterministic local mapping from validated assessment fields to allowlisted `suggested_action` values only. No provider-emitted actions. No free-form model explanations in UI. Recommendation display option: **absent = enabled**, independent of shadow masters. No native Comments attention filter/view in M11. Column-only UX remains unchanged under M12.

12. **M12 automatic action (`auto_spam_held_technical`).**  
    - **Only** reversible `hold` → `spam` via UPR-owned CAS (`UPDATE … WHERE comment_approved='0'`) plus proven WordPress public-hook/cache/count parity for the supported WP floor.  
    - **Never** auto-approve, auto-trash, abuse automation, sentiment/rating/criticism moderation, review editing, customer communication, or invitation handling.  
    - Score alone never acts. Required conjunction: assessment `completed`; confidence `high`; publication-risk ≥ frozen threshold; allowlisted technical-spam reason-code intersection; no mandatory-human reason code; M11 recommendation exactly `likely_spam`.  
    - Action only for an active immutable calibrated tuple (provider kind, assessor version, heuristic or model/prompt fingerprint, validator, assessment/recommendation/action policy versions).  
    - Strict enablement boundary: persist `upr_ai_auto_action_boundary_at` on every off→on; live action requires `completed_at > boundary_at`; equality abstains; missing/zero boundary fails closed; dry-run `observed` never promotes.  
    - Leased action ledger is sole durable authority; states include `processing`, `cas_succeeded`, `acted`, `abstained`, `observed`, `unknown_after_crash`. Successful CAS and `cas_succeeded` must share one DB transaction. **Never** replay public WordPress transition hooks after a crash; crash after `cas_succeeded` → `unknown_after_crash` + critical diagnostic + manual reconciliation.  
    - Distinct audit event `review.ai_auto_spam` under `AiActionOrigin`; `review.system_spam` remains invitation-abandon only. No claim tokens in audit payloads.  
    - Masters default **off**; independent kill switch; dry-run mode; separate quotas/circuit.  
    - Automatic action requires separate **Calibration GO**, **Implementation GO**, **Dry-run GO**, **DEV enablement GO**, and later **production enablement GO**. If CAS/hook-parity or atomic CAS+`cas_succeeded` cannot be proven, M12 closes as **deferred / NO-GO**.

## Consequences

- Integrators treat M8–M10 as assessment foundations; M11 as recommendation UI only; M12 as a **named**, separately gated auto-spam contract (`auto_spam_held_technical`) per [`../roadmap/m12-guarded-auto-spam.md`](../roadmap/m12-guarded-auto-spam.md). [`docs/future/ai-review-scoring.md`](../future/ai-review-scoring.md) is a demoted appendix under the M12 freeze.
- M5 moderation audit, Comments-admin context, staff-reply rules, and M2/M3 submission security remain intact and authoritative for status except the narrow AI CAS path when explicitly enabled.
- Support export and public-contract registry remain unchanged until a later freeze explicitly amends them.
- Implementation PRs that introduce auto-approval, option-stored secrets, provider filters, partial-index claim uniqueness, public-hook replay after crash, or enablement without Calibration GO are out of policy.
- M10 implementation must keep Responses `store: false`, typed fail-closed OpenAI errors, and atomic external quotas per the M10 freeze.
- Documentation freeze merge and tag do **not** turn on automatic action in any environment.

## Related

- [`../roadmap/m8-ai-assisted-moderation-planning.md`](../roadmap/m8-ai-assisted-moderation-planning.md) — authoritative freeze (D1–D17)
- [`../roadmap/m10-external-ai-advisory-assessments.md`](../roadmap/m10-external-ai-advisory-assessments.md) — M10 external OpenAI advisory freeze
- [`../roadmap/m11-ai-moderation-recommendations.md`](../roadmap/m11-ai-moderation-recommendations.md) — M11 recommendation-only freeze
- [`../roadmap/m12-guarded-auto-spam.md`](../roadmap/m12-guarded-auto-spam.md) — M12 guarded auto-spam freeze
- [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md) — demoted M12 appendix
- [`../runbooks/ai-outage.md`](../runbooks/ai-outage.md)
- [`ADR-0003-public-contract-compatibility.md`](ADR-0003-public-contract-compatibility.md)
- [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md) §9
