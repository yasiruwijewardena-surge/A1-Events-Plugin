<?php
/**
 * Reads Vite's build manifest and enqueues the React app's JS/CSS, scoped
 * to requests that will actually render the [events_showcase] shortcode.
 *
 * @package Events_Showcase
 */

namespace Events_Showcase;

defined( 'ABSPATH' ) || exit;

/**
 * Asset enqueueing for the shortcode's React app.
 *
 * Scoping is decided by should_enqueue(), which checks has_shortcode()
 * against the queried post's content. That check has a known blind spot:
 * it can't see the shortcode when it's added via a widget, a reusable
 * block or synced pattern, a page-builder field, or a template's
 * do_shortcode() call, since none of those store their content in
 * $post->post_content. Sites that need assets loaded in one of those
 * cases should hook the `events_showcase_enqueue_assets` filter — see
 * should_enqueue() below — and return true. For example, to force assets
 * on for a specific page regardless of what has_shortcode() sees:
 *
 *     add_filter(
 *         'events_showcase_enqueue_assets',
 *         function ( $should, $post ) {
 *             if ( $post && 42 === $post->ID ) {
 *                 return true;
 *             }
 *             return $should;
 *         },
 *         10,
 *         2
 *     );
 *
 * Drop that in a theme's functions.php (or a small site-specific plugin),
 * swapping 42 for the ID of the page whose template calls
 * do_shortcode( '[events_showcase]' ) or embeds it via a page builder.
 */
class Assets {

	/**
	 * Script/style handle. Shared as a base for the per-file style handles
	 * enqueued in enqueue().
	 */
	const HANDLE = 'events-showcase-app';

	/**
	 * Manifest path, relative to the plugin root. `.vite/manifest.json`
	 * is where Vite 5+ writes it when `build.manifest` is enabled.
	 */
	const MANIFEST_PATH = 'assets/build/.vite/manifest.json';

	/**
	 * Manifest key for the app's entry point (see react-app/vite.config.js).
	 */
	const ENTRY = 'src/main.jsx';

	/**
	 * Decoded manifest, cached after the first read so a page with the
	 * shortcode doesn't hit disk twice in one request. `null` covers both
	 * "not read yet" and "read but missing/invalid" — self::$read
	 * disambiguates the two.
	 *
	 * @var array|null
	 */
	private static $manifest = null;

	/**
	 * Whether manifest() has already attempted a read this request.
	 *
	 * @var bool
	 */
	private static $read = false;

	/**
	 * Calls register() to wire up hooks, same as the plugin's other classes.
	 */
	public function __construct() {
		$this->register();
	}

	/**
	 * Wires up the enqueue and admin-notice hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		\add_action( 'admin_notices', array( $this, 'admin_notice' ) );
	}

	/**
	 * Enqueues the app's JS and CSS, if this request needs them.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		if ( $this->is_dev_mode() ) {
			$this->enqueue_dev();
			return;
		}

		$entry = $this->entry();
		if ( ! $entry || empty( $entry['file'] ) ) {
			// Missing/malformed manifest: bail without a fatal or a
			// front-end notice. admin_notice() surfaces this to editors.
			return;
		}

		$build_url = EVENTS_SHOWCASE_URL . 'assets/build/';

		// null (not false, the parameter default) tells WP_Scripts::do_item()
		// to skip appending a version query string entirely. Vite's
		// filenames are already content-hashed, so a ?ver= would be
		// redundant and would defeat the immutable-asset caching the
		// hashing exists to enable.
		//
		// The 'react'/'react-dom' dependencies are the point of the build's
		// externalisation: WordPress registers both handles and has shipped
		// React 18 since 6.2, so the bundle resolves React from
		// window.React/window.ReactDOM rather than carrying its own copy
		// (see react-app/vite.config.js). That took the bundle from ~155 kB
		// to ~14 kB. Declaring them here is what guarantees they are
		// printed — and printed first — since WP_Scripts resolves the whole
		// dependency graph before emitting anything.
		//
		// They stay classic scripts: filter_script_tag() below only adds
		// type="module" to this plugin's own handle, which matters, because
		// React's UMD builds assign to window and would export nothing at
		// all if loaded as modules.
		\wp_enqueue_script(
			self::HANDLE,
			$build_url . $entry['file'],
			array( 'react', 'react-dom' ),
			null,
			true
		);
		\add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 10, 2 );

		foreach ( array_unique( $this->collect_css( $entry ) ) as $css_file ) {
			// The handle is derived from the hashed filename, not an
			// array index: once collect_css() walks imported chunks, its
			// traversal order — and therefore any index — can change
			// between builds even when a given file's contents haven't,
			// which would break anything depending on a stable handle.
			$handle = 'events-showcase-' . \sanitize_key( \basename( $css_file, '.css' ) );

			// collect_css()'s array_unique() only catches duplicate paths;
			// two distinct paths could still sanitize to the same handle.
			if ( \wp_style_is( $handle, 'enqueued' ) ) {
				continue;
			}

			\wp_enqueue_style( $handle, $build_url . $css_file, array(), null );
		}

		\add_action( 'wp_head', array( $this, 'preload_imports' ) );
	}

	/**
	 * Reads and decodes the manifest once per request.
	 *
	 * @return array|null Decoded manifest, or null if missing/malformed.
	 */
	private function manifest(): ?array {
		if ( self::$read ) {
			return self::$manifest;
		}
		self::$read = true;

		$path = EVENTS_SHOWCASE_DIR . self::MANIFEST_PATH;
		if ( ! \file_exists( $path ) ) {
			return self::$manifest; // stays null.
		}

		$decoded = json_decode( (string) \file_get_contents( $path ), true );

		self::$manifest = ( \JSON_ERROR_NONE === json_last_error() && \is_array( $decoded ) )
			? $decoded
			: null;

		return self::$manifest;
	}

	/**
	 * The entry point's manifest chunk.
	 *
	 * @return array|null
	 */
	private function entry(): ?array {
		$manifest = $this->manifest();
		return $manifest[ self::ENTRY ] ?? null;
	}

	/**
	 * Collects a chunk's own CSS plus its imported chunks' CSS,
	 * recursively. Vite splits CSS across chunks as an app grows, so
	 * reading only the entry's `css` array misses anything pulled in
	 * through a lazy-loaded or shared chunk.
	 *
	 * @param array $chunk Manifest chunk (entry or an imported chunk).
	 * @param array $seen  Import keys already walked, to avoid re-walking
	 *                     a chunk reachable through more than one path.
	 * @return string[]
	 */
	private function collect_css( array $chunk, array $seen = array() ): array {
		$manifest = $this->manifest() ?? array();
		$css      = $chunk['css'] ?? array();

		foreach ( $chunk['imports'] ?? array() as $import_key ) {
			if ( isset( $seen[ $import_key ] ) || ! isset( $manifest[ $import_key ] ) ) {
				continue;
			}
			$seen[ $import_key ] = true;
			$css                 = array_merge( $css, $this->collect_css( $manifest[ $import_key ], $seen ) );
		}

		return $css;
	}

	/**
	 * Decides whether the current request should load the app's assets.
	 *
	 * @return bool
	 */
	private function should_enqueue(): bool {
		$post   = \is_singular() ? \get_queried_object() : null;
		$should = $post instanceof \WP_Post && \has_shortcode( $post->post_content, 'events_showcase' );

		/**
		 * Filters whether Events Showcase's JS/CSS are enqueued on the
		 * current request. Use this to force assets on for a shortcode
		 * rendered somewhere has_shortcode() can't see — a widget, a
		 * reusable block or synced pattern, a template's do_shortcode()
		 * call, or a page builder that stores content outside
		 * $post->post_content. See the class docblock above for a
		 * copy-pasteable example.
		 *
		 * @param bool          $should Whether assets should be enqueued.
		 * @param \WP_Post|null $post   The queried post, or null if not singular.
		 */
		return (bool) \apply_filters( 'events_showcase_enqueue_assets', $should, $post );
	}

	/**
	 * Adds `type="module"` to this plugin's script tag only.
	 *
	 * WordPress prints a plain `<script src>`, which throws on the
	 * bundle's first `import` statement. wp_enqueue_script_module()
	 * (WP 6.5+) avoids needing this filter at all, but this plugin
	 * declares a 6.2 floor per its header — raising that floor to 6.5
	 * would be a reasonable trade to make deliberately, but isn't this
	 * class's call to make on its own.
	 *
	 * Guarded on this plugin's own handle, which is load-bearing now that
	 * 'react' and 'react-dom' are dependencies: those are UMD builds that
	 * assign to window, and a UMD script evaluated as a module gets its
	 * own scope, so window.React would never be set and the bundle would
	 * fail on the first hook call.
	 *
	 * @param string $tag    The `<script>` tag markup.
	 * @param string $handle The script's registered handle.
	 * @return string
	 */
	public function filter_script_tag( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}

		// Strip whatever type WordPress already printed rather than
		// bailing when one is present: core emits type='text/javascript'
		// unless the active theme declares add_theme_support( 'html5',
		// 'script' ), which most themes (including Divi) don't — so a
		// "skip if type= exists" guard would make this filter a silent
		// no-op and ship the bundle as a classic script that throws on
		// its first `import`.
		$tag = preg_replace( "/\s+type=(['\"])[^'\"]*\\1/", '', $tag );

		return str_replace( ' src=', ' type="module" src=', $tag );
	}

	/**
	 * Prints `<link rel="modulepreload">` for the entry's static imports.
	 *
	 * This lets the browser start fetching the entry's dependent chunks
	 * in parallel with downloading and parsing the entry script itself,
	 * instead of discovering each import serially as the module graph is
	 * evaluated.
	 *
	 * @return void
	 */
	public function preload_imports(): void {
		$entry = $this->entry();
		if ( ! $entry || empty( $entry['imports'] ) ) {
			return;
		}

		$manifest  = $this->manifest() ?? array();
		$build_url = EVENTS_SHOWCASE_URL . 'assets/build/';

		foreach ( $entry['imports'] as $import_key ) {
			if ( empty( $manifest[ $import_key ]['file'] ) ) {
				continue;
			}

			printf(
				'<link rel="modulepreload" href="%s">' . "\n",
				\esc_url( $build_url . $manifest[ $import_key ]['file'] )
			);
		}
	}

	/**
	 * Warns site admins — never front-end visitors — when the build
	 * output hasn't been generated yet.
	 *
	 * @return void
	 */
	public function admin_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->is_dev_mode() || null !== $this->manifest() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			\esc_html__( 'Events Showcase: the React build output is missing. Run `npm run build` in the react-app/ folder to generate it.', 'events-showcase' )
		);
	}

	/**
	 * Whether to load assets from the Vite dev server instead of the
	 * built manifest. Opt-in only, via a constant defined in wp-config.php
	 * — never on by default, so a stray dev flag can't ship to production.
	 *
	 * @return bool
	 */
	private function is_dev_mode(): bool {
		return \defined( 'EVENTS_SHOWCASE_DEV' ) && EVENTS_SHOWCASE_DEV;
	}

	/**
	 * Points the page at the Vite dev server instead of a build, for hot
	 * module replacement during development. React Fast Refresh needs its
	 * preamble evaluated before React itself loads, hence the inline
	 * script ahead of @vite/client.
	 *
	 * @return void
	 */
	private function enqueue_dev(): void {
		\add_action(
			'wp_head',
			static function () {
				?>
				<script type="module">
					import RefreshRuntime from 'http://localhost:5173/@react-refresh';
					RefreshRuntime.injectIntoGlobalHook( window );
					window.$RefreshReg$ = () => {};
					window.$RefreshSig$ = () => ( type ) => type;
					window.__vite_plugin_react_preamble_installed__ = true;
				</script>
				<script type="module" src="http://localhost:5173/@vite/client"></script>
				<script type="module" src="http://localhost:5173/src/main.jsx"></script>
				<?php
			}
		);
	}
}
