# M6 — Integration and Developer Experience — closure

**Verdict:** PASS — generic-core M6 shipped as annotated **`v0.6.0`**. No DEV/production WordPress access, deployment, email, GitHub Release, or ZIP was performed as part of this closure.

## Artifacts

| Item | Reference |
|------|-----------|
| Freeze plan | [`docs/roadmap/m6-integration-and-developer-experience.md`](m6-integration-and-developer-experience.md) |
| Freeze PR | [#30](https://github.com/magpern/universal-product-reviews/pull/30) |
| Freeze merge commit | `04d1a8622835e33a685a193d39a50f1fd62066b4` |
| Freeze tag | `m6-integration-and-developer-experience-freeze` (annotated object `7b4bcee10b7de73f9247efab9214574a59a47447`) → peeled `04d1a86` |
| Implementation PR | [#31](https://github.com/magpern/universal-product-reviews/pull/31) |
| Implementation merge commit | `6074b8e15227da6883c40464166451c1a41ce5dc` |
| C2 reason-cap correction (on PR #31) | `2e7c999b5c8c3f84328e8adfde183ae6cf0306c8` |
| C2 correction CI | [run 33166902710](https://github.com/magpern/universal-product-reviews/actions/runs/33166902710) — **success** on `2e7c999` |
| Implementation merge CI | [run 33167491304](https://github.com/magpern/universal-product-reviews/actions/runs/33167491304) — **success** on `6074b8e` |
| Version metadata PR | [#32](https://github.com/magpern/universal-product-reviews/pull/32) |
| Version metadata merge commit | `49c089f46b1335afbd325367a5a90d442b358947` |
| Metadata post-merge CI | [run 33167667938](https://github.com/magpern/universal-product-reviews/actions/runs/33167667938) — **success** on `49c089f` |
| Release tag | `v0.6.0` (annotated object `9e24d97cf491831e65631f905b0c9763246bfa5a`) → peeled **`49c089f`** |

## Tag target proof (`v0.6.0` → `49c089f`)

The annotated tag peels to the **release-preparation metadata merge**, not the implementation merge (`6074b8e`, still `0.5.0`) and not this closure documentation commit.

On peeled commit `49c089f46b1335afbd325367a5a90d442b358947`:

| Source | Declares |
|--------|----------|
| Plugin header | `Version: 0.6.0` |
| `UPR_VERSION` | `'0.6.0'` |
| `CHANGELOG.md` | `## [0.6.0] - 2026-08-28` |
| `scripts/ci/check.sh` | asserts `Version: 0.6.0` |

## C2 storage alignment (post-review correction)

Inbound C2 reason codes may match `^[a-z0-9_]{1,64}$`, but persisted invite suppression and audit use `delivery_invalidated:<code>` in `suppression_code varchar(64)`. The prefix is 21 characters; normalised reason codes are capped at **43** ASCII characters **before** persistence (`DeliveryEventNormaliser::REASON_MAX_LENGTH`). Invalid or free-text input becomes `unspecified`; raw free text never persists. Unit and integration tests assert invite-row and audit storage boundaries.

## Delivered capabilities (generic core)

- Canonical public contracts registry (`docs/integration/public-contracts.md`, docs/CI label `upr-public-contracts/v1`) and ADR-0003 compatibility policy
- Integrator onboarding, expanded generic adapter example, WC review import **strategy doc only**
- Uncached integration-readiness diagnostics **I1–I5** in Diagnostics and Site Health; support export unchanged (`upr-support-export/v1`)
- Fail-safe untyped C1/C2 receivers with runtime normalisation (`DeliveryEventNormaliser`); schedule source `'adapter'` for C1
- Thin stable helper `DeliveryStatus::has_confirmation( int $order_id ): bool`
- Contract and example-privacy tests; CI stable-contract inventory guard (`scripts/ci/m6-stable-contracts.tsv`)

## Explicit non-actions

- No GitHub Release
- No ZIP / release package publish
- No DEV or production WordPress access
- No deployment, bind-mount, settings change, or outbound email
- No host-/brand-/theme-specific code; no WooCommerce `Internal\*`
- No runtime WC review importer; no public mint/resend APIs; no AI; no M7 work

## Rollback boundary

Revert or deactivate the plugin code if needed. M6 introduced **no** schema migration; invitation/audit tables remain as before M6.

## Next product-development milestone

Post-M6 work (for example **M7 — Storefront Compatibility and Quality** on the forward roadmap) requires its own **plan → freeze → implementation** cycle and is **not** authorised by this closure.
