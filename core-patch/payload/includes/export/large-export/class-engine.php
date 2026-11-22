<?php
/**
 * Bounded export worker and immutable job configuration.
 *
 * @package WooCommerce\Export
 */

namespace Automattic\WooCommerce\Internal\LargeExport;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Engine {
	public const STATUSES = array( 'private', 'publish', 'draft', 'future', 'pending' );
	public const ACTIVE   = array( 'pending', 'queued', 'running', 'pausing', 'finalizing', 'verifying', 'cancelling' );

	/** @var Store */
	private $store;

	/** @var Files */
	private $files;

	public function __construct( Store $store, Files $files ) {
		$this->store = $store;
		$this->files = $files;
	}

	public static function allowed( int $user ): bool {
		return $user > 0 && user_can( $user, 'edit_products' ) && user_can( $user, 'export' );
	}

	public function create( int $user, array $options ): array {
		if ( ! self::allowed( $user ) ) {
			throw new InvalidArgumentException( 'Insufficient privileges to export products.' );
		}
		if ( ! self::read_only_supported() ) {
			throw new InvalidArgumentException( 'Large export requires the read-only variation data-store patch. Reapply the verified core patch.' );
		}
		if ( ! wp_cache_supports( 'flush_runtime' ) ) {
			throw new InvalidArgumentException( 'Large export requires request-local object-cache flushing. Configure a compatible cache drop-in.' );
		}
		require_once __DIR__ . '/class-reader.php';
		if ( has_filter( 'woocommerce_product_export_rows' ) ) {
			throw new InvalidArgumentException( 'An extension transforms whole CSV batches. Use the legacy exporter for that extension.' );
		}

		$config = array(
			'version'            => 1,
			'mode'               => $options['mode'] ?? 'compatibility',
			'format'             => $options['format'] ?? 'csv',
			'columns'            => self::strings( $options['columns'] ?? array(), 1000 ),
			'types'              => self::strings( $options['types'] ?? array(), 100 ),
			'categories'         => array_map( 'sanitize_title_with_dashes', self::strings( $options['categories'] ?? array(), 500 ) ),
			'ids'                => array(),
			'statuses'           => self::strings( $options['statuses'] ?? array(), count( self::STATUSES ) ),
			'meta'               => ! empty( $options['meta'] ),
			'bom'                => ! isset( $options['bom'] ) || (bool) $options['bom'],
			'spreadsheet'        => ! isset( $options['spreadsheet'] ) || (bool) $options['spreadsheet'],
			'excel_view'         => ! empty( $options['excel_view'] ),
			'excel_cell_version' => 2,
			'html_clean_version' => 1,
			'estimate'           => ! isset( $options['estimate'] ) || (bool) $options['estimate'],
			'consistency'        => 'live',
		);
		if ( $config['excel_view'] ) {
			$config['bom']         = true;
			$config['spreadsheet'] = true;
		}
		if ( ! in_array( $config['mode'], array( 'keyset', 'compatibility' ), true ) ) {
			throw new InvalidArgumentException( 'Unknown export mode.' );
		}
		if ( ! in_array( $config['format'], array( 'csv', 'zip' ), true ) ) {
			throw new InvalidArgumentException( 'Unknown export file format.' );
		}
		if ( 'zip' === $config['format'] && ! method_exists( \HashContext::class, '__serialize' ) ) {
			throw new InvalidArgumentException( 'Resumable ZIP64 packaging requires PHP 8.0 or newer. Choose CSV on PHP 7.4.' );
		}
		$available_types = \WC_Admin_Exporters::get_product_types();
		if ( array_diff( $config['types'], array_keys( $available_types ) ) || array_diff( $config['statuses'], self::STATUSES ) ) {
			throw new InvalidArgumentException( 'Invalid product type or status.' );
		}
		$config['types']          = $config['types'] ? $config['types'] : array_keys( $available_types );
		$config['statuses']       = $config['statuses'] ? $config['statuses'] : self::STATUSES;
		$config['child_statuses'] = empty( $options['statuses'] ) ? ( new \WC_Product_Query() )->get( 'status' ) : $config['statuses'];
		$ids                      = $options['ids'] ?? array();
		if ( ! is_array( $ids ) || count( $ids ) > 2000 ) {
			throw new InvalidArgumentException( 'Select at most 2000 product IDs per job.' );
		}
		foreach ( $ids as $id ) {
			if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! ctype_digit( (string) $id ) || (int) $id <= 0 ) {
				throw new InvalidArgumentException( 'Invalid product ID.' );
			}
			$config['ids'][ (int) $id ] = (int) $id;
		}
		$config['ids'] = array_values( $config['ids'] );
		if ( isset( $options['request_id'] ) ) {
			if ( ! is_string( $options['request_id'] ) || ! preg_match( '/^[a-zA-Z0-9_-]{16,128}$/D', $options['request_id'] ) ) {
				throw new InvalidArgumentException( 'Invalid request ID.' );
			}
			$config['request_id'] = $options['request_id'];
		}

		$config['batch_min'] = max( 1, min( 100, (int) apply_filters( 'woocommerce_large_export_batch_min', 25 ) ) );
		$config['batch_max'] = max( $config['batch_min'], min( 100, (int) apply_filters( 'woocommerce_large_export_batch_max', 100 ) ) );
		$config['batch']     = max( $config['batch_min'], min( $config['batch_max'], (int) apply_filters( 'woocommerce_large_export_batch_initial', 50 ) ) );
		$reader              = new Reader( $config );
		if ( ! class_exists( 'DOMDocument' ) ) {
			throw new InvalidArgumentException( 'Product HTML cleaning requires the PHP DOM extension.' );
		}
		// Freeze the insertion boundary at job creation, including when the first
		// worker is not scheduled until later. This is not a transactional snapshot.
		$config['source'] = $reader->source_boundary();
		$delimiter        = $reader->get_delimiter();
		if ( ! is_string( $delimiter ) || 1 !== strlen( $delimiter ) || false !== strpos( "\r\n\"\0", $delimiter ) ) {
			throw new InvalidArgumentException( 'Invalid CSV delimiter.' );
		}
		$config['delimiter'] = $delimiter;
		$this->files->capacity();
		$this->store->install();
		return $this->store->create( $user, $config );
	}

	private static function strings( $values, int $maximum ): array {
		if ( ! is_array( $values ) || count( $values ) > $maximum ) {
			throw new InvalidArgumentException( 'Invalid export selection.' );
		}
		$result = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 250 || '' === $value ) {
				throw new InvalidArgumentException( 'Invalid export selection.' );
			}
			$result[] = wc_clean( $value );
		}
		return array_values( array_unique( $result ) );
	}

	/**
	 * Process as many complete units as fit in a budget. One unit may outlast
	 * the budget if a database query or storage operation stalls.
	 */
	public function run( string $id, int $seconds = 6 ): ?array {
		$lock = $this->files->lock();
		if ( ! $lock ) {
			return $this->store->get( $id );
		}
		$job           = null;
		$read_only     = null;
		$previous_user = get_current_user_id();
		try {
			$job = $this->store->claim( $id );
			if ( ! $job ) {
				return $this->store->get( $id );
			}
			wp_set_current_user( (int) $job['user_id'] );
			$read_only = static function () {
				return false;
			};
			add_filter( 'woocommerce_product_variation_update_title_on_read', $read_only, PHP_INT_MAX );
			$deadline = microtime( true ) + max( 1, min( 300, $seconds ) );
			do {
				$start = microtime( true );
				$this->unit( $job );
				$job = $this->store->get( $id );
				if ( $job && ! in_array( $job['status'], array( 'cancelling', 'cancelled', 'expired' ), true ) ) {
					$this->files->manifest( $job );
				}
				if ( ! $job || ! in_array( $job['status'], self::ACTIVE, true ) ) {
					break;
				}
				if ( wp_cache_supports( 'flush_runtime' ) ) {
					wp_cache_flush_runtime();
				}
				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
				$throttle = max( 0, min( 500, (int) apply_filters( 'woocommerce_large_export_throttle_ms', 25 ) ) );
				if ( $throttle ) {
					usleep( $throttle * 1000 );
				}
			} while ( microtime( true ) + ( microtime( true ) - $start ) < $deadline );
		} catch ( Throwable $error ) {
			if ( ! $job ) {
				throw $error;
			}
			// Exception messages from third-party hooks may contain row data or credentials.
			if ( $error instanceof RuntimeException && 'Product export stopped before exceeding its memory budget. Use a smaller background export batch.' === $error->getMessage() ) {
				// Retry the same uncommitted keyset range with fewer objects. OFFSET
				// jobs keep their page width and fail safely instead of skipping rows.
				$current = $this->store->get( $id );
				if ( $current && 'keyset' === ( $current['checkpoint']['mode'] ?? '' ) ) {
					$checkpoint                       = $current['checkpoint'];
					$checkpoint['batch']              = min( \WC_Product_Export_Resources::suggested_batch(), max( 1, (int) floor( min( 100, $checkpoint['batch'] ) / 2 ) ) );
					$checkpoint['resource_batch_max'] = $checkpoint['batch'];
					$this->store->save( $job, array( 'checkpoint' => $checkpoint ) );
				}
			}
			$message = get_class( $error ) === RuntimeException::class && in_array( $error->getMessage(), self::public_errors(), true )
				? $error->getMessage() : 'A worker unit failed. Check private storage and database availability, then review export compatibility.';
			if ( in_array( $message, array( 'This export used the old shortened Excel format. Start a new export to preserve full HTML.', 'This export predates HTML cleaning. Start a new export to avoid mixing old and cleaned rows.' ), true ) ) {
				$this->store->save(
					$job,
					array(
						'status'     => 'failed',
						'last_error' => $message,
					)
				);
			} else {
				$this->store->failure( $job, $message );
			}
			if ( wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} finally {
			try {
				if ( $job ) {
					$this->store->release( $job );
				}
			} finally {
				if ( $read_only ) {
					remove_filter( 'woocommerce_product_variation_update_title_on_read', $read_only, PHP_INT_MAX );
				}
				wp_set_current_user( $previous_user );
				fclose( $lock );
			}
		}
		return $this->store->get( $id );
	}

	private static function public_errors(): array {
		return array(
			'Product export stopped before exceeding its memory budget. Use a smaller background export batch.',
			'This export used the old shortened Excel format. Start a new export to preserve full HTML.',
			'This export predates HTML cleaning. Start a new export to avoid mixing old and cleaned rows.',
			'Product HTML cleaning requires the PHP DOM extension.',
			'The product description HTML could not be repaired safely.',
			'The product description HTML is nested too deeply to export safely.',
			'Product export related-data batch exceeds its safe limit.',
			'The product export resource check could not read the database.',
			'The product query cannot be preflighted within safe resource limits. Review query filters before exporting.',
			'Large export requires the read-only variation data-store patch. Reapply the verified core patch.',
			'Large export requires request-local object-cache flushing. Configure a compatible cache drop-in.',
			'The export owner no longer has export privileges.',
			'An extension transforms whole CSV batches. Use the legacy exporter for that extension.',
			'The product query order changed during export. Start a new export.',
			'The product query page size changed during export. Start a new export.',
			'The product data store changed. Start a new export.',
			'The product export query changed while the job was running. Start a new export.',
			'The product database query failed.',
			'A product query override does not support bounded background export. Use the legacy exporter.',
			'The product query returned unexpected IDs. Use the compatibility mode or legacy exporter.',
			'The product query did not hydrate every selected product. The export was stopped to prevent missing rows.',
			'The export exceeded the supported number of CSV columns.',
			'No CSV columns are selected for this export.',
			'The export schema has changed. Start a new export.',
			'Insufficient free space in large export storage.',
			'An export file write could not complete.',
			'The product ID watermark could not be read.',
			'The finalized CSV record count does not match the committed export chunks.',
			'The export size does not match the verification checkpoint.',
			'The CSV verification proof is invalid.',
			'The CSV verification segment size is invalid.',
			'The CSV failed verification.',
		);
	}

	private function unit( array $job ): void {
		$id     = $job['id'];
		$config = $job['config'];
		$state  = $job['checkpoint'];
		if ( 'cancelling' === $job['status'] ) {
			if ( $this->files->purge( $id ) ) {
				$this->store->save( $job, array( 'status' => 'cancelled' ) );
			} else {
				$this->store->save( $job, array( 'checkpoint' => $state ) );
			}
			return;
		}
		if ( 'pausing' === $job['status'] ) {
			$this->store->save( $job, array( 'status' => 'paused' ) );
			return;
		}
		if ( ! self::allowed( (int) $job['user_id'] ) ) {
			throw new RuntimeException( 'The export owner no longer has export privileges.' );
		}
		if ( 1 !== ( $config['version'] ?? null ) ) {
			throw new RuntimeException( 'The export schema has changed. Start a new export.' );
		}
		if ( ! empty( $config['excel_view'] ) && 2 !== ( $config['excel_cell_version'] ?? null ) ) {
			throw new RuntimeException( 'This export used the old shortened Excel format. Start a new export to preserve full HTML.' );
		}
		if ( 1 !== ( $config['html_clean_version'] ?? null ) ) {
			throw new RuntimeException( 'This export predates HTML cleaning. Start a new export to avoid mixing old and cleaned rows.' );
		}
		if ( 'finalizing' === $job['status'] ) {
			$state = $this->files->finalize( $id, $state, $config );
			do_action( 'woocommerce_large_export_finalize_written', $id, $state['final_index'] );
			$finished = isset( $state['final_bytes'] ) && $state['index'] === $state['final_index'];
			if ( $finished && (int) ( $state['final_rows'] ?? -1 ) !== (int) ( $state['rows'] ?? -2 ) ) {
				throw new RuntimeException( 'The finalized CSV record count does not match the committed export chunks.' );
			}
			$status = $finished ? 'verifying' : 'finalizing';
			$this->store->save(
				$job,
				array(
					'checkpoint' => $state,
					'status'     => $status,
				)
			);
			return;
		}
		if ( 'verifying' === $job['status'] ) {
			if ( ! isset( $state['checksum'] ) ) {
				$state = $this->files->verify( $id, $state );
			} elseif ( 'zip' === ( $config['format'] ?? 'csv' ) ) {
				if ( empty( $state['archive']['finished'] ) ) {
					$state['archive'] = $this->files->package( $id, $state );
				} else {
					$state['archive'] = $this->files->verify( $id, $state['archive'], 'zip' );
				}
			}
			$verified = isset( $state['checksum'] ) && ( 'zip' !== ( $config['format'] ?? 'csv' ) || isset( $state['archive']['checksum'] ) );
			$status   = $verified ? 'completed' : 'verifying';
			$this->store->save(
				$job,
				array(
					'checkpoint' => $state,
					'status'     => $status,
				)
			);
			return;
		}

		if ( ! self::read_only_supported() ) {
			throw new RuntimeException( 'Large export requires the read-only variation data-store patch. Reapply the verified core patch.' );
		}
		if ( ! wp_cache_supports( 'flush_runtime' ) ) {
			throw new RuntimeException( 'Large export requires request-local object-cache flushing. Configure a compatible cache drop-in.' );
		}
		require_once __DIR__ . '/class-reader.php';
		if ( has_filter( 'woocommerce_product_export_rows' ) ) {
			throw new RuntimeException( 'An extension transforms whole CSV batches. Use the legacy exporter for that extension.' );
		}
		$reader = new Reader( $config );
		if ( ! $state ) {
			$state          = $reader->initialize( $config );
			$state['batch'] = $config['batch'];
			$this->store->save( $job, array( 'checkpoint' => $state ) );
			return;
		}
		// Legacy AJAX carries discovered column names between requests. Restore
		// that context too, so late custom-column getters run in later chunks.
		$reader->set_column_names( $state['raw_columns'] ?? ( $reader->get_column_names() + $state['columns'] ) );

		$start       = microtime( true );
		$query_count = get_num_queries();
		$batch       = 'compatibility' === $state['mode'] ? $config['batch'] : $state['batch'];
		$reader->set_limit( $batch );
		$reader->set_page( $state['page'] );
		$batch = max( 1, min( $batch, (int) $reader->get_limit() ) );
		list( $products, $state, $finished, $query_time ) = $reader->select( $config, $state, $batch );
		if ( $finished ) {
			if ( ! $state['columns'] ) {
				throw new RuntimeException( 'No CSV columns are selected for this export.' );
			}
			$this->store->save(
				$job,
				array(
					'checkpoint' => $state,
					'status'     => 'finalizing',
				)
			);
			return;
		}

		$written    = array(
			'rows'  => 0,
			'bytes' => 0,
		);
		$write_time = 0.0;
		if ( $products ) {
			$write_start = microtime( true );
			// The database checkpoint is authoritative. If a worker died after an
			// atomic chunk rename but before saving this index, safely replace that
			// uncommitted chunk instead of appending duplicate rows.
			$this->files->discard_uncommitted_chunk( $id, (int) $state['index'] );
			$written = $this->files->write_chunk( $id, $state['index'], $reader->rows( $products ) );
			do_action( 'woocommerce_large_export_chunk_written', $id, $state['index'] );
			$write_time = microtime( true ) - $write_start;
			++$state['index'];
			$state['rows']       += $written['rows'];
			$state['bytes']      += $written['bytes'];
			$state['columns']    += $reader->columns();
			$state['raw_columns'] = $reader->get_column_names();
			if ( count( $state['columns'] ) > 10000 || count( $state['raw_columns'] ) > 10000 ) {
				throw new RuntimeException( 'The export exceeded the supported number of CSV columns.' );
			}
		}

		$elapsed                   = max( 0.001, microtime( true ) - $start );
		$rate                      = $written['rows'] / $elapsed;
		$state['rate']             = round( isset( $state['rate'] ) ? 0.85 * $state['rate'] + 0.15 * $rate : $rate, 2 );
		$state['peak_memory']      = max( $state['peak_memory'] ?? 0, memory_get_peak_usage( true ) );
		$state['unit_count']       = ( $state['unit_count'] ?? 0 ) + 1;
		$state['unit_seconds']     = ( $state['unit_seconds'] ?? 0.0 ) + $elapsed;
		$state['unit_max_seconds'] = max( $state['unit_max_seconds'] ?? 0.0, $elapsed );
		$samples                   = array_slice( $state['unit_samples'] ?? array(), -127 );
		$samples[]                 = round( $elapsed, 3 );
		$state['unit_samples']     = $samples;
		$state['query_seconds']    = ( $state['query_seconds'] ?? 0.0 ) + $query_time;
		$state['write_seconds']    = ( $state['write_seconds'] ?? 0.0 ) + $write_time;
		$state['queries']          = ( $state['queries'] ?? 0 ) + get_num_queries() - $query_count;
		if ( 'keyset' === $state['mode'] ) {
			$memory_limit    = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
			$memory_limit    = $memory_limit > 0 ? $memory_limit : 256 * 1024 * 1024;
			$memory_pressure = memory_get_usage( true ) >= (int) ( $memory_limit * 0.70 );
			if ( $elapsed > 2 || $query_time > 1 || $write_time > 1 || $memory_pressure ) {
				$state['batch'] = max( min( $config['batch_min'], $batch ), (int) ( $batch * 0.7 ) );
			} elseif ( $elapsed < 0.6 && $written['rows'] >= $batch ) {
				$state['batch'] = min( 100, $config['batch_max'], $state['resource_batch_max'] ?? $config['batch_max'], max( $batch + 1, (int) ( $batch * 1.2 ) ) );
			}
		}
		$this->store->save(
			$job,
			array(
				'checkpoint' => $state,
				'last_error' => '',
			)
		);
	}

	public function sweep(): void {
		$lock = $this->files->lock();
		if ( ! $lock ) {
			return;
		}
		try {
			foreach ( $this->store->expires() as $id ) {
				do_action( 'woocommerce_large_export_before_expire', $id );
				try {
					// Fence operator retry before removing any files. A retry that wins
					// the revision race must keep its valid checkpoint and chunks.
					$this->store->control( $id, 'expire' );
				} catch ( InvalidArgumentException $error ) {
					continue;
				}
				if ( $this->files->purge( $id ) ) {
					$this->store->control( $id, 'purged' );
				}
			}
		} finally {
			fclose( $lock );
		}
	}

	private static function read_only_supported(): bool {
		return class_exists( 'WC_Product_Variation_Data_Store_CPT' ) &&
			defined( 'WC_Product_Variation_Data_Store_CPT::LARGE_EXPORT_READ_ONLY_VERSION' ) &&
			1 === \WC_Product_Variation_Data_Store_CPT::LARGE_EXPORT_READ_ONLY_VERSION;
	}

	public static function summary( array $job ): array {
		$state = $job['checkpoint'];
		$rate  = $state['rate'] ?? 0;
		$rows  = $state['rows'] ?? 0;
		// Old checkpoints estimated every posts-table row, including media/revisions.
		// Do not display those values as selected product counts or derive an ETA from them.
		$total    = null;
		$progress = $total ? min( 99, (int) floor( 100 * $rows / $total ) ) : null;
		$samples  = $state['unit_samples'] ?? array();
		sort( $samples, SORT_NUMERIC );
		$p95          = $samples ? $samples[ (int) ceil( count( $samples ) * 0.95 ) - 1 ] : null;
		$average_unit = empty( $state['unit_count'] ) ? null : $state['unit_seconds'] / $state['unit_count'];
		$artifact     = 'zip' === ( $job['config']['format'] ?? 'csv' ) ? ( $state['archive'] ?? array() ) : $state;
		if ( 'completed' === $job['status'] ) {
			$progress = 100;
		}
		return array(
			'id'                          => $job['id'],
			'status'                      => $job['status'],
			'mode'                        => $state['mode'] ?? $job['config']['mode'],
			'format'                      => $job['config']['format'] ?? 'csv',
			'rows'                        => $rows,
			'estimate'                    => $total,
			'progress'                    => $progress,
			'rate'                        => $rate,
			'eta'                         => $total && $rate > 0 && $total > $rows && 'running' === $job['status'] ? (int) ceil( ( $total - $rows ) / $rate ) : null,
			'elapsed'                     => empty( $job['started_at'] ) ? 0 : max( 0, ( $job['completed_at'] ? $job['completed_at'] : time() ) - $job['started_at'] ),
			'batch'                       => $state['batch'] ?? $job['config']['batch'],
			// Chunk JSON byte counts are staging usage, not the finalized CSV size.
			'bytes'                       => $artifact['final_bytes'] ?? 0,
			'staging_bytes'               => $state['bytes'] ?? 0,
			'database_queries'            => $state['queries'] ?? 0,
			'database_seconds'            => round( $state['query_seconds'] ?? 0, 3 ),
			'writer_seconds'              => round( $state['write_seconds'] ?? 0, 3 ),
			'peak_memory_bytes'           => $state['peak_memory'] ?? 0,
			'work_units'                  => $state['unit_count'] ?? 0,
			'avg_unit_seconds'            => null === $average_unit ? null : round( $average_unit, 3 ),
			'p95_recent_128_unit_seconds' => null === $p95 ? null : round( $p95, 3 ),
			'max_unit_seconds'            => round( $state['unit_max_seconds'] ?? 0, 3 ),
			'checksum'                    => $artifact['checksum'] ?? null,
			'attempts'                    => $job['attempts'],
			'last_error'                  => 'completed' === $job['status'] ? '' : $job['last_error'],
			'logs'                        => $job['logs'] ?? array(),
		);
	}
}
