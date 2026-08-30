# Future Design Note: AI Review Scoring and Guarded Auto-Action (demoted M12 appendix)

**Status:** **Demoted M12 appendix.** Authoritative M12 design is the freeze: [`../roadmap/m12-guarded-auto-spam.md`](../roadmap/m12-guarded-auto-spam.md) and ADR-0004 (amended M12). Near-term AI assessment authority remains M8–M10; operator recommendations remain the M11 freeze. This document does **not** authorise runtime automatic action, Calibration GO, Implementation GO, Dry-run GO, or any enablement.

## Purpose

A future AI moderation service may assess submitted reviews and produce a **publication-risk score from 1 to 100** (`publication_safety_score`: **higher = greater publication/policy risk**). The score is not a measure of review quality, helpfulness, truthfulness, customer value, or commercial favourability.

**M11** implements recommendation-only labels derived from that score and allowlisted reason codes. **M12** freezes exactly one potential automatic-action contract: **`auto_spam_held_technical`** (reversible `hold` → `spam`). **Auto-approve is permanently excluded.** Runtime action requires separate Calibration, Implementation, Dry-run, DEV enablement, and later production enablement GOs.

Product reviews are the first possible use case. Store/order-service reviews are a separate future review type with separate data, display, and schema rules; they may reuse the moderation service but must not be mixed into product-review data or Product structured data.

## Non-negotiable principles

- Never use star rating, sentiment, criticism, positivity, or likelihood to purchase as a score input or moderation outcome.
- A valid negative review must be treated exactly like a valid positive review.
- The AI may not rewrite a review or delete it automatically.
- AI uncertainty, outage, unsupported language, or missing data fails open to ordinary pending human moderation (review already persisted) for assessment paths; M12 action fail-closed abstains.
- The host can disable all automated decisions immediately through a feature flag / kill switch.
- Every score, policy version, reason code, decision, override, and later restoration is recorded in the append-only audit trail (allowlisted payloads only).
- Never auto-reject based solely on AI sentiment or rating. High-certainty technical spam automation, if enabled, is only the named `auto_spam_held_technical` contract — not sentiment rejection.
- Never auto-approve.

## Required decision pipeline (M12+)

1. Apply deterministic controls first: verified-purchase/authorization checks, nonce/session validation, duplicate detection, rate limits, known spam signals, link rules, and local personal-data and regulatory hold detection.
2. If any mandatory hold applies, keep the review pending. Do not call or rely on AI to override it.
3. If permitted by the host privacy decision and M10+ external rules (when applicable), classify the minimum necessary review content.
4. Store a publication-risk score, confidence, reason codes, policy version, and timestamp per M8 schema.
5. Apply M11 recommendations for operators (held-only actionable labels).
6. Apply M12 `ActionPolicy` only after freeze gates and Calibration GO:
   - **Mandatory hold / mandatory-human codes:** human review; never act.
   - **Below threshold, low confidence, tuple mismatch, or boundary fail:** abstain.
   - **All conjunction gates pass:** eligible for `auto_spam_held_technical` only when masters and dry-run/live modes allow.
7. Never auto-approve. Never use sentiment/rating to reject.

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
2. **Documentation freeze:** [`../roadmap/m12-guarded-auto-spam.md`](../roadmap/m12-guarded-auto-spam.md) — design only; no runtime.
3. **Calibration:** stratified labelled corpus (≥400 legitimate-negative; ≥200 technical-spam); holdout ≥20% locked before tuning; Wilson 95% UCB false-spam ≤ 1.0% on legitimate-negative holdout; technical-spam precision ≥ 95%; zero mandatory-human would-act rows. Privacy-safe evidence only.
4. **Implementation GO:** masters default off; CAS + hook parity + leased ledger.
5. **Dry-run GO:** ledger `observed` only; never promotable.
6. **DEV enablement GO:** refreshes `upr_ai_auto_action_boundary_at`; strict `completed_at > boundary`.
7. **Production enablement GO:** out of M12 product freeze.

If CAS/hook-parity or atomic CAS+`cas_succeeded` cannot be proven, or product requires public-hook replay after crash → **NO-GO / deferred**.

## Privacy and data minimisation

Aligned with ADR-0004: send only review text and the minimum non-identifying context required; never send rating, email, name, order identifiers, IP, tokens, or invite URLs. Review text may contain personal data. External processing is M10+. Keep raw provider payloads out of the audit store. Secrets stay host-owned. M12 action uses no new provider call and no claim tokens in audits.

## Relationship to the frozen roadmap

- **M8** freezes planning boundaries (local vs external vs auto-action split).
- **M9** implements local shadow only.
- **M10** freezes and implements external processing.
- **M11** freezes and implements **recommendation-only** guidance ([`../roadmap/m11-ai-moderation-recommendations.md`](../roadmap/m11-ai-moderation-recommendations.md)).
- **M12** freezes **`auto_spam_held_technical`** ([`../roadmap/m12-guarded-auto-spam.md`](../roadmap/m12-guarded-auto-spam.md)); this file is a demoted appendix under that freeze.
