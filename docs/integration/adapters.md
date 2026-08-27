# Adapter contracts

Host integrations wire into UPR via public hooks. Adapters live **outside** the core plugin repository (MU-plugins, companion plugins, theme functions).

## Delivery adapter

**Purpose:** Signal when an entire order is confirmed delivered (not merely shipped).

### Actions (adapter fires)

```php
do_action( 'upr_order_delivery_confirmed', int $order_id, array $context );
do_action( 'upr_order_delivery_invalidated', int $order_id, string $reason );
```

Requirements:

- Emit `upr_order_delivery_confirmed` only when **all** line items satisfy host delivery rules.
- Idempotent — at most one actionable confirmation per order until invalidated.
- Invalidate on cancel, full refund, or delivery reversal.

### Filter (adapter implements)

```php
apply_filters( 'upr_is_order_delivered', false, $order_id );
```

Used by reconciliation CLI to find eligible orders without direct access to fulfillment databases.

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
 *   operation:'schedule'|'initial_send'|'reminder_send'
 * } $context
 * @return array{decision:'allow'|'email_disabled'|'paused'|'not_authorised', reason_code?:string}
 */
apply_filters( 'upr_invitation_send_authorisation', $decision, $context );
```

Rules:

- Evaluated before schedule (delivery / completed fallback / reconcile) and immediately before initial/reminder send.
- Core does **not** call the host filter when master enable is off or emergency pause is on.
- Hosts may only change provisional `allow` → `not_authorised` (or keep `allow`).
- Denied decisions must not invoke mail transport or write sent-state / `email.sent` success audits.
- Do not implement denial by dropping mail in a transport wrapper after UPR would mark sent.

See [`../roadmap/m3-invitation-email-controls.md`](../roadmap/m3-invitation-email-controls.md).

## Mail transport adapter (M2 contract)

**Purpose:** Replace or wrap the default mail transport. Host SES/SMTP adapters live **outside** this repository.

```php
apply_filters( 'upr_mail_transport', ?MailTransport $transport );
```

- Production default: `WpMailTransport` (`wp_mail`) — **at-least-once**, not exactly-once.
- Non-production default: logging/fake transport (no real email).
- Messages carry a stable UPR `message_id` for provider-side idempotency.

See [`../milestones/M2-invitations.md`](../milestones/M2-invitations.md).

## Review link builder (M2 contract)

Hosts may supply a `ReviewLinkBuilder` via `upr_review_link_builder`, or override the token-free form base via `upr_review_form_base_url`.

**Forbidden:** public filters that receive raw invite or session secrets.

## Host security duties (M2)

When M2 runtime is enabled, hosts **must** redact or exclude `/upr-review/{token}/` from web-server access logs. See [`../runbooks/token-incidents.md`](../runbooks/token-incidents.md).

## Storefront adapter

Optional filter for PDP summary data:

```php
apply_filters( 'upr_product_rating_summary', null, $product_id );
// Returns null or [ 'average' => float, 'count' => int, 'url' => string ]
```

Host storefront plugin renders markup; UPR does not output theme HTML.

## Availability messaging adapter (M1 contract / M3 UI)

**Purpose:** Consume read-only submission eligibility data for customer-facing messaging.

**Source of truth:** Filter `upr_product_review_availability` (core default). Native product-comment enforcement in core must align with that contract ([ADR-0002](../decisions/ADR-0002-productization-boundary.md); [submission-availability.md](submission-availability.md)).

**M1:** UPR enforces policy via `preprocess_comment` (guest block) and exposes filters only. Host adapters **may** read filters for diagnostics or minimal copy; polished PDP unavailable-form UI is **M3**.

**M2:** Guests with a valid form-session cookie may submit via the invitation form path; native PDP remains default-deny without that session.

**M3:** Host storefront or theme adapter renders messaging when `can_submit` is false. Hosts **must not** set `comments_open` to false to express unavailable submission — that suppresses approved review lists in stock WooCommerce templates.

**B1+ (`v0.2.2`):** Core adds availability-aligned native denial for all identities (`NativeSubmissionGuard`) and display-only `NativePdpForm::should_render()`. Hosts consume the helper for form visibility; they must not reimplement availability-aligned native POST denial as long-term security. UPR does not use `comments_open` as an availability gate. M2 guest forms remain exclusively on `/upr-review/form/`.

### Filters (UPR provides defaults)

```php
apply_filters( 'upr_product_review_availability', $availability, $product_id, $user_id );
apply_filters( 'upr_product_review_unavailable_message', null, $reason_code, $product_id, $user_id );
```

See [`submission-availability.md`](submission-availability.md) for reason codes:

- `reviews_disabled`
- `product_not_reviewable`
- `guest_requires_invitation`
- `not_verified_purchaser`

Host adapters render markup; UPR never calls `wc_add_notice` or theme template hooks in M1/M2 core for PDP messaging.

## Theme adapter

CSS on native WooCommerce reviews tab — host child theme responsibility.

## Card adapter

Host product-card plugin gates display (feature flag + minimum review count). UPR supplies data via native WC product rating APIs.

## Illustrative stub

See [`site-upr-adapters.php.example`](site-upr-adapters.php.example) — **non-runnable** until adapted by host.

## WooCommerce settings

See [`woocommerce-settings.md`](woocommerce-settings.md).
