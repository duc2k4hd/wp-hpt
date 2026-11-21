<?php
/**
 * Công cụ Reset dữ liệu Staff Tracking trên Server
 * Đường dẫn: /wp-content/plugins/staff-tracking/reset-db-tool.php
 * 
 * Bảo mật: Chỉ cho phép chạy khi:
 * 1. Chạy qua dòng lệnh (CLI): php reset-db-tool.php
 * 2. Hoặc chạy qua trình duyệt/curl với token bảo mật: ?token=st_reset_secure_2026
 */

// 1. Tự động tìm và load môi trường WordPress
$wp_load_path = null;
$search_dirs = [
    dirname(__FILE__) . '/../../../wp-load.php',
    dirname(__FILE__) . '/../../wp-load.php',
    dirname(__FILE__) . '/../wp-load.php',
    dirname(__FILE__) . '/wp-load.php'
];

foreach ($search_dirs as $path) {
    if (file_exists($path)) {
        $wp_load_path = $path;
        break;
    }
}

if (!$wp_load_path) {
    die("Lỗi: Không tìm thấy tệp tin wp-load.php để khởi chạy WordPress.\n");
}

require_once($wp_load_path);

// 2. Kiểm tra quyền truy cập bảo mật
$is_cli = (php_sapi_name() === 'cli');
$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

if (!$is_cli && $token !== 'hpt_st_reset_secure_2026') {
    status_header(403);
    die("Quyền truy cập bị từ chối. Token sai hoặc không tồn tại!\n");
}

// 3. Thực hiện dọn dẹp dữ liệu
global $wpdb;

$tables = [
    $wpdb->prefix . 'staff_tracking',
    $wpdb->prefix . 'st_behavior_logs',
    $wpdb->prefix . 'st_personnel',
    $wpdb->prefix . 'st_assignments',
    $wpdb->prefix . 'st_payouts'
];

echo $is_cli ? "" : "<pre>";
echo "=== BẮT ĐẦU QUÁ TRÌNH RESET DỮ LIỆU PLUGIN ===\n\n";

foreach ($tables as $table) {
    // Truncate để làm trống dữ liệu và đưa AUTO_INCREMENT về 1
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query("TRUNCATE TABLE `{$table}`");
    echo "✔ Đã làm trống bảng: {$table}\n";
}

// Dọn dẹp cache transients trong bảng options
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '_transient_st_%'");
echo "✔ Đã dọn dẹp bộ nhớ đệm transients cache trong options.\n";

// Clear KPI cache
ST_Database::clear_kpi_cache();
echo "✔ Đã làm sạch cache KPI thành công.\n";

echo "\n✔ RESET THÀNH CÔNG! HỆ THỐNG ĐÃ TRỐNG HOÀN TOÀN NHƯ MỚI.\n";
echo $is_cli ? "" : "</pre>";
