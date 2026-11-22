<?php
/**
 * Bounded WP-CLI operational commands for large product exports.
 *
 * @package WooCommerce\Export
 */

namespace Automattic\WooCommerce\Internal\LargeExport;

use InvalidArgumentException;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI entry point for starting, observing and operating export jobs.
 *
 * ## EXAMPLES
 *
 *     wp wc export-large start --user=1
 *     wp wc export-large run --job-id=UUID --seconds=10
 *     wp wc export-large run --seconds=30
 *     wp wc export-large status --job-id=UUID --format=json
 *     wp wc export-large pause --job-id=UUID
 *     wp wc export-large resume --job-id=UUID
 *     wp wc export-large cancel --job-id=UUID
 *     wp wc export-large retry --job-id=UUID
 *     wp wc export-large verify --job-id=UUID
 *
 * @package WooCommerce\CLI
 */
final class Command {

	/** @var Store */
	private $store;

	/** @var Files */
	private $files;

	/** @var Engine */
	private $engine;

	/**
	 * Register this command if the environment provides WP-CLI.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wc export-large', self::class );
		}
	}

	/**
	 * Start a durable export job.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : Export owner. WP-CLI's --user global is used when this option is omitted.
	 *
	 * [--type=<products>]
	 * : Export entity type. Only products are supported.
	 *
	 * [--mode=<mode>]
	 * : Export query mode: compatibility (default) or opt-in keyset.
	 *
	 * [--file-format=<format>]
	 * : Artifact format: csv (default) or resumable ZIP64 STORE (PHP 8+).
	 *
	 * [--meta]
	 * : Include custom product metadata.
	 *
	 * [--columns=<columns>]
	 * : Comma-separated product column identifiers.
	 *
	 * [--types=<types>]
	 * : Comma-separated WooCommerce product types.
	 *
	 * [--categories=<categories>]
	 * : Comma-separated product category slugs.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated product IDs, limited to 2000 per job.
	 *
	 * [--statuses=<statuses>]
	 * : Comma-separated WooCommerce product statuses.
	 *
	 * [--spreadsheet]
	 * : Enable spreadsheet formula protection.
	 *
	 * [--no-spreadsheet]
	 * : Keep CSV cell values unchanged for product importer compatibility.
	 *
	 * [--bom]
	 * : Add a UTF-8 BOM to the CSV output.
	 *
	 * [--excel-view]
	 * : Preserve HTML and line breaks; cap cells at Excel's 32,767 UTF-16 units. Forces BOM and formula protection.
	 *
	 * [--no-bom]
	 * : Omit the UTF-8 BOM.
	 *
	 * [--format=<format>]
	 * : Output format: table or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc export-large start --user=1 --columns=id,sku,name
	 *     wp wc export-large start --mode=compatibility --meta --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function start( array $args, array $assoc_args ): void {
		$type = $assoc_args['type'] ?? 'products';
		if ( 'products' !== $type ) {
			\WP_CLI::error( 'Only product exports are supported.' );
		}
		$user = isset( $assoc_args['user'] ) ? absint( $assoc_args['user'] ) : get_current_user_id();
		if ( ! Engine::allowed( $user ) ) {
			\WP_CLI::error( 'The selected user must have edit_products and export capabilities.' );
		}
		try {
			$job = $this->engine()->create(
				$user,
				array(
					'mode'        => $assoc_args['mode'] ?? 'compatibility',
					'format'      => $assoc_args['file-format'] ?? 'csv',
					'meta'        => isset( $assoc_args['meta'] ),
					'columns'     => $this->csv_list( $assoc_args['columns'] ?? '' ),
					'types'       => $this->csv_list( $assoc_args['types'] ?? '' ),
					'categories'  => $this->csv_list( $assoc_args['categories'] ?? '' ),
					'ids'         => $this->integer_list( $assoc_args['ids'] ?? '' ),
					'statuses'    => $this->csv_list( $assoc_args['statuses'] ?? '' ),
					'spreadsheet' => \WP_CLI\Utils\get_flag_value( $assoc_args, 'spreadsheet', true ),
					'bom'         => \WP_CLI\Utils\get_flag_value( $assoc_args, 'bom', true ),
					'excel_view'  => isset( $assoc_args['excel-view'] ),
				)
			);
		} catch ( InvalidArgumentException $error ) {
			\WP_CLI::error( $error->getMessage() );
		} catch ( RuntimeException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		Integration::enqueue( $job['id'] );
		$this->display( $job, $assoc_args['format'] ?? 'table' );
	}

	/**
	 * Process a job for a bounded duration, or drain due work until the budget ends.
	 *
	 * ## OPTIONS
	 *
	 * [--job-id=<id>]
	 * : Process one named job instead of scanning the bounded due queue.
	 *
	 * [--seconds=<seconds>]
	 * : Maximum time budget, from 1 through 300 seconds. Defaults to 30.
	 *
	 * [--format=<format>]
	 * : Output format: table or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc export-large run --job-id=UUID --seconds=20
	 *     wp wc export-large run --seconds=30
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$budget = $this->seconds( $assoc_args['seconds'] ?? 30 );
		$single = isset( $assoc_args['job-id'] ) ? (string) $assoc_args['job-id'] : '';
		if ( $single && ! $this->store()->get( $single ) ) {
			\WP_CLI::error( 'The export job does not exist.' );
		}
		$started = microtime( true );
		$results = array();
		do {
			$ids = $single ? array( $single ) : $this->store()->due();
			if ( ! $ids ) {
				break;
			}
			$worked = false;
			foreach ( $ids as $id ) {
				$before         = $this->store()->get( $id );
				$job            = $this->engine()->run( $id, min( 6, $budget ) );
				$results[ $id ] = $job;
				$worked         = $worked || ( $before && $job && $before['revision'] !== $job['revision'] );
				if ( microtime( true ) - $started >= $budget ) {
					break 2;
				}
			}
			if ( ! $worked ) {
				break;
			}
		} while ( microtime( true ) - $started < $budget );

		if ( ! $results ) {
			if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
				\WP_CLI::line( '[]' );
			} else {
				\WP_CLI::log( 'No due export jobs.' );
			}
			return;
		}
		$output = array_map( array( $this, 'summary' ), array_values( $results ) );
		$this->display_many( $output, $assoc_args['format'] ?? 'table' );
		foreach ( $results as $result ) {
			if ( $result && 'failed' === $result['status'] ) {
				\WP_CLI::halt( 1 );
			}
		}
	}

	/**
	 * Display one job and its real persisted progress.
	 *
	 * ## OPTIONS
	 *
	 * --job-id=<id>
	 * : Job to inspect.
	 *
	 * [--format=<format>]
	 * : Output format: table or json.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$this->display( $this->required( $assoc_args ), $assoc_args['format'] ?? 'table' );
	}

	/** Pause an export after its current storage unit. */
	public function pause( array $args, array $assoc_args ): void {
		$this->operate( $assoc_args, 'pause' );
	}

	/** Resume an export from its last committed checkpoint. */
	public function resume( array $args, array $assoc_args ): void {
		$this->operate( $assoc_args, 'resume' );
	}

	/** Cancel an export and remove its private temporary files. */
	public function cancel( array $args, array $assoc_args ): void {
		$this->operate( $assoc_args, 'cancel' );
	}

	/** Retry a job that exhausted its automatic retry budget. */
	public function retry( array $args, array $assoc_args ): void {
		$this->operate( $assoc_args, 'retry' );
	}

	/** Remove a bounded page of expired private artifacts (CLI-only retention timer). */
	public function cleanup( array $args, array $assoc_args ): void {
		$this->engine()->sweep();
		\WP_CLI::success( 'The bounded export retention pass completed.' );
	}

	/**
	 * Confirm that a completed artifact matches its checkpoint's byte count and SHA-256.
	 *
	 * ## OPTIONS
	 *
	 * --job-id=<id>
	 * : Completed job to verify.
	 *
	 * [--format=<format>]
	 * : Output format: table or json.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function verify( array $args, array $assoc_args ): void {
		$job = $this->required( $assoc_args );
		if ( 'completed' !== $job['status'] || empty( $job['checkpoint']['checksum'] ) ) {
			\WP_CLI::error( 'Only a completed export with a verified checksum can be checked.' );
		}
		try {
			$extension  = 'zip' === ( $job['config']['format'] ?? 'csv' ) ? 'zip' : 'csv';
			$checkpoint = 'zip' === $extension ? $job['checkpoint']['archive'] : $job['checkpoint'];
			$path       = $this->files()->path( $job['id'], $extension, $checkpoint );
			$bytes      = filesize( $path );
			$algorithm  = (string) $checkpoint['checksum_algorithm'];
			$expected   = (string) $checkpoint['checksum'];
			if ( 'sha256' === $algorithm ) {
				$hash = hash_file( 'sha256', $path );
			} else {
				// Recompute every segment on PHP 7.4; never compare a stored digest to itself.
				$checked = $checkpoint;
				unset( $checked['checksum'], $checked['verify_context'], $checked['verify_format'] );
				do {
					$checked = $this->files()->verify( $job['id'], $checked, $extension );
				} while ( ! isset( $checked['checksum'] ) );
				$hash = $checked['checksum'];
			}
		} catch ( RuntimeException $error ) {
			\WP_CLI::error( 'The completed export file cannot be read.' );
		}
		$expected_bytes = (int) ( $checkpoint['final_bytes'] ?? -1 );
		$valid          = false !== $bytes && false !== $hash && $expected_bytes === $bytes && hash_equals( $expected, $hash );
		$result         = array(
			'id'        => $job['id'],
			'verified'  => $valid,
			'bytes'     => $bytes,
			'algorithm' => $algorithm,
			'checksum'  => $hash,
		);
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			\WP_CLI::log( $valid ? 'Export checksum and file size match.' : 'Export checksum or file size does not match.' );
			\WP_CLI\Utils\format_items( 'table', array( $result ), array( 'id', 'verified', 'bytes', 'algorithm', 'checksum' ) );
		}
		if ( ! $valid ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Apply a guarded state change to one job.
	 *
	 * @param array  $assoc_args Command arguments.
	 * @param string $action     Store control action.
	 */
	private function operate( array $assoc_args, string $action ): void {
		$job = $this->required( $assoc_args );
		if ( ! current_user_can( 'manage_options' ) && get_current_user_id() !== (int) $job['user_id'] ) {
			\WP_CLI::error( "You cannot control another user's export job." );
		}
		try {
			$job = $this->store()->control( $job['id'], $action );
		} catch ( InvalidArgumentException $error ) {
			\WP_CLI::error( $error->getMessage() );
		} catch ( RuntimeException $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		if ( in_array( $job['status'], Engine::ACTIVE, true ) ) {
			Integration::enqueue( $job['id'], max( 0, (int) $job['available_at'] - time() ) );
		}
		$this->display( $job, $assoc_args['format'] ?? 'table' );
	}

	/**
	 * Resolve a job or report a concise CLI error.
	 *
	 * @param array $assoc_args Command arguments.
	 * @return array
	 */
	private function required( array $assoc_args ): array {
		$id  = (string) ( $assoc_args['job-id'] ?? '' );
		$job = $id ? $this->store()->get( $id ) : null;
		if ( ! $job ) {
			\WP_CLI::error( 'Provide a valid --job-id for an existing export job.' );
		}
		if ( ! current_user_can( 'manage_options' ) && get_current_user_id() !== (int) $job['user_id'] ) {
			\WP_CLI::error( "You cannot view another user's export job." );
		}
		return $job;
	}

	/**
	 * Print a single summary in the requested format.
	 *
	 * @param array  $job    Job row.
	 * @param string $format Output format.
	 */
	private function display( array $job, string $format ): void {
		$this->display_many( array( $this->summary( $job ) ), $format );
	}

	/**
	 * Print a bounded set of summaries.
	 *
	 * @param array  $summaries Summaries to print.
	 * @param string $format    Output format.
	 */
	private function display_many( array $summaries, string $format ): void {
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			\WP_CLI::error( 'Output format must be table or json.' );
		}
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( count( $summaries ) === 1 ? $summaries[0] : $summaries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$rows = array_map(
			static function ( array $summary ): array {
				unset( $summary['logs'] );
				return $summary;
			},
			$summaries
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'status', 'rows', 'estimate', 'progress', 'rate', 'eta', 'elapsed', 'batch', 'bytes', 'attempts', 'last_error' ) );
	}

	/**
	 * Return the engine's persisted status summary.
	 *
	 * @param array $job Job row.
	 * @return array
	 */
	public function summary( array $job ): array {
		return Engine::summary( $job );
	}

	private function store(): Store {
		if ( ! $this->store ) {
			$this->store = new Store();
			$this->store->install();
		}
		return $this->store;
	}

	private function files(): Files {
		if ( ! $this->files ) {
			$this->files = new Files();
		}
		return $this->files;
	}

	private function engine(): Engine {
		if ( ! $this->engine ) {
			$this->engine = new Engine( $this->store(), $this->files() );
		}
		return $this->engine;
	}

	private function csv_list( string $value ): array {
		return '' === trim( $value ) ? array() : array_map( 'trim', explode( ',', $value ) );
	}

	private function integer_list( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}
		$values = $this->csv_list( $value );
		foreach ( $values as $id ) {
			if ( ! ctype_digit( $id ) || (int) $id < 1 ) {
				throw new InvalidArgumentException( 'Product IDs must be positive integers.' );
			}
		}
		return array_map( 'intval', $values );
	}

	private function seconds( $value ): int {
		if ( ! is_numeric( $value ) || (int) $value < 1 || (int) $value > 300 ) {
			\WP_CLI::error( 'The --seconds value must be from 1 through 300.' );
		}
		return (int) $value;
	}
}
