# M6 — Integration and Developer Experience (authoritative freeze)

**Status:** Frozen M6 product specification. **Does not** authorise production rollout, host deploy, customer contact, GitHub Release, or ZIP.  
**Baseline:** Universal Product Reviews annotated **`v0.5.0`**.  
**Release target (after implementation acceptance):** **`v0.6.0`**.  
**Freeze tag:** `m6-integration-and-developer-experience-freeze` (annotated; peels to the merge commit of this document).

Generic core only: no host-, brand-, theme-, provider-, or infrastructure-specific runtime code. No WooCommerce `Internal\*` APIs. Adapters stay outside this repository.

Registry documentation version label (docs/CI only): **`upr-public-contracts/v1`**. No runtime `UPR_PUBLIC_CONTRACTS_VERSION` constant.

---

## 1. Scope (M6 work packages)

| WP | Deliverable |
|----|-------------|
| **WP1** | Mechanically complete public-contracts registry; ADR-0003 compatibility; integrator onboarding; expanded generic examples (C7/C8b/C14 privacy bans); WC review import **strategy doc only** |
| **WP2** | Uncached integration-readiness diagnostics **I1–I5** in Diagnostics (+ optional Site Health); runbook; **no** support-export change |
| **WP3** | Thin `DeliveryStatus::has_confirmation( int $order_id ): bool` helper (**S**) |
| **WP4** | Fail-safe C1/C2 receivers + runtime normalisation; contract tests; sensitive/R example privacy tests; CI registry completeness |
| **WP5** | Metadata-before-tag **`v0.6.0`**; closure doc; no Release/ZIP/DEV/prod |

**Non-goals:** support-export schema/field changes; host/storefront/theme/provider/deploy/DEV code in-repo; production rollout; GitHub Release/ZIP; customer edits; incentives; AI; auto-approval; media; retention purge; parallel moderation queue; invitation security/claims/sessions/suppression/native submission changes unless proven generic-core defect; `Internal\*`; public mint/resend APIs; runtime WC review importer; audit-extension host filters; runtime public-contract version constant; broad SDKs/REST integration APIs.

---

## 2. Locked decisions

| ID | Decision | Locked value |
|----|----------|--------------|
| L1 | Milestone naming | **M6 = Integration and Developer Experience.** AI is **not** M6 (later / post-DPIA). [`ARCHITECTURE.md`](../../ARCHITECTURE.md) §9/§16 amended accordingly. |
| L2 | Shape | Docs-first + I1–I5 readiness + contract tests; no host adapters in-repo |
| L3 | Support export | **Unchanged** — no readiness fields, no `upr-support-export/v1` bump |
| L4 | I1 | **Information** only when `upr_is_order_delivered` missing: “delivery lookup callback not detected.” Never warning/critical; never implies emails must not run; never proves adapter/delivery health. Hosts may rely solely on C1. |
| L5 | I3 | Enum `default` \| `custom` \| `unknown` only — algorithm in §6; wiring signal, not usable-transport proof; never class/vendor names |
| L6 | C8 split | **C8a** `upr_review_form_base_url` = **S** (token-free). **C8b** `ReviewLinkBuilder` / `upr_review_link_builder` = **R** (token-aware). No false “no token reaches builders” claim. |
| L7 | C1 context | Stable allowlist = **`delivered_at` only**. No inert `source` / `reason_code`. Schedule source always `'adapter'`. |
| L8 | C1/C2 enforcement | Runtime normalisation + **fail-safe untyped receivers** (§5). Host-facing docs may keep typed signatures. |
| L9 | Registry | Mechanically complete inventory; CI enforces every **S** entry exists with expected type/signature and is documented |
| L10 | I* cache | I1–I5 **uncached** (preferred: never in D1–D11 transient) |
| L11 | I5 | Label: **“core availability service present”** only — not host storefront wiring |
| L12 | Version constant | **No** `UPR_PUBLIC_CONTRACTS_VERSION`; docs/CI registry label only; **no I6** |
| L13 | Sensitivity | Orthogonal to stability. **C7** = **S + sensitive-data-bearing**. Same no-log/no-persist/no-forward as C8b/C14; example privacy tests **required** for C7/C8b/C14 |
| L14 | Deferred hooks | `upr_product_review_unavailable_message`, `upr_product_rating_summary` = **D** (absent from code) |
| L15 | Out | No mint/resend; no audit host filters; no runtime importer |
| L16 | C18 | `DeliveryStatus::has_confirmation` **in** as thin **S** |
| L17 | Release | **`v0.6.0`** with metadata-before-tag discipline (§8) |

---

## 3. Stability and sensitivity

| Layer | Meaning |
|-------|---------|
| **S** | Stable public — SemVer-compatible for integrators |
| **P** | Provisional `0.x` — documented but evolving |
| **R** | Restricted — token-aware / email rewrite; not primary onboarding |
| **I** | Internal — AS hooks, admin_post, repositories |
| **D** | Deferred — doc-only or later milestone; not implemented **S** |

| Sensitivity | Rule |
|-------------|------|
| **none** | No customer PII / tokens / invite URLs by contract |
| **sensitive-data-bearing** | May receive email, raw tokens, and/or token-bearing URLs (or bodies containing them). **Never** log, persist, cache, or forward those inputs/outputs. Required example privacy tests. |

Applies to: **C7** (**S** + sensitive), **C8b** (**R**), **C14** (**R**).

Do not declare inert keys stable — every accepted key needs a defined core consumer and storage/behaviour rule.

---

## 4. Registry inventory (mechanical completeness)

Inventory **must** include:

1. Filters UPR `apply_filters` in `src/`
2. Actions UPR listens for that hosts fire (`upr_order_delivery_*`)
3. Public interfaces/helpers (`MailTransport`, `ReviewLinkBuilder`, `ReviewAvailability`, `NativePdpForm`, `InvitationAuthorisation`, `ProductReviewability`, `DeliveryStatus`)
4. Documented hooks **absent from code** (marked **D**)

CI: every **S** entry exists with expected hook type and documented signature; every **S** entry is documented; no undocumented **S**.

Canonical doc (implementation): `docs/integration/public-contracts.md` (`upr-public-contracts/v1` in docs/CI only).

---

## 5. Delivery events C1 / C2 (highest-priority contracts)

Schemas are **runtime-enforced**, not documentation promises. Normalise **before** any typed internal service call, meta write, scheduling, suppression, or audit write.

### C1 — `upr_order_delivery_confirmed` (**S**)

**Host-facing signature:** `do_action( 'upr_order_delivery_confirmed', int $order_id, array $context = array() );`

**Core receiver:** Untyped/mixed inbound args (no `TypeError` on malformed fires).

| Inbound | Normalisation |
|---------|----------------|
| `$order_id` | Coerce to int only if `int` or numeric string `^-?[0-9]+$`; else invalid. Invalid or ≤0 → **no-op**. |
| `$context` | Non-array → `[]`, then missing-`delivered_at` → `time()` path. |

**Allowlisted `$context` key (M6):** `delivered_at` only.

**`delivered_at` normalisation:**

1. Missing → `time()`.
2. Coerce `(int)` only for `int` or numeric string `^-?[0-9]+$`; else invalid → `time()`.
3. Invalid, ≤0, or **implausible** → `time()`. Implausible: `$t > time() + 86400` **or** `$t < 946684800` (2000-01-01 UTC).
4. Persist `_upr_delivery_confirmed_at` from normalised unix when order exists.
5. Schedule `Jobs::schedule_order_items( $order_id, 'adapter', $event_at )` when core controls allow.

**Also:** unknown keys ignored; no PII in context; idempotent re-confirm (not exactly-once); host fires only when entire order meets delivery rules; does not clear on invalidate (current behaviour unchanged in M6).

### C2 — `upr_order_delivery_invalidated` (**S**)

**Host-facing signature:** `do_action( 'upr_order_delivery_invalidated', int $order_id, string $reason );`

**Core receiver:** Untyped/mixed inbound args.

| Inbound | Normalisation |
|---------|----------------|
| `$order_id` | Same as C1; invalid/≤0 → **no-op**. |
| `$reason` | Non-string (omitted, array, object, null, int, …) → `unspecified`. |

**String `$reason` then:**

1. Trim; empty → `unspecified`.
2. Must match `^[a-z0-9_]{1,64}$` else → `unspecified`.
3. Cap normalised reason at **43** characters (composed `delivery_invalidated:` + code ≤ 64 bytes for `suppression_code varchar(64)`); longer valid-pattern codes truncate at normalisation.
4. Persist only `delivery_invalidated:` + normalised code.

Free text **must never** reach stored state.

---

## 6. Integration readiness (I1–I5)

Diagnostics (+ optional non-critical Site Health). **Not** in support export.

| ID | Signal | Semantics |
|----|--------|-----------|
| I1 | `has_filter( 'upr_is_order_delivered' )` | Missing → **information** only (L4) |
| I2 | `has_filter( 'upr_review_invitation_action' )` | Missing → information (optional) |
| I3 | Mail transport mode | Algorithm below |
| I4 | `has_filter( 'upr_invitation_send_authorisation' )` | Missing → information |
| I5 | Core availability service present | `ReviewAvailability` default registered — wording exact (L11) |

**No I6.**

**I3 algorithm** (inspect registration only — do **not** construct transport, invoke callbacks, or send mail):

| Result | Condition |
|--------|-----------|
| `default` | `! has_filter( 'upr_mail_transport' )` |
| `custom` | `has_filter( 'upr_mail_transport' )` is true |
| `unknown` | Inspection cannot complete safely |

Wiring signal only — not proof of a usable transport.

**Forbidden in I\* evidence:** order IDs, emails, tokens, URLs, bodies, product names, class names, file paths, callback dumps.

---

## 7. Contract catalogue (summary)

| ID | Entry | Compat | Notes |
|----|-------|--------|-------|
| C1 | `upr_order_delivery_confirmed` | **S** | §5 |
| C2 | `upr_order_delivery_invalidated` | **S** | §5 |
| C3 | `upr_is_order_delivered` | **S** | Optional if host only fires C1 |
| C4 | `upr_order_delivery_confirmed_at` | **P** | |
| C5 | `upr_review_invitation_action` | **S** | |
| C6 | `upr_invitation_send_authorisation` | **S** | |
| C7 | `upr_mail_transport` / `MailTransport` | **S** + sensitive | Preferred over C14 |
| C8a | `upr_review_form_base_url` | **S** | Token-free |
| C8b | `upr_review_link_builder` / `ReviewLinkBuilder` | **R** | Token-aware |
| C9 | Availability filters/helpers | **S** | |
| C10 | `NativePdpForm::should_render` | **S** | |
| C11–C13, C15 | Reviewability / zero-total / min length | **P** (C11→**S** after docs) | |
| C14 | `upr_invitation_email_{body,subject,headers}` | **R** | |
| C16–C17 | Message / rating summary hooks | **D** | Absent from code |
| C18 | `DeliveryStatus::has_confirmation` | **S** | Thin helper |
| — | AS `upr_send_*`, admin_post, repos | **I** | Not public |

No host moderation/audit extension filters in M6.

---

## 8. Documentation deliverables (implementation)

| Doc | Role |
|-----|------|
| `docs/integration/public-contracts.md` | Canonical registry |
| `docs/integration/integrator-onboarding.md` | Integrator checklist |
| `docs/integration/adapters.md` | Narrative; C1/C2 exact schemas |
| `docs/integration/site-upr-adapters.php.example` | Generic stubs; no log/persist/forward of sensitive inputs |
| `docs/integration/wc-review-import-strategy.md` | Docs-only import principles |
| `docs/decisions/ADR-0003-public-contract-compatibility.md` | Compat + deprecation |
| Runbooks | Integration readiness in operator-controls |

Compatibility (pre-1.0): **S** breaks only in a minor with CHANGELOG “Breaking (public contracts)” and registry doc version bump.

---

## 9. Mandatory tests (summary)

- C1/C2 unit normalisation matrices; storage-rejection integration (invalid timestamps / free-text reasons never stored raw)
- Malformed-arg integration: fire C1/C2 with wrong types / missing args — **no TypeError/fatal**, no unintended meta/schedule/suppression
- I1–I5 semantics (I1 information-only; I3 enum; I5 wording; uncached)
- C7/C8b/C14 shipped-example privacy assertions
- CI registry completeness for **S**; no support-export schema drift; no mint API; Internal/host bans
- M1–M5 regression

Out of M6: browser e2e, real mail, DEV/prod WP.

---

## 10. Release gate (`v0.6.0`)

Before pushing annotated `v0.6.0`, verify the tag target commit contains:

- Plugin header `Version: 0.6.0`
- `UPR_VERSION = '0.6.0'`
- Changelog `[0.6.0]` entry
- CI/package version metadata for `0.6.0`

Never tag a commit still declaring `0.5.0`. Freeze tag ≠ release tag. No GitHub Release or ZIP in default M6 closure.

---

## Amendment boundary

Changes to locked decisions require a freeze amendment. Host-specific adapters, themes, and provider code remain out of scope.
