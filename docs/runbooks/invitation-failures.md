# Runbook: Invitation delivery failures

## Symptoms

- Customer did not receive review invitation email
- AS job `upr_send_initial_bundle` or `upr_send_reminder_bundle` failed
- Mail transport errors in host logs

## Diagnosis

1. Check invite item `schedule_state`, send-claim fields, and `suppression_code` (M2 tables; admin UI is later).
2. Verify order item not suppressed or delayed (`suppression_code`, `delay_until`).
3. Check Action Scheduler for failed jobs in group `upr`.
4. Verify host mail transport (non-production must use logging transport).
5. Confirm customer opt-out meta not set.
6. Confirm product still reviewable (discontinued products revoke invitations).

## Remediation

1. Fix mail transport if broken.
2. Run reconciliation: `wp upr reconcile-invitations --dry-run` then without `--dry-run` (`--dry-run` = zero writes).
3. Re-send reminder only if initial was sent and item still unreviewed — respects one-reminder policy and claim/`message_id` semantics (at-least-once, not exactly-once).

## Prevention

- Monitor AS failure rate
- Alert on mail transport failures
- Validate delivery adapter firing `upr_order_delivery_confirmed`

## Related

- [`reconciliation.md`](reconciliation.md)
- [`token-incidents.md`](token-incidents.md)
- [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md)
