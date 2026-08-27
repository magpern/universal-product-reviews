#!/usr/bin/env bash
# Write release.meta.json for a local UPR checkout (DEV). Fail-closed unless HEAD
# matches the annotated tag for the plugin header version.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

HEADER_VERSION="$(
	sed -nE 's/^ \* Version:[[:space:]]*([0-9A-Za-z.-]+).*/\1/p' universal-product-reviews.php | head -n1
)"
TAG="v${HEADER_VERSION}"
if ! git rev-parse --verify "${TAG}^{commit}" >/dev/null 2>&1; then
	echo "ERROR: tag ${TAG} not found" >&2
	exit 1
fi
EXPECTED="$(git rev-parse "${TAG}^{commit}")"
HEAD="$(git rev-parse HEAD)"
if [[ "${HEAD}" != "${EXPECTED}" ]]; then
	echo "ERROR: HEAD ${HEAD} != ${TAG} peel ${EXPECTED}" >&2
	exit 1
fi

python3 - "${ROOT}/release.meta.json" "${HEADER_VERSION}" "${TAG}" "${HEAD}" <<'PY'
import json, sys
path, version, tag, commit = sys.argv[1:5]
meta = {
    "schema": "universal-product-reviews.package-meta/v1",
    "version": version,
    "tag": tag,
    "commit": commit.lower(),
}
with open(path, "w", encoding="utf-8") as fh:
    json.dump(meta, fh, indent=2)
    fh.write("\n")
print("wrote", path)
PY
