<?php
include_once 'paths/product-contact-settings.php';
include('paths/data-product.php');
include_once 'paths/custom-data-product.php';
include_once 'paths/product-admin-profiler.php';
include_once 'paths/product-category-tools.php';
include_once 'paths/product-admin-optimization.php';
include_once 'paths/product-import-export-tools.php';
include_once 'paths/product-frontend-optimization.php';
include_once 'paths/media-upload-optimization.php';
include_once 'paths/brand-admin-tools.php';
include_once 'paths/product-category-admin-tools.php';

function hpt_is_contact_settings_option_name($option_name) {
    $option_name = (string) $option_name;

    if ($option_name === '') {
        return false;
    }

    $prefixes = array(
        'options_desktop',
        '_options_desktop',
        'options_email_lien_he',
        '_options_email_lien_he',
        'options_product_contact_mobile_phone',
        '_options_product_contact_mobile_phone',
        'options_product_contact_mobile_zalo',
        '_options_product_contact_mobile_zalo',
        'options_product_contact_quote_phone',
        '_options_product_contact_quote_phone',
        'options_product_contact_quote_zalo',
        '_options_product_contact_quote_zalo',
        'options_product_contact_technical_phone',
        '_options_product_contact_technical_phone',
        'options_product_contact_technical_zalo',
        '_options_product_contact_technical_zalo',
    );

    foreach ($prefixes as $prefix) {
        if (strpos($option_name, $prefix) === 0) {
            return true;
        }
    }

    return false;
}

function hpt_is_contact_settings_page_request() {
    $page = isset($_REQUEST['page']) ? sanitize_key((string) $_REQUEST['page']) : '';

    if ($page === 'setting-page') {
        return true;
    }

    $referer = isset($_POST['_wp_http_referer']) ? wp_unslash((string) $_POST['_wp_http_referer']) : '';

    return $referer !== '' && strpos($referer, 'page=setting-page') !== false;
}

function hpt_queue_contact_settings_cache_purge($reason = '') {
    $GLOBALS['hpt_contact_settings_cache_purge_needed'] = true;

    if (!isset($GLOBALS['hpt_contact_settings_cache_purge_reasons']) || !is_array($GLOBALS['hpt_contact_settings_cache_purge_reasons'])) {
        $GLOBALS['hpt_contact_settings_cache_purge_reasons'] = array();
    }

    $reason = trim((string) $reason);

    if ($reason !== '') {
        $GLOBALS['hpt_contact_settings_cache_purge_reasons'][] = $reason;
    }
}

function hpt_purge_contact_settings_public_cache() {
    static $purged = false;

    if ($purged || empty($GLOBALS['hpt_contact_settings_cache_purge_needed'])) {
        return;
    }

    $purged = true;

    $reasons = $GLOBALS['hpt_contact_settings_cache_purge_reasons'] ?? array();
    $reason  = !empty($reasons)
        ? 'HPT contact settings updated: ' . implode(' | ', array_unique(array_filter(array_map('strval', $reasons))))
        : 'HPT contact settings updated';

    if (has_action('litespeed_purge_all')) {
        do_action('litespeed_purge_all', $reason);
    }

    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}

add_action('updated_option', function ($option, $old_value, $value) {
    if (!hpt_is_contact_settings_option_name($option)) {
        return;
    }

    hpt_queue_contact_settings_cache_purge('updated_option:' . $option);
}, 20, 3);

add_action('added_option', function ($option, $value) {
    if (!hpt_is_contact_settings_option_name($option)) {
        return;
    }

    hpt_queue_contact_settings_cache_purge('added_option:' . $option);
}, 20, 2);

add_action('deleted_option', function ($option) {
    if (!hpt_is_contact_settings_option_name($option)) {
        return;
    }

    hpt_queue_contact_settings_cache_purge('deleted_option:' . $option);
}, 20, 1);

add_action('acf/save_post', function ($post_id) {
    if (is_numeric($post_id)) {
        return;
    }

    if (!hpt_is_contact_settings_page_request()) {
        return;
    }

    hpt_queue_contact_settings_cache_purge('acf/save_post:setting-page');
}, 30);

add_action('shutdown', 'hpt_purge_contact_settings_public_cache', 1);

add_action('init', function () {
    load_plugin_textdomain('acf', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}, 5);

function add_custom_script() {
    // Tailwind utilities are used by the child-theme templates.
    $tailwind_css = get_stylesheet_directory_uri() . '/assets/css/tailwind.css';
    wp_enqueue_style('tailwind-css', $tailwind_css);

    // The product gallery is Flatsome/Flickity-based; no template uses the
    // bundled Swiper assets. Do not enqueue that unused CSS/JS on every URL.
    $custom_js = get_stylesheet_directory_uri() . '/assets/js/custom.js';
    wp_enqueue_script('custom-js', $custom_js, array('jquery'), '1.0.0', true);
    if (function_exists('wp_script_add_data')) {
        wp_script_add_data('custom-js', 'strategy', 'defer');
    }
}
add_action('wp_enqueue_scripts', 'add_custom_script');

function ww_load_dashicons() {
    wp_enqueue_style('dashicons');
}
add_action('wp_enqueue_scripts', 'ww_load_dashicons');


function woocommerce_template_loop_product_title() {
    echo wp_kses_post('<h3 class="woocommerce-loop-product__title product-title"><a href="' . get_permalink() . '">' . get_the_title() . '</a></h3>');
}


function hpt_get_brand_filter_taxonomy() {
    $taxonomy = apply_filters('hpt_brand_filter_taxonomy', 'thuong_hieu');

    if ($taxonomy && taxonomy_exists($taxonomy)) {
        return $taxonomy;
    }

    if (taxonomy_exists('product_brand')) {
        return 'product_brand';
    }

    return '';
}

function display_brand_filter() {
    $brand_taxonomy = hpt_get_brand_filter_taxonomy();

    if (!$brand_taxonomy) {
        return;
    }

    $brands = get_terms(array(
        'taxonomy'   => $brand_taxonomy,
        'hide_empty' => false,
    ));

    include('paths/filter-item.php');
}
add_shortcode('display_brand', 'display_brand_filter');

function hpt_get_brand_avatar_url($term_id) {
    static $cache = array();

    $term_id = absint($term_id);

    if ($term_id <= 0) {
        return '';
    }

    if (array_key_exists($term_id, $cache)) {
        return $cache[$term_id];
    }

    $avatar_value = get_term_meta($term_id, 'avatar', true);

    if (empty($avatar_value) && function_exists('get_field')) {
        $avatar_value = get_field('avatar', 'term_' . $term_id);
    }

    if (is_array($avatar_value) && !empty($avatar_value['url'])) {
        $cache[$term_id] = (string) $avatar_value['url'];
        return $cache[$term_id];
    }

    if (is_numeric($avatar_value)) {
        $cache[$term_id] = (string) wp_get_attachment_image_url((int) $avatar_value, 'full');
        return $cache[$term_id];
    }

    $cache[$term_id] = is_string($avatar_value) ? $avatar_value : '';
    return $cache[$term_id];
}

function hpt_register_product_category_rewrite_rule() {
    add_rewrite_rule(
        '^danh-muc/(.+)/?',
        'index.php?product_cat=$matches[1]',
        'top'
    );
}
add_action('init', 'hpt_register_product_category_rewrite_rule');

function hpt_maybe_flush_product_category_rewrite_rules() {
    $rewrite_version = '2026-06-17-1';

    if (get_option('hpt_product_cat_rewrite_version') === $rewrite_version) {
        return;
    }

    hpt_register_product_category_rewrite_rule();
    flush_rewrite_rules(false);
    update_option('hpt_product_cat_rewrite_version', $rewrite_version, false);
}
add_action('admin_init', 'hpt_maybe_flush_product_category_rewrite_rules');

function hpt_mark_product_category_rewrite_for_flush() {
    delete_option('hpt_product_cat_rewrite_version');
}
add_action('after_switch_theme', 'hpt_mark_product_category_rewrite_for_flush');

function display_technical_documents() {
    $args = [
        'post_type'      => 'tai-lieu-ky-thuat',
        'posts_per_page' => 20, // ✅ giới hạn
        'orderby'        => 'date',
        'order'          => 'DESC'
    ];

    $query = new WP_Query($args);
    ob_start();

    if ($query->have_posts()) {
        echo '<div class="technical-documents-list lg:tw-grid tw-grid-cols-2 lg:tw-gap-8">';
        while ($query->have_posts()) {
            $query->the_post();
            get_template_part('template-parts/technical-documents/component-technical-document');
        }
        echo '</div>';
        echo '<div class="text-center tw-mt-4"><button id="load-more-docs" class="button">Xem thêm</button></div>';
    } else {
        echo '<p>Không có tài liệu kỹ thuật nào.</p>';
    }
    wp_reset_postdata();

    return ob_get_clean();
}
add_shortcode('display_technical_documents', 'display_technical_documents');

// Thêm nút xem them vào trang tin tức
function add_read_more_button($output) {
    if (!is_single() && is_post_type_archive('post')) { // Kiểm tra xem có phải là trang lưu trữ không
        $output .= '<a href="' . get_permalink() . '" class="read-more-button">Xem thêm</a>';
    }
    return $output;
}
add_filter('the_excerpt', 'add_read_more_button', 999);


// thêm nút sau nút thêm vào giỏ hàng
add_action('woocommerce_after_add_to_cart_button', 'custom_buttons_after_add_to_cart');

/**
 * Chuẩn hóa danh sách tài liệu sản phẩm.
 *
 * Cơ sở dữ liệu chỉ lưu URL, phân cách bằng dấu phẩy, tối đa 3 link.
 * Tên Catalog / Manual / Datasheet được gán theo vị trí khi hiển thị.
 *
 * @param mixed $value Giá trị từ ACF, post meta hoặc file import.
 * @return string[]
 */
function hpt_parse_product_document_links($value) {
    if (is_array($value)) {
        $parts = $value;
    } else {
        $value = str_replace(array("\r\n", "\r", "\n", '|', ';'), ',', (string) $value);
        $parts = explode(',', $value);
    }

    $links = array();

    foreach ($parts as $part) {
        $url = esc_url_raw(trim((string) $part), array('http', 'https'));

        if ($url === '' || !in_array(strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)), array('http', 'https'), true)) {
            continue;
        }

        if (!in_array($url, $links, true)) {
            $links[] = $url;
        }

        if (count($links) === 3) {
            break;
        }
    }

    return $links;
}

function hpt_normalize_product_document_links($value) {
    return implode(',', hpt_parse_product_document_links($value));
}

// Chuẩn hóa cả khi người dùng lưu trực tiếp field ACF trong trang sản phẩm.
add_filter('acf/update_value/name=link_pdf_product', function ($value) {
    return hpt_normalize_product_document_links($value);
}, 10, 1);

function custom_buttons_after_add_to_cart() {
    global $product;

    if (!$product || !is_a($product, 'WC_Product')) {
        return;
    }

    $checkout_url = wc_get_checkout_url();
    $product_id   = $product->get_id();
    $link_pdf_product = trim((string) get_post_meta($product_id, 'link_pdf_product', true));

    if ($link_pdf_product === '' && function_exists('get_field')) {
        $link_pdf_product = trim((string) get_field('link_pdf_product', $product_id));
    }
    $document_links = hpt_parse_product_document_links($link_pdf_product);
    // Nút Mua Ngay
    echo '<a href="' . esc_url($checkout_url) . '?add-to-cart=' . $product_id . '" class="button buy-now-button tw-justify-center tw-bg-[#DC2626] !tw-rounded-[5px] tw-inline-flex tw-items-center tw-gap-x-1">
            <svg width="20" height="14" viewBox="0 0 20 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M11.6663 5.24992V3.83325C12.1247 3.63881 12.5934 3.49297 13.0726 3.39575C13.5518 3.29853 14.0552 3.24992 14.583 3.24992C14.9441 3.24992 15.2983 3.2777 15.6455 3.33325C15.9927 3.38881 16.333 3.45825 16.6663 3.54159V4.87492C16.333 4.74992 15.9962 4.65617 15.6559 4.59367C15.3156 4.53117 14.958 4.49992 14.583 4.49992C14.0552 4.49992 13.5483 4.56589 13.0622 4.69783C12.5761 4.82978 12.1108 5.01381 11.6663 5.24992ZM11.6663 9.83325V8.41658C12.1247 8.22214 12.5934 8.07631 13.0726 7.97908C13.5518 7.88186 14.0552 7.83325 14.583 7.83325C14.9441 7.83325 15.2983 7.86103 15.6455 7.91658C15.9927 7.97214 16.333 8.04158 16.6663 8.12492V9.45825C16.333 9.33325 15.9962 9.2395 15.6559 9.177C15.3156 9.1145 14.958 9.08325 14.583 9.08325C14.0552 9.08325 13.5483 9.14575 13.0622 9.27075C12.5761 9.39575 12.1108 9.58325 11.6663 9.83325ZM11.6663 7.54158V6.12492C12.1247 5.93047 12.5934 5.78464 13.0726 5.68742C13.5518 5.5902 14.0552 5.54158 14.583 5.54158C14.9441 5.54158 15.2983 5.56936 15.6455 5.62492C15.9927 5.68047 16.333 5.74992 16.6663 5.83325V7.16658C16.333 7.04158 15.9962 6.94783 15.6559 6.88533C15.3156 6.82283 14.958 6.79158 14.583 6.79158C14.0552 6.79158 13.5483 6.85756 13.0622 6.9895C12.5761 7.12145 12.1108 7.30547 11.6663 7.54158ZM5.41634 10.3333C6.06912 10.3333 6.70454 10.4062 7.32259 10.552C7.94065 10.6978 8.55523 10.9166 9.16634 11.2083V2.99992C8.5969 2.66659 7.99273 2.41659 7.35384 2.24992C6.71495 2.08325 6.06912 1.99992 5.41634 1.99992C4.91634 1.99992 4.41981 2.04853 3.92676 2.14575C3.4337 2.24297 2.95801 2.38881 2.49967 2.58325V10.8333C2.98579 10.6666 3.46842 10.5416 3.94759 10.4583C4.42676 10.3749 4.91634 10.3333 5.41634 10.3333ZM10.833 11.2083C11.4441 10.9166 12.0587 10.6978 12.6768 10.552C13.2948 10.4062 13.9302 10.3333 14.583 10.3333C15.083 10.3333 15.5726 10.3749 16.0518 10.4583C16.5309 10.5416 17.0136 10.6666 17.4997 10.8333V2.58325C17.0413 2.38881 16.5656 2.24297 16.0726 2.14575C15.5795 2.04853 15.083 1.99992 14.583 1.99992C13.9302 1.99992 13.2844 2.08325 12.6455 2.24992C12.0066 2.41659 11.4025 2.66659 10.833 2.99992V11.2083ZM9.99967 13.6666C9.33301 13.1388 8.61079 12.7291 7.83301 12.4374C7.05523 12.1458 6.24967 11.9999 5.41634 11.9999C4.83301 11.9999 4.26009 12.0763 3.69759 12.2291C3.13509 12.3819 2.5969 12.5971 2.08301 12.8749C1.79134 13.0277 1.51009 13.0208 1.23926 12.8541C0.968424 12.6874 0.833008 12.4444 0.833008 12.1249V2.08325C0.833008 1.93047 0.871202 1.78464 0.947591 1.64575C1.02398 1.50686 1.13856 1.4027 1.29134 1.33325C1.93023 0.999919 2.5969 0.749919 3.29134 0.583252C3.98579 0.416585 4.69412 0.333252 5.41634 0.333252C6.2219 0.333252 7.01009 0.437419 7.78092 0.645752C8.55176 0.854085 9.29134 1.16659 9.99967 1.58325C10.708 1.16659 11.4476 0.854085 12.2184 0.645752C12.9893 0.437419 13.7775 0.333252 14.583 0.333252C15.3052 0.333252 16.0136 0.416585 16.708 0.583252C17.4025 0.749919 18.0691 0.999919 18.708 1.33325C18.8608 1.4027 18.9754 1.50686 19.0518 1.64575C19.1281 1.78464 19.1663 1.93047 19.1663 2.08325V12.1249C19.1663 12.4444 19.0309 12.6874 18.7601 12.8541C18.4893 13.0208 18.208 13.0277 17.9163 12.8749C17.4025 12.5971 16.8643 12.3819 16.3018 12.2291C15.7393 12.0763 15.1663 11.9999 14.583 11.9999C13.7497 11.9999 12.9441 12.1458 12.1663 12.4374C11.3886 12.7291 10.6663 13.1388 9.99967 13.6666Z" fill="white"/>
            </svg>
            <span>Mua Ngay</span>
    </a>';
    // Nút Báo Giá
    echo '
	
	<a id="request_quote" class=" button quote-button !tw-bg-[#0284C7] !tw-rounded-[5px] tw-inline-flex tw-items-center tw-justify-center tw-gap-x-1 lg:tw-ml-3">
                <svg width="14" height="18" class="-tw-mt-[4px]" viewBox="0 0 14 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M1.99967 17.3334C1.54134 17.3334 1.14898 17.1702 0.822591 16.8438C0.496202 16.5174 0.333008 16.1251 0.333008 15.6667V5.66675C0.333008 5.20841 0.496202 4.81605 0.822591 4.48966C1.14898 4.16328 1.54134 4.00008 1.99967 4.00008H3.66634C3.66634 3.08341 3.99273 2.29869 4.64551 1.64591C5.29829 0.993137 6.08301 0.666748 6.99967 0.666748C7.91634 0.666748 8.70106 0.993137 9.35384 1.64591C10.0066 2.29869 10.333 3.08341 10.333 4.00008H11.9997C12.458 4.00008 12.8504 4.16328 13.1768 4.48966C13.5031 4.81605 13.6663 5.20841 13.6663 5.66675V15.6667C13.6663 16.1251 13.5031 16.5174 13.1768 16.8438C12.8504 17.1702 12.458 17.3334 11.9997 17.3334H1.99967ZM1.99967 15.6667H11.9997V5.66675H10.333V7.33341C10.333 7.56953 10.2531 7.76744 10.0934 7.92716C9.9337 8.08689 9.73579 8.16675 9.49967 8.16675C9.26356 8.16675 9.06565 8.08689 8.90592 7.92716C8.7462 7.76744 8.66634 7.56953 8.66634 7.33341V5.66675H5.33301V7.33341C5.33301 7.56953 5.25315 7.76744 5.09342 7.92716C4.9337 8.08689 4.73579 8.16675 4.49967 8.16675C4.26356 8.16675 4.06565 8.08689 3.90592 7.92716C3.7462 7.76744 3.66634 7.56953 3.66634 7.33341V5.66675H1.99967V15.6667ZM5.33301 4.00008H8.66634C8.66634 3.54175 8.50315 3.14939 8.17676 2.823C7.85037 2.49661 7.45801 2.33341 6.99967 2.33341C6.54134 2.33341 6.14898 2.49661 5.82259 2.823C5.4962 3.14939 5.33301 3.54175 5.33301 4.00008Z" fill="white"/>
                </svg>
                <span>Báo Giá</span>
            </a>';
    // Một tài liệu: mở trực tiếp. Từ hai tài liệu: hiển thị popup chọn link.
    if (!empty($document_links)) {
        $document_button_content = '<svg width="20" height="20" viewBox="0 0 20 20" fill="white" xmlns="http://www.w3.org/2000/svg" class="tw-text-white">
                    <path d="M9.99967 13.3333L5.83301 9.16658L6.99967 7.95825L9.16634 10.1249V3.33325H10.833V10.1249L12.9997 7.95825L14.1663 9.16658L9.99967 13.3333ZM4.99967 16.6666C4.54134 16.6666 4.14898 16.5034 3.82259 16.177C3.4962 15.8506 3.33301 15.4583 3.33301 14.9999V12.4999H4.99967V14.9999H14.9997V12.4999H16.6663V14.9999C16.6663 15.4583 16.5031 15.8506 16.1768 16.177C15.8504 16.5034 15.458 16.6666 14.9997 16.6666H4.99967Z" fill="#0284C7"/>
                </svg>
                <span class="tw-font-bold">Download tài liệu</span>';
        $document_button_class = 'download-catalog tw-inline-flex tw-px-3 tw-py-1 tw-items-center tw-justify-center tw-gap-x-2 !tw-bg-white hover:!tw-bg-gray-100 !tw-border-[#0284C7] tw-transition-all tw-border-solid tw-border-[1px] tw-rounded-md';

        if (count($document_links) === 1) {
            echo '<a href="' . esc_url($document_links[0]) . '" target="_blank" rel="noopener noreferrer" class="' . esc_attr($document_button_class) . '">' . $document_button_content . '</a>';
        } else {
            $modal_id = 'hpt-product-documents-' . $product_id;
            $labels   = array('Catalog', 'Manual', 'Datasheet');

            echo '<button type="button" class="' . esc_attr($document_button_class) . ' hpt-product-documents-open" data-modal-id="' . esc_attr($modal_id) . '" aria-haspopup="dialog" aria-controls="' . esc_attr($modal_id) . '">' . $document_button_content . '</button>';
            echo '<div id="' . esc_attr($modal_id) . '" class="hpt-product-documents-modal" role="dialog" aria-modal="true" aria-label="Tài liệu sản phẩm" hidden>';
            echo '<button type="button" class="hpt-product-documents-backdrop" data-close-documents aria-label="Đóng"></button>';
            echo '<div class="hpt-product-documents-dialog">';
            echo '<button type="button" class="hpt-product-documents-close" data-close-documents aria-label="Đóng">&times;</button>';

            foreach ($document_links as $index => $document_url) {
                echo '<a class="hpt-product-document-link" href="' . esc_url($document_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($labels[$index]) . '</a>';
            }

            echo '</div></div>';
        }
    }
}

add_action('wp_footer', function () {
    if (!is_product()) {
        return;
    }
    ?>
    <script>
    (function () {
        function closeModal(modal) {
            if (!modal) return;
            modal.hidden = true;
            document.body.classList.remove('hpt-documents-modal-open');
        }

        document.addEventListener('click', function (event) {
            var opener = event.target.closest('.hpt-product-documents-open');
            if (opener) {
                var modal = document.getElementById(opener.getAttribute('data-modal-id'));
                if (modal) {
                    document.body.appendChild(modal);
                    modal.hidden = false;
                    document.body.classList.add('hpt-documents-modal-open');
                    var firstLink = modal.querySelector('.hpt-product-document-link');
                    if (firstLink) firstLink.focus();
                }
                return;
            }

            var closer = event.target.closest('[data-close-documents]');
            if (closer) closeModal(closer.closest('.hpt-product-documents-modal'));
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeModal(document.querySelector('.hpt-product-documents-modal:not([hidden])'));
            }
        });
    }());
    </script>
    <?php
}, 99);


// shortcode lấy dữ liệu sản phẩm liên quan  
add_shortcode('recently_viewed_products', 'display_recently_viewed_products');

function display_recently_viewed_products() {
    ob_start();
    the_widget('WC_Widget_Recent_Products', array(
        'title' => 'Sản Phẩm Đã Xem',
        'number' => 5, // Số lượng sản phẩm bạn muốn hiển thị
    ));
    return ob_get_clean();
}

// Hook thay đổi hiển thị giá
add_filter('woocommerce_get_price_html', 'custom_woocommerce_currency_display', 10, 2);
function custom_woocommerce_currency_display($price, $product) {
    $regular_price = floatval($product->get_regular_price());
    $sale_price = floatval($product->get_sale_price());

    // Kiểm tra nếu không có giá
    if (!$regular_price && !$sale_price) {
        return '<span class="woocommerce-Price-amount amount !tw-text-[#0284C7] tw-text-[20px] lg:tw-text-[18px]">Liên hệ</span>';
    }

    $regular_price_formatted = number_format($regular_price, 0, '', '.') . ' VNĐ';
    $sale_price_formatted = number_format($sale_price, 0, '', '.') . ' VNĐ';

    if ($product->is_on_sale()) {
        // Nếu có giá khuyến mãi, hiển thị giá khuyến mãi trước, sau đó là giá gốc
        $price = '<span class="woocommerce-Price-amount amount !tw-text-[#DC2626] tw-text-[20px] lg:tw-text-[18px] ">' . $sale_price_formatted . '</span> ';
        $price .= '<span class="woocommerce-Price-amount amount tw-line-through !tw-text-[#A3A3A3] tw-text-[20px] lg:tw-text-[17px]">' . $regular_price_formatted . '</span>';
    } else {
        // Nếu không có giá khuyến mãi, chỉ hiển thị giá gốc
        $price = '<span class="woocommerce-Price-amount amount !tw-text-[#DC2626] tw-text-[16px] md:tw-text-[18px] lg:tw-text-[20px]">' . $regular_price_formatted . '</span>';
    }
    return $price;
}


// Xử lý hiện popup phần báo giá trong trang sản phẩm chi tiết  tr
function preload_popup_content_register_form() {
    if (!function_exists('is_product') || !is_product()) {
        return;
    }

    // in nội dung block ra, nhưng ban đầu ẩn đi
    echo '<div id="overlay" class="tw-hidden"></div>';
    echo '<div id="popup-container" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-loaded="0" class="tw-max-w-[400px] lg:tw-max-w-[624px] tw-w-full tw-hidden" >
            <button id="close-popup" class=" tw-z-[10]"><span class="dashicons dashicons-no-alt"></span></button>
            <div id="popup-content"></div>
          </div>';
}
add_action('wp_footer', 'preload_popup_content_register_form');

function hpt_get_quote_popup_content_html() {
    $cache_key = 'hpt_quote_popup_block_html_v1';
    $cached    = get_transient($cache_key);

    if (false !== $cached) {
        return $cached;
    }

    $html = do_shortcode('[block id="form-yeu-cau-bao-gia"]');

    if (!is_string($html)) {
        $html = '';
    }

    set_transient($cache_key, $html, DAY_IN_SECONDS);

    return $html;
}

function hpt_ajax_get_quote_popup_content() {
	if (!hpt_public_request_rate_limit('quote_popup', 30, MINUTE_IN_SECONDS)) {
		wp_send_json_error(array('message' => 'Vui lòng thử lại sau.'), 429);
	}

	wp_send_json_success(array(
        'html' => hpt_get_quote_popup_content_html(),
    ));
}
add_action('wp_ajax_hpt_get_quote_popup_content', 'hpt_ajax_get_quote_popup_content');
add_action('wp_ajax_nopriv_hpt_get_quote_popup_content', 'hpt_ajax_get_quote_popup_content');

function hpt_public_request_rate_limit($action, $limit = 30, $window = 60) {
	$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
	$key = 'hpt_rl_' . sanitize_key($action) . '_' . md5($ip);
	$count = (int) get_transient($key);

	if ($count >= (int) $limit) {
		return false;
	}

	set_transient($key, $count + 1, max(1, (int) $window));
	return true;
}


add_filter('woocommerce_product_related_products_heading', 'custom_related_products_title');
function custom_related_products_title($title) {
    return 'Sản phẩm liên quan'; // Thay đổi tiêu đề thành "Sản phẩm liên quan"
}

function custom_registration_form() {
    if (is_user_logged_in()) {
        return 'Bạn đã đăng nhập.';
    }

    ob_start();
    get_template_part('template-parts/auth/custom-registration-form');
    return ob_get_clean();
}
add_shortcode('custom_registration', 'custom_registration_form');

function custom_register_user() {
	if (!wp_verify_nonce((string) ($_POST['hpt_registration_nonce'] ?? ''), 'hpt_custom_registration')) {
		wp_send_json_error(['message' => 'Yêu cầu không hợp lệ. Vui lòng tải lại trang.'], 403);
	}

	if (!hpt_public_request_rate_limit('registration', 5, 15 * MINUTE_IN_SECONDS)) {
		wp_send_json_error(['message' => 'Bạn thao tác quá nhiều lần. Vui lòng thử lại sau.'], 429);
	}

    if (!isset($_POST['email'], $_POST['password'], $_POST['name'], $_POST['phone'], $_POST['confirm_password'])) {
        wp_send_json_error(['message' => 'Vui lòng điền đầy đủ thông tin.']);
    }

    $email = sanitize_email(wp_unslash($_POST['email']));
    $password = (string) wp_unslash($_POST['password']);
    $name = sanitize_text_field($_POST['name']);
    $phone = sanitize_text_field($_POST['phone']);
    $confirm_password = (string) wp_unslash($_POST['confirm_password']);

    if (!is_email($email)) {
        wp_send_json_error(['field' => 'email', 'message' => 'Email không hợp lệ.']);
    }

    if (strlen($password) < 8) {
        wp_send_json_error(['field' => 'password', 'message' => 'Mật khẩu phải có ít nhất 8 ký tự.']);
    }
    error_log("Hàm đăng ký người dùng được gọi.");
    if (email_exists($email)) {
        wp_send_json_error(['field' => 'email', 'message' => 'Lỗi: Xin lỗi, địa chỉ email này đã được sử dụng!']);
    }

    if ($password !== $confirm_password) {
        wp_send_json_error(['field' => 'confirm_password', 'message' => 'Lỗi: Mật khẩu không khớp.']);
    }

    $user_id = wp_create_user($email, $password, $email); // Use email as the username

    if (!is_wp_error($user_id)) {
        $name_parts = explode(' ', $name);
        $first_name = array_shift($name_parts); // Lấy họ
        $last_name = implode(' ', $name_parts); // Lấy tên

        wp_update_user(['ID' => $user_id, 'display_name' => $name, 'first_name' => $first_name, 'last_name' => $last_name]); // Set the display name to Full Name
        update_user_meta($user_id, 'name', $name);
        // Lưu số điện thoại vào meta key WooCommerce
        update_user_meta($user_id, 'billing_phone', $phone); // Địa chỉ thanh toán
        update_user_meta($user_id, 'shipping_phone', $phone); // Địa chỉ giao hàng

        wp_send_json_success(['message' => 'Đăng ký thành công!']);
    } else {
        wp_send_json_error(['field' => 'password', 'message' => 'Lỗi: ' . $user_id->get_error_message()]);
    }
}

// Add AJAX action for non-logged in users
add_action('wp_ajax_nopriv_custom_register_user', 'custom_register_user');

// Tạo shortcode cho liên kết "Đăng ký"
function registration_link_shortcode() {
    if (!is_user_logged_in()) {
        return '<a href="/dang-ky"><span class="tw-text-base tw-normal-case tw-text-[#171717]">Đăng ký</span></a>';
    }
    return ''; // Nếu đã đăng nhập, không trả về gì cả
}

// Đăng ký shortcode
add_shortcode('registration_link', 'registration_link_shortcode');



// Đ1
// function auto_slug_from_focus_keyword_on_create($post_id) {

//     if (!check_user_528_role()) {
//         return;
//     }

//     if (get_post_type($post_id) === 'product' && !wp_is_post_revision($post_id)) {

//         $focus_keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true);

//         if (!empty($focus_keyword)) {
//             $slug = sanitize_title($focus_keyword);
//             remove_action('save_post', 'auto_slug_from_focus_keyword_on_create');
//             wp_update_post(array(
//                 'ID' => $post_id,
//                 'post_name' => $slug,
//             ));
//             add_action('save_post', 'auto_slug_from_focus_keyword_on_create');
//         }
//     }
// }
// add_action('save_post', 'auto_slug_from_focus_keyword_on_create');



// Đ2 //
function is_allowed_user() {
    $current_user_id = get_current_user_id();
    $allowed_user_ids = array(528, 111);

    return in_array($current_user_id, $allowed_user_ids);
}

function check_user_528_role() {
    $user = get_user_by('id', 528);
    if ($user && in_array('administrator', $user->roles)) {
        return true;
    }
    return false;
}

function restrict_theme_editor_access() {
    $allowed_user_ids = array(528, 111);
    $current_user_id = get_current_user_id();
    if (!in_array($current_user_id, $allowed_user_ids) || !check_user_528_role()) {
        remove_submenu_page('themes.php', 'theme-editor.php');
    }
}
add_action('admin_menu', 'restrict_theme_editor_access', 999);



// Đ5-k
function add_author_column_to_products($columns) {
    if (!check_user_528_role()) {
        return $columns;
    }

    $columns['author'] = __('Tác Giả', 'text-domain');
    return $columns;
}
add_filter('manage_edit-product_columns', 'add_author_column_to_products');

function display_author_column_in_products($column, $post_id) {
    if (!check_user_528_role()) {
        return;
    }

    if ($column === 'author') {
        $author_id = get_post_field('post_author', $post_id);
        $author_name = get_the_author_meta('display_name', $author_id);
        echo esc_html($author_name);
    }
}
add_action('manage_product_posts_custom_column', 'display_author_column_in_products', 10, 2);

function make_author_column_sortable($columns) {

    if (!check_user_528_role()) {
        return $columns;
    }

    $columns['author'] = 'author';
    return $columns;
}
add_filter('manage_edit-product_sortable_columns', 'make_author_column_sortable');

function sort_products_by_author($query) {

    if (!check_user_528_role()) {
        return;
    }

    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    if ($query->get('orderby') === 'author') {
        $query->set('orderby', 'post_author');
    }
}
add_action('pre_get_posts', 'sort_products_by_author');

function hide_author_column_by_default($hidden, $screen) {

    if (!check_user_528_role()) {
        return $hidden;
    }

    if ($screen->id === 'edit-product') {
        $hidden[] = 'author';
    }
    return $hidden;
}
add_filter('default_hidden_columns', 'hide_author_column_by_default', 10, 2);



// Đ7
function add_author_column_to_csv($columns) {
    if (check_user_528_role()) {
        $columns['author'] = 'Người Đăng';
    }
    return $columns;
}
add_filter('woocommerce_product_export_column_names', 'add_author_column_to_csv');
add_filter('woocommerce_product_export_product_default_columns', 'add_author_column_to_csv');

function add_author_data_to_csv($value, $product) {
    static $export_author_ids = array();

    if (is_allowed_user() && check_user_528_role()) {
        $product_id = $product->get_id();
        if (!isset($export_author_ids[$product_id])) {
            $author_post_id = $product->is_type('variation') ? $product->get_parent_id() : $product_id;
            $post = get_post($author_post_id);
            $export_author_ids[$product_id] = $post ? absint($post->post_author) : 0;
        }

        $author_id = $export_author_ids[$product_id];
        return $author_id ? get_the_author_meta('display_name', $author_id) : '';
    }
    return $value;
}
add_filter('woocommerce_product_export_product_column_author', 'add_author_data_to_csv', 10, 2);



// Đ9
function custom_focus_keyword_csv_features() {
    if (!check_user_528_role()) {
        return;
    }

    function add_focus_keyword_column_to_csv($columns) {
        $columns['focus_keyword'] = __('Từ khoá chính - Meta: rank_math_focus_keyword', 'textdomain');
        return $columns;
    }
    add_filter('woocommerce_product_export_column_names', 'add_focus_keyword_column_to_csv');
    add_filter('woocommerce_product_export_product_default_columns', 'add_focus_keyword_column_to_csv');

    function export_focus_keyword_to_csv($value, $product) {
        $focus_keyword = get_post_meta($product->get_id(), 'rank_math_focus_keyword', true);
        return $focus_keyword ?: '';
    }
    add_filter('woocommerce_product_export_product_column_focus_keyword', 'export_focus_keyword_to_csv', 10, 2);
}
add_action('init', 'custom_focus_keyword_csv_features');



function custom_my_account_menu_items($items) {
    $items['dashboard'] = __('Thông tin tài khoản', 'woocommerce');
    // Đổi tên tab 'orders' thành 'Đơn hàng của tôi'
    $items['orders'] = __('Đơn hàng của tôi', 'woocommerce');
    // Thêm mục 'Change Password' (Đổi mật khẩu)
    $items['change-password'] = __('Đổi mật khẩu', 'woocommerce');
    // Xóa các tab không cần thiết
    unset($items['downloads']);
    unset($items['payment-methods']);
    // Ẩn tab 'Addresses' (Địa chỉ)
    unset($items['edit-address']);
    // Đổi tên tab
    $items['edit-account'] = __('Cập nhật thông tin', 'woocommerce');
    $items['customer-logout'] = __('Đăng xuất', 'woocommerce');
    return $items;
}
add_filter('woocommerce_account_menu_items', 'custom_my_account_menu_items');

function add_password_change_endpoint() {
    add_rewrite_endpoint('change-password', EP_PAGES);
}
add_action('init', 'add_password_change_endpoint');

// Hiển thị form đổi mật khẩu
function change_password_content() {
    if (!is_user_logged_in()) {
        echo '<p>' . __('Bạn cần đăng nhập để đổi mật khẩu.', 'woocommerce') . '</p>';
        return;
    }
    get_template_part('template-parts/auth/form-change-password');
}
add_action('woocommerce_account_change-password_endpoint', 'change_password_content');

function handle_password_change() {
    if (isset($_POST['save_password'])) {
        if (!is_user_logged_in() || !wp_verify_nonce((string) ($_POST['hpt_change_password_nonce'] ?? ''), 'hpt_change_password')) {
            wc_add_notice(__('Yêu cầu không hợp lệ. Vui lòng tải lại trang.', 'woocommerce'), 'error');
            return;
        }

        $current_password = (string) wp_unslash($_POST['password_current'] ?? '');
        $new_password = (string) wp_unslash($_POST['password_1'] ?? '');
        $confirm_password = (string) wp_unslash($_POST['password_2'] ?? '');

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            wc_add_notice(__('Vui lòng điền tất cả các trường.', 'woocommerce'), 'error');
            return;
        }

        if ($new_password !== $confirm_password) {
            wc_add_notice(__('Mật khẩu mới và xác nhận mật khẩu không khớp.', 'woocommerce'), 'error');
            return;
        }

        if (strlen($new_password) < 8) {
            wc_add_notice(__('Mật khẩu mới phải có ít nhất 8 ký tự.', 'woocommerce'), 'error');
            return;
        }

        $user = wp_get_current_user();

        if (!wp_check_password($current_password, $user->user_pass, $user->ID)) {
            wc_add_notice(__('Mật khẩu hiện tại không đúng.', 'woocommerce'), 'error');
            return;
        }

        wp_set_password($new_password, $user->ID);
        wc_add_notice(__('Mật khẩu của bạn đã được thay đổi.', 'woocommerce'), 'success');
    }
}
add_action('template_redirect', 'handle_password_change');

// Thêm tab "Đổi mật khẩu" vào My Account
function add_password_change_tab($items) {
    $items['change-password'] = __('Đổi mật khẩu', 'woocommerce'); // Thêm tab mới
    return $items;
}
add_filter('woocommerce_account_menu_items', 'add_password_change_tab');

// Hiển thị trường số điện thoại trong form "Chỉnh sửa tài khoản"
function add_phone_number_field_to_account_details() {
    $user_id = get_current_user_id();
    $phone = get_user_meta($user_id, 'billing_phone', true);
?>
    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
        <label for="account_phone">Số điện thoại</label>
        <input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="account_phone" id="account_phone" value="<?php echo esc_attr($phone); ?>" />
    </p>
<?php
}
add_action('woocommerce_edit_account_form', 'add_phone_number_field_to_account_details');
// Lưu số điện thoại khi người dùng cập nhật thông tin
function save_phone_number_field_in_account_details($user_id) {
    if (isset($_POST['account_phone'])) {
        update_user_meta($user_id, 'billing_phone', sanitize_text_field($_POST['account_phone']));
    }
}
add_action('woocommerce_save_account_details', 'save_phone_number_field_in_account_details');

add_action('wp_ajax_filter_brand', 'filter_brand');
add_action('wp_ajax_nopriv_filter_brand', 'filter_brand');

function hpt_get_brand_filter_ordering_args() {
    if (function_exists('WC') && WC()->query) {
        $ordering_args = WC()->query->get_catalog_ordering_args();

        if (is_array($ordering_args) && !empty($ordering_args)) {
            return $ordering_args;
        }
    }

    return array(
        'orderby' => 'menu_order title',
        'order'   => 'ASC',
    );
}

function hpt_get_brand_filter_per_page() {
    return (int) apply_filters(
        'loop_shop_per_page',
        wc_get_default_products_per_row() * wc_get_default_product_rows_per_page()
    );
}

function hpt_get_cached_product_cat_term($term_id) {
    static $terms = array();

    $term_id = absint($term_id);

    if ($term_id <= 0) {
        return null;
    }

    if (!array_key_exists($term_id, $terms)) {
        $term = get_term($term_id, 'product_cat');
        $terms[$term_id] = ($term && !is_wp_error($term)) ? $term : null;
    }

    return $terms[$term_id];
}

function hpt_get_product_cat_depth($term_id) {
    $depth = 0;
    $term  = hpt_get_cached_product_cat_term($term_id);

    while ($term && !is_wp_error($term) && (int) $term->parent > 0) {
        $depth++;
        $term = hpt_get_cached_product_cat_term((int) $term->parent);
    }

    return $depth;
}

function hpt_get_brand_root_product_cat_id($brand_id) {
    static $cache = array();

    $brand_id = absint($brand_id);

    if ($brand_id <= 0) {
        return 0;
    }

    if (array_key_exists($brand_id, $cache)) {
        return $cache[$brand_id];
    }

    $brand_taxonomy = hpt_get_brand_filter_taxonomy();
    $brand_term     = $brand_taxonomy ? get_term($brand_id, $brand_taxonomy) : null;

    if (!$brand_term || is_wp_error($brand_term)) {
        $cache[$brand_id] = 0;
        return 0;
    }

    $candidates = array();

    if (!empty($brand_term->slug)) {
        $slug_match = get_term_by('slug', $brand_term->slug, 'product_cat');

        if ($slug_match && !is_wp_error($slug_match)) {
            $candidates[$slug_match->term_id] = $slug_match;
        }
    }

    if (!empty($brand_term->name)) {
        $name_match = get_term_by('name', $brand_term->name, 'product_cat');

        if ($name_match && !is_wp_error($name_match)) {
            $candidates[$name_match->term_id] = $name_match;
        }

        $like_terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'name__like' => $brand_term->name,
            'number'     => 20,
        ));

        if (!empty($like_terms) && !is_wp_error($like_terms)) {
            $normalized_brand = sanitize_title($brand_term->slug ?: $brand_term->name);

            foreach ($like_terms as $like_term) {
                $normalized_term = sanitize_title($like_term->slug ?: $like_term->name);

                if ($normalized_term === $normalized_brand) {
                    $candidates[$like_term->term_id] = $like_term;
                }
            }
        }
    }

    if (empty($candidates)) {
        $cache[$brand_id] = 0;
        return 0;
    }

    uasort($candidates, function ($a, $b) {
        return hpt_get_product_cat_depth($a->term_id) <=> hpt_get_product_cat_depth($b->term_id);
    });

    $resolved = reset($candidates);
    $cache[$brand_id] = $resolved ? (int) $resolved->term_id : 0;

    return $cache[$brand_id];
}

function hpt_get_brand_filter_parent_cat_id($root_cat_id = 0, $brand_id = 0) {
    $root_cat_id = absint($root_cat_id);
    $brand_id    = absint($brand_id);

    if ($root_cat_id > 0) {
        return $root_cat_id;
    }

    if ($brand_id > 0) {
        $brand_root_cat_id = hpt_get_brand_root_product_cat_id($brand_id);

        if ($brand_root_cat_id > 0) {
            return $brand_root_cat_id;
        }
    }

    return hpt_get_brand_filter_global_parent_cat_id();
}

function hpt_get_brand_filter_global_parent_cat_id() {
    static $parent_id = null;

    if (null !== $parent_id) {
        return $parent_id;
    }

    $top_level_terms = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'parent'     => 0,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ));

    if (empty($top_level_terms) || is_wp_error($top_level_terms)) {
        $parent_id = 0;
        return $parent_id;
    }

    if (1 === count($top_level_terms)) {
        $child_ids = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'parent'     => (int) $top_level_terms[0]->term_id,
            'fields'     => 'ids',
            'number'     => 1,
        ));

        if (!empty($child_ids) && !is_wp_error($child_ids)) {
            $parent_id = (int) $top_level_terms[0]->term_id;
            return $parent_id;
        }
    }

    $parent_id = 0;
    return $parent_id;
}

function hpt_resolve_brand_filter_display_category($term_id, $root_cat_id = 0, $brand_id = 0) {
    $term = hpt_get_cached_product_cat_term($term_id);

    if (!$term) {
        return null;
    }

    $root_cat_id      = absint($root_cat_id);
    $brand_id         = absint($brand_id);
    $target_parent_id = hpt_get_brand_filter_parent_cat_id($root_cat_id, $brand_id);

    if ($target_parent_id > 0 && (int) $term->term_id === $target_parent_id) {
        return null;
    }

    if (0 === $target_parent_id) {
        while ((int) $term->parent > 0) {
            $parent_term = hpt_get_cached_product_cat_term((int) $term->parent);

            if (!$parent_term) {
                break;
            }

            $term = $parent_term;
        }

        return $term;
    }

    while ((int) $term->parent > 0 && (int) $term->parent !== $target_parent_id) {
        $parent_term = hpt_get_cached_product_cat_term((int) $term->parent);

        if (!$parent_term) {
            return null;
        }

        $term = $parent_term;
    }

    if ((int) $term->parent === $target_parent_id) {
        return $term;
    }

    return null;
}

function hpt_get_effective_product_cat_id($root_cat_id, $selected_cat_id) {
    $root_cat_id     = absint($root_cat_id);
    $selected_cat_id = absint($selected_cat_id);

    if ($selected_cat_id <= 0) {
        return $root_cat_id;
    }

    if ($root_cat_id <= 0 || $selected_cat_id === $root_cat_id) {
        return $selected_cat_id;
    }

    $selected_term = get_term($selected_cat_id, 'product_cat');

    if (!$selected_term || is_wp_error($selected_term)) {
        return $root_cat_id;
    }

    $parent_id = (int) $selected_term->parent;

    while ($parent_id > 0) {
        if ($parent_id === $root_cat_id) {
            return $selected_cat_id;
        }

        $parent_term = get_term($parent_id, 'product_cat');

        if (!$parent_term || is_wp_error($parent_term)) {
            break;
        }

        $parent_id = (int) $parent_term->parent;
    }

    return $root_cat_id;
}

function hpt_build_brand_filter_tax_query($root_cat_id, $brand_id, $selected_cat_id = 0) {
    $tax_query       = array();
    $brand_taxonomy  = hpt_get_brand_filter_taxonomy();
    $effective_cat_id = hpt_get_effective_product_cat_id($root_cat_id, $selected_cat_id);

    if ($effective_cat_id > 0) {
        $tax_query[] = array(
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => array($effective_cat_id),
            'include_children' => true,
        );
    }

    if ($brand_taxonomy && $brand_id > 0) {
        $tax_query[] = array(
            'taxonomy'         => $brand_taxonomy,
            'field'            => 'term_id',
            'terms'            => array(absint($brand_id)),
            'include_children' => false,
        );
    }

    if (count($tax_query) > 1) {
        $tax_query['relation'] = 'AND';
    }

    return $tax_query;
}

function hpt_get_brand_filter_query_args($root_cat_id, $brand_id, $selected_cat_id = 0, $posts_per_page = -1, $paged = 1) {
    $args          = array(
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'posts_per_page'      => $posts_per_page,
        'ignore_sticky_posts' => true,
    );
    $ordering_args = hpt_get_brand_filter_ordering_args();
    $tax_query     = hpt_build_brand_filter_tax_query($root_cat_id, $brand_id, $selected_cat_id);

    if (!empty($ordering_args['orderby'])) {
        $args['orderby'] = $ordering_args['orderby'];
    }

    if (!empty($ordering_args['order'])) {
        $args['order'] = $ordering_args['order'];
    }

    if (!empty($ordering_args['meta_key'])) {
        $args['meta_key'] = $ordering_args['meta_key'];
    }

    if (!empty($tax_query)) {
        $args['tax_query'] = $tax_query;
    }

    if ($paged > 1) {
        $args['paged'] = $paged;
    }

    if (-1 === (int) $posts_per_page || 1 === (int) $posts_per_page) {
        $args['no_found_rows'] = true;
    }

    return $args;
}

function hpt_get_filtered_product_ids($root_cat_id, $brand_id) {
    $query = new WP_Query(array_merge(
        hpt_get_brand_filter_query_args($root_cat_id, $brand_id, 0, -1),
        array(
            'fields'                 => 'ids',
            'orderby'                => 'none',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        )
    ));

    return array_map('absint', $query->posts);
}

function hpt_get_filtered_child_categories($root_cat_id, $brand_id) {
    $root_cat_id = absint($root_cat_id);
    $brand_id    = absint($brand_id);

    $product_ids = hpt_get_filtered_product_ids($root_cat_id, $brand_id);

    if (empty($product_ids)) {
        return array();
    }

    $product_terms = wp_get_object_terms($product_ids, 'product_cat', array(
        'orderby' => 'name',
        'order'   => 'ASC',
    ));

    if (empty($product_terms) || is_wp_error($product_terms)) {
        return array();
    }

    $matched_terms = array();

    foreach ($product_terms as $term) {
        $current_term = hpt_resolve_brand_filter_display_category($term->term_id, $root_cat_id, $brand_id);

        if (
            $current_term
        ) {
            $matched_terms[$current_term->term_id] = array(
                'term_id' => $current_term->term_id,
                'name'    => $current_term->name,
            );
        }
    }

    uasort($matched_terms, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    return array_values($matched_terms);
}

function hpt_get_brand_filter_pagination_html($query, $paged) {
    if (!$query instanceof WP_Query || $query->max_num_pages < 2) {
        return '';
    }

    $links = paginate_links(array(
        'base'      => '#page=%#%',
        'format'    => '',
        'current'   => max(1, $paged),
        'total'     => max(1, (int) $query->max_num_pages),
        'type'      => 'array',
        'prev_text' => '&laquo;',
        'next_text' => '&raquo;',
    ));

    if (empty($links) || !is_array($links)) {
        return '';
    }

    $html = '<nav class="woocommerce-pagination" aria-label="Điều hướng sản phẩm"><ul class="page-numbers nav-pagination links text-center">';

    foreach ($links as $link) {
        if (preg_match('/page=(\d+)/', $link, $matches)) {
            $page = absint($matches[1]);
            $link = preg_replace('/href="[^"]*"/', 'href="javascript:void(0);" data-page="' . $page . '"', $link);
        }

        $html .= '<li>' . $link . '</li>';
    }

    $html .= '</ul></nav>';

    return $html;
}

function filter_brand() {
    if (!hpt_public_request_rate_limit('filter_brand', 120, MINUTE_IN_SECONDS)) {
        wp_send_json_error(array('message' => 'Vui lòng thử lại sau.'), 429);
    }

    $root_cat_id     = absint($_POST['rootCatId'] ?? 0);
    $selected_cat_id = absint($_POST['catId'] ?? 0);
    $brand_id        = absint($_POST['brandId'] ?? 0);
    $paged           = min(100, max(1, absint($_POST['page'] ?? 1)));
    $per_page        = max(1, hpt_get_brand_filter_per_page());
    $effective_cat_id = hpt_get_effective_product_cat_id($root_cat_id, $selected_cat_id);
    $cache_key       = sprintf(
        'hpt_brand_filter_v4_%d_%d_%d_%d_%d',
        $root_cat_id,
        $effective_cat_id,
        $brand_id,
        $paged,
        $per_page
    );
    $cached          = get_transient($cache_key);

    if (false !== $cached) {
        wp_send_json_success($cached);
    }

    $query         = new WP_Query(hpt_get_brand_filter_query_args($root_cat_id, $brand_id, $selected_cat_id, $per_page, $paged));
    $products_html = '';

    if ($query->have_posts()) {
        ob_start();
        while ($query->have_posts()) {
            $query->the_post();
            wc_get_template_part('content', 'product');
        }
        $products_html = ob_get_clean();
    } else {
        $products_html = '<li class="product hpt-filter-message"><div class="box-text"><p>Không có sản phẩm phù hợp.</p></div></li>';
    }

    wp_reset_postdata();

    $response = array(
        'products_html'   => $products_html,
        'pagination_html' => hpt_get_brand_filter_pagination_html($query, $paged),
        'sub_cat'         => $brand_id > 0 ? hpt_get_filtered_child_categories($root_cat_id, $brand_id) : array(),
        'current_page'    => $paged,
        'max_num_pages'   => (int) $query->max_num_pages,
        'found_posts'     => (int) $query->found_posts,
    );

    set_transient($cache_key, $response, 6 * HOUR_IN_SECONDS);
    wp_send_json_success($response);
}

// Thêm nút "Hủy đơn hàng" vào cột Hành động trong trang My Account > Orders
add_filter('woocommerce_my_account_my_orders_actions', 'add_cancel_order_action', 10, 2);

function add_cancel_order_action($actions, $order) {
    // Chỉ thêm nút "Hủy đơn hàng" nếu trạng thái đơn hàng là "processing" hoặc "on-hold"
    if (in_array($order->get_status(), array('processing', 'on-hold'), true)) {
        $actions['cancel'] = array(
            'url'  => wp_nonce_url(
                add_query_arg('cancel_order', $order->get_id(), wc_get_account_endpoint_url('orders')),
                'woocommerce-cancel_order'
            ),
            'name' => __('Cancel', 'woocommerce'),
        );
    }
    return $actions;
}

// Xử lý hủy đơn hàng khi người dùng nhấn nút "Hủy đơn hàng"
add_action('init', 'process_cancel_order_action');

function process_cancel_order_action() {
    if (isset($_GET['cancel_order'], $_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'woocommerce-cancel_order')) {
        $order_id = absint($_GET['cancel_order']);
        $order    = wc_get_order($order_id);

        if ($order && $order->get_user_id() === get_current_user_id()) {
            // Cập nhật trạng thái đơn hàng thành "cancelled"
            $order->update_status('cancelled', __('Order cancelled by the customer.', 'woocommerce'));
            wc_add_notice(__('Your order has been cancelled.', 'woocommerce'));
        }
    }
}

add_filter('woocommerce_account_orders_columns', 'custom_my_orders_columns');

function custom_my_orders_columns($columns) {
    // Chỉnh sửa tiêu đề cột và đảm bảo có đủ 5 cột, với trạng thái thanh toán và trạng thái vận chuyển riêng biệt
    $columns = array(
        'order-number'     => __('Đơn hàng', 'woocommerce'),
        'order-date'       => __('Ngày mua', 'woocommerce'),
        'order-status'   => __('Trạng thái thanh toán', 'woocommerce'), // Cột trạng thái thanh toán
        'shipping-status'  => __('Trạng thái vận chuyển', 'woocommerce'), // Cột trạng thái vận chuyển
        'order-actions'    => __('Hành động', 'woocommerce'),
    );
    return $columns;
}

function phongmyvn_recently_viewed_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'columns' => 6,  // 6 cột hiển thị trên mỗi hàng
            'limit'   => 28, // 24 sản phẩm hiển thị ban đầu
            'offset'  => 0,  // Vị trí bắt đầu
        ),
        $atts,
        'recently_viewed'
    );

    $viewed_products = !empty($_COOKIE['woocommerce_recently_viewed']) ? (array) explode('|', wp_unslash($_COOKIE['woocommerce_recently_viewed'])) : array();
    $viewed_products = array_reverse(array_filter(array_map('absint', $viewed_products)));

    error_log('Total viewed products: ' . count($viewed_products));
    if (empty($viewed_products)) {
        return '<p>Không có sản phẩm nào đã xem.</p>';
    }

    // Lấy danh sách sản phẩm theo limit và offset
    $viewed_products = array_slice($viewed_products, $atts['offset'], $atts['limit']);

    if (empty($viewed_products)) {
        return '';
    }

    // Sử dụng layout row
    $shortcode = do_shortcode('[ux_products type="row" columns="' . intval($atts['columns']) . '" ids="' . implode(',', $viewed_products) . '"]');

    return '<div class="recently-viewed-products">' . $shortcode . '</div>';
}
add_shortcode('recently_viewed', 'phongmyvn_recently_viewed_shortcode');

add_action('wp_footer', function () {
?>
    <script>
        jQuery(document).ready(function($) {
            let offset = 24; // Bắt đầu hiển thị 24 sản phẩm
            let limit = 8; // Số sản phẩm mỗi lần tải
            const loadMoreButton = $('#load-more-viewed-products');
            const productsContainer = $('#recently-viewed-container');

            loadMoreButton.on('click', function() {
                $.ajax({
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    type: 'POST',
                    data: {
                        action: 'load_more_recently_viewed',
                        offset: offset,
                        limit: limit,
                    },
                    beforeSend: function() {
                        loadMoreButton.text('Đang tải...');
                    },
                    success: function(response) {
                        if (response) {
                            productsContainer.append(response); // Thêm sản phẩm mới
                            offset += limit; // Tăng offset lên 8 để tải hàng tiếp theo
                            loadMoreButton.text('Xem thêm');
                        } else {
                            loadMoreButton.hide(); // Ẩn nút nếu không còn sản phẩm nào
                        }
                    },
                    error: function() {
                        loadMoreButton.text('Có lỗi xảy ra, thử lại!');
                    }
                });
            });
        });
    </script>
<?php
});

function phongmyvn_load_more_recently_viewed() {
    if (!hpt_public_request_rate_limit('recently_viewed', 60, MINUTE_IN_SECONDS)) {
        status_header(429);
        wp_die('Vui lòng thử lại sau.');
    }

    $offset = isset($_POST['offset']) ? min(1000, max(0, absint($_POST['offset']))) : 0;
    $limit  = isset($_POST['limit']) ? min(24, max(1, absint($_POST['limit']))) : 8;

    echo do_shortcode('[recently_viewed columns="4" limit="' . $limit . '" offset="' . $offset . '"]');

    wp_die(); // Kết thúc AJAX
}
add_action('wp_ajax_load_more_recently_viewed', 'phongmyvn_load_more_recently_viewed');
add_action('wp_ajax_nopriv_load_more_recently_viewed', 'phongmyvn_load_more_recently_viewed');

add_filter('woocommerce_recently_viewed_cookie_lifetime', function ($lifetime) {
    return 86400; // Tăng thời gian lưu cookie (1 ngày)
});

add_filter('woocommerce_recently_viewed_products_max', function ($max) {
    return 50; // Tăng số lượng sản phẩm được lưu trong cookie
});


function display_product_overview_shortcode($atts) {
    // Lấy thuộc tính truyền vào, nếu có
    $atts = shortcode_atts(
        array(
            'product_id' => get_the_ID(), // Nếu không truyền product_id, mặc định lấy ID bài viết hiện tại
        ),
        $atts
    );

    // Lấy giá trị của custom field 'overview'
    $product_id = intval($atts['product_id']);
    $overview = get_field('overview', $product_id);

    // Nếu không có giá trị, trả về chuỗi trống
    if (empty($overview)) {
        return '<p>Thông tin tổng quan chưa được cập nhật.</p>';
    }

    // Trả về giá trị custom field để hiển thị
    //return '<div class="product-overview">' . do_shortcode('[img_overview_top]') . $overview . do_shortcode('[img_overview_bottom]') . '</div>';
    return '<div class="product-overview">'. $overview . '</div>';

}
add_shortcode('product_overview', 'display_product_overview_shortcode');

add_filter('term_link', function ($url, $term, $taxonomy) {
    if ($taxonomy === 'product_cat') {
        return str_replace('/product-category/', '/danh-muc/', $url);
    }
    return $url;
}, 10, 3);




// Lấy số sản phẩm đã xem từ cookie WooCommerce
function get_viewed_products_count() {
    $viewed_products = !empty($_COOKIE['woocommerce_recently_viewed']) ? (array) explode('|', wp_unslash($_COOKIE['woocommerce_recently_viewed'])) : array();
    $viewed_products = array_reverse(array_filter(array_map('absint', $viewed_products)));
    return count($viewed_products);
}

// Tạo shortcode để hiển thị nút sản phẩm đã xem và số lượng sản phẩm đã xem
function viewed_products_count_shortcode() {
    $count = get_viewed_products_count();
    $output = '<div>
    <a class="tw-flex tw-items-center tw-gap-x-1" href="/san-pham-da-xem">
        <svg width="27" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M2 9V5C2 4.45 2.19583 3.97917 2.5875 3.5875C2.97917 3.19583 3.45 3 4 3H20C20.55 3 21.0208 3.19583 21.4125 3.5875C21.8042 3.97917 22 4.45 22 5V9H20V5H4V9H2ZM4 18C3.45 18 2.97917 17.8042 2.5875 17.4125C2.19583 17.0208 2 16.55 2 16V11H4V16H20V11H22V16C22 16.55 21.8042 17.0208 21.4125 17.4125C21.0208 17.8042 20.55 18 20 18H4ZM1 21V19H23V21H1ZM2 11V9H8C8.18333 9 8.35833 9.05 8.525 9.15C8.69167 9.25 8.81667 9.38333 8.9 9.55L10.075 11.875L13.15 6.5C13.2333 6.35 13.35 6.22917 13.5 6.1375C13.65 6.04583 13.8167 6 14 6C14.1833 6 14.3583 6.04583 14.525 6.1375C14.6917 6.22917 14.8167 6.36667 14.9 6.55L16.125 9H22V11H15.5C15.3167 11 15.1417 10.9542 14.975 10.8625C14.8083 10.7708 14.6833 10.6333 14.6 10.45L13.95 9.125L10.875 14.5C10.7917 14.6667 10.6667 14.7917 10.5 14.875C10.3333 14.9583 10.1583 15 9.975 15C9.79167 15 9.62083 14.95 9.4625 14.85C9.30417 14.75 9.18333 14.6167 9.1 14.45L7.375 11H2Z" fill="#0284C7"/>
        </svg>
        <span class="header-viewed-products-title tw-relative">Sản phẩm đã xem <sup class="tw-text-[#ff0000] !tw-text-[16px] tw-rounded-full tw-opacity-90 tw-absolute  tw-w-[18px] tw-h-[18px] tw-flex tw-items-center tw-justify-center tw-p-0 tw-left-[-10px] tw-top-[-14px]" style="font-size:16px">' . $count . '</sup></span>
    </a>
</div>';
    return $output;
}
add_shortcode('viewed_products_count', 'viewed_products_count_shortcode');

function remove_related_products($related_posts) {
    return array(); // Trả về một mảng rỗng, tức là không có sản phẩm liên quan nào
}
add_filter('woocommerce_related_products', 'remove_related_products');
function GetChildCat($listcat, $cat){
    $result = $cat;
    for ($i = 0; $i < count($listcat); $i++) {
        if ($listcat[$i]->parent > 0){
            // $child trả về mảng ID, không phải object
            $child = get_term_children($listcat[$i]->term_id, 'product_cat');
            $result = $listcat[$i];
            foreach ($child as $value) {
                for ($j = 0; $j < count($listcat); $j++){
                    // $value là int (term_id), nên so sánh trực tiếp
                    if ($listcat[$j]->term_id == $value){
                        $result = $listcat[$j];
                        break 2; // thoát cả 2 vòng for
                    }
                }
            }
            break;
        }
    }
    return $result;
}

/**
 * Shortcode: [related_products_by_category]
 * Hiển thị 12 sản phẩm liên quan và nút "Xem thêm" (load thêm 6 sp / lần)
 */
function related_products_by_category_shortcode($atts) {
    global $product;
    if (!is_a($product, 'WC_Product')) return '';

    $product_id = $product->get_id();

    // ✅ Lấy danh mục sâu nhất
    $categories = get_the_terms($product_id, 'product_cat');
    if (empty($categories)) return '';

    $deepest_cat = null; $max_depth = -1;
    foreach ($categories as $cat) {
        $depth = 0; $p = $cat->parent;
        while ($p > 0) { $depth++; $p = get_term($p, 'product_cat')->parent; }
        if ($depth > $max_depth) { $max_depth = $depth; $deepest_cat = $cat; }
    }
    if (!$deepest_cat) $deepest_cat = $categories[0];

    $cache_key = 'related_products_v2_' . $product_id . '_' . (int) $deepest_cat->term_id;

    if ($cached = get_transient($cache_key)) return $cached;

    // Ưu tiên truy vấn ổn định, tránh ORDER BY RAND() rất nặng trên bảng lớn.
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => 12,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'post__not_in'   => [$product_id],
        'no_found_rows'  => true,
        'ignore_sticky_posts' => true,
        'tax_query'      => [[
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => $deepest_cat->term_id,
        ]]
    ];

    $query = new WP_Query($args);

    ob_start();
    if ($query->have_posts()) {
        echo '<div class="container related-wrapper">';
        echo '<h2 class="related-title">Sản phẩm liên quan</h2>';
        echo '<ul class="related-products tw-grid tw-grid-cols-2 md:tw-grid-cols-3 lg:tw-grid-cols-6 tw-gap-2 lg:tw-gap-3">';
        while ($query->have_posts()) {
            $query->the_post();
            wc_get_template_part('content', 'product');
        }
        echo '</ul>';
        echo '<div class="load-more-wrap tw-text-center tw-mt-4">';
        echo '<button id="load-more-product" class="tw-bg-blue-500 hover:tw-bg-blue-600 tw-text-white tw-px-4 tw-py-2 tw-rounded">Xem thêm sản phẩm</button>';
        echo '</div>';
        echo '</div>';
    }
    wp_reset_postdata();

    $output = ob_get_clean();

    // ✅ Cache nhẹ (2 tiếng thôi)
    set_transient($cache_key, $output, 2 * HOUR_IN_SECONDS);
    return $output;
}
add_shortcode('related_products_by_category', 'related_products_by_category_shortcode');

/*
function load_more_related_products() {
    $product_id = intval($_POST['product_id']);
    $offset     = intval($_POST['offset']);
    $exclude    = isset($_POST['exclude']) ? array_map('intval', $_POST['exclude']) : [];

    $categories = get_the_terms($product_id, 'product_cat');
    if (empty($categories)) wp_die();

    $deepest_cat = null; $max_depth = -1;
    foreach ($categories as $cat) {
        $depth = 0; $p = $cat->parent;
        while ($p > 0) { $depth++; $p = get_term($p, 'product_cat')->parent; }
        if ($depth > $max_depth) { $max_depth = $depth; $deepest_cat = $cat; }
    }
    if (!$deepest_cat) $deepest_cat = $categories[0];

    $args = [
        'post_type'      => 'product',
        'posts_per_page' => 6,
        'offset'         => $offset,
        'orderby'        => 'rand',
        'post__not_in'   => array_merge([$product_id], $exclude),
        'tax_query'      => [[
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => $deepest_cat->term_id,
        ]]
    ];

    $related = new WP_Query($args);
    ob_start();
    if ($related->have_posts()) {
        while ($related->have_posts()) {
            $related->the_post();
            wc_get_template_part('content', 'product');
        }
    }
    wp_reset_postdata();

    echo ob_get_clean();
    wp_die();
}

add_action('wp_ajax_load_more_related_products', 'load_more_related_products');
add_action('wp_ajax_nopriv_load_more_related_products', 'load_more_related_products');


function enqueue_related_products_script() {
    if (is_product()) { // chỉ load trong trang chi tiết sản phẩm
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
                var offset = 12;
                var product_id = <?php echo get_the_ID(); ?>;
                var loadedIDs = $('.related-products .product').map(function(){ return $(this).attr('id').replace('post-', ''); }).get();
            
                $('#load-more-product').on('click', function() {
                    var btn = $(this);
                    btn.text('Đang tải...');
                    $.post(ajaxurl, {
                        action: 'load_more_related_products',
                        product_id: product_id,
                        offset: offset,
                        exclude: loadedIDs
                    }, function(response) {
                        if (response.trim().length > 0) {
                            $('.related-products').append(response);
                            var newIDs = $(response).map(function(){ return $(this).attr('id').replace('post-', ''); }).get();
                            loadedIDs = loadedIDs.concat(newIDs);
                            offset += 6;
                            btn.text('Xem thêm sản phẩm');
                        } else {
                            btn.hide();
                        }
                    });
                });
            });
        </script>

        <?php
    }
}
add_action('wp_footer', 'enqueue_related_products_script');

*/

// Kiểm tra nếu là khu vực quản trị và kiểu bài viết là post
function restrict_products_to_author($query) {
    // Chỉ áp dụng trong khu vực admin và với truy vấn chính
    if (is_admin() && $query->is_main_query() && $query->get('post_type') === 'product') {
        // Kiểm tra xem người dùng có vai trò nào cần áp dụng
        if (! current_user_can('manage_woocommerce')) { // Người dùng không phải quản trị viên
            // Lấy ID người dùng hiện tại
            $current_user_id = get_current_user_id();
            // Chỉ hiển thị sản phẩm của họ
            $query->set('author', $current_user_id);
        }
    }
}
add_action('pre_get_posts', 'restrict_products_to_author');

add_filter('woocommerce_get_recently_viewed_products', 'custom_recently_viewed_products', 10, 1);

function custom_recently_viewed_products($viewed_products) {
    // Tăng số lượng sản phẩm được lưu trữ
    if (isset($_COOKIE['woocommerce_recently_viewed'])) {
        $viewed_products = array_filter(explode('|', $_COOKIE['woocommerce_recently_viewed']));
        $viewed_products = array_reverse($viewed_products); // Đảo ngược để hiển thị sản phẩm mới nhất
        return $viewed_products;
    }
    return [];
}

add_action('template_redirect', 'custom_set_recently_viewed_cookie', 20);

function custom_set_recently_viewed_cookie() {
    if (is_singular('product')) {
        global $post;

        if (empty($_COOKIE['woocommerce_recently_viewed'])) {
            $viewed_products = [];
        } else {
            $viewed_products = explode('|', $_COOKIE['woocommerce_recently_viewed']);
        }

        // Thêm sản phẩm hiện tại vào danh sách
        $viewed_products[] = $post->ID;

        // Loại bỏ trùng lặp và cắt danh sách theo giới hạn mong muốn
        $viewed_products = array_unique($viewed_products);
        $viewed_products = array_slice($viewed_products, -30); // Tăng giới hạn lên 30 sản phẩm

        // Cập nhật cookie
        setcookie('woocommerce_recently_viewed', implode('|', $viewed_products), time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
    }
}

function product_detail_recently_viewed_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'limit' => 6, // Số sản phẩm hiển thị
        ),
        $atts,
        'recently_viewed'
    );
    $count = get_viewed_products_count();
    // Lấy danh sách sản phẩm đã xem từ cookie
    $viewed_products = !empty($_COOKIE['woocommerce_recently_viewed'])
        ? (array) explode('|', wp_unslash($_COOKIE['woocommerce_recently_viewed']))
        : array();

    // Lọc, đảo ngược thứ tự (mới nhất đầu tiên) và chỉ lấy số lượng theo limit
    $viewed_products = array_reverse(array_filter(array_map('absint', $viewed_products)));
    $viewed_products = array_slice($viewed_products, 0, $atts['limit']);

    if (empty($viewed_products)) {
        return;
    }

    // Thiết lập query để lấy thông tin sản phẩm đã xem
    $args = array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => count($viewed_products),
        'post__in'       => $viewed_products,
        'ignore_sticky_posts' => true,
        'no_found_rows'  => true,
        'orderby'        => 'post__in', // Sắp xếp theo thứ tự của danh sách sản phẩm đã xem
    );

    $query = new WP_Query($args);

    if (!$query->have_posts()) {
        return;
    }

    // Bắt đầu tạo HTML
    ob_start();

    echo '<aside class="widget woocommerce widget_recently_viewed_products">';
    echo '<span class="widget-title shop-sidebar">Sản phẩm vừa được xem (' . $count . ')</span>';
    echo '<div class="is-divider small"></div>';
    echo '<ul class="product_list_widget">';

    while ($query->have_posts()) {
        $query->the_post();
        global $product;

    ?>
        <li>
            <a href="<?php echo get_the_permalink(); ?>" class="product-link">
                <?php echo get_the_post_thumbnail(get_the_ID(), 'woocommerce_thumbnail', array('style' => 'max-width: 100%; height: auto;')); ?>
                <span class="product-title"><?php echo get_the_title(); ?></span>
            </a>
            <span class="woocommerce-Price-amount amount !tw-text-[#DC2626] tw-text-[18px] lg:tw-text-[20px]">
                <?php echo $product->get_price_html(); ?>
            </span>
        </li>
<?php
    }

    echo '</ul>';
    echo '<div id="redirect-product"><a href="/san-pham-da-xem" class="text-center !tw-flex !tw-items-center" style="display:flex;align-items:center;color:#0284C7;justify-content:center"><p style="margin:0">Xem thêm sản phẩm</p> <span class="dashicons dashicons-plus-alt2" ></span></a></div>';
    echo '</aside>';

    wp_reset_postdata();

    return ob_get_clean();
}

add_shortcode('product_recently_viewed', 'product_detail_recently_viewed_shortcode');


// function add_comments_to_product_description() {
//     if (is_product()) {
//         comments_template();
//     }
// }
// add_action('woocommerce_after_single_product_summary', 'add_comments_to_product_description', 15);


//Phần xóa dashicon hãy xóa đi nếu trang lỗi css
function remove_unused_styles() {
    if (!is_admin() && !is_user_logged_in()) {
        // Xóa Dashicons trên Frontend nếu không cần
        wp_deregister_style('dashicons');
    }
    
    if (!is_admin() && !is_singular() && !is_page('checkout') && !is_page('cart')) {
        // Chỉ xóa Flatsome và Frontend CSS trên trang không cần thiết
        wp_deregister_style('flatsome');
        wp_deregister_style('frontend');
    }
    
    // Chặn Google CDN styles nếu không dùng
    // wp_dequeue_style('styles__ltr');
}
add_action('wp_enqueue_scripts', 'remove_unused_styles', 100);

// resize ảnh tự động trên mobile
// function responsive_images_mobile($content) {
//     if (wp_is_mobile()) {
//         return preg_replace('/<img([^>]+)src=/', '<img$1srcset="image-small.jpg 600w, image-medium.jpg 1024w" sizes="(max-width: 600px) 100vw, 50vw" src=', $content);
//     }
//     return $content;
// }
// add_filter('the_content', 'responsive_images_mobile');

//Đổi nút "ADD TO CART" thành thành "Thêm vào giỏ hàng"
function change_add_to_cart_button_text( $text ) {
    return __('Thêm vào giỏ hàng', 'woocommerce');
}
add_filter( 'woocommerce_product_single_add_to_cart_text', 'change_add_to_cart_button_text' );

// Sửa đổi 'Cart' thành 'Giỏ hàng', 'Reviews' thành 'Đánh giá'
function change_woocommerce_cart_text($translated_text, $text, $domain) {
    if ($domain === 'woocommerce') {
        $translations = array(
            'Cart' => 'Giỏ hàng',
            'Reviews' => 'Đánh giá'
        );
        if (isset($translations[$text])) {
            return __($translations[$text], 'woocommerce');
        }
    }
    return $translated_text;
}
add_filter('gettext', 'change_woocommerce_cart_text', 30, 3);


// Đổi tên tab "Description" thành "Mô tả"
function change_description_tab_title($tabs) {
    if (isset($tabs['description'])) {
        $tabs['description']['title'] = __('Mô tả', 'woocommerce'); // Thay đổi tiêu đề tab "Description" thành "Mô tả"
    }
    return $tabs;
}

add_filter('woocommerce_product_tabs', 'change_description_tab_title');


/// code dua mo tả danh mục xuống dưới
add_action( 'wp', 'tu_move_wc_archive_description' );
function tu_move_wc_archive_description() {
    if ( is_archive() ) {
        remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
        remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );
        add_action( 'woocommerce_after_main_content', 'woocommerce_taxonomy_archive_description', 10 );
        add_action( 'woocommerce_after_main_content', 'woocommerce_product_archive_description', 10 );
    }
}

function add_id_column_category() {
    if ( ! is_admin() ) {
        return;
    }

    // Thêm cột ID
    add_filter( 'manage_edit-product_cat_columns', function ( $columns ) {
        $columns['category_id'] = 'ID';
        return $columns;
    });

    // Hiển thị ID
    add_filter( 'manage_product_cat_custom_column', function ( $content, $column_name, $term_id ) {
        if ( $column_name === 'category_id' ) {
            $content = $term_id;
        }
        return $content;
    }, 10, 3 );
}
add_action( 'admin_init', 'add_id_column_category' );
