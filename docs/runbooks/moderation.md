# Runbook: Moderation queue

## Scope

Product reviews in `hold` (pending) state awaiting human approval.

## Access

- WordPress **Comments** admin filtered to product reviews (M1+).
- UPR **Reviews → Queue** admin screen (M4+).

## Procedures

### Triage new review

1. Open pending review; verify verified-purchase linkage.
2. Check deterministic flags (regulatory keywords, PII spans, link count).
3. **Approve** if genuine product experience — including negative reviews.
4. **Spam** only with reason code if high-certainty deterministic match.
5. **Hold** if uncertain — do not reject for rating or sentiment.

### Bulk actions

Require reason code in audit log. Never bulk-delete pending reviews.

### Escalation

Regulatory or compliance flags → legal/ops queue per host policy.

## Metrics

- Pending count and age
- Moderation SLA (target: >90% within 48h during pilot)

## Related

- [`retention.md`](retention.md)
- [`ai-outage.md`](ai-outage.md)
