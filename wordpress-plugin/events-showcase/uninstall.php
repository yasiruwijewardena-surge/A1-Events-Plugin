<?php
/**
 * Fires when the plugin is deleted from the Plugins screen (not on plain
 * deactivation). Removes every trace of data the plugin created.
 *
 * @package Events_Showcase
 */

namespace Events_Showcase;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-post-type.php';
require_once __DIR__ . '/includes/class-events-repository.php';

// get_terms()/get_posts() below need the taxonomy and post type actually
// registered, which they normally aren't yet during an uninstall request.
// Calling register() directly is safe outside of `init` — it just calls
// register_post_type()/register_taxonomy() immediately.
( new Post_Type() )->register();

/**
 * Deletes every Events Showcase post, term, and option on the current
 * (or only, on a single-site install) site.
 *
 * @return void
 */
function uninstall_site() {
	$post_type = Post_Type::post_type();
	$taxonomy  = Post_Type::taxonomy();

	$post_ids = \get_posts(
		array(
			'post_type'   => $post_type,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	foreach ( $post_ids as $post_id ) {
		// true = bypass Trash. Uninstall means gone, not "recoverable".
		// wp_delete_post() also removes the post's own postmeta rows.
		\wp_delete_post( $post_id, true );
	}

	$term_ids = \get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);

	if ( ! \is_wp_error( $term_ids ) ) {
		foreach ( $term_ids as $term_id ) {
			\wp_delete_term( $term_id, $taxonomy );
		}
	}

	\delete_option( Events_Repository::VERSION_OPTION );

	// No plugin-specific transients are set anywhere in this codebase
	// (caching uses the object cache group below instead), so there is
	// nothing else to clean up here.
	if ( \function_exists( 'wp_cache_flush_group' ) ) {
		\wp_cache_flush_group( Events_Repository::CACHE_GROUP );
	} else {
		// wp_cache_flush_group() only exists from WP 6.1; fall back to a
		// full flush on the 6.0 floor this plugin declares support for.
		\wp_cache_flush();
	}
}

if ( \is_multisite() ) {
	$site_ids = \get_sites( array( 'fields' => 'ids' ) );

	foreach ( $site_ids as $site_id ) {
		\switch_to_blog( $site_id );
		uninstall_site();
		\restore_current_blog();
	}
} else {
	uninstall_site();
}
