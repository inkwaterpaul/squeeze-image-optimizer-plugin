<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records every attachment whose file extension changed (a format
 * conversion), so the admin page can hand you the exact WP-CLI
 * search-replace command for hunting down any hardcoded URLs left in
 * post content. We deliberately don't attempt that rewrite ourselves —
 * WP-CLI's search-replace already does it safely across serialized data,
 * and re-implementing that inside the plugin is where this kind of tool
 * usually goes wrong.
 */
class SIO_Conversions_Log {

	public static function add( $attachment_id, $old_url, $new_url ) {
		$log   = get_option( SIO_LOG_OPTION_KEY, array() );
		$log[] = array(
			'attachment_id' => $attachment_id,
			'old_url'       => $old_url,
			'new_url'       => $new_url,
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
			Run each command with <code>--dry-run</code> first to see what it would touch, on a staging copy if you have one.
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>When</th>
					<th>Attachment</th>
					<th>Old URL</th>
					<th>New URL</th>
					<th>WP-CLI command</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$cmd = sprintf(
						"wp search-replace '%s' '%s' --skip-columns=guid --dry-run",
						$entry['old_url'],
						$entry['new_url']
					);
					?>
					<tr>
						<td><?php echo esc_html( $entry['date'] ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $entry['attachment_id'] ) ); ?>">#<?php echo (int) $entry['attachment_id']; ?></a></td>
						<td style="word-break:break-all;"><?php echo esc_html( $entry['old_url'] ); ?></td>
						<td style="word-break:break-all;"><?php echo esc_html( $entry['new_url'] ); ?></td>
						<td>
							<code style="user-select:all;"><?php echo esc_html( $cmd ); ?></code>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
