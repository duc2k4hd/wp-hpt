<?php
/**
 * Thương hiệu admin tools: XLSX import/export, bounded batch jobs and quick edit.
 */
defined('ABSPATH') || exit;

function hpt_brand_taxonomy() {
    return (string) apply_filters('hpt_brand_admin_taxonomy', 'thuong_hieu');
}

function hpt_brand_admin_screen() {
    return is_admin() && isset($_GET['taxonomy']) && hpt_brand_taxonomy() === sanitize_key((string) $_GET['taxonomy'])
        && isset($_GET['post_type']) && 'product' === sanitize_key((string) $_GET['post_type']);
}

function hpt_brand_can_manage() {
    $taxonomy = get_taxonomy(hpt_brand_taxonomy());
    return $taxonomy && current_user_can($taxonomy->cap->manage_terms);
}

function hpt_brand_fields() {
    return array(
        'id'          => 'ID',
        'name'        => 'Tên',
        'slug'        => 'Slug',
        'description' => 'Mô tả',
        'parent'      => 'Parent ID',
        'count'       => 'Số sản phẩm',
    );
}

function hpt_brand_admin_assets($hook) {
    if ('edit-tags.php' !== $hook || !hpt_brand_admin_screen()) {
        return;
    }
    wp_enqueue_style('dashicons');
    // Dùng asset SlimSelect nội bộ: tìm kiếm nhanh, không phụ thuộc CDN và
    // vẫn giữ nguyên select gốc để WordPress nhận đúng giá trị parent.
    $slim_dir = get_stylesheet_directory() . '/assets/vendor/slimselect/';
    $slim_uri = get_stylesheet_directory_uri() . '/assets/vendor/slimselect/';
    $slim_css = $slim_dir . 'slimselect.css';
    $slim_js  = $slim_dir . 'slimselect.js';
    if (file_exists($slim_css)) {
        wp_enqueue_style('hpt-slimselect', $slim_uri . 'slimselect.css', array(), (string) filemtime($slim_css));
    }
    if (file_exists($slim_js)) {
        // Nạp ở phần head vì phần khởi tạo nằm trong admin_footer-edit-tags.php.
        wp_enqueue_script('hpt-slimselect', $slim_uri . 'slimselect.js', array(), (string) filemtime($slim_js), false);
    }
    add_filter('screen_settings', 'hpt_brand_screen_settings', 10, 2);
}
add_action('admin_enqueue_scripts', 'hpt_brand_admin_assets');

function hpt_brand_screen_settings($settings, $screen) {
    if (!$screen || 'edit-tags' !== $screen->base || !hpt_brand_admin_screen()) {
        return $settings;
    }
    return $settings . '<p class="hpt-brand-screen-note">Số thương hiệu mỗi trang: chọn trong Tùy chọn màn hình (20 / 50 / 200 / 500).</p>';
}

function hpt_brand_add_screen_option() {
    if (!hpt_brand_admin_screen()) {
        return;
    }
    $requested = absint($_GET['hpt_brand_per_page'] ?? 0);
    if (in_array($requested, array(20, 50, 200, 500), true)) {
        update_user_option(get_current_user_id(), 'edit_thuong_hieu_per_page', $requested);
    }
    add_screen_option('per_page', array(
        'label'   => 'Thương hiệu mỗi trang',
        'default' => 50,
        'option'  => 'edit_thuong_hieu_per_page',
    ));
}
add_action('load-edit-tags.php', 'hpt_brand_add_screen_option');

function hpt_brand_set_screen_option($status, $option, $value) {
    if ('edit_thuong_hieu_per_page' !== $option) {
        return $status;
    }
    $allowed = array(20, 50, 200, 500);
    return in_array((int) $value, $allowed, true) ? (int) $value : 50;
}
add_filter('set-screen-option', 'hpt_brand_set_screen_option', 10, 3);

function hpt_brand_per_page() {
    $requested = absint($_GET['hpt_brand_per_page'] ?? 0);
    if (in_array($requested, array(20, 50, 200, 500), true)) {
        update_user_option(get_current_user_id(), 'edit_thuong_hieu_per_page', $requested);
        return $requested;
    }
    $value = (int) get_user_option('edit_thuong_hieu_per_page');
    return in_array($value, array(20, 50, 200, 500), true) ? $value : 50;
}

function hpt_brand_filter_per_page($value) {
    if (!hpt_brand_admin_screen()) return $value;
    $requested = absint($_GET['hpt_brand_per_page'] ?? 0);
    if (in_array($requested, array(20, 50, 200, 500), true)) {
        update_user_option(get_current_user_id(), 'edit_thuong_hieu_per_page', $requested);
        return $requested;
    }
    return hpt_brand_per_page();
}
add_filter('edit_tags_per_page', 'hpt_brand_filter_per_page');

/** Custom taxonomies do not run the core edit_tags_per_page filter. */
function hpt_brand_filter_term_query_args($args, $taxonomies) {
    if (!hpt_brand_admin_screen() || !in_array(hpt_brand_taxonomy(), (array) $taxonomies, true)) {
        return $args;
    }
    $per_page = hpt_brand_per_page();
    $page = max(1, absint($_GET['paged'] ?? 1));
    $args['number'] = $per_page;
    $args['offset'] = ($page - 1) * $per_page;
    return $args;
}
add_filter('get_terms_args', 'hpt_brand_filter_term_query_args', 10, 2);

function hpt_brand_columns($columns) {
    return array(
        'cb'          => isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />',
        'name'        => 'Tên',
        'hpt_id'      => 'ID',
        'hpt_slug'    => 'Slug',
        'hpt_desc'    => 'Mô tả',
        // Dùng khóa chuẩn `posts` để WP_Terms_List_Table tự lấy `$term->count`.
        'posts'       => 'Số sản phẩm',
    );
}
add_filter('manage_edit-' . 'thuong_hieu' . '_columns', 'hpt_brand_columns');

function hpt_brand_custom_column($content, $column_name, $term_id) {
    $term = get_term((int) $term_id, hpt_brand_taxonomy());
    if (!$term || is_wp_error($term)) {
        return $content;
    }
    if ('hpt_id' === $column_name) {
        return '<code>' . esc_html((string) $term->term_id) . '</code>';
    }
    if ('hpt_slug' === $column_name) {
        return '<code>' . esc_html($term->slug) . '</code>';
    }
    if ('hpt_desc' === $column_name) {
        return esc_html(wp_trim_words(wp_strip_all_tags($term->description), 14, '…'));
    }
    if (in_array($column_name, array('count', 'posts'), true)) {
        $count = max(0, (int) $term->count);
        $url = add_query_arg(
            array(
                'post_type' => 'product',
                hpt_brand_taxonomy() => $term->slug,
            ),
            admin_url('edit.php')
        );
        return '<a href="' . esc_url($url) . '">' . esc_html(number_format_i18n($count)) . '</a>';
    }
    return $content;
}
add_filter('manage_' . 'thuong_hieu' . '_custom_column', 'hpt_brand_custom_column', 10, 3);

/**
 * WooCommerce có thể đăng ký thêm product_brand, trong khi website đang dùng
 * thuong_hieu.  Ẩn menu product_brand bị trùng, không xóa taxonomy hay dữ liệu.
 */
function hpt_brand_remove_duplicate_product_brand_menu() {
    if (!taxonomy_exists('product_brand') || !taxonomy_exists(hpt_brand_taxonomy())) {
        return;
    }
    remove_submenu_page(
        'edit.php?post_type=product',
        'edit-tags.php?taxonomy=product_brand&amp;post_type=product'
    );
}
add_action('admin_menu', 'hpt_brand_remove_duplicate_product_brand_menu', 999);

function hpt_brand_row_actions($actions, $term) {
    if ($term && hpt_brand_admin_screen()) {
        $actions['hpt_quick_edit'] = '<a href="#" class="hpt-brand-quick-edit" data-term-id="' . esc_attr($term->term_id) . '">Sửa nhanh</a>';
    }
    return $actions;
}
add_filter('term_row_actions', 'hpt_brand_row_actions', 10, 2);

function hpt_brand_render_toolbar() {
    if (!hpt_brand_admin_screen() || !hpt_brand_can_manage()) {
        return;
    }
    $fields = hpt_brand_fields();
    ?>
    <div class="hpt-brand-toolbar">
        <div class="hpt-brand-toolbar-head">
            <div class="hpt-brand-identity">
                <span class="hpt-brand-eyebrow">PRODUCT / TAXONOMY</span>
                <h2>Quản lý thương hiệu</h2>
                <p>Nhập, xuất, tìm và chỉnh sửa thương hiệu trong một màn hình.</p>
            </div>
            <div class="hpt-brand-actions">
                <label class="hpt-brand-page-size">Hiển thị <select class="hpt-brand-page-size-select"><option value="20" <?php selected(hpt_brand_per_page(), 20); ?>>20</option><option value="50" <?php selected(hpt_brand_per_page(), 50); ?>>50</option><option value="200" <?php selected(hpt_brand_per_page(), 200); ?>>200</option><option value="500" <?php selected(hpt_brand_per_page(), 500); ?>>500</option></select> thương hiệu</label>
                <button type="button" class="button button-primary hpt-brand-open-import"><span class="dashicons dashicons-upload"></span> Nhập XLSX</button>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hpt-brand-export-form">
                    <input type="hidden" name="action" value="hpt_brand_export_xlsx">
                    <?php wp_nonce_field('hpt_brand_export_xlsx', 'hpt_brand_nonce'); ?>
                    <details class="hpt-brand-export-fields"><summary><span class="dashicons dashicons-admin-generic"></span> Chọn cột xuất</summary><div class="hpt-brand-export-options">
                        <?php foreach ($fields as $key => $label) : ?>
                            <label><input type="checkbox" name="fields[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, array('id', 'name', 'slug', 'description', 'parent'), true)); ?>> <?php echo esc_html($label); ?></label>
                        <?php endforeach; ?>
                    </div></details>
                    <button type="submit" class="button hpt-brand-export-button"><span class="dashicons dashicons-download"></span> Xuất XLSX</button>
                </form>
            </div>
        </div>
        <div class="hpt-brand-import-modal" hidden>
            <div class="hpt-brand-modal-card">
                <button type="button" class="hpt-brand-modal-close" aria-label="Đóng">×</button>
                <h2>Nhập thương hiệu từ XLSX</h2>
                <p class="description">Cột hỗ trợ: ID, Tên, Slug, Mô tả, Parent ID. Hệ thống xử lý mỗi batch 100 dòng.</p>
                <label class="hpt-brand-upload-label">Chọn file XLSX<input type="file" class="hpt-brand-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></label>
                <label class="hpt-brand-update-check"><input type="checkbox" class="hpt-brand-allow-update"> <strong>Cho phép cập nhật thương hiệu đã có</strong><span>Mặc định tắt: ID/slug đã tồn tại sẽ được bỏ qua, không ghi đè.</span></label>
                <div class="hpt-brand-mapping" hidden></div>
                <div class="hpt-brand-import-actions"><button type="button" class="button button-primary hpt-brand-start" disabled>Tiếp tục</button><span class="hpt-brand-progress-text"></span></div>
                <div class="hpt-brand-progress" hidden><div></div></div>
            </div>
        </div>
    </div>
    <?php
}
add_action('all_admin_notices', 'hpt_brand_render_toolbar');

function hpt_brand_xlsx_autoload() {
    $autoload = WP_CONTENT_DIR . '/plugins/tablepress/libraries/vendor/autoload.php';
    if (!class_exists('TablePress\\PhpOffice\\PhpSpreadsheet\\IOFactory') && file_exists($autoload)) {
        require_once $autoload;
    }
    $filter_file = WP_CONTENT_DIR . '/plugins/tablepress/libraries/vendor/PhpSpreadsheet/Reader/IReadFilter.php';
    if (!interface_exists('TablePress\\PhpOffice\\PhpSpreadsheet\\Reader\\IReadFilter') && file_exists($filter_file)) {
        require_once $filter_file;
    }
    return class_exists('TablePress\\PhpOffice\\PhpSpreadsheet\\IOFactory');
}

function hpt_brand_job_key($token) {
    return 'hpt_brand_import_' . get_current_user_id() . '_' . preg_replace('/[^a-zA-Z0-9]/', '', (string) $token);
}

function hpt_brand_private_dir() {
    $upload = wp_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'hpt-brand-ie';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
        @file_put_contents($dir . '/index.html', '');
        @file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    return $dir;
}

function hpt_brand_require_ajax() {
    if (!hpt_brand_can_manage()) {
        wp_send_json_error(array('message' => 'Bạn không có quyền quản lý thương hiệu.'), 403);
    }
    check_ajax_referer('hpt_brand_admin', 'nonce');
}

function hpt_brand_reader_for_rows($path, $start, $end) {
    if (!hpt_brand_xlsx_autoload()) {
        return new WP_Error('xlsx_missing', 'Máy chủ chưa có thư viện đọc XLSX.');
    }
    try {
        $reader = \TablePress\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $filter = new class($start, $end) implements \TablePress\PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            private $start; private $end;
            public function __construct($start, $end) { $this->start = (int) $start; $this->end = (int) $end; }
            public function readCell(string $column, int $row, string $worksheetName = ''): bool { return 1 === $row || ($row >= $this->start && $row <= $this->end); }
        };
        $reader->setReadFilter($filter);
        return $reader->load($path);
    } catch (\Throwable $e) {
        return new WP_Error('xlsx_read_failed', 'Không đọc được XLSX: ' . $e->getMessage());
    }
}

function hpt_brand_headers_from_sheet($path) {
    $book = hpt_brand_reader_for_rows($path, 1, 1);
    if (is_wp_error($book)) return $book;
    try {
        $sheet = $book->getActiveSheet();
        $headers = array_map('trim', $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', null, true, false)[0]);
    } catch (\Throwable $e) {
        $book->disconnectWorksheets(); unset($book);
        return new WP_Error('xlsx_header_failed', 'Không đọc được dòng tiêu đề XLSX.');
    }
    $book->disconnectWorksheets();
    unset($book);
    return array_values($headers);
}

function hpt_brand_xlsx_total_rows($path) {
    if (!hpt_brand_xlsx_autoload()) {
        return 0;
    }
    try {
        $reader = \TablePress\PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        // PhpSpreadsheet hiện tại yêu cầu truyền đường dẫn file vào API này.
        $info = $reader->listWorksheetInfo($path);
        return !empty($info[0]['totalRows']) ? max(0, (int) $info[0]['totalRows'] - 1) : 0;
    } catch (\Throwable $e) {
        return 0;
    }
}

add_action('wp_ajax_hpt_brand_import_start', function () {
    hpt_brand_require_ajax();
    if (empty($_FILES['file']['tmp_name']) || strtolower(pathinfo((string) $_FILES['file']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
        wp_send_json_error(array('message' => 'Vui lòng chọn đúng file .xlsx.'), 400);
    }
    $target = trailingslashit(hpt_brand_private_dir()) . wp_unique_filename(hpt_brand_private_dir(), sanitize_file_name((string) $_FILES['file']['name']));
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
        wp_send_json_error(array('message' => 'Không thể lưu file import.'), 500);
    }
    $headers = hpt_brand_headers_from_sheet($target);
    if (is_wp_error($headers) || empty($headers)) {
        @unlink($target);
        wp_send_json_error(array('message' => is_wp_error($headers) ? $headers->get_error_message() : 'File không có dòng tiêu đề.'), 400);
    }
    $total = hpt_brand_xlsx_total_rows($target);
    $token = wp_generate_password(24, false, false);
    $map = array();
    $known = hpt_brand_fields();
    $aliases = array('id' => 'id', 'ten' => 'name', 'name' => 'name', 'slug' => 'slug', 'mo-ta' => 'description', 'mo_ta' => 'description', 'description' => 'description', 'parent-id' => 'parent', 'parent_id' => 'parent', 'parent' => 'parent', 'so-san-pham' => 'count', 'count' => 'count');
    foreach ($headers as $index => $header) {
        $normalized = sanitize_title($header);
        if (isset($aliases[$normalized])) { $map[$index] = $aliases[$normalized]; continue; }
        foreach ($known as $field => $label) {
            if ($normalized === sanitize_title($label) || $normalized === $field) { $map[$index] = $field; break; }
        }
    }
    set_transient(hpt_brand_job_key($token), array('path' => $target, 'headers' => $headers, 'mapping' => $map, 'offset' => 2, 'total' => max(0, $total), 'processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => array(), 'allow_update' => false), DAY_IN_SECONDS);
    wp_send_json_success(array('token' => $token, 'headers' => $headers, 'mapping' => $map, 'fields' => hpt_brand_fields(), 'total' => max(0, $total)));
});

function hpt_brand_import_value($row, $mapping, $field) {
    $index = array_search($field, $mapping, true);
    return false === $index ? '' : trim((string) ($row[$index] ?? ''));
}

function hpt_brand_parent_id($value) {
    $value = trim((string) $value);
    if ($value === '') return 0;
    if (ctype_digit($value)) return absint($value);
    $term = get_term_by('slug', sanitize_title($value), hpt_brand_taxonomy());
    if (!$term) $term = get_term_by('name', $value, hpt_brand_taxonomy());
    return $term && !is_wp_error($term) ? (int) $term->term_id : 0;
}

add_action('wp_ajax_hpt_brand_import_map', function () {
    hpt_brand_require_ajax();
    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    $job = get_transient(hpt_brand_job_key($token));
    if (!is_array($job)) wp_send_json_error(array('message' => 'Job import đã hết hạn.'), 404);
    $mapping = json_decode(wp_unslash($_POST['mapping'] ?? '{}'), true);
    if (!is_array($mapping)) $mapping = array();
    $job['mapping'] = array_map('sanitize_key', $mapping);
    $job['allow_update'] = !empty($_POST['allow_update']);
    set_transient(hpt_brand_job_key($token), $job, DAY_IN_SECONDS);
    wp_send_json_success(array('message' => 'Đã lưu mapping.'));
});

add_action('wp_ajax_hpt_brand_import_batch', function () {
    hpt_brand_require_ajax();
    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    $job = get_transient(hpt_brand_job_key($token));
    if (!is_array($job) || empty($job['path']) || !file_exists($job['path'])) wp_send_json_error(array('message' => 'Job import không tồn tại.'), 404);
    if ((int) $job['total'] <= 0) {
        @unlink($job['path']);
        delete_transient(hpt_brand_job_key($token));
        wp_send_json_success(array('done' => true, 'percent' => 100, 'processed' => 0, 'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => array()));
    }
    $start = (int) $job['offset']; $end = min($start + 99, max($start, (int) $job['total'] + 1));
    $book = hpt_brand_reader_for_rows($job['path'], $start, $end);
    if (is_wp_error($book)) wp_send_json_error(array('message' => $book->get_error_message()), 500);
    $rows = $book->getActiveSheet()->rangeToArray('A' . $start . ':' . $book->getActiveSheet()->getHighestColumn() . $end, null, true, false);
    $taxonomy = hpt_brand_taxonomy();
    foreach ($rows as $row) {
        $job['processed']++;
        $name = hpt_brand_import_value($row, $job['mapping'], 'name');
        $slug = sanitize_title(hpt_brand_import_value($row, $job['mapping'], 'slug'));
        $id = absint(hpt_brand_import_value($row, $job['mapping'], 'id'));
        $description = hpt_brand_import_value($row, $job['mapping'], 'description');
        $parent = hpt_brand_parent_id(hpt_brand_import_value($row, $job['mapping'], 'parent'));
        if ($name === '' && $id <= 0 && $slug === '') { $job['skipped']++; continue; }
        $term = $id > 0 ? get_term($id, $taxonomy) : ($slug !== '' ? get_term_by('slug', $slug, $taxonomy) : null);
        if ($term && !is_wp_error($term)) {
            if (empty($job['allow_update'])) { $job['skipped']++; continue; }
            $update_data = array();
            if (false !== array_search('name', $job['mapping'], true) && '' !== $name) $update_data['name'] = $name;
            if (false !== array_search('slug', $job['mapping'], true) && '' !== $slug) $update_data['slug'] = $slug;
            if (false !== array_search('description', $job['mapping'], true)) $update_data['description'] = $description;
            if (false !== array_search('parent', $job['mapping'], true)) $update_data['parent'] = $parent;
            $result = wp_update_term($term->term_id, $taxonomy, $update_data);
            if (is_wp_error($result)) { $job['failed']++; $job['errors'][] = $result->get_error_message(); } else $job['updated']++;
            continue;
        }
        // ID/slug không tồn tại: slug mới vẫn là bản ghi mới hợp lệ.
        // Nếu file chỉ có slug mà thiếu tên, dùng slug làm tên để WordPress có thể tạo term.
        if ($id > 0) { $job['failed']++; $job['errors'][] = 'Không tìm thấy ID: ' . $id; continue; }
        $insert_name = '' !== $name ? $name : $slug;
        if ('' === $insert_name) { $job['skipped']++; continue; }
        $insert_args = array('description' => $description, 'parent' => $parent);
        if ('' !== $slug) { $insert_args['slug'] = $slug; }
        $result = wp_insert_term($insert_name, $taxonomy, $insert_args);
        if (is_wp_error($result)) { $job['failed']++; $job['errors'][] = $result->get_error_message(); } else $job['created']++;
    }
    $book->disconnectWorksheets();
    unset($book);
    $job['offset'] = $end + 1;
    $done = $job['processed'] >= $job['total'];
    if ($done) { @unlink($job['path']); delete_transient(hpt_brand_job_key($token)); } else set_transient(hpt_brand_job_key($token), $job, DAY_IN_SECONDS);
    $percent = $job['total'] > 0 ? min(100, (int) floor($job['processed'] * 100 / $job['total'])) : 100;
    wp_send_json_success(array('done' => $done, 'percent' => $percent, 'processed' => $job['processed'], 'total' => $job['total'], 'created' => $job['created'], 'updated' => $job['updated'], 'skipped' => $job['skipped'], 'failed' => $job['failed'], 'errors' => array_slice($job['errors'], -10)));
});

function hpt_brand_xlsx_escape($value) {
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function hpt_brand_xlsx_column($number) {
    $letters = '';
    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $letters = chr(65 + $remainder) . $letters;
        $number = (int) floor(($number - 1) / 26);
    }
    return $letters;
}

function hpt_brand_xlsx_cell($reference, $value) {
    if (is_int($value) || is_float($value) || (is_string($value) && '' !== $value && is_numeric($value))) {
        return '<c r="' . $reference . '"><v>' . hpt_brand_xlsx_escape($value) . '</v></c>';
    }
    return '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">' . hpt_brand_xlsx_escape($value) . '</t></is></c>';
}

function hpt_brand_write_xlsx($headers, $rows, $path) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('zip_missing', 'Máy chủ chưa bật ZipArchive để xuất XLSX.');
    }
    $sheet_rows = '<row r="1">';
    foreach (array_values($headers) as $index => $value) {
        $sheet_rows .= hpt_brand_xlsx_cell(hpt_brand_xlsx_column($index + 1) . '1', $value);
    }
    $sheet_rows .= '</row>';
    $row_number = 2;
    foreach ($rows as $row) {
        $sheet_rows .= '<row r="' . $row_number . '">';
        foreach (array_values($row) as $index => $value) {
            $sheet_rows .= hpt_brand_xlsx_cell(hpt_brand_xlsx_column($index + 1) . $row_number, $value);
        }
        $sheet_rows .= '</row>';
        $row_number++;
    }
    $zip = new ZipArchive();
    if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        return new WP_Error('xlsx_open_failed', 'Không thể tạo file XLSX.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Thuong hieu" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheet_rows . '</sheetData></worksheet>');
    $zip->close();
    return true;
}

add_action('admin_post_hpt_brand_export_xlsx', function () {
    if (!hpt_brand_can_manage()) wp_die('Bạn không có quyền.');
    check_admin_referer('hpt_brand_export_xlsx', 'hpt_brand_nonce');
    if (!hpt_brand_xlsx_autoload()) wp_die('Máy chủ chưa có thư viện XLSX.');
    $fields = array_values(array_intersect(array_keys(hpt_brand_fields()), array_map('sanitize_key', (array) ($_POST['fields'] ?? array()))));
    if (empty($fields)) $fields = array('id', 'name', 'slug', 'description', 'parent');
    $terms = get_terms(array('taxonomy' => hpt_brand_taxonomy(), 'hide_empty' => false, 'number' => 0, 'orderby' => 'term_id', 'order' => 'ASC'));
    if (is_wp_error($terms)) wp_die($terms->get_error_message());
    $headers = array();
    foreach ($fields as $field) $headers[] = hpt_brand_fields()[$field];
    $rows = array();
    foreach ($terms as $term) {
        $values = array('id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'parent' => $term->parent, 'count' => $term->count);
        $rows[] = array_map(static function ($field) use ($values) { return $values[$field]; }, $fields);
    }
    $path = trailingslashit(hpt_brand_private_dir()) . 'export-' . get_current_user_id() . '-' . wp_generate_password(8, false, false) . '.xlsx';
    $result = hpt_brand_write_xlsx($headers, $rows, $path);
    if (is_wp_error($result)) wp_die($result->get_error_message());
    nocache_headers(); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="thuong-hieu-' . gmdate('Ymd-His') . '.xlsx"'); header('Content-Length: ' . filesize($path)); readfile($path); @unlink($path); exit;
});

add_action('wp_ajax_hpt_brand_get_term', function () {
    hpt_brand_require_ajax(); $term = get_term(absint($_POST['term_id'] ?? 0), hpt_brand_taxonomy());
    if (!$term || is_wp_error($term)) wp_send_json_error(array('message' => 'Không tìm thấy thương hiệu.'), 404);
    wp_send_json_success(array('id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'parent' => $term->parent));
});

add_action('wp_ajax_hpt_brand_update_term', function () {
    hpt_brand_require_ajax(); $term_id = absint($_POST['term_id'] ?? 0);
    $result = wp_update_term($term_id, hpt_brand_taxonomy(), array('name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')), 'slug' => sanitize_title(wp_unslash($_POST['slug'] ?? '')), 'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')), 'parent' => absint($_POST['parent'] ?? 0)));
    if (is_wp_error($result)) wp_send_json_error(array('message' => $result->get_error_message()), 400);
    wp_send_json_success(array('message' => 'Đã lưu thương hiệu.'));
});

add_action('admin_footer-edit-tags.php', function () {
    if (!hpt_brand_admin_screen()) return;
    $nonce = wp_create_nonce('hpt_brand_admin');
    ?>
    <style>
    body.taxonomy-thuong_hieu #col-left{width:32%;}body.taxonomy-thuong_hieu #col-right{width:66%;}body.taxonomy-thuong_hieu #col-left .form-wrap,body.taxonomy-thuong_hieu #col-right .postbox{border-radius:8px} .hpt-brand-toolbar{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:18px 22px;margin:15px 0 20px;box-shadow:0 1px 2px #0000000d}.hpt-brand-toolbar-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.hpt-brand-toolbar h2{margin:0 14px 4px 0}.hpt-brand-toolbar p{margin:0 20px 0 0;color:#646970}.hpt-brand-toolbar .dashicons{vertical-align:middle}.hpt-brand-export-form{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.hpt-brand-export-form label{font-size:12px}.hpt-brand-import-modal{position:fixed;inset:0;background:#0008;z-index:100000;display:grid;place-items:center}.hpt-brand-import-modal[hidden]{display:none}.hpt-brand-modal-card{background:#fff;width:min(720px,calc(100% - 32px));padding:26px;border-radius:8px;box-shadow:0 10px 40px #0005;position:relative}.hpt-brand-modal-close{position:absolute;right:16px;top:10px;border:0;background:none;font-size:28px;cursor:pointer}.hpt-brand-upload-label{display:block;margin:20px 0;padding:18px;border:2px dashed #8c8f94;text-align:center;font-weight:600}.hpt-brand-upload-label input{display:block;margin:12px auto 0}.hpt-brand-update-check{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0}.hpt-brand-update-check input{width:20px;height:20px;accent-color:#2271b1}.hpt-brand-update-check span{width:100%;padding-left:28px;color:#646970;font-size:12px}.hpt-brand-mapping{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:18px 0}.hpt-brand-mapping label{display:flex;align-items:center;justify-content:space-between;gap:8px}.hpt-brand-progress{height:10px;background:#dcdcde;border-radius:6px;overflow:hidden;margin-top:15px}.hpt-brand-progress div{height:100%;width:0;background:#2271b1;transition:width .2s}.hpt-brand-import-actions{display:flex;gap:15px;align-items:center}.hpt-brand-quick-edit{font-weight:600}.hpt-brand-quick-modal{position:fixed;inset:0;background:#0008;z-index:100001;display:grid;place-items:center}.hpt-brand-quick-card{background:#fff;padding:24px;width:min(560px,calc(100% - 30px));border-radius:8px}.hpt-brand-quick-card input,.hpt-brand-quick-card textarea{width:100%;margin:5px 0 14px}.hpt-brand-quick-actions{text-align:right}.hpt-brand-screen-note{margin-top:6px;color:#646970}@media(max-width:782px){body.taxonomy-thuong_hieu #col-left,body.taxonomy-thuong_hieu #col-right{width:100%;float:none}}
    </style>
    <style id="hpt-brand-modern-admin-overrides">
        body.taxonomy-thuong_hieu #wpbody-content { max-width: 1600px; }
        body.taxonomy-thuong_hieu .wrap > h1 { font-size: 28px; font-weight: 700; letter-spacing: -.02em; color: #172b4d; margin: 26px 0 18px; }
        body.taxonomy-thuong_hieu #col-left { width: 31%; padding-right: 18px; box-sizing: border-box; }
        body.taxonomy-thuong_hieu #col-right { width: 69%; box-sizing: border-box; }
        body.taxonomy-thuong_hieu #col-left .form-wrap { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; box-shadow: 0 8px 24px rgba(25, 45, 75, .06); }
        body.taxonomy-thuong_hieu #col-left h2 { font-size: 19px; color: #172b4d; margin: 0 0 20px; }
        body.taxonomy-thuong_hieu #col-left label { color: #425466; font-weight: 600; }
        body.taxonomy-thuong_hieu #col-left input[type=text], body.taxonomy-thuong_hieu #col-left textarea, body.taxonomy-thuong_hieu #col-left select { border: 1px solid #cbd5e1; border-radius: 8px; min-height: 40px; box-shadow: none; }
        body.taxonomy-thuong_hieu #col-left input[type=text]:focus, body.taxonomy-thuong_hieu #col-left textarea:focus, body.taxonomy-thuong_hieu #col-left select:focus { border-color: #2271b1; box-shadow: 0 0 0 3px rgba(34,113,177,.12); }
        body.taxonomy-thuong_hieu #col-right .tablenav { margin: 0 0 12px; }
        body.taxonomy-thuong_hieu #col-right .wp-list-table { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 8px 24px rgba(25,45,75,.05); }
        body.taxonomy-thuong_hieu #col-right .wp-list-table thead th { background: #f7f9fc; color: #52606d; font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: .04em; }
        body.taxonomy-thuong_hieu #col-right .wp-list-table tbody tr:nth-child(even) { background: #fbfcfe; }
        body.taxonomy-thuong_hieu #col-right .wp-list-table tbody tr:hover { background: #eef6ff; }
        body.taxonomy-thuong_hieu #col-right .wp-list-table td { padding-top: 13px; padding-bottom: 13px; vertical-align: top; }
        body.taxonomy-thuong_hieu #col-right .wp-list-table .column-name { width: 28%; }
        body.taxonomy-thuong_hieu #col-right .wp-list-table .column-hpt_id { width: 12%; }
        body.taxonomy-thuong_hieu #col-right .wp-list-table .column-hpt_slug { width: 18%; }
        body.taxonomy-thuong_hieu #col-right .wp-list-table code { background: #edf2f7; color: #425466; border-radius: 5px; padding: 3px 7px; font-size: 11px; }
        body.taxonomy-thuong_hieu .hpt-brand-toolbar { border: 1px solid #dbe5f0; border-left: 5px solid #2271b1; border-radius: 14px; padding: 22px 24px; margin: 18px 0 24px; background: linear-gradient(135deg,#fff 0%,#f7fbff 100%); box-shadow: 0 10px 30px rgba(25,45,75,.08); }
        body.taxonomy-thuong_hieu .hpt-brand-toolbar-head { display:flex; justify-content:space-between; align-items:center; gap:24px; }
        body.taxonomy-thuong_hieu .hpt-brand-identity { min-width: 260px; }
        body.taxonomy-thuong_hieu .hpt-brand-eyebrow { display:block; color:#2271b1; font-size:10px; font-weight:800; letter-spacing:.12em; margin-bottom:6px; }
        body.taxonomy-thuong_hieu .hpt-brand-toolbar h2 { color:#172b4d; font-size:20px; margin:0 0 5px; }
        body.taxonomy-thuong_hieu .hpt-brand-toolbar p { color:#627d98; margin:0; }
        body.taxonomy-thuong_hieu .hpt-brand-actions { display:flex; align-items:center; justify-content:flex-end; gap:9px; flex-wrap:wrap; }
        body.taxonomy-thuong_hieu .hpt-brand-page-size { display:flex; align-items:center; gap:5px; color:#52606d; font-size:12px; white-space:nowrap; }
        body.taxonomy-thuong_hieu .hpt-brand-page-size select { border:1px solid #cbd5e1; border-radius:7px; min-height:34px; }
        body.taxonomy-thuong_hieu .hpt-brand-actions .button { min-height:34px; border-radius:7px; padding:0 13px; display:inline-flex; align-items:center; gap:5px; }
        body.taxonomy-thuong_hieu .hpt-brand-export-form { display:flex; align-items:center; gap:8px; }
        body.taxonomy-thuong_hieu .hpt-brand-export-fields { position:relative; }
        body.taxonomy-thuong_hieu .hpt-brand-export-fields summary { list-style:none; cursor:pointer; border:1px solid #cbd5e1; border-radius:7px; min-height:32px; padding:0 10px; display:flex; align-items:center; gap:5px; color:#425466; font-size:12px; background:#fff; }
        body.taxonomy-thuong_hieu .hpt-brand-export-fields summary::-webkit-details-marker { display:none; }
        body.taxonomy-thuong_hieu .hpt-brand-export-options { position:absolute; right:0; top:42px; z-index:20; width:190px; padding:12px; background:#fff; border:1px solid #dbe5f0; border-radius:9px; box-shadow:0 12px 30px rgba(25,45,75,.15); display:grid; gap:8px; }
        body.taxonomy-thuong_hieu .hpt-brand-export-options label { font-size:12px; color:#425466; }
        body.taxonomy-thuong_hieu .hpt-brand-export-options input { accent-color:#2271b1; }
        body.taxonomy-thuong_hieu .hpt-brand-modal-card, body.taxonomy-thuong_hieu .hpt-brand-quick-card { border-radius:14px; box-shadow:0 18px 60px rgba(15,23,42,.25); }
        body.taxonomy-thuong_hieu .hpt-brand-modal-card h2, body.taxonomy-thuong_hieu .hpt-brand-quick-card h2 { color:#172b4d; font-size:21px; }
        body.taxonomy-thuong_hieu .hpt-brand-upload-label { border-color:#90cdf4; background:#f7fbff; border-radius:10px; color:#22577a; }
        body.taxonomy-thuong_hieu .hpt-brand-mapping select { border:1px solid #cbd5e1; border-radius:7px; min-height:34px; }
        body.taxonomy-thuong_hieu .hpt-brand-quick-card input, body.taxonomy-thuong_hieu .hpt-brand-quick-card textarea { border:1px solid #cbd5e1; border-radius:7px; padding:8px 10px; }
        /* Trường thương hiệu cha: vùng chọn lớn, dễ chạm và có thể tìm kiếm nhanh. */
        body.taxonomy-thuong_hieu #col-left select[name="parent"], body.taxonomy-thuong_hieu #col-left #parent { width:100%; min-width:100%; min-height:48px; }
        body.taxonomy-thuong_hieu #col-left .ss-main { width:100%; min-height:48px; border:1px solid #cbd5e1; border-radius:10px; background:#fff; font-size:14px; }
        body.taxonomy-thuong_hieu #col-left .ss-main:focus, body.taxonomy-thuong_hieu #col-left .ss-main.ss-open { border-color:#2271b1; box-shadow:0 0 0 3px rgba(34,113,177,.12); }
        body.taxonomy-thuong_hieu #col-left .ss-content { z-index:100100; width:100%; min-width:320px; border:1px solid #dbe5f0; border-radius:10px; box-shadow:0 14px 34px rgba(15,23,42,.18); }
        body.taxonomy-thuong_hieu #col-left .ss-search input { min-height:42px; border:0; border-bottom:1px solid #e2e8f0; border-radius:0; padding:0 12px; font-size:14px; }
        body.taxonomy-thuong_hieu #col-left .ss-list { max-height:360px; }
        body.taxonomy-thuong_hieu #col-left .ss-list .ss-option { min-height:40px; padding:10px 12px; font-size:14px; }
        body.taxonomy-thuong_hieu #col-left .ss-list .ss-option:hover, body.taxonomy-thuong_hieu #col-left .ss-list .ss-option.ss-highlighted { background:#eef6ff; color:#145da0; }
        body.taxonomy-thuong_hieu #col-left .hpt-brand-parent-help { color:#627d98; margin-top:7px; }
        @media (max-width: 1100px) { body.taxonomy-thuong_hieu .hpt-brand-toolbar-head { align-items:flex-start; flex-direction:column; } body.taxonomy-thuong_hieu .hpt-brand-actions { justify-content:flex-start; } }
        @media (max-width: 782px) { body.taxonomy-thuong_hieu #col-left, body.taxonomy-thuong_hieu #col-right { width:100%; float:none; padding:0; } body.taxonomy-thuong_hieu .hpt-brand-toolbar { padding:18px; } body.taxonomy-thuong_hieu .hpt-brand-export-form { flex-wrap:wrap; } }
    </style>
    <script>
    jQuery(function($){const cfg={ajaxurl:ajaxurl,nonce:'<?php echo esc_js($nonce); ?>'};let token='';
      const parentSelect=document.querySelector('#parent, select[name="parent"]');
      if(parentSelect && window.SlimSelect){
        new SlimSelect({select:parentSelect,settings:{showSearch:true,searchPlaceholder:'Tìm thương hiệu cha…',searchText:'Không tìm thấy thương hiệu',placeholderText:'Chọn thương hiệu cha',allowDeselect:true,closeOnSelect:true,contentPosition:'absolute',openPosition:'auto'}});
        const parentField=parentSelect.closest('.form-field') || parentSelect.parentElement;
        if(parentField && !parentField.querySelector('.hpt-brand-parent-help')){const note=document.createElement('p');note.className='description hpt-brand-parent-help';note.textContent='Gõ tên để tìm nhanh thương hiệu cha.';parentField.appendChild(note);}
      }
      const modal=$('.hpt-brand-import-modal'), mapBox=$('.hpt-brand-mapping'), start=$('.hpt-brand-start'), file=$('.hpt-brand-file');
      $('.hpt-brand-page-size-select').on('change',function(){const u=new URL(window.location.href);u.searchParams.set('hpt_brand_per_page',this.value);u.searchParams.delete('paged');window.location.href=u.toString()});
      $('.hpt-brand-open-import').on('click',()=>modal.prop('hidden',false)); $('.hpt-brand-modal-close').on('click',()=>modal.prop('hidden',true));
      file.on('change',function(){if(!this.files[0])return;const fd=new FormData();fd.append('action','hpt_brand_import_start');fd.append('nonce',cfg.nonce);fd.append('file',this.files[0]);$('.hpt-brand-progress-text').text('Đang đọc tiêu đề…');$.ajax({url:cfg.ajaxurl,method:'POST',data:fd,processData:false,contentType:false}).done(r=>{if(!r.success){alert(r.data.message);return}token=r.data.token;mapBox.empty().prop('hidden',false);const fields=r.data.fields;Object.entries(r.data.headers).forEach(([i,h])=>{let opts='<option value="">Bỏ qua</option>';Object.entries(fields).forEach(([k,v])=>opts+=`<option value="${k}" ${r.data.mapping[i]===k?'selected':''}>${v}</option>`);mapBox.append(`<label>${$('<div>').text(h).html()}<select data-index="${i}">${opts}</select></label>`)});start.prop('disabled',false);$('.hpt-brand-progress-text').text(`${r.data.total} dòng`)}).fail(()=>alert('Không đọc được file XLSX.'))});
      mapBox.on('change','select',function(){const value=this.value;if(!value)return;mapBox.find('select').not(this).filter(function(){return this.value===value}).val('')});
      start.on('click',function(){const mapping={};mapBox.find('select').each(function(){if(this.value)mapping[$(this).data('index')]=this.value});const fd=new FormData();fd.append('action','hpt_brand_import_map');fd.append('nonce',cfg.nonce);fd.append('token',token);fd.append('mapping',JSON.stringify(mapping));fd.append('allow_update',$('.hpt-brand-allow-update').prop('checked')?'1':'0');$.ajax({url:cfg.ajaxurl,method:'POST',data:fd,processData:false,contentType:false}).done(()=>{start.prop('disabled',true);$('.hpt-brand-progress').prop('hidden',false);runBatch()})});
      function runBatch(){ $.post(cfg.ajaxurl,{action:'hpt_brand_import_batch',nonce:cfg.nonce,token:token},function(r){if(!r.success){alert(r.data.message);start.prop('disabled',false);return}$('.hpt-brand-progress div').css('width',r.data.percent+'%');$('.hpt-brand-progress-text').text(`${r.data.percent}% · tạo ${r.data.created}, cập nhật ${r.data.updated}, bỏ qua ${r.data.skipped}, lỗi ${r.data.failed}`);if(!r.data.done){setTimeout(runBatch,180)}else{const d=r.data;let message=`Đã hoàn tất nhập thương hiệu. Tạo: ${d.created}, cập nhật: ${d.updated}, bỏ qua: ${d.skipped}, lỗi: ${d.failed}.`;if(Number(d.failed)>0 && d.errors && d.errors.length){message+='\n\n'+d.errors.join('\n');}alert(message);location.reload()}})}
      $(document).on('click','.hpt-brand-quick-edit',function(e){e.preventDefault();const id=$(this).data('term-id');$.post(cfg.ajaxurl,{action:'hpt_brand_get_term',nonce:cfg.nonce,term_id:id},function(r){if(!r.success){alert(r.data.message);return}const d=r.data;$('body').append(`<div class="hpt-brand-quick-modal"><div class="hpt-brand-quick-card"><h2>Sửa nhanh thương hiệu</h2><input type="hidden" name="term_id" value="${d.id}"><label>Tên<input name="name" value="${$('<div>').text(d.name).html()}"></label><label>Slug<input name="slug" value="${$('<div>').text(d.slug).html()}"></label><label>Mô tả<textarea name="description">${$('<div>').text(d.description).html()}</textarea></label><label>Parent ID<input name="parent" value="${d.parent||0}"></label><div class="hpt-brand-quick-actions"><button class="button cancel">Hủy</button> <button class="button button-primary save">Lưu</button></div></div></div>`)});});
      $(document).on('click','.hpt-brand-quick-modal .cancel',function(){$(this).closest('.hpt-brand-quick-modal').remove()});$(document).on('click','.hpt-brand-quick-modal .save',function(){const box=$(this).closest('.hpt-brand-quick-modal');const data={action:'hpt_brand_update_term',nonce:cfg.nonce};box.find('input,textarea').each(function(){data[this.name]=this.value});$.post(cfg.ajaxurl,data,function(r){if(!r.success){alert(r.data.message);return}box.remove();location.reload()})});
    });
    </script>
    <?php
});
