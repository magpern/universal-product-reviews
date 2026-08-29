# Runbook: AI moderation outage and privacy gate

## Scope

Optional AI-assisted moderation triage. Authoritative planning: [`../roadmap/m8-ai-assisted-moderation-planning.md`](../roadmap/m8-ai-assisted-moderation-planning.md), [`../roadmap/m9-local-ai-shadow-mode.md`](../roadmap/m9-local-ai-shadow-mode.md), [`../roadmap/m10-external-ai-advisory-assessments.md`](../roadmap/m10-external-ai-advisory-assessments.md), [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md).

**Runtime status:**

- **M9** local shadow is **implemented** on `main` (disabled by default; built-in-only).
- **M10** external OpenAI advisory is **implemented and closed** on `main` after corrective PRs **#55–#57** (external opt-in **off**; provider default **`local`**; host-only credentials). SemVer / Release / ZIP / DEV-prod enablement remain **deferred** pending a separate privacy/governance/provider-limit GO. See [`../roadmap/m10-external-ai-advisory-assessments-closure.md`](../roadmap/m10-external-ai-advisory-assessments-closure.md).
- **M11** recommendation-only guidance: freeze [`../roadmap/m11-ai-moderation-recommendations.md`](../roadmap/m11-ai-moderation-recommendations.md). **Never** auto-approves / auto-spams / mutates comment status. Actionable labels only while status is `hold`.
- **M12** (auto-action) remains unimplemented and separately gated.

## Privacy gate

### External processing (M10)

Do **not** enable external AI in any environment until all of the following are true:

1. Documented processor/privacy terms with OpenAI.
2. OpenAI project retention/privacy posture configured.
3. Dedicated OpenAI project/service account with **provider-side** spend and rate limits (mandatory — plugin caps alone cannot defend a compromised administrator or leaked secret).
4. Operator acknowledgement that review text may contain personal data (Controls requires these server-side acks to enable).
5. Maintainer explicit GO.

UPR does **not** treat a “DPIA done” checkbox as machine-enforced proof of compliance. Machine gates: shadow + external opt-in default **off**; server-side confirms/acks; fail-closed OpenAI (no silent local fallback).

### Secrets

Provider API keys must live in the `UPR_OPENAI_API_KEY` PHP constant or environment variable (constant wins). **Never** store keys in UPR options, audit, diagnostics, support export, logs, or exceptions. Controls / Site Health / Diagnostics show **present/absent** and source (`constant` \| `environment` \| `missing`) only.

Until external AI is enabled: deterministic rules and human moderation only; M9 keeps review text inside UPR core (no outbound HTTP outside the allowlisted OpenAI client path).

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

## External AI outage / misconfiguration (M10)

When provider=`openai` and any of the following hold — **fail closed** (terminal allowlisted `failed`/`skipped`; **never** silent local assessor):

| Condition | Typical terminal |
|-----------|------------------|
| External AI disabled | `failed` / `provider_unavailable` |
| Credential missing | `failed` / `credential_missing` |
| Daily/monthly quota exhausted | `skipped` / `budget_exceeded` (+ claim cleared) |
| Model / input / incomplete / unavailable / validation | `failed` + typed code |

Disabling external AI fails closed for **new** external work and **silently** clears **only** in-flight claims stamped `claim_provider_kind=openai` (no terminal assessment row, no AI audit). Claims acquired as `local` are preserved even if the selected provider option later changes. The worker binds each attempt to the **immutable** stamped claim provider kind after acquisition and does not re-read the live provider option for that attempt. Transactional finalisation re-checks the locked claim’s provider kind against the attempt and, on mismatch, clears without writing a terminal assessment. Disabling **local shadow** retains M9 precedence: silent revoke of in-flight claims without terminal AI rows where locked. OpenAI re-analysis is refused (and the Comments control is hidden) while external AI is disabled.

### Test connection

Controls → OpenAI test connection: paid **synthetic** payload only; consumes **external** quota; must **not** consume M9 site rate or trip the circuit. Confirmation + `manage_woocommerce` required.

## Circuit breaker and rate limit (M9)

Site-wide ops state lives in `{prefix}upr_moderation_ops` with atomic updates. Rate slots are consumed **only after** claim acquisition and **immediately before** assessor work (local or OpenAI). External daily/monthly quotas are a **separate** row (`upr_moderation_external_ops`) and apply only to OpenAI outbound attempts (including test connection).

## Non-held comments

Once a review is approved, spammed, or trashed: no new assessment jobs and no re-analysis. **M11:** hide actionable recommendation labels and reason badges; retain assessment rows for audit/retention only (optional non-actionable “historical” marker only).

- Shadow **enabled** + active claim → terminal `skipped` / `ineligible_comment` + clear claim + AI audit
- Shadow **disabled** → clear claim silently; retention recompute only; no new row; no AI audit

## Shadow / external / recommendation modes

- Provider enum exactly **`local` \| `openai`** — no provider filter / class override
- OpenAI: Responses API only, `store: false`, no tools / conversation chaining
- AI outputs stored as terminal assessment rows only (when enabled paths complete)
- **Zero** automated approve / spam / delete from AI (M11 recommendations never mutate status)
- Operators compare advisory output / M11 recommendations to human decisions for calibration
- Sentiment fairness is **not** proven by unit tests — requires a governed calibration set before **M12** auto-action

## Escalation

PII or health-related content in reviews → human moderation regardless of AI status.

## Related

- [`moderation.md`](moderation.md)
- [`moderation-capabilities.md`](moderation-capabilities.md)
- [`operator-controls.md`](operator-controls.md)
- [`../roadmap/m9-local-ai-shadow-mode.md`](../roadmap/m9-local-ai-shadow-mode.md)
- [`../roadmap/m10-external-ai-advisory-assessments.md`](../roadmap/m10-external-ai-advisory-assessments.md)
- [`../roadmap/m10-external-ai-advisory-assessments-closure.md`](../roadmap/m10-external-ai-advisory-assessments-closure.md)
- [`../roadmap/m11-ai-moderation-recommendations.md`](../roadmap/m11-ai-moderation-recommendations.md)
- [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md) (M12 appendix)
- `ARCHITECTURE.md` §9
