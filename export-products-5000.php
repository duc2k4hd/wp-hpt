<?php
/**
 * Export ALL WooCommerce products using native WordPress export logic
 * (Same as Tools > Export > Products)
 */

/* ================== HARD INIT ================== */
while (ob_get_level()) {
    ob_end_clean();
}

set_time_limit(0);
ini_set('memory_limit', '-1');
ignore_user_abort(true);

/* ================== LOAD WORDPRESS ================== */
define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';

/* ================== PERMISSION CHECK ================== */
if (!current_user_can('export')) {
    wp_die('You are not allowed to export.');
}

/* ================== LOAD EXPORT CORE ================== */
require_once ABSPATH . 'wp-admin/includes/export.php';

/* ================== FORCE HEADERS ================== */
header('Content-Type: application/rss+xml; charset=UTF-8');
header('Content-Disposition: attachment; filename=products-export.xml');
header('Pragma: no-cache');
header('Expires: 0');

/* ================== EXPORT ARGUMENTS ================== */
/**
 * This is EXACTLY what export.php passes internally
 */
$args = [
    'content' => 'product',
];

/* ================== RUN CORE EXPORT ================== */
export_wp($args);

/* ================== END ================== */
exit;
