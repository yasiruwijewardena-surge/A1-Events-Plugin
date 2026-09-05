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

		$result = $this->repository->get_events(
			array(
				'category' => $args['category'],
				'per_page' => $args['per_page'],
				'page'     => 1,
				'search'   => $args['search'],
			)
		);

		$wrapper_id = 'es-events-' . $instance;
		$payload_id = 'es-events-data-' . $instance;

		ob_start();
		?>
		<div class="es-events" id="<?php echo esc_attr( $wrapper_id ); ?>">
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
	 * @param array $atts Raw shortcode attributes.
	 * @return array{category: string, per_page: int, search: string}
	 */
	private function parse_atts( array $atts ): array {
		$atts = \shortcode_atts(
			array(
				'category' => '',
				'per-page' => 12,
				'search'   => '',
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

		return array(
			'category' => $category,
			'per_page' => max( 1, min( 48, (int) $atts['per-page'] ) ),
			'search'   => \sanitize_text_field( (string) $atts['search'] ),
		);
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
	 * date/time format and timezone rather than a hardcoded one.
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
		return \wp_date( \get_option( 'date_format' ) . ' ' . \get_option( 'time_format' ), $timestamp );
	}
}
