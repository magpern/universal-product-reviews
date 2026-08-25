# Runbook: Invitation delivery failures

## Symptoms

- Customer did not receive review invitation email
- AS job `upr_send_initial_bundle` or `upr_send_reminder_bundle` failed
- Mail transport errors in host logs

## Diagnosis

1. Check UPR **Reviews → Invitations** for line-item state (M2+).
2. Verify order item not suppressed or delayed (`suppression_code`, `delay_until`).
3. Check Action Scheduler for failed jobs in group `upr`.
4. Verify host `wp_mail` transport (SMTP logs).
5. Confirm customer opt-out meta not set.

## Remediation

1. Fix mail transport if broken.
2. Reschedule invitation manually from admin if supported (M2+).
3. Run reconciliation: `wp upr reconcile-invitations --dry-run` then without `--dry-run`.
4. Re-send reminder only if initial was sent and item still unreviewed — respects one-reminder policy.

## Prevention

- Monitor AS failure rate
- Alert on mail transport failures
- Validate delivery adapter firing `upr_order_delivery_confirmed`

## Related

- [`reconciliation.md`](reconciliation.md)
- [`token-incidents.md`](token-incidents.md)
