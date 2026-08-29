# Runbook: AI moderation outage and privacy gate

## Scope

Optional AI-assisted moderation triage. Authoritative planning: [`../roadmap/m8-ai-assisted-moderation-planning.md`](../roadmap/m8-ai-assisted-moderation-planning.md), [`../roadmap/m9-local-ai-shadow-mode.md`](../roadmap/m9-local-ai-shadow-mode.md), [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md).

**Runtime status:** M9 local shadow is **implemented** on `main` (disabled by default; built-in-only). M10 external OpenAI advisory is specified by freeze [`../roadmap/m10-external-ai-advisory-assessments.md`](../roadmap/m10-external-ai-advisory-assessments.md) (implementation authorised by that freeze; SemVer/release deferred). M11 (auto-approval) remains unimplemented.

## Privacy gate

### External processing (M10+)

Do **not** enable external AI until the host has completed processor/privacy terms, configured OpenAI project retention/privacy posture, dedicated OpenAI project **provider-side** spend/rate limits, operator acknowledgement that review text may contain personal data, and maintainer GO. A documented DPIA is a **human/process** obligation — UPR does **not** treat a “DPIA done” checkbox as machine-enforced proof of compliance. Machine gates: shadow + external opt-in default **off**; server-side confirms/acks.

### Secrets

Provider API keys must live in the `UPR_OPENAI_API_KEY` PHP constant or environment variable (constant wins). **Never** store keys in UPR options, audit, diagnostics, or support export.

Until AI is enabled: deterministic rules and human moderation only; no review-text transmission off-host (M9 is local-only by design; review text stays inside UPR core `src/Ai/`).

## AI disabled / local outage (M9)

When local shadow is **off**:

- Point A is silent (no job, no row, no AI audit)
- In-flight workers clear/release claims without creating assessment rows or AI audit events
- Non-held status transitions clear claims silently and only recompute historical retention (no `skipped` row, no AI audit)
- Historical advisory rows remain retained and visible
- Ordinary M5 Comments-admin moderation continues
- **No** automated approve / spam / delete from AI
- Do **not** represent disable as `provider_unavailable`

When the circuit breaker is open or the hourly rate limit is hit (shadow **enabled**): queued jobs for **held** reviews may produce `skipped` rows (`circuit_open` / `rate_limited`) without calling the assessor.

When the cooperative deadline discards a late result (shadow still enabled, claim still owned): `failed` / `deadline_exceeded`.

## Circuit breaker and rate limit (M9)

Site-wide ops state lives in `{prefix}upr_moderation_ops` with atomic updates (not option read-modify-write). Rate slots are consumed **only after** claim acquisition and **immediately before** `assess()`.

## Non-held comments

Once a review is approved, spammed, or trashed: no new assessment jobs and no re-analysis. Historical advisory may remain visible.

- Shadow **enabled** + active claim → terminal `skipped` / `ineligible_comment` + clear claim + AI audit
- Shadow **disabled** → clear claim silently; retention recompute only; no new row; no AI audit

## Shadow mode (M9)

- Built-in in-process heuristic only; CI enforces no network primitives and no provider filter in core
- AI outputs stored as terminal assessment rows only (when enabled paths complete)
- **Zero** automated approve / spam / delete from AI
- Operators compare advisory output to human decisions for calibration
- Sentiment fairness is **not** proven by unit tests — requires a governed calibration set before M11

## Escalation

PII or health-related content in reviews → human moderation regardless of AI status.

## Related

- [`moderation.md`](moderation.md)
- [`moderation-capabilities.md`](moderation-capabilities.md)
- [`../roadmap/m9-local-ai-shadow-mode.md`](../roadmap/m9-local-ai-shadow-mode.md)
- [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md) (M11 appendix)
- `ARCHITECTURE.md` §9
