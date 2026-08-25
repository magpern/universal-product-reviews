# Runbook: Token incidents

## Scope

Signed invitation tokens (`upr_tokens`) and form sessions.

## Incidents

| Incident | Response |
|----------|----------|
| Customer reports link "already used" without submitting | Check `redeemed_at` — should be NULL until submit; investigate premature redeem bug |
| Token expired | Issue path: reminder email with fresh 30d token (M2+) |
| Suspected token leak | Revoke tokens for affected order items; rotate if systemic |
| Replay attack | Verify idempotency and single-use redeem on submit |
| Session abandoned | Customer may reopen link — token not consumed on view |

## Revocation triggers

- Partial/full refund of line item
- Order cancel
- Reminder issued (prior active token revoked)
- Compliance suppress
- Manual operator revoke (M2+ admin)

## Diagnostics

- Inspect token row: `expires_at`, `redeemed_at`, `revoked_at`, `order_item_id`
- Correlate with audit log entries

## Security

- Raw tokens never stored — only `token_hash`
- Do not log full tokens in application logs

## Related

- [`invitation-failures.md`](invitation-failures.md)
