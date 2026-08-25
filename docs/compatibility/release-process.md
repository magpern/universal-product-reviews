# Release process (documented — not executed in M0)

## Versioning

- [Semantic Versioning](https://semver.org/) after `1.0.0`.
- M0–M0 freeze uses `0.0.0` scaffold.
- Pre-1.0 milestones may use `0.x.y`.

## Build (future)

1. Bump version in plugin header and `UPR_VERSION` constant.
2. Update `CHANGELOG.md`.
3. Run `composer ci` locally.
4. Build installable ZIP excluding dev files (`.git`, `tests`, `scripts/ci`, docs optional per packaging policy).
5. Generate SHA-256 checksum file alongside ZIP.

Example (to be implemented in M1+):

```bash
# Placeholder — script added in a future milestone
bash scripts/build-zip.sh
shasum -a 256 dist/universal-product-reviews-*.zip > dist/checksum.sha256
```

## Git tags

| Tag pattern | Purpose |
|-------------|---------|
| `plan-rev6-freeze` | Internal architecture baseline (M0) — **not a public release** |
| `v*.*.*` | Installable releases (M1+) |

Do not create GitHub Releases for planning freeze tags.

## Host installation

1. Upload ZIP via WordPress admin or deploy to `wp-content/plugins/universal-product-reviews/`.
2. Activate on staging first.
3. Follow [`docs/production-replay.md`](../production-replay.md).

## Rollback

Install previous ZIP version or deactivate plugin. See production replay rollback section.

## Compatibility verification before activation

- WordPress / WooCommerce / PHP versions
- HPOS declaration visible in WooCommerce features screen
- Adapter plugins present if delivery/support integration required
