<?php
/**
 * Built-in admin UI for the event detail fields, using only core
 * add_meta_box()/save_post — no dependency on ACF or any other plugin.
 *
 * @package Events_Showcase
 */

namespace Events_Showcase;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a "Event Details" meta box on the Events edit screen and
 * saves it to the same `es_*` post meta keys ACF_Fields writes to.
 *
 * This exists so the plugin has zero required dependencies: Post_Type
 * already registers the meta schema (types, sanitize callbacks) via
 * register_post_meta() independent of ACF, and Events_Repository::meta()
 * already reads raw post meta first, falling back to ACF only if that's
 * empty. This class is the piece that was missing — an editing UI that
 * doesn't require ACF to exist at all.
 */
class Meta_Box {

	/**
	 * Nonce action/field name for the save handler.
	 */
	const NONCE_ACTION = 'events_showcase_save_meta';
	const NONCE_NAME   = 'events_showcase_meta_nonce';

	/**
	 * Registers hooks — unless ACF is active, in which case ACF_Fields
	 * already provides this exact UI and showing both would put two
	 * "Event Details" boxes on the same screen for no reason.
	 */
	public function __construct() {
		if ( \function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		\add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		// Dynamic for the same reason as Events_Repository's save_post
		// hook: Post_Type::post_type() is filterable, and a hardcoded
		// hook name would silently stop saving on a repointed post type.
		\add_action( 'save_post_' . Post_Type::post_type(), array( $this, 'save' ) );
	}

	/**
	 * @return void
	 */
	public function register_meta_box(): void {
		\add_meta_box(
			'events_showcase_details',
			__( 'Event Details', 'events-showcase' ),
			array( $this, 'render' ),
			Post_Type::post_type(),
			'normal',
			'high'
		);
	}

	/**
	 * Renders the meta box fields, pre-filled from existing post meta.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		\wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$start = $this->to_input_value( \get_post_meta( $post->ID, 'es_start_datetime', true ) );
		$end   = $this->to_input_value( \get_post_meta( $post->ID, 'es_end_datetime', true ) );

		// A suggestion in the field's value attribute, not a saved value —
		// nothing is written to post meta from this; it only takes effect
		// if the editor saves the form with this value still in place.
		if ( '' === $end && '' !== $start ) {
			$end = $this->suggested_end_value( $start );
		}

		$venue    = \get_post_meta( $post->ID, 'es_venue_name', true );
		$location = \get_post_meta( $post->ID, 'es_location', true );
		$url      = \get_post_meta( $post->ID, 'es_external_url', true );
		$all_day  = (bool) \get_post_meta( $post->ID, 'es_all_day', true );
		$status   = \get_post_meta( $post->ID, 'es_status', true ) ?: 'scheduled';
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="es_start_datetime"><?php esc_html_e( 'Start Date & Time', 'events-showcase' ); ?></label>
					</th>
					<td>
						<input
							type="datetime-local"
							id="es_start_datetime"
							name="es_start_datetime"
							value="<?php echo esc_attr( $start ); ?>"
							required
						>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="es_end_datetime"><?php esc_html_e( 'End Date & Time', 'events-showcase' ); ?></label>
					</th>
					<td>
						<input
							type="datetime-local"
							id="es_end_datetime"
							name="es_end_datetime"
							value="<?php echo esc_attr( $end ); ?>"
						>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="es_venue_name"><?php esc_html_e( 'Venue Name', 'events-showcase' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="es_venue_name"
							name="es_venue_name"
							value="<?php echo esc_attr( $venue ); ?>"
						>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="es_location"><?php esc_html_e( 'Location', 'events-showcase' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="es_location"
							name="es_location"
							value="<?php echo esc_attr( $location ); ?>"
						>
						<p class="description">
							<?php esc_html_e( 'City or region — drives the location filter.', 'events-showcase' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="es_external_url"><?php esc_html_e( 'External URL', 'events-showcase' ); ?></label>
					</th>
					<td>
						<input
							type="url"
							class="regular-text"
							id="es_external_url"
							name="es_external_url"
							value="<?php echo esc_attr( $url ); ?>"
							placeholder="https://"
						>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'All-day event', 'events-showcase' ); ?></th>
					<td>
						<label for="es_all_day">
							<input
								type="checkbox"
								id="es_all_day"
								name="es_all_day"
								value="1"
								<?php checked( $all_day ); ?>
							>
							<?php esc_html_e( 'This is an all-day event', 'events-showcase' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Hides the time and shows only the date, everywhere the event appears.', 'events-showcase' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="es_status"><?php esc_html_e( 'Status', 'events-showcase' ); ?></label>
					</th>
					<td>
						<select id="es_status" name="es_status">
							<option value="scheduled" <?php selected( $status, 'scheduled' ); ?>>
								<?php esc_html_e( 'Scheduled', 'events-showcase' ); ?>
							</option>
							<option value="postponed" <?php selected( $status, 'postponed' ); ?>>
								<?php esc_html_e( 'Postponed', 'events-showcase' ); ?>
							</option>
							<option value="cancelled" <?php selected( $status, 'cancelled' ); ?>>
								<?php esc_html_e( 'Cancelled', 'events-showcase' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Postponed and cancelled events still appear in listings — an event someone is checking on needs to be findable.', 'events-showcase' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Saves the meta box's fields to the same `es_*` post meta keys
	 * ACF_Fields uses, so Events_Repository can't tell which UI wrote them.
	 *
	 * @param int $post_id Post being saved.
	 * @return void
	 */
	public function save( int $post_id ): void {
		if (
			! isset( $_POST[ self::NONCE_NAME ] )
			|| ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		// Autosave fires save_post without this meta box's fields in
		// $_POST at all — saving here would silently blank every field.
		if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! \current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['es_start_datetime'] ) ) {
			$this->save_datetime( $post_id, 'es_start_datetime', \sanitize_text_field( \wp_unslash( $_POST['es_start_datetime'] ) ) );
		}

		if ( isset( $_POST['es_end_datetime'] ) ) {
			$this->save_datetime( $post_id, 'es_end_datetime', \sanitize_text_field( \wp_unslash( $_POST['es_end_datetime'] ) ) );
		}

		if ( isset( $_POST['es_venue_name'] ) ) {
			\update_post_meta( $post_id, 'es_venue_name', \sanitize_text_field( \wp_unslash( $_POST['es_venue_name'] ) ) );
		}

		if ( isset( $_POST['es_location'] ) ) {
			\update_post_meta( $post_id, 'es_location', \sanitize_text_field( \wp_unslash( $_POST['es_location'] ) ) );
		}

		if ( isset( $_POST['es_external_url'] ) ) {
			$url = \esc_url_raw( \wp_unslash( $_POST['es_external_url'] ) );
			if ( '' === $url ) {
				\delete_post_meta( $post_id, 'es_external_url' );
			} else {
				\update_post_meta( $post_id, 'es_external_url', $url );
			}
		}

		// Not behind isset(): an unchecked checkbox is simply absent from
		// $_POST, so "not set" has to mean "false," not "leave unchanged" —
		// otherwise unchecking the box and saving would never take effect.
		\update_post_meta( $post_id, 'es_all_day', isset( $_POST['es_all_day'] ) ? 1 : 0 );

		if ( isset( $_POST['es_status'] ) ) {
			$allowed = array( 'scheduled', 'postponed', 'cancelled' );
			$status  = \sanitize_key( \wp_unslash( $_POST['es_status'] ) );
			\update_post_meta( $post_id, 'es_status', \in_array( $status, $allowed, true ) ? $status : 'scheduled' );
		}
	}

	/**
	 * Converts a submitted datetime-local value to the stored format, or
	 * deletes the meta key if it was cleared.
	 *
	 * @param int    $post_id   Post being saved.
	 * @param string $key       Meta key.
	 * @param string $raw_value Raw "Y-m-d\TH:i" value from $_POST.
	 * @return void
	 */
	private function save_datetime( int $post_id, string $key, string $raw_value ): void {
		$value = $this->from_input_value( $raw_value );

		if ( '' === $value ) {
			\delete_post_meta( $post_id, $key );
			return;
		}

		\update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Start-plus-default-duration, in datetime-local input format —
	 * Settings::get( 'default_duration' ) is minutes, so this is purely
	 * additive arithmetic on the same wall-clock value already in the
	 * start field, no timezone conversion involved.
	 *
	 * @param string $start_input Already-populated start field value.
	 * @return string
	 */
	private function suggested_end_value( string $start_input ): string {
		$start = \DateTime::createFromFormat( 'Y-m-d\TH:i', $start_input );
		if ( ! $start ) {
			return '';
		}

		$start->modify( '+' . (int) Settings::get( 'default_duration' ) . ' minutes' );

		return $start->format( 'Y-m-d\TH:i' );
	}

	/**
	 * Stored → input: "Y-m-d H:i:s" to the "Y-m-d\TH:i" a datetime-local
	 * input expects, in the site's timezone — the same interpretation
	 * Events_Repository::iso8601() uses on the way out, so a value never
	 * silently shifts hours by round-tripping through this UI.
	 *
	 * @param string|false $stored Raw meta value.
	 * @return string
	 */
	private function to_input_value( $stored ): string {
		if ( empty( $stored ) ) {
			return '';
		}

		$datetime = \DateTime::createFromFormat( 'Y-m-d H:i:s', $stored, \wp_timezone() );

		return $datetime ? $datetime->format( 'Y-m-d\TH:i' ) : '';
	}

	/**
	 * Input → stored: the reverse of to_input_value(). A datetime-local
	 * field submits no timezone info, so it's treated as the site's local
	 * time — matching how the value is interpreted everywhere else.
	 *
	 * @param string $value Raw "Y-m-d\TH:i" value from $_POST.
	 * @return string
	 */
	private function from_input_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$datetime = \DateTime::createFromFormat( 'Y-m-d\TH:i', $value, \wp_timezone() );

		return $datetime ? $datetime->format( 'Y-m-d H:i:s' ) : '';
	}
}
