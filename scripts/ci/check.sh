#!/usr/bin/env bash
# M0 CI checks — lightweight gates for scaffold and frozen documentation.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

fail() {
  echo "CI CHECK FAILED: $*" >&2
  exit 1
}

echo "==> PHP syntax lint"
find . -name '*.php' -not -path './vendor/*' -print0 | while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null
done

echo "==> Composer autoload / structure"
test -f universal-product-reviews.php || fail "missing plugin bootstrap"
test -f src/Plugin.php || fail "missing src/Plugin.php"
test -f ARCHITECTURE.md || fail "missing ARCHITECTURE.md"
test -f docs/production-replay.md || fail "missing docs/production-replay.md"
grep -q 'Plugin Name: Universal Product Reviews' universal-product-reviews.php || fail "plugin header"
grep -q 'Version: 0.0.0' universal-product-reviews.php || fail "expected M0 version 0.0.0"
grep -q 'namespace UniversalProductReviews' src/Plugin.php || fail "namespace"

echo "==> M0 inert scaffold checks"
# Must not register runtime review hooks in M0.
if grep -RIn --include='*.php' -E "add_action\s*\(\s*['\"]pre_comment_approved|add_action\s*\(\s*['\"]wp_ajax_|register_activation_hook|as_schedule|ActionScheduler" src/ universal-product-reviews.php 2>/dev/null; then
  fail "M0 scaffold must not register review/cron/activation hooks"
fi

echo "==> Forbidden WooCommerce Internal OrderReviews references"
if grep -RIn --include='*.php' -E 'Internal\\OrderReviews|Internal/OrderReviews' src/ universal-product-reviews.php 2>/dev/null; then
  fail "Internal OrderReviews reference found"
fi

echo "==> Forbidden site/vendor patterns in src/ and docs/"
SCAN_PATHS=(src docs ARCHITECTURE.md README.md CONTRIBUTING.md CHANGELOG.md)
while IFS= read -r pattern || [[ -n "$pattern" ]]; do
  [[ -z "$pattern" || "$pattern" =~ ^# ]] && continue
  if grep -RIn -E "$pattern" "${SCAN_PATHS[@]}" 2>/dev/null; then
    fail "forbidden pattern matched: $pattern"
  fi
done < "$ROOT/scripts/ci/forbidden-patterns.txt"

echo "==> Documentation link sanity (required files exist)"
required_docs=(
  docs/integration/adapters.md
  docs/integration/schema-acceptance.md
  docs/integration/woocommerce-settings.md
  docs/integration/site-upr-adapters.php.example
  docs/compatibility/wordpress-woocommerce.md
  docs/compatibility/release-process.md
  docs/runbooks/moderation.md
  docs/runbooks/invitation-failures.md
  docs/runbooks/reconciliation.md
  docs/runbooks/retention.md
  docs/runbooks/token-incidents.md
  docs/runbooks/ai-outage.md
)
for f in "${required_docs[@]}"; do
  test -f "$f" || fail "missing $f"
done

echo "==> All M0 CI checks passed"
