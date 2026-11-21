<?php
/**
 * Init WooCommerce data exporters.
 *
 * @package     WooCommerce\Admin
 * @version     x.x.x
 */

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Enums\ProductType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Admin_Exporters Class.
 */
class WC_Admin_Exporters {

	/**
	 * Array of exporter IDs.
	 *
	 * @var string[]
	 */
	protected $exporters = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! $this->export_allowed() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'add_to_menus' ) );
		add_action( 'admin_head', array( $this, 'hide_from_menus' ) );
		add_action( 'admin_head', array( $this, 'menu_highlight_for_product_export' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'admin_init', array( $this, 'download_export_file' ) );
		add_action( 'wp_ajax_woocommerce_do_ajax_product_export', array( $this, 'do_ajax_product_export' ) );

		// Register WooCommerce exporters.
		$this->exporters['product_exporter'] = array(
			'menu'       => 'edit.php?post_type=product',
			'name'       => __( 'Product Export', 'woocommerce' ),
			'capability' => 'export',
			'callback'   => array( $this, 'product_exporter' ),
		);
	}

	/**
	 * Return true if WooCommerce export is allowed for current user, false otherwise.
	 *
	 * @return bool Whether current user can perform export.
	 */
	protected function export_allowed() {
		return current_user_can( 'edit_products' ) && current_user_can( 'export' );
	}

	/**
	 * Add menu items for our custom exporters.
	 */
	public function add_to_menus() {
		foreach ( $this->exporters as $id => $exporter ) {
			add_submenu_page( $exporter['menu'], $exporter['name'], $exporter['name'], $exporter['capability'], $id, $exporter['callback'] );
		}
	}

	/**
	 * Hide menu items from view so the pages exist, but the menu items do not.
	 */
	public function hide_from_menus() {
		global $submenu;

		foreach ( $this->exporters as $id => $exporter ) {
			if ( isset( $submenu[ $exporter['menu'] ] ) ) {
				foreach ( $submenu[ $exporter['menu'] ] as $key => $menu ) {
					if ( $id === $menu[2] ) {
						unset( $submenu[ $exporter['menu'] ][ $key ] );
					}
				}
			}
		}
	}

	/**
	 * Highlight Products > All Products submenu for Product Exporter.
	 */
	public function menu_highlight_for_product_export() {
		global $submenu_file;

		$screen = get_current_screen();

		if ( $screen && 'product_page_product_exporter' === $screen->id ) {
			$submenu_file = 'edit.php?post_type=product'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Enqueue scripts.
	 */
	public function admin_scripts() {
		$suffix  = Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min';
		$version = Constants::get_constant( 'WC_VERSION' ) . '-hpt-export-2';
		wp_register_script( 'wc-product-export', WC()->plugin_url() . '/assets/js/admin/wc-product-export' . $suffix . '.js', array( 'jquery' ), $version );
		wp_localize_script(
			'wc-product-export',
			'wc_product_export_params',
			array(
				'export_nonce'       => wp_create_nonce( 'wc-product-export' ),
				'export_error'       => __( 'The product export stopped because the server returned an unexpected response. Please try again.', 'woocommerce' ),
				'export_timeout'     => __( 'The product export request timed out.', 'woocommerce' ),
				'export_error_batch' => __( 'Export stopped at batch %d.', 'woocommerce' ),
			)
		);
	}

	/**
	 * Export page UI.
	 */
	public function product_exporter() {
		if ( defined( 'WC_LARGE_PRODUCT_EXPORT' ) && true === WC_LARGE_PRODUCT_EXPORT && class_exists( '\Automattic\WooCommerce\Internal\LargeExport\Integration' ) ) {
			\Automattic\WooCommerce\Internal\LargeExport\Integration::render_page();
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Large exports require the background engine. Enable WC_LARGE_PRODUCT_EXPORT, configure private WC_LARGE_EXPORT_DIR storage and a worker before exporting large selections. Synchronous batches stop safely when their resource budget is exceeded.', 'woocommerce' ) . '</p></div>';
		include_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
		include_once __DIR__ . '/views/html-admin-page-product-export.php';
	}

	/**
	 * Serve the generated file.
	 */
	public function download_export_file() {
		if ( isset( $_GET['action'], $_GET['nonce'] ) && wp_verify_nonce( wp_unslash( $_GET['nonce'] ), 'product-csv' ) && 'download_product_csv' === wp_unslash( $_GET['action'] ) ) { // WPCS: input var ok, sanitization ok.
			if ( ! $this->export_allowed() ) {
				wp_die( esc_html__( 'Insufficient privileges to export products.', 'woocommerce' ), '', array( 'response' => 403 ) );
			}
			include_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
			$exporter = new WC_Product_CSV_Exporter();
			$uploads  = wp_upload_dir();
			try {
				$download_lock = WC_Product_Export_Resources::download_lock( $uploads['basedir'] );
			} catch ( RuntimeException $error ) {
				wp_die( esc_html__( 'The CSV download could not acquire its storage lock.', 'woocommerce' ), '', array( 'response' => 409 ) );
			}
			if ( ! $download_lock ) {
				header( 'Retry-After: 10' );
				wp_die( esc_html__( 'Another CSV download is running. Try again when it finishes.', 'woocommerce' ), '', array( 'response' => 429 ) );
			}

			if ( ! empty( $_GET['filename'] ) ) { // WPCS: input var ok.
				$exporter->set_filename( wp_unslash( $_GET['filename'] ) ); // WPCS: input var ok, sanitization ok.
			}

			try {
				$exporter->export();
			} catch ( RuntimeException $error ) {
				wp_die( esc_html__( 'The CSV could not be downloaded within safe resource limits. Use the background exporter or check storage availability.', 'woocommerce' ), '', array( 'response' => 409 ) );
			}
		}
	}

	/**
	 * AJAX callback for doing the actual export to the CSV file.
	 */
	public function do_ajax_product_export() {
		check_ajax_referer( 'wc-product-export', 'security' );

		if ( ! $this->export_allowed() ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient privileges to export products.', 'woocommerce' ) ) );
		}
		if ( defined( 'WC_LARGE_PRODUCT_EXPORT' ) && true === WC_LARGE_PRODUCT_EXPORT ) {
			wp_send_json_error( array( 'message' => __( 'Background export is enabled. Reload the Export page to create a saved background job.', 'woocommerce' ) ), 409 );
		}

		include_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';

		$step     = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 1; // WPCS: input var ok, sanitization ok.
		$exporter = new WC_Product_CSV_Exporter();
		$cursor   = isset( $_POST['export_cursor'] ) ? absint( wp_unslash( $_POST['export_cursor'] ) ) : 0; // WPCS: input var ok, sanitization ok.
		$exporter->set_export_cursor( $cursor );
		$exporter->set_excel_view( ! empty( $_POST['excel_view'] ) ); // WPCS: input var ok.

		if ( ! empty( $_POST['columns'] ) ) { // WPCS: input var ok.
			$exporter->set_column_names( wp_unslash( $_POST['columns'] ) ); // WPCS: input var ok, sanitization ok.
		}

		if ( ! empty( $_POST['selected_columns'] ) ) { // WPCS: input var ok.
			$exporter->set_columns_to_export( wp_unslash( $_POST['selected_columns'] ) ); // WPCS: input var ok, sanitization ok.
		}

		if ( ! empty( $_POST['export_meta'] ) ) { // WPCS: input var ok.
			$exporter->enable_meta_export( true );
		}

		if ( ! empty( $_POST['export_types'] ) ) { // WPCS: input var ok.
			$exporter->set_product_types_to_export( wp_unslash( $_POST['export_types'] ) ); // WPCS: input var ok, sanitization ok.
		}

		if ( ! empty( $_POST['export_category'] ) && is_array( $_POST['export_category'] ) ) {// WPCS: input var ok.
			$exporter->set_product_category_to_export( wp_unslash( array_values( $_POST['export_category'] ) ) ); // WPCS: input var ok, sanitization ok.
		}

		// Set specific product IDs if provided.
		if ( ! empty( $_POST['export_product_ids'] ) ) { // WPCS: input var ok.
			$ids_raw = explode( ',', sanitize_text_field( wp_unslash( $_POST['export_product_ids'] ) ) ); // WPCS: input var ok, sanitization ok.

			if ( ! empty( $ids_raw ) ) {
				$exporter->set_product_ids_to_export( $ids_raw );
			}
		}

		if ( ! empty( $_POST['filename'] ) ) { // WPCS: input var ok.
			$exporter->set_filename( wp_unslash( $_POST['filename'] ) ); // WPCS: input var ok, sanitization ok.
		}

		$exporter->set_page( $step );
		$uploads   = wp_upload_dir();
		$lock_path = trailingslashit( $uploads['basedir'] ) . '.wc-product-export.lock';
		$lock      = ! is_link( $lock_path ) ? @fopen( $lock_path, 'c+b' ) : false;
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			wp_send_json_error( array( 'message' => __( 'Another product export batch is running. Wait for it to finish before trying again.', 'woocommerce' ) ), 429 );
		}
		try {
			$exporter->generate_file();
		} catch ( RuntimeException $error ) {
			$export_error = in_array(
				$error->getMessage(),
				array(
					'Product export stopped before exceeding its memory budget. Use a smaller background export batch.',
					'Product export related-data batch exceeds its safe limit.',
					'The product query cannot be preflighted within safe resource limits. Review query filters before exporting.',
					'This selection has too many variations for synchronous export. Enable Large Product Export and use the background exporter.',
					'This selection exceeds 10000 primary records. Enable Large Product Export and use the background exporter.',
				),
				true
			) ? $error->getMessage() : __( 'The export batch could not complete safely. Check storage and database availability, then use the background exporter.', 'woocommerce' );
		} finally {
			fclose( $lock );
		}
		if ( isset( $export_error ) ) {
			wp_send_json_error( array( 'message' => $export_error ), 400 );
		}

		$query_args = apply_filters(
			'woocommerce_export_get_ajax_query_args',
			array(
				'nonce'    => wp_create_nonce( 'product-csv' ),
				'action'   => 'download_product_csv',
				'filename' => $exporter->get_filename(),
			)
		);

		if ( 100 === $exporter->get_percent_complete() ) {
			wp_send_json_success(
				array(
					'step'       => 'done',
					'percentage' => 100,
					'url'        => add_query_arg( $query_args, admin_url( 'edit.php?post_type=product&page=product_exporter' ) ),
				)
			);
		} else {
			wp_send_json_success(
				array(
					'step'       => ++$step,
					'percentage' => $exporter->get_percent_complete(),
					'columns'    => $exporter->get_column_names(),
					'cursor'     => $exporter->get_next_export_cursor(),
				)
			);
		}
	}

	/**
	 * Gets the product types that can be exported.
	 *
	 * @since 5.1.0
	 * @return array The product types keys and labels.
	 */
	public static function get_product_types() {
		$product_types                           = wc_get_product_types();
		$product_types[ ProductType::VARIATION ] = __( 'Product variations', 'woocommerce' );

		/**
		 * Allow third-parties to filter the exportable product types.
		 *
		 * @since 5.1.0
		 * @param array $product_types {
		 *     The product type key and label.
		 *
		 *     @type string Product type key - eg 'variable', 'simple' etc.
		 *     @type string A translated product label which appears in the export product type dropdown.
		 * }
		 */
		return apply_filters( 'woocommerce_exporter_product_types', $product_types );
	}
}

new WC_Admin_Exporters();
