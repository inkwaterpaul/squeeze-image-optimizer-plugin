( function ( $ ) {
	'use strict';

	function fmtBytes( b ) {
		if ( b < 1024 ) return b + ' B';
		if ( b < 1024 * 1024 ) return ( b / 1024 ).toFixed( 1 ) + ' KB';
		return ( b / 1024 / 1024 ).toFixed( 2 ) + ' MB';
	}

	function ajax( action, data ) {
		return $.post( SIO.ajaxUrl, Object.assign( { action: action, nonce: SIO.nonce }, data ) );
	}

	// wp_localize_script sends every value through as a string, so
	// SIO.batchSize arrives as "5", not 5. Left as-is, `index + SIO.batchSize`
	// silently does STRING concatenation (0+"5"="05", 5+"5"="55", 55+"5"="555"…)
	// instead of addition, which is exactly what caused batches to balloon
	// to thousands of images in one request — and the resulting timeout.
	// Coerce once, here, so nothing downstream can repeat that mistake.
	var BATCH_SIZE = parseInt( SIO.batchSize, 10 ) || 20;

	function esc( str ) {
		return $( '<div>' ).text( str == null ? '' : String( str ) ).html();
	}

	var errorCount = 0;

	function logError( label, detail ) {
		errorCount++;
		$( '#sio-bulk-log-wrap' ).show();
		$( '#sio-bulk-error-count' ).text( errorCount );
		var html = '<p>' + esc( label );
		if ( detail ) {
			html += '<span class="sio-log-detail">' + esc( detail ) + '</span>';
		}
		html += '</p>';
		$( '#sio-bulk-log' ).prepend( html );
	}

	// --- Bulk run (plugin's own "Bulk Optimise" tab) ------------------------

	var bulkState = {
		ids: [], index: 0, stopped: false,
		totalOrig: 0, totalNew: 0, optimized: 0, skipped: 0, kept: 0, errors: 0,
		msPerImage: null // rolling average, seeded after the first batch returns
	};

	function fmtDuration( seconds ) {
		if ( seconds < 60 ) return Math.ceil( seconds ) + 's';
		var mins = Math.floor( seconds / 60 );
		var secs = Math.round( seconds % 60 );
		return mins + 'm ' + secs + 's';
	}

	// done = confirmed-complete count; inflightEnd = how far the bar should
	// show as "in progress" (the batch currently out for processing), so the
	// bar visibly advances the moment a request is sent, not just when it
	// returns — a single slow batch no longer looks like a frozen page.
	function updateBulkProgress( done, inflightEnd ) {
		var total = bulkState.ids.length;
		var donePct     = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
		var inflightPct = total > 0 ? Math.round( ( inflightEnd / total ) * 100 ) : 0;

		$( '#sio-bulk-count' ).text( done + ' / ' + total );
		$( '#sio-bulk-pct' ).text( donePct + '%' );
		$( '#sio-bulk-fill' ).css( 'width', donePct + '%' );
		$( '#sio-bulk-fill-inflight' ).css( 'width', inflightPct + '%' );

		if ( done >= total ) {
			$( '#sio-bulk-status' ).text( 'Finishing up…' );
			return;
		}

		var statusText = 'Optimising images ' + ( done + 1 ) + '–' + inflightEnd + ' of ' + total + '…';
		if ( bulkState.msPerImage ) {
			var remaining = total - done;
			var etaSeconds = ( remaining * bulkState.msPerImage ) / 1000;
			statusText += '  (about ' + fmtDuration( etaSeconds ) + ' remaining)';
		}
		$( '#sio-bulk-status' ).text( statusText );
	}

	function renderBulkSummary() {
		var pct = bulkState.totalOrig > 0 ? Math.round( ( 1 - bulkState.totalNew / bulkState.totalOrig ) * 100 ) : 0;
		var headline = bulkState.ids.length + ' image' + ( bulkState.ids.length === 1 ? '' : 's' ) + ' processed, '
			+ bulkState.optimized + ' actually optimised';
		var bits = [];
		bits.push( fmtBytes( bulkState.totalOrig ) + ' → ' + fmtBytes( bulkState.totalNew ) + ' (' + ( pct >= 0 ? '-' : '+' ) + Math.abs( pct ) + '% overall)' );
		if ( bulkState.skipped ) bits.push( bulkState.skipped + ' already small' );
		if ( bulkState.kept ) bits.push( bulkState.kept + ' kept original (would’ve grown)' );
		if ( bulkState.errors ) bits.push( bulkState.errors + ' errors' );
		$( '#sio-bulk-summary' ).html( '<p><strong>' + headline + '</strong></p><p>' + bits.join( ' · ' ) + '</p>' );
	}

	function processNextBatch() {
		if ( bulkState.stopped || bulkState.index >= bulkState.ids.length ) {
			$( '#sio-start-bulk' ).prop( 'disabled', false ).text( 'Optimise all images' );
			$( '#sio-stop-bulk' ).hide();
			$( '#sio-bulk-fill-inflight' ).css( 'width', '0%' );
			$( '#sio-bulk-status' ).text( bulkState.stopped ? 'Stopped.' : 'Done.' );
			renderBulkSummary();
			return;
		}

		var batch      = bulkState.ids.slice( bulkState.index, bulkState.index + BATCH_SIZE );
		var batchStart = bulkState.index;
		var inflightEnd = batchStart + batch.length;

		// Show this batch as "in flight" the moment it's sent, not when it
		// returns — so the bar keeps moving even if the server takes a while
		// (e.g. a batch of unusually large images).
		updateBulkProgress( batchStart, inflightEnd );

		var requestStarted = Date.now();

		ajax( 'sio_optimize_batch', { ids: batch } )
			.done( function ( resp ) {
				if ( resp.success ) {
					resp.data.results.forEach( function ( r ) {
						if ( r.status === 'error' ) {
							bulkState.errors++;
							var label = r.title ? ( r.title + ' (#' + r.id + ')' ) : ( 'Image #' + r.id );
							logError( label, r.message || 'Unknown error.' );
						} else {
							bulkState.totalOrig += r.orig_size || 0;
							bulkState.totalNew += r.new_size || 0;
							if ( r.status === 'skipped' ) bulkState.skipped++;
							else if ( r.status === 'kept' ) bulkState.kept++;
							else if ( r.status === 'optimized' ) bulkState.optimized++;
						}
					} );
				}

				// Rolling average ms/image, weighted toward recent batches so
				// the ETA adapts as the mix of large/small images changes.
				var elapsed    = Date.now() - requestStarted;
				var thisAvg    = elapsed / batch.length;
				bulkState.msPerImage = bulkState.msPerImage === null
					? thisAvg
					: ( bulkState.msPerImage * 0.7 ) + ( thisAvg * 0.3 );

				bulkState.index += batch.length;
				updateBulkProgress( bulkState.index, bulkState.index );
				processNextBatch();
			} )
			.fail( function ( jqXHR, textStatus ) {
				bulkState.errors += batch.length;

				// The whole batch died before PHP could even return per-image
				// results — usually a server timeout or a fatal error (e.g. a
				// memory limit) on one of the images in it. No per-image detail
				// is possible here, but naming the batch and the likely cause
				// beats a silent count going up with no explanation.
				var label  = 'Batch of ' + batch.length + ' images failed (IDs: ' + batch.join( ', ' ) + ')';
				var detail = 'HTTP ' + ( jqXHR.status || '?' ) + ' ' + ( textStatus || '' );
				if ( jqXHR.responseJSON && typeof jqXHR.responseJSON.data === 'string' ) {
					detail += ' — ' + jqXHR.responseJSON.data;
				} else if ( jqXHR.status === 0 ) {
					detail += ' — connection lost or request timed out.';
				} else if ( jqXHR.status >= 500 ) {
					detail += ' — likely a PHP timeout or memory limit on one of these images. Check your host’s PHP error log for this time.';
				}
				logError( label, detail.trim() );

				bulkState.index += batch.length;
				updateBulkProgress( bulkState.index, bulkState.index );
				processNextBatch();
			} );
	}

	$( document ).on( 'click', '#sio-start-bulk', function () {
		bulkState = {
			ids: [], index: 0, stopped: false,
			totalOrig: 0, totalNew: 0, optimized: 0, skipped: 0, kept: 0, errors: 0,
			msPerImage: null
		};
		errorCount = 0;
		$( '#sio-bulk-summary' ).empty();
		$( '#sio-bulk-log' ).empty();
		$( '#sio-bulk-log-wrap' ).hide();
		$( '#sio-bulk-error-count' ).text( '0' );
		$( this ).prop( 'disabled', true ).text( 'Fetching image list…' );
		$( '#sio-stop-bulk' ).show();

		ajax( 'sio_get_attachment_ids', {} ).done( function ( resp ) {
			if ( ! resp.success ) {
				$( '#sio-start-bulk' ).prop( 'disabled', false ).text( 'Optimise all images' );
				return;
			}
			bulkState.ids = resp.data.ids;
			$( '#sio-bulk-progress' ).show();
			$( '#sio-start-bulk' ).text( 'Optimising…' );
			updateBulkProgress( 0, 0 );
			processNextBatch();
		} );
	} );

	$( document ).on( 'click', '#sio-stop-bulk', function () {
		bulkState.stopped = true;
		$( this ).hide();
	} );

	// --- Media Library row actions -------------------------------------------

	$( document ).on( 'click', '.sio-optimize-now', function ( e ) {
		e.preventDefault();
		var $link = $( this );
		var id    = $link.data( 'id' );
		$link.text( 'Optimising…' );

		ajax( 'sio_optimize_single', { id: id } ).done( function ( resp ) {
			if ( resp.success ) {
				location.reload();
			} else {
				$link.text( 'Failed — retry' );
			}
		} );
	} );

	$( document ).on( 'click', '.sio-restore', function ( e ) {
		e.preventDefault();
		if ( ! confirm( 'Restore the original file? This replaces the optimised version.' ) ) {
			return;
		}
		var $link = $( this );
		var id    = $link.data( 'id' );
		$link.text( 'Restoring…' );

		ajax( 'sio_restore_single', { id: id } ).done( function ( resp ) {
			if ( resp.success ) {
				location.reload();
			} else {
				$link.text( 'Failed — retry' );
			}
		} );
	} );

	// --- Manual "delete all backups now" (Settings tab) ----------------------

	$( document ).on( 'click', '#sio-purge-backups', function ( e ) {
		e.preventDefault();
		if ( ! confirm( 'Delete every stored backup now? You will no longer be able to restore originals that have already been optimised. This cannot be undone.' ) ) {
			return;
		}
		var $link = $( this );
		$link.text( 'Deleting…' );

		ajax( 'sio_purge_backups', {} ).done( function ( resp ) {
			if ( resp.success ) {
				location.reload();
			} else {
				$link.text( 'Failed — retry' );
			}
		} );
	} );
} )( jQuery );
