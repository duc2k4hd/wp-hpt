<?php
/**
 * Admin UI, background queue, and protected download integration.
 *
 * @package WooCommerce\Export
 */

namespace Automattic\WooCommerce\Internal\LargeExport;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Keep HTTP requests limited to job control and bounded file delivery.
 */
final class Integration {
	const WORKER_HOOK  = 'woocommerce_large_product_export_worker';
	const SWEEP_HOOK   = 'woocommerce_large_product_export_sweep';
	const ACTION_GROUP = 'woocommerce-large-product-export';
	const ADMIN_PAGE   = 'wc-large-product-export';

	/** @var Store|null */
	private static $store;

	/** @var Files|null */
	private static $files;

	/** @var Engine|null */
	private static $engine;

	/** Register the opt-in admin and queue entry points. */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_woocommerce_large_export_download', array( __CLASS__, 'download' ) );
		add_action( 'wp_ajax_woocommerce_large_export_create', array( __CLASS__, 'create' ) );
		add_action( 'wp_ajax_woocommerce_large_export_status', array( __CLASS__, 'status' ) );
		add_action( 'wp_ajax_woocommerce_large_export_control', array( __CLASS__, 'control' ) );
		add_action( self::WORKER_HOOK, array( __CLASS__, 'run_worker' ) );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule_sweeper' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule_sweeper' ), 20 );
	}

	/** Register a one-minute fallback interval when Action Scheduler is unavailable. */
	public static function cron_schedules( array $schedules ): array {
		$schedules['wc_large_export_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (WooCommerce large export recovery)', 'woocommerce' ),
		);
		return $schedules;
	}

	/** Ensure abandoned worker leases and queued work are revisited after process failure. */
	public static function schedule_sweeper(): void {
		if ( 'cli' === self::runner() ) {
			return;
		}
		if ( 'cron' !== self::runner() && function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( as_has_scheduled_action( self::SWEEP_HOOK, array(), self::ACTION_GROUP ) ) {
				return;
			}
			$action_id = as_schedule_recurring_action( time() + 60, 60, self::SWEEP_HOOK, array(), self::ACTION_GROUP, true );
			if ( $action_id ) {
				return;
			}
		}
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 60, 'wc_large_export_minute', self::SWEEP_HOOK );
		}
	}

	/** Requeue eligible work when a process died after its durable lease expired. */
	public static function sweep(): void {
		if ( 'cli' === self::runner() ) {
			return;
		}
		try {
			$store = self::store();
			foreach ( $store->due() as $job_id ) {
				$pending = wp_next_scheduled( self::WORKER_HOOK, array( $job_id ) );
				if ( 'cron' !== self::runner() && function_exists( 'as_has_scheduled_action' ) ) {
					$pending = $pending || as_has_scheduled_action( self::WORKER_HOOK, array( $job_id ), self::ACTION_GROUP );
				}
				if ( ! $pending ) {
					$job = $store->get( $job_id );
					if ( $job ) {
						self::enqueue( $job_id, max( 1, (int) $job['available_at'] - time() ) );
					}
				}
			}
			self::engine()->sweep();
		} catch ( Throwable $exception ) {
			error_log( 'WooCommerce large product export recovery sweep could not complete.' );
		}
	}

	/** Register a separate page; the stock exporter and its hooks remain available. */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Large Product Export', 'woocommerce' ),
			__( 'Large Export', 'woocommerce' ),
			'export',
			self::ADMIN_PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/** Load the small admin controller only on the new export page. */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'product_page_' . self::ADMIN_PAGE, 'product_page_product_exporter' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-large-product-export',
			WC()->plugin_url() . '/assets/js/admin/wc-large-product-export.js',
			array( 'jquery' ),
			defined( 'WC_VERSION' ) ? WC_VERSION : false,
			true
		);
		wp_localize_script(
			'wc-large-product-export',
			'wc_large_product_export_params',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'woocommerce-large-product-export-ui' ),
				'poll_interval' => max( 2000, min( 15000, (int) apply_filters( 'woocommerce_large_export_poll_interval_ms', 4000 ) ) ),
				'messages'      => array(
					'working' => __( 'Processing the export in the background…', 'woocommerce' ),
					'error'   => __( 'The export request failed. The saved job can be checked or resumed.', 'woocommerce' ),
				),
			)
		);
	}

	/** Render current-user jobs and the asynchronous export form. */
	public static function render_page(): void {
		self::require_export_capability();
		$jobs  = array();
		$error = '';
		$ready = defined( 'WC_LARGE_PRODUCT_EXPORT' ) && true === WC_LARGE_PRODUCT_EXPORT;
		try {
			if ( $ready ) {
				self::files();
				$jobs = self::store()->recent( get_current_user_id() );
			}
		} catch ( Throwable $exception ) {
			$error = $exception->getMessage();
		}

		$exporter      = new \WC_Product_CSV_Exporter();
		$product_types = \WC_Admin_Exporters::get_product_types();
		$categories    = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 5000,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		$selected_ids = array();
		if ( ! empty( $_GET['product_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Mirrors the core selected-products exporter.
			check_admin_referer( 'export-selected-products' );
			$selected_ids = array_values( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['product_ids'] ) ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		include WC_ABSPATH . 'includes/admin/views/html-admin-page-large-product-export.php';
	}

	/** Create one small durable job, then schedule its independent worker. */
	public static function create(): void {
		check_ajax_referer( 'woocommerce-large-product-export-ui', 'security' );
		self::require_export_capability_json();

		try {
			$ids = array();
			if ( ! empty( $_POST['product_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$raw_ids = explode( ',', sanitize_text_field( wp_unslash( $_POST['product_ids'] ) ) );
				if ( count( $raw_ids ) > 2000 ) {
					throw new \InvalidArgumentException( __( 'Export selected products in jobs of at most 2,000 IDs.', 'woocommerce' ) );
				}
				$ids = array_values( array_filter( array_map( 'absint', $raw_ids ) ) );
			}

			$options = array(
				'mode'        => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'compatibility', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'format'      => isset( $_POST['file_format'] ) ? sanitize_key( wp_unslash( $_POST['file_format'] ) ) : 'csv', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'columns'     => self::post_strings( 'columns', 1000 ),
				'types'       => self::post_strings( 'types', 100 ),
				'categories'  => self::post_strings( 'categories', 500 ),
				'ids'         => $ids,
				'meta'        => ! empty( $_POST['meta'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'spreadsheet' => ! empty( $_POST['spreadsheet'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'bom'         => ! empty( $_POST['bom'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'excel_view'  => ! empty( $_POST['excel_view'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			);
			if ( isset( $_POST['request_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$request_id = sanitize_text_field( wp_unslash( $_POST['request_id'] ) );
				if ( ! preg_match( '/\A[A-Za-z0-9_-]{16,128}\z/D', $request_id ) ) {
					throw new \InvalidArgumentException( __( 'Invalid export request ID.', 'woocommerce' ) );
				}
				$options['request_id'] = $request_id;
			}
			$job = self::engine()->create( get_current_user_id(), $options );
			self::enqueue( $job['id'] );
			wp_send_json_success( self::summary( $job ) );
		} catch ( \InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		} catch ( Throwable $exception ) {
			wp_send_json_error( array( 'message' => __( 'The export job could not be created. Check the configured private storage and database, then retry.', 'woocommerce' ) ), 500 );
		}
	}

	/** Return persisted progress, not a browser-generated estimate. */
	public static function status(): void {
		check_ajax_referer( 'woocommerce-large-product-export-ui', 'security' );
		self::require_export_capability_json();

		try {
			if ( empty( $_POST['job_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$jobs = self::store()->recent( get_current_user_id() );
			} else {
				$job = self::store()->get( sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				self::require_job_access( $job );
				$jobs = array( $job );
			}
			wp_send_json_success( array_map( array( __CLASS__, 'summary' ), $jobs ) );
		} catch ( Throwable $exception ) {
			wp_send_json_error( array( 'message' => __( 'The export job status could not be read.', 'woocommerce' ) ), 500 );
		}
	}

	/** Apply an authorized pause, resume, cancel, or retry command. */
	public static function control(): void {
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! wp_verify_nonce( isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '', 'woocommerce-large-export-' . $job_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_send_json_error( array( 'message' => __( 'The export control token has expired. Refresh the page.', 'woocommerce' ) ), 403 );
		}
		self::require_export_capability_json();

		try {
			$job = self::store()->get( $job_id );
			self::require_job_access( $job );
			$action = isset( $_POST['control'] ) ? sanitize_key( wp_unslash( $_POST['control'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! in_array( $action, array( 'pause', 'resume', 'cancel', 'retry' ), true ) ) {
				throw new \InvalidArgumentException( __( 'Unknown export control action.', 'woocommerce' ) );
			}
			$job = self::store()->control( $job_id, $action );
			if ( in_array( $job['status'], Engine::ACTIVE, true ) ) {
				self::enqueue( $job_id, max( 0, (int) $job['available_at'] - time() ) );
			}
			wp_send_json_success( self::summary( $job ) );
		} catch ( \InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		} catch ( Throwable $exception ) {
			wp_send_json_error( array( 'message' => __( 'The export control could not be applied.', 'woocommerce' ) ), 500 );
		}
	}

	/** Run a single bounded worker window and enqueue the next window if needed. */
	public static function run_worker( $job_id ): void {
		if ( 'cli' === self::runner() ) {
			return;
		}
		if ( ! is_string( $job_id ) || ! preg_match( '/\A[0-9a-f-]{36}\z/D', $job_id ) ) {
			return;
		}
		try {
			$job = self::engine()->run( $job_id, 5 );
			if ( $job && in_array( $job['status'], Engine::ACTIVE, true ) ) {
				$delay = max( 2, (int) $job['available_at'] - time() );
				self::enqueue( $job_id, min( 900, $delay ) );
			}
		} catch ( Throwable $exception ) {
			// Keep request data and third-party exception messages out of server logs.
			error_log( 'WooCommerce large product export worker could not complete a scheduled window.' );
			self::schedule_fallback( $job_id, 30 );
		}
	}

	/** Serve only a verified artifact belonging to the current authorized user. */
	public static function download(): void {
		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'woocommerce-large-export-download-' . $job_id );
		self::require_export_capability();

		try {
			$job = self::store()->get( $job_id );
			self::require_job_access( $job );
			if ( 'completed' !== $job['status'] || empty( $job['checkpoint']['checksum'] ) ) {
				wp_die( esc_html__( 'This export file is not yet verified and ready to download.', 'woocommerce' ), '', array( 'response' => 409 ) );
			}
			$extension = 'zip' === ( $job['config']['format'] ?? 'csv' ) ? 'zip' : 'csv';
			$proof     = 'zip' === $extension ? ( $job['checkpoint']['archive'] ?? array() ) : $job['checkpoint'];
			self::serve_file( self::files()->path( $job_id, $extension, $proof ), $job );
		} catch ( Throwable $exception ) {
			wp_die( esc_html__( 'The verified export file is unavailable.', 'woocommerce' ), '', array( 'response' => 404 ) );
		}
	}

	/**
	 * Stream the verified artifact in bounded blocks, or let an explicitly configured
	 * internal web-server location handle the transfer.
	 *
	 * @param string $path Verified file path.
	 * @param array  $job  Persisted job.
	 */
	private static function serve_file( string $path, array $job ): void {
		$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			header( 'Allow: GET, HEAD' );
			wp_die( esc_html__( 'This download only supports GET and HEAD.', 'woocommerce' ), '', array( 'response' => 405 ) );
		}
		clearstatcache( true, $path );
		$size = filesize( $path );
		if ( false === $size || ! is_file( $path ) || is_link( $path ) ) {
			wp_die( esc_html__( 'The verified export file is unavailable.', 'woocommerce' ), '', array( 'response' => 404 ) );
		}

		$extension = 'zip' === ( $job['config']['format'] ?? 'csv' ) ? 'zip' : 'csv';
		$proof     = 'zip' === $extension ? $job['checkpoint']['archive'] : $job['checkpoint'];
		$checksum  = (string) $proof['checksum'];
		$etag      = '"' . $checksum . '"';
		$modified  = (int) ( $job['completed_at'] ? $job['completed_at'] : $job['updated_at'] );
		$range     = self::requested_range( $size, $etag, $modified );
		if ( false === $range ) {
			header( 'Content-Range: bytes */' . $size );
			wp_die( esc_html__( 'The requested download range is not valid.', 'woocommerce' ), '', array( 'response' => 416 ) );
		}

		$start        = null === $range ? 0 : $range[0];
		$length       = null === $range ? $size : $range[1] - $range[0] + 1;
		$status       = null === $range ? 200 : 206;
		$filename     = 'products-' . $job['id'] . '.' . $extension;
		$accel_prefix = defined( 'WC_LARGE_EXPORT_X_ACCEL_PREFIX' ) ? WC_LARGE_EXPORT_X_ACCEL_PREFIX : '';
		$accel        = is_string( $accel_prefix ) && preg_match( '~\A/[A-Za-z0-9/_-]+\z~D', $accel_prefix ) && false === strpos( $accel_prefix, '..' );
		$sendfile     = defined( 'WC_LARGE_EXPORT_X_SENDFILE' ) && true === WC_LARGE_EXPORT_X_SENDFILE;
		if ( 'GET' === $method && ! $accel && ! $sendfile ) {
			$download_lock = self::files()->download_lock();
			if ( ! $download_lock ) {
				header( 'Retry-After: 10' );
				wp_die( esc_html__( 'Another export download is running. Try again when it finishes.', 'woocommerce' ), '', array( 'response' => 429 ) );
			}
		}
		// WordPress/plugins may leave an output buffer active. A bounded filesystem
		// stream is not bounded if that buffer accumulates the complete response.
		while ( ob_get_level() > 0 ) {
			if ( ! Files::io(
				static function () {
					return ob_end_clean(); }
			) ) {
				wp_die( esc_html__( 'The server output buffer cannot be disabled for this download.', 'woocommerce' ), '', array( 'response' => 500 ) );
			}
		}
		$compression = strtolower( (string) ini_get( 'zlib.output_compression' ) );
		if ( ! in_array( $compression, array( '', '0', 'off' ), true ) && false === Files::io(
			static function () {
				return ini_set( 'zlib.output_compression', '0' ); }
		) ) {
			wp_die( esc_html__( 'The server compression setting prevents a byte-exact download.', 'woocommerce' ), '', array( 'response' => 500 ) );
		}
		status_header( $status );
		header( 'Content-Type: ' . ( 'zip' === $extension ? 'application/zip' : 'text/csv; charset=utf-8' ) );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Accept-Ranges: bytes' );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
		header( 'Content-Length: ' . $length );
		if ( null !== $range ) {
			header( 'Content-Range: bytes ' . $start . '-' . ( $start + $length - 1 ) . '/' . $size );
		}

		if ( $accel ) {
			header( 'X-Accel-Redirect: ' . rtrim( $accel_prefix, '/' ) . '/' . self::files()->relative_path( $job['id'], $extension ) );
			exit;
		}
		if ( $sendfile ) {
			header( 'X-Sendfile: ' . $path );
			exit;
		}
		if ( 'HEAD' === $method ) {
			exit;
		}

		$stream = fopen( $path, 'rb' );
		if ( false === $stream || 0 !== fseek( $stream, $start ) ) {
			if ( is_resource( $stream ) ) {
				fclose( $stream );
			}
			wp_die( esc_html__( 'The verified export file could not be opened.', 'woocommerce' ), '', array( 'response' => 500 ) );
		}
		$output = fopen( 'php://output', 'wb' );
		if ( false === $output ) {
			fclose( $stream );
			wp_die( esc_html__( 'The verified export file could not be streamed.', 'woocommerce' ), '', array( 'response' => 500 ) );
		}
		$sent = stream_copy_to_stream( $stream, $output, $length );
		fclose( $stream );
		fclose( $output );
		if ( false === $sent || $sent !== $length ) {
			error_log( 'WooCommerce large product export download ended before the verified byte range was sent.' );
		}
		exit;
	}

	/** Parse one RFC 7233 byte range, honoring If-Range validators. */
	private static function requested_range( int $size, string $etag, int $modified ) {
		if ( empty( $_SERVER['HTTP_RANGE'] ) ) {
			return null;
		}
		// WordPress also magic-quotes $_SERVER; quoted ETags must be unslashed.
		$if_range = isset( $_SERVER['HTTP_IF_RANGE'] ) ? trim( wp_unslash( $_SERVER['HTTP_IF_RANGE'] ) ) : '';
		if ( '' !== $if_range && ! hash_equals( $etag, $if_range ) && strtotime( $if_range ) !== $modified ) {
			return null;
		}
		$header = trim( wp_unslash( (string) $_SERVER['HTTP_RANGE'] ) );
		if ( ! preg_match( '/\Abytes=([0-9]*)-([0-9]*)\z/D', $header, $matches ) || 0 === $size ) {
			return false;
		}
		if ( '' === $matches[1] ) {
			$suffix = (int) $matches[2];
			return $suffix > 0 ? array( max( 0, $size - $suffix ), $size - 1 ) : false;
		}
		$start = (int) $matches[1];
		$end   = '' === $matches[2] ? $size - 1 : min( (int) $matches[2], $size - 1 );
		return $start < $size && $end >= $start ? array( $start, $end ) : false;
	}

	/** Queue a job independently of any browser request. */
	public static function enqueue( string $job_id, int $delay = 0 ): void {
		if ( 'cli' === self::runner() ) {
			return;
		}
		$timestamp = time() + max( 1, min( 900, $delay ) );
		if ( 'cron' !== self::runner() && function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			// Always create one delayed successor: a unique async action could be
			// deduplicated against the currently executing worker action.
			$action_id = as_schedule_single_action( $timestamp, self::WORKER_HOOK, array( $job_id ), self::ACTION_GROUP, false );
			if ( $action_id ) {
				return;
			}
		}
		self::schedule_fallback( $job_id, $delay );
	}

	/** Explicitly isolate CLI-only installations from checkout/email queue runners. */
	private static function runner(): string {
		$runner = defined( 'WC_LARGE_EXPORT_RUNNER' ) ? WC_LARGE_EXPORT_RUNNER : 'action-scheduler';
		if ( ! in_array( $runner, array( 'cli', 'action-scheduler', 'cron' ), true ) ) {
			throw new \RuntimeException( 'Invalid large export runner configuration.' );
		}
		return $runner;
	}

	private static function schedule_fallback( string $job_id, int $delay ): void {
		$args = array( $job_id );
		if ( ! wp_next_scheduled( self::WORKER_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 1, min( 900, $delay ) ), self::WORKER_HOOK, $args );
		}
	}

	/** Return a safe summary, current-user nonce, and verified download URL. */
	public static function summary( array $job ): array {
		$data                  = Engine::summary( $job );
		$data['control_nonce'] = wp_create_nonce( 'woocommerce-large-export-' . $job['id'] );
		// JSON URLs are assigned with jQuery.attr(). wp_nonce_url() HTML-escapes
		// ampersands, making job_id/_wpnonce unreadable by the download endpoint.
		$data['download_url'] = 'completed' === $job['status']
			? add_query_arg( '_wpnonce', wp_create_nonce( 'woocommerce-large-export-download-' . $job['id'] ), admin_url( 'admin-post.php?action=woocommerce_large_export_download&job_id=' . rawurlencode( $job['id'] ) ) )
			: '';
		return $data;
	}

	private static function post_strings( string $key, int $limit ): array {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}
		$values = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $values ) || count( $values ) > $limit ) {
			throw new \InvalidArgumentException( __( 'The export selection is invalid.', 'woocommerce' ) );
		}
		$clean = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 250 ) {
				throw new \InvalidArgumentException( __( 'The export selection is invalid.', 'woocommerce' ) );
			}
			$clean[] = sanitize_text_field( $value );
		}
		return array_values( array_unique( $clean ) );
	}

	private static function require_export_capability(): void {
		if ( ! Engine::allowed( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You are not allowed to export products.', 'woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	private static function require_export_capability_json(): void {
		if ( ! Engine::allowed( get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to export products.', 'woocommerce' ) ), 403 );
		}
	}

	private static function require_job_access( ?array $job ): void {
		if ( ! $job ) {
			throw new \InvalidArgumentException( __( 'The export job does not exist.', 'woocommerce' ) );
		}
		if ( get_current_user_id() !== (int) $job['user_id'] && ! current_user_can( 'manage_options' ) ) {
			throw new \InvalidArgumentException( __( 'You cannot access this export job.', 'woocommerce' ) );
		}
	}

	private static function store(): Store {
		if ( ! self::$store ) {
			self::$store = new Store();
			self::$store->install();
		}
		return self::$store;
	}

	private static function files(): Files {
		if ( ! self::$files ) {
			self::$files = new Files();
		}
		return self::$files;
	}

	private static function engine(): Engine {
		if ( ! self::$engine ) {
			self::$engine = new Engine( self::store(), self::files() );
		}
		return self::$engine;
	}
}
