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
	 *     @type string   $category   Taxonomy term slug to filter by. Ignored
	 *                                when $related_to is given — see there.
	 *     @type int      $per_page   Results per page.
	 *     @type int      $page       Page number, 1-indexed.
	 *     @type string   $search     Free-text search term.
	 *     @type string   $show       'upcoming' (default), 'past', or 'all'.
	 *     @type int|null $related_to Post ID to build a "related events"
	 *                                listing around, or null (default) for
	 *                                a plain listing. When given: matches
	 *                                any es_event_category term attached to
	 *                                that post (replacing $category, not
	 *                                combined with it) and excludes that
	 *                                post from the results. Not exposed to
	 *                                REST — see Shortcode::resolve_related_to()
	 *                                for why this is shortcode-only.
	 * }
	 * @return array{events: array, total: int, pages: int, last_modified: ?string}
	 */
	public function get_events( array $args = array() ): array {
		// Hardcoded, not Settings::get() — this class takes explicit,
		// already-resolved arguments and stays a pure data layer.
		// Resolving the *actual* default (shortcode attribute → saved
		// option → this hardcoded fallback) is each caller's job: see
		// Shortcode::parse_atts() and REST_Controller::events_args().
		// A caller that omits 'show' entirely — which neither of those
		// two ever does — lands here as a last resort, not as the
		// effective precedence chain.
		$args = \wp_parse_args(
			$args,
			array(
				'category'   => '',
				'per_page'   => 10,
				'page'       => 1,
				'search'     => '',
				'show'       => 'upcoming',
				'related_to' => null,
			)
		);

		// wp_parse_args() only fills in a default for a *missing* key — a
		// caller-supplied `null` (e.g. WP_REST_Request::get_param() for an
		// optional arg with no schema default) overrides the '' default
		// above and survives as null. Cast now so every check below can
		// trust these are strings, not null.
		$args['category'] = (string) $args['category'];
		$args['search']   = (string) $args['search'];

		$related_to = ! empty( $args['related_to'] ) ? (int) $args['related_to'] : 0;

		// REST and the shortcode both already validate 'show' against this
		// same enum, but the repository doesn't take that on faith from
		// any caller — an invalid value falls back to the default rather
		// than producing an unfiltered/mis-sorted query.
		$show_values = array( 'upcoming', 'past', 'all' );
		$args['show'] = \in_array( $args['show'], $show_values, true ) ? $args['show'] : 'upcoming';

		// Named clauses, not the old top-level meta_key/orderby=meta_value
		// pair: that shorthand and a second meta_query clause (the date
		// comparison below) would both try to join wp_postmeta, and two
		// unrelated joins against the same table produce duplicate result
		// rows. A named clause lets 'orderby' point at *this* join
		// specifically, however many others end up alongside it.
		$meta_query = array(
			'start_clause' => array(
				'key'     => 'es_start_datetime',
				'compare' => 'EXISTS',
				'type'    => 'DATETIME',
			),
		);

		// 'past' sorts most-recent-first (like a history/archive list);
		// 'upcoming' and 'all' sort soonest-first (like an agenda). This
		// is a product decision — a past-events list read chronologically
		// forward would bury the events someone's most likely looking
		// for (last month's) under events from years ago.
		//
		// A related listing ($related_to below) uses this same ordering —
		// it is not ranked by how many categories an event shares with the
		// source post. WP_Query has no built-in way to order by tax-match
		// count without dropping to raw SQL, and the added complexity
		// isn't worth it for a "related events" strip.
		$order = 'past' === $args['show'] ? 'DESC' : 'ASC';

		if ( 'all' !== $args['show'] ) {
			$meta_query['date_clause'] = array(
				'key'     => 'es_start_datetime',
				'value'   => \current_time( 'mysql' ),
				'compare' => 'upcoming' === $args['show'] ? '>=' : '<',
				'type'    => 'DATETIME',
			);
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
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'orderby'        => array( 'start_clause' => $order ),
		);

		if ( $related_to > 0 ) {
			// A related listing replaces the plain category filter with the
			// source event's own terms rather than trying to combine two
			// independent tax_query clauses — $args['category'] is ignored
			// in this branch.
			$related_term_ids = \wp_get_post_terms( $related_to, Post_Type::taxonomy(), array( 'fields' => 'ids' ) );

			if ( ! \is_wp_error( $related_term_ids ) && ! empty( $related_term_ids ) ) {
				$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => Post_Type::taxonomy(),
						'field'    => 'term_id',
						'terms'    => $related_term_ids,
					),
				);
			}
			// No terms on the source post: $query_args simply has no
			// tax_query at all, which already is the "plain upcoming
			// listing" fallback this method promises — see the class-level
			// comment on the second fallback branch below for the other
			// half of that promise (terms exist, but nothing else shares
			// them).

			// A related section must never list the event you're already
			// reading, regardless of which branch above ran.
			$query_args['post__not_in'] = array( $related_to );
		} elseif ( '' !== $args['category'] ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => Post_Type::taxonomy(),
					'field'    => 'slug',
					'terms'    => \sanitize_title( (string) $args['category'] ),
				),
			);
		}

		/**
		 * Filters the WP_Query arguments for an events listing.
		 *
		 * This is the seam for a site that needs to change *what is
		 * selected* rather than how it is shaped — exclude a category, add
		 * a meta constraint, restrict by author — without forking the
		 * plugin. It is the counterpart to `events_showcase_rest_item`,
		 * which filters each event on the way out; between them a site can
		 * influence both ends of the query without touching this file.
		 *
		 * The result is cached (see below), and the cache key is derived
		 * from the filtered arguments — so a filter that varies its output
		 * automatically varies the key. Nothing extra is needed to keep
		 * the two in step.
		 *
		 * @param array $query_args Arguments about to be passed to WP_Query.
		 * @param array $args       The repository's own resolved arguments.
		 */
		$query_args = (array) \apply_filters( 'events_showcase_query_args', $query_args, $args );

		// Cached on the *filtered* arguments, not on $args, so that a
		// filter above is reflected in the key automatically rather than
		// silently serving another caller's cached result.
		//
		// The date clause's value is `now`, which would otherwise make the
		// key unique per second and defeat the cache entirely. It is a
		// pure function of $args['show'] — which is part of the hash — so
		// dropping it from the key costs nothing and restores a cache
		// lifetime bounded by invalidation rather than by the clock.
		//
		// The trade-off of hashing here rather than earlier: a related
		// listing now resolves its source post's terms (one query) before
		// the cache is consulted. That's the price of a key that can't go
		// stale against a filter, and it's one query on the only template
		// where related listings appear.
		$key_args = $query_args;
		unset( $key_args['meta_query']['date_clause']['value'] );

		$cache_key = $this->cache_key( 'events_' . \md5( (string) \wp_json_encode( array( $args, $key_args ) ) ) );
		$cached    = \wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$query = new \WP_Query( $query_args );

		// Second half of the related-listing fallback: the source post had
		// categories (a tax_query was built above), but nothing else on
		// the site shares any of them, so this query came back empty.
		// Re-run once, dropping the tax_query, rather than showing an
		// empty "Related events" block — that reads as broken; three
		// unrelated upcoming events do not. post__not_in stays, so the
		// source event still never lists itself.
		if ( $related_to > 0 && 0 === $query->found_posts && isset( $query_args['tax_query'] ) ) {
			unset( $query_args['tax_query'] );
			$query = new \WP_Query( $query_args );
		}

		$result = array(
			'events'        => \array_map( array( $this, 'normalise' ), $query->posts ),
			'total'         => (int) $query->found_posts,
			'pages'         => (int) $query->max_num_pages,
			// Carried alongside the payload so the REST controller can set
			// a Last-Modified header without re-querying.
			'last_modified' => $this->latest_modified( $query->posts ),
		);

		// The cache key already hashes $args, so 'show' is covered like
		// every other parameter — but unlike category/search, an
		// upcoming/past split is time-based: an event can cross from
		// upcoming to past without any save_post/delete/term-change firing
		// to bust the cache. Worst case it lingers on the wrong side of
		// the split for up to CACHE_TTL (300s) after it crosses. Accepted
		// as-is — a version-bump-on-every-request cache would defeat the
		// point of caching, and 5 minutes of staleness on a boundary this
		// coarse isn't worth that trade.
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
			// get_the_title()/get_the_excerpt() return HTML-entity-encoded
			// text (e.g. a literal "&" stored as "&#038;") — correct for a
			// theme that echoes it straight into HTML, but this value's
			// consumers are JSON-then-React-text and esc_html() in the
			// no-JS fallback, neither of which decodes HTML entities.
			// Left un-decoded, an event titled "R&D" would literally show
			// "R&#038;D" on screen. wp_specialchars_decode() reverses it
			// back to plain text once, here, so every consumer gets the
			// same correct value.
			'title'          => \wp_specialchars_decode( \get_the_title( $post ), ENT_QUOTES ),
			'permalink'      => \get_permalink( $post ),
			'excerpt'        => \has_excerpt( $post )
				? \wp_specialchars_decode( \wp_strip_all_tags( \get_the_excerpt( $post ) ), ENT_QUOTES )
				: '',
			// Deliberately not `apply_filters( 'the_content', ... )`: the
			// shortcode itself runs *during* the_content on the host page,
			// so filtering through the_content again here re-enters the
			// same chain once per event — do_shortcode is part of that
			// chain, so an event body containing [events_showcase] would
			// recurse infinitely, and Divi (this site's theme) hooks
			// the_content heavily enough that running it nested produces
			// duplicated/mangled output even without that. A JSON payload
			// also has no business running arbitrary third-party content
			// filters. wp_kses_post() + wpautop() gives safe, paragraph-
			// wrapped HTML without re-entering anything.
			'content'        => \wp_kses_post( \wpautop( $post->post_content ) ),
			'start_datetime' => $this->iso8601( $this->meta( $post->ID, 'es_start_datetime' ) ),
			'end_datetime'   => $this->iso8601( $this->meta( $post->ID, 'es_end_datetime' ) ),
			'venue'          => $this->meta( $post->ID, 'es_venue_name' ),
			'location'       => $this->meta( $post->ID, 'es_location' ),
			'categories'     => $this->categories( $post->ID ),
			'thumbnail'      => $this->thumbnail( $post->ID ),
			'external_url'   => $this->meta( $post->ID, 'es_external_url' ),
			'all_day'        => $this->boolean_meta( $post->ID, 'es_all_day' ),
			// Deliberately not filtered by status here or in get_events():
			// a postponed or cancelled event still needs to be findable by
			// someone checking whether it's still on — hiding it would
			// defeat the point of having a status field at all.
			'status'         => $this->status_meta( $post->ID ),
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
	 * Same ACF-fallback shape as meta(), but for the boolean all-day flag
	 * — a real bool, never the string "1"/"" get_post_meta() would
	 * otherwise hand back.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key (also the ACF field name).
	 * @return bool
	 */
	private function boolean_meta( int $post_id, string $key ): bool {
		$value = \get_post_meta( $post_id, $key, true );

		if ( '' === $value && \function_exists( 'get_field' ) ) {
			$value = \get_field( $key, $post_id );
		}

		return \rest_sanitize_boolean( $value );
	}

	/**
	 * The event status, defaulting (and falling back on any unrecognised
	 * stored value) to "scheduled" — covers events saved before this field
	 * existed, which have no es_status meta at all yet.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function status_meta( int $post_id ): string {
		$allowed = array( 'scheduled', 'postponed', 'cancelled' );
		$value   = $this->meta( $post_id, 'es_status' );

		return \in_array( $value, $allowed, true ) ? $value : 'scheduled';
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
