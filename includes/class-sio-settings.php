<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and serves the plugin's settings (max width, quality, skip threshold,
 * format-conversion mode, auto-optimise-on-upload toggle).
 */
class SIO_Settings {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Default values. Chosen to match the sensible, conservative defaults
	 * used by the Squeeze browser tool: don't upscale, don't convert format
	 * unless explicitly asked, leave small files alone.
	 */
	public static function defaults() {
		return array(
			'max_width'     => 2000,
			'quality'       => 82,
			'skip_kb'       => 200,
			'convert_mode'  => 'none', // none | to_webp | to_jpeg
			'auto_optimize' => 1,
			'backup_days'   => 30, // 0 = keep forever
		);
	}

	public static function get_all() {
		$saved = get_option( SIO_OPTION_KEY, array() );
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function get( $key ) {
		$all = self::get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public function register_settings() {
		register_setting(
			'sio_settings_group',
			SIO_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public function sanitize( $input ) {
		$out      = array();
		$defaults = self::defaults();

		$out['max_width'] = isset( $input['max_width'] ) ? max( 200, min( 8000, (int) $input['max_width'] ) ) : $defaults['max_width'];
		$out['quality']   = isset( $input['quality'] ) ? max( 1, min( 100, (int) $input['quality'] ) ) : $defaults['quality'];
		$out['skip_kb']   = isset( $input['skip_kb'] ) ? max( 0, min( 10000, (int) $input['skip_kb'] ) ) : $defaults['skip_kb'];

		$allowed_modes      = array( 'none', 'to_webp', 'to_jpeg' );
		$out['convert_mode'] = ( isset( $input['convert_mode'] ) && in_array( $input['convert_mode'], $allowed_modes, true ) )
			? $input['convert_mode']
			: $defaults['convert_mode'];

		$out['auto_optimize'] = ! empty( $input['auto_optimize'] ) ? 1 : 0;

		$allowed_backup_days = array( 0, 7, 14, 30, 90 );
		$out['backup_days']  = ( isset( $input['backup_days'] ) && in_array( (int) $input['backup_days'], $allowed_backup_days, true ) )
			? (int) $input['backup_days']
			: $defaults['backup_days'];

		return $out;
	}

	/**
	 * Renders the settings fields (used inside the plugin's admin page,
	 * see SIO_Media_Library::render_page()).
	 */
	public static function render_fields() {
		$s = self::get_all();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="sio_max_width">Max width</label></th>
				<td>
					<input type="number" min="200" max="8000" step="10" id="sio_max_width"
						name="<?php echo esc_attr( SIO_OPTION_KEY ); ?>[max_width]"
						value="<?php echo esc_attr( $s['max_width'] ); ?>" class="small-text" /> px
					<p class="description">Images narrower than this are left at their current width &mdash; nothing is ever upscaled.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sio_quality">Quality</label></th>
				<td>
					<input type="number" min="1" max="100" id="sio_quality"
						name="<?php echo esc_attr( SIO_OPTION_KEY ); ?>[quality]"
						value="<?php echo esc_attr( $s['quality'] ); ?>" class="small-text" /> %
					<p class="description">Applies to JPEG and WebP output. 80&ndash;85 is a safe starting point for photos.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sio_skip_kb">Skip below</label></th>
				<td>
					<input type="number" min="0" max="10000" id="sio_skip_kb"
						name="<?php echo esc_attr( SIO_OPTION_KEY ); ?>[skip_kb]"
						value="<?php echo esc_attr( $s['skip_kb'] ); ?>" class="small-text" /> KB
					<p class="description">Files already this small are left exactly as they are.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Format conversion</th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="<?php echo esc_attr( SIO_OPTION_KEY ); ?>[convert_mode]" value="none" <?php checked( $s['convert_mode'], 'none' ); ?> />
							Keep original format &mdash; recompress only (safest, no URLs change)
						</label><br/>
						<label>
							<input type="radio" name="<?php echo esc_attr( SIO_OPTION_KEY ); ?>[convert_mode]" value="to_jpeg" <?php checked( $s['convert_mode'], 'to_jpeg' ); ?> />
							Convert PNG &rarr; JPEG where there's no transparency
						</label><br/>
						<label>
							<input type="radio" name="<?php echo esc_attr( SIO_OPTION_KEY ); ?>[convert_mode]" value="to_webp" <?php checked( $s['convert_mode'], 'to_webp' ); ?> />
							Convert PNG &amp; JPEG &rarr; WebP
						</label>
						<p class="description">
							<strong>Changing format changes the file's extension and URL.</strong>
							New uploads are unaffected by this (nothing references them yet). For images already in
							your library and used in page content, the attachment's own links update automatically,
							but any URL hand-typed into content won't &mdash; see the Conversions Log tab for the
							exact <code>wp search-replace</code> command to fix those via WP-CLI, with a dry run first.
						</p>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row">Automatic optimisation</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( SIO_OPTION_KEY ); ?>[auto_optimize]" value="1" <?php checked( $s['auto_optimize'], 1 ); ?> />
						Optimise every new image as it's uploaded
					</label>
					<p class="description">Runs quietly on upload, before thumbnails are generated. Turn this off if you'd rather only run the bulk tool below by hand.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sio_backup_days">Keep backups for</label></th>
				<td>
					<select id="sio_backup_days" name="<?php echo esc_attr( SIO_OPTION_KEY ); ?>[backup_days]">
						<option value="7" <?php selected( $s['backup_days'], 7 ); ?>>7 days</option>
						<option value="14" <?php selected( $s['backup_days'], 14 ); ?>>14 days</option>
						<option value="30" <?php selected( $s['backup_days'], 30 ); ?>>30 days</option>
						<option value="90" <?php selected( $s['backup_days'], 90 ); ?>>90 days</option>
						<option value="0" <?php selected( $s['backup_days'], 0 ); ?>>Forever (never auto-delete)</option>
					</select>
					<p class="description">
						A backed-up original is deleted automatically once it's this old &mdash; its "Restore original"
						link in the Media Library disappears along with it. A daily background check does the
						deleting, so expiry can lag by up to a day. This only affects the backup copy; the live,
						optimised image is never touched.
					</p>
					<?php
					$stats = SIO_Backup::stats();
					?>
					<p class="description">
						Currently storing <strong><?php echo (int) $stats['count']; ?></strong> backup<?php echo 1 === $stats['count'] ? '' : 's'; ?>,
						using <strong><?php echo esc_html( size_format( $stats['bytes'] ) ); ?></strong> of disk space.
						<?php if ( $stats['count'] > 0 ) : ?>
							&mdash; <a href="#" id="sio-purge-backups">delete all backups now</a>
						<?php endif; ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}
}
