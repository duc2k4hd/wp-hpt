/*global ajaxurl, wc_product_export_params */
;(function ( $, window ) {
	/**
	 * productExportForm handles the export process.
	 */
	var productExportForm = function( $form ) {
		this.$form = $form;
		this.$error = $( '<div class="notice notice-error inline woocommerce-export-error" role="alert">' ).append( $( '<p>' ) ).hide();
		this.$error.insertBefore( $form.find( 'section' ).first() );

		// Initial state.
		this.$form.find('.woocommerce-exporter-progress').val( 0 );

		// Methods.
		this.processStep = this.processStep.bind( this );

		// Events.
		$form.on( 'submit', { productExportForm: this }, this.onSubmit );
		$form.find( '.woocommerce-exporter-types' ).on( 'change', { productExportForm: this }, this.exportTypeFields );
	};

	/**
	 * Handle export form submission.
	 */
	productExportForm.prototype.onSubmit = function( event ) {
		event.preventDefault();

		var exporter       = event.data.productExportForm,
			currentDate    = new Date(),
			day            = currentDate.getDate(),
			month          = currentDate.getMonth() + 1,
			year           = currentDate.getFullYear(),
			timestamp      = currentDate.getTime(),
			filename       = 'wc-product-export-' + day + '-' + month + '-' + year + '-' + timestamp + '.csv';

		if ( exporter.$form.hasClass( 'woocommerce-exporter__exporting' ) ) {
			return;
		}

		exporter.$error.hide();
		exporter.$form.addClass( 'woocommerce-exporter__exporting' );
		exporter.$form.find( '.woocommerce-exporter-progress' ).val( 0 );
		exporter.$form.find( '.woocommerce-exporter-button' ).prop( 'disabled', true );
		exporter.processStep( 1, $( this ).serialize(), '', filename, 'start' );
	};

	productExportForm.prototype.stop = function( message ) {
		this.$form.removeClass( 'woocommerce-exporter__exporting' );
		this.$form.find( '.woocommerce-exporter-button' ).prop( 'disabled', false );
		if ( message ) {
			this.$error.find( 'p' ).text( message );
			this.$error.show();
		}
	};

	/**
	 * Process the current export step.
	 */
	productExportForm.prototype.processStep = function( step, data, columns, filename, cursor ) {
		var $this              = this,
			selected_columns   = $( '.woocommerce-exporter-columns' ).val(),
			export_meta        = $( '#woocommerce-exporter-meta:checked' ).length ? 1: 0,
			export_types       = $( '.woocommerce-exporter-types' ).val(),
			export_category    = $( '.woocommerce-exporter-category' ).val(),
			export_product_ids = $this.$form.find('input[name="product_ids"]').val() || '';

		$.ajax( {
			type: 'POST',
			url: ajaxurl,
			timeout: 90000,
			data: {
				form               : data,
				excel_view         : $( '#woocommerce-exporter-excel-view:checked' ).length ? 1 : 0,
				action             : 'woocommerce_do_ajax_product_export',
				step               : step,
				columns            : columns,
				selected_columns   : selected_columns,
				export_meta        : export_meta,
				export_types       : export_types,
				export_category    : export_category,
				export_product_ids : export_product_ids,
				filename           : filename,
				export_cursor      : cursor || '',
				security           : wc_product_export_params.export_nonce
			},
			dataType: 'json',
			success: function( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					$this.stop( response && response.data && response.data.message || wc_product_export_params.export_error );
					return;
				}

				$this.$form.find( '.woocommerce-exporter-progress' ).val( response.data.percentage );
				if ( 'done' === response.data.step && response.data.url ) {
					window.location = response.data.url;
					window.setTimeout( function() { $this.stop(); }, 2000 );
					return;
				}

				var nextStep = parseInt( response.data.step, 10 );
				if ( nextStep !== step + 1 ) {
					$this.stop( wc_product_export_params.export_error );
					return;
				}

				$this.processStep( nextStep, data, response.data.columns, filename, response.data.cursor );
			}
		} ).fail( function( response, status ) {
			var error = response.responseJSON && response.responseJSON.data;
			if ( error && error.message ) {
				$this.stop( error.message );
				return;
			}

			var reason = 'timeout' === status ? wc_product_export_params.export_timeout : wc_product_export_params.export_error;
			if ( response.status >= 400 ) {
				reason += ' (HTTP ' + response.status + ')';
			}
			$this.stop( reason + ' ' + wc_product_export_params.export_error_batch.replace( '%d', step ) );
		} );
	};

	/**
	 * Handle fields per export type.
	 */
	productExportForm.prototype.exportTypeFields = function() {
		var exportCategory = $( '.woocommerce-exporter-category' );

		if ( -1 !== $.inArray( 'variation', $( this ).val() ) ) {
			exportCategory.closest( 'tr' ).hide();
			exportCategory.val( '' ).trigger( 'change' ); // Reset WooSelect selected value.
		} else {
			exportCategory.closest( 'tr' ).show();
		}
	};

	/**
	 * Function to call productExportForm on jquery selector.
	 */
	$.fn.wc_product_export_form = function() {
		new productExportForm( this );
		return this;
	};

	$( '.woocommerce-exporter' ).wc_product_export_form();

})( jQuery, window );
