# M8 — AI-Assisted Moderation Planning (authoritative freeze)

**Status:** Frozen M8 product specification. **Does not** authorise AI runtime implementation, provider calls, migrations, settings UI, automatic approval, production rollout, host deploy, customer contact, GitHub Release, or ZIP.  
**Baseline:** Universal Product Reviews `main` @ `e6869ef` (M7 implementation merged; plugin still **`0.6.0`** until separate M7 release metadata).  
**Release:** **No version bump** in M8. First AI implementation is a later milestone (**M9**, proposed `v0.8.0`) under separate authorisation.  
**Freeze tag:** `m8-ai-assisted-moderation-planning-freeze` (annotated; peels to the merge commit of this document).

Generic core only: no host-, brand-, theme-, provider-, or infrastructure-specific runtime code. No WooCommerce `Internal\*` APIs. Adapters stay outside this repository.

**Related ADR:** [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md).  
**Public contracts:** [`../integration/public-contracts.md`](../integration/public-contracts.md) (`upr-public-contracts/v1`) is **unchanged** by M8. Unimplemented AI surfaces must **not** be registered until an implementation milestone ships code.

---

## 1. Scope (M8 work packages)

| WP | Deliverable |
|----|-------------|
| **WP1** | Authoritative freeze (this document) — D1–D17, allowlists, lifecycle, retention, claims |
| **WP2** | **ADR-0004** — AI moderation boundary |
| **WP3** | Reconcile [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md) as **M11 appendix**; this freeze is near-term authority for M9/M10 |
| **WP4** | Updates to `ARCHITECTURE.md` §9/§16, `post-m3-product-roadmap.md`, `ai-outage.md`, `moderation-capabilities.md` |
| **WP5** | CI doc-link guard for this freeze + ADR-0004 |
| **WP6** | Documentation PR only — merge + annotated freeze tag; **no** runtime code |

**Non-goals (explicit):** PHP/runtime AI code; migrations or schema changes; provider calls; AI settings page; Action Scheduler jobs; assessment/claim/ops tables; admin UI; external HTTP / `wp_remote_*`; public-contract registry changes; host-specific code; automatic approval, rejection, spam, or status mutation from AI; encrypted/plain UPR storage of provider secrets; machine-enforced DPIA gates; schema downgrade or table-drop “rollback”; DEV/production access; deployment; email; GitHub Release; ZIP; plugin version bump.

---

## 2. Locked decisions (D1–D17)

| ID | Decision | Locked value |
|----|----------|--------------|
| **D1** | Milestone type | **M8 = planning/documentation only** — zero runtime |
| **D2** | AI output purpose | **Publication-safety advisory** only — not sentiment, quality, helpfulness, or commercial favourability |
| **D3** | Auto-action | **No** automatic approval, rejection, or spam from AI in M8, M9, or M10 |
| **D4** | Default review status | New product reviews remain **`hold`** by default (unchanged M1/M5) |
| **D5** | Negative reviews | Must **not** be policy-disadvantaged vs positive reviews |
| **D6** | Mutation ban | AI **never** mutates comment body, author, rating, product linkage, or comment status |
| **D7** | First implementation | **M9 = local-only shadow** — no external HTTP from core |
| **D8** | External processing | **M10** requires a **separate freeze** (not authorised by M8 or M9) |
| **D9** | Storage / schema | Dedicated terminal assessment table; **monotonic** migrations; **no** downgrade rollback |
| **D10** | Secrets | Host-owned via env / `wp-config.php` constants only; **UPR never stores provider keys** in options, audit, diagnostics, or exports |
| **D11** | Rating input | Star **rating excluded** from all provider inputs |
| **D12** | Reason codes | v1 allowlist locked in §5 (policy `2026-08-ps-v1`) |
| **D13** | Lifecycle / retention | Points A–C (§6); indexed `retention_due_at` purge (§8) |
| **D14** | Skipped rows | Only for comments that **entered the AI job pipeline**; Point A ineligible = silent (no job/row/audit) |
| **D15** | Assess / re-assess | **Currently held** top-level in-scope product reviews only; approved/spam/trash/deleted/replies/out-of-scope **blocked**; local re-analysis `moderate_comments`; M10 external re-analysis `manage_woocommerce` |
| **D16** | Registry / M11 | **No** public-contract registry entry until implementation; M11 auto-approval needs ADR amendment + governed calibration |
| **D17** | Execution claim | Separate `{prefix}upr_moderation_assessment_claims` with `PRIMARY KEY (comment_id, policy_version)`; acquire before provider; **one-transaction** completion (lock claim, re-check token + held eligibility, insert + clear); non-held revoke inserts `skipped`/`ineligible_comment` and clears token; at-least-once advisory; never duplicate status mutation |

---

## 3. Forward milestones (not authorised by this freeze)

| Milestone | Scope | Version (proposed) |
|-----------|--------|-------------------|
| **M9 — Local AI Shadow Mode** | Local in-process provider; assessments table + claims + ops; Comments admin advisory; fail-open; zero status mutation | `v0.8.0` |
| **M10 — External AI Processing** | Separate freeze; core-enforced external branch; minimal external DTO; opt-in default off; elevated re-analysis cap | `v0.9.0` |
| **M11 — Auto-approval** | ADR-0004 amendment + governed calibration/evaluation; not provable by mock unit tests alone | Later |

---

## 4. Purpose and bounded output schema

### Purpose

| Facet | Purpose | Explicitly not |
|-------|---------|----------------|
| Publication-safety score (1–100) | Policy-violation / publication-risk likelihood | Quality, helpfulness, truthfulness, commercial favourability |
| Reason codes | Policy-risk categories from v1 allowlist | Sentiment, rating alignment, negativity |
| Confidence / indeterminate | Provider uncertainty | Auto-action trigger in M9/M10 |
| Failure / skipped | Operational or eligibility outcome | Submission denial |

### Assessment record (`upr-moderation-assessment/v1`)

Historical **terminal** rows only in `{prefix}upr_moderation_assessments`:

| Field | Notes |
|-------|--------|
| `schema_version` | `upr-moderation-assessment/v1` |
| `comment_id`, `assessment_id` | Comment FK + row PK |
| `mode` | `shadow` only in M9/M10; never `auto` before M11 |
| `state` | `completed` \| `indeterminate` \| `failed` \| `skipped` |
| `publication_safety_score` | 1–100 when `completed`; else null |
| `confidence` | `high` \| `medium` \| `low` \| null |
| `reason_codes` | Subset of v1 allowlist; max 8 |
| `policy_version` | e.g. `2026-08-ps-v1` |
| `provider_kind` | M9: **`local` only**; M10 adds `external` |
| `provider_fingerprint` | Opaque config hash; no vendor name in core |
| `failure_code` | Failure allowlist or null |
| `requested_at`, `completed_at` | UTC |
| `retention_due_at` | Indexed, **NOT NULL** on every terminal row |

**Forbidden everywhere:** raw prompt, review body copy, email, token, invite URL, order ID, customer name, provider raw JSON.

**Failure-code allowlist:** `deadline_exceeded`, `malformed`, `rate_limited`, `provider_unavailable`, `validation_rejected`, `privacy_blocked`, `unsupported_language`, `ineligible_comment`, `circuit_open`.

---

## 5. v1 reason-code allowlist (`policy_version` = `2026-08-ps-v1`)

Validator **rejects** any code not listed. Forbidden substrings/labels: `sentiment`, `rating`, `negative`, `positive`, `critical`, `praise`, `helpful`, `quality`.

| Code | Meaning |
|------|---------|
| `spam_pattern` | Likely spam / SEO / promotional pattern |
| `link_abuse` | Excessive, suspicious, or disallowed links |
| `pii_suspected` | Suspected personal data in body |
| `contact_info_suspected` | Contact or off-platform redirect attempt |
| `abuse_harassment` | Harassment or targeted abuse (non-sentiment) |
| `threat_suspected` | Threat of harm |
| `hate_suspected` | Hate or discriminatory content |
| `regulatory_claim_suspected` | Product regulatory / legal compliance claim |
| `medical_claim_suspected` | Health or medical efficacy claim |
| `safety_claim_suspected` | Product safety allegation |
| `impersonation_suspected` | Impersonation or false identity claim |
| `fraud_suspected` | Fraud or scam allegation pattern |
| `off_topic` | Unrelated to product experience |
| `unsupported_language` | Language not supported by policy/model |
| `insufficient_signal` | Too short or ambiguous for reliable classification |

**Deferred (not v1):** `duplicate_pattern`, `coordinated_manipulation_suspected` — require corpus/comparison capability in a separately frozen milestone.

---

## 6. Assessment eligibility and lifecycle

Ordinary ineligible comments that never enter the AI flow produce **no job, no row, no audit**.

### Point A — `comment_post` enqueue (lightweight)

Runs only when local shadow is **enabled**. Does **not** create assessment rows.

| Condition | Outcome |
|-----------|---------|
| AI disabled | **Silent** |
| Not in-scope product review | **Silent** |
| Not top-level / staff reply | **Silent** |
| Not held after insert | **Silent** |
| All pass | Enqueue AS job `upr_assess_review` with `comment_id` + `policy_version` |

### Point B — AS worker (authoritative for terminal rows)

Creates terminal assessment rows (except comment-delete purge and non-held revoke). In-flight ownership lives only in `{prefix}upr_moderation_assessment_claims`.

| Worker condition | Row | `state` | `failure_code` |
|------------------|-----|---------|----------------|
| No longer eligible | Yes | `skipped` | `ineligible_comment` |
| Circuit open | Yes | `skipped` | `circuit_open` |
| Rate limited | Yes | `skipped` | `rate_limited` |
| Privacy blocked | Yes | `skipped` | `privacy_blocked` |
| Unsupported language | Yes | `skipped` | `unsupported_language` |
| Cooperative deadline exceeded | Yes | `failed` | `deadline_exceeded` |
| Malformed / validation rejected | Yes | `failed` | `malformed` / `validation_rejected` |
| Valid output | Yes | `completed` / `indeterminate` | — |

**Fail open:** comment status unchanged; ordinary moderation continues.

### Point C — operator re-analysis

M9 assesses and re-assesses **only currently held**, top-level, in-scope product reviews.

- Approved / spam / trash / deleted → **reject in UI**; no job, no row.
- Restored to **hold** → re-analysis allowed again (current status).
- Historical advisory remains visible on non-held comments; **no re-analysis control**.

| Action | M9 | M10 |
|--------|----|-----|
| View AI advisory | `moderate_comments` | `moderate_comments` |
| Local re-analysis (held only) | `moderate_comments` + nonce | same |
| External (re)processing | N/A | `manage_woocommerce` + nonce + external opt-in |
| Enable/disable AI | `manage_woocommerce` + confirmation | same |

Per-comment re-analysis rate limit: **1 / 15 minutes**.

### Status transitions

| Transition | New jobs | Re-analysis | Claim / retention |
|------------|----------|-------------|-------------------|
| → `approve` | No | Blocked | Recompute `retention_due_at`; **atomic claim revoke** (§7) |
| → `spam` / `trash` | No | Blocked | Same |
| Permanent delete | N/A | N/A | Purge all assessment rows immediately |

On non-held transition with an active claim: atomically insert terminal `skipped` / `ineligible_comment` (set `completed_at`, `retention_due_at`), then **clear** the claim token. Merely expiring the claim is **insufficient**.

---

## 7. Provider, claims, ops, and deadline (M9 design — not implemented in M8)

### Local provider only

- In-process only; core AI module must not call `wp_remote_*`, `curl_*`, or sockets.
- Pseudocode filter (document only until M9): `upr_local_moderation_assessment_provider`.
- Request: `review_text`, optional `detected_language`, `policy_version`. **No rating.**

### Cooperative 15-second deadline

Record `t0` immediately before `assess()`. A synchronous PHP call **cannot** be marked failed while still running. **After** return/throw, if elapsed > 15s, discard any success payload and attempt `failed` / `deadline_exceeded` only under a still-matching claim.

### `{prefix}upr_moderation_assessment_claims` (portable)

| Column | Role |
|--------|------|
| `comment_id`, `policy_version` | **`PRIMARY KEY`** — no partial unique indexes |
| `claim_token`, `claim_expires_at` | Ownership; nullable when free |
| `requested_at`, `updated_at` | Timestamps |

Acquire before provider (atomic update when free or expired). Default TTL **60s**.

### Completion (one database transaction only)

1. Lock the claim row for the exact `(comment_id, policy_version)` (`SELECT … FOR UPDATE` or equivalent).
2. Re-check matching token **and** current held eligibility.
3. If either fails → **commit no assessment row**; discard provider result.
4. If both pass → insert terminal assessment **and** clear claim in the **same** transaction.

**Forbidden:** ordered writes with claim check first (race with non-held revoke).

### Delivery guarantee

- Advisory assessment: **at-least-once**.
- Comment status: **never** mutated by AI.
- Terminal insert: gated by holding the current claim token inside the completion transaction.

### `{prefix}upr_moderation_ops`

Single-row site rate limit + circuit breaker with **atomic SQL** updates (no option read-modify-write). Preferred over `GET_LOCK`. Rate: 60 provider invocations/hour/site. Circuit: 10 consecutive `failed` → open 30 min.

---

## 8. Retention

| Comment status when setting / recomputing | `retention_due_at` |
|-------------------------------------------|-------------------|
| `hold` | +180 days from `completed_at` or transition |
| `approve` | +90 days |
| `spam` / `trash` | +30 days |

Restore from spam/trash → hold/approve: recompute from **restore time**. Purge: `DELETE … WHERE retention_due_at <= UTC_TIMESTAMP()`. Future AS hook `upr_purge_moderation_assessments` (internal).

---

## 9. Privacy and secrets

| Topic | Rule |
|-------|------|
| External transmission | **None** in M9; M10 requires separate freeze + machine-checked opt-in default off |
| DPIA | Host **human/process** obligation — not a core machine gate |
| Secrets | Env / `wp-config.php` only; never in UPR options (plain or “encrypted”), audit, diagnostics, or support export |
| Support export | Unchanged in M9; no assessment payloads |

---

## 10. Audit (future internal events)

| Event | When |
|-------|------|
| `review.ai_assessment_completed` | `completed` / `indeterminate` |
| `review.ai_assessment_failed` | Provider/validation failure |
| `review.ai_assessment_skipped` | Pre-provider skip or non-held revoke |
| `review.ai_reanalysis_requested` | Operator re-run |

Payload allowlist: `comment_id`, `product_id`, `assessment_id`, `state`, `policy_version`, `provider_kind`, `failure_code?` — **no score, no reason codes** by default.

---

## 11. Public contracts and compatibility

- M8: **no** changes to `upr-public-contracts/v1`.
- Local provider filter/interface: ADR/freeze only until **M9 implementation** registers runtime code.
- External provider: **M10** freeze + implementation.
- No change to C9/C10/guards; AI must not register `comments_open` or submission filters.

---

## 12. M9 structural test contracts (specify now; implement later)

Prove hold invariant; no status mutation; rating excluded; reason-code allowlist; fail open; disabled = silent; Point A silent vs Point B skipped; cooperative deadline after return; one-txn completion + non-held revoke; ops-table concurrency; no `wp_remote_*` in AI module; held-only re-analysis; `retention_due_at` purge; support export unchanged.

**Do not claim** that a model avoids penalising negative sentiment — that requires a governed calibration programme before M11.

---

## 13. Acceptance (this freeze)

- [ ] D1–D17 locked with no TBD on auto-action or completion races
- [ ] ADR-0004 present; `public-contracts.md` unchanged
- [ ] No PHP/runtime / migration / settings / AS / table diffs in this PR
- [ ] CI green; annotated tag peels to freeze merge

---

## Related

- [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md)
- [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md) — M11 appendix only
- [`../runbooks/ai-outage.md`](../runbooks/ai-outage.md)
- [`../runbooks/moderation-capabilities.md`](../runbooks/moderation-capabilities.md)
- [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md) §9
