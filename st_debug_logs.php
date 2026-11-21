<?php
require('wp-load.php');
global $wpdb;
$table = $wpdb->prefix . 'st_traffic_logs';
$results = $wpdb->get_results("SELECT id, ip, created_at FROM {$table} ORDER BY id DESC LIMIT 5");
echo "<pre>";
print_r($results);
$dt = new DateTime( 'now', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
echo "Current Time (HCM): " . $dt->format('Y-m-d H:i:s') . "\n";
echo "Rate Limit Config: " . get_option('st_rate_limit') . "\n";
$dt->modify( '-5 seconds' );
echo "Window (5s ago): " . $dt->format('Y-m-d H:i:s') . "\n";
