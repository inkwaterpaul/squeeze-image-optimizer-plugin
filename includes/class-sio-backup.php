<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps one untouched copy of each attachment's original file (the first
 * time it's optimised) so the whole thing can be undone from the Media
 * Library. Only used for the bulk/manual path over existing attachments —
 * see the README for why upload-time auto-optimisation doesn't back up.
 */
class SIO_Backup {

	public static function ensure_backup_dir() {
		$dir = self::backup_root();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}
	}

	public static function backup_root() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . SIO_BACKUP_DIRNAME;
	}

	private static function backup_dir_for( $attachment_id ) {
		return trailingslashit( self::backup_root() ) . (int) $attachment_id;
	}

	public static function has_backup( $attachment_id ) {
		return (bool) get_post_meta( $attachment_id, '_sio_has_backup', true );
	}

	/**
	 * Copies the current file to the backup store, but only the first time
	 * this attachment is ever optimised — re-running optimisation later must
	 * never overwrite the true original with an already-optimised version.
	 */
	public static function backup_once( $attachment_id, $file_path ) {
		if ( self::has_backup( $attachment_id ) ) {
			return true;
		}

		self::ensure_backup_dir();
		$dir = self::backup_dir_for( $attachment_id );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$basename    = wp_basename( $file_path );
		$backup_path = trailingslashit( $dir ) . $basename;

		if ( ! @copy( $file_path, $backup_path ) ) {
			return false;
		}

		update_post_meta( $attachment_id, '_sio_has_backup', 1 );
		update_post_meta(
			$attachment_id,
			'_sio_backup_meta',
			array(
				'path'          => $backup_path,
				'attached_file' => get_post_meta( $attachment_id, '_wp_attached_file', true ),
				'mime_type'     => get_post_mime_type( $attachment_id ),
				'original_size' => filesize( $file_path ),
				'time'          => time(),
			)
		);

		return true;
	}

	/**
	 * Restores the backed-up original over the current file, reverting the
	 * attachment's mime type and attached-file pointer if those changed
	 * (i.e. a format conversion is being undone), then regenerates
	 * thumbnail sizes from the restored original.
	 */
	public static function restore( $attachment_id ) {
		$backup_meta = get_post_meta( $attachment_id, '_sio_backup_meta', true );
		if ( empty( $backup_meta ) || empty( $backup_meta['path'] ) || ! file_exists( $backup_meta['path'] ) ) {
			return new WP_Error( 'sio_no_backup', 'No backup is stored for this image.' );
		}

		$current_file = get_attached_file( $attachment_id );
		$uploads      = wp_upload_dir();
		$current_urls = SIO_Content_Urls::url_map( $attachment_id );

		// Delete the currently-generated sizes before we change the base image.
		$current_meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $current_meta['sizes'] ) && is_array( $current_meta['sizes'] ) ) {
			$dir = trailingslashit( dirname( $current_file ) );
			foreach ( $current_meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					@unlink( $dir . $size['file'] );
				}
			}
		}

		// If the optimised file lives at a different path (format changed),
		// remove it; otherwise it gets overwritten by the restore copy below.
		$original_relative = $backup_meta['attached_file'];
		$original_abs       = trailingslashit( $uploads['basedir'] ) . ltrim( $original_relative, '/' );

		if ( $current_file && file_exists( $current_file ) && $current_file !== $original_abs ) {
			@unlink( $current_file );
		}

		wp_mkdir_p( dirname( $original_abs ) );
		if ( ! @copy( $backup_meta['path'], $original_abs ) ) {
			return new WP_Error( 'sio_restore_failed', 'Could not copy the backup back into place.' );
		}

		update_attached_file( $attachment_id, $original_abs );
		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $backup_meta['mime_type'],
			)
		);

		$new_metadata = wp_generate_attachment_metadata( $attachment_id, $original_abs );
		wp_update_attachment_metadata( $attachment_id, $new_metadata );

		// Undoing a format conversion deletes the converted file, so point
		// post content back at the restored original's URLs.
		SIO_Content_Urls::rewrite(
			SIO_Content_Urls::replacements( $current_urls, SIO_Content_Urls::url_map( $attachment_id ) )
		);

		delete_post_meta( $attachment_id, '_sio_optimized' );
		update_post_meta( $attachment_id, '_sio_restored', current_time( 'mysql' ) );

		// The file on disk now IS the original again, so there's nothing left
		// to back up — reclaim the space straight away rather than waiting
		// for the next expiry sweep.
		self::remove_backup( $attachment_id );

		return true;
	}

	/**
	 * Deletes one attachment's backup copy (if any) and clears its flags.
	 * Used after a restore (the live file now IS the original, so the
	 * backup is redundant) and by the expiry sweep / manual purge below.
	 */
	public static function remove_backup( $attachment_id ) {
		$backup_meta = get_post_meta( $attachment_id, '_sio_backup_meta', true );
		if ( ! empty( $backup_meta['path'] ) && file_exists( $backup_meta['path'] ) ) {
			@unlink( $backup_meta['path'] );
			@rmdir( dirname( $backup_meta['path'] ) ); // only removes if now empty
		}
		delete_post_meta( $attachment_id, '_sio_has_backup' );
		delete_post_meta( $attachment_id, '_sio_backup_meta' );
	}

	/**
	 * Deletes every backup older than $max_age_days (0 = keep forever, never
	 * called in that case — see the daily cron hook). Returns how many were
	 * removed.
	 */
	public static function purge_expired( $max_age_days ) {
		if ( (int) $max_age_days <= 0 ) {
			return 0;
		}

		$cutoff = time() - ( (int) $max_age_days * DAY_IN_SECONDS );
		$ids    = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_sio_has_backup',
						'value' => 1,
					),
				),
			)
		);

		$removed = 0;
		foreach ( $ids as $id ) {
			$backup_meta = get_post_meta( $id, '_sio_backup_meta', true );
			$time        = ! empty( $backup_meta['time'] ) ? (int) $backup_meta['time'] : 0;
			// No recorded timestamp (backup predates this feature) counts as
			// expired too, rather than being kept forever by default.
			if ( 0 === $time || $time <= $cutoff ) {
				self::remove_backup( $id );
				$removed++;
			}
		}

		return $removed;
	}

	/** Deletes every backup regardless of age — used by the manual button. */
	public static function purge_all() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_sio_has_backup',
						'value' => 1,
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			self::remove_backup( $id );
		}

		return count( $ids );
	}

	/** Count and total disk size of backups currently stored, for the admin screen. */
	public static function stats() {
		$root = self::backup_root();
		if ( ! is_dir( $root ) ) {
			return array(
				'count' => 0,
				'bytes' => 0,
			);
		}

		$count = 0;
		$bytes = 0;
		foreach ( glob( trailingslashit( $root ) . '*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			$files = glob( trailingslashit( $dir ) . '*' ) ?: array();
			if ( empty( $files ) ) {
				continue;
			}
			$count++;
			foreach ( $files as $f ) {
				if ( is_file( $f ) ) {
					$bytes += filesize( $f );
				}
			}
		}

		return array(
			'count' => $count,
			'bytes' => $bytes,
		);
	}
}
