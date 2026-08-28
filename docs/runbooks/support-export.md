# Support-safe export contract

Local JSON download from **Controls → Download support export**.

| Rule | Value |
|------|--------|
| Schema | `upr-support-export/v1` (fixed in code) |
| Window | Default **7 days** aggregate evidence |
| Capability | `manage_woocommerce` + nonce |
| Delivery | Browser download only — no email, no remote POST |

**M5:** Support export is **unchanged**. No new moderation fields.

## Allowlisted fields

- Plugin / schema version strings and control booleans (emails enabled, pause, boundary-set).
- Diagnostic IDs + statuses + severities + evidence codes (no free-text dumps beyond fixed messages already in diagnostics).
- Aggregate counts: lifecycle-by-state, `email.failed`, stale/expired claim counts.
- Last `reconcile.completed` counters + timestamp **or** `no_recorded_run`.

## Forbidden (must never appear)

Order IDs, order item IDs, emails, tokens / hashes, invite URLs, cookies, comment bodies, raw audit payloads, product names, Action Scheduler arguments, free-text errors.

See also [`operator-controls.md`](operator-controls.md), [`moderation-capabilities.md`](moderation-capabilities.md), and the M4/M5 freezes.
