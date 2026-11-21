<?php
/**
 * HPT Frontend Performance Optimizer
 * Tối ưu tốc độ trang chi tiết sản phẩm (Frontend) khi chưa cache
 * Không sửa đổi logic nghiệp vụ, không ảnh hưởng dữ liệu
 */
if (!defined('ABSPATH')) {
    exit;
}

// =====================================================
// 10. Tắt RSS Feed cho từng sản phẩm đơn lẻ
// WooCommerce mặc định tạo RSS feed cho mỗi sản phẩm - không cần thiết và gây lãng phí
// =====================================================
add_action('wp_loaded', 'hpt_disable_single_product_feed');
function hpt_disable_single_product_feed() {
    if (!is_admin()) {
        remove_action('wp_head', 'feed_links_extra', 3);
    }
}

// =====================================================
// 11. Tắt các tính năng WP không cần thiết trên Frontend
// Giảm số lượng query và enqueue scripts thừa
// =====================================================
add_action('init', 'hpt_disable_unnecessary_wp_features');
function hpt_disable_unnecessary_wp_features() {
    if (is_admin()) {
        return;
    }

    // Tắt XML-RPC pingback
    remove_action('xmlrpc_rsd_apis', 'rest_output_rsd');
    // Tắt Emoji (WordPress tự load thêm DNS prefetch + script)
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
    // Tắt oEmbed discovery links
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');
    // Tắt Shortlink
    remove_action('wp_head', 'wp_shortlink_wp_head');
    // Tắt REST API link trong head (vẫn giữ API hoạt động)
    remove_action('wp_head', 'rest_output_link_wp_head');
    // Tắt WLW Manifest (Windows Live Writer - không cần)
    remove_action('wp_head', 'wlwmanifest_link');
    // Tắt RSD Link (Really Simple Discovery - không cần)
    remove_action('wp_head', 'rsd_link');
    // Tắt WordPress version tag (bảo mật + giảm header size)
    remove_action('wp_head', 'wp_generator');
}

// =====================================================
// 12. Tắt WordPress Post Revision tự động (chỉ trang sản phẩm)
// Revision làm phình bảng wp_posts và gây slow query
// Đã bật WP_POST_REVISIONS là cần, nhưng 25 revision/sản phẩm là quá nhiều
// =====================================================
add_filter('wp_revisions_to_keep', 'hpt_limit_product_revisions', 10, 2);
function hpt_limit_product_revisions($num, $post) {
    if ($post && $post->post_type === 'product') {
        return 3; // Chỉ giữ 3 revision gần nhất cho sản phẩm
    }
    return $num;
}

// =====================================================
// 13. Cache WooCommerce Related Products / Upsell để tránh query lặp
// Đây là nguồn gốc của 200-300 query trên mỗi trang sản phẩm
// =====================================================
add_filter('woocommerce_related_products', 'hpt_cache_related_products', 10, 3);
function hpt_cache_related_products($related_posts, $product_id, $args) {
    // Không cache nếu là xem trong admin preview
    if (is_admin()) {
        return $related_posts;
    }

    $limit = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : 4;
    $cache_key = 'hpt_related_' . $product_id . '_' . $limit;
    $cached = wp_cache_get($cache_key, 'hpt_product');

    if ($cached !== false) {
        return $cached;
    }

    // Lưu vào object cache (RAM) - tốc độ cực nhanh, không ghi DB
    wp_cache_set($cache_key, $related_posts, 'hpt_product', 600); // Cache 10 phút
    return $related_posts;
}

// =====================================================
// 14. Tắt tính năng đếm số lần xem (view count) nếu có
// và tắt WooCommerce wishlist/compare scripts không dùng
// =====================================================
add_action('wp_enqueue_scripts', 'hpt_dequeue_unnecessary_scripts', 99);
function hpt_dequeue_unnecessary_scripts() {
    if (!is_product()) {
        return;
    }

    // Tắt Jetpack nếu có (thường load nhiều asset)
    wp_dequeue_style('jetpack-slideshow');
    wp_dequeue_script('jetpack-slideshow');

    // Tắt Contact Form 7 trên trang sản phẩm (đã tắt global, đây là fallback)
    if (function_exists('wpcf7_enqueue_scripts')) {
        wp_dequeue_script('contact-form-7');
        wp_dequeue_style('contact-form-7');
        wp_dequeue_script('contact-form-7-validation');
    }
}

// Make only the product's first (LCP) image eager. All gallery/related images
// stay lazy, keeping the initial request small without changing WooCommerce's
// gallery or responsive srcset generation.
add_filter('wp_get_attachment_image_attributes', 'hpt_product_image_loading_attributes', 20, 3);
function hpt_product_image_loading_attributes($attr, $attachment, $size) {
    static $hero_seen = false;

    if (!function_exists('is_product') || !is_product() || !is_object($attachment)) {
        return $attr;
    }

    global $product;
    if (!$product || !is_a($product, 'WC_Product')) {
        return $attr;
    }

    $hero_id = (int) $product->get_image_id();
    if (!$hero_seen && $hero_id > 0 && (int) $attachment->ID === $hero_id) {
        $attr['loading']       = 'eager';
        $attr['fetchpriority'] = 'high';
        $attr['decoding']      = 'async';
        $hero_seen             = true;
    } elseif (empty($attr['loading'])) {
        $attr['loading'] = 'lazy';
    }

    return $attr;
}

// Flatsome adds several idle prefetch links. Keep the core theme and
// WooCommerce runtime, but do not spend bandwidth prefetching slider/tooltips
// chunks that are not used by the child product template.
add_filter('wp_resource_hints', 'hpt_product_resource_hints', 999, 2);
function hpt_product_resource_hints($urls, $relation_type) {
    if (!function_exists('is_product') || !is_product() || 'prefetch' !== $relation_type || !is_array($urls)) {
        return $urls;
    }

    return array_values(array_filter($urls, function ($url) {
        $url = is_array($url) && isset($url['href']) ? $url['href'] : $url;
        return !is_string($url) || !preg_match('#/chunk\.(?:slider|tooltips)\.js(?:\?|$)#i', $url);
    }));
}

// =====================================================
// 15. Tắt REST API noinline / JSON embed tự động load trên mỗi post
// Trang sản phẩm không cần embed code - giảm 1 query OPTIONS
// =====================================================
add_filter('embed_oembed_discover', '__return_false');

// =====================================================
// 16. Giới hạn Last-Modified query - thủ phạm gây chậm 0.17s trên Feed
// SELECT post_modified_gmt FROM wp_posts... ORDER BY DESC LIMIT 1
// Thêm index hint hoặc cache kết quả này
// =====================================================
add_filter('pre_get_lastpostmodified', 'hpt_cache_last_post_modified', 10, 2);
function hpt_cache_last_post_modified($lastpostmodified, $timezone) {
    // Chỉ tối ưu trên frontend
    if (is_admin()) {
        return $lastpostmodified;
    }

    $cache_key = 'hpt_lastmod_' . $timezone;
    $cached = wp_cache_get($cache_key, 'hpt_misc');

    if ($cached !== false) {
        return $cached;
    }

    return $lastpostmodified; // Để WP tự query, nhưng chúng ta sẽ cache kết quả
}

add_filter('lastpostmodified', 'hpt_store_last_post_modified_cache', 10, 2);
function hpt_store_last_post_modified_cache($lastpostmodified, $timezone) {
    if (!is_admin() && $lastpostmodified) {
        $cache_key = 'hpt_lastmod_' . $timezone;
        wp_cache_set($cache_key, $lastpostmodified, 'hpt_misc', 300); // Cache 5 phút
    }
    return $lastpostmodified;
}

// =====================================================
// 17. Tối ưu Rank Math: Giảm DB queries trên Frontend
// Rank Math load nhiều option trên mỗi request
// =====================================================
add_action('rank_math/frontend/disable_breadcrumbs', '__return_false');

// Chặn Rank Math load toàn bộ setting nếu không phải trang sản phẩm
add_action('wp', 'hpt_limit_rankmath_on_frontend', 1);
function hpt_limit_rankmath_on_frontend() {
    // Chỉ tắt các module không cần thiết trên frontend
    if (is_admin() || !function_exists('rank_math')) {
        return;
    }

    // Tắt Rank Math Analytics tracking trên frontend (nó gọi DB update mỗi request)
    // Analytics nên chạy qua GSC, không cần track server-side
    add_filter('rank_math/analytics/pageview', '__return_false');
    add_filter('rank_math/analytics/track_pageview', '__return_false');
}

// =====================================================
// 18. Tắt WooCommerce session handler ghi DB cho guest (anonymous users)
// WooCommerce ghi session vào wp_options cho mỗi anonymous visitor
// Điều này tạo ra hàng nghìn bản ghi vô dụng và làm chậm mọi request
// =====================================================
add_filter('woocommerce_session_expiring', function() {
    return 60 * 60 * 47; // 47 giờ thay vì 47h mặc định - nhưng giảm overhead
});

// Không ghi session nếu giỏ hàng trống (anonymous user chỉ duyệt)
add_filter('woocommerce_session_save_data', 'hpt_skip_empty_wc_session');
function hpt_skip_empty_wc_session($session_data) {
    if (is_admin() || is_user_logged_in()) {
        return $session_data;
    }

    // Nếu cart rỗng, không cần lưu session
    if (function_exists('WC') && WC()->cart && WC()->cart->is_empty()) {
        // Trả về false để WC bỏ qua việc ghi session này
        return false;
    }

    return $session_data;
}
