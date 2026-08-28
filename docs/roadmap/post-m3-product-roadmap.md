# Post-M3 product roadmap (non-binding)

**Status:** Forward product-development priorities only. **Not** a freeze plan and **not** an implementation authorisation.  
**Baseline:** Universal Product Reviews annotated **`v0.6.0`**.  
**Out of scope for this document:** Production rollout, host deploy runbooks, and operational invitation gates. Those remain separately governed.

Each milestone below requires its own **plan → documentation freeze → implementation** cycle before work starts. Keep UPR **generic**: no host-, brand-, theme-, provider-, or infrastructure-specific runtime code in this repository.

**Recommended next implementation milestone after M8 freeze:** **M9** (Local AI Shadow Mode — **not** authorised by M8 alone; requires a separate implementation plan after [`m8-ai-assisted-moderation-planning.md`](m8-ai-assisted-moderation-planning.md)). M7 implementation is merged on `main`; M7 release metadata (`v0.7.0`) remains separately authorised.

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

- Storefront compatibility matrix and integration-boundary documentation.
- Contract-only C9/C10/enforcement characterization (no visual theme suite in UPR).
- Mandatory accessibility hardening on core-owned `/upr-review/form/`.

**Freeze:** [`m7-storefront-compatibility-and-quality.md`](m7-storefront-compatibility-and-quality.md).

## M8 — AI-Assisted Moderation Planning

- Authoritative planning freeze for optional AI-assisted moderation (local shadow → external → auto-approval split).
- **No** runtime AI, migrations, settings, provider calls, or automatic approval in this milestone.
- ADR-0004 locks privacy, held-only eligibility, portable claims table, one-transaction completion, and secrets model.

**Freeze:** [`m8-ai-assisted-moderation-planning.md`](m8-ai-assisted-moderation-planning.md).  
**ADR:** [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md).

## M9 — Local AI Shadow Mode (forward; not authorised by M8 alone)

- Local-only advisory assessments; Comments-admin display; fail-open; zero status mutation.
- Requires its own implementation plan after the M8 freeze tag.

## M10 / M11 (forward)

- **M10** — external AI processing (separate freeze).  
- **M11** — automatic approval (ADR amendment + governed calibration). See [`../future/ai-review-scoring.md`](../future/ai-review-scoring.md).

---

## Standing notes

- Production rollout and its operational gates are **not** product-development milestones and are not scheduled by this roadmap.
- Closing a prior freeze (for example M3 invitation controls) does not authorise the next milestone without a new plan freeze.
- Related historical roadmap material remains under [`docs/roadmap/`](.).
