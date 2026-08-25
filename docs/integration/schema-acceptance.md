# Schema acceptance criteria

UPR does **not** emit Product JSON-LD. Host SEO plugin owns structured data.

## Host integration tests should assert

On representative product detail pages (PDPs):

1. **Single Product entity** — parsed JSON-LD contains exactly one `Product` (or equivalent `@type`) for the canonical product URL/ID.
2. **No duplicate Product graph** — no competing Product entity from WooCommerce core footer schema or duplicate plugins.
3. **Aggregate rating parity** — when visible approved reviews exist, `aggregateRating.ratingCount` / `reviewCount` matches on-page approved review count.
4. **Review node parity** — each schema `review` entry corresponds to a **rendered** review in the `#reviews` section (text and rating).
5. **No orphan schema** — no schema review absent from rendered content.
6. **Empty state** — when no approved reviews, no `aggregateRating` node (typical SEO plugin behaviour when `rating_count < 1`).

## Test approach

- Parse all `application/ld+json` blocks on PDP URL.
- Extract Product nodes by `@type` and canonical URL/id matching.
- Compare against DOM content in `#reviews` or WooCommerce reviews tab panel.

Do **not** use brittle assertions such as "exactly one script tag on page" — Rank Math and similar plugins may emit multi-entity graphs.

## Responsibility

| Layer | Owner |
|-------|-------|
| Schema emission | Host SEO plugin |
| Review visibility | Native WC reviews tab + UPR moderation |
| Acceptance tests | Host CI / acceptance harness |

See [`adapters.md`](adapters.md) for storefront presentation adapters.
