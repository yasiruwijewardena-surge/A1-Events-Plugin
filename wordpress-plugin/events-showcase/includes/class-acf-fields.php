<?php
/**
 * Registers the ACF field group for the "event" post type (start date,
 * end date, venue, location) and exposes those fields on the REST API
 * response under an "acf" key, so the React app never has to hard-code
 * content — it all comes from WordPress.
 *
 * Registration is guarded by function_exists() so the plugin still loads
 * (minus these fields) if ACF isn't active; see README for setup notes.
 *
 * @package EventsShowcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Events_Showcase_ACF_Fields {

	public function __construct() {
		add_action( 'acf/init', array( $this, 'register_field_group' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_field' ) );
	}

	public function register_field_group() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_local_field_group(
			array(
				'key'      => 'group_events_showcase',
				'title'    => 'Event Details',
				'fields'   => array(
					array(
						'key'   => 'field_es_start_date',
						'label' => 'Start Date & Time',
						'name'  => 'start_date',
						'type'  => 'date_time_picker',
					),
					array(
						'key'   => 'field_es_end_date',
						'label' => 'End Date & Time',
						'name'  => 'end_date',
						'type'  => 'date_time_picker',
					),
					array(
						'key'   => 'field_es_venue',
						'label' => 'Venue',
						'name'  => 'venue',
						'type'  => 'text',
					),
					array(
						'key'   => 'field_es_location',
						'label' => 'Location (city/region)',
						'name'  => 'location',
						'type'  => 'text',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'event',
						),
					),
				),
			)
		);
	}

	/**
	 * Adds an "acf" field to the /wp-json/wp/v2/events REST response
	 * containing the fields registered above.
	 */
	public function register_rest_field() {
		register_rest_field(
			'event',
			'acf',
			array(
				'get_callback' => function ( $post ) {
					if ( ! function_exists( 'get_fields' ) ) {
						return array();
					}
					return get_fields( $post['id'] ) ?: array();
				},
				'schema'       => null,
			)
		);
	}
}
