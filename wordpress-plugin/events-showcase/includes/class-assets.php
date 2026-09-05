<?php
/**
 * Enqueues the Vite build output (hashed JS + CSS) for the React app,
 * but only on pages/posts that actually contain [events_showcase] — so
 * the bundle never loads site-wide. Reads Vite's manifest.json rather
 * than hard-coding filenames, since production filenames are hashed.
 *
 * @package EventsShowcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Events_Showcase_Assets {

	const MANIFEST_PATH = 'assets/build/.vite/manifest.json';
	const ENTRY          = 'src/main.jsx';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function maybe_enqueue() {
		if ( ! $this->current_page_has_shortcode() ) {
			return;
		}

		$manifest = $this->read_manifest();
		if ( ! $manifest || empty( $manifest[ self::ENTRY ] ) ) {
			return;
		}

		$entry = $manifest[ self::ENTRY ];

		// JS: Vite output is an ES module, so it must be enqueued with
		// type="module" — handled via the script_loader_tag filter below.
		wp_enqueue_script(
			'events-showcase-app',
			EVENTS_SHOWCASE_URL . 'assets/build/' . $entry['file'],
			array(),
			EVENTS_SHOWCASE_VERSION,
			true
		);
		add_filter( 'script_loader_tag', array( $this, 'add_module_type' ), 10, 2 );

		// CSS: Vite emits one CSS file per entry when styles are imported
		// from JS (see react-app/src/main.jsx).
		if ( ! empty( $entry['css'] ) ) {
			foreach ( $entry['css'] as $i => $css_file ) {
				wp_enqueue_style(
					'events-showcase-app-' . $i,
					EVENTS_SHOWCASE_URL . 'assets/build/' . $css_file,
					array(),
					EVENTS_SHOWCASE_VERSION
				);
			}
		}
	}

	/**
	 * Checks whether the current request's post content contains the
	 * shortcode, so assets are scoped to pages that use it.
	 */
	private function current_page_has_shortcode() {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();
		return $post && has_shortcode( $post->post_content, 'events_showcase' );
	}

	private function read_manifest() {
		$path = EVENTS_SHOWCASE_DIR . self::MANIFEST_PATH;
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path );
		return json_decode( $contents, true );
	}

	public function add_module_type( $tag, $handle ) {
		if ( 'events-showcase-app' !== $handle ) {
			return $tag;
		}
		return str_replace( ' src', ' type="module" src', $tag );
	}
}
