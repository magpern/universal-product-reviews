# M15 — Operator AI Moderation Queue (authoritative freeze)

**Status:** Frozen M15 **product and implementation** specification. Authorises **documentation freeze** and **implementation on this freeze**. Does **not** authorise Calibration GO, production enablement, DEV/production WordPress access, credentials, provider configuration, external-AI enablement, email, auto-spam master enablement, host-specific code, GitHub Release, ZIP, plugin SemVer / version tag, or movement of `v0.8.0`.  
**Baseline:** Universal Product Reviews `main` @ **`daa065ff27e85db7fd27ac133af49c5675d15157`** (PR [#77](https://github.com/magpern/universal-product-reviews/pull/77) — M14 acceptance / C20 promotion). Runtime remains **`0.8.0`**.  
**Freeze tag:** `m15-operator-ai-moderation-queue-freeze` (annotated; peels to the merge commit of this document).

**Related:** [`m5-review-moderation-operations.md`](m5-review-moderation-operations.md), [`m11-ai-moderation-recommendations.md`](m11-ai-moderation-recommendations.md), [`m13-operator-ai-command-surface.md`](m13-operator-ai-command-surface.md), [`m14-customer-seven-day-review-edits-closure.md`](m14-customer-seven-day-review-edits-closure.md), [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md), [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md) §12.

Generic core only: no host-, brand-, theme-, or infrastructure-specific runtime code. No WooCommerce `Internal\*` APIs. Support export schema **`upr-support-export/v1` unchanged**. No new public `upr_*` host filters. No new Action Scheduler hook. No schema / `DB_VERSION` bump.

---

## 0. Problem and users

**Problem:** Operators lack a clear held-review queue that surfaces existing AI assessments in a privacy-safe, operator-readable form while leaving final moderation decisions to humans.

**Users:** Comment moderators (`moderate_comments`); WooCommerce shop managers on Overview (`manage_woocommerce`).

**Milestone role:** Enhanced **native Comments** held queue only. Assessment is advisory. The operator—not AI—chooses Publish, Keep on hold, Mark as spam, or Move to trash. AI never mutates comment status.

---

## 1. Locked decisions (Q1–Q7)

### Q1 — Queue surface

| ID | Decision | Locked value |
|----|----------|--------------|
| **Q1** | Surface | **Comments-primary.** Default operator entry: `edit-comments.php?upr_view=pending`. No parallel `WP_List_Table`, SPA, REST queue, or second comment query engine. |
| **Q1-link** | Plugin action link | The UPR plugin-row action labelled **“Product reviews”** is shown only to users with `moderate_comments`. It links **exactly** to `edit-comments.php?upr_view=pending`. It does **not** introduce a new permission model or expose held-review counts to users without that capability. |
| **Q1-overview** | Overview held count | Show the held product-review count to users with the existing Settings/Overview capability (`manage_woocommerce`). Render the **clickable** deep link to `edit-comments.php?upr_view=pending` **only** when the user **also** has `moderate_comments`; otherwise render the **count without a link**. The count is **one bounded aggregate** using the existing admin-cache pattern; unavailable count renders fixed **“Held review count unavailable”** copy (still unlinked unless both caps hold). It must **not** fetch comment IDs, bodies, authors, emails, assessment text, or create a parallel queue query. |
| **Q1a** | Forbidden UI | No second list table; no REST/SPA; no page-only PHP post-filter; no `comment__in` of all held IDs; no `post__in` of all products |
| **Q1b** | Pagination | Core `comments_per_page` + core `found_comments`. Preserve M5 `is_comments_list_query()` guards |
| **Q1c** | Filtering | Existing: `upr_view`, `upr_source`, `upr_recommendation`, core search/status, WC review-type (AND). No new attention-by-score view |
| **Q1d** | Sorting | **Native Comments date order only.** No `ORDER BY` risk score / recommendation |
| **Q1e** | Empty state | When pending view has zero rows: native empty table + escaped copy “No product reviews awaiting moderation.” |
| **Q1f** | Prefetch | Reuse `CommentListPrefetch` + `AssessmentRepository::latest_for_comments` on **displayed IDs only**. Reentrancy guard unchanged |

### Q2 — Assessment presentation

Existing stored taxonomy is **publication-risk**, not quality/helpfulness ([ADR-0004](../decisions/ADR-0004-ai-moderation-boundary.md), M8 D2). M15 adds a **derive-at-read** presenter — **no** new assessment columns, reason codes, or provider payload fields.

| ID | Decision | Locked value |
|----|----------|--------------|
| **Q2** | Presenter | `QueueAssessmentPresenter` maps latest assessment **of any state** (same as Comments column / M13 advisory split) to bounded dimension DTOs. Never falls back to an older completed row. |
| **Q2a** | Overall / “Likely acceptable” | `likely_publishable` remains the **stored enum and recommendation-filter token**. M15 changes **only** the operator-facing display label **rendered by `QueueAssessmentPresenter`** to **“Likely acceptable (advisory — human must publish)”**. Do **not** change `RecommendationPolicy::action_label()` globally; existing filters, CLI, and M11 display remain unchanged. The queue presenter owns this mapping and must **never** output “approved” or “approve” for AI output. Other presenter labels: Likely spam; Likely abuse; Mandatory human review; Needs human review. |
| **Q2b** | Dimensions | **Spam likelihood** ← spam-family codes; **Relevance** ← `off_topic`; **Safety/policy concern** ← mandatory-human + abuse-family; **Content signal** ← `insufficient_signal` / `unsupported_language`. User-facing name is **“Content signal”**, not “content quality”. |
| **Q2c** | Dimension values | Each dimension: `unavailable` \| `none_indicated` \| `suspected` (plus content-signal specifics `insufficient_signal` / `unsupported_language`). No numeric sub-scores beyond the existing risk score on **completed** rows. |
| **Q2d** | Neutral states | See §2 |
| **Q2e** | Rationale | Short structured list: i18n templates + ≤8 allowlisted reason-code labels (`_` → space). `esc_html` only. No model prose, HTML, URLs-as-actions, or executable content. |
| **Q2f** | Held vs not | Held: show dimensions + overall. Non-held: **“Historical assessment”** only (M11 R9). Stale-while-held: §2 — **not** “Historical assessment”. |
| **Q2g** | Rendering location | Structured, visible, escaped **`<dl>` inside the existing `upr_ai` table cell** on the **held pending view only**. Compact summary may remain the first line of that same cell. **No** row-extra hook, alternate panel, or React. |

### Q3 — Operator actions

| Queue label | WP status | Mechanism |
|-------------|-----------|-----------|
| **Publish** | `approve` | **Relabel only** of native Approve on the held UPR queue. Mutation remains native. |
| **Keep on hold** | `hold` (no change) | **Sole UPR `admin-post` action.** Zero status write. |
| **Mark as spam** | `spam` | **Relabel only** of native Spam. |
| **Move to trash** | `trash` | **Relabel only** of native Trash. |

#### Native Publish / Mark as spam / Move to trash

M15 **does not replace or harden** WordPress core moderation authorization.

- Native Publish / Mark as spam / Move to trash retain **WordPress core** capability, nonce, race, and last-write semantics.
- UPR **only relabels** the native action text on the held UPR queue.
- Existing M5 `transition_comment_status` audit remains authoritative and records `origin=operator` for normal administrator moderation ([M5 freeze](m5-review-moderation-operations.md)).
- Product and order **links** remain separately capability-gated (M5 L5). That gating does **not** apply to native status actions.
- **No new direct status-write endpoint** is introduced.
- No UPR stale-status refusal, extra confirm/interstitial, or `edit_post` authorization for those native actions.

#### Keep on hold (sole UPR `admin-post`)

| ID | Decision | Locked value |
|----|----------|--------------|
| **Q3** | No “Deny” | No operator-facing queue action label, bulk label, or queue translation uses “Deny”. Security error messages and internal `denied` identifiers are unaffected. |
| **Q3a** | Keep-on-hold caps | Requires `moderate_comments`, valid nonce, and an **in-scope held** product review. Fail closed. `manage_woocommerce` is **not** required. **No** extra `edit_post` requirement. OpenAI re-analyse remains `manage_woocommerce` (M10). |
| **Q3b** | Native caps | Native Publish/Spam/Trash: **WordPress core only**. UPR does not hide, wrap, or re-check those actions. |
| **Q3c** | Keep-on-hold nonce | `check_admin_referer( 'upr_queue_keep_hold_' . $comment_id )`. CSRF failure → refuse, **no** status write, **no** audit. |
| **Q3d** | Bulk | Native bulk Approve/Spam/Trash remain, relabeled on pending view only. **Keep on hold is not a bulk action.** |
| **Q3e** | Confirm | Native Publish/Spam/Trash: **no** UPR extra confirm. Keep on hold: **no** confirm. |
| **Q3f** | Keep-on-hold race | Makes **no** status write and **never** calls a status API. Observed **non-held** → refuse with allowlisted `stale_status`, **write no audit**. Defer audit means **“held when observed”**, not a durable hold lease and **not** an M12 exemption. Must **not** suppress later native moderation or auto-spam policy. |
| **Q3g** | Status API CI | `src/Ai/**` must not call status APIs. Keep-on-hold lives under `src/Moderation/` or `src/Admin/`, not `src/Ai/`. No status-API allowlist entry — Keep on hold never calls those APIs. |
| **Q3h** | Hooks | Preserve M5/M9/M12 transition semantics. Relabeled native Publish/Spam/Trash **must not** set `SystemStatusOrigin` or `AiActionOrigin`. Keep on hold fires **no** `transition_comment_status`. |

### Q4 — AI boundary

| ID | Decision | Locked value |
|----|----------|--------------|
| **Q4** | Advisory only | Assessment is presenter input. Operator actions work with **no** assessment. |
| **Q4a** | Mutation ban | AI / RecommendationPolicy / ActionWorker / WouldActReport **cannot** publish, hold, spam, trash, or enqueue those actions. |
| **Q4b** | Safeguards | Queue must not weaken M14 edit claims/CAS, M9 claim-before-rate, M10 fail-closed OpenAI, M12 default-off masters, or held-only eligibility. Keep on hold must not suppress later native moderation or auto-spam. |
| **Q4c** | After content edit | Latest row is `skipped`/`content_edited` (M14 E25). Queue shows **Stale — content edited**, not prior completed scores. Re-assessment only if still held **and** shadow on (existing E33). |
| **Q4d** | External / local-only / disabled | Typed failure or disable-silent per M9/M10; operator can still moderate. No silent local fallback on OpenAI paths. |

### Q5 — Audit and privacy

| ID | Decision | Locked value |
|----|----------|--------------|
| **Q5** | Status-changing decisions | Native Publish/Spam/Trash continue to emit **unchanged** `review.status_changed` (M5 L16). That event **is** the status decision and actor. Do **not** add assessment text, reason codes, assessment ids, or policy versions. |
| **Q5a** | Keep on hold | New event **`review.operator_deferred` only**. **Payload exactly:** `comment_id`, `product_id`, `old_status` (`hold`), `new_status` (`hold`), `queue_action` (`keep_hold`), `assessment_available` (bool), `assessment_state` (`none` or a terminal allowlist value), `actor_id`, `origin` (`operator`). Logged through existing `AuditLogger` with `actor_type=moderator`. The audit **row** may carry the same already-allowed associated order / order-item identifiers as M5 `review.status_changed`, derived through existing `ReviewContext`; **those identifiers are not added to the payload**. Never adds order identifiers to SupportExport or the queue UI. **Request-local dedupe** means duplicate emissions from **one request** only; **separate deliberate Keep-on-hold requests are separately auditable**. **Forbidden on payload:** `assessment_id`, `policy_version`, assessment text, reason codes, rationale, body/email/token/URL/prompt/raw provider/API key/ciphertext/unbounded error/claim/lease tokens. |
| **Q5b** | Companion event | **None.** No `review.operator_queue_decision`. |
| **Q5c** | SupportExport | **No runtime change**; golden v1 test remains. |
| **Q5d** | Retention | Assessment purge unchanged. `review.operator_deferred` inherits M5: **no** audit TTL/purge. |
| **Q5e** | Forbidden everywhere | Review body, customer email, token, URL, raw provider output, prompt, API key, ciphertext, unbounded error text, claim/lease tokens — queue, audit, diagnostics, CLI, SupportExport. |

### Q6 — Accessibility and usability

| ID | Decision | Locked value |
|----|----------|--------------|
| **Q6** | Native admin first | `WP_List_Table` Comments semantics; visible text labels; no color-only meaning |
| **Q6a** | Keyboard | Native row-action links/forms; Keep-on-hold keyboard-operable |
| **Q6b** | Feedback | Native Publish/Spam/Trash: **core** notices only. Keep on hold: UPR notices for success / `stale_status` / cap / nonce refuse. Screen-reader text on Keep-on-hold includes comment ID + product title (not email) |
| **Q6c** | JS | No new SPA. No UPR confirm/interstitial on native Publish/Spam/Trash |

### Q7 — Testing, contracts, schema, release

| ID | Decision | Locked value |
|----|----------|--------------|
| **Q7** | Tests | Focused PHPUnit (unit + integration). No Playwright unless proven necessary (not expected). |
| **Q7a** | Schema | **None.** |
| **Q7b** | Public contracts | **No C21.** C19/C20 untouched. No new public `upr_*` filters. AS job names stay runbook/internal. |
| **Q7c** | Release | Runtime **`0.8.0`**. No SemVer, `v*` tag movement, Release, ZIP, deploy. |
| **Q7d** | Enablement | Does **not** authorise OpenAI enablement, M12 masters, Calibration GO, credentials, or production auto-spam. |

---

## 2. Neutral / non-completed display (held)

| Condition | Operator copy (fixed i18n) | Overall | Dimensions |
|-----------|----------------------------|---------|------------|
| No assessment row | Assessment unavailable | needs human (implicit) | all `unavailable` |
| Recommendations display option off | — (existing) | hidden | hidden |
| Shadow/external off, historical row exists | Show row per rules; no re-analyse | per latest | per latest |
| `state=failed` + allowlisted `failure_code` | Assessment failed — {code label} | needs human | `unavailable` |
| `skipped` + `content_edited` | Stale — content edited | needs human | `unavailable` (do **not** show prior completed risk/reasons) |
| `skipped` + other failure | Assessment skipped — {code} | needs human | `unavailable` |
| `indeterminate` | Assessment inconclusive | needs human | `unavailable` |
| `failed`/`skipped` `credential_missing` | External credential missing | needs human | `unavailable` |
| `budget_exceeded` | External quota exhausted | needs human | `unavailable` |
| `provider_unavailable` / `circuit_open` | Provider unavailable | needs human | `unavailable` |
| Disable-silent (M9: no row, no AI audit) | Assessment unavailable | — | `unavailable` |
| Local-only mode (`AiProvider::selected()=local`) | Show `local`; OpenAI re-analyse hidden | per row | per row |

Failure-code labels are allowlisted phrases only (no exception text).

---

## 3. Architecture

```text
edit-comments.php?upr_view=pending
  └─ CommentListEnhancements (M5/M11/M13 + M15)
       ├─ upr_ai cell: QueueAssessmentPresenter → <dl> (held pending only)
       ├─ Relabel: Publish / Mark as spam / Move to trash (native mutation)
       └─ Keep on hold → admin-post (no status API) → review.operator_deferred

Overview (manage_woocommerce)
  └─ Held count aggregate; link only if also moderate_comments

src/Ai/*  ─x─ never calls status APIs
```

---

## 4. Work packages

| WP | Deliverable |
|----|-------------|
| **WP0** | This freeze + roadmap / ARCHITECTURE / ADR / runbook index; freeze tag |
| **WP1** | `QueueAssessmentPresenter` + structured `<dl>` inside `upr_ai` on held pending view; presenter-only “Likely acceptable” mapping (`action_label()` unchanged) |
| **WP2** | Native action text relabel; Keep-on-hold `admin-post`; empty state; plugin-row link; Overview bounded held-count with dual-cap link rule |
| **WP3** | `review.operator_deferred` via `AuditLogger` (`actor_type=moderator`, Q5a payload + M5-style row order ids); privacy allowlist tests |
| **WP4** | Full test matrix + M5–M14 regression + runbook rewrite |

---

## 5. Explicit non-goals

Parallel moderation dashboard; React/SPA; custom comment datastore; changing stored `suggested_action` tokens or reason-code allowlist; changing `RecommendationPolicy::action_label()`; adding “quality”/sentiment assessor fields; auto-approve; enabling M12; OpenAI/credentials; M14 edit behavior changes; Deny as an operator-facing queue action; bulk keep-on-hold; M12 exemption on deferral; replacing or hardening core Publish/Spam/Trash authorization; extra confirm/stale-refuse/`edit_post` on native status actions; new status-write endpoint; companion audit event for Publish/Spam/Trash; `assessment_id` / `policy_version` on `review.operator_deferred` payload; exposing Overview held counts without Settings/Overview capability or linking without `moderate_comments`; exposing held counts on the plugin row without `moderate_comments`; fetching comment IDs/bodies for the Overview count; audit TTL; SupportExport v2; `Internal\*`; SemVer/Release/ZIP/deploy; host-specific UI.

---

## 6. Test matrix (required)

- **Presenter:** all §2 states; stale `content_edited` never shows prior completed risk; non-held → Historical only; display option off → —; reason-code cap 8; forbidden fragments absent; `QueueAssessmentPresenter` maps `likely_publishable` to “Likely acceptable (advisory — human must publish)” and never “approved”/“approve”; `RecommendationPolicy::action_label()` still returns the M11 “Likely publishable …” string; `<dl>` inside `upr_ai` on pending+held only.
- **Plugin link:** “Product reviews” shown iff `moderate_comments`; href exactly `edit-comments.php?upr_view=pending`; no held-count in plugin-row markup.
- **Overview count:** count with `manage_woocommerce`; clickable link only when also `moderate_comments`; otherwise count/unavailable copy with no `href`; one bounded cached aggregate; failure → exact “Held review count unavailable”; no comment IDs/bodies/authors/emails/assessment text; no parallel queue query.
- **Query:** pending pagination/`found_comments`; recommendation + source AND; search; no N+1; prefetch reentrancy; secondary `comment__in` isolation (M5).
- **Caps/nonce:** Keep-on-hold refuses without `moderate_comments`, without valid nonce, or when out of scope / not held (`stale_status`, no audit). Native Publish/Spam/Trash not wrapped with extra UPR checks. OpenAI re-analyse still `manage_woocommerce`. Product/order links still require object-level `edit_post` (M5).
- **Actions:** Publish/Spam/Trash → native transition + `review.status_changed` origin operator; no UPR status-write endpoint; Keep-on-hold → no `comment_approved` change, no status API, + `review.operator_deferred` when observed held; observed non-held → `stale_status` and no audit. No `review.operator_queue_decision`.
- **Defer audit:** `AuditLogger` with `actor_type=moderator`; payload keys exactly Q5a; row may include M5-style order/order-item ids; those ids absent from SupportExport and queue HTML; two Keep-on-hold requests in separate requests emit two events; duplicate emissions in one request are deduped.
- **Deny wording:** no operator-facing queue action label, bulk label, or queue translation uses “Deny”; security error messages and internal `denied` identifiers are unaffected.
- **AI cannot mutate:** `src/Ai` grep status APIs; presenter/policy/WouldActReport leave status unchanged; ActionWorker still abstains (M12 default-off).
- **Races:** native last-write for Publish/Spam/Trash vs M12 CAS / M14 E23 unchanged; Keep-on-hold does not block a later native spam or auto-spam; defer audit is “held when observed” only.
- **Privacy:** queue HTML/audit/export fixtures contain none of the forbidden fields; `review.operator_deferred` payload has no `assessment_id`, `policy_version`, text, or reason codes.
- **A11y (PHPUnit):** action labels present as visible text; empty state text; definition-list structure in `upr_ai` cell.
- **Regression:** M5 columns/views/link caps; M11 column (`action_label()` unchanged); M13 filters/compiler; M14 C20 + content_edited; SupportExport golden v1; `scripts/ci/check.sh`.

---

## 7. Public-contract impact

- [`../integration/public-contracts.md`](../integration/public-contracts.md): **unchanged** (no new C*).
- `upr-support-export/v1`: **unchanged**.
- C20 `CustomerEditAvailability::resolve`: **untouched**.
- Internal: **one** new audit event type string `review.operator_deferred` (not a host filter).

---

## 8. DEV / production / release

- Authorises implementation with M12 masters **default off** and external AI **default off**.
- Does not authorise DEV/prod enablement, Calibration GO, credentials, email, or customer-data access.
- Plugin SemVer **`0.8.0`**; support-export **v1**; no tag/Release/ZIP beyond this freeze tag.

---

## 9. Acceptance / NO-GO

**Accept when:** all WPs merged with CI green; locks Q1–Q7 hold; masters and external AI default off; runtime `0.8.0`.

**NO-GO / stop if:** parallel queue/SPA; AI status mutation; companion `review.operator_queue_decision`; `assessment_id`/`policy_version` on deferred payload; global `action_label()` change; Overview link without `moderate_comments`; SupportExport/runtime SemVer change; `Internal\*`; Calibration claimed; automatic approve.

---

## 10. Amendment boundary

This document is the sole M15 product/implementation specification until an explicit freeze amendment. Host-specific moderation UI remains out of scope.

---

## Related

- [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)
- [`m5-review-moderation-operations.md`](m5-review-moderation-operations.md)
- [`m11-ai-moderation-recommendations.md`](m11-ai-moderation-recommendations.md)
- [`m13-operator-ai-command-surface.md`](m13-operator-ai-command-surface.md)
- [`../runbooks/moderation.md`](../runbooks/moderation.md)
- [`../runbooks/moderation-capabilities.md`](../runbooks/moderation-capabilities.md)
