<?php
/**
 * Mandatory write guards for in-scope review body and rating meta (E7).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

use UniversalProductReviews\Moderation\ReviewScope;
use UniversalProductReviews\Submission\GuestSubmitAuthorization;

defined( 'ABSPATH' ) || exit;

final class CustomerEditGuard {

	public static function register(): void {
		add_filter( 'wp_update_comment_data', array( self::class, 'filter_update_comment_data' ), 0, 3 );
		add_filter( 'add_comment_metadata', array( self::class, 'filter_add_rating_meta' ), 0, 5 );
		add_filter( 'update_comment_metadata', array( self::class, 'filter_update_rating_meta' ), 0, 5 );
		add_filter( 'delete_comment_metadata', array( self::class, 'filter_delete_rating_meta' ), 0, 5 );
		add_filter( 'map_meta_cap', array( self::class, 'filter_map_meta_cap' ), 10, 4 );
		add_filter( 'rest_pre_insert_comment', array( self::class, 'filter_rest_pre_insert_comment' ), 10, 2 );
	}

	/**
	 * @param array<string, mixed>|\WP_Error $data Comment data.
	 * @param \WP_Comment                    $comment Existing comment.
	 * @param array<string, mixed>           $commentarr Incoming.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function filter_update_comment_data( $data, $comment, $commentarr ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! is_array( $data ) || ! $comment instanceof \WP_Comment ) {
			return $data;
		}
		$comment_id = (int) $comment->comment_ID;
		if ( ! self::is_in_scope_id( $comment_id, $comment ) ) {
			return $data;
		}
		if ( current_user_can( 'moderate_comments' ) ) {
			return $data;
		}
		if ( ! CustomerEditAuthorization::allows_comment( $comment_id ) ) {
			return new \WP_Error( 'upr_edit_forbidden', __( 'This review cannot be edited.', 'universal-product-reviews' ) );
		}

		$out                     = $comment->to_array();
		$out['comment_content']  = isset( $data['comment_content'] ) ? (string) $data['comment_content'] : (string) $comment->comment_content;
		$out['comment_ID']       = $comment_id;
		$out['comment_post_ID']  = (int) $comment->comment_post_ID;
		$out['comment_parent']   = (int) $comment->comment_parent;
		$out['comment_type']     = (string) $comment->comment_type;
		$out['user_id']          = (int) $comment->user_id;
		$out['comment_author']   = (string) $comment->comment_author;
		$out['comment_author_email'] = (string) $comment->comment_author_email;
		$out['comment_author_url']   = (string) $comment->comment_author_url;
		$out['comment_date']     = (string) $comment->comment_date;
		$out['comment_date_gmt'] = (string) $comment->comment_date_gmt;
		$out['comment_approved'] = (string) $comment->comment_approved;
		return $out;
	}

	/**
	 * @param null|bool $check Short-circuit.
	 * @param mixed     $object_id Comment id.
	 * @param string    $meta_key Meta key.
	 * @return mixed
	 */
	public static function filter_add_rating_meta( $check, $object_id, $meta_key, $meta_value = '', $unique = false ) {
		return self::guard_rating_meta( $check, (int) $object_id, (string) $meta_key, 'add' );
	}

	/**
	 * @param null|bool $check Short-circuit.
	 * @param mixed     $object_id Comment id.
	 * @param string    $meta_key Meta key.
	 * @return mixed
	 */
	public static function filter_update_rating_meta( $check, $object_id, $meta_key, $meta_value = '', $prev_value = '' ) {
		return self::guard_rating_meta( $check, (int) $object_id, (string) $meta_key, 'update' );
	}

	/**
	 * @param null|bool $check Short-circuit.
	 * @param mixed     $object_id Comment id.
	 * @param string    $meta_key Meta key.
	 * @return mixed
	 */
	public static function filter_delete_rating_meta( $check, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
		return self::guard_rating_meta( $check, (int) $object_id, (string) $meta_key, 'delete' );
	}

	/**
	 * @param list<string>       $caps Caps.
	 * @param string             $cap Requested cap.
	 * @param int                $user_id User.
	 * @param array<int, mixed>  $args Args.
	 * @return list<string>
	 */
	public static function filter_map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( 'edit_comment' !== $cap ) {
			return $caps;
		}
		$comment_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $comment_id <= 0 || ! self::is_in_scope_id( $comment_id ) ) {
			return $caps;
		}
		$user = get_userdata( (int) $user_id );
		if ( $user instanceof \WP_User && ! empty( $user->allcaps['moderate_comments'] ) ) {
			return $caps;
		}
		return array( 'do_not_allow' );
	}

	/**
	 * @param array<string, mixed>|\WP_Error $prepared Prepared comment.
	 * @param \WP_REST_Request               $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function filter_rest_pre_insert_comment( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! is_array( $prepared ) ) {
			return $prepared;
		}
		$id = isset( $prepared['comment_ID'] ) ? (int) $prepared['comment_ID'] : 0;
		if ( $id <= 0 && $request instanceof \WP_REST_Request ) {
			$id = (int) $request->get_param( 'id' );
		}
		if ( $id <= 0 || ! self::is_in_scope_id( $id ) ) {
			return $prepared;
		}
		if ( current_user_can( 'moderate_comments' ) ) {
			return $prepared;
		}
		if ( CustomerEditAuthorization::allows_comment( $id ) ) {
			return $prepared;
		}
		return new \WP_Error( 'upr_edit_forbidden', __( 'This review cannot be edited.', 'universal-product-reviews' ), array( 'status' => 403 ) );
	}

	/**
	 * @param mixed $check Existing short-circuit.
	 * @return mixed
	 */
	private static function guard_rating_meta( $check, int $comment_id, string $meta_key, string $op ) {
		if ( 'rating' !== $meta_key || $comment_id <= 0 ) {
			return $check;
		}
		if ( ! self::is_in_scope_id( $comment_id ) ) {
			return $check;
		}
		if ( current_user_can( 'moderate_comments' ) ) {
			return $check;
		}
		if ( CustomerEditAuthorization::allows_comment( $comment_id ) ) {
			return $check;
		}
		$comment = get_comment( $comment_id );
		if ( $comment instanceof \WP_Comment && GuestSubmitAuthorization::allows_product( (int) $comment->comment_post_ID ) ) {
			return $check;
		}
		// Initial native/WC submit writes rating on comment_post / first add — not an E7 edit.
		// wp_update_comment_meta fires update_{type}_metadata BEFORE falling through to add_metadata.
		if ( doing_action( 'comment_post' ) ) {
			return $check;
		}
		if ( in_array( $op, array( 'add', 'update' ), true ) && function_exists( 'metadata_exists' ) && ! metadata_exists( 'comment', $comment_id, 'rating' ) ) {
			return $check;
		}
		return false;
	}

	private static function is_in_scope_id( int $comment_id, ?\WP_Comment $comment = null ): bool {
		if ( $comment_id <= 0 ) {
			return false;
		}
		if ( ! $comment instanceof \WP_Comment ) {
			$comment = get_comment( $comment_id );
		}
		if ( ! $comment instanceof \WP_Comment ) {
			return false;
		}
		if ( (int) $comment->comment_parent !== 0 ) {
			return false;
		}
		return ReviewScope::is_product_review( $comment->to_array() );
	}
}
