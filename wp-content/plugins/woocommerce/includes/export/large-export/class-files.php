<?php
/**
 * Durable private files for the background product exporter.
 *
 * @package WooCommerce\Export
 */

namespace Automattic\WooCommerce\Internal\LargeExport;

use DirectoryIterator;
use HashContext;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Writes keyed row chunks, builds an aligned CSV and verifies it before publication.
 *
 * Call mutations while holding the site writer lock. The job store checkpoint is
 * authoritative: an uncommitted chunk is replaceable and uncommitted CSV bytes
 * are truncated before the next append.
 */
final class Files {
	private const RESERVE_BYTES = 64 * 1024 * 1024;
	private const SEGMENT_BYTES = 8 * 1024 * 1024;
	private const RECORD_BYTES  = 76; // 10 decimal digits, space, hex SHA-256, newline.
	private const MAX_ROW_BYTES = 32 * 1024 * 1024;

	/** @var string Canonical private base. */
	private $root;

	/** @var string Name of this site's storage directory. */
	private $site;

	/**
	 * Validate the configured location and calculate an installation-specific directory.
	 */
	public function __construct() {
		if ( PHP_INT_SIZE < 8 || ! defined( 'WC_LARGE_EXPORT_DIR' ) || ! is_string( WC_LARGE_EXPORT_DIR ) ) {
			throw new RuntimeException( 'Large export requires 64-bit PHP and a configured private storage directory.' );
		}

		$root = rtrim( str_replace( '\\', '/', WC_LARGE_EXPORT_DIR ), '/' );
		if ( ( '/' !== substr( $root, 0, 1 ) && ! preg_match( '~\A[A-Za-z]:/~', $root ) )
			|| '//' === substr( $root, 0, 2 )
			|| preg_match( '~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f]~', $root ) ) {
			throw new RuntimeException( 'Large export storage needs a canonical absolute local path.' );
		}

		$canonical = realpath( $root );
		if ( false === $canonical || ! is_dir( $canonical ) || self::normalized( $root ) !== self::normalized( $canonical ) ) {
			throw new RuntimeException( 'Large export storage must be an existing directory with no symlink in its path.' );
		}
		for ( $part = $root; dirname( $part ) !== $part; $part = dirname( $part ) ) {
			if ( is_link( $part ) ) {
				throw new RuntimeException( 'Large export storage cannot use a symlink.' );
			}
		}

		$uploads   = wp_get_upload_dir();
		$public    = array( ABSPATH, WP_CONTENT_DIR, $uploads['basedir'], $_SERVER['DOCUMENT_ROOT'] ?? '' );
		$root_path = self::normalized( $canonical );
		foreach ( $public as $dir ) {
			if ( '' === $dir ) {
				continue;
			}
			$real_dir = realpath( $dir );
			$path     = self::normalized( false === $real_dir ? $dir : $real_dir );
			if ( $root_path === $path || 0 === strpos( $root_path, $path . '/' ) ) {
				throw new RuntimeException( 'Large export storage must be outside the webroot, WordPress and uploads.' );
			}
		}

		global $wpdb;
		$this->root = $canonical;
		$this->site = 'wc-products-' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . self::normalized( ABSPATH ) ), 0, 24 );
		$this->ensure_directory( $this->site_dir() );
	}

	/**
	 * Claim a nonblocking writer lock across all jobs in this installation.
	 *
	 * @return resource|null Close the stream to release the lock.
	 */
	public function lock() {
		$stream = $this->open( $this->site_dir() . '/writer.lock', 'c+b' );
		if ( ! flock( $stream, LOCK_EX | LOCK_NB, $would_block ) ) {
			fclose( $stream );
			if ( $would_block ) {
				return null;
			}
			throw new RuntimeException( 'The private filesystem does not provide the required writer lock.' );
		}
		return $stream;
	}

	/**
	 * Check space before writing to the private volume.
	 *
	 * @param int $bytes Additional expected usage, if known.
	 */
	public function capacity( int $bytes = 0 ): void {
		$root      = $this->root;
		$available = self::io(
			static function () use ( $root ) {
				return disk_free_space( $root );
			}
		);
		if ( false === $available || $available < self::RESERVE_BYTES + max( 0, $bytes ) ) {
			throw new RuntimeException( 'Insufficient free space in large export storage.' );
		}
	}

	/**
	 * Mirror committed database state for operators. The DB remains authoritative;
	 * this bounded manifest can always be regenerated after a crash between commits.
	 *
	 * @param array $job Persisted job, excluding row data.
	 */
	public function manifest( array $job ): void {
		$dir    = $this->job_dir( $job['id'], true );
		$data   = json_encode(
			array(
				'schema_version' => 1,
				'job_id'         => $job['id'],
				'status'         => $job['status'],
				'revision'       => $job['revision'],
				'updated_at'     => $job['updated_at'],
				'config'         => $job['config'],
				'checkpoint'     => $job['checkpoint'],
			),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		$path   = $dir . '/manifest.json';
		$stream = $this->open( $path . '.tmp', 'c+b' );
		try {
			$this->position( $stream, 0 );
			$this->write_all( $stream, $data );
			$this->sync( $stream );
		} finally {
			fclose( $stream );
		}
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) || ! @rename( $path . '.tmp', $path ) ) {
			throw new RuntimeException( 'Unable to publish the export checkpoint manifest.' );
		}
	}

	/**
	 * Write an immutable chunk and close all handles before publishing it.
	 *
	 * @param string   $id             Job UUID.
	 * @param int      $index          Zero-based chunk number.
	 * @param iterable $formatted_rows Sparse rows keyed by eventual CSV columns.
	 * @return array{rows:int,bytes:int}
	 */
	public function write_chunk( string $id, int $index, iterable $formatted_rows ): array {
		$this->capacity();
		$dir    = $this->job_dir( $id, true );
		$target = $this->chunk_path( $dir, $index );
		if ( file_exists( $target ) || is_link( $target ) ) {
			throw new RuntimeException( 'A committed or unverified chunk already occupies this index.' );
		}
		$temp      = $target . '.tmp';
		$stream    = $this->open( $temp, 'c+b' );
		$row_count = 0;
		$raw_bytes = 0;
		$hash      = hash_init( 'sha256' );
		try {
			if ( ! ftruncate( $stream, 0 ) ) {
				throw new RuntimeException( 'Unable to reset an interrupted export chunk.' );
			}
			$this->position( $stream, 0 );
			foreach ( $formatted_rows as $row ) {
				if ( ! is_array( $row ) ) {
					throw new RuntimeException( 'An export row has an unsupported structure.' );
				}
				$line = json_encode( (object) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
				if ( strlen( $line ) >= self::MAX_ROW_BYTES ) {
					throw new RuntimeException( 'An export row exceeds the 32 MiB encoded row limit.' );
				}
				// A record begins with a marker. The footer begins with a JSON object.
				$line = '+' . $line;
				$this->write_all( $stream, $line );
				hash_update( $hash, $line );
				$raw_bytes += strlen( $line );
				++$row_count;
			}
			$footer = json_encode( array( $row_count, $raw_bytes, hash_final( $hash ) ), JSON_THROW_ON_ERROR ) . "\n";
			$this->write_all( $stream, $footer );
			$this->sync( $stream );
		} finally {
			fclose( $stream );
		}
		// Only the uncommitted chunk at the caller's current index may be replaced.
		$this->discard_file( $target );
		$this->rename( $temp, $target );

		return array(
			'rows'  => $row_count,
			'bytes' => $raw_bytes + strlen( $footer ),
		);
	}

	/**
	 * Append one committed chunk and fixed-size SHA-256 proofs of the appended bytes.
	 *
	 * @param string $id     Job UUID.
	 * @param array  $state  Last committed checkpoint.
	 * @param array  $config Job options.
	 * @return array Updated checkpoint.
	 */
	public function finalize( string $id, array $state, array $config ): array {
		$this->capacity();
		$dir       = $this->job_dir( $id, true );
		$index     = (int) ( $state['final_index'] ?? 0 );
		$offset    = (int) ( $state['final_bytes'] ?? 0 );
		$parts     = (int) ( $state['final_parts'] ?? 0 );
		$columns   = $state['columns'];
		$delimiter = $config['delimiter'] ?? ',';
		if ( ! is_string( $delimiter ) || 1 !== strlen( $delimiter ) || false !== strpos( "\0\r\n\"", $delimiter ) || ! $columns ) {
			throw new RuntimeException( 'The CSV schema or delimiter is invalid.' );
		}

		$csv = $this->open( $dir . '/products.partial', $offset ? 'r+b' : 'c+b' );
		try {
			$proof = $this->open( $dir . '/proof.partial', $parts ? 'r+b' : 'c+b' );
			try {
				$this->position( $csv, $offset );
				$this->position( $proof, $parts * self::RECORD_BYTES );

				$segment = hash_init( 'sha256' );
				$length  = 0;
				$rows    = 0;
				if ( 0 === $offset ) {
					$header = $this->csv_row( array_values( $columns ), $delimiter );
					if ( ! isset( $config['bom'] ) || $config['bom'] ) {
						$header = "\xEF\xBB\xBF" . $header;
					}
					$this->write_proven( $csv, $proof, $header, $segment, $length );
				}

				if ( $index < (int) $state['index'] ) {
					$input = $this->open( $this->chunk_path( $dir, $index ), 'rb' );
					try {
						$rows = $this->append_chunk( $input, $csv, $proof, array_keys( $columns ), $delimiter, $segment, $length );
					} finally {
						fclose( $input );
					}
					++$index;
				}

				if ( $length ) {
					$this->write_proof( $proof, $length, hash_final( $segment ) );
				}
				$this->sync( $csv );
				$this->sync( $proof );
				$state['final_index'] = $index;
				$state['final_bytes'] = $this->size( $csv );
				$state['final_parts'] = intdiv( $this->size( $proof ), self::RECORD_BYTES );
				$state['final_rows']  = (int) ( $state['final_rows'] ?? 0 ) + $rows;
			} finally {
				fclose( $proof );
			}
		} finally {
			fclose( $csv );
		}
		return $state;
	}

	/**
	 * Verify a bounded set of on-disk byte ranges, then publish atomically.
	 *
	 * PHP 7.4 cannot persist HashContext. Segment verification is identical, but a
	 * rolling digest of verified segments is stored as its validator on that runtime.
	 *
	 * @param string $id    Job UUID.
	 * @param array  $state Last committed checkpoint.
	 * @return array Updated checkpoint, including checksum when verification finishes.
	 */
	public function verify( string $id, array $state, string $extension = 'csv' ): array {
		$this->extension( $extension );
		$dir         = $this->job_dir( $id );
		$partial     = $dir . ( 'csv' === $extension ? '/products.partial' : '/products.zip.partial' );
		$published   = $dir . '/products.' . $extension;
		$was_renamed = ! file_exists( $partial );
		$format      = method_exists( HashContext::class, '__serialize' ) ? 'sha256' : 'sha256-segments-v1';
		$offset      = 0;
		$part        = 0;
		$context     = 'sha256' === $format ? hash_init( 'sha256' ) : str_repeat( '0', 64 );

		if ( ( $state['verify_format'] ?? '' ) === $format && (int) ( $state['verify_php'] ?? 0 ) === PHP_VERSION_ID ) {
			$offset  = (int) $state['verify_bytes'];
			$part    = (int) $state['verify_part'];
			$context = $state['verify_context'];
			if ( 'sha256' === $format ) {
				$context = unserialize( base64_decode( $context, true ), array( 'allowed_classes' => array( HashContext::class ) ) );
				if ( ! $context instanceof HashContext ) {
					throw new RuntimeException( 'The verification checkpoint is invalid.' );
				}
			}
		}

		$csv = $this->open( $was_renamed ? $published : $partial, 'rb' );
		try {
			$proof = $this->open( $dir . ( 'csv' === $extension ? '/proof.partial' : '/zip-proof.partial' ), 'rb' );
			try {
				if ( $this->size( $csv ) !== $state['final_bytes']
					|| $this->size( $proof ) !== $state['final_parts'] * self::RECORD_BYTES
					|| 0 !== fseek( $csv, $offset )
					|| 0 !== fseek( $proof, self::RECORD_BYTES * $part ) ) {
					throw new RuntimeException( 'Export size does not match the verification checkpoint.' );
				}
				$started_at = $offset;
				$first_part = $part;
				while ( $part < $state['final_parts'] && $part < $first_part + 64 && $offset - $started_at < 4 * self::SEGMENT_BYTES ) {
					$record = $this->read_exact( $proof, self::RECORD_BYTES );
					if ( ! preg_match( '/\A([0-9]{10}) ([0-9a-f]{64})\n\z/D', $record, $match ) ) {
						throw new RuntimeException( 'The CSV verification proof is invalid.' );
					}
					$length = (int) $match[1];
					if ( $length < 1 || $length > self::SEGMENT_BYTES || $offset + $length > $state['final_bytes'] ) {
						throw new RuntimeException( 'The CSV verification segment size is invalid.' );
					}
					$this->verify_part( $csv, $length, $match[2], $context );
					$offset += $length;
					++$part;
				}
			} finally {
				fclose( $proof );
			}
		} finally {
			fclose( $csv );
		}

		if ( $part === $state['final_parts'] ) {
			if ( $offset !== $state['final_bytes'] ) {
				throw new RuntimeException( 'The verified data does not cover the complete CSV.' );
			}
			if ( ! $was_renamed ) {
				$this->rename( $partial, $published );
			}
			$this->write_ready_marker( $dir, $offset, $format, $context instanceof HashContext ? hash_final( clone $context ) : $context, $extension );
			$state['checksum_algorithm'] = $format;
			$state['checksum']           = $context instanceof HashContext ? hash_final( $context ) : $context;
			if ( 'sha256' === $format ) {
				$state['sha256'] = $state['checksum'];
			}
			unset( $state['verify_format'], $state['verify_php'], $state['verify_bytes'], $state['verify_part'], $state['verify_context'] );
		} else {
			$state['verify_format']  = $format;
			$state['verify_php']     = PHP_VERSION_ID;
			$state['verify_bytes']   = $offset;
			$state['verify_part']    = $part;
			$state['verify_context'] = $context instanceof HashContext ? base64_encode( serialize( $context ) ) : $context;
		}

		return $state;
	}

	/**
	 * Package the verified CSV using ZIP64 STORE in bounded 8 MiB work units.
	 * Compression streams cannot be checkpointed portably. STORE deliberately
	 * trades compression for recovery, bounded memory and valid files above 4 GiB.
	 * ZIP CRC32 state is serializable on PHP 8+. Every archive byte also receives
	 * an independent SHA-256 proof and is reread by verify() before publication.
	 *
	 * @param string $id Job UUID.
	 * @param array  $csv_state Verified CSV checkpoint with optional archive state.
	 * @return array Proposed archive checkpoint, committed by the caller.
	 */
	public function package( string $id, array $csv_state ): array {
		if ( ! method_exists( HashContext::class, '__serialize' ) ) {
			throw new RuntimeException( 'Resumable ZIP64 packaging requires PHP 8.0 or newer.' );
		}
		$dir    = $this->job_dir( $id );
		$state  = $csv_state['archive'] ?? array();
		$offset = (int) ( $state['source_bytes'] ?? 0 );
		$size   = (int) $csv_state['final_bytes'];
		$this->capacity( min( self::SEGMENT_BYTES, $size - $offset ) + 1024 );
		$crc = hash_init( 'crc32b' );
		if ( $offset ) {
			if ( PHP_VERSION_ID !== ( $state['php'] ?? 0 ) ) {
				// A PHP upgrade cannot safely deserialize a different runtime's context.
				$state  = array();
				$offset = 0;
			} else {
				$crc = unserialize( base64_decode( $state['crc'], true ), array( 'allowed_classes' => array( HashContext::class ) ) );
				if ( ! $crc instanceof HashContext ) {
					throw new RuntimeException( 'The ZIP64 CRC checkpoint is invalid.' );
				}
			}
		}
		$input = $this->open( $this->path( $id, 'csv', $csv_state ), 'rb' );
		try {
			if ( 0 !== fseek( $input, $offset ) ) {
				throw new RuntimeException( 'The ZIP64 source offset is invalid.' );
			}
			$output = $this->open( $dir . '/products.zip.partial', empty( $state['final_bytes'] ) ? 'c+b' : 'r+b' );
			try {
				$proof = $this->open( $dir . '/zip-proof.partial', empty( $state['final_parts'] ) ? 'c+b' : 'r+b' );
				try {
					$this->position( $output, (int) ( $state['final_bytes'] ?? 0 ) );
					$this->position( $proof, (int) ( $state['final_parts'] ?? 0 ) * self::RECORD_BYTES );
					$segment = hash_init( 'sha256' );
					$length  = 0;
					$name    = 'products.csv';
					if ( ! $offset ) {
						// Bit 3 defers CRC and sizes to the descriptor. Keep local
						// ZIP64 sizes zero too, as required by strict older readers.
						$extra  = pack( 'vvPP', 1, 16, 0, 0 );
						$header = pack( 'VvvvvvVVVvv', 0x04034b50, 45, 0x0808, 0, 0, 33, 0, 0xffffffff, 0xffffffff, strlen( $name ), strlen( $extra ) );
						$this->write_proven( $output, $proof, $header . $name . $extra, $segment, $length );
					}
					$remaining = min( self::SEGMENT_BYTES, $size - $offset );
					while ( $remaining > 0 ) {
						$block = $this->read_exact( $input, min( 1024 * 1024, $remaining ) );
						hash_update( $crc, $block );
						$this->write_proven( $output, $proof, $block, $segment, $length );
						$offset    += strlen( $block );
						$remaining -= strlen( $block );
					}
					if ( $offset === $size ) {
						$crc_value      = hexdec( hash_final( clone $crc ) );
						$descriptor     = pack( 'VVPP', 0x08074b50, $crc_value, $size, $size );
						$central_offset = ftell( $output ) + strlen( $descriptor );
						$extra          = pack( 'vvPPP', 1, 24, $size, $size, 0 );
						$central        = pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 45, 45, 0x0808, 0, 0, 33, $crc_value, 0xffffffff, 0xffffffff, strlen( $name ), strlen( $extra ), 0, 0, 0, 0, 0xffffffff ) . $name . $extra;
						$end_offset     = $central_offset + strlen( $central );
						$end64          = pack( 'VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, 1, 1, strlen( $central ), $central_offset );
						$locator        = pack( 'VVPV', 0x07064b50, 0, $end_offset, 1 );
						$end            = pack( 'VvvvvVVv', 0x06054b50, 0, 0, 0xffff, 0xffff, 0xffffffff, 0xffffffff, 0 );
						$this->write_proven( $output, $proof, $descriptor . $central . $end64 . $locator . $end, $segment, $length );
						$state['finished'] = true;
					}
					if ( $length ) {
						$this->write_proof( $proof, $length, hash_final( $segment ) );
					}
					$this->sync( $output );
					$this->sync( $proof );
					$state['source_bytes'] = $offset;
					$state['final_bytes']  = $this->size( $output );
					$state['final_parts']  = intdiv( $this->size( $proof ), self::RECORD_BYTES );
					$state['php']          = PHP_VERSION_ID;
					$state['crc']          = base64_encode( serialize( $crc ) );
				} finally {
					fclose( $proof );
				}
			} finally {
				fclose( $output );
			}
		} finally {
			fclose( $input );
		}
		return $state;
	}

	/**
	 * Resolve the verified artifact after authorization.
	 *
	 * @param string $id Job UUID.
	 * @return string Absolute filesystem path.
	 */
	public function path( string $id, string $extension = 'csv', array $expected = array() ): string {
		$this->extension( $extension );
		$dir    = $this->job_dir( $id );
		$path   = $dir . '/products.' . $extension;
		$stream = $this->open( $path, 'rb' );
		$size   = $this->size( $stream );
		fclose( $stream );
		$marker = $this->open( $dir . ( 'csv' === $extension ? '/ready.json' : '/ready-zip.json' ), 'rb' );
		try {
			$marker_size = $this->size( $marker );
			if ( $marker_size < 1 || $marker_size > 1024 ) {
				throw new RuntimeException( 'The export readiness marker is invalid.' );
			}
			$data = json_decode( $this->read_exact( $marker, $marker_size ), true, 4, JSON_THROW_ON_ERROR );
		} finally {
			fclose( $marker );
		}
		if ( ! is_array( $data ) || (int) ( $data['bytes'] ?? -1 ) !== $size || ! preg_match( '/\A[a-f0-9]{64}\z/D', $data['checksum'] ?? '' ) ) {
			throw new RuntimeException( 'The export file has not been verified for download.' );
		}
		if ( $expected && ( ( $expected['final_bytes'] ?? -1 ) !== $size || ! hash_equals( (string) ( $expected['checksum'] ?? '' ), $data['checksum'] ) || ( $expected['checksum_algorithm'] ?? '' ) !== $data['algorithm'] ) ) {
			throw new RuntimeException( 'The export artifact does not match its database checkpoint.' );
		}
		return $path;
	}

	/**
	 * Delete a chunk only after the caller confirms that the persisted checkpoint
	 * does not include its index. This is recovery for a file committed before DB.
	 *
	 * @param string $id Job UUID.
	 * @param int    $index Uncommitted chunk index.
	 */
	public function discard_uncommitted_chunk( string $id, int $index ): void {
		$this->discard_file( $this->chunk_path( $this->job_dir( $id ), $index ) );
	}

	/**
	 * Resolve a private offload route after authorization.
	 *
	 * @param string $id Job UUID.
	 * @return string Path relative to the configured private base.
	 */
	public function relative_path( string $id, string $extension = 'csv' ): string {
		$this->path( $id, $extension );
		return $this->site . '/' . $id . '/products.' . $extension;
	}

	/**
	 * Clean at most 256 files. Safe to repeat after interruption.
	 *
	 * @param string $id Job UUID.
	 * @return bool Whether the job directory is fully removed.
	 */
	public function purge( string $id ): bool {
		$dir = $this->job_dir( $id );
		if ( ! file_exists( $dir ) ) {
			return true;
		}
		$removed = 0;
		foreach ( new DirectoryIterator( $dir ) as $file ) {
			if ( $file->isDot() ) {
				continue;
			}
			if ( ! preg_match( '/\A(?:chunk-[0-9]{8}\.rows(?:\.tmp)?|products\.(?:partial|csv|zip(?:\.partial)?)|(?:zip-)?proof\.partial|(?:manifest|ready(?:-zip)?)\.json(?:\.tmp)?)\z/D', $file->getFilename() ) ) {
				throw new RuntimeException( 'An unexpected file prevents automatic large export cleanup.' );
			}
			$this->discard_file( $file->getPathname() );
			if ( ++$removed >= 256 ) {
				return false;
			}
		}
		if ( ! @rmdir( $dir ) ) {
			throw new RuntimeException( 'Unable to remove the large export job directory.' );
		}
		return true;
	}

	private function append_chunk( $input, $csv, $proof, array $columns, string $delimiter, &$segment, int &$length ): int {
		$hash  = hash_init( 'sha256' );
		$rows  = 0;
		$bytes = 0;
		while ( true ) {
			$line = fgets( $input, self::MAX_ROW_BYTES + 2 );
			if ( false === $line ) {
				break;
			}
			if ( "\n" !== substr( $line, -1 ) ) {
				throw new RuntimeException( 'An export chunk contains an incomplete row.' );
			}
			if ( '+' !== $line[0] ) {
				$footer = json_decode( $line, true, 8, JSON_THROW_ON_ERROR );
				if ( array( $rows, $bytes, hash_final( $hash ) ) !== $footer || false !== fgetc( $input ) || ! feof( $input ) ) {
					throw new RuntimeException( 'An export chunk failed its checksum or row count verification.' );
				}
				return $rows;
			}
			hash_update( $hash, $line );
			$bytes += strlen( $line );
			$row    = json_decode( substr( $line, 1 ), true, 8, JSON_THROW_ON_ERROR );
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'An export chunk contains an invalid row.' );
			}
			$cells = array();
			foreach ( $columns as $key ) {
				$cells[] = $row[ $key ] ?? '';
			}
			$this->write_proven( $csv, $proof, $this->csv_row( $cells, $delimiter ), $segment, $length );
			++$rows;
		}
		throw new RuntimeException( 'An export chunk is missing its checksum footer.' );
	}

	private function csv_row( array $cells, string $delimiter ): string {
		// This keeps the CSV escape byte empty on all supported PHP versions and
		// lets the writer compare the expected byte count against each actual write.
		$special = $delimiter . "\"\r\n\t ";
		foreach ( $cells as &$cell ) {
			if ( ! is_scalar( $cell ) && null !== $cell ) {
				throw new RuntimeException( 'An export column contains a non-scalar value.' );
			}
			$cell = (string) $cell;
			if ( false !== strpbrk( $cell, $special ) || ( '' === $cell && 1 === count( $cells ) ) ) {
				$cell = '"' . str_replace( '"', '""', $cell ) . '"';
			}
		}
		unset( $cell );
		return implode( $delimiter, $cells ) . "\n";
	}

	private function write_proven( $csv, $proof, string $data, &$segment, int &$length ): void {
		$offset = 0;
		$size   = strlen( $data );
		do {
			$block = substr( $data, $offset, self::SEGMENT_BYTES - $length );
			$this->write_all( $csv, $block );
			hash_update( $segment, $block );
			$length += strlen( $block );
			$offset += strlen( $block );
			if ( self::SEGMENT_BYTES === $length ) {
				$this->write_proof( $proof, $length, hash_final( $segment ) );
				$segment = hash_init( 'sha256' );
				$length  = 0;
			}
		} while ( $offset < $size );
	}

	private function write_proof( $proof, int $length, string $digest ): void {
		$this->write_all( $proof, sprintf( "%010d %s\n", $length, $digest ) );
	}

	private function verify_part( $stream, int $remaining, string $expected, &$context ): void {
		$hash = hash_init( 'sha256' );
		while ( $remaining > 0 ) {
			$data = $this->read_exact( $stream, min( $remaining, 1024 * 1024 ) );
			hash_update( $hash, $data );
			if ( self::is_hash_context( $context ) ) {
				hash_update( $context, $data );
			}
			$remaining -= strlen( $data );
		}
		$digest = hash_final( $hash );
		if ( ! hash_equals( $expected, $digest ) ) {
			throw new RuntimeException( 'The CSV failed verification.' );
		}
		if ( ! self::is_hash_context( $context ) ) {
			$context = hash( 'sha256', $context . $digest );
		}
	}

	private function write_ready_marker( string $dir, int $bytes, string $algorithm, string $checksum, string $extension = 'csv' ): void {
		$path   = $dir . ( 'csv' === $extension ? '/ready.json' : '/ready-zip.json' );
		$stream = $this->open( $path . '.tmp', 'c+b' );
		try {
			$this->position( $stream, 0 );
			$this->write_all(
				$stream,
				json_encode(
					array(
						'bytes'     => $bytes,
						'algorithm' => $algorithm,
						'checksum'  => $checksum,
					),
					JSON_THROW_ON_ERROR
				)
			);
			$this->sync( $stream );
		} finally {
			fclose( $stream );
		}
		$this->discard_file( $path );
		$this->rename( $path . '.tmp', $path );
	}

	private function extension( string $extension ): void {
		if ( ! in_array( $extension, array( 'csv', 'zip' ), true ) ) {
			throw new RuntimeException( 'Unsupported export artifact format.' );
		}
	}

	private static function normalized( string $path ): string {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		return '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path;
	}

	private function site_dir(): string {
		return $this->root . '/' . $this->site;
	}

	private function job_dir( string $id, bool $create = false ): string {
		if ( ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id ) ) {
			throw new RuntimeException( 'Invalid export job ID.' );
		}
		// Recheck the parent for every operation before using a descendant.
		$this->directory( $this->site_dir() );
		$dir = $this->site_dir() . '/' . $id;
		if ( $create ) {
			$this->ensure_directory( $dir );
		} elseif ( file_exists( $dir ) || is_link( $dir ) ) {
			$this->directory( $dir );
		}
		return $dir;
	}

	private function ensure_directory( string $path ): void {
		if ( ! file_exists( $path ) && ! is_link( $path ) && ! @mkdir( $path, 0700 ) && ! is_dir( $path ) ) {
			throw new RuntimeException( 'Unable to create private large export storage.' );
		}
		$this->directory( $path );
	}

	private function directory( string $path ): void {
		clearstatcache( true, $path );
		$real = realpath( $path );
		if ( is_link( $path ) || ! is_dir( $path ) || false === $real || self::normalized( $real ) !== self::normalized( $path ) ) {
			throw new RuntimeException( 'Large export directory is missing or unsafe.' );
		}
	}

	private function chunk_path( string $dir, int $index ): string {
		if ( $index < 0 || $index >= 100000000 ) {
			throw new RuntimeException( 'The large export chunk number is out of range.' );
		}
		return sprintf( '%s/chunk-%08d.rows', $dir, $index );
	}

	private function open( string $path, string $mode ) {
		clearstatcache( true, $path );
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			throw new RuntimeException( 'An export file path is unsafe.' );
		}
		$stream = @fopen( $path, $mode );
		if ( false === $stream ) {
			throw new RuntimeException( 'Unable to open a required export file.' );
		}
		$metadata = fstat( $stream );
		if ( false === $metadata || 0100000 !== ( $metadata['mode'] & 0170000 ) || 1 !== $metadata['nlink'] ) {
			fclose( $stream );
			throw new RuntimeException( 'Export storage requires regular files with one filesystem link.' );
		}
		return $stream;
	}

	private function discard_file( string $path ): void {
		clearstatcache( true, $path );
		if ( is_link( $path ) || ( file_exists( $path ) && ( ! is_file( $path ) || ! @unlink( $path ) ) ) ) {
			throw new RuntimeException( 'Unable to remove a large export file.' );
		}
	}

	private function rename( string $from, string $to ): void {
		clearstatcache( true, $to );
		if ( file_exists( $to ) || is_link( $to ) || ! @rename( $from, $to ) ) {
			throw new RuntimeException( 'Unable to publish a large export file.' );
		}
	}

	private function position( $stream, int $offset ): void {
		$size = $this->size( $stream );
		if ( $size < $offset || ( $size > $offset && ! self::io(
			static function () use ( $stream, $offset ) {
				return ftruncate( $stream, $offset ); }
		) ) || 0 !== fseek( $stream, $offset ) ) {
			throw new RuntimeException( 'Export file does not match its committed byte offset.' );
		}
	}

	private function size( $stream ): int {
		$metadata = fstat( $stream );
		if ( false === $metadata || $metadata['size'] < 0 ) {
			throw new RuntimeException( 'Unable to determine the export file size.' );
		}
		return (int) $metadata['size'];
	}

	private function write_all( $stream, string $data ): void {
		$length = strlen( $data );
		for ( $offset = 0; $offset < $length; $offset += $written ) {
			$written = self::io(
				static function () use ( $stream, $data, $offset ) {
					return fwrite( $stream, 0 === $offset ? $data : substr( $data, $offset ) );
				}
			);
			if ( false === $written || 0 === $written ) {
				throw new RuntimeException( 'An export file write could not complete.' );
			}
		}
	}

	private function read_exact( $stream, int $length ): string {
		$data = '';
		while ( strlen( $data ) < $length ) {
			$remaining = $length - strlen( $data );
			$part      = self::io(
				static function () use ( $stream, $remaining ) {
					return fread( $stream, $remaining );
				}
			);
			if ( false === $part || '' === $part ) {
				throw new RuntimeException( 'An export file read ended early.' );
			}
			$data .= $part;
		}
		return $data;
	}

	private function sync( $stream ): void {
		if ( ! self::io(
			static function () use ( $stream ) {
				return fflush( $stream ) && ( ! function_exists( 'fsync' ) || fsync( $stream ) ); }
		) ) {
			throw new RuntimeException( 'Unable to flush an export file to disk.' );
		}
	}

	/**
	 * Turn an I/O warning into a safe exception while preserving the caller's
	 * error handler. Warning text can contain private filesystem paths. Results
	 * are still checked by each caller, including zero-byte and partial writes.
	 *
	 * @param callable $operation One local I/O operation.
	 * @return mixed Operation result.
	 */
	public static function io( callable $operation ) {
		set_error_handler(
			static function () {
				throw new RuntimeException( 'A private export filesystem operation failed.' );
			}
		);
		try {
			return $operation();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Supported runtimes use HashContext objects; the segment fallback is a string.
	 *
	 * @param mixed $context Hash context from hash_init().
	 * @return bool
	 */
	private static function is_hash_context( $context ): bool {
		return is_object( $context ) && is_a( $context, 'HashContext' );
	}
}
