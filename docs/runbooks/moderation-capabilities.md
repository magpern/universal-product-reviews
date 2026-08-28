# Runbook: Moderation capabilities and privacy

## Capabilities

| Surface | Capability |
|---------|------------|
| Comments list / native moderation | `moderate_comments` (WordPress core) |
| Product edit link in UPR column | Object-level `edit_post` on the product |
| Order edit link in UPR column | `wc_get_order()` succeeds **and** object-level `edit_post` on the order |
| UPR invitation Controls / diagnostics / support export | `manage_woocommerce` (unchanged from M4) |

Staff-reply hold exemption additionally requires `moderate_comments` and `edit_post` on the product, plus a verified `replyto-comment` nonce on the exact native reply AJAX action.

## Privacy boundaries (M5)

| Allowed | Forbidden |
|---------|-----------|
| Operational `order_id` / `order_item_id` in authorised moderation **audit** | Comment body, email, raw token, URL, direct customer PII in UPR-added UI or audit |
| Order **link** in Comments column when object edit cap passes | Showing order IDs when object capability fails (show —) |
| Source labels Invitation-linked / Unlinked/unknown | Labelling unlinked reviews as “Native” |
| | Order IDs in support export, diagnostics, or lower-capability UI |

## Support export

Schema remains `upr-support-export/v1`. M5 does not add fields.

## Editing

Native WordPress Edit Comment is outside UPR-added UX. Customer review edits are a later milestone, not M5.

## Related

- [`moderation.md`](moderation.md)
- [`support-export.md`](support-export.md)
