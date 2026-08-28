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
grep -q 'Version: 0.6.0' universal-product-reviews.php || fail "expected version 0.6.0"
grep -q 'namespace UniversalProductReviews' src/Plugin.php || fail "namespace"
test -f src/Database/Schema.php || fail "missing Schema.php"
test -f src/Database/Migrator.php || fail "missing Migrator.php"

# Portable PHP content search (GNU and BusyBox grep — no --include).
grep_php_src() {
  local re="$1"
  find src -name '*.php' -print0 2>/dev/null | xargs -0 grep -nE "$re" 2>/dev/null || true
}

echo "==> M2 scope guards (forbidden UI / Internal / bypass seams)"
if grep_php_src 'wc_add_notice|woocommerce_single_product_summary' | grep -q .; then
  fail "forbidden PDP UI hooks detected"
fi
if grep -nE 'wc_add_notice|woocommerce_single_product_summary' universal-product-reviews.php 2>/dev/null; then
  fail "forbidden PDP UI hooks detected"
fi
if grep_php_src "add_action\s*\(\s*['\"]pre_comment_on_post" | grep -q .; then
  fail "guest guard must not use pre_comment_on_post"
fi
if grep_php_src 'upr_test_guest_block_without_die|should_block_without_die' | grep -q .; then
  fail "production test seam for guest guard must not exist"
fi
if grep_php_src 'upr_review_page_url' | grep -q .; then
  fail "forbidden public filter receiving raw token"
fi
if grep_php_src "add_filter\s*\(\s*['\"]comments_open" | grep -q .; then
  fail "UPR must not register comments_open as availability/submission gate"
fi

echo "==> Rewrite flush must not run from Plugin bootstrap / frontend init"
if grep -n 'flush_rewrite_rules\|function maybe_flush(' src/Plugin.php 2>/dev/null; then
  fail "Plugin.php must not flush rewrite rules on bootstrap"
fi
if grep -n "function maybe_flush(" src/Http/RewriteRules.php 2>/dev/null; then
  fail "RewriteRules::maybe_flush (frontend) must not exist"
fi
if grep_php_src "RewriteRules::class,\s*'maybe_flush'" | grep -q .; then
  fail "maybe_flush must not be registered"
fi
if ! grep -q 'function flush_controlled' src/Http/RewriteRules.php; then
  fail "missing RewriteRules::flush_controlled"
fi
if ! grep -q "add_action( 'admin_init', array( self::class, 'maybe_flush_controlled' )" src/Http/RewriteRules.php; then
  fail "controlled rewrite flush must be admin_init only"
fi

echo "==> Forbidden WooCommerce Internal references"
if grep_php_src 'Internal\\OrderReviews|Internal/OrderReviews|Automattic\\WooCommerce\\Internal\\' | grep -q .; then
  fail "Internal WooCommerce API reference found"
fi
if grep -nE 'Internal\\OrderReviews|Internal/OrderReviews|Automattic\\WooCommerce\\Internal\\' universal-product-reviews.php 2>/dev/null; then
  fail "Internal WooCommerce API reference found"
fi

echo "==> Comment status APIs must go through SystemStatusOrigin"
STATUS_API_RE='wp_set_comment_status|wp_spam_comment|wp_unspam_comment|wp_trash_comment|wp_untrash_comment'
ALLOWLIST_FILE="$ROOT/scripts/ci/status-api-allowlist.txt"
allowed_files=()
if [[ -f "$ALLOWLIST_FILE" ]]; then
  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ -z "$line" || "$line" =~ ^# ]] && continue
    allowed_files+=( "$line" )
  done < "$ALLOWLIST_FILE"
fi
is_allowlisted() {
  local f="$1"
  local a
  for a in "${allowed_files[@]+"${allowed_files[@]}"}"; do
    [[ "$f" == "$a" ]] && return 0
  done
  return 1
}
while IFS= read -r match; do
  [[ -z "$match" ]] && continue
  file="${match%%:*}"
  rel="${file#"$ROOT"/}"
  # match may be relative (src/...) when grep runs without absolute paths
  if [[ "$rel" == "$match" && "$match" == src/* ]]; then
    rel="$match"
    file="$ROOT/$match"
  fi
  if [[ "$rel" == "src/Moderation/SystemStatusOrigin.php" ]]; then
    continue
  fi
  if is_allowlisted "$rel"; then
    continue
  fi
  fail "direct comment status API outside SystemStatusOrigin (freeze-amend allowlist to permit): $match"
done < <(grep_php_src "$STATUS_API_RE")
# Also flag status-changing wp_update_comment usage (comment_approved) outside allowlist / SystemStatusOrigin.
while IFS= read -r match; do
  [[ -z "$match" ]] && continue
  file="${match%%:*}"
  rel="${file#"$ROOT"/}"
  if [[ "$rel" == "$match" && "$match" == src/* ]]; then
    rel="$match"
    file="$ROOT/$match"
  fi
  if [[ "$rel" == "src/Moderation/SystemStatusOrigin.php" ]]; then
    continue
  fi
  if is_allowlisted "$rel"; then
    continue
  fi
  if grep -n "comment_approved" "$file" >/dev/null 2>&1; then
    fail "wp_update_comment with comment_approved outside SystemStatusOrigin: $match"
  fi
done < <(grep_php_src 'wp_update_comment\s*\(')

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
  docs/integration/storefront-compatibility.md
  docs/integration/woocommerce-settings.md
  docs/integration/site-upr-adapters.php.example
  docs/compatibility/wordpress-woocommerce.md
  docs/compatibility/release-process.md
  docs/runbooks/moderation.md
  docs/runbooks/moderation-capabilities.md
  docs/runbooks/invitation-failures.md
  docs/runbooks/reconciliation.md
  docs/runbooks/operator-controls.md
  docs/runbooks/support-export.md
  docs/runbooks/retention.md
  docs/runbooks/token-incidents.md
  docs/runbooks/ai-outage.md
  docs/roadmap/m4-operator-controls-and-diagnostics.md
  docs/roadmap/m4-operator-controls-and-diagnostics-closure.md
  docs/roadmap/m5-review-moderation-operations.md
  docs/roadmap/m5-review-moderation-operations-closure.md
  docs/roadmap/m6-integration-and-developer-experience.md
  docs/roadmap/m6-integration-and-developer-experience-closure.md
  docs/roadmap/m7-storefront-compatibility-and-quality.md
  docs/roadmap/m8-ai-assisted-moderation-planning.md
  docs/roadmap/m8-ai-assisted-moderation-planning-closure.md
  docs/roadmap/m9-local-ai-shadow-mode.md
  docs/roadmap/m9-local-ai-shadow-mode-closure.md
  docs/integration/public-contracts.md
  docs/integration/integrator-onboarding.md
  docs/integration/wc-review-import-strategy.md
  docs/decisions/ADR-0003-public-contract-compatibility.md
  docs/decisions/ADR-0004-ai-moderation-boundary.md
)
for f in "${required_docs[@]}"; do
  test -f "$f" || fail "missing $f"
done

echo "==> M6 stable public contracts inventory"
REGISTRY="$ROOT/docs/integration/public-contracts.md"
CONTRACTS_TSV="$ROOT/scripts/ci/m6-stable-contracts.tsv"
test -f "$CONTRACTS_TSV" || fail "missing $CONTRACTS_TSV"
while IFS=$'\t' read -r cid kind symbol sensitivity || [ -n "$cid" ]; do
  [[ -z "$cid" || "$cid" =~ ^# ]] && continue
  grep -q "$cid" "$REGISTRY" || fail "stable contract $cid not documented in public-contracts.md"
  case "$kind" in
    action|filter)
      found=0
      while IFS= read -r -d '' f; do
        if grep -Fq "'${symbol}'" "$f"; then
          if [ "$kind" = "action" ] && grep -Fq 'add_action' "$f"; then
            found=1
            break
          fi
          if [ "$kind" = "filter" ] && grep -Fq 'apply_filters' "$f"; then
            found=1
            break
          fi
        fi
      done < <(find "$ROOT/src" -name '*.php' -print0)
      [ "$found" = 1 ] || fail "stable ${kind} missing in src: $symbol"
      ;;
    php)
      rel="${symbol#UniversalProductReviews\\}"
      rel_path="src/$(echo "$rel" | tr '\\' '/').php"
      test -f "$ROOT/$rel_path" || fail "stable PHP API missing file: $rel_path"
      ;;
    *)
      fail "unknown contract kind: $kind"
      ;;
  esac
  case "$sensitivity" in
    none|sensitive) ;;
    *) fail "stable contract $cid missing sensitivity none|sensitive" ;;
  esac
  if [ "$sensitivity" = "sensitive" ]; then
    grep -qi "sensitive" "$REGISTRY" || fail "registry must document sensitive-data-bearing surfaces"
  fi
done < "$CONTRACTS_TSV"

echo "==> M6 example privacy (no log/persist/forward helpers in adapter example)"
EXAMPLE="$ROOT/docs/integration/site-upr-adapters.php.example"
for bad in error_log update_option set_transient wp_remote_ wp_safe_remote_ file_put_contents; do
  if grep -q "$bad" "$EXAMPLE"; then
    fail "adapter example must not call $bad"
  fi
done

echo "==> No public mint/resend API"
if grep -RIn -E 'function\s+(mint_|resend_)|upr_mint_|upr_resend_' "$ROOT/src" 2>/dev/null; then
  fail "public mint/resend API detected"
fi

echo "==> M9 AI module local-only boundary"
AI_DIR="$ROOT/src/Ai"
if [[ -d "$AI_DIR" ]]; then
  if grep -RIn -E 'wp_remote_|wp_safe_remote_|curl_|fsockopen|stream_socket_client|socket_create' "$AI_DIR" --include='*.php' 2>/dev/null | grep -q .; then
    fail "src/Ai must not use network primitives"
  fi
fi
if grep -RIn -F "upr_local_moderation_assessment_provider" "$ROOT/src" --include='*.php' 2>/dev/null | grep -q .; then
  fail "AI provider filter must not be registered in src until M10"
fi
if grep -RIn -E "apply_filters\s*\(\s*['\"]upr_.*moderation.*provider" "$ROOT/src" --include='*.php' 2>/dev/null | grep -q .; then
  fail "replaceable AI provider filters forbidden in M9"
fi

echo "==> Support export schema unchanged"
grep -q "upr-support-export/v1" "$ROOT/src/Admin/SupportExport.php" || fail "support export schema version drift"
if grep -n "IntegrationReadiness\|'I1'\|\"I1\"" "$ROOT/src/Admin/SupportExport.php" 2>/dev/null; then
  fail "support export must not include integration readiness"
fi

echo "==> CI workflow asserts DEV integration coordinates"
grep -q '11.0.1' .github/workflows/ci.yml || fail "WC 11.0.1 mandatory leg missing from CI"
grep -q '8.4' .github/workflows/ci.yml || fail "PHP 8.4 mandatory leg missing from CI"

echo "==> All M2 CI checks passed"
