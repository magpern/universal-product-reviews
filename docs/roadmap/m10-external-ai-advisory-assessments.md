# M10 — External AI Advisory Assessments (authoritative freeze)

**Status:** Frozen M10 product and implementation specification. Authorises **implementation** of optional OpenAI-backed advisory assessments under the work packages in §12. Does **not** authorise GitHub Release, ZIP, plugin SemVer / version tag, DEV/production WordPress access, deployment, email, real OpenAI API use with customer reviews, automatic approval, or M11 behaviour.  
**Baseline:** Universal Product Reviews corrected `main` @ **`0625b215ae4a40511820c49f53e1e4fa30479cc9`** (PR [#47](https://github.com/magpern/universal-product-reviews/pull/47) — `latest_for_comments` determinism).  
**Release sequencing:** M10 planning and implementation begin from the current corrected `main` baseline. Versioning, release metadata, and any new annotated SemVer tag are deferred until the relevant development milestone is complete. Do **not** move or recreate **`v0.8.0`**.  
**Freeze tag:** `m10-external-ai-advisory-assessments-freeze` (annotated; peels to the merge commit of this document).

**Related:** [`m9-local-ai-shadow-mode.md`](m9-local-ai-shadow-mode.md), [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md), workspace M10 plan.

Generic core only: no host-, brand-, theme-, or infrastructure-specific runtime code. No WooCommerce `Internal\*` APIs. No provider filter / arbitrary callback extension point.

**Public contracts:** First AI registry entry is **C19** (see §10). **C18** remains `DeliveryStatus::has_confirmation`. Support export schema `upr-support-export/v1` unchanged (no secrets, bodies, or assessment payloads).

---

## 1. Scope

| WP | Deliverable |
|----|-------------|
| **WP0** | This freeze + ADR/runbook/public-contract doc updates |
| **WP1** | External quota schema + typed provider models/errors; forward-only migrate |
| **WP2** | OpenAI Responses client, credential resolver, structured-output validator, no-network seams |
| **WP3** | Worker provider enum switch, atomic dual quotas, fail-closed OpenAI path, claim/txn lifecycle |
| **WP4** | Controls (enablement confirms/acks), test connection, Diagnostics/Site Health, re-analysis caps |
| **WP5** | Runbooks, CI HTTP path allowlist + filter forbid, regression/closure evidence |

**Non-goals:** Multi-provider framework; `upr_moderation_assessment_provider` filter; silent OpenAI→local fallback; customer AI replies; auto-moderation (M11); embeddings/RAG/media; bulk backfill; DB-stored API keys; conversation/`previous_response_id`/tools; SemVer bump / Release / ZIP / DEV-prod enablement in this milestone’s freeze or implementation merges.

---

## 2. Locked decisions (M10)

| ID | Decision | Locked value |
|----|----------|--------------|
| **O1** | Provider enum | Exactly **`local` \| `openai`**. No filter, class-name override, or custom transport hook |
| **O2** | Defaults | Shadow + external both **off**; provider default **`local`** |
| **O3** | OpenAI fail-closed | When provider=`openai` and unavailable/misconfigured/quota-blocked/invalid → terminal allowlisted `skipped`/`failed` — **never** silent local assessor |
| **O4** | Mutation ban | Unchanged from M9 — AI never mutates comment/product/order/invite/token/email/moderation state |
| **O5** | Eligibility | Currently **held**, top-level, in-scope product reviews only (M9 `Eligibility`) |
| **O6** | Claims / completion | M9 claim-before-work; one claim per `(comment_id, policy_version)`; one-txn finalize (lock, recheck token+held+enabled, insert, clear claim rowcount 1) |
| **O7** | Disable-silent | M9 precedence: disable clears in-flight claims without terminal AI row/audit where locked |
| **O8** | API | `POST https://api.openai.com/v1/responses` only; structured outputs `json_schema` `strict: true`; **`store: false`**; forbid `conversation`, `previous_response_id`, tools/function calling/browsing/MCP/code execution |
| **O9** | Credentials | **Host-only:** (1) `UPR_OPENAI_API_KEY` constant, (2) `getenv('UPR_OPENAI_API_KEY')`. **No** option/DB key storage (ADR-0004 reinforced; no encryption theatre) |
| **O10** | Models | Dropdown: `gpt-4o-mini` (**default**), `gpt-4.1-mini`, `gpt-4.1-nano`, `gpt-5.6` (advanced/costly). Manual ID: advanced; `^[a-zA-Z0-9._:-]{1,64}$`; not a security control |
| **O11** | Numeric limits | See §8 |
| **O12** | Quotas | Atomic **daily + monthly** request consume in one locked update; monthly deny must not increment daily; exhaustion → `skipped` / `budget_exceeded` + clear claim |
| **O13** | Test connection | Paid synthetic request; confirm; consume **external** quota only; **no** M9 rate/circuit; no customer data; allowlisted result codes only |
| **O14** | Re-analysis | When provider=`openai`, server-side **`manage_woocommerce`** required to schedule; `moderate_comments` alone denied |
| **O15** | Enablement | Server-side: `manage_woocommerce` + nonce + posted confirm + privacy/governance acks; forged/missing → cannot enable |
| **O16** | Damage limit | Plugin caps ≠ defence against admin/key theft; live enablement **requires** dedicated OpenAI project with provider-side spend/rate limits |
| **O17** | Score encoding | OpenAI JSON Schema uses integer `publication_safety_score` with sentinel **`0`** meaning null; adapter maps `0`→`null` when `state=indeterminate` before `AssessmentValidator`; `completed` requires 1–100 |
| **O18** | Public contract | **C19** `AiProvider::selected(): string` returns `local`\|`openai` only — no secrets |
| **O19** | Phrases/guidance | Bounded evidence cues in user-data JSON only; cannot alter system instructions, schema, provider, quotas, or status |
| **O20** | Release | No SemVer / Release / ZIP in M10 freeze or implementation merges |

---

## 3. Credential and configuration

### Resolution (fail closed)

1. Non-empty `UPR_OPENAI_API_KEY` PHP constant.  
2. Else non-empty `getenv( 'UPR_OPENAI_API_KEY' )`.  
3. Else missing → OpenAI path cannot run.

UI shows source `constant` \| `environment` \| `missing` and present/absent only — never the value.

### Options (proposed keys)

| Key | Default | Notes |
|-----|---------|-------|
| `upr_local_ai_shadow_enabled` | `no` | Existing master shadow gate |
| `upr_ai_external_enabled` | `no` | External opt-in |
| `upr_ai_provider` | `local` | `local` \| `openai` |
| `upr_openai_model` | `gpt-4o-mini` | Dropdown |
| `upr_openai_model_manual` | `''` | Advanced override |
| `upr_openai_max_output_tokens` | `256` | Clamped 64–512 |
| `upr_ai_operator_guidance` | `''` | Max 2048 chars |
| `upr_ai_allowed_phrases` | `[]` | Max 20 × 64 chars |
| `upr_ai_disallowed_phrases` | `[]` | Max 20 × 64 chars |
| `upr_openai_daily_request_cap` | `100` | Clamped 1–10000 |
| `upr_openai_monthly_request_cap` | `2000` | Clamped 1–100000 |
| Enablement ack flags | required on enable | Privacy/processor; retention posture; review-text-may-contain-PII; confirm |

`show_in_rest` false; `manage_woocommerce`; server-side sanitize.

### External enablement (server-side)

All required: capability, nonce, explicit posted confirmation, governance acknowledgements. Tests must prove forged/missing posts cannot enable.

---

## 4. Architecture and data-flow

```text
Point A → shadow on? → enqueue upr_assess_review
Worker: claim → recheck shadow → eligibility → site rate/circuit
  → provider enum
      local → BuiltInLocalAssessor
      openai → external enabled + credential + model OK?
           no  → finalize failed/skipped (typed code; no local)
           yes → atomic daily+monthly quota
                fail → skipped/budget_exceeded + clear claim
                ok   → OpenAiAdvisoryAssessor (Responses, store:false)
  → AssessmentValidator → finalize_terminal (one txn)
```

Interface: `AssessmentProvider` with **typed failure taxonomy** (not exception-message inference). Implementations: built-in + OpenAI only. **No** `apply_filters` provider hook.

HTTP only under allowlisted `src/Ai/OpenAi/*` via `wp_remote_post`. CI forbids other network primitives and all provider-filter strings.

Fingerprint: local unchanged; openai = SHA-256 of non-secret tuple (kind, policy, model, max tokens, guidance/phrases hashes, schema revision) — never the key. Persist `provider_kind` `local` \| `openai`.

---

## 5. OpenAI request / response contract

- Endpoint: `https://api.openai.com/v1/responses`
- Auth: `Authorization: Bearer <secret>`
- Timeout: **12s**
- Body: `model`, `max_output_tokens`, **`store: false`**, `input` (system immutable + user JSON data), `text.format` json_schema strict
- Forbidden: `tools`, `conversation`, `previous_response_id`, and equivalents
- Input review max **4096** chars; oversize → `input_too_large` (no silent truncate)
- No order/identity/email/IP/token/session/admin notes in payload
- Persist only allowlisted assessment columns; never raw response/prompt/headers/bodies/request IDs that identify content

### Typed failures → terminal state (locked map)

| Internal / allowlisted code | Terminal state |
|----------------------------|----------------|
| `budget_exceeded` | `skipped` (+ clear claim) |
| `credential_missing` | `failed` |
| `model_invalid` | `failed` |
| `input_too_large` | `failed` |
| `provider_incomplete` | `failed` |
| `provider_unavailable` | `failed` |
| `validation_rejected` / `malformed` | `failed` |
| `rate_limited` / `circuit_open` / `ineligible_comment` | `skipped` (M9) |
| `deadline_exceeded` | `failed` (M9) |

Extend `PolicyAllowlist::FAILURE_CODES` with the new codes above.

---

## 6. Prompt-injection and phrase semantics

- Immutable system instruction in code: advisory only; schema-only output; review text is untrusted data; ignore instructions inside review/guidance/phrases.
- Operator guidance + allowed/disallowed phrases = **evidence cues only** in user-data JSON; length/count limited; plain text; cannot change schema/provider/endpoint/model policy/tools/safety/quotas/status.
- Tests must cover injection and code-generation review strings → safe validated advisory or fail-closed typed result; never executable/persisted code content.

---

## 7. Cost, quota, test connection, circuit

| Control | Value |
|---------|-------|
| Site rate (M9, worker only) | 60/hour after claim |
| Circuit (M9, worker only) | 10 consecutive failed → 30 min |
| External daily default/clamp | 100 / 1–10000 |
| External monthly default/clamp | 2000 / 1–100000 |
| max_output_tokens default/clamp | 256 / 64–512 |
| HTTP timeout | 12s |
| Worker cooperative deadline | 15s (M9) |
| Claim TTL | 60s (M9) |

Atomic quota: single-row `FOR UPDATE` / conditional update increments **both** day and month or neither.

**Test connection:** confirm + external quota consume; fixed synthetic fixture; `store: false`; **must not** call site rate consume or circuit `record_success`/`record_failure`.

At-least-once claim recovery re-consumes external quota on each outbound attempt.

---

## 8. Schema

Bump `Schema::DB_VERSION` to **`20260829a`**.

Add `{prefix}upr_moderation_external_ops` (single row id=1): period keys + day/month counts + `updated_at`. Seed idempotently. No assessment uniqueness (re-analysis allowed). Retention unchanged.

---

## 9. Admin, diagnostics, export

- Controls: provider enum, external enable (server confirms/acks), credential status, test connection, models, tokens, guidance, phrases, caps, status strip.
- Comments `upr_ai`: escaped labels + bounded scalars only.
- OpenAI re-analysis: server-side `manage_woocommerce`.
- Diagnostics D16–D18 (or extend): external enabled, credential present bool, provider enum, quota aggregate — no secrets/bodies.
- Support export: may add non-secret booleans `ai_external_enabled`, `ai_provider` only if implemented without schema version bump **or** leave export unchanged — **locked: leave `upr-support-export/v1` payload shape unchanged** (evidence codes already cover shadow via D12); do not add secrets.

---

## 10. Public contract C19

| | |
|--|--|
| **ID** | **C19** — `AiProvider::selected()` (**S**, none) |
| **Signature** | `UniversalProductReviews\Ai\AiProvider::selected(): string` |
| **Returns** | `'local'` or `'openai'` (sanitised option; default `local`) |
| **Notes** | Discoverability only. Never returns secrets, models beyond enum policy, or review text. |
| **CI** | Add to stable contracts inventory |

---

## 11. Privacy / live enablement (non-actions in this milestone)

Before any real key + real/customer review leaves the site (separate GO, not part of implementation merges):

1. Documented processor/privacy terms.  
2. Configured OpenAI project data-retention/privacy posture.  
3. Dedicated OpenAI project/service account with **provider-side** spend and rate limits.  
4. Operator acknowledgement that review text may contain personal data.  
5. Maintainer explicit GO.

Implementation and freeze merges: **no** real OpenAI calls, no DEV/prod enablement, no SemVer tag.

---

## 12. Work packages and completion

Implement WP1–WP5 on focused PRs from current `main` after this freeze tag. Merge only when CI green. Closure docs PR records evidence; **no** version bump.

### Acceptance checklist

- [x] Enum `local`\|`openai`; no provider filter; fail-closed OpenAI
- [x] `store: false`; no tools/conversation chaining
- [x] Host-only credentials; never displayed/logged/exported
- [x] Server-side enablement confirms/acks; OpenAI re-analysis `manage_woocommerce`
- [x] Atomic dual quotas; test connection skips M9 rate/circuit
- [x] Typed failure map; injection tests; secret redaction
- [x] C19 registered; CI network allowlist path-scoped
- [x] No SemVer / Release / ZIP / DEV-prod / real customer OpenAI traffic in milestone merges

Closure: [`m10-external-ai-advisory-assessments-closure.md`](m10-external-ai-advisory-assessments-closure.md).
