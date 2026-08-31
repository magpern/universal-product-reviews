# Runbook: Moderation capabilities and privacy

## Capabilities

| Surface | Capability |
|---------|------------|
| Comments list / native moderation | `moderate_comments` (WordPress core) |
| Product edit link in UPR column | Object-level `edit_post` on the product |
| Order edit link in UPR column | `wc_get_order()` succeeds **and** object-level `edit_post` on the order |
| UPR invitation Controls / diagnostics / support export | `manage_woocommerce` (unchanged from M4) |

Staff-reply hold exemption additionally requires `moderate_comments` and `edit_post` on the product, plus a verified `replyto-comment` nonce on the exact native reply AJAX action.

### AI advisory (M9 / M10)

| Surface | Capability |
|---------|------------|
| View AI advisory column/detail | `moderate_comments` |
| Request **local** re-analysis of a **currently held** top-level product review | `moderate_comments` + nonce (provider=`local`) |
| Request **OpenAI** re-analysis of a **currently held** top-level product review | `manage_woocommerce` + nonce (provider=`openai`; `moderate_comments` alone **denied**) |
| Enable/disable local AI shadow | `manage_woocommerce` + confirmation (default **off**) |
| Enable/disable external AI + provider/model/caps | `manage_woocommerce` + confirms/acks (external default **off**) |
| Save / replace / clear encrypted OpenAI API key | `manage_woocommerce` + nonce + confirm (O9′) |
| OpenAI test connection | `manage_woocommerce` + nonce + confirm |

Approved, spam, trash, deleted, replies, and out-of-scope comments must not be newly assessed or re-analysed. Historical advisory may remain visible without a re-analysis control. Shadow **disabled** stops all new assessment output and AI audit events; historical rows stay visible.

Provider enum is exactly **`local` \| `openai`** — **no** host-replaceable provider filter.

### Operator AI moderation queue (M15 — frozen; not implemented until WP1–WP4)

| Surface | Capability |
|---------|------------|
| Plugin-row “Product reviews” → `edit-comments.php?upr_view=pending` | `moderate_comments` |
| Overview held-review **count** | `manage_woocommerce` (Settings/Overview) |
| Overview held-review **deep link** | `manage_woocommerce` **and** `moderate_comments` (count without link if only the former) |
| Keep on hold (`admin-post`, no status write) | `moderate_comments` + nonce + in-scope held product review |
| Native Publish / Mark as spam / Move to trash | WordPress core only (relabel on held queue; UPR does not harden) |

Authoritative freeze: [`../roadmap/m15-operator-ai-moderation-queue.md`](../roadmap/m15-operator-ai-moderation-queue.md).

## Privacy boundaries (M5)

| Allowed | Forbidden |
|---------|-----------|
| Operational `order_id` / `order_item_id` in authorised moderation **audit** | Comment body, email, raw token, URL, direct customer PII in UPR-added UI or audit |
| Order **link** in Comments column when object edit cap passes | Showing order IDs when object capability fails (show —) |
| Source labels Invitation-linked / Unlinked/unknown | Labelling unlinked reviews as “Native” |
| | Order IDs in support export, diagnostics, or lower-capability UI |

### AI privacy (M8 / M9 / M10)

| Allowed | Forbidden |
|---------|-----------|
| Bounded score / allowlisted reason codes / provider_kind labels in Comments admin | Raw prompts, provider JSON, review body copies in audit/export |
| Aggregate AI failure / quota counters; credential present + source (`constant`\|`environment`\|`stored`\|`missing`) | Provider API keys, ciphertext, raw review text, request/response bodies, provider request IDs that identify content |
| | Rating as assessor input; sentiment reason codes; exception messages/traces with secrets |

## Support export

Schema remains `upr-support-export/v1`. M5–M10 must not add assessment payloads, secrets, or review text without a separate export-schema freeze.

## Editing

Native WordPress Edit Comment (`moderate_comments`) is unchanged M5 operator UX.

Customer 7-day self-edits are implemented (M14). Authoritative freeze: [`../roadmap/m14-customer-seven-day-review-edits.md`](../roadmap/m14-customer-seven-day-review-edits.md).

| Surface | Runtime |
|---------|---------|
| Logged-in author (verified purchase) | `/upr-review/edit/?comment_id=` while the 7-day UTC window and hold/approve status hold. C20 `CustomerEditAvailability::resolve()` is display-only and cannot grant writes. |
| Guest | Original completed invite secret on `/upr-review/{token}/` → 303 `/upr-review/edit/` with a short-lived `edit_session` cookie. `find_active_by_raw(..., 'invite')` stays null; no second submit. |
| Write path | Body + rating only; request-local arm; durable `upr_review_edit_claims`; approve→hold via `ApproveToHoldCas` (operator spam/trash always wins). |
| Reissue | At most one active `edit_session` per parent invite; 10 mints / rolling hour including revoked; parent row `SELECT … FOR UPDATE`. |
| Recovery | Existing `upr_reconcile_invitations` / `wp upr reconcile-invitations` resumes `writing` and `content_written` generations (recovery-owned, not TTL-released). |

Security revoke of a **redeemed** invite uses `TokenRepository::revoke( $id )` and **does** deny guest edit. Mass `revoke_for_item` / `revoke_all_outstanding` remain unredeemed-only.

Do not enable AI masters for edit reassessment; shadow remains default-off. No revision-body store. SupportExport remains `upr-support-export/v1`.

## Related

- [`moderation.md`](moderation.md)
- [`support-export.md`](support-export.md)
- [`ai-outage.md`](ai-outage.md)
- [`../roadmap/m8-ai-assisted-moderation-planning.md`](../roadmap/m8-ai-assisted-moderation-planning.md)
- [`../roadmap/m9-local-ai-shadow-mode.md`](../roadmap/m9-local-ai-shadow-mode.md)
- [`../roadmap/m10-external-ai-advisory-assessments.md`](../roadmap/m10-external-ai-advisory-assessments.md)
- [`../roadmap/m15-operator-ai-moderation-queue.md`](../roadmap/m15-operator-ai-moderation-queue.md)
- [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md)
