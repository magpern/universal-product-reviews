# Post-M3 product roadmap (non-binding)

**Status:** Forward product-development priorities only. **Not** a freeze plan and **not** an implementation authorisation.  
**Baseline:** Universal Product Reviews annotated **`v0.6.0`**.  
**Out of scope for this document:** Production rollout, host deploy runbooks, and operational invitation gates. Those remain separately governed.

Each milestone below requires its own **plan → documentation freeze → implementation** cycle before work starts. Keep UPR **generic**: no host-, brand-, theme-, provider-, or infrastructure-specific runtime code in this repository.

**Recommended next implementation milestone:** **M7** (Storefront Compatibility and Quality; not authorised by M6 closure alone).

---

## M4 — Operator Controls and Diagnostics

- Invitation health, failures, queues, and audit visibility.
- Mail, cron, adapter, and Site Health diagnostics.
- Installation wizard and compatibility preflight.

**M4.1 freeze:** [`m4-operator-controls-and-diagnostics.md`](m4-operator-controls-and-diagnostics.md).  
**Closed at `v0.4.0`:** [`m4-operator-controls-and-diagnostics-closure.md`](m4-operator-controls-and-diagnostics-closure.md).

## M5 — Review Moderation Operations

- Native Comments-admin context (columns, views, filters, prefetch).
- Deterministic moderation audit; nesting-safe `SystemStatusOrigin`.
- Verified staff-reply hold exemption; `review.reply_posted`.
- **Not** customer review edits (later milestone).

**Freeze:** [`m5-review-moderation-operations.md`](m5-review-moderation-operations.md).  
**Closed at `v0.5.0`:** [`m5-review-moderation-operations-closure.md`](m5-review-moderation-operations-closure.md).

## M6 — Integration and Developer Experience

- Public-contract registry, ADR compatibility, integrator onboarding, generic examples.
- Integration-readiness diagnostics (I1–I5); fail-safe delivery event contracts (C1/C2).
- Existing WooCommerce review import/migration **strategy** (docs only; no runtime importer).
- Developer VPS / host adapters remain **outside** this repository.

**Freeze:** [`m6-integration-and-developer-experience.md`](m6-integration-and-developer-experience.md).  
**Closed at `v0.6.0`:** [`m6-integration-and-developer-experience-closure.md`](m6-integration-and-developer-experience-closure.md).

## M7 — Storefront Compatibility and Quality

- Accessibility acceptance across supported theme patterns.
- Cross-theme storefront compatibility suite.
- Supported integration-boundary documentation.

## M8 — AI-Assisted Moderation Planning

- Privacy, threshold, override, and approval design.
- No automatic approval or AI implementation in this milestone.
- Requires a separate frozen plan before any implementation.

---

## Standing notes

- Production rollout and its operational gates are **not** product-development milestones and are not scheduled by this roadmap.
- Closing a prior freeze (for example M3 invitation controls) does not authorise the next milestone without a new plan freeze.
- Related historical roadmap material remains under [`docs/roadmap/`](.).
