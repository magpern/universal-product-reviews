<?php
/**
 * Plugins-screen action links for Universal Product Reviews.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

use UniversalProductReviews\Moderation\CommentListEnhancements;

defined( 'ABSPATH' ) || exit;

final class PluginActionLinks {

	public static function register(): void {
		if ( ! defined( 'UPR_PLUGIN_FILE' ) ) {
			return;
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( UPR_PLUGIN_FILE ),
			array( self::class, 'filter_action_links' )
		);
	}

	/**
	 * @param array<string, string> $links Existing action links.
	 * @return array<string, string>
	 */
	public static function filter_action_links( array $links ): array {
		$extra = array();

		if ( current_user_can( 'manage_woocommerce' ) ) {
			$settings_url = add_query_arg(
				array(
					'page' => SettingsPage::MENU_SLUG,
				),
				admin_url( 'admin.php' )
			);
			$extra['upr_settings'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $settings_url ),
				esc_html__( 'Settings', 'universal-product-reviews' )
			);
		}

		if ( current_user_can( 'moderate_comments' ) ) {
			$reviews_url = add_query_arg(
				array(
					'upr_view' => CommentListEnhancements::VIEW_PENDING,
				),
				admin_url( 'edit-comments.php' )
			);
			$extra['upr_product_reviews'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $reviews_url ),
				esc_html__( 'Product reviews', 'universal-product-reviews' )
			);
		}

		return array_merge( $extra, $links );
	}
}
