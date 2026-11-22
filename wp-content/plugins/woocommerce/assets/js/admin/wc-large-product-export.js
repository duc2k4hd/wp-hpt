/* global jQuery, wc_large_product_export_params */
/* eslint-disable camelcase */
;( function ( $ ) {
	'use strict';

	var $form = $( '#wc-large-export-form' );
	if ( ! $form.length ) {
		return;
	}

	var $tbody = $( '#wc-large-export-jobs tbody' );
	var $notice = $( '#wc-large-export-notice' );
	var polling = false;
	var timer = null;
	var $columns = $form.find( 'input[name="columns[]"]' );
	function updateColumnCount() {
		$( '#wc-large-export-column-count' ).text( 'Đã chọn ' + $columns.filter( ':checked' ).length + ' cột' );
	}
	$form.on( 'click', '[data-columns-action]', function () {
		$columns.prop( 'checked', $( this ).attr( 'data-columns-action' ) === 'all' );
		updateColumnCount();
	} );
	$columns.on( 'change', updateColumnCount );
	updateColumnCount();

	function message( text, isError ) {
		$notice.toggleClass( 'notice-error', !! isError ).toggleClass( 'notice-success', ! isError );
		$notice.find( 'p' ).text( text || '' );
		$notice.prop( 'hidden', ! text );
	}

	function request( action, data ) {
		if ( 'string' === typeof data ) {
			data += '&';
			data += $.param( { action: action, security: wc_large_product_export_params.nonce } );
		} else {
			data = data || {};
			data.action = action;
			data.security = wc_large_product_export_params.nonce;
		}
		return $.ajax( {
			url: wc_large_product_export_params.ajax_url,
			type: 'POST',
			data: data,
			dataType: 'json',
			timeout: 30000
		} );
	}

	function button( label, action, job ) {
		return $( '<button type="button" class="button button-small">' )
			.text( label )
			.attr( 'data-control', action )
			.attr( 'data-job-id', job.id )
			.attr( 'data-nonce', job.control_nonce );
	}

	function formatBytes( bytes ) {
		bytes = Number( bytes ) || 0;
		if ( bytes < 1024 ) {
			return bytes + ' B';
		}
		var units = [ 'KB', 'MB', 'GB', 'TB' ];
		var unit = -1;
		do {
			bytes /= 1024;
			unit++;
		} while ( bytes >= 1024 && unit < units.length - 1 );
		return bytes.toFixed( 1 ) + ' ' + units[ unit ];
	}

	function formatSeconds( seconds ) {
		seconds = Number( seconds );
		if ( ! Number.isFinite( seconds ) || seconds < 0 ) {
			return '—';
		}
		var hours = Math.floor( seconds / 3600 );
		var minutes = Math.floor( ( seconds % 3600 ) / 60 );
		var remainder = Math.floor( seconds % 60 );
		return ( hours ? hours + 'h ' : '' ) + ( minutes ? minutes + 'm ' : '' ) + remainder + 's';
	}

	function renderActions( job ) {
		var $actions = $( '<div class="wc-large-export-actions">' );
		if ( job.status === 'pending' || job.status === 'queued' || job.status === 'running' ) {
			$actions.append( button( 'Pause', 'pause', job ), ' ', button( 'Cancel', 'cancel', job ) );
		} else if ( job.status === 'finalizing' || job.status === 'verifying' ) {
			$actions.append( button( 'Pause', 'pause', job ), ' ', button( 'Cancel', 'cancel', job ) );
		} else if ( job.status === 'pausing' ) {
			$actions.append( button( 'Cancel', 'cancel', job ) );
		} else if ( job.status === 'paused' ) {
			$actions.append( button( 'Resume', 'resume', job ), ' ', button( 'Cancel', 'cancel', job ) );
		} else if ( job.status === 'failed' ) {
			$actions.append( button( 'Retry', 'retry', job ), ' ', button( 'Cancel', 'cancel', job ) );
		} else if ( job.status === 'completed' && job.download_url ) {
			$actions.append( $( '<a class="button button-primary button-small">' ).attr( 'href', job.download_url ).text( job.format === 'zip' ? 'Download ZIP' : 'Download CSV' ) );
		}
		if ( job.last_error ) {
			$actions.append( $( '<p class="description">' ).text( job.last_error ) );
		}
		if ( job.logs && job.logs.length ) {
			var $details = $( '<details>' ).append( $( '<summary>' ).text( 'View worker log (' + job.logs.length + ')' ) );
			var $list = $( '<ol>' );
			job.logs.forEach( function ( entry ) {
				var label = new Date( Number( entry.at ) * 1000 ).toLocaleString() + ' · ' + ( entry.status || '' );
				if ( entry.message ) {
					label += ' · ' + entry.message;
				}
				$list.append( $( '<li>' ).text( label ) );
			} );
			$actions.append( $details.append( $list ) );
		}
		return $actions;
	}

	function render( jobs ) {
		$tbody.empty();
		if ( ! jobs.length ) {
			$tbody.append( $( '<tr>' ).append( $( '<td colspan="7">' ).text( 'No export jobs yet.' ) ) );
			return;
		}
		jobs.forEach( function ( job ) {
			var $row = $( '<tr>' );
			var progress = job.status === 'completed' ? '100% (verified)' : ( null === job.progress ? 'Total unknown' : job.progress + '% (estimate)' );
			var $progress = $( '<div>' );
			if ( null !== job.progress ) {
				$progress.append( $( '<progress max="100">' ).val( job.progress ) );
			}
			$progress.append( $( '<span>' ).text( Number( job.rows ).toLocaleString() + ( job.estimate ? ' / ~' + Number( job.estimate ).toLocaleString() : '' ) + ' rows · ' + progress ) );
			$row.append( $( '<td>' ).text( job.id ) );
			$row.append( $( '<td>' ).text( job.status + ( job.mode ? ' · ' + job.mode : '' ) ).append( $( '<p class="description">' ).text( 'Current batch: ' + job.batch ) ) );
			$row.append( $( '<td>' ).append( $progress ) );
			$row.append( $( '<td>' ).text( job.rate ? Number( job.rate ).toLocaleString() + ' rows/s' : '—' ) );
			$row.append( $( '<td>' ).text( formatSeconds( job.elapsed ) + ( null !== job.eta ? ' / ~' + formatSeconds( job.eta ) : '' ) ) );
			$row.append( $( '<td>' ).text( job.status === 'completed' ? formatBytes( job.bytes ) : ( job.staging_bytes ? '~' + formatBytes( job.staging_bytes ) + ' temporary chunks' : 'Not finalized' ) ) );
			$row.append( $( '<td>' ).append( renderActions( job ) ) );
			$tbody.append( $row );
		} );
	}

	function poll() {
		if ( timer ) {
			window.clearTimeout( timer );
			timer = null;
		}
		if ( polling ) {
			return;
		}
		polling = true;
		request( 'woocommerce_large_export_status' )
			.done( function ( response ) {
				if ( response && response.success && response.data ) {
					render( response.data );
				}
			} )
			.fail( function () {
				message( wc_large_product_export_params.messages.error, true );
			} )
			.always( function () {
				polling = false;
				timer = window.setTimeout( poll, wc_large_product_export_params.poll_interval );
			} );
	}

	var pendingStart = null;
	$form.on( 'submit', function ( event ) {
		event.preventDefault();
		if ( ! $columns.filter( ':checked' ).length ) {
			message( 'Vui lòng tích chọn ít nhất một cột cần xuất.', true );
			return;
		}
		var selection = $form.serialize();
		// A timed-out Start may already have committed its job. Retain the token
		// for a retry of the same selection until the server acknowledges it.
		if ( ! pendingStart || pendingStart.selection !== selection ) {
			pendingStart = {
				selection: selection,
				id: window.crypto && window.crypto.randomUUID
					? window.crypto.randomUUID().replace( /-/g, '' )
					: ( Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2 ) + Math.random().toString( 36 ).slice( 2 ) ).replace( /[^a-z0-9]/gi, '' )
			};
		}
		var $submit = $form.find( '[type="submit"]' ).prop( 'disabled', true );
		message( wc_large_product_export_params.messages.working, false );
		request( 'woocommerce_large_export_create', selection + '&request_id=' + encodeURIComponent( pendingStart.id ) )
			.done( function ( response ) {
				if ( response && response.success ) {
					pendingStart = null;
					message( 'Export job queued. Processing continues without this page.', false );
					poll();
				} else {
					message( response && response.data && response.data.message ? response.data.message : wc_large_product_export_params.messages.error, true );
				}
			} )
			.fail( function ( xhr ) {
				var response = xhr.responseJSON;
				message( response && response.data && response.data.message ? response.data.message : wc_large_product_export_params.messages.error, true );
			} )
			.always( function () {
				$submit.prop( 'disabled', false );
			} );
	} );

	$tbody.on( 'click', '[data-control]', function () {
		var $button = $( this ).prop( 'disabled', true );
		$.ajax( {
			url: wc_large_product_export_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			timeout: 30000,
			data: {
				action: 'woocommerce_large_export_control',
				job_id: $button.attr( 'data-job-id' ),
				security: $button.attr( 'data-nonce' ),
				control: $button.attr( 'data-control' )
			}
		} ).done( function ( response ) {
			if ( response && response.success ) {
				poll();
			} else {
				message( response && response.data && response.data.message ? response.data.message : wc_large_product_export_params.messages.error, true );
			}
		} ).fail( function ( xhr ) {
			var response = xhr.responseJSON;
			message( response && response.data && response.data.message ? response.data.message : wc_large_product_export_params.messages.error, true );
		} ).always( function () {
			$button.prop( 'disabled', false );
		} );
	} );

	$( window ).on( 'beforeunload', function () {
		if ( timer ) {
			window.clearTimeout( timer );
		}
	} );

	poll();
}( jQuery ) );
