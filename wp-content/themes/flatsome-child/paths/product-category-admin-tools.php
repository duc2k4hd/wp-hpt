<?php
/**
 * Giao diện và công cụ quản lý taxonomy product_cat trên màn hình chính.
 * Không thay đổi dữ liệu hiện có; các thao tác ghi đều đi qua API term của WP.
 */
defined('ABSPATH') || exit;

function hpt_product_cat_admin_screen() {
    return is_admin()
        && isset($_GET['taxonomy']) && 'product_cat' === sanitize_key((string) $_GET['taxonomy'])
        && isset($_GET['post_type']) && 'product' === sanitize_key((string) $_GET['post_type']);
}

function hpt_product_cat_admin_can_manage() {
    return current_user_can('manage_woocommerce') || current_user_can('manage_product_terms');
}

function hpt_product_cat_admin_fields() {
    return array(
        'term_id'      => 'ID danh mục',
        'name'         => 'Tên',
        'slug'         => 'Slug',
        'description'  => 'Mô tả',
        'parent_id'    => 'ID danh mục cha',
        'parent_slug'  => 'Slug danh mục cha',
        'parent_name'  => 'Tên danh mục cha',
        'count'        => 'Số sản phẩm',
        'display_type' => 'Kiểu hiển thị',
        'thumbnail_id' => 'Thumbnail ID',
        'menu_order'   => 'Thứ tự',
    );
}

function hpt_product_cat_admin_per_page() {
    $allowed = array(20, 50, 200, 500);
    $requested = absint($_GET['hpt_product_cat_per_page'] ?? 0);
    if (in_array($requested, $allowed, true)) {
        update_user_option(get_current_user_id(), 'edit_product_cat_per_page', $requested);
        return $requested;
    }
    $saved = (int) get_user_option('edit_product_cat_per_page');
    return in_array($saved, $allowed, true) ? $saved : 50;
}

function hpt_product_cat_admin_screen_option() {
    if (!hpt_product_cat_admin_screen()) {
        return;
    }
    hpt_product_cat_admin_per_page();
    add_screen_option('per_page', array(
        'label'   => 'Danh mục mỗi trang',
        'default' => 50,
        'option'  => 'edit_product_cat_per_page',
    ));
}
add_action('load-edit-tags.php', 'hpt_product_cat_admin_screen_option');

function hpt_product_cat_admin_set_screen_option($status, $option, $value) {
    if ('edit_product_cat_per_page' !== $option) {
        return $status;
    }
    return in_array((int) $value, array(20, 50, 200, 500), true) ? (int) $value : 50;
}
add_filter('set-screen-option', 'hpt_product_cat_admin_set_screen_option', 10, 3);

function hpt_product_cat_admin_screen_settings($settings, $screen) {
    if (!$screen || 'edit-tags' !== $screen->base || !hpt_product_cat_admin_screen()) {
        return $settings;
    }
    return $settings . '<p class="hpt-cat-screen-note">Số danh mục mỗi trang: 20 / 50 / 200 / 500.</p>';
}

function hpt_product_cat_admin_assets($hook) {
    if ('edit-tags.php' !== $hook || !hpt_product_cat_admin_screen()) {
        return;
    }
    wp_enqueue_style('dashicons');
    $dir = get_stylesheet_directory() . '/assets/vendor/slimselect/';
    $uri = get_stylesheet_directory_uri() . '/assets/vendor/slimselect/';
    if (file_exists($dir . 'slimselect.css')) {
        wp_enqueue_style('hpt-cat-slimselect', $uri . 'slimselect.css', array(), (string) filemtime($dir . 'slimselect.css'));
    }
    if (file_exists($dir . 'slimselect.js')) {
        wp_enqueue_script('hpt-cat-slimselect', $uri . 'slimselect.js', array(), (string) filemtime($dir . 'slimselect.js'), false);
    }
    add_filter('screen_settings', 'hpt_product_cat_admin_screen_settings', 10, 2);
}
add_action('admin_enqueue_scripts', 'hpt_product_cat_admin_assets');

function hpt_product_cat_admin_columns($columns) {
    return array(
        'cb'          => isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />',
        'name'        => 'Tên',
        'category_id' => 'ID',
        'slug'        => 'Slug',
        'description' => 'Mô tả',
        'posts'       => 'Số sản phẩm',
    );
}
add_filter('manage_edit-product_cat_columns', 'hpt_product_cat_admin_columns', 20);

function hpt_product_cat_admin_custom_column($content, $column_name, $term_id) {
    if ('category_id' === $column_name) {
        return '<code>' . esc_html((string) absint($term_id)) . '</code>';
    }
    return $content;
}
add_filter('manage_product_cat_custom_column', 'hpt_product_cat_admin_custom_column', 20, 3);

function hpt_product_cat_admin_row_actions($actions, $term) {
    if ($term && hpt_product_cat_admin_screen()) {
        $actions['hpt_cat_quick_edit'] = '<a href="#" class="hpt-cat-quick-edit" data-term-id="' . esc_attr($term->term_id) . '">Sửa nhanh</a>';
    }
    return $actions;
}
add_filter('term_row_actions', 'hpt_product_cat_admin_row_actions', 20, 2);

function hpt_product_cat_admin_toolbar() {
    if (!hpt_product_cat_admin_screen() || !hpt_product_cat_admin_can_manage()) {
        return;
    }
    $fields = hpt_product_cat_admin_fields();
    $tool_url = admin_url('edit.php?post_type=product&page=hpt-product-cat-tools');
    $export_defaults = array('term_id', 'name', 'slug', 'parent_id', 'description');
    ?>
    <div class="hpt-cat-toolbar">
        <div class="hpt-cat-toolbar-head">
            <div class="hpt-cat-identity">
                <span class="hpt-cat-eyebrow">PRODUCT / CATEGORIES</span>
                <h2>Quản lý danh mục</h2>
                <p>Nhập, xuất, tìm kiếm và chỉnh sửa danh mục ngay trên một màn hình.</p>
            </div>
            <div class="hpt-cat-actions">
                <label class="hpt-cat-page-size">Hiển thị
                    <select class="hpt-cat-page-size-select">
                        <?php foreach (array(20, 50, 200, 500) as $size) : ?>
                            <option value="<?php echo (int) $size; ?>" <?php selected(hpt_product_cat_admin_per_page(), $size); ?>><?php echo (int) $size; ?></option>
                        <?php endforeach; ?>
                    </select> danh mục
                </label>
                <a class="button" href="<?php echo esc_url($tool_url); ?>"><span class="dashicons dashicons-database-export"></span> Công cụ CSV</a>
                <button type="button" class="button button-primary hpt-cat-open-import"><span class="dashicons dashicons-upload"></span> Nhập XLSX</button>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hpt-cat-export-form">
                    <input type="hidden" name="action" value="hpt_product_cat_export_xlsx">
                    <?php wp_nonce_field('hpt_product_cat_export_xlsx', 'hpt_product_cat_nonce'); ?>
                    <details class="hpt-cat-export-fields"><summary><span class="dashicons dashicons-admin-generic"></span> Chọn cột</summary><div class="hpt-cat-export-options">
                        <?php foreach ($fields as $key => $label) : ?>
                            <label><input type="checkbox" name="fields[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $export_defaults, true)); ?>> <?php echo esc_html($label); ?></label>
                        <?php endforeach; ?>
                    </div></details>
                    <button type="submit" class="button"><span class="dashicons dashicons-download"></span> Xuất XLSX</button>
                </form>
            </div>
        </div>
        <div class="hpt-cat-import-modal" hidden>
            <div class="hpt-cat-modal-card">
                <button type="button" class="hpt-cat-modal-close" aria-label="Đóng">×</button>
                <h2>Nhập danh mục từ XLSX</h2>
                <p class="description">Hỗ trợ ID, tên, slug, mô tả, danh mục cha và các trường WooCommerce. Xử lý theo batch để tránh nghẽn server.</p>
                <label class="hpt-cat-upload-label">Chọn file XLSX<input type="file" class="hpt-cat-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></label>
                <label class="hpt-cat-update-check"><input type="checkbox" class="hpt-cat-allow-update"> <strong>Cho phép cập nhật danh mục đã có</strong><span>Mặc định tắt: ID/slug đã tồn tại sẽ được bỏ qua.</span></label>
                <div class="hpt-cat-mapping" hidden></div>
                <div class="hpt-cat-import-actions"><button type="button" class="button button-primary hpt-cat-start" disabled>Tiếp tục</button><span class="hpt-cat-progress-text"></span></div>
                <div class="hpt-cat-progress" hidden><div></div></div>
            </div>
        </div>
    </div>
    <?php
}
add_action('all_admin_notices', 'hpt_product_cat_admin_toolbar');

function hpt_product_cat_admin_require_ajax() {
    if (!hpt_product_cat_admin_can_manage()) {
        wp_send_json_error(array('message' => 'Bạn không có quyền quản lý danh mục.'), 403);
    }
    check_ajax_referer('hpt_product_cat_admin', 'nonce');
}

function hpt_product_cat_admin_job_key($token) {
    return 'hpt_product_cat_xlsx_' . get_current_user_id() . '_' . preg_replace('/[^a-zA-Z0-9]/', '', (string) $token);
}

function hpt_product_cat_admin_private_dir() {
    if (function_exists('hpt_brand_private_dir')) {
        return hpt_brand_private_dir();
    }
    $upload = wp_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'hpt-product-cat-ie';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
        @file_put_contents($dir . '/index.html', '');
        @file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    return $dir;
}

function hpt_product_cat_admin_xlsx_fields_from_headers($headers) {
    $fields = hpt_product_cat_admin_fields();
    $mapping = array();
    foreach ((array) $headers as $index => $header) {
        $normalized = function_exists('hpt_product_cat_tools_normalize_header')
            ? hpt_product_cat_tools_normalize_header($header)
            : sanitize_key($header);
        if (!isset($fields[$normalized])) {
            foreach ($fields as $field => $label) {
                $label_key = str_replace('-', '_', sanitize_title(remove_accents($label)));
                if ($normalized === $label_key) {
                    $normalized = $field;
                    break;
                }
            }
        }
        if (isset($fields[$normalized])) {
            $mapping[$index] = $normalized;
        }
    }
    return $mapping;
}

function hpt_product_cat_admin_empty_summary() {
    return array(
        'processed' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0,
        'skipped' => 0, 'parent_updates' => 0, 'errors_count' => 0, 'errors' => array(),
    );
}

add_action('wp_ajax_hpt_product_cat_xlsx_start', function () {
    hpt_product_cat_admin_require_ajax();
    if (empty($_FILES['file']['tmp_name']) || strtolower(pathinfo((string) $_FILES['file']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
        wp_send_json_error(array('message' => 'Vui lòng chọn đúng file .xlsx.'), 400);
    }
    if (!function_exists('hpt_brand_headers_from_sheet') || !function_exists('hpt_brand_xlsx_total_rows')) {
        wp_send_json_error(array('message' => 'Máy chủ chưa sẵn sàng thư viện đọc XLSX.'), 500);
    }
    $dir = hpt_product_cat_admin_private_dir();
    $target = trailingslashit($dir) . wp_unique_filename($dir, sanitize_file_name((string) $_FILES['file']['name']));
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
        wp_send_json_error(array('message' => 'Không thể lưu file import.'), 500);
    }
    $headers = hpt_brand_headers_from_sheet($target);
    if (is_wp_error($headers) || empty($headers)) {
        @unlink($target);
        wp_send_json_error(array('message' => is_wp_error($headers) ? $headers->get_error_message() : 'File không có dòng tiêu đề.'), 400);
    }
    $token = wp_generate_password(24, false, false);
    $mapping = hpt_product_cat_admin_xlsx_fields_from_headers($headers);
    $total = hpt_brand_xlsx_total_rows($target);
    $job = array(
        'path' => $target, 'headers' => $headers, 'mapping' => $mapping, 'offset' => 2,
        'total' => max(0, (int) $total), 'allow_update' => false,
        'summary' => hpt_product_cat_admin_empty_summary(), 'pending_parents' => array(),
    );
    set_transient(hpt_product_cat_admin_job_key($token), $job, DAY_IN_SECONDS);
    wp_send_json_success(array('token' => $token, 'headers' => $headers, 'mapping' => $mapping, 'fields' => hpt_product_cat_admin_fields(), 'total' => max(0, (int) $total)));
});

add_action('wp_ajax_hpt_product_cat_xlsx_map', function () {
    hpt_product_cat_admin_require_ajax();
    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    $key = hpt_product_cat_admin_job_key($token);
    $job = get_transient($key);
    if (!is_array($job)) {
        wp_send_json_error(array('message' => 'Job import đã hết hạn.'), 404);
    }
    $mapping = json_decode(wp_unslash($_POST['mapping'] ?? '{}'), true);
    $job['mapping'] = is_array($mapping) ? array_map('sanitize_key', $mapping) : array();
    $job['allow_update'] = !empty($_POST['allow_update']);
    set_transient($key, $job, DAY_IN_SECONDS);
    wp_send_json_success(array('message' => 'Đã lưu mapping danh mục.'));
});

add_action('wp_ajax_hpt_product_cat_xlsx_batch', function () {
    hpt_product_cat_admin_require_ajax();
    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    $key = hpt_product_cat_admin_job_key($token);
    $job = get_transient($key);
    if (!is_array($job) || empty($job['path']) || !file_exists($job['path'])) {
        wp_send_json_error(array('message' => 'Job import không tồn tại.'), 404);
    }
    if ((int) $job['total'] <= 0) {
        @unlink($job['path']);
        delete_transient($key);
        wp_send_json_success(array('done' => true, 'percent' => 100, 'summary' => $job['summary']));
    }
    $start = (int) $job['offset'];
    $end = min($start + 99, (int) $job['total'] + 1);
    if (!function_exists('hpt_brand_reader_for_rows')) {
        wp_send_json_error(array('message' => 'Không tìm thấy bộ đọc XLSX.'), 500);
    }
    $book = hpt_brand_reader_for_rows($job['path'], $start, $end);
    if (is_wp_error($book)) {
        wp_send_json_error(array('message' => $book->get_error_message()), 500);
    }
    $sheet = $book->getActiveSheet();
    $rows = $sheet->rangeToArray('A' . $start . ':' . $sheet->getHighestColumn() . $end, null, true, false);
    $available = array_values(array_unique(array_filter(array_values((array) $job['mapping']))));
    foreach ($rows as $row_index => $row) {
        $job['summary']['processed']++;
        $normalized = array();
        foreach ((array) $job['mapping'] as $index => $field) {
            $normalized[$field] = function_exists('hpt_product_cat_tools_normalize_cell')
                ? hpt_product_cat_tools_normalize_cell($row[(int) $index] ?? '')
                : trim((string) ($row[(int) $index] ?? ''));
        }
        if (function_exists('hpt_product_cat_tools_import_row')) {
            hpt_product_cat_tools_import_row($normalized, $available, $start + (int) $row_index, $job['summary'], $job['pending_parents'], !empty($job['allow_update']));
        }
    }
    $book->disconnectWorksheets();
    unset($book);
    $job['offset'] = $end + 1;
    $done = (int) $job['summary']['processed'] >= (int) $job['total'];
    if ($done && !empty($job['pending_parents']) && function_exists('hpt_product_cat_tools_resolve_pending_parents')) {
        hpt_product_cat_tools_resolve_pending_parents($job['pending_parents'], $job['summary']);
    }
    $percent = min(100, (int) floor($job['summary']['processed'] * 100 / max(1, (int) $job['total'])));
    $summary = $job['summary'];
    if ($done) {
        @unlink($job['path']);
        delete_transient($key);
    } else {
        set_transient($key, $job, DAY_IN_SECONDS);
    }
    wp_send_json_success(array('done' => $done, 'percent' => $done ? 100 : $percent, 'summary' => $summary));
});

add_action('admin_post_hpt_product_cat_export_xlsx', function () {
    if (!hpt_product_cat_admin_can_manage()) {
        wp_die('Bạn không có quyền quản lý danh mục.');
    }
    check_admin_referer('hpt_product_cat_export_xlsx', 'hpt_product_cat_nonce');
    if (!function_exists('hpt_brand_write_xlsx')) {
        wp_die('Không tìm thấy bộ tạo file XLSX.');
    }
    $available = hpt_product_cat_admin_fields();
    $fields = array_values(array_intersect(array_keys($available), array_map('sanitize_key', (array) ($_POST['fields'] ?? array()))));
    if (empty($fields)) {
        $fields = array('term_id', 'name', 'slug', 'parent_id', 'description');
    }
    $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 0, 'orderby' => 'term_id', 'order' => 'ASC'));
    if (is_wp_error($terms)) {
        wp_die($terms->get_error_message());
    }
    $headers = array_map(static function ($field) use ($available) { return $available[$field]; }, $fields);
    $rows = array();
    foreach ($terms as $term) {
        $parent = $term->parent ? get_term((int) $term->parent, 'product_cat') : null;
        $values = array(
            'term_id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug,
            'description' => $term->description, 'parent_id' => $term->parent,
            'parent_slug' => ($parent && !is_wp_error($parent)) ? $parent->slug : '',
            'parent_name' => ($parent && !is_wp_error($parent)) ? $parent->name : '',
            'count' => $term->count,
            'display_type' => (string) get_term_meta($term->term_id, 'display_type', true),
            'thumbnail_id' => (int) get_term_meta($term->term_id, 'thumbnail_id', true),
            'menu_order' => (int) get_term_meta($term->term_id, 'order', true),
        );
        $rows[] = array_map(static function ($field) use ($values) { return $values[$field] ?? ''; }, $fields);
    }
    $path = trailingslashit(hpt_product_cat_admin_private_dir()) . 'product-cat-export-' . get_current_user_id() . '-' . wp_generate_password(8, false, false) . '.xlsx';
    $result = hpt_brand_write_xlsx($headers, $rows, $path);
    if (is_wp_error($result)) {
        wp_die($result->get_error_message());
    }
    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="product-categories-' . gmdate('Ymd-His') . '.xlsx"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    @unlink($path);
    exit;
});

add_action('wp_ajax_hpt_product_cat_get_term', function () {
    hpt_product_cat_admin_require_ajax();
    $term = get_term(absint($_POST['term_id'] ?? 0), 'product_cat');
    if (!$term || is_wp_error($term)) {
        wp_send_json_error(array('message' => 'Không tìm thấy danh mục.'), 404);
    }
    wp_send_json_success(array(
        'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug,
        'description' => $term->description, 'parent' => $term->parent,
    ));
});

add_action('wp_ajax_hpt_product_cat_update_term', function () {
    hpt_product_cat_admin_require_ajax();
    $term_id = absint($_POST['term_id'] ?? 0);
    $result = wp_update_term($term_id, 'product_cat', array(
        'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
        'slug' => sanitize_title(wp_unslash($_POST['slug'] ?? '')),
        'description' => wp_kses_post(wp_unslash($_POST['description'] ?? '')),
        'parent' => absint($_POST['parent'] ?? 0),
    ));
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 400);
    }
    wp_send_json_success(array('message' => 'Đã lưu danh mục.'));
});

add_action('admin_footer-edit-tags.php', function () {
    if (!hpt_product_cat_admin_screen()) {
        return;
    }
    $nonce = wp_create_nonce('hpt_product_cat_admin');
    ?>
    <style id="hpt-product-cat-admin-style">
        body.taxonomy-product_cat #wpbody-content { max-width:1600px; }
        body.taxonomy-product_cat .wrap > h1 { color:#172b4d; font-size:28px; font-weight:700; letter-spacing:-.02em; margin:26px 0 18px; }
        body.taxonomy-product_cat #col-left { width:31%; padding-right:18px; box-sizing:border-box; }
        body.taxonomy-product_cat #col-right { width:69%; box-sizing:border-box; }
        body.taxonomy-product_cat #col-left .form-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px; box-shadow:0 8px 24px rgba(25,45,75,.06); }
        body.taxonomy-product_cat #col-left h2 { color:#172b4d; font-size:19px; margin:0 0 20px; }
        body.taxonomy-product_cat #col-left input[type=text], body.taxonomy-product_cat #col-left textarea, body.taxonomy-product_cat #col-left select { border:1px solid #cbd5e1; border-radius:8px; min-height:40px; box-shadow:none; }
        body.taxonomy-product_cat #col-right .wp-list-table { border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 8px 24px rgba(25,45,75,.05); }
        body.taxonomy-product_cat #col-right .wp-list-table thead th { background:#f7f9fc; color:#52606d; font-weight:700; text-transform:uppercase; font-size:11px; letter-spacing:.04em; }
        body.taxonomy-product_cat #col-right .wp-list-table tbody tr:nth-child(even) { background:#fbfcfe; }
        body.taxonomy-product_cat #col-right .wp-list-table tbody tr:hover { background:#eef6ff; }
        body.taxonomy-product_cat #col-right .wp-list-table td { padding-top:13px; padding-bottom:13px; vertical-align:top; }
        body.taxonomy-product_cat #col-right .wp-list-table code { background:#edf2f7; color:#425466; border-radius:5px; padding:3px 7px; font-size:11px; }
        .hpt-cat-toolbar { border:1px solid #dbe5f0; border-left:5px solid #2271b1; border-radius:14px; padding:22px 24px; margin:18px 0 24px; background:linear-gradient(135deg,#fff 0%,#f7fbff 100%); box-shadow:0 10px 30px rgba(25,45,75,.08); }
        .hpt-cat-toolbar-head { display:flex; justify-content:space-between; align-items:center; gap:24px; }
        .hpt-cat-eyebrow { display:block; color:#2271b1; font-size:10px; font-weight:800; letter-spacing:.12em; margin-bottom:6px; }
        .hpt-cat-identity h2 { color:#172b4d; font-size:20px; margin:0 0 5px; }
        .hpt-cat-identity p { color:#627d98; margin:0; }
        .hpt-cat-actions { display:flex; align-items:center; justify-content:flex-end; gap:9px; flex-wrap:wrap; }
        .hpt-cat-actions .button { min-height:34px; border-radius:7px; padding:0 13px; display:inline-flex; align-items:center; gap:5px; }
        .hpt-cat-page-size { display:flex; align-items:center; gap:5px; color:#52606d; font-size:12px; white-space:nowrap; }
        .hpt-cat-page-size select { border:1px solid #cbd5e1; border-radius:7px; min-height:34px; }
        .hpt-cat-export-form { display:flex; align-items:center; gap:8px; }
        .hpt-cat-export-fields { position:relative; }
        .hpt-cat-export-fields summary { list-style:none; cursor:pointer; border:1px solid #cbd5e1; border-radius:7px; min-height:32px; padding:0 10px; display:flex; align-items:center; gap:5px; color:#425466; font-size:12px; background:#fff; }
        .hpt-cat-export-fields summary::-webkit-details-marker { display:none; }
        .hpt-cat-export-options { position:absolute; right:0; top:42px; z-index:20; width:220px; padding:12px; background:#fff; border:1px solid #dbe5f0; border-radius:9px; box-shadow:0 12px 30px rgba(25,45,75,.15); display:grid; gap:8px; }
        .hpt-cat-export-options label { font-size:12px; color:#425466; }
        .hpt-cat-import-modal { position:fixed; inset:0; background:#0008; z-index:100000; display:grid; place-items:center; }
        .hpt-cat-import-modal[hidden] { display:none; }
        .hpt-cat-modal-card { background:#fff; width:min(720px,calc(100% - 32px)); padding:26px; border-radius:14px; box-shadow:0 18px 60px rgba(15,23,42,.25); position:relative; }
        .hpt-cat-modal-card h2 { color:#172b4d; font-size:21px; }
        .hpt-cat-modal-close { position:absolute; right:16px; top:10px; border:0; background:none; font-size:28px; cursor:pointer; }
        .hpt-cat-upload-label { display:block; margin:20px 0; padding:18px; border:2px dashed #90cdf4; border-radius:10px; text-align:center; font-weight:600; color:#22577a; background:#f7fbff; }
        .hpt-cat-upload-label input { display:block; margin:12px auto 0; }
        .hpt-cat-update-check { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:12px 0; }
        .hpt-cat-update-check input { width:20px; height:20px; accent-color:#2271b1; }
        .hpt-cat-update-check span { width:100%; padding-left:28px; color:#646970; font-size:12px; }
        .hpt-cat-mapping { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin:18px 0; }
        .hpt-cat-mapping label { display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .hpt-cat-mapping select { border:1px solid #cbd5e1; border-radius:7px; min-height:34px; }
        .hpt-cat-import-actions { display:flex; gap:15px; align-items:center; }
        .hpt-cat-progress { height:10px; background:#dcdcde; border-radius:6px; overflow:hidden; margin-top:15px; }
        .hpt-cat-progress div { height:100%; width:0; background:#2271b1; transition:width .2s; }
        .hpt-cat-screen-note { margin-top:6px; color:#646970; }
        body.taxonomy-product_cat #col-left .ss-main { width:100%; min-height:48px; border:1px solid #cbd5e1; border-radius:10px; background:#fff; font-size:14px; }
        body.taxonomy-product_cat #col-left .ss-content { z-index:100100; width:100%; min-width:320px; border:1px solid #dbe5f0; border-radius:10px; box-shadow:0 14px 34px rgba(15,23,42,.18); }
        body.taxonomy-product_cat #col-left .ss-list { max-height:360px; }
        body.taxonomy-product_cat #col-left .ss-search input { min-height:42px; border:0; border-bottom:1px solid #e2e8f0; border-radius:0; padding:0 12px; font-size:14px; }
        body.taxonomy-product_cat #col-left .hpt-cat-parent-help { color:#627d98; margin-top:7px; }
        @media(max-width:1100px) { .hpt-cat-toolbar-head { align-items:flex-start; flex-direction:column; } .hpt-cat-actions { justify-content:flex-start; } }
        @media(max-width:782px) { body.taxonomy-product_cat #col-left, body.taxonomy-product_cat #col-right { width:100%; float:none; padding:0; } .hpt-cat-toolbar { padding:18px; } }
    </style>
    <script>
    jQuery(function($){
      const cfg={ajaxurl:ajaxurl,nonce:'<?php echo esc_js($nonce); ?>'};let token='';
      const parentSelect=document.querySelector('#parent, select[name="parent"]');
      if(parentSelect && window.SlimSelect){new SlimSelect({select:parentSelect,settings:{showSearch:true,searchPlaceholder:'Tìm danh mục cha…',searchText:'Không tìm thấy danh mục',placeholderText:'Chọn danh mục cha',allowDeselect:true,closeOnSelect:true,contentPosition:'absolute',openPosition:'auto'}});const field=parentSelect.closest('.form-field')||parentSelect.parentElement;if(field&&!field.querySelector('.hpt-cat-parent-help')){const note=document.createElement('p');note.className='description hpt-cat-parent-help';note.textContent='Gõ tên để tìm nhanh danh mục cha.';field.appendChild(note);}}
      const modal=$('.hpt-cat-import-modal'),mapBox=$('.hpt-cat-mapping'),start=$('.hpt-cat-start'),file=$('.hpt-cat-file');
      $('.hpt-cat-page-size-select').on('change',function(){const u=new URL(window.location.href);u.searchParams.set('hpt_product_cat_per_page',this.value);u.searchParams.delete('paged');window.location.href=u.toString();});
      $('.hpt-cat-open-import').on('click',()=>modal.prop('hidden',false));$('.hpt-cat-modal-close').on('click',()=>modal.prop('hidden',true));
      file.on('change',function(){if(!this.files[0])return;const fd=new FormData();fd.append('action','hpt_product_cat_xlsx_start');fd.append('nonce',cfg.nonce);fd.append('file',this.files[0]);$('.hpt-cat-progress-text').text('Đang đọc tiêu đề…');$.ajax({url:cfg.ajaxurl,method:'POST',data:fd,processData:false,contentType:false}).done(r=>{if(!r.success){alert(r.data.message);return}token=r.data.token;mapBox.empty().prop('hidden',false);const fields=r.data.fields;Object.entries(r.data.headers).forEach(([i,h])=>{let opts='<option value="">Bỏ qua</option>';Object.entries(fields).forEach(([k,v])=>opts+=`<option value="${k}" ${r.data.mapping[i]===k?'selected':''}>${$('<div>').text(v).html()}</option>`);mapBox.append(`<label>${$('<div>').text(h).html()}<select data-index="${i}">${opts}</select></label>`);});start.prop('disabled',false);$('.hpt-cat-progress-text').text(`${r.data.total} dòng dữ liệu`);}).fail(()=>alert('Không đọc được file XLSX.'));});
      mapBox.on('change','select',function(){const v=this.value;if(!v)return;mapBox.find('select').not(this).filter(function(){return this.value===v;}).val('');});
      start.on('click',function(){const mapping={};mapBox.find('select').each(function(){if(this.value)mapping[$(this).data('index')]=this.value;});const fd=new FormData();fd.append('action','hpt_product_cat_xlsx_map');fd.append('nonce',cfg.nonce);fd.append('token',token);fd.append('mapping',JSON.stringify(mapping));fd.append('allow_update',$('.hpt-cat-allow-update').prop('checked')?'1':'0');$.ajax({url:cfg.ajaxurl,method:'POST',data:fd,processData:false,contentType:false}).done(r=>{if(!r.success){alert(r.data.message);return;}start.prop('disabled',true);$('.hpt-cat-progress').prop('hidden',false);runBatch();});});
      function runBatch(){$.post(cfg.ajaxurl,{action:'hpt_product_cat_xlsx_batch',nonce:cfg.nonce,token:token},function(r){if(!r.success){alert(r.data.message);start.prop('disabled',false);return;}const s=r.data.summary||{};$('.hpt-cat-progress div').css('width',r.data.percent+'%');$('.hpt-cat-progress-text').text(`${r.data.percent}% · tạo ${s.created||0}, cập nhật ${s.updated||0}, bỏ qua ${s.skipped||0}, lỗi ${s.errors_count||0}`);if(!r.data.done){setTimeout(runBatch,180);}else{let msg=`Hoàn tất. Tạo: ${s.created||0}, cập nhật: ${s.updated||0}, bỏ qua: ${s.skipped||0}, lỗi: ${s.errors_count||0}.`;if(s.errors&&s.errors.length)msg+='\n\n'+s.errors.slice(0,8).join('\n');alert(msg);location.reload();}});}
      $(document).on('click','.hpt-cat-quick-edit',function(e){e.preventDefault();const id=$(this).data('term-id');$.post(cfg.ajaxurl,{action:'hpt_product_cat_get_term',nonce:cfg.nonce,term_id:id},function(r){if(!r.success){alert(r.data.message);return;}const d=r.data;$('body').append(`<div class="hpt-cat-quick-modal hpt-cat-import-modal"><div class="hpt-cat-modal-card"><h2>Sửa nhanh danh mục</h2><input type="hidden" name="term_id" value="${d.id}"><label>Tên<input name="name" value="${$('<div>').text(d.name).html()}"></label><label>Slug<input name="slug" value="${$('<div>').text(d.slug).html()}"></label><label>Mô tả<textarea name="description">${$('<div>').text(d.description).html()}</textarea></label><label>ID danh mục cha<input name="parent" value="${d.parent||0}"></label><div class="hpt-cat-import-actions"><button class="button cancel">Hủy</button><button class="button button-primary save">Lưu</button></div></div></div>`);});});
      $(document).on('click','.hpt-cat-quick-modal .cancel',function(){$(this).closest('.hpt-cat-quick-modal').remove();});$(document).on('click','.hpt-cat-quick-modal .save',function(){const box=$(this).closest('.hpt-cat-quick-modal');const data={action:'hpt_product_cat_update_term',nonce:cfg.nonce};box.find('input,textarea').each(function(){data[this.name]=this.value;});$.post(cfg.ajaxurl,data,function(r){if(!r.success){alert(r.data.message);return;}box.remove();location.reload();});});
    });
    </script>
    <?php
});


