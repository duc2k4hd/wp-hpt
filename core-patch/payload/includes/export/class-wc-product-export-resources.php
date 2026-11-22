<?php
/**
 * Request-local resource guards shared by product export paths.
 *
 * @package WooCommerce\Export
 */

defined( 'ABSPATH' ) || exit;

/** Fail before native CRUD hydration can materialize an oversized metadata batch. */
final class WC_Product_Export_Resources {
	/** @var int Conservative retry size measured by the last failed preflight. */
	private static $suggested_batch = 1;

	public static function suggested_batch() {
		return self::$suggested_batch;
	}

	/** Reserve room for encoding, escaping and CSV buffers before copying a row. */
	public static function check_row( $row ) {
		if ( ! is_array( $row ) ) {
			throw new RuntimeException( 'A product export row hook did not return a row.' );
		}
		$bytes = 0;
		foreach ( $row as $value ) {
			$bytes += is_string( $value ) ? strlen( $value ) : 32;
		}
		self::check_memory( $bytes * 4, 1 );
		return $bytes;
	}
	/** One PHP-streamed download per storage directory, without blocking workers. */
	public static function download_lock( $directory ) {
		$path = trailingslashit( $directory ) . '.wc-product-export-download.lock';
		if ( is_link( $path ) ) {
			throw new RuntimeException( 'The CSV download lock path is unsafe.' );
		}
		$stream = self::io(
			static function () use ( $path ) {
				return fopen( $path, 'c+b' );
			}
		);
		if ( ! $stream ) {
			throw new RuntimeException( 'The CSV download lock could not be opened.' );
		}
		if ( ! flock( $stream, LOCK_EX | LOCK_NB ) ) {
			fclose( $stream );
			return null;
		}
		return $stream;
	}

	/** A ceiling independent of an excessively large hosting memory_limit. */
	public static function ceiling() {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		return $limit > 0 ? min( 256 * MB_IN_BYTES, (int) ( $limit * 0.70 ) ) : 256 * MB_IN_BYTES;
	}

	public static function check_memory( $additional = 0, $records = 0 ) {
		if ( memory_get_usage( true ) + max( 0, $additional ) >= self::ceiling() ) {
			$available             = max( 0, self::ceiling() - memory_get_usage( true ) );
			self::$suggested_batch = $additional > 0 && $records > 0 ? max( 1, (int) floor( $records * $available / $additional * 0.75 ) ) : 1;
			throw new RuntimeException( 'Product export stopped before exceeding its memory budget. Use a smaller background export batch.' );
		}
	}

	/**
	 * Check raw metadata sizes using a scalar aggregate, before cache/CRUD copies.
	 * The estimate includes per-meta overhead and parent data of variations.
	 * It is deliberately conservative, not a guarantee about extension allocations.
	 *
	 * @param int[] $ids Bounded IDs selected for hydration.
	 */
	public static function check_ids( array $ids ) {
		global $wpdb;
		self::check_memory();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return;
		}
		if ( count( $ids ) > 500 ) {
			throw new RuntimeException( 'Product export related-data batch exceeds its safe limit.' );
		}
		$source_count = count( $ids );
		$posts        = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'The product export resource check could not read the database.' );
		}
		$bytes = 0;
		foreach ( $posts as $post ) {
			if ( $post->post_parent ) {
				$ids[] = (int) $post->post_parent;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		foreach ( array_chunk( $ids, 100 ) as $group ) {
			$post_size = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(OCTET_LENGTH(post_content) + OCTET_LENGTH(post_excerpt) + OCTET_LENGTH(post_title) + 256), 0) FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_fill( 0, count( $group ), '%d' ) ) . ')', $group ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'The product export resource check could not read the database.' );
			}
			$bytes += (int) $post_size;
			self::check_memory( $bytes * 8, min( $source_count, count( $group ) ) );
			$size = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(COALESCE(OCTET_LENGTH(meta_value), 0) + COALESCE(OCTET_LENGTH(meta_key), 0) + 256), 0) FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', array_fill( 0, count( $group ), '%d' ) ) . ')', $group ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'The product export resource check could not read the database.' );
			}
			$bytes += (int) $size;
			self::check_memory( $bytes * 8, min( $source_count, count( $group ) ) );
			// Term objects/descriptions can exhaust the same object cache as meta.
			$terms = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(OCTET_LENGTH(tt.description) + OCTET_LENGTH(t.name) + OCTET_LENGTH(t.slug) + 512), 0) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id IN (" . implode( ',', array_fill( 0, count( $group ), '%d' ) ) . ')', $group ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'The product export resource check could not read the database.' );
			}
			$bytes += (int) $terms;
			self::check_memory( $bytes * 8, min( $source_count, count( $group ) ) );
		}
	}

	/** Preflight IDs and sizes before SELECT posts.* can allocate large descriptions. */
	public static function preflight( $request, $query, $marker, $limit, $key = 'wc_safe_export_query' ) {
		global $wpdb;
		if ( $marker !== $query->get( $key ) ) {
			return $request;
		}
		$pattern    = '/^SELECT\s+((?:SQL_CALC_FOUND_ROWS\s+)?(?:DISTINCT\s+)?)' . preg_quote( $wpdb->posts, '/' ) . '\.\*(?=\s+FROM\b)/i';
		$id_request = preg_replace_callback(
			$pattern,
			static function ( $matches ) use ( $wpdb ) {
				return 'SELECT ' . $matches[1] . $wpdb->posts . '.ID';
			},
			$request,
			1,
			$changed
		);
		if ( ! $changed || ! preg_match( '/\bLIMIT\s+(?:[0-9]+\s*,\s*)?([0-9]+)\s*$/i', $request, $match ) || (int) $match[1] > $limit || (int) $match[1] < 1 ) {
			throw new RuntimeException( 'The product query cannot be preflighted within safe resource limits. Review query filters before exporting.' );
		}
		$ids = $wpdb->get_col( $id_request ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Native WP_Query SQL; only the SELECT projection is replaced.
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'The product export resource check could not read the database.' );
		}
		if ( false !== stripos( $request, 'SQL_CALC_FOUND_ROWS' ) ) {
			$total = $wpdb->get_var( 'SELECT FOUND_ROWS()' );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'The product export resource check could not read the database.' );
			}
			$query->set( 'wc_safe_export_found_rows', (int) $total );
		}
		self::check_ids( $ids );
		$query->set( 'wc_safe_export_selected_ids', array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return "SELECT {$wpdb->posts}.* FROM {$wpdb->posts} WHERE 1=0";
		}
		// Reuse the exact ordered page selected by the original filtered query.
		// Hydration must not repeat its expensive joins or OFFSET scan.
		return $wpdb->prepare( "SELECT {$wpdb->posts}.* FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY FIELD(ID, ' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( $ids, $ids ) );
	}

	/** Preserve native totals before the resource checks issue additional queries. */
	public static function found_rows_query( $sql, $query, $marker, $key = 'wc_safe_export_query' ) {
		if ( $marker === $query->get( $key ) && 'SELECT FOUND_ROWS()' === $sql && null !== $query->get( 'wc_safe_export_found_rows', null ) ) {
			return 'SELECT ' . (int) $query->get( 'wc_safe_export_found_rows' );
		}
		return $sql;
	}

	/** A bounded WooCommerce query with a guard before native metadata priming. */
	public static function query( array $args ) {
		global $wpdb;
		self::check_memory();
		$marker                       = wp_generate_uuid4();
		$args['wc_safe_export_query'] = $marker;
		$guard                        = static function ( $posts, $query ) use ( $marker, $args ) {
			if ( $marker === $query->get( 'wc_safe_export_query' ) ) {
				if ( count( $posts ) > $args['limit'] ) {
					throw new RuntimeException( 'A product export query exceeded its bounded batch size.' );
				}
				self::check_ids( wp_list_pluck( $posts, 'ID' ) );
			}
			return $posts;
		};
		$preflight                    = static function ( $request, $query ) use ( $marker, $args ) {
			return self::preflight( $request, $query, $marker, $args['limit'] );
		};
		add_filter( 'posts_request', $preflight, PHP_INT_MAX, 2 );
		$found = static function ( $sql, $query ) use ( $marker ) {
			return self::found_rows_query( $sql, $query, $marker );
		};
		$split = static function ( $value, $query ) use ( $marker ) {
			return $marker === $query->get( 'wc_safe_export_query' ) ? false : $value;
		};
		add_filter( 'found_posts_query', $found, PHP_INT_MAX, 2 );
		add_filter( 'split_the_query', $split, PHP_INT_MAX, 2 );
		add_filter( 'posts_results', $guard, PHP_INT_MIN, 2 );
		try {
			$products = wc_get_products( $args );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'The product export resource check could not read the database.' );
			}
			return $products;
		} finally {
			remove_filter( 'posts_request', $preflight, PHP_INT_MAX );
			remove_filter( 'found_posts_query', $found, PHP_INT_MAX );
			remove_filter( 'split_the_query', $split, PHP_INT_MAX );
			remove_filter( 'posts_results', $guard, PHP_INT_MIN );
		}
	}

	/** Convert I/O warnings to a path-free error and preserve the caller's handler. */
	public static function io( callable $operation ) {
		set_error_handler(
			static function () {
				throw new RuntimeException( 'The CSV filesystem operation failed. Check storage permissions and free space.' );
			}
		);
		try {
			return $operation();
		} finally {
			restore_error_handler();
		}
	}
}
