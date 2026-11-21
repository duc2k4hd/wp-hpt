<?php
/**
 * Hải Phòng Tech - Custom Data Product (Fixed & Optimized)
 * Fix: OOM, recursion, false-to-array deprecated, Commands out of sync
 */

/* =============================================
 * WIDGET & SIDEBAR
 * ============================================= */
add_action('widgets_init', function () {
    global $wp_widget_factory;
    if (class_exists('WC_Widget_Product_Categories') && !isset($wp_widget_factory->widgets['WC_Widget_Product_Categories'])) {
        register_widget('WC_Widget_Product_Categories');
    }
}, 99);

add_action('widgets_init', function () {
    register_sidebar([
        'name'          => __('Shop Sidebar', 'flatsome'),
        'id'            => 'shop-sidebar',
        'description'   => __('Widgets in this area will be shown on shop/category pages.', 'flatsome'),
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ]);
}, 5);

/* =============================================
 * REDIRECT /checkout → giỏ hàng
 * ============================================= */
add_action('template_redirect', function () {
    if (function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url('order-received')) {
        wc_add_notice(__('⚠️ Thanh toán đã bị tắt. Vui lòng xem giỏ hàng của bạn.', 'woocommerce'), 'notice');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }
});

/* =============================================
 * TỰ ĐỘNG ĐIỀN THÔNG TIN ẢNH KHI UPLOAD
 * ============================================= */
add_action('add_attachment', function ($attachment_id) {
    if ((!is_admin() && !wp_doing_ajax()) || !current_user_can('upload_files')) {
        return;
    }

    $mime = get_post_mime_type($attachment_id);
    if (!$mime || strpos($mime, 'image/') !== 0) return;

    $file = get_attached_file($attachment_id);
    if (!$file) return;

    $attachment = get_post($attachment_id);
    if (!$attachment || $attachment->post_type !== 'attachment') {
        return;
    }

    $name_no_ext = pathinfo(basename($file), PATHINFO_FILENAME);
    $target_title = "$name_no_ext | Hải Phòng Tech";
    $target_content = "$name_no_ext | Hải Phòng Tech";
    $target_excerpt = "$name_no_ext | Hải Phòng Tech";
    $alt_text = "$name_no_ext | Hải Phòng Tech";
    $post_update = [
        'ID' => $attachment_id,
    ];
    $needs_post_update = false;

    if ((string) $attachment->post_title !== $target_title) {
        $post_update['post_title'] = $target_title;
        $needs_post_update = true;
    }

    if ((string) $attachment->post_content !== $target_content) {
        $post_update['post_content'] = $target_content;
        $needs_post_update = true;
    }

    if ((string) $attachment->post_excerpt !== $target_excerpt) {
        $post_update['post_excerpt'] = $target_excerpt;
        $needs_post_update = true;
    }

    if ($needs_post_update) {
        wp_update_post($post_update);
    }

    if (get_post_meta($attachment_id, '_wp_attachment_image_alt', true) !== $alt_text) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
    }
});

/* =============================================
 * XÓA CACHE CHI TIẾT SẢN PHẨM KHI LƯU
 * ============================================= */
function hpt_get_product_detail_transient_keys($product_id) {
    $product_id = absint($product_id);

    if ($product_id <= 0) {
        return array();
    }

    $keys = array(
        'contact_form_' . $product_id,
        'parsed_content_' . $product_id,
        'schema_cache_' . $product_id,
        'schema_table_' . $product_id,
        'checked_empty_desc_' . $product_id,
        'need_update_desc_' . $product_id,
    );

    foreach (array('dimension', 'diagram') as $field) {
        $keys[] = "acf_field_{$field}_{$product_id}";
    }

    return $keys;
}

function hpt_clear_product_detail_cache($product_id) {
    static $cleared = array();

    $product_id = absint($product_id);

    if ($product_id <= 0 || isset($cleared[$product_id])) {
        return;
    }

    $cleared[$product_id] = true;

    foreach (hpt_get_product_detail_transient_keys($product_id) as $transient_key) {
        delete_transient($transient_key);
    }

    clean_post_cache($product_id);

    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($product_id);
    }
}

function hpt_maybe_clear_product_detail_cache($post_id) {
    $post_id = absint($post_id);

    if (
        $post_id <= 0 ||
        wp_is_post_revision($post_id) ||
        wp_is_post_autosave($post_id) ||
        get_post_type($post_id) !== 'product'
    ) {
        return;
    }

    hpt_clear_product_detail_cache($post_id);
}

add_action('save_post_product', 'hpt_maybe_clear_product_detail_cache', 20);
add_action('woocommerce_update_product', 'hpt_clear_product_detail_cache', 20);
add_action('woocommerce_new_product', 'hpt_clear_product_detail_cache', 20);

add_action('acf/save_post', function ($post_id) {
    if (!is_numeric($post_id)) {
        return;
    }

    hpt_maybe_clear_product_detail_cache((int) $post_id);
}, 20);

add_action('set_object_terms', function ($object_id, $terms, $tt_ids, $taxonomy) {
    if (!in_array($taxonomy, array('product_cat', 'product_tag', 'thuong_hieu'), true)) {
        return;
    }

    hpt_maybe_clear_product_detail_cache($object_id);
}, 20, 4);

/* =============================================
 * SHORTCODE KÍCH THƯỚC & SƠ ĐỒ
 * ============================================= */
function display_acf_shortcode($field, $label) {
    if (is_admin()) return;
    $post_id = get_the_ID();
    if (!$post_id) return '';

    $cache_key = "acf_field_{$field}_{$post_id}";
    $data = get_transient($cache_key);

    if ($data === false) {
        if (!function_exists('get_field')) return '';
        $data = get_field($field, $post_id);
        if ($data === null || $data === false) {
            $data = '';
        }
        set_transient($cache_key, $data, DAY_IN_SECONDS);
    }

    if (empty($data)) return '';

    $sku   = get_post_meta($post_id, '_sku', true);
    $lines = explode("\n", trim($data));

    $has_label = stripos($data, $label);
    $has_img   = strpos($data, '<img');

    if ($has_label !== false && ($has_img === false || $has_label < $has_img)) {
        foreach ($lines as $i => $line) {
            if (stripos($line, $label) !== false) {
                $lines[$i] = preg_replace('/<(p|div|span|h\d)>(.*?)<\/\1>/', '<h2>$2</h2>', $line, 1);
                return "<div class=\"$field\">" . implode("\n", $lines) . '</div>';
            }
        }
    } else {
        foreach ($lines as $line) {
            if (stripos($line, '<img') !== false || stripos($line, 'alt=') !== false) {
                return "<div class=\"$field\"><h2>$label " . esc_html($sku) . "</h2>$data</div>";
            }
        }
    }

    return "<div class=\"$field\"><h2>$label " . esc_html($sku) . "</h2>$data</div>";
}

add_shortcode('dimension', function () { return display_acf_shortcode('dimension', 'Kích thước'); });
add_shortcode('diagram',   function () { return display_acf_shortcode('diagram', 'Sơ đồ đấu nối'); });

/* =============================================
 * SHORTCODE LIÊN HỆ
 * ============================================= */
add_shortcode('contact_form', function () {
    if (is_admin()) return;
    $post_id = get_the_ID();
    if (!$post_id) return '';

    $cache_key = 'contact_form_' . $post_id;
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $categories = get_the_terms($post_id, 'product_cat');
    $brands = ['autonics', 'boxco', 'cognex', 'hanyoung', 'honeywell', 'jeono', 'jst', 'kacon', 'kem', 'keyence', 'kg auto', 'koino', 'ls', 'mitsubishi', 'molex', 'myungbo', 'omron', 'panasonic', 'proface', 'qlight', 'rootech', 's-jin ace tube', 'safect', 'samwon', 'schnieder', 'segibiz', 'sick', 'sunjin tech', 'weintek', 'woonyoung', 'youngshin', 'abb'];

    if (!empty($categories) && !is_wp_error($categories)) {
        foreach ($categories as $category) {
            if (in_array(strtolower($category->name), $brands, true)) {
                $output = '[dimension][diagram]<h2>Liên hệ</h2><p class="contact-info">Công ty Hải Phòng Tech chuyên cung cấp các sản phẩm  ' . esc_html($category->name) . '. Quý khách vui lòng liên hệ <a style="color: #00aae7;" href="http://haiphongtech.vn">Hải Phòng Tech</a> để nhận được báo giá ưu đãi nhất.</p><hr>';
                set_transient($cache_key, $output, 12 * HOUR_IN_SECONDS);
                return $output;
            }
        }
    }

    $default = '[dimension][diagram]<h2>Liên hệ</h2><p class="contact-info">Vui lòng liên hệ <a style="color: #00aae7;" href="http://haiphongtech.vn">Hải Phòng Tech</a> để biết thêm thông tin về sản phẩm.</p>';
    set_transient($cache_key, $default, 12 * HOUR_IN_SECONDS);
    return $default;
});

function hpt_extract_schema_properties_from_table_html($content, $limit = 20) {
    if (empty($content) || false === stripos($content, '<table')) {
        return [];
    }

    if (!preg_match('/<table\b[^>]*>(.*?)<\/table>/is', $content, $table_match)) {
        return [];
    }

    if (!preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $table_match[1], $row_matches)) {
        return [];
    }

    $properties = [];

    foreach ($row_matches[1] as $row_html) {
        if (!preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/is', $row_html, $col_matches) || count($col_matches[1]) < 2) {
            continue;
        }

        $name  = trim(wp_strip_all_tags(html_entity_decode($col_matches[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $value = trim(wp_strip_all_tags(html_entity_decode($col_matches[1][1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if ($name === '' || $value === '') {
            continue;
        }

        $properties[] = [
            '@type' => 'PropertyValue',
            'name'  => $name,
            'value' => $value,
        ];

        if (count($properties) >= $limit) {
            break;
        }
    }

    return $properties;
}

/* =============================================
 * THÊM FORM LIÊN HỆ VÀO CUỐI MÔ TẢ
 * FIX: recursion guard + DOMDocument safety
 * ============================================= */
add_filter('the_content', function ($content) {
    if (is_admin()) return;
    // ✅ Guard chống recursion — nguyên nhân chính gây OOM
    static $is_running = false;
    if ($is_running) return $content;

    if (!function_exists('is_product') || !is_product()) return $content;

    $post_id = get_the_ID();
    if (!$post_id) return $content;

    $cache_key = "parsed_content_{$post_id}";
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $is_running = true;
    $contact_html = do_shortcode('[contact_form]');

    if (empty($contact_html)) {
        $is_running = false;
        return $content;
    }

    // Nếu nội dung đang cập nhật
    if (stripos($content, 'Đang cập nhật thông số...') !== false) {
        $output = $content . $contact_html;
        set_transient($cache_key, $output, DAY_IN_SECONDS);
        $is_running = false;
        return $output;
    }

    if (empty(trim($content))) {
        $result = $contact_html;
        set_transient($cache_key, $result, DAY_IN_SECONDS);
        $is_running = false;
        return $result;
    }

    $new_content = trim($content);

    if (stripos($new_content, 'liên hệ') !== false) {
        $new_content = preg_replace(
            '/<(h[2-6])[^>]*>.*?liên\s*hệ.*?<\/\1>.*$/isu',
            '',
            $new_content
        );

        if (!is_string($new_content) || $new_content === '') {
            $new_content = '';
        } else {
            $new_content = trim($new_content);
        }
    }

    $result = $new_content . $contact_html;
    set_transient($cache_key, $result, DAY_IN_SECONDS);

    $is_running = false;
    return $result;
});

/* =============================================
 * EXPORT/IMPORT ACF CỘT CSV
 * ============================================= */
function custom_acf_csv_columns($columns) {
    if (!is_array($columns)) $columns = [];
    return array_merge($columns, [
        'dimension' => 'Kích thước sản phẩm',
        'diagram'   => 'Sơ đồ đấu nối điện',
    ]);
}
add_filter('woocommerce_product_export_column_names', 'custom_acf_csv_columns');
add_filter('woocommerce_product_export_product_default_columns', 'custom_acf_csv_columns');
add_filter('woocommerce_csv_product_import_mapping_options', 'custom_acf_csv_columns');

add_filter('woocommerce_csv_product_import_mapping_default_columns', function ($columns) {
    if (!is_array($columns)) $columns = [];
    return array_merge($columns, [
        'Kích thước sản phẩm' => 'dimension',
        'Sơ đồ đấu nối điện' => 'diagram',
    ]);
});

function custom_export_acf_fields($value, $product, $column) {
    $acf_fields = ['dimension', 'diagram'];
    if (in_array($column, $acf_fields, true) && function_exists('get_field')) {
        return get_field($column, $product->get_id()) ?: '';
    }
    return $value;
}
add_filter('woocommerce_product_export_product_column_dimension', 'custom_export_acf_fields', 10, 3);
add_filter('woocommerce_product_export_product_column_diagram',   'custom_export_acf_fields', 10, 3);

add_filter('woocommerce_product_import_pre_insert_product_object', function ($object, $data) {
    foreach (['dimension', 'diagram'] as $field) {
        if (empty($data[$field])) continue;

        if (filter_var($data[$field], FILTER_VALIDATE_URL)) {
            $img_url = esc_url_raw(trim($data[$field]));
            $img_id  = attachment_url_to_postid($img_url);

            if ($img_id) {
                $img_alt     = get_post_meta($img_id, '_wp_attachment_image_alt', true);
                $img_meta    = wp_get_attachment_metadata($img_id);
                $img_caption = wp_get_attachment_caption($img_id);
                $width       = (is_array($img_meta) && isset($img_meta['width']))  ? $img_meta['width']  : 800;
                $height      = (is_array($img_meta) && isset($img_meta['height'])) ? $img_meta['height'] : '';

                $srcset = wp_get_attachment_image_srcset($img_id, 'full');
                $sizes  = wp_get_attachment_image_sizes($img_id, 'full');

                $figure_html  = '<figure id="attachment_' . $img_id . '" class="wp-caption aligncenter" style="width: ' . esc_attr($width) . 'px;" aria-describedby="caption-attachment-' . $img_id . '">';
                $figure_html .= '<img loading="lazy" decoding="async" class="wp-image-' . $img_id . ' size-full" src="' . esc_url($img_url) . '" alt="' . esc_attr($img_alt) . '" width="' . esc_attr($width) . '" height="' . esc_attr($height) . '"';
                if ($srcset) $figure_html .= ' srcset="' . esc_attr($srcset) . '"';
                if ($sizes)  $figure_html .= ' sizes="' . esc_attr($sizes) . '"';
                $figure_html .= ' />';
                $figure_html .= '<figcaption id="caption-attachment-' . $img_id . '" class="wp-caption-text">' . esc_html($img_caption ?: $img_alt) . '</figcaption>';
                $figure_html .= '</figure>';

                $object->update_meta_data($field, $figure_html);
            } else {
                $object->update_meta_data($field, '<img src="' . esc_url($img_url) . '" alt="" width="800" />');
            }
        } else {
            $object->update_meta_data($field, $data[$field]);
        }
    }
    return $object;
}, 10, 2);

/* =============================================
 * SEO: noindex archive pages with pagination/query strings
 * ============================================= */
if (!function_exists('haiphongtech_archive_needs_noindex_follow')) {
    function haiphongtech_archive_needs_noindex_follow() {
        if (is_admin()) {
            return false;
        }

        /* add-to-cart keeps its existing noindex, nofollow behavior. */
        if (isset($_GET['add-to-cart'])) {
            return false;
        }

        /* These low-value endpoints must remain crawlable so noindex is seen. */
        if (is_search() || is_author() || is_tag() || is_feed() || is_trackback() || (is_tax('product_tag'))) {
            return true;
        }

        $paged = max((int) get_query_var('paged'), (int) get_query_var('page'));
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $has_pagination_path = preg_match('#/page/\d+(?:/|$)#i', $request_uri) === 1;
        $query_string = isset($_SERVER['QUERY_STRING']) ? trim((string) $_SERVER['QUERY_STRING']) : '';

        if (function_exists('is_shop') && is_shop()) {
            return $paged > 1 || $has_pagination_path || $query_string !== '';
        }

        if (!(is_category() || is_tax())) {
            return false;
        }

        return $paged > 1 || $has_pagination_path || $query_string !== '';
    }
}

add_filter('rank_math/frontend/robots', function ($robots) {
    if (is_admin()) {
        return $robots;
    }

    if (!is_array($robots)) {
        $robots = [];
    }

    if (haiphongtech_archive_needs_noindex_follow()) {
        $robots['index'] = 'noindex';
        $robots['follow'] = 'follow';
    }

    /* Preserve the existing add-to-cart exception. */
    if (isset($_GET['add-to-cart'])) {
        $robots['index'] = 'noindex';
    }

    return $robots;
}, 20);

add_action('wp_head', function () {
    if (haiphongtech_archive_needs_noindex_follow()) {
        echo '<meta name="robots" content="noindex, follow" />' . "\n";
    }
}, 1);

add_action('template_redirect', function () {
    /* Keep add-to-cart URLs nofollow as before. */
    if (isset($_GET['add-to-cart'])) {
        header('X-Robots-Tag: noindex, nofollow', true);
        return;
    }

    if (haiphongtech_archive_needs_noindex_follow()) {
        header('X-Robots-Tag: noindex, follow', true);
    }
}, 20);

/* =============================================
 * PRODUCT SCHEMA OWNERSHIP
 * The child theme emits one complete product graph below. Suppress only
 * duplicate product schema; keep Rank Math/WooCommerce output on archives,
 * pages and other post types.
 * ============================================= */
function hpt_is_custom_schema_request() {
    if (is_admin()) {
        return false;
    }

    $is_product         = function_exists('is_product') && is_product();
    $is_product_archive = function_exists('is_product_category') && is_product_category();

    return $is_product || $is_product_archive || is_singular('post');
}

add_filter('rank_math/json_ld', function ($data) {
    return hpt_is_custom_schema_request() ? array() : $data;
}, 999);

add_filter('rank_math/frontend/outputs', function ($outputs) {
    return hpt_is_custom_schema_request() ? false : $outputs;
}, 999);

function hpt_disable_woocommerce_product_schema_on_single($data) {
    return (function_exists('is_product') && is_product()) ? array() : $data;
}

add_filter('woocommerce_structured_data_product', 'hpt_disable_woocommerce_product_schema_on_single', 999);
add_filter('woocommerce_structured_data_breadcrumblist', 'hpt_disable_woocommerce_product_schema_on_single', 999);
add_filter('woocommerce_structured_data_organization', 'hpt_disable_woocommerce_product_schema_on_single', 999);
add_filter('woocommerce_structured_data_webpage', 'hpt_disable_woocommerce_product_schema_on_single', 999);
add_filter('woocommerce_structured_data_website', 'hpt_disable_woocommerce_product_schema_on_single', 999);

/* =============================================
 * CUSTOM PRODUCT SCHEMA
 * FIX: DOMDocument safety, null checks
 * ============================================= */
add_action('wp_head', function () {
    if (is_admin()) return;
    if (!function_exists('is_product') || !is_product()) return;

    global $post;
    if (!$post) return;

    $product_id = $post->ID;
    $cache_key  = "schema_cache_{$product_id}";

    $cached = get_transient($cache_key);
    if ($cached !== false) {
        echo $cached;
        return;
    }

    if (!function_exists('wc_get_product')) return;
    $product = wc_get_product($product_id);
    if (!$product) return;

    ob_start();

    // Thương hiệu. Never emit a made-up brand when the product has no term.
    $brand_taxonomy = function_exists('hpt_get_brand_filter_taxonomy')
        ? hpt_get_brand_filter_taxonomy()
        : (taxonomy_exists('thuong_hieu') ? 'thuong_hieu' : (taxonomy_exists('product_brand') ? 'product_brand' : ''));
    $terms = $brand_taxonomy ? get_the_terms($product->get_id(), $brand_taxonomy) : false;
    $brand_name = '';
    if (!empty($terms) && !is_wp_error($terms) && is_array($terms)) {
        $brand_name = $terms[0]->name;
    }

    $site_url          = get_site_url();
    $org_name          = 'Hải Phòng Tech';
    $product_image_url = wp_get_attachment_image_url($product->get_image_id(), 'full') ?: '';
    $product_name      = $product->get_name();
    $product_sku       = $product->get_sku();
    $product_price_raw = $product->get_price();
    $product_price     = is_numeric($product_price_raw) && (float) $product_price_raw > 0
        ? (float) $product_price_raw
        : null;
    $product_url       = get_permalink($product_id);

    $offers_json = '';
    if (null !== $product_price) {
        $offers_json = wp_json_encode(array(
            '@type'           => 'Offer',
            'priceCurrency'   => get_woocommerce_currency(),
            'price'           => (string) $product_price,
            'priceValidUntil' => gmdate('Y-m-d', current_time('timestamp') + YEAR_IN_SECONDS),
            'availability'    => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'itemCondition'   => 'https://schema.org/NewCondition',
            'url'             => $product_url,
            'seller'          => array('@id' => untrailingslashit($site_url) . '#onlinestore'),
            'shippingDetails' => array(
                '@type'              => 'OfferShippingDetails',
                'shippingRate'       => array('@type' => 'MonetaryAmount', 'value' => '0', 'currency' => get_woocommerce_currency()),
                'shippingDestination' => array('@type' => 'DefinedRegion', 'addressCountry' => 'VN'),
                'deliveryTime'       => array(
                    '@type'        => 'ShippingDeliveryTime',
                    'handlingTime' => array('@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'd'),
                    'transitTime'  => array('@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 3, 'unitCode' => 'd'),
                ),
            ),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $brand_json = $brand_name !== ''
        ? wp_json_encode(array('@type' => 'Brand', 'name' => $brand_name), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '';

    // Description fallback
    $rank_math_description = get_post_meta($product_id, 'rank_math_description', true);
    $product_description = wp_strip_all_tags(
        $rank_math_description ?: ($post->post_excerpt ?: $post->post_content)
    );

    // Bóc thông số kỹ thuật từ bảng HTML
    $additional_properties = [];
    $content = $post->post_content;

    if (!empty($content) && strpos($content, '<table') !== false) {
        $table_cache_key = "schema_table_{$product_id}";
        $additional_properties = get_transient($table_cache_key);

        if ($additional_properties === false) {
            $additional_properties = hpt_extract_schema_properties_from_table_html($content, 20);
            set_transient($table_cache_key, $additional_properties, WEEK_IN_SECONDS);
        }

        // ✅ FIX: get_transient có thể trả false nếu expired ngay lúc đọc
        if (!is_array($additional_properties)) {
            $additional_properties = [];
        }
    }

    if (empty($additional_properties)) {
        $additional_properties = [
            ["@type" => "PropertyValue", "name" => "Mã sản phẩm", "value" => $product_sku ?: 'Đang cập nhật'],
            ["@type" => "PropertyValue", "name" => "Tên sản phẩm", "value" => wp_strip_all_tags($product_name ?: get_the_title())],
        ];
    }

    $additional_json = json_encode($additional_properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Kiểm tra description trống (1 lần/ngày, chỉ frontend)
    $check_cache_key = "checked_empty_desc_{$product_id}";
    if (get_transient($check_cache_key) === false) {
        $desc = trim($product->get_description());
        if (empty($desc)) {
            set_transient("need_update_desc_{$product_id}", true, DAY_IN_SECONDS);
        }
        set_transient($check_cache_key, true, DAY_IN_SECONDS);
    }

    // Breadcrumb
    $category_ids = $product->get_category_ids();
    $main_cat_id  = !empty($category_ids) ? $category_ids[0] : null;
    $breadcrumb_terms = [];

    if ($main_cat_id) {
        $term = get_term($main_cat_id, 'product_cat');
        while ($term && !is_wp_error($term) && $term->parent != 0) {
            $breadcrumb_terms[] = $term;
            $term = get_term($term->parent, 'product_cat');
        }
        if ($term && !is_wp_error($term)) {
            $breadcrumb_terms[] = $term;
        }
        $breadcrumb_terms = array_reverse($breadcrumb_terms);
    }

    $product_name_meta = get_post_meta($product_id, 'rank_math_title', true);
    if (empty($product_name_meta)) {
        $product_name_meta = $product_name ?: get_the_title($product_id);
    }

    $breadcrumb_items = [];
    $breadcrumb_items[] = [
        "@type" => "ListItem",
        "position" => 1,
        "item" => ["@id" => $site_url, "name" => "Trang chủ"],
    ];
    $position = 2;
    foreach ($breadcrumb_terms as $bt) {
        $term_link = get_term_link($bt);
        if (is_wp_error($term_link)) continue;
        $breadcrumb_items[] = [
            "@type" => "ListItem",
            "position" => $position++,
            "item" => ["@id" => $term_link, "name" => esc_html($bt->name)],
        ];
    }
    $breadcrumb_items[] = [
        "@type" => "ListItem",
        "position" => $position,
        "item" => ["@id" => $product_url, "name" => esc_js(trim(wp_strip_all_tags($product_name)))],
    ];
    $breadcrumb_json = json_encode($breadcrumb_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Organization",
      "@id": "<?php echo esc_url($site_url); ?>#organization",
      "name": "<?php echo esc_js($org_name); ?>",
      "url": "<?php echo esc_url($site_url); ?>",
      "logo": "https://haiphongtech.vn/wp-content/uploads/2025/07/banner-09.png",
      "email": "kinhdoanhhpt@haiphongtech.vn",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "Số 250 Đường Đại Thắng, Phường Dương Kinh, Thành phố Hải Phòng, Việt Nam",
        "addressLocality": "Lê Chân",
        "addressRegion": "Hải Phòng",
        "postalCode": "180000",
        "addressCountry": "VN"
      },
      "contactPoint": [
        {
          "@type": "ContactPoint",
          "telephone": "0866 555 212",
          "contactType": "customer service"
        }
      ]
    },
    {
      "@type": "OnlineStore",
      "@id": "https://haiphongtech.vn#onlinestore",
      "name": "Hải Phòng Tech",
      "url": "https://haiphongtech.vn",
      "hasMerchantReturnPolicy": {
        "@type": "MerchantReturnPolicy",
        "applicableCountry": "VN",
        "returnPolicyCategory": "https://schema.org/MerchantReturnFiniteReturnWindow",
        "merchantReturnDays": 7,
        "returnMethod": "https://schema.org/ReturnByMail",
        "returnFees": "https://schema.org/FreeReturn",
        "refundType": "https://schema.org/FullRefund"
      }
    },
    {
      "@type": "WebSite",
      "@id": "https://haiphongtech.vn#website",
      "url": "https://haiphongtech.vn/",
      "name": "Hải Phòng Tech",
      "publisher": { "@id": "https://haiphongtech.vn#organization" },
      "inLanguage": "vi-VN"
    },
    {
      "@type": "WebPage",
      "@id": "<?php echo esc_url($product_url); ?>#webpage",
      "url": "<?php echo esc_url($product_url); ?>",
      "name": "<?php echo esc_js($product_name_meta); ?>",
      "description": "<?php echo esc_js(wp_trim_words($product_description, 30)); ?>",
      "datePublished": "<?php echo get_the_date('c', $post); ?>",
      "dateModified": "<?php echo get_the_modified_date('c', $post); ?>",
      "inLanguage": "vi-VN",
      "isPartOf": { "@id": "<?php echo esc_url($site_url); ?>/#website" },
      "primaryImageOfPage": { "@id": "<?php echo esc_url($product_url); ?>#primaryimage" },
      "breadcrumb": { "@id": "<?php echo esc_url($product_url); ?>#breadcrumb" }
    },
    {
      "@type": "BreadcrumbList",
      "@id": "<?php echo esc_url($product_url); ?>#breadcrumb",
      "itemListElement": <?php echo $breadcrumb_json; ?>
    },
    {
      "@type": "ImageObject",
      "@id": "<?php echo esc_url($product_url); ?>#primaryimage",
      "url": "<?php echo esc_url($product_image_url); ?>",
      "width": 600,
      "height": 600,
      "caption": "<?php echo esc_js($product_name); ?>"
    },
    {
      "@type": "Product",
      "@id": "<?php echo esc_url($product_url); ?>#product",
      "name": "<?php echo esc_js(trim(wp_strip_all_tags($product_name))); ?>",
      "description": "<?php echo esc_js(wp_trim_words($product_description, 50)); ?>",
      "sku": "<?php echo esc_js(preg_replace('/\s+/', '-', $product_sku)); ?>",
      "mpn": "<?php echo esc_js(preg_replace('/\s+/', '-', $product_sku)); ?>",
      "additionalProperty": <?php echo $additional_json; ?>,
      <?php if ($brand_json !== ''): ?>
      "brand": <?php echo $brand_json; ?>,
      <?php endif; ?>
      "image": ["<?php echo esc_url($product_image_url); ?>"],
      <?php if ($offers_json !== ''): ?>
      "offers": <?php echo $offers_json; ?>,
      <?php endif; ?>
      "mainEntityOfPage": { "@id": "<?php echo esc_url($product_url); ?>#webpage" }
    }
  ]
}
</script>
<?php
    $output = ob_get_clean();
    set_transient($cache_key, $output, WEEK_IN_SECONDS);
    echo $output;
}, 5);

/* =============================================
 * CATEGORY SCHEMA
 * FIX: kiểm tra is_array, tối ưu memory
 * ============================================= */
add_action('wp_head', function () {
    if (is_admin()) return;
    if (!function_exists('is_product_category') || !is_product_category()) return;

    $category     = get_queried_object();
    if (!$category || !isset($category->term_id)) return;

    $category_url = get_term_link($category);
    if (is_wp_error($category_url)) return;

    $product_ids = [];
    global $wp_query;

    if ($wp_query instanceof WP_Query && !empty($wp_query->posts) && is_array($wp_query->posts)) {
        foreach ($wp_query->posts as $post_item) {
            if ($post_item instanceof WP_Post && 'product' === $post_item->post_type) {
                $product_ids[] = (int) $post_item->ID;
            }
        }
    }

    if (empty($product_ids)) {
        $cache_key   = 'cat_schema_fallback_' . $category->term_id . '_' . max(1, (int) get_query_var('paged'));
        $product_ids = get_transient($cache_key);

        if ($product_ids === false || !is_array($product_ids)) {
            $fallback_limit = 24;

            if (function_exists('wc_get_default_products_per_row') && function_exists('wc_get_default_product_rows_per_page')) {
                $fallback_limit = max(1, wc_get_default_products_per_row() * wc_get_default_product_rows_per_page());
            }

            $product_ids = wc_get_products([
                'limit'    => $fallback_limit,
                'orderby'  => 'menu_order',
                'order'    => 'ASC',
                'return'   => 'ids',
                'tax_query' => [
                    [
                        'taxonomy'         => 'product_cat',
                        'field'            => 'term_id',
                        'terms'            => [(int) $category->term_id],
                        'include_children' => false,
                    ],
                ],
            ]);

            if (!is_array($product_ids)) {
                $product_ids = [];
            }

            set_transient($cache_key, $product_ids, DAY_IN_SECONDS);
        }
    }

    // ItemList — chỉ dùng permalink, KHÔNG load wc_get_product() để tiết kiệm memory
    $items    = [];
    $position = 1;
    foreach ($product_ids as $pid) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $position++,
            'url'      => get_permalink($pid),
        ];
    }

    $category_name = $category->name;
    $category_desc = !empty($category->description)
        ? wp_strip_all_tags($category->description)
        : 'Khám phá danh mục ' . $category_name . ' chính hãng, giá tốt, sẵn kho tại Hải Phòng Tech.';

    $schemas = [];

    // ItemList
    $schemas[] = [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        '@id'             => $category_url . '#itemlist',
        'name'            => $category_name,
        'url'             => $category_url,
        'numberOfItems'   => count($items),
        'itemListElement' => $items,
    ];

    // WebPage
    $schemas[] = [
        '@context'    => 'https://schema.org',
        '@type'       => 'WebPage',
        '@id'         => $category_url . '#webpage',
        'name'        => 'Danh mục ' . $category_name,
        'url'         => $category_url,
        'description' => $category_desc,
        'isPartOf'    => ['@id' => get_site_url() . '/#website'],
    ];

    // Breadcrumb
    $breadcrumb_items = [];
    $pos = 1;
    $breadcrumb_items[] = [
        '@type' => 'ListItem', 'position' => $pos++,
        'name'  => 'Trang chủ', 'item' => get_site_url(),
    ];

    if ($category->parent) {
        $parent = get_term($category->parent, 'product_cat');
        if ($parent && !is_wp_error($parent)) {
            $parent_link = get_term_link($parent);
            if (!is_wp_error($parent_link)) {
                $breadcrumb_items[] = [
                    '@type' => 'ListItem', 'position' => $pos++,
                    'name'  => $parent->name, 'item' => $parent_link,
                ];
            }
        }
    }

    $breadcrumb_items[] = [
        '@type' => 'ListItem', 'position' => $pos++,
        'name'  => $category_name, 'item' => $category_url,
    ];

    $schemas[] = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        '@id'             => $category_url . '#breadcrumb',
        'itemListElement' => $breadcrumb_items,
    ];

    // FAQ
    $schemas[] = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => [
            [
                '@type'          => 'Question',
                'name'           => 'Làm sao để chọn đúng cảm biến phù hợp ứng dụng?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => 'Bạn nên xác định môi trường sử dụng, vật thể cần phát hiện, và khoảng cách để chọn đúng loại cảm biến.',
                ],
            ],
            [
                '@type'          => 'Question',
                'name'           => 'Thời gian bảo hành sản phẩm là bao lâu?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => 'Tất cả sản phẩm tại Hải Phòng Tech đều được bảo hành chính hãng từ 6 đến 12 tháng.',
                ],
            ],
            [
                '@type'          => 'Question',
                'name'           => 'Hải Phòng Tech có hỗ trợ giao hàng toàn quốc không?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => 'Có, chúng tôi giao hàng toàn quốc qua nhiều đơn vị vận chuyển uy tín.',
                ],
            ],
        ],
    ];

    echo '<script type="application/ld+json">'
        . wp_json_encode($schemas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . '</script>';
});

/* =============================================
 * SHORTCODE HIỂN THỊ SẢN PHẨM TRONG BÀI VIẾT
 * ============================================= */
function hptech_products_shortcode($atts) {
    if (!is_single() || get_post_type() !== 'post') return '';

    $atts = shortcode_atts([
        'category' => '',
        'limit'    => 12,
    ], $atts, 'hptech_products');

    $category = sanitize_text_field($atts['category']);
    $limit    = intval($atts['limit']);

    if (empty($category)) {
        return '<p>⚠ Vui lòng nhập slug hoặc ID danh mục!</p>';
    }

    $field_type = is_numeric($category) ? 'id' : 'slug';

    $query = new WP_Query([
        'post_type'      => 'product',
        'posts_per_page' => $limit,
        'no_found_rows'  => true,
        'cache_results'  => true,
        'tax_query'      => [
            [
                'taxonomy' => 'product_cat',
                'field'    => $field_type,
                'terms'    => $category,
            ],
        ],
    ]);

    ob_start();
    if ($query->have_posts()) {
        echo '<div class="hptech-products">';
        while ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());
            if (!$product) continue;
            ?>
            <div class="hptech-product-item">
                <a href="<?php echo esc_url(get_the_permalink()); ?>">
                    <?php echo $product->get_image('woocommerce_thumbnail'); ?>
                    <h4><?php echo esc_html(get_the_title()); ?></h4>
                </a>
                <p class="price"><?php echo $product->get_price_html(); ?></p>
            </div>
            <?php
        }
        echo '</div>';
    }
    wp_reset_postdata();
    return ob_get_clean();
}
add_shortcode('hptech_products', 'hptech_products_shortcode');

/* =============================================
 * DEQUEUE CF7 NGOÀI TRANG LIÊN HỆ
 * ============================================= */
add_action('wp_enqueue_scripts', function () {
    if (function_exists('wpcf7_enqueue_scripts') && !is_page('lien-he')) {
        wp_dequeue_script('contact-form-7');
        wp_dequeue_style('contact-form-7');
    }
}, 99);

/* =============================================
 * SCROLL TO TOP BUTTON
 * ============================================= */
add_action('wp_footer', function () {
    ?>
    <div id="btn-to-top" style="position:fixed;bottom:90px;right:15px;width:60px;height:60px;
                background:linear-gradient(135deg,#00c6ff,#0084ff);border-radius:50%;
                display:flex;justify-content:center;align-items:center;
                box-shadow:0 6px 15px rgba(0,0,0,0.25);cursor:pointer;z-index:9999;
                transition:opacity 0.3s ease;opacity:0;visibility:hidden;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white" width="28px" height="28px">
            <path d="M4 12l1.41 1.41L11 7.83V20h2V7.83l5.59 5.58L20 12l-8-8-8 8z"/>
        </svg>
    </div>
    <script>
    (function(){
        var b=document.getElementById("btn-to-top");
        window.addEventListener("scroll",function(){
            b.style.opacity=window.scrollY>200?"1":"0";
            b.style.visibility=window.scrollY>200?"visible":"hidden";
        });
        b.addEventListener("click",function(){window.scrollTo({top:0,behavior:"smooth"});});
    })();
    </script>
    <?php
});

/* =============================================
 * ARTICLE (BLOG POST) SCHEMA
 * ============================================= */
function haiphongtech_article_schema() {
    if (!is_singular('post')) return;

    global $post;
    if (!$post) return;

    $post_id       = $post->ID;
    $post_url      = get_permalink($post_id);
    $post_title    = get_the_title($post_id);
    $post_desc     = get_the_excerpt($post_id);
    $post_date     = get_the_date('c', $post_id);
    $post_modified = get_the_modified_date('c', $post_id);
    $image_url     = get_the_post_thumbnail_url($post_id, 'full');
    if (!$image_url) {
        $image_url = "https://haiphongtech.vn/wp-content/uploads/2025/07/banner-09.png";
    }
    $author_name = get_the_author_meta('display_name', $post->post_author);

    // Breadcrumb
    $breadcrumbs = [];
    $position    = 1;
    $breadcrumbs[] = [
        "@type" => "ListItem", "position" => $position++,
        "name"  => "Trang chủ", "item" => "https://haiphongtech.vn/",
    ];

    $categories = get_the_category($post_id);
    if (!empty($categories) && !is_wp_error($categories)) {
        $category = $categories[0];
        $parents  = get_ancestors($category->term_id, 'category');
        $parents  = array_reverse($parents);

        foreach ($parents as $parent_id) {
            $parent = get_category($parent_id);
            if ($parent && !is_wp_error($parent)) {
                $breadcrumbs[] = [
                    "@type" => "ListItem", "position" => $position++,
                    "name"  => $parent->name, "item" => get_category_link($parent->term_id),
                ];
            }
        }

        $breadcrumbs[] = [
            "@type" => "ListItem", "position" => $position++,
            "name"  => $category->name, "item" => get_category_link($category->term_id),
        ];
    }

    $breadcrumbs[] = [
        "@type" => "ListItem", "position" => $position++,
        "name"  => $post_title, "item" => $post_url,
    ];

    $schema = [
        "@context" => "https://schema.org",
        "@graph"   => [
            ["@type" => "BreadcrumbList", "itemListElement" => $breadcrumbs],
            [
                "@type" => "Organization",
                "@id"   => "https://haiphongtech.vn/#organization",
                "name"  => "Hải Phòng Tech",
                "url"   => "https://haiphongtech.vn/",
                "logo"  => "https://haiphongtech.vn/wp-content/uploads/2025/07/banner-09.png",
                "email" => "kinhdoanhhpt@haiphongtech.vn",
                "address" => [
                    "@type" => "PostalAddress",
                    "streetAddress"   => "Số 250 Đường Đại Thắng, Phường Dương Kinh, Thành phố Hải Phòng, Việt Nam",
                    "addressLocality" => "Lê Chân",
                    "addressRegion"   => "Hải Phòng",
                    "postalCode"      => "180000",
                    "addressCountry"  => ["@type" => "Country", "name" => "VN"],
                ],
                "contactPoint" => [
                    "@type" => "ContactPoint", "telephone" => "0866 555 212", "contactType" => "customer service",
                ],
            ],
            [
                "@type" => "WebSite", "@id" => "https://haiphongtech.vn/#website",
                "url"   => "https://haiphongtech.vn/", "name" => "Hải Phòng Tech",
                "inLanguage" => "vi-VN",
                "publisher"  => ["@id" => "https://haiphongtech.vn/#organization"],
            ],
            [
                "@type" => "WebPage", "@id" => $post_url . "#webpage",
                "url" => $post_url, "name" => $post_title, "description" => $post_desc,
                "datePublished" => $post_date, "dateModified" => $post_modified,
                "inLanguage" => "vi-VN",
                "isPartOf"  => ["@id" => "https://haiphongtech.vn/#website"],
                "publisher" => ["@id" => "https://haiphongtech.vn/#organization"],
                "primaryImageOfPage" => [
                    "@type" => "ImageObject", "@id" => $post_url . "#primaryimage",
                    "url" => $image_url, "width" => 600, "height" => 600, "caption" => $post_title,
                ],
            ],
            [
                "@type" => "BlogPosting", "@id" => $post_url . "#blogposting",
                "headline" => $post_title, "description" => $post_desc,
                "image" => ["@type" => "ImageObject", "url" => $image_url, "width" => 600, "height" => 600],
                "author"    => ["@type" => "Person", "name" => $author_name],
                "publisher" => ["@id" => "https://haiphongtech.vn/#organization"],
                "mainEntityOfPage" => ["@id" => $post_url . "#webpage"],
                "datePublished" => $post_date, "dateModified" => $post_modified, "inLanguage" => "vi-VN",
            ],
        ],
    ];

    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
}
add_action('wp_head', 'haiphongtech_article_schema');

/* =============================================
 * XOÁ rel prev/next trên archive
 * ============================================= */
add_action('wp_head', function () {
    if (is_category() || is_tag() || is_tax() || is_post_type_archive()) {
        ob_start(function ($output) {
            $output = preg_replace('/<link[^>]+rel=["\']prev["\'][^>]*>/i', '', $output);
            $output = preg_replace('/<link[^>]+rel=["\']next["\'][^>]*>/i', '', $output);
            return $output;
        });
    }
}, 0);

/* =============================================
 * FIX CANONICAL cho phân trang taxonomy
 * ============================================= */
add_filter('rank_math/frontend/canonical', function ($canonical) {
    if (is_category() || is_tag() || is_tax() || is_post_type_archive()) {
        if (get_query_var('paged') > 1) {
            $canonical = get_pagenum_link(1);
        }
    }
    return trailingslashit($canonical);
});

/* =============================================
 * TẮT MỘT SỐ ADMIN NOTICE WOO
 * ============================================= */
add_filter('woocommerce_enable_admin_help_tab', '__return_false');
add_filter('woocommerce_show_admin_notice', '__return_false');

/* =============================================
 * FOOTER: CONTACT FAB + TOC + PRODUCT STYLES
 * FIX: kiểm tra ACF trả false/empty trước khi dùng
 * ============================================= */
function haiphongtech_footer_contact_and_toc() {
    // Hiển thị contact FAB trên mọi trang; dữ liệu ACF vẫn có fallback an toàn.
    $infoContact = function_exists('get_field') ? get_field("desktop", "option") : null;

    $contacts = [];
    if (is_array($infoContact) && count($infoContact) >= 3) {
        $middleContacts = array_slice($infoContact, 1, -1);
        if (is_array($middleContacts)) {
            $contacts = array_map(function ($item) {
                return [
                    'name'  => isset($item['name'])  ? $item['name']  : '',
                    'phone' => isset($item['phone']) ? $item['phone'] : '0866555212',
                ];
            }, $middleContacts);
        }
    }
    ?>
        <div class="contact-fab">
            <div class="contact-actions" id="contactActions">
                <?php foreach ($contacts as $contact) { ?>
                    <a href="https://zalo.me/<?php echo str_replace('.', '', $contact['phone'] ?: '0866555212'); ?>" target="_blank" class="fab-item zalo" title="Zalo">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="48px" height="48px">
                            <path fill="#2962ff" d="M15,36V6.827l-1.211-0.811C8.64,8.083,5,13.112,5,19v10c0,7.732,6.268,14,14,14h10c4.722,0,8.883-2.348,11.417-5.931V36H15z"/>
                            <path fill="#eee" d="M29,5H19c-1.845,0-3.601,0.366-5.214,1.014C10.453,9.25,8,14.528,8,19c0,6.771,0.936,10.735,3.712,14.607c0.216,0.301,0.357,0.653,0.376,1.022c0.043,0.835-0.129,2.365-1.634,3.742c-0.162,0.148-0.059,0.419,0.16,0.428c0.942,0.041,2.843-0.014,4.797-0.877c0.557-0.246,1.191-0.203,1.729,0.083C20.453,39.764,24.333,40,28,40c4.676,0,9.339-1.04,12.417-2.916C42.038,34.799,43,32.014,43,29V19C43,11.268,36.732,5,29,5z"/>
                            <path fill="#2962ff" d="M36.75,27C34.683,27,33,25.317,33,23.25s1.683-3.75,3.75-3.75s3.75,1.683,3.75,3.75S38.817,27,36.75,27z M36.75,21c-1.24,0-2.25,1.01-2.25,2.25s1.01,2.25,2.25,2.25S39,24.49,39,23.25S37.99,21,36.75,21z"/>
                            <path fill="#2962ff" d="M31.5,27h-1c-0.276,0-0.5-0.224-0.5-0.5V18h1.5V27z"/>
                            <path fill="#2962ff" d="M27,19.75v0.519c-0.629-0.476-1.403-0.769-2.25-0.769c-2.067,0-3.75,1.683-3.75,3.75S22.683,27,24.75,27c0.847,0,1.621-0.293,2.25-0.769V26.5c0,0.276,0.224,0.5,0.5,0.5h1v-7.25H27z M24.75,25.5c-1.24,0-2.25-1.01-2.25-2.25S23.51,21,24.75,21S27,22.01,27,23.25S25.99,25.5,24.75,25.5z"/>
                            <path fill="#2962ff" d="M21.25,18h-8v1.5h5.321L13,26h0.026c-0.163,0.211-0.276,0.463-0.276,0.75V27h7.5c0.276,0,0.5-0.224,0.5-0.5v-1h-5.321L21,19h-0.026c0.163-0.211,0.276-0.463,0.276-0.75V18z"/>
                        </svg>
                        <small class="contact-text">Zalo <?php echo esc_html($contact['name'] ?: 'Hải Phòng Tech'); ?></small>
                    </a>
                <?php } ?>
                <a href="https://zalo.me/0866555212" target="_blank" class="fab-item zalo" title="Zalo Hải Phòng Tech">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="48px" height="48px">
                        <path fill="#2962ff" d="M15,36V6.827l-1.211-0.811C8.64,8.083,5,13.112,5,19v10c0,7.732,6.268,14,14,14h10c4.722,0,8.883-2.348,11.417-5.931V36H15z"/>
                        <path fill="#eee" d="M29,5H19c-1.845,0-3.601,0.366-5.214,1.014C10.453,9.25,8,14.528,8,19c0,6.771,0.936,10.735,3.712,14.607c0.216,0.301,0.357,0.653,0.376,1.022c0.043,0.835-0.129,2.365-1.634,3.742c-0.162,0.148-0.059,0.419,0.16,0.428c0.942,0.041,2.843-0.014,4.797-0.877c0.557-0.246,1.191-0.203,1.729,0.083C20.453,39.764,24.333,40,28,40c4.676,0,9.339-1.04,12.417-2.916C42.038,34.799,43,32.014,43,29V19C43,11.268,36.732,5,29,5z"/>
                        <path fill="#2962ff" d="M36.75,27C34.683,27,33,25.317,33,23.25s1.683-3.75,3.75-3.75s3.75,1.683,3.75,3.75S38.817,27,36.75,27z M36.75,21c-1.24,0-2.25,1.01-2.25,2.25s1.01,2.25,2.25,2.25S39,24.49,39,23.25S37.99,21,36.75,21z"/>
                        <path fill="#2962ff" d="M31.5,27h-1c-0.276,0-0.5-0.224-0.5-0.5V18h1.5V27z"/>
                        <path fill="#2962ff" d="M27,19.75v0.519c-0.629-0.476-1.403-0.769-2.25-0.769c-2.067,0-3.75,1.683-3.75,3.75S22.683,27,24.75,27c0.847,0,1.621-0.293,2.25-0.769V26.5c0,0.276,0.224,0.5,0.5,0.5h1v-7.25H27z M24.75,25.5c-1.24,0-2.25-1.01-2.25-2.25S23.51,21,24.75,21S27,22.01,27,23.25S25.99,25.5,24.75,25.5z"/>
                        <path fill="#2962ff" d="M21.25,18h-8v1.5h5.321L13,26h0.026c-0.163,0.211-0.276,0.463-0.276,0.75V27h7.5c0.276,0,0.5-0.224,0.5-0.5v-1h-5.321L21,19h-0.026c0.163-0.211,0.276-0.463,0.276-0.75V18z"/>
                    </svg>
                    <small class="contact-text">Zalo Hải Phòng Tech</small>
                </a>
            </div>
            <button class="fab-toggle" id="fabToggle">
                <svg id="svg_zalo_icon" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" viewBox="0 0 614.501 613.667" xml:space="preserve">
                    <path fill="#FFFFFF" d="M464.721,301.399c-13.984-0.014-23.707,11.478-23.944,28.312c-0.251,17.771,9.168,29.208,24.037,29.202   c14.287-0.007,23.799-11.095,24.01-27.995C489.028,313.536,479.127,301.399,464.721,301.399z" />
                    <path fill="#FFFFFF" d="M291.83,301.392c-14.473-0.316-24.578,11.603-24.604,29.024c-0.02,16.959,9.294,28.259,23.496,28.502   c15.072,0.251,24.592-10.87,24.539-28.707C315.214,313.318,305.769,301.696,291.83,301.392z" />
                    <path fill="#FFFFFF" d="M310.518,3.158C143.102,3.158,7.375,138.884,7.375,306.3s135.727,303.142,303.143,303.142   c167.415,0,303.143-135.727,303.143-303.142S477.933,3.158,310.518,3.158z M217.858,391.083   c-33.364,0.818-66.828,1.353-100.133-0.343c-21.326-1.095-27.652-18.647-14.248-36.583c21.55-28.826,43.886-57.065,65.792-85.621   c2.546-3.305,6.214-5.996,7.15-12.705c-16.609,0-32.784,0.04-48.958-0.013c-19.195-0.066-28.278-5.805-28.14-17.652   c0.132-11.768,9.175-17.329,28.397-17.348c25.159-0.026,50.324-0.06,75.476,0.026c9.637,0.033,19.604,0.105,25.304,9.789   c6.22,10.561,0.284,19.512-5.646,27.454c-21.26,28.497-43.015,56.624-64.559,84.902c-2.599,3.41-5.119,6.88-9.453,12.725   c23.424,0,44.123-0.053,64.816,0.026c8.674,0.026,16.662,1.873,19.941,11.267C237.892,379.329,231.368,390.752,217.858,391.083z    M350.854,330.211c0,13.417-0.093,26.841,0.039,40.265c0.073,7.599-2.599,13.647-9.512,17.084   c-7.296,3.642-14.71,3.028-20.304-2.968c-3.997-4.281-6.214-3.213-10.488-0.422c-17.955,11.728-39.908,9.96-56.597-3.866   c-29.928-24.789-30.026-74.803-0.211-99.776c16.194-13.562,39.592-15.462,56.709-4.143c3.951,2.619,6.201,4.815,10.396-0.053   c5.39-6.267,13.055-6.761,20.271-3.357c7.454,3.509,9.935,10.165,9.776,18.265C350.67,304.222,350.86,317.217,350.854,330.211z    M395.617,369.579c-0.118,12.837-6.398,19.783-17.196,19.908c-10.779,0.132-17.593-6.966-17.646-19.512   c-0.179-43.352-0.185-86.696,0.007-130.041c0.059-12.256,7.302-19.921,17.896-19.222c11.425,0.752,16.992,7.448,16.992,18.833   c0,22.104,0,44.216,0,66.327C395.677,327.105,395.828,348.345,395.617,369.579z M463.981,391.868   c-34.399-0.336-59.037-26.444-58.786-62.289c0.251-35.66,25.304-60.713,60.383-60.396c34.631,0.304,59.374,26.306,58.998,61.986   C524.207,366.492,498.534,392.205,463.981,391.868z" />
                 </svg>
            </button>
        </div>

        <style>
            .contact-fab{position:fixed;bottom:160px;right:15px;z-index:9999;display:flex;flex-direction:column;align-items:center}
            .contact-fab svg{width:40px!important;height:40px!important}
            .fab-toggle img{width:40px;height:40px}
            .fab-toggle{width:60px;height:60px;margin:auto;border-radius:50%;background:linear-gradient(135deg,rgb(0,198,255),rgb(0,132,255));border:none;color:#fff;cursor:pointer;box-shadow:0 6px 15px rgba(0,0,0,0.25);display:flex;align-items:center;justify-content:center;transition:all .3s ease;padding:0!important}
            .fab-toggle:hover{transform:scale(1.1);background:linear-gradient(135deg,rgb(0,196,255),rgb(0,135,255))}
            .contact-actions{display:flex;flex-direction:column;align-items:center;gap:15px;margin-bottom:12px;transform:scale(0);opacity:0;pointer-events:none;transition:all .4s cubic-bezier(.19,1,.22,1)}
            .contact-actions.show{transform:scale(1);opacity:1;pointer-events:all}
            .fab-item{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:26px;text-decoration:none;box-shadow:0 6px 15px rgba(0,0,0,0.25);transform:translateY(20px);opacity:0;transition:transform .4s ease,opacity .4s ease}
            .contact-actions.show .fab-item{opacity:1;transform:translateY(0)}
            <?php foreach ($contacts as $index => $contact) { ?>
            .contact-actions.show .fab-item:nth-child(<?php echo $index + 1; ?>){transition-delay:<?php echo number_format(0.05 * ($index + 1), 2); ?>s}
            <?php } ?>
            .fab-item.zalo{background:linear-gradient(135deg,#00b4d8,#0077b6)}
            .fab-item.zalo img{width:28px;filter:brightness(0) invert(1)}
            .fab-item:hover{transform:translateY(-4px) scale(1.07);box-shadow:0 8px 18px rgba(0,0,0,0.3)}
            .contact-text{position:absolute;top:-5px;right:75px;padding:8px 18px;font-size:16px;font-weight:600;color:#fff;background:linear-gradient(135deg,#6dd5fa,#2980b9);border-radius:9999px;box-shadow:0 6px 15px rgba(0,0,0,0.25),0 0 12px rgba(41,128,185,0.6);white-space:nowrap;letter-spacing:.5px;text-shadow:0 2px 8px rgba(0,0,0,0.3);backdrop-filter:blur(6px);animation:float 3s ease-in-out infinite;cursor:pointer;transition:all .3s ease}
            .contact-text::after{content:"";position:absolute;right:-13px;top:28px;bottom:-10px;width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-top:8px solid #0077b6;transform:rotate(300deg);filter:drop-shadow(0 2px 2px rgba(0,0,0,0.3))}
            .contact-text:hover{background:linear-gradient(135deg,#74ebd5,#ACB6E5);box-shadow:0 8px 20px rgba(0,0,0,0.35),0 0 18px rgba(255,255,255,0.6);transform:scale(1.05)}
            @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
        </style>

        <script>
        document.getElementById('fabToggle').addEventListener('click',function(){
            var a=document.getElementById('contactActions'),t=document.getElementById('fabToggle');
            a.classList.toggle('show');t.classList.toggle('active');
            t.innerHTML=t.classList.contains('active')
                ?'<svg id="svg_zalo_icon" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" viewBox="0 0 614.501 613.667" xml:space="preserve"><path fill="#FFFFFF" d="M464.721,301.399c-13.984-0.014-23.707,11.478-23.944,28.312c-0.251,17.771,9.168,29.208,24.037,29.202c14.287-0.007,23.799-11.095,24.01-27.995C489.028,313.536,479.127,301.399,464.721,301.399z"/><path fill="#FFFFFF" d="M291.83,301.392c-14.473-0.316-24.578,11.603-24.604,29.024c-0.02,16.959,9.294,28.259,23.496,28.502c15.072,0.251,24.592-10.87,24.539-28.707C315.214,313.318,305.769,301.696,291.83,301.392z"/><path fill="#FFFFFF" d="M310.518,3.158C143.102,3.158,7.375,138.884,7.375,306.3s135.727,303.142,303.143,303.142c167.415,0,303.143-135.727,303.143-303.142S477.933,3.158,310.518,3.158z M217.858,391.083c-33.364,0.818-66.828,1.353-100.133-0.343c-21.326-1.095-27.652-18.647-14.248-36.583c21.55-28.826,43.886-57.065,65.792-85.621c2.546-3.305,6.214-5.996,7.15-12.705c-16.609,0-32.784,0.04-48.958-0.013c-19.195-0.066-28.278-5.805-28.14-17.652c0.132-11.768,9.175-17.329,28.397-17.348c25.159-0.026,50.324-0.06,75.476,0.026c9.637,0.033,19.604,0.105,25.304,9.789c6.22,10.561,0.284,19.512-5.646,27.454c-21.26,28.497-43.015,56.624-64.559,84.902c-2.599,3.41-5.119,6.88-9.453,12.725c23.424,0,44.123-0.053,64.816,0.026c8.674,0.026,16.662,1.873,19.941,11.267C237.892,379.329,231.368,390.752,217.858,391.083z M350.854,330.211c0,13.417-0.093,26.841,0.039,40.265c0.073,7.599-2.599,13.647-9.512,17.084c-7.296,3.642-14.71,3.028-20.304-2.968c-3.997-4.281-6.214-3.213-10.488-0.422c-17.955,11.728-39.908,9.96-56.597-3.866c-29.928-24.789-30.026-74.803-0.211-99.776c16.194-13.562,39.592-15.462,56.709-4.143c3.951,2.619,6.201,4.815,10.396-0.053c5.39-6.267,13.055-6.761,20.271-3.357c7.454,3.509,9.935,10.165,9.776,18.265C350.67,304.222,350.86,317.217,350.854,330.211z M395.617,369.579c-0.118,12.837-6.398,19.783-17.196,19.908c-10.779,0.132-17.593-6.966-17.646-19.512c-0.179-43.352-0.185-86.696,0.007-130.041c0.059-12.256,7.302-19.921,17.896-19.222c11.425,0.752,16.992,7.448,16.992,18.833c0,22.104,0,44.216,0,66.327C395.677,327.105,395.828,348.345,395.617,369.579z M463.981,391.868c-34.399-0.336-59.037-26.444-58.786-62.289c0.251-35.66,25.304-60.713,60.383-60.396c34.631,0.304,59.374,26.306,58.998,61.986C524.207,366.492,498.534,392.205,463.981,391.868z"/></svg>'
                :'<svg id="svg_zalo_icon" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" viewBox="0 0 614.501 613.667" xml:space="preserve"><path fill="#FFFFFF" d="M464.721,301.399c-13.984-0.014-23.707,11.478-23.944,28.312c-0.251,17.771,9.168,29.208,24.037,29.202c14.287-0.007,23.799-11.095,24.01-27.995C489.028,313.536,479.127,301.399,464.721,301.399z"/><path fill="#FFFFFF" d="M291.83,301.392c-14.473-0.316-24.578,11.603-24.604,29.024c-0.02,16.959,9.294,28.259,23.496,28.502c15.072,0.251,24.592-10.87,24.539-28.707C315.214,313.318,305.769,301.696,291.83,301.392z"/><path fill="#FFFFFF" d="M310.518,3.158C143.102,3.158,7.375,138.884,7.375,306.3s135.727,303.142,303.143,303.142c167.415,0,303.143-135.727,303.143-303.142S477.933,3.158,310.518,3.158z M217.858,391.083c-33.364,0.818-66.828,1.353-100.133-0.343c-21.326-1.095-27.652-18.647-14.248-36.583c21.55-28.826,43.886-57.065,65.792-85.621c2.546-3.305,6.214-5.996,7.15-12.705c-16.609,0-32.784,0.04-48.958-0.013c-19.195-0.066-28.278-5.805-28.14-17.652c0.132-11.768,9.175-17.329,28.397-17.348c25.159-0.026,50.324-0.06,75.476,0.026c9.637,0.033,19.604,0.105,25.304,9.789c6.22,10.561,0.284,19.512-5.646,27.454c-21.26,28.497-43.015,56.624-64.559,84.902c-2.599,3.41-5.119,6.88-9.453,12.725c23.424,0,44.123-0.053,64.816,0.026c8.674,0.026,16.662,1.873,19.941,11.267C237.892,379.329,231.368,390.752,217.858,391.083z M350.854,330.211c0,13.417-0.093,26.841,0.039,40.265c0.073,7.599-2.599,13.647-9.512,17.084c-7.296,3.642-14.71,3.028-20.304-2.968c-3.997-4.281-6.214-3.213-10.488-0.422c-17.955,11.728-39.908,9.96-56.597-3.866c-29.928-24.789-30.026-74.803-0.211-99.776c16.194-13.562,39.592-15.462,56.709-4.143c3.951,2.619,6.201,4.815,10.396-0.053c5.39-6.267,13.055-6.761,20.271-3.357c7.454,3.509,9.935,10.165,9.776,18.265C350.67,304.222,350.86,317.217,350.854,330.211z M395.617,369.579c-0.118,12.837-6.398,19.783-17.196,19.908c-10.779,0.132-17.593-6.966-17.646-19.512c-0.179-43.352-0.185-86.696,0.007-130.041c0.059-12.256,7.302-19.921,17.896-19.222c11.425,0.752,16.992,7.448,16.992,18.833c0,22.104,0,44.216,0,66.327C395.677,327.105,395.828,348.345,395.617,369.579z M463.981,391.868c-34.399-0.336-59.037-26.444-58.786-62.289c0.251-35.66,25.304-60.713,60.383-60.396c34.631,0.304,59.374,26.306,58.998,61.986C524.207,366.492,498.534,392.205,463.981,391.868z"/></svg>';
        });
        </script>
    <?php if (is_singular('product') || get_post_type() === 'product') : ?>
        <style>
            .woocommerce-Tabs-panel>img{width:800px!important;height:auto!important;object-fit:cover}
            .woocommerce-Tabs-panel h2,.product-overview h2{background-color:#0284c7!important;color:#fff!important;padding:10px 5px!important;border-radius:5px!important}
            .woocommerce-Tabs-panel h3,.woocommerce-Tabs-panel h4,.woocommerce-Tabs-panel h5,.woocommerce-Tabs-panel h6,.woocommerce-Tabs-panel h3 *,.woocommerce-Tabs-panel h4 *,.woocommerce-Tabs-panel h5 *,.woocommerce-Tabs-panel h6 *{background:#fff!important;color:#000!important}
            .woocommerce-Tabs-panel table:first-of-type tr{display:flex}
            .woocommerce-Tabs-panel table:first-of-type tr th{border:.1px solid rgba(0,0,0,0.1)!important;text-align:center!important;padding:10px 0}
            .woocommerce-Tabs-panel table:first-of-type tr td{font-size:14px!important;position:relative;display:-webkit-box;width:100%;-webkit-line-clamp:6;-webkit-box-orient:vertical;overflow:hidden;cursor:pointer;transition:all .3s ease;padding:10px;color:#000;font-style:italic;height:auto;border-top:0;border-left:.1px solid rgba(0,0,0,0.1);border-right:.1px solid rgba(0,0,0,0.1);border-bottom:0;white-space:normal}
            .woocommerce-Tabs-panel table:first-of-type tr td.clamped::after{content:' [...]';position:absolute;bottom:1px;right:10px;background:#fff;padding:5px 10px;color:blue;font-weight:700;font-style:normal;pointer-events:none}
            .woocommerce-Tabs-panel table:first-of-type tr td.expanded{display:block;-webkit-line-clamp:unset;-webkit-box-orient:unset;overflow:visible;white-space:normal}
            .woocommerce-Tabs-panel table:first-of-type tr td.expanded::after{content:'<'}
            .woocommerce-Tabs-panel table:first-of-type tr .expanded{-webkit-line-clamp:unset!important;overflow:visible!important}
        </style>
    <?php endif; ?>

    <?php if (is_single() && get_post_type() !== 'product') : ?>
        <div id="hpt-toc-container">
          <button id="hpt-toc-toggle">📑 Xem mục lục</button>
          <div id="hpt-toc-overlay"></div>
          <div id="hpt-toc-panel">
            <div class="hpt-toc-header">
              <h4 class="hpt-toc-title">Mục lục bài viết</h4>
              <button id="hpt-toc-close">
                <svg xmlns="http://www.w3.org/2000/svg" height="32" width="32" viewBox="0 0 640 640">
                  <path fill="#ff0000" d="M320 112C434.9 112 528 205.1 528 320C528 434.9 434.9 528 320 528C205.1 528 112 434.9 112 320C112 205.1 205.1 112 320 112zM320 576C461.4 576 576 461.4 576 320C576 178.6 461.4 64 320 64C178.6 64 64 178.6 64 320C64 461.4 178.6 576 320 576zM231 231C221.6 240.4 221.6 255.6 231 264.9L286 319.9L231 374.9C221.6 384.3 221.6 399.5 231 408.8C240.4 418.1 255.6 418.2 264.9 408.8L319.9 353.8L374.9 408.8C384.3 418.2 399.5 418.2 408.8 408.8C418.1 399.4 418.2 384.2 408.8 374.9L353.8 319.9L408.8 264.9C418.2 255.5 418.2 240.3 408.8 231C399.4 221.7 384.2 221.6 374.9 231L319.9 286L264.9 231C255.5 221.6 240.3 221.6 231 231z"/>
                </svg>
              </button>
            </div>
            <div id="hpt-toc-content"></div>
          </div>
        </div>

        <style>
            .hptech-products{display:flex;flex-wrap:wrap;gap:10px}
            .hptech-product-item{flex:0 0 calc(25% - 10px);border:1px solid #eee;padding:10px;text-align:center;box-sizing:border-box;background:#fff;border-radius:6px;transition:all .3s ease}
            .hptech-product-item:hover{box-shadow:0 3px 10px rgba(0,0,0,0.1);transform:translateY(-3px)}
            @media(max-width:768px){.hptech-product-item{flex:0 0 calc(50% - 10px)}.entry-content,.single-page{padding:5px!important}article,.post{margin:20px auto!important}}
            .hptech-product-item img{max-width:100%;max-height:160px;object-fit:contain;margin-bottom:8px}
            .hptech-product-item h4{font-size:14px;line-height:1.4em;height:2.8em;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;margin:5px 0}
            .hptech-product-item .price{color:#e60000;font-weight:700;font-size:15px;margin-top:5px}
            #hpt-toc-container{position:fixed;top:45%;z-index:9999;font-family:system-ui,sans-serif}
            #hpt-toc-toggle{background:linear-gradient(135deg,#00b4d8,#0077b6);color:#fff;border:none;border-radius:0 6px 6px 0;padding:10px 14px;cursor:pointer;font-weight:600;box-shadow:0 4px 10px rgba(0,0,0,0.25);transition:all .3s ease;left:-70px}
            #hpt-toc-toggle:hover{transform:translateX(3px);background:linear-gradient(135deg,#48cae4,#0096c7);left:0;transition:left .5s}
            #hpt-toc-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.01);backdrop-filter:blur(2px);opacity:0;pointer-events:none;transition:opacity .3s ease;z-index:9998}
            #hpt-toc-overlay.hpt-show{opacity:1;pointer-events:all}
            #hpt-toc-panel{position:fixed;top:0;left:-280px;width:260px;height:100%;max-height:100vh;overflow-y:auto;background:#fff;border-radius:0 6px 6px 0;box-shadow:2px 4px 16px rgba(0,0,0,0.15);padding:30px 16px;transition:left .4s ease;z-index:9999}
            #hpt-toc-panel.hpt-show{left:0}
            .hpt-toc-header{display:flex;justify-content:space-between;align-items:center}
            #hpt-toc-close{background:transparent;border:none;font-size:18px;cursor:pointer;color:#333;margin:auto;transition:transform .2s ease;display:flex;justify-content:center;align-items:center}
            #hpt-toc-close:hover{transform:scale(1.2)}
            #hpt-toc-panel .hpt-toc-title{font-weight:700;margin:0;color:#0077b6}
            #hpt-toc-content ul{list-style:none;margin:10px 0 0;padding:0}
            #hpt-toc-content li{margin:4px 0}
            #hpt-toc-content a{color:#333;text-decoration:none;font-size:14px;transition:color .2s}
            #hpt-toc-content a:hover{color:#0077b6}
            .hpt-toc-h1 a{font-weight:700;color:#005f73}
            .hpt-toc-h2 a{margin-left:10px;color:#0a9396}
            .hpt-toc-h3 a{margin-left:20px;color:#94d2bd}
            .hpt-toc-h4 a{margin-left:30px;color:#e9d8a6}
            #hpt-toc-panel::-webkit-scrollbar{width:5px}
            #hpt-toc-panel::-webkit-scrollbar-thumb{background:#bbb;border-radius:10px}
            #hpt-toc-content a.hpt-active{color:#0077b6;font-weight:700;border-left:3px solid #0077b6;padding-left:6px;background:rgba(0,119,182,0.05);border-radius:4px;transition:all .2s ease}
        </style>

        <script>
        document.addEventListener("DOMContentLoaded",function(){
            var toggle=document.getElementById("hpt-toc-toggle"),
                panel=document.getElementById("hpt-toc-panel"),
                overlay=document.getElementById("hpt-toc-overlay"),
                closeBtn=document.getElementById("hpt-toc-close"),
                content=document.getElementById("hpt-toc-content");

            function toSlug(s){return s.normalize("NFD").replace(/[\u0300-\u036f]/g,"").replace(/đ/g,"d").replace(/Đ/g,"d").toLowerCase().trim().replace(/[^a-z0-9\s-]/g,"").replace(/\s+/g,"-")}
            function toggleTOC(show){panel.classList.toggle("hpt-show",show);overlay.classList.toggle("hpt-show",show)}

            if(toggle)toggle.addEventListener("click",function(){toggleTOC(true)});
            if(closeBtn)closeBtn.addEventListener("click",function(){toggleTOC(false)});
            if(overlay)overlay.addEventListener("click",function(){toggleTOC(false)});

            var allH=Array.from(document.querySelectorAll("article h2,article h3,article h4"));
            var filtered=allH.filter(function(h){return!h.closest(".hptech-products")&&!h.closest(".footer-wrapper")});
            if(!filtered.length)return;

            var ul=document.createElement("ul");
            ul.className="hpt-toc-list";

            filtered.forEach(function(heading,index){
                var text=heading.textContent.trim();
                var id=heading.id||toSlug(text)||"hpt-toc-"+index;
                heading.id="hpt-"+id;
                var li=document.createElement("li");
                var a=document.createElement("a");
                a.href="#hpt-"+id;
                a.textContent=text;
                li.className="hpt-toc-"+heading.tagName.toLowerCase();
                li.appendChild(a);
                ul.appendChild(li);
            });

            if(content)content.appendChild(ul);

            document.querySelectorAll("#hpt-toc-content a").forEach(function(a){
                a.addEventListener("click",function(e){
                    e.preventDefault();
                    var target=document.querySelector(this.getAttribute("href"));
                    if(target){window.scrollTo({top:target.offsetTop-80,behavior:"smooth"});toggleTOC(false)}
                });
            });

            var tocLinks=document.querySelectorAll("#hpt-toc-content a");
            function onScroll(){
                var current="";
                filtered.forEach(function(h){if(window.scrollY>=h.getBoundingClientRect().top+window.scrollY-100)current=h.id});
                tocLinks.forEach(function(a){a.classList.toggle("hpt-active",a.getAttribute("href")==="#"+current)});
            }
            window.addEventListener("scroll",onScroll);
            onScroll();
        });
        </script>
    <?php endif; ?>
<?php
}
add_action('wp_footer', 'haiphongtech_footer_contact_and_toc', 99);

/* =============================================
 * REDIRECT: lowercase product URLs
 * ============================================= */
add_action('template_redirect', function () {
    if (!is_singular('product')) return;

    $current_url = home_url(add_query_arg([], $_SERVER['REQUEST_URI']));
    $lower_url   = strtolower($current_url);

    if ($current_url !== $lower_url) {
        wp_redirect($lower_url, 301);
        exit;
    }
});

/* =============================================
 * REDIRECT: /product/.../feed/ + ?add-to-cart
 * ============================================= */
add_action('init', function () {
    if (is_admin()) return;

    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

    // /product/.../feed/
    if (preg_match('#^/product/.+/feed/?$#', $uri)) {
        $redirect = preg_replace('#/feed/?$#', '/', home_url($uri));
        wp_redirect($redirect, 301);
        exit;
    }

    // ?add-to-cart=
    if (strpos($uri, '/product/') === 0 && isset($_GET['add-to-cart'])) {
        $clean = strtok(home_url($uri), '?');
        wp_redirect($clean, 301);
        exit;
    }
}, 1);

/* =============================================
 * ADMIN: Tìm kiếm danh mục sản phẩm nhanh
 * ============================================= */
add_action('admin_footer', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'product') return;
    ?>
    <script>
    jQuery(document).ready(function($){
        if (!$('#product_catdiv').length) {
            return;
        }

        var s='<input type="text" id="product_cat_search" placeholder="🔍 Tìm danh mục..." style="width:100%;margin-bottom:8px;padding:6px;">';
        $('#product_catdiv .inside').prepend(s);
        $('#product_cat_search').on('keyup',function(){
            var k=$(this).val().toLowerCase();
            $('#product_catchecklist li').each(function(){$(this).toggle($(this).text().toLowerCase().indexOf(k)!==-1)});
        });
    });
    </script>
    <?php
});


add_action('wp_footer', function () {
?>
<script>
document.addEventListener('DOMContentLoaded', function () {

  document.body.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('single_add_to_cart_button')) {

      let productName = document.querySelector('.product_title')?.innerText || '';
      let priceText = document.querySelector('.price .amount')?.innerText || '0';

      let price = parseInt(priceText.replace(/[^\d]/g, ''));

      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push({
        event: "add_to_cart",

        // ✅ THÊM 2 DÒNG NÀY
        value: price,
        currency: "VND",

        // giữ lại ecommerce (chuẩn GA4)
        ecommerce: {
          value: price,
          currency: "VND",
          items: [{
            item_name: productName,
            price: price
          }]
        }
      });

    }
  });

});
</script>
<?php
});
