# Runbook: AI moderation outage and privacy gate

## Scope

Optional AI-assisted moderation triage. Authoritative planning: [`../roadmap/m8-ai-assisted-moderation-planning.md`](../roadmap/m8-ai-assisted-moderation-planning.md), [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md).

**Runtime status:** Not implemented on current `main`. M9 (local shadow), M10 (external), and M11 (auto-approval) each require separate authorisation. **Disabled by default** when implemented.

## Privacy gate

### External processing (M10+)

Do **not** enable external AI until the host has completed its own DPIA / processor terms process and maintainer GO. A documented DPIA is a **human/process** obligation — UPR does **not** treat a “DPIA done” checkbox as a machine-enforced proof of compliance. Machine gates are: feature enable + external opt-in default **off**.

### Secrets

Provider API keys must live in host environment variables or `wp-config.php` constants read by host adapters. **Never** store keys in UPR options, audit, diagnostics, or support export.

Until AI is enabled: deterministic rules and human moderation only; no review-text transmission off-host (M9 is local-only by design).

## AI disabled / local outage (M9)

When local shadow is off, no provider is registered, the circuit breaker is open, or the cooperative deadline discards a late result:

- All reviews remain in the standard **hold** moderation queue
- **No** automated approve / spam / delete from AI
- Ordinary M5 Comments-admin moderation continues
- No external review text transmission

## Circuit breaker and rate limit (M9 design)

Site-wide ops state lives in `{prefix}upr_moderation_ops` with atomic updates (not option read-modify-write). When the circuit is open or the hourly rate limit is hit, queued jobs for **held** reviews may produce `skipped` rows (`circuit_open` / `rate_limited`) without calling the provider.

## Non-held comments

Once a review is approved, spammed, or trashed: no new assessment jobs and no re-analysis. Historical advisory may remain visible. Active claims must be **revoked** (terminal `skipped` / `ineligible_comment` + clear claim token), not merely expired.

## Shadow mode (M9 / M10)

- AI outputs stored as terminal assessment rows only
- **Zero** automated approve / spam / delete from AI
- Operators compare advisory output to human decisions for calibration
- Sentiment fairness is **not** proven by unit tests — requires a governed calibration set before M11

## Escalation

PII or health-related content in reviews → human moderation regardless of AI status.

## Related

- [`moderation.md`](moderation.md)
- [`moderation-capabilities.md`](moderation-capabilities.md)
- [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md) (M11 appendix)
- `ARCHITECTURE.md` §9
