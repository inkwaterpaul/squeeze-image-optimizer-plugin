<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: the plugin's own page (settings + bulk run + conversions log),
 * a status column and row actions in the Media Library list view, and the
 * AJAX endpoints the bulk run and row actions call.
 */
class SIO_Media_Library {

	private static $instance = null;
	const NONCE_ACTION = 'sio_ajax';
	const BATCH_SIZE   = 20;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );

		add_action( 'wp_ajax_sio_get_attachment_ids', array( $this, 'ajax_get_attachment_ids' ) );
		add_action( 'wp_ajax_sio_optimize_batch', array( $this, 'ajax_optimize_batch' ) );
		add_action( 'wp_ajax_sio_optimize_single', array( $this, 'ajax_optimize_single' ) );
		add_action( 'wp_ajax_sio_restore_single', array( $this, 'ajax_restore_single' ) );
		add_action( 'wp_ajax_sio_purge_backups', array( $this, 'ajax_purge_backups' ) );
	}

	public function add_menu() {
		add_media_page(
			'Image Optimizer',
			'Image Optimizer',
			'manage_options',
			'squeeze-image-optimizer',
			array( $this, 'render_page' )
		);
	}

	public function enqueue( $hook ) {
		$is_plugin_page = ( 'media_page_squeeze-image-optimizer' === $hook );
		$is_library     = ( 'upload.php' === $hook );

		if ( ! $is_plugin_page && ! $is_library ) {
			return;
		}

		wp_enqueue_style( 'sio-admin', SIO_PLUGIN_URL . 'assets/admin.css', array(), SIO_VERSION );
		wp_enqueue_script( 'sio-admin', SIO_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), SIO_VERSION, true );
		wp_localize_script(
			'sio-admin',
			'SIO',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				// Sent as a string by wp_localize_script regardless of this
				// PHP type — admin.js explicitly parses it back to a number,
				// so don't rely on it arriving as one.
				'batchSize' => self::BATCH_SIZE,
			)
		);
	}

	// --- Media Library column -------------------------------------------------

	public function add_column( $columns ) {
		$columns['sio_status'] = 'Optimised';
		return $columns;
	}

	public function render_column( $column_name, $attachment_id ) {
		if ( 'sio_status' !== $column_name ) {
			return;
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			echo '&mdash;';
			return;
		}

		$stats = get_post_meta( $attachment_id, '_sio_optimized', true );
		echo '<div class="sio-column" data-id="' . esc_attr( $attachment_id ) . '">';

		if ( $stats && is_array( $stats ) ) {
			if ( 'optimized' === $stats['status'] ) {
				$pct = $stats['orig_size'] > 0 ? round( ( 1 - $stats['new_size'] / $stats['orig_size'] ) * 100 ) : 0;
				echo '<span class="sio-saved">-' . esc_html( $pct ) . '%</span><br/>';
				echo esc_html( size_format( $stats['orig_size'] ) ) . ' &rarr; ' . esc_html( size_format( $stats['new_size'] ) );
			} elseif ( 'kept' === $stats['status'] ) {
				echo '<span class="sio-muted">Kept original</span>';
			} elseif ( 'skipped' === $stats['status'] ) {
				echo '<span class="sio-muted">Already small</span>';
			}
			echo '<br/>';
			if ( SIO_Backup::has_backup( $attachment_id ) ) {
				echo '<a href="#" class="sio-restore" data-id="' . esc_attr( $attachment_id ) . '">Restore original</a>';
			}
		} else {
			echo '<a href="#" class="sio-optimize-now" data-id="' . esc_attr( $attachment_id ) . '">Optimise now</a>';
		}

		echo '</div>';
	}

	// --- Admin page -------------------------------------------------------

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['sio_save_settings'] ) && check_admin_referer( 'sio_save_settings' ) ) {
			$input = isset( $_POST[ SIO_OPTION_KEY ] ) ? wp_unslash( $_POST[ SIO_OPTION_KEY ] ) : array();
			update_option( SIO_OPTION_KEY, SIO_Settings::instance()->sanitize( $input ) );
			echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
		}

		if ( isset( $_POST['sio_clear_log'] ) && check_admin_referer( 'sio_clear_log' ) ) {
			SIO_Conversions_Log::clear();
			echo '<div class="notice notice-success"><p>Conversions log cleared.</p></div>';
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		?>
		<div class="wrap sio-wrap">
			<h1>Squeeze Image Optimizer <span class="sio-version">v<?php echo esc_html( SIO_VERSION ); ?></span></h1>

			<h2 class="nav-tab-wrapper">
				<a href="?page=squeeze-image-optimizer&tab=settings" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">Settings</a>
				<a href="?page=squeeze-image-optimizer&tab=bulk" class="nav-tab <?php echo 'bulk' === $active_tab ? 'nav-tab-active' : ''; ?>">Bulk Optimise</a>
				<a href="?page=squeeze-image-optimizer&tab=log" class="nav-tab <?php echo 'log' === $active_tab ? 'nav-tab-active' : ''; ?>">Conversions Log</a>
			</h2>

			<?php if ( 'settings' === $active_tab ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'sio_save_settings' ); ?>
					<?php SIO_Settings::render_fields(); ?>
					<p class="submit">
						<button type="submit" name="sio_save_settings" value="1" class="button button-primary">Save Settings</button>
					</p>
				</form>

			<?php elseif ( 'bulk' === $active_tab ) : ?>
				<p>Runs over every image currently in your Media Library (animated GIFs are always left alone), using the settings on the Settings tab. Safe to leave running &mdash; it processes a few images per request so it won't time out on a large library.</p>
				<p>
					<button type="button" id="sio-start-bulk" class="button button-primary">Optimise all images</button>
					<button type="button" id="sio-stop-bulk" class="button" style="display:none;">Stop</button>
				</p>
				<div id="sio-bulk-progress" style="display:none;">
					<div class="sio-progress-label">
						<span>Optimising <strong id="sio-bulk-count">0 / 0</strong></span>
						<span id="sio-bulk-pct">0%</span>
					</div>
					<div class="sio-progress-track">
						<div class="sio-progress-fill" id="sio-bulk-fill"></div>
						<div class="sio-progress-fill sio-progress-fill-inflight" id="sio-bulk-fill-inflight"></div>
					</div>
					<p class="sio-progress-status" id="sio-bulk-status">Starting…</p>
				</div>
				<div id="sio-bulk-summary"></div>
				<div id="sio-bulk-log-wrap" style="display:none;">
					<h3>Errors (<span id="sio-bulk-error-count">0</span>)</h3>
					<p class="description">Each line names the image and what went wrong. A whole-batch failure (no per-image detail) usually means the server hit a timeout or memory limit on one of those images — check your host's PHP error log around that time for the full detail.</p>
					<div id="sio-bulk-log"></div>
				</div>

			<?php elseif ( 'log' === $active_tab ) : ?>
				<?php SIO_Conversions_Log::render(); ?>
				<?php if ( ! empty( SIO_Conversions_Log::all() ) ) : ?>
					<form method="post" style="margin-top:1em;">
						<?php wp_nonce_field( 'sio_clear_log' ); ?>
						<button type="submit" name="sio_clear_log" value="1" class="button" onclick="return confirm('Clear the conversions log? This does not undo any conversions.');">Clear log</button>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// --- AJAX ---------------------------------------------------------------

	private function check_ajax() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}
	}

	public function ajax_get_attachment_ids() {
		$this->check_ajax();
		$ids = SIO_Optimizer::eligible_attachment_ids();
		wp_send_json_success( array( 'ids' => array_map( 'intval', $ids ) ) );
	}

	public function ajax_optimize_batch() {
		$this->check_ajax();
		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : array();
		$ids = array_filter( $ids );

		// Enforced here as well as client-side: a cached or buggy admin.js
		// must never be able to send thousands of images in one request
		// and run straight into a PHP timeout.
		if ( count( $ids ) > self::BATCH_SIZE ) {
			wp_send_json_error(
				sprintf( 'Batch of %d images exceeds the limit of %d. Reload the page (to pick up the current script) and try again.', count( $ids ), self::BATCH_SIZE ),
				400
			);
		}

		$results = array();
		foreach ( $ids as $id ) {
			// Belt and braces on top of optimize_attachment()'s own try/catch:
			// nothing thrown while processing one image should ever take the
			// rest of the batch down with it.
			try {
				$results[] = SIO_Optimizer::optimize_attachment( $id );
			} catch ( \Throwable $e ) {
				$results[] = array(
					'id'      => $id,
					'status'  => 'error',
					'message' => 'Unexpected error: ' . $e->getMessage(),
				);
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	public function ajax_optimize_single() {
		$this->check_ajax();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( 'Missing attachment id.' );
		}
		$result = SIO_Optimizer::optimize_attachment( $id );
		wp_send_json_success( $result );
	}

	public function ajax_restore_single() {
		$this->check_ajax();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( 'Missing attachment id.' );
		}
		$result = SIO_Backup::restore( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array( 'id' => $id ) );
	}

	public function ajax_purge_backups() {
		$this->check_ajax();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}
		$count = SIO_Backup::purge_all();
		wp_send_json_success( array( 'count' => $count ) );
	}
}
