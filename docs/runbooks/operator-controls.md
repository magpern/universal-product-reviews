# Runbook: Operator controls and diagnostics

## Capability

All admin UI and `admin-post` actions require **`manage_woocommerce`**.

| Surface | Cap | Notes |
|---------|-----|--------|
| WooCommerce → Product Reviews (Overview / Diagnostics / Controls) | `manage_woocommerce` | Tabs under slug `universal-product-reviews` |
| Reconcile dry-run / apply | `manage_woocommerce` + nonce; apply needs `upr_confirm=1` | Uses `ReconciliationService::run` |
| Controlled DB upgrade | `manage_woocommerce` + nonce + confirm | `Migrator::upgrade_now` + rewrite flush; never on page load |
| Support export | `manage_woocommerce` + nonce | Local JSON download only |
| Site Health tests | WP Site Health (read) | Schema, Woo, AS, pause, emails — no sensitive details |

## Control states

Distinguish these on Controls:

1. **Invitation emails disabled** — fail-closed default; no new invitation email work.
2. **Emergency pause active** — blocks scheduling/sending; revokes outstanding tokens on pause.
3. **Sending not authorised** — host filter `upr_invitation_send_authorisation` may still deny; **cannot be toggled in UPR admin**.

Enabling emails or unpausing refreshes the no-retro-send scheduling boundary. Enabling emails or enabling pause requires a **posted confirmation checkbox** verified server-side (browser `confirm()` alone is not sufficient).

## Diagnostics (D1–D11)

Open **Diagnostics** for bounded checks (cached ≤ 60s). Unavailable ≠ critical.

Safe recovery: Controls (enable/pause), reconcile dry-run→apply, controlled DB upgrade. No mint/resend.

## Support export

Controls → **Download support export**. Schema `upr-support-export/v1`. Allowlisted aggregates only — **never** order IDs, emails, tokens, URLs, comments, payloads, product names, or AS args.

## Capability map

| Action | Cap | Nonce | Confirmation |
|--------|-----|-------|--------------|
| View Overview / Diagnostics / Controls | `manage_woocommerce` | n/a | n/a |
| Save enable / pause settings | `manage_woocommerce` | Settings API | Server-side `upr_confirm_enable_emails` / `upr_confirm_emergency_pause` required when enabling; JS is UX only |
| Reconcile dry-run | `manage_woocommerce` | `upr_reconcile_dry_run` | no |
| Reconcile apply | `manage_woocommerce` | `upr_reconcile_apply` | `upr_confirm=1` |
| Controlled DB upgrade | `manage_woocommerce` | `upr_db_upgrade` | `upr_confirm=1` |
| Support export download | `manage_woocommerce` | `upr_support_export` | n/a |

## Related

- [`support-export.md`](support-export.md)
- [`invitation-failures.md`](invitation-failures.md)
- [`reconciliation.md`](reconciliation.md)
- [`../roadmap/m4-operator-controls-and-diagnostics.md`](../roadmap/m4-operator-controls-and-diagnostics.md)
