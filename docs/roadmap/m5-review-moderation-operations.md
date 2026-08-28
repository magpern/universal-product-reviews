# M5 — Review Moderation Operations (authoritative freeze)

**Status:** Frozen M5 product specification. **Does not** authorise production rollout, host deploy, customer contact, GitHub Release, or ZIP.  
**Baseline:** Universal Product Reviews annotated **`v0.4.0`**.  
**Release target (after implementation acceptance):** **`v0.5.0`**.  
**Freeze tag:** `m5-review-moderation-operations-freeze` (annotated; peels to the merge commit of this document).

Generic core only: no host-, brand-, theme-, provider-, or infrastructure-specific runtime code. No WooCommerce `Internal\*` APIs.

---

## 1. Scope (M5 work packages)

| WP | Deliverable |
|----|-------------|
| **WP1** | Native Comments-admin context: columns (Product, Rating, Source, Order), views/filters, bounded prefetch |
| **WP2** | Deterministic moderation audit via `transition_comment_status`; nesting-safe `SystemStatusOrigin` |
| **WP3** | Staff-reply hold exemption (verified nonce + caps + depth-one); `review.reply_posted` |
| **WP4** | Permissions, privacy, no retention TTL |
| **WP5** | Runbooks, capability map, tests |

**Non-goals:** parallel moderation dashboard; custom review datastore; UPR review-editing UX; customer review edits; AI; auto-approval; incentives; media; mail; schema/JSON-LD output; bulk-spam-reason UI; support-export field changes; `PurgeContext` / audit TTL; production rollout; `Internal\*`.

---

## 2. Locked decisions

| ID | Decision | Locked value |
|----|----------|--------------|
| L1 | Milestone naming | **M5 = Review Moderation Operations.** Customer 7-day edits are a **later milestone**, not M5. [`ARCHITECTURE.md`](../../ARCHITECTURE.md) §12/§16 amended accordingly. |
| L2 | Scope | All `comment_type=review` on `product` posts |
| L3 | Source labels | **Invitation-linked** \| **Unlinked/unknown** only (never “Native”) |
| L4 | Source filters | Invitation-linked \| All UPR product reviews only; no native-only; no negative-meta inference |
| L5 | Edit links | `current_user_can( 'edit_post', $id )`; orders via `wc_get_order()` then object-level edit |
| L6 | Audit scope | All in-scope product-review transitions |
| L7 | `review.reply_posted` | Separate event for validated staff replies only |
| L8 | Bulk spam reason UI | Out of M5 |
| L9 | Support export | **Unchanged** — no new fields |
| L10 | Audit dedupe | Request-local only |
| L11 | Staff-reply exemption | Exact contract in §5 |
| L12 | Editing | No UPR edit UX; native WordPress Edit Comment remains |
| L13–L14 | Status events | Deterministic map in §4 |
| L15 | UPR status mutations | All via `SystemStatusOrigin`; CI forbids direct APIs outside allowlist |
| L16 | Audit privacy | Operational `order_id`/`order_item_id` allowed in authorised audit; never body/email/token/URL/direct customer PII; order IDs absent from export, diagnostics, lower-capability UI |
| L17 | Audit retention | M5 adds **no** TTL or purge |

---

## 3. Native Comments list (WP1)

### Query / views

Gate: `is_admin()` and `$pagenow === 'edit-comments.php'` only.

| View / filter | Constraints |
|---------------|-------------|
| UPR product reviews | `type=review`, `post_type=product` |
| UPR pending | above + `status=hold` |
| Invitation-linked | above + positive linkage (meta `_upr_order_item_id` EXISTS **OR** invite `review_comment_id` match via pagination-safe `comments_clauses`) |
| All UPR product reviews | `type=review`, `post_type=product` |

GET vars: `upr_view`, `upr_source` — sanitised allowlists only. Preserve core pagination, search, status, and WooCommerce review-type selector (AND semantics).

**Forbidden:** `comment_type=review` alone without `post_type=product`; `post__in` of all products; `comment__in` of all invite IDs; `NOT EXISTS` native inference.

### Invitation-linked JOIN safety

Must: no duplicate comments; correct `found_comments`/pagination; prepared SQL; compose with search/status/type; dual-linked comments appear once.

### Columns

| Column | Content | Cap / privacy |
|--------|---------|---------------|
| Product | Edit link iff `edit_post` on product; else text | No PII |
| Rating | `rating` meta or — | |
| Source | Invitation-linked \| Unlinked/unknown | |
| Order | Link iff `wc_get_order()` + `edit_post` on order; else — | No order ID when cap fails |

### Prefetch

Per list page: batch comment meta; invite rows by `review_comment_id IN (…)` and `order_item_id IN (…)` only for meta-linked IDs on the page; batch-prime product posts. Bound to displayed IDs; no N+1.

---

## 4. Moderation audit (WP2)

### `SystemStatusOrigin`

- `run( callable $fn )` — nesting-safe **depth counter** (or save/restore) in `finally`
- `is_active(): bool` — depth > 0
- Inventory every `src/` call to `wp_set_comment_status`, spam/trash helpers, and status-changing `wp_update_comment`
- Route each through `run()`
- CI fails on direct calls outside wrapper unless freeze-amended allowlist entry

### Events (per real `transition_comment_status` fire)

| Condition | Event |
|-----------|-------|
| Operator (`is_admin()` + `moderate_comments`, marker inactive) | `review.status_changed` |
| Marker active + new status spam | `review.system_spam` |
| Marker active (other) or external/CLI/cron/plugin/non-admin | `review.system_status_changed` |

Payload always includes normalised `old_status` and `new_status`. Allowlist: `comment_id`, `product_id`, `order_id?`, `order_item_id?`, `old_status`, `new_status`, `source`, `actor_id`, `origin`. Request-local dedupe only. Moderator spam/trash does **not** rewrite invite rows.

---

## 5. Staff-reply exemption (WP3)

Evaluated **only** inside `ReviewModeration::hold_new_product_reviews` (`pre_comment_approved`).

Exemption from UPR’s approve→hold downgrade applies **only when all** are true:

1. Request is the exact WordPress authenticated native reply endpoint/action (`replyto-comment` AJAX path — not arbitrary admin-AJAX, not frontend `wp-comments-post.php`);
2. The `replyto-comment` nonce is **independently verified** with WordPress public nonce APIs inside this path;
3. `current_user_can( 'moderate_comments' )`;
4. `current_user_can( 'edit_post', $product_id )`;
5. Parent exists and is a **top-level** in-scope product review (`parent.comment_parent == 0`);
6. Parent and reply share the same product post;
7. Depth is exactly one;
8. Core’s `$approved` is passed through unchanged — **never force approve**.

**Fail closed:** missing/invalid nonce; frontend posts; arbitrary admin-AJAX; crafted data; nested replies; parent-only forgery.

Emit `review.reply_posted` only for validated staff replies (separate from status events).

---

## 6. Release safety (`v0.5.0`)

Before pushing annotated `v0.5.0`, verify the tag target commit contains:

- Plugin header `Version: 0.5.0`
- `UPR_VERSION = '0.5.0'`
- Changelog `[0.5.0]` entry
- CI/package version metadata for `0.5.0`

Never tag a commit still declaring `0.4.0`.

---

## 7. Documentation deliverables (implementation)

- Rewrite [`docs/runbooks/moderation.md`](../runbooks/moderation.md) — enhanced native Comments list, not a UPR queue
- Add [`docs/runbooks/moderation-capabilities.md`](../runbooks/moderation-capabilities.md)
- State: no M5 audit TTL/purge; support export unchanged; native Edit Comment remains outside UPR UX

---

## 8. Mandatory tests (summary)

Scope/source matrix; meta/invite/dual linkage; invitation-linked pagination/search/no duplicates; bounded prefetch; object-level links; operator / `reject_comment` / external audit origins; approve→unapprove→approve; blog comments unaudited; CI status-API policy; genuine native reply + invalid/missing/spoofed nonce; frontend moderator parent; customer forgery; top-level hold; core-held staff reply; privacy fields; M1–M4 regression.

---

## Amendment boundary

Changes to locked decisions require a freeze amendment. Host-specific moderation UI remains out of scope.
