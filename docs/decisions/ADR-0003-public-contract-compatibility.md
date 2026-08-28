# ADR-0003: Public contract compatibility

**Status:** Accepted (M6)  
**Date:** 2026-08-28  
**Context:** Hosts integrate via WordPress hooks and thin PHP helpers. M6 freezes a documented public surface (`upr-public-contracts/v1` in docs/CI only).

## Decision

1. Classify every inventoried surface as **S / P / R / I / D**, with independent **sensitivity**.
2. Pre-1.0: **S** breaks only in a minor release with CHANGELOG section **“Breaking (public contracts)”** and a registry doc version bump (`v1` → `v2`).
3. Prefer ≥1 minor deprecation note before removal, except security emergencies (freeze amendment).
4. **Restricted** and **sensitive-data-bearing** contracts require documented no-log / no-persist / no-forward rules; shipped examples must obey; tests assert examples.
5. Do not declare inert keys or unimplemented hooks as **S**.
6. No runtime `UPR_PUBLIC_CONTRACTS_VERSION` constant until a concrete consumer exists.

## Consequences

- Integrators pin against plugin SemVer and the registry doc identifier.
- CI enforces that every **S** entry exists in code and is documented.
- Support export remains a separate schema (`upr-support-export/v1`) and is not versioned with this ADR.

## Related

- [`../integration/public-contracts.md`](../integration/public-contracts.md)
- [`../roadmap/m6-integration-and-developer-experience.md`](../roadmap/m6-integration-and-developer-experience.md)
