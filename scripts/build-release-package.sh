#!/usr/bin/env bash
#
# Build a deterministic Universal Product Reviews release package from an
# immutable Git ref. Injects generic release.meta.json without modifying the
# tagged source tree or retagging.
#
# Usage:
#   scripts/build-release-package.sh [ref]
# Default ref: v0.3.0
#
# Outputs under builds/ (gitignored):
#   universal-product-reviews-<version>.zip
#   universal-product-reviews-<version>.SHA256SUMS
#   universal-product-reviews-<version>.release.meta.json  (copy of embedded meta)
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REF="${1:-v0.3.0}"
OUT_DIR="${ROOT}/builds"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/upr-pkg.XXXXXX")"
cleanup() {
	# Composer-in-Docker may leave root-owned vendor/; best-effort remove.
	chmod -R u+w "${STAGE}" 2>/dev/null || true
	rm -rf "${STAGE}" 2>/dev/null || docker run --rm -v "${STAGE}:/stage" alpine:3.20 rm -rf /stage >/dev/null 2>&1 || true
}
trap cleanup EXIT

cd "${ROOT}"

if ! git rev-parse --verify "${REF}^{commit}" >/dev/null 2>&1; then
	echo "ERROR: unknown ref ${REF}" >&2
	exit 1
fi

COMMIT="$(git rev-parse "${REF}^{commit}")"
# Prefer annotated tag name when REF is a tag; otherwise use exact-match describe.
if git rev-parse -q --verify "refs/tags/${REF}" >/dev/null 2>&1; then
	TAG="${REF}"
else
	TAG="$(git describe --tags --exact-match "${COMMIT}" 2>/dev/null || true)"
	if [[ -z "${TAG}" ]]; then
		echo "ERROR: ref ${REF} (${COMMIT}) is not an exact annotated/lightweight tag; refuse ambiguous package" >&2
		exit 1
	fi
fi

# Extract Version: from plugin header at that tree (not working tree).
HEADER_VERSION="$(
	git show "${COMMIT}:universal-product-reviews.php" \
		| sed -nE 's/^ \* Version:[[:space:]]*([0-9A-Za-z.-]+).*/\1/p' \
		| head -n1
)"
if [[ -z "${HEADER_VERSION}" ]]; then
	echo "ERROR: could not read Version header at ${COMMIT}" >&2
	exit 1
fi

# Tag convention: v<version>
EXPECTED_TAG="v${HEADER_VERSION}"
if [[ "${TAG}" != "${EXPECTED_TAG}" ]]; then
	echo "ERROR: tag ${TAG} does not match header version ${HEADER_VERSION} (expected ${EXPECTED_TAG})" >&2
	exit 1
fi

SLUG="universal-product-reviews"
ZIP_NAME="${SLUG}-${HEADER_VERSION}.zip"
ZIP_PATH="${OUT_DIR}/${ZIP_NAME}"
SUMS_PATH="${OUT_DIR}/${SLUG}-${HEADER_VERSION}.SHA256SUMS"
META_COPY="${OUT_DIR}/${SLUG}-${HEADER_VERSION}.release.meta.json"
PKG_DIR="${STAGE}/${SLUG}"

echo "==> Building UPR package"
echo "    ref:     ${REF}"
echo "    tag:     ${TAG}"
echo "    commit:  ${COMMIT}"
echo "    version: ${HEADER_VERSION}"
echo "    output:  ${ZIP_PATH}"

mkdir -p "${OUT_DIR}" "${PKG_DIR}"

echo "==> Exporting immutable tree (no .git)"
git archive --format=tar "${COMMIT}" | tar -C "${PKG_DIR}" -xf -

# Production autoload only (no dev deps). Prefer host composer, else docker.
echo "==> composer install --no-dev"
if command -v composer >/dev/null 2>&1; then
	(
		cd "${PKG_DIR}"
		composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
	)
else
	docker run --rm \
		-v "${PKG_DIR}:/app" \
		-w /app \
		composer:2 \
		composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
fi

# Strip residual VCS / CI / tests if somehow present (archive should already omit .git).
rm -rf "${PKG_DIR}/.git" "${PKG_DIR}/.github" "${PKG_DIR}/tests" \
	"${PKG_DIR}/phpunit.xml.dist" "${PKG_DIR}/phpunit-integration.xml.dist" \
	"${PKG_DIR}/.phpunit.result.cache"

META_PATH="${PKG_DIR}/release.meta.json"
python3 - "${META_PATH}" "${HEADER_VERSION}" "${TAG}" "${COMMIT}" <<'PY'
import json, sys
path, version, tag, commit = sys.argv[1:5]
meta = {
    "schema": "universal-product-reviews.package-meta/v1",
    "version": version,
    "tag": tag,
    "commit": commit.lower(),
}
with open(path, "w", encoding="utf-8") as fh:
    json.dump(meta, fh, indent=2, sort_keys=False)
    fh.write("\n")
print("wrote", path)
PY

cp -f "${META_PATH}" "${META_COPY}"

echo "==> Creating zip"
rm -f "${ZIP_PATH}"
if command -v zip >/dev/null 2>&1; then
	(
		cd "${STAGE}"
		zip -qr "${ZIP_PATH}" "${SLUG}"
	)
else
	python3 - "${STAGE}" "${ZIP_PATH}" "${SLUG}" <<'PY'
import sys, zipfile
from pathlib import Path
stage, zip_path, slug = sys.argv[1:4]
root = Path(stage) / slug
with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
    for path in sorted(root.rglob("*")):
        if path.is_file():
            zf.write(path, path.relative_to(Path(stage)).as_posix())
print("wrote", zip_path)
PY
fi

echo "==> SHA-256 manifest"
(
	cd "${OUT_DIR}"
	# Portable sha256
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "${ZIP_NAME}" > "${SUMS_PATH}.tmp"
	else
		shasum -a 256 "${ZIP_NAME}" > "${SUMS_PATH}.tmp"
	fi
	# Also hash the meta copy for operator convenience.
	META_BASENAME="$(basename "${META_COPY}")"
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "${META_BASENAME}" >> "${SUMS_PATH}.tmp"
	else
		shasum -a 256 "${META_BASENAME}" >> "${SUMS_PATH}.tmp"
	fi
	mv "${SUMS_PATH}.tmp" "${SUMS_PATH}"
	cat "${SUMS_PATH}"
)

# Sanity: no .git inside zip; meta required
python3 - "${ZIP_PATH}" <<'PY'
import sys, zipfile
z = zipfile.ZipFile(sys.argv[1])
names = z.namelist()
gitty = [n for n in names if n == ".git" or n.startswith(".git/") or "/.git/" in n or n.endswith("/.git")]
meta = [n for n in names if n.endswith("release.meta.json")]
if gitty:
    raise SystemExit("ERROR: package contains .git paths: " + ", ".join(gitty[:5]))
if not meta:
    raise SystemExit("ERROR: package missing release.meta.json")
print("OK: no .git; meta present:", meta[0])
PY

echo "==> Done: ${ZIP_PATH}"
