# Runbook: Moderation capabilities and privacy

## Capabilities

| Surface | Capability |
|---------|------------|
| Comments list / native moderation | `moderate_comments` (WordPress core) |
| Product edit link in UPR column | Object-level `edit_post` on the product |
| Order edit link in UPR column | `wc_get_order()` succeeds **and** object-level `edit_post` on the order |
| UPR invitation Controls / diagnostics / support export | `manage_woocommerce` (unchanged from M4) |

Staff-reply hold exemption additionally requires `moderate_comments` and `edit_post` on the product, plus a verified `replyto-comment` nonce on the exact native reply AJAX action.

### AI advisory (M9 freeze; runtime after implementation)

| Surface | Capability |
|---------|------------|
| View AI advisory column/detail | `moderate_comments` |
| Request **local** re-analysis of a **currently held** top-level product review | `moderate_comments` + nonce |
| Enable/disable local AI shadow | `manage_woocommerce` + confirmation (default **off**) |
| Trigger **external** (re)processing (M10+) | `manage_woocommerce` + nonce + external opt-in |

Approved, spam, trash, deleted, replies, and out-of-scope comments must not be newly assessed or re-analysed. Historical advisory may remain visible without a re-analysis control. Shadow **disabled** stops all new assessment output and AI audit events; historical rows stay visible.

M9 uses a **built-in-only** local assessor — no host-replaceable provider filter.

## Privacy boundaries (M5)

| Allowed | Forbidden |
|---------|-----------|
| Operational `order_id` / `order_item_id` in authorised moderation **audit** | Comment body, email, raw token, URL, direct customer PII in UPR-added UI or audit |
| Order **link** in Comments column when object edit cap passes | Showing order IDs when object capability fails (show —) |
| Source labels Invitation-linked / Unlinked/unknown | Labelling unlinked reviews as “Native” |
| | Order IDs in support export, diagnostics, or lower-capability UI |

### AI privacy (M8 / M9)

| Allowed | Forbidden |
|---------|-----------|
| Bounded score / allowlisted reason codes in Comments admin for authorised moderators | Raw prompts, provider JSON, review body copies in audit/export |
| Aggregate AI failure counters in diagnostics | Provider API keys in options, audit, diagnostics, or support export |
| | Rating as assessor input; sentiment reason codes; exception messages/traces |

## Support export

Schema remains `upr-support-export/v1`. M5–M9 must not add assessment payloads without a separate export-schema freeze.

## Editing

Native WordPress Edit Comment is outside UPR-added UX. Customer review edits are a later milestone, not M5.

## Related

- [`moderation.md`](moderation.md)
- [`support-export.md`](support-export.md)
- [`ai-outage.md`](ai-outage.md)
- [`../roadmap/m8-ai-assisted-moderation-planning.md`](../roadmap/m8-ai-assisted-moderation-planning.md)
- [`../roadmap/m9-local-ai-shadow-mode.md`](../roadmap/m9-local-ai-shadow-mode.md)
- [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md)
