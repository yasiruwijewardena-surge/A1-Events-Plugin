<?php
/**
 * Outputs Schema.org Event structured data (JSON-LD) on singular event pages.
 *
 * @package Events_Showcase
 */

namespace Events_Showcase;

defined( 'ABSPATH' ) || exit;

/**
 * Prints a `<script type="application/ld+json">` block in `wp_head` for
 * each singular event page. Built entirely from
 * Events_Repository::normalise() — this is a third consumer of that
 * method, alongside the REST controller and the shortcode's SSR payload,
 * so structured data can never describe an event differently than the
 * page itself does.
 */
class Schema {

	/**
	 * @var Events_Repository
	 */
	private $repository;

	/**
	 * @param Events_Repository $repository Shared data layer.
	 */
	public function __construct( Events_Repository $repository ) {
		$this->repository = $repository;
		\add_action( 'wp_head', array( $this, 'output' ) );
	}

	/**
	 * Prints the JSON-LD block, if this request is a singular view of one
	 * event.
	 *
	 * @return void
	 */
	public function output(): void {
		if ( ! \is_singular( Post_Type::post_type() ) ) {
			return;
		}

		$post = \get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$event  = $this->repository->normalise( $post );
		$schema = $this->build_schema( $event );

		/**
		 * Filters the final Schema.org Event array before it's encoded and
		 * output — the same extension point pattern as
		 * `events_showcase_rest_item`.
		 *
		 * @param array    $schema Schema.org Event data, keyed as it will be encoded.
		 * @param array    $event  The normalised event (Events_Repository::normalise()).
		 * @param \WP_Post $post   The event post.
		 */
		$schema = \apply_filters( 'events_showcase_schema', $schema, $event, $post );

		// Same flag, same reason, as the shortcode's inline payload: a
		// literal "</script>" inside the description would otherwise
		// close this tag early and spill the rest of the JSON onto the
		// page as visible text.
		$json = \wp_json_encode( $schema, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $json is wp_json_encode()'d above, not raw.
		printf( "<script type=\"application/ld+json\">%s</script>\n", $json );
	}

	/**
	 * Maps a normalised event to the Schema.org Event shape.
	 *
	 * @param array $event Normalised event.
	 * @return array
	 */
	private function build_schema( array $event ): array {
		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Event',
			'name'        => $event['title'],
			'description' => $this->description( $event ),
			'url'         => $event['permalink'],
			'image'       => $event['thumbnail']['url'],
			'startDate'   => $this->schema_date( $event['start_datetime'], $event['all_day'] ),
			'endDate'     => $this->schema_date( $event['end_datetime'], $event['all_day'] ),
			// eventAttendanceMode is deliberately omitted: the plugin has
			// no online/offline field, and guessing one would be wrong —
			// an incorrect structured-data claim is worse than a missing one.
			'eventStatus' => $this->event_status( $event['status'] ),
			'location'    => $this->location( $event ),
		);

		return $this->remove_null( $schema );
	}

	/**
	 * The excerpt if there is one; otherwise a stripped and truncated
	 * fallback from the content. Never raw HTML — a `description` property
	 * containing markup is exactly the kind of thing structured-data
	 * validators reject.
	 *
	 * @param array $event Normalised event.
	 * @return string|null
	 */
	private function description( array $event ): ?string {
		if ( ! empty( $event['excerpt'] ) ) {
			return $event['excerpt'];
		}

		if ( empty( $event['content'] ) ) {
			return null;
		}

		return \wp_trim_words( \wp_strip_all_tags( $event['content'] ), 55 );
	}

	/**
	 * The repository's ISO 8601 value, or — for an all-day event — just
	 * the date portion. Schema.org's own examples use a bare date for
	 * all-day events rather than a datetime with a fabricated time of day.
	 *
	 * @param string|null $iso8601 Repository's ISO 8601 value.
	 * @param bool        $all_day Whether the event is all-day.
	 * @return string|null
	 */
	private function schema_date( ?string $iso8601, bool $all_day ): ?string {
		if ( empty( $iso8601 ) ) {
			return null;
		}

		if ( ! $all_day ) {
			return $iso8601;
		}

		// Reparsed preserving the offset embedded in the ISO string,
		// rather than strtotime()+gmdate() converting to UTC first — a
		// local date near midnight could otherwise land on the wrong
		// calendar day once shifted to UTC.
		$datetime = \DateTime::createFromFormat( \DateTime::ATOM, $iso8601 );

		return $datetime ? $datetime->format( 'Y-m-d' ) : null;
	}

	/**
	 * Maps the plugin's status enum to the Schema.org URLs it expects.
	 *
	 * @param string $status One of scheduled|postponed|cancelled.
	 * @return string
	 */
	private function event_status( string $status ): string {
		$map = array(
			'scheduled' => 'https://schema.org/EventScheduled',
			'postponed' => 'https://schema.org/EventPostponed',
			'cancelled' => 'https://schema.org/EventCancelled',
		);

		return $map[ $status ] ?? $map['scheduled'];
	}

	/**
	 * A nested Place, or null to omit the whole `location` key — a Place
	 * with neither a name nor an address is exactly the kind of
	 * placeholder value structured-data validators flag as an error, so
	 * there's no value in emitting one.
	 *
	 * @param array $event Normalised event.
	 * @return array|null
	 */
	private function location( array $event ): ?array {
		if ( empty( $event['venue'] ) && empty( $event['location'] ) ) {
			return null;
		}

		return $this->remove_null(
			array(
				'@type'   => 'Place',
				'name'    => $event['venue'] ?: null,
				'address' => $event['location'] ?: null,
			)
		);
	}

	/**
	 * Drops any key whose value is null. Google's structured data
	 * validator treats a null-valued property as an error, not as an
	 * absent one — omitting the key entirely is the correct behaviour,
	 * not just a cosmetic preference.
	 *
	 * @param array $data Associative array.
	 * @return array
	 */
	private function remove_null( array $data ): array {
		return \array_filter(
			$data,
			static function ( $value ) {
				return null !== $value;
			}
		);
	}
}
