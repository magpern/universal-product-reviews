<?php
/**
 * Admin menu, tabs, and notice rendering.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminController {

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		SettingsPage::register_settings();
		AdminActions::register();
		SiteHealth::register();
	}

	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Product Reviews', 'universal-product-reviews' ),
			__( 'Product Reviews', 'universal-product-reviews' ),
			'manage_woocommerce',
			SettingsPage::MENU_SLUG,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'overview', 'diagnostics', 'controls' ), true ) ) {
			$tab = 'overview';
		}

		self::render_notices();

		$base = admin_url( 'admin.php' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Universal Product Reviews', 'universal-product-reviews' ); ?></h1>
			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php echo esc_attr__( 'Product Reviews tabs', 'universal-product-reviews' ); ?>">
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => SettingsPage::MENU_SLUG, 'tab' => 'overview' ), $base ) ); ?>" class="nav-tab <?php echo 'overview' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( 'Overview', 'universal-product-reviews' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => SettingsPage::MENU_SLUG, 'tab' => 'diagnostics' ), $base ) ); ?>" class="nav-tab <?php echo 'diagnostics' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( 'Diagnostics', 'universal-product-reviews' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => SettingsPage::MENU_SLUG, 'tab' => 'controls' ), $base ) ); ?>" class="nav-tab <?php echo 'controls' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( 'Controls', 'universal-product-reviews' ); ?>
				</a>
			</nav>
			<?php
			if ( 'diagnostics' === $tab ) {
				DiagnosticsPage::render();
			} elseif ( 'controls' === $tab ) {
				SettingsPage::render_controls();
			} else {
				OverviewPage::render();
			}
			?>
		</div>
		<?php
	}

	private static function render_notices(): void {
		if ( empty( $_GET['upr_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$notice = sanitize_key( wp_unslash( (string) $_GET['upr_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map    = array(
			'reconcile_dry_run'      => array( 'success', __( 'Reconcile dry-run completed (zero writes).', 'universal-product-reviews' ) ),
			'reconcile_applied'      => array( 'success', __( 'Reconcile applied.', 'universal-product-reviews' ) ),
			'schema_upgraded'        => array( 'success', __( 'Database schema upgraded.', 'universal-product-reviews' ) ),
			'schema_upgrade_failed'  => array( 'error', __( 'Database schema upgrade failed or is still behind target.', 'universal-product-reviews' ) ),
			'confirm_required'       => array( 'error', __( 'Confirmation checkbox is required for this action.', 'universal-product-reviews' ) ),
		);
		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}
		$class = 'notice notice-' . $map[ $notice ][0] . ' is-dismissible';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $map[ $notice ][1] ) . '</p></div>';
	}
}
