<?php
// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = [
    'staff_tracking'    => $wpdb->prefix . 'staff_tracking',
    'st_behavior_logs' => $wpdb->prefix . 'st_behavior_logs',
    'st_personnel'      => $wpdb->prefix . 'st_personnel',
    'st_assignments'    => $wpdb->prefix . 'st_assignments',
    'st_payouts'        => $wpdb->prefix . 'st_payouts'
];

$timestamp   = date( 'Ymd_His' );
$backup_name = 'backup_' . $timestamp;

// Thư mục lưu backup 1: trong thư mục plugin (để trong thư mục backups của plugin)
$plugin_backup_dir = dirname( __FILE__ ) . '/backups/' . $backup_name;

// Thư mục lưu backup 2: trong thư mục uploads của WordPress (để tránh bị mất dữ liệu khi WordPress xóa folder plugin lúc gỡ cài đặt)
$upload_dir = wp_upload_dir();
$safe_backup_dir = $upload_dir['basedir'] . '/staff-tracking-backups/' . $backup_name;

// Tạo các thư mục nếu chưa tồn tại
if ( ! file_exists( $plugin_backup_dir ) ) {
    wp_mkdir_p( $plugin_backup_dir );
}
if ( ! file_exists( $safe_backup_dir ) ) {
    wp_mkdir_p( $safe_backup_dir );
}

// Tiến hành backup dữ liệu của từng bảng ra file JSON
foreach ( $tables as $key => $table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
    if ( ! empty( $rows ) ) {
        $json_data = json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        
        // Ghi vào thư mục plugin
        @file_put_contents( $plugin_backup_dir . '/' . $key . '.json', $json_data );
        
        // Ghi vào thư mục uploads an toàn
        @file_put_contents( $safe_backup_dir . '/' . $key . '.json', $json_data );
    }
}

// Xóa (DROP) các bảng trong cơ sở dữ liệu sạch sẽ
foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

// Xóa các options đã lưu cấu hình của plugin trong bảng options
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE 'st_%' OR option_name LIKE '_transient_st_%' OR option_name LIKE '_transient_timeout_st_%'" );
