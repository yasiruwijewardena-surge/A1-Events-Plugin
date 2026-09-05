<?php
/**
 * Registers the ACF field group for events.
 *
 * @package Events_Showcase
 */

namespace Events_Showcase;

defined( 'ABSPATH' ) || exit;

/**
 * ACF field group registration. Always defined — unlike a class-level
 * function_exists() guard, this keeps the bootstrap uniform (every class
 * is instantiated the same way) and lets `init()` be unit-tested directly
 * even on an environment without ACF installed.
 *
 * The post meta these fields write to is registered by Post_Type, not
 * here — those registrations must run whether or not ACF is present,
 * since they're what the repository's non-ACF fallback path relies on.
 */
class ACF_Fields {

	/**
	 * Hooks registration, guarded by ACF's presence.
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * @return void
	 */
	public function init(): void {
		// ACF is optional — the repository falls back to raw post meta without it.
		if ( ! \function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		\add_action( 'acf/init', array( $this, 'register_field_group' ) );
	}

	/**
	 * Field config lives in code, not the ACF admin UI, so it ships with
	 * the plugin and works on a fresh install with no export/import step.
	 *
	 * @return void
	 */
	public function register_field_group(): void {
		\acf_add_local_field_group(
			array(
				'key'      => 'group_events_showcase_details',
				'title'    => __( 'Event Details', 'events-showcase' ),
				'fields'   => array(
					array(
						'key'            => 'field_es_start_datetime',
						'label'          => __( 'Start Date & Time', 'events-showcase' ),
						'name'           => 'es_start_datetime',
						'type'           => 'date_time_picker',
						'required'       => 1,
						'display_format' => 'Y-m-d H:i:s',
						'return_format'  => 'Y-m-d H:i:s',
					),
					array(
						'key'            => 'field_es_end_datetime',
						'label'          => __( 'End Date & Time', 'events-showcase' ),
						'name'           => 'es_end_datetime',
						'type'           => 'date_time_picker',
						'required'       => 0,
						'display_format' => 'Y-m-d H:i:s',
						'return_format'  => 'Y-m-d H:i:s',
					),
					array(
						'key'   => 'field_es_venue_name',
						'label' => __( 'Venue Name', 'events-showcase' ),
						'name'  => 'es_venue_name',
						'type'  => 'text',
					),
					array(
						'key'          => 'field_es_location',
						'label'        => __( 'Location', 'events-showcase' ),
						'name'         => 'es_location',
						'type'         => 'text',
						'instructions' => __( 'City or region — drives the location filter.', 'events-showcase' ),
					),
					array(
						'key'      => 'field_es_external_url',
						'label'    => __( 'External URL', 'events-showcase' ),
						'name'     => 'es_external_url',
						'type'     => 'url',
						'required' => 0,
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => Post_Type::post_type(),
						),
					),
				),
			)
		);
	}
}
