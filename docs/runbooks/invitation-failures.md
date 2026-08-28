# Runbook: Invitation delivery failures

## Symptoms

- Customer did not receive review invitation email
- AS job `upr_send_initial_bundle` or `upr_send_reminder_bundle` failed
- Mail transport errors in host logs

## Diagnosis

1. Prefer **WooCommerce → Product Reviews → Diagnostics / Overview** (see [`operator-controls.md`](operator-controls.md)) for D1–D11, open workload, and `email.failed` counts — no PII.
2. Check invite item `schedule_state`, send-claim fields, and `suppression_code` (M2 tables) when deeper inspection is needed.
3. Verify order item not suppressed or delayed (`suppression_code`, `delay_until`).
4. Check Action Scheduler for failed jobs in group `upr` (Diagnostics D5/D6; WooCommerce → Status → Scheduled Actions).
5. Verify host mail transport (non-production must use logging transport).
6. Confirm customer opt-out meta not set.
7. Confirm product still reviewable (discontinued products revoke invitations).

## Remediation

1. Fix mail transport if broken.
2. Run reconciliation from **Controls** (dry-run then apply with confirmation) or CLI: `wp upr reconcile-invitations --dry-run` then without `--dry-run` (`--dry-run` = zero writes).
3. Re-send reminder only if initial was sent and item still unreviewed — respects one-reminder policy and claim/`message_id` semantics (at-least-once, not exactly-once). No mint/resend from the admin UI.

## Prevention

- Monitor AS failure rate
- Alert on mail transport failures
- Validate delivery adapter firing `upr_order_delivery_confirmed`

## Related

- [`operator-controls.md`](operator-controls.md)
- [`reconciliation.md`](reconciliation.md)
- [`token-incidents.md`](token-incidents.md)
- [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md)
