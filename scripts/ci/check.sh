#!/usr/bin/env bash
# M2 CI checks — lint, policy guards, and documentation structure.
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
test -f docs/milestones/M2-invitations.md || fail "missing M2 milestone spec"
test -f docs/production-replay.md || fail "missing docs/production-replay.md"
grep -q 'Plugin Name: Universal Product Reviews' universal-product-reviews.php || fail "plugin header"
grep -q 'Version: 0.4.0' universal-product-reviews.php || fail "expected version 0.4.0"
grep -q 'namespace UniversalProductReviews' src/Plugin.php || fail "namespace"
test -f src/Database/Schema.php || fail "missing Schema.php"
test -f src/Database/Migrator.php || fail "missing Migrator.php"

echo "==> M2 scope guards (forbidden UI / Internal / bypass seams)"
if grep -RIn --include='*.php' -E 'wc_add_notice|woocommerce_single_product_summary' src/ universal-product-reviews.php 2>/dev/null; then
  fail "forbidden PDP UI hooks detected"
fi
if grep -RIn --include='*.php' -E "add_action\s*\(\s*['\"]pre_comment_on_post" src/ universal-product-reviews.php 2>/dev/null; then
  fail "guest guard must not use pre_comment_on_post"
fi
if grep -RIn --include='*.php' -E 'upr_test_guest_block_without_die|should_block_without_die' src/ universal-product-reviews.php 2>/dev/null; then
  fail "production test seam for guest guard must not exist"
fi
if grep -RIn --include='*.php' -E 'upr_review_page_url' src/ universal-product-reviews.php 2>/dev/null; then
  fail "forbidden public filter receiving raw token"
fi

echo "==> Rewrite flush must not run from Plugin bootstrap / frontend init"
if grep -n 'flush_rewrite_rules\|function maybe_flush(' src/Plugin.php 2>/dev/null; then
  fail "Plugin.php must not flush rewrite rules on bootstrap"
fi
if grep -n "function maybe_flush(" src/Http/RewriteRules.php 2>/dev/null; then
  fail "RewriteRules::maybe_flush (frontend) must not exist"
fi
if grep -RIn --include='*.php' -E "RewriteRules::class,\s*'maybe_flush'" src/ 2>/dev/null; then
  fail "maybe_flush must not be registered"
fi
if ! grep -q 'function flush_controlled' src/Http/RewriteRules.php; then
  fail "missing RewriteRules::flush_controlled"
fi
if ! grep -q "add_action( 'admin_init', array( self::class, 'maybe_flush_controlled' )" src/Http/RewriteRules.php; then
  fail "controlled rewrite flush must be admin_init only"
fi

echo "==> Forbidden WooCommerce Internal references"
if grep -RIn --include='*.php' -E 'Internal\\OrderReviews|Internal/OrderReviews|Automattic\\WooCommerce\\Internal\\' src/ universal-product-reviews.php 2>/dev/null; then
  fail "Internal WooCommerce API reference found"
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
  docs/milestones/M2-invitations.md
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
  docs/runbooks/operator-controls.md
  docs/runbooks/support-export.md
  docs/runbooks/retention.md
  docs/runbooks/token-incidents.md
  docs/runbooks/ai-outage.md
  docs/roadmap/m4-operator-controls-and-diagnostics.md
)
for f in "${required_docs[@]}"; do
  test -f "$f" || fail "missing $f"
done

echo "==> CI workflow asserts DEV integration coordinates"
grep -q '11.0.1' .github/workflows/ci.yml || fail "WC 11.0.1 mandatory leg missing from CI"
grep -q '8.4' .github/workflows/ci.yml || fail "PHP 8.4 mandatory leg missing from CI"

echo "==> All M2 CI checks passed"
