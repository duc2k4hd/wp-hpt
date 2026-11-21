<?php
/**
 * Bounded record scanner for seekable CSV streams.
 *
 * @package WooCommerce\Import
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks CSV record and field sizes without reading an entire oversized record into memory.
 *
 * The scanner reads fixed-size blocks, validates one logical CSV record (including
 * quoted line breaks), then rewinds so PHP's fgetcsv() can parse the validated row.
 */
class WC_CSV_Stream_Reader {
	/**
	 * Scan the next logical CSV record and enforce its byte limits.
	 *
	 * @param resource $stream          Seekable CSV file handle.
	 * @param string   $delimiter       CSV field delimiter.
	 * @param string   $enclosure       CSV enclosure character.
	 * @param string   $escape          CSV escape character; NUL disables escaping.
	 * @param int      $max_record_size Maximum logical record size in bytes.
	 * @param int      $max_field_size  Maximum individual field size in bytes.
	 * @return bool True when a record is present, false at EOF.
	 * @throws RuntimeException When the stream is not seekable, is malformed, or exceeds a byte limit.
	 */
	public static function assert_record_bounds( $stream, $delimiter, $enclosure, $escape, $max_record_size, $max_field_size ) {
		$record_start = ftell( $stream );
		if ( false === $record_start ) {
			throw new RuntimeException( __( 'The CSV file is not seekable; the import cannot checkpoint it safely.', 'woocommerce' ) );
		}

		$delimiter       = (string) $delimiter;
		$enclosure       = (string) $enclosure;
		$escape          = (string) $escape;
		$max_record_size = max( 1, (int) $max_record_size );
		$max_field_size  = max( 1, min( $max_record_size, (int) $max_field_size ) );
		$record_size     = 0;
		$field_size      = 0;
		$in_enclosure    = false;
		$enclosure_pending = false;
		$escaped         = false;
		$found_record    = false;

		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$chunk_size = strlen( $chunk );
			for ( $index = 0; $index < $chunk_size; ++$index ) {
				$character = $chunk[ $index ];
				++$record_size;
				$found_record = true;

				if ( $record_size > $max_record_size ) {
					throw new RuntimeException(
						sprintf(
							/* translators: %d: maximum CSV record size in bytes. */
							__( 'CSV record exceeds the maximum allowed size of %d bytes.', 'woocommerce' ),
							$max_record_size
						)
					);
				}

				if ( $in_enclosure && $enclosure_pending ) {
					$enclosure_pending = false;
					if ( $character === $enclosure ) {
						// Doubled enclosure inside a quoted field represents one data byte.
						++$field_size;
						self::assert_field_size( $field_size, $max_field_size );
						continue;
					}

					$in_enclosure = false;
				}

				if ( $in_enclosure ) {
					if ( $escaped ) {
						++$field_size;
						self::assert_field_size( $field_size, $max_field_size );
						$escaped = false;
						continue;
					}

					if ( "\0" !== $escape && '' !== $escape && $character === $escape ) {
					++$field_size;
					self::assert_field_size( $field_size, $max_field_size );
					$escaped = true;
					continue;
				}

					if ( $character === $enclosure ) {
					$enclosure_pending = true;
					continue;
				}

					++$field_size;
					self::assert_field_size( $field_size, $max_field_size );
					continue;
				}

				if ( $escaped ) {
					++$field_size;
					self::assert_field_size( $field_size, $max_field_size );
					$escaped = false;
					continue;
				}

				if ( "\0" !== $escape && '' !== $escape && $character === $escape ) {
					++$field_size;
					self::assert_field_size( $field_size, $max_field_size );
					$escaped = true;
					continue;
				}

				if ( $character === $delimiter ) {
					$field_size = 0;
					continue;
				}

				if ( $character === $enclosure && 0 === $field_size ) {
					$in_enclosure = true;
					continue;
				}

				if ( "\n" === $character || "\r" === $character ) {
					break 2;
				}

				++$field_size;
				self::assert_field_size( $field_size, $max_field_size );
			}
		}

		if ( $in_enclosure && ! $enclosure_pending ) {
			throw new RuntimeException( __( 'The CSV file contains an unterminated quoted field.', 'woocommerce' ) );
		}

		if ( 0 !== fseek( $stream, $record_start ) ) {
			throw new RuntimeException( __( 'The CSV file could not be rewound to its last checkpoint.', 'woocommerce' ) );
		}

		return $found_record;
	}

	/**
	 * Assert that the current field does not exceed the configured byte limit.
	 *
	 * @param int $field_size      Field size in bytes.
	 * @param int $max_field_size Maximum field size in bytes.
	 * @throws RuntimeException When the field exceeds the configured maximum.
	 */
	private static function assert_field_size( $field_size, $max_field_size ) {
		if ( $field_size > $max_field_size ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %d: maximum CSV field size in bytes. */
					__( 'CSV field exceeds the maximum allowed size of %d bytes.', 'woocommerce' ),
					$max_field_size
				)
			);
		}
	}
}
