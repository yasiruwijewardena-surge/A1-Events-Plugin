<?php
/**
 * Registers the [events_showcase] shortcode and renders the mount element
 * that the React app (react-app/) attaches to. Also flags, on render,
 * that Events_Showcase_Assets should enqueue the build for this page —
 * so the JS/CSS never load on pages that don't use the shortcode.
 *
 * @package EventsShowcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Events_Showcase_Shortcode {

	/**
	 * Set to true the first time the shortcode renders on a request.
	 * Read by Events_Showcase_Assets to decide whether to enqueue.
	 *
	 * @var bool
	 */
	public static $rendered = false;

	public function __construct() {
		add_shortcode( 'events_showcase', array( $this, 'render' ) );
	}

	/**
	 * @param array $atts Shortcode attributes, e.g.
	 *                    [events_showcase per_page="9" category="workshops"]
	 */
	public function render( $atts ) {
		self::$rendered = true;

		$atts = shortcode_atts(
			array(
				'per_page' => 12,
				'category' => '',
			),
			$atts,
			'events_showcase'
		);

		$rest_url = rest_url( 'wp/v2/events' );
		if ( ! empty( $atts['category'] ) ) {
			$rest_url = add_query_arg( 'event_category_slug', sanitize_title( $atts['category'] ), $rest_url );
		}

		ob_start();
		?>
		<div
			class="events-showcase-root"
			data-events-showcase
			data-rest-url="<?php echo esc_url( $rest_url ); ?>"
			data-per-page="<?php echo esc_attr( absint( $atts['per_page'] ) ); ?>"
		></div>
		<?php
		return ob_get_clean();
	}
}
