<?php
/**
 * Frontend Thương hiệu:
 * - Đổi đường dẫn taxonomy `thuong_hieu` từ `/thuong_hieu/<slug>/` sang `/thuong-hieu/<slug>/`.
 * - Chuyển hướng 301 các đường dẫn cũ `/thuong_hieu/...` sang `/thuong-hieu/...`.
 * - Tạo trang danh sách `/thuong-hieu/` hiển thị toàn bộ thương hiệu (tìm kiếm nhanh + nhóm A-Z).
 * - Tự động chèn mục menu "Thương hiệu" ngay sau "Shop" trên menu chính (desktop & mobile).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Đổi slug rewrite của taxonomy `thuong_hieu` thành `thuong-hieu`.
 */
add_filter('register_taxonomy_args', function ($args, $taxonomy) {
    if ($taxonomy !== 'thuong_hieu') {
        return $args;
    }

    if (!isset($args['rewrite']) || !is_array($args['rewrite'])) {
        $args['rewrite'] = array();
    }

    $args['rewrite']['slug'] = 'thuong-hieu';
    if (!isset($args['rewrite']['with_front'])) {
        $args['rewrite']['with_front'] = false;
    }

    return $args;
}, 20, 2);

/**
 * Đăng ký rewrite rule cho trang danh sách `/thuong-hieu/`.
 */
function hpt_register_brands_directory_rewrite_rule() {
    add_rewrite_tag('%hpt_brands_directory%', '([0-1])');
    add_rewrite_rule(
        '^thuong-hieu/?$',
        'index.php?hpt_brands_directory=1',
        'top'
    );
}
add_action('init', 'hpt_register_brands_directory_rewrite_rule', 20);

add_filter('query_vars', function ($vars) {
    $vars[] = 'hpt_brands_directory';
    return $vars;
});

/**
 * Tự động flush rewrite rules một lần khi phiên bản rewrite thay đổi.
 */
function hpt_maybe_flush_brand_rewrite_rules() {
    $rewrite_version = '2026-11-28-1';

    if (get_option('hpt_brand_rewrite_version') === $rewrite_version) {
        return;
    }

    hpt_register_brands_directory_rewrite_rule();
    flush_rewrite_rules(false);
    update_option('hpt_brand_rewrite_version', $rewrite_version, false);
}
add_action('admin_init', 'hpt_maybe_flush_brand_rewrite_rules');

add_action('after_switch_theme', function () {
    delete_option('hpt_brand_rewrite_version');
});

/**
 * Chuyển hướng 301 từ đường dẫn cũ `/thuong_hieu/...` sang `/thuong-hieu/...`.
 */
add_action('template_redirect', function () {
    if (is_admin() || empty($_SERVER['REQUEST_URI'])) {
        return;
    }

    $request_uri = wp_unslash((string) $_SERVER['REQUEST_URI']);
    $parts       = wp_parse_url($request_uri);
    $path        = isset($parts['path']) ? $parts['path'] : '';

    if ($path === '' || !preg_match('#^/thuong_hieu(/.*)?$#i', $path, $matches)) {
        return;
    }

    $suffix     = isset($matches[1]) ? $matches[1] : '/';
    $target_url = home_url('/thuong-hieu' . $suffix);

    if (!empty($parts['query'])) {
        $target_url .= '?' . $parts['query'];
    }

    wp_redirect($target_url, 301);
    exit;
}, 1);

/**
 * Kiểm tra có đang ở trang danh sách `/thuong-hieu/` hay không.
 */
function hpt_is_brands_directory_page() {
    return get_query_var('hpt_brands_directory') === '1';
}

/**
 * Thiết lập trạng thái 200 và template cho trang `/thuong-hieu/`.
 */
add_filter('template_include', function ($template) {
    if (!hpt_is_brands_directory_page()) {
        return $template;
    }

    global $wp_query;
    $wp_query->is_404  = false;
    $wp_query->is_page = true;
    status_header(200);

    $directory_template = get_stylesheet_directory() . '/template-parts/brands/directory.php';
    if (file_exists($directory_template)) {
        return $directory_template;
    }

    return $template;
}, 99);

/**
 * Thiết lập tiêu đề và canonical cho trang `/thuong-hieu/`.
 */
add_filter('pre_get_document_title', function ($title) {
    if (hpt_is_brands_directory_page()) {
        return 'Thương hiệu - ' . get_bloginfo('name');
    }
    return $title;
}, 20);

add_filter('rank_math/frontend/title', function ($title) {
    if (hpt_is_brands_directory_page()) {
        return 'Thương hiệu - ' . get_bloginfo('name');
    }
    return $title;
}, 20);

add_filter('rank_math/frontend/canonical', function ($canonical) {
    if (hpt_is_brands_directory_page()) {
        return home_url('/thuong-hieu/');
    }
    return $canonical;
}, 20);

/**
 * Lấy danh sách toàn bộ thương hiệu sắp xếp theo tên (A-Z) và nạp sẵn cache ảnh (tránh N+1 query).
 */
function hpt_get_brands_for_directory() {
    $taxonomy = function_exists('hpt_get_brand_filter_taxonomy')
        ? hpt_get_brand_filter_taxonomy()
        : (taxonomy_exists('thuong_hieu') ? 'thuong_hieu' : '');

    if ($taxonomy === '') {
        return array();
    }

    $terms = get_terms(array(
        'taxonomy'               => $taxonomy,
        'hide_empty'             => false,
        'orderby'                => 'name',
        'order'                  => 'ASC',
        'update_term_meta_cache' => true,
    ));

    if (empty($terms) || is_wp_error($terms)) {
        return array();
    }

    $attachment_ids = array();
    $raw_avatars    = array();
    foreach ($terms as $term) {
        $avatar = get_term_meta($term->term_id, 'avatar', true);
        $raw_avatars[$term->term_id] = $avatar;
        if (is_numeric($avatar) && (int) $avatar > 0) {
            $attachment_ids[] = (int) $avatar;
        }
    }

    if (!empty($attachment_ids)) {
        _prime_post_caches(array_unique($attachment_ids), false, false);
    }

    $brands = array();
    foreach ($terms as $term) {
        $avatar_url = '';
        $avatar     = $raw_avatars[$term->term_id];

        if (is_numeric($avatar) && (int) $avatar > 0) {
            $avatar_url = (string) wp_get_attachment_image_url((int) $avatar, 'medium');
        } elseif (is_array($avatar) && !empty($avatar['url'])) {
            $avatar_url = (string) $avatar['url'];
        } elseif (is_string($avatar) && $avatar !== '') {
            $avatar_url = $avatar;
        } elseif (function_exists('hpt_get_brand_avatar_url')) {
            $avatar_url = hpt_get_brand_avatar_url($term->term_id);
        }

        $term_link = get_term_link($term, $taxonomy);
        if (is_wp_error($term_link)) {
            continue;
        }

        $brands[] = array(
            'id'         => (int) $term->term_id,
            'name'       => $term->name,
            'url'        => $term_link,
            'avatar_url' => $avatar_url,
        );
    }

    usort($brands, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    return $brands;
}

/**
 * Tự động thêm mục menu "Thương hiệu" ngay sau mục "Shop" trên các menu có chứa "Shop".
 */
add_filter('wp_nav_menu_objects', function ($items, $args) {
    if (is_admin() || empty($items) || !is_array($items)) {
        return $items;
    }

    $location = isset($args->theme_location) ? (string) $args->theme_location : '';
    if (in_array($location, array('footer', 'my_account'), true)) {
        return $items;
    }

    $brands_url  = trailingslashit(home_url('/thuong-hieu'));
    $brands_path = '/thuong-hieu';
    $shop_url    = function_exists('wc_get_page_permalink') ? trailingslashit(wc_get_page_permalink('shop')) : '';

    // Không chèn trùng nếu người dùng đã tự thêm trong Giao diện > Menu.
    foreach ($items as $item) {
        if ((int) $item->menu_item_parent !== 0) {
            continue;
        }
        $item_title = mb_strtolower(trim((string) $item->title), 'UTF-8');
        $item_path  = untrailingslashit((string) wp_parse_url((string) $item->url, PHP_URL_PATH));
        if ($item_title === 'thương hiệu' || $item_path === $brands_path) {
            return $items;
        }
    }

    $is_current_brands = hpt_is_brands_directory_page() || is_tax('thuong_hieu');

    $brand_obj                        = new stdClass();
    $brand_obj->ID                    = 990001;
    $brand_obj->db_id                 = 990001;
    $brand_obj->post_type             = 'nav_menu_item';
    $brand_obj->post_status           = 'publish';
    $brand_obj->title                 = 'Thương hiệu';
    $brand_obj->url                   = $brands_url;
    $brand_obj->menu_item_parent      = 0;
    $brand_obj->type                  = 'custom';
    $brand_obj->object                = 'custom';
    $brand_obj->object_id             = 990001;
    $brand_obj->target                = '';
    $brand_obj->attr_title            = 'Thương hiệu';
    $brand_obj->description           = '';
    $brand_obj->xfn                   = '';
    $brand_obj->has_children          = false;
    $brand_obj->current               = $is_current_brands;
    $brand_obj->current_item_ancestor = false;
    $brand_obj->current_item_parent   = false;
    $brand_obj->classes               = array(
        'menu-item',
        'menu-item-type-custom',
        'menu-item-object-custom',
        'hpt-menu-item-brands',
    );
    if ($is_current_brands) {
        $brand_obj->classes[] = 'current-menu-item';
        $brand_obj->classes[] = 'active';
    }

    $brand_item = class_exists('WP_Post') ? new WP_Post($brand_obj) : $brand_obj;

    $new_items = array();
    $inserted  = false;

    foreach ($items as $item) {
        $new_items[] = $item;

        if ($inserted || (int) $item->menu_item_parent !== 0) {
            continue;
        }

        $title_lower = mb_strtolower(trim((string) $item->title), 'UTF-8');
        $item_url    = trailingslashit((string) $item->url);

        if ($title_lower === 'shop' || ($shop_url !== '' && $item_url === $shop_url)) {
            $brand_item->menu_order = isset($item->menu_order) ? ((int) $item->menu_order + 1) : 0;
            $new_items[]            = $brand_item;
            $inserted               = true;
        }
    }

    return $inserted ? $new_items : $items;
}, 10, 2);
