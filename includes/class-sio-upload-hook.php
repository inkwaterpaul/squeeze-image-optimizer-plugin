<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimises a file the moment it lands in the uploads directory, before any
 * attachment post or thumbnail sizes exist for it — so core generates every
 * registered size from the already-resized/recompressed source instead of
 * doing the work twice.
 *
 * Deliberately doesn't take a backup here: there's no attachment yet to
 * attach one to, and nothing in the site references the file yet either.
 * If you need the untouched original kept, disable auto-optimise and use
 * the bulk tool instead (which does back up), or just re-upload if a
 * result ever looks wrong.
 */
class SIO_Upload_Hook {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'wp_handle_upload', array( $this, 'maybe_optimize' ) );
		add_filter( 'wp_handle_sideload', array( $this, 'maybe_optimize' ) );
	}

	public function maybe_optimize( $upload ) {
		if ( empty( SIO_Settings::get( 'auto_optimize' ) ) ) {
			return $upload;
		}
		if ( empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}
		if ( ! in_array( $upload['type'], SIO_Optimizer::HANDLED_MIMES, true ) ) {
			return $upload;
		}

		$args   = SIO_Settings::get_all();
		$result = SIO_Optimizer::process_file( $upload['file'], $upload['type'], $args );

		if ( 'optimized' !== $result['status'] ) {
			return $upload;
		}

		$upload['file'] = $result['file'];
		$upload['type'] = $result['mime'];

		if ( ! empty( $result['ext_changed'] ) ) {
			$upload['url'] = preg_replace( '/\.[^.]+$/', '.' . SIO_Optimizer::ext_for_mime( $result['mime'] ), $upload['url'] );
		}

		return $upload;
	}
}
