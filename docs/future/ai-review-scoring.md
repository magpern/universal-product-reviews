# Future Design Note: AI Review Scoring and Guarded Auto-Approval

**Status:** **M11 appendix only.** Near-term AI planning authority is the M8 freeze: [`../roadmap/m8-ai-assisted-moderation-planning.md`](../roadmap/m8-ai-assisted-moderation-planning.md) and [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md). This document does **not** authorise M9 local shadow, M10 external processing, or any runtime AI. Consider auto-approval only after M9/M10, a privacy assessment, governed calibration, and an ADR-0004 amendment.

## Purpose

A future AI moderation service may assess submitted reviews and produce a **publication-safety score from 1 to 100**. The score estimates whether a review is safe to publish without routine human handling. It is not a measure of review quality, helpfulness, truthfulness, customer value, or commercial favourability.

Product reviews are the first possible use case. Store/order-service reviews are a separate future review type with separate data, display, and schema rules; they may reuse the moderation service but must not be mixed into product-review data or Product structured data.

## Non-negotiable principles

- Never use star rating, sentiment, criticism, positivity, or likelihood to purchase as a score input or moderation outcome.
- A valid negative review must be treated exactly like a valid positive review.
- The AI may not rewrite a review or delete it automatically.
- AI uncertainty, outage, unsupported language, or missing data fails open to ordinary pending human moderation (review already persisted).
- The host can disable all automated decisions immediately through a feature flag.
- Every score, policy version, reason code, decision, override, and later restoration is recorded in the append-only audit trail (allowlisted payloads only).

## Required decision pipeline (M11+)

1. Apply deterministic controls first: verified-purchase/authorization checks, nonce/session validation, duplicate detection, rate limits, known spam signals, link rules, and local personal-data and regulatory hold detection.
2. If any mandatory hold applies, keep the review pending. Do not call or rely on AI to override it.
3. If permitted by the host privacy decision and M10+ external rules (when applicable), classify the minimum necessary review content.
4. Store a publication-safety score, confidence, reason codes, policy version, and timestamp per M8 schema.
5. Apply the current decision policy:
   - **Mandatory hold:** human review, regardless of score.
   - **Below auto-approval threshold or low confidence:** human review.
   - **At/above threshold and all gates pass:** eligible for auto-approval only after the calibration gates below are met.
6. Never auto-reject based solely on AI. High-certainty technical spam remains governed by the separate deterministic spam policy.

## Mandatory human-review holds

Regardless of score, hold reviews that contain or may contain:

- personal data or sensitive personal data;
- medical, regulatory, safety, or prohibited product claims;
- threats, abuse, hate, self-harm, fraud, impersonation, or legal allegations;
- suspected coordinated manipulation (requires comparison capability beyond M9 single-text DTO);
- unsupported language or ambiguous classification;
- a changed/unknown model or policy version during rollout;
- an edit to a previously approved review.

## Rollout gates (M11)

1. **Shadow mode (M9/M10):** collect scores and reason codes with no automated action.
2. **Calibration:** compare AI recommendations against human outcomes on a large, representative, stratified sample that includes legitimate negative reviews. Unit/integration mocks **cannot** prove sentiment parity.
3. **Approval gate:** maintainers approve an explicit threshold and confidence policy only after near-zero false approvals and acceptable disagreement rates are demonstrated.
4. **Limited rollout:** enable auto-approval only for the narrowest low-risk cohort; randomly sample auto-approved reviews for human QA.
5. **Ongoing controls:** monitor false approvals, moderator overrides, customer complaints, restores, language/model drift, and score distribution. Disable automation immediately if a guardrail breaches.

No fixed threshold is approved by this design note or by M8.

## Privacy and data minimisation

Aligned with ADR-0004: send only review text and the minimum non-identifying context required; never send rating, email, name, order identifiers, IP, tokens, or invite URLs. External processing is M10+. Keep raw provider payloads out of the audit store. Secrets stay host-owned.

## Relationship to the frozen roadmap

- **M8** freezes planning boundaries (local vs external vs auto-approval).
- **M9** implements local shadow only.
- **M10** freezes and implements external processing.
- **M11** may implement guarded auto-approval only after this appendix’s gates and an ADR-0004 amendment.
