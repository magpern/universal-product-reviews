# M9 — Local AI Shadow Mode (authoritative freeze)

**Status:** Frozen M9 product and implementation specification. Authorises **implementation** of local-only advisory shadow assessment under the work packages in §12. Does **not** authorise GitHub Release, ZIP, plugin SemVer / `v0.8.0` tag, DEV/production WordPress access, deployment, email, external provider calls, automatic approval, or M10/M11 behaviour.  
**Baseline:** Universal Product Reviews `main` @ `b30f46d` (M8 closure); M8 freeze tag `m8-ai-assisted-moderation-planning-freeze` → `0c40620`. Plugin runtime remains **`0.6.0`** until separately authorised release metadata.  
**Release:** Proposed later as **`v0.8.0`** under separate authorisation after implementation closure (and after any separately authorised M7 `v0.7.0`). **No version bump in this freeze PR.**  
**Freeze tag:** `m9-local-ai-shadow-mode-freeze` (annotated; peels to the merge commit of this document).

**Related:** [`m8-ai-assisted-moderation-planning.md`](m8-ai-assisted-moderation-planning.md), [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md).

Generic core only: no host-, brand-, theme-, vendor-, or infrastructure-specific runtime code. No WooCommerce `Internal\*` APIs. Adapters stay outside this repository.

**Public contracts:** [`../integration/public-contracts.md`](../integration/public-contracts.md) (`upr-public-contracts/v1`) is **unchanged** by M9. No AI provider registry entry (no C18). M8’s `upr_local_moderation_assessment_provider` filter name remains **pseudocode deferred to M10**.

---

## 1. Scope

| WP | Deliverable |
|----|-------------|
| **WP0** | This freeze + related doc/CI updates |
| **WP1** | Schema: assessments, claims, ops tables + forward-only migrate |
| **WP2** | Built-in-only assessor under `src/Ai/` + CI network/filter forbid |
| **WP3** | Claims, ops, AS worker `upr_assess_review`, completion transaction |
| **WP4** | Points A–C lifecycle, revoke precedence, retention/purge |
| **WP5** | Default-off controls + Comments advisory column + held re-analysis |
| **WP6** | Allowlisted AI audit + diagnostics/Site Health (export unchanged) |

**Non-goals:** Replaceable provider filter; host callbacks receiving review text; C18 / public-contract AI entry; external HTTP / API keys / external DTO; auto-approve/reject/spam; support-export assessment payloads; schema downgrade/drop; vendor-named providers; machine-enforced DPIA checkbox; `v0.8.0` / Release / ZIP / DEV-prod access in this milestone’s freeze or implementation merges.

---

## 2. Locked decisions (M9)

| ID | Decision | Locked value |
|----|----------|--------------|
| **N1** | Assessor | **Built-in only** under `src/Ai/`; deterministic in-process heuristic; **no** replaceable filter |
| **N2** | Local-only enforceability | CI forbids `wp_remote_*`, `wp_safe_remote_*`, `curl_*`, sockets, and `upr_local_moderation_assessment_provider` in `src/Ai/` / `src/` as specified |
| **N3** | Default | Shadow **disabled** (`upr_local_ai_shadow_enabled` default `no`) |
| **N4** | Mutation ban | AI **never** mutates comment status, content, author, rating, linkage, invitations, or tokens |
| **N5** | Eligibility | Assess / re-assess **currently held**, top-level, in-scope product reviews only |
| **N6** | Disable silence | AI off → **no** new assessment row, **no** AI audit (including in-flight and disable-then-non-held-transition) |
| **N7** | Claims PK | `{prefix}upr_moderation_assessment_claims` `PRIMARY KEY (comment_id, policy_version)`; **no** partial indexes |
| **N8** | Worker order | Eligibility → **acquire claim** → atomic rate/circuit → `assess()` (rate never before claim) |
| **N9** | Completion | One txn: `FOR UPDATE` claim; re-check token + held eligibility + **shadow enabled**; insert+clear or clear-only / no row |
| **N10** | Fingerprint | Plain SHA-256 of canonical non-secret inputs; **not** `wp_salt` |
| **N11** | Throwable | Map to `failed` / `provider_unavailable`; never for feature disable; never log message/trace/body/raw |
| **N12** | Public contracts | **Unchanged**; first AI registry entry is **M10** |
| **N13** | Support export | `upr-support-export/v1` **unchanged**; no assessment payloads |
| **N14** | Mode | `shadow` only; never `auto` before M11 |

---

## 3. Data model

### 3.1 `{prefix}upr_moderation_assessments` (terminal rows only)

| Column | Type | Notes |
|--------|------|-------|
| `assessment_id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` | PK |
| `schema_version` | `varchar(64) NOT NULL` | `upr-moderation-assessment/v1` |
| `comment_id` | `bigint(20) unsigned NOT NULL` | |
| `mode` | `varchar(16) NOT NULL` | always `shadow` in M9 |
| `state` | `varchar(32) NOT NULL` | `completed` \| `indeterminate` \| `failed` \| `skipped` |
| `publication_safety_score` | `tinyint unsigned DEFAULT NULL` | 1–100 iff `completed`; else NULL |
| `confidence` | `varchar(16) DEFAULT NULL` | `high` \| `medium` \| `low` \| NULL |
| `reason_codes` | `text DEFAULT NULL` | JSON array ≤8 allowlisted codes |
| `policy_version` | `varchar(32) NOT NULL` | `2026-08-ps-v1` |
| `provider_kind` | `varchar(16) NOT NULL` | `local` only |
| `provider_fingerprint` | `char(64) NOT NULL` | opaque hex |
| `failure_code` | `varchar(64) DEFAULT NULL` | failure allowlist or NULL |
| `requested_at` | `datetime NOT NULL` | UTC |
| `completed_at` | `datetime NOT NULL` | UTC |
| `retention_due_at` | `datetime NOT NULL` | indexed; never NULL |

Indexes: `PRIMARY KEY (assessment_id)`; `KEY comment_completed (comment_id, completed_at)`; `KEY retention_due_at (retention_due_at)`; `KEY state_completed (state, completed_at)`.

Charset/collation: `$wpdb->get_charset_collate()`. Forward-only `dbDelta` via existing `Schema` / `Migrator`. No page-load migrate. Disabling shadow does **not** drop tables or historical rows.

### 3.2 `{prefix}upr_moderation_assessment_claims`

| Column | Type | Notes |
|--------|------|-------|
| `comment_id` | `bigint(20) unsigned NOT NULL` | |
| `policy_version` | `varchar(32) NOT NULL` | |
| `claim_token` | `varchar(64) DEFAULT NULL` | NULL when free |
| `claim_expires_at` | `datetime DEFAULT NULL` | |
| `requested_at` | `datetime NOT NULL` | |
| `updated_at` | `datetime NOT NULL` | |

`PRIMARY KEY (comment_id, policy_version)`. Claim TTL **60s**. No partial unique indexes.

### 3.3 `{prefix}upr_moderation_ops`

| Column | Type | Notes |
|--------|------|-------|
| `id` | `tinyint unsigned NOT NULL` | always `1` |
| `rate_window_started_at` | `datetime NOT NULL` | |
| `rate_count` | `smallint unsigned NOT NULL DEFAULT 0` | |
| `consecutive_failures` | `smallint unsigned NOT NULL DEFAULT 0` | |
| `circuit_open_until` | `datetime DEFAULT NULL` | |
| `updated_at` | `datetime NOT NULL` | |

Seed `id=1` idempotently on migrate. Atomic SQL only (no option RMW / `GET_LOCK`). Rate: **60**/hour/site after claim, before `assess()`. Circuit: **10** consecutive `failed` after provider attempt → open **30 min**. `skipped` never increments circuit.

---

## 4. Built-in assessor and privacy

- Namespace `UniversalProductReviews\Ai\*` under `src/Ai/`.
- `AssessmentRequest`: `review_text`, optional `detected_language`, `policy_version`. **No rating.** No identity/order/token/IP fields.
- Output validated: score 1–100 when `completed`; confidence allowlist; ≤8 reason codes from M8 policy `2026-08-ps-v1`; failure codes from M8 allowlist only; no arbitrary text stored/displayed.
- Fingerprint: `hash( 'sha256', implode( "\n", array( 'local', $policy_version, 'upr.builtin.local', $config_revision ) ) )`.
- Throwable → `failed` / `provider_unavailable` at worker boundary; never persist/log exception text, traces, review text, or raw output.
- Tests may use **test-only** constructor seams / fakes; **no real or external provider calls in CI**.

**Forbidden everywhere:** raw prompt, review body copy, email, token, invite URL, order ID, customer name, provider raw JSON, API keys in UPR options/audit/diagnostics/export.

---

## 5. Lifecycle

### Point A — `comment_post`

Only when shadow **enabled**. Eligible held top-level in-scope product review → enqueue AS `upr_assess_review` (group `upr`, unique) with `comment_id` + `policy_version`. Ineligible / disabled → **silent** (no job, no row, no audit). Does **not** create assessment rows.

### Point B — worker (claim-before-rate)

1. Precheck: if disabled → exit (no acquire, no row, no audit).  
2. Acquire claim (60s TTL); fail → exit without rate touch.  
3. Re-check: disabled → clear owned claim, no row, no audit; ineligible → `skipped` / `ineligible_comment`.  
4. Atomic rate/circuit; blocked → `skipped` (`rate_limited` / `circuit_open`).  
5. `assess()`; Throwable → `failed` / `provider_unavailable`.  
6. Cooperative **15s** deadline after return/throw only; no forced PHP kill.  
7. Completion transaction (token + held + **enabled**); fail → clear matching claim if owned, no row; disable path → no AI audit.

### Point C — re-analysis

Held-only; `moderate_comments` + nonce; 1 / 15 minutes / comment; refuse if disabled (no job/row/audit).

### Disabled-state precedence (locked)

| Situation | Behaviour |
|-----------|-----------|
| Disabled before job runs | No claim acquire, no row, no audit |
| Disabled after claim, before `assess()` | Clear owned claim; no row; no audit |
| Disabled while `assess()` running | Discard result; clear matching claim; no row; no audit |
| Completion | Re-check token + held eligibility + **enabled** in one transaction |
| `transition_comment_status` → approve/spam/trash while **disabled** | Clear any active claim **silently**; recompute historical retention **only**; **no** `skipped` row; **no** AI audit |
| Same transition while **enabled** + active claim | Insert `skipped` / `ineligible_comment`; clear claim; AI audit |
| Same transition while **enabled** + no claim | Retention recompute only |

**Do not** use `provider_unavailable` for operator disable. Historical assessment rows remain retained and visible when disabled; disable only stops **new** output.

**Required interleaving test:** claim active → disable shadow → status transition → no new row, no AI audit, claim cleared.

### Retention

| Status | `retention_due_at` |
|--------|-------------------|
| hold | +180 days |
| approve | +90 days |
| spam / trash | +30 days |

Restore recomputes from restore time. Permanent delete purges assessment + claim rows. AS `upr_purge_moderation_assessments`: bounded `DELETE … WHERE retention_due_at <= UTC_TIMESTAMP()`.

---

## 6. Admin, audit, diagnostics

- Enable: `manage_woocommerce` + server-side confirmation; default off.
- Comments column: bounded advisory (state, score if completed, confidence, ≤8 reason codes); historical visible on non-held; re-analysis control **held-only**.
- Audit events (enabled paths only): `review.ai_assessment_completed`, `review.ai_assessment_failed`, `review.ai_assessment_skipped`, `review.ai_reanalysis_requested`. Payload: `comment_id`, `product_id`, `assessment_id`, `state`, `policy_version`, `provider_kind`, optional `failure_code` — **no** score/reason codes/body/PII.
- Diagnostics/Site Health: enabled, schema readiness, circuit/rate, aggregate 24h counts — privacy-safe evidence codes only. Support export unchanged.

---

## 7. Acceptance (implementation)

- [ ] Default off; disable silent; historical visible
- [ ] Built-in-only; CI network + no-provider-filter forbid green
- [ ] Held-only; zero status/content mutation
- [ ] Claim-before-rate; completion txn; disable+transition interleave
- [ ] Public contracts + support export unchanged
- [ ] No Release / ZIP / SemVer tag / DEV-prod / external provider as part of M9 implementation merges

---

## Related

- [`m8-ai-assisted-moderation-planning.md`](m8-ai-assisted-moderation-planning.md)
- [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md)
- [`../runbooks/ai-outage.md`](../runbooks/ai-outage.md)
- [`../runbooks/moderation-capabilities.md`](../runbooks/moderation-capabilities.md)
- [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md) §9
