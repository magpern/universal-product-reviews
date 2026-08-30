# Runbook: Retention and purge incidents

## Policy

Product review spam/trash retained **30 days** (default) before authorized purge via `upr_purge_spam` inside `PurgeContext` (future retention milestone — not implemented in M5).

**M5 note:** Moderation **audit** rows have **no** TTL or purge behaviour in M5. Do not expect automatic audit cleanup from this milestone.

**M9+ assessment retention:** Terminal rows in `{prefix}upr_moderation_assessments` use `retention_due_at`. Recurring Action Scheduler hook `upr_purge_moderation_assessments` (group `upr`, internal inventory only) calls `AssessmentRepository::purge_due()`. **D21** reports due_count and last successful purge age (privacy-safe). The last-purge option is stamped **only** after a successful purge execution — never from Overview, would-act, or CLI reporting.

## Symptoms

- Review spam deleted earlier than retention window
- Disk growth from retained spam
- Purge job failures
- D21 warning/critical for assessment retention backlog or stale purge

## Diagnosis

1. Confirm `pre_delete_comment` guard active (M4+).
2. Check if third-party spam service deleted comments — should be blocked for product reviews.
3. Review AS schedule for `upr_purge_spam` and `upr_purge_moderation_assessments`.
4. Inspect `PurgeContext` — only purge job should delete protected comments.
5. For assessments: Diagnostics **D21** (due_count / purge age only).

## Remediation

### Premature deletion suspected

1. Restore from database backup if available.
2. Audit plugin and spam service configuration.
3. Re-run retention guard tests on staging.

### Purge job stuck

1. Check AS failed actions in group `upr`.
2. Manually trigger purge in staging with monitoring.
3. Verify comment ages exceed retention threshold.
4. For assessments: confirm recurring `upr_purge_moderation_assessments` is scheduled when public AS APIs are present.

## Manual exceptional purge

Requires elevated capability and audit entry — not automated.

## Related

- [`moderation.md`](moderation.md)
