<?php
/**
 * Plugin Name:       Events Showcase
 * Description:       Headless events data layer — custom post type, a built-in fields UI (or ACF, if installed), and a REST API — for a React-driven events grid mounted via shortcode.
 * Version:           1.7.1
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Yasiru Wijewardena
 * License:           GPL-2.0-or-later
 * Text Domain:       events-showcase
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package Events_Showcase
 */

namespace Events_Showcase;

defined( 'ABSPATH' ) || exit;

define( 'EVENTS_SHOWCASE_VERSION', '1.7.1' );
define( 'EVENTS_SHOWCASE_DIR', \plugin_dir_path( __FILE__ ) );
define( 'EVENTS_SHOWCASE_URL', \plugin_dir_url( __FILE__ ) );

require_once EVENTS_SHOWCASE_DIR . 'includes/class-post-type.php';
require_once EVENTS_SHOWCASE_DIR . 'includes/class-settings.php';
require_once EVENTS_SHOWCASE_DIR . 'includes/class-acf-fields.php';
require_once EVENTS_SHOWCASE_DIR . 'includes/class-meta-box.php';
require_once EVENTS_SHOWCASE_DIR . 'includes/class-events-repository.php';
require_once EVENTS_SHOWCASE_DIR . 'includes/class-rest-controller.php';
require_once EVENTS_SHOWCASE_DIR . 'includes/class-shortcode.php';
require_once EVENTS_SHOWCASE_DIR . 'includes/class-schema.php';
require_once EVENTS_SHOWCASE_DIR . 'includes/class-assets.php';

/**
 * Instantiates every class the plugin needs. Each class wires up its own
 * hooks in its constructor, so this function is just a registry of "what
 * exists" — no ordering logic beyond what plugins_loaded already gives us.
 *
 * @return void
 */
/**
 * Loads the plugin's own translations from /languages.
 *
 * Needed because this plugin isn't distributed through wordpress.org —
 * translations for plugins that are get installed and loaded by core
 * automatically, and a bundled /languages folder does not. Without this
 * call every __() in the plugin silently returns English no matter what
 * the site's locale is or what .mo files ship alongside it.
 *
 * Hooked to `init` rather than called during `plugins_loaded`: since
 * WP 6.7, loading a text domain before `init` triggers a
 * _doing_it_wrong() notice, because translations can't be resolved until
 * the locale is settled.
 *
 * @return void
 */
function load_textdomain() {
	\load_plugin_textdomain(
		'events-showcase',
		false,
		\dirname( \plugin_basename( __FILE__ ) ) . '/languages'
	);
}
\add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

function bootstrap() {
	new Post_Type();
	new Settings();

	// Exactly one of these two actually registers anything: each checks
	// ACF's presence itself and no-ops if it's on the wrong side of that
	// check, so only one "Event Details" UI ever appears on the edit
	// screen regardless of which plugins are active.
	new ACF_Fields();
	new Meta_Box();

	// Shared between the two so Events_Repository's cache-busting hooks
	// (save_post, deleted_post, set_object_terms) are registered once, not
	// twice.
	$repository = new Events_Repository();
	new REST_Controller( $repository );
	new Shortcode( $repository );
	new Schema( $repository );

	// No repository needed — Assets only reads the build manifest and
	// checks the current request's post content, neither of which touches
	// event data.
	new Assets();
}
\add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/**
 * Registers the post type immediately — activation runs before the next
 * `init`, but rewrite rules can only be flushed against types that are
 * already registered — then flushes so the new permalinks work right away.
 *
 * @return void
 */
function activate() {
	( new Post_Type() )->register();
	\flush_rewrite_rules();
}
\register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Flushes rewrite rules again on deactivation so the post type's rules
 * don't linger and shadow whatever permalink structure comes next.
 *
 * @return void
 */
function deactivate() {
	\flush_rewrite_rules();
}
\register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
