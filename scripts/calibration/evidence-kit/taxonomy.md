# M12 human labelling taxonomy

Use these labels only. They are privacy-safe category codes for calibration — not WordPress comment statuses.

## Labels

| Code | Meaning | Primary stratum |
|------|---------|-----------------|
| `technical_spam` | Clear **technical** spam / scam / link abuse / fraud / impersonation patterns that a careful human would mark spam without relying on sentiment | `technical_spam` |
| `not_spam` | Legitimate **negative or critical** product review that should **not** be treated as spam (human-not-spam) | `legitimate_negative` |
| `mandatory_human` | Content that requires mandatory human review (e.g. suspected PII, medical/regulatory/safety claims, threats) — never auto-action | `mandatory_human` |
| `excluded` | Out of scope / unusable / ambiguous after adjudication / wrong language unsupported / not a product review | `excluded` |

## Hard rule — negative ≠ spam

The following are **never** `technical_spam` merely because they are negative:

- Criticism of product quality, shipping, support, price, or brand
- Low star ratings or disappointed tone
- Sentiment (anger, sarcasm, disappointment)
- Disagreement with marketing claims
- Competitor comparison
- Abuse **allegations** that need human judgement (prefer `mandatory_human` when policy holds apply)
- Unverifiable claims that are not technical spam patterns

`technical_spam` requires concrete spam/scam/abuse-of-channel signals (e.g. unrelated promotional flooding, malware/phishing links, obvious fraud/impersonation patterns), **not** “customer is unhappy”.

## Double-labelling

- Blind overlap ≥ 20% of the **combined primary** corpus (`not_spam` + `technical_spam`).
- Record `labeler_a` / `labeler_b` and `label_a` / `label_b` (opaque reviewer ids).
- On disagreement: record `adjudicator` + `adjudicated_label` before the row is final.
- Final `human_label` must equal the adjudicated (or agreed) label and match the stratum.

## Assessment fields in the privacy-safe export

Only store allowlisted assessment maps used by the offline would-act conjunction:

- `state`, `confidence`, `publication_safety_score`, `reason_codes`

Do **not** export review text beside labels in the Git-bound evidence JSON.
