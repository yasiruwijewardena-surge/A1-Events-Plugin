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
	 * Derived meta key holding when an event ends, in the same naive
	 * "Y-m-d H:i:s" site-local format as es_start_datetime. Never edited
	 * directly — see sync_ends_at(). Underscore-prefixed so WordPress
	 * hides it from the Custom Fields box.
	 */
	const ENDS_AT_KEY = '_es_ends_at';

	/**
	 * The meta keys ends_at() depends on. A change to any of them
	 * recalculates ENDS_AT_KEY.
	 */
	const ENDS_AT_SOURCES = array( 'es_start_datetime', 'es_end_datetime', 'es_all_day' );

	/**
	 * Option recording that every existing event has ENDS_AT_KEY filled
	 * in — see maybe_backfill_ends_at().
	 */
	const ENDS_AT_BACKFILL_OPTION = 'events_showcase_ends_at_backfilled';

	/**
	 * Wires registration to `init`.
	 */
	public function __construct() {
		\add_action( 'init', array( $this, 'register' ) );
		\add_action( 'init', array( $this, 'register_meta' ) );
		// Dynamic for the same reason as every other save_post_{post_type}
		// hook in this plugin: Post_Type::post_type() is filterable.
		\add_action( 'save_post_' . self::post_type(), array( $this, 'backfill_start_datetime' ) );

		// Meta hooks rather than save_post: they fire whichever UI or API
		// wrote the value — the meta box, ACF (which saves after
		// save_post_{post_type} has already run), the REST API, WP-CLI —
		// so ENDS_AT_KEY can't drift from the fields it's derived from.
		\add_action( 'added_post_meta', array( $this, 'sync_ends_at_on_meta_change' ), 10, 3 );
		\add_action( 'updated_post_meta', array( $this, 'sync_ends_at_on_meta_change' ), 10, 3 );
		\add_action( 'deleted_post_meta', array( $this, 'sync_ends_at_on_meta_change' ), 10, 3 );
		\add_action( 'init', array( $this, 'maybe_backfill_ends_at' ), 20 );

		// A star column on All Events, so whoever manages content can see
		// which events are featured without opening each one.
		\add_filter( 'manage_' . self::post_type() . '_posts_columns', array( $this, 'add_featured_column' ) );
		\add_action( 'manage_' . self::post_type() . '_posts_custom_column', array( $this, 'render_featured_column' ), 10, 2 );
		\add_action( 'admin_head-edit.php', array( $this, 'print_featured_column_styles' ) );
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
				// Not dashicons-calendar-event: the dashicons.min.css shipped
				// with the site's WordPress (7.1) has no glyph for it, so the
				// menu showed an empty icon slot. calendar-alt is present.
				'menu_icon'    => 'dashicons-calendar-alt',
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
			'es_featured',
			array(
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => false,
				// Same core helper as es_all_day — see there.
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
	 * Backfills es_start_datetime from the post's own date when it's
	 * empty, so "every event has a start date" holds by construction.
	 *
	 * This closes the gap at the source rather than in the query: without
	 * it, Events_Repository::get_events() has to treat a missing start
	 * date as a real case to handle, and there's no good way to handle
	 * it — sorting such an event to either end of the list is arbitrary,
	 * and an `OR NOT EXISTS` clause would complicate the ordering clause
	 * for no benefit. With the invariant guaranteed here instead, the
	 * query layer never has to think about it.
	 *
	 * Also covers a path the editing UIs' `required` attributes don't: a
	 * post created directly via the REST API (register_post_meta() makes
	 * es_start_datetime writable there, but nothing marks it required)
	 * would otherwise silently produce an event invisible in every
	 * listing, with no error anywhere.
	 *
	 * @param int $post_id Post being saved.
	 * @return void
	 */
	public function backfill_start_datetime( int $post_id ): void {
		// Autosaves/revisions fire this hook too but aren't the real post
		// — nothing meaningful to backfill against a draft snapshot, and
		// writing to one would just be discarded anyway.
		if ( \wp_is_post_autosave( $post_id ) || \wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( '' !== \get_post_meta( $post_id, 'es_start_datetime', true ) ) {
			return;
		}

		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// post_date is already a naive "Y-m-d H:i:s" string in the site's
		// local time — the same convention es_start_datetime itself uses
		// (see Events_Repository::iso8601()) — so no conversion is needed.
		\update_post_meta( $post_id, 'es_start_datetime', $post->post_date );
	}

	/**
	 * When an event ends: the single definition every "upcoming / ongoing
	 * / past" decision in the plugin is made from.
	 *
	 * - An end date and time, if one is set.
	 * - An all-day event runs to the end of its last day (the end date's
	 *   day if set, otherwise the start date's) — a whole-day festival
	 *   isn't over at 00:00 on the morning it begins.
	 * - Otherwise there's nothing to go on but the start time, so the
	 *   event is treated as over once it starts, which is how the plugin
	 *   always behaved for events without an end.
	 *
	 * Takes and returns naive "Y-m-d H:i:s" site-local strings, the format
	 * the event fields are stored in. Pure string work — no timezone
	 * conversion is needed because every value is in the same zone.
	 *
	 * @param string|null $start   es_start_datetime.
	 * @param string|null $end     es_end_datetime.
	 * @param bool        $all_day es_all_day.
	 * @return string|null Null only when there is no start date at all.
	 */
	public static function ends_at( ?string $start, ?string $end, bool $all_day ): ?string {
		$start = (string) $start;
		$end   = (string) $end;

		// An end before the start is a data-entry slip, not an event that
		// ends before it begins; fall back to the start rather than
		// filing a still-to-come event under "past".
		if ( '' !== $end && '' !== $start && $end < $start ) {
			$end = '';
		}

		if ( $all_day ) {
			$day = '' !== $end ? $end : $start;
			return '' !== $day ? \substr( $day, 0, 10 ) . ' 23:59:59' : null;
		}

		if ( '' !== $end ) {
			return $end;
		}

		return '' !== $start ? $start : null;
	}

	/**
	 * Recalculates ENDS_AT_KEY when one of its source fields changes.
	 *
	 * @param int|int[] $meta_ids  Meta ID(s) — unused.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key that changed.
	 * @return void
	 */
	public function sync_ends_at_on_meta_change( $meta_ids, $object_id, $meta_key ): void {
		if ( ! \in_array( $meta_key, self::ENDS_AT_SOURCES, true ) ) {
			return;
		}

		if ( self::post_type() !== \get_post_type( $object_id ) ) {
			return;
		}

		self::sync_ends_at( (int) $object_id );
	}

	/**
	 * Writes (or clears) ENDS_AT_KEY for one event from its current
	 * field values. Public and static so the backfill and anything else
	 * can reuse it.
	 *
	 * @param int $post_id Event post ID.
	 * @return void
	 */
	public static function sync_ends_at( int $post_id ): void {
		$ends_at = self::ends_at(
			\get_post_meta( $post_id, 'es_start_datetime', true ) ?: null,
			\get_post_meta( $post_id, 'es_end_datetime', true ) ?: null,
			\rest_sanitize_boolean( \get_post_meta( $post_id, 'es_all_day', true ) )
		);

		if ( null === $ends_at ) {
			\delete_post_meta( $post_id, self::ENDS_AT_KEY );
			return;
		}

		\update_post_meta( $post_id, self::ENDS_AT_KEY, $ends_at );
	}

	/**
	 * Fills in ENDS_AT_KEY for every event that existed before it did,
	 * once. Listings filter on that key, so an event without it would
	 * silently drop out of both "upcoming" and "past".
	 *
	 * Runs on init rather than only on activation: updating a plugin by
	 * replacing its files (how this one is deployed) doesn't fire the
	 * activation hook. The option makes every later request a single
	 * autoloaded lookup.
	 *
	 * @return void
	 */
	public function maybe_backfill_ends_at(): void {
		if ( \get_option( self::ENDS_AT_BACKFILL_OPTION ) ) {
			return;
		}

		$post_ids = \get_posts(
			array(
				'post_type'        => self::post_type(),
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		foreach ( $post_ids as $post_id ) {
			self::sync_ends_at( (int) $post_id );
		}

		\update_option( self::ENDS_AT_BACKFILL_OPTION, 1, true );
	}

	/**
	 * Adds a narrow "Featured" column after the title on All Events.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_featured_column( array $columns ): array {
		$result = array();

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result['es_featured'] = __( 'Featured', 'events-showcase' );
			}
		}

		return $result;
	}

	/**
	 * Prints a filled star for featured events. The visually hidden text
	 * is what a screen reader announces — the star itself is decorative.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Row's post ID.
	 * @return void
	 */
	public function render_featured_column( string $column, int $post_id ): void {
		if ( 'es_featured' !== $column || ! \get_post_meta( $post_id, 'es_featured', true ) ) {
			return;
		}

		printf(
			'<span class="dashicons dashicons-star-filled" aria-hidden="true"></span><span class="screen-reader-text">%s</span>',
			\esc_html__( 'Featured', 'events-showcase' )
		);
	}

	/**
	 * Keeps the Featured column narrow — by default WordPress gives every
	 * custom column an equal share of the leftover width.
	 *
	 * @return void
	 */
	public function print_featured_column_styles(): void {
		if ( self::post_type() !== \get_current_screen()?->post_type ) {
			return;
		}

		echo '<style>.column-es_featured{width:5.5em;text-align:center}.column-es_featured .dashicons{color:#dba617}</style>';
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
