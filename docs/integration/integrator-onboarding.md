# Integrator onboarding

Generic checklist for wiring a **host adapter** to Universal Product Reviews. Adapters live **outside** this repository. Canonical contracts: [`public-contracts.md`](public-contracts.md) (`upr-public-contracts/v1`).

## Order of work

1. **Controls** — Enable invitation emails only after scheduling boundary is intentional; know emergency pause.
2. **C1 delivery confirm** — Fire `upr_order_delivery_confirmed` when the **entire** order meets host delivery rules. Optional context key: `delivered_at` (unix) only.
3. **C2 invalidate** — Fire `upr_order_delivery_invalidated` with a **reason code** (`cancel`, `refund`, …), never free text.
4. **C3 lookup (optional)** — Implement `upr_is_order_delivered` for reconciliation / completed-fallback skip if you do not rely solely on C1.
5. **C5 support** — Optional delay/suppress via `upr_review_invitation_action`.
6. **C6 send authorisation** — Optional further deny of `allow` → `not_authorised`.
7. **C7 mail** — Prefer custom `MailTransport` over email rewrite filters. Treat as **sensitive-data-bearing**: never log/persist/forward message fields.
8. **C8a base URL** — Token-free `upr_review_form_base_url` if needed. Avoid C8b unless you must replace the builder (restricted; token-aware).
9. **C9 / C10 availability** — Consume availability + `NativePdpForm::should_render` for PDP display. Do not implement deferred hooks as if they exist.
10. **Readiness** — Check Diagnostics **I1–I5** (advisory wiring only). Not in support export.
11. **Pin** — Record plugin SemVer + registry doc id `upr-public-contracts/v1`.

## Privacy

- Never put emails, tokens, or invite URLs in C1/C2 payloads.
- Never log/cache/analytics-forward C7 / C8b / C14 inputs or outputs.
- See illustrative stubs: [`site-upr-adapters.php.example`](site-upr-adapters.php.example).

## Out of scope here

- Runtime WooCommerce review importer (see [`wc-review-import-strategy.md`](wc-review-import-strategy.md) — docs only).
- Mint/resend token APIs, host deploy, theme HTML in core.
