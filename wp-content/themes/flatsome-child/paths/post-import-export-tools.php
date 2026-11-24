<?php
/**
 * HPT — Engine Xuất/Nhập bài viết nâng cao (Chạy theo batch AJAX, chống timeout).
 *
 * Hỗ trợ định dạng Excel (.xlsx) và CSV (.csv chuẩn UTF-8).
 * Quản lý job, ghi nhận tiến trình, đếm số bài tạo mới, cập nhật, bỏ qua và log lỗi chi tiết.
 *
 * @package flatsome-child
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ============================================================
 * 1) CẤU HÌNH & BẢNG CƠ SỞ DỮ LIỆU
 * ============================================================ */

function hpt_post_ie_db_version() {
    return '1.0.0';
}

function hpt_post_ie_jobs_table() {
    global $wpdb;
    return $wpdb->prefix . 'hpt_post_jobs';
}

function hpt_post_ie_errors_table() {
    global $wpdb;
    return $wpdb->prefix . 'hpt_post_job_errors';
}

function hpt_post_ie_import_batch_size() {
    return max(5, min(100, (int) apply_filters('hpt_post_ie_import_batch_size', 20)));
}

function hpt_post_ie_export_batch_size() {
    return max(20, min(500, (int) apply_filters('hpt_post_ie_export_batch_size', 100)));
}

function hpt_post_ie_user_can_manage() {
    return current_user_can('edit_posts') || current_user_can('manage_options');
}

/**
 * Tự động tạo bảng Job và Error log khi admin truy cập
 */
function hpt_post_ie_maybe_install() {
    if (get_option('hpt_post_ie_db_version') === hpt_post_ie_db_version()) {
        return;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $jobs   = hpt_post_ie_jobs_table();
    $errors = hpt_post_ie_errors_table();

    $sql_jobs = "CREATE TABLE {$jobs} (
        job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        type varchar(20) NOT NULL DEFAULT 'import',
        status varchar(20) NOT NULL DEFAULT 'pending',
        mode varchar(20) NOT NULL DEFAULT 'upsert',
        format varchar(10) NOT NULL DEFAULT 'xlsx',
        file_path text NULL,
        download_path text NULL,
        total_rows bigint(20) unsigned NOT NULL DEFAULT 0,
        processed_rows bigint(20) unsigned NOT NULL DEFAULT 0,
        success_count bigint(20) unsigned NOT NULL DEFAULT 0,
        updated_count bigint(20) unsigned NOT NULL DEFAULT 0,
        failed_count bigint(20) unsigned NOT NULL DEFAULT 0,
        skipped_count bigint(20) unsigned NOT NULL DEFAULT 0,
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
        post_identifier varchar(255) NULL,
        error_message text NULL,
        raw_data longtext NULL,
        created_at datetime NULL,
        PRIMARY KEY  (id),
        KEY job_id (job_id)
    ) {$charset_collate};";

    dbDelta($sql_jobs);
    dbDelta($sql_errors);

    update_option('hpt_post_ie_db_version', hpt_post_ie_db_version(), false);
}
add_action('admin_init', 'hpt_post_ie_maybe_install');

/* ============================================================
 * 2) THƯ VIỆN ĐỌC/GHI EXCEL (PHPSPREADSHEET)
 * ============================================================ */

function hpt_post_ie_load_spreadsheet() {
    if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        return true;
    }

    $candidates = array(
        ABSPATH . 'vendor/autoload.php',
        WP_CONTENT_DIR . '/plugins/tablepress/libraries/vendor/autoload.php',
    );

    foreach ($candidates as $file) {
        if (file_exists($file)) {
            require_once $file;
            if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                return true;
            }
        }
    }

    return class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
}

/* ============================================================
 * 3) QUẢN LÝ THƯ MỤC LƯU TRỮ VÀ TIẾN TRÌNH JOB
 * ============================================================ */

function hpt_post_ie_storage_dir() {
    $upload = wp_upload_dir();
    $dir    = apply_filters('hpt_post_ie_storage_directory', trailingslashit($upload['basedir']) . 'hpt-post-ie');
    $dir    = untrailingslashit(wp_normalize_path($dir));

    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }

    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }
    if (!file_exists($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }

    return $dir;
}

function hpt_post_ie_create_job($type, $mode, $file_path = '', $config = array()) {
    global $wpdb;

    $now = current_time('mysql');
    $data = array(
        'type'            => sanitize_key($type),
        'status'          => 'pending',
        'mode'            => sanitize_key($mode),
        'format'          => sanitize_key($config['format'] ?? 'xlsx'),
        'file_path'       => $file_path,
        'download_path'   => '',
        'total_rows'      => 0,
        'processed_rows'  => 0,
        'success_count'   => 0,
        'updated_count'   => 0,
        'failed_count'    => 0,
        'skipped_count'   => 0,
        'current_offset'  => 0,
        'percent'         => 0,
        'config'          => !empty($config) ? wp_json_encode($config) : null,
        'error_message'   => '',
        'created_by'      => get_current_user_id(),
        'started_at'      => $now,
        'finished_at'     => null,
        'updated_at'      => $now,
    );

    $wpdb->insert(hpt_post_ie_jobs_table(), $data);
    return (int) $wpdb->insert_id;
}

function hpt_post_ie_get_job($job_id) {
    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM ' . hpt_post_ie_jobs_table() . ' WHERE job_id = %d', absint($job_id)),
        ARRAY_A
    );

    if ($row && !empty($row['config'])) {
        $row['config'] = json_decode($row['config'], true);
    }
    return $row;
}

function hpt_post_ie_update_job($job_id, $data) {
    global $wpdb;
    $data['updated_at'] = current_time('mysql');

    if (isset($data['config']) && is_array($data['config'])) {
        $data['config'] = wp_json_encode($data['config']);
    }

    $wpdb->update(hpt_post_ie_jobs_table(), $data, array('job_id' => absint($job_id)));
}

function hpt_post_ie_log_error($job_id, $row_number, $identifier, $message, $raw_data = '') {
    global $wpdb;
    $wpdb->insert(hpt_post_ie_errors_table(), array(
        'job_id'          => absint($job_id),
        'row_num'         => absint($row_number),
        'post_identifier' => mb_substr((string) $identifier, 0, 250),
        'error_message'   => mb_substr((string) $message, 0, 1000),
        'raw_data'        => is_array($raw_data) ? wp_json_encode($raw_data) : mb_substr((string) $raw_data, 0, 4000),
        'created_at'      => current_time('mysql'),
    ));
}

function hpt_post_ie_get_job_errors($job_id, $limit = 200) {
    global $wpdb;
    return $wpdb->get_results(
        $wpdb->prepare(
            'SELECT row_num, post_identifier, error_message FROM ' . hpt_post_ie_errors_table() .
            ' WHERE job_id = %d ORDER BY id ASC LIMIT %d',
            absint($job_id),
            absint($limit)
        ),
        ARRAY_A
    );
}

/* ============================================================
 * 4) ĐỊNH NGHĨA CỘT DỮ LIỆU & ÁNH XẠ TIÊU ĐỀ (HEADER ALIASES)
 * ============================================================ */

function hpt_post_ie_fields() {
    return array(
        'id'               => 'ID bài viết',
        'title'            => 'Tiêu đề bài viết',
        'slug'             => 'Đường dẫn (Slug)',
        'content'          => 'Nội dung bài viết',
        'excerpt'          => 'Tóm tắt / Mô tả ngắn',
        'category'         => 'Chuyên mục (phân cách bằng dấu phẩy)',
        'tags'             => 'Thẻ (Tags)',
        'thumbnail'        => 'Ảnh đại diện (URL hoặc tên ảnh)',
        'status'           => 'Trạng thái (publish, draft, pending, private)',
        'date'             => 'Ngày đăng (YYYY-MM-DD HH:MM:SS)',
        'author'           => 'Tác giả (Username / ID / Email)',
        'meta_title'       => 'SEO Title (Rank Math)',
        'meta_description' => 'SEO Description (Rank Math)',
        'meta_keyword'     => 'SEO Focus Keyword (Rank Math)',
        'permalink'        => 'Đường dẫn công khai (URL bài viết)',
    );
}

function hpt_post_ie_header_aliases() {
    return array(
        'id' => array('id', 'mã bài viết', 'ma_bai_viet', 'post_id', 'id_bai_viet'),
        'title' => array('tiêu đề', 'tieu_de', 'tiêu đề bài viết', 'tieu_de_bai_viet', 'tên bài viết', 'ten_bai_viet', 'title', 'post_title', 'name'),
        'slug' => array('slug', 'đường dẫn', 'duong_dan', 'post_name', 'liên kết', 'lien_ket', 'post_slug'),
        'content' => array('nội dung', 'noi_dung', 'nội dung bài viết', 'noi_dung_bai_viet', 'content', 'post_content', 'chi tiết', 'chi_tiet', 'body'),
        'excerpt' => array('tóm tắt', 'tom_tat', 'mô tả ngắn', 'mo_ta_ngan', 'excerpt', 'post_excerpt', 'summary', 'mô tả', 'mo_ta'),
        'category' => array('chuyên mục', 'chuyen_muc', 'danh mục', 'danh_muc', 'category', 'categories', 'post_category', 'chuyen_muc_bai_viet'),
        'tags' => array('thẻ', 'the', 'thẻ bài viết', 'tag', 'tags', 'post_tag', 'từ khóa', 'tu_khoa'),
        'thumbnail' => array('ảnh đại diện', 'anh_dai_dien', 'ảnh', 'anh', 'hình ảnh', 'hinh_anh', 'thumbnail', 'featured_image', 'image', 'avatar'),
        'status' => array('trạng thái', 'trang_thai', 'status', 'post_status'),
        'date' => array('ngày đăng', 'ngay_dang', 'ngày', 'ngay', 'date', 'post_date', 'publish_date'),
        'author' => array('tác giả', 'tac_gia', 'người đăng', 'nguoi_dang', 'author', 'post_author'),
        'meta_title' => array('seo title', 'seo_title', 'tiêu đề seo', 'tieu_de_seo', 'meta_title', 'rank_math_title'),
        'meta_description' => array('seo description', 'seo_description', 'mô tả seo', 'mo_ta_seo', 'meta_description', 'rank_math_description'),
        'meta_keyword' => array('focus keyword', 'focus_keyword', 'seo keyword', 'seo_keyword', 'từ khóa chính', 'tu_khoa_chinh', 'meta_keyword', 'rank_math_focus_keyword', 'keywords', 'tu_khoa'),
    );
}

function hpt_post_ie_resolve_field($header) {
    $clean = trim(mb_strtolower((string) $header));
    $clean = remove_accents($clean);
    $clean = preg_replace('/[^a-z0-9]+/', '_', $clean);
    $clean = trim($clean, '_');

    if ($clean === '') {
        return '';
    }

    foreach (hpt_post_ie_header_aliases() as $field => $aliases) {
        if ($clean === $field) {
            return $field;
        }
        foreach ($aliases as $alias) {
            $alias_clean = remove_accents(mb_strtolower($alias));
            $alias_clean = preg_replace('/[^a-z0-9]+/', '_', $alias_clean);
            $alias_clean = trim($alias_clean, '_');
            if ($clean === $alias_clean) {
                return $field;
            }
        }
    }

    return '';
}

function hpt_post_ie_map_headers($headers) {
    $map = array();
    foreach ((array) $headers as $col_idx => $header_name) {
        $field = hpt_post_ie_resolve_field($header_name);
        if ($field !== '' && !isset($map[$field])) {
            $map[$field] = $col_idx;
        }
    }
    return $map;
}

/* ============================================================
 * 5) ĐỌC DỮ LIỆU FILE (XLSX / CSV)
 * ============================================================ */

function hpt_post_ie_read_headers($file_path, $format = 'xlsx') {
    if ($format === 'csv') {
        $handle = fopen($file_path, 'r');
        if (!$handle) return array();

        $line = fgets($handle);
        fclose($handle);

        if (!$line) return array();

        // Xử lý BOM UTF-8
        if (substr($line, 0, 3) === "\xEF\xBB\xBF") {
            $line = substr($line, 3);
        }

        // Tự nhận diện phân cách
        $delimiters = array(',', ';', "\t");
        $delimiter = ',';
        $max_count = 0;
        foreach ($delimiters as $d) {
            $c = substr_count($line, $d);
            if ($c > $max_count) {
                $max_count = $c;
                $delimiter = $d;
            }
        }

        $headers = str_getcsv($line, $delimiter);
        return array_map('trim', (array) $headers);
    }

    // Đọc XLSX
    if (hpt_post_ie_load_spreadsheet()) {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file_path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file_path);
            $sheet = $spreadsheet->getActiveSheet();
            $headers = array();
            $highestCol = $sheet->getHighestColumn();
            $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

            for ($c = 1; $c <= $highestColIndex; $c++) {
                $val = trim((string) $sheet->getCellByColumnAndRow($c, 1)->getValue());
                if ($val !== '') {
                    $headers[] = $val;
                } else {
                    $headers[] = 'Cột ' . $c;
                }
            }
            return $headers;
        } catch (\Exception $e) {
            return array();
        }
    }

    return array();
}

function hpt_post_ie_count_rows($file_path, $format = 'xlsx') {
    if ($format === 'csv') {
        $handle = fopen($file_path, 'r');
        if (!$handle) return 0;
        $count = 0;
        while (!feof($handle)) {
            $line = fgets($handle);
            if (trim($line) !== '') {
                $count++;
            }
        }
        fclose($handle);
        return max(0, $count - 1); // Trừ dòng tiêu đề
    }

    if (hpt_post_ie_load_spreadsheet()) {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file_path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file_path);
            $sheet = $spreadsheet->getActiveSheet();
            return max(0, $sheet->getHighestRow() - 1);
        } catch (\Exception $e) {
            return 0;
        }
    }

    return 0;
}

function hpt_post_ie_read_slice($file_path, $format, $offset, $limit) {
    $rows = array();
    $start_row = $offset + 2; // +1 vì 1-indexed, +1 vì bỏ tiêu đề dòng 1
    $end_row   = $start_row + $limit - 1;

    if ($format === 'csv') {
        $handle = fopen($file_path, 'r');
        if (!$handle) return array();

        $header_line = fgets($handle);
        $delimiter = ',';
        if ($header_line) {
            if (substr($header_line, 0, 3) === "\xEF\xBB\xBF") {
                $header_line = substr($header_line, 3);
            }
            foreach (array(',', ';', "\t") as $d) {
                if (substr_count($header_line, $d) > substr_count($header_line, $delimiter)) {
                    $delimiter = $d;
                }
            }
        }

        $current_line = 1;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $current_line++;
            if ($current_line < $start_row) {
                continue;
            }
            if ($current_line > $end_row) {
                break;
            }
            $rows[] = array(
                'line_num' => $current_line,
                'data'     => $data,
            );
        }
        fclose($handle);
        return $rows;
    }

    // Đọc XLSX
    if (hpt_post_ie_load_spreadsheet()) {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file_path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file_path);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            $highestCol = $sheet->getHighestColumn();
            $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

            for ($r = $start_row; $r <= min($end_row, $highestRow); $r++) {
                $row_data = array();
                for ($c = 1; $c <= $highestColIndex; $c++) {
                    $row_data[] = (string) $sheet->getCellByColumnAndRow($c, $r)->getValue();
                }
                $rows[] = array(
                    'line_num' => $r,
                    'data'     => $row_data,
                );
            }
        } catch (\Exception $e) {
            return array();
        }
    }

    return $rows;
}

/* ============================================================
 * 6) XỬ LÝ DỮ LIỆU IMPORT CHI TIẾT
 * ============================================================ */

/**
 * Xử lý tải ảnh hoặc tìm ảnh đại diện
 */
function hpt_post_ie_attach_thumbnail($post_id, $image_input) {
    $image_input = trim((string) $image_input);
    if ($image_input === '') {
        return;
    }

    // 1. Nếu là số ID của attachment
    if (is_numeric($image_input)) {
        $attachment_id = absint($image_input);
        if (wp_attachment_is_image($attachment_id)) {
            set_post_thumbnail($post_id, $attachment_id);
            return;
        }
    }

    // 2. Nếu là URL từ xa
    if (filter_var($image_input, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $image_input)) {
        // Kiểm tra xem ảnh này đã từng tải về chưa (dựa theo URL nguồn)
        global $wpdb;
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_hpt_source_image_url' AND meta_value = %s LIMIT 1",
            $image_input
        ));

        if ($existing_id && wp_attachment_is_image($existing_id)) {
            set_post_thumbnail($post_id, $existing_id);
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $att_id = media_sideload_image($image_input, $post_id, null, 'id');
        if (!is_wp_error($att_id) && $att_id) {
            set_post_thumbnail($post_id, $att_id);
            update_post_meta($att_id, '_hpt_source_image_url', $image_input);
        }
        return;
    }

    // 3. Nếu là tên file trong media library (ví dụ: my-photo.webp)
    global $wpdb;
    $filename = basename($image_input);
    $att_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
        '%' . $wpdb->esc_like($filename)
    ));

    if ($att_id && wp_attachment_is_image($att_id)) {
        set_post_thumbnail($post_id, $att_id);
    }
}

/**
 * Xử lý chuyên mục (category) với hỗ trợ phân cấp cha/con
 */
function hpt_post_ie_resolve_categories($cat_string, $auto_create = true) {
    if (empty($cat_string)) {
        return array();
    }

    $raw_cats = preg_split('/[,|]/', (string) $cat_string);
    $cat_ids  = array();

    foreach ($raw_cats as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;

        // Hỗ trợ phân cấp theo dấu '>' (ví dụ: Tin tức > Công nghệ)
        if (strpos($entry, '>') !== false) {
            $levels = array_map('trim', explode('>', $entry));
            $parent_id = 0;
            foreach ($levels as $cat_name) {
                if ($cat_name === '') continue;
                $term = term_exists($cat_name, 'category', $parent_id);
                if (!$term) {
                    if ($auto_create) {
                        $new_term = wp_insert_term($cat_name, 'category', array('parent' => $parent_id));
                        if (!is_wp_error($new_term)) {
                            $parent_id = $new_term['term_id'];
                        }
                    }
                } else {
                    $parent_id = is_array($term) ? $term['term_id'] : $term;
                }
            }
            if ($parent_id) {
                $cat_ids[] = (int) $parent_id;
            }
        } else {
            // Danh mục đơn
            $term = term_exists($entry, 'category');
            if (!$term) {
                if ($auto_create) {
                    $new_term = wp_insert_term($entry, 'category');
                    if (!is_wp_error($new_term)) {
                        $cat_ids[] = (int) $new_term['term_id'];
                    }
                }
            } else {
                $cat_ids[] = is_array($term) ? (int) $term['term_id'] : (int) $term;
            }
        }
    }

    return array_unique(array_filter($cat_ids));
}

/**
 * Xử lý thẻ (post_tag)
 */
function hpt_post_ie_resolve_tags($tags_string) {
    if (empty($tags_string)) {
        return array();
    }
    $tags = array_map('trim', explode(',', (string) $tags_string));
    return array_values(array_filter($tags));
}

/**
 * Xử lý tác giả bài viết
 */
function hpt_post_ie_resolve_author($author_input) {
    $author_input = trim((string) $author_input);
    if ($author_input === '') {
        return get_current_user_id() ?: 1;
    }

    if (is_numeric($author_input)) {
        $user = get_user_by('id', (int) $author_input);
        if ($user) return $user->ID;
    }

    $user = get_user_by('login', $author_input);
    if ($user) return $user->ID;

    $user = get_user_by('email', $author_input);
    if ($user) return $user->ID;

    return get_current_user_id() ?: 1;
}

/**
 * Xử lý một dòng dữ liệu import
 */
function hpt_post_ie_process_row($row_data, $header_map, $mode, $options = array()) {
    $get_val = function ($field) use ($row_data, $header_map) {
        if (!isset($header_map[$field])) return '';
        $idx = $header_map[$field];
        return isset($row_data[$idx]) ? trim((string) $row_data[$idx]) : '';
    };

    $id        = absint($get_val('id'));
    $title     = $get_val('title');
    $slug      = sanitize_title($get_val('slug'));
    $content   = $get_val('content');
    $excerpt   = $get_val('excerpt');
    $category  = $get_val('category');
    $tags      = $get_val('tags');
    $thumbnail = $get_val('thumbnail');
    $status    = strtolower($get_val('status'));
    $date      = $get_val('date');
    $author    = $get_val('author');

    $meta_title = $get_val('meta_title');
    $meta_desc  = $get_val('meta_description');
    $meta_kw    = $get_val('meta_keyword');

    if ($title === '') {
        return array('status' => 'error', 'message' => 'Tiêu đề bài viết không được để trống.');
    }

    $valid_statuses = array('publish', 'draft', 'pending', 'private', 'future');
    if (!in_array($status, $valid_statuses, true)) {
        $status = 'publish';
    }

    // Tìm bài viết hiện có: Ưu tiên theo ID, sau đó theo Slug
    $existing_post = null;
    if ($id > 0) {
        $p = get_post($id);
        if ($p && $p->post_type === 'post') {
            $existing_post = $p;
        }
    }

    if (!$existing_post && $slug !== '') {
        $p = get_page_by_path($slug, OBJECT, 'post');
        if ($p) {
            $existing_post = $p;
        }
    }

    // Dry-run mode
    if ($mode === 'dryrun') {
        if ($existing_post) {
            return array('status' => 'updated', 'id' => $existing_post->ID, 'message' => 'Khớp bài viết ID: ' . $existing_post->ID);
        }
        return array('status' => 'created', 'id' => 0, 'message' => 'Hợp lệ để tạo mới');
    }

    // Kiểm tra chế độ create / update
    if ($existing_post) {
        if ($mode === 'create') {
            return array('status' => 'skipped', 'message' => 'Đã tồn tại (Bỏ qua do chọn chế độ Chỉ tạo mới)');
        }
    } else {
        if ($mode === 'update') {
            return array('status' => 'skipped', 'message' => 'Không tìm thấy bài viết để cập nhật');
        }
    }

    // Chuẩn bị dữ liệu bài viết
    $post_args = array(
        'post_type'    => 'post',
        'post_title'   => $title,
        'post_status'  => $status,
    );

    if ($slug !== '') {
        $post_args['post_name'] = $slug;
    }

    if ($content !== '') {
        $post_args['post_content'] = $content;
    }

    if ($excerpt !== '') {
        $post_args['post_excerpt'] = $excerpt;
    }

    if ($date !== '') {
        $timestamp = strtotime($date);
        if ($timestamp) {
            $post_args['post_date'] = gmdate('Y-m-d H:i:s', $timestamp + (get_option('gmt_offset') * HOUR_IN_SECONDS));
            $post_args['edit_date'] = true;
        }
    }

    if ($author !== '') {
        $post_args['post_author'] = hpt_post_ie_resolve_author($author);
    }

    $is_update = false;
    if ($existing_post) {
        $post_args['ID'] = $existing_post->ID;
        $post_id = wp_update_post($post_args, true);
        $is_update = true;
    } else {
        $post_id = wp_insert_post($post_args, true);
    }

    if (is_wp_error($post_id)) {
        return array('status' => 'error', 'message' => $post_id->get_error_message());
    }

    // Gán Categories
    if ($category !== '') {
        $auto_create = !empty($options['auto_create_terms']);
        $cat_ids = hpt_post_ie_resolve_categories($category, $auto_create);
        if (!empty($cat_ids)) {
            wp_set_post_categories($post_id, $cat_ids);
        }
    }

    // Gán Tags
    if ($tags !== '') {
        $tag_names = hpt_post_ie_resolve_tags($tags);
        if (!empty($tag_names)) {
            wp_set_post_tags($post_id, $tag_names, false);
        }
    }

    // Xử lý Rank Math SEO meta
    if ($meta_title !== '') {
        update_post_meta($post_id, 'rank_math_title', $meta_title);
    }
    if ($meta_desc !== '') {
        update_post_meta($post_id, 'rank_math_description', $meta_desc);
    }
    if ($meta_kw !== '') {
        update_post_meta($post_id, 'rank_math_focus_keyword', $meta_kw);
    }

    // Gán Featured Image (nếu không bật chế độ bỏ qua ảnh)
    if (empty($options['skip_images']) && $thumbnail !== '') {
        hpt_post_ie_attach_thumbnail($post_id, $thumbnail);
    }

    return array(
        'status' => $is_update ? 'updated' : 'created',
        'id'     => $post_id,
        'title'  => $title,
    );
}

/* ============================================================
 * 7) AJAX IMPORT HANDLERS (INIT & BATCH)
 * ============================================================ */

function hpt_post_ie_ajax_import_init() {
    if (!hpt_post_ie_user_can_manage()) {
        wp_send_json_error(array('message' => 'Bạn không có quyền thực hiện thao tác này.'), 403);
    }
    check_ajax_referer('hpt_post_ie_ajax', 'nonce');

    if (empty($_FILES['import_file']['tmp_name'])) {
        wp_send_json_error(array('message' => 'Vui lòng chọn file cần nhập.'), 400);
    }

    $uploaded_name = sanitize_file_name($_FILES['import_file']['name']);
    $ext = strtolower(pathinfo($uploaded_name, PATHINFO_EXTENSION));

    if (!in_array($ext, array('xlsx', 'csv', 'txt'), true)) {
        wp_send_json_error(array('message' => 'Chỉ hỗ trợ file định dạng Excel (.xlsx) hoặc CSV (.csv).'), 400);
    }

    $format = ($ext === 'xlsx') ? 'xlsx' : 'csv';
    $storage_dir = hpt_post_ie_storage_dir();
    $target_file = $storage_dir . '/import-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false) . '.' . $format;

    if (!move_uploaded_file($_FILES['import_file']['tmp_name'], $target_file)) {
        wp_send_json_error(array('message' => 'Không thể lưu file tải lên máy chủ.'), 500);
    }

    $headers = hpt_post_ie_read_headers($target_file, $format);
    if (empty($headers)) {
        @unlink($target_file);
        wp_send_json_error(array('message' => 'Không đọc được dòng tiêu đề từ file.'), 400);
    }

    $header_map = hpt_post_ie_map_headers($headers);
    if (empty($header_map['title'])) {
        @unlink($target_file);
        wp_send_json_error(array('message' => 'Không tìm thấy cột "Tiêu đề bài viết" trong file. Vui lòng kiểm tra dòng tiêu đề.'), 400);
    }

    $total_rows = hpt_post_ie_count_rows($target_file, $format);
    if ($total_rows <= 0) {
        @unlink($target_file);
        wp_send_json_error(array('message' => 'File không chứa dòng dữ liệu nào.'), 400);
    }

    $mode = sanitize_key($_POST['mode'] ?? 'upsert');
    $skip_images = !empty($_POST['skip_images']) ? 1 : 0;
    $auto_create_terms = !empty($_POST['auto_create_terms']) ? 1 : 0;

    $config = array(
        'format'            => $format,
        'headers'           => $headers,
        'header_map'        => $header_map,
        'skip_images'       => $skip_images,
        'auto_create_terms' => $auto_create_terms,
    );

    $job_id = hpt_post_ie_create_job('import', $mode, $target_file, $config);
    hpt_post_ie_update_job($job_id, array(
        'status'     => 'processing',
        'total_rows' => $total_rows,
    ));

    wp_send_json_success(array(
        'job_id'      => $job_id,
        'total_rows'  => $total_rows,
        'header_map'  => $header_map,
        'headers'     => $headers,
    ));
}
add_action('wp_ajax_hpt_post_ie_import_init', 'hpt_post_ie_ajax_import_init');

function hpt_post_ie_ajax_import_batch() {
    if (!hpt_post_ie_user_can_manage()) {
        wp_send_json_error(array('message' => 'Bạn không có quyền thực hiện thao tác này.'), 403);
    }
    check_ajax_referer('hpt_post_ie_ajax', 'nonce');

    $job_id = absint($_POST['job_id'] ?? 0);
    $job = hpt_post_ie_get_job($job_id);
    if (!$job) {
        wp_send_json_error(array('message' => 'Không tìm thấy job xử lý.'), 404);
    }

    if ($job['status'] === 'paused' || $job['status'] === 'cancelled') {
        wp_send_json_success(array(
            'job_id'   => $job_id,
            'status'   => $job['status'],
            'done'     => false,
            'percent'  => $job['percent'],
        ));
    }

    $batch_size = hpt_post_ie_import_batch_size();
    $offset     = (int) $job['current_offset'];
    $total      = (int) $job['total_rows'];
    $config     = (array) $job['config'];
    $header_map = (array) ($config['header_map'] ?? array());
    $format     = $config['format'] ?? 'xlsx';
    $file_path  = $job['file_path'];

    if (!file_exists($file_path)) {
        hpt_post_ie_update_job($job_id, array('status' => 'failed', 'error_message' => 'File import đã bị xóa.'));
        wp_send_json_error(array('message' => 'File import không còn trên hệ thống.'), 404);
    }

    $slice = hpt_post_ie_read_slice($file_path, $format, $offset, $batch_size);

    $success_count = (int) $job['success_count'];
    $updated_count = (int) $job['updated_count'];
    $failed_count  = (int) $job['failed_count'];
    $skipped_count = (int) $job['skipped_count'];

    $options = array(
        'skip_images'       => !empty($config['skip_images']),
        'auto_create_terms' => !empty($config['auto_create_terms']),
    );

    foreach ($slice as $row_item) {
        $line_num = $row_item['line_num'];
        $row_data = $row_item['data'];

        $identifier = $row_data[$header_map['title'] ?? 0] ?? ('Dòng ' . $line_num);

        $res = hpt_post_ie_process_row($row_data, $header_map, $job['mode'], $options);

        if ($res['status'] === 'created') {
            $success_count++;
        } elseif ($res['status'] === 'updated') {
            $updated_count++;
        } elseif ($res['status'] === 'skipped') {
            $skipped_count++;
        } else {
            $failed_count++;
            hpt_post_ie_log_error($job_id, $line_num, $identifier, $res['message'] ?? 'Lỗi không xác định', $row_data);
        }
    }

    $processed = min($total, $offset + count($slice));
    $percent   = $total > 0 ? min(100, (int) round(($processed / $total) * 100)) : 100;
    $done      = ($processed >= $total) || empty($slice);

    $update_data = array(
        'current_offset' => $processed,
        'processed_rows' => $processed,
        'success_count'  => $success_count,
        'updated_count'  => $updated_count,
        'failed_count'   => $failed_count,
        'skipped_count'  => $skipped_count,
        'percent'        => $percent,
    );

    if ($done) {
        $update_data['status']      = 'completed';
        $update_data['finished_at'] = current_time('mysql');
        @unlink($file_path);
    }

    hpt_post_ie_update_job($job_id, $update_data);

    wp_send_json_success(array(
        'job_id'    => $job_id,
        'status'    => $done ? 'completed' : 'processing',
        'done'      => $done,
        'percent'   => $percent,
        'processed' => $processed,
        'total'     => $total,
        'created'   => $success_count,
        'updated'   => $updated_count,
        'skipped'   => $skipped_count,
        'failed'    => $failed_count,
        'errors'    => hpt_post_ie_get_job_errors($job_id, 20),
    ));
}
add_action('wp_ajax_hpt_post_ie_import_batch', 'hpt_post_ie_ajax_import_batch');

/* ============================================================
 * 8) AJAX EXPORT HANDLERS (INIT & BATCH)
 * ============================================================ */

function hpt_post_ie_ajax_export_init() {
    if (!hpt_post_ie_user_can_manage()) {
        wp_send_json_error(array('message' => 'Bạn không có quyền thực hiện thao tác này.'), 403);
    }
    check_ajax_referer('hpt_post_ie_ajax', 'nonce');

    $category_id = absint($_POST['category_id'] ?? 0);
    $post_status = sanitize_key($_POST['post_status'] ?? 'all');
    $format      = sanitize_key($_POST['format'] ?? 'xlsx');
    if (!in_array($format, array('xlsx', 'csv'), true)) {
        $format = 'xlsx';
    }

    $raw_fields = $_POST['fields'] ?? array();
    $all_fields = hpt_post_ie_fields();
    $chosen_fields = array();

    if (!empty($raw_fields) && is_array($raw_fields)) {
        foreach ($raw_fields as $f) {
            $f = sanitize_key($f);
            if (isset($all_fields[$f])) {
                $chosen_fields[$f] = $all_fields[$f];
            }
        }
    }
    if (empty($chosen_fields)) {
        $chosen_fields = $all_fields;
    }

    // Đếm tổng số bài viết cần xuất
    $query_args = array(
        'post_type'      => 'post',
        'posts_per_page' => 1,
        'fields'         => 'ids',
    );

    if ($category_id > 0) {
        $query_args['cat'] = $category_id;
    }

    if ($post_status !== 'all' && $post_status !== '') {
        $query_args['post_status'] = $post_status;
    } else {
        $query_args['post_status'] = array('publish', 'draft', 'pending', 'private', 'future');
    }

    $q = new WP_Query($query_args);
    $total_rows = (int) $q->found_posts;

    if ($total_rows <= 0) {
        wp_send_json_error(array('message' => 'Không tìm thấy bài viết nào theo điều kiện lọc.'), 400);
    }

    $storage_dir = hpt_post_ie_storage_dir();
    $filename = 'xuat-bai-viet-' . gmdate('Ymd-His') . '.' . $format;
    $target_file = $storage_dir . '/' . $filename;

    // Khởi tạo file xuất
    if ($format === 'csv') {
        $fp = fopen($target_file, 'w');
        if ($fp) {
            // Ghi UTF-8 BOM để Excel hiển thị đúng tiếng Việt
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, array_values($chosen_fields));
            fclose($fp);
        }
    }

    $config = array(
        'category_id'   => $category_id,
        'post_status'   => $post_status,
        'format'        => $format,
        'chosen_fields' => $chosen_fields,
    );

    $job_id = hpt_post_ie_create_job('export', 'export', $target_file, $config);
    hpt_post_ie_update_job($job_id, array(
        'status'        => 'processing',
        'download_path' => $target_file,
        'total_rows'    => $total_rows,
    ));

    wp_send_json_success(array(
        'job_id'     => $job_id,
        'total_rows' => $total_rows,
    ));
}
add_action('wp_ajax_hpt_post_ie_export_init', 'hpt_post_ie_ajax_export_init');

function hpt_post_ie_ajax_export_batch() {
    if (!hpt_post_ie_user_can_manage()) {
        wp_send_json_error(array('message' => 'Bạn không có quyền thực hiện thao tác này.'), 403);
    }
    check_ajax_referer('hpt_post_ie_ajax', 'nonce');

    $job_id = absint($_POST['job_id'] ?? 0);
    $job = hpt_post_ie_get_job($job_id);
    if (!$job) {
        wp_send_json_error(array('message' => 'Không tìm thấy job xử lý.'), 404);
    }

    $batch_size    = hpt_post_ie_export_batch_size();
    $offset        = (int) $job['current_offset'];
    $total         = (int) $job['total_rows'];
    $config        = (array) $job['config'];
    $format        = $config['format'] ?? 'xlsx';
    $target_file   = $job['download_path'];
    $chosen_fields = (array) ($config['chosen_fields'] ?? hpt_post_ie_fields());

    $query_args = array(
        'post_type'      => 'post',
        'posts_per_page' => $batch_size,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    );

    if (!empty($config['category_id'])) {
        $query_args['cat'] = absint($config['category_id']);
    }

    if (!empty($config['post_status']) && $config['post_status'] !== 'all') {
        $query_args['post_status'] = $config['post_status'];
    } else {
        $query_args['post_status'] = array('publish', 'draft', 'pending', 'private', 'future');
    }

    $posts = get_posts($query_args);
    $rows_to_append = array();

    foreach ($posts as $post) {
        $row = array();
        foreach (array_keys($chosen_fields) as $key) {
            switch ($key) {
                case 'id':
                    $row[] = $post->ID;
                    break;
                case 'title':
                    $row[] = $post->post_title;
                    break;
                case 'slug':
                    $row[] = $post->post_name;
                    break;
                case 'content':
                    $row[] = $post->post_content;
                    break;
                case 'excerpt':
                    $row[] = $post->post_excerpt;
                    break;
                case 'category':
                    $cats = wp_get_post_categories($post->ID, array('fields' => 'names'));
                    $row[] = !is_wp_error($cats) ? implode(', ', $cats) : '';
                    break;
                case 'tags':
                    $tags = wp_get_post_tags($post->ID, array('fields' => 'names'));
                    $row[] = !is_wp_error($tags) ? implode(', ', $tags) : '';
                    break;
                case 'thumbnail':
                    $thumb_url = get_the_post_thumbnail_url($post->ID, 'full');
                    $row[] = $thumb_url ?: '';
                    break;
                case 'status':
                    $row[] = $post->post_status;
                    break;
                case 'date':
                    $row[] = $post->post_date;
                    break;
                case 'author':
                    $author_name = get_the_author_meta('user_login', $post->post_author);
                    $row[] = $author_name ?: '';
                    break;
                case 'meta_title':
                    $row[] = (string) get_post_meta($post->ID, 'rank_math_title', true);
                    break;
                case 'meta_description':
                    $row[] = (string) get_post_meta($post->ID, 'rank_math_description', true);
                    break;
                case 'meta_keyword':
                    $row[] = (string) get_post_meta($post->ID, 'rank_math_focus_keyword', true);
                    break;
                case 'permalink':
                    $row[] = get_permalink($post->ID);
                    break;
                default:
                    $row[] = '';
                    break;
            }
        }
        $rows_to_append[] = $row;
    }

    // Ghi vào file
    if ($format === 'csv') {
        $fp = fopen($target_file, 'a');
        if ($fp) {
            foreach ($rows_to_append as $r) {
                fputcsv($fp, $r);
            }
            fclose($fp);
        }
    } else {
        // Đối với XLSX, nếu đang chạy từng batch, ta lưu tạm CSV, khi batch cuối cùng xong sẽ chuyển thành XLSX
        $temp_csv = $target_file . '.tmp.csv';
        $fp = fopen($temp_csv, $offset === 0 ? 'w' : 'a');
        if ($fp) {
            if ($offset === 0) {
                fwrite($fp, "\xEF\xBB\xBF");
                fputcsv($fp, array_values($chosen_fields));
            }
            foreach ($rows_to_append as $r) {
                fputcsv($fp, $r);
            }
            fclose($fp);
        }
    }

    $processed = min($total, $offset + count($posts));
    $percent   = $total > 0 ? min(100, (int) round(($processed / $total) * 100)) : 100;
    $done      = ($processed >= $total) || empty($posts);

    $download_url = '';
    if ($done) {
        if ($format === 'xlsx') {
            $temp_csv = $target_file . '.tmp.csv';
            if (file_exists($temp_csv) && hpt_post_ie_load_spreadsheet()) {
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                $reader->setInputEncoding('UTF-8');
                $spreadsheet = $reader->load($temp_csv);
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $writer->save($target_file);
                @unlink($temp_csv);
            }
        }

        $download_url = add_query_arg(array(
            'action' => 'hpt_post_ie_download',
            'job_id' => $job_id,
            'nonce'  => wp_create_nonce('hpt_post_ie_download_' . $job_id),
        ), admin_url('admin-ajax.php'));
    }

    hpt_post_ie_update_job($job_id, array(
        'current_offset' => $processed,
        'processed_rows' => $processed,
        'success_count'  => $processed,
        'percent'        => $percent,
        'status'         => $done ? 'completed' : 'processing',
        'finished_at'    => $done ? current_time('mysql') : null,
    ));

    wp_send_json_success(array(
        'job_id'       => $job_id,
        'done'         => $done,
        'percent'      => $percent,
        'processed'    => $processed,
        'total'        => $total,
        'download_url' => $download_url,
    ));
}
add_action('wp_ajax_hpt_post_ie_export_batch', 'hpt_post_ie_ajax_export_batch');

/* ============================================================
 * 9) DOWNLOAD FILE EXPORT & FILE MẪU (TEMPLATE)
 * ============================================================ */

function hpt_post_ie_ajax_download() {
    if (!hpt_post_ie_user_can_manage()) {
        wp_die('Bạn không có quyền tải file này.');
    }

    $job_id = absint($_GET['job_id'] ?? 0);
    if (!wp_verify_nonce($_GET['nonce'] ?? '', 'hpt_post_ie_download_' . $job_id)) {
        wp_die('Liên kết tải đã hết hạn hoặc không hợp lệ.');
    }

    $job = hpt_post_ie_get_job($job_id);
    if (!$job || empty($job['download_path']) || !file_exists($job['download_path'])) {
        wp_die('File xuất không tồn tại hoặc đã bị xóa.');
    }

    $file_path = $job['download_path'];
    $filename  = basename($file_path);
    $format    = $job['format'] ?: 'xlsx';

    nocache_headers();
    if ($format === 'xlsx') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    } else {
        header('Content-Type: text/csv; charset=UTF-8');
    }

    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file_path));

    readfile($file_path);
    exit;
}
add_action('wp_ajax_hpt_post_ie_download', 'hpt_post_ie_ajax_download');

/**
 * Tải file mẫu bài viết Excel hoặc CSV
 */
function hpt_post_ie_ajax_sample_template() {
    if (!hpt_post_ie_user_can_manage()) {
        wp_die('Không có quyền.');
    }

    $format = sanitize_key($_GET['format'] ?? 'xlsx');
    $fields = array(
        'id'               => 'ID',
        'title'            => 'Tiêu đề bài viết',
        'slug'             => 'Đường dẫn (Slug)',
        'category'         => 'Chuyên mục',
        'excerpt'          => 'Tóm tắt bài viết',
        'content'          => 'Nội dung chi tiết',
        'tags'             => 'Thẻ (Tags)',
        'thumbnail'        => 'Ảnh đại diện (URL)',
        'status'           => 'Trạng thái',
        'meta_title'       => 'SEO Title',
        'meta_description' => 'SEO Description',
        'meta_keyword'     => 'SEO Keyword',
    );

    $sample_rows = array(
        array(
            '',
            'Giới thiệu công nghệ tự động hóa công nghiệp 4.0',
            'gioi-thieu-cong-nghe-tu-dong-hoa-cong-nghiep-4-0',
            'Tin tức > Công nghệ',
            'Tổng quan các giải pháp tự động hóa tiên tiến trong nhà máy hiện đại.',
            '<p>Tự động hóa công nghiệp đang trở thành xu thế tất yếu của các doanh nghiệp sản xuất...</p>',
            'tự động hóa, công nghệ 4.0, nhà máy thông minh',
            'https://picsum.photos/800/600',
            'publish',
            'Công nghệ tự động hóa 4.0 trong công nghiệp hiện đại',
            'Khám phá xu hướng tự động hóa công nghiệp 4.0 và giải pháp thông minh cho doanh nghiệp.',
            'tự động hóa công nghiệp',
        ),
        array(
            '',
            'Hướng dẫn lựa chọn biến tần phù hợp cho động cơ 3 pha',
            'huong-dan-lua-chon-bien-tan-phu-hop-cho-dong-co-3-pha',
            'Tin tức > Kỹ thuật',
            'Kinh nghiệm thực tế khi tính chọn công suất và dải tần số biến tần.',
            '<p>Khi chọn biến tần cho động cơ 3 pha, kỹ sư cần chú ý các thông số quan trọng như công suất tải, điện áp vào...</p>',
            'biến tần, động cơ 3 pha, kỹ thuật điện',
            'https://picsum.photos/800/600',
            'publish',
            'Cách chọn biến tần cho động cơ 3 pha chuẩn kỹ thuật',
            'Hướng dẫn chi tiết phương pháp tính chọn biến tần tối ưu chi phí và độ bền cho động cơ.',
            'biến tần động cơ 3 pha',
        ),
    );

    nocache_headers();

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="mau-nhap-bai-viet.csv"');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, array_values($fields));
        foreach ($sample_rows as $r) {
            fputcsv($fp, $r);
        }
        fclose($fp);
        exit;
    }

    if (hpt_post_ie_load_spreadsheet()) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('MauNhapBaiViet');

        $col = 1;
        foreach (array_values($fields) as $header) {
            $sheet->setCellValueByColumnAndRow($col, 1, $header);
            $sheet->getStyleByColumnAndRow($col, 1)->getFont()->setBold(true);
            $col++;
        }

        $row_idx = 2;
        foreach ($sample_rows as $sample) {
            $col_idx = 1;
            foreach ($sample as $val) {
                $sheet->setCellValueByColumnAndRow($col_idx, $row_idx, $val);
                $col_idx++;
            }
            $row_idx++;
        }

        foreach (range(1, count($fields)) as $c) {
            $colString = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $sheet->getColumnDimension($colString)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="mau-nhap-bai-viet.xlsx"');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    wp_die('Máy chủ không sẵn sàng thư viện tạo file mẫu XLSX.');
}
add_action('wp_ajax_hpt_post_ie_sample_template', 'hpt_post_ie_ajax_sample_template');

/* ============================================================
 * 10) ĐIỀU KHIỂN JOB (PAUSE / RESUME / CANCEL)
 * ============================================================ */

function hpt_post_ie_ajax_control() {
    if (!hpt_post_ie_user_can_manage()) {
        wp_send_json_error(array('message' => 'Không có quyền.'), 403);
    }
    check_ajax_referer('hpt_post_ie_ajax', 'nonce');

    $job_id = absint($_POST['job_id'] ?? 0);
    $cmd    = sanitize_key($_POST['cmd'] ?? '');

    $job = hpt_post_ie_get_job($job_id);
    if (!$job) {
        wp_send_json_error(array('message' => 'Không tìm thấy job.'));
    }

    if ($cmd === 'pause') {
        hpt_post_ie_update_job($job_id, array('status' => 'paused'));
    } elseif ($cmd === 'resume') {
        hpt_post_ie_update_job($job_id, array('status' => 'processing'));
    } elseif ($cmd === 'cancel') {
        hpt_post_ie_update_job($job_id, array('status' => 'cancelled'));
        if (!empty($job['file_path']) && file_exists($job['file_path'])) {
            @unlink($job['file_path']);
        }
    }

    wp_send_json_success(array('status' => $cmd));
}
add_action('wp_ajax_hpt_post_ie_control', 'hpt_post_ie_ajax_control');

/* ============================================================
 * 11) ĐĂNG KÝ MENU ADMIN, ASSETS VÀ THÔNG BÁO
 * ============================================================ */

function hpt_post_ie_register_menu() {
    add_submenu_page(
        'edit.php',
        'Xuất/Nhập bài viết',
        'Xuất/Nhập bài viết',
        'edit_posts',
        'hpt-post-ie',
        'hpt_post_ie_render_page'
    );
}
add_action('admin_menu', 'hpt_post_ie_register_menu');

/**
 * Hiển thị nút liên kết nhanh trên trang danh sách bài viết
 */
function hpt_post_ie_post_list_notice() {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'edit-post') {
        return;
    }
    if (!current_user_can('edit_posts')) {
        return;
    }
    $url = admin_url('edit.php?page=hpt-post-ie');
    echo '<div class="notice notice-info" style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;">
            <span><strong>Công cụ bài viết:</strong> Xuất hoặc nhập dữ liệu bài viết theo file Excel (.xlsx) / CSV.</span>
            <a class="button button-primary" href="' . esc_url($url) . '"><span class="dashicons dashicons-database-import" style="vertical-align:middle;margin-right:4px;"></span>Xuất/Nhập bài viết</a>
          </div>';
}
add_action('admin_notices', 'hpt_post_ie_post_list_notice');

function hpt_post_ie_admin_enqueue($hook) {
    if ($hook !== 'posts_page_hpt-post-ie') {
        return;
    }

    $css_file = get_stylesheet_directory() . '/assets/css/post-import-export-admin.css';
    $js_file  = get_stylesheet_directory() . '/assets/js/post-import-export-admin.js';

    $css_uri  = get_stylesheet_directory_uri() . '/assets/css/post-import-export-admin.css';
    $js_uri   = get_stylesheet_directory_uri() . '/assets/js/post-import-export-admin.js';

    if (file_exists($css_file)) {
        wp_enqueue_style('hpt-post-ie-admin', $css_uri, array(), (string) filemtime($css_file));
    }

    if (file_exists($js_file)) {
        wp_enqueue_script('hpt-post-ie-admin', $js_uri, array('jquery'), (string) filemtime($js_file), true);
        wp_localize_script('hpt-post-ie-admin', 'HPTPostIE', array(
            'ajax_url'          => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('hpt_post_ie_ajax'),
            'sample_xlsx_url'   => admin_url('admin-ajax.php?action=hpt_post_ie_sample_template&format=xlsx'),
            'sample_csv_url'    => admin_url('admin-ajax.php?action=hpt_post_ie_sample_template&format=csv'),
            'import_batch_size' => hpt_post_ie_import_batch_size(),
            'export_batch_size' => hpt_post_ie_export_batch_size(),
        ));
    }
}
add_action('admin_enqueue_scripts', 'hpt_post_ie_admin_enqueue');

/* ============================================================
 * 12) GIAO DIỆN TRANG QUẢN TRỊ (ADMIN UI)
 * ============================================================ */

function hpt_post_ie_render_page() {
    if (!hpt_post_ie_user_can_manage()) {
        wp_die('Bạn không có quyền truy cập trang này.');
    }

    $categories = get_categories(array('hide_empty' => false, 'orderby' => 'name'));
    $fields = hpt_post_ie_fields();
    ?>
    <div class="wrap hpt-pie-admin hpt-post-ie-admin">
        <header class="hpt-pie-hero">
            <div>
                <span class="hpt-pie-eyebrow">HPT POST DATA TOOLS</span>
                <h1>Xuất / Nhập bài viết</h1>
                <p>Quản lý dữ liệu bài viết theo lô tốc độ cao, hỗ trợ Excel (.xlsx) và CSV (.csv), an toàn tuyệt đối chống timeout.</p>
            </div>
            <div class="hpt-pie-hero-badge">
                <span class="dashicons dashicons-welcome-write-blog"></span>
                <strong>Batch Engine</strong>
                <small>Xử lý nền linh hoạt</small>
            </div>
        </header>

        <nav class="hpt-pie-nav" aria-label="Điều hướng nhanh">
            <a href="#hpt-post-import-card"><span class="dashicons dashicons-upload"></span> Nhập bài viết</a>
            <a href="#hpt-post-export-card"><span class="dashicons dashicons-download"></span> Xuất bài viết</a>
            <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=hpt_post_ie_sample_template&format=xlsx')); ?>" target="_blank"><span class="dashicons dashicons-media-spreadsheet"></span> Tải mẫu Excel (.xlsx)</a>
            <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=hpt_post_ie_sample_template&format=csv')); ?>" target="_blank"><span class="dashicons dashicons-media-text"></span> Tải mẫu CSV (.csv)</a>
        </nav>

        <div class="hpt-pie-summary-grid">
            <div>
                <span class="dashicons dashicons-cloud-saved"></span>
                <strong>Resumable & Batch</strong>
                <small>Không lo timeout trên hosting</small>
            </div>
            <div>
                <span class="dashicons dashicons-randomize"></span>
                <strong>Smart Mapping</strong>
                <small>Tự động nhận diện tiêu đề cột</small>
            </div>
            <div>
                <span class="dashicons dashicons-search"></span>
                <strong>Chuẩn SEO Rank Math</strong>
                <small>Giữ nguyên Meta Title, Desc, Keywords</small>
            </div>
        </div>

        <!-- CARD 1: NHẬP BÀI VIẾT -->
        <div id="hpt-post-import-card" class="hpt-pie-card">
            <div class="hpt-pie-card-heading">
                <div class="hpt-pie-step">01</div>
                <div>
                    <h2>Nhập bài viết từ file Excel (.xlsx) hoặc CSV</h2>
                    <p>Hỗ trợ cập nhật bài viết cũ, tạo bài viết mới, tự động tải ảnh đại diện và tạo chuyên mục mới.</p>
                </div>
            </div>
            <div class="hpt-pie-card-body">
                <form id="hpt-post-import-form" enctype="multipart/form-data">
                    <div class="hpt-pie-form-grid">
                        <label class="hpt-pie-field hpt-pie-field--wide">
                            <span>Chọn file dữ liệu (.xlsx, .csv)</span>
                            <input type="file" name="import_file" id="hpt-post-import-file" accept=".xlsx,.csv,.txt" required>
                            <small>Định dạng: Excel (.xlsx) hoặc CSV (UTF-8). Dòng đầu tiên là tiêu đề cột. <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=hpt_post_ie_sample_template&format=xlsx')); ?>">Tải file mẫu Excel chuẩn tại đây</a>.</small>
                        </label>

                        <fieldset class="hpt-pie-field">
                            <legend>Chế độ nhập</legend>
                            <label><input type="radio" name="mode" value="upsert" checked> <strong>Upsert (Khuyến nghị)</strong><small>Cập nhật nếu bài viết đã có (khớp ID/Slug), tạo mới nếu chưa có</small></label>
                            <label><input type="radio" name="mode" value="create"> <strong>Chỉ tạo mới</strong><small>Bỏ qua nếu bài viết đã tồn tại</small></label>
                            <label><input type="radio" name="mode" value="update"> <strong>Chỉ cập nhật</strong><small>Bỏ qua nếu không tìm thấy bài viết tương ứng</small></label>
                            <label><input type="radio" name="mode" value="dryrun"> <strong>Dry-run (Kiểm tra thử)</strong><small>Đọc và kiểm tra cấu trúc dữ liệu, không ghi vào database</small></label>
                        </fieldset>

                        <fieldset class="hpt-pie-field">
                            <legend>Tùy chọn xử lý</legend>
                            <label><input type="checkbox" name="auto_create_terms" value="1" checked> <strong>Tự tạo chuyên mục mới</strong><small>Tự động tạo danh mục cha/con nếu trong hệ thống chưa có</small></label>
                            <label><input type="checkbox" name="skip_images" value="1"> <strong>Bỏ qua tải ảnh đại diện</strong><small>Tăng tốc độ nhập dữ liệu tối đa khi không cần tải ảnh mới</small></label>
                            <div class="hpt-pie-safe-note"><span class="dashicons dashicons-shield"></span> Dữ liệu được ghi an toàn từng batch độc lập.</div>
                        </fieldset>
                    </div>

                    <button class="button button-primary hpt-pie-primary" type="submit" id="hpt-post-import-btn">
                        <span class="dashicons dashicons-upload"></span> Bắt đầu nhập bài viết
                    </button>
                </form>

                <!-- KHU VỰC TIẾN TRÌNH IMPORT -->
                <div id="hpt-post-import-progress" class="hpt-pie-progress" style="display:none;">
                    <strong class="hpt-pie-title">Đang chuẩn bị dữ liệu...</strong>
                    <div class="hpt-pie-track"><div class="hpt-pie-fill" style="width:0;"></div></div>
                    <div class="hpt-pie-meta">Đang xử lý: 0 / 0</div>
                    <p class="hpt-pie-actions">
                        <button type="button" class="button hpt-post-pause" data-type="import">Tạm dừng</button>
                        <button type="button" class="button hpt-post-resume" data-type="import" style="display:none;">Tiếp tục</button>
                        <button type="button" class="button hpt-post-cancel" data-type="import">Hủy bỏ</button>
                    </p>
                    <div class="hpt-pie-errors" style="display:none;"></div>
                </div>
            </div>
        </div>

        <!-- CARD 2: XUẤT BÀI VIẾT -->
        <div id="hpt-post-export-card" class="hpt-pie-card">
            <div class="hpt-pie-card-heading">
                <div class="hpt-pie-step">02</div>
                <div>
                    <h2>Xuất bài viết ra file Excel (.xlsx) hoặc CSV</h2>
                    <p>Lọc theo chuyên mục, trạng thái và chủ động chọn các trường dữ liệu cần xuất.</p>
                </div>
            </div>
            <div class="hpt-pie-card-body">
                <form id="hpt-post-export-form">
                    <div class="hpt-pie-form-grid hpt-pie-form-grid--export">
                        <label class="hpt-pie-field">
                            <span>Lọc theo chuyên mục</span>
                            <select name="category_id" id="hpt-post-export-cat">
                                <option value="0">-- Tất cả chuyên mục --</option>
                                <?php foreach ($categories as $cat) : ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>">
                                        <?php echo esc_html($cat->name); ?> (<?php echo (int) $cat->count; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>Mặc định xuất toàn bộ bài viết từ mọi chuyên mục.</small>
                        </label>

                        <fieldset class="hpt-pie-field">
                            <legend>Trạng thái & Định dạng</legend>
                            <label>Trạng thái bài viết:
                                <select name="post_status" style="width:100%;margin-top:4px;">
                                    <option value="all">Tất cả trạng thái</option>
                                    <option value="publish" selected>Đã xuất bản (Publish)</option>
                                    <option value="draft">Bản nháp (Draft)</option>
                                    <option value="pending">Chờ duyệt (Pending)</option>
                                    <option value="private">Riêng tư (Private)</option>
                                </select>
                            </label>
                            <label style="margin-top:10px;">Định dạng file xuất:
                                <select name="format" style="width:100%;margin-top:4px;">
                                    <option value="xlsx" selected>Excel (.xlsx) - Khuyến nghị</option>
                                    <option value="csv">CSV (.csv chuẩn UTF-8 có BOM)</option>
                                </select>
                            </label>
                        </fieldset>

                        <fieldset class="hpt-pie-field hpt-pie-field--wide">
                            <legend>Chọn các cột cần xuất</legend>
                            <div class="hpt-pie-checkbox-grid">
                                <?php foreach ($fields as $field_key => $field_label) : ?>
                                    <label>
                                        <input type="checkbox" name="fields[]" value="<?php echo esc_attr($field_key); ?>" <?php checked(in_array($field_key, array('id', 'title', 'slug', 'category', 'excerpt', 'thumbnail', 'status', 'date', 'permalink', 'meta_title', 'meta_description', 'meta_keyword'), true)); ?>>
                                        <?php echo esc_html($field_label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    </div>

                    <button class="button button-primary hpt-pie-primary" type="submit" id="hpt-post-export-btn">
                        <span class="dashicons dashicons-download"></span> Bắt đầu xuất bài viết
                    </button>
                </form>

                <!-- KHU VỰC TIẾN TRÌNH EXPORT -->
                <div id="hpt-post-export-progress" class="hpt-pie-progress" style="display:none;">
                    <strong class="hpt-pie-title">Đang xuất dữ liệu...</strong>
                    <div class="hpt-pie-track"><div class="hpt-pie-fill" style="width:0;"></div></div>
                    <div class="hpt-pie-meta">Đang xử lý: 0 / 0</div>
                    <p class="hpt-pie-actions">
                        <button type="button" class="button hpt-post-pause" data-type="export">Tạm dừng</button>
                        <button type="button" class="button hpt-post-resume" data-type="export" style="display:none;">Tiếp tục</button>
                        <button type="button" class="button hpt-post-cancel" data-type="export">Hủy bỏ</button>
                        <a class="button button-primary hpt-post-download" style="display:none;" href="#"><span class="dashicons dashicons-download"></span> Tải file về máy</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php
}
