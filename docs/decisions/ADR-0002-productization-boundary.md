# ADR-0002: Productization boundary (two-layer architecture)

**Status:** Accepted  
**Date:** 2026-08-27  
**Decision owner:** Maintainer  
**Freeze tag:** `upr-productization-boundary-freeze` (annotated; points at the merge commit that lands this ADR)

## Context

Universal Product Reviews (`universal-product-reviews`) is a portable WooCommerce plugin: domain operations, invitation lifecycle, M2 guest session authorization, moderation, and public adapter contracts live in this repository. Merchant-specific delivery, support-desk, mail-provider, theme/storefront layout, and infrastructure wiring stay outside the generic core ([ARCHITECTURE.md](../../ARCHITECTURE.md) §3; [adapters.md](../integration/adapters.md)).

During M3 host integration, logged-in native product-review denial for cases where `upr_product_review_availability` reports `can_submit=false` (including catalogue-hidden / non-reviewable products) was being implemented in a **private host companion plugin**. That placement would make a sellable security guarantee depend on code purchasers of UPR would not receive. Guest native denial already lives in core (`GuestSubmissionGuard`); availability data already lives in core (`ReviewAvailability`); enforcement for logged-in native POSTs must not diverge into host-only code.

Separately, some host UX used WordPress `comments_open` as a submission/availability gate. Closing comments to hide the review form also suppresses approved review lists in stock WooCommerce templates — an unsafe display coupling.

## Decision

### Two layers only

| Layer | Role |
|-------|------|
| **This repository** (`universal-product-reviews`) | Sellable WooCommerce domain and security core |
| **External host composition** | Private merchant adapters, DEV probes, theme/storefront rendering, branded copy, infrastructure |

**Reject** a third reusable package tentatively named `universal-product-reviews-woocommerce` (or any similarly scoped companion). This repository already requires WooCommerce and is the WooCommerce product. A third package would rename the same boundary without a coherent second SKU and would risk parking security in an optional install.

### UPR core responsibility

- Availability-aligned **native product-comment enforcement for all identities** (guest and logged-in), using `upr_product_review_availability` as the source of truth for whether a native product-review insert may proceed when `can_submit` is false.
- Preserve M2: guests submit only via the invitation form path with form session **and** request-local authorization arm; never enable a native guest comment route.
- Provide a **generic native-PDP form display helper** (read-only; same eligibility narrowing as documented in [submission-availability.md](../integration/submission-availability.md)) for themes/hosts to decide whether to render the native review form.
- No host, theme, fulfillment, support-desk, mail-provider, site, or product names in code, APIs, hooks, settings, documentation, tests, fixtures, or defaults (existing CI forbid-lists).
- **Must not** register a `comments_open` filter (or equivalent) as an availability or submission gate.

### External host responsibility

- Merchant delivery / support / mail transport adapters implementing published `upr_*` contracts.
- DEV verification CLIs and pin/preflight probes.
- Theme and storefront rendering (PDP sections, template forks, CSS, product-card ratings).
- Branded unavailable messaging and infrastructure (e.g. access-log redaction of invite URLs).

### Host PR that placed logged-in denial in companion code

An open pull request in a private host companion repository that implements logged-in native product-review denial (and related display decoupling) **in host-only code** must be **superseded**, not merged as the long-term security owner. Security enforcement ships in UPR core; the host PR is replaced by a slim follow-up that consumes core APIs, removes unsafe `comments_open` gating, and keeps branded UX / DEV probes only.

## Consequences and compatibility sequence

| Step | Work |
|------|------|
| **B0** (this ADR) | Documentation freeze only |
| **B1** | Generic UPR patch **`v0.2.2`**: availability-aligned native submission guard + native-PDP display helper + tests ([roadmap](../roadmap/b1-native-submission-enforcement.md)) |
| **B2** | Host companion: move **UPR dependency pin** to `v0.2.2`; remove old host `comments_open` availability gating; drop duplicate preprocess; consume core helper. Host plugin version is chosen independently |
| **B3** | Amend host-owned M3 ownership docs to match this ADR |
| **B4** | Resume host PDP reviews section **only after** B1 is released **and** B2 is merged |

Until B1+B2, stock or forked templates that still encounter host `comments_open=false` can hide approved review lists for unavailable actors.

## No-third-package rationale

1. Core is already WooCommerce-gated (`Requires Plugins: woocommerce`).
2. Sellable security must not be optional or private-companion-only.
3. Delivery/support/mail/theme adapters are merchant-specific by nature; published filters already define the reusable contract.
4. Prefer the smallest architecture that remains genuinely sellable.

## Risks and rollback

| Risk | Mitigation |
|------|------------|
| Host docs still describe host-owned preprocess | B3 ownership amend after B1 |
| Double `preprocess_comment` if superseded host PR merges anyway | Do not merge that PR; B2 must not register competing availability denial |
| B4 started before B2 | Hard dependency: B4 requires B1 **and** merged B2 |

**Rollback boundary for B0:** revert this documentation merge (and freeze tag if needed). No runtime behaviour changes in B0. B1 rollback remains: revert to `v0.2.1` without schema migration.

## Related

- [ARCHITECTURE.md](../../ARCHITECTURE.md) — portable core vs external adapters
- [submission-availability.md](../integration/submission-availability.md) — availability contract
- [adapters.md](../integration/adapters.md) — host adapter roles
- [b1-native-submission-enforcement.md](../roadmap/b1-native-submission-enforcement.md) — frozen B1 acceptance matrix
