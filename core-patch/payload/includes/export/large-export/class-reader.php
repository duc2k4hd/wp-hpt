<?php
/**
 * Bounded product selection and row transformation for background exports.
 *
 * @package WooCommerce\Export
 */

namespace Automattic\WooCommerce\Internal\LargeExport;

use RuntimeException;
use WC_Data_Store;

defined( 'ABSPATH' ) || exit;

require_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';

/**
 * Reuses the existing WooCommerce column and product serialization hooks.
 */
final class Reader extends \WC_Product_CSV_Exporter {
	/**
	 * Preserve formula-like values for CSVs explicitly intended for import.
	 *
	 * @var bool
	 */
	private $spreadsheet;

	public function __construct( array $config ) {
		// This class can be loaded while WooCommerce itself is bootstrapping.
		// Defer the admin exporter until a worker/admin request has finished loading
		// WordPress pluggable functions (the class constructor checks capabilities).
		if ( ! class_exists( 'WC_Admin_Exporters', false ) ) {
			require_once WC_ABSPATH . 'includes/admin/class-wc-admin-exporters.php';
		}
		parent::__construct();
		$this->spreadsheet = $config['spreadsheet'];
		$this->set_excel_view( ! empty( $config['excel_view'] ) );
		$this->set_product_types_to_export( $config['types'] );
		$this->set_columns_to_export( $config['columns'] );
		$this->set_product_category_to_export( $config['categories'] ?? array() );
		$this->set_product_ids_to_export( $config['ids'] ?? array() );
		$this->enable_meta_export( $config['meta'] );
	}

	/** Capture the supported data store and insertion watermark without hydrating rows. */
	public function source_boundary(): array {
		global $wpdb;
		$cpt = 'WC_Product_Data_Store_CPT' === WC_Data_Store::load( 'product' )->get_current_class_name();
		$max = $cpt ? $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) : null;
		if ( $cpt && $wpdb->last_error ) {
			throw new RuntimeException( 'The product ID watermark could not be read.' );
		}
		return array(
			'cpt'    => $cpt,
			'max_id' => $cpt ? (int) $max : null,
		);
	}

	public function escape_data( $data ) {
		return $this->spreadsheet ? parent::escape_data( $data ) : $data;
	}

	/**
	 * Initialize the checkpoint. Post IDs are an insertion boundary, not a snapshot.
	 */
	public function initialize( array $config ): array {
		global $wpdb;

		$source  = $config['source'] ?? $this->source_boundary();
		$cpt     = ! empty( $source['cpt'] );
		$current = 'WC_Product_Data_Store_CPT' === WC_Data_Store::load( 'product' )->get_current_class_name();
		if ( $cpt !== $current ) {
			throw new RuntimeException( 'The product data store changed. Start a new export.' );
		}
		$state = array(
			'index'       => 0,
			'rows'        => 0,
			'bytes'       => 0,
			'cursor'      => 0,
			'page'        => 1,
			'parents'     => array(),
			'child'       => 0,
			'columns'     => $this->columns(),
			'raw_columns' => $this->get_column_names(),
			'max_id'      => $source['max_id'],
			'mode'        => $cpt ? $config['mode'] : 'compatibility',
			'cpt'         => $cpt,
		);
		if ( $cpt ) {
			// A cheap upper bound for the default catalog selection. Filtered totals may be unknown.
			if ( $config['estimate'] && Engine::STATUSES === $config['statuses'] &&
				! $config['ids'] && ! $config['categories'] && array_keys( \WC_Admin_Exporters::get_product_types() ) === $config['types'] &&
				! has_filter( 'woocommerce_product_export_product_query_args' ) && ! has_filter( 'woocommerce_product_object_query_args' ) &&
				! has_filter( 'woocommerce_product_object_query' ) && ! has_filter( 'woocommerce_product_data_store_cpt_get_products_query' ) ) {
				$estimate = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(TABLE_ROWS) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->posts ) );
				if ( ! $wpdb->last_error && (int) $estimate > 0 ) {
					$state['estimate'] = (int) $estimate;
				}
			}
		}
		return $state;
	}

	/**
	 * Select a single batch and advance a proposed checkpoint. The caller commits it
	 * only after the corresponding chunk is durable.
	 */
	public function select( array $config, array $state, int $limit ): array {
		$query_start = microtime( true );
		$current_cpt = 'WC_Product_Data_Store_CPT' === WC_Data_Store::load( 'product' )->get_current_class_name();
		if ( $current_cpt !== (bool) $state['cpt'] ) {
			throw new RuntimeException( 'The product data store changed. Start a new export.' );
		}
		$main = array(
			'status'   => $config['statuses'],
			'limit'    => $limit,
			'page'     => $state['page'],
			'orderby'  => array( 'ID' => 'ASC' ),
			'return'   => 'objects',
			'paginate' => false,
			'type'     => $config['types'],
		);
		if ( $config['ids'] ) {
			$main['include'] = $config['ids'];
			$main['type']    = array_keys( \WC_Admin_Exporters::get_product_types() );
		} elseif ( $config['categories'] ) {
			$main['category'] = $config['categories'];
		}
		$main             = apply_filters( 'woocommerce_product_export_product_query_args', $main );
		$fingerprint_args = $main;
		unset( $fingerprint_args['page'], $fingerprint_args['limit'], $fingerprint_args['return'], $fingerprint_args['paginate'] );
		$fingerprint = hash( 'sha256', wp_json_encode( $fingerprint_args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) );
		if ( isset( $state['query_fingerprint'] ) && ! hash_equals( $state['query_fingerprint'], $fingerprint ) ) {
			throw new RuntimeException( 'The product export query changed while the job was running. Start a new export.' );
		}
		$state['query_fingerprint'] = $fingerprint;
		$main['limit']              = min( $limit, max( 1, (int) $main['limit'] ) );
		$main['return']             = 'objects';
		$main['paginate']           = false;
		if ( 'keyset' === $state['mode'] && ( array( 'ID' => 'ASC' ) !== $main['orderby'] || ! empty( $main['offset'] ) ) ) {
			if ( $state['cursor'] || $state['rows'] ) {
				throw new RuntimeException( 'The product query order changed during export. Start a new export.' );
			}
			$state['mode'] = 'compatibility';
		}
		// OFFSET pages must retain their original width. A changing limit shifts
		// every subsequent page and can silently duplicate or omit products.
		if ( 'compatibility' === $state['mode'] ) {
			if ( isset( $state['page_limit'] ) && $state['page_limit'] !== $main['limit'] ) {
				throw new RuntimeException( 'The product query page size changed during export. Start a new export.' );
			}
			$state['page_limit'] = $main['limit'];
		}

		if ( $state['parents'] ) {
			$child_limit = $main['limit'];
			$parent      = reset( $state['parents'] );
			$args        = array(
				'parent'   => $parent,
				'type'     => 'variation',
				// The legacy parent-expansion query uses WC_Product_Query defaults
				// (which exclude scheduled variations), unless a new status filter is explicit.
				'status'   => $config['child_statuses'] ?? $config['statuses'],
				'return'   => 'objects',
				'orderby'  => array( 'ID' => 'ASC' ),
				'limit'    => $child_limit,
				'page'     => 1 + (int) ( $state['child_page'] ?? 0 ),
				'paginate' => false,
			);
			$products    = $this->query( $args, $state, $state['child'] );
			if ( $products ) {
				$state['child']      = end( $products )->get_id();
				$state['child_page'] = $args['page'];
			}
			if ( count( $products ) < $child_limit ) {
				array_shift( $state['parents'] );
				$state['child']      = 0;
				$state['child_page'] = 0;
			}
			// A variation queried directly as well as through its parent belongs in the CSV once.
			if ( $products && ( ! empty( $main['include'] ) || ! empty( $main['category'] ) ) ) {
				$ids             = array_map(
					static function ( $product ) {
						return $product->get_id();
					},
					$products
				);
				$main['include'] = empty( $main['include'] ) ? $ids : array_values( array_intersect( $ids, (array) $main['include'] ) );
				if ( $main['include'] ) {
					$main['page']  = 1;
					$main['limit'] = count( $ids );
					unset( $main['offset'] );
					$direct     = $this->query( $main, $state, 0 );
					$direct_ids = array_fill_keys(
						array_map(
							static function ( $product ) {
								return $product->get_id();
							},
							$direct
						),
						true
					);
					$products   = array_filter(
						$products,
						static function ( $product ) use ( $direct_ids ) {
							return ! isset( $direct_ids[ $product->get_id() ] );
						}
					);
				}
			}
			return array( $products, $state, false, microtime( true ) - $query_start );
		}

		$products = $this->query( $main, $state, $state['cursor'] );
		if ( ! $products ) {
			return array( array(), $state, true, microtime( true ) - $query_start );
		}
		$state['cursor'] = end( $products )->get_id();
		++$state['page'];
		if ( ! empty( $main['include'] ) || ! empty( $main['category'] ) ) {
			foreach ( $products as $product ) {
				if ( $product->is_type( 'variable' ) ) {
					$state['parents'][] = $product->get_id();
				}
			}
		}
		return array( $products, $state, false, microtime( true ) - $query_start );
	}

	/**
	 * Bound the WooCommerce CPT query without bypassing its query/term filters.
	 */
	private function query( array $args, array $state, int $cursor ): array {
		global $wpdb;
		$max_id      = $state['max_id'];
		$keyset      = 'keyset' === $state['mode'];
		$seen        = false;
		$error       = false;
		$marker      = wp_generate_uuid4();
		$bounded_cpt = ! empty( $state['cpt'] ) && null !== $max_id;
		$prime_meta  = $keyset && self::native_hooks_only( array( 'woocommerce_data_stores', 'woocommerce_product_data_store', 'woocommerce_product_variation_data_store', 'woocommerce_product_class' ) );
		$meta_primed = false;
		if ( $bounded_cpt ) {
			$args['wc_large_export_query'] = $marker;
			if ( $prime_meta ) {
				$args['cache_results'] = false;
			}
		}
		if ( $keyset ) {
			$args['page'] = 1;
		}
		$where    = null;
		$results  = null;
		$simplify = null;
		$split    = null;
		if ( $bounded_cpt ) {
			if ( $bounded_cpt ) {
				// Prime bounded metadata groups before WP/CRUD hydration. Native
				// IN(1000+) meta cache queries can become whole-table scans on MariaDB.
				$split = static function ( $value, $query ) use ( $marker ) {
					return $marker === $query->get( 'wc_large_export_query' ) ? false : $value;
				};
			}
			// Compatibility can remove the same proven tautology only when no SQL
			// extension hook can depend on its shape. Otherwise retain native SQL.
			$can_simplify = $keyset || self::native_hooks_only( array( 'pre_get_posts', 'parse_query', 'query', 'posts_search', 'posts_where', 'posts_where_paged', 'posts_join', 'posts_join_paged', 'posts_clauses', 'posts_distinct', 'posts_groupby', 'posts_orderby', 'posts_fields', 'post_limits', 'posts_request', 'posts_pre_query', 'posts_results', 'posts_clauses_request', 'posts_where_request', 'posts_join_request', 'posts_groupby_request', 'posts_orderby_request', 'posts_distinct_request', 'posts_fields_request', 'post_limits_request', 'posts_request_ids', 'split_the_query' ) );
			if ( $can_simplify && is_array( $args['type'] ) && in_array( 'variation', $args['type'], true ) &&
				self::native_hooks_only( array( 'get_terms', 'get_terms_args', 'get_terms_defaults', 'terms_clauses', 'pre_get_terms', 'terms_pre_query', 'get_terms_fields', 'get_terms_orderby', 'list_terms_exclusions' ) ) ) {
				// WC's "selected type OR no type" condition is a tautology ONLY
				// when the selection covers every actual product_type term. Removing
				// that exact clause avoids MariaDB materializing all product type
				// relationships on every cursor batch. Unknown types retain native SQL.
				$actual_types = $wpdb->get_col( $wpdb->prepare( "SELECT t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy=%s LIMIT 101", 'product_type' ) );
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'The product database query failed.' );
				}
				if ( count( $actual_types ) <= 100 && ! array_diff( $actual_types, $args['type'] ) ) {
					$expected = array(
						'relation' => 'OR',
						array(
							'taxonomy' => 'product_type',
							'field'    => 'slug',
							'terms'    => $args['type'],
						),
						array(
							'taxonomy' => 'product_type',
							'field'    => 'id',
							'operator' => 'NOT EXISTS',
						),
					);
					$simplify = static function ( $wp_args, $query_vars ) use ( $marker, $expected ) {
						if ( ( $query_vars['wc_large_export_query'] ?? null ) === $marker && isset( $wp_args['tax_query'] ) && 'AND' === ( $wp_args['tax_query']['relation'] ?? 'AND' ) ) {
							foreach ( $wp_args['tax_query'] as $key => $clause ) {
								if ( $expected === $clause ) {
									unset( $wp_args['tax_query'][ $key ] );
								}
							}
						}
						return $wp_args;
					};
					add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $simplify, PHP_INT_MAX, 2 );
				}
			}
			$where   = static function ( $sql, $query ) use ( &$seen, $max_id, $cursor, $keyset, $marker, $wpdb ) {
				if ( $marker === $query->get( 'wc_large_export_query' ) ) {
					$seen = true;
					$sql .= $wpdb->prepare( " AND {$wpdb->posts}.ID <= %d", $max_id );
					if ( $keyset ) {
						$sql .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $cursor );
					}
				}
				return $sql;
			};
			$results = static function ( $posts, $query ) use ( &$error, &$meta_primed, $prime_meta, $marker, $wpdb, $args ) {
				if ( $marker === $query->get( 'wc_large_export_query' ) && $wpdb->last_error ) {
					$error = true;
				}
				if ( ! $error && $posts && $marker === $query->get( 'wc_large_export_query' ) ) {
					\WC_Product_Export_Resources::check_ids( wp_list_pluck( $posts, 'ID' ) );
				}
				if ( ! $error && $prime_meta && $posts && $marker === $query->get( 'wc_large_export_query' ) ) {
					if ( count( $posts ) > $args['limit'] ) {
						throw new RuntimeException( 'A product query override does not support bounded background export. Use the legacy exporter.' );
					}
					self::prime_crud_metadata( $posts );
					$meta_primed = true;
				}
				return $posts;
			};
			add_filter( 'posts_where', $where, PHP_INT_MAX, 2 );
			add_filter( 'posts_results', $results, PHP_INT_MIN, 2 );
			if ( $split ) {
				add_filter( 'split_the_query', $split, PHP_INT_MAX, 2 );
			}
		}
		$preflight = static function ( $request, $query ) use ( $marker, $args ) {
			return \WC_Product_Export_Resources::preflight( $request, $query, $marker, $args['limit'], 'wc_large_export_query' );
		};
		add_filter( 'posts_request', $preflight, PHP_INT_MAX, 2 );
		$found = static function ( $sql, $query ) use ( $marker ) {
			return \WC_Product_Export_Resources::found_rows_query( $sql, $query, $marker, 'wc_large_export_query' );
		};
		add_filter( 'found_posts_query', $found, PHP_INT_MAX, 2 );
		try {
			$products = wc_get_products( $args );
			if ( $error || $wpdb->last_error ) {
				throw new RuntimeException( 'The product database query failed.' );
			}
		} finally {
			remove_filter( 'posts_request', $preflight, PHP_INT_MAX );
			remove_filter( 'found_posts_query', $found, PHP_INT_MAX );
			if ( $bounded_cpt ) {
				remove_filter( 'posts_where', $where, PHP_INT_MAX );
				remove_filter( 'posts_results', $results, PHP_INT_MIN );
				if ( $simplify ) {
					remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', $simplify, PHP_INT_MAX );
				}
				if ( $split ) {
					remove_filter( 'split_the_query', $split, PHP_INT_MAX );
				}
			}
		}
		if ( ! is_array( $products ) || ( $bounded_cpt && ! $seen ) || count( $products ) > $args['limit'] ) {
			throw new RuntimeException( 'A product query override does not support bounded background export. Use the legacy exporter.' );
		}
		$previous = $cursor;
		$ids      = array();
		foreach ( $products as $product ) {
			$id = $product instanceof \WC_Product ? $product->get_id() : 0;
			if ( ! $id || isset( $ids[ $id ] ) || ( $keyset && $id <= $previous ) || ( $bounded_cpt && $id > $max_id ) ) {
				throw new RuntimeException( 'The product query returned unexpected IDs. Use the compatibility mode or legacy exporter.' );
			}
			$ids[ $id ] = true;
			$previous   = $id;
		}
		if ( $prime_meta && ! $meta_primed && $products ) {
			self::prime_crud_metadata( $products );
		}
		return $products;
	}

	/**
	 * Core installs hooks even on an otherwise clean site. Permit only callbacks
	 * audited in this WooCommerce/WordPress baseline; extensions conservatively
	 * keep native term resolution and metadata hydration.
	 *
	 * @param string[] $hooks Hooks whose extension callbacks would change semantics.
	 * @return bool
	 */
	private static function native_hooks_only( array $hooks ): bool {
		$allowed = array(
			'_post_format_get_terms',
			'wc_change_term_counts',
			'wc_terms_clauses',
			'wc_change_pre_get_terms',
			'wc_change_get_terms_defaults',
			array( 'Automattic\\WooCommerce\\Admin\\API\\Init', 'add_data_stores' ),
		);
		foreach ( $hooks as $hook ) {
			if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
				continue;
			}
			foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( ! in_array( $callback['function'], $allowed, true ) ) {
						return false;
					}
				}
			}
		}
		return true;
	}

	/**
	 * WordPress's post_meta cache omits meta IDs, so WC_Data otherwise issues a
	 * separate read_meta() query per product even after update_post_meta_cache().
	 * Prime WC's raw cache before rows access lazy custom metadata. Each object's
	 * data store still invokes filter_raw_meta_data() and the existing meta hooks.
	 * Custom product classes/data stores retain their own unmodified hydration.
	 *
	 * @param array $posts Only the bounded, marked CPT query's result.
	 */
	private static function prime_crud_metadata( array $posts ): void {
		global $wpdb;
		$ids = array();
		foreach ( $posts as $post ) {
			$id = $post instanceof \WC_Product ? $post->get_id() : ( is_object( $post ) ? (int) $post->ID : (int) $post );
			if ( $id > 0 ) {
				$ids[ $id ] = array();
			}
		}
		if ( ! $ids ) {
			return;
		}
		foreach ( array_chunk( array_keys( $ids ), 500 ) as $group ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', array_fill( 0, count( $group ), '%d' ) ) . ') ORDER BY meta_id', $group ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'The product database query failed.' );
			}
			$wp_meta = array_fill_keys( $group, array() );
			foreach ( $rows as $row ) {
				$id = (int) $row->post_id;
				unset( $row->post_id );
				$ids[ $id ][]                       = $row;
				$wp_meta[ $id ][ $row->meta_key ][] = $row->meta_value;
			}
			foreach ( $wp_meta as $id => $values ) {
				wp_cache_add( $id, $values, 'post_meta' );
			}
		}
		\WC_Data::prime_raw_meta_data_cache( $ids, 'products' );
	}

	/**
	 * Discover and format one row at a time while retaining WooCommerce CRUD getters.
	 */
	public function rows( array $products ): iterable {
		if ( $products && method_exists( $this, 'prime_export_batch_caches' ) ) {
			$this->prime_export_batch_caches( $products );
		}
		foreach ( $products as $product ) {
			\WC_Product_Export_Resources::check_memory();
			$row = $this->generate_row_data( $product );
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'A product export row hook did not return a row.' );
			}
			foreach ( $row as $key => $value ) {
				$row[ $key ] = (string) $this->format_data( $value );
			}
			yield $row;
		}
	}

	public function columns(): array {
		$columns = array();
		foreach ( $this->get_column_names() as $key => $label ) {
			if ( $this->is_column_exporting( $key ) ) {
				$columns[ $key ] = (string) $this->format_data( $label );
			}
		}
		return $columns;
	}
}
