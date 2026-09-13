<?php
/**
 * Site-wide default settings: a submenu page under the Events CPT menu,
 * built entirely on the WordPress Settings API.
 *
 * @package Events_Showcase
 */

namespace Events_Showcase;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the settings page, the single option it writes to, and the
 * static accessor every other class reads defaults from.
 *
 * Precedence is strictly shortcode attribute → saved option → hardcoded
 * default. This class only owns the middle step; Shortcode::parse_atts()
 * and REST_Controller::events_args() each pull the option in as their own
 * `shortcode_atts()`/args-schema default, which gets the ordering right
 * without this class needing to know anything about shortcodes or REST.
 * Events_Repository deliberately never calls Settings::get() itself — it
 * takes explicit, already-resolved arguments and stays a pure data layer;
 * resolving defaults is each caller's job, not the query layer's.
 */
class Settings {

	/**
	 * The one option row everything here reads from and writes to.
	 */
	const OPTION_NAME = 'events_showcase_settings';

	/**
	 * Settings API "option group" — ties settings_fields() on the page to
	 * register_setting() below.
	 */
	const OPTION_GROUP = 'events_showcase_settings_group';

	/**
	 * The submenu page's slug.
	 */
	const PAGE_SLUG = 'events-showcase-settings';

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		\add_action( 'admin_menu', array( $this, 'register_menu' ) );
		\add_action( 'admin_init', array( $this, 'register_settings' ) );

		$plugin_file = \plugin_basename( EVENTS_SHOWCASE_DIR . 'events-showcase.php' );
		\add_filter( 'plugin_action_links_' . $plugin_file, array( $this, 'add_settings_link' ) );
	}

	/**
	 * Hardcoded defaults. The saved option is merged over this, so a
	 * fresh install (or a saved option missing a key an earlier version
	 * didn't have) always has a complete, valid set of values.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaults(): array {
		return array(
			'default_show'     => 'upcoming',
			'default_per_page' => 12,
			'default_layout'   => 'grid',
			'default_columns'  => 3,
			'default_duration' => 120,
			'date_format'      => 'site',
		);
	}

	/**
	 * The saved option merged over the hardcoded defaults. Every other
	 * class reads settings through get(), not get_option() directly, so
	 * there's exactly one place that knows the default values and exactly
	 * one place a missing key gets filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_all(): array {
		$saved = \get_option( self::OPTION_NAME, array() );

		// wp_parse_args() needs an array to merge — a corrupted or
		// hand-edited option value shouldn't be able to fatal every class
		// that calls get(), it should just behave like "nothing saved yet."
		if ( ! \is_array( $saved ) ) {
			$saved = array();
		}

		return \wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * A single setting's effective value.
	 *
	 * @param string $key One of the keys defaults() defines.
	 * @return mixed|null Null only for a key that isn't a real setting.
	 */
	public static function get( string $key ) {
		$all = self::get_all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Adds the submenu page under the Events CPT's own menu — not a
	 * top-level item, and not under Settings, since these are defaults
	 * for a specific content type's shortcode, not site-wide options.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		\add_submenu_page(
			'edit.php?post_type=' . Post_Type::post_type(),
			__( 'Events Showcase Settings', 'events-showcase' ),
			__( 'Settings', 'events-showcase' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a "Settings" link to this plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_settings_link( array $links ): array {
		$url = \admin_url( 'edit.php?post_type=' . Post_Type::post_type() . '&page=' . self::PAGE_SLUG );

		\array_unshift(
			$links,
			\sprintf( '<a href="%s">%s</a>', \esc_url( $url ), \esc_html__( 'Settings', 'events-showcase' ) )
		);

		return $links;
	}

	/**
	 * Registers the option, its sanitize callback, and every field —
	 * entirely through the Settings API. No manual form handling, nonce
	 * checking, or $_POST reading: settings_fields()/options.php do all
	 * of that, correctly, on their own.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		\register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		\add_settings_section(
			'display_defaults',
			__( 'Display defaults', 'events-showcase' ),
			array( $this, 'render_display_section' ),
			self::PAGE_SLUG
		);

		\add_settings_field(
			'default_show',
			__( 'Default "show" filter', 'events-showcase' ),
			array( $this, 'field_default_show' ),
			self::PAGE_SLUG,
			'display_defaults'
		);

		\add_settings_field(
			'default_per_page',
			__( 'Events per page', 'events-showcase' ),
			array( $this, 'field_default_per_page' ),
			self::PAGE_SLUG,
			'display_defaults'
		);

		\add_settings_field(
			'default_layout',
			__( 'Layout', 'events-showcase' ),
			array( $this, 'field_default_layout' ),
			self::PAGE_SLUG,
			'display_defaults'
		);

		\add_settings_field(
			'default_columns',
			__( 'Columns', 'events-showcase' ),
			array( $this, 'field_default_columns' ),
			self::PAGE_SLUG,
			'display_defaults'
		);

		\add_settings_section(
			'event_defaults',
			__( 'Event defaults', 'events-showcase' ),
			array( $this, 'render_event_section' ),
			self::PAGE_SLUG
		);

		\add_settings_field(
			'default_duration',
			__( 'Default duration', 'events-showcase' ),
			array( $this, 'field_default_duration' ),
			self::PAGE_SLUG,
			'event_defaults'
		);

		\add_settings_field(
			'date_format',
			__( 'Date format', 'events-showcase' ),
			array( $this, 'field_date_format' ),
			self::PAGE_SLUG,
			'event_defaults'
		);
	}

	/**
	 * Validates and returns the *whole* settings array — never trusts an
	 * individual field to have covered a partial or tampered POST, since
	 * this callback is what actually gets saved as the option value.
	 * Every field falls back to its own default on an invalid value
	 * rather than rejecting the save outright: a malformed POST here
	 * isn't a user-facing error condition, just a value to discard.
	 *
	 * @param mixed $value Raw value from the settings form.
	 * @return array<string, mixed>
	 */
	public function sanitize( $value ): array {
		$value    = \is_array( $value ) ? $value : array();
		$defaults = self::defaults();

		return array(
			'default_show'     => $this->sanitize_enum(
				$value['default_show'] ?? null,
				array( 'upcoming', 'past', 'all' ),
				$defaults['default_show']
			),
			'default_per_page' => $this->sanitize_range( $value['default_per_page'] ?? null, 1, 48, $defaults['default_per_page'] ),
			'default_layout'   => $this->sanitize_enum(
				$value['default_layout'] ?? null,
				array( 'grid', 'list', 'compact' ),
				$defaults['default_layout']
			),
			'default_columns'  => $this->sanitize_int_enum( $value['default_columns'] ?? null, array( 2, 3, 4 ), $defaults['default_columns'] ),
			'default_duration' => $this->sanitize_range( $value['default_duration'] ?? null, 5, 1440, $defaults['default_duration'] ),
			'date_format'      => $this->sanitize_enum(
				$value['date_format'] ?? null,
				array( 'site', 'short', 'long' ),
				$defaults['date_format']
			),
		);
	}

	/**
	 * @param mixed  $value    Raw value.
	 * @param string[] $allowed Allowed values.
	 * @param string $fallback Value to use when $value isn't allowed.
	 * @return string
	 */
	private function sanitize_enum( $value, array $allowed, string $fallback ): string {
		$value = \is_scalar( $value ) ? \sanitize_key( (string) $value ) : '';
		return \in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Same as sanitize_enum() but for an integer-valued enum
	 * (default_columns), where the allowed set is small, known numbers
	 * rather than arbitrary strings.
	 *
	 * @param mixed $value    Raw value.
	 * @param int[] $allowed  Allowed values.
	 * @param int   $fallback Value to use when $value isn't allowed.
	 * @return int
	 */
	private function sanitize_int_enum( $value, array $allowed, int $fallback ): int {
		$value = \is_numeric( $value ) ? (int) $value : null;
		return \in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * @param mixed $value    Raw value.
	 * @param int   $min      Minimum, inclusive.
	 * @param int   $max      Maximum, inclusive.
	 * @param int   $fallback Value to use when $value isn't numeric at all.
	 * @return int
	 */
	private function sanitize_range( $value, int $min, int $max, int $fallback ): int {
		if ( ! \is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * @return void
	 */
	public function render_display_section(): void {
		echo '<p>' . \esc_html__( 'Defaults for [events_showcase] when an attribute is omitted.', 'events-showcase' ) . '</p>';
	}

	/**
	 * @return void
	 */
	public function render_event_section(): void {
		echo '<p>' . \esc_html__( 'Defaults applied when editing an individual event.', 'events-showcase' ) . '</p>';
	}

	/**
	 * @return void
	 */
	public function field_default_show(): void {
		$value = self::get( 'default_show' );
		$name  = self::OPTION_NAME . '[default_show]';
		?>
		<select name="<?php echo \esc_attr( $name ); ?>">
			<option value="upcoming" <?php \selected( $value, 'upcoming' ); ?>><?php \esc_html_e( 'Upcoming', 'events-showcase' ); ?></option>
			<option value="past" <?php \selected( $value, 'past' ); ?>><?php \esc_html_e( 'Past', 'events-showcase' ); ?></option>
			<option value="all" <?php \selected( $value, 'all' ); ?>><?php \esc_html_e( 'All', 'events-showcase' ); ?></option>
		</select>
		<p class="description">
			<?php \esc_html_e( 'Which events show when the shortcode\'s own show="" attribute is omitted.', 'events-showcase' ); ?>
		</p>
		<?php
	}

	/**
	 * @return void
	 */
	public function field_default_per_page(): void {
		$value = self::get( 'default_per_page' );
		$name  = self::OPTION_NAME . '[default_per_page]';
		?>
		<input
			type="number"
			min="1"
			max="48"
			class="small-text"
			name="<?php echo \esc_attr( $name ); ?>"
			value="<?php echo \esc_attr( (string) $value ); ?>"
		>
		<p class="description">
			<?php \esc_html_e( 'Clamped 1–48, matching the shortcode\'s own per-page="" limit.', 'events-showcase' ); ?>
		</p>
		<?php
	}

	/**
	 * @return void
	 */
	public function field_default_layout(): void {
		$value = self::get( 'default_layout' );
		$name  = self::OPTION_NAME . '[default_layout]';
		?>
		<select id="events_showcase_default_layout" name="<?php echo \esc_attr( $name ); ?>">
			<option value="grid" <?php \selected( $value, 'grid' ); ?>><?php \esc_html_e( 'Grid', 'events-showcase' ); ?></option>
			<option value="list" <?php \selected( $value, 'list' ); ?>><?php \esc_html_e( 'List', 'events-showcase' ); ?></option>
			<option value="compact" <?php \selected( $value, 'compact' ); ?>><?php \esc_html_e( 'Compact', 'events-showcase' ); ?></option>
		</select>
		<?php
	}

	/**
	 * @return void
	 */
	public function field_default_columns(): void {
		$value = self::get( 'default_columns' );
		$name  = self::OPTION_NAME . '[default_columns]';
		?>
		<select id="events_showcase_default_columns" name="<?php echo \esc_attr( $name ); ?>">
			<option value="2" <?php \selected( $value, 2 ); ?>>2</option>
			<option value="3" <?php \selected( $value, 3 ); ?>>3</option>
			<option value="4" <?php \selected( $value, 4 ); ?>>4</option>
		</select>
		<p class="description">
			<?php \esc_html_e( 'Only meaningful when the layout is Grid — List and Compact ignore this.', 'events-showcase' ); ?>
		</p>
		<?php
	}

	/**
	 * @return void
	 */
	public function field_default_duration(): void {
		$value = self::get( 'default_duration' );
		$name  = self::OPTION_NAME . '[default_duration]';
		?>
		<input
			type="number"
			min="5"
			max="1440"
			step="5"
			class="small-text"
			name="<?php echo \esc_attr( $name ); ?>"
			value="<?php echo \esc_attr( (string) $value ); ?>"
		>
		<?php \esc_html_e( 'minutes', 'events-showcase' ); ?>
		<p class="description">
			<?php \esc_html_e( 'Pre-fills the end time in the event editor when a start time is set and no end time is given yet — a suggestion, not a value saved until the event is.', 'events-showcase' ); ?>
		</p>
		<?php
	}

	/**
	 * @return void
	 */
	public function field_date_format(): void {
		$value = self::get( 'date_format' );
		$name  = self::OPTION_NAME . '[date_format]';
		?>
		<select name="<?php echo \esc_attr( $name ); ?>">
			<option value="site" <?php \selected( $value, 'site' ); ?>><?php \esc_html_e( 'Match site setting', 'events-showcase' ); ?></option>
			<option value="short" <?php \selected( $value, 'short' ); ?>><?php \esc_html_e( 'Short (14 Oct 2026)', 'events-showcase' ); ?></option>
			<option value="long" <?php \selected( $value, 'long' ); ?>><?php \esc_html_e( 'Long (14 October 2026)', 'events-showcase' ); ?></option>
		</select>
		<p class="description">
			<?php \esc_html_e( '"Match site setting" uses WordPress\'s own Settings → General date format.', 'events-showcase' ); ?>
		</p>
		<?php
	}

	/**
	 * @return void
	 */
	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo \esc_html( \get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				\settings_fields( self::OPTION_GROUP );
				\do_settings_sections( self::PAGE_SLUG );
				\submit_button();
				?>
			</form>
		</div>
		<?php
		$this->print_columns_visibility_script();
	}

	/**
	 * Hides the Columns field's entire row while Layout isn't "Grid" —
	 * the field's own description already says it's ignored otherwise,
	 * this just stops showing a control that would do nothing. Plain
	 * inline JS scoped to this one page, not a separate enqueued file:
	 * with JS disabled the row simply stays visible, which is still
	 * correct (just less tidy), so there's no functional fallback to get
	 * wrong.
	 *
	 * @return void
	 */
	private function print_columns_visibility_script(): void {
		?>
		<script>
		( function () {
			var layout = document.getElementById( 'events_showcase_default_layout' );
			var columns = document.getElementById( 'events_showcase_default_columns' );
			var row = columns ? columns.closest( 'tr' ) : null;

			if ( ! layout || ! row ) {
				return;
			}

			var sync = function () {
				row.style.display = ( 'grid' === layout.value ) ? '' : 'none';
			};

			layout.addEventListener( 'change', sync );
			sync();
		} )();
		</script>
		<?php
	}
}
