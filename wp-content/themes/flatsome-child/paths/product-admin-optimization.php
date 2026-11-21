<?php
if (!defined('ABSPATH')) {
    exit;
}

// 1. Giảm tần suất Heartbeat trên trang sửa sản phẩm
add_filter('heartbeat_settings', 'hpt_optimize_heartbeat_for_products');
function hpt_optimize_heartbeat_for_products($settings) {
    global $pagenow;
    if (is_admin() && ($pagenow === 'post.php' || $pagenow === 'post-new.php')) {
        $post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : '';
        if (empty($post_type) && isset($_GET['post'])) {
            $post_type = get_post_type(absint($_GET['post']));
        }
        
        if ($post_type === 'product') {
            $settings['interval'] = 60; // Tăng lên 60 giây
        }
    }
    return $settings;
}

// 2. Tăng giới hạn nạp Variations để tránh nghẽn khi có nhiều biến thể
add_filter('woocommerce_ajax_variation_threshold', 'hpt_increase_variation_threshold', 10, 2);
function hpt_increase_variation_threshold($threshold, $product) {
    return 150; // Tăng từ 30 lên 150
}

// 3. Defer term counting khi lưu sản phẩm để tối ưu SQL
add_action('woocommerce_process_product_meta', 'hpt_defer_term_counting_start', 1);
add_action('woocommerce_process_product_meta', 'hpt_defer_term_counting_end', 999);
function hpt_defer_term_counting_start($post_id) {
    wp_defer_term_counting(true);
}
function hpt_defer_term_counting_end($post_id) {
    wp_defer_term_counting(false);
}

// 4. Chặn LiteSpeed Cache purge khi autosave/draft hoặc đang import hàng loạt
add_filter('litespeed_purge_post', 'hpt_disable_litespeed_purge_on_autosave', 10, 2);
function hpt_disable_litespeed_purge_on_autosave($purge_status, $post_id = 0) {
    $post_id = absint($post_id);

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return false;
    }

    // Trong lúc import sản phẩm hàng loạt, không purge từng sản phẩm (sẽ purge 1 lần cuối job).
    if (hpt_is_bulk_product_import()) {
        return false;
    }

    if ($post_id > 0) {
        $post_status = get_post_status($post_id);

        if (in_array($post_status, ['draft', 'auto-draft'], true)) {
            return false;
        }
    }

    return $purge_status;
}

// Một số version/hook có thể chỉ truyền 1 tham số cho filter này.
// Giữ callback mềm để tránh fatal khi chạy REST/import nội bộ.
add_filter('rank_math/instant_indexing/publish_url', 'hpt_disable_rankmath_indexing_on_draft', 10, 2);
function hpt_disable_rankmath_indexing_on_draft($url, $post = null) {
    if (!$post instanceof WP_Post) {
        if (is_numeric($post)) {
            $post = get_post((int) $post);
        } else {
            return $url;
        }
    }

    if (!$post instanceof WP_Post) {
        return $url;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return false;
    }

    // Không gửi instant indexing cho từng sản phẩm khi import hàng loạt.
    if (hpt_is_bulk_product_import()) {
        return false;
    }

    if (in_array($post->post_status, ['draft', 'auto-draft'])) {
        return false;
    }

    return $url;
}

// 6. Tối ưu ACF: Bỏ qua xử lý nếu dữ liệu không thay đổi (tiết kiệm RAM & CPU)
add_filter('acf/pre_update_value', 'hpt_optimize_acf_update_unchanged', 10, 4);
function hpt_optimize_acf_update_unchanged($check, $value, $post_id, $field) {
    if (!is_admin() || $value === null) {
        return $check;
    }

    // Chỉ áp dụng tối ưu này cho sản phẩm
    if (get_post_type($post_id) !== 'product') {
        return $check;
    }

    // Lấy giá trị thô cũ từ database
    $old_value = get_post_meta($post_id, $field['name'], true);

    // Chuyển về định dạng chuẩn để so sánh an toàn
    $compare_new = is_array($value) ? serialize($value) : (string) $value;
    $compare_old = is_array($old_value) ? serialize($old_value) : (string) $old_value;

    if ($compare_new === $compare_old) {
        // Trả về chính giá trị mới để ACF bỏ qua bước format, update DB và lưu field key reference
        return $value;
    }

    return $check;
}

// 7. Tối ưu ACF Relationship & Post Object: Vô hiệu hoá bộ lọc Taxonomy (tránh load hàng ngàn term gây sập RAM)
add_filter('acf/load_field/type=relationship', 'hpt_optimize_acf_relationship_field_load', 20);
add_filter('acf/load_field/type=post_object', 'hpt_optimize_acf_relationship_field_load', 20);
function hpt_optimize_acf_relationship_field_load($field) {
    if (!is_admin()) {
        return $field;
    }

    // Nếu field có cấu hình filters, ta sẽ gỡ bỏ 'taxonomy'
    if (isset($field['filters']) && is_array($field['filters'])) {
        $key = array_search('taxonomy', $field['filters']);
        if ($key !== false) {
            unset($field['filters'][$key]);
            // Reindex mảng
            $field['filters'] = array_values($field['filters']);
        }
    }

    return $field;
}

// 8. Dọn dẹp các Meta Box mặc định của WordPress không cần thiết trên trang Sản Phẩm
// Các meta box này (đặc biệt là postcustom) thực thi các truy vấn SQL GROUP BY rất nặng trên table wp_postmeta
add_action('add_meta_boxes_product', 'hpt_remove_unused_wp_metaboxes', 999);
function hpt_remove_unused_wp_metaboxes() {
    remove_meta_box('postcustom', 'product', 'normal'); // Tắt Custom Fields mặc định (Đã dùng ACF)
    remove_meta_box('commentstatusdiv', 'product', 'normal'); // Tắt Discussion
    remove_meta_box('commentsdiv', 'product', 'normal'); // Tắt Comments
    remove_meta_box('trackbacksdiv', 'product', 'normal'); // Tắt Trackbacks
    remove_meta_box('authordiv', 'product', 'normal'); // Tắt Author
}

// 9. Tối ưu Frontend: Tắt đếm số lượng (pad_counts) trong Widget Danh mục WooCommerce
// Fix lỗi "ngốn 155MB RAM" và truy vấn SQL siêu chậm do WooCommerce phải đếm số lượng SP ở mọi category con
add_filter('woocommerce_product_categories_widget_args', 'hpt_optimize_wc_category_widget_args');
add_filter('woocommerce_product_categories_widget_dropdown_args', 'hpt_optimize_wc_category_widget_args');
function hpt_optimize_wc_category_widget_args($args) {
    $args['show_count'] = 0;
    $args['pad_counts'] = 0;
    $args['hierarchical'] = 1; // Giữ nguyên phân cấp
    return $args;
}

// Chặn truy vấn pad_term_counts của WordPress nếu nó bị gọi ở nơi khác trên frontend
add_filter('get_terms_args', function($args, $taxonomies) {
    if (!is_admin() && isset($args['pad_counts']) && $args['pad_counts']) {
        $args['pad_counts'] = false;
    }
    return $args;
}, 10, 2);

// 10. Cờ "đang import sản phẩm hàng loạt" + hoãn tác vụ nặng
// Dùng cho cả luồng WooCommerce CSV importer native lẫn engine import nâng cao
// (paths/product-import-export-tools.php). Mục tiêu: gom term counting và chặn
// các tác vụ phụ trợ (LiteSpeed purge, Rank Math indexing) chạy trên từng sản phẩm.

$GLOBALS['hpt_bulk_product_import_depth'] = 0;

function hpt_is_bulk_product_import() {
    return !empty($GLOBALS['hpt_bulk_product_import_depth']);
}

/**
 * Bắt đầu chế độ import hàng loạt. Hỗ trợ lồng nhau (depth) để begin/end an toàn
 * khi vừa chạy engine vừa đi qua hook native.
 */
function hpt_begin_bulk_product_import() {
    $GLOBALS['hpt_bulk_product_import_depth'] = (int) ($GLOBALS['hpt_bulk_product_import_depth'] ?? 0) + 1;

    if ($GLOBALS['hpt_bulk_product_import_depth'] === 1) {
        // Hoãn đếm term: tránh GROUP BY nặng trên wp_term_relationships mỗi sản phẩm.
        wp_defer_term_counting(true);
    }
}

/**
 * Kết thúc chế độ import hàng loạt và flush đếm term 1 lần.
 */
function hpt_end_bulk_product_import() {
    $depth = (int) ($GLOBALS['hpt_bulk_product_import_depth'] ?? 0);

    if ($depth <= 0) {
        return;
    }

    $GLOBALS['hpt_bulk_product_import_depth'] = $depth - 1;

    if ($GLOBALS['hpt_bulk_product_import_depth'] === 0) {
        // Đẩy đếm term gom lại 1 lần cho cả batch.
        wp_defer_term_counting(false);
    }
}

// Tự bật cho luồng WooCommerce CSV importer native: mỗi AJAX batch là 1 request,
// nên kết thúc khi request shutdown để flush đếm term.
add_action('woocommerce_product_import_before_import', function ($parsed_data) {
    hpt_begin_bulk_product_import();
    register_shutdown_function('hpt_end_bulk_product_import');
}, 1);
