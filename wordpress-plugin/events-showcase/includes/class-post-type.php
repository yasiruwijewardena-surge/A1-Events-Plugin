<?php
/**
 * Registers the Events custom post type, its category taxonomy, and the
 * post meta schema that backs the ACF fields (see class-acf-fields.php).
 *
 * @package Events_Showcase
 */

namespace Events_Showcase;

defined( 'ABSPATH' ) || exit;

/**
 * Custom post type + taxonomy + post meta schema registration.
 */
class Post_Type {

	/**
	 * Wires registration to `init`.
	 */
	public function __construct() {
		\add_action( 'init', array( $this, 'register' ) );
		\add_action( 'init', array( $this, 'register_meta' ) );
	}

	/**
	 * Registers the post type and its taxonomy.
	 *
	 * The post type is public on purpose: a single event's own permalink
	 * is the no-JS fallback for an event card — shared links, disabled
	 * JavaScript, and search engine crawlers all still land on a real page.
	 *
	 * @return void
	 */
	public function register() {
		$post_type = self::post_type();

		// A site already running another events plugin can repoint this
		// filter at its post type instead of ours; registering over an
		// existing type would fatal, so bail out rather than collide.
		if ( \post_type_exists( $post_type ) ) {
			return;
		}

		\register_post_type(
			$post_type,
			array(
				'labels'       => array(
					'name'                  => __( 'Events', 'events-showcase' ),
					'singular_name'         => __( 'Event', 'events-showcase' ),
					'add_new'               => __( 'Add New', 'events-showcase' ),
					'add_new_item'          => __( 'Add New Event', 'events-showcase' ),
					'edit_item'             => __( 'Edit Event', 'events-showcase' ),
					'new_item'              => __( 'New Event', 'events-showcase' ),
					'view_item'             => __( 'View Event', 'events-showcase' ),
					'view_items'            => __( 'View Events', 'events-showcase' ),
					'search_items'          => __( 'Search Events', 'events-showcase' ),
					'not_found'             => __( 'No events found', 'events-showcase' ),
					'not_found_in_trash'    => __( 'No events found in Trash', 'events-showcase' ),
					'all_items'             => __( 'All Events', 'events-showcase' ),
					'archives'              => __( 'Event Archives', 'events-showcase' ),
					'attributes'            => __( 'Event Attributes', 'events-showcase' ),
					'insert_into_item'      => __( 'Insert into event', 'events-showcase' ),
					'uploaded_to_this_item' => __( 'Uploaded to this event', 'events-showcase' ),
					'featured_image'        => __( 'Event Image', 'events-showcase' ),
					'set_featured_image'    => __( 'Set event image', 'events-showcase' ),
					'remove_featured_image' => __( 'Remove event image', 'events-showcase' ),
					'use_featured_image'    => __( 'Use as event image', 'events-showcase' ),
					'menu_name'             => __( 'Events', 'events-showcase' ),
					'name_admin_bar'        => __( 'Event', 'events-showcase' ),
				),
				'public'       => true,
				'has_archive'  => false,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-calendar-event',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
				// The internal slug is `es_event` to avoid colliding with
				// the ecosystem's most commonly registered post type, but
				// the public URL stays human-readable: /event/slug/ rather
				// than /es_event/slug/.
				'rewrite'      => array( 'slug' => 'event' ),
			)
		);

		\register_taxonomy(
			self::taxonomy(),
			$post_type,
			array(
				'labels'       => array(
					'name'              => __( 'Event Categories', 'events-showcase' ),
					'singular_name'     => __( 'Event Category', 'events-showcase' ),
					'search_items'      => __( 'Search Event Categories', 'events-showcase' ),
					'all_items'         => __( 'All Event Categories', 'events-showcase' ),
					'parent_item'       => __( 'Parent Event Category', 'events-showcase' ),
					'parent_item_colon' => __( 'Parent Event Category:', 'events-showcase' ),
					'edit_item'         => __( 'Edit Event Category', 'events-showcase' ),
					'update_item'       => __( 'Update Event Category', 'events-showcase' ),
					'add_new_item'      => __( 'Add New Event Category', 'events-showcase' ),
					'new_item_name'     => __( 'New Event Category Name', 'events-showcase' ),
					'menu_name'         => __( 'Categories', 'events-showcase' ),
				),
				'hierarchical' => true,
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Registers the ACF-backed fields as core post meta, with an explicit
	 * type and sanitize callback each. This runs unconditionally — unlike
	 * the ACF field group itself (see ACF_Fields::init()) — because it's
	 * what makes Events_Repository's non-ACF fallback path work: without
	 * it, `get_post_meta()` still returns the raw string ACF saved, but
	 * with no type coercion and no REST meta exposure.
	 *
	 * @return void
	 */
	public function register_meta() {
		$post_type = self::post_type();

		\register_post_meta(
			$post_type,
			'es_start_datetime',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		\register_post_meta(
			$post_type,
			'es_end_datetime',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		\register_post_meta(
			$post_type,
			'es_venue_name',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		\register_post_meta(
			$post_type,
			'es_location',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		\register_post_meta(
			$post_type,
			'es_external_url',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
			)
		);

		\register_post_meta(
			$post_type,
			'es_all_day',
			array(
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => false,
				// Core helper, not a hand-rolled cast: it already handles
				// every truthy representation a checkbox/ACF true_false
				// field can submit ('1', 'true', 'on', 1, true, ...).
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		\register_post_meta(
			$post_type,
			'es_status',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => 'scheduled',
				// A closure with exactly one declared parameter, not a
				// bare function name — see the REST controller's category
				// arg for why that distinction matters when WordPress
				// calls a sanitize callback with more positional
				// arguments than the value alone.
				'sanitize_callback' => static function ( $value ) {
					$allowed = array( 'scheduled', 'postponed', 'cancelled' );
					$value   = \sanitize_key( (string) $value );
					return \in_array( $value, $allowed, true ) ? $value : 'scheduled';
				},
			)
		);
	}

	/**
	 * The post type slug. `es_`-prefixed because post types share the
	 * global `$wp_post_types` namespace across every theme and plugin on
	 * the site — a bare `events` is one of the most likely collisions in
	 * the whole ecosystem. Filterable so a site with a conflicting events
	 * plugin can still repoint every other class in this plugin at a
	 * different post type without touching code.
	 *
	 * @return string
	 */
	public static function post_type() {
		return \apply_filters( 'events_showcase_post_type', 'es_event' );
	}

	/**
	 * The taxonomy slug. Same collision reasoning as the post type above —
	 * taxonomy names share their own global namespace — so it gets the
	 * same `es_` prefix.
	 *
	 * @return string
	 */
	public static function taxonomy() {
		return \apply_filters( 'events_showcase_taxonomy', 'es_event_category' );
	}
}
