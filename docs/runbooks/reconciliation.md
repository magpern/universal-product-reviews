# Runbook: Reconciliation

## Purpose

Backfill missed invitation schedules when delivery events were missed (plugin downtime, adapter gaps).

## Command

```bash
wp upr reconcile-invitations [--lookback-days=90] [--dry-run]
```

## When to run

- After plugin upgrade or activation on site with order history
- After delivery adapter deployment
- Nightly AS job `upr_reconcile_invitations` (automatic, M2+)
- Manual run after incident recovery

## Procedure

1. Run with `--dry-run`; review proposed schedules.
2. Confirm count aligns with expected delivered orders in look-back window.
3. Run without `--dry-run`.
4. Verify idempotency — second run schedules nothing new.

## Filters

Delivery adapter must implement `upr_is_order_delivered` for accurate reconciliation.

## Related

- [`invitation-failures.md`](invitation-failures.md)
