# M11 — AI Moderation Recommendations (authoritative freeze)

**Status:** Frozen M11 product and implementation specification. Authorises **implementation** of recommendation-only operator guidance on top of M9/M10 assessments. Does **not** authorise automatic comment-status mutation, GitHub Release, ZIP, plugin SemVer / version tag, DEV/production WordPress access, deployment, email, real OpenAI enablement, credentials configuration, or M12 auto-action.  
**Baseline:** Universal Product Reviews `main` @ **`22918f19f89d350de765edd0641f8d2a0aaa5920`** (PR [#58](https://github.com/magpern/universal-product-reviews/pull/58) — M10 final corrections closure).  
**Release sequencing:** Runtime version remains **`0.8.0`**. Do **not** bump SemVer, create `v*` tags, GitHub Releases, or ZIPs in this initiative.  
**Freeze tag:** `m11-ai-moderation-recommendations-freeze` (annotated; peels to the merge commit of this document).

**Related:** [`m9-local-ai-shadow-mode.md`](m9-local-ai-shadow-mode.md), [`m10-external-ai-advisory-assessments.md`](m10-external-ai-advisory-assessments.md), [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md), [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md) (M12 appendix).

Generic core only: no host-, brand-, theme-, or infrastructure-specific runtime code. No WooCommerce `Internal\*` APIs. No provider filter / arbitrary callback extension point. Support export schema `upr-support-export/v1` **unchanged**.

---

## 1. Scope

| WP | Deliverable |
|----|-------------|
| **WP0** | This freeze + ADR-0004 amendment + roadmap/runbook updates |
| **WP1** | `RecommendationPolicy` + value object; unit matrix (risk direction) |
| **WP2** | Native Comments AI advisory **column** enhancements (held-only actionable labels) |
| **WP3** | Recommendation display option; diagnostics / Site Health aggregates |
| **WP4** | Integration + regression tests; closure evidence |

**Non-goals:** Auto-approve, auto-spam, auto-trash, auto-hold, sentiment/rating-based rejection; native Comments attention filter/view; recommendation projection table; provider-emitted `suggested_action`; free-form model explanations; outbound provider payload expansion; support-export contract change; SemVer / Release / ZIP / DEV-prod enablement; M12 auto-action implementation.

---

## 2. Locked decisions (M11)

| ID | Decision | Locked value |
|----|----------|--------------|
| **R1** | Milestone role | **Recommendation-only.** M11 never changes WordPress comment status |
| **R2** | Auto-action | Forbidden in M11. Deferred to **M12** (requires new ADR amendment, calibration evidence, dry-run, separate master enable, kill switch, separate product GO) |
| **R3** | Forbidden actions | No auto-approve, auto-spam, auto-trash, auto-hold, or sentiment/rating-based rejection |
| **R4** | Score direction | `publication_safety_score` is a **risk score**: **higher = greater publication/policy risk** (less safe to publish). UI copy says “risk score” |
| **R5** | Mapper inputs | Validated terminal fields only: `state`, risk score, `confidence`, allowlisted `reason_codes`. No provider-emitted suggested actions |
| **R6** | `suggested_action` allowlist | Exactly: `needs_human`, `likely_publishable`, `likely_spam`, `likely_abuse`, `mandatory_human` |
| **R7** | Policy constants | `RECOMMENDATION_POLICY_VERSION`; risk ≥ **80** (high-risk paths); risk ≤ **40** (`likely_publishable`); freeze-locked, not operator sliders |
| **R8** | Held-only actionable UI | Actionable labels + reason badges **only** while current status is `hold` |
| **R9** | Leave-hold | On approve/spam/trash (or any non-hold): **hide** recommendation labels and reason badges; **retain** assessment row for audit/retention; no re-analysis on non-hold |
| **R10** | Restore to hold | Reassessment / re-analysis follows existing M9/M10 held-only eligibility and claim rules |
| **R11** | Attention view | **None** in M11 (no queryable projection; no page-only post-filter) |
| **R12** | Display option | Key e.g. `upr_ai_recommendations_display`; **absent = enabled**; independent of local/external shadow masters; `manage_woocommerce` to change; no confirm required to hide/show |
| **R13** | Privacy | M11 adds **no** provider payload fields. No additional account/order/email/token/URL fields. Review text remains untrusted and **may contain personal data**. M10 enablement acknowledgements remain the controlling boundary for external transmission |
| **R14** | Support export | `upr-support-export/v1` unchanged |
| **R15** | Release | No SemVer / Release / ZIP / `v*` tag in this initiative |
| **R16** | M9/M10 invariants | Held/top-level/in-scope eligibility; one-txn claim finalize; immutable `claim_provider_kind`; disable-silent clears; human status wins races; external AI default off — all preserved |

---

## 3. Recommendation policy (deterministic)

`RecommendationPolicy::suggest( $assessment ): Recommendation` maps validated assessment fields to `suggested_action`.

**Precedence (evaluate in order):**

1. Malformed / non-array / invalid stored row → `needs_human`
2. `state` ∈ {`failed`, `skipped`, `indeterminate`} → `needs_human`
3. `confidence` = `low` or score `null` / non-integer → `needs_human`
4. Any reason code in mandatory-human set → `mandatory_human`  
   Set: `pii_suspected`, `medical_claim_suspected`, `regulatory_claim_suspected`, `safety_claim_suspected`
5. `state=completed` + `confidence=high` + risk ≥ 80 + any spam-family code and **no** mandatory-human code → `likely_spam`  
   Spam-family: `spam_pattern`, `link_abuse`, `fraud_suspected`, `impersonation_suspected`
6. `state=completed` + `confidence=high` + risk ≥ 80 + any abuse-family code → `likely_abuse`  
   Abuse-family: `abuse_harassment`, `threat_suspected`, `hate_suspected`
7. `state=completed` + `confidence` ∈ {`medium`,`high`} + risk ≤ 40 → `likely_publishable`
8. Else → `needs_human`

**Operator copy:**

- `likely_publishable` must include fixed i18n text equivalent to: **advisory — human must approve**
- Explanations = fixed translated templates + allowlisted reason-code labels only (bounded). No free-form model prose.

---

## 4. Comments-admin UX

- Extend existing **AI advisory** column only ([`CommentListEnhancements`](../../src/Moderation/CommentListEnhancements.php)).
- Escape all output. No raw provider JSON, model prose, tokens, secrets, or URLs-as-actions.
- No CSS-heavy UI, React app, new queue, attention filter/view, or page-only Comments query post-filtering.
- Respect M5 bounded-prefetch and recursion protections.
- Re-analysis capability/nonce model unchanged (held-only; OpenAI path still `manage_woocommerce`).

---

## 5. Controls and diagnostics

| Control | Behaviour |
|---------|-----------|
| Recommendation display | Absent = enabled; disabled hides actionable labels; retains assessments |
| Auto-action | Hard-off / unavailable in UI copy (“requires M12”) |
| Diagnostics / Site Health | Policy version; “risk score: higher means greater publication risk”; auto-action unavailable; optional aggregate suggestion distribution **without** projection table or export-contract change |

---

## 6. Architecture

```text
M9/M10 assessment pipeline (unchanged)
        → terminal row in upr_moderation_assessments
        → RecommendationPolicy (local, derive-at-read)
        → Comments column (hold only: actionable labels)
Human Approve / Spam / Trash → hide actionable labels; retain row
```

M11 adds **zero** calls to `wp_set_comment_status` or equivalent from AI/recommendation code.

---

## 7. Work packages and acceptance

See §1. Mandatory tests include: risk-direction matrix (fail if inverted); mandatory-human precedence; abstention paths; held-only visibility; leave-hold hide; restore eligibility; display option semantics; no status mutation; support-export stability; M9/M10 race regressions; no attention view.

---

## 8. M12 forward pointer

Guarded automatic action (if ever) is **M12**, not M11. Gates: ADR amendment authorising a named action contract; calibration including legitimate negative reviews; dry-run; separate master enable + kill switch; never auto-approve without near-zero false-approval evidence; never sentiment/rating rejection. Appendix: [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md).

---

## 9. Closure

Closure: [`m11-ai-moderation-recommendations-closure.md`](m11-ai-moderation-recommendations-closure.md).
