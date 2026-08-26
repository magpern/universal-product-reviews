# Universal Product Reviews

Public-source, proprietary WooCommerce plugin (`UPR`) for verified-purchase product reviews, line-item invitation lifecycle, moderation, retention, and host adapter integration.

**Version:** `0.2.0` (M2 invitations — unreleased; awaiting closure acceptance before `v0.2.0` tag)

## Status

| Milestone | State |
|-----------|-------|
| M0 | Repository foundation and frozen architecture (Rev 6) |
| M1 | Core enablement — bootstrap, HPOS declare, moderation hold, guest guard, availability filters (`v0.1.0`) |
| M2 | Invitations runtime implemented on `feat/m2-invitations` (frozen at `m2-invitations-freeze`; not tagged `v0.2.0`) |
| M3+ | Not started |

This repository contains the portable plugin core and generic integration documentation. Host-specific adapters, theme CSS, and site configuration live **outside** this repository.

## Requirements

- WordPress 6.5+ (M1 CI/DEV mandatory: 7.0.2)
- PHP 8.1+ (M1 CI/DEV mandatory: 8.4)
- WooCommerce 8.2+ (M1 CI/DEV mandatory: 11.0.1)

## Installation

Hosts install via bind-mount (DEV) or plugin ZIP (staging/production) after validating [`docs/production-replay.md`](docs/production-replay.md). Apply WooCommerce settings per [`docs/integration/woocommerce-settings.md`](docs/integration/woocommerce-settings.md).

## Documentation

| Document | Purpose |
|----------|---------|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Authoritative frozen technical specification (Rev 6) |
| [`docs/milestones/M1-core-enablement.md`](docs/milestones/M1-core-enablement.md) | Frozen M1 specification |
| [`docs/milestones/M2-invitations.md`](docs/milestones/M2-invitations.md) | Frozen M2 invitations specification |
| [`docs/decisions/ADR-0001-repository-visibility.md`](docs/decisions/ADR-0001-repository-visibility.md) | Public repository governance |
| [`docs/production-replay.md`](docs/production-replay.md) | Host integration and deployment runbook |
| [`docs/integration/`](docs/integration/) | Adapter contracts and examples |
| [`docs/compatibility/`](docs/compatibility/) | Minimum platform expectations |
| [`docs/runbooks/`](docs/runbooks/) | Operator runbooks |

## Development

```bash
composer install
composer ci
composer test:unit
# Integration (requires MySQL):
bash tests/bin/install-wp.sh
composer test:integration
```

## Licence

Proprietary — public visibility does not grant an open-source licence. See [`LICENSE.md`](LICENSE.md).

## Freeze baselines

| Tag | Purpose |
|-----|---------|
| `plan-rev6-freeze` | Rev 6 architecture baseline |
| `m1-core-enablement-freeze` | M1 plan freeze (documentation only) |
| `v0.1.0` | M1 runtime release (accepted) |
| `m2-invitations-freeze` | M2 plan freeze (documentation only) |

Release tag `v0.2.0` is created only after M2 closure acceptance.
