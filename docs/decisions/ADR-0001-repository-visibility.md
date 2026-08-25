# ADR-0001: Repository visibility and public-source governance

**Status:** Accepted  
**Date:** 2026-08-26  
**Decision owner:** Maintainer

## Context

The M0 planning prompt and Rev 6 architecture freeze assumed that the Universal Product Reviews repository would be private. The repository is currently public so that GitHub Actions can use the available runner configuration.

The codebase is generic and currently contains an inert M0 scaffold plus architecture, operational, and integration documentation. It contains no host configuration, credentials, customer data, production deployment material, or runtime review capability.

Several M0 documents incorrectly described the repository as private after its visibility was changed.

## Decision

`magpern/universal-product-reviews` remains **public for now**.

This ADR overrides the M0/Rev 6 private-repository assumption and any repository documentation that describes the current repository as private. The `plan-rev6-freeze` tag remains a historical architecture baseline; it does not override this later governance decision.

The project remains proprietary unless and until the maintainer explicitly adopts an open-source licence. Public visibility permits source inspection; it does not grant permission to copy, redistribute, deploy, or create derivative works.

## Mandatory public-source controls

- Never commit credentials, tokens, private keys, customer/order/review data, production configuration, host paths, IP addresses, or internal-only operational details.
- Keep host-specific adapters and deployment configuration outside this repository.
- Use generic examples with clearly fictitious values only.
- Treat issue reports and pull requests as public communications.
- Review every future change for security-sensitive token, authorization, privacy, and abuse-handling implications before merge.
- Security vulnerabilities must not be described in public issues before a fix and disclosure decision are available. Use the repository security-advisory/reporting channel when configured, otherwise contact the maintainer privately.
- A future decision may re-private the repository or adopt an open-source licence; either requires a new ADR.

## Consequences

- Documentation and contribution guidance must accurately state that the repository is public and proprietary.
- M1 may proceed only with these controls observed.
- No scope, technical contract, host integration boundary, or production authority changes as a result of this ADR.
