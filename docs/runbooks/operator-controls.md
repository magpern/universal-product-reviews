# Runbook: Operator controls and diagnostics

## Capability

All admin UI and `admin-post` actions require **`manage_woocommerce`**.

| Surface | Cap | Notes |
|---------|-----|--------|
| WooCommerce → Product Reviews (Overview / Diagnostics / Controls) | `manage_woocommerce` | Tabs under slug `universal-product-reviews` |
| Reconcile dry-run / apply | `manage_woocommerce` + nonce; apply needs `upr_confirm=1` | Uses `ReconciliationService::run` |
| Controlled DB upgrade | `manage_woocommerce` + nonce + confirm | `Migrator::upgrade_now` + rewrite flush; never on page load |
| Support export | `manage_woocommerce` + nonce | Local JSON download only |
| Site Health tests | WP Site Health (read) | Schema, Woo, AS, pause, emails, local AI shadow, external AI — no secrets |
| OpenAI test connection | `manage_woocommerce` + nonce + confirm | Synthetic payload; external quota only |
| Enable external AI | `manage_woocommerce` + Settings API | Server-side confirm + privacy/retention/PII acks |

## Control states

Distinguish these on Controls:

1. **Invitation emails disabled** — fail-closed default; no new invitation email work.
2. **Emergency pause active** — blocks scheduling/sending; revokes outstanding tokens on pause.
3. **Sending not authorised** — host filter `upr_invitation_send_authorisation` may still deny; **cannot be toggled in UPR admin**.

Enabling emails or unpausing refreshes the no-retro-send scheduling boundary. Enabling emails or enabling pause requires a **posted confirmation checkbox** verified server-side (browser `confirm()` alone is not sufficient).

Enabling emails or unpausing refreshes the no-retro-send scheduling boundary. Enabling emails, enabling pause, enabling local AI shadow, or enabling **external AI** requires **posted confirmation** verified server-side (browser `confirm()` alone is not sufficient). External enable also requires the three governance acknowledgement checkboxes.

## Diagnostics (D1–D18)

Open **Diagnostics** for bounded checks (cached ≤ 60s). Unavailable ≠ critical. AI-related:

| ID | Meaning (privacy-safe) |
|----|------------------------|
| D12 | Local AI shadow enabled/disabled |
| D13–D15 | Assessment schema / ops / 24h counts |
| D16 | External AI enabled + provider enum |
| D17 | Credential present bool + source (never value) |
| D18 | External quota day/month aggregates |

Safe recovery: Controls (enable/pause/AI), reconcile dry-run→apply, controlled DB upgrade. No mint/resend.

## Integration readiness (I1–I5)

Same Diagnostics tab, separate section. Always computed fresh (not in the D1–D11 cache). Advisory **wiring** signals only — not operational proof of delivery or mail. **Not** included in support export.

| ID | Meaning |
|----|---------|
| I1 | Delivery lookup filter presence — information if missing |
| I2 | Support action filter — information if missing |
| I3 | Mail transport mode `default` \| `custom` \| `unknown` (registration only) |
| I4 | Send-authorisation filter — information if missing |
| I5 | Core availability service present |

See [`../integration/public-contracts.md`](../integration/public-contracts.md) and [`../roadmap/m6-integration-and-developer-experience.md`](../roadmap/m6-integration-and-developer-experience.md).

## Support export

Controls → **Download support export**. Schema `upr-support-export/v1`. Allowlisted aggregates only — **never** order IDs, emails, tokens, URLs, comments, payloads, product names, or AS args. M6 does **not** add readiness fields.

## Capability map

| Action | Cap | Nonce | Confirmation |
|--------|-----|-------|--------------|
| View Overview / Diagnostics / Controls | `manage_woocommerce` | n/a | n/a |
| Save enable / pause / AI settings | `manage_woocommerce` | Settings API | Server-side confirms when enabling emails, pause, local shadow, or external AI (+ governance acks for external) |
| OpenAI test connection | `manage_woocommerce` | `upr_ai_test_connection` | `upr_confirm_test_connection=1` |
| Reconcile dry-run | `manage_woocommerce` | `upr_reconcile_dry_run` | no |
| Reconcile apply | `manage_woocommerce` | `upr_reconcile_apply` | `upr_confirm=1` |
| Controlled DB upgrade | `manage_woocommerce` | `upr_db_upgrade` | `upr_confirm=1` |
| Support export download | `manage_woocommerce` | `upr_support_export` | n/a |

## Related

- [`support-export.md`](support-export.md)
- [`invitation-failures.md`](invitation-failures.md)
- [`reconciliation.md`](reconciliation.md)
- [`ai-outage.md`](ai-outage.md)
- [`../roadmap/m4-operator-controls-and-diagnostics.md`](../roadmap/m4-operator-controls-and-diagnostics.md)
- [`../roadmap/m10-external-ai-advisory-assessments.md`](../roadmap/m10-external-ai-advisory-assessments.md)
