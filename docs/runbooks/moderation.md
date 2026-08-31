# Runbook: Review moderation (native Comments admin)

## Scope

UPR product reviews are WordPress comments with `comment_type=review` on `product` posts. Moderators work in the **native WordPress Comments** screen (`edit-comments.php`). M5 enhances that screen with context columns and filters — it does **not** add a parallel UPR moderation queue.

## Operator workflow

1. Open **Comments** in wp-admin.
2. Use UPR views: **UPR product reviews** / **UPR pending**, and optionally source filter **Invitation-linked** or **All UPR product reviews**.
3. WooCommerce’s review-type selector remains available and combines with UPR filters (AND).
4. Columns show Product, Rating, Source (`Invitation-linked` | `Unlinked/unknown`), Order (order link only when object-level edit capability passes), and **AI advisory** (M9–M11).
5. **Approve** genuine reviews — including negative reviews. Do not reject for rating or sentiment alone.
6. Use native Approve / Unapprove / Spam / Trash. There is no UPR bulk-spam-reason UI in M5. **M11** may show allowlisted recommendation labels (risk score: higher = greater publication risk) **only while the review is Pending (`hold`)**. Recommendations are advisory — humans must approve. Leaving Pending hides actionable labels; assessments remain for audit. **M13** adds held-only recommendation **filters** for triage (still advisory). **M12** `auto_spam_held_technical` exists on `main` with masters **default off**; auto-approve is permanently excluded; production enablement requires Calibration GO + separate approval. See [`m13-operator-ai-command-surface.md`](../roadmap/m13-operator-ai-command-surface.md) and [`m12-simulation-implementation-closure.md`](../roadmap/m12-simulation-implementation-closure.md).

## Hold policy

- New **top-level** product reviews that WordPress would approve are forced to **Pending** by UPR.
- Validated **staff replies** via the native Comments reply AJAX action (`replyto-comment` + verified nonce + caps + depth-one) are exempt from that downgrade; core’s approval result is passed through unchanged (never force-approve).
- Guest native PDP submission and invitation submit guards are unchanged (M1–M3).

## Editing

Native WordPress **Edit Comment** remains the operator path (`moderate_comments`). Customers may self-edit in-window via `/upr-review/edit/` (M14). Successful approve edits return to **Pending**. Operator spam/trash during finalisation is not overwritten. See [`moderation-capabilities.md`](moderation-capabilities.md) and [`../roadmap/m14-customer-seven-day-review-edits.md`](../roadmap/m14-customer-seven-day-review-edits.md).

## Audit

In-scope status transitions are audited:

| Origin | Event |
|--------|-------|
| Operator (`moderate_comments` in admin) | `review.status_changed` |
| UPR `SystemStatusOrigin` → spam | `review.system_spam` |
| UPR `AiActionOrigin` → spam (M12, when enabled) | `review.ai_auto_spam` |
| Other UPR / CLI / cron / plugin | `review.system_status_changed` |

Validated staff replies also emit `review.reply_posted`.

Payloads are allowlisted operational IDs only (no comment body, email, token, URL, or direct customer PII). Claim / lease tokens must never appear in audit payloads.

**M12 crash reconciliation:** If diagnostics show `unknown_after_crash` with AI CAS evidence, treat as **critical**: verify current comment status and third-party side effects manually; do **not** expect UPR to replay WordPress transition hooks. Prefer native Not spam / re-moderate as needed. See [`m12-guarded-auto-spam.md`](../roadmap/m12-guarded-auto-spam.md) §7.

**Retention:** M5 adds **no** audit TTL or purge behaviour.

## Support export

Unchanged (`upr-support-export/v1`). No new M5 fields. Order IDs remain absent from support export.

## Related

- [`moderation-capabilities.md`](moderation-capabilities.md)
- [`../roadmap/m5-review-moderation-operations.md`](../roadmap/m5-review-moderation-operations.md)
- [`../roadmap/m11-ai-moderation-recommendations.md`](../roadmap/m11-ai-moderation-recommendations.md)
- [`../roadmap/m12-guarded-auto-spam.md`](../roadmap/m12-guarded-auto-spam.md)
- [`support-export.md`](support-export.md)
- [`retention.md`](retention.md)
- [`ai-outage.md`](ai-outage.md)
