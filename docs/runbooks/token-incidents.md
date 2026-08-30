# Runbook: Token incidents

## Scope

Invitation tokens (`upr_tokens`, purpose `invite`), form sessions (purpose `form_session`), and M14 edit sessions (purpose `edit_session`). Authoritative M2 behaviour: [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md). M14 guest-edit proof: [`../roadmap/m14-customer-seven-day-review-edits.md`](../roadmap/m14-customer-seven-day-review-edits.md).

## Incidents

| Incident | Response |
|----------|----------|
| Customer reports link "already used" without submitting | Check `redeemed_at` — must be NULL until successful submit; investigate premature redeem |
| Token expired | Reminder path issues a fresh 30d invite token and revokes the prior token + child sessions |
| Suspected token leak | Revoke tokens for affected order items; rotate WP auth salt only with full invalidation awareness |
| Replay attack | Verify single-winner redeem and one `review_comment_id` |
| Session abandoned | Customer may reopen invite link (open ≠ redeem) until invite expiry/revoke |
| Token appears in access logs | Host must redact `/upr-review/{token}/`; treat as leak if logs retained |
| Guest cannot edit after successful submit | Expected until M14: completion sets `redeemed_at` only; `find_active_by_raw` stays null. After M14: same secret may mint `edit_session` only when E3 tuple+window hold |
| Suspected leak of a **redeemed** invite during the 7-day edit window | **Security revoke** that token id with `TokenRepository::revoke( $id )` (sets `revoked_at` even when `redeemed_at` is set). That **does** kill M14 edit. Do **not** use `revoke_for_item` / `revoke_all_outstanding` for this — those skip redeemed rows by design |

## Revocation triggers

- Partial/full refund of line item
- Order cancel
- Reminder issued (prior active invite revoked + child sessions)
- Compliance / adapter suppress / opt-out
- Product discontinued / not reviewable
- Successful redeem (`redeemed_at` only — **does not** set `revoked_at`; **does not** kill M14 completed-invite edit)
- Token-incident / explicit **security revoke** of a specific token id (`TokenRepository::revoke( $id )` — **does** kill M14 edit even if redeemed)
- Manual operator revoke (admin UI is M4+)

Mass `revoke_for_item` / `revoke_all_outstanding` remain **unredeemed-only**. Post-complete item suppress does **not** revoke the redeemed invite (M14 E15: existing-comment edit may continue).

## Diagnostics

- Inspect token row: `purpose`, `expires_at`, `redeemed_at`, `revoked_at`, `order_item_id`, `parent_token_id`, `product_id`
- Correlate with audit log entries (never expect raw secrets in audit)

## Security

- Raw tokens/session secrets never stored — only HMAC-SHA-256 (`hash_hmac` with `wp_salt('auth')`)
- Do not log full tokens in application logs
- Token exchange returns `303` + `Referrer-Policy: no-referrer`; form URLs are token-free
- Cookie: `__Host-upr_review_session` on HTTPS (`Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, no `Domain`)
- **Host duty:** redact or exclude `/upr-review/{token}/` from web-server access logs
- Auth-salt rotation invalidates **all** outstanding tokens and sessions (including M14 completed-invite HMAC match and in-flight claim HMACs)

## Related

- [`invitation-failures.md`](invitation-failures.md)
- [`reconciliation.md`](reconciliation.md)
- [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md)
- [`../roadmap/m14-customer-seven-day-review-edits.md`](../roadmap/m14-customer-seven-day-review-edits.md)
