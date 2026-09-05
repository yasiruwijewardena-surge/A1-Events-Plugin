<?php
/**
 * REST API routes for the events data layer.
 *
 * @package Events_Showcase
 */

namespace Events_Showcase;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the plugin's REST routes. This class only
 * translates HTTP <-> PHP — every response is built entirely from
 * Events_Repository, per the "no query logic in this file" rule.
 */
class REST_Controller {

	/**
	 * REST namespace, versioned so a future breaking change can ship
	 * alongside v1 instead of replacing it.
	 */
	const NAMESPACE_ = 'events-showcase/v1';

	/**
	 * @var Events_Repository
	 */
	private $repository;

	/**
	 * @param Events_Repository $repository Shared data layer.
	 */
	public function __construct( Events_Repository $repository ) {
		$this->repository = $repository;
		\add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers both routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		\register_rest_route(
			self::NAMESPACE_,
			'/events',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_events' ),
				// Events are published content, not user data — every
				// request is allowed through regardless of auth state.
				'permission_callback' => '__return_true',
				'args'                => $this->events_args(),
			)
		);

		\register_rest_route(
			self::NAMESPACE_,
			'/filters',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_filters' ),
				// Same reasoning as above: category/location lists are public.
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
	}

	/**
	 * GET /events-showcase/v1/events
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_events( \WP_REST_Request $request ) {
		$result = $this->repository->get_events(
			array(
				'category' => $request->get_param( 'category' ),
				'per_page' => $request->get_param( 'per_page' ),
				'page'     => $request->get_param( 'page' ),
				'search'   => $request->get_param( 'search' ),
			)
		);

		$response = new \WP_REST_Response(
			array(
				'events' => $result['events'],
				'total'  => $result['total'],
				'pages'  => $result['pages'],
			)
		);

		$response->header( 'Cache-Control', 'public, max-age=60' );

		if ( ! empty( $result['last_modified'] ) ) {
			$response->header( 'Last-Modified', $this->http_date( $result['last_modified'] ) );
		}

		return $response;
	}

	/**
	 * GET /events-showcase/v1/filters — lets the React filter controls be
	 * data-driven instead of hard-coding category/location options.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_filters() {
		$response = new \WP_REST_Response(
			array(
				'categories' => $this->repository->get_categories(),
				'locations'  => $this->repository->get_locations(),
			)
		);

		$response->header( 'Cache-Control', 'public, max-age=60' );

		return $response;
	}

	/**
	 * Args schema for /events. Every parameter rejects unexpected input
	 * via validate_callback rather than silently coercing it.
	 *
	 * @return array<string, array>
	 */
	private function events_args(): array {
		return array(
			'category' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => static function ( $value ) {
					// Same charset WordPress enforces on slugs; a category
					// that doesn't exist just yields zero results, not an error.
					return '' === $value || 1 === \preg_match( '/^[a-z0-9-]+$/', $value );
				},
			),
			'per_page' => array(
				'type'              => 'integer',
				'required'          => false,
				'default'           => 10,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ) {
					return \is_numeric( $value ) && $value >= 1 && $value <= 100;
				},
			),
			'page'     => array(
				'type'              => 'integer',
				'required'          => false,
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ) {
					return \is_numeric( $value ) && $value >= 1;
				},
			),
			'search'   => array(
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ) {
					// Bounding length isn't about correctness — it's a
					// cheap guard against a pathologically large LIKE query.
					return \is_string( $value ) && \strlen( $value ) <= 200;
				},
			),
		);
	}

	/**
	 * Converts an ISO 8601 datetime into the RFC 7231 format the
	 * Last-Modified header requires.
	 *
	 * @param string $iso8601 ISO 8601 datetime string.
	 * @return string
	 */
	private function http_date( string $iso8601 ): string {
		$timestamp = \strtotime( $iso8601 );
		return $timestamp ? \gmdate( 'D, d M Y H:i:s', $timestamp ) . ' GMT' : '';
	}
}
