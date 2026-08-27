# UPR package metadata (`release.meta.json`)

Generic, host-agnostic build metadata embedded in **packaged** Universal Product Reviews trees. It is **not** committed into annotated release tags; build scripts inject it at package time.

## Schema

File basename: `release.meta.json` (plugin root).

```json
{
  "schema": "universal-product-reviews.package-meta/v1",
  "version": "0.3.0",
  "tag": "v0.3.0",
  "commit": "b2abc2defc30fc023601593aa1720cbfdd0a4f3c"
}
```

| Field | Meaning |
|-------|---------|
| `schema` | Must equal `universal-product-reviews.package-meta/v1` |
| `version` | Semantic version matching the plugin header / `UPR_VERSION` |
| `tag` | Exact Git tag used as the immutable package source |
| `commit` | Full 40-char lowercase SHA of that tag’s peel |

No host, brand, or site-specific keys are permitted.

## Integrity model

1. Package is built from an **exact tag/commit** via `scripts/build-release-package.sh`.
2. SHA-256 of the ZIP (and meta copy) is recorded in `*.SHA256SUMS`.
3. Installers/operators verify the ZIP against the sums before staging.
4. Host plugins that pin UPR (e.g. Biopentra host 0.1.5+) fail closed unless this file matches their required version/tag/commit.

Cryptographic signing of the meta file is out of scope for `package-meta/v1`; trust is the combination of private artifact retention + SHA-256 + fail-closed host pin.

## Build

```bash
# From a clone that can see the tag:
git fetch --tags
bash scripts/build-release-package.sh v0.3.0
```

Outputs (gitignored `builds/`):

- `universal-product-reviews-0.3.0.zip`
- `universal-product-reviews-0.3.0.SHA256SUMS`
- `universal-product-reviews-0.3.0.release.meta.json`

Does **not** alter or retag `v0.3.0` source. Does **not** create a public GitHub Release.

## Development checkouts

Runtime pin consumers must see the same file. For a verified local checkout at the pinned commit:

```bash
bash scripts/write-release-meta-from-git.sh
```

That helper refuses to write unless `HEAD` matches the exact tag peel for the header version.
