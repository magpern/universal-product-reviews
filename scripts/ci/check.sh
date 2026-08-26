#!/usr/bin/env bash
# M1 CI checks — lint, policy guards, and documentation structure.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

fail() {
  echo "CI CHECK FAILED: $*" >&2
  exit 1
}

echo "==> PHP syntax lint"
find . -name '*.php' -not -path './vendor/*' -not -path './tests/tmp/*' -print0 | while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null
done

echo "==> Composer autoload / structure"
test -f universal-product-reviews.php || fail "missing plugin bootstrap"
test -f src/Plugin.php || fail "missing src/Plugin.php"
test -f ARCHITECTURE.md || fail "missing ARCHITECTURE.md"
test -f docs/milestones/M1-core-enablement.md || fail "missing M1 milestone spec"
test -f docs/production-replay.md || fail "missing docs/production-replay.md"
grep -q 'Plugin Name: Universal Product Reviews' universal-product-reviews.php || fail "plugin header"
grep -q 'Version: 0.1.0' universal-product-reviews.php || fail "expected M1 version 0.1.0"
grep -q 'namespace UniversalProductReviews' src/Plugin.php || fail "namespace"

echo "==> M1 scope guards (no M2+ artefacts)"
if grep -RIn --include='*.php' -E 'dbDelta|CREATE TABLE|as_schedule|WP_CLI::add_command|upr_invite|upr_tokens' src/ universal-product-reviews.php 2>/dev/null; then
  fail "M2+ artefact detected"
fi
if grep -RIn --include='*.php' -E 'wc_add_notice|woocommerce_single_product_summary|wp_enqueue_style|register_activation_hook' src/ universal-product-reviews.php 2>/dev/null; then
  fail "forbidden UI/activation hook detected"
fi
if grep -RIn --include='*.php' -E "add_action\s*\(\s*['\"]pre_comment_on_post" src/ universal-product-reviews.php 2>/dev/null; then
  fail "guest guard must not use pre_comment_on_post"
fi
if grep -RIn --include='*.php' -E 'upr_test_guest_block_without_die|should_block_without_die' src/ universal-product-reviews.php 2>/dev/null; then
  fail "production test seam for guest guard must not exist"
fi

echo "==> Forbidden WooCommerce Internal OrderReviews references"
if grep -RIn --include='*.php' -E 'Internal\\OrderReviews|Internal/OrderReviews' src/ universal-product-reviews.php 2>/dev/null; then
  fail "Internal OrderReviews reference found"
fi

echo "==> Forbidden site/vendor patterns in src/"
SCAN_PATHS=(src)
while IFS= read -r pattern || [[ -n "$pattern" ]]; do
  [[ -z "$pattern" || "$pattern" =~ ^# ]] && continue
  if grep -RIn -E "$pattern" "${SCAN_PATHS[@]}" 2>/dev/null; then
    fail "forbidden pattern matched: $pattern"
  fi
done < "$ROOT/scripts/ci/forbidden-patterns.txt"

echo "==> Forbidden site/vendor patterns in generic docs"
DOC_SCAN=(docs ARCHITECTURE.md README.md CHANGELOG.md)
DOC_FORBIDDEN=(biopentra blocksy rank-math rankmath yoast judge.me yotpo trustpilot elementor fluent-imap fluentform mp-commerce mpcf)
for pattern in "${DOC_FORBIDDEN[@]}"; do
  if grep -RIn -E "$pattern" "${DOC_SCAN[@]}" 2>/dev/null; then
    fail "forbidden doc pattern matched: $pattern"
  fi
done

echo "==> Documentation link sanity (required files exist)"
required_docs=(
  docs/milestones/M1-core-enablement.md
  docs/integration/adapters.md
  docs/integration/schema-acceptance.md
  docs/integration/submission-availability.md
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

echo "==> CI workflow asserts DEV integration coordinates"
grep -q '11.0.1' .github/workflows/ci.yml || fail "WC 11.0.1 mandatory leg missing from CI"
grep -q '8.4' .github/workflows/ci.yml || fail "PHP 8.4 mandatory leg missing from CI"

echo "==> All M1 CI checks passed"
