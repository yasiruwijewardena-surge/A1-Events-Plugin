<?php
/**
 * Single source of truth for querying and shaping event data. Both the
 * REST controller and the future server-rendered inline payload call into
 * this class so the two consumers can never drift out of sync.
 *
 * @package Events_Showcase
 */

namespace Events_Showcase;

defined( 'ABSPATH' ) || exit;

/**
 * Queries, normalises, and caches event data.
 */
class Events_Repository {

	/**
	 * Object cache group for everything this class stores.
	 */
	const CACHE_GROUP = 'events_showcase';

	/**
	 * Belt-and-braces TTL alongside the version-based busting below —
	 * covers edits that bypass our hooks (direct DB writes, WP-CLI, etc.).
	 */
	const CACHE_TTL = 300;

	/**
	 * Option name for the integer folded into every cache key.
	 * Incrementing it invalidates the whole group at once, since core has
	 * no guaranteed "flush this group" primitive without a persistent
	 * object cache backend that supports it.
	 */
	const VERSION_OPTION = 'events_showcase_cache_version';

	/**
	 * Registers the cache-busting hooks.
	 */
	public function __construct() {
		// Dynamic on purpose: Post_Type::post_type() is filterable, so a
		// hardcoded `save_post_events` would silently stop invalidating
		// the cache the moment a site repoints the post type filter.
		\add_action( 'save_post_' . Post_Type::post_type(), array( $this, 'bust_cache' ) );
		\add_action( 'deleted_post', array( $this, 'maybe_bust_cache_on_delete' ), 10, 2 );
		\add_action( 'set_object_terms', array( $this, 'maybe_bust_cache_on_terms' ), 10, 4 );
	}

	/**
	 * Queries published events.
	 *
	 * @param array $args {
	 *     @type string $category Taxonomy term slug to filter by.
	 *     @type int    $per_page Results per page.
	 *     @type int    $page     Page number, 1-indexed.
	 *     @type string $search   Free-text search term.
	 * }
	 * @return array{events: array, total: int, pages: int, last_modified: ?string}
	 */
	public function get_events( array $args = array() ): array {
		$args = \wp_parse_args(
			$args,
			array(
				'category' => '',
				'per_page' => 10,
				'page'     => 1,
				'search'   => '',
			)
		);

		$cache_key = $this->cache_key( 'events_' . \md5( (string) \wp_json_encode( $args ) ) );
		$cached    = \wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$query_args = array(
			'post_type'      => Post_Type::post_type(),
			'post_status'    => 'publish',
			// Hard ceiling: an unbounded per_page from a caller other than
			// the (already-validated) REST controller is still a DoS risk
			// against WP_Query.
			'posts_per_page' => max( 1, min( 100, (int) $args['per_page'] ) ),
			'paged'          => max( 1, (int) $args['page'] ),
			's'              => \sanitize_text_field( (string) $args['search'] ),
			// Y-m-d H:i:s sorts identically whether compared as a string
			// or a date, so plain meta_value ordering is enough — no need
			// for the slower meta_value_num / DATETIME cast.
			'orderby'        => 'meta_value',
			'meta_key'       => 'es_start_datetime',
			'order'          => 'ASC',
		);

		if ( '' !== $args['category'] ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => Post_Type::taxonomy(),
					'field'    => 'slug',
					'terms'    => \sanitize_title( (string) $args['category'] ),
				),
			);
		}

		$query = new \WP_Query( $query_args );

		$result = array(
			'events'        => \array_map( array( $this, 'normalise' ), $query->posts ),
			'total'         => (int) $query->found_posts,
			'pages'         => (int) $query->max_num_pages,
			// Carried alongside the payload so the REST controller can set
			// a Last-Modified header without re-querying.
			'last_modified' => $this->latest_modified( $query->posts ),
		);

		\wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Available categories, for the React filter controls.
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	public function get_categories(): array {
		$cache_key = $this->cache_key( 'categories' );
		$cached    = \wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$terms = \get_terms(
			array(
				'taxonomy'   => Post_Type::taxonomy(),
				'hide_empty' => true,
			)
		);

		$categories = \is_wp_error( $terms ) ? array() : \array_map(
			static function ( \WP_Term $term ) {
				return array(
					'slug' => $term->slug,
					'name' => $term->name,
				);
			},
			$terms
		);

		\wp_cache_set( $cache_key, $categories, self::CACHE_GROUP, self::CACHE_TTL );

		return $categories;
	}

	/**
	 * Distinct, non-empty location values actually in use. A raw meta
	 * query here is far cheaper than loading every event post just to
	 * read one meta key off each — core has no "distinct meta values"
	 * API.
	 *
	 * @return string[]
	 */
	public function get_locations(): array {
		global $wpdb;

		$cache_key = $this->cache_key( 'locations' );
		$cached    = \wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$locations = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s
					AND pm.meta_value != ''
					AND p.post_type = %s
					AND p.post_status = 'publish'
				ORDER BY pm.meta_value ASC",
				'es_location',
				Post_Type::post_type()
			)
		);

		\wp_cache_set( $cache_key, $locations, self::CACHE_GROUP, self::CACHE_TTL );

		return $locations;
	}

	/**
	 * Flattens a post into the predictable shape both REST and the future
	 * inline payload rely on. Missing values are `null` or `[]` — never an
	 * undefined index — so consumers don't need defensive isset() checks.
	 *
	 * @param \WP_Post $post Event post.
	 * @return array
	 */
	public function normalise( \WP_Post $post ): array {
		$item = array(
			'id'             => $post->ID,
			'title'          => \get_the_title( $post ),
			'permalink'      => \get_permalink( $post ),
			'excerpt'        => \has_excerpt( $post ) ? \wp_strip_all_tags( \get_the_excerpt( $post ) ) : '',
			'content'        => \apply_filters( 'the_content', $post->post_content ),
			'start_datetime' => $this->iso8601( $this->meta( $post->ID, 'es_start_datetime' ) ),
			'end_datetime'   => $this->iso8601( $this->meta( $post->ID, 'es_end_datetime' ) ),
			'venue'          => $this->meta( $post->ID, 'es_venue_name' ),
			'location'       => $this->meta( $post->ID, 'es_location' ),
			'categories'     => $this->categories( $post->ID ),
			'thumbnail'      => $this->thumbnail( $post->ID ),
			'external_url'   => $this->meta( $post->ID, 'es_external_url' ),
		);

		return \apply_filters( 'events_showcase_rest_item', $item, $post );
	}

	/**
	 * Reads a field's value from post meta, falling back to ACF's
	 * get_field() when meta is empty — covers the gap between a bare
	 * get_post_meta() read and whatever formatting ACF applies on save.
	 * The public shape is identical either way: a string, or null.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key (also the ACF field name).
	 * @return string|null
	 */
	private function meta( int $post_id, string $key ) {
		$value = \get_post_meta( $post_id, $key, true );

		if ( ( '' === $value || false === $value ) && \function_exists( 'get_field' ) ) {
			$value = \get_field( $key, $post_id );
		}

		if ( null === $value || false === $value || '' === $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Converts a stored "Y-m-d H:i:s" datetime into an ISO 8601 string.
	 * Never leaks the raw MySQL format to a consumer.
	 *
	 * @param string|null $mysql_datetime Naive local datetime string.
	 * @return string|null
	 */
	private function iso8601( ?string $mysql_datetime ): ?string {
		if ( empty( $mysql_datetime ) ) {
			return null;
		}

		// The stored value has no UTC offset (ACF's date_time_picker
		// writes local wall-clock time), so attach wp_timezone()
		// explicitly rather than letting DateTime assume UTC.
		$datetime = \DateTime::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime, \wp_timezone() );

		return $datetime ? $datetime->format( \DateTime::ATOM ) : null;
	}

	/**
	 * @param int $post_id Post ID.
	 * @return array<int, array{slug: string, name: string}>
	 */
	private function categories( int $post_id ): array {
		$terms = \get_the_terms( $post_id, Post_Type::taxonomy() );

		if ( empty( $terms ) || \is_wp_error( $terms ) ) {
			return array();
		}

		return \array_map(
			static function ( \WP_Term $term ) {
				return array(
					'slug' => $term->slug,
					'name' => $term->name,
				);
			},
			$terms
		);
	}

	/**
	 * @param int $post_id Post ID.
	 * @return array{url: ?string, width: ?int, height: ?int, alt: ?string}
	 */
	private function thumbnail( int $post_id ): array {
		$empty = array(
			'url'    => null,
			'width'  => null,
			'height' => null,
			'alt'    => null,
		);

		$attachment_id = \get_post_thumbnail_id( $post_id );
		if ( ! $attachment_id ) {
			return $empty;
		}

		// A registered size, not 'full' — keeps the payload predictable
		// and avoids shipping a multi-megabyte original to the grid.
		$image = \wp_get_attachment_image_src( $attachment_id, 'large' );
		if ( ! $image ) {
			return $empty;
		}

		$alt = \get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return array(
			'url'    => $image[0],
			'width'  => $image[1],
			'height' => $image[2],
			'alt'    => '' !== $alt ? $alt : null,
		);
	}

	/**
	 * Newest post_modified_gmt among a result set, as ISO 8601 — used for
	 * the REST response's Last-Modified header.
	 *
	 * @param \WP_Post[] $posts Posts already fetched by the query.
	 * @return string|null
	 */
	private function latest_modified( array $posts ): ?string {
		if ( empty( $posts ) ) {
			return null;
		}

		$latest = \array_reduce(
			$posts,
			static function ( $carry, \WP_Post $post ) {
				return ( null === $carry || $post->post_modified_gmt > $carry ) ? $post->post_modified_gmt : $carry;
			},
			null
		);

		if ( ! $latest ) {
			return null;
		}

		$datetime = \DateTime::createFromFormat( 'Y-m-d H:i:s', $latest, new \DateTimeZone( 'UTC' ) );

		return $datetime ? $datetime->format( \DateTime::ATOM ) : null;
	}

	/**
	 * Folds the current cache version into a cache key so bumping the
	 * version (see bust_cache()) invalidates every key at once without
	 * needing to enumerate or delete them individually.
	 *
	 * @param string $suffix Key-specific suffix, e.g. a hash of query args.
	 * @return string
	 */
	private function cache_key( string $suffix ): string {
		return (int) \get_option( self::VERSION_OPTION, 1 ) . '_' . $suffix;
	}

	/**
	 * Invalidates the whole cache group by incrementing the stored version.
	 *
	 * @return void
	 */
	public function bust_cache() {
		$version = (int) \get_option( self::VERSION_OPTION, 1 );
		// Autoload off: this changes on every save and would otherwise
		// bloat the alloptions cache loaded on every request.
		\update_option( self::VERSION_OPTION, $version + 1, false );
	}

	/**
	 * `deleted_post` fires for every post type, so filter down to events
	 * before paying for a cache bust.
	 *
	 * @param int           $post_id Deleted post ID.
	 * @param \WP_Post|null $post    The now-deleted post object, if available.
	 * @return void
	 */
	public function maybe_bust_cache_on_delete( $post_id, $post ) {
		if ( $post instanceof \WP_Post && Post_Type::post_type() === $post->post_type ) {
			$this->bust_cache();
		}
	}

	/**
	 * `set_object_terms` fires for every taxonomy on every object type, so
	 * filter down to our taxonomy before paying for a cache bust.
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $terms     Terms assigned.
	 * @param array  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy slug.
	 * @return void
	 */
	public function maybe_bust_cache_on_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( Post_Type::taxonomy() === $taxonomy ) {
			$this->bust_cache();
		}
	}
}
