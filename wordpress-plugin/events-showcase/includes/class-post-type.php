<?php
/**
 * Registers the "event" custom post type and "event_category" taxonomy
 * that back the shortcode's data. Both are REST-enabled so the React app
 * can read them from /wp-json/wp/v2/events without any custom endpoint.
 *
 * @package EventsShowcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Events_Showcase_Post_Type {

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register() {
		register_post_type(
			'event',
			array(
				'labels'       => array(
					'name'          => __( 'Events', 'events-showcase' ),
					'singular_name' => __( 'Event', 'events-showcase' ),
					'add_new_item'  => __( 'Add New Event', 'events-showcase' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-calendar-alt',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				'show_in_rest' => true,
				'rest_base'    => 'events',
			)
		);

		register_taxonomy(
			'event_category',
			'event',
			array(
				'labels'       => array(
					'name'          => __( 'Event Categories', 'events-showcase' ),
					'singular_name' => __( 'Event Category', 'events-showcase' ),
				),
				'public'       => true,
				'hierarchical' => true,
				'show_in_rest' => true,
				'rest_base'    => 'event_category',
			)
		);
	}
}
