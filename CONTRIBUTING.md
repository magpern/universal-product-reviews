# Contributing

This is a **private** plugin repository. Contributions follow internal review only.

## Branch workflow

1. Branch from `main` using conventional prefixes: `feat/`, `fix/`, `chore/`, `docs/`.
2. Open a pull request; CI must pass before merge.
3. `main` is protected; direct pushes require passing checks where branch protection permits.

## Commit messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
type(scope): subject

body

footer
```

Types: `feat`, `fix`, `docs`, `chore`, `ci`, `test`, `refactor`.

## Code standards

- PHP 8.1+ with `declare(strict_types=1);` in new files under `src/`.
- Namespace: `UniversalProductReviews\`.
- Prefix: `upr_*` for hooks, tables, CLI, Action Scheduler group.
- **Do not** import `Automattic\WooCommerce\Internal\OrderReviews\*`.
- **Do not** add site, host, or vendor-specific names to `src/` or generic product documentation.

## Local validation

```bash
composer install
composer ci
```

## Milestone boundaries

Each milestone (M0–M8) has explicit scope in `ARCHITECTURE.md`. Do not implement later-milestone features in earlier PRs.

## Release process (documented, not automated in M0)

See [`docs/production-replay.md`](docs/production-replay.md) and [`docs/compatibility/release-process.md`](docs/compatibility/release-process.md).
