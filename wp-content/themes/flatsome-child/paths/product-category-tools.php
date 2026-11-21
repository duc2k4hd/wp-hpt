<?php

if (!defined('ABSPATH')) {
    exit;
}

function hpt_product_cat_tools_user_can_manage() {
    return current_user_can('manage_woocommerce') || current_user_can('manage_product_terms');
}

function hpt_product_cat_tools_get_page_url($args = array()) {
    $url = admin_url('edit.php?post_type=product&page=hpt-product-cat-tools');

    if (!empty($args)) {
        $url = add_query_arg($args, $url);
    }

    return $url;
}

function hpt_product_cat_tools_notice_key() {
    return 'hpt_product_cat_tools_notice_' . get_current_user_id();
}

function hpt_product_cat_tools_set_notice($notice) {
    set_transient(hpt_product_cat_tools_notice_key(), $notice, 10 * MINUTE_IN_SECONDS);
}

function hpt_product_cat_tools_pop_notice() {
    $key    = hpt_product_cat_tools_notice_key();
    $notice = get_transient($key);

    if (false !== $notice) {
        delete_transient($key);
    }

    return $notice;
}

function hpt_product_cat_tools_make_debug_token() {
    return 'hptcat-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
}

function hpt_product_cat_tools_debug_ui_key() {
    return 'hpt_product_cat_tools_debug_ui_' . get_current_user_id();
}

function hpt_product_cat_tools_set_debug_ui($debug) {
    set_transient(hpt_product_cat_tools_debug_ui_key(), $debug, 12 * HOUR_IN_SECONDS);
}

function hpt_product_cat_tools_get_debug_ui() {
    $debug = get_transient(hpt_product_cat_tools_debug_ui_key());

    return (is_array($debug) && !empty($debug)) ? $debug : array();
}

function hpt_product_cat_tools_clear_debug_ui() {
    delete_transient(hpt_product_cat_tools_debug_ui_key());
}

function hpt_product_cat_tools_debug_to_pretty_json($value) {
    $json = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '';
}

function hpt_product_cat_tools_build_debug_payload($operation, $status, $message, $extra = array()) {
    return array_merge(array(
        'token'     => hpt_product_cat_tools_make_debug_token(),
        'operation' => (string) $operation,
        'status'    => (string) $status,
        'message'   => (string) $message,
        'time'      => current_time('mysql'),
    ), $extra);
}

function hpt_product_cat_tools_debug_sanitize_request($operation) {
    $operation = (string) $operation;

    if ($operation === 'export') {
        return array(
            'action'                    => sanitize_key($_POST['action'] ?? ''),
            'include_children'          => !empty($_POST['include_children']),
            'export_columns'            => array_values(array_map('sanitize_key', array_slice((array) ($_POST['export_columns'] ?? array()), 0, 30))),
            'export_category_ids_count' => count((array) ($_POST['export_category_ids'] ?? array())),
            'export_category_ids_head'  => array_slice(array_values(array_map('absint', (array) ($_POST['export_category_ids'] ?? array()))), 0, 20),
        );
    }

    if ($operation === 'import') {
        return array(
            'action'    => sanitize_key($_POST['action'] ?? ''),
            'file_name' => sanitize_file_name($_FILES['product_cat_csv']['name'] ?? ''),
            'file_size' => absint($_FILES['product_cat_csv']['size'] ?? 0),
        );
    }

    return array(
        'action' => sanitize_key($_POST['action'] ?? ''),
    );
}

function hpt_product_cat_tools_get_async_settings() {
    return array(
        'ajax_url'                  => admin_url('admin-ajax.php'),
        'nonce'                     => wp_create_nonce('hpt_product_cat_tools_ajax'),
        'export_batch_size'         => max(50, (int) apply_filters('hpt_product_cat_tools_export_batch_size', 300)),
        'export_concurrency'        => max(1, (int) apply_filters('hpt_product_cat_tools_export_concurrency', 4)),
        'import_batch_size'         => max(10, (int) apply_filters('hpt_product_cat_tools_import_batch_size', 80)),
        'import_concurrency'        => max(1, (int) apply_filters('hpt_product_cat_tools_import_concurrency', 2)),
        'import_parent_batch_size'  => max(10, (int) apply_filters('hpt_product_cat_tools_import_parent_batch_size', 120)),
        'import_parent_concurrency' => max(1, (int) apply_filters('hpt_product_cat_tools_import_parent_concurrency', 1)),
    );
}

function hpt_product_cat_tools_verify_ajax_request() {
    if (!hpt_product_cat_tools_user_can_manage()) {
        wp_send_json_error(array(
            'message' => 'Bạn không có quyền thực hiện thao tác này.',
        ), 403);
    }

    check_ajax_referer('hpt_product_cat_tools_ajax', 'nonce');
}

function hpt_product_cat_tools_sanitize_async_token($token) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $token);
}

function hpt_product_cat_tools_export_state_key($token) {
    return 'hpt_pct_export_state_' . hpt_product_cat_tools_sanitize_async_token($token);
}

function hpt_product_cat_tools_set_export_state($token, $state) {
    set_transient(hpt_product_cat_tools_export_state_key($token), $state, 2 * HOUR_IN_SECONDS);
}

function hpt_product_cat_tools_get_export_state($token) {
    $state = get_transient(hpt_product_cat_tools_export_state_key($token));

    return is_array($state) ? $state : array();
}

function hpt_product_cat_tools_clear_export_state($token) {
    delete_transient(hpt_product_cat_tools_export_state_key($token));
}

function hpt_product_cat_tools_register_debug_runtime($operation, $token) {
    static $registered = false;

    if ($registered) {
        return;
    }

    $registered = true;

    register_shutdown_function(function () use ($operation, $token) {
        $error = error_get_last();

        if (!$error) {
            return;
        }

        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);

        if (!in_array((int) $error['type'], $fatal_types, true)) {
            return;
        }

        $debug = hpt_product_cat_tools_build_debug_payload($operation, 'fatal', 'Thao tác bị dừng đột ngột.', array(
            'token'   => $token,
            'type'    => 'FatalError',
            'file'    => wp_normalize_path((string) ($error['file'] ?? '')),
            'line'    => (int) ($error['line'] ?? 0),
            'request' => hpt_product_cat_tools_debug_sanitize_request($operation),
        ));

        hpt_product_cat_tools_set_debug_ui($debug);
        hpt_product_cat_tools_set_notice(array(
            'type'    => 'error',
            'message' => sprintf(
                'Thao tác %s bị dừng đột ngột. Xem mục Chẩn đoán lỗi gần nhất để xử lý.',
                $operation
            ),
            'debug'   => $debug,
        ));
    });
}

function hpt_product_cat_tools_begin_debug($operation) {
    $token = hpt_product_cat_tools_make_debug_token();

    hpt_product_cat_tools_register_debug_runtime($operation, $token);

    set_error_handler(function ($severity, $message, $file, $line) use ($operation, $token) {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    return $token;
}

function hpt_product_cat_tools_end_debug($operation, $token, $status = 'done', $context = array()) {
    restore_error_handler();

    if ($status === 'success') {
        hpt_product_cat_tools_clear_debug_ui();
    }
}

function hpt_product_cat_tools_handle_runtime_exception($operation, Throwable $exception, $token, $context = array()) {
    $debug = hpt_product_cat_tools_build_debug_payload($operation, 'failed', $exception->getMessage(), array(
        'token'     => $token,
        'type'      => get_class($exception),
        'file'      => wp_normalize_path($exception->getFile()),
        'line'      => (int) $exception->getLine(),
        'request'   => hpt_product_cat_tools_debug_sanitize_request($operation),
        'context'   => $context,
        'trace'     => array_slice(
            preg_split('/\r\n|\r|\n/', $exception->getTraceAsString()),
            0,
            12
        ),
    ));

    hpt_product_cat_tools_set_debug_ui($debug);

    hpt_product_cat_tools_set_notice(array(
        'type'    => 'error',
        'message' => sprintf(
            'Thao tác %s thất bại. Xem mục Chẩn đoán lỗi gần nhất để xử lý.',
            $operation
        ),
        'debug'   => $debug,
    ));
}

function hpt_product_cat_tools_export_columns_config() {
    return array(
        'term_id' => array(
            'label'       => 'ID danh mục',
            'description' => 'Khóa chính để update khi import lại.',
            'default'     => true,
            'required'    => true,
        ),
        'parent_id' => array(
            'label'       => 'ID danh mục cha',
            'description' => 'Dùng để dựng lại quan hệ cha-con.',
            'default'     => false,
            'required'    => false,
        ),
        'parent_slug' => array(
            'label'       => 'Slug danh mục cha',
            'description' => 'Fallback khi parent_id khác môi trường.',
            'default'     => false,
            'required'    => false,
        ),
        'parent_name' => array(
            'label'       => 'Tên danh mục cha',
            'description' => 'Fallback cuối khi import.',
            'default'     => false,
            'required'    => false,
        ),
        'name' => array(
            'label'       => 'Tên danh mục',
            'description' => 'Tên hiển thị của term.',
            'default'     => true,
            'required'    => false,
        ),
        'slug' => array(
            'label'       => 'Slug',
            'description' => 'Dùng để fallback khi không có ID.',
            'default'     => false,
            'required'    => false,
        ),
        'description' => array(
            'label'       => 'Mô tả',
            'description' => 'Nội dung description của category.',
            'default'     => false,
            'required'    => false,
        ),
        'display_type' => array(
            'label'       => 'Kiểu hiển thị',
            'description' => 'Meta WooCommerce: products/subcategories/both.',
            'default'     => false,
            'required'    => false,
        ),
        'thumbnail_id' => array(
            'label'       => 'Thumbnail ID',
            'description' => 'Ảnh danh mục theo attachment ID.',
            'default'     => false,
            'required'    => false,
        ),
        'menu_order' => array(
            'label'       => 'Thứ tự',
            'description' => 'Meta order của danh mục.',
            'default'     => false,
            'required'    => false,
        ),
        'count' => array(
            'label'       => 'Số sản phẩm',
            'description' => 'Chỉ để tham chiếu, import không dùng.',
            'default'     => false,
            'required'    => false,
        ),
        'term_meta_json' => array(
            'label'       => 'Meta JSON',
            'description' => 'Xuất thêm toàn bộ term meta còn lại dưới dạng JSON.',
            'default'     => false,
            'required'    => false,
        ),
    );
}

function hpt_product_cat_tools_default_export_columns() {
    $defaults = array();

    foreach (hpt_product_cat_tools_export_columns_config() as $column_key => $column_config) {
        if (!empty($column_config['default'])) {
            $defaults[] = $column_key;
        }
    }

    return $defaults;
}

function hpt_product_cat_tools_get_all_terms_for_export() {
    $terms = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ));

    return (empty($terms) || is_wp_error($terms)) ? array() : $terms;
}

function hpt_product_cat_tools_build_term_tree($terms) {
    $tree = array();

    foreach ((array) $terms as $term) {
        $parent_id = (int) $term->parent;

        if (!isset($tree[$parent_id])) {
            $tree[$parent_id] = array();
        }

        $tree[$parent_id][] = $term;
    }

    return $tree;
}

function hpt_product_cat_tools_get_flat_term_choices($term_tree) {
    $choices = array();
    $visited = array();

    $walk = static function ($parent_id, $depth) use (&$walk, &$choices, &$visited, $term_tree) {
        if (empty($term_tree[$parent_id])) {
            return;
        }

        foreach ($term_tree[$parent_id] as $term) {
            $term_id = (int) $term->term_id;

            if (isset($visited[$term_id])) {
                continue;
            }

            $visited[$term_id] = true;
            $choices[] = array(
                'term'  => $term,
                'depth' => $depth,
            );

            $walk($term_id, $depth + 1);
        }
    };

    $walk(0, 0);

    foreach ($term_tree as $terms) {
        foreach ($terms as $term) {
            $term_id = (int) $term->term_id;

            if (isset($visited[$term_id])) {
                continue;
            }

            $choices[] = array(
                'term'  => $term,
                'depth' => 0,
            );

            $walk($term_id, 1);
        }
    }

    return $choices;
}

function hpt_product_cat_tools_render_term_select_options($flat_choices) {
    foreach ((array) $flat_choices as $choice) {
        $term      = $choice['term'];
        $depth     = max(0, (int) ($choice['depth'] ?? 0));
        $term_id   = (int) $term->term_id;
        $prefix    = $depth > 0 ? str_repeat('-- ', $depth) : '';
        $label     = $prefix . $term->name . '  [#' . $term_id . ']';
        $sub_label = $term->slug ? ' (' . $term->slug . ')' : '';

        echo '<option value="' . esc_attr($term_id) . '">' . esc_html($label . $sub_label) . '</option>';
    }
}

function hpt_product_cat_tools_get_selected_export_columns($raw_columns) {
    $available_columns = hpt_product_cat_tools_export_columns_config();
    $requested_columns = array_values(array_unique(array_map('sanitize_key', (array) $raw_columns)));
    $selected_columns  = array();

    foreach ($requested_columns as $column_key) {
        if (isset($available_columns[$column_key])) {
            $selected_columns[] = $column_key;
        }
    }

    if (empty($selected_columns)) {
        $selected_columns = hpt_product_cat_tools_default_export_columns();
    }

    if (!in_array('term_id', $selected_columns, true)) {
        array_unshift($selected_columns, 'term_id');
    }

    return array_values(array_unique($selected_columns));
}

function hpt_product_cat_tools_get_export_term_ids($raw_term_ids, $include_children = true) {
    $selected_term_ids = array_values(array_unique(array_filter(array_map('absint', (array) $raw_term_ids))));

    if (empty($selected_term_ids)) {
        return array();
    }

    $resolved_term_ids = $selected_term_ids;

    if ($include_children) {
        foreach ($selected_term_ids as $term_id) {
            $children = get_term_children($term_id, 'product_cat');

            if (is_array($children) && !empty($children)) {
                $resolved_term_ids = array_merge($resolved_term_ids, array_map('absint', $children));
            }
        }
    }

    $resolved_term_ids = array_values(array_unique(array_filter($resolved_term_ids)));
    sort($resolved_term_ids, SORT_NUMERIC);

    return $resolved_term_ids;
}

function hpt_product_cat_tools_get_term_meta_json_map($term_ids, $excluded_meta_keys = array()) {
    global $wpdb;

    $term_ids = array_values(array_unique(array_filter(array_map('absint', (array) $term_ids))));

    if (empty($term_ids)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
    $sql          = "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id IN ({$placeholders}) ORDER BY meta_id ASC";
    $meta_rows    = $wpdb->get_results($wpdb->prepare($sql, $term_ids), ARRAY_A);
    $meta_map     = array();

    foreach ((array) $meta_rows as $meta_row) {
        $term_id  = (int) ($meta_row['term_id'] ?? 0);
        $meta_key = (string) ($meta_row['meta_key'] ?? '');

        if (!$term_id || in_array($meta_key, $excluded_meta_keys, true)) {
            continue;
        }

        $meta_value = maybe_unserialize($meta_row['meta_value'] ?? '');

        if (!isset($meta_map[$term_id])) {
            $meta_map[$term_id] = array();
        }

        if (!array_key_exists($meta_key, $meta_map[$term_id])) {
            $meta_map[$term_id][$meta_key] = $meta_value;
            continue;
        }

        if (!is_array($meta_map[$term_id][$meta_key]) || !array_key_exists(0, $meta_map[$term_id][$meta_key])) {
            $meta_map[$term_id][$meta_key] = array($meta_map[$term_id][$meta_key]);
        }

        $meta_map[$term_id][$meta_key][] = $meta_value;
    }

    $encoded_map = array();

    foreach ($meta_map as $term_id => $term_meta) {
        $encoded_map[$term_id] = wp_json_encode($term_meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return $encoded_map;
}

function hpt_product_cat_tools_build_export_query_context($selected_columns, $selected_term_ids = array()) {
    global $wpdb;

    $selected_columns  = array_values(array_unique(array_map('sanitize_key', (array) $selected_columns)));
    $selected_term_ids = array_values(array_unique(array_filter(array_map('absint', (array) $selected_term_ids))));
    $select_sql        = array(
        't.term_id AS term_id',
    );
    $joins_sql         = array();

    if (in_array('parent_id', $selected_columns, true)) {
        $select_sql[] = 'tt.parent AS parent_id';
    }

    if (in_array('name', $selected_columns, true)) {
        $select_sql[] = 't.name AS name';
    }

    if (in_array('slug', $selected_columns, true)) {
        $select_sql[] = 't.slug AS slug';
    }

    if (in_array('description', $selected_columns, true)) {
        $select_sql[] = 'tt.description AS description';
    }

    if (in_array('count', $selected_columns, true)) {
        $select_sql[] = 'tt.count AS count';
    }

    if (in_array('parent_slug', $selected_columns, true) || in_array('parent_name', $selected_columns, true)) {
        $joins_sql['parent_terms'] = "LEFT JOIN {$wpdb->terms} AS parent_t ON parent_t.term_id = tt.parent";

        if (in_array('parent_slug', $selected_columns, true)) {
            $select_sql[] = "COALESCE(parent_t.slug, '') AS parent_slug";
        }

        if (in_array('parent_name', $selected_columns, true)) {
            $select_sql[] = "COALESCE(parent_t.name, '') AS parent_name";
        }
    }

    if (in_array('display_type', $selected_columns, true)) {
        $joins_sql['display_meta'] = "LEFT JOIN {$wpdb->termmeta} AS display_meta ON display_meta.term_id = t.term_id AND display_meta.meta_key = 'display_type'";
        $select_sql[]              = "COALESCE(display_meta.meta_value, '') AS display_type";
    }

    if (in_array('thumbnail_id', $selected_columns, true)) {
        $joins_sql['thumbnail_meta'] = "LEFT JOIN {$wpdb->termmeta} AS thumbnail_meta ON thumbnail_meta.term_id = t.term_id AND thumbnail_meta.meta_key = 'thumbnail_id'";
        $select_sql[]                = "COALESCE(thumbnail_meta.meta_value, '') AS thumbnail_id";
    }

    if (in_array('menu_order', $selected_columns, true)) {
        $joins_sql['order_meta'] = "LEFT JOIN {$wpdb->termmeta} AS order_meta ON order_meta.term_id = t.term_id AND order_meta.meta_key = 'order'";
        $select_sql[]            = "COALESCE(order_meta.meta_value, '') AS menu_order";
    }

    return array(
        'selected_columns'  => $selected_columns,
        'selected_term_ids' => $selected_term_ids,
        'select_sql'        => $select_sql,
        'joins_sql'         => $joins_sql,
    );
}

function hpt_product_cat_tools_fetch_export_rows_batch($context, $batch_size, $offset) {
    global $wpdb;

    $where_sql      = 'tt.taxonomy = %s';
    $prepare_values = array('product_cat');

    if (!empty($context['selected_term_ids'])) {
        $where_sql      .= ' AND t.term_id IN (' . implode(',', array_fill(0, count($context['selected_term_ids']), '%d')) . ')';
        $prepare_values = array_merge($prepare_values, $context['selected_term_ids']);
    }

    $prepare_values[] = (int) $batch_size;
    $prepare_values[] = (int) $offset;

    $sql = "
        SELECT
            " . implode(",\n            ", $context['select_sql']) . "
        FROM {$wpdb->terms} AS t
        INNER JOIN {$wpdb->term_taxonomy} AS tt
            ON tt.term_id = t.term_id
        " . implode("\n        ", $context['joins_sql']) . "
        WHERE {$where_sql}
        ORDER BY t.term_id ASC
        LIMIT %d OFFSET %d
    ";

    $prepared_sql = $wpdb->prepare($sql, $prepare_values);

    if (!is_string($prepared_sql) || $prepared_sql === '') {
        return new WP_Error('hpt_export_prepare_error', 'Không build được câu lệnh SQL export.');
    }

    $rows = $wpdb->get_results($prepared_sql, ARRAY_A);

    if (!empty($wpdb->last_error)) {
        return new WP_Error('hpt_export_sql_error', $wpdb->last_error);
    }

    if (!is_array($rows)) {
        return new WP_Error('hpt_export_rows_error', 'Không lấy được dữ liệu danh mục để export.');
    }

    return $rows;
}

function hpt_product_cat_tools_count_export_rows($context) {
    global $wpdb;

    $where_sql      = 'tt.taxonomy = %s';
    $prepare_values = array('product_cat');

    if (!empty($context['selected_term_ids'])) {
        $where_sql      .= ' AND t.term_id IN (' . implode(',', array_fill(0, count($context['selected_term_ids']), '%d')) . ')';
        $prepare_values = array_merge($prepare_values, $context['selected_term_ids']);
    }

    $sql = "
        SELECT COUNT(*)
        FROM {$wpdb->terms} AS t
        INNER JOIN {$wpdb->term_taxonomy} AS tt
            ON tt.term_id = t.term_id
        WHERE {$where_sql}
    ";

    $prepared_sql = $wpdb->prepare($sql, $prepare_values);

    if (!is_string($prepared_sql) || $prepared_sql === '') {
        return new WP_Error('hpt_export_count_prepare_error', 'Không build được câu lệnh đếm export.');
    }

    $count = $wpdb->get_var($prepared_sql);

    if (!empty($wpdb->last_error)) {
        return new WP_Error('hpt_export_count_sql_error', $wpdb->last_error);
    }

    return max(0, (int) $count);
}

function hpt_product_cat_tools_write_export_rows($output, $rows, $selected_columns) {
    if (empty($rows)) {
        return;
    }

    $term_meta_json_map = in_array('term_meta_json', $selected_columns, true)
        ? hpt_product_cat_tools_get_term_meta_json_map(
            wp_list_pluck($rows, 'term_id'),
            array('display_type', 'thumbnail_id', 'order')
        )
        : array();

    foreach ($rows as $row) {
        $csv_row = array();
        $term_id = (int) ($row['term_id'] ?? 0);

        foreach ($selected_columns as $column_key) {
            if ($column_key === 'term_meta_json') {
                $csv_row[] = (string) ($term_meta_json_map[$term_id] ?? '');
                continue;
            }

            if ($column_key === 'term_id' || $column_key === 'parent_id' || $column_key === 'count') {
                $csv_row[] = (int) ($row[$column_key] ?? 0);
                continue;
            }

            $csv_row[] = (string) ($row[$column_key] ?? '');
        }

        fputcsv($output, $csv_row);
    }
}

function hpt_product_cat_tools_build_export_csv_chunk($rows, $selected_columns, $include_header = false) {
    $stream = fopen('php://temp', 'r+');

    if (!$stream) {
        return '';
    }

    if ($include_header) {
        fputcsv($stream, $selected_columns);
    }

    hpt_product_cat_tools_write_export_rows($stream, $rows, $selected_columns);
    rewind($stream);
    $chunk = stream_get_contents($stream);
    fclose($stream);

    return is_string($chunk) ? $chunk : '';
}

function hpt_ajax_product_cat_export_init() {
    hpt_product_cat_tools_verify_ajax_request();

    try {
        $settings          = hpt_product_cat_tools_get_async_settings();
        $selected_columns  = hpt_product_cat_tools_get_selected_export_columns($_POST['export_columns'] ?? array());
        $selected_term_ids = hpt_product_cat_tools_get_export_term_ids(
            $_POST['export_category_ids'] ?? array(),
            !empty($_POST['include_children'])
        );
        $context           = hpt_product_cat_tools_build_export_query_context($selected_columns, $selected_term_ids);
        $total_rows        = hpt_product_cat_tools_count_export_rows($context);

        if (is_wp_error($total_rows)) {
            throw new RuntimeException($total_rows->get_error_message());
        }

        $job_token = hpt_product_cat_tools_make_debug_token();
        $batch_size = (int) $settings['export_batch_size'];
        $state = array(
            'selected_columns' => $selected_columns,
            'selected_term_ids' => $selected_term_ids,
            'created_at'       => time(),
        );

        hpt_product_cat_tools_set_export_state($job_token, $state);

        wp_send_json_success(array(
            'job_token'     => $job_token,
            'headers'       => $selected_columns,
            'header_csv'    => hpt_product_cat_tools_build_export_csv_chunk(array(), $selected_columns, true),
            'total_rows'    => $total_rows,
            'batch_size'    => $batch_size,
            'total_batches' => $total_rows > 0 ? (int) ceil($total_rows / $batch_size) : 0,
            'concurrency'   => (int) $settings['export_concurrency'],
            'filename'      => 'product-categories-' . gmdate('Ymd-His') . '.csv',
        ));
    } catch (Throwable $exception) {
        wp_send_json_error(array(
            'message' => $exception->getMessage(),
        ), 500);
    }
}
add_action('wp_ajax_hpt_product_cat_export_init', 'hpt_ajax_product_cat_export_init');

function hpt_ajax_product_cat_export_batch() {
    hpt_product_cat_tools_verify_ajax_request();

    try {
        $settings   = hpt_product_cat_tools_get_async_settings();
        $job_token  = hpt_product_cat_tools_sanitize_async_token($_POST['job_token'] ?? '');
        $batch_index = max(0, absint($_POST['batch_index'] ?? 0));
        $state      = hpt_product_cat_tools_get_export_state($job_token);

        if (empty($state['selected_columns'])) {
            throw new RuntimeException('Phiên export không còn hợp lệ. Hãy chạy lại export.');
        }

        $selected_columns  = $state['selected_columns'];
        $selected_term_ids = $state['selected_term_ids'] ?? array();
        $context           = hpt_product_cat_tools_build_export_query_context($selected_columns, $selected_term_ids);
        $batch_size        = (int) $settings['export_batch_size'];
        $offset            = $batch_index * $batch_size;
        $rows              = hpt_product_cat_tools_fetch_export_rows_batch($context, $batch_size, $offset);

        if (is_wp_error($rows)) {
            throw new RuntimeException($rows->get_error_message());
        }

        wp_send_json_success(array(
            'batch_index' => $batch_index,
            'row_count'   => count($rows),
            'chunk'       => hpt_product_cat_tools_build_export_csv_chunk($rows, $selected_columns, false),
        ));
    } catch (Throwable $exception) {
        wp_send_json_error(array(
            'message' => $exception->getMessage(),
        ), 500);
    }
}
add_action('wp_ajax_hpt_product_cat_export_batch', 'hpt_ajax_product_cat_export_batch');

function hpt_ajax_product_cat_export_cleanup() {
    hpt_product_cat_tools_verify_ajax_request();

    $job_token = hpt_product_cat_tools_sanitize_async_token($_POST['job_token'] ?? '');

    if ($job_token !== '') {
        hpt_product_cat_tools_clear_export_state($job_token);
    }

    wp_send_json_success(array(
        'cleared' => true,
    ));
}
add_action('wp_ajax_hpt_product_cat_export_cleanup', 'hpt_ajax_product_cat_export_cleanup');

function hpt_register_product_cat_tools_page() {
    add_submenu_page(
        'edit.php?post_type=product',
        'Import/Export Danh mục',
        'Import/Export Danh mục',
        'manage_woocommerce',
        'hpt-product-cat-tools',
        'hpt_render_product_cat_tools_page'
    );
}
add_action('admin_menu', 'hpt_register_product_cat_tools_page');

function hpt_product_cat_tools_admin_enqueue($hook_suffix) {
    if ($hook_suffix !== 'product_page_hpt-product-cat-tools') {
        return;
    }

    $base_uri = get_stylesheet_directory_uri() . '/assets/vendor/slimselect';
    $assets_uri = get_stylesheet_directory_uri() . '/assets/js';

    wp_enqueue_style(
        'hpt-slimselect',
        $base_uri . '/slimselect.css',
        array(),
        '3.6.1'
    );

    wp_add_inline_style(
        'hpt-slimselect',
        '
        .hpt-export-category-picker .ss-main {
            min-height: 42px;
            border-color: #8c8f94;
            border-radius: 4px;
        }
        .hpt-export-category-picker .ss-content {
            max-height: 360px;
        }
        .hpt-export-category-picker .ss-values {
            gap: 6px;
        }
        .hpt-export-category-picker .ss-value {
            max-width: 100%;
        }
        .hpt-export-category-picker .ss-search input {
            min-height: 36px;
        }
        .hpt-tool-progress {
            display:none;
            margin-top:16px;
            padding:12px;
            border:1px solid #ccd0d4;
            background:#fff;
        }
        .hpt-tool-progress.is-active {
            display:block;
        }
        .hpt-tool-progress__bar {
            width:100%;
            height:10px;
            background:#f0f0f1;
            border-radius:999px;
            overflow:hidden;
            margin:10px 0 8px;
        }
        .hpt-tool-progress__fill {
            width:0;
            height:100%;
            background:#2271b1;
            transition:width .2s ease;
        }
        .hpt-tool-progress__meta {
            color:#50575e;
            font-size:13px;
        }
        .hpt-tool-progress__errors {
            margin-top:12px;
            max-height:180px;
            overflow:auto;
            background:#fff8f8;
            border:1px solid #f0c0c1;
            padding:10px;
        }
        '
    );

    wp_enqueue_script(
        'hpt-slimselect',
        $base_uri . '/slimselect.js',
        array(),
        '3.6.1',
        true
    );

    wp_enqueue_script(
        'hpt-product-cat-tools-admin',
        $assets_uri . '/product-category-tools-admin.js',
        array(),
        '1.0.0',
        true
    );

    wp_add_inline_script(
        'hpt-product-cat-tools-admin',
        'window.HPTProductCatTools = ' . wp_json_encode(hpt_product_cat_tools_get_async_settings()) . ';',
        'before'
    );
}
add_action('admin_enqueue_scripts', 'hpt_product_cat_tools_admin_enqueue');

function hpt_render_product_cat_tools_page() {
    if (!hpt_product_cat_tools_user_can_manage()) {
        wp_die(esc_html__('Bạn không có quyền truy cập trang này.', 'hpt'));
    }

    $notice            = hpt_product_cat_tools_pop_notice();
    $columns           = hpt_product_cat_tools_export_columns_config();
    $default_columns   = hpt_product_cat_tools_default_export_columns();
    $export_terms      = hpt_product_cat_tools_get_all_terms_for_export();
    $export_terms_tree = hpt_product_cat_tools_build_term_tree($export_terms);
    $flat_term_choices = hpt_product_cat_tools_get_flat_term_choices($export_terms_tree);
    $debug_ui          = hpt_product_cat_tools_get_debug_ui();
    ?>
    <div class="wrap">
        <h1>Import/Export Danh mục sản phẩm</h1>
        <p>Tool này chỉ xử lý taxonomy <code>product_cat</code>, export dạng stream để nhẹ RAM và import theo từng dòng để tránh lỗi database.</p>

        <?php if (!empty($notice) && is_array($notice)) : ?>
            <div class="notice notice-<?php echo esc_attr($notice['type'] ?? 'info'); ?> is-dismissible">
                <p><?php echo esc_html($notice['message'] ?? ''); ?></p>
                <?php if (!empty($notice['summary']) && is_array($notice['summary'])) : ?>
                    <p>
                        <?php
                        printf(
                            'Đã xử lý: %d | Tạo mới: %d | Cập nhật: %d | Không đổi: %d | Bỏ qua: %d | Cập nhật cha-con: %d | Lỗi: %d',
                            (int) ($notice['summary']['processed'] ?? 0),
                            (int) ($notice['summary']['created'] ?? 0),
                            (int) ($notice['summary']['updated'] ?? 0),
                            (int) ($notice['summary']['unchanged'] ?? 0),
                            (int) ($notice['summary']['skipped'] ?? 0),
                            (int) ($notice['summary']['parent_updates'] ?? 0),
                            (int) ($notice['summary']['errors_count'] ?? 0)
                        );
                        ?>
                    </p>
                    <?php if (!empty($notice['summary']['errors'])) : ?>
                        <div style="max-height:240px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;">
                            <?php foreach ($notice['summary']['errors'] as $error_text) : ?>
                                <p style="margin:0 0 8px;"><?php echo esc_html($error_text); ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width:960px;">
            <h2>Export CSV</h2>
            <p>Xuất theo batch SQL chỉ đọc, có thể chọn phạm vi danh mục và chọn đúng các cột cần lấy để nhẹ hơn.</p>
            <form id="hpt-product-cat-export-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hpt_export_product_categories'); ?>
                <input type="hidden" name="action" value="hpt_export_product_categories">
                <input type="hidden" name="export_columns[]" value="term_id">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Phạm vi danh mục</th>
                        <td>
                            <p style="margin-top:0;">Không chọn gì thì xuất toàn bộ danh mục sản phẩm.</p>
                            <label style="display:inline-flex;align-items:center;gap:6px;margin:0 0 12px;">
                                <input type="checkbox" name="include_children" value="1" checked>
                                <span>Xuất kèm toàn bộ danh mục con của các danh mục đã chọn</span>
                            </label>
                            <div class="hpt-export-category-picker" style="max-width:680px;">
                                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 10px;">
                                    <button type="button" class="button button-secondary" id="hpt-export-category-select-all">Chọn tất cả</button>
                                    <button type="button" class="button button-link-delete" id="hpt-export-category-clear">Bỏ chọn hết</button>
                                    <span id="hpt-export-category-count" style="color:#666;"></span>
                                </div>
                                <select
                                    id="hpt-export-category-select"
                                    name="export_category_ids[]"
                                    multiple
                                    style="width:100%;max-width:none;"
                                >
                                    <?php
                                    if (!empty($flat_term_choices)) {
                                        hpt_product_cat_tools_render_term_select_options($flat_term_choices);
                                    } else {
                                        echo '<option value="" disabled>Chưa có danh mục sản phẩm nào.</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div style="margin-top:8px;color:#666;">
                                Dùng ô tìm kiếm ngay trong SlimSelect để lọc nhanh theo tên, slug hoặc ID danh mục.
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Cột xuất</th>
                        <td>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px 16px;">
                                <?php foreach ($columns as $column_key => $column_config) : ?>
                                    <?php $is_required = !empty($column_config['required']); ?>
                                    <label style="display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border:1px solid #e2e4e7;background:#fff;">
                                        <input
                                            type="checkbox"
                                            name="<?php echo $is_required ? '' : 'export_columns[]'; ?>"
                                            value="<?php echo esc_attr($column_key); ?>"
                                            <?php checked(in_array($column_key, $default_columns, true) || $is_required); ?>
                                            <?php disabled($is_required); ?>
                                        >
                                        <span>
                                            <strong><?php echo esc_html($column_config['label']); ?></strong><br>
                                            <span style="color:#666;"><?php echo esc_html($column_config['description']); ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Tải CSV danh mục', 'primary', 'submit', false); ?>
            </form>
            <div id="hpt-export-progress" class="hpt-tool-progress" aria-live="polite">
                <strong id="hpt-export-progress-title">Đang chuẩn bị export...</strong>
                <div class="hpt-tool-progress__bar"><div class="hpt-tool-progress__fill"></div></div>
                <div class="hpt-tool-progress__meta"></div>
                <div class="hpt-tool-progress__errors" hidden></div>
            </div>
        </div>

        <div class="card" style="max-width:960px;">
            <h2>Import CSV</h2>
            <p>Ưu tiên cập nhật theo <code>term_id</code>. Nếu không có <code>term_id</code> thì hệ thống thử khớp theo <code>slug</code>. Nếu vẫn không có thì tạo mới.</p>
            <p>Quan hệ cha-con được xử lý 2 bước để tránh lỗi khi parent xuất hiện sau child trong file.</p>
            <p>Chỉ các cột thực sự có mặt trong file CSV mới được cập nhật. Nếu không muốn đụng vào cấu trúc cha-con, hãy bỏ các cột <code>parent_id</code>, <code>parent_slug</code>, <code>parent_name</code> khỏi file import.</p>
            <form id="hpt-product-cat-import-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('hpt_import_product_categories'); ?>
                <input type="hidden" name="action" value="hpt_import_product_categories">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="hpt_product_cat_csv">File CSV</label></th>
                        <td>
                            <input type="file" name="product_cat_csv" id="hpt_product_cat_csv" accept=".csv,.txt" required>
                            <p class="description">Khuyến nghị dùng file export từ tool này để import ngược lại.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Import CSV danh mục', 'secondary', 'submit', false); ?>
            </form>
            <div id="hpt-import-progress" class="hpt-tool-progress" aria-live="polite">
                <strong id="hpt-import-progress-title">Đang chuẩn bị import...</strong>
                <div class="hpt-tool-progress__bar"><div class="hpt-tool-progress__fill"></div></div>
                <div class="hpt-tool-progress__meta"></div>
                <div class="hpt-tool-progress__errors" hidden></div>
            </div>
        </div>

        <div class="card" style="max-width:960px;">
            <h2>Map cột chuẩn</h2>
            <p>Header chuẩn nên dùng đúng các cột sau để import ổn định:</p>
            <code style="display:block;padding:12px;white-space:normal;"><?php echo esc_html(implode(', ', array_keys($columns))); ?></code>
            <p>Các alias phổ biến như <code>id</code>, <code>category_id</code>, <code>ten_danh_muc</code>, <code>danh_muc_cha_id</code>, <code>anh_id</code>, <code>thu_tu</code> cũng được tự map.</p>
            <p><code>term_meta_json</code> là JSON object tùy chọn để cập nhật thêm term meta khác mà không phải sửa code.</p>
        </div>

        <?php if (!empty($debug_ui) && is_array($debug_ui)) : ?>
            <div style="max-width:960px;margin-top:16px;border:1px solid #d63638;border-left:4px solid #d63638;background:#fcf0f1;padding:14px 16px;border-radius:4px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                    <div style="min-width:0;">
                        <h2 style="margin:0 0 8px;font-size:16px;line-height:1.35;color:#8a2424;">Chẩn đoán lỗi gần nhất</h2>
                        <p style="margin:0 0 8px;color:#1d2327;">
                            <strong><?php echo esc_html($debug_ui['message'] ?? 'Đã xảy ra lỗi không xác định.'); ?></strong>
                        </p>
                        <p style="margin:0;color:#50575e;line-height:1.5;">
                            <?php echo esc_html($debug_ui['operation'] ?? ''); ?>
                            <?php if (!empty($debug_ui['type'])) : ?>
                                <span> | <?php echo esc_html($debug_ui['type']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($debug_ui['file'])) : ?>
                                <span> | <code><?php echo esc_html(wp_basename($debug_ui['file'])); ?></code><?php if (!empty($debug_ui['line'])) : ?>:<?php echo (int) $debug_ui['line']; ?><?php endif; ?></span>
                            <?php endif; ?>
                            <?php if (!empty($debug_ui['token'])) : ?>
                                <span> | Mã: <code><?php echo esc_html($debug_ui['token']); ?></code></span>
                            <?php endif; ?>
                            <?php if (!empty($debug_ui['time'])) : ?>
                                <span> | <?php echo esc_html($debug_ui['time']); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <?php if (!empty($debug_ui['request']) || !empty($debug_ui['context']) || !empty($debug_ui['trace'])) : ?>
                    <details style="margin-top:12px;">
                        <summary style="cursor:pointer;font-weight:600;color:#8a2424;">Xem chi tiết kỹ thuật</summary>

                        <?php if (!empty($debug_ui['request'])) : ?>
                            <p style="margin:12px 0 6px;"><strong>Request</strong></p>
                            <pre style="max-height:220px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;white-space:pre-wrap;"><?php echo esc_html(hpt_product_cat_tools_debug_to_pretty_json($debug_ui['request'])); ?></pre>
                        <?php endif; ?>

                        <?php if (!empty($debug_ui['context'])) : ?>
                            <p style="margin:12px 0 6px;"><strong>Ngữ cảnh</strong></p>
                            <pre style="max-height:220px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;white-space:pre-wrap;"><?php echo esc_html(hpt_product_cat_tools_debug_to_pretty_json($debug_ui['context'])); ?></pre>
                        <?php endif; ?>

                        <?php if (!empty($debug_ui['trace'])) : ?>
                            <p style="margin:12px 0 6px;"><strong>Trace rút gọn</strong></p>
                            <pre style="max-height:260px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;white-space:pre-wrap;"><?php echo esc_html(implode(PHP_EOL, (array) $debug_ui['trace'])); ?></pre>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const selectEl = document.getElementById('hpt-export-category-select');
            const selectAllBtn = document.getElementById('hpt-export-category-select-all');
            const clearBtn = document.getElementById('hpt-export-category-clear');
            const countEl = document.getElementById('hpt-export-category-count');

            if (!selectEl || !countEl) {
                return;
            }

            const options = Array.from(selectEl.options);
            let slim = null;

            function updateCount() {
                const selectedCount = options.filter(function (option) {
                    return option.selected;
                }).length;

                countEl.textContent = selectedCount > 0
                    ? selectedCount + ' danh mục đã chọn'
                    : 'Chưa chọn danh mục nào, hệ thống sẽ xuất toàn bộ.';
            }

            if (typeof SlimSelect !== 'undefined') {
                slim = new SlimSelect({
                    select: selectEl,
                    settings: {
                        placeholderText: 'Chọn danh mục cần xuất',
                        searchPlaceholder: 'Tìm theo tên, slug hoặc ID...',
                        searchText: 'Không tìm thấy danh mục phù hợp',
                        closeOnSelect: false,
                        keepOrder: true,
                        hideSelected: false,
                        showSearch: true,
                        maxValuesShown: 1,
                        maxValuesMessage: '{number} danh mục đã chọn'
                    },
                    events: {
                        afterChange: function () {
                            updateCount();
                        }
                    }
                });
            }

            if (selectAllBtn) {
                selectAllBtn.addEventListener('click', function (event) {
                    event.preventDefault();

                    const allValues = options
                        .filter(function (option) {
                            return option.value !== '';
                        })
                        .map(function (option) {
                            return option.value;
                        });

                    options.forEach(function (option) {
                        option.selected = option.value !== '';
                    });

                    if (slim) {
                        slim.setSelected(allValues);
                    }

                    updateCount();
                });
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', function (event) {
                    event.preventDefault();

                    options.forEach(function (option) {
                        option.selected = false;
                    });

                    if (slim) {
                        slim.setSelected([]);
                    }

                    updateCount();
                });
            }

            selectEl.addEventListener('change', updateCount);

            updateCount();
        });
    </script>
    <?php
}

function hpt_export_product_categories() {
    if (!hpt_product_cat_tools_user_can_manage()) {
        wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'hpt'));
    }

    check_admin_referer('hpt_export_product_categories');

    ignore_user_abort(true);

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    $debug_token = hpt_product_cat_tools_begin_debug('export');
    $debug_context = array();

    try {
        $selected_columns  = hpt_product_cat_tools_get_selected_export_columns($_POST['export_columns'] ?? array());
        $selected_term_ids = hpt_product_cat_tools_get_export_term_ids(
            $_POST['export_category_ids'] ?? array(),
            !empty($_POST['include_children'])
        );
        $context          = hpt_product_cat_tools_build_export_query_context($selected_columns, $selected_term_ids);
        $headers          = $selected_columns;
        $debug_context    = array(
            'selected_columns'         => $selected_columns,
            'selected_term_ids_count'  => count($selected_term_ids),
            'selected_term_ids_sample' => array_slice($selected_term_ids, 0, 20),
        );

        $batch_size = 1000;
        $offset     = 0;
        $first_rows = hpt_product_cat_tools_fetch_export_rows_batch($context, $batch_size, $offset);

        if (is_wp_error($first_rows)) {
            throw new RuntimeException($first_rows->get_error_message());
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        @ini_set('zlib.output_compression', 'Off');

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        nocache_headers();

        $filename = 'product-categories-' . gmdate('Ymd-His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        if (!$output) {
            throw new RuntimeException('Không thể mở luồng xuất CSV.');
        }

        echo "\xEF\xBB\xBF";
        fputcsv($output, $headers);
        hpt_product_cat_tools_write_export_rows($output, $first_rows, $selected_columns);
        $offset += $batch_size;
        fflush($output);

        do {
            $rows = hpt_product_cat_tools_fetch_export_rows_batch($context, $batch_size, $offset);

            if (is_wp_error($rows)) {
                throw new RuntimeException($rows->get_error_message());
            }

            if (empty($rows)) {
                break;
            }

            hpt_product_cat_tools_write_export_rows($output, $rows, $selected_columns);
            $offset += $batch_size;
            fflush($output);
        } while (is_array($rows) && count($rows) === $batch_size);

        fclose($output);

        hpt_product_cat_tools_end_debug('export', $debug_token, 'success', array(
            'rows_first_batch' => count($first_rows),
        ));
        exit;
    } catch (Throwable $exception) {
        hpt_product_cat_tools_handle_runtime_exception('export', $exception, $debug_token, $debug_context);
        hpt_product_cat_tools_end_debug('export', $debug_token, 'failed');

        if (!headers_sent()) {
            wp_safe_redirect(hpt_product_cat_tools_get_page_url());
            exit;
        }

        wp_die(
            'Export thất bại. Mã tham chiếu: ' . esc_html($debug_token) . '. Hãy quay lại trang tool để xem mục Chẩn đoán lỗi gần nhất.',
            'Export Error',
            array('response' => 500)
        );
    }
}
add_action('admin_post_hpt_export_product_categories', 'hpt_export_product_categories');

function hpt_product_cat_tools_detect_delimiter($file_path) {
    $line = '';
    $handle = @fopen($file_path, 'r');

    if ($handle) {
        $line = (string) fgets($handle);
        fclose($handle);
    }

    $delimiters = array(',', ';', "\t", '|');
    $scores     = array();

    foreach ($delimiters as $delimiter) {
        $scores[$delimiter] = substr_count($line, $delimiter);
    }

    arsort($scores);
    $delimiter = key($scores);

    return $delimiter ?: ',';
}

function hpt_product_cat_tools_normalize_cell($value) {
    if (!is_string($value)) {
        return '';
    }

    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

    if (function_exists('mb_check_encoding') && function_exists('mb_convert_encoding') && !mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1');
    }

    return trim($value);
}

function hpt_product_cat_tools_header_aliases() {
    return array(
        'term_id'        => array('term_id', 'id', 'category_id', 'product_cat_id', 'danh_muc_id'),
        'parent_id'      => array('parent_id', 'parent', 'parent_term_id', 'danh_muc_cha_id'),
        'parent_slug'    => array('parent_slug', 'parent_category_slug', 'danh_muc_cha_slug'),
        'parent_name'    => array('parent_name', 'parent_category_name', 'danh_muc_cha', 'danh_muc_cha_ten'),
        'name'           => array('name', 'category_name', 'ten', 'ten_danh_muc'),
        'slug'           => array('slug', 'category_slug', 'duong_dan_tinh'),
        'description'    => array('description', 'mo_ta', 'category_description'),
        'display_type'   => array('display_type', 'display', 'catalog_display', 'hien_thi'),
        'thumbnail_id'   => array('thumbnail_id', 'image_id', 'thumbnail', 'anh_id', 'thumbnail_attachment_id'),
        'menu_order'     => array('menu_order', 'order', 'sort_order', 'thu_tu'),
        'term_meta_json' => array('term_meta_json', 'meta_json', 'extra_meta_json'),
    );
}

function hpt_product_cat_tools_normalize_header($header) {
    $header = hpt_product_cat_tools_normalize_cell($header);
    $header = remove_accents($header);
    $header = strtolower($header);
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    $header = trim($header, '_');

    foreach (hpt_product_cat_tools_header_aliases() as $canonical => $aliases) {
        if (in_array($header, $aliases, true)) {
            return $canonical;
        }
    }

    return $header;
}

function hpt_product_cat_tools_parse_header_row($header_row) {
    $mapped = array();

    foreach ((array) $header_row as $index => $column_name) {
        $mapped[$index] = hpt_product_cat_tools_normalize_header((string) $column_name);
    }

    return $mapped;
}

function hpt_product_cat_tools_row_from_csv($header_map, $row) {
    $data = array();

    foreach ((array) $header_map as $index => $field) {
        if ($field === '') {
            continue;
        }

        $data[$field] = hpt_product_cat_tools_normalize_cell($row[$index] ?? '');
    }

    return $data;
}

function hpt_product_cat_tools_has_available_field($available_fields, $field) {
    return in_array((string) $field, (array) $available_fields, true);
}

function hpt_product_cat_tools_has_any_available_fields($available_fields, $fields) {
    foreach ((array) $fields as $field) {
        if (hpt_product_cat_tools_has_available_field($available_fields, $field)) {
            return true;
        }
    }

    return false;
}

function hpt_product_cat_tools_resolve_parent_request($row, $available_fields = array()) {
    $has_parent_fields = hpt_product_cat_tools_has_any_available_fields($available_fields, array('parent_id', 'parent_slug', 'parent_name'));

    if (!$has_parent_fields) {
        return array('requested' => false, 'resolved' => false, 'parent_id' => 0);
    }

    $parent_id   = absint($row['parent_id'] ?? 0);
    $parent_slug = sanitize_title($row['parent_slug'] ?? '');
    $parent_name = sanitize_text_field($row['parent_name'] ?? '');

    if ($parent_id <= 0 && $parent_slug === '' && $parent_name === '') {
        return array('requested' => true, 'resolved' => true, 'parent_id' => 0);
    }

    if ($parent_id > 0) {
        $parent_term = get_term($parent_id, 'product_cat');

        if ($parent_term && !is_wp_error($parent_term)) {
            return array('requested' => true, 'resolved' => true, 'parent_id' => (int) $parent_term->term_id);
        }

        return array('requested' => true, 'resolved' => false, 'parent_id' => 0);
    }

    if ($parent_slug !== '') {
        $parent_term = get_term_by('slug', $parent_slug, 'product_cat');

        if ($parent_term && !is_wp_error($parent_term)) {
            return array('requested' => true, 'resolved' => true, 'parent_id' => (int) $parent_term->term_id);
        }

        return array('requested' => true, 'resolved' => false, 'parent_id' => 0);
    }

    if ($parent_name !== '') {
        $parent_term = get_term_by('name', $parent_name, 'product_cat');

        if ($parent_term && !is_wp_error($parent_term)) {
            return array('requested' => true, 'resolved' => true, 'parent_id' => (int) $parent_term->term_id);
        }

        return array('requested' => true, 'resolved' => false, 'parent_id' => 0);
    }

    return array('requested' => true, 'resolved' => false, 'parent_id' => 0);
}

function hpt_product_cat_tools_push_error(&$summary, $message) {
    $summary['errors_count']++;

    if (count($summary['errors']) < 80) {
        $summary['errors'][] = $message;
    }
}

function hpt_product_cat_tools_values_equal($left, $right) {
    return wp_json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        === wp_json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function hpt_product_cat_tools_apply_term_meta($term_id, $row, $available_fields, &$summary, $row_number) {
    $changed = false;

    if (in_array('display_type', $available_fields, true)) {
        $display_type = sanitize_key($row['display_type'] ?? '');
        $allowed      = array('', 'products', 'subcategories', 'both');

        if (!in_array($display_type, $allowed, true)) {
            $display_type = '';
        }

        $current_display_type = (string) get_term_meta($term_id, 'display_type', true);

        if ($display_type === '') {
            if ($current_display_type !== '') {
                delete_term_meta($term_id, 'display_type');
                $changed = true;
            }
        } elseif ($current_display_type !== $display_type) {
            update_term_meta($term_id, 'display_type', $display_type);
            $changed = true;
        }
    }

    if (in_array('thumbnail_id', $available_fields, true)) {
        $thumbnail_id = absint($row['thumbnail_id'] ?? 0);
        $current_thumbnail_id = absint(get_term_meta($term_id, 'thumbnail_id', true));

        if ($thumbnail_id > 0 && $current_thumbnail_id !== $thumbnail_id) {
            update_term_meta($term_id, 'thumbnail_id', $thumbnail_id);
            $changed = true;
        } elseif ($thumbnail_id <= 0 && $current_thumbnail_id > 0) {
            delete_term_meta($term_id, 'thumbnail_id');
            $changed = true;
        }
    }

    if (in_array('menu_order', $available_fields, true)) {
        $menu_order = (string) intval($row['menu_order'] ?? 0);
        $current_menu_order = get_term_meta($term_id, 'order', true);
        $current_menu_order = $current_menu_order === '' ? '' : (string) $current_menu_order;

        if ($menu_order !== '0') {
            if ($current_menu_order !== $menu_order) {
                update_term_meta($term_id, 'order', $menu_order);
                $changed = true;
            }
        } elseif ($current_menu_order !== '' && $current_menu_order !== '0') {
            delete_term_meta($term_id, 'order');
            $changed = true;
        }
    }

    if (in_array('term_meta_json', $available_fields, true) && !empty($row['term_meta_json'])) {
        $meta_payload = json_decode(wp_unslash($row['term_meta_json']), true);

        if (!is_array($meta_payload)) {
            hpt_product_cat_tools_push_error($summary, 'Dòng ' . $row_number . ': term_meta_json không phải JSON hợp lệ.');
            return $changed;
        }

        foreach ($meta_payload as $meta_key => $meta_value) {
            $meta_key = sanitize_key($meta_key);

            if ($meta_key === '') {
                continue;
            }

            $current_meta_value = get_term_meta($term_id, $meta_key, true);

            if (!hpt_product_cat_tools_values_equal($current_meta_value, $meta_value)) {
                update_term_meta($term_id, $meta_key, $meta_value);
                $changed = true;
            }
        }
    }

    return $changed;
}

function hpt_product_cat_tools_import_row($row, $available_fields, $row_number, &$summary, &$pending_parents, $allow_update = true) {
    $requested_term_id = absint($row['term_id'] ?? 0);
    $has_name_field    = hpt_product_cat_tools_has_available_field($available_fields, 'name');
    $has_slug_field    = hpt_product_cat_tools_has_available_field($available_fields, 'slug');
    $has_description   = hpt_product_cat_tools_has_available_field($available_fields, 'description');
    $name              = $has_name_field ? sanitize_text_field($row['name'] ?? '') : '';
    $slug              = $has_slug_field ? sanitize_title($row['slug'] ?? '') : '';
    $description       = $has_description ? wp_kses_post($row['description'] ?? '') : '';
    $existing_term     = null;

    if ($requested_term_id > 0) {
        $existing_term = get_term($requested_term_id, 'product_cat');

        if (!$existing_term || is_wp_error($existing_term)) {
            $existing_term = null;
        }
    }

    if (!$existing_term && $has_slug_field && $slug !== '') {
        $slug_match = get_term_by('slug', $slug, 'product_cat');

        if ($slug_match && !is_wp_error($slug_match)) {
            $existing_term = $slug_match;
        }
    }

    if (!$existing_term && $name === '' && $slug === '') {
        $summary['skipped']++;
        hpt_product_cat_tools_push_error($summary, 'Dòng ' . $row_number . ': thiếu term_id, name và slug nên bị bỏ qua.');
        return;
    }

    $parent_resolution = hpt_product_cat_tools_resolve_parent_request($row, $available_fields);
    $term_args         = array();
    $term_changed      = false;
    $meta_changed      = false;
    $has_pending_parent = false;

    if ($has_description && (!$existing_term || (string) $existing_term->description !== $description)) {
        $term_args['description'] = $description;
    }

    if ($has_slug_field && $slug !== '' && (!$existing_term || (string) $existing_term->slug !== $slug)) {
        $term_args['slug'] = $slug;
    }

    if ($existing_term && $parent_resolution['requested'] && $parent_resolution['resolved'] && (int) $existing_term->parent !== (int) $parent_resolution['parent_id']) {
        $term_args['parent'] = (int) $parent_resolution['parent_id'];
    } elseif (!$existing_term && $parent_resolution['requested'] && $parent_resolution['resolved']) {
        $term_args['parent'] = (int) $parent_resolution['parent_id'];
    }

    if ($existing_term) {
        if (!$allow_update) {
            $summary['skipped']++;
            return;
        }
        $target_term_id = (int) $existing_term->term_id;
        $slug_is_changed = $has_slug_field && $slug !== '' && (string) $existing_term->slug !== $slug;
        $conflict_term  = $slug_is_changed ? get_term_by('slug', $slug, 'product_cat') : null;

        if ($conflict_term && !is_wp_error($conflict_term) && (int) $conflict_term->term_id !== $target_term_id) {
            $summary['skipped']++;
            hpt_product_cat_tools_push_error($summary, 'Dòng ' . $row_number . ': slug "' . $slug . '" đang thuộc term_id ' . (int) $conflict_term->term_id . '.');
            return;
        }

        if ($has_name_field && $name !== '' && (string) $existing_term->name !== $name) {
            $term_args['name'] = $name;
        }

        if (!empty($term_args)) {
            $updated_term = wp_update_term($target_term_id, 'product_cat', $term_args);

            if (is_wp_error($updated_term)) {
                $summary['skipped']++;
                hpt_product_cat_tools_push_error($summary, 'Dòng ' . $row_number . ': ' . $updated_term->get_error_message());
                return;
            }

            $term_changed = true;
        }
    } else {
        if (!$has_name_field || $name === '') {
            $summary['skipped']++;
            hpt_product_cat_tools_push_error($summary, 'Dòng ' . $row_number . ': tạo mới bắt buộc phải có cột name và dữ liệu name hợp lệ.');
            return;
        }

        if ($parent_resolution['requested'] && !$parent_resolution['resolved']) {
            $term_args['parent'] = 0;
        }

        $created_term = wp_insert_term($name, 'product_cat', $term_args);

        if (is_wp_error($created_term)) {
            $summary['skipped']++;
            hpt_product_cat_tools_push_error($summary, 'Dòng ' . $row_number . ': ' . $created_term->get_error_message());
            return;
        }

        $target_term_id = (int) $created_term['term_id'];
        $summary['created']++;
        $term_changed = true;
    }

    $meta_changed = hpt_product_cat_tools_apply_term_meta($target_term_id, $row, $available_fields, $summary, $row_number);

    if ($parent_resolution['requested'] && !$parent_resolution['resolved']) {
        $pending_parents[] = array(
            'term_id'          => $target_term_id,
            'row_number'       => $row_number,
            'row'              => $row,
            'available_fields' => $available_fields,
        );
        $has_pending_parent = true;
    }

    if ($existing_term) {
        if ($term_changed || $meta_changed || $has_pending_parent) {
            $summary['updated']++;
        } else {
            $summary['unchanged']++;
        }
    }
}

function hpt_product_cat_tools_resolve_pending_parents($pending_parents, &$summary) {
    if (empty($pending_parents)) {
        return;
    }

    foreach ($pending_parents as $pending_parent) {
        $term_id           = absint($pending_parent['term_id'] ?? 0);
        $row_number        = absint($pending_parent['row_number'] ?? 0);
        $parent_resolution = hpt_product_cat_tools_resolve_parent_request(
            $pending_parent['row'] ?? array(),
            $pending_parent['available_fields'] ?? array()
        );

        if (!$term_id) {
            continue;
        }

        if (!$parent_resolution['resolved']) {
            hpt_product_cat_tools_push_error($summary, 'Dòng ' . $row_number . ': không resolve được danh mục cha sau khi import xong.');
            continue;
        }

        if ((int) $parent_resolution['parent_id'] === $term_id) {
            hpt_product_cat_tools_push_error($summary, 'Dòng ' . $row_number . ': parent_id trùng với chính term_id ' . $term_id . '.');
            continue;
        }

        $current_term = get_term($term_id, 'product_cat');

        if ($current_term && !is_wp_error($current_term) && (int) $current_term->parent === (int) $parent_resolution['parent_id']) {
            continue;
        }

        $updated_term = wp_update_term($term_id, 'product_cat', array(
            'parent' => (int) $parent_resolution['parent_id'],
        ));

        if (is_wp_error($updated_term)) {
            hpt_product_cat_tools_push_error($summary, 'Dòng ' . $row_number . ': ' . $updated_term->get_error_message());
            continue;
        }

        $summary['parent_updates']++;
    }
}

function hpt_ajax_product_cat_import_init() {
    hpt_product_cat_tools_verify_ajax_request();

    try {
        $settings   = hpt_product_cat_tools_get_async_settings();
        $header_row = json_decode(wp_unslash($_POST['header_row'] ?? '[]'), true);

        if (!is_array($header_row) || empty($header_row)) {
            throw new RuntimeException('File CSV không có header hợp lệ.');
        }

        $header_map       = hpt_product_cat_tools_parse_header_row($header_row);
        $available_fields = array_values(array_unique(array_filter($header_map)));

        if (empty($available_fields)) {
            throw new RuntimeException('Không nhận diện được cột hợp lệ trong file CSV.');
        }

        wp_send_json_success(array(
            'header_map'               => $header_map,
            'available_fields'         => $available_fields,
            'import_batch_size'        => (int) $settings['import_batch_size'],
            'import_concurrency'       => (int) $settings['import_concurrency'],
            'import_parent_batch_size' => (int) $settings['import_parent_batch_size'],
            'import_parent_concurrency'=> (int) $settings['import_parent_concurrency'],
        ));
    } catch (Throwable $exception) {
        wp_send_json_error(array(
            'message' => $exception->getMessage(),
        ), 500);
    }
}
add_action('wp_ajax_hpt_product_cat_import_init', 'hpt_ajax_product_cat_import_init');

function hpt_ajax_product_cat_import_batch() {
    hpt_product_cat_tools_verify_ajax_request();

    try {
        $header_map       = json_decode(wp_unslash($_POST['header_map'] ?? '[]'), true);
        $available_fields = json_decode(wp_unslash($_POST['available_fields'] ?? '[]'), true);
        $rows             = json_decode(wp_unslash($_POST['rows'] ?? '[]'), true);

        if (!is_array($header_map) || empty($header_map)) {
            throw new RuntimeException('Header map không hợp lệ.');
        }

        if (!is_array($available_fields) || empty($available_fields)) {
            throw new RuntimeException('Danh sách cột import không hợp lệ.');
        }

        if (!is_array($rows) || empty($rows)) {
            throw new RuntimeException('Batch import không có dữ liệu.');
        }

        $summary = array(
            'processed'      => 0,
            'created'        => 0,
            'updated'        => 0,
            'unchanged'      => 0,
            'skipped'        => 0,
            'parent_updates' => 0,
            'errors_count'   => 0,
            'errors'         => array(),
        );
        $pending_parents = array();

        wp_defer_term_counting(true);

        try {
            foreach ($rows as $row_item) {
                $row_number = absint($row_item['row_number'] ?? 0);
                $cells      = $row_item['cells'] ?? array();

                if (!is_array($cells)) {
                    continue;
                }

                $row = hpt_product_cat_tools_row_from_csv($header_map, $cells);

                if (empty(array_filter($row, static function ($value) {
                    return $value !== '';
                }))) {
                    continue;
                }

                $summary['processed']++;
                hpt_product_cat_tools_import_row($row, $available_fields, $row_number, $summary, $pending_parents);
            }
        } finally {
            wp_defer_term_counting(false);
        }

        wp_send_json_success(array(
            'summary'         => $summary,
            'pending_parents' => $pending_parents,
        ));
    } catch (Throwable $exception) {
        wp_send_json_error(array(
            'message' => $exception->getMessage(),
        ), 500);
    }
}
add_action('wp_ajax_hpt_product_cat_import_batch', 'hpt_ajax_product_cat_import_batch');

function hpt_ajax_product_cat_import_resolve_parents() {
    hpt_product_cat_tools_verify_ajax_request();

    try {
        $items = json_decode(wp_unslash($_POST['items'] ?? '[]'), true);

        if (!is_array($items) || empty($items)) {
            wp_send_json_success(array(
                'summary' => array(
                    'processed'      => 0,
                    'created'        => 0,
                    'updated'        => 0,
                    'unchanged'      => 0,
                    'skipped'        => 0,
                    'parent_updates' => 0,
                    'errors_count'   => 0,
                    'errors'         => array(),
                ),
            ));
        }

        $summary = array(
            'processed'      => 0,
            'created'        => 0,
            'updated'        => 0,
            'unchanged'      => 0,
            'skipped'        => 0,
            'parent_updates' => 0,
            'errors_count'   => 0,
            'errors'         => array(),
        );

        hpt_product_cat_tools_resolve_pending_parents($items, $summary);

        wp_send_json_success(array(
            'summary' => $summary,
        ));
    } catch (Throwable $exception) {
        wp_send_json_error(array(
            'message' => $exception->getMessage(),
        ), 500);
    }
}
add_action('wp_ajax_hpt_product_cat_import_resolve_parents', 'hpt_ajax_product_cat_import_resolve_parents');

function hpt_import_product_categories() {
    if (!hpt_product_cat_tools_user_can_manage()) {
        wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'hpt'));
    }

    check_admin_referer('hpt_import_product_categories');

    if (empty($_FILES['product_cat_csv']['tmp_name']) || !is_uploaded_file($_FILES['product_cat_csv']['tmp_name'])) {
        hpt_product_cat_tools_set_notice(array(
            'type'    => 'error',
            'message' => 'Chưa có file CSV hợp lệ.',
        ));

        wp_safe_redirect(hpt_product_cat_tools_get_page_url());
        exit;
    }

    ignore_user_abort(true);

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    $debug_token = hpt_product_cat_tools_begin_debug('import');
    $debug_context = array();

    try {
        $file_path = $_FILES['product_cat_csv']['tmp_name'];
        $delimiter = hpt_product_cat_tools_detect_delimiter($file_path);
        $csv       = new SplFileObject($file_path, 'r');

        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $csv->setCsvControl($delimiter);

        $header_row = $csv->fgetcsv();

        if (empty($header_row) || (count($header_row) === 1 && $header_row[0] === null)) {
            throw new RuntimeException('File CSV không có header hợp lệ.');
        }

        $header_map       = hpt_product_cat_tools_parse_header_row($header_row);
        $available_fields = array_values(array_unique(array_filter($header_map)));
        $summary          = array(
            'processed'      => 0,
            'created'        => 0,
            'updated'        => 0,
            'unchanged'      => 0,
            'skipped'        => 0,
            'parent_updates' => 0,
            'errors_count'   => 0,
            'errors'         => array(),
        );
        $pending_parents  = array();
        $row_number       = 1;
        $debug_context    = array(
            'delimiter'        => $delimiter,
            'available_fields' => $available_fields,
            'row_number'       => $row_number,
            'processed'        => 0,
        );

        wp_suspend_cache_invalidation(true);
        wp_defer_term_counting(true);

        try {
            while (!$csv->eof()) {
                $raw_row = $csv->fgetcsv();
                $row_number++;

                if ($raw_row === false || $raw_row === array(null) || $raw_row === null) {
                    continue;
                }

                $row = hpt_product_cat_tools_row_from_csv($header_map, $raw_row);

                if (empty(array_filter($row, static function ($value) {
                    return $value !== '';
                }))) {
                    continue;
                }

                $summary['processed']++;
                $debug_context['row_number'] = $row_number;
                $debug_context['processed']  = $summary['processed'];
                hpt_product_cat_tools_import_row($row, $available_fields, $row_number, $summary, $pending_parents);
            }

            hpt_product_cat_tools_resolve_pending_parents($pending_parents, $summary);
        } finally {
            wp_defer_term_counting(false);
            wp_suspend_cache_invalidation(false);
            clean_taxonomy_cache('product_cat');
        }

        $notice_type = $summary['errors_count'] > 0 ? 'warning' : 'success';
        $message     = $summary['errors_count'] > 0
            ? 'Import hoàn tất nhưng có dòng lỗi, xem chi tiết bên dưới.'
            : 'Import danh mục sản phẩm hoàn tất.';

        hpt_product_cat_tools_set_notice(array(
            'type'    => $notice_type,
            'message' => $message,
            'summary' => $summary,
        ));

        hpt_product_cat_tools_end_debug('import', $debug_token, 'success', array(
            'summary' => $summary,
        ));

        wp_safe_redirect(hpt_product_cat_tools_get_page_url());
        exit;
    } catch (Throwable $exception) {
        hpt_product_cat_tools_handle_runtime_exception('import', $exception, $debug_token, $debug_context);
        hpt_product_cat_tools_end_debug('import', $debug_token, 'failed');
        wp_safe_redirect(hpt_product_cat_tools_get_page_url());
        exit;
    }
}
add_action('admin_post_hpt_import_product_categories', 'hpt_import_product_categories');
