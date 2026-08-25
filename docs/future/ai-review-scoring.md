# Future Design Note: AI Review Scoring and Guarded Auto-Approval

**Status:** Deferred design note. This document is not implementation authority and does not change the frozen M0–M8 plan. Consider it only after the host pilot, privacy assessment, and a separately approved milestone.

## Purpose

A future AI moderation service may assess submitted reviews and produce a **publication-safety score from 1 to 100**. The score estimates whether a review is safe to publish without routine human handling. It is not a measure of review quality, helpfulness, truthfulness, customer value, or commercial favourability.

Product reviews are the first possible use case. Store/order-service reviews are a separate future review type with separate data, display, and schema rules; they may reuse the moderation service but must not be mixed into product-review data or Product structured data.

## Non-negotiable principles

- Never use star rating, sentiment, criticism, positivity, or likelihood to purchase as a score input or moderation outcome.
- A valid negative review must be treated exactly like a valid positive review.
- The AI may not rewrite a review or delete it automatically.
- AI uncertainty, outage, unsupported language, or missing data fails closed to human moderation.
- The host can disable all automated decisions immediately through a feature flag.
- Every score, policy version, reason code, decision, override, and later restoration is recorded in the append-only audit trail.

## Required decision pipeline

1. Apply deterministic controls first: verified-purchase/authorization checks, nonce/session validation, duplicate detection, rate limits, known spam signals, link rules, and local personal-data and regulatory hold detection.
2. If any mandatory hold applies, keep the review pending. Do not call or rely on AI to override it.
3. If permitted by the host privacy decision, classify the minimum necessary review content and coarse non-identifying context.
4. Store a publication-safety score, confidence, reason codes, model/prompt policy version, and timestamp.
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
- suspected coordinated manipulation;
- unsupported language or ambiguous classification;
- a changed/unknown model or policy version during rollout;
- an edit to a previously approved review.

## Rollout gates

1. **Shadow mode:** collect scores and reason codes with no automated action.
2. **Calibration:** compare AI recommendations against human outcomes on a large, representative, stratified sample that includes legitimate negative reviews.
3. **Approval gate:** maintainers approve an explicit threshold and confidence policy only after near-zero false approvals and acceptable disagreement rates are demonstrated.
4. **Limited rollout:** enable auto-approval only for the narrowest low-risk cohort; randomly sample auto-approved reviews for human QA.
5. **Ongoing controls:** monitor false approvals, moderator overrides, customer complaints, restores, language/model drift, and score distribution. Disable automation immediately if a guardrail breaches.

No fixed threshold is approved by this design note. A numeric threshold must be evidence-based and versioned after calibration; it must not be selected merely because a value such as 90 or 95 appears intuitively safe.

## Privacy and data minimisation

Before any external AI call, require a documented privacy/DPIA decision, processor terms and retention decision, approved provider/model, and host maintainer approval.

Send only the review text and the minimum non-identifying context required for classification. Do not send customer email, name, order identifiers, IP address, or full purchase history. Keep raw provider payloads out of the audit store.

## Testing and audit requirements

Future implementation must test:

- identical treatment of favourable and critical reviews;
- mandatory holds overriding a high score;
- outage, timeout, malformed response, and unsupported-language fallback to pending;
- threshold boundary and confidence boundary behaviour;
- idempotency and concurrency;
- audit completeness and moderator override/restoration recording;
- feature-flag shutdown;
- calibrated false-approval monitoring.

## Relationship to the frozen roadmap

The frozen M0–M8 scope remains unchanged. Current M6 is AI shadow mode only, post-DPIA. Any scoring-based auto-approval requires a separate approved milestone after M8 and must satisfy every guardrail in this document.
