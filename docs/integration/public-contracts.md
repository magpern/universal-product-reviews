# Public contracts registry (`upr-public-contracts/v1`)

**Status:** Canonical integrator surface for Universal Product Reviews (M6).  
**Identifier:** `upr-public-contracts/v1` (documentation and CI only — **no** runtime version constant).  
**Freeze:** [`../roadmap/m6-integration-and-developer-experience.md`](../roadmap/m6-integration-and-developer-experience.md).

Stability (**S / P / R / I / D**) and **sensitivity** (**none** / **sensitive-data-bearing**) are independent. Sensitive and restricted surfaces must **never** log, persist, cache, or forward callback inputs/outputs containing emails, tokens, invite URLs, or email bodies.

Support export schema `upr-support-export/v1` is **unchanged** by M6 and is not part of this registry version.

---

## Legend

| Layer | Meaning |
|-------|---------|
| **S** | Stable — SemVer-compatible for hosts |
| **P** | Provisional — documented `0.x`, may evolve |
| **R** | Restricted — token-aware / email rewrite; not primary onboarding |
| **I** | Internal — not a host contract |
| **D** | Deferred — historically named or planned; **absent from code** |

| Sensitivity | Rule |
|-------------|------|
| **none** | No customer PII / tokens / invite URLs by contract |
| **sensitive-data-bearing** | May receive email, tokens, and/or token-bearing URLs (or bodies). No log/persist/cache/forward. |

---

## Stable contracts (S)

### C1 — `upr_order_delivery_confirmed` (**S**, sensitivity: none)

| | |
|--|--|
| **Type** | Action (host fires; core listens) |
| **Host signature** | `do_action( 'upr_order_delivery_confirmed', int $order_id, array $context = array() );` |
| **Core receiver** | Mixed inbound args (fail-safe). Invalid/`≤0` order → no-op. Non-array context → `[]`. |
| **Allowlist** | `delivered_at` only (optional unix). No inert `source` / `reason_code`. |
| **Normalisation** | Invalid / ≤0 / pre-2000-01-01 / > now+86400 → `time()`. Persist + schedule use normalised unix only. Schedule source always `'adapter'`. |
| **Failure** | Missing order: no meta; no schedule if controls block. |
| **Idempotency** | Re-confirm safe; not exactly-once. |
| **Tests** | Unit normaliser; integration confirm + malformed args |
| **Code** | `InvitationScheduler::on_delivery_confirmed`, `DeliveryEventNormaliser` |

### C2 — `upr_order_delivery_invalidated` (**S**, sensitivity: none)

| | |
|--|--|
| **Type** | Action (host fires; core listens) |
| **Host signature** | `do_action( 'upr_order_delivery_invalidated', int $order_id, string $reason );` |
| **Core receiver** | Mixed inbound. Invalid order → no-op. Non-string reason → `unspecified`. |
| **Reason** | After trim must match `^[a-z0-9_]{1,64}$` else `unspecified`. Normalised reason capped at **43** chars so `delivery_invalidated:` + code fits `varchar(64)`; longer valid-pattern codes truncate deterministically at normalisation (never silent downstream `substr`). Stored only as `delivery_invalidated:<code>`. Free text never stored. |
| **Tests** | Unit + integration (free-text never in storage; malformed args) |
| **Code** | `InvitationScheduler::on_delivery_invalidated`, `DeliveryEventNormaliser` |

### C3 — `upr_is_order_delivered` (**S**, none)

| | |
|--|--|
| **Type** | Filter (host) |
| **Signature** | `apply_filters( 'upr_is_order_delivered', bool $delivered, int $order_id ): bool` |
| **Notes** | Used by completed-fallback skip + reconciliation. **Optional** if host only fires C1. |
| **Code** | `InvitationScheduler`, `ReconciliationService` |

### C5 — `upr_review_invitation_action` (**S**, none)

| | |
|--|--|
| **Type** | Filter |
| **Signature** | `apply_filters( 'upr_review_invitation_action', array $decision, int $order_id, int $order_item_id ): array` |
| **Code** | `Eligibility` |

### C6 — `upr_invitation_send_authorisation` (**S**, none)

| | |
|--|--|
| **Type** | Filter |
| **Signature** | See [`adapters.md`](adapters.md) — host may demote `allow` → `not_authorised` only. |
| **Code** | `InvitationAuthorisation` |

### C7 — `upr_mail_transport` / `MailTransport` (**S**, **sensitive-data-bearing**)

| | |
|--|--|
| **Type** | Filter + PHP interface |
| **Signature** | `apply_filters( 'upr_mail_transport', ?MailTransport $transport )`; `MailTransport::send( EmailMessage ): SendResult` |
| **Sensitivity** | Receives recipient email and token-bearing invite URLs. Never log/persist/forward. Preferred over C14. |
| **Code** | `MailTransportFactory`, `MailTransport` |

### C8a — `upr_review_form_base_url` (**S**, none)

| | |
|--|--|
| **Type** | Filter |
| **Signature** | `apply_filters( 'upr_review_form_base_url', string $url ): string` |
| **Notes** | **Token-free** base URL only. |
| **Code** | `DefaultReviewLinkBuilder` |

### C9 — `upr_product_review_availability` + helpers (**S**, none)

| | |
|--|--|
| **Type** | Filter + PHP helpers |
| **Signature** | See [`submission-availability.md`](submission-availability.md) |
| **Code** | `ReviewAvailability` |

### C10 — `NativePdpForm::should_render` (**S**, none)

| | |
|--|--|
| **Type** | PHP helper (display-only) |
| **Signature** | `NativePdpForm::should_render( int $product_id ): bool` |
| **Code** | `Submission\NativePdpForm` |

### C18 — `DeliveryStatus::has_confirmation` (**S**, none)

| | |
|--|--|
| **Type** | PHP helper |
| **Signature** | `DeliveryStatus::has_confirmation( int $order_id ): bool` |
| **Notes** | True iff order meta `_upr_delivery_confirmed_at` is non-empty. Invalid/missing order → false. Discoverability only — not adapter/ops proof. |
| **Code** | `Invitations\DeliveryStatus` |

### C19 — `AiProvider::selected` (**S**, none)

| | |
|--|--|
| **Type** | PHP helper |
| **Signature** | `UniversalProductReviews\Ai\AiProvider::selected(): string` |
| **Notes** | Returns `'local'` or `'openai'` from sanitised settings (default `'local'`). Discoverability only — never secrets, review text, or raw provider output. Implemented in M10. |
| **Code** | `Ai\AiProvider` |

---

## Provisional (P)

| ID | Entry | Notes |
|----|-------|-------|
| C4 | `upr_order_delivery_confirmed_at` | Fill unix when meta missing |
| C11 | `upr_product_is_reviewable` | Towards **S** after docs maturity |
| C12 | `upr_item_is_reviewable` | |
| C13 | `upr_include_zero_total_items` | |
| C15 | `upr_review_min_length` | |
| C20 | `CustomerEditAvailability::resolve` | M14 display helper (**P**). Signature `resolve( int $comment_id, int $user_id ): array{ can_edit: bool, reason_code: string }`. **No** filter that can force `can_edit=true`. Not a write grant. Code: `src/CustomerEdit/CustomerEditAvailability.php`. Freeze: [`../roadmap/m14-customer-seven-day-review-edits.md`](../roadmap/m14-customer-seven-day-review-edits.md). |

---

## Restricted (R) — sensitive by nature

| ID | Entry | Rule |
|----|-------|------|
| C8b | `upr_review_link_builder` / `ReviewLinkBuilder` | `invite_exchange_url( string $raw_invite_token )` is token-aware. Never log/cache/forward token or built URL. |
| C14 | `upr_invitation_email_{body,subject,headers}` | May contain invite URLs/tokens + product names. Never log/persist/forward. Prefer C7. |

---

## Deferred (D) — absent from code

| ID | Name | Guidance |
|----|------|----------|
| C16 | `upr_product_review_unavailable_message` | Host UI via C9 reason codes; **do not** treat as implemented |
| C17 | `upr_product_rating_summary` | Storefront later; **do not** treat as implemented |

---

## Internal (I) — not host contracts

Action Scheduler hooks `upr_send_*`, `upr_schedule_order_items`, `upr_reconcile_invitations`, `upr_db_upgrade`; `admin_post` handlers; repositories.

---

## Inventory completeness

Every `upr_*` filter applied in `src/`, every `upr_order_delivery_*` action core listens for, public interfaces/helpers above, and deferred absences are listed here. CI verifies **S** entries exist with expected type and that this document references them (`scripts/ci/m6-stable-contracts.tsv`).

---

## Related

- [`integrator-onboarding.md`](integrator-onboarding.md)
- [`adapters.md`](adapters.md)
- [`../roadmap/m14-customer-seven-day-review-edits.md`](../roadmap/m14-customer-seven-day-review-edits.md)
- [`wc-review-import-strategy.md`](wc-review-import-strategy.md) (docs-only; no M6 runtime importer)
