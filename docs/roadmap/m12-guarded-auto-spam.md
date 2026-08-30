# M12 — Guarded AI-assisted auto-spam (authoritative freeze)

**Status:** Frozen M12 **product and design** specification. Authorises **documentation and calibration harness work only** at freeze time. Does **not** authorise runtime automatic action, Calibration GO, Implementation GO, Dry-run GO, DEV/production enablement, credentials, provider configuration, external-AI enablement, email, host-specific code, GitHub Release, ZIP, plugin SemVer / version tag, or movement of `v0.8.0`.  
**Baseline:** Universal Product Reviews `main` @ **`df6521201a7b30f89efa3755e1fcca663f1900cd`** (PR [#61](https://github.com/magpern/universal-product-reviews/pull/61) — M11 closure). Runtime remains **`0.8.0`**.  
**Freeze tag:** `m12-guarded-auto-spam-freeze` (annotated; peels to the merge commit of this freeze).

**Related:** [`m11-ai-moderation-recommendations.md`](m11-ai-moderation-recommendations.md), [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md), [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md) (demoted appendix under this freeze).

Generic core only: no host-, brand-, theme-, or infrastructure-specific runtime code. No WooCommerce `Internal\*` APIs. Support export schema `upr-support-export/v1` **unchanged**.

---

## 0. Authority and GO chain

This freeze materialises the approved M12 plan. Documentation freeze **does not** authorise runtime action.

| Gate | Authorises |
|------|------------|
| **Documentation freeze** (this document + ADR amendment + tag) | Design lock only |
| **Simulation GO** | Privacy-safe **synthetic / AI-generated** fixtures may validate taxonomy, CAS/ledger/hook-parity/reversibility/rate-limits/disable boundaries, and false-positive **scenarios**. Authorises **implementation with masters default-off** and **DEV/pre-production testing only** with controlled synthetic fixtures. **Never** production enablement, production customer-review action, or a claim of real-world precision/false-positive performance. Verdict: `SIMULATION GO — implementation and non-production testing only` |
| **Calibration GO** | Authorised **real-world**, human-labelled, privacy-safe evidence meeting frozen metrics. Required before any **production** automatic-action enablement may be considered. Does **not** itself turn production on. Verdict: `CALIBRATION GO — production enablement decision may be considered` |
| **Implementation GO** | Code with masters **default off** (may follow Simulation GO) |
| **Dry-run GO** | Ledger `observed` only; no CAS |
| **DEV enablement GO** | Master on in DEV / pre-prod with synthetic or separately authorised fixtures; refreshes boundary; **never production** |
| **Production enablement GO** | Requires prior **Calibration GO**; out of documentation freeze |

Harness verdict `NO-GO — automatic action deferred` when evidence is empty, incomplete, template/example, or fails gates.

If CAS/hook-parity requirements or atomic CAS+`cas_succeeded` cannot be proven on supported WordPress/MySQL/MariaDB APIs, **or** product requires automatic crash recovery with full third-party public-hook delivery, M12 closes as **deferred / NO-GO** for automatic action.

---

## 1. Sole automatic-action contract

| Field | Locked value |
|-------|----------------|
| Contract id | **`auto_spam_held_technical`** |
| Native mutation | Reversible **`hold` → `spam`** via UPR-owned **CAS** + proven public-hook parity |
| Permanently excluded | **Auto-approve** (forever out of M12); auto-trash; abuse automation; sentiment/rating/criticism outcomes; review edit/delete; customer communication; invitation lifecycle changes |
| Score alone | **Forbidden** — never acts |
| Eligibility | Currently **held**, **top-level**, **in-scope** product review only |
| Decision input | Terminal assessment; recompute `RecommendationPolicy::suggest` locally (no new provider call) |
| Conjunction (all required) | `state=completed` ∧ `confidence=high` ∧ risk ≥ frozen threshold ∧ allowlisted technical-spam reason-code ∩ ∧ ¬mandatory-human ∧ M11 recommendation exactly `likely_spam` |
| Never act on | `likely_abuse`, `likely_publishable`, `mandatory_human`, `needs_human`, low confidence, malformed/null/ambiguous output, uncalibrated tuple |

**Frozen thresholds / code sets (defaults locked):**

- Publication-risk threshold: **≥ 80** (same as M11 `RISK_HIGH_MIN`).
- Technical-spam / spam-family codes: full M11 `SPAM_FAMILY_CODES` — `spam_pattern`, `link_abuse`, `fraud_suspected`, `impersonation_suspected`.
- Mandatory-human codes: full M11 `MANDATORY_HUMAN_CODES` — `pii_suspected`, `medical_claim_suspected`, `regulatory_claim_suspected`, `safety_claim_suspected`.
- Assessment identity for ledger key: **assessment primary key** (preferred).
- Technical-spam precision floor (holdout): **≥ 95%**.
- Lease TTL default: **60 seconds**.

---

## 2. Non-negotiable invariants

1. Only currently held, top-level, in-scope product reviews.
2. Only a validated terminal assessment from an **immutable calibrated tuple** may be considered.
3. Score alone never acts.
4. Required conjunction as in §1.
5. No new provider call for the action step.
6. No PII/payload expansion beyond M9/M10.
7. No claim tokens, hashes, or prefixes in audit payloads.
8. Existing M9/M10 assessment claims, provider stamps, disable semantics, quotas, privacy, and held-only lifecycle **unchanged**.
9. Existing M11 column-only UX and no-attention-filter decision **unchanged**.
10. `review.system_spam` remains exclusively the invitation-abandon / `SystemStatusOrigin` path.

---

## 3. Strict enablement boundary (A)

| Rule | Locked behaviour |
|------|------------------|
| Option | Persist **`upr_ai_auto_action_boundary_at`** on every **off → on** master-enable transition of `upr_ai_auto_spam_enabled` |
| Live gate | Assessment `completed_at` (UTC) **must be strictly greater than** `boundary_at` |
| Equality | **Abstains** — assessments completed in the enablement second are safely skipped |
| Missing / zero boundary | **Fail closed** — no live action |
| Re-enable | **Refreshes** the boundary to now |
| Dry-run `observed` | **Never** becomes actionable later |
| Later live action | Requires a **newly completed**, strictly post-boundary assessment |

Confirm copy for operators: no historical sweep; assessments completed in the enablement second are skipped.

---

## 4. Calibrated tuple (B)

Action is allowed only for an **active, immutable** calibration approval covering all of:

1. Provider kind (`local` \| `openai`)
2. Assessor version
3. Local heuristic version **or** OpenAI model + prompt/guidance fingerprint
4. Validator version
5. Assessment policy version
6. Recommendation policy version (`RECOMMENDATION_POLICY_VERSION`)
7. Action policy version (`ACTION_POLICY_VERSION`)

Any tuple drift **invalidates** approval and requires new dry-run / calibration before re-enablement.

---

## 5. Calibration requirements (C)

| Requirement | Locked value |
|-------------|--------------|
| Legitimate-negative corpus | ≥ **400** deliberately oversampled legitimate negative/critical reviews labelled **human-not-spam** |
| Technical-spam corpus | ≥ **200** separately sized technical-spam reviews |
| Holdout | ≥ **20%** from **each** primary stratum, locked **before** threshold tuning |
| Blind double-label | ≥ **20%** overlap; adjudicate disagreements |
| Calibration GO metrics | **Holdout only** (no tuning on holdout) |
| Binding negative-safety gate | 95% Wilson upper confidence bound for false-spam on the legitimate-negative holdout **≤ 1.0%** |
| Technical-spam precision | Frozen floor **≥ 95%** on holdout |
| Mandatory-human | **Zero** would-act / act rows with mandatory-human codes |
| Evidence privacy | No review body, secret, or unnecessary PII committed to Git |

If a compliant **Calibration** corpus cannot be obtained → **Calibration GO** is not issued; production automatic action remains prohibited. **Simulation GO** may still be pursued with privacy-safe synthetic fixtures to authorise implementation (masters off) and non-production testing only.

---

## 6. CAS and normal-path hook parity (D)

### 6.1 Forbidden pattern

A naive `recheck hold → wp_set_comment_status()` is **forbidden**. Core `wp_set_comment_status()` updates by `comment_ID` only (not CAS) and can overwrite a concurrent human approve.

### 6.2 Sole candidate mutator

UPR-owned conditional write equivalent to:

```sql
UPDATE {prefix}comments
SET comment_approved = 'spam'
WHERE comment_ID = %d AND comment_approved = '0'
```

| Affected rows | Behaviour |
|---------------|-----------|
| **0** | Human wins; **no** success-path hooks; ledger → token-matched `abstained` (`status_changed`) |
| **1** | Normal non-crash path executes the frozen public-hook / cache / count parity sequence under `AiActionOrigin` |

### 6.3 Supported WordPress pin

| Coordinate | Pin |
|------------|-----|
| Plugin header | **Requires at least: 6.5** |
| Primary CI parity reference | **wp-phpunit/wp-phpunit `7.1.0`** → WordPress zip **7.1** (DEV integration leg PHP 8.4 / WC 11.0.1) |
| Compatibility floor leg | WordPress **6.5** (non-blocking CI); re-proof required before re-enablement if sequence drifts |
| Re-proof trigger | Any WP major/minor bump that changes `wp_set_comment_status` / `wp_transition_comment_status` observables |

### 6.4 Parity matrix — held (`'0'`) → spam (must match core)

Assume pre-CAS: `$comment_old = clone get_comment( $id )` with `comment_approved === '0'`; requested status string `'spam'`.

| Step | Obligation | Exact behaviour |
|------|------------|-----------------|
| 0 | Precondition | Clone old comment object **before** write (as core does) |
| 1 | Conditional DB write | CAS `UPDATE` above; abort sequence if ≠1 row |
| 2 | Cache | `clean_comment_cache( $comment_id )` |
| 3 | Reload | `$comment = get_comment( $comment_id )` (must show `comment_approved === 'spam'`) |
| 4 | Action | `do_action( 'wp_set_comment_status', $comment->comment_ID, 'spam' )` — second arg is the **requested** status string `'spam'` |
| 5 | Transition helper | `wp_transition_comment_status( 'spam', $comment_old->comment_approved, $comment )` i.e. raw old `'0'` |
| 5a | Status mapping | `new`: `'spam'` unchanged; `old`: `'0'` → **`'unapproved'`** |
| 5b | Hook | `do_action( 'transition_comment_status', 'spam', 'unapproved', $comment )` |
| 5c | Hook | `do_action( 'comment_unapproved_to_spam', $comment )` |
| 5d | Hook | `do_action( 'comment_spam_' . $comment->comment_type, $comment->comment_ID, $comment )` — product reviews typically **`comment_spam_review`** |
| 6 | Counts | `wp_update_comment_count( $comment->comment_post_ID )` (respect deferred counting) |
| 7 | Return | Success boolean aligned with core callers |

**Core note:** For spam, core does **not** add `wp_new_comment_notify_postauthor` (approve-only). CAS must not invent approve-path side effects.

### 6.5 UPR listener expectations

| Listener | Hook / priority | On CAS success | On CAS 0-row |
|----------|-----------------|----------------|--------------|
| M5 `ModerationAudit::on_transition` | `transition_comment_status` @10 | Emits **`review.ai_auto_spam`** (via `AiActionOrigin`), **not** `review.system_spam` | No AI success-path status audit |
| M9 `AssessmentLifecycle::on_transition` | `transition_comment_status` @15 | Non-held revoke / retention as today for spam | No spurious revoke from failed CAS |
| M11 Comments UI | derive-at-read | Actionable labels hide once status is spam | Unchanged if still hold |

Third-party plugins must see the **same** public hook names, order, and mapped old/new statuses as core `wp_set_comment_status( $id, 'spam' )` after held→spam.

### 6.6 Instrumented parity transcript test (mandatory)

Compare instrumented hook transcript of:

1. **Reference:** held comment + core `wp_set_comment_status( $id, 'spam' )`
2. **CAS path** under `AiActionOrigin`

Assert equal ordered public hooks and arguments for steps 4–6; assert M5/M9 observables; assert concurrent approve → 0-row CAS leaves approved comment and produces no spam transition hooks.

**If exact public-hook parity cannot be proven using supported WordPress APIs → automatic action is NO-GO.**

---

## 7. Leased action ledger and crash protocol (E)

### 7.1 Ledger authority

One durable action ledger is the sole authority:

| Item | Locked design |
|------|----------------|
| Table | `{prefix}upr_moderation_action_ledger` |
| Primary key | `(comment_id, assessment_id_or_fingerprint, action_policy_version)` — prefer assessment PK |
| States | `processing` · `cas_succeeded` · `acted` · `abstained` · `observed` · `unknown_after_crash` |
| Lease fields | Opaque `lease_token`; `lease_expires_at`; optional `lease_owner` while `processing` |

### 7.2 Lease and terminal rules

- Acquire / recover only **`processing`** leases (recover expired `processing` only).
- Terminal writes require the **matching lease token**.
- `acted`, `abstained`, `observed`, `cas_succeeded`, and `unknown_after_crash` **block** another CAS for the same key.
- In **one shared DB transaction**: successful CAS and token-matched `processing → cas_succeeded` must **commit together**.
- If that shared transaction cannot be proven on supported WordPress / MySQL / MariaDB → **automatic-action NO-GO**.
- **Never** infer that AI acted merely because a comment is currently spam.
- **Never** replay the CAS after `cas_succeeded` / terminal / `unknown_after_crash`.
- **Never** replay public WordPress transition hooks after a crash.

### 7.3 Happy path (no crash)

1. Gates: master on, kill off, not dry-run, calibrated tuple, `completed_at > boundary`, `likely_spam`.
2. Lease → `processing`.
3. Re-check gates; fail → token-matched `abstained`.
4. **Txn:** CAS + `cas_succeeded` (or 0-row → `abstained`).
5. Same PHP request: §6.4 hooks **exactly once** + distinct `review.ai_auto_spam` audit under `AiActionOrigin`.
6. Token-matched `cas_succeeded → acted`.

### 7.4 Crash after `cas_succeeded` (before/during hooks)

1. Terminalise ledger as **`unknown_after_crash`** (token-matched / recovery authority).
2. Retain durable AI-owned CAS evidence (e.g. `ai_cas_committed_at`) — proves AI mutated hold→spam; does **not** claim normal completion.
3. **Do not** re-CAS; **do not** replay public hooks; **do not** emit success `review.ai_auto_spam`.
4. Surface a **critical** diagnostic (D20 / Site Health) for **manual operator reconciliation**.
5. UPR may update **private** ledger/diagnostic state only.

### 7.5 Crash while still `processing` (no `cas_succeeded`)

- Still hold → recovered worker may retry full happy-path under a new lease.
- Already spam without `cas_succeeded` → `unknown_after_crash` **without** AI CAS evidence (do not attribute human/system spam to AI); no CAS replay; no public-hook replay.

### 7.6 State matrix

| State | May CAS? | Public hooks? | Success `review.ai_auto_spam`? |
|-------|----------|---------------|--------------------------------|
| `processing` | Yes (once) | No | No |
| `cas_succeeded` | **Never** | Only continuing **same unbroken request** toward `acted` | Only as part of that happy path |
| `acted` | Never | No | Already emitted once |
| `abstained` | Never | No | No |
| `observed` | Never | No | Dry-run only |
| `unknown_after_crash` | **Never** | **Never** (no replay) | **Never** |

Dry-run: **`observed`** only (no CAS). Restore-to-hold: all terminal states including `unknown_after_crash` block re-action on the same assessment key.

**Product NO-GO:** If automatic recovery with full third-party public-hook delivery is required, M12 automatic action is NO-GO.

---

## 8. Origin, audit, and diagnostics

| Concern | Design |
|---------|--------|
| Marker | `AiActionOrigin` wraps **only** the CAS mutator + happy-path hook sequence |
| Event | **`review.ai_auto_spam`** — never `review.system_spam` |
| Origin marker | `upr_ai_auto_action` |
| Payload on `acted` | Allowlisted operational fields only |
| Payload on `unknown_after_crash` | **No** success `review.ai_auto_spam`; critical diagnostic may record assessment id, whether AI CAS was durable, reconciliation needed — **no** lease tokens, body, secrets |
| Invitation abandon | Still `SystemStatusOrigin` → `review.system_spam` |

`ModerationAudit` branches on `AiActionOrigin` **before** generic system spam classification.

### Controls (implementation, masters default off)

| Control | Behaviour |
|---------|-----------|
| Master | `upr_ai_auto_spam_enabled` — default **off**; every off→on refreshes boundary |
| Kill switch | Independent; force abstain; no new leases; no invented AI attribution; no hook replay |
| Dry-run | `observed` only; zero status mutation |
| Quotas / circuit | Separate from assessment ops |
| Capability | `manage_woocommerce` + nonce for controls |
| Diagnostics D20 | Counts for all ledger states; **critical** when `unknown_after_crash` with AI CAS evidence; never count those as successful acts |
| Support export | Unchanged unless a later freeze adds aggregates |
| Schema | Additive ledger only; forward-only; no downgrade on disable |

---

## 9. Work packages (post-Simulation GO or post-Calibration GO)

Implementation (masters default off) may proceed after **Simulation GO** or **Calibration GO**. Production enablement requires **Calibration GO** plus a separate production enablement GO.

| WP | Deliverable |
|----|-------------|
| **WP0** | This freeze + ADR-0004 amendment + roadmap/runbook/appendix updates + freeze tag |
| **WP1** | Calibration harness + privacy-safe evidence format (read-only; no providers / status changes) |
| **WP2** | `ActionPolicy`, tuple gate, fail-closed abstention |
| **WP3** | CAS mutator under `AiActionOrigin` + happy-path hook parity |
| **WP4** | Durable leased ledger + crash protocol (no hook replay) |
| **WP5** | Boundary, Controls, D20, Site Health aggregates |
| **WP6** | Required tests (§10) + M1–M11 regression; masters remain off |
| **WP7** | Closure documentation |

---

## 10. Required test coverage (implementation)

- Core-versus-CAS normal-path hook transcript parity
- Concurrent moderator approve wins with zero-row CAS
- No auto-approve, auto-trash, abuse action, sentiment/rating rejection, or score-only action
- Action-policy conjunction and mandatory-human precedence
- Tuple mismatch / drift abstention
- Ledger uniqueness and no re-action after restore on the same assessment
- Lease expiry before CAS recovery
- Successful CAS plus ledger `cas_succeeded` atomicity
- Crash after `cas_succeeded` before/during hooks → `unknown_after_crash`, no hook replay, no second CAS, critical diagnostic
- No AI attribution merely from current spam status
- Strict boundary equality and pre-boundary abstention
- Re-enable boundary refresh
- Dry-run `observed` never promotes
- Audit distinction from `review.system_spam`
- No token material in audits
- Full M1–M11 regression

---

## 11. Explicit non-actions of this freeze

- No SemVer bump, version metadata PR, version tag, GitHub Release, ZIP
- No DEV/prod deployment, credentials, provider configuration, external-AI enablement, email
- No host-specific code; do not move `v0.8.0`
- No runtime PHP for auto-action in the freeze PR
- No Calibration GO, Implementation GO, Dry-run GO, or enablement GO implied by merge

---

## 12. Closure pointer

Closure: [`m12-guarded-auto-spam-closure.md`](m12-guarded-auto-spam-closure.md). Current production posture: **NO-GO — automatic action deferred** until Calibration GO. Simulation GO (when issued) authorises implementation + non-production testing only — see [`m12-calibration-nogo.md`](m12-calibration-nogo.md).
