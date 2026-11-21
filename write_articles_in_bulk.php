<?php

$autoload_paths = array(
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
);

$autoload_path = '';

foreach ($autoload_paths as $candidate_path) {
    if (is_file($candidate_path)) {
        $autoload_path = $candidate_path;
        break;
    }
}

if ($autoload_path === '') {
    http_response_code(500);
    echo 'Khong tim thay file autoload Composer.';
    exit;
}

require $autoload_path;

if (!is_file(__DIR__ . '/wp-load.php')) {
    http_response_code(500);
    echo 'Khong tim thay wp-load.php.';
    exit;
}

if (!defined('WP_DISABLE_FATAL_ERROR_HANDLER')) {
    define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
}

require __DIR__ . '/wp-load.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '1024M');

function hpt_bulk_writer_get_self_url() {
    $absolute_file = wp_normalize_path(__FILE__);
    $absolute_root = wp_normalize_path(ABSPATH);
    $relative_path = ltrim(str_replace($absolute_root, '', $absolute_file), '/');
    $url           = site_url('/' . $relative_path);
    $query_string  = isset($_SERVER['QUERY_STRING']) ? trim((string) $_SERVER['QUERY_STRING']) : '';

    if ($query_string !== '') {
        $url .= '?' . $query_string;
    }

    return $url;
}

function hpt_bulk_writer_render_login_required() {
    $login_url = wp_login_url(hpt_bulk_writer_get_self_url());
    $admin_url = admin_url();

    status_header(401);
    nocache_headers();
    ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dang nhap de su dung tool</title>
    <style>
        body{margin:0;font-family:"Segoe UI",Tahoma,sans-serif;background:#f4f7fb;color:#0f172a}
        .wrap{max-width:560px;margin:72px auto;padding:0 16px}
        .card{background:#fff;border:1px solid #dbe4ee;border-radius:18px;padding:28px;box-shadow:0 18px 40px rgba(15,23,42,.08)}
        h1{margin:0 0 12px;font-size:28px;line-height:1.2}
        p{margin:0 0 12px;line-height:1.6;color:#475569}
        .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px}
        .button{display:inline-flex;align-items:center;justify-content:center;padding:13px 18px;border-radius:12px;text-decoration:none;font-weight:700}
        .button-primary{background:#0f766e;color:#fff}
        .button-secondary{background:#eef2f7;color:#0f172a}
        code{background:#f8fafc;padding:2px 6px;border-radius:6px}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Can dang nhap quan tri</h1>
            <p>File nay la tool PHP chay truc tiep ngoai <code>wp-admin</code>, nen toi da doi sang luong dang nhap tay de tranh redirect loop cua <code>auth_redirect()</code>.</p>
            <p>Hay dang nhap bang tai khoan co quyen <code>manage_woocommerce</code>, sau do he thong se quay lai dung trang nay.</p>
            <div class="actions">
                <a class="button button-primary" href="<?php echo esc_url($login_url); ?>">Dang nhap WordPress</a>
                <a class="button button-secondary" href="<?php echo esc_url($admin_url); ?>">Mo wp-admin</a>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

function hpt_bulk_writer_enforce_access() {
    if (!is_user_logged_in()) {
        hpt_bulk_writer_render_login_required();
    }

    if (!current_user_can('manage_woocommerce')) {
        wp_die('Ban khong co quyen su dung tool nay.');
    }
}

hpt_bulk_writer_enforce_access();

function hpt_bulk_writer_normalize_text($value) {
    if (is_string($value)) {
        return trim($value);
    }

    if (is_numeric($value)) {
        return trim((string) $value);
    }

    return '';
}

function hpt_bulk_writer_has_value($value) {
    if (is_array($value)) {
        return !empty(array_filter($value, 'hpt_bulk_writer_has_value'));
    }

    if (is_bool($value)) {
        return true;
    }

    if (is_numeric($value)) {
        return true;
    }

    return trim((string) $value) !== '';
}

function hpt_bulk_writer_slugify($text) {
    $text = remove_accents((string) $text);
    $text = sanitize_title($text);

    return $text !== '' ? $text : 'san-pham';
}

function hpt_bulk_writer_split_pipe($raw) {
    $parts = array_map('trim', explode('|', (string) $raw));

    return array_values(array_filter($parts, static function ($part) {
        return $part !== '';
    }));
}

function hpt_bulk_writer_split_csv_names($raw) {
    $parts = array_map('trim', explode(',', (string) $raw));

    return array_values(array_filter($parts, static function ($part) {
        return $part !== '';
    }));
}

function hpt_bulk_writer_normalize_document_links($raw) {
    $raw   = str_replace(array("\r\n", "\r", "\n", '|', ';'), ',', (string) $raw);
    $parts = array_map('trim', explode(',', $raw));
    $links = array();

    foreach ($parts as $part) {
        $url = esc_url_raw($part, array('http', 'https'));

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

    return implode(',', $links);
}

function hpt_bulk_writer_normalize_header_key($value) {
    $value = hpt_bulk_writer_normalize_text($value);
    $value = remove_accents($value);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);

    return trim((string) $value, '_');
}

function hpt_bulk_writer_header_aliases() {
    return array(
        'name' => array('ten_san_pham', 'product_name', 'name'),
        'sku' => array('ma_san_pham', 'sku', 'product_sku'),
        'product_url' => array('duong_dan_san_pham', 'product_url', 'permalink', 'url_san_pham'),
        'date_created' => array('ngay_xuat_ban', 'publish_date', 'date_created', 'ngay_dang'),
        'status' => array('trang_thai_publish_draft_private_future', 'trang_thai', 'status'),
        'description' => array('thong_so_ky_thuat', 'mo_ta_dai', 'description'),
        'short_description' => array('mo_ta_ngan', 'short_description', 'excerpt'),
        'slug' => array('duong_dan_la_ma_san_pham', 'slug', 'product_slug'),
        'regular_price' => array('gia_ban_thuong', 'regular_price', 'price'),
        'stock_quantity' => array('so_luong_ton_kho', 'stock_quantity', 'qty', 'so_luong'),
        'stock_status' => array('trang_thai_con_hang', 'stock_status', 'tinh_trang_hang'),
        'category_ids' => array('danh_muc', 'danh_muc_san_pham', 'categories'),
        'image_urls' => array('anh_san_pham', 'image_urls', 'images', 'gallery'),
        'image_alt' => array('caption_anh', 'image_alt', 'alt', 'alt_text'),
        'rank_math_description' => array('meta_rank_math_description', 'rank_math_description', 'seo_description'),
        'rank_math_focus_keyword' => array('meta_focus_keyword', 'rank_math_focus_keyword', 'focus_keyword'),
        'overview' => array('tong_quan', 'overview'),
        'brand_name' => array('hang', 'thuong_hieu', 'brand'),
        'tag_names' => array('the_cach_nhau_1_dau_phay', 'tag_names', 'tags', 'the'),
        'dimension_image_url' => array('anh_kich_thuoc_link_anh_webp', 'anh_kich_thuoc', 'dimension_image_url'),
        'rank_math_title' => array('rank_math_title', 'meta_rank_math_title', 'seo_title'),
        'link_pdf_product' => array('link_pdf_product', 'link_pdf', 'link_tai_lieu', 'link_tai_lieu_pdf', 'tai_lieu_pdf', 'document_url'),
    );
}

function hpt_bulk_writer_resolve_header_field($header_name) {
    $normalized_header = hpt_bulk_writer_normalize_header_key($header_name);

    if ($normalized_header === '') {
        return '';
    }

    foreach (hpt_bulk_writer_header_aliases() as $field => $aliases) {
        if (in_array($normalized_header, $aliases, true)) {
            return $field;
        }
    }

    return '';
}

function hpt_bulk_writer_resolve_header_map($header_row) {
    $header_map = array();

    foreach ((array) $header_row as $column_key => $header_name) {
        $field = hpt_bulk_writer_resolve_header_field($header_name);

        if ($field !== '' && !isset($header_map[$field])) {
            $header_map[$field] = $column_key;
        }
    }

    if (empty($header_map['name']) || empty($header_map['sku'])) {
        return array();
    }

    return $header_map;
}

function hpt_bulk_writer_get_row_value($row, $header_map, $field, $legacy_key = '') {
    if (!empty($header_map[$field]) && array_key_exists($header_map[$field], (array) $row)) {
        return $row[$header_map[$field]];
    }

    if ($legacy_key !== '' && array_key_exists($legacy_key, (array) $row)) {
        return $row[$legacy_key];
    }

    return '';
}

function hpt_bulk_writer_extract_slug_from_url($url) {
    $url  = hpt_bulk_writer_normalize_text($url);
    $path = wp_parse_url($url, PHP_URL_PATH);

    if (!is_string($path) || $path === '') {
        return '';
    }

    $slug = basename(untrailingslashit($path));

    return sanitize_title($slug);
}

function hpt_bulk_writer_parse_stock_quantity($value) {
    $value = hpt_bulk_writer_normalize_text($value);

    if ($value === '') {
        return '';
    }

    $value = str_replace(' ', '', $value);
    $value = str_replace(',', '.', $value);

    if (!is_numeric($value)) {
        return '';
    }

    return (string) max(0, (int) round((float) $value));
}

function hpt_bulk_writer_parse_categories_legacy($input) {
    $input = hpt_bulk_writer_normalize_text($input);

    if ($input === '') {
        return array();
    }

    $parts = explode(',', $input);

    if (count($parts) !== 2) {
        return array();
    }

    $names = array_map('trim', explode('|', trim($parts[0])));
    $ids   = array_map('trim', explode('|', trim($parts[1])));

    if (count($names) !== count($ids)) {
        return array();
    }

    $categories = array();
    $total      = count($names);

    for ($index = 0; $index < $total; $index++) {
        $categories[] = array(
            'id'   => (int) $ids[$total - $index - 1],
            'name' => $names[$index],
            'slug' => hpt_bulk_writer_slugify($names[$index]),
        );
    }

    return $categories;
}

function hpt_bulk_writer_build_legacy_tags($raw_tags) {
    $tags = array();

    foreach (hpt_bulk_writer_split_csv_names($raw_tags) as $tag_name) {
        $tags[] = array(
            'name' => $tag_name,
            'slug' => hpt_bulk_writer_slugify($tag_name),
        );
    }

    return $tags;
}

function hpt_bulk_writer_build_legacy_images($image_urls_raw, $sku, $alt_text) {
    $images = array();

    foreach (hpt_bulk_writer_split_pipe($image_urls_raw) as $image_src) {
        $image_src      = hpt_bulk_writer_normalize_source_image_url($image_src);
        $attachment_id  = hpt_bulk_writer_find_existing_attachment_id_by_source_url($image_src);

        if ($attachment_id > 0) {
            if (get_post_meta($attachment_id, '_hpt_source_image_url', true) !== $image_src) {
                update_post_meta($attachment_id, '_hpt_source_image_url', $image_src);
            }

            $images[] = array(
                'id' => $attachment_id,
            );
            continue;
        }

        $images[] = array(
            'src'  => $image_src,
            'name' => $sku . ' – Giải pháp hiệu quả, chính hãng, đến từ Hải Phòng Tech.',
            'alt'  => $alt_text,
        );
    }

    return $images;
}

function hpt_bulk_writer_build_legacy_dimension_markup($dimension_image_raw, $slug) {
    $first_webp = '';

    if (hpt_bulk_writer_normalize_text($dimension_image_raw) !== '') {
        $first_image = explode('|', (string) $dimension_image_raw);
        $first_image = trim((string) ($first_image[0] ?? ''));

        if ($first_image !== '') {
            $first_webp = preg_replace('/\.\w+$/', '.webp', $first_image);
        }
    }

    if ($first_webp === '') {
        return '';
    }

    return '<caption><img src="' . esc_url_raw($first_webp) . '" alt="' . esc_attr($slug . ' Hải Phòng Tech') . '" width="900" height="400" /></caption>';
}

function hpt_bulk_writer_get_brand_taxonomy() {
    if (function_exists('hpt_get_brand_filter_taxonomy')) {
        $taxonomy = hpt_get_brand_filter_taxonomy();

        if ($taxonomy && taxonomy_exists($taxonomy)) {
            return $taxonomy;
        }
    }

    if (taxonomy_exists('thuong_hieu')) {
        return 'thuong_hieu';
    }

    if (taxonomy_exists('product_brand')) {
        return 'product_brand';
    }

    return '';
}

function hpt_bulk_writer_resolve_or_create_term_id($name, $taxonomy) {
    static $cache = array();

    $name     = hpt_bulk_writer_normalize_text($name);
    $taxonomy = sanitize_key($taxonomy);

    if ($name === '' || $taxonomy === '' || !taxonomy_exists($taxonomy)) {
        return 0;
    }

    $cache_key = $taxonomy . '|' . mb_strtolower($name);

    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }

    $term = get_term_by('name', $name, $taxonomy);

    if (!$term || is_wp_error($term)) {
        $term = get_term_by('slug', sanitize_title($name), $taxonomy);
    }

    if ($term && !is_wp_error($term)) {
        $cache[$cache_key] = (int) $term->term_id;
        return $cache[$cache_key];
    }

    $inserted = wp_insert_term($name, $taxonomy);

    if (is_wp_error($inserted) || empty($inserted['term_id'])) {
        $cache[$cache_key] = 0;
        return 0;
    }

    $cache[$cache_key] = (int) $inserted['term_id'];

    return $cache[$cache_key];
}

function hpt_bulk_writer_extract_category_ids($raw) {
    $raw = hpt_bulk_writer_normalize_text($raw);

    if ($raw === '') {
        return array();
    }

    $ids       = array();
    $last_comma = strrpos($raw, ',');

    if (false !== $last_comma) {
        $ids_part = trim(substr($raw, $last_comma + 1));

        if (preg_match('/^[0-9|\s]+$/', $ids_part)) {
            $ids = array_map('absint', hpt_bulk_writer_split_pipe($ids_part));
        }
    } elseif (preg_match('/^[0-9|\s]+$/', $raw)) {
        $ids = array_map('absint', hpt_bulk_writer_split_pipe($raw));
    }

    $ids = array_values(array_unique(array_filter($ids)));

    if (!empty($ids)) {
        return $ids;
    }

    $names_part = false !== $last_comma ? trim(substr($raw, 0, $last_comma)) : $raw;
    $names      = hpt_bulk_writer_split_pipe($names_part);
    $resolved   = array();

    foreach ($names as $name) {
        $term_id = hpt_bulk_writer_resolve_or_create_term_id($name, 'product_cat');

        if ($term_id > 0) {
            $resolved[] = $term_id;
        }
    }

    return array_values(array_unique(array_filter($resolved)));
}

function hpt_bulk_writer_build_dimension_markup($primary_image_raw, $gallery_image_raw, $alt_base_raw, $name_raw) {
    $primary_urls = hpt_bulk_writer_split_pipe($primary_image_raw);
    $image_urls   = hpt_bulk_writer_split_pipe($gallery_image_raw);
    $source_url   = '';

    if (!empty($primary_urls)) {
        $source_url = $primary_urls[0];
    } elseif (!empty($image_urls)) {
        $source_url = $image_urls[0];
    }

    if ($source_url === '') {
        return '';
    }

    if (!preg_match('/\.webp(?:$|\?)/i', $source_url)) {
        $source_url = preg_replace('/\.(jpe?g|png|gif|bmp|svg)(\?.*)?$/i', '.webp$2', $source_url);
    }

    $alt_base = hpt_bulk_writer_normalize_text($alt_base_raw);

    if ($alt_base === '') {
        $alt_base = hpt_bulk_writer_normalize_text($name_raw);
    }

    $alt_text = trim($alt_base . ' Hải Phòng Tech');

    return sprintf(
        '<img src="%s" alt="%s" width="900" height="400" />',
        esc_url_raw($source_url),
        esc_attr($alt_text)
    );
}

function hpt_bulk_writer_parse_row($row_number, $row, $header_map = array()) {
    $sku         = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'sku', 'B'));
    $name        = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'name', 'A'));
    $product_url = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'product_url', 'C'));

    if ($sku === '') {
        return array(
            'skip'    => true,
            'row_number' => (int) $row_number,
            'sku'     => '',
            'message' => 'Dòng thiếu SKU.',
        );
    }

    if ($name === '') {
        return array(
            'skip'    => true,
            'row_number' => (int) $row_number,
            'sku'     => $sku,
            'message' => 'Dòng thiếu tên sản phẩm.',
        );
    }

    $slug = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'slug', 'H'));

    if ($slug === '' && $product_url !== '') {
        $slug = hpt_bulk_writer_extract_slug_from_url($product_url);
    }

    if ($slug === '') {
        $slug = hpt_bulk_writer_slugify($name);
    }

    $image_urls_raw       = hpt_bulk_writer_get_row_value($row, $header_map, 'image_urls', 'M');
    $dimension_image_raw  = hpt_bulk_writer_get_row_value($row, $header_map, 'dimension_image_url', 'T');
    $image_alt            = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'image_alt', 'N'));
    $rank_math_title      = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'rank_math_title', 'U'));
    $link_pdf_product     = hpt_bulk_writer_normalize_document_links(hpt_bulk_writer_get_row_value($row, $header_map, 'link_pdf_product', 'V'));
    $image_urls           = hpt_bulk_writer_split_pipe($image_urls_raw);
    $stock_quantity       = hpt_bulk_writer_parse_stock_quantity(hpt_bulk_writer_get_row_value($row, $header_map, 'stock_quantity', 'J'));

    return array(
        'skip'               => false,
        'row_number'         => (int) $row_number,
        'name'               => $name,
        'sku'                => $sku,
        'product_url'        => $product_url,
        'slug'               => $slug,
        'status'             => hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'status', 'E')),
        'description'        => (string) hpt_bulk_writer_get_row_value($row, $header_map, 'description', 'F'),
        'short_description'  => (string) hpt_bulk_writer_get_row_value($row, $header_map, 'short_description', 'G'),
        'regular_price'      => hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'regular_price', 'I')),
        'stock_quantity'     => $stock_quantity,
        'stock_status'       => hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'stock_status', 'K')),
        'category_ids'       => hpt_bulk_writer_extract_category_ids(hpt_bulk_writer_get_row_value($row, $header_map, 'category_ids', 'L')),
        'tag_names'          => hpt_bulk_writer_split_csv_names(hpt_bulk_writer_get_row_value($row, $header_map, 'tag_names', 'S')),
        'brand_name'         => hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'brand_name', 'R')),
        'image_urls'         => $image_urls,
        'image_alt'          => $image_alt,
        'rank_math_description' => hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'rank_math_description', 'O')),
        'rank_math_focus_keyword' => hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'rank_math_focus_keyword', 'P')),
        'overview'           => (string) hpt_bulk_writer_get_row_value($row, $header_map, 'overview', 'Q'),
        'rank_math_title'    => $rank_math_title,
        'link_pdf_product'   => $link_pdf_product,
        'dimension_markup'   => hpt_bulk_writer_build_dimension_markup($dimension_image_raw, $image_urls_raw, $image_alt !== '' ? $image_alt : $slug, $name),
        'date_created'       => hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'date_created', 'D')),
    );
}

function hpt_bulk_writer_parse_row_legacy($row_number, $row, $header_map = array()) {
    $sku             = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'sku', 'B'));
    $name            = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'name', 'A'));
    $product_url     = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'product_url', 'C'));
    $date_created    = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'date_created', 'D'));
    $status          = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'status', 'E'));
    $description     = (string) hpt_bulk_writer_get_row_value($row, $header_map, 'description', 'F');
    $short_desc_raw  = (string) hpt_bulk_writer_get_row_value($row, $header_map, 'short_description', 'G');
    $slug            = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'slug', 'H'));
    $regular_price   = hpt_bulk_writer_get_row_value($row, $header_map, 'regular_price', 'I');
    $stock_status    = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'stock_status', 'K'));
    $category_raw    = hpt_bulk_writer_get_row_value($row, $header_map, 'category_ids', 'L');
    $image_urls_raw  = hpt_bulk_writer_get_row_value($row, $header_map, 'image_urls', 'M');
    $image_alt       = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'image_alt', 'N'));
    $seo_desc        = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'rank_math_description', 'O'));
    $focus_keyword   = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'rank_math_focus_keyword', 'P'));
    $overview        = (string) hpt_bulk_writer_get_row_value($row, $header_map, 'overview', 'Q');
    $brand_name      = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'brand_name', 'R'));
    $tags_raw        = hpt_bulk_writer_get_row_value($row, $header_map, 'tag_names', 'S');
    $dimension_raw   = hpt_bulk_writer_get_row_value($row, $header_map, 'dimension_image_url', 'T');
    $rank_math_title = hpt_bulk_writer_normalize_text(hpt_bulk_writer_get_row_value($row, $header_map, 'rank_math_title', 'U'));
    $link_pdf_product = hpt_bulk_writer_normalize_document_links(hpt_bulk_writer_get_row_value($row, $header_map, 'link_pdf_product', 'V'));

    if ($sku === '') {
        return array(
            'skip'       => true,
            'row_number' => (int) $row_number,
            'sku'        => '',
            'message'    => 'Dòng thiếu SKU.',
        );
    }

    if ($name === '') {
        return array(
            'skip'       => true,
            'row_number' => (int) $row_number,
            'sku'        => $sku,
            'message'    => 'Dòng thiếu tên sản phẩm.',
        );
    }

    $slug = $slug !== '' ? $slug : hpt_bulk_writer_slugify($name);
    $images = hpt_bulk_writer_build_legacy_images($image_urls_raw, $sku, $image_alt);
    $categories = hpt_bulk_writer_parse_categories_legacy($category_raw);
    $tags = hpt_bulk_writer_build_legacy_tags($tags_raw);

    $payload = array(
        'name'               => $name,
        'slug'               => $slug,
        'brands'             => array(
            array(
                'name' => $brand_name,
                'slug' => hpt_bulk_writer_slugify($brand_name),
            ),
        ),
        'permalink'          => $product_url,
        'date_created'       => $date_created,
        'status'             => $status !== '' ? $status : 'private',
        'description'        => $description,
        'short_description'  => nl2br($short_desc_raw),
        'sku'                => $sku,
        'regular_price'      => strval($regular_price !== null && $regular_price !== '' ? $regular_price : 0),
        'stock_quantity'     => null,
        'categories'         => $categories,
        'tags'               => $tags,
        'type'               => 'simple',
        'featured'           => false,
        'catalog_visibility' => 'visible',
        'images'             => $images,
        'meta_data'          => array(
            array(
                'key'   => 'rank_math_description',
                'value' => $seo_desc,
            ),
            array(
                'key'   => 'dimension',
                'value' => hpt_bulk_writer_build_legacy_dimension_markup($dimension_raw, $slug),
            ),
            array(
                'key'   => '_dimension',
                'value' => 'field_67da3f1089dc1',
            ),
            array(
                'key'   => 'overview',
                'value' => $overview,
            ),
            array(
                'key'   => '_overview',
                'value' => 'field_673b4b5310165',
            ),
            array(
                'key'   => 'link_pdf_product',
                'value' => $link_pdf_product,
            ),
            array(
                'key'   => '_link_pdf_product',
                'value' => 'field_66c749b6c8692',
            ),
            array(
                'key'   => 'rank_math_focus_keyword',
                'value' => $focus_keyword,
            ),
            array(
                'key'   => '_rank_math_focus_keyword',
                'value' => $focus_keyword,
            ),
            array(
                'key'   => 'rank_math_title',
                'value' => $rank_math_title,
            ),
        ),
        'stock_status'       => $stock_status !== '' ? $stock_status : 'instock',
        'manage_stock'       => false,
        'purchasable'        => true,
    );

    return array(
        'skip'       => false,
        'row_number' => (int) $row_number,
        'sku'        => $sku,
        'payload'    => $payload,
    );
}

function hpt_bulk_writer_normalize_product_status($status, $default = 'private') {
    $status  = sanitize_key($status);
    $allowed = array('publish', 'draft', 'pending', 'private', 'future');

    if (in_array($status, $allowed, true)) {
        return $status;
    }

    return $default;
}

function hpt_bulk_writer_normalize_stock_status($status) {
    $status  = sanitize_key($status);
    $allowed = array('instock', 'outofstock', 'onbackorder');

    if (in_array($status, $allowed, true)) {
        return $status;
    }

    return '';
}

function hpt_bulk_writer_should_write_value($value, $is_existing, $preserve_on_empty) {
    if (!$is_existing) {
        return true;
    }

    if (!$preserve_on_empty) {
        return true;
    }

    return hpt_bulk_writer_has_value($value);
}

function hpt_bulk_writer_maybe_set_product_prop($product, $prop, $value, $is_existing, $preserve_on_empty) {
    $setter = 'set_' . $prop;

    if (!method_exists($product, $setter) || !hpt_bulk_writer_should_write_value($value, $is_existing, $preserve_on_empty)) {
        return;
    }

    $product->{$setter}($value);
}

function hpt_bulk_writer_maybe_update_post_meta($product_id, $meta_key, $value, $is_existing, $preserve_on_empty) {
    if (!hpt_bulk_writer_should_write_value($value, $is_existing, $preserve_on_empty)) {
        return;
    }

    update_post_meta($product_id, $meta_key, $value);
}

function hpt_bulk_writer_maybe_update_acf_field($product_id, $field_name, $field_key, $value, $is_existing, $preserve_on_empty) {
    if (!hpt_bulk_writer_should_write_value($value, $is_existing, $preserve_on_empty)) {
        return;
    }

    if (function_exists('update_field')) {
        update_field($field_key ?: $field_name, $value, $product_id);
        return;
    }

    update_post_meta($product_id, $field_name, $value);

    if ($field_key !== '') {
        update_post_meta($product_id, '_' . $field_name, $field_key);
    }
}

function hpt_bulk_writer_apply_taxonomies($product_id, $mapped_row, $is_existing, $options) {
    $warnings = array();

    $preserve_on_empty = !empty($options['preserve_on_empty']);
    $category_ids      = array_map('absint', (array) ($mapped_row['category_ids'] ?? array()));
    $tag_names         = array_values(array_unique((array) ($mapped_row['tag_names'] ?? array())));
    $brand_name        = hpt_bulk_writer_normalize_text($mapped_row['brand_name'] ?? '');

    if (!$is_existing || !empty($category_ids) || !$preserve_on_empty) {
        $category_result = wp_set_post_terms($product_id, $category_ids, 'product_cat', false);

        if (is_wp_error($category_result)) {
            $warnings[] = 'Danh mục: ' . $category_result->get_error_message();
        }
    }

    if (!$is_existing || !empty($tag_names) || !$preserve_on_empty) {
        $tag_result = wp_set_post_terms($product_id, $tag_names, 'product_tag', false);

        if (is_wp_error($tag_result)) {
            $warnings[] = 'Tag: ' . $tag_result->get_error_message();
        }
    }

    $brand_taxonomy = hpt_bulk_writer_get_brand_taxonomy();

    if ($brand_taxonomy !== '' && (!$is_existing || $brand_name !== '' || !$preserve_on_empty)) {
        $brand_ids = array();

        if ($brand_name !== '') {
            $brand_id = hpt_bulk_writer_resolve_or_create_term_id($brand_name, $brand_taxonomy);

            if ($brand_id > 0) {
                $brand_ids[] = $brand_id;
            } else {
                $warnings[] = 'Khong resolve duoc thuong hieu "' . $brand_name . '".';
            }
        }

        $brand_result = wp_set_object_terms($product_id, $brand_ids, $brand_taxonomy, false);

        if (is_wp_error($brand_result)) {
            $warnings[] = 'Thuong hieu: ' . $brand_result->get_error_message();
        }
    }

    return $warnings;
}

function hpt_bulk_writer_prepare_media_import() {
    static $loaded = false;

    if ($loaded) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $loaded = true;
}

function hpt_bulk_writer_normalize_source_image_url($image_url) {
    $image_url = html_entity_decode(trim((string) $image_url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return $image_url;
}

function hpt_bulk_writer_get_upload_relative_path_from_url($image_url) {
    $image_url = hpt_bulk_writer_normalize_source_image_url($image_url);

    if ($image_url === '') {
        return '';
    }

    $upload_dir = wp_upload_dir();
    $url_path   = wp_parse_url($image_url, PHP_URL_PATH);
    $base_path  = wp_parse_url($upload_dir['baseurl'], PHP_URL_PATH);

    if (!is_string($url_path) || $url_path === '') {
        return '';
    }

    $url_path  = rawurldecode(wp_normalize_path($url_path));
    $base_path = is_string($base_path) ? rawurldecode(untrailingslashit(wp_normalize_path($base_path))) : '';

    if ($base_path !== '' && strpos($url_path, $base_path . '/') === 0) {
        return ltrim(substr($url_path, strlen($base_path) + 1), '/');
    }

    $uploads_marker = '/wp-content/uploads/';
    $marker_pos     = strpos($url_path, $uploads_marker);

    if (false !== $marker_pos) {
        return ltrim(substr($url_path, $marker_pos + strlen($uploads_marker)), '/');
    }

    return '';
}

function hpt_bulk_writer_find_attachment_id_by_attached_file($relative_path) {
    $relative_path = ltrim(wp_normalize_path((string) $relative_path), '/');

    if ($relative_path === '') {
        return 0;
    }

    $attachments = get_posts(array(
        'post_type'              => 'attachment',
        'post_status'            => 'inherit',
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query'             => array(
            array(
                'key'   => '_wp_attached_file',
                'value' => $relative_path,
            ),
        ),
    ));

    return !empty($attachments) ? (int) $attachments[0] : 0;
}

function hpt_bulk_writer_find_attachment_id_by_unique_basename($file_name) {
    global $wpdb;

    $file_name = sanitize_file_name((string) $file_name);

    if ($file_name === '') {
        return 0;
    }

    $like = '%/' . $wpdb->esc_like($file_name);

    $post_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_wp_attached_file'
            AND (meta_value = %s OR meta_value LIKE %s)
        ORDER BY post_id ASC
        LIMIT 2",
        $file_name,
        $like
    ));

    if (count($post_ids) !== 1) {
        return 0;
    }

    return (int) $post_ids[0];
}

function hpt_bulk_writer_find_existing_attachment_id_by_source_url($image_url) {
    static $cache = array();

    $normalized_url = hpt_bulk_writer_normalize_source_image_url($image_url);

    if ($normalized_url === '') {
        return 0;
    }

    if (array_key_exists($normalized_url, $cache)) {
        return $cache[$normalized_url];
    }

    $candidate_urls = array_values(array_unique(array_filter(array(
        $normalized_url,
        rawurldecode($normalized_url),
        set_url_scheme($normalized_url, 'http'),
        set_url_scheme($normalized_url, 'https'),
    ))));

    foreach ($candidate_urls as $candidate_url) {
        $attachment_id = (int) attachment_url_to_postid($candidate_url);

        if ($attachment_id > 0) {
            $cache[$normalized_url] = $attachment_id;
            return $attachment_id;
        }
    }

    foreach ($candidate_urls as $candidate_url) {
        $attachments = get_posts(array(
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array(
                    'key'   => '_hpt_source_image_url',
                    'value' => $candidate_url,
                ),
            ),
        ));

        if (!empty($attachments)) {
            $cache[$normalized_url] = (int) $attachments[0];
            return $cache[$normalized_url];
        }
    }

    $relative_path = hpt_bulk_writer_get_upload_relative_path_from_url($normalized_url);

    if ($relative_path !== '') {
        $attachment_id = hpt_bulk_writer_find_attachment_id_by_attached_file($relative_path);

        if ($attachment_id > 0) {
            $cache[$normalized_url] = $attachment_id;
            return $attachment_id;
        }
    }

    $file_name = wp_basename((string) wp_parse_url($normalized_url, PHP_URL_PATH));

    if ($file_name !== '') {
        $attachment_id = hpt_bulk_writer_find_attachment_id_by_unique_basename($file_name);

        if ($attachment_id > 0) {
            $cache[$normalized_url] = $attachment_id;
            return $attachment_id;
        }
    }

    $cache[$normalized_url] = 0;

    return $cache[$normalized_url];
}

function hpt_bulk_writer_import_images($product_id, $image_urls, $alt_text, $title_text) {
    static $url_cache = array();

    $warnings  = array();
    $image_ids = array();
    $image_urls = array_values(array_unique(array_filter(array_map('trim', (array) $image_urls))));

    if (empty($image_urls)) {
        return array($image_ids, $warnings);
    }

    hpt_bulk_writer_prepare_media_import();

    foreach ($image_urls as $image_url) {
        $image_url = hpt_bulk_writer_normalize_source_image_url($image_url);

        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            $warnings[] = 'URL anh khong hop le: ' . $image_url;
            continue;
        }

        if (array_key_exists($image_url, $url_cache) && $url_cache[$image_url] > 0) {
            $image_ids[] = $url_cache[$image_url];
            continue;
        }

        $attachment_id = hpt_bulk_writer_find_existing_attachment_id_by_source_url($image_url);

        if (!$attachment_id) {
            $attachment_id = media_sideload_image($image_url, $product_id, null, 'id');
        }

        if (is_wp_error($attachment_id)) {
            $warnings[] = 'Anh "' . $image_url . '": ' . $attachment_id->get_error_message();
            $url_cache[$image_url] = 0;
            continue;
        }

        $attachment_id = (int) $attachment_id;
        $url_cache[$image_url] = $attachment_id;

        if ($attachment_id <= 0) {
            $warnings[] = 'Khong import duoc anh: ' . $image_url;
            continue;
        }

        if (get_post_meta($attachment_id, '_hpt_source_image_url', true) !== $image_url) {
            update_post_meta($attachment_id, '_hpt_source_image_url', $image_url);
        }

        $attachment_update = array('ID' => $attachment_id);
        $needs_update      = false;
        $normalized_title  = hpt_bulk_writer_normalize_text($title_text);

        if ($normalized_title !== '') {
            $attachment = get_post($attachment_id);

            if ($attachment && $attachment->post_title !== $normalized_title) {
                $attachment_update['post_title'] = $normalized_title;
                $needs_update = true;
            }
        }

        if ($needs_update) {
            wp_update_post($attachment_update);
        }

        $normalized_alt = hpt_bulk_writer_normalize_text($alt_text);

        if ($normalized_alt !== '' && get_post_meta($attachment_id, '_wp_attachment_image_alt', true) !== $normalized_alt) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $normalized_alt);
        }

        $image_ids[] = $attachment_id;
    }

    return array(array_values(array_unique(array_filter($image_ids))), $warnings);
}

function hpt_bulk_writer_apply_product_date($product_id, $date_created) {
    $date_created = hpt_bulk_writer_normalize_text($date_created);

    if ($date_created === '') {
        return '';
    }

    $timestamp = strtotime($date_created);

    if (!$timestamp) {
        return 'Khong parse duoc ngay tao "' . $date_created . '".';
    }

    $post_update = array(
        'ID'            => $product_id,
        'post_date'     => wp_date('Y-m-d H:i:s', $timestamp),
        'post_date_gmt' => gmdate('Y-m-d H:i:s', $timestamp),
    );

    $result = wp_update_post($post_update, true);

    if (is_wp_error($result)) {
        return $result->get_error_message();
    }

    return '';
}

function hpt_bulk_writer_cache_product_id_by_sku($sku, $product_id) {
    $sku        = hpt_bulk_writer_normalize_text($sku);
    $product_id = (int) $product_id;

    if (!isset($GLOBALS['hpt_bulk_writer_sku_lookup_cache']) || !is_array($GLOBALS['hpt_bulk_writer_sku_lookup_cache'])) {
        $GLOBALS['hpt_bulk_writer_sku_lookup_cache'] = array();
    }

    if ($sku === '') {
        return;
    }

    $GLOBALS['hpt_bulk_writer_sku_lookup_cache'][$sku] = $product_id > 0 ? $product_id : 0;
}

function hpt_bulk_writer_get_product_id_by_sku($sku) {
    global $wpdb;

    $sku = hpt_bulk_writer_normalize_text($sku);

    if ($sku === '') {
        return 0;
    }

    if (!isset($GLOBALS['hpt_bulk_writer_sku_lookup_cache']) || !is_array($GLOBALS['hpt_bulk_writer_sku_lookup_cache'])) {
        $GLOBALS['hpt_bulk_writer_sku_lookup_cache'] = array();
    }

    if (array_key_exists($sku, $GLOBALS['hpt_bulk_writer_sku_lookup_cache'])) {
        return (int) $GLOBALS['hpt_bulk_writer_sku_lookup_cache'][$sku];
    }

    $product_id = (int) wc_get_product_id_by_sku($sku);

    if ($product_id <= 0) {
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT posts.ID
            FROM {$wpdb->posts} AS posts
            INNER JOIN {$wpdb->postmeta} AS postmeta ON postmeta.post_id = posts.ID
            WHERE postmeta.meta_key = '_sku'
                AND postmeta.meta_value = %s
                AND posts.post_type IN ('product', 'product_variation')
                AND posts.post_status NOT IN ('trash', 'auto-draft')
            ORDER BY CASE posts.post_type WHEN 'product' THEN 0 ELSE 1 END, posts.ID ASC
            LIMIT 5",
            $sku
        ));

        if (count($product_ids) > 1) {
            hpt_bulk_writer_write_debug_log('warning', 'Phat hien nhieu product trung SKU khi fallback lookup', array(
                'sku'         => $sku,
                'product_ids' => array_map('intval', $product_ids),
            ));
        }

        $product_id = !empty($product_ids) ? (int) $product_ids[0] : 0;
    }

    hpt_bulk_writer_cache_product_id_by_sku($sku, $product_id);

    return $product_id;
}

function hpt_bulk_writer_upsert_product($mapped_row, $options) {
    $sku             = $mapped_row['sku'];
    $row_number      = isset($mapped_row['row_number']) ? (int) $mapped_row['row_number'] : 0;
    $existing_id     = hpt_bulk_writer_get_product_id_by_sku($sku);
    $update_existing = !empty($options['update_existing']);
    $create_missing  = !empty($options['create_missing']);
    $import_images   = !empty($options['import_images']);
    $preserve_on_empty = !empty($options['preserve_on_empty']);

    if ($existing_id > 0 && !$update_existing) {
        return array(
            'status'     => 'skipped',
            'action'     => 'bo qua',
            'row_number' => $row_number,
            'sku'        => $sku,
            'product_id' => $existing_id,
            'message'    => 'San pham da ton tai va tuy chon cap nhat dang tat.',
        );
    }

    if ($existing_id <= 0 && !$create_missing) {
        return array(
            'status'     => 'skipped',
            'action'     => 'bo qua',
            'row_number' => $row_number,
            'sku'        => $sku,
            'product_id' => 0,
            'message'    => 'SKU chua ton tai va tuy chon tao moi dang tat.',
        );
    }

    if ($existing_id > 0 && get_post_type($existing_id) === 'product_variation') {
        return array(
            'status'     => 'error',
            'action'     => 'loi',
            'row_number' => $row_number,
            'sku'        => $sku,
            'product_id' => $existing_id,
            'message'    => 'SKU dang thuoc product variation, tool nay chi xu ly san pham cha.',
        );
    }

    $product = $existing_id > 0 ? wc_get_product($existing_id) : new WC_Product_Simple();

    if (!$product || !is_a($product, 'WC_Product')) {
        return array(
            'status'     => 'error',
            'action'     => 'loi',
            'row_number' => $row_number,
            'sku'        => $sku,
            'product_id' => $existing_id,
            'message'    => 'Khong khoi tao duoc doi tuong san pham WooCommerce.',
        );
    }

    $is_existing = $existing_id > 0;
    $action      = $is_existing ? 'cap nhat' : 'tao moi';
    $warnings    = array();

    hpt_bulk_writer_maybe_set_product_prop($product, 'name', $mapped_row['name'], $is_existing, $preserve_on_empty);
    hpt_bulk_writer_maybe_set_product_prop($product, 'slug', $mapped_row['slug'], $is_existing, $preserve_on_empty);
    hpt_bulk_writer_maybe_set_product_prop($product, 'description', $mapped_row['description'], $is_existing, $preserve_on_empty);

    $short_description = hpt_bulk_writer_normalize_text($mapped_row['short_description']);
    $short_description = $short_description !== '' ? nl2br($short_description) : '';
    hpt_bulk_writer_maybe_set_product_prop($product, 'short_description', $short_description, $is_existing, $preserve_on_empty);

    if (!$is_existing || !$preserve_on_empty || hpt_bulk_writer_has_value($mapped_row['status'])) {
        $product->set_status(
            hpt_bulk_writer_normalize_product_status(
                $mapped_row['status'],
                $is_existing ? $product->get_status() : 'private'
            )
        );
    }

    if (!$is_existing || !$preserve_on_empty || hpt_bulk_writer_has_value($mapped_row['regular_price'])) {
        $product->set_regular_price((string) $mapped_row['regular_price']);
    }

    $stock_quantity = hpt_bulk_writer_parse_stock_quantity($mapped_row['stock_quantity'] ?? '');

    if ($stock_quantity !== '') {
        $product->set_manage_stock(true);
        $product->set_stock_quantity((int) $stock_quantity);
    } elseif (!$is_existing) {
        $product->set_manage_stock(false);
    } elseif (!$preserve_on_empty) {
        $product->set_manage_stock(false);
        $product->set_stock_quantity(null);
    }

    $stock_status = hpt_bulk_writer_normalize_stock_status($mapped_row['stock_status']);

    if ($stock_status !== '' || !$is_existing || !$preserve_on_empty) {
        $product->set_stock_status($stock_status !== '' ? $stock_status : 'instock');
    }

    if (!$is_existing) {
        $product->set_catalog_visibility('visible');
        $product->set_featured(false);
        $product->set_manage_stock(false);
        $product->set_sku($sku);
    } elseif ($product->get_sku() !== $sku) {
        $product->set_sku($sku);
    }

    $product_id = $product->save();

    if (!$product_id) {
        return array(
            'status'     => 'error',
            'action'     => $action,
            'row_number' => $row_number,
            'sku'        => $sku,
            'product_id' => 0,
            'message'    => 'Luu san pham that bai, khong nhan duoc product ID.',
        );
    }

    hpt_bulk_writer_cache_product_id_by_sku($sku, $product_id);

    $warnings = array_merge($warnings, hpt_bulk_writer_apply_taxonomies($product_id, $mapped_row, $is_existing, $options));

    hpt_bulk_writer_maybe_update_post_meta(
        $product_id,
        'rank_math_description',
        $mapped_row['rank_math_description'],
        $is_existing,
        $preserve_on_empty
    );

    hpt_bulk_writer_maybe_update_post_meta(
        $product_id,
        'rank_math_focus_keyword',
        $mapped_row['rank_math_focus_keyword'],
        $is_existing,
        $preserve_on_empty
    );

    hpt_bulk_writer_maybe_update_post_meta(
        $product_id,
        'rank_math_title',
        $mapped_row['rank_math_title'],
        $is_existing,
        $preserve_on_empty
    );

    hpt_bulk_writer_maybe_update_acf_field(
        $product_id,
        'dimension',
        'field_67da3f1089dc1',
        $mapped_row['dimension_markup'],
        $is_existing,
        $preserve_on_empty
    );

    hpt_bulk_writer_maybe_update_acf_field(
        $product_id,
        'overview',
        'field_673b4b5310165',
        $mapped_row['overview'],
        $is_existing,
        $preserve_on_empty
    );

    hpt_bulk_writer_maybe_update_acf_field(
        $product_id,
        'link_pdf_product',
        'field_66c749b6c8692',
        $mapped_row['link_pdf_product'],
        $is_existing,
        $preserve_on_empty
    );

    if (!$is_existing) {
        $date_warning = hpt_bulk_writer_apply_product_date($product_id, $mapped_row['date_created']);

        if ($date_warning !== '') {
            $warnings[] = 'Ngay tao: ' . $date_warning;
        }
    }

    if ($import_images && !empty($mapped_row['image_urls'])) {
        list($image_ids, $image_warnings) = hpt_bulk_writer_import_images(
            $product_id,
            $mapped_row['image_urls'],
            $mapped_row['image_alt'] !== '' ? $mapped_row['image_alt'] : $mapped_row['name'],
            $mapped_row['sku'] . ' - Giai phap hieu qua, chinh hang, den tu Hai Phong Tech.'
        );

        $warnings = array_merge($warnings, $image_warnings);

        if (!empty($image_ids)) {
            $product = wc_get_product($product_id);

            if ($product && is_a($product, 'WC_Product')) {
                $product->set_image_id((int) $image_ids[0]);
                $product->set_gallery_image_ids(count($image_ids) > 1 ? array_slice($image_ids, 1) : array());

                $product->save();
            }
        }
    }

    if (function_exists('hpt_clear_product_detail_cache')) {
        hpt_clear_product_detail_cache($product_id);
    } else {
        clean_post_cache($product_id);

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }
    }

    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    return array(
        'status'     => empty($warnings) ? 'success' : 'warning',
        'action'     => $action,
        'row_number' => $row_number,
        'sku'        => $sku,
        'product_id' => $product_id,
        'message'    => empty($warnings)
            ? 'Xu ly thanh cong.'
            : implode(' | ', $warnings),
    );
}

function hpt_bulk_writer_build_report($results, $upload_name) {
    $spreadsheet = new Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->fromArray(
        array('STT', 'SKU', 'Ket qua', 'Hanh dong', 'Product ID', 'Chi tiet'),
        null,
        'A1'
    );

    $row_index = 2;

    foreach ($results as $index => $result) {
        $sheet->fromArray(
            array(
                $index + 1,
                $result['sku'],
                $result['status'],
                $result['action'],
                $result['product_id'],
                $result['message'],
            ),
            null,
            'A' . $row_index
        );
        $row_index++;
    }

    foreach (array('A', 'B', 'C', 'D', 'E', 'F') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $report_dir = __DIR__ . '/outputs';

    if (!is_dir($report_dir)) {
        wp_mkdir_p($report_dir);
    }

    $safe_name   = sanitize_title(pathinfo((string) $upload_name, PATHINFO_FILENAME));
    $report_name = ($safe_name !== '' ? $safe_name : 'bulk-report') . '-' . date('Y-m-d-H-i-s') . '.xlsx';
    $report_path = $report_dir . '/' . $report_name;

    $writer = new Xlsx($spreadsheet);
    $writer->save($report_path);

    return array(
        'path' => $report_path,
        'url'  => 'outputs/' . rawurlencode($report_name),
    );
}

function hpt_bulk_writer_get_request_nonce_action() {
    return 'hpt_bulk_writer_async_request';
}

function hpt_bulk_writer_get_request_options_from_input($input) {
    $input = is_array($input) ? $input : array();

    return array(
        'create_missing'    => !empty($input['create_missing']),
        'update_existing'   => !empty($input['update_existing']),
        'import_images'     => !empty($input['import_images']),
        'preserve_on_empty' => !empty($input['preserve_on_empty']),
    );
}

function hpt_bulk_writer_create_empty_summary() {
    return array(
        'success' => 0,
        'warning' => 0,
        'skipped' => 0,
        'error'   => 0,
    );
}

function hpt_bulk_writer_merge_summary(&$summary, $source) {
    foreach (hpt_bulk_writer_create_empty_summary() as $status => $count) {
        if (!isset($summary[$status])) {
            $summary[$status] = 0;
        }

        $summary[$status] += (int) ($source[$status] ?? 0);
    }
}

function hpt_bulk_writer_disable_async_action_scheduler_runner($allow) {
    return false;
}

function hpt_bulk_writer_get_runtime_optimization_depth() {
    return (int) ($GLOBALS['hpt_bulk_writer_runtime_optimization_depth'] ?? 0);
}

function hpt_bulk_writer_remove_shutdown_callbacks_for_object_class($class_name) {
    global $wp_filter;

    if (empty($wp_filter['shutdown']) || !($wp_filter['shutdown'] instanceof WP_Hook)) {
        return;
    }

    foreach ($wp_filter['shutdown']->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $callback_entry) {
            $callback = $callback_entry['function'] ?? null;

            if (!($callback instanceof Closure)) {
                continue;
            }

            $reflection = new ReflectionFunction($callback);
            $object     = $reflection->getClosureThis();

            if (!is_object($object) || !is_a($object, $class_name)) {
                continue;
            }

            remove_action('shutdown', $callback, $priority);
        }
    }
}

function hpt_bulk_writer_begin_bulk_runtime_optimizations() {
    $depth = hpt_bulk_writer_get_runtime_optimization_depth() + 1;
    $GLOBALS['hpt_bulk_writer_runtime_optimization_depth'] = $depth;

    if ($depth > 1) {
        return;
    }

    add_filter('action_scheduler_allow_async_request_runner', 'hpt_bulk_writer_disable_async_action_scheduler_runner', 999);

    if (class_exists('ActionScheduler_QueueRunner')) {
        ActionScheduler_QueueRunner::instance()->unhook_dispatch_async_request();
    }

    hpt_bulk_writer_remove_shutdown_callbacks_for_object_class('Automattic\\WooCommerce\\Internal\\BatchProcessing\\BatchProcessingController');

    if (function_exists('hpt_begin_bulk_product_import')) {
        hpt_begin_bulk_product_import();
    }
}

function hpt_bulk_writer_end_bulk_runtime_optimizations() {
    $depth = hpt_bulk_writer_get_runtime_optimization_depth();

    if ($depth <= 0) {
        return;
    }

    $depth--;
    $GLOBALS['hpt_bulk_writer_runtime_optimization_depth'] = $depth;

    if ($depth > 0) {
        return;
    }

    remove_filter('action_scheduler_allow_async_request_runner', 'hpt_bulk_writer_disable_async_action_scheduler_runner', 999);

    if (function_exists('hpt_end_bulk_product_import')) {
        hpt_end_bulk_product_import();
    }
}

if (!class_exists('HPT_Bulk_Writer_WP_Die_Exception')) {
    class HPT_Bulk_Writer_WP_Die_Exception extends RuntimeException {}
}

function hpt_bulk_writer_normalize_error_text($value) {
    if (is_wp_error($value)) {
        return trim($value->get_error_message());
    }

    if (is_array($value)) {
        $parts = array();

        array_walk_recursive($value, static function ($item) use (&$parts) {
            $text = trim(wp_strip_all_tags((string) $item));

            if ($text !== '') {
                $parts[] = $text;
            }
        });

        return trim(implode(' | ', array_unique($parts)));
    }

    return trim(wp_strip_all_tags((string) $value));
}

function hpt_bulk_writer_extract_html_error_message($html) {
    $html = is_string($html) ? trim($html) : '';

    if ($html === '') {
        return '';
    }

    $messages = array();

    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $title_match)) {
        $title = hpt_bulk_writer_normalize_error_text(html_entity_decode($title_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($title !== '') {
            $messages[] = $title;
        }
    }

    if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $paragraph_matches)) {
        foreach ($paragraph_matches[1] as $paragraph_html) {
            $paragraph = hpt_bulk_writer_normalize_error_text(html_entity_decode($paragraph_html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($paragraph !== '') {
                $messages[] = $paragraph;
            }
        }
    }

    if (empty($messages)) {
        $text = hpt_bulk_writer_normalize_error_text(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($text !== '') {
            $messages[] = $text;
        }
    }

    return implode(' | ', array_slice(array_values(array_unique(array_filter($messages))), 0, 4));
}

function hpt_bulk_writer_capture_wp_die_handler($message, $title = '', $args = array()) {
    $parts = array_filter(array(
        hpt_bulk_writer_normalize_error_text($title),
        hpt_bulk_writer_normalize_error_text($message),
    ));

    throw new HPT_Bulk_Writer_WP_Die_Exception(
        !empty($parts) ? implode(': ', $parts) : 'Request bi dung boi wp_die().'
    );
}

function hpt_bulk_writer_force_wp_die_handler($handler) {
    return 'hpt_bulk_writer_capture_wp_die_handler';
}

function hpt_bulk_writer_is_async_request() {
    return (
        isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['hpt_bulk_writer_request_mode'])
        && $_POST['hpt_bulk_writer_request_mode'] === 'async'
    );
}

function hpt_bulk_writer_mark_response_sent() {
    $GLOBALS['hpt_bulk_writer_debug_response_sent'] = true;
}

function hpt_bulk_writer_has_response_been_sent() {
    return !empty($GLOBALS['hpt_bulk_writer_debug_response_sent']);
}

function hpt_bulk_writer_get_debug_context() {
    $context = $GLOBALS['hpt_bulk_writer_debug_context'] ?? array();

    return is_array($context) ? $context : array();
}

function hpt_bulk_writer_set_debug_context($context) {
    $GLOBALS['hpt_bulk_writer_debug_context'] = is_array($context) ? $context : array();
}

function hpt_bulk_writer_merge_debug_context($context) {
    hpt_bulk_writer_set_debug_context(array_merge(
        hpt_bulk_writer_get_debug_context(),
        is_array($context) ? $context : array()
    ));
}

function hpt_bulk_writer_get_debug_log_dir() {
    $upload_dir = wp_upload_dir();

    return trailingslashit($upload_dir['basedir']) . 'hpt-bulk-writer-logs';
}

function hpt_bulk_writer_get_debug_log_path() {
    $dir_path = hpt_bulk_writer_get_debug_log_dir();

    if (!is_dir($dir_path)) {
        wp_mkdir_p($dir_path);
    }

    return trailingslashit($dir_path) . 'bulk-writer-' . gmdate('Y-m-d') . '.log';
}

function hpt_bulk_writer_append_debug_log_reference($message) {
    $message = trim((string) $message);

    if ($message === '') {
        $message = 'Khong co thong diep loi chi tiet.';
    }

    return $message . ' | Debug log: ' . hpt_bulk_writer_get_debug_log_path();
}

function hpt_bulk_writer_write_debug_log($level, $message, $context = array()) {
    $payload = array_merge(
        hpt_bulk_writer_get_debug_context(),
        is_array($context) ? $context : array()
    );

    $json = !empty($payload)
        ? wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '';

    $line = sprintf(
        '[%s] %s %s',
        gmdate('c'),
        strtoupper((string) $level),
        trim((string) $message)
    );

    if (is_string($json) && $json !== '') {
        $line .= ' | ' . $json;
    }

    @file_put_contents(hpt_bulk_writer_get_debug_log_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function hpt_bulk_writer_send_raw_json_and_exit($payload, $status_code = 500) {
    $payload = is_array($payload) ? $payload : array(
        'success' => false,
        'data'    => array(
            'message' => 'Phan hoi JSON khong hop le.',
        ),
    );

    hpt_bulk_writer_mark_response_sent();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        status_header((int) $status_code);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
    }

    echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hpt_bulk_writer_is_fatal_error_type($error_type) {
    return in_array((int) $error_type, array(
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
        E_RECOVERABLE_ERROR,
    ), true);
}

function hpt_bulk_writer_build_runtime_error_message($message, $file = '', $line = 0) {
    $context = hpt_bulk_writer_get_debug_context();
    $parts   = array();

    $message = trim((string) $message);

    if ($message !== '') {
        $parts[] = $message;
    }

    if ($file !== '') {
        $parts[] = $file . ((int) $line > 0 ? ':' . (int) $line : '');
    }

    if (!empty($context['action'])) {
        $parts[] = 'action=' . sanitize_key((string) $context['action']);
    }

    if (!empty($context['job_token'])) {
        $parts[] = 'job=' . sanitize_file_name((string) $context['job_token']);
    }

    if (isset($context['batch_index'])) {
        $parts[] = 'batch=' . (int) $context['batch_index'];
    }

    if (isset($context['row_number'])) {
        $parts[] = 'row=' . (int) $context['row_number'];
    }

    if (!empty($context['sku'])) {
        $parts[] = 'sku=' . trim((string) $context['sku']);
    }

    return implode(' | ', array_filter($parts));
}

function hpt_bulk_writer_handle_uncaught_exception($exception) {
    if (!($exception instanceof Throwable)) {
        return;
    }

    $message = hpt_bulk_writer_build_runtime_error_message(
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );

    hpt_bulk_writer_write_debug_log('fatal', $message, array(
        'trace' => $exception->getTraceAsString(),
    ));

    if (hpt_bulk_writer_is_async_request() && !hpt_bulk_writer_has_response_been_sent()) {
        hpt_bulk_writer_send_raw_json_and_exit(array(
            'success' => false,
            'data'    => array(
                'message' => hpt_bulk_writer_append_debug_log_reference($message),
            ),
        ), 500);
    }
}

function hpt_bulk_writer_handle_php_error($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }

    hpt_bulk_writer_write_debug_log('php-error', hpt_bulk_writer_build_runtime_error_message($errstr, $errfile, $errline), array(
        'errno' => (int) $errno,
    ));

    return false;
}

function hpt_bulk_writer_handle_shutdown() {
    if (hpt_bulk_writer_has_response_been_sent()) {
        return;
    }

    $last_error = error_get_last();

    if (!is_array($last_error) || !hpt_bulk_writer_is_fatal_error_type($last_error['type'] ?? 0)) {
        return;
    }

    $buffer = '';

    if (ob_get_level() > 0) {
        $buffer = (string) ob_get_contents();
    }

    $message = hpt_bulk_writer_build_runtime_error_message(
        (string) ($last_error['message'] ?? 'PHP fatal error'),
        (string) ($last_error['file'] ?? ''),
        (int) ($last_error['line'] ?? 0)
    );

    $log_context = array(
        'type' => (int) ($last_error['type'] ?? 0),
    );

    if (trim($buffer) !== '') {
        $log_context['buffer'] = mb_substr(trim(hpt_bulk_writer_normalize_error_text($buffer)), 0, 2000);
    }

    hpt_bulk_writer_write_debug_log('fatal', $message, $log_context);

    if (hpt_bulk_writer_is_async_request()) {
        hpt_bulk_writer_send_raw_json_and_exit(array(
            'success' => false,
            'data'    => array(
                'message' => hpt_bulk_writer_append_debug_log_reference($message),
            ),
        ), 500);
    }
}

function hpt_bulk_writer_register_debug_handlers() {
    static $registered = false;

    if ($registered) {
        return;
    }

    $registered = true;

    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    error_reporting(E_ALL);

    if (hpt_bulk_writer_is_async_request() && ob_get_level() === 0) {
        ob_start();
    }

    set_error_handler('hpt_bulk_writer_handle_php_error');
    set_exception_handler('hpt_bulk_writer_handle_uncaught_exception');
    register_shutdown_function('hpt_bulk_writer_handle_shutdown');
}

hpt_bulk_writer_register_debug_handlers();

function hpt_bulk_writer_send_json_success($data = array(), $status_code = 200) {
    hpt_bulk_writer_mark_response_sent();
    status_header($status_code);
    nocache_headers();
    wp_send_json_success($data, $status_code);
}

function hpt_bulk_writer_send_json_error($message, $status_code = 400, $extra = array()) {
    hpt_bulk_writer_mark_response_sent();

    if ((int) $status_code >= 500) {
        hpt_bulk_writer_write_debug_log('error', (string) $message, array(
            'status_code' => (int) $status_code,
            'extra'       => $extra,
        ));
    }

    status_header($status_code);
    nocache_headers();
    wp_send_json_error(array_merge(array(
        'message' => (string) $message,
    ), $extra), $status_code);
}

function hpt_bulk_writer_verify_async_request() {
    $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';

    if (!wp_verify_nonce($nonce, hpt_bulk_writer_get_request_nonce_action())) {
        hpt_bulk_writer_send_json_error('Nonce khong hop le. Vui long tai lai trang va thu lai.', 403);
    }
}

function hpt_bulk_writer_get_jobs_root_dir() {
    $upload_dir = wp_upload_dir();

    return trailingslashit($upload_dir['basedir']) . 'hpt-bulk-writer-jobs';
}

function hpt_bulk_writer_ensure_dir($dir_path) {
    if (!is_dir($dir_path)) {
        wp_mkdir_p($dir_path);
    }

    return is_dir($dir_path);
}

function hpt_bulk_writer_cleanup_old_jobs() {
    $root_dir = hpt_bulk_writer_get_jobs_root_dir();

    if (!is_dir($root_dir)) {
        return;
    }

    foreach ((array) glob($root_dir . '/job-*') as $job_dir) {
        if (!is_dir($job_dir)) {
            continue;
        }

        $meta_path = $job_dir . '/meta.json';
        $expires_at = filemtime($job_dir);

        if (is_file($meta_path)) {
            $meta = hpt_bulk_writer_read_json_file($meta_path, array());

            if (!empty($meta['created_at'])) {
                $expires_at = (int) $meta['created_at'];
            }
        }

        if ($expires_at > 0 && $expires_at < (time() - DAY_IN_SECONDS)) {
            hpt_bulk_writer_delete_dir_recursive($job_dir);
        }
    }
}

function hpt_bulk_writer_create_job_token() {
    return 'job-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
}

function hpt_bulk_writer_get_job_dir($job_token) {
    return trailingslashit(hpt_bulk_writer_get_jobs_root_dir()) . sanitize_file_name($job_token);
}

function hpt_bulk_writer_get_job_meta_path($job_token) {
    return hpt_bulk_writer_get_job_dir($job_token) . '/meta.json';
}

function hpt_bulk_writer_get_job_batches_dir($job_token) {
    return hpt_bulk_writer_get_job_dir($job_token) . '/batches';
}

function hpt_bulk_writer_get_job_results_dir($job_token) {
    return hpt_bulk_writer_get_job_dir($job_token) . '/results';
}

function hpt_bulk_writer_get_job_batch_path($job_token, $batch_index) {
    return hpt_bulk_writer_get_job_batches_dir($job_token) . '/batch-' . absint($batch_index) . '.json';
}

function hpt_bulk_writer_get_job_result_path($job_token, $batch_index) {
    return hpt_bulk_writer_get_job_results_dir($job_token) . '/batch-' . absint($batch_index) . '.json';
}

function hpt_bulk_writer_save_json_file($file_path, $data) {
    $dir_path = dirname($file_path);

    if (!hpt_bulk_writer_ensure_dir($dir_path)) {
        return false;
    }

    $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($json) || $json === '') {
        return false;
    }

    return file_put_contents($file_path, $json, LOCK_EX) !== false;
}

function hpt_bulk_writer_read_json_file($file_path, $default = array()) {
    if (!is_file($file_path)) {
        return $default;
    }

    $json = file_get_contents($file_path);

    if (!is_string($json) || $json === '') {
        return $default;
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : $default;
}

function hpt_bulk_writer_load_job_meta($job_token) {
    $job_token = sanitize_file_name((string) $job_token);

    if ($job_token === '') {
        return array();
    }

    $job_meta = hpt_bulk_writer_read_json_file(hpt_bulk_writer_get_job_meta_path($job_token), array());

    return is_array($job_meta) ? $job_meta : array();
}

function hpt_bulk_writer_delete_dir_recursive($dir_path) {
    if (!is_dir($dir_path)) {
        return;
    }

    foreach ((array) glob($dir_path . '/*') as $item_path) {
        if (is_dir($item_path)) {
            hpt_bulk_writer_delete_dir_recursive($item_path);
            continue;
        }

        @unlink($item_path);
    }

    @rmdir($dir_path);
}

function hpt_bulk_writer_process_mapped_row($mapped_row, $options) {
    if (!empty($mapped_row['skip'])) {
        return array(
            'status'     => 'skipped',
            'action'     => 'bo qua',
            'row_number' => (int) ($mapped_row['row_number'] ?? 0),
            'sku'        => (string) ($mapped_row['sku'] ?? ''),
            'product_id' => 0,
            'message'    => (string) ($mapped_row['message'] ?? 'Dong du lieu khong hop le.'),
        );
    }

    try {
        return hpt_bulk_writer_upsert_product($mapped_row, $options);
    } catch (Throwable $exception) {
        return array(
            'status'     => 'error',
            'action'     => 'loi',
            'row_number' => (int) ($mapped_row['row_number'] ?? 0),
            'sku'        => (string) ($mapped_row['sku'] ?? ''),
            'product_id' => 0,
            'message'    => $exception->getMessage(),
        );
    }
}

function hpt_bulk_writer_get_rest_credentials() {
	return array(
		'consumer_key'    => defined( 'HPT_WC_CONSUMER_KEY' ) ? HPT_WC_CONSUMER_KEY : (string) getenv( 'HPT_WC_CONSUMER_KEY' ),
		'consumer_secret' => defined( 'HPT_WC_CONSUMER_SECRET' ) ? HPT_WC_CONSUMER_SECRET : (string) getenv( 'HPT_WC_CONSUMER_SECRET' ),
	);
}

function hpt_bulk_writer_apply_rest_request_payload($request, $payload, $payload_json) {
    $request->set_header('content-type', 'application/json');
    $request->set_body($payload_json);

    if (method_exists($request, 'set_json_params')) {
        $request->set_json_params($payload);
        return;
    }

    if (method_exists($request, 'set_body_params')) {
        $request->set_body_params($payload);
    }
}

function hpt_bulk_writer_execute_rest_product_request($method, $rest_route, $payload) {
    $payload_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($payload_json) || $payload_json === '') {
        return new WP_Error('hpt_bulk_writer_json', 'Khong ma hoa duoc payload REST.');
    }

    $request = new WP_REST_Request(strtoupper($method), (string) $rest_route);
    hpt_bulk_writer_apply_rest_request_payload($request, $payload, $payload_json);

    hpt_bulk_writer_write_debug_log('info', 'REST request bat dau', array(
        'method'     => strtoupper((string) $method),
        'rest_route' => (string) $rest_route,
    ));

    $unexpected_output = '';
    $buffer_level      = ob_get_level();

    add_filter('wp_die_handler', 'hpt_bulk_writer_force_wp_die_handler', 999);

    try {
        ob_start();
        $response = rest_do_request($request);
    } catch (HPT_Bulk_Writer_WP_Die_Exception $exception) {
        $unexpected_output = ob_get_level() > $buffer_level ? (string) ob_get_clean() : '';
        hpt_bulk_writer_write_debug_log('error', 'wp_die trong REST request', array(
            'rest_route' => (string) $rest_route,
            'message'    => $exception->getMessage(),
            'buffer'     => trim($unexpected_output) !== '' ? mb_substr(trim(hpt_bulk_writer_normalize_error_text($unexpected_output)), 0, 2000) : '',
        ));

        return new WP_Error('hpt_bulk_writer_wp_die', $exception->getMessage());
    } catch (Throwable $exception) {
        $unexpected_output = ob_get_level() > $buffer_level ? (string) ob_get_clean() : '';
        hpt_bulk_writer_write_debug_log('error', 'Throwable trong REST request', array(
            'rest_route' => (string) $rest_route,
            'message'    => $exception->getMessage(),
            'file'       => $exception->getFile(),
            'line'       => $exception->getLine(),
            'buffer'     => trim($unexpected_output) !== '' ? mb_substr(trim(hpt_bulk_writer_normalize_error_text($unexpected_output)), 0, 2000) : '',
        ));

        return new WP_Error('hpt_bulk_writer_rest_throwable', $exception->getMessage());
    } finally {
        if (ob_get_level() > $buffer_level) {
            $unexpected_output = (string) ob_get_clean();
        }

        remove_filter('wp_die_handler', 'hpt_bulk_writer_force_wp_die_handler', 999);
    }

    if (is_wp_error($response)) {
        return $response;
    }

    if (!($response instanceof WP_REST_Response)) {
        $response = rest_ensure_response($response);
    }

    $response_data = $response->get_data();
    $http_code     = (int) $response->get_status();
    $response_body = is_string($response_data)
        ? $response_data
        : wp_json_encode($response_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (trim($unexpected_output) !== '') {
        hpt_bulk_writer_write_debug_log('warning', 'REST request phat sinh output ngoai du kien', array(
            'rest_route' => (string) $rest_route,
            'http_code'  => $http_code,
            'buffer'     => mb_substr(trim(hpt_bulk_writer_normalize_error_text($unexpected_output)), 0, 2000),
        ));
    }

    if ($http_code >= 400) {
        hpt_bulk_writer_write_debug_log('error', 'REST request tra ve HTTP loi', array(
            'rest_route'    => (string) $rest_route,
            'http_code'     => $http_code,
            'response_body' => is_string($response_body) ? mb_substr(trim(hpt_bulk_writer_normalize_error_text($response_body)), 0, 2000) : '',
        ));
    }

    return array(
        'body'      => $response_body,
        'http_code' => $http_code,
        'data'      => $response_data,
    );
}

function hpt_bulk_writer_process_legacy_row($prepared_row, $options) {
    if (!empty($prepared_row['skip'])) {
        return array(
            'status'     => 'skipped',
            'action'     => 'bo qua',
            'row_number' => (int) ($prepared_row['row_number'] ?? 0),
            'sku'        => (string) ($prepared_row['sku'] ?? ''),
            'product_id' => 0,
            'message'    => (string) ($prepared_row['message'] ?? 'Dong du lieu khong hop le.'),
        );
    }

    $sku             = (string) ($prepared_row['sku'] ?? '');
    $row_number      = (int) ($prepared_row['row_number'] ?? 0);
    $existing_id     = hpt_bulk_writer_get_product_id_by_sku($sku);
    $create_missing  = !empty($options['create_missing']);
    $update_existing = !empty($options['update_existing']);
    $import_images   = !empty($options['import_images']);
    $payload         = is_array($prepared_row['payload'] ?? null) ? $prepared_row['payload'] : array();

    hpt_bulk_writer_merge_debug_context(array(
        'row_number' => $row_number,
        'sku'        => $sku,
        'existing_id'=> $existing_id,
    ));

    if ($existing_id > 0 && !$update_existing) {
        return array(
            'status'     => 'skipped',
            'action'     => 'bo qua',
            'row_number' => $row_number,
            'sku'        => $sku,
            'product_id' => $existing_id,
            'message'    => 'San pham da ton tai va tuy chon cap nhat dang tat.',
        );
    }

    if ($existing_id <= 0 && !$create_missing) {
        return array(
            'status'     => 'skipped',
            'action'     => 'bo qua',
            'row_number' => $row_number,
            'sku'        => $sku,
            'product_id' => 0,
            'message'    => 'SKU chua ton tai va tuy chon tao moi dang tat.',
        );
    }

    if (!$import_images) {
        unset($payload['images']);
    }

    if ($existing_id > 0) {
        unset($payload['date_created']);
    }

    $action      = $existing_id > 0 ? 'cap nhat' : 'tao moi';
    $rest_route  = $existing_id > 0 ? '/wc/v3/products/' . $existing_id : '/wc/v3/products';
    $http_method = $existing_id > 0 ? 'PUT' : 'POST';
    hpt_bulk_writer_merge_debug_context(array(
        'action'     => 'process_row',
        'rest_route' => $rest_route,
        'http_method'=> $http_method,
    ));
    $response    = hpt_bulk_writer_execute_rest_product_request($http_method, $rest_route, $payload);

    if (is_wp_error($response)) {
        $recovered_product_id = hpt_bulk_writer_get_product_id_by_sku($sku);

        if ($existing_id <= 0 && $recovered_product_id > 0) {
            hpt_bulk_writer_write_debug_log('warning', 'REST tra loi WP_Error nhung SKU da ton tai sau request', array(
                'sku'                  => $sku,
                'recovered_product_id' => $recovered_product_id,
            ));
            $existing_id = $recovered_product_id;
        }

        hpt_bulk_writer_write_debug_log('error', 'Loi WP_Error khi xu ly dong', array(
            'error_code' => $response->get_error_code(),
            'message'    => $response->get_error_message(),
        ));

        return array(
            'status'     => 'error',
            'action'     => $action,
            'row_number' => $row_number,
            'sku'        => $sku,
            'product_id' => $existing_id,
            'message'    => hpt_bulk_writer_append_debug_log_reference($response->get_error_message()),
        );
    }

    $response_body = (string) ($response['body'] ?? '');
    $http_code     = (int) ($response['http_code'] ?? 0);
    $response_data = is_array($response['data'] ?? null)
        ? $response['data']
        : json_decode($response_body, true);

    if (!is_array($response_data) && $response_body !== '' && stripos($response_body, '<html') !== false) {
        $message = hpt_bulk_writer_extract_html_error_message($response_body);
        $recovered_product_id = hpt_bulk_writer_get_product_id_by_sku($sku);

        if ($existing_id <= 0 && $recovered_product_id > 0) {
            hpt_bulk_writer_write_debug_log('warning', 'REST tra HTML bat thuong nhung SKU da ton tai sau request', array(
                'sku'                  => $sku,
                'recovered_product_id' => $recovered_product_id,
            ));
            $existing_id = $recovered_product_id;
        }

        hpt_bulk_writer_write_debug_log('error', 'REST response HTML bat thuong', array(
            'http_code' => $http_code,
            'message'   => $message,
        ));

        return array(
            'status'     => 'error',
            'action'     => $action,
            'row_number' => $row_number,
            'sku'        => $sku,
            'product_id' => $existing_id,
            'message'    => hpt_bulk_writer_append_debug_log_reference($message),
        );
    }

    if ($http_code >= 400 || !is_array($response_data) || isset($response_data['code'])) {
        $message = (string) ($response_data['message'] ?? hpt_bulk_writer_extract_html_error_message($response_body) ?: ('HTTP ' . $http_code));
        $recovered_product_id = hpt_bulk_writer_get_product_id_by_sku($sku);

        if ($existing_id <= 0 && $recovered_product_id > 0) {
            hpt_bulk_writer_write_debug_log('warning', 'REST response loi nhung SKU da ton tai sau request', array(
                'sku'                  => $sku,
                'recovered_product_id' => $recovered_product_id,
            ));
            $existing_id = $recovered_product_id;
        }

        hpt_bulk_writer_write_debug_log('error', 'REST response loi logic', array(
            'http_code' => $http_code,
            'message'   => $message,
            'response_code' => is_array($response_data) ? ($response_data['code'] ?? '') : '',
        ));

        return array(
            'status'     => 'error',
            'action'     => $action,
            'row_number' => $row_number,
            'sku'        => $sku,
            'product_id' => $existing_id,
            'message'    => hpt_bulk_writer_append_debug_log_reference($message),
        );
    }

    $product_id = (int) ($response_data['id'] ?? $existing_id);

    if ($product_id > 0) {
        hpt_bulk_writer_cache_product_id_by_sku($sku, $product_id);
    }

    if ($product_id > 0) {
        if (function_exists('hpt_clear_product_detail_cache')) {
            hpt_clear_product_detail_cache($product_id);
        } else {
            clean_post_cache($product_id);

            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }
        }
    }

    return array(
        'status'     => 'success',
        'action'     => $action,
        'row_number' => $row_number,
        'sku'        => $sku,
        'product_id' => $product_id,
        'message'    => 'Xu ly thanh cong.',
    );
}

function hpt_bulk_writer_handle_init_job() {
    hpt_bulk_writer_verify_async_request();
    hpt_bulk_writer_cleanup_old_jobs();
    hpt_bulk_writer_set_debug_context(array(
        'action' => 'init_job',
    ));

    if (!isset($_FILES['excel_file']) || !is_array($_FILES['excel_file'])) {
        hpt_bulk_writer_send_json_error('Khong nhan duoc file upload.');
    }

    if ((int) ($_FILES['excel_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        hpt_bulk_writer_send_json_error('Upload file that bai. Ma loi: ' . (int) ($_FILES['excel_file']['error'] ?? -1));
    }

    $tmp_name = (string) ($_FILES['excel_file']['tmp_name'] ?? '');

    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
        hpt_bulk_writer_send_json_error('File upload khong hop le.');
    }

    $options = hpt_bulk_writer_get_request_options_from_input($_POST);

    /*
     * Keep each PHP request short and bounded while allowing enough overlap
     * to hide REST/database latency. Image work gets a smaller window because
     * WooCommerce may create attachment metadata in the same request.
     */
    $batch_size  = !empty($options['import_images']) ? 6 : 24;
    $concurrency = !empty($options['import_images']) ? 2 : 4;

    $batch_size  = max(1, min(24, (int) apply_filters('hpt_bulk_writer_batch_size', $batch_size, $options)));
    $concurrency = max(1, min(4, (int) apply_filters('hpt_bulk_writer_concurrency', $concurrency, $options)));
    $job_token    = hpt_bulk_writer_create_job_token();
    $job_dir      = hpt_bulk_writer_get_job_dir($job_token);
    $batches_dir  = hpt_bulk_writer_get_job_batches_dir($job_token);
    $results_dir  = hpt_bulk_writer_get_job_results_dir($job_token);

    if (!hpt_bulk_writer_ensure_dir($job_dir) || !hpt_bulk_writer_ensure_dir($batches_dir) || !hpt_bulk_writer_ensure_dir($results_dir)) {
        hpt_bulk_writer_send_json_error('Khong tao duoc thu muc tam de xu ly batch.', 500);
    }

    $spreadsheet = IOFactory::load($tmp_name);
    $sheet       = $spreadsheet->getActiveSheet();
    $rows        = $sheet->toArray('', true, true, true);
    $last_row    = !empty($rows) ? max(array_keys($rows)) : 0;

    if (empty($rows) || $last_row < 2) {
        hpt_bulk_writer_send_json_error('File Excel khong co du lieu hop le.');
    }

    $header_row = isset($rows[1]) && is_array($rows[1]) ? $rows[1] : array();
    $header_map = hpt_bulk_writer_resolve_header_map($header_row);
    $batch_rows = array();
    $batch_index = 0;
    $total_rows = 0;

    for ($row_number = 2; $row_number <= $last_row; $row_number++) {
        $row = isset($rows[$row_number]) && is_array($rows[$row_number]) ? $rows[$row_number] : array();

        if (empty(array_filter($row, 'hpt_bulk_writer_has_value'))) {
            continue;
        }

        $batch_rows[] = hpt_bulk_writer_parse_row_legacy($row_number, $row, $header_map);
        $total_rows++;

        if (count($batch_rows) >= $batch_size) {
            if (!hpt_bulk_writer_save_json_file(
                hpt_bulk_writer_get_job_batch_path($job_token, $batch_index),
                $batch_rows
            )) {
                hpt_bulk_writer_send_json_error('Khong ghi duoc file batch tam thoi.', 500);
            }
            $batch_index++;
            $batch_rows = array();
        }
    }

    if (!empty($batch_rows)) {
        if (!hpt_bulk_writer_save_json_file(
            hpt_bulk_writer_get_job_batch_path($job_token, $batch_index),
            $batch_rows
        )) {
            hpt_bulk_writer_send_json_error('Khong ghi duoc file batch tam thoi.', 500);
        }
        $batch_index++;
    }

    // Release PhpSpreadsheet's worksheet graph before returning the response.
    if (method_exists($spreadsheet, 'disconnectWorksheets')) {
        $spreadsheet->disconnectWorksheets();
    }
    unset($sheet, $rows, $spreadsheet);
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    $job_meta = array(
        'job_token'     => $job_token,
        'created_at'    => time(),
        'upload_name'   => sanitize_file_name((string) ($_FILES['excel_file']['name'] ?? 'bulk-report.xlsx')),
        'total_rows'    => $total_rows,
        'total_batches' => $batch_index,
        'batch_size'    => $batch_size,
        'concurrency'   => $concurrency,
        'options'       => $options,
    );

    if (!hpt_bulk_writer_save_json_file(hpt_bulk_writer_get_job_meta_path($job_token), $job_meta)) {
        hpt_bulk_writer_send_json_error('Khong ghi duoc file meta cho batch job.', 500);
    }

    hpt_bulk_writer_merge_debug_context(array(
        'job_token'     => $job_token,
        'total_rows'    => $total_rows,
        'total_batches' => $batch_index,
    ));

    hpt_bulk_writer_write_debug_log('info', 'Khoi tao batch job thanh cong');

    hpt_bulk_writer_send_json_success(array(
        'job_token'     => $job_token,
        'total_rows'    => $total_rows,
        'total_batches' => $batch_index,
        'batch_size'    => $batch_size,
        'concurrency'   => $concurrency,
    ));
}

function hpt_bulk_writer_handle_process_batch() {
    hpt_bulk_writer_verify_async_request();

    $job_token  = sanitize_file_name((string) ($_POST['job_token'] ?? ''));
    $batch_index = absint($_POST['batch_index'] ?? 0);
    hpt_bulk_writer_set_debug_context(array(
        'action'      => 'process_batch',
        'job_token'   => $job_token,
        'batch_index' => $batch_index,
    ));
    $job_meta   = hpt_bulk_writer_load_job_meta($job_token);

    if (empty($job_meta)) {
        hpt_bulk_writer_send_json_error('Khong tim thay batch job hop le.', 404);
    }

    $result_path = hpt_bulk_writer_get_job_result_path($job_token, $batch_index);

    if (is_file($result_path)) {
        hpt_bulk_writer_send_json_success(hpt_bulk_writer_read_json_file($result_path, array()));
    }

    $batch_path = hpt_bulk_writer_get_job_batch_path($job_token, $batch_index);
    $batch_rows = hpt_bulk_writer_read_json_file($batch_path, array());

    if (empty($batch_rows)) {
        hpt_bulk_writer_send_json_error('Batch khong ton tai hoac khong co du lieu.', 404);
    }

    $summary = hpt_bulk_writer_create_empty_summary();
    $results = array();
    $options = is_array($job_meta['options'] ?? null) ? $job_meta['options'] : array();

    hpt_bulk_writer_write_debug_log('info', 'Bat dau xu ly batch', array(
        'row_count' => count($batch_rows),
    ));

    hpt_bulk_writer_begin_bulk_runtime_optimizations();

    if (function_exists('wp_defer_term_counting')) {
        wp_defer_term_counting(true);
    }

    if (function_exists('wp_defer_comment_counting')) {
        wp_defer_comment_counting(true);
    }

    try {
        foreach ($batch_rows as $mapped_row) {
            hpt_bulk_writer_merge_debug_context(array(
                'row_number' => (int) ($mapped_row['row_number'] ?? 0),
                'sku'        => (string) ($mapped_row['sku'] ?? ''),
            ));
            $result = hpt_bulk_writer_process_legacy_row($mapped_row, $options);
            $results[] = $result;

            if (!isset($summary[$result['status']])) {
                $summary[$result['status']] = 0;
            }

            $summary[$result['status']]++;
        }
    } finally {
        if (function_exists('wp_defer_term_counting')) {
            wp_defer_term_counting(false);
        }

        if (function_exists('wp_defer_comment_counting')) {
            wp_defer_comment_counting(false);
        }

        hpt_bulk_writer_end_bulk_runtime_optimizations();
    }

    $payload = array(
        'summary'   => $summary,
        'results'   => $results,
        'row_count' => count($results),
    );

    if (!hpt_bulk_writer_save_json_file($result_path, $payload)) {
        hpt_bulk_writer_send_json_error('Khong luu duoc ket qua batch.', 500);
    }

    hpt_bulk_writer_write_debug_log('info', 'Xu ly batch xong', array(
        'summary' => $summary,
    ));

    hpt_bulk_writer_send_json_success($payload);
}

function hpt_bulk_writer_handle_finalize_job() {
    hpt_bulk_writer_verify_async_request();

    $job_token = sanitize_file_name((string) ($_POST['job_token'] ?? ''));
    hpt_bulk_writer_set_debug_context(array(
        'action'    => 'finalize_job',
        'job_token' => $job_token,
    ));
    $job_meta  = hpt_bulk_writer_load_job_meta($job_token);

    if (empty($job_meta)) {
        hpt_bulk_writer_send_json_error('Khong tim thay batch job hop le.', 404);
    }

    $summary = hpt_bulk_writer_create_empty_summary();
    $results = array();
    $total_batches = (int) ($job_meta['total_batches'] ?? 0);

    for ($batch_index = 0; $batch_index < $total_batches; $batch_index++) {
        $result_path = hpt_bulk_writer_get_job_result_path($job_token, $batch_index);

        if (!is_file($result_path)) {
            hpt_bulk_writer_send_json_error('Van con batch chua xu ly xong: #' . $batch_index . '.', 409);
        }

        $payload = hpt_bulk_writer_read_json_file($result_path, array());
        hpt_bulk_writer_merge_summary($summary, $payload['summary'] ?? array());

        foreach ((array) ($payload['results'] ?? array()) as $result) {
            $results[] = $result;
        }
    }

    usort($results, static function ($left, $right) {
        return (int) ($left['row_number'] ?? 0) <=> (int) ($right['row_number'] ?? 0);
    });

    $report = !empty($results)
        ? hpt_bulk_writer_build_report($results, $job_meta['upload_name'] ?? 'bulk-report.xlsx')
        : array('url' => '', 'path' => '');

    hpt_bulk_writer_send_json_success(array(
        'summary'     => $summary,
        'results'     => $results,
        'report_url'  => (string) ($report['url'] ?? ''),
        'report_path' => (string) ($report['path'] ?? ''),
    ));
}

function hpt_bulk_writer_handle_cleanup_job() {
    hpt_bulk_writer_verify_async_request();

    $job_token = sanitize_file_name((string) ($_POST['job_token'] ?? ''));
    hpt_bulk_writer_set_debug_context(array(
        'action'    => 'cleanup_job',
        'job_token' => $job_token,
    ));

    if ($job_token === '') {
        hpt_bulk_writer_send_json_success(array('deleted' => false));
    }

    hpt_bulk_writer_delete_dir_recursive(hpt_bulk_writer_get_job_dir($job_token));
    hpt_bulk_writer_send_json_success(array('deleted' => true));
}

function hpt_bulk_writer_handle_async_request() {
    if (!is_user_logged_in()) {
        hpt_bulk_writer_send_json_error('Phien dang nhap da het han. Vui long dang nhap lai.', 401);
    }

    if (!current_user_can('manage_woocommerce')) {
        hpt_bulk_writer_send_json_error('Ban khong co quyen su dung tool nay.', 403);
    }

    $action = sanitize_key($_POST['hpt_bulk_writer_action'] ?? '');

    if ($action === 'init_job') {
        hpt_bulk_writer_handle_init_job();
    }

    if ($action === 'process_batch') {
        hpt_bulk_writer_handle_process_batch();
    }

    if ($action === 'finalize_job') {
        hpt_bulk_writer_handle_finalize_job();
    }

    if ($action === 'cleanup_job') {
        hpt_bulk_writer_handle_cleanup_job();
    }

    hpt_bulk_writer_send_json_error('Action bat dong bo khong hop le.', 400);
}

function hpt_bulk_writer_render_page($state) {
    $results          = isset($state['results']) && is_array($state['results']) ? $state['results'] : array();
    $summary          = isset($state['summary']) && is_array($state['summary']) ? $state['summary'] : array();
    $global_error     = isset($state['global_error']) ? (string) $state['global_error'] : '';
    $report_url       = isset($state['report_url']) ? (string) $state['report_url'] : '';
    $report_path      = isset($state['report_path']) ? (string) $state['report_path'] : '';
    $submitted        = !empty($state['submitted']);
    $default_options  = isset($state['options']) && is_array($state['options']) ? $state['options'] : array();

    $create_missing   = array_key_exists('create_missing', $default_options) ? !empty($default_options['create_missing']) : true;
    $update_existing  = array_key_exists('update_existing', $default_options) ? !empty($default_options['update_existing']) : true;
    $import_images    = array_key_exists('import_images', $default_options) ? !empty($default_options['import_images']) : true;
    $preserve_empty   = array_key_exists('preserve_on_empty', $default_options) ? !empty($default_options['preserve_on_empty']) : true;
    $request_nonce    = wp_create_nonce(hpt_bulk_writer_get_request_nonce_action());

    ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="nofollow, noindex"/>
    <link rel="shortcut icon" href="https://i.pinimg.com/originals/4d/3f/d5/4d3fd5c79837384de61fd2ca64da03a7.gif" type="image/x-icon">
    <title>Bulk Product Writer</title>
    <style>
        :root{
            --bg:#eef3f8;
            --card:#ffffff;
            --text:#0f172a;
            --muted:#475569;
            --line:#d9e3ef;
            --primary:#0f766e;
            --primary-dark:#115e59;
            --danger:#b91c1c;
            --danger-bg:#fef2f2;
            --warning:#b45309;
            --warning-bg:#fffbeb;
            --success:#166534;
            --success-bg:#f0fdf4;
            --info-bg:#eff6ff;
            --shadow:0 18px 42px rgba(15,23,42,.08);
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            padding:32px 18px 48px;
            font-family:"Segoe UI",Tahoma,sans-serif;
            color:var(--text);
            background:
                radial-gradient(circle at top left, rgba(15,118,110,.10), transparent 22%),
                linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
        }
        .page{
            max-width:1180px;
            margin:0 auto;
        }
        .hero{
            margin-bottom:18px;
        }
        .hero h1{
            margin:0 0 8px;
            font-size:30px;
            line-height:1.15;
        }
        .hero p{
            margin:0;
            color:var(--muted);
            max-width:880px;
        }
        .layout{
            display:grid;
            grid-template-columns:minmax(320px, 440px) minmax(0, 1fr);
            gap:20px;
            align-items:start;
        }
        .card{
            background:var(--card);
            border:1px solid rgba(15,23,42,.06);
            border-radius:20px;
            box-shadow:var(--shadow);
        }
        .panel{
            padding:22px;
            position:sticky;
            top:18px;
        }
        .panel h2,
        .results-card h2{
            margin:0 0 14px;
            font-size:20px;
        }
        .field{
            margin-bottom:16px;
        }
        .field label{
            display:block;
            font-weight:700;
            margin-bottom:8px;
        }
        .field input[type="file"]{
            width:100%;
            padding:14px;
            border:1px solid var(--line);
            border-radius:14px;
            background:#fbfdff;
        }
        .field-note{
            margin-top:6px;
            color:var(--muted);
            font-size:13px;
        }
        .options{
            display:grid;
            gap:10px;
            margin:16px 0 18px;
        }
        .option{
            display:flex;
            gap:10px;
            align-items:flex-start;
            padding:12px 14px;
            border:1px solid var(--line);
            border-radius:14px;
            background:#fbfdff;
        }
        .option input{
            margin-top:3px;
        }
        .option strong{
            display:block;
            margin-bottom:4px;
        }
        .option span{
            color:var(--muted);
            font-size:13px;
        }
        .mapping{
            margin-top:18px;
            padding-top:16px;
            border-top:1px dashed var(--line);
        }
        .mapping h3{
            margin:0 0 8px;
            font-size:15px;
        }
        .mapping ul{
            margin:0;
            padding-left:18px;
            color:var(--muted);
            line-height:1.55;
        }
        .submit-row{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            align-items:center;
        }
        .button{
            appearance:none;
            border:none;
            background:linear-gradient(135deg, var(--primary) 0%, #0ea5a0 100%);
            color:#fff;
            font-weight:700;
            padding:14px 20px;
            border-radius:14px;
            cursor:pointer;
            font-size:15px;
            box-shadow:0 10px 24px rgba(15,118,110,.22);
        }
        .button:hover{
            background:linear-gradient(135deg, var(--primary-dark) 0%, #0f766e 100%);
        }
        .button[disabled]{
            opacity:.7;
            cursor:wait;
        }
        .results-card{
            padding:22px;
        }
        .progress{
            display:none;
            margin-bottom:16px;
            padding:16px;
            border:1px solid var(--line);
            border-radius:16px;
            background:#fbfdff;
        }
        .progress.is-active{
            display:block;
        }
        .progress-head{
            display:flex;
            justify-content:space-between;
            gap:12px;
            margin-bottom:10px;
            align-items:center;
        }
        .progress-head strong{
            display:block;
            font-size:15px;
        }
        .progress-meta{
            color:var(--muted);
            font-size:13px;
            line-height:1.5;
        }
        .progress-track{
            height:10px;
            border-radius:999px;
            background:#dbe7f3;
            overflow:hidden;
            margin-bottom:10px;
        }
        .progress-fill{
            width:0;
            height:100%;
            background:linear-gradient(135deg, var(--primary) 0%, #0ea5a0 100%);
            transition:width .2s ease;
        }
        .progress-errors{
            display:grid;
            gap:8px;
            margin-top:12px;
            color:var(--danger);
            font-size:13px;
        }
        .alert{
            padding:14px 16px;
            border-radius:14px;
            margin-bottom:16px;
            border:1px solid transparent;
        }
        .alert.error{
            background:var(--danger-bg);
            border-color:#fecaca;
            color:var(--danger);
        }
        .alert.info{
            background:var(--info-bg);
            border-color:#bfdbfe;
            color:#1d4ed8;
        }
        .summary-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:14px;
            margin-bottom:18px;
        }
        .summary-item{
            background:#f8fbff;
            border:1px solid var(--line);
            border-radius:16px;
            padding:14px 16px;
        }
        .summary-item b{
            display:block;
            font-size:26px;
            line-height:1;
            margin-bottom:6px;
        }
        .summary-item span{
            color:var(--muted);
            font-size:13px;
        }
        .report-link{
            display:inline-flex;
            gap:8px;
            align-items:center;
            margin-bottom:16px;
            padding:10px 14px;
            border-radius:12px;
            background:#ecfeff;
            color:#155e75;
            text-decoration:none;
            font-weight:700;
        }
        .report-path{
            margin-top:-6px;
            margin-bottom:14px;
            color:var(--muted);
            font-size:13px;
            word-break:break-word;
        }
        .table-wrap{
            overflow:auto;
            border:1px solid var(--line);
            border-radius:16px;
        }
        table{
            width:100%;
            border-collapse:collapse;
            min-width:760px;
        }
        th,td{
            padding:13px 14px;
            text-align:left;
            vertical-align:top;
            border-bottom:1px solid var(--line);
        }
        th{
            background:#f8fbff;
            font-size:13px;
            text-transform:uppercase;
            letter-spacing:.04em;
            color:#334155;
        }
        tr:last-child td{
            border-bottom:none;
        }
        .status-pill{
            display:inline-flex;
            align-items:center;
            border-radius:999px;
            padding:6px 10px;
            font-size:12px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.04em;
            white-space:nowrap;
        }
        .status-success{
            color:var(--success);
            background:var(--success-bg);
        }
        .status-warning{
            color:var(--warning);
            background:var(--warning-bg);
        }
        .status-error{
            color:var(--danger);
            background:var(--danger-bg);
        }
        .status-skipped{
            color:#475569;
            background:#f1f5f9;
        }
        .sku{
            font-family:Consolas,Monaco,monospace;
            font-weight:700;
        }
        .message{
            color:var(--muted);
            line-height:1.45;
        }
        .empty{
            padding:20px;
            border:1px dashed var(--line);
            border-radius:16px;
            color:var(--muted);
            background:#fbfdff;
        }
        @media (max-width: 980px){
            .layout{
                grid-template-columns:1fr;
            }
            .panel{
                position:static;
            }
            .summary-grid{
                grid-template-columns:repeat(2,minmax(0,1fr));
            }
        }
        @media (max-width: 640px){
            body{
                padding:20px 12px 36px;
            }
            .hero h1{
                font-size:24px;
            }
            .summary-grid{
                grid-template-columns:1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="hero">
            <h1>Bulk Product Writer</h1>
            <p>Tool nay chay theo batch nho do trinh duyet dieu phoi, giup tranh request timeout khi tao/cap nhat nhieu san pham, nhieu anh va nhieu taxonomy trong mot lan import.</p>
        </div>

        <div class="layout">
            <div class="card panel">
                <h2>Nhap file va tuy chon</h2>
                <form method="post" enctype="multipart/form-data" id="hpt-bulk-writer-form">
                    <?php wp_nonce_field('hpt_bulk_writer_submit', 'hpt_bulk_writer_nonce'); ?>
                    <div class="field">
                        <label for="excel_file">File Excel</label>
                        <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls" required>
                        <div class="field-note">Doc truc tiep file upload, khong di chuyen sang thu muc tam rieng.</div>
                    </div>

                    <div class="options">
                        <label class="option">
                            <input type="checkbox" name="create_missing" value="1" <?php checked($create_missing); ?>>
                            <span>
                                <strong>Tao san pham moi neu SKU chua ton tai</strong>
                                <span>Neu tat, cac SKU moi se bi bo qua.</span>
                            </span>
                        </label>

                        <label class="option">
                            <input type="checkbox" name="update_existing" value="1" <?php checked($update_existing); ?>>
                            <span>
                                <strong>Cap nhat san pham da ton tai</strong>
                                <span>Tool se tim theo SKU bang lookup WooCommerce va fallback doc truc tiep `_sku` trong DB.</span>
                            </span>
                        </label>

                        <label class="option">
                            <input type="checkbox" name="import_images" value="1" <?php checked($import_images); ?>>
                            <span>
                                <strong>Import anh dai dien va gallery</strong>
                                <span>Anh chi duoc cap nhat khi cot `M` co URL hop le.</span>
                            </span>
                        </label>

                        <label class="option">
                            <input type="checkbox" name="preserve_on_empty" value="1" <?php checked($preserve_empty); ?>>
                            <span>
                                <strong>Giu du lieu cu neu o Excel trong</strong>
                                <span>Khuyen nghi bat de tranh ghi de nham overview, rank math, link tai lieu va taxonomy.</span>
                            </span>
                        </label>
                    </div>

                    <div class="submit-row">
                        <button class="button" type="submit" id="hpt-bulk-writer-submit">Tai len va xu ly theo batch</button>
                    </div>
                </form>

                <div class="mapping">
                    <h3>Mapping cot hien tai</h3>
                    <ul>
                        <li><strong>Tên sản phẩm</strong>: Ten san pham</li>
                        <li><strong>Mã sản phẩm</strong>: SKU</li>
                        <li><strong>Đường dẫn sản phẩm</strong>: Link san pham, dung de fallback tach slug neu can</li>
                        <li><strong>Ngày xuất bản</strong>: Ngay dang bai, ho tro ca `future`</li>
                        <li><strong>Trạng thái (publish, draft, private, future)</strong>: Trang thai san pham</li>
                        <li><strong>Thông số kỹ thuật</strong>: Mo ta dai / HTML ky thuat</li>
                        <li><strong>Mô tả ngắn</strong>: Short description</li>
                        <li><strong>Đường dẫn là mã sản phẩm</strong>: Slug</li>
                        <li><strong>Giá bán thường</strong>: Regular price</li>
                        <li><strong>Số lượng tồn kho</strong>: Stock quantity</li>
                        <li><strong>Trạng thái còn hàng?</strong>: `instock`, `outofstock`, `onbackorder`</li>
                        <li><strong>Danh mục</strong>: Ten|Ten hoac `ten1|ten2,id1|id2`</li>
                        <li><strong>Ảnh sản phẩm</strong>: URL anh, cach nhau bang `|` neu co gallery</li>
                        <li><strong>Caption ảnh</strong>: Alt/caption anh</li>
                        <li><strong>meta_rank_math_description</strong>: Meta description</li>
                        <li><strong>meta_focus_keyword</strong>: Focus keyword</li>
                        <li><strong>Tổng Quan</strong>: Field overview</li>
                        <li><strong>Hãng</strong>: Thuong hieu</li>
                        <li><strong>Thẻ (cách nhau 1 dấu phẩy)</strong>: Tags</li>
                        <li><strong>Ảnh kích thước (link ảnh .webP)</strong>: Anh sinh field `dimension`</li>
                        <li><strong>rank_math_title</strong>: SEO title</li>
                        <li><strong>Link PDF</strong>: Toi da 3 URL cach nhau bang dau phay, theo thu tu Catalog, Manual, Datasheet</li>
                    </ul>
                </div>
            </div>

            <div class="card results-card">
                <h2>Ket qua xu ly</h2>

                <div class="progress" id="hpt-bulk-writer-progress">
                    <div class="progress-head">
                        <strong id="hpt-bulk-writer-progress-title">Dang cho thao tac...</strong>
                        <span id="hpt-bulk-writer-progress-percent">0%</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" id="hpt-bulk-writer-progress-fill"></div>
                    </div>
                    <div class="progress-meta" id="hpt-bulk-writer-progress-meta">Tool se chia batch nho de tranh timeout.</div>
                    <div class="progress-errors" id="hpt-bulk-writer-progress-errors" hidden></div>
                </div>

                <div class="alert error" id="hpt-bulk-writer-global-error" <?php echo $global_error !== '' ? '' : 'hidden'; ?>>
                    <?php echo esc_html($global_error); ?>
                </div>

                <div class="summary-grid" id="hpt-bulk-writer-summary" <?php echo ($submitted && !empty($summary)) ? '' : 'hidden'; ?>>
                    <div class="summary-item">
                        <b id="hpt-bulk-writer-summary-success"><?php echo (int) ($summary['success'] ?? 0); ?></b>
                        <span>Thanh cong</span>
                    </div>
                    <div class="summary-item">
                        <b id="hpt-bulk-writer-summary-warning"><?php echo (int) ($summary['warning'] ?? 0); ?></b>
                        <span>Canh bao</span>
                    </div>
                    <div class="summary-item">
                        <b id="hpt-bulk-writer-summary-skipped"><?php echo (int) ($summary['skipped'] ?? 0); ?></b>
                        <span>Bo qua</span>
                    </div>
                    <div class="summary-item">
                        <b id="hpt-bulk-writer-summary-error"><?php echo (int) ($summary['error'] ?? 0); ?></b>
                        <span>Loi</span>
                    </div>
                </div>

                <a
                    class="report-link"
                    id="hpt-bulk-writer-report-link"
                    href="<?php echo esc_url($report_url); ?>"
                    download
                    <?php echo $report_url !== '' ? '' : 'hidden'; ?>
                >Tai file bao cao XLSX</a>
                <div class="report-path" id="hpt-bulk-writer-report-path" <?php echo $report_path !== '' ? '' : 'hidden'; ?>>
                    <?php echo esc_html($report_path); ?>
                </div>

                <div class="alert info" id="hpt-bulk-writer-info" <?php echo (!$submitted && $global_error === '') ? '' : 'hidden'; ?>>
                    Chua co lan xu ly nao trong request hien tai.
                </div>

                <div class="empty" id="hpt-bulk-writer-empty" <?php echo ($submitted && empty($results) && $global_error === '') ? '' : 'hidden'; ?>>
                    Khong co dong du lieu hop le de xu ly.
                </div>

                <div class="table-wrap" id="hpt-bulk-writer-table-wrap" <?php echo !empty($results) ? '' : 'hidden'; ?>>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>SKU</th>
                                <th>Ket qua</th>
                                <th>Hanh dong</th>
                                <th>Product ID</th>
                                <th>Chi tiet</th>
                            </tr>
                        </thead>
                        <tbody id="hpt-bulk-writer-results-body">
                            <?php foreach ($results as $index => $result) : ?>
                                <?php
                                $status = (string) ($result['status'] ?? 'info');
                                $pill_class = 'status-skipped';

                                if ($status === 'success') {
                                    $pill_class = 'status-success';
                                } elseif ($status === 'warning') {
                                    $pill_class = 'status-warning';
                                } elseif ($status === 'error') {
                                    $pill_class = 'status-error';
                                }
                                ?>
                                <tr>
                                    <td><?php echo (int) $index + 1; ?></td>
                                    <td class="sku"><?php echo esc_html($result['sku'] ?? ''); ?></td>
                                    <td><span class="status-pill <?php echo esc_attr($pill_class); ?>"><?php echo esc_html($status); ?></span></td>
                                    <td><?php echo esc_html($result['action'] ?? ''); ?></td>
                                    <td><?php echo (int) ($result['product_id'] ?? 0); ?></td>
                                    <td class="message"><?php echo esc_html($result['message'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.HPTBulkWriterConfig = <?php echo wp_json_encode(array(
            'endpoint' => hpt_bulk_writer_get_self_url(),
            'nonce'    => $request_nonce,
        )); ?>;

        (function () {
            var config = window.HPTBulkWriterConfig || null;

            if (!config) {
                return;
            }

            var state = {
                results: [],
                resultKeys: {},
                summary: createEmptySummary()
            };

            document.addEventListener('DOMContentLoaded', function () {
                var form = document.getElementById('hpt-bulk-writer-form');
                var button = document.getElementById('hpt-bulk-writer-submit');

                if (!form || !button) {
                    return;
                }

                form.addEventListener('submit', async function (event) {
                    event.preventDefault();

                    if (form.dataset.running === '1') {
                        return;
                    }

                    var fileInput = document.getElementById('excel_file');

                    if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                        showGlobalError('Chua chon file Excel hop le.');
                        return;
                    }

                    var jobToken = '';

                    form.dataset.running = '1';
                    toggleButton(button, true, 'Dang khoi tao batch...');
                    resetState();
                    showInfo('Dang tai file va chia batch nho...');
                    setProgress(true, 'Dang khoi tao batch...', 5, 'Dang phan tich file Excel va tao cac lo xu ly nho.', []);

                    try {
                        var initPayload = new FormData(form);
                        var initData = await requestAction('init_job', initPayload);
                        jobToken = initData.job_token || '';

                        if (!initData.total_batches || initData.total_batches < 1) {
                            setProgress(true, 'Hoan tat', 100, 'Khong co dong du lieu hop le de xu ly.', []);
                            showEmpty(true);
                            hideInfo();
                            return;
                        }

                        var completedBatches = 0;

                        hideInfo();
                        setProgress(true, 'Dang xu ly tung batch...', 10, '0/' + initData.total_batches + ' batch | 0/' + initData.total_rows + ' dong', []);

                        await runConcurrent(initData.total_batches, initData.concurrency || 1, async function (batchIndex) {
                            var batchData = await withRetry(function () {
                                return requestAction('process_batch', {
                                    job_token: jobToken,
                                    batch_index: String(batchIndex)
                                });
                            }, 2);

                            completedBatches += 1;
                            mergeSummary(state.summary, batchData.summary || {});
                            appendResults(batchData.results || []);
                            renderSummary(state.summary);

                            setProgress(
                                true,
                                'Dang xu ly tung batch...',
                                10 + Math.round((completedBatches / initData.total_batches) * 80),
                                completedBatches + '/' + initData.total_batches + ' batch | ' + getSummaryTotal(state.summary) + '/' + initData.total_rows + ' dong',
                                collectLatestErrors()
                            );
                        });

                        var finalData = await requestAction('finalize_job', {
                            job_token: jobToken
                        });

                        state.summary = finalData.summary || state.summary;
                        renderSummary(state.summary);

                        if (!state.results.length && Array.isArray(finalData.results)) {
                            appendResults(finalData.results);
                        }

                        if (finalData.report_url) {
                            showReport(finalData.report_url, finalData.report_path || '');
                        }

                        showEmpty(state.results.length === 0);
                        setProgress(true, 'Xu ly hoan tat', 100, buildSummaryText(state.summary), collectLatestErrors());
                    } catch (error) {
                        showGlobalError(error && error.message ? error.message : 'Co loi khi xu ly batch.');
                        setProgress(true, 'Xu ly that bai', 100, error && error.message ? error.message : 'Co loi khi xu ly batch.', collectLatestErrors(true, error && error.message ? error.message : 'Co loi khi xu ly batch.'));
                    } finally {
                        if (jobToken) {
                            requestAction('cleanup_job', {
                                job_token: jobToken
                            }).catch(function () {
                                return null;
                            });
                        }

                        form.dataset.running = '0';
                        toggleButton(button, false);
                    }
                });
            });

            function requestAction(action, payload) {
                var formData = payload instanceof FormData ? payload : new FormData();

                if (!(payload instanceof FormData)) {
                    Object.keys(payload || {}).forEach(function (key) {
                        appendFormValue(formData, key, payload[key]);
                    });
                }

                formData.append('hpt_bulk_writer_request_mode', 'async');
                formData.append('hpt_bulk_writer_action', action);
                formData.append('nonce', config.nonce);

                return fetch(config.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                }).then(function (response) {
                    return response.text().then(function (responseText) {
                        var json = null;

                        try {
                            json = JSON.parse(responseText);
                        } catch (error) {
                            throw new Error(extractHtmlErrorMessage(responseText) || (responseText || 'Phan hoi may chu khong hop le.').slice(0, 500));
                        }

                        if (!response.ok || !json || !json.success) {
                            var requestError = new Error((json && json.data && json.data.message) || 'Yeu cau that bai.');
                            requestError.status = response.status;
                            requestError.retryable = response.status === 408 || response.status === 425 || response.status === 429 || response.status >= 500;
                            throw requestError;
                        }

                        return json.data || {};
                    });
                });
            }

            function withRetry(task, retries) {
                var maxRetries = typeof retries === 'number' ? retries : 1;
                var attempt = 0;

                function run() {
                    return task().catch(function (error) {
                        attempt += 1;

                        if (attempt > maxRetries || (error && error.retryable === false)) {
                            throw error;
                        }

                        return sleep(800 * attempt).then(run);
                    });
                }

                return run();
            }

            function runConcurrent(total, concurrency, worker) {
                var limit = Math.max(1, Math.min(concurrency || 1, total || 1));
                var cursor = 0;
                var workers = Array.from({ length: limit }, function () {
                    return (async function () {
                        while (cursor < total) {
                            var current = cursor;
                            cursor += 1;
                            await worker(current);
                        }
                    })();
                });

                return Promise.all(workers);
            }

            function resetState() {
                state.results = [];
                state.resultKeys = {};
                state.summary = createEmptySummary();
                renderSummary(state.summary, true);
                renderResults();
                showReport('', '');
                showGlobalError('');
                showEmpty(false);
            }

            function createEmptySummary() {
                return {
                    success: 0,
                    warning: 0,
                    skipped: 0,
                    error: 0
                };
            }

            function mergeSummary(target, source) {
                ['success', 'warning', 'skipped', 'error'].forEach(function (key) {
                    target[key] = Number(target[key] || 0) + Number((source && source[key]) || 0);
                });
            }

            function renderSummary(summary, forceHide) {
                var summaryBox = document.getElementById('hpt-bulk-writer-summary');

                if (!summaryBox) {
                    return;
                }

                if (forceHide) {
                    summaryBox.hidden = true;
                    return;
                }

                document.getElementById('hpt-bulk-writer-summary-success').textContent = String(Number(summary.success || 0));
                document.getElementById('hpt-bulk-writer-summary-warning').textContent = String(Number(summary.warning || 0));
                document.getElementById('hpt-bulk-writer-summary-skipped').textContent = String(Number(summary.skipped || 0));
                document.getElementById('hpt-bulk-writer-summary-error').textContent = String(Number(summary.error || 0));
                summaryBox.hidden = false;
            }

            function appendResults(results) {
                (Array.isArray(results) ? results : []).forEach(function (result) {
                    var key = String(result.row_number || 0) + '|' + String(result.sku || '');

                    if (state.resultKeys[key]) {
                        return;
                    }

                    state.resultKeys[key] = true;
                    state.results.push(result);
                });

                state.results.sort(function (left, right) {
                    return Number(left.row_number || 0) - Number(right.row_number || 0);
                });

                renderResults();
            }

            function renderResults() {
                var body = document.getElementById('hpt-bulk-writer-results-body');
                var tableWrap = document.getElementById('hpt-bulk-writer-table-wrap');

                if (!body || !tableWrap) {
                    return;
                }

                if (!state.results.length) {
                    body.innerHTML = '';
                    tableWrap.hidden = true;
                    return;
                }

                body.innerHTML = state.results.map(function (result, index) {
                    var status = String(result.status || 'skipped');
                    var pillClass = 'status-skipped';

                    if (status === 'success') {
                        pillClass = 'status-success';
                    } else if (status === 'warning') {
                        pillClass = 'status-warning';
                    } else if (status === 'error') {
                        pillClass = 'status-error';
                    }

                    return [
                        '<tr>',
                        '<td>' + escapeHtml(String(index + 1)) + '</td>',
                        '<td class="sku">' + escapeHtml(String(result.sku || '')) + '</td>',
                        '<td><span class="status-pill ' + escapeHtml(pillClass) + '">' + escapeHtml(status) + '</span></td>',
                        '<td>' + escapeHtml(String(result.action || '')) + '</td>',
                        '<td>' + escapeHtml(String(result.product_id || 0)) + '</td>',
                        '<td class="message">' + escapeHtml(String(result.message || '')) + '</td>',
                        '</tr>'
                    ].join('');
                }).join('');

                tableWrap.hidden = false;
            }

            function setProgress(active, title, percent, meta, errors) {
                var box = document.getElementById('hpt-bulk-writer-progress');
                var titleEl = document.getElementById('hpt-bulk-writer-progress-title');
                var percentEl = document.getElementById('hpt-bulk-writer-progress-percent');
                var fillEl = document.getElementById('hpt-bulk-writer-progress-fill');
                var metaEl = document.getElementById('hpt-bulk-writer-progress-meta');
                var errorsEl = document.getElementById('hpt-bulk-writer-progress-errors');
                var safeErrors = Array.isArray(errors) ? errors.filter(Boolean).slice(0, 8) : [];

                if (!box || !titleEl || !percentEl || !fillEl || !metaEl || !errorsEl) {
                    return;
                }

                box.classList.toggle('is-active', !!active);
                titleEl.textContent = title || '';
                percentEl.textContent = String(Math.max(0, Math.min(100, Number(percent || 0)))) + '%';
                fillEl.style.width = String(Math.max(0, Math.min(100, Number(percent || 0)))) + '%';
                metaEl.textContent = meta || '';

                if (safeErrors.length) {
                    errorsEl.hidden = false;
                    errorsEl.innerHTML = safeErrors.map(function (errorText) {
                        return '<div>' + escapeHtml(String(errorText)) + '</div>';
                    }).join('');
                } else {
                    errorsEl.hidden = true;
                    errorsEl.innerHTML = '';
                }
            }

            function collectLatestErrors(forceInclude, errorText) {
                var errors = state.results
                    .filter(function (result) {
                        return result && (result.status === 'error' || result.status === 'warning');
                    })
                    .slice(-5)
                    .map(function (result) {
                        return (result.sku ? '[' + result.sku + '] ' : '') + result.message;
                    });

                if (forceInclude && errorText) {
                    errors.unshift(errorText);
                }

                return errors;
            }

            function showGlobalError(message) {
                var box = document.getElementById('hpt-bulk-writer-global-error');

                if (!box) {
                    return;
                }

                if (!message) {
                    box.hidden = true;
                    box.textContent = '';
                    return;
                }

                box.hidden = false;
                box.textContent = message;
            }

            function showReport(url, path) {
                var link = document.getElementById('hpt-bulk-writer-report-link');
                var pathEl = document.getElementById('hpt-bulk-writer-report-path');

                if (!link || !pathEl) {
                    return;
                }

                if (!url) {
                    link.hidden = true;
                    pathEl.hidden = true;
                    link.removeAttribute('href');
                    pathEl.textContent = '';
                    return;
                }

                link.hidden = false;
                link.href = url;
                pathEl.hidden = !path;
                pathEl.textContent = path || '';
            }

            function showInfo(message) {
                var info = document.getElementById('hpt-bulk-writer-info');

                if (!info) {
                    return;
                }

                if (!message) {
                    info.hidden = true;
                    info.textContent = '';
                    return;
                }

                info.hidden = false;
                info.textContent = message;
            }

            function hideInfo() {
                showInfo('');
            }

            function showEmpty(visible) {
                var emptyBox = document.getElementById('hpt-bulk-writer-empty');

                if (emptyBox) {
                    emptyBox.hidden = !visible;
                }
            }

            function buildSummaryText(summary) {
                return [
                    'Thanh cong: ' + Number(summary.success || 0),
                    'Canh bao: ' + Number(summary.warning || 0),
                    'Bo qua: ' + Number(summary.skipped || 0),
                    'Loi: ' + Number(summary.error || 0)
                ].join(' | ');
            }

            function getSummaryTotal(summary) {
                return Number(summary.success || 0)
                    + Number(summary.warning || 0)
                    + Number(summary.skipped || 0)
                    + Number(summary.error || 0);
            }

            function toggleButton(button, disabled, busyText) {
                if (!button) {
                    return;
                }

                if (!button.dataset.originalText) {
                    button.dataset.originalText = button.textContent;
                }

                button.disabled = !!disabled;
                button.textContent = disabled ? (busyText || button.dataset.originalText) : button.dataset.originalText;
            }

            function appendFormValue(formData, key, value) {
                if (Array.isArray(value)) {
                    value.forEach(function (item) {
                        formData.append(key, item);
                    });
                    return;
                }

                formData.append(key, value == null ? '' : String(value));
            }

            function sleep(ms) {
                return new Promise(function (resolve) {
                    window.setTimeout(resolve, ms);
                });
            }

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function extractHtmlErrorMessage(html) {
                if (!html || html.indexOf('<') === -1) {
                    return '';
                }

                var container = document.createElement('div');
                container.innerHTML = html;

                var messages = [];
                var title = container.querySelector('title');

                if (title && title.textContent) {
                    messages.push(title.textContent.trim());
                }

                Array.prototype.slice.call(container.querySelectorAll('p')).forEach(function (paragraph) {
                    var text = (paragraph.textContent || '').trim();

                    if (text) {
                        messages.push(text);
                    }
                });

                messages = messages.filter(Boolean).filter(function (item, index, items) {
                    return items.indexOf(item) === index;
                });

                return messages.slice(0, 4).join(' | ');
            }
        })();
    </script>
</body>
</html>
    <?php
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['hpt_bulk_writer_request_mode'])
    && $_POST['hpt_bulk_writer_request_mode'] === 'async'
) {
    hpt_bulk_writer_handle_async_request();
}

$state = array(
    'submitted' => false,
    'results'   => array(),
    'summary'   => array(),
    'report_url'  => '',
    'report_path' => '',
    'global_error' => '',
    'options' => array(
        'create_missing'    => true,
        'update_existing'   => true,
        'import_images'     => true,
        'preserve_on_empty' => true,
    ),
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $state['submitted'] = true;
    $state['options']   = array(
        'create_missing'    => !empty($_POST['create_missing']),
        'update_existing'   => !empty($_POST['update_existing']),
        'import_images'     => !empty($_POST['import_images']),
        'preserve_on_empty' => !empty($_POST['preserve_on_empty']),
    );

    try {
        if (
            !isset($_POST['hpt_bulk_writer_nonce']) ||
            !wp_verify_nonce($_POST['hpt_bulk_writer_nonce'], 'hpt_bulk_writer_submit')
        ) {
            throw new RuntimeException('Nonce khong hop le. Vui long tai lai trang va thu lai.');
        }

        if (!isset($_FILES['excel_file']) || !is_array($_FILES['excel_file'])) {
            throw new RuntimeException('Khong nhan duoc file upload.');
        }

        if ((int) $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload file that bai. Ma loi: ' . (int) $_FILES['excel_file']['error']);
        }

        $tmp_name = (string) ($_FILES['excel_file']['tmp_name'] ?? '');

        if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
            throw new RuntimeException('File upload khong hop le.');
        }

        $spreadsheet = IOFactory::load($tmp_name);
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = $sheet->toArray('', true, true, true);
        $last_row    = !empty($rows) ? max(array_keys($rows)) : 0;

        if (empty($rows) || $last_row < 2) {
            throw new RuntimeException('File Excel khong co du lieu hop le.');
        }

        $results    = array();
        $header_row = isset($rows[1]) && is_array($rows[1]) ? $rows[1] : array();
        $header_map = hpt_bulk_writer_resolve_header_map($header_row);

        hpt_bulk_writer_begin_bulk_runtime_optimizations();

        if (function_exists('wp_defer_term_counting')) {
            wp_defer_term_counting(true);
        }

        if (function_exists('wp_defer_comment_counting')) {
            wp_defer_comment_counting(true);
        }

        try {
            for ($row_number = 2; $row_number <= $last_row; $row_number++) {
                $row = isset($rows[$row_number]) && is_array($rows[$row_number]) ? $rows[$row_number] : array();

                if (empty(array_filter($row, 'hpt_bulk_writer_has_value'))) {
                    continue;
                }

                $mapped_row = hpt_bulk_writer_parse_row_legacy($row_number, $row, $header_map);

                if (!empty($mapped_row['skip'])) {
                    $results[] = array(
                        'status'     => 'skipped',
                        'action'     => 'bo qua',
                        'sku'        => $mapped_row['sku'],
                        'product_id' => 0,
                        'message'    => $mapped_row['message'],
                    );
                    continue;
                }

                try {
                    $results[] = hpt_bulk_writer_process_legacy_row($mapped_row, $state['options']);
                } catch (Throwable $exception) {
                    $results[] = array(
                        'status'     => 'error',
                        'action'     => 'loi',
                        'row_number' => (int) ($mapped_row['row_number'] ?? 0),
                        'sku'        => $mapped_row['sku'],
                        'product_id' => 0,
                        'message'    => $exception->getMessage(),
                    );
                }
            }
        } finally {
            if (function_exists('wp_defer_term_counting')) {
                wp_defer_term_counting(false);
            }

            if (function_exists('wp_defer_comment_counting')) {
                wp_defer_comment_counting(false);
            }

            hpt_bulk_writer_end_bulk_runtime_optimizations();
        }

        $summary = array(
            'success' => 0,
            'warning' => 0,
            'skipped' => 0,
            'error'   => 0,
        );

        foreach ($results as $result) {
            $status = isset($result['status']) ? (string) $result['status'] : 'error';

            if (!isset($summary[$status])) {
                $summary[$status] = 0;
            }

            $summary[$status]++;
        }

        $state['results']  = $results;
        $state['summary']  = $summary;

        if (!empty($results)) {
            $report = hpt_bulk_writer_build_report($results, $_FILES['excel_file']['name'] ?? 'bulk-report.xlsx');
            $state['report_url']  = $report['url'];
            $state['report_path'] = $report['path'];
        }
    } catch (Throwable $exception) {
        $state['global_error'] = $exception->getMessage();
    }
}

hpt_bulk_writer_render_page($state);
