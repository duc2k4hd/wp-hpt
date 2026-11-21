<?php
/**
 * Handles Batch CSV export.
 *
 * Based on https://pippinsplugins.com/batch-processing-for-big-data/
 *
 * @package  WooCommerce\Export
 * @version  3.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Include dependencies.
 */
if ( ! class_exists( 'WC_CSV_Exporter', false ) ) {
	require_once WC_ABSPATH . 'includes/export/abstract-wc-csv-exporter.php';
}

/**
 * WC_CSV_Exporter Class.
 */
abstract class WC_CSV_Batch_Exporter extends WC_CSV_Exporter {

	/**
	 * Page being exported
	 *
	 * @var integer
	 */
	protected $page = 1;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->column_names = $this->get_default_column_names();
	}

	/**
	 * Get file path to export to.
	 *
	 * @return string
	 */
	protected function get_file_path() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . $this->get_filename();
	}

	/**
	 * Get CSV headers row file path to export to.
	 *
	 * @return string
	 */
	protected function get_headers_row_file_path() {
		return $this->get_file_path() . '.headers';
	}

	/**
	 * Get the contents of the CSV headers row file. Defaults to the original known headers.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function get_headers_row_file() {

		$file = chr( 239 ) . chr( 187 ) . chr( 191 ) . $this->export_column_headers();

		if ( @file_exists( $this->get_headers_row_file_path() ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$file = @file_get_contents( $this->get_headers_row_file_path() ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
		}

		return $file;
	}

	/**
	 * Get the file contents.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function get_file() {
		$file = '';
		if ( @file_exists( $this->get_file_path() ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$file = @file_get_contents( $this->get_file_path() ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
		} else {
			@file_put_contents( $this->get_file_path(), '' ); // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_file_put_contents, Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			@chmod( $this->get_file_path(), 0664 ); // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.chmod_chmod, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents, Generic.PHP.NoSilencedErrors.Discouraged
		}
		return $file;
	}

	/**
	 * Serve the file and remove once sent to the client.
	 *
	 * @since 3.1.0
	 */
	public function export() {
		$legacy_output_is_overridden = 'WC_CSV_Exporter' !== ( new ReflectionMethod( $this, 'send_content' ) )->getDeclaringClass()->getName()
			|| __CLASS__ !== ( new ReflectionMethod( $this, 'get_file' ) )->getDeclaringClass()->getName()
			|| __CLASS__ !== ( new ReflectionMethod( $this, 'get_headers_row_file' ) )->getDeclaringClass()->getName();

		$this->send_headers();

		if ( $legacy_output_is_overridden ) {
			// Preserve custom exporters that override the public string-based output API.
			$this->send_content( $this->get_headers_row_file() . $this->get_file() );
		} else {
			// Keep the existing behavior of creating an empty file if no export exists.
			if ( ! file_exists( $this->get_file_path() ) ) {
				$this->get_file();
			}

			$output = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen

			if ( false !== $output ) {
				$headers_path = $this->get_headers_row_file_path();
				$headers      = file_exists( $headers_path ) ? fopen( $headers_path, 'rb' ) : false;

				if ( false !== $headers ) {
					$header_size   = (int) fstat( $headers )['size'];
					$header_copied = stream_copy_to_stream( $headers, $output );
					fclose( $headers );
					$headers_copied = false !== $header_copied && $header_size === $header_copied;
				} else {
					$header          = chr( 239 ) . chr( 187 ) . chr( 191 ) . $this->export_column_headers();
					$header_copied   = fwrite( $output, $header );
					$headers_copied  = false !== $header_copied && strlen( $header ) === $header_copied;
				}

				$body = fopen( $this->get_file_path(), 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
				if ( false !== $body ) {
					$body_size   = (int) fstat( $body )['size'];
					$body_copied = stream_copy_to_stream( $body, $output );
					$body_copied = false !== $body_copied && $body_size === $body_copied;
				} else {
					$body_copied = false;
				}
				$copied = $headers_copied && $body_copied;

				if ( false !== $body ) {
					fclose( $body );
				}

				fclose( $output );

				if ( ! $copied ) {
					wc_get_logger()->error(
						sprintf(
							/* translators: %s is file path. */
							__( 'Unable to stream the CSV export file %s.', 'woocommerce' ),
							esc_html( $this->get_file_path() )
						)
					);
					wp_die( esc_html__( 'The CSV export could not be streamed. The generated file has been retained for another attempt.', 'woocommerce' ) );
				}
			} else {
				wp_die( esc_html__( 'The CSV export could not be opened for streaming. The generated file has been retained for another attempt.', 'woocommerce' ) );
			}
		}

		@unlink( $this->get_file_path() ); // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_unlink, Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $this->get_headers_row_file_path() ); // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_unlink, Generic.PHP.NoSilencedErrors.Discouraged
		die();
	}

	/**
	 * Generate the CSV file.
	 *
	 * @since 3.1.0
	 */
	public function generate_file() {
		if ( 1 === $this->get_page() ) {
			@unlink( $this->get_file_path() ); // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_unlink, Generic.PHP.NoSilencedErrors.Discouraged,

			// We need to initialize the file here.
			$this->get_file();
		}
		$this->prepare_data_to_export();
		$this->write_csv_data( $this->get_csv_data() );
	}

	/**
	 * Write data to the file.
	 *
	 * @since 3.1.0
	 * @param string $data Data.
	 */
	protected function write_csv_data( $data ) {

		if ( ! file_exists( $this->get_file_path() ) || ! is_writeable( $this->get_file_path() ) ) {
			wc_get_logger()->error(
				sprintf(
					/* translators: %s is file path. */
					__( 'Unable to create or write to %s during CSV export. Please check file permissions.', 'woocommerce' ),
					esc_html( $this->get_file_path() )
				)
			);
			return false;
		}

		/**
		 * Filters the mode parameter which specifies the type of access you require to the stream (used during file
		 * writing for CSV exports). Defaults to 'a+' (which supports both reading and writing, and places the file
		 * pointer at the end of the file).
		 *
		 * @see   https://www.php.net/manual/en/function.fopen.php
		 * @since 6.8.0
		 *
		 * @param string $fopen_mode, either (r, r+, w, w+, a, a+, x, x+, c, c+, e)
		 */
		$fopen_mode = apply_filters( 'woocommerce_csv_exporter_fopen_mode', 'a+' );
		$fp         = fopen( $this->get_file_path(), $fopen_mode );

		if ( $fp ) {
			fwrite( $fp, $data );
			fclose( $fp );
		}

		// Add all columns when finished.
		if ( 100 === $this->get_percent_complete() ) {
			$header = chr( 239 ) . chr( 187 ) . chr( 191 ) . $this->export_column_headers();

			// We need to use a temporary file to store headers, this will make our life so much easier.
			@file_put_contents( $this->get_headers_row_file_path(), $header ); //phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_file_put_contents, Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}

	}

	/**
	 * Get page.
	 *
	 * @since 3.1.0
	 * @return int
	 */
	public function get_page() {
		return $this->page;
	}

	/**
	 * Set page.
	 *
	 * @since 3.1.0
	 * @param int $page Page Nr.
	 */
	public function set_page( $page ) {
		$this->page = absint( $page );
	}

	/**
	 * Get count of records exported.
	 *
	 * @since 3.1.0
	 * @return int
	 */
	public function get_total_exported() {
		return ( ( $this->get_page() - 1 ) * $this->get_limit() ) + $this->exported_row_count;
	}

	/**
	 * Get total % complete.
	 *
	 * @since 3.1.0
	 * @return int
	 */
	public function get_percent_complete() {
		return $this->total_rows ? (int) floor( ( $this->get_total_exported() / $this->total_rows ) * 100 ) : 100;
	}
}
