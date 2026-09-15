<?php
/**
 * Renders the [events_showcase] shortcode: the mount element, the
 * server-rendered initial payload, and a no-JS fallback list.
 *
 * @package Events_Showcase
 */

namespace Events_Showcase;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode registration and rendering. Pulls all event data from
 * Events_Repository — the same method the REST controller calls — so the
 * server-rendered payload and the REST response can never drift apart in
 * shape.
 */
class Shortcode {

	/**
	 * Set true the first time the shortcode renders on this request.
	 *
	 * This is a last resort, not a primary signal: `wp_enqueue_scripts` —
	 * the normal place to enqueue — fires before `the_content` is
	 * processed, so this flag is still `false` at that point even when the
	 * shortcode is about to render. Assets::should_enqueue() therefore
	 * checks `has_shortcode()` on the post content instead, which is known
	 * ahead of time. Something reading this flag can only act from a later
	 * hook such as `wp_footer` — fine for a footer-loaded script, but too
	 * late for stylesheets, since CSS enqueued that late causes a flash of
	 * unstyled content.
	 *
	 * @var bool
	 */
	public static $rendered = false;

	/**
	 * Counts instances on the current request so element ids stay unique
	 * when the shortcode appears more than once on one page.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * @var Events_Repository
	 */
	private $repository;

	/**
	 * @param Events_Repository $repository Shared data layer.
	 */
	public function __construct( Events_Repository $repository ) {
		$this->repository = $repository;
		\add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registers the shortcode tag.
	 *
	 * @return void
	 */
	public function register(): void {
		// do_shortcode() can call back with '' instead of [] when the tag
		// has no attributes at all; casting here keeps render()'s own
		// signature strictly typed instead of hedging on every call site.
		\add_shortcode(
			'events_showcase',
			function ( $atts ) {
				return $this->render( (array) $atts );
			}
		);
	}

	/**
	 * Builds the shortcode's output. Never echoes — always returns.
	 *
	 * @param array $atts Raw shortcode attributes.
	 * @return string
	 */
	public function render( array $atts ): string {
		self::$rendered = true;
		$instance       = ++self::$instance;

		$args = $this->parse_atts( $atts );

		$query_args = array(
			'category' => $args['category'],
			'per_page' => $args['per_page'],
			'page'     => 1,
			'search'   => $args['search'],
			'show'     => $args['show'],
		);

		// Only resolved (and only added to the query) when related="true" —
		// see resolve_related_to() for what "resolved" means and why it can
		// legitimately come back empty.
		if ( $args['related'] ) {
			$related_to = $this->resolve_related_to();
			if ( null !== $related_to ) {
				$query_args['related_to'] = $related_to;
			}
		}

		$result = $this->repository->get_events( $query_args );

		$wrapper_id = 'es-events-' . $instance;
		$payload_id = 'es-events-data-' . $instance;

		// Printed on the outer .es-events element as well as handed to
		// React for the inner wrapper, so a theme's CSS can hook either
		// the server-rendered shell or the mounted app.
		$extra_class = '' !== $args['class'] ? ' ' . $args['class'] : '';

		ob_start();
		?>
		<div class="es-events<?php echo esc_attr( $extra_class ); ?>" id="<?php echo esc_attr( $wrapper_id ); ?>">
			<script type="application/json" id="<?php echo esc_attr( $payload_id ); ?>">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- payload_json() already ran this through wp_json_encode(); it is not raw.
				echo $this->payload_json( $result );
				?>
			</script>
			<div
				data-events-showcase
				data-payload="<?php echo esc_attr( $payload_id ); ?>"
				data-api="<?php echo esc_url( \rest_url( 'events-showcase/v1' ) ); ?>"
				data-per-page="<?php echo esc_attr( (string) $args['per_page'] ); ?>"
				data-category="<?php echo esc_attr( $args['category'] ); ?>"
				data-search="<?php echo esc_attr( $args['search'] ); ?>"
				data-show="<?php echo esc_attr( $args['show'] ); ?>"
				data-layout="<?php echo esc_attr( $args['layout'] ); ?>"
				data-columns="<?php echo esc_attr( $args['columns'] ); ?>"
				<?php // Always printed (not only when false) so main.jsx never has to guess what an absent attribute means. ?>
				data-filters="<?php echo esc_attr( $args['filters'] ? 'true' : 'false' ); ?>"
				data-class="<?php echo esc_attr( $args['class'] ); ?>"
				<?php // Not required by the current routes (permission_callback is '__return_true' on both) — included so an authenticated endpoint added later doesn't need a markup change. ?>
				data-nonce="<?php echo esc_attr( \wp_create_nonce( 'wp_rest' ) ); ?>"
			></div>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fallback_html() escapes every value itself; nothing raw reaches this echo.
			echo $this->fallback_html( $result['events'] );
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Sanitizes and clamps every attribute in one place.
	 *
	 * Precedence — shortcode attribute → saved option → hardcoded
	 * default — comes for free from sourcing shortcode_atts()'s own
	 * defaults array from Settings::get(): shortcode_atts() only fills in
	 * a key the caller didn't pass, so an explicit attribute always wins,
	 * and an omitted one falls through to whatever the option currently
	 * resolves to (which itself falls back to Settings' own hardcoded
	 * default if nothing's been saved).
	 *
	 * @param array $atts Raw shortcode attributes.
	 * @return array{category: string, per_page: int, search: string, show: string, layout: string, columns: string, filters: bool, related: bool, class: string}
	 */
	private function parse_atts( array $atts ): array {
		// Read straight off the raw, caller-supplied $atts — before
		// shortcode_atts() merges in defaults — purely to decide what
		// 'filters' should default to below. shortcode_atts() only ever
		// falls back to a default when the key is entirely absent from the
		// caller's own array, so an explicit filters="true" alongside
		// related="true" still overrides whatever default we hand it here.
		$related_requested = isset( $atts['related'] )
			? \filter_var( $atts['related'], FILTER_VALIDATE_BOOLEAN )
			: false;

		$atts = \shortcode_atts(
			array(
				'category' => '',
				'per-page' => Settings::get( 'default_per_page' ),
				'search'   => '',
				'show'     => Settings::get( 'default_show' ),
				'layout'   => Settings::get( 'default_layout' ),
				'columns'  => (string) Settings::get( 'default_columns' ),
				// A related-events strip with a search box is incoherent, so
				// related="true" flips this default off — but only the
				// default: see $related_requested above for why an explicit
				// filters="true" still wins.
				'filters'  => $related_requested ? 'false' : 'true',
				'related'  => 'false',
				// Escape hatch for theming. The stylesheet's own selectors
				// are deliberately specific so they beat a host theme's
				// rules, which means a themer can't win with a bare class;
				// most restyling should go through the --es-* custom
				// properties instead (see events.css). This is for the
				// cases those don't cover, and for scoping overrides to one
				// instance when the same page has several.
				'class'    => '',
			),
			$atts,
			'events_showcase'
		);

		$category = \sanitize_title( $atts['category'] );

		// A typo'd slug silently returning zero events reads like a
		// broken plugin; falling back to "show everything" at least
		// surfaces the mistake as an ignored filter, not an empty grid.
		if ( '' !== $category && ! \term_exists( $category, Post_Type::taxonomy() ) ) {
			$category = '';
		}

		$show = \sanitize_key( $atts['show'] );
		if ( ! \in_array( $show, array( 'upcoming', 'past', 'all' ), true ) ) {
			$show = 'upcoming';
		}

		$layout = \sanitize_key( $atts['layout'] );
		if ( ! \in_array( $layout, array( 'grid', 'list', 'compact' ), true ) ) {
			$layout = 'grid';
		}

		$columns = \sanitize_key( (string) $atts['columns'] );
		if ( ! \in_array( $columns, array( '2', '3', '4' ), true ) ) {
			$columns = '3';
		}

		// Space-separated list, each token run through sanitize_html_class()
		// individually — the function strips whitespace, so handing it the
		// whole string would silently weld "a b" into "ab". Empty results
		// (a token that was entirely invalid characters) are dropped rather
		// than emitted as a stray space.
		$classes = \array_filter(
			\array_map( 'sanitize_html_class', \preg_split( '/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY ) ?: array() )
		);

		return array(
			'category' => $category,
			'per_page' => max( 1, min( 48, (int) $atts['per-page'] ) ),
			'search'   => \sanitize_text_field( (string) $atts['search'] ),
			'show'     => $show,
			'layout'   => $layout,
			'columns'  => $columns,
			// FILTER_VALIDATE_BOOLEAN (not a truthy cast) so filters="0" or
			// filters="no" behave as false too, not just the literal string
			// "false" — shortcode attributes are always strings, and authors
			// spell "off" a few different ways.
			'filters'  => \filter_var( $atts['filters'], FILTER_VALIDATE_BOOLEAN ),
			'related'  => \filter_var( $atts['related'], FILTER_VALIDATE_BOOLEAN ),
			'class'    => \implode( ' ', $classes ),
		);
	}

	/**
	 * Resolves the "current event" a related="true" section is relative to.
	 *
	 * Only meaningful when this shortcode renders on an event's own
	 * singular template — is_singular() is true there and
	 * get_queried_object_id() is that event's post ID. Anywhere else (a
	 * page, a blog post, an archive), there is no source event to relate
	 * to, so this returns null and render() falls back to a plain listing
	 * instead of erroring or rendering nothing: a shortcode that breaks
	 * when moved to the wrong template is worse than one that degrades to
	 * something sensible.
	 *
	 * @return int|null
	 */
	private function resolve_related_to(): ?int {
		if ( ! \is_singular( Post_Type::post_type() ) ) {
			return null;
		}

		$id = \get_queried_object_id();

		return $id > 0 ? $id : null;
	}

	/**
	 * Encodes the repository result for the inline `<script>` payload.
	 *
	 * @param array $result Events_Repository::get_events() return value.
	 * @return string
	 */
	private function payload_json( array $result ): string {
		$payload = array(
			'events' => $result['events'],
			'total'  => $result['total'],
			'pages'  => $result['pages'],
		);

		// JSON_HEX_TAG escapes "<" and ">" as their \u escape sequences —
		// without it, a literal "</script>" inside an event's description
		// would close this tag early and spill the rest of the JSON into
		// the page as visible text.
		$json = \wp_json_encode(
			$payload,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return false !== $json ? $json : '{"events":[],"total":0,"pages":0}';
	}

	/**
	 * Builds the `<noscript>` fallback: a plain list of links to each
	 * event's own permalink. This is why Post_Type registers a public
	 * post type — the single-event template is the baseline the modal
	 * enhances, not an afterthought.
	 *
	 * @param array $events Normalised events (see Events_Repository::normalise()).
	 * @return string
	 */
	private function fallback_html( array $events ): string {
		if ( empty( $events ) ) {
			return '';
		}

		ob_start();
		?>
		<noscript>
			<ul class="es-events__fallback">
				<?php foreach ( $events as $event ) : ?>
					<li>
						<a href="<?php echo esc_url( $event['permalink'] ); ?>">
							<?php echo esc_html( $event['title'] ); ?>
						</a>
						<?php if ( ! empty( $event['start_datetime'] ) ) : ?>
							<time datetime="<?php echo esc_attr( $event['start_datetime'] ); ?>">
								<?php echo esc_html( $this->format_date( $event['start_datetime'] ) ); ?>
							</time>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</noscript>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Formats an ISO 8601 datetime for human display, in the site's own
	 * timezone rather than a hardcoded one, and in whichever date format
	 * Settings::get( 'date_format' ) resolves to.
	 *
	 * @param string $iso8601 ISO 8601 datetime string.
	 * @return string
	 */
	private function format_date( string $iso8601 ): string {
		$timestamp = \strtotime( $iso8601 );
		if ( ! $timestamp ) {
			return '';
		}

		// wp_date() (not date()) applies the site's timezone setting
		// rather than the server's.
		return \wp_date( $this->date_format() . ' ' . \get_option( 'time_format' ), $timestamp );
	}

	/**
	 * The PHP date() format string for the current date_format setting.
	 * 'site' is the only one that reads an option — 'short'/'long' are
	 * fixed formats, not tied to whatever WordPress's own setting is.
	 *
	 * @return string
	 */
	private function date_format(): string {
		$map = array(
			'short' => 'j M Y',
			'long'  => 'j F Y',
		);

		return $map[ Settings::get( 'date_format' ) ] ?? \get_option( 'date_format' );
	}
}
