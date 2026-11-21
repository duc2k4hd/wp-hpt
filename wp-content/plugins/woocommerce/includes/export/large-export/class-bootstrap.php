<?php
/**
 * Opt-in bootstrap for the durable large product exporter.
 *
 * @package WooCommerce\Export
 */

namespace Automattic\WooCommerce\Internal\LargeExport;

defined( 'ABSPATH' ) || exit;

/**
 * Load the isolated export subsystem only when explicitly enabled by wp-config.php.
 */
final class Bootstrap {
	/**
	 * Register background, admin, download, and CLI entry points.
	 */
	public static function register(): void {
		if ( ! defined( 'WC_LARGE_PRODUCT_EXPORT' ) || true !== WC_LARGE_PRODUCT_EXPORT ) {
			return;
		}

		require_once __DIR__ . '/class-store.php';
		require_once __DIR__ . '/class-files.php';
		require_once __DIR__ . '/class-reader.php';
		require_once __DIR__ . '/class-engine.php';
		require_once __DIR__ . '/class-command.php';
		require_once __DIR__ . '/class-integration.php';

		Integration::register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_hook( 'after_wp_load', array( Command::class, 'register' ) );
		}
	}
}
