# Runbook: AI provider outage and privacy gate

## Scope

AI-assisted moderation triage (M6+, optional). **Disabled by default.**

## Privacy gate (hard stop)

Do **not** enable external AI until:

- DPIA approved for review text processing
- Provider data retention / zero-retention contract documented
- Maintainer explicit GO

Until then: deterministic rules and human moderation only.

## AI disabled / outage

When AI shadow mode is off or provider unavailable:

- All reviews remain in standard moderation queue
- No automated action from AI
- Deterministic spam rules continue
- No external review text transmission

## AI enabled — provider outage

1. UPR treats AI as unavailable — flags not updated
2. No auto-spam from AI paths
3. Monitor provider status page
4. Resume shadow mode when service restored; review backlog normally

## Shadow mode (first AI phase)

- AI outputs stored as flags only
- **Zero** automated approve/spam/delete from AI
- Operators compare flags to human decisions for calibration

## Escalation

PII or health-related content in reviews → human moderation regardless of AI status.

## Related

- [`moderation.md`](moderation.md)
- `ARCHITECTURE.md` §9
