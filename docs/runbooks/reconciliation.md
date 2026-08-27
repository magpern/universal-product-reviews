# Runbook: Reconciliation

## Purpose

Backfill missed invitation schedules, recover abandoned send claims, revoke ineligible items, and repair orphaned review associations. Authoritative behaviour: [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md).

## Admin UI

**WooCommerce → Product Reviews → Controls** offers reconcile dry-run / apply (with confirmation) and a controlled database upgrade. Overview shows the last `reconcile.completed` audit row or **No recorded run.** See [`operator-controls.md`](operator-controls.md).

## Commands

```bash
wp upr reconcile-invitations [--lookback-days=90] [--dry-run]
wp upr db-upgrade
```

## When to run

- After plugin upgrade or activation on a site with order history
- After delivery adapter deployment
- Nightly Action Scheduler job `upr_reconcile_invitations` (automatic in M2 runtime)
- Manual run after incident recovery
- When schema version lags (Controls → DB upgrade, or `wp upr db-upgrade`) — never on ordinary admin page load

## Procedure

1. Run dry-run (Controls or `--dry-run`); review the summary.
2. Confirm counts align with expected delivered/completed orders in the look-back window.
3. Apply (Controls with confirmation, or CLI without `--dry-run`).
4. Verify idempotency — second run schedules nothing unexpected.

### Dry-run rules

`--dry-run` must perform **zero writes**, including **no audit rows**. Output is stdout only. Audit `reconcile.completed` is written only on non-dry-run executions.

## Repair themes

- Missing invite rows for eligible line items
- Abandoned `initial_sending` / `reminder_sending` claims past stale window
- Refunded/cancelled/opted-out/not-reviewable items → suppress + revoke tokens/sessions
- Orphaned comments with association meta but incomplete UPR state → attach `review_comment_id` and complete; **never** re-invite

## Filters

Delivery adapter must implement `upr_is_order_delivered` for accurate reconciliation. Support adapter may return `delay` / `suppress` via `upr_review_invitation_action`.

## Related

- [`operator-controls.md`](operator-controls.md)
- [`invitation-failures.md`](invitation-failures.md)
- [`token-incidents.md`](token-incidents.md)
- [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md)
