<?php
/**
 * HPT — Engine Import/Export sản phẩm nâng cao (chạy theo batch, không cần cron).
 *
 * Mục tiêu: xử lý vài trăm nghìn → hàng triệu sản phẩm mà không timeout, không
 * tràn RAM, có resume + log lỗi theo dòng + tiến trình.
 *
 * Kiến trúc: tầng job/state/error tự viết (bảng DB) BỌC lại chính importer/exporter
 * CSV của WooCommerce để giữ nguyên 100% logic parsing/CRUD chuẩn WooCommerce
 * (giá, kho, category, attribute, variation, ảnh) cùng các hook tùy chỉnh trong
 * data-product.php. AJAX chỉ tạo job và đẩy từng batch — không có request HTTP dài.
 *
 * @package flatsome-child
 */

if (!defined('ABSPATH')) {
    exit;
}

/* Keep the standard WooCommerce exporter/importer useful for SEO maintenance. */
function hpt_pie_add_slug_url_export_columns($columns) {
    $columns = (array) $columns;

    if (!isset($columns['slug'])) {
        $columns['slug'] = 'Slug sản phẩm';
    }
    if (!isset($columns['permalink'])) {
        $columns['permalink'] = 'Đường dẫn sản phẩm';
    }

    return $columns;
}
add_filter('woocommerce_product_export_column_names', 'hpt_pie_add_slug_url_export_columns', 20);
add_filter('woocommerce_product_export_product_default_columns', 'hpt_pie_add_slug_url_export_columns', 20);

function hpt_pie_export_slug($value, $product) {
    return $product instanceof WC_Product ? $product->get_slug('edit') : $value;
}
add_filter('woocommerce_product_export_product_column_slug', 'hpt_pie_export_slug', 10, 2);

function hpt_pie_export_permalink($value, $product) {
    return $product instanceof WC_Product ? get_permalink($product->get_id()) : $value;
}
add_filter('woocommerce_product_export_product_column_permalink', 'hpt_pie_export_permalink', 10, 2);

function hpt_pie_add_slug_import_mapping($options) {
    $options = (array) $options;
    if (!isset($options['slug'])) {
        $options['slug'] = 'Slug sản phẩm';
    }
    return $options;
}
add_filter('woocommerce_csv_product_import_mapping_options', 'hpt_pie_add_slug_import_mapping', 20);

/* ============================================================
 * 1) CẤU HÌNH + BẢNG DỮ LIỆU
 * ============================================================ */

function hpt_pie_db_version() {
    return '1.1.0';
}

function hpt_pie_jobs_table() {
    global $wpdb;
    return $wpdb->prefix . 'hpt_product_jobs';
}

function hpt_pie_errors_table() {
    global $wpdb;
    return $wpdb->prefix . 'hpt_product_job_errors';
}

/**
 * Batch size cấu hình được qua filter (tinh chỉnh theo sức server).
 */
function hpt_pie_import_batch_size() {
	return max(5, min(100, (int) apply_filters('hpt_pie_import_batch_size', 40)));
}

/**
 * Return the parsed PHP memory limit in bytes, or zero when it is unlimited/unknown.
 *
 * @return int
 */
function hpt_pie_memory_limit_bytes() {
    $limit = trim((string) ini_get('memory_limit'));

    if ($limit === '' || $limit === '-1') {
        return 0;
    }

    $unit = strtolower(substr($limit, -1));
    $size = (float) $limit;
    $multipliers = array('g' => 1073741824, 'm' => 1048576, 'k' => 1024);

    if (isset($multipliers[$unit])) {
        $size *= $multipliers[$unit];
    }

    return max(0, (int) $size);
}

/**
 * SHA-256 fingerprint of file metadata and its first/last 64 KiB (not a full-file digest).
 *
 * @param string $path File path.
 * @return string|false
 */
function hpt_pie_file_fingerprint($path) {
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return false;
    }

    $stat = fstat($handle);
    if (!is_array($stat) || !isset($stat['size'], $stat['mtime'])) {
        fclose($handle);
        return false;
    }

    $sample_size = 65536;
    $head = fread($handle, min($sample_size, (int) $stat['size']));
    $tail = '';
    if ((int) $stat['size'] > $sample_size && 0 === fseek($handle, max(0, (int) $stat['size'] - $sample_size))) {
        $tail = fread($handle, $sample_size);
    }
    fclose($handle);

    if (!is_string($head) || !is_string($tail)) {
        return false;
    }

    return hash('sha256', (int) $stat['size'] . ':' . (int) $stat['mtime'] . "\0" . $head . "\0" . $tail);
}

/**
 * Adjust the next import batch using only measured request time and PHP peak memory.
 *
 * @param int $current_batch Current batch size.
 * @param int $elapsed_ms Batch duration in milliseconds.
 * @param int $peak_bytes PHP peak memory in bytes.
 * @param int $memory_limit_bytes PHP memory limit in bytes; zero means unknown/unlimited.
 * @return int
 */
function hpt_pie_next_import_batch_size($current_batch, $elapsed_ms, $peak_bytes, $memory_limit_bytes = 0) {
    $minimum = max(1, (int) apply_filters('hpt_pie_import_batch_min', 5));
    $maximum = max($minimum, (int) apply_filters('hpt_pie_import_batch_max', 100));
    $current = max($minimum, min($maximum, (int) $current_batch));
    $slow = (int) $elapsed_ms > 8000;
    $memory_pressure = $memory_limit_bytes > 0 && $peak_bytes >= (int) ($memory_limit_bytes * 0.70);

    if ($slow || $memory_pressure) {
        return max($minimum, (int) floor($current / 2));
    }

    if ((int) $elapsed_ms > 0 && (int) $elapsed_ms < 2000 && $memory_limit_bytes > 0 && $peak_bytes < (int) ($memory_limit_bytes * 0.40)) {
        return min($maximum, $current + max(1, (int) floor($current * 0.20)));
    }

    return $current;
}

function hpt_pie_export_batch_size() {
    return max(20, min(500, (int) apply_filters('hpt_pie_export_batch_size', 200)));
}

function hpt_pie_sku_export_batch_size() {
    return max(50, min(500, (int) apply_filters('hpt_pie_sku_export_batch_size', 250)));
}

function hpt_pie_sku_export_workers() {
	return max(1, min(2, (int) apply_filters('hpt_pie_sku_export_workers', 1)));
}

function hpt_pie_user_can_manage() {
    return current_user_can('manage_woocommerce') || current_user_can('import');
}

/**
 * Tự tạo/migrate 2 bảng job + error log (nhỏ, an toàn). Gọi ở admin_init theo cờ version.
 * KHÔNG tự thêm index lên wp_postmeta ở đây (bảng lớn, dễ khóa) — để thao tác thủ công.
 */
function hpt_pie_maybe_install() {
    if (get_option('hpt_pie_db_version') === hpt_pie_db_version()) {
        return;
    }

    hpt_pie_install_tables();
    update_option('hpt_pie_db_version', hpt_pie_db_version(), false);
}
add_action('admin_init', 'hpt_pie_maybe_install');

function hpt_pie_install_tables() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $jobs            = hpt_pie_jobs_table();
    $errors          = hpt_pie_errors_table();

    $sql_jobs = "CREATE TABLE {$jobs} (
        job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        type varchar(20) NOT NULL DEFAULT 'import',
        status varchar(20) NOT NULL DEFAULT 'pending',
        mode varchar(20) NOT NULL DEFAULT 'upsert',
        file_path text NULL,
        download_path text NULL,
        total_rows bigint(20) unsigned NOT NULL DEFAULT 0,
        processed_rows bigint(20) unsigned NOT NULL DEFAULT 0,
        success_count bigint(20) unsigned NOT NULL DEFAULT 0,
        updated_count bigint(20) unsigned NOT NULL DEFAULT 0,
        failed_count bigint(20) unsigned NOT NULL DEFAULT 0,
        skipped_count bigint(20) unsigned NOT NULL DEFAULT 0,
        unchanged_count bigint(20) unsigned NOT NULL DEFAULT 0,
        current_offset bigint(20) unsigned NOT NULL DEFAULT 0,
        percent int(11) NOT NULL DEFAULT 0,
        config longtext NULL,
        error_message text NULL,
        created_by bigint(20) unsigned NOT NULL DEFAULT 0,
        started_at datetime NULL,
        finished_at datetime NULL,
        updated_at datetime NULL,
        PRIMARY KEY  (job_id),
        KEY status (status),
        KEY type_status (type, status)
    ) {$charset_collate};";

    $sql_errors = "CREATE TABLE {$errors} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        job_id bigint(20) unsigned NOT NULL,
        row_num bigint(20) unsigned NOT NULL DEFAULT 0,
        sku varchar(100) NULL,
        error_message text NULL,
        raw_data longtext NULL,
        created_at datetime NULL,
        PRIMARY KEY  (id),
        KEY job_id (job_id),
        KEY job_sku (job_id, sku)
    ) {$charset_collate};";

    dbDelta($sql_jobs);
    dbDelta($sql_errors);
}

/* ============================================================
 * 2) JOB / ERROR — CRUD
 * ============================================================ */

function hpt_pie_now() {
    return current_time('mysql');
}

function hpt_pie_create_job($data) {
    global $wpdb;

    $defaults = array(
        'type'       => 'import',
        'status'     => 'running',
        'mode'       => 'upsert',
        'file_path'  => '',
        'config'     => array(),
        'created_by' => get_current_user_id(),
        'started_at' => hpt_pie_now(),
        'updated_at' => hpt_pie_now(),
    );
    $data = wp_parse_args($data, $defaults);

    if (is_array($data['config'])) {
        $data['config'] = wp_json_encode($data['config']);
    }

    $wpdb->insert(hpt_pie_jobs_table(), $data);

    return (int) $wpdb->insert_id;
}

function hpt_pie_get_job($job_id) {
    global $wpdb;

    $job_id = absint($job_id);
    if ($job_id <= 0) {
        return null;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM ' . hpt_pie_jobs_table() . ' WHERE job_id = %d', $job_id),
        ARRAY_A
    );

    if (!$row) {
        return null;
    }

    $row['config_arr'] = json_decode((string) $row['config'], true);
    if (!is_array($row['config_arr'])) {
        $row['config_arr'] = array();
    }

    return $row;
}

function hpt_pie_update_job($job_id, $data) {
    global $wpdb;

    $data['updated_at'] = hpt_pie_now();

    if (isset($data['config']) && is_array($data['config'])) {
        $data['config'] = wp_json_encode($data['config']);
    }

    $wpdb->update(hpt_pie_jobs_table(), $data, array('job_id' => absint($job_id)));
}

/**
 * Ghi lỗi theo dòng. Giới hạn số bản ghi/job để bảng không phình vô hạn.
 */
function hpt_pie_log_error($job_id, $row_number, $sku, $message, $raw_data = '') {
    global $wpdb;

    static $counts = array();

    $job_id = absint($job_id);
    $max    = (int) apply_filters('hpt_pie_max_error_rows_per_job', 5000);

    if (!isset($counts[$job_id])) {
        $counts[$job_id] = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . hpt_pie_errors_table() . ' WHERE job_id = %d', $job_id)
        );
    }

    if ($counts[$job_id] >= $max) {
        return;
    }

    if (is_array($raw_data)) {
        $raw_data = wp_json_encode($raw_data);
    }
    $raw_data = mb_substr((string) $raw_data, 0, 4000);

    $wpdb->insert(hpt_pie_errors_table(), array(
        'job_id'       => $job_id,
        'row_num'      => absint($row_number),
        'sku'          => mb_substr((string) $sku, 0, 100),
        'error_message' => mb_substr((string) $message, 0, 1000),
        'raw_data'     => $raw_data,
        'created_at'   => hpt_pie_now(),
    ));

    $counts[$job_id]++;
}

function hpt_pie_get_job_errors($job_id, $limit = 200) {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            'SELECT row_num, sku, error_message FROM ' . hpt_pie_errors_table() .
            ' WHERE job_id = %d ORDER BY id ASC LIMIT %d',
            absint($job_id),
            absint($limit)
        ),
        ARRAY_A
    );
}

/* ============================================================
 * 3) THƯ MỤC LƯU FILE
 * ============================================================ */

function hpt_pie_storage_dir() {
    $upload = wp_upload_dir();
    $dir    = apply_filters('hpt_pie_storage_directory', trailingslashit($upload['basedir']) . 'hpt-product-ie');
    $dir    = untrailingslashit(wp_normalize_path($dir));

    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }

    // Apache/LiteSpeed protection; Nginx must also deny direct requests to this path.
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }
    if (!file_exists($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }

    return $dir;
}

function hpt_pie_private_path($name) {
    $dir = hpt_pie_storage_dir();
    return trailingslashit($dir) . ltrim(str_replace(array('..', '\\', '/'), '', (string) $name), '-');
}

/**
 * Acquire a per-job, process-scoped lock. flock releases automatically on PHP shutdown.
 * A batch is rejected rather than run concurrently if the lock cannot be acquired.
 *
 * @param int $job_id Job identifier.
 * @return resource|false
 */
function hpt_pie_acquire_job_lock($job_id) {
    $job_id = absint($job_id);
    if ($job_id <= 0) {
        return false;
    }

    $global_lock = fopen(hpt_pie_private_path('worker-global.lock'), 'c+');
    if (!$global_lock || !flock($global_lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($global_lock)) {
            fclose($global_lock);
        }
        return false;
    }

    $job_lock = fopen(hpt_pie_private_path('job-' . $job_id . '.lock'), 'c+');
    if (!$job_lock || !flock($job_lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($job_lock)) {
            fclose($job_lock);
        }
        flock($global_lock, LOCK_UN);
        fclose($global_lock);
        return false;
    }

    register_shutdown_function(function () use ($global_lock, $job_lock) {
        foreach (array($job_lock, $global_lock) as $lock) {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    });

    return $job_lock;
}

/**
 * Ensure a job is only visible to its creator or a WooCommerce manager.
 *
 * @param array $job Job row.
 * @return bool
 */
function hpt_pie_user_can_access_job($job) {
    return is_array($job)
        && ((int) ($job['created_by'] ?? 0) === get_current_user_id() || current_user_can('manage_woocommerce'));
}

/* ============================================================
 * 4) ĐĂNG KÝ MENU + ASSET + AJAX
 * ============================================================ */

function hpt_pie_register_page() {
    add_submenu_page(
        'edit.php?post_type=product',
        'Import/Export sản phẩm (Nâng cao)',
        'Import/Export (Nâng cao)',
        'manage_woocommerce',
        'hpt-product-ie',
        'hpt_pie_render_page'
    );
    add_submenu_page(
        'edit.php?post_type=product',
        'Xuất sản phẩm theo SKU TXT',
        'Xuất theo SKU TXT',
        'manage_woocommerce',
        'hpt-product-sku-export',
        'hpt_pie_render_sku_page'
    );
}
add_action('admin_menu', 'hpt_pie_register_page');

function hpt_pie_render_sku_page() {
    hpt_pie_render_page();
}

function hpt_pie_product_list_sku_export_notice() {
    if (!current_user_can('manage_woocommerce') || ($_GET['post_type'] ?? '') !== 'product' || !empty($_GET['page'])) {
        return;
    }
    $url = admin_url('edit.php?post_type=product&page=hpt-product-sku-export');
    echo '<div class="notice notice-info"><p><a class="button button-primary" href="' . esc_url($url) . '">Xuất sản phẩm theo file SKU TXT</a></p></div>';
}
add_action('admin_notices', 'hpt_pie_product_list_sku_export_notice');

function hpt_pie_get_settings() {
    return array(
        'ajax_url'           => admin_url('admin-ajax.php'),
        'nonce'              => wp_create_nonce('hpt_pie_ajax'),
        'import_batch_size'  => hpt_pie_import_batch_size(),
        'export_batch_size'  => hpt_pie_export_batch_size(),
        'sku_export_workers' => hpt_pie_sku_export_workers(),
        'active_jobs'        => hpt_pie_get_active_jobs_for_user(),
    );
}

function hpt_pie_admin_enqueue($hook_suffix) {
    if (!in_array($hook_suffix, array('product_page_hpt-product-ie', 'product_page_hpt-product-sku-export'), true)) {
        return;
    }

    wp_enqueue_script(
        'hpt-product-ie-admin',
        get_stylesheet_directory_uri() . '/assets/js/product-import-export-admin.js',
        array('jquery'),
        (string) filemtime(get_stylesheet_directory() . '/assets/js/product-import-export-admin.js'),
        true
    );

    wp_enqueue_style(
        'hpt-product-ie-admin',
        get_stylesheet_directory_uri() . '/assets/css/product-import-export-admin.css',
        array(),
        (string) filemtime(get_stylesheet_directory() . '/assets/css/product-import-export-admin.css')
    );

    wp_add_inline_script(
        'hpt-product-ie-admin',
        'window.HPTProductIE = ' . wp_json_encode(hpt_pie_get_settings()) . ';',
        'before'
    );
}
add_action('admin_enqueue_scripts', 'hpt_pie_admin_enqueue');

function hpt_pie_verify_ajax() {
    if (!hpt_pie_user_can_manage()) {
        wp_send_json_error(array('message' => 'Bạn không có quyền thực hiện thao tác này.'), 403);
    }
    check_ajax_referer('hpt_pie_ajax', 'nonce');
}

/* ============================================================
 * 5) IMPORT
 * ============================================================ */

function hpt_pie_import_compare_value($value) {
    if ($value instanceof WC_DateTime) {
        return 'date:' . $value->getTimestamp();
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_null($value)) {
        return '';
    }
    if (is_array($value)) {
        $normalized = array();
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = hpt_pie_import_compare_value($item);
        }
        ksort($normalized);
        return wp_json_encode($normalized);
    }
    return trim((string) $value);
}

function hpt_pie_import_existing_product($data) {
    $id = !empty($data['id']) ? absint($data['id']) : 0;
    if (!$id && !empty($data['sku'])) {
        $id = absint(wc_get_product_id_by_sku((string) $data['sku']));
    }
    if (!$id) {
        return false;
    }

    $product = wc_get_product($id);
    return $product && 'importing' !== $product->get_status() ? $product : false;
}

function hpt_pie_import_attribute_signature($attributes, $defaults = array()) {
    $signature = array();
    foreach ((array) $attributes as $key => $attribute) {
        if ($attribute instanceof WC_Product_Attribute) {
            $name = $attribute->get_name('edit');
            $signature[$name] = array(
                'options'  => $attribute->get_options('edit'),
                'visible'  => $attribute->get_visible('edit'),
                'taxonomy' => $attribute->get_id('edit') > 0,
                'variation'=> $attribute->get_variation('edit'),
                'default'  => $defaults[$name] ?? '',
            );
            continue;
        }

        $attribute = (array) $attribute;
        $name = (string) ($attribute['name'] ?? $key);
        if (!empty($attribute['taxonomy'])) {
            $name = function_exists('wc_attribute_taxonomy_name') ? wc_attribute_taxonomy_name($name) : sanitize_title($name);
        } else {
            $name = sanitize_title($name);
        }
        $signature[$name] = array(
            'options'  => (array) ($attribute['value'] ?? array()),
            'visible'  => !isset($attribute['visible']) || (bool) $attribute['visible'],
            'taxonomy' => !empty($attribute['taxonomy']),
            'variation'=> !empty($attribute['variation']),
            'default'  => (string) ($attribute['default'] ?? ''),
        );
    }

    return hpt_pie_import_compare_value($signature);
}

function hpt_pie_import_image_sources_signature($sources) {
    return hpt_pie_import_compare_value(array_values(array_filter(array_map(function ($source) {
        return trim((string) $source);
    }, (array) $sources), 'strlen')));
}

function hpt_pie_import_download_signature($downloads) {
    $signature = array();

    foreach ((array) $downloads as $download) {
        if ($download instanceof WC_Product_Download) {
            $download = $download->get_data();
        } else {
            $download = (array) $download;
        }

        $signature[] = array(
            'id'      => (string) ($download['id'] ?? $download['download_id'] ?? ''),
            'name'    => (string) ($download['name'] ?? ''),
            'file'    => (string) ($download['file'] ?? $download['url'] ?? ''),
            'enabled' => !array_key_exists('enabled', $download) || (bool) $download['enabled'],
        );
    }

    usort($signature, function ($left, $right) {
        return strcmp(wp_json_encode($left), wp_json_encode($right));
    });

    return hpt_pie_import_compare_value($signature);
}

function hpt_pie_import_meta_signature($product) {
    $signature = array();

    foreach ((array) $product->get_meta_data() as $meta) {
        $signature[] = array(
            'id'      => (int) ($meta->id ?? 0),
            'key'     => (string) ($meta->key ?? ''),
            'value'   => hpt_pie_import_compare_value($meta->value ?? ''),
            'changes' => method_exists($meta, 'get_changes') ? hpt_pie_import_compare_value($meta->get_changes()) : '',
        );
    }

    return hpt_pie_import_compare_value($signature);
}

/**
 * Resolve the row identity before WooCommerce validates the row. WooCommerce
 * checks both identifiers but otherwise keeps an invalid CSV ID when a valid
 * SKU match exists, which makes the later product lookup fail.
 *
 * A valid ID remains authoritative so a CSV can intentionally change a
 * product's SKU. SKU is used when the ID is absent, invalid, or still points
 * to an importing placeholder.
 */
function hpt_pie_import_resolve_existing_identity($data, $importer = null) {
    if (!is_array($data) || !$importer || !is_callable(array($importer, 'get_params'))) {
        return $data;
    }

    $params = $importer->get_params();
    if (empty($params['update_existing'])) {
        return $data;
    }

    $id = !empty($data['id']) ? absint($data['id']) : 0;
    $sku = array_key_exists('sku', $data) ? trim((string) $data['sku']) : '';
    if ($sku === '') {
        return $data;
    }

    $id_product = $id ? wc_get_product($id) : false;
    $id_exists  = $id_product && 'importing' !== $id_product->get_status();
    $sku_id      = absint(wc_get_product_id_by_sku($sku));
    $sku_product = $sku_id ? wc_get_product($sku_id) : false;

    if ($id_exists) {
        // Do not guess when valid ID and existing SKU identify different products.
        if ($sku_product && 'importing' !== $sku_product->get_status() && $sku_id !== $id) {
            $data['hpt_import_identity_conflict'] = array(
                'id'     => $id,
                'sku_id' => $sku_id,
                'sku'    => $sku,
            );
        }

        return $data;
    }

    if ($sku_product && 'importing' !== $sku_product->get_status()) {
        $data['id'] = $sku_id;
    }

    return $data;
}
add_filter('woocommerce_product_importer_parsed_data', 'hpt_pie_import_resolve_existing_identity', 5, 2);

function hpt_pie_import_reject_identity_conflict($data) {
    if (!empty($data['hpt_import_identity_conflict'])) {
        $conflict = (array) $data['hpt_import_identity_conflict'];
        throw new Exception(sprintf(
            'CSV có ID %d nhưng SKU "%s" thuộc sản phẩm ID %d. Dòng này không được cập nhật để tránh ghi nhầm sản phẩm.',
            absint($conflict['id'] ?? 0),
            (string) ($conflict['sku'] ?? ''),
            absint($conflict['sku_id'] ?? 0)
        ));
    }

    return $data;
}
add_filter('woocommerce_product_import_process_item_data', 'hpt_pie_import_reject_identity_conflict', 1);

/**
 * Refuse a retryable import row that has no stable product identity.
 *
 * @param array $data Parsed product data.
 */
function hpt_pie_import_reject_unstable_identity($data) {
    if (empty($GLOBALS['hpt_pie_require_stable_identity'])) {
        return $data;
    }

    $has_id  = !empty($data['id']) && absint($data['id']) > 0;
    $has_sku = isset($data['sku']) && trim((string) $data['sku']) !== '';

    if (!$has_id && !$has_sku) {
        throw new Exception('Dòng CSV thiếu ID và SKU nên không thể retry an toàn; hãy bổ sung một định danh cho sản phẩm.');
    }

    return $data;
}
add_filter('woocommerce_product_import_process_item_data', 'hpt_pie_import_reject_unstable_identity', 1);

/**
 * Tag only temporary WooCommerce placeholder products created by this import job.
 *
 * @param int       $product_id Product ID.
 * @param WC_Product $product   Product object.
 */
function hpt_pie_tag_import_placeholder($product_id, $product) {
    $job_id = absint($GLOBALS['hpt_pie_current_import_job_id'] ?? 0);
    if ($job_id && $product instanceof WC_Product && 'importing' === $product->get_status('edit')) {
        update_post_meta(absint($product_id), '_hpt_pie_import_job_id', $job_id);
    }
}

function hpt_pie_import_filter_unchanged_data($data) {
    if (empty($GLOBALS['hpt_pie_use_fast_importer']) || !is_array($data)) {
        return $data;
    }

    $product = hpt_pie_import_existing_product($data);
    if (!$product) {
        return $data;
    }

    // Keep SKU in the row as WooCommerce's lookup key; it is not a mutable field here.
    $fields = array(
        'type', 'global_unique_id', 'name', 'description', 'short_description', 'slug',
        'status', 'featured', 'catalog_visibility', 'tax_status', 'tax_class', 'stock_status',
        'stock_quantity', 'manage_stock', 'low_stock_amount', 'backorders', 'sold_individually',
        'weight', 'length', 'width', 'height', 'reviews_allowed', 'purchase_note', 'sale_price',
        'regular_price', 'date_on_sale_from', 'date_on_sale_to', 'parent_id', 'menu_order',
        'product_url', 'button_text', 'virtual', 'downloadable', 'download_limit', 'download_expiry',
        'shipping_class_id', 'upsell_ids', 'cross_sell_ids', 'children',
    );

    foreach ($fields as $field) {
        if (!array_key_exists($field, $data)) {
            continue;
        }
        $getter = 'get_' . $field;
        if (is_callable(array($product, $getter))) {
            $current = $product->{$getter}('edit');
            if (hpt_pie_import_compare_value($current) === hpt_pie_import_compare_value($data[$field])) {
                unset($data[$field]);
            }
        }
    }

    foreach (array('category_ids', 'tag_ids') as $field) {
        if (!array_key_exists($field, $data)) {
            continue;
        }
        $getter = 'get_' . $field;
        if (hpt_pie_import_compare_value($product->{$getter}('edit')) === hpt_pie_import_compare_value($data[$field])) {
            unset($data[$field]);
        }
    }

    if (isset($data['raw_attributes']) && hpt_pie_import_attribute_signature(
        $product->get_attributes('edit'),
        $product->get_default_attributes('edit')
    ) === hpt_pie_import_attribute_signature($data['raw_attributes'])) {
        unset($data['raw_attributes']);
    }

    if (!empty($data['meta_data'])) {
        $data['meta_data'] = array_values(array_filter($data['meta_data'], function ($meta) use ($product) {
            $key = (string) ($meta['key'] ?? '');
            if ($key === '') {
                return true;
            }
            return hpt_pie_import_compare_value($product->get_meta($key, true, 'edit')) !== hpt_pie_import_compare_value($meta['value'] ?? '');
        }));
        if (empty($data['meta_data'])) {
            unset($data['meta_data']);
        }
    }

    if (array_key_exists('raw_image_id', $data)) {
        $current_image = $product->get_image_id('edit') ? wp_get_attachment_url($product->get_image_id('edit')) : '';
        if (hpt_pie_import_image_sources_signature($current_image ? array($current_image) : array()) === hpt_pie_import_image_sources_signature(array($data['raw_image_id']))) {
            unset($data['raw_image_id']);
        }
    }

    if (array_key_exists('raw_gallery_image_ids', $data)) {
        $current_gallery = array();
        foreach ($product->get_gallery_image_ids('edit') as $attachment_id) {
            $url = wp_get_attachment_url($attachment_id);
            if ($url) {
                $current_gallery[] = $url;
            }
        }
        if (hpt_pie_import_image_sources_signature($current_gallery) === hpt_pie_import_image_sources_signature($data['raw_gallery_image_ids'])) {
            unset($data['raw_gallery_image_ids']);
        }
    }

    if (isset($data['downloads']) && hpt_pie_import_download_signature($product->get_downloads('edit')) === hpt_pie_import_download_signature($data['downloads'])) {
        unset($data['downloads']);
    }

    $identity = array('id', 'sku');
    $mutable = array_diff(array_keys($data), $identity);
    if (empty($mutable)) {
        $data['hpt_skip_save_unchanged'] = 1;
        $data['hpt_existing_product_id'] = $product->get_id();
    }

    return $data;
}
add_filter('woocommerce_product_importer_parsed_data', 'hpt_pie_import_filter_unchanged_data', 20);

/**
 * Register a narrow WooCommerce importer override so an unchanged product does
 * not reach WC_Data::save(), while changed columns still use the core setters.
 */
function hpt_pie_register_fast_importer_class() {
    if (class_exists('HPT_Product_CSV_Importer', false) || !class_exists('WC_Product_CSV_Importer')) {
        return;
    }

    class HPT_Product_CSV_Importer extends WC_Product_CSV_Importer {
        protected function process_item($data) {
            try {
                $skip_save_unchanged = !empty($data['hpt_skip_save_unchanged']);
                $expected_product_id = absint($data['hpt_existing_product_id'] ?? 0);
                unset($data['hpt_skip_save_unchanged'], $data['hpt_existing_product_id']);

                // A SKU-only row may lose its unchanged SKU during filtering;
                // keep the resolved product ID so it cannot become a new item.
                if ($skip_save_unchanged && $expected_product_id > 0 && empty($data['id'])) {
                    $data['id'] = $expected_product_id;
                }

                do_action('woocommerce_product_import_before_process_item', $data);
                $data = apply_filters('woocommerce_product_import_process_item_data', $data);

                if (empty($data['id']) && !empty($data['sku'])) {
                    $product_id = wc_get_product_id_by_sku($data['sku']);
                    if ($product_id) {
                        $data['id'] = $product_id;
                    }
                }

                $object   = $this->get_product_object($data);
                $updating = false;

                if (is_wp_error($object)) {
                    return $object;
                }

                if ($object->get_id() && 'importing' !== $object->get_status()) {
                    $updating = true;
                }

                if (\Automattic\WooCommerce\Enums\ProductType::EXTERNAL === $object->get_type()) {
                    unset($data['manage_stock'], $data['stock_status'], $data['backorders'], $data['low_stock_amount']);
                }

                $is_variation = false;
                if (\Automattic\WooCommerce\Enums\ProductType::VARIATION === $object->get_type()) {
                    if (isset($data['status']) && -1 === $data['status']) {
                        $data['status'] = 0;
                    }
                    $is_variation = true;
                }

                if ('importing' === $object->get_status()) {
                    $object->set_status(\Automattic\WooCommerce\Enums\ProductStatus::PUBLISH);
                    $object->set_slug('');
                }

                if (array_key_exists('cogs_value', $data)) {
                    $object->set_cogs_value($data['cogs_value']);
                    unset($data['cogs_value']);
                }

                $meta_before = $skip_save_unchanged ? hpt_pie_import_meta_signature($object) : '';
                $result = $object->set_props(array_diff_key($data, array_flip(array('meta_data', 'raw_image_id', 'raw_gallery_image_ids', 'raw_attributes'))));

                if (is_wp_error($result)) {
                    throw new Exception($result->get_error_message());
                }

                if (\Automattic\WooCommerce\Enums\ProductType::VARIATION === $object->get_type()) {
                    $this->set_variation_data($object, $data);
                } else {
                    $this->set_product_data($object, $data);
                }

                $this->set_image_data($object, $data);
                $this->set_meta_data($object, $data);

                $object = apply_filters('woocommerce_product_import_pre_insert_product_object', $object, $data);

                $has_scalar_changes = !empty($object->get_changes());
                $has_meta_changes   = $skip_save_unchanged && $meta_before !== hpt_pie_import_meta_signature($object);
                $can_skip_save      = $skip_save_unchanged
                    && $updating
                    && $expected_product_id > 0
                    && $expected_product_id === $object->get_id()
                    && !$has_scalar_changes
                    && !$has_meta_changes;

                if ($can_skip_save) {
                    $GLOBALS['hpt_pie_import_unchanged_ids'][$object->get_id()] = true;
                    $GLOBALS['hpt_pie_import_unchanged_count'] = (int) ($GLOBALS['hpt_pie_import_unchanged_count'] ?? 0) + 1;
                } else {
                    $object->save();
                    do_action('woocommerce_product_import_inserted_product_object', $object, $data);
                }

                return array(
                    'id'           => $object->get_id(),
                    'updated'      => $updating,
                    'is_variation' => $is_variation,
                );
            } catch (Exception $e) {
                return new WP_Error('woocommerce_product_importer_error', $e->getMessage(), array('status' => $e->getCode()));
            }
        }
    }
}

function hpt_pie_select_fast_importer_class($class) {
    if (!empty($GLOBALS['hpt_pie_use_fast_importer']) && class_exists('HPT_Product_CSV_Importer', false)) {
        return 'HPT_Product_CSV_Importer';
    }
    return $class;
}
add_filter('woocommerce_product_csv_importer_class', 'hpt_pie_select_fast_importer_class', 20);

/**
 * Nạp các class importer/exporter của WooCommerce một cách an toàn.
 */
function hpt_pie_load_wc_importer() {
    if (!defined('WC_ABSPATH')) {
        return false;
    }
    if (!class_exists('WC_Product_CSV_Importer_Controller')) {
        include_once WC_ABSPATH . 'includes/admin/importers/class-wc-product-csv-importer-controller.php';
    }
    if (!class_exists('WC_Product_CSV_Importer')) {
        include_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
    }
    return class_exists('WC_Product_CSV_Importer_Controller') && class_exists('WC_Product_CSV_Importer');
}

/**
 * Tự động map cột CSV → field sản phẩm, tái dùng đúng logic của WooCommerce
 * (bao gồm các cột tùy chỉnh đã đăng ký trong data-product.php) qua Reflection.
 *
 * @param array $raw_headers Mảng tiêu đề cột (đã bỏ BOM).
 * @return array index => field_key
 */
function hpt_pie_build_mapping($raw_headers) {
    if (!hpt_pie_load_wc_importer()) {
        return array();
    }

    try {
        $controller = new WC_Product_CSV_Importer_Controller();
        $method     = new ReflectionMethod('WC_Product_CSV_Importer_Controller', 'auto_map_columns');
        $method->setAccessible(true);
        $mapping = $method->invoke($controller, $raw_headers, true);
        return is_array($mapping) ? $mapping : array();
    } catch (\Throwable $e) {
        return array();
    }
}

/**
 * Đọc dòng tiêu đề đầu tiên của CSV (xử lý BOM + delimiter).
 *
 * @return array{headers:array, delimiter:string, header_offset:int}|null
 */
function hpt_pie_read_header($file, $delimiter = '') {
    $handle = fopen($file, 'rb');
    if (!$handle) {
        return null;
    }

    $sample = fgets($handle, 8193);
    if ($sample === false) {
        fclose($handle);
        return null;
    }

    $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample);

    if ($delimiter === '') {
        $delimiter = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
        if (substr_count($sample, "\t") > substr_count($sample, $delimiter)) {
            $delimiter = "\t";
        }
    }

    if (strlen($delimiter) !== 1) {
        fclose($handle);
        return null;
    }

    if (!class_exists('WC_CSV_Stream_Reader') && defined('WC_ABSPATH')) {
        include_once WC_ABSPATH . 'includes/import/class-wc-csv-stream-reader.php';
    }

    if (!class_exists('WC_CSV_Stream_Reader') || 0 !== fseek($handle, 0)) {
        fclose($handle);
        return null;
    }

    try {
        if (!WC_CSV_Stream_Reader::assert_record_bounds($handle, $delimiter, '"', "\0", 4 * 1024 * 1024, 2 * 1024 * 1024)) {
            fclose($handle);
            return null;
        }
        $headers = fgetcsv($handle, 0, $delimiter, '"', "\0"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fgetcsv
    } catch (\Throwable $error) {
        fclose($handle);
        return null;
    }

    if (!is_array($headers)) {
        fclose($handle);
        return null;
    }

    $headers = array_map(function ($h) {
        return trim((string) $h);
    }, $headers);
    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    }

    $header_offset = ftell($handle);
    fclose($handle);

    return array(
        'headers'       => $headers,
        'delimiter'     => $delimiter,
        'header_offset' => (int) $header_offset,
    );
}

/**
 * INIT IMPORT — nhận file upload, tạo job, build mapping, trả job_id.
 */
function hpt_pie_ajax_import_init() {
    hpt_pie_verify_ajax();

    if (empty($_FILES['import_file']) || !isset($_FILES['import_file']['tmp_name']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
        wp_send_json_error(array('message' => 'Chưa nhận được file CSV.'));
    }

    $original_name = sanitize_file_name($_FILES['import_file']['name']);
    $ext           = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    if (!in_array($ext, array('csv', 'txt'), true)) {
        wp_send_json_error(array('message' => 'Chỉ hỗ trợ file CSV/TXT cho dữ liệu lớn. XLSX vui lòng dùng cho file nhỏ qua công cụ khác.'));
    }

    $mode = sanitize_key($_POST['mode'] ?? 'upsert');
    if (!in_array($mode, array('upsert', 'create', 'dryrun'), true)) {
        $mode = 'upsert';
    }

    $skip_images = !empty($_POST['skip_images']);
    $delimiter   = isset($_POST['delimiter']) ? sanitize_text_field(wp_unslash($_POST['delimiter'])) : '';

    // Di chuyển file vào thư mục lưu trữ.
    $dir       = hpt_pie_storage_dir();
    $stored    = $dir . '/import-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false) . '.' . $ext;

    if (!@move_uploaded_file($_FILES['import_file']['tmp_name'], $stored)) {
        wp_send_json_error(array('message' => 'Không thể lưu file import lên server (kiểm tra quyền ghi thư mục uploads).'));
    }

    $file_fingerprint = hpt_pie_file_fingerprint($stored);
    if (!is_string($file_fingerprint) || strlen($file_fingerprint) !== 64) {
        @unlink($stored);
        wp_send_json_error(array('message' => 'Không thể tạo fingerprint cho file import.'));
    }

    $header = hpt_pie_read_header($stored, $delimiter);
    if (!$header || empty($header['headers'])) {
        @unlink($stored);
        wp_send_json_error(array('message' => 'Không đọc được dòng tiêu đề của file CSV.'));
    }

    $mapping = hpt_pie_build_mapping($header['headers']);
    if (empty($mapping)) {
        @unlink($stored);
        wp_send_json_error(array('message' => 'Không thể tạo mapping cột (WooCommerce importer không sẵn sàng).'));
    }

    $config = array(
        'delimiter'     => $header['delimiter'],
        'mapping'       => $mapping,
        'headers'       => $header['headers'],
        'header_offset' => $header['header_offset'],
        'skip_images'   => $skip_images ? 1 : 0,
        'max_record_bytes' => 4 * 1024 * 1024,
        'max_field_bytes'  => 2 * 1024 * 1024,
        'max_batch_bytes'  => 16 * 1024 * 1024,
        'file_size'     => (int) filesize($stored),
        'file_mtime'    => (int) filemtime($stored),
        'file_fingerprint_sha256' => $file_fingerprint,
        'file_fingerprint_verified_offset' => (int) $header['header_offset'] - (256 * 1024 * 1024),
        'file_name'     => $original_name,
        'adaptive_import_batch_size' => hpt_pie_import_batch_size(),
    );

    $job_id = hpt_pie_create_job(array(
        'type'           => 'import',
        'status'         => 'running',
        'mode'           => $mode,
        'file_path'      => $stored,
        'current_offset' => $header['header_offset'],
        'config'         => $config,
    ));

    wp_send_json_success(array(
        'job_id'      => $job_id,
        'mode'        => $mode,
        'batch_size'  => hpt_pie_import_batch_size(),
        'file_size'   => $config['file_size'],
        'mapped_cols' => count(array_filter($mapping)),
    ));
}
add_action('wp_ajax_hpt_pie_import_init', 'hpt_pie_ajax_import_init');

/**
 * BATCH IMPORT — xử lý 1 lô bằng WooCommerce importer (resume theo byte offset).
 */
function hpt_pie_ajax_import_batch() {
    hpt_pie_verify_ajax();

    $job_id = absint($_POST['job_id'] ?? 0);
    $job = hpt_pie_get_job($job_id);
    if (!$job || $job['type'] !== 'import') {
        wp_send_json_error(array('message' => 'Không tìm thấy job import.'));
    }
    if (!hpt_pie_user_can_access_job($job)) {
        wp_send_json_error(array('message' => 'Bạn không có quyền truy cập job import này.'), 403);
    }
    if (!hpt_pie_acquire_job_lock($job_id)) {
        wp_send_json_error(array('message' => 'Batch khác đang xử lý job này. Vui lòng thử lại sau.'), 409);
    }
    $job = hpt_pie_get_job($job_id);
    if (!$job || !hpt_pie_user_can_access_job($job)) {
        wp_send_json_error(array('message' => 'Không tìm thấy job import.'));
    }

    if ($job['status'] === 'finalizing') {
        if (hpt_pie_finish_import_cleanup($job['job_id'])) {
            hpt_pie_update_job($job['job_id'], array('status' => 'completed', 'percent' => 100, 'finished_at' => hpt_pie_now()));
        }
        wp_send_json_success(hpt_pie_job_progress(hpt_pie_get_job($job['job_id'])));
    }

    if (in_array($job['status'], array('paused', 'cancelled', 'completed', 'failed'), true)) {
        wp_send_json_success(hpt_pie_job_progress($job));
    }

    $file = $job['file_path'];
    if (!file_exists($file)) {
        hpt_pie_update_job($job['job_id'], array('status' => 'failed', 'error_message' => 'File import không còn tồn tại.', 'finished_at' => hpt_pie_now()));
        wp_send_json_error(array('message' => 'File import không còn tồn tại.'));
    }

    $config = $job['config_arr'];
    $offset = (int) $job['current_offset'];
    clearstatcache(true, $file);
    $fingerprint_interval = max(1024 * 1024, min(64 * 1024 * 1024, (int) ($config['max_batch_bytes'] ?? (16 * 1024 * 1024)) * 4));
    $last_fingerprint_offset = (int) ($config['file_fingerprint_verified_offset'] ?? -$fingerprint_interval);
    $check_fingerprint = !empty($config['file_fingerprint_sha256']) && ((int) $offset - $last_fingerprint_offset >= $fingerprint_interval);
    if ((isset($config['file_size']) && (int) filesize($file) !== (int) $config['file_size'])
        || (isset($config['file_mtime']) && (int) filemtime($file) !== (int) $config['file_mtime'])
        || ($check_fingerprint && !hash_equals((string) $config['file_fingerprint_sha256'], (string) hpt_pie_file_fingerprint($file)))) {
        hpt_pie_update_job($job['job_id'], array('status' => 'failed', 'error_message' => 'File import đã bị thay đổi sau khi tạo job; job đã dừng để tránh áp dụng checkpoint lên dữ liệu khác.', 'finished_at' => hpt_pie_now()));
        wp_send_json_error(array('message' => 'File import đã bị thay đổi sau khi tạo job; hãy khởi tạo job mới.'));
    }
    if ($check_fingerprint) {
        $config['file_fingerprint_verified_offset'] = (int) $offset;
    }
    $batch_size = max(5, min(100, (int) ($config['adaptive_import_batch_size'] ?? hpt_pie_import_batch_size())));
    $mode       = $job['mode'];

    if (!hpt_pie_load_wc_importer()) {
        wp_send_json_error(array('message' => 'WooCommerce importer không sẵn sàng.'));
    }

    if ($mode === 'dryrun') {
        $result = hpt_pie_run_dryrun_batch($job, $file, $config, $offset, $batch_size);
        wp_send_json_success($result);
    }

    $args = array(
        'delimiter'        => $config['delimiter'] ?? ',',
        'start_pos'        => $offset,
        'mapping'          => $config['mapping'] ?? array(),
        'update_existing'  => ($mode !== 'create'),
        'lines'            => $batch_size,
        'parse'            => true,
        'prevent_timeouts' => true,
        'max_record_bytes' => (int) ($config['max_record_bytes'] ?? (4 * 1024 * 1024)),
        'max_field_bytes'  => (int) ($config['max_field_bytes'] ?? (2 * 1024 * 1024)),
        'max_batch_bytes'  => (int) ($config['max_batch_bytes'] ?? (16 * 1024 * 1024)),
    );

    // Tùy chọn bỏ xử lý ảnh trong lúc import (nhanh hơn nhiều cho dữ liệu lớn).
    $skip_images_cb = null;
    if (!empty($config['skip_images'])) {
        $skip_images_cb = function ($parsed) {
            unset($parsed['images'], $parsed['raw_image_id'], $parsed['raw_gallery_image_ids']);
            return $parsed;
        };
        add_filter('woocommerce_product_importer_parsed_data', $skip_images_cb, 99);
    }

    $GLOBALS['hpt_pie_use_fast_importer']   = ($mode !== 'create');
    $GLOBALS['hpt_pie_import_unchanged_ids'] = array();
    $GLOBALS['hpt_pie_import_unchanged_count'] = 0;
    $GLOBALS['hpt_pie_require_stable_identity'] = true;
    $GLOBALS['hpt_pie_current_import_job_id'] = (int) $job['job_id'];
add_action('woocommerce_new_product', 'hpt_pie_tag_import_placeholder', 1, 2);
add_action('woocommerce_new_product_variation', 'hpt_pie_tag_import_placeholder', 1, 2);
    hpt_pie_register_fast_importer_class();
    hpt_begin_bulk_product_import();

    $time_start = microtime(true);

    try {
        $importer = WC_Product_CSV_Importer_Controller::get_importer($file, $args);
        $results  = $importer->import();
        $new_pos  = (int) $importer->get_file_position();
        $percent  = (int) $importer->get_percent_complete();
    } catch (\Throwable $e) {
        $GLOBALS['hpt_pie_use_fast_importer'] = false;
        $GLOBALS['hpt_pie_require_stable_identity'] = false;
        $GLOBALS['hpt_pie_current_import_job_id'] = 0;
        remove_action('woocommerce_new_product', 'hpt_pie_tag_import_placeholder', 1);
        remove_action('woocommerce_new_product_variation', 'hpt_pie_tag_import_placeholder', 1);
        hpt_end_bulk_product_import();
        if ($skip_images_cb) {
            remove_filter('woocommerce_product_importer_parsed_data', $skip_images_cb, 99);
        }
        hpt_pie_log_error($job['job_id'], 0, '', 'Lỗi batch: ' . $e->getMessage());
        hpt_pie_update_job($job['job_id'], array('status' => 'failed', 'error_message' => $e->getMessage(), 'finished_at' => hpt_pie_now()));
        wp_send_json_error(array('message' => $e->getMessage()));
    }

    $GLOBALS['hpt_pie_use_fast_importer'] = false;
    $GLOBALS['hpt_pie_require_stable_identity'] = false;
    $GLOBALS['hpt_pie_current_import_job_id'] = 0;
    remove_action('woocommerce_new_product', 'hpt_pie_tag_import_placeholder', 1);
    remove_action('woocommerce_new_product_variation', 'hpt_pie_tag_import_placeholder', 1);
    hpt_end_bulk_product_import();
    if ($skip_images_cb) {
        remove_filter('woocommerce_product_importer_parsed_data', $skip_images_cb, 99);
    }

    $imported  = count($results['imported']);
    $unchanged = (int) ($GLOBALS['hpt_pie_import_unchanged_count'] ?? 0);
    $updated   = max(0, count($results['updated']) - $unchanged);
    $failed   = is_array($results['failed']) ? $results['failed'] : array();
    $skipped  = is_array($results['skipped']) ? $results['skipped'] : array();

    // Ghi lỗi từng dòng.
    foreach ($failed as $err) {
        if (is_wp_error($err)) {
            $data = $err->get_error_data();
            hpt_pie_log_error($job['job_id'], $data['row'] ?? 0, $data['sku'] ?? '', $err->get_error_message());
        }
    }
    // skipped = bị bỏ qua (vd create-only nhưng SKU đã tồn tại) — ghi nhẹ để báo cáo.
    foreach ($skipped as $err) {
        if (is_wp_error($err)) {
            $data = $err->get_error_data();
            hpt_pie_log_error($job['job_id'], $data['row'] ?? 0, $data['sku'] ?? '', '[Bỏ qua] ' . $err->get_error_message());
        }
    }

    $processed = (int) $job['processed_rows'] + $imported + $updated + $unchanged + count($failed) + count($skipped);
    $done      = ($percent >= 100) || ($new_pos <= $offset);
    $batch_time_ms = (int) round((microtime(true) - $time_start) * 1000);
    $peak_memory   = memory_get_peak_usage(true);
    $config['adaptive_import_batch_size'] = hpt_pie_next_import_batch_size(
        $batch_size,
        $batch_time_ms,
        $peak_memory,
        hpt_pie_memory_limit_bytes()
    );

    $update = array(
        'current_offset' => $new_pos,
        'config'         => $config,
        'processed_rows' => $processed,
        'success_count'  => (int) $job['success_count'] + $imported,
        'updated_count'  => (int) $job['updated_count'] + $updated,
        'unchanged_count' => (int) ($job['unchanged_count'] ?? 0) + $unchanged,
        'failed_count'   => (int) $job['failed_count'] + count($failed),
        'skipped_count'  => (int) $job['skipped_count'] + count($skipped),
        'percent'        => min(100, $percent),
    );

    if ($done) {
        if (hpt_pie_finish_import_cleanup($job['job_id'])) {
            $update['status']      = 'completed';
            $update['percent']     = 100;
            $update['finished_at'] = hpt_pie_now();
        } else {
            $update['status']  = 'finalizing';
            $update['percent'] = min(99, $percent);
        }
    }

    hpt_pie_update_job($job['job_id'], $update);

    // Giải phóng RAM.
    unset($importer, $results);
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    $progress = hpt_pie_job_progress(hpt_pie_get_job($job['job_id']));
    $progress['batch_time_ms'] = $batch_time_ms;
    $progress['batch_size']    = $batch_size;
    $progress['next_batch_size'] = $config['adaptive_import_batch_size'];
    $progress['mem_peak_mb']   = round($peak_memory / 1048576, 1);

    wp_send_json_success($progress);
}
add_action('wp_ajax_hpt_pie_import_batch', 'hpt_pie_ajax_import_batch');

/**
 * Dọn dẹp 1 lần khi import xong: xóa rác importer của WC + purge cache.
 */
function hpt_pie_finish_import_cleanup($job_id, $batch_size = 100) {
    $job_id = absint($job_id);
    if ($job_id <= 0) {
        return false;
    }

    // Only clean placeholders created by this job. Never sweep unrelated posts,
    // metadata, or terms across the entire store at import completion.
    $batch_size = max(1, min(500, absint($batch_size)));
    $placeholder_ids = get_posts(array(
            'post_type'              => array('product', 'product_variation'),
            'post_status'            => 'any',
            'fields'                 => 'ids',
            'posts_per_page'         => $batch_size + 1,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_key'               => '_hpt_pie_import_job_id',
            'meta_value'             => $job_id,
        ));

    $has_more        = count((array) $placeholder_ids) > $batch_size;
    $placeholder_ids = array_slice((array) $placeholder_ids, 0, $batch_size);

    foreach ($placeholder_ids as $placeholder_id) {
        $placeholder_id = absint($placeholder_id);
        if ('importing' === get_post_status($placeholder_id)) {
            wp_delete_post($placeholder_id, true);
        } else {
            delete_post_meta($placeholder_id, '_hpt_pie_import_job_id');
            delete_post_meta($placeholder_id, '_original_id');
        }
    }

    if ($has_more) {
        return false;
    }

    // Purge toàn site 1 lần (thay cho purge từng sản phẩm đã bị chặn lúc import).
    if (function_exists('do_action')) {
        do_action('litespeed_purge_all');
    }
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients();
    }

    return true;
}

/**
 * DRY-RUN — chỉ validate + kiểm tra tồn tại theo SKU, KHÔNG ghi DB.
 */
function hpt_pie_run_dryrun_batch($job, $file, $config, $offset, $batch_size) {
    $delimiter = $config['delimiter'] ?? ',';
    $mapping   = $config['mapping'] ?? array();

    // Tìm index cột sku / name / regular_price.
    $idx_sku   = array_search('sku', $mapping, true);
    $idx_name  = array_search('name', $mapping, true);
    $idx_price = array_search('regular_price', $mapping, true);

    $handle = fopen($file, 'r');
    if (!$handle) {
        wp_send_json_error(array('message' => 'Không mở được file để dry-run.'));
    }
    fseek($handle, $offset);

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $failed  = 0;
    $count   = 0;

    while ($count < $batch_size) {
        try {
            $has_record = WC_CSV_Stream_Reader::assert_record_bounds(
                $handle,
                $delimiter,
                '"',
                "\0",
                (int) ($config['max_record_bytes'] ?? (4 * 1024 * 1024)),
                (int) ($config['max_field_bytes'] ?? (2 * 1024 * 1024))
            );
        } catch (\Throwable $e) {
            fclose($handle);
            hpt_pie_log_error($job['job_id'], (int) $job['processed_rows'] + $count + 1, '', 'CSV record không hợp lệ: ' . $e->getMessage());
            hpt_pie_update_job($job['job_id'], array('status' => 'failed', 'error_message' => $e->getMessage(), 'finished_at' => hpt_pie_now()));
            wp_send_json_error(array('message' => $e->getMessage()));
        }

        if (!$has_record || ($row = fgetcsv($handle, 0, $delimiter)) === false) {
            break;
        }

        if ($row === array(null) || (count($row) === 1 && trim((string) $row[0]) === '')) {
            continue; // dòng trống
        }
        $count++;

        $sku   = ($idx_sku !== false && isset($row[$idx_sku])) ? trim((string) $row[$idx_sku]) : '';
        $name  = ($idx_name !== false && isset($row[$idx_name])) ? trim((string) $row[$idx_name]) : '';
        $price = ($idx_price !== false && isset($row[$idx_price])) ? trim((string) $row[$idx_price]) : '';

        if ($sku === '') {
            $failed++;
            hpt_pie_log_error($job['job_id'], (int) $job['processed_rows'] + $count, '', 'Thiếu SKU.', $row);
            continue;
        }
        if ($price !== '' && !is_numeric(str_replace(array(',', '.'), '', $price)) && !is_numeric($price)) {
            $failed++;
            hpt_pie_log_error($job['job_id'], (int) $job['processed_rows'] + $count, $sku, 'Giá không hợp lệ: ' . $price, $row);
            continue;
        }

        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) {
            $updated++;
        } else {
            if ($name === '') {
                $failed++;
                hpt_pie_log_error($job['job_id'], (int) $job['processed_rows'] + $count, $sku, 'SKU mới nhưng thiếu Tên sản phẩm.', $row);
                continue;
            }
            $created++;
        }
    }

    $new_pos = ftell($handle);
    $done    = feof($handle) || $count === 0;
    fclose($handle);

    $update = array(
        'current_offset' => (int) $new_pos,
        'config'         => $config,
        'processed_rows' => (int) $job['processed_rows'] + $count,
        'success_count'  => (int) $job['success_count'] + $created,
        'updated_count'  => (int) $job['updated_count'] + $updated,
        'failed_count'   => (int) $job['failed_count'] + $failed,
        'skipped_count'  => (int) $job['skipped_count'] + $skipped,
    );

    $size = (int) ($config['file_size'] ?? filesize($file));
    $update['percent'] = $size > 0 ? min(100, (int) floor(($new_pos / $size) * 100)) : 100;

    if ($done) {
        $update['status']      = 'completed';
        $update['percent']     = 100;
        $update['finished_at'] = hpt_pie_now();
    }

    hpt_pie_update_job($job['job_id'], $update);

    return hpt_pie_job_progress(hpt_pie_get_job($job['job_id']));
}

/* ============================================================
 * 6) EXPORT
 * ============================================================ */

function hpt_pie_load_wc_exporter() {
    if (!defined('WC_ABSPATH')) {
        return false;
    }
    if (!class_exists('WC_Product_CSV_Exporter')) {
        include_once WC_ABSPATH . 'includes/export/abstract-wc-csv-exporter.php';
        include_once WC_ABSPATH . 'includes/export/abstract-wc-csv-batch-exporter.php';
        include_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
    }
    return class_exists('WC_Product_CSV_Exporter');
}

function hpt_pie_make_exporter($config) {
    $exporter = new WC_Product_CSV_Exporter();
    $exporter->set_limit(hpt_pie_export_batch_size());
    $exporter->set_excel_view(!empty($config['excel_view']));

    if (!empty($config['filename'])) {
        $exporter->set_filename($config['filename']);
    }
    if (!empty($config['category_ids']) && is_array($config['category_ids'])) {
        // wc_get_products arg 'category' mong đợi SLUG, không phải ID → đổi sang slug.
        $slugs = array();
        foreach ($config['category_ids'] as $cid) {
            $term = get_term(absint($cid), 'product_cat');
            if ($term && !is_wp_error($term)) {
                $slugs[] = $term->slug;
            }
        }
        if (!empty($slugs)) {
            $exporter->set_product_category_to_export($slugs);
        }
    }

    return $exporter;
}

/**
 * INIT EXPORT — tạo job export.
 */
function hpt_pie_ajax_export_init() {
    hpt_pie_verify_ajax();

    if (!hpt_pie_load_wc_exporter()) {
        wp_send_json_error(array('message' => 'WooCommerce exporter không sẵn sàng.'));
    }

    $category_ids = array();
    if (!empty($_POST['category_ids'])) {
        $category_ids = array_filter(array_map('absint', (array) $_POST['category_ids']));
    }
    $gzip = !empty($_POST['gzip']);

    $filename = 'hpt-product-ie/export-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false) . '.csv';

    $config = array(
        'filename'     => $filename,
        'category_ids' => array_values($category_ids),
        'gzip'         => $gzip ? 1 : 0,
        'excel_view'   => !empty($_POST['excel_view']),
    );

    $job_id = hpt_pie_create_job(array(
        'type'    => 'export',
        'status'  => 'running',
        'mode'    => 'export',
        'config'  => $config,
        'percent' => 0,
    ));

    // Đảm bảo thư mục tồn tại.
    hpt_pie_storage_dir();

    wp_send_json_success(array(
        'job_id'     => $job_id,
        'batch_size' => hpt_pie_export_batch_size(),
    ));
}
add_action('wp_ajax_hpt_pie_export_init', 'hpt_pie_ajax_export_init');

/**
 * BATCH EXPORT — xuất 1 page bằng WooCommerce exporter (ghi nối tiếp 1 file).
 */
function hpt_pie_ajax_export_batch() {
    hpt_pie_verify_ajax();

    $job_id = absint($_POST['job_id'] ?? 0);
    if (!hpt_pie_acquire_job_lock($job_id)) {
        wp_send_json_error(array('message' => 'Batch khác đang xử lý job này. Vui lòng thử lại sau.'), 409);
    }

    $job = hpt_pie_get_job($job_id);
    if (!$job || $job['type'] !== 'export') {
        wp_send_json_error(array('message' => 'Không tìm thấy job export.'));
    }
    if (!hpt_pie_user_can_access_job($job)) {
        wp_send_json_error(array('message' => 'Bạn không có quyền truy cập job export này.'), 403);
    }

    if (in_array($job['status'], array('paused', 'cancelled', 'completed', 'failed'), true)) {
        wp_send_json_success(hpt_pie_job_progress($job));
    }

    if (!hpt_pie_load_wc_exporter()) {
        wp_send_json_error(array('message' => 'WooCommerce exporter không sẵn sàng.'));
    }

    $config = $job['config_arr'];
    $page   = max(1, (int) $job['current_offset']); // current_offset dùng làm số page với export.

    $time_start = microtime(true);

    try {
        $exporter = hpt_pie_make_exporter($config);
        if (method_exists($exporter, 'set_export_cursor')) {
            $exporter->set_export_cursor((int) ($config['export_cursor'] ?? 0));
        }
        $exporter->set_page($page);
        $exporter->generate_file();
        $percent  = (int) $exporter->get_percent_complete();
        $exported = (int) $exporter->get_total_exported();
        if (method_exists($exporter, 'get_next_export_cursor')) {
            $config['export_cursor'] = (int) $exporter->get_next_export_cursor();
        }
    } catch (\Throwable $e) {
        hpt_pie_update_job($job['job_id'], array('status' => 'failed', 'error_message' => $e->getMessage(), 'finished_at' => hpt_pie_now()));
        wp_send_json_error(array('message' => $e->getMessage()));
    }

    $done   = ($percent >= 100);
    $update = array(
        'current_offset' => $page + 1,
        'processed_rows' => $exported,
        'percent'        => min(100, $percent),
        'config'         => $config,
    );

    if ($done) {
        $download = hpt_pie_finalize_export($exporter, $config);
        $update['status']        = 'completed';
        $update['percent']       = 100;
        $update['finished_at']   = hpt_pie_now();
        $update['download_path'] = $download;
    }

    hpt_pie_update_job($job['job_id'], $update);

    unset($exporter);
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    $progress = hpt_pie_job_progress(hpt_pie_get_job($job['job_id']));
    $progress['batch_time_ms'] = (int) round((microtime(true) - $time_start) * 1000);
    $progress['mem_peak_mb']   = round(memory_get_peak_usage(true) / 1048576, 1);

    if ($done) {
        $progress['download_url'] = hpt_pie_download_url($job['job_id']);
    }

    wp_send_json_success($progress);
}
add_action('wp_ajax_hpt_pie_export_batch', 'hpt_pie_ajax_export_batch');

/* ============================================================
 * 6b) EXPORT THEO DANH SACH SKU TXT
 * ============================================================ */

function hpt_pie_sku_export_fields() {
    return array(
        'sku'              => 'SKU',
        'id'               => 'ID',
        'name'             => 'Tên sản phẩm',
        'slug'             => 'Slug',
        'type'             => 'Loại sản phẩm',
        'status'           => 'Trạng thái',
        'regular_price'    => 'Giá thường',
        'sale_price'       => 'Giá khuyến mãi',
        'price'            => 'Giá hiện tại',
        'stock_quantity'   => 'Số lượng tồn',
        'stock_status'     => 'Trạng thái tồn kho',
        'categories'       => 'Danh mục',
        'tags'             => 'Thẻ',
        'permalink'        => 'URL sản phẩm',
        'image_url'        => 'Ảnh chính',
        'gallery_urls'     => 'Ảnh gallery',
        'short_description'=> 'Mô tả ngắn',
        'description'      => 'Mô tả',
        'date_created'     => 'Ngày tạo',
        'date_modified'    => 'Ngày sửa',
        'match_status'    => 'Kết quả đối chiếu',
    );
}

function hpt_pie_sku_export_normalize_sku($sku) {
    $sku = trim((string) $sku);
    $sku = preg_replace('/^\xEF\xBB\xBF/', '', $sku);
    return trim($sku);
}

function hpt_pie_sku_export_selected_fields($raw) {
    $allowed = hpt_pie_sku_export_fields();
    $selected = array();
    foreach ((array) $raw as $field) {
        $field = sanitize_key($field);
        if (isset($allowed[$field]) && !in_array($field, $selected, true)) {
            $selected[] = $field;
        }
    }
    if (empty($selected)) {
        $selected = array('sku', 'id', 'name', 'slug', 'regular_price', 'sale_price', 'stock_quantity', 'stock_status', 'permalink');
    }
    // Luôn giữ SKU và trạng thái để mỗi dòng TXT được đối chiếu rõ ràng.
    if (!in_array('sku', $selected, true)) {
        array_unshift($selected, 'sku');
    }
    if (!in_array('match_status', $selected, true)) {
        $selected[] = 'match_status';
    }
    return $selected;
}

function hpt_pie_sku_export_read_batch($path, $line_offset, $limit) {
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return array('skus' => array(), 'next_offset' => $line_offset, 'done' => true);
    }

    $line = 0;
    while ($line < $line_offset && fgets($handle) !== false) {
        $line++;
    }

    $skus = array();
    while (count($skus) < $limit && ($raw = fgets($handle)) !== false) {
        $line++;
        $sku = hpt_pie_sku_export_normalize_sku($raw);
        if ($sku !== '') {
            $skus[] = $sku;
        }
    }
    $done = feof($handle);
    fclose($handle);

    return array('skus' => $skus, 'next_offset' => $line, 'done' => $done);
}

function hpt_pie_sku_export_query_products($skus) {
    global $wpdb;

    $skus = array_values(array_unique(array_filter(array_map('hpt_pie_sku_export_normalize_sku', (array) $skus))));
    if (empty($skus)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($skus), '%s'));
    $sql = "SELECT p.ID, p.post_parent, p.post_title, p.post_name, p.post_status, p.post_type,
                   p.post_excerpt, p.post_content, p.post_date, p.post_modified, pm.meta_value AS sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
            WHERE pm.meta_value IN ({$placeholders})
              AND p.post_type IN ('product', 'product_variation')
              AND p.post_status NOT IN ('trash', 'auto-draft')";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $skus), ARRAY_A);
    $by_sku = array();
    foreach ((array) $rows as $row) {
        $key = strtolower((string) $row['sku']);
        // A SKU should be unique; keep the lowest ID deterministically if old data is duplicated.
        if (!isset($by_sku[$key]) || (int) $row['ID'] < (int) $by_sku[$key]['ID']) {
            $by_sku[$key] = $row;
        }
    }
    if (empty($by_sku)) {
        return array();
    }

    $ids = array_values(array_unique(array_map('absint', wp_list_pluck($by_sku, 'ID'))));
    $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $meta_rows = $wpdb->get_results(
        $wpdb->prepare("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$id_placeholders}) AND meta_key IN ('_regular_price','_sale_price','_price','_stock','_stock_status','_thumbnail_id','_product_image_gallery')", $ids),
        ARRAY_A
    );
    $meta = array();
    foreach ($meta_rows as $row) {
        $meta[(int) $row['post_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
    }
    foreach ($by_sku as &$product) {
        $product['_meta'] = $meta[(int) $product['ID']] ?? array();
    }
    unset($product);

    return $by_sku;
}

function hpt_pie_sku_export_terms($post_id, $taxonomy) {
    $post_id = absint($post_id);
    $cache = $GLOBALS['hpt_pie_sku_term_cache'] ?? array();
    if (isset($cache[$taxonomy][$post_id])) {
        return implode(', ', $cache[$taxonomy][$post_id]);
    }

    hpt_pie_sku_export_prime_terms(array($post_id));
    $cache = $GLOBALS['hpt_pie_sku_term_cache'] ?? array();
    return isset($cache[$taxonomy][$post_id]) ? implode(', ', $cache[$taxonomy][$post_id]) : '';
}

function hpt_pie_sku_export_prime_terms($post_ids) {
    global $wpdb;

    $post_ids = array_values(array_filter(array_map('absint', (array) $post_ids)));
    if (empty($post_ids)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT tr.object_id, tt.taxonomy, t.name
         FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
         WHERE tr.object_id IN ({$placeholders}) AND tt.taxonomy IN ('product_cat', 'product_tag')
         ORDER BY t.name ASC",
        $post_ids
    ), ARRAY_A);

    if (!isset($GLOBALS['hpt_pie_sku_term_cache']) || !is_array($GLOBALS['hpt_pie_sku_term_cache'])) {
        $GLOBALS['hpt_pie_sku_term_cache'] = array();
    }
    foreach (array('product_cat', 'product_tag') as $taxonomy) {
        foreach ($post_ids as $post_id) {
            $GLOBALS['hpt_pie_sku_term_cache'][$taxonomy][$post_id] = array();
        }
    }
    foreach ((array) $rows as $row) {
        $GLOBALS['hpt_pie_sku_term_cache'][$row['taxonomy']][(int) $row['object_id']][] = $row['name'];
    }
}

function hpt_pie_sku_export_image_urls($product) {
    $meta = $product['_meta'] ?? array();
    $urls = array();
    $thumbnail_id = absint($meta['_thumbnail_id'] ?? 0);
    if ($thumbnail_id) {
        $url = wp_get_attachment_url($thumbnail_id);
        if ($url) {
            $urls[] = $url;
        }
    }
    foreach (array_filter(array_map('absint', explode(',', (string) ($meta['_product_image_gallery'] ?? '')))) as $attachment_id) {
        $url = wp_get_attachment_url($attachment_id);
        if ($url) {
            $urls[] = $url;
        }
    }
    return $urls;
}

function hpt_pie_sku_export_row($product, $fields) {
    $id = absint($product['ID']);
    $parent_id = absint($product['post_parent']) ?: $id;
    $meta = $product['_meta'] ?? array();
    $images = hpt_pie_sku_export_image_urls($product);
    $values = array();
    foreach ($fields as $field) {
        switch ($field) {
            case 'sku': $value = $product['sku']; break;
            case 'id': $value = $id; break;
            case 'name': $value = $product['post_title']; break;
            case 'slug': $value = $product['post_name']; break;
            case 'type': $value = $product['post_type'] === 'product_variation' ? 'variation' : 'simple/product'; break;
            case 'status': $value = $product['post_status']; break;
            case 'regular_price': $value = $meta['_regular_price'] ?? ''; break;
            case 'sale_price': $value = $meta['_sale_price'] ?? ''; break;
            case 'price': $value = $meta['_price'] ?? ''; break;
            case 'stock_quantity': $value = $meta['_stock'] ?? ''; break;
            case 'stock_status': $value = $meta['_stock_status'] ?? ''; break;
            case 'categories': $value = hpt_pie_sku_export_terms($parent_id, 'product_cat'); break;
            case 'tags': $value = hpt_pie_sku_export_terms($parent_id, 'product_tag'); break;
            case 'permalink': $value = get_permalink($id); break;
            case 'image_url': $value = $images[0] ?? ''; break;
            case 'gallery_urls': $value = implode(' | ', $images); break;
            case 'short_description': $value = $product['post_excerpt']; break;
            case 'description': $value = $product['post_content']; break;
            case 'date_created': $value = $product['post_date']; break;
            case 'date_modified': $value = $product['post_modified']; break;
            case 'match_status': $value = 'Tồn tại'; break;
            default: $value = '';
        }
        $values[] = is_scalar($value) ? $value : '';
    }
    return $values;
}

function hpt_pie_sku_export_missing_row($sku, $fields) {
    $values = array();
    foreach ($fields as $field) {
        if ($field === 'sku') {
            $values[] = $sku;
        } elseif ($field === 'match_status') {
            $values[] = 'Mã không tồn tại';
        } else {
            $values[] = '';
        }
    }
    return $values;
}

function hpt_pie_sku_export_finalize($job, $config) {
    $final = $config['output_path'];
    $tmp = $final . '.tmp';
    $out = fopen($tmp, 'wb');
    if (!$out) {
        return false;
    }
    fwrite($out, "\xEF\xBB\xBF");
    $labels = hpt_pie_sku_export_fields();
    fputcsv($out, array_map(function ($field) use ($labels) { return $labels[$field] ?? $field; }, $config['fields']));
    for ($i = 0; $i < (int) $config['batch_count']; $i++) {
        $batch_path = $config['batch_dir'] . '/batch-' . $i . '.csv';
        if (!file_exists($batch_path)) {
            continue;
        }
        $in = fopen($batch_path, 'rb');
        if ($in) {
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        @unlink($batch_path);
    }
    fclose($out);
    if (!@rename($tmp, $final)) {
        @unlink($tmp);
        return false;
    }
    @rmdir($config['batch_dir']);
    @unlink($config['sku_source']);

    if (!empty($config['gzip'])) {
        $gz_path = $final . '.gz';
        $in = fopen($final, 'rb');
        $gz = gzopen($gz_path, 'wb9');
        if ($in && $gz) {
            while (!feof($in)) {
                gzwrite($gz, fread($in, 1048576));
            }
            fclose($in);
            gzclose($gz);
            @unlink($final);
            return $gz_path;
        }
        if ($in) fclose($in);
        if ($gz) gzclose($gz);
    }
    return $final;
}

function hpt_pie_ajax_sku_export_init() {
    hpt_pie_verify_ajax();
    if (empty($_FILES['sku_file']['tmp_name']) || !is_uploaded_file($_FILES['sku_file']['tmp_name'])) {
        wp_send_json_error(array('message' => 'Chưa nhận được file SKU TXT.'));
    }
    $ext = strtolower(pathinfo(sanitize_file_name($_FILES['sku_file']['name']), PATHINFO_EXTENSION));
    if (!in_array($ext, array('txt', 'csv'), true)) {
        wp_send_json_error(array('message' => 'File SKU phải có phần mở rộng .txt hoặc .csv.'));
    }
    $dir = hpt_pie_storage_dir();
    $token = gmdate('Ymd-His') . '-' . wp_generate_password(12, false, false);
    $source = $dir . '/sku-' . $token . '.txt';
    if (!@move_uploaded_file($_FILES['sku_file']['tmp_name'], $source)) {
        wp_send_json_error(array('message' => 'Không thể lưu file SKU tạm thời.'));
    }
    $fields = hpt_pie_sku_export_selected_fields($_POST['fields'] ?? array());
    $total = 0;
    $in = fopen($source, 'rb');
    if ($in) {
        while (($line = fgets($in)) !== false) {
            if (hpt_pie_sku_export_normalize_sku($line) !== '') $total++;
        }
        fclose($in);
    }
    if ($total <= 0) {
        @unlink($source);
        wp_send_json_error(array('message' => 'File không có SKU hợp lệ.'));
    }
    $batch_size = hpt_pie_sku_export_batch_size();
    $batch_dir = $dir . '/sku-batches-' . $token;
    wp_mkdir_p($batch_dir);
    $config = array(
        'sku_source' => $source,
        'fields' => $fields,
        'gzip' => !empty($_POST['gzip']) ? 1 : 0,
        'batch_size' => $batch_size,
        'batch_count' => (int) ceil($total / $batch_size),
        'batch_dir' => $batch_dir,
        'output_path' => $dir . '/sku-export-' . $token . '.csv',
    );
    $job_id = hpt_pie_create_job(array('type' => 'sku_export', 'mode' => 'sku_file', 'status' => 'running', 'file_path' => $source, 'total_rows' => $total, 'config' => $config));
    wp_send_json_success(array('job_id' => $job_id, 'total' => $total, 'batch_size' => $batch_size));
}
add_action('wp_ajax_hpt_pie_sku_export_init', 'hpt_pie_ajax_sku_export_init');

function hpt_pie_sku_export_try_finalize($job_id) {
    $job = hpt_pie_get_job($job_id);
    if (!$job || $job['status'] !== 'running') {
        return $job;
    }

    $config = $job['config_arr'];
    $lock = !empty($config['sku_source']) ? fopen($config['sku_source'] . '.lock', 'c') : false;
    if (!$lock || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return $job;
    }

    $job = hpt_pie_get_job($job_id);
    if (!$job || $job['status'] !== 'running') {
        flock($lock, LOCK_UN);
        fclose($lock);
        return $job;
    }
    $config = $job['config_arr'];
    if ((int) $job['processed_rows'] < (int) $job['total_rows']) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return $job;
    }
    $batch_count = max(1, (int) ($config['batch_count'] ?? 0));
    for ($batch_no = 0; $batch_no < $batch_count; $batch_no++) {
        if (!file_exists($config['batch_dir'] . '/batch-' . $batch_no . '.csv')) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return $job;
        }
    }

    $download = hpt_pie_sku_export_finalize($job, $config);
    hpt_pie_update_job($job_id, array(
        'status'        => $download ? 'completed' : 'failed',
        'percent'       => $download ? 100 : (int) $job['percent'],
        'download_path' => $download ?: '',
        'finished_at'   => hpt_pie_now(),
    ));
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink($config['sku_source'] . '.lock');

    return hpt_pie_get_job($job_id);
}

function hpt_pie_ajax_sku_export_batch() {
    hpt_pie_verify_ajax();
    $job_id = absint($_POST['job_id'] ?? 0);
    if (!hpt_pie_acquire_job_lock($job_id)) {
        wp_send_json_error(array('message' => 'Batch khác đang xử lý job này. Vui lòng thử lại sau.'), 409);
    }

    $job = hpt_pie_get_job($job_id);
    if (!$job || $job['type'] !== 'sku_export') {
        wp_send_json_error(array('message' => 'Không tìm thấy job SKU export.'));
    }
    if (!hpt_pie_user_can_access_job($job)) {
        wp_send_json_error(array('message' => 'Bạn không có quyền truy cập job SKU export này.'), 403);
    }
    if (in_array($job['status'], array('paused', 'cancelled', 'completed', 'failed'), true)) {
        wp_send_json_success(hpt_pie_job_progress($job));
    }

    $config = $job['config_arr'];
    if (empty($config['sku_source']) || !file_exists($config['sku_source'])) {
        hpt_pie_update_job($job['job_id'], array('status' => 'failed', 'error_message' => 'File SKU tạm thời không còn tồn tại.', 'finished_at' => hpt_pie_now()));
        wp_send_json_error(array('message' => 'File SKU tạm thời không còn tồn tại.'));
    }

    // Claim only the cursor under a short lock; the database query and CSV write run in parallel workers.
    $lock = fopen($config['sku_source'] . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        wp_send_json_error(array('message' => 'Batch khác đang xử lý, vui lòng thử lại sau.'));
    }
    $job = hpt_pie_get_job($job['job_id']);
    if (!$job || $job['status'] !== 'running') {
        flock($lock, LOCK_UN);
        fclose($lock);
        wp_send_json_success(hpt_pie_job_progress($job));
    }
    $config = $job['config_arr'];
    $batch_no = (int) ($config['next_batch_no'] ?? 0);
    $read = hpt_pie_sku_export_read_batch($config['sku_source'], (int) $job['current_offset'], (int) $config['batch_size']);
    if (empty($read['skus'])) {
        $progress = hpt_pie_job_progress($job);
        $progress['stop_polling'] = true;
        flock($lock, LOCK_UN);
        fclose($lock);
        wp_send_json_success($progress);
    }

    $config['next_batch_no'] = $batch_no + 1;
    hpt_pie_update_job($job['job_id'], array(
        'current_offset' => (int) $read['next_offset'],
        'config'         => $config,
    ));
    flock($lock, LOCK_UN);
    fclose($lock);

    $products = hpt_pie_sku_export_query_products($read['skus']);
    $parent_ids = array_map(function ($product) {
        return absint($product['post_parent']) ?: absint($product['ID']);
    }, $products);
    hpt_pie_sku_export_prime_terms($parent_ids);
    $batch_path = $config['batch_dir'] . '/batch-' . $batch_no . '.csv';
    $out = fopen($batch_path, 'wb');
    $found = 0;
    $missing = 0;
    if (!$out) {
        hpt_pie_update_job($job['job_id'], array('status' => 'failed', 'error_message' => 'Không thể ghi batch export.', 'finished_at' => hpt_pie_now()));
        wp_send_json_error(array('message' => 'Không thể ghi batch export.'));
    }
    foreach ($read['skus'] as $sku) {
        $product_key = strtolower($sku);
        if (!isset($products[$product_key])) {
            $missing++;
            fputcsv($out, hpt_pie_sku_export_missing_row($sku, $config['fields']));
            continue;
        }
        fputcsv($out, hpt_pie_sku_export_row($products[$product_key], $config['fields']));
        $found++;
    }
    fclose($out);
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        'UPDATE ' . hpt_pie_jobs_table() . ' SET processed_rows = processed_rows + %d, success_count = success_count + %d, failed_count = failed_count + %d, updated_at = %s WHERE job_id = %d',
        count($read['skus']),
        $found,
        $missing,
        hpt_pie_now(),
        $job['job_id']
    ));

    $current = hpt_pie_get_job($job['job_id']);
    $percent = min(100, (int) floor(((int) $current['processed_rows'] / max(1, (int) $current['total_rows'])) * 100));
    hpt_pie_update_job($job['job_id'], array('percent' => $percent));
    $current = hpt_pie_sku_export_try_finalize($job['job_id']);
    $progress = hpt_pie_job_progress($current);
    if ($current && $current['status'] === 'completed') {
        $progress['download_url'] = hpt_pie_download_url($job['job_id']);
    }
    wp_send_json_success($progress);
}
add_action('wp_ajax_hpt_pie_sku_export_batch', 'hpt_pie_ajax_sku_export_batch');

/**
 * Hoàn tất export: ghép header + body thành 1 file CSV hoàn chỉnh (stream, nhẹ RAM),
 * tùy chọn nén .gz. Trả về đường dẫn file để tải.
 */
function hpt_pie_finalize_export($exporter, $config) {
    $upload   = wp_upload_dir();
    $base     = trailingslashit($upload['basedir']) . $config['filename'];
    $body     = $base;                 // WC ghi body vào chính filename
    $headers  = $base . '.headers';    // và header vào {filename}.headers

    $final = $base; // file CSV cuối cùng (ghi đè)

    // Ghép header + body theo stream.
    $out = fopen($final . '.tmp', 'w');
    if ($out) {
        if (file_exists($headers)) {
            $hf = fopen($headers, 'r');
            if ($hf) {
                stream_copy_to_stream($hf, $out);
                fclose($hf);
            }
        }
        if (file_exists($body)) {
            $bf = fopen($body, 'r');
            if ($bf) {
                stream_copy_to_stream($bf, $out);
                fclose($bf);
            }
        }
        fclose($out);
        @rename($final . '.tmp', $final);
    }

    @unlink($headers);

    // Nén gzip nếu yêu cầu.
    if (!empty($config['gzip'])) {
        $gz_path = $final . '.gz';
        $in      = fopen($final, 'rb');
        $gz      = gzopen($gz_path, 'wb9');
        if ($in && $gz) {
            while (!feof($in)) {
                gzwrite($gz, fread($in, 1048576));
            }
            fclose($in);
            gzclose($gz);
            @unlink($final);
            return $gz_path;
        }
        if ($in) {
            fclose($in);
        }
    }

    return $final;
}

function hpt_pie_download_url($job_id) {
    return add_query_arg(array(
        'action' => 'hpt_pie_download',
        'job_id' => absint($job_id),
        'nonce'  => wp_create_nonce('hpt_pie_download_' . absint($job_id)),
    ), admin_url('admin-ajax.php'));
}

/**
 * Tải file export đã hoàn tất (stream, không giữ toàn bộ trong RAM).
 */
function hpt_pie_ajax_download() {
    if (!hpt_pie_user_can_manage()) {
        wp_die('Không có quyền.');
    }

    $job_id = absint($_GET['job_id'] ?? 0);
    if (!wp_verify_nonce($_GET['nonce'] ?? '', 'hpt_pie_download_' . $job_id)) {
        wp_die('Nonce không hợp lệ.');
    }

    $job = hpt_pie_get_job($job_id);
    if (!$job || 'completed' !== $job['status'] || empty($job['download_path']) || !file_exists($job['download_path'])) {
        wp_die('File export không tồn tại.');
    }

    $storage_directory = realpath(hpt_pie_storage_dir());
    $download_path = realpath($job['download_path']);
    if (false === $storage_directory || false === $download_path) {
        wp_die('File export không nằm trong thư mục download hợp lệ.');
    }

    $storage_prefix = rtrim(str_replace('\\', '/', $storage_directory), '/') . '/';
    $download_target = str_replace('\\', '/', $download_path);
    if (0 !== strpos(strtolower($download_target), strtolower($storage_prefix))) {
        wp_die('File export không nằm trong thư mục download hợp lệ.');
    }

    $path     = $download_path;
    $is_gz    = (substr($path, -3) === '.gz');
    $filename = 'products-export-' . $job_id . ($is_gz ? '.csv.gz' : '.csv');

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: ' . ($is_gz ? 'application/gzip' : 'text/csv; charset=utf-8'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('Pragma: no-cache');
    header('Expires: 0');

    $fp = fopen($path, 'rb');
    if ($fp) {
        while (!feof($fp)) {
            echo fread($fp, 1048576);
            flush();
        }
        fclose($fp);
    }
    exit;
}
add_action('wp_ajax_hpt_pie_download', 'hpt_pie_ajax_download');

/* ============================================================
 * 7) TRẠNG THÁI + ĐIỀU KHIỂN JOB
 * ============================================================ */

function hpt_pie_job_progress($job) {
    if (!$job) {
        return array();
    }

    return array(
        'job_id'    => (int) $job['job_id'],
        'type'      => $job['type'],
        'status'    => $job['status'],
        'mode'      => $job['mode'],
        'percent'   => (int) $job['percent'],
        'processed' => (int) $job['processed_rows'],
        'total'     => (int) $job['total_rows'],
        'created'   => (int) $job['success_count'],
        'updated'   => (int) $job['updated_count'],
        'unchanged' => (int) ($job['unchanged_count'] ?? 0),
        'failed'    => (int) $job['failed_count'],
        'skipped'   => (int) $job['skipped_count'],
        'done'      => in_array($job['status'], array('completed', 'failed', 'cancelled'), true),
    );
}

function hpt_pie_ajax_status() {
    hpt_pie_verify_ajax();

    $job = hpt_pie_get_job($_POST['job_id'] ?? 0);
    if (!$job) {
        wp_send_json_error(array('message' => 'Không tìm thấy job.'));
    }
    if (!hpt_pie_user_can_access_job($job)) {
        wp_send_json_error(array('message' => 'Bạn không có quyền truy cập job này.'), 403);
    }

    $progress           = hpt_pie_job_progress($job);
    $progress['errors'] = hpt_pie_get_job_errors($job['job_id'], 200);

    if (!empty($job['download_path']) && $job['status'] === 'completed') {
        $progress['download_url'] = hpt_pie_download_url($job['job_id']);
    }

    wp_send_json_success($progress);
}
add_action('wp_ajax_hpt_pie_status', 'hpt_pie_ajax_status');

function hpt_pie_ajax_control() {
    hpt_pie_verify_ajax();

    $job    = hpt_pie_get_job($_POST['job_id'] ?? 0);
    $action = sanitize_key($_POST['control'] ?? '');

    if (!$job) {
        wp_send_json_error(array('message' => 'Không tìm thấy job.'));
    }
    if (!hpt_pie_user_can_access_job($job)) {
        wp_send_json_error(array('message' => 'Bạn không có quyền điều khiển job này.'), 403);
    }

    $map = array(
        'pause'  => 'paused',
        'resume' => 'running',
        'cancel' => 'cancelled',
    );

    if (!isset($map[$action])) {
        wp_send_json_error(array('message' => 'Lệnh không hợp lệ.'));
    }

    $update = array('status' => $map[$action]);
    if ($action === 'cancel') {
        $update['finished_at'] = hpt_pie_now();
    }

    hpt_pie_update_job($job['job_id'], $update);

    wp_send_json_success(hpt_pie_job_progress(hpt_pie_get_job($job['job_id'])));
}
add_action('wp_ajax_hpt_pie_control', 'hpt_pie_ajax_control');

/**
 * Tạo index tối ưu dedupe ảnh trên wp_postmeta (thao tác thủ công, có thể nặng).
 */
function hpt_pie_ajax_add_index() {
    hpt_pie_verify_ajax();

    global $wpdb;

    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name = %s",
            'hpt_attach_source'
        )
    );

    if ($exists) {
        wp_send_json_success(array('message' => 'Index đã tồn tại.'));
    }

    $wpdb->query("ALTER TABLE {$wpdb->postmeta} ADD INDEX hpt_attach_source (meta_key(20), meta_value(191))");

    if ($wpdb->last_error) {
        wp_send_json_error(array('message' => 'Lỗi tạo index: ' . $wpdb->last_error));
    }

    wp_send_json_success(array('message' => 'Đã tạo index hpt_attach_source.'));
}
add_action('wp_ajax_hpt_pie_add_index', 'hpt_pie_ajax_add_index');

/* ============================================================
 * 8) GIAO DIỆN ADMIN
 * ============================================================ */

function hpt_pie_render_page() {
    if (!hpt_pie_user_can_manage()) {
        wp_die(esc_html__('Bạn không có quyền truy cập trang này.', 'hpt'));
    }

    $cats = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'number'     => 1000,
        'orderby'    => 'name',
    ));
    $sku_focus = isset($_GET['page']) && sanitize_key($_GET['page']) === 'hpt-product-sku-export';
    ?>
    <div class="wrap hpt-pie-admin">
        <header class="hpt-pie-hero">
            <div>
                <span class="hpt-pie-eyebrow">HPT PRODUCT DATA TOOLS</span>
                <h1>Import / Export sản phẩm</h1>
                <p>Quản lý dữ liệu sản phẩm theo lô, có tiến trình realtime, tạm dừng và tiếp tục an toàn.</p>
            </div>
            <div class="hpt-pie-hero-badge"><span class="dashicons dashicons-performance"></span><strong>Batch engine</strong><small>Giảm tải WordPress</small></div>
        </header>
        <?php if ($sku_focus) : ?>
            <div class="hpt-pie-callout hpt-pie-callout--blue"><span class="dashicons dashicons-info"></span><div><strong>Đang ở chế độ xuất theo SKU</strong><p>File TXT chỉ dùng để lọc sản phẩm; dữ liệu gốc không bị thay đổi.</p></div></div>
        <?php endif; ?>
        <nav class="hpt-pie-nav" aria-label="Điều hướng công cụ">
            <a href="#hpt-pie-import-card"><span class="dashicons dashicons-upload"></span> Nhập sản phẩm</a>
            <a href="#hpt-pie-export-card"><span class="dashicons dashicons-download"></span> Xuất toàn bộ / danh mục</a>
            <a href="#hpt-pie-sku-export-card"><span class="dashicons dashicons-filter"></span> Xuất theo SKU</a>
            <a href="#hpt-pie-index-card"><span class="dashicons dashicons-admin-tools"></span> Tối ưu database</a>
        </nav>
        <?php if ($sku_focus) : ?>
            <div class="hpt-pie-callout hpt-pie-callout--amber"><span class="dashicons dashicons-lightbulb"></span><div><strong>Gợi ý tốc độ</strong><p>Export theo SKU sử dụng tối đa <?php echo (int) hpt_pie_sku_export_workers(); ?> worker độc lập, phù hợp với file SKU lớn.</p></div></div>
        <?php endif; ?>
        <div class="hpt-pie-summary-grid">
            <div><span class="dashicons dashicons-controls-repeat"></span><strong>Resumable</strong><small>Tiếp tục khi gián đoạn</small></div>
            <div><span class="dashicons dashicons-chart-area"></span><strong>Batch nhỏ</strong><small>Không giữ request dài</small></div>
            <div><span class="dashicons dashicons-database-view"></span><strong>WooCommerce native</strong><small>Giữ logic giá, kho, biến thể</small></div>
        </div>

        <div id="hpt-pie-import-card" class="hpt-pie-card">
            <div class="hpt-pie-card-heading"><div class="hpt-pie-step">01</div><div><h2>Nhập sản phẩm từ CSV</h2><p>Dùng cho tạo mới, cập nhật hàng loạt hoặc kiểm tra trước khi ghi dữ liệu.</p></div></div>
            <div class="hpt-pie-card-body">
            <form id="hpt-pie-import-form" enctype="multipart/form-data">
                <div class="hpt-pie-form-grid">
                    <label class="hpt-pie-field hpt-pie-field--wide"><span>File CSV / TXT</span><input type="file" name="import_file" id="hpt-pie-import-file" accept=".csv,.txt" required><small>UTF-8, có dòng tiêu đề. Hệ thống tự nhận diện dấu phẩy, chấm phẩy hoặc tab.</small></label>
                    <fieldset class="hpt-pie-field"><legend>Chế độ nhập</legend><label><input type="radio" name="mode" value="upsert" checked> <strong>Upsert</strong><small>Cập nhật nếu SKU đã tồn tại</small></label><label><input type="radio" name="mode" value="create"> <strong>Chỉ tạo mới</strong><small>Bỏ qua SKU đã tồn tại</small></label><label><input type="radio" name="mode" value="dryrun"> <strong>Dry-run</strong><small>Chỉ kiểm tra, không ghi dữ liệu</small></label></fieldset>
                    <fieldset class="hpt-pie-field"><legend>Tối ưu tốc độ</legend><label><input type="checkbox" name="skip_images" value="1"> <strong>Bỏ xử lý ảnh</strong><small>Khuyến nghị khi nhập file lớn; ảnh có thể bổ sung sau.</small></label><div class="hpt-pie-safe-note"><span class="dashicons dashicons-lock"></span> Import ghi theo batch và có thể tiếp tục.</div></fieldset>
                </div>
                <button class="button button-primary hpt-pie-primary" type="submit"><span class="dashicons dashicons-upload"></span> Bắt đầu nhập</button>
            </form>
            <div id="hpt-pie-import-progress" class="hpt-pie-progress" style="display:none;">
                <strong class="hpt-pie-title">Đang nhập...</strong><div class="hpt-pie-track"><div class="hpt-pie-fill" style="width:0;"></div></div><div class="hpt-pie-meta"></div><p class="hpt-pie-actions"><button type="button" class="button hpt-pie-pause" data-type="import">Tạm dừng</button><button type="button" class="button hpt-pie-resume" data-type="import" style="display:none;">Tiếp tục</button><button type="button" class="button hpt-pie-cancel" data-type="import">Hủy</button></p><div class="hpt-pie-errors" style="display:none;"></div>
            </div>
            </div>
        </div>

        <div id="hpt-pie-export-card" class="hpt-pie-card">
            <div class="hpt-pie-card-heading"><div class="hpt-pie-step">02</div><div><h2>Xuất sản phẩm ra CSV</h2><p>Bao gồm slug và đường dẫn sản phẩm để dùng cho SEO, đối soát hoặc backup.</p></div></div>
            <div class="hpt-pie-card-body">
            <form id="hpt-pie-export-form">
                <div class="hpt-pie-form-grid hpt-pie-form-grid--export">
                    <label class="hpt-pie-field"><span>Phạm vi danh mục</span><select name="category_ids[]" id="hpt-pie-export-cats" multiple size="7">
                                <?php
                                if (!is_wp_error($cats)) {
                                    foreach ($cats as $cat) {
                                        echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($cat->name) . ' (' . (int) $cat->count . ')</option>';
                                    }
                                }
                                ?>
                            </select><small>Không chọn = xuất toàn bộ sản phẩm. CSV luôn có ID, SKU, slug và URL.</small></label>
                    <fieldset class="hpt-pie-field"><legend>Định dạng tải</legend><label><input type="checkbox" name="gzip" value="1"> <strong>Nén .gz</strong><small>Khuyến nghị với file rất lớn.</small></label><div class="hpt-pie-columns"><span class="hpt-pie-chip">Slug</span><span class="hpt-pie-chip">URL sản phẩm</span><span class="hpt-pie-chip">Dữ liệu WooCommerce</span></div></fieldset>
                </div>
                <label class="hpt-pie-checkline"><input type="checkbox" name="excel_view" value="1" checked> Bản xem Excel: bỏ HTML, gộp xuống dòng, cắt tối đa 120 ký tự/ô. Bỏ chọn để xuất đầy đủ khi nhập lại. CSV không lưu độ rộng cột.</label>
                <button class="button button-primary hpt-pie-primary" type="submit"><span class="dashicons dashicons-download"></span> Bắt đầu xuất</button>
            </form>
            <div id="hpt-pie-export-progress" class="hpt-pie-progress" style="display:none;"><strong class="hpt-pie-title">Đang xuất...</strong><div class="hpt-pie-track"><div class="hpt-pie-fill" style="width:0;"></div></div><div class="hpt-pie-meta"></div><p class="hpt-pie-actions"><button type="button" class="button hpt-pie-pause" data-type="export">Tạm dừng</button><button type="button" class="button hpt-pie-resume" data-type="export" style="display:none;">Tiếp tục</button><button type="button" class="button hpt-pie-cancel" data-type="export">Hủy</button><a class="button button-primary hpt-pie-download" style="display:none;" href="#"><span class="dashicons dashicons-download"></span> Tải file</a></p>
            </div>
            </div>
        </div>

        <div id="hpt-pie-index-card" class="hpt-pie-card hpt-pie-card--compact">
            <div class="hpt-pie-card-heading"><div class="hpt-pie-step">03</div><div><h2>Tối ưu database</h2><p>Chạy một lần lúc tải thấp để tăng tốc kiểm tra ảnh trùng khi import.</p></div></div>
            <div class="hpt-pie-card-body"><div class="hpt-pie-warning"><span class="dashicons dashicons-warning"></span><span>Thao tác có thể khóa <code>wp_postmeta</code> trong thời gian ngắn trên database lớn.</span></div><button type="button" class="button" id="hpt-pie-add-index"><span class="dashicons dashicons-admin-tools"></span> Tạo index tối ưu ảnh</button><span id="hpt-pie-add-index-msg" class="hpt-pie-inline-message"></span></div>
        </div>

        <div id="hpt-pie-sku-export-card" class="hpt-pie-card">
            <div class="hpt-pie-card-heading"><div class="hpt-pie-step">04</div><div><h2>Xuất theo danh sách SKU</h2><p>Đối chiếu SKU và xuất CSV nhanh bằng các worker độc lập; không tạo, sửa hoặc xóa sản phẩm.</p></div><span class="hpt-pie-speed-badge"><span class="dashicons dashicons-controls-forward"></span> <?php echo (int) hpt_pie_sku_export_workers(); ?> workers</span></div>
            <div class="hpt-pie-card-body">
            <form id="hpt-pie-sku-export-form" enctype="multipart/form-data">
                <div class="hpt-pie-form-grid">
                    <label class="hpt-pie-field hpt-pie-field--wide"><span>File TXT / CSV chứa SKU</span><input type="file" name="sku_file" id="hpt-pie-sku-file" accept=".txt,.csv" required><small>Mỗi dòng một SKU. Hệ thống giữ nguyên thứ tự trong file và báo mã không tồn tại.</small></label>
                    <fieldset class="hpt-pie-field hpt-pie-field--wide"><legend>Cột dữ liệu xuất</legend><div class="hpt-pie-checkbox-grid">
                                <?php foreach (hpt_pie_sku_export_fields() as $field => $label) : ?>
                                    <label><input type="checkbox" name="fields[]" value="<?php echo esc_attr($field); ?>" <?php checked(in_array($field, array('sku','id','name','slug','regular_price','sale_price','stock_quantity','stock_status','permalink'), true)); ?>> <?php echo esc_html($label); ?></label>
                                <?php endforeach; ?>
                            </div></fieldset>
                    <label class="hpt-pie-checkline"><input type="checkbox" name="gzip" value="1"> <strong>Nén .gz khi hoàn tất</strong></label>
                </div>
                <button class="button button-primary hpt-pie-primary" type="submit"><span class="dashicons dashicons-filter"></span> Xuất theo SKU</button>
            </form>
            <div id="hpt-pie-sku-export-progress" class="hpt-pie-progress" style="display:none;"><strong class="hpt-pie-title">Đang xuất theo SKU...</strong><div class="hpt-pie-track"><div class="hpt-pie-fill" style="width:0;"></div></div><div class="hpt-pie-meta"></div><p class="hpt-pie-actions"><button type="button" class="button hpt-pie-pause" data-type="sku_export">Tạm dừng</button><button type="button" class="button hpt-pie-resume" data-type="sku_export" style="display:none;">Tiếp tục</button><button type="button" class="button hpt-pie-cancel" data-type="sku_export">Hủy</button><a class="button button-primary hpt-pie-download" style="display:none;" href="#"><span class="dashicons dashicons-download"></span> Tải file</a></p>
            </div>
            </div>
        </div>
    </div>
    <?php if ($sku_focus) : ?>
        <script>document.getElementById('hpt-pie-sku-export-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });</script>
    <?php endif; ?>
    <?php
}

/**
 * Restore a bounded list of the current user's unfinished jobs in the admin UI.
 *
 * @return array
 */
function hpt_pie_get_active_jobs_for_user() {
    global $wpdb;

    $jobs = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM ' . hpt_pie_jobs_table() . ' WHERE created_by = %d AND status IN (%s, %s, %s) ORDER BY job_id DESC LIMIT 20',
            get_current_user_id(),
            'running',
            'paused',
            'finalizing'
        ),
        ARRAY_A
    );

    return array_map('hpt_pie_job_progress', (array) $jobs);
}
