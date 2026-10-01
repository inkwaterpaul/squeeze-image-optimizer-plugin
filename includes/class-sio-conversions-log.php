<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records every attachment whose file extension changed (a format
 * conversion). Post content is updated automatically at conversion time
 * (see SIO_Content_Urls); the WP-CLI command here is for anything outside
 * post_content — page-builder data in post meta, widgets, theme options —
 * which can be PHP-serialized, so it's left to search-replace, which
 * handles serialized data safely.
 */
class SIO_Conversions_Log {

	/**
	 * $replacements is old URL => new URL for the main file and every
	 * resized copy (see SIO_Content_Urls::replacements()); the first pair
	 * is always the main file.
	 */
	public static function add( $attachment_id, $replacements, $posts_updated = 0 ) {
		$log   = get_option( SIO_LOG_OPTION_KEY, array() );
		$log[] = array(
			'attachment_id' => $attachment_id,
			'old_url'       => key( $replacements ),
			'new_url'       => reset( $replacements ),
			'replacements'  => $replacements,
			'posts_updated' => (int) $posts_updated,
			'date'          => current_time( 'mysql' ),
		);

		// Keep this bounded — it's a working log, not a permanent audit trail.
		if ( count( $log ) > 2000 ) {
			$log = array_slice( $log, -2000 );
		}

		update_option( SIO_LOG_OPTION_KEY, $log, false );
	}

	public static function all() {
		return get_option( SIO_LOG_OPTION_KEY, array() );
	}

	public static function clear() {
		delete_option( SIO_LOG_OPTION_KEY );
	}

	public static function render() {
		$entries = array_reverse( self::all() );
		if ( empty( $entries ) ) {
			echo '<p>No format conversions yet. Entries appear here whenever an image\'s file type changes (e.g. PNG &rarr; WebP), each with a ready-to-copy WP-CLI command.</p>';
			return;
		}
		?>
		<p>
			<strong><?php echo count( $entries ); ?></strong> conversion<?php echo 1 === count( $entries ) ? '' : 's'; ?> logged.
			Image URLs in post and page content (including Image and Cover blocks) are updated automatically when an image is converted.
			The WP-CLI command is only needed for URLs stored elsewhere — page-builder data, widgets, theme options.
			It includes <code>--dry-run</code>, so it only reports what it would change; remove that flag to apply it, on a staging copy first if you have one.
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>When</th>
					<th>Attachment</th>
					<th>Old URL</th>
					<th>New URL</th>
					<th>Posts updated</th>
					<th>WP-CLI command</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					// One command per URL — the main file plus each resized
					// copy, since the old copies are deleted on conversion.
					// Entries logged before 1.2.3 only recorded the main file.
					$pairs = ! empty( $entry['replacements'] )
						? $entry['replacements']
						: array( $entry['old_url'] => $entry['new_url'] );
					$cmds  = array();
					foreach ( $pairs as $old => $new ) {
						$cmds[] = sprintf( "wp search-replace '%s' '%s' --skip-columns=guid --dry-run", $old, $new );
					}
					$cmd = implode( "\n", $cmds );
					?>
					<tr>
						<td><?php echo esc_html( $entry['date'] ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $entry['attachment_id'] ) ); ?>">#<?php echo (int) $entry['attachment_id']; ?></a></td>
						<td style="word-break:break-all;"><?php echo esc_html( $entry['old_url'] ); ?></td>
						<td style="word-break:break-all;"><?php echo esc_html( $entry['new_url'] ); ?></td>
						<td><?php echo isset( $entry['posts_updated'] ) ? (int) $entry['posts_updated'] : '&mdash;'; ?></td>
						<td>
							<code style="user-select:all; white-space:pre-wrap;"><?php echo esc_html( $cmd ); ?></code>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
