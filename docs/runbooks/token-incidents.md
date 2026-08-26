# Runbook: Token incidents

## Scope

Invitation tokens (`upr_tokens`, purpose `invite`) and form sessions (purpose `form_session`). Authoritative behaviour: [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md).

## Incidents

| Incident | Response |
|----------|----------|
| Customer reports link "already used" without submitting | Check `redeemed_at` — must be NULL until successful submit; investigate premature redeem |
| Token expired | Reminder path issues a fresh 30d invite token and revokes the prior token + child sessions |
| Suspected token leak | Revoke tokens for affected order items; rotate WP auth salt only with full invalidation awareness |
| Replay attack | Verify single-winner redeem and one `review_comment_id` |
| Session abandoned | Customer may reopen invite link (open ≠ redeem) until invite expiry/revoke |
| Token appears in access logs | Host must redact `/upr-review/{token}/`; treat as leak if logs retained |

## Revocation triggers

- Partial/full refund of line item
- Order cancel
- Reminder issued (prior active invite revoked + child sessions)
- Compliance / adapter suppress / opt-out
- Product discontinued / not reviewable
- Successful redeem
- Manual operator revoke (admin UI is M4+)

## Diagnostics

- Inspect token row: `purpose`, `expires_at`, `redeemed_at`, `revoked_at`, `order_item_id`, `parent_token_id`, `product_id`
- Correlate with audit log entries (never expect raw secrets in audit)

## Security

- Raw tokens/session secrets never stored — only HMAC-SHA-256 (`hash_hmac` with `wp_salt('auth')`)
- Do not log full tokens in application logs
- Token exchange returns `303` + `Referrer-Policy: no-referrer`; form URLs are token-free
- Cookie: `__Host-upr_review_session` on HTTPS (`Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, no `Domain`)
- **Host duty:** redact or exclude `/upr-review/{token}/` from web-server access logs
- Auth-salt rotation invalidates **all** outstanding tokens and sessions

## Related

- [`invitation-failures.md`](invitation-failures.md)
- [`reconciliation.md`](reconciliation.md)
- [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md)
