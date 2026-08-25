# Universal Product Reviews

Public-source, proprietary WooCommerce plugin (`UPR`) for verified-purchase product reviews, line-item invitation lifecycle, moderation, retention, and host adapter integration.

**Version:** `0.0.0` (M0 foundation — no runtime review capability)

## Status

| Milestone | State |
|-----------|-------|
| M0 | Repository foundation and frozen architecture (Rev 6) |
| M1+ | Not started |

This repository contains the portable plugin core and generic integration documentation. Host-specific adapters, theme CSS, and site configuration live **outside** this repository.

## Requirements

- WordPress 6.5+
- PHP 8.1+
- WooCommerce (HPOS-compatible hosts supported; declared in M1+)

## Installation (future)

M0 does not target WordPress activation. After M1+, hosts install the plugin ZIP from a tagged release. See [`docs/production-replay.md`](docs/production-replay.md).

## Documentation

| Document | Purpose |
|----------|---------|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Authoritative frozen technical specification (Rev 6) |
| [`docs/decisions/ADR-0001-repository-visibility.md`](docs/decisions/ADR-0001-repository-visibility.md) | Public repository governance |
| [`docs/production-replay.md`](docs/production-replay.md) | Host integration and deployment runbook |
| [`docs/integration/`](docs/integration/) | Adapter contracts and examples |
| [`docs/compatibility/`](docs/compatibility/) | Minimum platform expectations |
| [`docs/runbooks/`](docs/runbooks/) | Operator runbooks |

## Development

```bash
composer install
composer ci
```

## Licence

Proprietary — public visibility does not grant an open-source licence. See [`LICENSE.md`](LICENSE.md).

## Freeze baseline

Planning freeze tag: `plan-rev6-freeze` (internal baseline, not a public release).
