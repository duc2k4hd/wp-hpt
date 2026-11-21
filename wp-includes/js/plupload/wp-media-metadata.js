/* global jQuery */

window.wp = window.wp || {};

( function( wp, $ ) {
	'use strict';

	if ( wp.HPTMediaMetadataQueue ) {
		return;
	}

	var active = 0,
		maxActive = 1,
		uploadsActive = false,
		pending = {},
		completed = {},
		queue = [];

	function complete( job, success, data ) {
		if ( success ) {
			completed[ job.id ] = true;
		}

		delete pending[ job.id ];

		try {
			if ( success ) {
				if ( job.done ) {
					job.done( data );
				}
			} else if ( job.fail ) {
				job.fail( data );
			}
		} finally {
			active--;
			pump();
		}
	}

	function retry( job, data ) {
		if ( job.attempt < 3 ) {
			job.attempt++;
			window.setTimeout( function() {
				run( job );
			}, Math.min( 5000, 500 * job.attempt ) );
			return;
		}

		complete( job, false, data );
	}

	function run( job ) {
		if ( uploadsActive ) {
			window.setTimeout( function() {
				run( job );
			}, 500 );
			return;
		}

		$.ajax( {
			type: 'post',
			url: job.url,
			dataType: 'json',
			data: {
				action: 'media-create-image-subsizes',
				_wpnonce: job.nonce,
				attachment_id: job.id
			}
		} ).done( function( response ) {
			if ( response && response.success && response.data ) {
				complete( job, true, response.data );
			} else {
				retry( job, response );
			}
		} ).fail( function( jqXHR ) {
			if ( jqXHR.status >= 500 || jqXHR.status === 429 ) {
				retry( job, jqXHR );
			} else {
				complete( job, false, jqXHR );
			}
		} );
	}

	function pump() {
		if ( uploadsActive || active >= maxActive || ! queue.length ) {
			return;
		}

		while ( active < maxActive && queue.length ) {
			active++;
			run( queue.shift() );
		}
	}

	wp.HPTMediaMetadataQueue = {
		setUploadsActive: function( activeState ) {
			uploadsActive = !! activeState;

			if ( ! uploadsActive ) {
				pump();
			}
		},

		enqueue: function( id, nonce, done, fail, url ) {
			id = parseInt( id, 10 );

			if ( ! id || pending[ id ] || completed[ id ] ) {
				return;
			}

			pending[ id ] = true;
			queue.push( {
				id:      id,
				nonce:   nonce,
				done:    done,
				fail:    fail,
				url:     url || ( window.ajaxurl || '/wp-admin/admin-ajax.php' ),
				attempt: 0
			} );

			pump();
		}
	};
}( window.wp, window.jQuery ) );
