# Runbook: Moderation queue

## Scope

Product reviews in `hold` (pending) state awaiting human approval.

## M1 operation (native WordPress Comments)

M1 has no dedicated UPR admin queue (M4+). Moderators use the standard WordPress **Comments** admin:

1. Filter or identify comments with type **review** on **product** posts.
2. All **new** product reviews enter **Pending** automatically (UPR `pre_comment_approved` hold).
3. **Approve** genuine reviews — including negative reviews.
4. Do not reject for rating or sentiment alone.

### Interim guest policy (M1 / M2)

- **Guests cannot submit** product reviews through the native WooCommerce PDP (UPR `preprocess_comment` guard).
- Guest invitation submission is specified in **M2** ([`../milestones/M2-invitations.md`](../milestones/M2-invitations.md)); runtime ships only after `m2-invitations-freeze`.
- Customer-facing explanation of unavailable forms is **M3** (host adapter); M1/M2 enforcement for native PDP is core-only.

### Global comment settings

UPR does **not** change `comment_moderation` or `comment_whitelist`. Non-review comments follow existing site policy.

## Access (by milestone)

| Milestone | Interface |
|-----------|-----------|
| M1 | WordPress **Comments** admin (product reviews, pending) |
| M2 | Same Comments admin; invitation-submitted reviews still enter Pending |
| M4+ | UPR **Reviews → Queue** admin screen (future) |

## Procedures

### Triage new review

1. Open pending review; verify verified-purchase linkage (native WC meta / order item meta when present).
2. **Approve** if genuine product experience — including negative reviews.
3. **Spam** only with reason code if high-certainty match (M4+ deterministic rules).
4. **Hold** if uncertain — do not reject for rating or sentiment.

### Bulk actions

Require reason code in audit log when UPR audit exists (M2+ audit table; bulk UI is M4+). Never bulk-delete pending reviews.

### Escalation

Regulatory or compliance flags → legal/ops queue per host policy.

## Metrics

- Pending count and age
- Moderation SLA (target: >90% within 48h during pilot)

## Related

- [`../milestones/M1-core-enablement.md`](../milestones/M1-core-enablement.md)
- [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md)
- [`retention.md`](retention.md)
- [`ai-outage.md`](ai-outage.md)
