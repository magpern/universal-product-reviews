# M7 — Storefront Compatibility and Quality (authoritative freeze)

**Status:** Frozen M7 product specification. **Does not** authorise production rollout, host deploy, customer contact, GitHub Release, or ZIP.  
**Baseline:** Universal Product Reviews annotated **`v0.6.0`**.  
**Release target (after implementation acceptance):** **`v0.7.0`**. **`v0.6.1` is excluded.**  
**Freeze tag:** `m7-storefront-compatibility-and-quality-freeze` (annotated; peels to the merge commit of this document).

Generic core only: no host-, brand-, theme-, provider-, or infrastructure-specific runtime code. No WooCommerce `Internal\*` APIs. Adapters stay outside this repository.

Registry documentation version label (docs/CI only): **`upr-public-contracts/v1`** (unchanged). No new stable contracts in M7 unless explicitly amended here (none planned).

---

## 1. Scope (M7 work packages)

| WP | Deliverable |
|----|-------------|
| **WP1** | Authoritative freeze (this document) |
| **WP2** | Integration-boundary docs: [`storefront-compatibility.md`](../integration/storefront-compatibility.md); updates to submission-availability, adapters, onboarding, ARCHITECTURE §14, compatibility policy |
| **WP3** | Contract/guard characterization tests (`M7StorefrontCompatibilityIntegrationTest`; bootstrap guard priority assertions; fail-closed `resolve()`) |
| **WP4** | **Mandatory** core-owned `/upr-review/form/` accessibility hardening in `ReviewFormEndpoint` + markup/security regression tests |
| **WP5** | CI policy: require storefront compatibility doc; preserve C9/C10 inventory; forbid new PDP hooks / `comments_open` gating |
| **WP6** | Implementation PR + CI (no merge/tag/release in default M7 execution authorisation) |
| **WP7** | Metadata-before-tag **`v0.7.0`**; closure doc — **separate authorisation** after implementation acceptance |

**Non-goals:** host/theme/storefront code in-repo; WooCommerce PDP template override; product-summary hooks; rating-summary API (**C17**); unavailable-message API (**C16**); `comments_open` filter; visual/browser/theme acceptance suite; Blocksy/Elementor/Rank Math; mint/resend; invitation/session/claim redesign; moderation dashboard; schema JSON-LD emission; AI; customer edits; retention purge; DEV/production access; GitHub Release/ZIP; compatibility-floor CI leg promotion to blocking.

---

## 2. Locked decisions (D1–D9)

| ID | Decision | Locked value |
|----|----------|--------------|
| **D1** | **C16** `upr_product_review_unavailable_message` | **Keep deferred (D)** — hosts map C9 `reason_code` to copy |
| **D2** | **C17** `upr_product_rating_summary` | **Keep deferred (D)** — hosts use WooCommerce public rating APIs |
| **D3** | **C11** `upr_product_is_reviewable` | **No promotion in M7** — remain **P** (provisional); publicly documented only; **not** added to stable CI inventory |
| **D4** | Guest form a11y | **Mandatory (WP4)** — `/upr-review/form/` only; markup tests must prove unchanged security invariants (nonce, session id, 403 handling, headers, no token/email/order/PII leakage) |
| **D5** | Block/FSE promise | **Contract-only in CI** — automate C9/C10/enforcement; **host visual verification** required for layout; no visual block-theme harness; manual integrator checks are **not** automated characterization |
| **D6** | Compatibility-floor CI leg | **Remain non-blocking** in M7 |
| **D7** | Template override in UPR | **No** |
| **D8** | New public helper beyond C9/C10 | **No** |
| **D9** | Release version | **`v0.7.0`** — metadata-before-tag; annotated tag peels to metadata merge commit |

### Submission contracts (unchanged authority)

- **C9** `upr_product_review_availability` + `ReviewAvailability` helpers = source of truth for submit eligibility.
- **C10** `NativePdpForm::should_render()` = display-only native PDP form gate (guests always `false`).
- **Server enforcement:** `GuestSubmissionGuard` @ `preprocess_comment` priority **5**; `NativeSubmissionGuard` @ priority **15**; WC type normalisation @ **1**.
- UPR **must not** register `comments_open` as a submission or availability gate.

---

## 3. Storefront ownership model

| Concern | Owner |
|---------|--------|
| Submit eligibility (authoritative) | UPR core — C9 + guards |
| Native PDP form visibility | Host + C10 |
| Approved review list display | WooCommerce / theme templates |
| Unavailable messaging | Host — C9 reason codes (**C16 not implemented**) |
| Rating summary / count on PDP | Host — WC product rating APIs (**C17 not implemented**) |
| Review section tab/anchor/layout | Host / theme / WC blocks |
| Guest invitation form UI | UPR core — minimal `/upr-review/form/` |
| Schema / JSON-LD | Host SEO plugin — [`schema-acceptance.md`](../integration/schema-acceptance.md) |
| Responsive styling | Host theme |

**Principle:** approved-review **visibility** is independent of submit **eligibility**. Display may be wrong; server must fail closed.

---

## 4. Block/FSE promise boundary

**UPR guarantees (in-repo CI):** C9, C10, and guard enforcement behave consistently when exercised via wp-phpunit and public WooCommerce/WordPress APIs — for classic and block/FSE storefronts alike. No block-specific UPR runtime code.

**UPR does not guarantee:** visual block-theme compatibility (reviews section placement, tab chrome, responsive layout).

**Host obligation:** integrators on block/FSE storefronts perform **host visual verification** that the reviews section exists and native form gating uses C10. Documented integrator acceptance — **not** UPR CI and **not** automated characterization.

---

## 5. Compatibility matrix (implementation doc source)

Canonical runtime matrix: [`docs/integration/storefront-compatibility.md`](../integration/storefront-compatibility.md) (created in WP2).

Rows must cover: classic PDP; block/FSE; reviews disabled; catalogue-hidden; verified purchaser; non-purchaser; guest native; guest invitation; custom PDP without reviews section; `comments_open=false` anti-pattern.

| Validation layer | Scope |
|------------------|--------|
| **UPR CI** | C9/C10/guard alignment; bootstrap priorities; fail-closed resolve; guest form markup/security |
| **Host acceptance** | Visual block-theme layout; PDP a11y audit; schema DOM parity |

---

## 6. Mandatory tests (summary)

- `ReviewAvailability::resolve()` fail-closed on malformed filter results
- Positive logged-in verified-purchaser path allowed; moderation hold unchanged
- Option matrices: reviews on/off, verification on/off, guest, catalogue-hidden — C9/C10 align with enforcement
- No UPR `comments_open` filter registered
- Exactly **one** `GuestSubmissionGuard` on `preprocess_comment` @ **5**; exactly **one** `NativeSubmissionGuard` @ **15**; no duplicate UPR guard registrations; order WC@1 → guest@5 → native@15
- Guest form markup: a11y tokens + security invariants (§2 D4)
- No host/theme/block fixtures; no production HTML; no browser snapshots

---

## 7. WP4 — invitation form accessibility (mandatory)

File: [`src/Http/ReviewFormEndpoint.php`](../../src/Http/ReviewFormEndpoint.php)

Required markup: valid HTML with `lang`; one `h1`; associated labels; fieldset/legend; accessible rating control name; required semantics where applicable.

**Security invariants (tests must prove unchanged):**

- `upr_nonce` and numeric `upr_session_id` hidden fields present
- Form `action` is token-free `/upr-review/form/` route only
- Missing/expired session → HTTP **403** plain-language output
- `Referrer-Policy: no-referrer` and no-cache headers preserved
- No raw invite token, token-bearing URL, email, order id, or customer PII in rendered output
- No change to CSRF validation, session authorization, or comment-creation behaviour

---

## 8. Release gate (`v0.7.0`)

Before pushing annotated `v0.7.0`, verify the tag target commit contains:

- Plugin header `Version: 0.7.0`
- `UPR_VERSION = '0.7.0'`
- Changelog `[0.7.0]` entry
- CI/package version metadata for `0.7.0`

Never tag a commit still declaring `0.6.0`. Freeze tag ≠ release tag. No GitHub Release or ZIP in default M7 closure.

---

## 9. Explicit non-actions (M7 execution)

- No GitHub Release or ZIP
- No DEV or production WordPress access
- No deployment, bind-mount, settings change, or outbound email
- No host/theme/storefront repository changes
- No `v0.7.0` tag until separate metadata-before-tag authorisation

---

## Amendment boundary

Changes to locked decisions D1–D9 require a freeze amendment. Host-specific adapters, themes, and provider code remain out of scope.
