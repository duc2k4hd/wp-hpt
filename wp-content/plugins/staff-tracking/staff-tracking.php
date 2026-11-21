<?php
/**
 * Plugin Name:  Staff Tracking
 * Plugin URI:   https://nguyenminhduc.id.vn/
 * Description:  Lightweight internal staff traffic tracking with admin dashboard and export.
 * Version:      1.3.30
 * Author:       Đức Nobi
 * License:      GPL-2.0+
 * Text Domain:  staff-tracking
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ────────────────────────────────────────────────────────────────
define( 'ST_VERSION',     '1.3.66' );
define( 'ST_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'ST_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'ST_TABLE',       'staff_tracking' );
define( 'ST_RATE_LIMIT',  60 );   // seconds – 1 session per IP per minute

// ─── Autoload classes ─────────────────────────────────────────────────────────
require_once ST_PLUGIN_DIR . 'includes/class-database.php';
require_once ST_PLUGIN_DIR . 'includes/class-wallet-service.php';
require_once ST_PLUGIN_DIR . 'includes/class-withdrawal-service.php';
require_once ST_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once ST_PLUGIN_DIR . 'includes/class-admin.php';
require_once ST_PLUGIN_DIR . 'includes/class-export.php';
require_once ST_PLUGIN_DIR . 'includes/class-traffic-validator.php';

// ─── Helpers ──────────────────────────────────────────────────────────────────
/**
 * Kiểm tra xem user hiện tại có được phép truy cập plugin không.
 * Mặc định: 'nguyenminhduc'. Các user khác lấy từ cấu hình.
 */
function st_is_user_allowed(): bool {
    $current_user = wp_get_current_user();
    if ( ! $current_user->exists() ) {
        return false;
    }

    $username = strtolower( $current_user->user_login );
    if ( $username === 'nguyenminhduc' ) {
        return true;
    }

    $allowed_str = get_option( 'st_allowed_admins', '' );
    if ( empty( $allowed_str ) ) {
        return false;
    }

    $allowed_users = array_map( 'trim', explode( ',', strtolower( $allowed_str ) ) );
    return in_array( $username, $allowed_users, true );
}

// ─── Bootstrap ────────────────────────────────────────────────────────────────
function st_init(): void {
    ST_Database::get_instance();
    ST_REST_API::get_instance();

    if ( is_admin() ) {
        ST_Admin::get_instance();
    }

    ST_Export::get_instance();

    // Tự động kiểm tra version CSDL để chạy nâng cấp tự động
    $db_ver = get_option( 'st_db_version', '' );
    if ( $db_ver !== ST_VERSION ) {
        ST_Database::install();
    }
}
add_action( 'plugins_loaded', 'st_init' );

// ─── Activation / Deactivation ────────────────────────────────────────────────
register_activation_hook( __FILE__, 'st_activate_plugin' );
register_deactivation_hook( __FILE__, 'st_deactivate_plugin' );

function st_activate_plugin(): void {
    ST_Database::install();
    if ( ! wp_next_scheduled( 'st_daily_payout_cron' ) ) {
        // Bắt đầu lúc 00:05 ngày tiếp theo
        $dt = new DateTime( 'tomorrow 00:05:00', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
        wp_schedule_event( $dt->getTimestamp(), 'daily', 'st_daily_payout_cron' );
    }
}

function st_deactivate_plugin(): void {
    wp_clear_scheduled_hook( 'st_daily_payout_cron' );
    flush_rewrite_rules();
}

// ─── Payout Cron Action ───────────────────────────────────────────────────────
add_action( 'st_daily_payout_cron', 'st_run_daily_payout' );
function st_run_daily_payout(): void {
    $yesterday = date( 'Y-m-d', strtotime( '-1 day' ) );
    ST_Database::create_payout_records( $yesterday );
}

// ─── Enqueue frontend assets ──────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'st_enqueue_frontend' );

function st_enqueue_frontend(): void {
    // Skip wp-admin, login page, cron, REST calls
    if ( is_admin() || wp_doing_ajax() ) {
        return;
    }

    wp_enqueue_style(
        'st-tracker',
        ST_PLUGIN_URL . 'assets/css/tracker.css',
        [],
        ST_VERSION   // Dùng version cố định — browser có thể cache, chỉ reload khi version thay đổi
    );

    wp_enqueue_script(
        'st-tracker',
        ST_PLUGIN_URL . 'assets/js/tracker.js',
        [],
        ST_VERSION,  // Dùng version cố định thay vì time()
        [ 'strategy' => 'defer', 'in_footer' => true ]
    );

    $onsite_opt = get_option( 'st_onsite_threshold' );
    $onsite_val = ( $onsite_opt !== '' && $onsite_opt !== false ) ? (int) $onsite_opt : 30;

    $scroll_opt = get_option( 'st_scroll_threshold' );
    $scroll_val = ( $scroll_opt !== '' && $scroll_opt !== false ) ? (int) $scroll_opt : 60;

    $toast_opt  = get_option( 'st_toast_duration' );
    $toast_val  = ( $toast_opt !== '' && $toast_opt !== false ) ? (int) $toast_opt : 5;

    $require_confirm_opt = get_option( 'st_require_confirmation' );
    $require_confirm_val = ( $require_confirm_opt !== '' && $require_confirm_opt !== false ) ? (int) $require_confirm_opt : 1;

    wp_localize_script( 'st-tracker', 'STConfig', [
        'endpoint'         => esc_url_raw( rest_url( 'tracking/v1/log' ) ),
        'behavior_endpoint'=> esc_url_raw( rest_url( 'tracking/v1/behavior' ) ),
        'verify_endpoint'  => esc_url_raw( rest_url( 'tracking/v1/verify-staff' ) ),
        'nonce'            => wp_create_nonce( 'wp_rest' ),
        'threshold'        => $onsite_val,
        'scroll_threshold' => $scroll_val,
        'toast_duration'   => $toast_val,
        'require_confirm'  => $require_confirm_val,
        'page_path'        => esc_url_raw( $_SERVER['REQUEST_URI'] ?? '/' ),
        'home_url'         => esc_url_raw( home_url( '/' ) ),
        'post_type'        => get_post_type(),
        'post_id'          => get_the_ID(),
        // Version nhiệm vụ — tăng mỗi khi admin tạo/sửa/xóa nhiệm vụ
        // Dùng để auto-invalidate sessionStorage cache của tracker
        'assign_ver'       => (string) get_option( 'st_assign_version', '0' ),
        // SlimSelect CDN: chỉ load khi mở modal (lazy load từ JS)
        'slimselect_css'   => 'https://cdn.jsdelivr.net/npm/slim-select@2.9.0/dist/slimselect.min.css',
        'slimselect_js'    => 'https://cdn.jsdelivr.net/npm/slim-select@2.9.0/dist/slimselect.min.js',
        'messages'         => [
            'require_google'       => get_option( 'st_msg_require_google', 'Bạn phải truy cập bài viết thông qua tìm kiếm Google!' ),
            'scroll_not_enough'    => get_option( 'st_msg_scroll_not_enough', 'Bạn chưa cuộn đủ {scroll}% chiều dài bài viết!' ),
            'invalid_staff'        => get_option( 'st_msg_invalid_staff', 'Thông tin nhân sự không tồn tại hoặc không khớp!' ),
            'already_completed'    => get_option( 'st_msg_already_completed', 'Bạn đã hoàn thành nhiệm vụ cho đường dẫn này trong ngày hôm nay rồi!' ),
            'not_in_mission'       => get_option( 'st_msg_not_in_mission', 'Sản phẩm này không nằm trong nhiệm vụ phân công của bạn!' ),
            'rate_limit'           => get_option( 'st_msg_rate_limit', 'Bạn đang gửi quá nhanh (Rate Limit)! Vui lòng thử lại sau.' ),
            'success'              => get_option( 'st_msg_success', '🎉 Ghi nhận KPI traffic thành công cho: {name}' ),
            'not_enough_conditions'=> get_option( 'st_msg_not_enough_conditions', 'Chưa đủ điều kiện: Cần onsite >= {onsite}s và cuộn >= {scroll}%' ),
            'connection_failed'    => get_option( 'st_msg_connection_failed', 'Kết nối thất bại, không thể ghi nhận KPI traffic!' ),
            'scroll_down_prompt'   => get_option( 'st_msg_scroll_down_prompt', 'Hãy cuộn xuống<br/><span class="st-scroll-highlight">để hoàn thành</span>' ),
            'stay_longer'          => get_option( 'st_msg_stay_longer', 'Bạn đã cuộn đủ! Hãy ở lại thêm {remaining} giây nữa để hoàn thành.' ),
            'recording_traffic'    => get_option( 'st_msg_recording_traffic', 'Đang ghi nhận traffic...' ),
        ],
        // Thêm mảng show_messages để điều khiển việc bật/tắt hiển thị
        'show_messages'    => [
            'require_google'       => (int) get_option( 'st_show_msg_require_google', 1 ) === 1,
            'scroll_not_enough'    => (int) get_option( 'st_show_msg_scroll_not_enough', 1 ) === 1,
            'invalid_staff'        => (int) get_option( 'st_show_msg_invalid_staff', 1 ) === 1,
            'already_completed'    => (int) get_option( 'st_show_msg_already_completed', 1 ) === 1,
            'not_in_mission'       => (int) get_option( 'st_show_msg_not_in_mission', 1 ) === 1,
            'rate_limit'           => (int) get_option( 'st_show_msg_rate_limit', 1 ) === 1,
            'success'              => (int) get_option( 'st_show_msg_success', 1 ) === 1,
            'not_enough_conditions'=> (int) get_option( 'st_show_msg_not_enough_conditions', 1 ) === 1,
            'connection_failed'    => (int) get_option( 'st_show_msg_connection_failed', 1 ) === 1,
            'scroll_down_prompt'   => (int) get_option( 'st_show_msg_scroll_down_prompt', 1 ) === 1,
            'stay_longer'          => (int) get_option( 'st_show_msg_stay_longer', 1 ) === 1,
            'recording_traffic'    => (int) get_option( 'st_show_msg_recording_traffic', 1 ) === 1,
        ]
    ] );
}

// ─── Referrer Policy for Safari Mobile ────────────────────────────────────────
add_action( 'wp_head', 'st_referrer_policy' );
function st_referrer_policy(): void {
    echo '<meta name="referrer" content="no-referrer-when-downgrade">' . "\n";
}
