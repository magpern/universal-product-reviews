# WooCommerce review import strategy (documentation only)

**Status:** Strategy notes for hosts. **Not** an M6 runtime feature — Universal Product Reviews ships **no** review importer in this milestone.

## Principles

1. Prefer leaving historical WooCommerce product reviews (`comment_type=review`) in place; UPR moderates native comments.
2. Do not invent invitation linkage for historical reviews unless you have a verified order-item mapping.
3. Any one-off migration lives in **host** tooling outside this repository.
4. Never import customer email into UPR invitation tables as a shortcut for invites.
5. Support export and diagnostics must not gain import-specific PII fields without a separate freeze.

## Suggested host approach (non-normative)

1. Inventory existing WC reviews and product IDs.
2. Decide whether invitation linkage is required for analytics; default is **no**.
3. If linking, map via host order/item IDs using public WC APIs only — never WooCommerce `Internal\*`.
4. Re-moderate only if policy requires; otherwise leave approved history intact.
5. Validate on a staging clone before production.

## Related

- [`public-contracts.md`](public-contracts.md)
- [`../roadmap/m6-integration-and-developer-experience.md`](../roadmap/m6-integration-and-developer-experience.md)
