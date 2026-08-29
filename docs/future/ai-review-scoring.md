# Future Design Note: AI Review Scoring and Guarded Auto-Action (M12 appendix)

**Status:** **M12 appendix only.** Near-term AI planning authority for assessments is M8–M10; for operator recommendations is the M11 freeze: [`../roadmap/m11-ai-moderation-recommendations.md`](../roadmap/m11-ai-moderation-recommendations.md) and [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md). This document does **not** authorise M11 implementation details beyond pointing to that freeze, and does **not** authorise any automatic comment-status mutation.

## Purpose

A future AI moderation service may assess submitted reviews and produce a **publication-risk score from 1 to 100** (`publication_safety_score`: **higher = greater publication/policy risk**). The score is not a measure of review quality, helpfulness, truthfulness, customer value, or commercial favourability.

**M11** implements recommendation-only labels derived from that score and allowlisted reason codes. **M12** (if ever) may implement guarded automatic action only after this appendix’s gates and a further ADR-0004 amendment.

Product reviews are the first possible use case. Store/order-service reviews are a separate future review type with separate data, display, and schema rules; they may reuse the moderation service but must not be mixed into product-review data or Product structured data.

## Non-negotiable principles

- Never use star rating, sentiment, criticism, positivity, or likelihood to purchase as a score input or moderation outcome.
- A valid negative review must be treated exactly like a valid positive review.
- The AI may not rewrite a review or delete it automatically.
- AI uncertainty, outage, unsupported language, or missing data fails open to ordinary pending human moderation (review already persisted).
- The host can disable all automated decisions immediately through a feature flag.
- Every score, policy version, reason code, decision, override, and later restoration is recorded in the append-only audit trail (allowlisted payloads only).
- Never auto-reject based solely on AI sentiment or rating. High-certainty technical spam, if ever automated, remains a separately justified, fail-closed contract — not sentiment rejection.

## Required decision pipeline (M12+)

1. Apply deterministic controls first: verified-purchase/authorization checks, nonce/session validation, duplicate detection, rate limits, known spam signals, link rules, and local personal-data and regulatory hold detection.
2. If any mandatory hold applies, keep the review pending. Do not call or rely on AI to override it.
3. If permitted by the host privacy decision and M10+ external rules (when applicable), classify the minimum necessary review content.
4. Store a publication-risk score, confidence, reason codes, policy version, and timestamp per M8 schema.
5. Apply M11 recommendations for operators (held-only actionable labels).
6. Apply any M12 decision policy only after gates below:
   - **Mandatory hold:** human review, regardless of score.
   - **Below auto-action threshold or low confidence:** human review.
   - **At/above threshold and all gates pass:** eligible for a **named**, separately enabled action contract only after calibration.
7. Never auto-approve without near-zero false-approval evidence. Never use sentiment/rating to reject.

## Mandatory human-review holds

Regardless of score, hold reviews that contain or may contain:

- personal data or sensitive personal data;
- medical, regulatory, safety, or prohibited product claims;
- threats, abuse, hate, self-harm, fraud, impersonation, or legal allegations;
- suspected coordinated manipulation (requires comparison capability beyond M9 single-text DTO);
- unsupported language or ambiguous classification;
- a changed/unknown model or policy version during rollout;
- an edit to a previously approved review.

## Rollout gates (M12)

1. **Shadow + recommendations (M9–M11):** collect scores/reason codes and operator recommendations with no automated status mutation.
2. **Calibration:** compare AI recommendations against human outcomes on a large, representative, stratified sample that includes legitimate negative reviews. Unit/integration mocks **cannot** prove sentiment parity.
3. **Approval gate:** maintainers approve an explicit threshold and confidence policy only after near-zero false approvals (for any auto-approve cohort) and acceptable disagreement rates are demonstrated.
4. **Dry-run:** shadow evaluation of “would have acted” metrics before enabling any master auto-action switch.
5. **Limited rollout:** enable only the narrowest low-risk cohort; randomly sample for human QA; instant kill switch.
6. **Ongoing controls:** monitor false actions, moderator overrides, customer complaints, restores, language/model drift, and score distribution. Disable automation immediately if a guardrail breaches.

No fixed auto-action threshold is approved by this design note, by M8, or by M11.

## Privacy and data minimisation

Aligned with ADR-0004: send only review text and the minimum non-identifying context required; never send rating, email, name, order identifiers, IP, tokens, or invite URLs. Review text may contain personal data. External processing is M10+. Keep raw provider payloads out of the audit store. Secrets stay host-owned.

## Relationship to the frozen roadmap

- **M8** freezes planning boundaries (local vs external vs auto-action split).
- **M9** implements local shadow only.
- **M10** freezes and implements external processing.
- **M11** freezes and implements **recommendation-only** guidance ([`../roadmap/m11-ai-moderation-recommendations.md`](../roadmap/m11-ai-moderation-recommendations.md)).
- **M12** may implement guarded auto-action only after this appendix’s gates and a further ADR-0004 amendment.
