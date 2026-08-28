# Storefront compatibility (M7)

Canonical matrix for how Universal Product Reviews coexists with WooCommerce storefront rendering. **Generic core only** — host themes, block layouts, CSS, schema, and branded copy stay outside this repository.

**Freeze:** [`../roadmap/m7-storefront-compatibility-and-quality.md`](../roadmap/m7-storefront-compatibility-and-quality.md).

## Contract authority

| ID | Role |
|----|------|
| **C9** | `upr_product_review_availability` — submit eligibility (authoritative) |
| **C10** | `NativePdpForm::should_render()` — native PDP form display gate (display-only) |
| **Guards** | `GuestSubmissionGuard` @ `preprocess_comment` **5**; `NativeSubmissionGuard` @ **15** |

**Deferred (not implemented):** **C16** unavailable-message filter; **C17** rating-summary filter. **C11** `upr_product_is_reviewable` remains **provisional (P)** — not promoted in M7.

UPR **never** registers `comments_open` as a submission or availability gate.

## Ownership summary

| Concern | Owner |
|---------|--------|
| May user submit? | UPR — C9 + guards |
| Show native form on PDP? | Host — wrap `#review_form_wrapper` using C10 |
| Show approved review list | WooCommerce / theme |
| Unavailable copy | Host — map C9 `reason_code` |
| Rating summary on PDP | Host — WC `WC_Product::get_average_rating()` / rating count |
| Guest form UI | UPR — `/upr-review/form/` only |
| Schema / JSON-LD | Host SEO plugin — [`schema-acceptance.md`](schema-acceptance.md) |

**Approved-review visibility is independent of submit eligibility.** Hosts must not hide approved lists by closing `comments_open` to gate submission.

## Compatibility matrix

| Rendering path / actor | Supported behaviour | UPR (CI) | Host (acceptance) | Failure mode |
|------------------------|---------------------|----------|-------------------|--------------|
| **Classic PDP — reviews on, reviewable, logged-in verified purchaser** | List visible; native form when C10 true | C9 allows; C10 true; guard 15 passes; hold applies | Render form via C10 | POST without purchase → 403 |
| **Classic PDP — verification required, non-purchaser** | No native submit | C9 `not_verified_purchaser`; C10 false | Hide form; show reason | POST → 403 |
| **Reviews disabled (`woocommerce_enable_reviews=no`)** | No new product reviews | C9 `reviews_disabled`; guards deny | Hide form | POST → 403 |
| **Catalogue-hidden / non-reviewable** | No submit any identity | C9 `product_not_reviewable` | Hide form; list policy optional | POST → 403; invite path blocked when hidden |
| **Guest — native PDP** | No native form or route | C10 always false; guard 5 | No guest native form | POST → 403 |
| **Guest — invitation (`/upr-review/form/`)** | Submit via session + arm | M2 guards unchanged | Log redaction | Expired session → 403 |
| **Block / FSE single product** | Same **C9/C10/enforcement** as classic | Contract tests only | **Visual verification** — reviews section + C10 gating in block layout | UPR fail-closed on POST if display wrong |
| **Custom PDP without reviews section** | UPR does not inject UI | Enforcement only | Provide invitation path for guests | N/A |
| **`comments_open=false` anti-pattern** | Stock WC may hide review list | UPR does not set; guards enforce POST | **Do not** use for submit gate | Fix host adapter |

## Block / FSE promise

**UPR CI guarantees:** C9, C10, and guard behaviour via wp-phpunit — not visual layout.

**Host must verify:** block/FSE themes expose a reviews area and gate the native form with C10. This is **host visual acceptance**, not UPR automated characterization.

## Reason codes (C9)

| Code | Meaning |
|------|---------|
| `reviews_disabled` | WC product reviews off |
| `product_not_reviewable` | e.g. catalogue-hidden |
| `guest_requires_invitation` | Guest without M2 session on native route |
| `not_verified_purchaser` | Logged-in, verification required, not purchased |

## Validation layers

| Layer | In UPR repo | Out of repo |
|-------|-------------|-------------|
| C9/C10/guard alignment | `M7StorefrontCompatibilityIntegrationTest` | — |
| Bootstrap guard priorities | Integration bootstrap tests | — |
| Guest form a11y + security | Markup/integration tests | — |
| PDP visual layout | — | Host theme QA |
| Block-theme layout | — | Host visual verification |
| Schema parity | — | Host SEO acceptance |

## Related

- [`submission-availability.md`](submission-availability.md)
- [`adapters.md`](adapters.md)
- [`integrator-onboarding.md`](integrator-onboarding.md)
- [`../decisions/ADR-0002-productization-boundary.md`](../decisions/ADR-0002-productization-boundary.md)
