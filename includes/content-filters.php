<?php
/**
 * Content Filters
 *
 * Filters for hiding restricted post and page content.
 *
 * @package     Restrict Content Pro
 * @subpackage  Content Filters
 * @copyright   Copyright (c) 2017, Restrict Content Pro
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */


/**
 * Filter the content based upon the "Restrict this content" metabox configuration.
 *
 * @param string $content Existing post content.
 *
 * @return string Newly modified post content (possibly with teaser).
 */
function rcp_filter_restricted_content( $content ) {
	global $post, $rcp_options;

	$user_id = get_current_user_id();

	$member = new RCP_Member( $user_id );

	if ( ! $member->can_access( $post->ID ) ) {

		$message = ! empty( $rcp_options['free_message'] ) ? $rcp_options['free_message'] : false; // message shown for free content

		if ( rcp_is_paid_content( $post->ID ) || in_array( $post->ID, rcp_get_post_ids_assigned_to_restricted_terms() ) ) {
			$message = ! empty( $rcp_options['paid_message'] ) ? $rcp_options['paid_message'] : false; // message shown for premium content
		}

		$message = ! empty( $message ) ? $message : __( 'This content is restricted to subscribers', 'rcp' );

		return rcp_format_teaser( $message );
	}

	return $content;
}
add_filter( 'the_content', 'rcp_filter_restricted_content' , 100 );

/**
 * Filter restricted content based on category restrictions
 *
 * @deprecated 2.7 This is now covered by rcp_filter_restricted_content()
 *
 * @access      public
 * @since       2.0
 * @return      $content
 */
function rcp_filter_restricted_category_content( $content ) {
	global $post, $rcp_options;

	$restrictions = array();

	foreach( rcp_get_restricted_taxonomies() as $taxonomy ) {
		$restriction = rcp_is_post_taxonomy_restricted( $post->ID, $taxonomy );

		// -1 means that the taxonomy terms are unrestricted
		if ( -1 === $restriction ) {
			continue;
		}

		// true or false. Whether or not the user has access to the restricted taxonomy terms
		$restrictions[] = $restriction;

	}

	if ( empty( $restrictions ) ) {
		return $content;
	}

	$restricted = ( apply_filters( 'rcp_restricted_taxonomy_match_all', false ) ) ? false !== array_search( true, $restrictions ) : false === array_search( false, $restrictions );

	if ( $restricted ) {

		$message = ! empty( $rcp_options['paid_message'] ) ? $rcp_options['paid_message'] : __( 'You need to have an active subscription to view this content.', 'rcp' );

		return rcp_format_teaser( $message );

	}

	return $content;

}
// add_filter( 'the_content', 'rcp_filter_restricted_category_content', 101 );

/**
 * Check the provided taxonomy along with the given post id to see if any restrictions are found
 *
 * @since      2.5
 * @param int      $post_id ID of the post to check.
 * @param string   $taxonomy
 * @param null|int $user_id User ID or leave as null to use curently logged in user.
 *
 * @return int|bool true if tax is restricted, false if user can access, -1 if unrestricted or invalid
 */
function rcp_is_post_taxonomy_restricted( $post_id, $taxonomy, $user_id = null ) {

	$restricted = -1;

	if ( current_user_can( 'edit_post', $post_id ) ) {
		return $restricted;
	}

	// make sure this post supports the supplied taxonomy
	$post_taxonomies = get_post_taxonomies( $post_id );
	if ( ! in_array( $taxonomy, (array) $post_taxonomies ) ) {
		return $restricted;
	}

	$terms = get_the_terms( $post_id, $taxonomy );

	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return $restricted;
	}

	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	// Loop through the categories and determine if one has restriction options
	foreach( $terms as $term ) {

		$term_meta = rcp_get_term_restrictions( $term->term_id );

		if ( empty( $term_meta['paid_only'] ) && empty( $term_meta['subscriptions'] ) && ( empty( $term_meta['access_level'] ) || 'None' == $term_meta['access_level'] ) ) {
			continue;
		}

		$restricted = true;

		/** Check that the user has a paid subscription ****************************************************************/
		$paid_only = ! empty( $term_meta['paid_only'] );
		if( $paid_only && rcp_is_paid_user( $user_id ) ) {
			$restricted = false;
			break;
		}

		/** If restricted to one or more subscription levels, make sure that the user is a member of one of the levels */
		$subscriptions = ! empty( $term_meta['subscriptions'] ) ? array_map( 'absint', $term_meta['subscriptions'] ) : false;
		if( $subscriptions && in_array( rcp_get_subscription_id( $user_id ), $subscriptions ) ) {
			$restricted = false;
			break;
		}

		/** If restricted to one or more access levels, make sure that the user is a member of one of the levls ********/
		$access_level = ! empty( $term_meta['access_level'] ) ? absint( $term_meta['access_level'] ) : 0;
		if( $access_level > 0 && rcp_user_has_access( $user_id, $access_level ) ) {
			$restricted = false;
			break;
		}
	}

	return apply_filters( 'rcp_is_post_taxonomy_restricted', $restricted, $taxonomy, $post_id, $user_id );
}

/**
 * Remove comments from posts/pages if user does not have access
 *
 * @since 2.6
 * @param string $template Path to template file to load
 *
 * @return string Path to template file to load
 */
function rcp_hide_comments( $template ) {

	$post_id = get_the_ID();

	if( ! empty( $post_id ) ) {

		if( ! rcp_user_can_access( get_current_user_id(), $post_id ) ) {

			$template = rcp_get_template_part( 'comments', 'no-access', false );

		}

	}

	return $template;
}
add_filter( 'comments_template', 'rcp_hide_comments', 9999999 );

/**
 * Format the teaser message. Default excerpt length is 50 words.
 *
 * @uses  rcp_excerpt_by_id()
 *
 * @param string $message Message to add to the end of the excerpt.
 *
 * @return string Formatted teaser with message appended.
 */
function rcp_format_teaser( $message ) {
	global $post, $rcp_options;

	$show_excerpt = isset( $rcp_options['content_excerpts'] ) ? $rcp_options['content_excerpts'] : 'individual';

	if ( 'always' == $show_excerpt || ( 'individual' == $show_excerpt && get_post_meta( $post->ID, 'rcp_show_excerpt', true ) ) ) {
		$excerpt_length = 50;
		if ( has_filter( 'rcp_filter_excerpt_length' ) ) {
			$excerpt_length = apply_filters( 'rcp_filter_excerpt_length', $excerpt_length );
		}
		$excerpt = rcp_excerpt_by_id( $post, $excerpt_length );
		$message = apply_filters( 'rcp_restricted_message', $message );
		$message = $excerpt . $message;
	} else {
		$message = apply_filters( 'rcp_restricted_message', $message );
	}

	return $message;
}

/**
 * Wrap the restricted message in paragraph tags and allow for shortcodes to be used.
 *
 * @param string $message Restricted content message.
 *
 * @return string
 */
function rcp_restricted_message_filter( $message ) {
	return do_shortcode( wpautop( $message ) );
}
add_filter( 'rcp_restricted_message', 'rcp_restricted_message_filter', 10, 1 );

/**
 * Unlink comments from restricted posts in the REST API.
 *
 * @param WP_REST_Response $response The response object.
 * @param WP_Post          $post     The original post type object.
 * @param WP_REST_Request  $request  Request used to generate the response.
 *
 * @since  2.8
 * @return WP_REST_Response
 */
function rcp_remove_replies_from_rest_api( $response, $post, $request ) {
	if ( rcp_is_restricted_content( $post->ID ) ) {
		$response->remove_link( 'replies' );
	}

	return $response;
}
add_filter( 'rest_prepare_post', 'rcp_remove_replies_from_rest_api', 10, 3 );

/**
 * Filter comment content on restricted posts in the REST API.
 *
 * @param WP_REST_Response $response The response object.
 * @param WP_Comment       $comment  The comment object.
 * @param WP_REST_Request  $request  Request used to generate the response.
 *
 * @since  2.8
 * @return WP_REST_Response
 */
function rcp_filter_comment_content_rest_api( $response, $comment, $request ) {
	if ( rcp_is_restricted_content( $comment->comment_post_ID ) ) {
		$data = $response->get_data();
		$data['content']['rendered'] = __( 'This content is restricted to subscribers.', 'rcp' );
		$response->set_data( $data );
	}

	return $response;
}
add_filter( 'rest_prepare_comment', 'rcp_filter_comment_content_rest_api', 10, 3 );