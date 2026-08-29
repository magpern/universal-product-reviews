# M10 WP5 — regression and policy evidence (pre-closure)

**Status:** Implementation evidence for WP5. Not a SemVer release, GitHub Release, ZIP, or enablement authorisation.  
**Baseline:** `main` after WP1–WP4 merges; freeze tag `m10-external-ai-advisory-assessments-freeze` → `2785d53c80e45e7fa80b433fee242d363ea55208`.

## Policy guards (CI)

`scripts/ci/check.sh` enforces:

- Network primitives in `src/Ai/` only under `src/Ai/OpenAi/` via `wp_remote_post`
- Forbidden: curl/sockets, other `wp_remote_*`, provider filter hooks (`upr_local_moderation_assessment_provider`, `upr_moderation_assessment_provider`, `apply_filters` moderation-provider patterns)
- Support export schema remains `upr-support-export/v1`
- C19 registered in stable contracts inventory
- Plugin version remains **`0.8.0`** (no M10 SemVer bump in implementation)

## Automated coverage (representative)

| Area | Tests |
|------|--------|
| Provider enum / Options / ProviderError | `M10AiProviderUnitTest`, `M10ProviderResolverUnitTest` |
| OpenAI client (`store:false`, injection, credentials) | `M10OpenAiClientUnitTest` |
| External quotas | `M10ExternalQuotaIntegrationTest` |
| Worker fail-closed / budget / no local fallback | `M10WorkerOpenAiIntegrationTest` |
| Enablement acks / re-analysis caps / test connection | `M10ExternalAiControlsUnitTest`, `M10ExternalControlsIntegrationTest` |
| M9 regression | Existing `M9*` unit/integration suites |

## Runbooks updated in WP5

- [`../runbooks/ai-outage.md`](../runbooks/ai-outage.md)
- [`../runbooks/operator-controls.md`](../runbooks/operator-controls.md)
- [`../runbooks/moderation-capabilities.md`](../runbooks/moderation-capabilities.md)
- [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md) §9 AI

## Explicit non-actions (still)

No DEV/production WordPress access; no real OpenAI API key configured for customer traffic; no SemVer bump; no `v0.8.1` / `v0.9.0`; no Release/ZIP; external AI left **disabled** by default.
