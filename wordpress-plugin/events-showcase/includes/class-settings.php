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
		<?php
		// No description here — print_columns_visibility_script() already
		// hides this field's entire row whenever Layout isn't "Grid," so
		// the "only meaningful for Grid" caveat a static description would
		// state is never something a visitor actually needs to read: the
		// field simply isn't there to ask about otherwise.
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

		// Read-only navigation state, not a form submission — nothing here
		// writes anything, so there's no nonce to verify, only a value to
		// display the right tab from.
		$tab = isset( $_GET['tab'] ) ? \sanitize_key( \wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! \in_array( $tab, array( 'settings', 'help' ), true ) ) {
			$tab = 'settings';
		}
		?>
		<div class="wrap events-showcase-settings">
			<h1><?php echo \esc_html( \get_admin_page_title() ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a
					href="<?php echo \esc_url( $this->tab_url( 'settings' ) ); ?>"
					class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"
				>
					<?php \esc_html_e( 'Settings', 'events-showcase' ); ?>
				</a>
				<a
					href="<?php echo \esc_url( $this->tab_url( 'help' ) ); ?>"
					class="nav-tab <?php echo 'help' === $tab ? 'nav-tab-active' : ''; ?>"
				>
					<?php \esc_html_e( 'How to Use', 'events-showcase' ); ?>
				</a>
			</h2>

			<?php if ( 'help' === $tab ) : ?>
				<?php $this->render_help_tab(); ?>
			<?php else : ?>
				<form action="options.php" method="post">
					<?php
					\settings_fields( self::OPTION_GROUP );
					\do_settings_sections( self::PAGE_SLUG );
					\submit_button();
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
		$this->print_page_styles();
		// The elements this script looks for only exist on the Settings
		// tab — harmless to print on the Help tab too (it no-ops when they
		// aren't found), but there's no reason to.
		if ( 'settings' === $tab ) {
			$this->print_columns_visibility_script();
		}
	}

	/**
	 * Builds a same-page URL for a given tab, preserving the submenu's own
	 * post_type/page query args rather than hardcoding admin.php or
	 * assuming the current URL already has them.
	 *
	 * @param string $tab 'settings' or 'help'.
	 * @return string
	 */
	private function tab_url( string $tab ): string {
		return \add_query_arg(
			array(
				'post_type' => Post_Type::post_type(),
				'page'      => self::PAGE_SLUG,
				'tab'       => $tab,
			),
			\admin_url( 'edit.php' )
		);
	}

	/**
	 * The "How to Use" tab: how to add an event, the shortcode syntax and
	 * its full attribute table, and a few ready-to-paste recipes. Static
	 * reference content, not tied to the Settings API — plain escaped
	 * markup is all this needs, except for the attribute table's "current
	 * default" column, which is real (see help_attributes()).
	 *
	 * Kept roughly in sync with README.md; that file is the source of
	 * truth for anyone reading the repo, this is the same information
	 * surfaced to someone who only has wp-admin, not a checkout of the
	 * code.
	 *
	 * @return void
	 */
	private function render_help_tab(): void {
		$shortcode_tag = '[events_showcase]';
		?>
		<div class="es-help">
			<h2><?php \esc_html_e( 'Adding events', 'events-showcase' ); ?></h2>
			<p><?php \esc_html_e( 'Events are added under the Events menu in the sidebar', 'events-showcase' ); ?></p>
			<ul class="es-help__list">
				<li>
					<strong><?php \esc_html_e( 'Start Date & Time is required.', 'events-showcase' ); ?></strong>
					<?php \esc_html_e( 'An event saved without one will not appear in any listing"', 'events-showcase' ); ?>
				</li>
				<li>
					<strong><?php \esc_html_e( 'Featured image', 'events-showcase' ); ?></strong>
					<?php \esc_html_e( 'becomes the card and modal thumbnail. An event without one still works; the card just renders without an image.', 'events-showcase' ); ?>
				</li>
				<li>
					<strong><?php \esc_html_e( 'Event Details', 'events-showcase' ); ?></strong>
					<?php \esc_html_e( 'also holds End Date & Time (for multi-day events), All-day, Venue Name, Status, and External URL.', 'events-showcase' ); ?>
				</li>
			</ul>

			<h3><?php \esc_html_e( 'Event status', 'events-showcase' ); ?></h3>
			<p>
				<?php \esc_html_e( 'An event can be Scheduled, Postponed, or Cancelled. A postponed or cancelled event still appears in every listing it otherwise would, marked with a badge, rather than being hidden.', 'events-showcase' ); ?>
			</p>
			<p>
				<?php \esc_html_e( 'If Advanced Custom Fields is active, the same fields appear through ACF\'s own field group instead of this plugin\'s built-in box', 'events-showcase' ); ?>
			</p>

			<h2><?php \esc_html_e( 'The shortcode', 'events-showcase' ); ?></h2>
			<p>
				<?php
				$intro = \sprintf(
					/* translators: %s: the [events_showcase] shortcode tag, in a <code> element. */
					\esc_html__( 'Drop %s into any page or post to show the events grid. Every attribute below is optional — an omitted one falls back to whatever the Settings tab currently has saved. The table below shows those live values, not this plugin\'s shipped defaults.', 'events-showcase' ),
					'<code>' . \esc_html( $shortcode_tag ) . '</code>'
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $intro is built entirely from esc_html()'d/esc_html__()'d pieces above; nothing raw reaches this echo.
				echo $intro;
				?>
			</p>

			<table class="widefat striped es-help__table">
				<thead>
					<tr>
						<th><?php \esc_html_e( 'Attribute', 'events-showcase' ); ?></th>
						<th><?php \esc_html_e( 'Current default', 'events-showcase' ); ?></th>
						<th><?php \esc_html_e( 'Description', 'events-showcase' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $this->help_attributes() as $row ) : ?>
						<tr>
							<td><code><?php echo \esc_html( $row['attribute'] ); ?></code></td>
							<td><?php echo \esc_html( $row['default'] ); ?></td>
							<td><?php echo \esc_html( $row['description'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php \esc_html_e( 'Related events', 'events-showcase' ); ?></h2>
			<p>
				<?php \esc_html_e( 'related="true" shows other events that share a category with the event currently being viewed, instead of a plain listing.', 'events-showcase' ); ?>
			</p>
			<p>
				<?php \esc_html_e( 'It only finds a source event when the shortcode renders on that event\'s own singular page — anywhere else (a page, a post, an archive), it falls back to a plain listing instead of erroring.', 'events-showcase' ); ?>
			</p>
			<p>
				<?php \esc_html_e( 'If the source event has no category, or nothing else shares one, it falls back to a plain upcoming listing instead of showing an empty section.', 'events-showcase' ); ?>
			</p>
			<p>
				<?php \esc_html_e( 'Related listings are ordered by start date, the same as any other listing — not by how many categories an event shares with the source event.', 'events-showcase' ); ?>
			</p>

			<h2><?php \esc_html_e( 'Common recipes', 'events-showcase' ); ?></h2>

			<h3><?php \esc_html_e( 'Full events page', 'events-showcase' ); ?></h3>
			<p><?php \esc_html_e( 'Search, filters, and pagination all on, using whatever this Settings tab has saved:', 'events-showcase' ); ?></p>
			<pre class="es-help__example"><code>[events_showcase]</code></pre>
			<p class="description"><?php \esc_html_e( 'What this looks like: the full browsing experience — search box, category and location dropdowns, and page numbers below the grid.', 'events-showcase' ); ?></p>

			<h3><?php \esc_html_e( 'Homepage teaser', 'events-showcase' ); ?></h3>
			<p><?php \esc_html_e( 'A handful of upcoming events with no search box or pager — a glance, not a browse interface:', 'events-showcase' ); ?></p>
			<pre class="es-help__example"><code>[events_showcase filters="false" per-page="3" show="upcoming"]</code></pre>
			<p class="description"><?php \esc_html_e( 'What this looks like: three event cards and nothing else — no search box, no filter dropdowns, no page numbers.', 'events-showcase' ); ?></p>

			<h3><?php \esc_html_e( 'Related events on a single event page', 'events-showcase' ); ?></h3>
			<p><?php \esc_html_e( 'Add this to the Event Details content (or a template) on the event\'s own singular view. filters defaults to false automatically here, so it doesn\'t need to be passed:', 'events-showcase' ); ?></p>
			<pre class="es-help__example"><code>[events_showcase related="true" per-page="3"]</code></pre>
			<p class="description"><?php \esc_html_e( 'What this looks like: a card grid of related events (or a fallback upcoming listing) with the same bare footprint as the teaser above — no search box, filters, or pager.', 'events-showcase' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Row data for the Help tab's attribute table, kept separate from the
	 * markup above so the (fairly long) copy doesn't crowd the HTML.
	 *
	 * The "default" column here is a live value for any attribute backed
	 * by a Settings field (per-page, show, layout, columns) — sourced
	 * through self::get(), the same accessor Shortcode::parse_atts() uses,
	 * so this table can never show a stale shipped default once someone's
	 * actually changed the Settings tab. category, search, and related
	 * have no corresponding setting at all (by design — see the Settings
	 * class docblock and README.md's "Design decisions" section), and
	 * filters likewise has none (a per-instance presentation choice, not
	 * a site-wide policy — see the filters attribute's own description
	 * below): those four keep static values.
	 *
	 * @return array<int, array{attribute: string, default: string, description: string}>
	 */
	private function help_attributes(): array {
		return array(
			array(
				'attribute'   => 'per-page',
				'default'     => (string) self::get( 'default_per_page' ),
				'description' => \__( 'Events per page, clamped 1–48.', 'events-showcase' ),
			),
			array(
				'attribute'   => 'category',
				'default'     => \__( '(none)', 'events-showcase' ),
				'description' => \__( 'Restrict to one event category slug. Still applied when filters="false" — the visitor just can’t change it.', 'events-showcase' ),
			),
			array(
				'attribute'   => 'search',
				'default'     => \__( '(none)', 'events-showcase' ),
				'description' => \__( 'Initial search string. Still applied when filters="false", same as category.', 'events-showcase' ),
			),
			array(
				'attribute'   => 'show',
				'default'     => (string) self::get( 'default_show' ),
				'description' => \__( 'upcoming, past, or all.', 'events-showcase' ),
			),
			array(
				'attribute'   => 'layout',
				'default'     => (string) self::get( 'default_layout' ),
				'description' => \__( 'grid, list, or compact.', 'events-showcase' ),
			),
			array(
				'attribute'   => 'columns',
				'default'     => (string) self::get( 'default_columns' ),
				'description' => \__( '2, 3, or 4. Only meaningful when layout="grid".', 'events-showcase' ),
			),
			array(
				'attribute'   => 'filters',
				'default'     => 'true',
				'description' => \__( 'Shows or hides the search box, category/location dropdowns, and pagination together. Defaults to false instead when related="true", unless set explicitly. Not a Settings option — a per-instance choice.', 'events-showcase' ),
			),
			array(
				'attribute'   => 'related',
				'default'     => 'false',
				'description' => \__( 'Shows events related to the event currently being viewed instead of a plain listing. See "Related events" below.', 'events-showcase' ),
			),
		);
	}

	/**
	 * A light layer of admin CSS scoped to this one page (via the
	 * .events-showcase-settings wrapper class), printed inline rather
	 * than a separately enqueued file — render_page() only ever runs
	 * when this exact page is being viewed, so there's no risk of it
	 * leaking onto other admin screens either way. Reuses WordPress's
	 * own admin colour palette (the same greys/border colour as core's
	 * .card class) rather than inventing a new one, so the page reads as
	 * "a properly finished WP admin screen," not a custom-branded one —
	 * do_settings_sections() already emits a real .form-table per
	 * section; this just gives each one a boxed card and some breathing
	 * room instead of the bare, unboxed default.
	 *
	 * @return void
	 */
	private function print_page_styles(): void {
		?>
		<style>
			.events-showcase-settings .form-table {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 8px;
				box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
				max-width: 760px;
				margin: 0 0 2rem;
				padding: 0.5rem 2rem;
			}

			.events-showcase-settings h2 {
				margin: 2rem 0 0.75rem;
			}

			.events-showcase-settings .form-table th {
				width: 220px;
				padding-left: 0;
			}

			.events-showcase-settings .description {
				max-width: 460px;
			}

			/* Rounded to match the cards below them, rather than the sharp
			 * corners wp-admin's own default .nav-tab gives every tab. */
			.events-showcase-settings .nav-tab-wrapper {
				margin-bottom: 1.5rem;
			}

			.events-showcase-settings .nav-tab {
				border-radius: 8px 8px 0 0;
			}

			/* Native selects/number inputs default to square corners
			 * regardless of theme — set explicitly so every control on the
			 * page shares the same rounding as its surrounding card. */
			.events-showcase-settings select,
			.events-showcase-settings input[type="number"] {
				border-radius: 6px;
			}

			.events-showcase-settings .es-help {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 8px;
				box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
				max-width: 760px;
				padding: 0.5rem 2rem 1.5rem;
			}

			.events-showcase-settings .es-help h2 {
				margin: 1.75rem 0 0.75rem;
			}

			.events-showcase-settings .es-help h2:first-child {
				margin-top: 1.25rem;
			}

			.events-showcase-settings .es-help h3 {
				margin: 1.25rem 0 0.4rem;
			}

			.events-showcase-settings .es-help__table {
				margin: 0.5rem 0 1rem;
			}

			.events-showcase-settings .es-help__table code {
				white-space: nowrap;
			}

			.events-showcase-settings .es-help__example {
				background: #f6f7f7;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				padding: 0.75rem 1rem;
				margin: 0 0 0.5rem;
				overflow-x: auto;
			}

			.events-showcase-settings .es-help__list {
				margin: 0 0 1rem;
				padding-left: 1.25rem;
			}

			.events-showcase-settings .es-help__list li {
				margin-bottom: 0.5rem;
			}
		</style>
		<?php
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
