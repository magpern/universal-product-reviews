# Runbook: Review moderation (native Comments admin)

## Scope

UPR product reviews are WordPress comments with `comment_type=review` on `product` posts. Moderators work in the **native WordPress Comments** screen (`edit-comments.php`). M5 enhances that screen with context columns and filters — it does **not** add a parallel UPR moderation queue.

## Operator workflow

1. Open **Comments** in wp-admin.
2. Use UPR views: **UPR product reviews** / **UPR pending**, and optionally source filter **Invitation-linked** or **All UPR product reviews**.
3. WooCommerce’s review-type selector remains available and combines with UPR filters (AND).
4. Columns show Product, Rating, Source (`Invitation-linked` | `Unlinked/unknown`), and Order (order link only when object-level edit capability passes).
5. **Approve** genuine reviews — including negative reviews. Do not reject for rating or sentiment alone.
6. Use native Approve / Unapprove / Spam / Trash. There is no UPR bulk-spam-reason UI in M5.

## Hold policy

- New **top-level** product reviews that WordPress would approve are forced to **Pending** by UPR.
- Validated **staff replies** via the native Comments reply AJAX action (`replyto-comment` + verified nonce + caps + depth-one) are exempt from that downgrade; core’s approval result is passed through unchanged (never force-approve).
- Guest native PDP submission and invitation submit guards are unchanged (M1–M3).

## Editing

UPR adds **no** review-editing UX. Native WordPress **Edit Comment** remains available outside UPR.

## Audit

In-scope status transitions are audited:

| Origin | Event |
|--------|-------|
| Operator (`moderate_comments` in admin) | `review.status_changed` |
| UPR `SystemStatusOrigin` → spam | `review.system_spam` |
| Other UPR / CLI / cron / plugin | `review.system_status_changed` |

Validated staff replies also emit `review.reply_posted`.

Payloads are allowlisted operational IDs only (no comment body, email, token, URL, or direct customer PII).

**Retention:** M5 adds **no** audit TTL or purge behaviour.

## Support export

Unchanged (`upr-support-export/v1`). No new M5 fields. Order IDs remain absent from support export.

## Related

- [`moderation-capabilities.md`](moderation-capabilities.md)
- [`../roadmap/m5-review-moderation-operations.md`](../roadmap/m5-review-moderation-operations.md)
- [`support-export.md`](support-export.md)
- [`retention.md`](retention.md)
