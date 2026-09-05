<?php
/**
 * Plugin Name:       Events Showcase
 * Description:       Interactive events grid with filtering, search, and a details modal, built in React + Vite and exposed via the [events_showcase] shortcode.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Yasiru Wijewardena
 * License:           GPL-2.0-or-later
 * Text Domain:       events-showcase
 *
 * @package EventsShowcase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EVENTS_SHOWCASE_VERSION', '0.1.0' );
define( 'EVENTS_SHOWCASE_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVENTS_SHOWCASE_URL', plugin_dir_url( __FILE__ ) );

require_once EVENTS_SHOWCASE_DIR . 'includes/class-post-type.php';
require_once EVENTS_SHOWCASE_DIR . 'includes/class-acf-fields.php';
require_once EVENTS_SHOWCASE_DIR . 'includes/class-shortcode.php';
require_once EVENTS_SHOWCASE_DIR . 'includes/class-assets.php';

/**
 * Boot the plugin. Each class wires up its own hooks in its constructor,
 * keeping the WordPress/PHP integration layer separate from the React app
 * (react-app/) and organized by concern.
 */
function events_showcase_bootstrap() {
	new Events_Showcase_Post_Type();
	new Events_Showcase_ACF_Fields();
	new Events_Showcase_Shortcode();
	new Events_Showcase_Assets();
}
add_action( 'plugins_loaded', 'events_showcase_bootstrap' );

/**
 * Flush rewrite rules on activation/deactivation so the "event" CPT's
 * permalinks and REST routes work immediately.
 */
function events_showcase_activate() {
	require_once EVENTS_SHOWCASE_DIR . 'includes/class-post-type.php';
	( new Events_Showcase_Post_Type() )->register();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'events_showcase_activate' );

function events_showcase_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'events_showcase_deactivate' );
