# M13 — Operator AI Moderation Command Surface (authoritative freeze)

**Status:** Frozen M13 **product and implementation** specification. **Implementation closed:** [`m13-operator-ai-command-surface-closure.md`](m13-operator-ai-command-surface-closure.md) (PR [#70](https://github.com/magpern/universal-product-reviews/pull/70) → `4fbe6068fe6e6ef8a737ffdd19004ab4954490fc`). Authorises **documentation freeze** and **implementation with M12 masters default-off**. Does **not** authorise Calibration GO, production enablement, DEV/production WordPress access, credentials, provider configuration, external-AI enablement, email, auto-spam master enablement, host-specific code, GitHub Release, ZIP, plugin SemVer / version tag, or movement of `v0.8.0`.  
**Baseline:** Universal Product Reviews `main` @ **`d4513bb037d15edd91816e0c1a9dfeb7cc192a86`** (PR [#68](https://github.com/magpern/universal-product-reviews/pull/68) — M12 Simulation-GO implementation closure). Runtime remains **`0.8.0`**.  
**Freeze tag:** `m13-operator-ai-command-surface-freeze` (annotated; peels to **`c82b176f08d932c3f413a7b1cd1eb712ca8aa67b`** — merge of this freeze).

**Related:** [`m12-guarded-auto-spam.md`](m12-guarded-auto-spam.md), [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md), [`m11-ai-moderation-recommendations.md`](m11-ai-moderation-recommendations.md), [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md).

Generic core only: no host-, brand-, theme-, or infrastructure-specific runtime code. No WooCommerce `Internal\*` APIs. Support export schema **`upr-support-export/v1` unchanged** — M13 makes **no** `SupportExport` runtime change. No new public `upr_*` host filters.

---

## 0. Problem and users

**Problem:** After M12 Simulation-GO implementation, operators lack Overview AI posture, a true read-only would-act preview, held recommendation triage filters, and CLI inspection — raising misconfiguration and premature-enablement risk.

**Users:** WooCommerce shop managers (`manage_woocommerce`); support using Site Health / existing support export; integrators using WP-CLI with explicit `--user`.

**Milestone role:** Operator **command surface** only. Never enables auto-spam masters or Calibration GO. Production automatic moderation remains prohibited pending real-world Calibration GO and separate production-enable approval.

---

## 1. Locked decisions (O1–O20)

| ID | Decision | Locked value |
|----|----------|--------------|
| **O1** | Role | Operator command surface only; never enables auto-spam masters or Calibration GO |
| **O2** | Forbidden actions | No automatic approve; no sentiment/rating/criticism-only action |
| **O3** | Would-act | Zero-write, fail-closed, ≤**500** distinct comments, selection via `latest_actionable_assessment_for_comment()`, dual metrics (§4), control-state block |
| **O4** | Dry-run | M12 dry-run option unchanged; would-act ≠ dry-run `observed` |
| **O5** | Comments filters | Held-only filters for all five `suggested_action` values; SQL only via `RecommendationPolicy` compiler |
| **O6** | Overview | Masters, boundary, ledger counts, `unknown_after_crash`, 24h assessment aggregates — no PII |
| **O7** | CLI | `wp upr ai-status`, `wp upr would-act`, `wp upr ledger-summary`; each requires `--user=` |
| **O8** | Caps | Admin: `manage_woocommerce` + nonce; Comments filters: existing list context |
| **O9** | Support export | **No `SupportExport` runtime change**; schema remains `upr-support-export/v1`; golden payload test required |
| **O10** | Privacy | Never emit review body, email, token, URL, prompt, raw provider output, credentials, lease/claim tokens in UI/CLI/diagnostics/audit/logs/exports |
| **O11** | Crash | Never replay WP transition hooks; surface `unknown_after_crash` + runbook only |
| **O12** | Contracts | No new public `upr_*` filters; AS job names in **operator runbooks / internal inventory only** — never `docs/integration/public-contracts.md` |
| **O13** | Release | No SemVer / `v*` / Release / ZIP / deployment in M13 |
| **O14** | Docs | Diagnostics D1–D21 copy; operator-controls; retention purge; AS inventory in runbooks |
| **O15** | Content eligible | `ActionPolicy::content_eligible_for_auto_spam( $assessment, $comment )` — held/top-level/in-scope, completed, **strict boundary**, Simulation tuple, `likely_spam`; **ignores** master/policy/sim/kill. Live `eligible()` = content + masters |
| **O16** | D21 | Severity matrix §6 |
| **O17** | Pre-boundary | `ActionPolicy::policy_match_pre_boundary( $assessment, $comment )` — same as content eligible **without** boundary; labeled **policy match (pre-boundary)** only — **never** “would act” |
| **O18** | SQL owner | `RecommendationPolicy` owns filter SQL compiler; Comments only consumes output |
| **O19** | Selection | `AssessmentRepository::latest_actionable_assessment_for_comment( int $comment_id ): ?array` — shared by WouldActReport and ActionWorker (§3) |
| **O20** | Compiler safety | Reject non-allowlisted actions; return `{ fragment, args[] }` for `$wpdb->prepare` — never interpolate request values |

---

## 2. Explicit non-goals

Enable any M12 master; Calibration GO; credentials / provider config; DEV/prod WP access; customer review edits; auto-approve; provider calls from would-act; audit/option/ledger/CAS/schedule/comment writes from would-act; SupportExport code changes or v2; AS job names in public-contracts; hook replay; `Internal\*`; SemVer/Release/ZIP; host-specific code.

---

## 3. Canonical assessment selection

**Method:** `AssessmentRepository::latest_actionable_assessment_for_comment( int $comment_id ): ?array`

1. Load latest assessment **of any state**: `MAX(assessment_id)` for `comment_id` (same identity as `latest_for_comments`).
2. If no row → `null` (reason `no_assessment`).
3. Let `L` be that row. **Supersession** (return `null`; **never** fall back to an older completed row):
   - `L.state ∈ {failed, skipped, indeterminate}` → `superseded_by_non_completed`
   - `L.state = completed` but Simulation **tuple mismatch** → `superseded_by_tuple_mismatch`
   - `L.state = completed` but assessment/policy identity drifts from active Simulation tuple (including `policy_version` / recommendation / action policy constants) → `superseded_by_policy_version`
4. **Actionable:** only when `L.state = completed` **and** tuple/policy identity matches the active Simulation calibrated tuple → return `L`.

**Reuse:**

- **WouldActReport** evaluates only this row (or abstains on null reason).
- **ActionWorker::handle:** if loaded `$assessment_id` is not the canonical actionable latest → **abstain** (e.g. `superseded`), clear/release owned processing claim per existing M12 rules, **never CAS**.

**Split vs Comments filters:** Recommendation filters use latest assessment **of any state** for M11 advisory derive-at-read. Auto-spam discovery / would-act / worker parity use `latest_actionable_*` only. This split is intentional and must be tested.

---

## 4. Would-act report

**Class:** privacy-safe aggregate reporter (name illustrative: `WouldActReport`).

### Sampling

1. Up to **500** distinct `comment_id`s ordered by latest assessment_id **of any state** DESC.
2. Per comment: `latest_actionable_assessment_for_comment()` only.
3. Null → content abstention count for supersession/empty reason; do not evaluate older completed rows.
4. Non-null → evaluate `content_eligible_for_auto_spam` and `policy_match_pre_boundary` on that row alone.

### Dual metrics

| Prefix | Predicate | Boundary unset | Operator copy |
|--------|-----------|----------------|---------------|
| `would_act_*` | `content_eligible_for_auto_spam` (includes strict boundary) | Truthful **zero** candidates; `control_state.boundary = unset`; abstain `boundary_unset` | “Would act **if masters were on** (requires enablement boundary).” |
| `policy_match_pre_boundary_*` | `policy_match_pre_boundary` (excludes boundary only) | May be non-zero | “**Policy match (pre-boundary)** — non-actionable; not would-act.” |

Also emit `control_state`: master / policy / sim / kill / dry_run booleans; boundary set|unset.

**Forbidden:** labeling `policy_match_pre_boundary_*` as would-act; calling live `eligible()` alone as the candidate predicate (masters-off collapse).

### Zero writes (absolute)

No audit row, option write, transient required for correctness, ledger write, CAS, scheduling, or comment update.

### Fail closed

On DB/query/validation failure: `ok: false`, allowlisted `error_code`, all candidate/match counts **0**, empty maps. Never partial-as-valid.

### Admin

`manage_woocommerce` + nonce. Confirm copy: read-only; does not change status, write audit rows, or enable auto-spam.

---

## 5. Comments recommendation filters

- Held-only native Comments list filters for all five allowlisted `suggested_action` values.
- Primary `edit-comments.php` list query only; existing product-review scope; `comment_approved = '0'`.
- `EXISTS` (no multiplicative joins); search/pagination/`found_comments` parity.
- No secondary `WP_Comment_Query`, REST, front-end, widgets, or count-query impact.
- No PHP page post-filter; no N+1; preserve prefetch recursion guard.

**SQL compiler** (owned by `RecommendationPolicy`):

- One structured definition drives `suggest()` and SQL.
- Reject non-allowlisted actions.
- Return prepared `{ fragment, args[] }` only — never interpolate `$_GET` / raw action strings.

---

## 6. Diagnostics D21 (purge health)

Uses `{prefix}upr_moderation_assessments.retention_due_at` and last successful purge timestamp. **No `Internal\*`.**

| Condition | Severity |
|-----------|----------|
| Query/schema failure | `unavailable` |
| `due_count = 0` and last purge age ≤ 36h (or healthy empty) | `information` |
| `0 < due_count ≤ 100` or last purge age ∈ (36h, 72h] | `warning` |
| `due_count > 100` or last purge age > 72h or recurring purge missing when public AS APIs present | `critical` |

Counts/ages only. Write last-purge option **only** after successful `purge_due` — never from would-act/Overview/CLI reporting.

Keep D20. Update Diagnostics UI copy to D1–D21.

---

## 7. Overview AI posture

Privacy-safe section: master/policy/simulation/kill/dry-run; boundary set/unset; ledger state counts; `unknown_after_crash` visibility; 24h assessment aggregates. No bodies/PII/tokens.

---

## 8. CLI

| Command | Behaviour |
|---------|-----------|
| `wp upr ai-status` | Read-only posture aggregates |
| `wp upr would-act` | WouldActReport JSON/text |
| `wp upr ledger-summary` | Ledger state counts |

Each command: require `--user=<id|login>` → resolve → `wp_set_current_user` → require `manage_woocommerce` → else `WP_CLI::error` with **no partial output**. No shell/root trust bypass.

---

## 9. Support export

- **Do not modify** `src/Admin/SupportExport.php` or related builders.
- Schema string remains `upr-support-export/v1`.
- Add golden v1 payload test (fixed fixtures → canonical encoding match).

---

## 10. Internal Action Scheduler inventory (runbooks only)

Document in operator runbooks (not public-contracts): `upr_assess_review`, `upr_auto_spam_action`, `upr_purge_moderation_assessments`, `upr_recover_auto_spam_crash`, plus existing invitation/db jobs. Not a supported host integration contract.

---

## 11. Work packages

| WP | Deliverable |
|----|-------------|
| **WP0** | This freeze + roadmap/runbook index + freeze tag |
| **WP1** | Overview AI posture + D21 + Site Health/runbook copy |
| **WP2** | Selection contract + ActionWorker superseded abstain + content/pre-boundary APIs + WouldActReport + admin_post |
| **WP3** | RecommendationPolicy SQL compiler + Comments filters + lifecycle tests |
| **WP4** | CLI with `--user` |
| **WP5** | Golden v1 SupportExport test only |
| **WP6** | Full tests + runbook/AS inventory + M1–M12 regression |

---

## 12. Test matrix (required)

- Selection parity worker ↔ would-act; supersession (failed/skipped/indeterminate/tuple/policy); stale assessment_id abstains without CAS; one evaluation per comment.
- Boundary unset: would-act zero + `boundary_unset`; pre-boundary may be >0; naming never conflates.
- Zero writes from would-act (audit count unchanged).
- Fail closed: no partial candidates.
- All five Comments filters; clear; pagination; search; secondary isolation; no N+1/prefetch recursion.
- Compiler allowlist + crafted query-value injection rejection.
- CLI missing/invalid/under-capable `--user`.
- D21 threshold matrix.
- SupportExport golden v1; privacy forbidden-field checks.
- M1–M12 regressions; CI policy guards.

---

## 13. DEV / production / release

- Authorises implementation with M12 masters **default off**.
- Does not authorise DEV/prod enablement, Calibration GO, credentials, email, or customer-data access.
- Plugin SemVer **`0.8.0`**; support-export **v1**; no tag/Release/ZIP beyond this freeze tag.

---

## 14. Acceptance / NO-GO

**Accept when:** all WPs merged with CI green; locks O1–O20 hold; masters default off.

**NO-GO / stop if:** masters default on; would-act writes anything; fail-open partials; latest-*completed*-only selection ignoring supersession; hand-rolled/interpolated filter SQL; SupportExport runtime edit; AS jobs in public-contracts; hook replay; `Internal\*`; SemVer/export v2; Calibration claimed from synthetic data; automatic approve.

---

## 15. Deferred (not M13)

Customer 7-day review edits (M14 candidate); C11/C16–C17 promotion; WC importer; SemVer/Release; Calibration GO / production enablement; support-export v2.

---

## Related

- [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)
- [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md)
- [`../runbooks/operator-controls.md`](../runbooks/operator-controls.md)
- [`../runbooks/moderation.md`](../runbooks/moderation.md)
