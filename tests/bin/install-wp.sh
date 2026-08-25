#!/usr/bin/env bash
# Install WordPress + WooCommerce for UPR integration tests.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMPDIR="${ROOT}/tests/tmp"
WP_DIR="${TMPDIR}/wordpress"
WC_VERSION="${WC_VERSION:-11.0.1}"
WP_DB_HOST="${WP_DB_HOST:-127.0.0.1}"
WP_DB_NAME="${WP_DB_NAME:-wordpress_test}"
WP_DB_USER="${WP_DB_USER:-root}"
WP_DB_PASS="${WP_DB_PASS:-root}"

if [ ! -f "$WP_DIR/wp-settings.php" ]; then
	echo "Setting up WordPress test environment..."
	WP_VERSION=$(php -r '
		$installed = json_decode(file_get_contents("'"$ROOT"'/vendor/composer/installed.json"), true);
		foreach ($installed["packages"] as $package) {
			if ("wp-phpunit/wp-phpunit" === $package["name"]) {
				echo $package["version"];
				exit;
			}
		}
		echo "";
	')
	if [ -z "$WP_VERSION" ]; then
		echo "Could not resolve wp-phpunit version" >&2
		exit 1
	fi
	echo "WordPress version: $WP_VERSION"
	mkdir -p "$TMPDIR"
	cd "$TMPDIR"
	curl -sSL "https://github.com/WordPress/WordPress/archive/refs/tags/$WP_VERSION.tar.gz" | tar --strip-components=1 -xz
	if [ -d "$WP_DIR" ] || [ -L "$WP_DIR" ]; then
		rm -rf "$WP_DIR"
	fi
	ln -s "." "$WP_DIR"
fi

CONFIG_FILE="${ROOT}/tests/wp-tests-config.php"
if [ ! -f "$CONFIG_FILE" ]; then
	cat > "$CONFIG_FILE" << 'EOF'
<?php
define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASS' ) ?: 'root' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
$_SERVER['HTTP_HOST'] = 'wordpress.test';
$_SERVER['SERVER_NAME'] = 'wordpress.test';
define( 'WP_TESTS_DOMAIN', 'wordpress.test' );
define( 'WP_TESTS_EMAIL', 'admin@wordpress.test' );
define( 'WP_TESTS_TITLE', 'WordPress Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'ABSPATH', __DIR__ . '/tmp/wordpress/' );
define( 'WP_DEBUG_DISPLAY', false );
$table_prefix = getenv( 'WP_PHPUNIT__TABLE_PREFIX' ) ?: 'wptests_';
require_once ABSPATH . 'wp-settings.php';
EOF
fi

if [ ! -d "$WP_DIR/wp-content/plugins/woocommerce" ]; then
	echo "Installing WooCommerce $WC_VERSION..."
	mkdir -p "$WP_DIR/wp-content/plugins"
	cd "$WP_DIR/wp-content/plugins"
	TMPZIP=$(mktemp)
	if [ "$WC_VERSION" = "latest" ]; then
		curl -sSLo "$TMPZIP" "https://downloads.wordpress.org/plugin/woocommerce.zip"
	else
		curl -sSLo "$TMPZIP" "https://github.com/woocommerce/woocommerce/releases/download/${WC_VERSION}/woocommerce.zip"
	fi
	unzip -q "$TMPZIP"
	rm "$TMPZIP"
fi

PLUGIN_TARGET="$WP_DIR/wp-content/plugins/universal-product-reviews"
mkdir -p "$PLUGIN_TARGET"
tar -C "$ROOT" -cf - \
	--exclude=tests/tmp \
	--exclude=vendor \
	--exclude=.git \
	. | tar -C "$PLUGIN_TARGET" -xf -

echo "WordPress test environment ready."
