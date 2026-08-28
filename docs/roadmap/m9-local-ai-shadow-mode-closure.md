# M9 — Local AI Shadow Mode — closure

**Verdict:** PASS — M9 documentation freeze, implementation, corrective hardening (PR #44), and combined **`v0.8.0`** release metadata/tag completed. **`v0.7.0` was intentionally not published.** No GitHub Release, ZIP, DEV/production WordPress access, deployment, email, external provider call, host adapter, provider filter, automatic moderation action, M10, or M11 work was performed as part of this closure.

## Artifacts

| Item | Reference |
|------|-----------|
| Freeze plan | [`docs/roadmap/m9-local-ai-shadow-mode.md`](m9-local-ai-shadow-mode.md) |
| Boundary ADR | [`docs/decisions/ADR-0004-ai-moderation-boundary.md`](../decisions/ADR-0004-ai-moderation-boundary.md) |
| Freeze PR | [#38](https://github.com/magpern/universal-product-reviews/pull/38) |
| Freeze merge commit | `a3f879321041524831a16bac22be8562223c8d0b` |
| Freeze PR CI | [run 33211960492](https://github.com/magpern/universal-product-reviews/actions/runs/33211960492) — **success** on head `0fddba3` |
| Freeze tag | `m9-local-ai-shadow-mode-freeze` (annotated object `6a6a9942c424ae0a02c3495c115cb130035a90d3`) → peeled **`a3f8793`** |

## Implementation PRs

| WP | PR | Merge commit | PR CI |
|----|----|--------------|-------|
| WP1 schema | [#39](https://github.com/magpern/universal-product-reviews/pull/39) | `0364700c4be7858a8d85ac4dbd09ef63e541bcc1` | [33212457970](https://github.com/magpern/universal-product-reviews/actions/runs/33212457970) success on `38f4d76` |
| WP2 built-in assessor | [#40](https://github.com/magpern/universal-product-reviews/pull/40) | `3bbe401f8b27100fb9cf6cd56543eabd4adce6e3` | [33212585895](https://github.com/magpern/universal-product-reviews/actions/runs/33212585895) success on `57df9c6` |
| WP3–WP6 runtime | [#41](https://github.com/magpern/universal-product-reviews/pull/41) | `7e6d90407936a1299328890179b529688069dbb5` | [33213033541](https://github.com/magpern/universal-product-reviews/actions/runs/33213033541) success on `519a4df` |

Post-merge CI on runtime merge: [run 33213441841](https://github.com/magpern/universal-product-reviews/actions/runs/33213441841) on `7e6d904` — **success**.

## Corrective hardening (PR #44)

| Item | Reference |
|------|-----------|
| Correction PR | [#44](https://github.com/magpern/universal-product-reviews/pull/44) |
| Head (verified) | `69aae3f749bc88e0b02ce580b875e1ec3a229e44` |
| PR CI | [run 33214569044](https://github.com/magpern/universal-product-reviews/actions/runs/33214569044) — **success** on that head |
| Merge commit | `bdb338a95dd8fdbb368b900a95311a971c6e2105` |
| Post-merge CI | [run 33214782438](https://github.com/magpern/universal-product-reviews/actions/runs/33214782438) — **success** on `bdb338a` |

Corrections:

1. Expired rate-window reset: assign `rate_count` before `rate_window_started_at` so an expired full window resets to `1` (not `61`).
2. Failed terminal insert: roll back and keep the owned claim.
3. Failed claim clear: require claim-clear `UPDATE` affected-row count `1` before commit; otherwise roll back the terminal insert.

Integration coverage: `M9OpsAndFinalizeIntegrationTest`.

## Release metadata and tag (`v0.8.0`)

| Item | Reference |
|------|-----------|
| Version metadata PR | [#45](https://github.com/magpern/universal-product-reviews/pull/45) |
| Metadata head | `68dd442152a4f536be64edbb68762aeacf25f881` |
| Metadata PR CI | [run 33214988030](https://github.com/magpern/universal-product-reviews/actions/runs/33214988030) — **success** |
| Metadata merge commit | `c734b5e9f2981f4a78bfcded8e9ea15f611c7465` |
| Metadata post-merge CI | [run 33215189273](https://github.com/magpern/universal-product-reviews/actions/runs/33215189273) — **success** on `c734b5e` |
| Release tag | `v0.8.0` (annotated object `b143619b5341bf4a3e861321b1e06058093773bb`) → peeled **`c734b5e`** |

### Why no `v0.7.0`

**`v0.7.0` was intentionally not published.** There was no matching M7-only version-metadata commit, and by the time release metadata was authorised, `main` already contained both M7 Storefront Compatibility and Quality and M9 Local AI Shadow Mode (plus PR #44). The combined corrected core is released as **`v0.8.0`**.

### Tag target proof (`v0.8.0` → `c734b5e`)

The annotated tag peels to the **release-metadata merge** of PR #45 — not an earlier implementation merge, not PR #44 alone, and not this closure documentation commit.

On peeled commit `c734b5e9f2981f4a78bfcded8e9ea15f611c7465`:

| Source | Declares |
|--------|----------|
| Plugin header | `Version: 0.8.0` |
| `UPR_VERSION` | `'0.8.0'` |
| `CHANGELOG.md` | `## [0.8.0] - 2026-08-28` |
| `scripts/ci/check.sh` | asserts `Version: 0.8.0` |

```text
v0.8.0 (annotated) b143619b5341bf4a3e861321b1e06058093773bb
  → peeled c734b5e9f2981f4a78bfcded8e9ea15f611c7465
```

## Capabilities included in `v0.8.0`

### M7 (Storefront Compatibility and Quality)

- Storefront compatibility matrix and integration-boundary documentation
- Contract-only C9/C10/enforcement characterization (classic and block/FSE); no visual theme suite in UPR
- Mandatory accessibility hardening on core-owned `/upr-review/form/`
- Native route and submission-guard guarantees (fail-closed resolve; guest@5 / native@15; no UPR `comments_open` gating)

### M9 (Local AI Shadow Mode)

- Local-only, default-off advisory shadow assessments; built-in assessor only
- Schema `20260828a`: assessments / claims / ops; AS `upr_assess_review`; claim-before-rate; disable-silent precedence
- Comments-admin `upr_ai` advisory display; diagnostics D12–D15 + Site Health; allowlisted AI audit
- Claim/transaction/rate-window corrective hardening (PR #44)
- **No** external provider, replaceable provider filter, automatic moderation, or AI-driven status changes

## Privacy and local-only enforcement

- Built-in-only assessor under `src/Ai/`; **no** `upr_local_moderation_assessment_provider` / replaceable filter
- CI forbids network primitives (`wp_remote_*`, `wp_safe_remote_*`, `curl_*`, sockets) in `src/Ai/` and forbids provider-filter registration in `src/`
- Fingerprint: plain SHA-256 of canonical non-secret inputs (salt-stable)
- Audit payloads allowlisted only (no score/reason codes/body/PII)
- `upr-support-export/v1` and `upr-public-contracts/v1` **unchanged** (no C18)

## Explicit non-actions

- **No** GitHub Release or ZIP / release-package publish
- **No** DEV or production WordPress access, install/activate/enable of shadow mode, deployment, settings change, or outbound email
- **No** external provider calls; **no** host adapter / provider filter
- **No** automatic approve / reject / spam from AI
- **No** M10 or M11 implementation
- **M10 remains a separately planned milestone** (external / replaceable provider freeze required)

## Next step

Begin a **separate M10 planning cycle** (plan → documentation freeze → implementation). Do **not** treat this closure as Release/ZIP/DEV/prod authorisation or as M10/M11 authorisation.
