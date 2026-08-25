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

## Storefront adapter

Optional filter for PDP summary data:

```php
apply_filters( 'upr_product_rating_summary', null, $product_id );
// Returns null or [ 'average' => float, 'count' => int, 'url' => string ]
```

Host storefront plugin renders markup; UPR does not output theme HTML.

## Theme adapter

CSS on native WooCommerce reviews tab — host child theme responsibility.

## Card adapter

Host product-card plugin gates display (feature flag + minimum review count). UPR supplies data via native WC product rating APIs.

## Illustrative stub

See [`site-upr-adapters.php.example`](site-upr-adapters.php.example) — **non-runnable** until adapted by host.

## WooCommerce settings

See [`woocommerce-settings.md`](woocommerce-settings.md).
