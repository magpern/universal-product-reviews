<?php
/**
 * Uninstall: retain UPR tables and review comments by default.
 *
 * @package UniversalProductReviews
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Intentionally retain {prefix}upr_invite_items, upr_tokens, upr_audit and WP comments.
// Hosts may delete manually if required by policy.
