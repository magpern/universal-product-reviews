# Adapter contracts

Host integrations wire into UPR via public hooks. Adapters live **outside** the core plugin repository (MU-plugins, companion plugins, theme functions).

**Canonical registry:** [`public-contracts.md`](public-contracts.md) (`upr-public-contracts/v1`).  
**Onboarding:** [`integrator-onboarding.md`](integrator-onboarding.md).  
**Compatibility:** [`../decisions/ADR-0003-public-contract-compatibility.md`](../decisions/ADR-0003-public-contract-compatibility.md).

## Delivery adapter

**Purpose:** Signal when an entire order is confirmed delivered (not merely shipped).

### Actions (adapter fires)

```php
do_action( 'upr_order_delivery_confirmed', int $order_id, array $context = array() );
do_action( 'upr_order_delivery_invalidated', int $order_id, string $reason );
```

**C1 `$context` (M6):** optional `delivered_at` (unix) only. Unknown keys ignored. Core always schedules with source `'adapter'`. Invalid/implausible timestamps fall back to `time()`. Core listeners accept malformed args without fatal errors.

**C2 `$reason`:** non-PII code matching `^[a-z0-9_]{1,64}$` (e.g. `cancel`, `refund`). Normalised reason is capped at **43** characters so `delivery_invalidated:` + code fits existing `suppression_code varchar(64)`; longer valid-pattern codes truncate at normalisation. Empty/invalid/non-string → stored as `delivery_invalidated:unspecified`. Free text never reaches storage.

Requirements:

- Emit `upr_order_delivery_confirmed` only when **all** line items satisfy host delivery rules.
- Idempotent re-confirm is safe (not exactly-once).
- Invalidate on cancel, full refund, or delivery reversal.

### Filter (adapter implements — optional if you only fire C1)

```php
apply_filters( 'upr_is_order_delivered', false, $order_id );
```

Used by reconciliation and completed-fallback skip. Missing this filter does **not** mean emails must not run.

### Helper

```php
\UniversalProductReviews\Invitations\DeliveryStatus::has_confirmation( int $order_id ): bool
```

True when `_upr_delivery_confirmed_at` is set — discoverability only.

## Support adapter

**Purpose:** Delay or suppress invitations without biasing review samples.

```php
/**
 * @param array{action:string,code?:string,delay_until?:int} $decision
 * @return array{action:'none'|'delay'|'suppress',code?:string,delay_until?:int}
 */
apply_filters(
  'upr_review_invitation_action',
  [ 'action' => 'none' ],
  $order_id,
  $order_item_id
);
```

Guidelines:

- **`suppress`:** compliance/safety, chargeback, opt-out, cancel/refund — permanent until condition clears.
- **`delay`:** open support ticket, dissatisfaction without compliance tag — reschedule only.
- **`none`:** send when otherwise eligible.

## Invitation send authorisation (M3 contract)

**Purpose:** Host policy may further restrict invitation **scheduling and sending** after core master enable and emergency pause allow the operation. Core remains authoritative for `email_disabled` and `paused`.

```php
/**
 * @param array{decision:string,reason_code?:string} $decision
 * @param array{
 *   order_id:int,
 *   order_item_id:int,
 *   product_id:int,
 *   operation:'schedule'|'initial_send'|'reminder_send',
 *   source_event_unix:int
 * } $context
 * @return array{decision:'allow'|'email_disabled'|'paused'|'not_authorised', reason_code?:string}
 */
apply_filters( 'upr_invitation_send_authorisation', $decision, $context );
```

Rules:

- Evaluated before schedule (delivery / completed fallback / reconcile) and immediately before initial/reminder send.
- Core does **not** call the host filter when master enable is off or emergency pause is on.
- Core also denies with `not_authorised` / `outside_scheduling_boundary` when `source_event_unix` is before `upr_invitation_scheduling_boundary_at` (refreshed on enable and on unpause). Host filter is not called for that deny.
- Hosts may only change provisional `allow` → `not_authorised` (or keep `allow`).
- Denied decisions must not invoke mail transport or write sent-state / `email.sent` success audits.
- Do not implement denial by dropping mail in a transport wrapper after UPR would mark sent.
- Deliberate pilot invite of a pre-boundary order must be an explicit operator action that supplies a post-boundary source timestamp; reconciliation must not backfill those events.

See [`../roadmap/m3-invitation-email-controls.md`](../roadmap/m3-invitation-email-controls.md).

## Mail transport adapter (**stable**, **sensitive-data-bearing**)

**Purpose:** Replace or wrap the default mail transport. Host SES/SMTP adapters live **outside** this repository.

```php
apply_filters( 'upr_mail_transport', ?MailTransport $transport );
```

- Production default: `WpMailTransport` (`wp_mail`) — **at-least-once**, not exactly-once.
- Non-production default: logging/fake transport (no real email).
- Messages carry a stable UPR `message_id` for provider-side idempotency.
- **Privacy:** transport sees recipient email and token-bearing invite URLs. Never log, persist, cache, or forward those fields to analytics/HTTP.

See [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md).

## Review link builder

### Token-free base URL (**stable**)

```php
apply_filters( 'upr_review_form_base_url', string $url ): string;
```

### Token-aware builder (**restricted**)

Hosts may supply a `ReviewLinkBuilder` via `upr_review_link_builder`. `invite_exchange_url( string $raw_invite_token )` embeds the raw token by design. Never log/cache/forward the token or built URL.

## Host security duties (M2)

When M2 runtime is enabled, hosts **must** redact or exclude `/upr-review/{token}/` from web-server access logs. See [`../runbooks/token-incidents.md`](../runbooks/token-incidents.md).

## Storefront summary (**deferred** — not implemented in core)

Historical docs mentioned `upr_product_rating_summary`. That filter is **absent from code** (registry **D**). Hosts that need PDP summary data should use public WooCommerce rating APIs or a later milestone — do not rely on a UPR filter that does not exist.

## Availability messaging

**Source of truth:** Filter `upr_product_review_availability` (core default). See [`submission-availability.md`](submission-availability.md).

**Deferred:** `upr_product_review_unavailable_message` is **not** implemented in core. Hosts render copy from C9 reason codes.

**B1+ (`v0.2.2`):** Core adds availability-aligned native denial (`NativeSubmissionGuard`) and display-only `NativePdpForm::should_render()`. UPR does not use `comments_open` as an availability gate. M2 guest forms remain exclusively on `/upr-review/form/`.

## Theme adapter

CSS on native WooCommerce reviews tab — host child theme responsibility.

## Card adapter

Host product-card plugin gates display (feature flag + minimum review count). UPR supplies data via native WC product rating APIs.

## Email rewrite filters (**restricted**)

`upr_invitation_email_body`, `upr_invitation_email_subject`, `upr_invitation_email_headers` may contain invite URLs/tokens. Prefer C7 mail transport. Never log/persist/forward rewrite inputs.

## Illustrative stub

See [`site-upr-adapters.php.example`](site-upr-adapters.php.example) — **non-runnable** until adapted by host. Examples must not log/persist/forward sensitive data.

## WooCommerce settings

See [`woocommerce-settings.md`](woocommerce-settings.md).

## Review import

Docs-only strategy: [`wc-review-import-strategy.md`](wc-review-import-strategy.md). No M6 runtime importer.
