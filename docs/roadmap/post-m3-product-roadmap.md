# Post-M3 product roadmap (non-binding)

**Status:** Forward product-development priorities only. **Not** a freeze plan and **not** an implementation authorisation.  
**Baseline:** Universal Product Reviews annotated **`v0.3.0`**.  
**Out of scope for this document:** Production rollout, host deploy runbooks, and operational invitation gates. Those remain separately governed.

Each milestone below requires its own **plan → documentation freeze → implementation** cycle before work starts. Keep UPR **generic**: no host-, brand-, theme-, provider-, or infrastructure-specific runtime code in this repository.

**Recommended next implementation milestone:** **M4**.

---

## M4 — Operator Controls and Diagnostics

- Invitation health, failures, queues, and audit visibility.
- Mail, cron, adapter, and Site Health diagnostics.
- Installation wizard and compatibility preflight.

## M5 — Review Moderation Operations

- Moderation filters, bulk actions, product/order context.
- Native WordPress threaded replies for customer responses.
- Review-management permissions and operator guidance.

## M6 — Integration and Developer Experience

- Host-adapter contracts and configuration examples.
- Demo fixtures and repeatable developer environment.
- Existing WooCommerce review import/migration strategy.

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
