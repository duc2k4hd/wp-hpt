<?php
/**
 * Handles product CSV export.
 *
 * @package WooCommerce\Export
 * @version 3.1.0
 */

use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Enums\ProductStockStatus;
use Automattic\WooCommerce\Enums\ProductType;
use Automattic\WooCommerce\Internal\CostOfGoodsSold\CostOfGoodsSoldController;
use Automattic\WooCommerce\Utilities\I18nUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Include dependencies.
 */
if ( ! class_exists( 'WC_CSV_Batch_Exporter', false ) ) {
	include_once WC_ABSPATH . 'includes/export/abstract-wc-csv-batch-exporter.php';
}

require_once __DIR__ . '/class-wc-product-export-resources.php';

/**
 * WC_Product_CSV_Exporter Class.
 */
class WC_Product_CSV_Exporter extends WC_CSV_Batch_Exporter {
	/** @var bool Limit cells to Excel's capacity while preserving their HTML content. */
	protected $excel_view = false;

	/** Select Excel cell limits without stripping HTML or line breaks. */
	public function set_excel_view( $enabled ) {
		$this->excel_view = (bool) $enabled;
	}

	/** Preserve content and cap the final cell, including formula protection. */
	public function format_data( $data ) {
		$data = parent::format_data( $data );
		if ( ! $this->excel_view || ! is_string( $data ) ) {
			return $data;
		}
		// Bound the temporary string before UTF-16 conversion. Excel's cell limit
		// is 32,767 UTF-16 code units; supplementary characters consume two units.
		$data  = mb_substr( $data, 0, 32767, 'UTF-8' );
		$utf16 = mb_convert_encoding( $data, 'UTF-16LE', 'UTF-8' );
		if ( strlen( $utf16 ) <= 65534 ) {
			return $data;
		}
		$utf16 = substr( $utf16, 0, 65534 );
		$last  = unpack( 'v', substr( $utf16, -2 ) )[1];
		if ( 0xD800 <= $last && 0xDBFF >= $last ) {
			$utf16 = substr( $utf16, 0, -2 );
		}
		return mb_convert_encoding( $utf16, 'UTF-8', 'UTF-16LE' );
	}

	/** @var int|null Primary records processed, excluding expanded variations. */
	protected $export_main_count = null;

	/** Bound even a third-party batch-limit filter to a safe number of objects. */
	public function get_limit() {
		return max( 1, min( 100, (int) parent::get_limit() ) );
	}

	/** Expanded children must not make progress finish before all parents are read. */
	public function get_percent_complete() {
		if ( null === $this->export_main_count ) {
			return parent::get_percent_complete();
		}
		$processed = ( $this->get_page() - 1 ) * $this->get_limit() + $this->export_main_count;
		return $this->total_rows ? min( 100, (int) floor( 100 * $processed / $this->total_rows ) ) : 100;
	}

	/**
	 * Type of export used in filter names.
	 *
	 * @var string
	 */
	protected $export_type = 'product';

	/**
	 * Should meta be exported?
	 *
	 * @var boolean
	 */
	protected $enable_meta_export = false;

	/**
	 * Which product types are being exported.
	 *
	 * @var array
	 */
	protected $product_types_to_export = array();

	/**
	 * Products belonging to what category should be exported.
	 *
	 * @var array
	 */
	protected $product_category_to_export = array();

	/**
	 * Specific product IDs to export, overriding other filters if hook is not used.
	 *
	 * @var array
	 */
	protected $product_ids_to_export = array();

	/**
	 * Product objects loaded while generating the current export batch.
	 *
	 * @var WC_Product[]
	 */
	protected $export_product_cache = array();

	/**
	 * Grouped product children loaded while generating the current export batch.
	 *
	 * @var array<int, int[]>
	 */
	protected $export_grouped_children_cache = array();

	/**
	 * Last product ID returned by the current export query.
	 *
	 * @var int
	 */
	protected $next_export_cursor = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$batch_limit = (int) apply_filters( 'woocommerce_product_export_initial_batch_limit', 100, $this );
		$this->set_limit( max( 10, min( 100, $batch_limit ) ) );
		$this->set_product_types_to_export( array_keys( WC_Admin_Exporters::get_product_types() ) );
	}

	/**
	 * Should meta be exported?
	 *
	 * @param bool $enable_meta_export Should meta be exported.
	 *
	 * @since 3.1.0
	 */
	public function enable_meta_export( $enable_meta_export ) {
		$this->enable_meta_export = (bool) $enable_meta_export;
	}

	/**
	 * Product types to export.
	 *
	 * @param array $product_types_to_export List of types to export.
	 *
	 * @since 3.1.0
	 */
	public function set_product_types_to_export( $product_types_to_export ) {
		$this->product_types_to_export = array_map( 'wc_clean', $product_types_to_export );
	}

	/**
	 * Product category to export
	 *
	 * @param string $product_category_to_export Product category slug to export, empty string exports all.
	 *
	 * @since  3.5.0
	 * @return void
	 */
	public function set_product_category_to_export( $product_category_to_export ) {
		$this->product_category_to_export = array_map( 'sanitize_title_with_dashes', $product_category_to_export );
	}

	/**
	 * Specific product IDs to export.
	 *
	 * @param array $product_ids List of product IDs to export.
	 * @since 9.9.0
	 */
	public function set_product_ids_to_export( $product_ids ) {
		$this->product_ids_to_export = array_filter( array_map( 'absint', (array) $product_ids ) );
	}

	/**
	 * Set the last product ID returned by the previous export batch.
	 *
	 * @param int $cursor Last exported product ID.
	 */
	public function set_export_cursor( $cursor ) {
		$this->next_export_cursor = absint( $cursor );
	}

	/**
	 * Get the last product ID returned by this export batch.
	 *
	 * @return int
	 */
	public function get_next_export_cursor() {
		return $this->next_export_cursor;
	}

	/**
	 * Return an array of columns to export.
	 *
	 * @since  3.1.0
	 * @return array
	 */
	public function get_default_column_names() {
		$weight_unit_label    = I18nUtil::get_weight_unit_label( get_option( 'woocommerce_weight_unit', 'kg' ) );
		$dimension_unit_label = I18nUtil::get_dimensions_unit_label( get_option( 'woocommerce_dimension_unit', 'cm' ) );

		$default_columns = array(
			'id'                 => __( 'ID', 'woocommerce' ),
			'type'               => __( 'Type', 'woocommerce' ),
			'sku'                => __( 'SKU', 'woocommerce' ),
			'global_unique_id'   => __( 'GTIN, UPC, EAN, or ISBN', 'woocommerce' ),
			'name'               => __( 'Name', 'woocommerce' ),
			'published'          => __( 'Published', 'woocommerce' ),
			'featured'           => __( 'Is featured?', 'woocommerce' ),
			'catalog_visibility' => __( 'Visibility in catalog', 'woocommerce' ),
			'short_description'  => __( 'Short description', 'woocommerce' ),
			'description'        => __( 'Description', 'woocommerce' ),
			'date_on_sale_from'  => __( 'Date sale price starts', 'woocommerce' ),
			'date_on_sale_to'    => __( 'Date sale price ends', 'woocommerce' ),
			'tax_status'         => __( 'Tax status', 'woocommerce' ),
			'tax_class'          => __( 'Tax class', 'woocommerce' ),
			'stock_status'       => __( 'In stock?', 'woocommerce' ),
			'stock'              => __( 'Stock', 'woocommerce' ),
			'low_stock_amount'   => __( 'Low stock amount', 'woocommerce' ),
			'backorders'         => __( 'Backorders allowed?', 'woocommerce' ),
			'sold_individually'  => __( 'Sold individually?', 'woocommerce' ),
			/* translators: %s: weight */
			'weight'             => sprintf( __( 'Weight (%s)', 'woocommerce' ), $weight_unit_label ),
			/* translators: %s: length */
			'length'             => sprintf( __( 'Length (%s)', 'woocommerce' ), $dimension_unit_label ),
			/* translators: %s: width */
			'width'              => sprintf( __( 'Width (%s)', 'woocommerce' ), $dimension_unit_label ),
			/* translators: %s: Height */
			'height'             => sprintf( __( 'Height (%s)', 'woocommerce' ), $dimension_unit_label ),
			'reviews_allowed'    => __( 'Allow customer reviews?', 'woocommerce' ),
			'purchase_note'      => __( 'Purchase note', 'woocommerce' ),
			'sale_price'         => __( 'Sale price', 'woocommerce' ),
			'regular_price'      => __( 'Regular price', 'woocommerce' ),
			'category_ids'       => __( 'Categories', 'woocommerce' ),
			'tag_ids'            => __( 'Tags', 'woocommerce' ),
			'shipping_class_id'  => __( 'Shipping class', 'woocommerce' ),
			'images'             => __( 'Images', 'woocommerce' ),
			'download_limit'     => __( 'Download limit', 'woocommerce' ),
			'download_expiry'    => __( 'Download expiry days', 'woocommerce' ),
			'parent_id'          => __( 'Parent', 'woocommerce' ),
			'grouped_products'   => __( 'Grouped products', 'woocommerce' ),
			'upsell_ids'         => __( 'Upsells', 'woocommerce' ),
			'cross_sell_ids'     => __( 'Cross-sells', 'woocommerce' ),
			'product_url'        => __( 'External URL', 'woocommerce' ),
			'button_text'        => __( 'Button text', 'woocommerce' ),
			'menu_order'         => __( 'Position', 'woocommerce' ),
		);

		if ( wc_get_container()->get( CostOfGoodsSoldController::class )->feature_is_enabled() ) {
			$default_columns['cogs_value'] = __( 'Cost of goods', 'woocommerce' );
		}

		return apply_filters(
			"woocommerce_product_export_{$this->export_type}_default_columns",
			$default_columns
		);
	}

	/**
	 * Prepare data for export.
	 *
	 * @since 3.1.0
	 */
	public function prepare_data_to_export() {
		$read_only = static function () {
			return false;
		};
		add_filter( 'woocommerce_product_variation_update_title_on_read', $read_only, PHP_INT_MAX );
		try {
			$this->prepare_bounded_product_data();
		} finally {
			remove_filter( 'woocommerce_product_variation_update_title_on_read', $read_only, PHP_INT_MAX );
		}
	}

	/** Build one bounded batch without persisting variation title repairs on read. */
	protected function prepare_bounded_product_data() {
		WC_Product_Export_Resources::check_memory();
		$this->export_product_cache          = array();
		$this->export_grouped_children_cache = array();
		$args                                = array(
			'status'   => array( ProductStatus::PRIVATE, ProductStatus::PUBLISH, ProductStatus::DRAFT, ProductStatus::FUTURE, ProductStatus::PENDING ),
			'limit'    => $this->get_limit(),
			'page'     => $this->get_page(),
			'orderby'  => array(
				'ID' => 'ASC',
			),
			'return'   => 'objects',
			'paginate' => true,
		);

		// Set up query args based on whether specific IDs are being exported.
		// We ignore type/category initially when specific IDs are provided.
		if ( ! empty( $this->product_ids_to_export ) ) {
			$args['include'] = $this->product_ids_to_export;
		} else {
			// Use the type and category filters set on the instance.
			$args['type'] = $this->product_types_to_export;
			if ( ! empty( $this->product_category_to_export ) ) {
				$args['category'] = $this->product_category_to_export;
			}
		}

		/**
		 * Filter the query args for the product export.
		 *
		 * @since 3.5.0
		 * @param array $args Arguments to pass to wc_get_products().
		 */
		$args          = apply_filters( "woocommerce_product_export_{$this->export_type}_query_args", $args );
		$args['limit'] = $this->get_limit();

		if ( ! empty( $args['include'] ) ) {
			$args['include'] = array_map( 'absint', (array) $args['include'] );
		}

		$cursor                   = $this->next_export_cursor;
		$this->next_export_cursor = 0;
		$total_cache_key          = $this->get_export_total_cache_key( $args );
		$cached_total             = false;
		if ( 1 < $this->get_page() ) {
			$cached_total = get_transient( $total_cache_key );
		}

		// Reuse the first batch's count; cursor batches skip both found-rows and OFFSET.
		$can_reuse_total = false !== $cached_total && is_numeric( $cached_total );
		if ( $can_reuse_total ) {
			$args['paginate'] = false;
		}

		$cursor_compatible = isset( $args['orderby'] )
			&& array( 'ID' => 'ASC' ) === $args['orderby']
			&& empty( $args['offset'] );
		$use_cursor        = $cursor_compatible && (
			1 === $this->get_page()
			|| ( $can_reuse_total && 0 < $cursor )
		);
		if ( $use_cursor ) {
			$args['page'] = 1;
			if ( 0 < $cursor ) {
				$args['wc_product_export_after_id'] = $cursor;
			}
		}
		$cursor_filter = null;
		if ( $use_cursor && 0 < $cursor ) {
			$cursor_filter = static function ( $where, $query ) use ( $cursor ) {
				if ( $query instanceof WP_Query && $cursor === absint( $query->get( 'wc_product_export_after_id' ) ) ) {
					global $wpdb;
					$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $cursor );
				}
				return $where;
			};
			add_filter( 'posts_where', $cursor_filter, 10, 2 );
		}

		try {
			$products = WC_Product_Export_Resources::query( $args );
		} finally {
			if ( $cursor_filter ) {
				remove_filter( 'posts_where', $cursor_filter, 10 );
			}
		}

		if ( $can_reuse_total ) {
			$this->total_rows = absint( $cached_total );
			$product_rows     = $products;
		} else {
			$this->total_rows = absint( $products->total );
			$product_rows     = $products->products;
			if ( 1 === $this->get_page() ) {
				$expiration = (int) apply_filters( 'woocommerce_product_export_total_cache_expiration', DAY_IN_SECONDS, $this );
				set_transient( $total_cache_key, $this->total_rows, max( HOUR_IN_SECONDS, $expiration ) );
			}
		}
		if ( $this->total_rows > 10000 ) {
			throw new RuntimeException( 'This selection exceeds 10000 primary records. Enable Large Product Export and use the background exporter.' );
		}
		if ( $use_cursor && empty( $product_rows ) ) {
			// The first count is only a progress estimate. If rows disappear while
			// exporting, finish at the actual cursor boundary instead of looping on
			// a stale total forever.
			$this->total_rows = $this->get_total_exported();
		}

		$this->export_main_count = count( $product_rows );
		$this->row_data          = array();
		$variable_products       = array();
		$this->prime_export_batch_caches( $product_rows );
		if ( $use_cursor && $product_rows ) {
			$last_product = end( $product_rows );
			if ( $last_product instanceof WC_Product ) {
				$this->next_export_cursor = $last_product->get_id();
			}
		}

		foreach ( $product_rows as $product ) {
			WC_Product_Export_Resources::check_memory();
			// Check if the product is variable and if either the include or category filter is active.
			// This is to ensure that product variations are only included if they are being selectively exported or if they are part of a category.
			if ( ( ! empty( $args['include'] ) || ! empty( $args['category'] ) ) &&
				$product->is_type( ProductType::VARIABLE ) &&
				! in_array( $product->get_id(), $variable_products, true ) ) {
				$variable_products[] = $product->get_id();
			}

			$this->row_data[] = $this->generate_row_data( $product );
		}

		// If variable products were identified (either through include or category filters), fetch their variations.
		if ( ! empty( $variable_products ) ) {
			foreach ( $variable_products as $parent_id ) {
				// Small expansions can stay synchronous; large ones must use durable
				// child cursors in the background engine rather than an unbounded read.
				$remaining = max( 0, 500 - count( $this->row_data ) );
				$products  = WC_Product_Export_Resources::query(
					array(
						'parent'  => $parent_id,
						'type'    => array( ProductType::VARIATION ),
						'return'  => 'objects',
						'limit'   => $remaining + 1,
						'exclude' => array_map(
							static function ( $product ) {
								return $product->get_id(); },
							$product_rows
						),
					)
				);
				if ( count( $products ) > $remaining ) {
					throw new RuntimeException( 'This selection has too many variations for synchronous export. Enable Large Product Export and use the background exporter.' );
				}

				if ( ! $products ) {
					continue;
				}

				$this->prime_export_batch_caches( $products );

				foreach ( $products as $product ) {
					WC_Product_Export_Resources::check_memory();
					$this->row_data[] = $this->generate_row_data( $product );
				}
			}
		}
		$bytes = 0;
		foreach ( $this->row_data as $row ) {
			$bytes += WC_Product_Export_Resources::check_row( $row );
		}
		WC_Product_Export_Resources::check_memory( $bytes * 4 );
	}

	/**
	 * Build a per-export key for the product count so later batches do not repeat
	 * an expensive full query just to rediscover the same total row count.
	 *
	 * @param array $args Product query arguments after WooCommerce export filters.
	 * @return string
	 */
	protected function get_export_total_cache_key( $args ) {
		unset( $args['page'], $args['limit'], $args['return'], $args['paginate'], $args['wc_product_export_after_id'] );
		$query_hash = hash( 'sha256', (string) wp_json_encode( $args ) );

		return 'wc_product_export_total_' . md5( $this->get_filename() . '|' . $query_hash );
	}

	/**
	 * Prime attachment, linked-product and author caches before generating a batch.
	 *
	 * Standard WordPress queries already prime the main products. Exporting image
	 * columns and linked-product columns otherwise performs additional lookups for
	 * each image/relationship in each row; priming those IDs bounds the database
	 * work to a small number of queries per batch.
	 *
	 * @param WC_Product[] $products Products in the current export batch.
	 */
	protected function prime_export_batch_caches( $products ) {
		WC_Product_Export_Resources::check_memory();
		$attachment_ids = array();
		$related_ids    = array();
		$user_ids       = array();

		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$product_id = $product->get_id();
			if ( $product_id ) {
				$this->export_product_cache[ $product_id ] = $product;

				$post_data = get_post( $product_id );
				if ( $post_data && ! empty( $post_data->post_author ) ) {
					$user_ids[] = absint( $post_data->post_author );
				}
			}

			if ( $this->is_column_exporting( 'images' ) ) {
				$attachment_ids[] = $product->get_image_id( 'edit' );
				$attachment_ids   = array_merge( $attachment_ids, (array) $product->get_gallery_image_ids( 'edit' ) );
			}
			$related_ids = array_merge(
				$related_ids,
				$this->is_column_exporting( 'upsell_ids' ) ? (array) $product->get_upsell_ids( 'edit' ) : array(),
				$this->is_column_exporting( 'cross_sell_ids' ) ? (array) $product->get_cross_sell_ids( 'edit' ) : array(),
				$this->is_column_exporting( 'parent_id' ) ? array( $product->get_parent_id( 'edit' ) ) : array()
			);

			// HPT custom export columns resolve logo and TablePress relationship
			// posts per product. Prime those references as one bounded batch query.
			foreach ( array( 'logo', 'tablepress' ) as $related_meta_key ) {
				if ( ! $this->is_column_exporting( $related_meta_key ) ) {
					continue;
				}
				$related_meta_ids = $product->get_meta( $related_meta_key, true, 'edit' );
				if ( is_array( $related_meta_ids ) ) {
					array_walk_recursive(
						$related_meta_ids,
						static function ( $related_id ) use ( &$related_ids ) {
							if ( is_numeric( $related_id ) && absint( $related_id ) > 0 ) {
								$related_ids[] = absint( $related_id );
							}
						}
					);
				} elseif ( is_numeric( $related_meta_ids ) && absint( $related_meta_ids ) > 0 ) {
					$related_ids[] = absint( $related_meta_ids );
				}
			}

			if ( $this->is_column_exporting( 'grouped_products' ) && $product->is_type( ProductType::GROUPED ) ) {
				$children = array_filter( array_map( 'absint', (array) $product->get_children( 'edit' ) ) );
				$this->export_grouped_children_cache[ $product_id ] = $children;
				$related_ids                                        = array_merge( $related_ids, $children );
			}
		}

		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );
		$related_ids    = array_values( array_unique( array_filter( array_map( 'absint', $related_ids ) ) ) );
		$user_ids       = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );

		if ( $attachment_ids && function_exists( '_prime_post_caches' ) ) {
			foreach ( array_chunk( $attachment_ids, 100 ) as $group ) {
				WC_Product_Export_Resources::check_ids( $group );
				_prime_post_caches( $group, false, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			}
		}

		if ( $related_ids && function_exists( '_prime_post_caches' ) ) {
			foreach ( array_chunk( $related_ids, 100 ) as $group ) {
				WC_Product_Export_Resources::check_ids( $group );
				_prime_post_caches( $group, true, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			}
		}

		if ( $user_ids && function_exists( 'cache_users' ) ) {
			cache_users( $user_ids ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		}
	}

	/**
	 * Load and reuse a linked product object within the current export batch.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product|false
	 */
	protected function get_cached_export_product( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return false;
		}

		if ( ! array_key_exists( $product_id, $this->export_product_cache ) ) {
			WC_Product_Export_Resources::check_ids( array( $product_id ) );
			$this->export_product_cache[ $product_id ] = wc_get_product( $product_id );
		}

		return $this->export_product_cache[ $product_id ];
	}

	/**
	 * Take a product and generate row data from it for export.
	 *
	 * @param WC_Product $product WC_Product object.
	 *
	 * @return array
	 */
	protected function generate_row_data( $product ) {
		$columns = $this->get_column_names();
		$row     = array();
		foreach ( $columns as $column_id => $column_name ) {
			$column_id = strstr( $column_id, ':' ) ? current( explode( ':', $column_id ) ) : $column_id;
			$value     = '';

			// Skip some columns if dynamically handled later or if we're being selective.
			if ( in_array( $column_id, array( 'downloads', 'attributes', 'meta' ), true ) || ! $this->is_column_exporting( $column_id ) ) {
				continue;
			}

			if ( has_filter( "woocommerce_product_export_{$this->export_type}_column_{$column_id}" ) ) {
				// Filter for 3rd parties.
				$value = apply_filters( "woocommerce_product_export_{$this->export_type}_column_{$column_id}", '', $product, $column_id );

			} elseif ( is_callable( array( $this, "get_column_value_{$column_id}" ) ) ) {
				// Handle special columns which don't map 1:1 to product data.
				$value = $this->{"get_column_value_{$column_id}"}( $product );

			} elseif ( is_callable( array( $product, "get_{$column_id}" ) ) ) {
				// Default and custom handling.
				$value = $product->{"get_{$column_id}"}( 'edit' );
			}

			if ( 'description' === $column_id || 'short_description' === $column_id ) {
				$value = $this->filter_description_field( $value );
			}

			$row[ $column_id ] = $value;
		}

		$this->prepare_downloads_for_export( $product, $row );
		$this->prepare_attributes_for_export( $product, $row );
		$this->prepare_meta_for_export( $product, $row );

		/**
		 * Allow third-party plugins to filter the data in a single row of the exported CSV file.
		 *
		 * @since 3.1.0
		 *
		 * @param array                   $row         An associative array with the data of a single row in the CSV file.
		 * @param WC_Product              $product     The product object corresponding to the current row.
		 * @param WC_Product_CSV_Exporter $exporter    The instance of the CSV exporter.
		 */
		$row = apply_filters( 'woocommerce_product_export_row_data', $row, $product, $this );
		WC_Product_Export_Resources::check_row( $row );
		return $row;
	}

	/**
	 * Get published value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return int
	 */
	protected function get_column_value_published( $product ) {
		$statuses = array(
			ProductStatus::DRAFT   => -1,
			ProductStatus::PRIVATE => 0,
			ProductStatus::PUBLISH => 1,
		);

		// Fix display for variations when parent product is a draft.
		if ( ProductType::VARIATION === $product->get_type() ) {
			$parent = $product->get_parent_data();
			$status = ProductStatus::DRAFT === $parent['status'] ? $parent['status'] : $product->get_status( 'edit' );
		} else {
			$status = $product->get_status( 'edit' );
		}

		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : -1;
	}

	/**
	 * Get formatted sale price.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @return string
	 */
	protected function get_column_value_sale_price( $product ) {
		return wc_format_localized_price( $product->get_sale_price( 'view' ) );
	}

	/**
	 * Get formatted regular price.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @return string
	 */
	protected function get_column_value_regular_price( $product ) {
		return wc_format_localized_price( $product->get_regular_price() );
	}

	/**
	 * Get product_cat value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_category_ids( $product ) {
		$term_ids = $product->get_category_ids( 'edit' );
		return $this->format_term_ids( $term_ids, 'product_cat' );
	}

	/**
	 * Get product_tag value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_tag_ids( $product ) {
		$term_ids = $product->get_tag_ids( 'edit' );
		return $this->format_term_ids( $term_ids, 'product_tag' );
	}

	/**
	 * Get product_shipping_class value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_shipping_class_id( $product ) {
		$term_ids = $product->get_shipping_class_id( 'edit' );
		return $this->format_term_ids( $term_ids, 'product_shipping_class' );
	}

	/**
	 * Get images value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_images( $product ) {
		$image_ids = array_merge( array( $product->get_image_id( 'edit' ) ), $product->get_gallery_image_ids( 'edit' ) );
		$images    = array();

		foreach ( $image_ids as $image_id ) {
			$image = wp_get_attachment_image_src( $image_id, 'full' );

			if ( $image ) {
				$images[] = $image[0];
			}
		}

		return $this->implode_values( $images );
	}

	/**
	 * Prepare linked products for export.
	 *
	 * @param int[] $linked_products Array of linked product ids.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function prepare_linked_products_for_export( $linked_products ) {
		$product_list = array();

		foreach ( $linked_products as $linked_product ) {
			if ( $linked_product->get_sku() ) {
				$product_list[] = $linked_product->get_sku();
			} else {
				$product_list[] = 'id:' . $linked_product->get_id();
			}
		}

		return $this->implode_values( $product_list );
	}

	/**
	 * Get cross_sell_ids value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_cross_sell_ids( $product ) {
		return $this->prepare_linked_products_for_export( array_filter( array_map( array( $this, 'get_cached_export_product' ), (array) $product->get_cross_sell_ids( 'edit' ) ) ) );
	}

	/**
	 * Get upsell_ids value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_upsell_ids( $product ) {
		return $this->prepare_linked_products_for_export( array_filter( array_map( array( $this, 'get_cached_export_product' ), (array) $product->get_upsell_ids( 'edit' ) ) ) );
	}

	/**
	 * Get parent_id value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_parent_id( $product ) {
		if ( $product->get_parent_id( 'edit' ) ) {
			$parent = $this->get_cached_export_product( $product->get_parent_id( 'edit' ) );
			if ( ! $parent ) {
				return '';
			}

			return $parent->get_sku( 'edit' ) ? $parent->get_sku( 'edit' ) : 'id:' . $parent->get_id();
		}
		return '';
	}

	/**
	 * Get grouped_products value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_grouped_products( $product ) {
		if ( ProductType::GROUPED !== $product->get_type() ) {
			return '';
		}

		$grouped_products = array();
		$child_ids        = $this->export_grouped_children_cache[ $product->get_id() ] ?? $product->get_children( 'edit' );
		foreach ( $child_ids as $child_id ) {
			$child = $this->get_cached_export_product( $child_id );
			if ( ! $child ) {
				continue;
			}

			$grouped_products[] = $child->get_sku( 'edit' ) ? $child->get_sku( 'edit' ) : 'id:' . $child_id;
		}
		return $this->implode_values( $grouped_products );
	}

	/**
	 * Get download_limit value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_download_limit( $product ) {
		return $product->is_downloadable() && $product->get_download_limit( 'edit' ) ? $product->get_download_limit( 'edit' ) : '';
	}

	/**
	 * Get download_expiry value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_download_expiry( $product ) {
		return $product->is_downloadable() && $product->get_download_expiry( 'edit' ) ? $product->get_download_expiry( 'edit' ) : '';
	}

	/**
	 * Get stock value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_stock( $product ) {
		$manage_stock   = $product->get_manage_stock( 'edit' );
		$stock_quantity = $product->get_stock_quantity( 'edit' );

		if ( $product->is_type( ProductType::VARIATION ) && 'parent' === $manage_stock ) {
			return 'parent';
		} elseif ( $manage_stock ) {
			return $stock_quantity;
		} else {
			return '';
		}
	}

	/**
	 * Get stock status value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_stock_status( $product ) {
		$status = $product->get_stock_status( 'edit' );

		if ( ProductStockStatus::ON_BACKORDER === $status ) {
			return 'backorder';
		}

		return ProductStockStatus::IN_STOCK === $status ? 1 : 0;
	}

	/**
	 * Get backorders.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_backorders( $product ) {
		$backorders = $product->get_backorders( 'edit' );

		switch ( $backorders ) {
			case 'notify':
				return 'notify';
			default:
				return wc_string_to_bool( $backorders ) ? 1 : 0;
		}
	}

	/**
	 * Get low stock amount value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.5.0
	 * @return int|string Empty string if value not set
	 */
	protected function get_column_value_low_stock_amount( $product ) {
		return $product->managing_stock() && $product->get_low_stock_amount( 'edit' ) ? $product->get_low_stock_amount( 'edit' ) : '';
	}

	/**
	 * Get type value.
	 *
	 * @param WC_Product $product Product being exported.
	 *
	 * @since  3.1.0
	 * @return string
	 */
	protected function get_column_value_type( $product ) {
		$types   = array();
		$types[] = $product->get_type();

		if ( $product->is_downloadable() ) {
			$types[] = 'downloadable';
		}

		if ( $product->is_virtual() ) {
			$types[] = 'virtual';
		}

		return $this->implode_values( $types );
	}

	/**
	 * Filter description field for export.
	 * Convert newlines to '\n'.
	 *
	 * @param string $description Product description text to filter.
	 *
	 * @since  3.5.4
	 * @return string
	 */
	protected function filter_description_field( $description ) {
		if ( $this->excel_view ) {
			return $description;
		}
		$description = str_replace( '\n', "\\\\n", $description );
		$description = str_replace( "\n", '\n', $description );
		return $description;
	}
	/**
	 * Export downloads.
	 *
	 * @param WC_Product $product Product being exported.
	 * @param array      $row     Row being exported.
	 *
	 * @since 3.1.0
	 */
	protected function prepare_downloads_for_export( $product, &$row ) {
		if ( $product->is_downloadable() && $this->is_column_exporting( 'downloads' ) ) {
			$downloads = $product->get_downloads( 'edit' );

			if ( $downloads ) {
				$i = 1;
				foreach ( $downloads as $download ) {
					/* translators: %s: download number */
					$this->column_names[ 'downloads:id' . $i ] = sprintf( __( 'Download %d ID', 'woocommerce' ), $i );
					/* translators: %s: download number */
					$this->column_names[ 'downloads:name' . $i ] = sprintf( __( 'Download %d name', 'woocommerce' ), $i );
					/* translators: %s: download number */
					$this->column_names[ 'downloads:url' . $i ] = sprintf( __( 'Download %d URL', 'woocommerce' ), $i );
					$row[ 'downloads:id' . $i ]                 = $download->get_id();
					$row[ 'downloads:name' . $i ]               = $download->get_name();
					$row[ 'downloads:url' . $i ]                = $download->get_file();
					++$i;
				}
			}
		}
	}

	/**
	 * Export attributes data.
	 *
	 * @param WC_Product $product Product being exported.
	 * @param array      $row     Row being exported.
	 *
	 * @since 3.1.0
	 */
	protected function prepare_attributes_for_export( $product, &$row ) {
		if ( $this->is_column_exporting( 'attributes' ) ) {
			$attributes         = $product->get_attributes();
			$default_attributes = $product->get_default_attributes();

			if ( count( $attributes ) ) {
				$i = 1;
				foreach ( $attributes as $attribute_name => $attribute ) {
					/* translators: %s: attribute number */
					$this->column_names[ 'attributes:name' . $i ] = sprintf( __( 'Attribute %d name', 'woocommerce' ), $i );
					/* translators: %s: attribute number */
					$this->column_names[ 'attributes:value' . $i ] = sprintf( __( 'Attribute %d value(s)', 'woocommerce' ), $i );
					/* translators: %s: attribute number */
					$this->column_names[ 'attributes:visible' . $i ] = sprintf( __( 'Attribute %d visible', 'woocommerce' ), $i );
					/* translators: %s: attribute number */
					$this->column_names[ 'attributes:taxonomy' . $i ] = sprintf( __( 'Attribute %d global', 'woocommerce' ), $i );

					if ( is_a( $attribute, 'WC_Product_Attribute' ) ) {
						$row[ 'attributes:name' . $i ] = html_entity_decode( wc_attribute_label( $attribute->get_name(), $product ), ENT_QUOTES );

						if ( $attribute->is_taxonomy() ) {
							$terms  = $attribute->get_terms();
							$values = array();

							foreach ( $terms as $term ) {
								$values[] = $term->name;
							}

							$row[ 'attributes:value' . $i ]    = $this->implode_values( $values );
							$row[ 'attributes:taxonomy' . $i ] = 1;
						} else {
							$row[ 'attributes:value' . $i ]    = $this->implode_values( $attribute->get_options() );
							$row[ 'attributes:taxonomy' . $i ] = 0;
						}

						$row[ 'attributes:visible' . $i ] = $attribute->get_visible();
					} else {
						$row[ 'attributes:name' . $i ] = html_entity_decode( wc_attribute_label( $attribute_name, $product ), ENT_QUOTES );

						if ( 0 === strpos( $attribute_name, 'pa_' ) ) {
							$option_term = get_term_by( 'slug', $attribute, $attribute_name ); // @codingStandardsIgnoreLine.
							$row[ 'attributes:value' . $i ]    = $option_term && ! is_wp_error( $option_term ) ? html_entity_decode( str_replace( ',', '\\,', $option_term->name ), ENT_QUOTES ) : html_entity_decode( str_replace( ',', '\\,', $attribute ), ENT_QUOTES );
							$row[ 'attributes:taxonomy' . $i ] = 1;
						} else {
							$row[ 'attributes:value' . $i ]    = html_entity_decode( str_replace( ',', '\\,', $attribute ), ENT_QUOTES );
							$row[ 'attributes:taxonomy' . $i ] = 0;
						}

						$row[ 'attributes:visible' . $i ] = '';
					}

					if ( $product->is_type( ProductType::VARIABLE ) && isset( $default_attributes[ sanitize_title( $attribute_name ) ] ) ) {
						/* translators: %s: attribute number */
						$this->column_names[ 'attributes:default' . $i ] = sprintf( __( 'Attribute %d default', 'woocommerce' ), $i );
						$default_value                                   = $default_attributes[ sanitize_title( $attribute_name ) ];

						if ( 0 === strpos( $attribute_name, 'pa_' ) ) {
							$option_term = get_term_by( 'slug', $default_value, $attribute_name ); // @codingStandardsIgnoreLine.
							$row[ 'attributes:default' . $i ] = $option_term && ! is_wp_error( $option_term ) ? $option_term->name : $default_value;
						} else {
							$row[ 'attributes:default' . $i ] = $default_value;
						}
					}
					++$i;
				}
			}
		}
	}

	/**
	 * Export meta data.
	 *
	 * @param WC_Product $product Product being exported.
	 * @param array      $row Row data.
	 *
	 * @since 3.1.0
	 */
	protected function prepare_meta_for_export( $product, &$row ) {
		if ( $this->enable_meta_export ) {
			$meta_data = $product->get_meta_data();

			if ( count( $meta_data ) ) {
				$meta_keys_to_skip = apply_filters( 'woocommerce_product_export_skip_meta_keys', array(), $product );

				$i = 1;
				foreach ( $meta_data as $meta ) {
					if ( in_array( $meta->key, $meta_keys_to_skip, true ) ) {
						continue;
					}

					// Allow 3rd parties to process the meta, e.g. to transform non-scalar values to scalar.
					$meta_value = apply_filters( 'woocommerce_product_export_meta_value', $meta->value, $meta, $product, $row );

					if ( ! is_scalar( $meta_value ) ) {
						continue;
					}

					$column_key = 'meta:' . esc_attr( $meta->key );
					/* translators: %s: meta data name */
					$this->column_names[ $column_key ] = sprintf( __( 'Meta: %s', 'woocommerce' ), $meta->key );
					$row[ $column_key ]                = $meta_value;
					++$i;
				}
			}
		}
	}
}
