# M4.1 — Operator Controls and Diagnostics — closure

**Verdict:** PASS — generic-core M4.1 shipped as **`v0.4.0`**. No DEV/production deployment, settings change, email, GitHub Release, or ZIP performed as part of this closure.

## Artifacts

| Item | Reference |
|------|-----------|
| Freeze plan | [`docs/roadmap/m4-operator-controls-and-diagnostics.md`](m4-operator-controls-and-diagnostics.md) |
| Freeze PR | [#21](https://github.com/magpern/universal-product-reviews/pull/21) |
| Freeze merge commit | `cbff4cb712b8215a9e7a6b57180669403c2f1d41` |
| Freeze tag | `m4-operator-controls-and-diagnostics-freeze` (annotated) → `cbff4cb` |
| Implementation PR | [#22](https://github.com/magpern/universal-product-reviews/pull/22) |
| Implementation merge commit | `b6f620c2ecafb34b980433a5ccf10e14147d59e3` |
| Version metadata PR | [#23](https://github.com/magpern/universal-product-reviews/pull/23) |
| Version metadata merge commit | `bd86afe7be62e58c43f32d7f2e0e149d826764b0` |
| Release tag | `v0.4.0` (annotated tag object `35a7c5aeb04cd2f7ed2341e997f08f33002e1df9`) → peeled target `b6f620c` |

## Post-merge CI (implementation merge `b6f620c`)

Run: [33148147631](https://github.com/magpern/universal-product-reviews/actions/runs/33148147631) — **success**

| Job | Result |
|-----|--------|
| M1 lint and policy checks | pass |
| Unit tests (PHP 8.1) | pass |
| Unit tests (PHP 8.4) | pass |
| Integration (DEV stack PHP 8.4 / WC 11.0.1) | pass |
| Integration floor (non-blocking) | pass |

## Delivered capabilities (M4.1)

| WP | Deliverable |
|----|-------------|
| **WP1** | Overview tab: control status; open workload (including old still-active rows); recent lifecycle; allowlisted recent audit (≤25); last `reconcile.completed` or **“No recorded run.”**; safe admin links |
| **WP2** | Diagnostics **D1–D11** with pass / warning / information / unavailable; bounded Action Scheduler “at least N”; ≤60s cache; safe actions only |
| **WP3** | Site Health tests (schema + tables, WooCommerce, Action Scheduler APIs, pause, invitation emails) — public APIs only |
| **WP4** | Controls tab (extended settings model): enable/pause with **server-side** confirmation; reconcile dry-run/apply; controlled DB upgrade; audit `invite.emails_enabled` / `invite.emails_disabled` |
| **WP5** | Runbooks (`operator-controls`, `support-export`, troubleshooting updates); allowlisted local support export (`upr-support-export/v1`, 7-day window) |

## Privacy and generic-core boundaries preserved

- **Capability:** `manage_woocommerce` for all admin mutations and export.
- **Support export:** fixed allowlist; **never** order IDs, item IDs, emails, tokens, URLs, comments, payloads, product names, AS args, or free text.
- **Admin recent audit:** allowlisted columns only; no raw `payload_json` dump.
- **No** host/provider/brand code; **no** WooCommerce `Internal\*`; **no** mint/resend/token workaround APIs; **no** host-policy delivery diagnostics (D12).
- **DB upgrade:** never on page load; explicit confirm + nonce + audit; rewrite flush only after verified schema success when rewrite version lags.

## Non-actions (explicit)

- No GitHub Release or production ZIP
- No DEV or production WordPress access, bind mount, activation, or settings change
- No customer email or host-adapter code
- No M5 work started

## Operator validation checklist (future DEV install)

Use after installing UPR **`v0.4.0`** on a non-production WordPress + WooCommerce stack:

1. **Menu:** WooCommerce → Product Reviews shows **Overview**, **Diagnostics**, **Controls** tabs (`manage_woocommerce`).
2. **Controls default:** invitation emails disabled; emergency pause off; status badges distinguish disabled / pause / not authorised.
3. **Enable guard:** enabling emails requires posted `upr_confirm_enable_emails=1` (direct POST without it must not enable).
4. **Pause guard:** activating pause requires posted `upr_confirm_emergency_pause=1`.
5. **Diagnostics:** D1–D11 render; unavailable states are not treated as critical; D6 uses “at least N” or unavailable when capped.
6. **Schema:** D4 and Site Health report warning when version matches but UPR tables are missing (`Migrator::needs_upgrade()`).
7. **DB upgrade:** Controls → controlled upgrade succeeds with confirm; no migrator on ordinary admin page load.
8. **Support export:** local JSON download; verify no order IDs, emails, or tokens in payload.
9. **Site Health:** UPR tests appear; no sensitive diagnostic details exposed.

## Next step

M4.1 is closed. Select the next roadmap milestone (e.g. M5 per [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)) in a separate planning/execution step — not part of this closure.
