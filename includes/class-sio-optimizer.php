<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core resize/recompress/convert pipeline. Built entirely on WordPress's
 * own wp_get_image_editor() abstraction (the same code path core uses to
 * generate thumbnails), so it works with whichever of Imagick or GD the
 * host has available, with no shell-outs and no third-party binaries.
 */
class SIO_Optimizer {

	// Animated GIFs can't go through this pipeline without collapsing the
	// animation, so they're always left alone.
	const SKIP_MIMES = array( 'image/gif' );

	const HANDLED_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	public static function ext_for_mime( $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
				return 'jpg';
			case 'image/png':
				return 'png';
			case 'image/webp':
				return 'webp';
			default:
				return '';
		}
	}

	/**
	 * Works out the target mime type for a given source mime, honouring the
	 * configured conversion mode. Returns the source mime unchanged when no
	 * conversion applies.
	 */
	public static function target_mime( $source_mime, $convert_mode ) {
		if ( 'to_webp' === $convert_mode && in_array( $source_mime, array( 'image/png', 'image/jpeg' ), true ) ) {
			return 'image/webp';
		}
		if ( 'to_jpeg' === $convert_mode && 'image/png' === $source_mime ) {
			return 'image/jpeg';
		}
		return $source_mime;
	}

	/**
	 * Runs resize + quality + (optional) format conversion on a raw file on
	 * disk. Used both by the attachment-based path below and by the
	 * upload-time hook, which doesn't have an attachment post to work with
	 * yet.
	 *
	 * Returns an array:
	 *   status: 'optimized' | 'skipped' | 'kept' | 'error'
	 *   file:   final file path (unchanged from input if skipped/kept)
	 *   mime:   final mime type
	 *   orig_size, new_size (bytes)
	 *   message (set on error)
	 */
	public static function process_file( $file, $source_mime, $args ) {
		if ( in_array( $source_mime, self::SKIP_MIMES, true ) ) {
			return self::unchanged_result( $file, $source_mime, 'skipped' );
		}
		if ( ! in_array( $source_mime, self::HANDLED_MIMES, true ) ) {
			return self::unchanged_result( $file, $source_mime, 'skipped' );
		}
		if ( ! file_exists( $file ) ) {
			return array(
				'status'  => 'error',
				'message' => 'File not found: ' . $file,
			);
		}

		$orig_size = filesize( $file );

		if ( $orig_size <= ( (int) $args['skip_kb'] * 1024 ) ) {
			return self::unchanged_result( $file, $source_mime, 'skipped' );
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return array(
				'status'  => 'error',
				'message' => $editor->get_error_message(),
			);
		}

		$size = $editor->get_size();
		if ( ! is_wp_error( $size ) && ! empty( $size['width'] ) && $size['width'] > (int) $args['max_width'] ) {
			// Never upscale — only resize down, and only on width (height follows
			// proportionally via the null param).
			$resized = $editor->resize( (int) $args['max_width'], null, false );
			if ( is_wp_error( $resized ) ) {
				return array(
					'status'  => 'error',
					'message' => $resized->get_error_message(),
				);
			}
		}

		$editor->set_quality( (int) $args['quality'] );

		$target_mime = self::target_mime( $source_mime, $args['convert_mode'] );

		// JPEG has no alpha channel — converting a transparent PNG straight to
		// JPEG would flatten the transparent areas onto black. Rather than do
		// that silently, leave this particular file as PNG (still recompressed)
		// and let it through un-converted.
		if ( 'image/jpeg' === $target_mime && 'image/png' === $source_mime && self::png_has_alpha( $file ) ) {
			$target_mime = $source_mime;
		}

		$ext_changed = ( $target_mime !== $source_mime );

		if ( $ext_changed ) {
			$path_info = pathinfo( $file );
			$new_ext   = self::ext_for_mime( $target_mime );
			$save_path = trailingslashit( $path_info['dirname'] ) . $path_info['filename'] . '.' . $new_ext;
		} else {
			$save_path = $file;
		}

		$saved = $editor->save( $save_path, $target_mime );
		if ( is_wp_error( $saved ) ) {
			return array(
				'status'  => 'error',
				'message' => $saved->get_error_message(),
			);
		}

		$new_size = isset( $saved['path'] ) ? filesize( $saved['path'] ) : 0;

		// If the "optimised" version is no smaller, don't use it — keep what
		// was already there and discard the new file.
		if ( $new_size <= 0 || $new_size >= $orig_size ) {
			if ( $saved['path'] !== $file ) {
				@unlink( $saved['path'] );
			}
			return self::unchanged_result( $file, $source_mime, 'kept', $orig_size );
		}

		// If the extension changed, the old full-size file is now orphaned —
		// remove it (a backup, if requested, was already taken by the caller
		// before this function ran).
		if ( $ext_changed && $file !== $saved['path'] && file_exists( $file ) ) {
			@unlink( $file );
		}

		return array(
			'status'      => 'optimized',
			'file'        => $saved['path'],
			'mime'        => $target_mime,
			'ext_changed' => $ext_changed,
			'orig_size'   => $orig_size,
			'new_size'    => $new_size,
		);
	}

	/**
	 * Cheap, decode-free check for whether a PNG can carry transparency.
	 * Reads only the IHDR chunk's colour type (always the first 26 bytes of
	 * a valid PNG) rather than decoding pixels, so this stays fast even on
	 * a large batch. Colour types 4 and 6 always carry an alpha channel;
	 * type 3 (indexed) only does if a tRNS chunk is present, which we check
	 * for within a capped read of the file's head.
	 */
	public static function png_has_alpha( $file ) {
		$handle = @fopen( $file, 'rb' );
		if ( ! $handle ) {
			// Can't verify — assume it might have transparency and play safe.
			return true;
		}

		$head = fread( $handle, 33 );
		if ( false === $head || strlen( $head ) < 26 ) {
			fclose( $handle );
			return true;
		}

		$color_type = ord( $head[25] );

		if ( in_array( $color_type, array( 4, 6 ), true ) ) {
			fclose( $handle );
			return true;
		}

		if ( 3 === $color_type ) {
			// Indexed colour: only transparent if a tRNS chunk exists.
			// Scan a capped chunk of the file's head for the tRNS marker —
			// well-formed PNGs keep palette-related chunks near the start,
			// well before the (often large) IDAT data.
			rewind( $handle );
			$chunk = fread( $handle, 65536 );
			fclose( $handle );
			return ( false !== $chunk && false !== strpos( $chunk, 'tRNS' ) );
		}

		fclose( $handle );
		return false;
	}

	private static function unchanged_result( $file, $mime, $status, $orig_size = null ) {
		return array(
			'status'    => $status,
			'file'      => $file,
			'mime'      => $mime,
			'orig_size' => null === $orig_size ? ( file_exists( $file ) ? filesize( $file ) : 0 ) : $orig_size,
			'new_size'  => null === $orig_size ? ( file_exists( $file ) ? filesize( $file ) : 0 ) : $orig_size,
		);
	}

	/**
	 * Full attachment-aware pipeline: backs up the original (first run
	 * only), runs process_file(), then — if anything actually changed —
	 * updates the attachment's mime/attached-file, regenerates thumbnail
	 * sizes, logs a format conversion if one happened, and records stats
	 * on the attachment for the Media Library column.
	 */
	public static function optimize_attachment( $attachment_id ) {
		$title = get_the_title( $attachment_id );

		// Imagick in particular can throw on a corrupt file, an unsupported
		// colour profile, or hitting a memory/time limit — catching this
		// means one bad image reports a clear per-image error instead of
		// taking down the rest of its batch with an uncaught exception
		// (which otherwise shows up client-side as a mystery failed batch
		// with no explanation at all).
		try {
			return self::do_optimize_attachment( $attachment_id, $title );
		} catch ( \Throwable $e ) {
			return array(
				'id'      => $attachment_id,
				'title'   => $title,
				'status'  => 'error',
				'message' => 'Unexpected error: ' . $e->getMessage(),
			);
		}
	}

	private static function do_optimize_attachment( $attachment_id, $title ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return array(
				'id'      => $attachment_id,
				'title'   => $title,
				'status'  => 'error',
				'message' => 'Not an image attachment.',
			);
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return array(
				'id'      => $attachment_id,
				'title'   => $title,
				'status'  => 'error',
				'message' => 'Original file is missing on disk.',
			);
		}

		$source_mime = get_post_mime_type( $attachment_id );
		$args        = SIO_Settings::get_all();
		$old_url     = wp_get_attachment_url( $attachment_id );

		SIO_Backup::backup_once( $attachment_id, $file );

		$result = self::process_file( $file, $source_mime, $args );

		if ( 'error' === $result['status'] ) {
			return array_merge( array( 'id' => $attachment_id, 'title' => $title ), $result );
		}

		if ( 'optimized' === $result['status'] ) {
			if ( ! empty( $result['ext_changed'] ) ) {
				update_attached_file( $attachment_id, $result['file'] );
				wp_update_post(
					array(
						'ID'             => $attachment_id,
						'post_mime_type' => $result['mime'],
					)
				);
			}

			$metadata = wp_generate_attachment_metadata( $attachment_id, get_attached_file( $attachment_id ) );
			wp_update_attachment_metadata( $attachment_id, $metadata );

			if ( ! empty( $result['ext_changed'] ) ) {
				$new_url = wp_get_attachment_url( $attachment_id );
				SIO_Conversions_Log::add( $attachment_id, $old_url, $new_url );
			}
		}

		$stats = array(
			'status'    => $result['status'],
			'orig_size' => $result['orig_size'],
			'new_size'  => $result['new_size'],
			'date'      => current_time( 'mysql' ),
		);
		update_post_meta( $attachment_id, '_sio_optimized', $stats );

		return array_merge( array( 'id' => $attachment_id, 'title' => $title ), $result );
	}

	/**
	 * All image attachment IDs eligible for the bulk pass (excludes
	 * animated GIFs, which are always left alone).
	 */
	public static function eligible_attachment_ids() {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => self::HANDLED_MIMES,
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	}
}
