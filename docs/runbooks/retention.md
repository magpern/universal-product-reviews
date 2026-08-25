# Runbook: Retention and purge incidents

## Policy

Product review spam/trash retained **30 days** (default) before authorized purge via `upr_purge_spam` inside `PurgeContext`.

## Symptoms

- Review spam deleted earlier than retention window
- Disk growth from retained spam
- Purge job failures

## Diagnosis

1. Confirm `pre_delete_comment` guard active (M4+).
2. Check if third-party spam service deleted comments — should be blocked for product reviews.
3. Review AS schedule for `upr_purge_spam`.
4. Inspect `PurgeContext` — only purge job should delete protected comments.

## Remediation

### Premature deletion suspected

1. Restore from database backup if available.
2. Audit plugin and spam service configuration.
3. Re-run retention guard tests on staging.

### Purge job stuck

1. Check AS failed actions in group `upr`.
2. Manually trigger purge in staging with monitoring.
3. Verify comment ages exceed retention threshold.

## Manual exceptional purge

Requires elevated capability and audit entry — not automated.

## Related

- [`moderation.md`](moderation.md)
