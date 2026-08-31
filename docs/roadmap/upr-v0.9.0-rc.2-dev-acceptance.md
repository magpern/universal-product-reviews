# UPR v0.9.0-rc.2 — DEV pre-production acceptance

**Verdict:** **DEV RC ACCEPTED**  
**Date:** 2026-08-31  
**Site:** DEV WordPress instance (Docker bind-mount workflow)  
**Authority:** annotated tag `v0.9.0-rc.2` → peel **`06e406f4ba944b8360209322b3953d0a27f080bc`**

**Production:** Untouched — no production host, database, WordPress, proxy, DNS, real mail transport, GitHub Release, installable ZIP, final `v0.9.0` tag, external OpenAI enablement, auto-spam enablement, or customer data.

**Supersedes:** `v0.9.0-rc.1` for DEV reproducibility (`5b7a7d0` did not include M13 CLI global `--user` fix; rc.1 acceptance was recorded as **RC-plus-fix only**).

---

## Candidate identity (exact)

| Field | Value |
|-------|--------|
| Tag | `v0.9.0-rc.2` |
| UPR commit (HEAD = tag peel = `release.meta.json`) | `06e406f4ba944b8360209322b3953d0a27f080bc` |
| Runtime `UPR_VERSION` | `0.9.0-rc.2` |
| Host adapter (`upr-host-adapter`) | `8ccec6bcdbe07fa124a1cf7efa19ee5c4c462916` (PR [#5](https://github.com/magpern/upr-host-adapter/pull/5)) |
| Storefront acceptance | `d5dc190a2085200e4ce173517ea831cdf97c7b9d` (PR [#4](https://github.com/magpern/storefront-acceptance/pull/4)) |
| Environment | `development` |
| Mail transport | `UniversalProductReviews\Email\LoggingMailTransport` |

**Alignment proof:** bind-mount HEAD, annotated tag peel, and `release.meta.json` commit are identical before testing commenced.

---

## Preflight and baseline

| Gate | Result |
|------|--------|
| Blocksy `woo_has_product_tabs=no` verify | **PASS** |
| `wp upr-host-adapter verify-pilot-preflight` | **PASS** — `UPR=0.9.0-rc.2 @ 06e406f…`, `host=0.1.1` |
| `wp upr-host-adapter verify-dev-mail` | **PASS** — `LoggingMailTransport`, recorded=1 |
| Invitations disabled, pause off, pilot unauthorised, allowlist empty | **PASS** |
| External AI off, auto-spam off, local shadow off (baseline) | **PASS** |
| SupportExport schema `upr-support-export/v1` | **PASS** |

---

## Proof matrix

| Gate | Result |
|------|--------|
| R1 `email_disabled` | **PASS** |
| R2 emergency pause | **PASS** |
| R3 `not_authorised` (non-allowlisted) | **PASS** |
| R5 host cannot override disabled path | **PASS** (prior rehearsal) |
| Reconcile dry-run (`ReconciliationService::run`) | **PASS** |
| R8 AS drain (`verify-as-drain-dev`) | **PASS** |
| R9 token redaction (host-path `access.log` + `bp-cache.log`) | **PASS** |
| R10 `verify-wp6-dev` (8/8) | **PASS** |
| Native submit / catalogue-hidden (`verify-native-submit-dev`) | **PASS** |
| Support lookup (`verify-support-dev`) | **PASS** |
| M14 C20 in-window / expired / spam-block / clock | **PASS** (DEV harness) |
| M13 `wp upr ai-status --user=1` | **PASS** — all masters off |
| M13 `wp upr would-act --user=1` | **PASS** — zero-write, `would_act_total=0` |
| M13 `wp upr ledger-summary --user=1` | **PASS** — all ledger counts 0 |
| M15 row relabels + Keep on hold + footer POST + deferred audit | **PASS** (DEV harness) |
| M15 held count aggregate (no id leak) + plugin-row link | **PASS** |
| Local shadow AI (temp on); no status mutation | **PASS** |
| Playwright mobile sticky (3) + mobile a11y (5) | **PASS** (8 passed, 1 skipped by project) |
| Playwright desktop reviews (8) + schema (1) + purchase panel (4) | **PASS** (13 passed, 2 skipped) |
| Playwright A7 (4/4) + run-checks (4/4) | **PASS** |

Fixture recipients: **`@example.invalid` only**. Controlled validation log: `/tmp/upr-rc2-acceptance-20260831T212946Z.log` (on DEV VPS).

---

## M15 evidence (synthetic, torn down)

- Row actions on `upr_view=pending`: **Publish**, **Mark as spam**, **Move to trash**, **Keep on hold** (`upr_keep_hold` submit+form attribute; no `<form>` in row span).
- Footer POST form with `upr_queue_keep_hold` nonce and `comment_id`.
- `QueueAssessmentPresenter` `<dl>` for no-assessment state; no `comment_content` in presenter output.
- Plugin-row **Product reviews** → `edit-comments.php?upr_view=pending`.
- `review.operator_deferred` audit +1; `comment_approved` unchanged after deferred emit.

---

## Non-blocking tooling notes

| Item | Note |
|------|------|
| `verify-token-redaction-dev` (wpcli container) | **Not authoritative** — SWAG log directory not mounted in ephemeral wpcli. Host-path R9 on DEV reverse-proxy nginx `access.log` and `bp-cache.log` used (same pattern as M3 rehearsal). |
| M13 CLI `--user` (pre-rc.2) | WP-CLI global `--user` name collision with subcommand `$assoc`; fixed in PR [#82](https://github.com/magpern/universal-product-reviews/pull/82), included in rc.2 lineage. Real `wp upr … --user=1` commands verified on rc.2. |
| A7 Playwright selector | Fixed in storefront-acceptance PR #4 (`.upr-host-adapter-review-unavailable`). |
| M14 browser suite | Not run; DEV harness + prior M14 CI/unit/integration closure remain authoritative. |

---

## Restoration proof (final)

```json
{
  "upr_version": "0.9.0-rc.2",
  "meta_commit": "06e406f4ba944b8360209322b3953d0a27f080bc",
  "invitation_emails": false,
  "pause": false,
  "local_shadow": false,
  "external_ai": false,
  "auto_spam": false,
  "host_pilot_auth": false,
  "host_allowlist": [],
  "upr_pending_as": 0
}
```

---

## Explicit non-actions

- No SemVer bump to final `v0.9.0`, GitHub Release, or installable ZIP
- No production deploy or operational enablement
- No external OpenAI credentials or calls
- No auto-spam master enablement
- No customer/production review data committed

---

## Related

- [`CHANGELOG.md`](../../CHANGELOG.md) — `[0.9.0-rc.2]`
- PR [#83](https://github.com/magpern/universal-product-reviews/pull/83) — rc.2 metadata
- PR [#82](https://github.com/magpern/universal-product-reviews/pull/82) — M13 CLI `--user` fix (in rc.2 lineage)
- [`post-m3-product-roadmap.md`](post-m3-product-roadmap.md)
