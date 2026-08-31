# Post-M3 product roadmap (non-binding)

**Status:** Forward product-development priorities only. **Not** a freeze plan and **not** an implementation authorisation.  
**Baseline:** Universal Product Reviews annotated **`v0.8.0`**.  
**Out of scope for this document:** Production rollout, host deploy runbooks, and operational invitation gates. Those remain separately governed.

Each milestone below requires its own **plan → documentation freeze → implementation** cycle before work starts. Keep UPR **generic**: no host-, brand-, theme-, provider-, or infrastructure-specific runtime code in this repository.

**Recommended next implementation milestone:** none frozen. Forward candidates (each needs its own **plan → documentation freeze → implementation**): C11/C16–C17 promotion; WC importer; deferred SemVer/Release. Separately governed: M10 SemVer/enablement GO; **M12 Calibration GO** before any production auto-spam enablement. **M14** customer 7-day edits are **closed**: [`m14-customer-seven-day-review-edits-closure.md`](m14-customer-seven-day-review-edits-closure.md) (PR #73; runtime `0.8.0`; SupportExport `upr-support-export/v1` unchanged; C20 remains Provisional). **M13** operator command surface is **closed**: [`m13-operator-ai-command-surface-closure.md`](m13-operator-ai-command-surface-closure.md) (PR #70; masters default-off). M11 recommendation-only is **closed**: [`m11-ai-moderation-recommendations-closure.md`](m11-ai-moderation-recommendations-closure.md). M10 implementation is **closed** after #55–#57 (SemVer deferred; external AI still off by default): [`m10-external-ai-advisory-assessments-closure.md`](m10-external-ai-advisory-assessments-closure.md). M7 and M9 are closed at **`v0.8.0`**. M8 planning is closed. M12 Simulation-GO implementation: [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md) (production still blocked).

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
**Shipped in `v0.8.0`** (combined with M9; `v0.7.0` intentionally not published).

## M8 — AI-Assisted Moderation Planning

- Authoritative planning freeze for optional AI-assisted moderation (local shadow → external → auto-approval split).
- **No** runtime AI, migrations, settings, provider calls, or automatic approval in this milestone.
- ADR-0004 locks privacy, held-only eligibility, portable claims table, one-transaction completion, and secrets model.

**Freeze:** [`m8-ai-assisted-moderation-planning.md`](m8-ai-assisted-moderation-planning.md).  
**ADR:** [`../decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md).  
**Closure:** [`m8-ai-assisted-moderation-planning-closure.md`](m8-ai-assisted-moderation-planning-closure.md).

## M9 — Local AI Shadow Mode

- Built-in-only local advisory assessments; Comments-admin display; fail-open; zero status mutation; disabled by default.
- No replaceable provider filter; no AI public-contract registry entry (deferred to M10).
- Portable assessments / claims / ops tables; claim-before-rate; disable-silent precedence.

**Freeze:** [`m9-local-ai-shadow-mode.md`](m9-local-ai-shadow-mode.md).  
**Closed at `v0.8.0`:** [`m9-local-ai-shadow-mode-closure.md`](m9-local-ai-shadow-mode-closure.md).

## M10 / M11 / M12 (forward)

- **M10** — External AI advisory assessments (OpenAI); freeze [`m10-external-ai-advisory-assessments.md`](m10-external-ai-advisory-assessments.md). **Closed and accepted after #55–#57** (implementation; SemVer deferred; no enablement): [`m10-external-ai-advisory-assessments-closure.md`](m10-external-ai-advisory-assessments-closure.md). **O9 superseded by** [`m10-o9-encrypted-openai-credential-amendment.md`](m10-o9-encrypted-openai-credential-amendment.md) (encrypted Controls credential; host override still first).  
- **M11** — AI moderation **recommendations** only (no auto-action). Freeze: [`m11-ai-moderation-recommendations.md`](m11-ai-moderation-recommendations.md). **Closed** (SemVer deferred; no auto-action / no enablement): [`m11-ai-moderation-recommendations-closure.md`](m11-ai-moderation-recommendations-closure.md).  
- **M12** — Sole contract **`auto_spam_held_technical`**. Freeze: [`m12-guarded-auto-spam.md`](m12-guarded-auto-spam.md). Two-gate evidence: **Simulation GO** / **Calibration GO**. Posture: [`m12-calibration-nogo.md`](m12-calibration-nogo.md). Freeze-era closure: [`m12-guarded-auto-spam-closure.md`](m12-guarded-auto-spam-closure.md). **Simulation-GO implementation closure:** [`m12-simulation-implementation-closure.md`](m12-simulation-implementation-closure.md) (PR #67; masters default off; production still blocked). Kit: `scripts/calibration/evidence-kit/`. Auto-approve permanently excluded.  
- **M13** — Operator AI Moderation Command Surface. Freeze: [`m13-operator-ai-command-surface.md`](m13-operator-ai-command-surface.md). **Closed:** [`m13-operator-ai-command-surface-closure.md`](m13-operator-ai-command-surface-closure.md) (PR #70; masters default-off; no Calibration GO / no production enablement).  
- **M14** — Customer 7-day review edits. Freeze: [`m14-customer-seven-day-review-edits.md`](m14-customer-seven-day-review-edits.md). **Closed:** [`m14-customer-seven-day-review-edits-closure.md`](m14-customer-seven-day-review-edits-closure.md) (PR #73; runtime `0.8.0`; C20 remains Provisional; SupportExport `upr-support-export/v1` unchanged).

---

## Standing notes

- Production rollout and its operational gates are **not** product-development milestones and are not scheduled by this roadmap.
- Closing a prior freeze (for example M3 invitation controls) does not authorise the next milestone without a new plan freeze.
- Related historical roadmap material remains under [`docs/roadmap/`](.).
