<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('HPT_PRODUCT_ADMIN_PROFILER_REQUEST_START')) {
    define('HPT_PRODUCT_ADMIN_PROFILER_REQUEST_START', microtime(true));
}

function hpt_product_admin_perf_user_can_manage() {
    return current_user_can('edit_products')
        || current_user_can('manage_woocommerce')
        || current_user_can('manage_product_terms');
}

function hpt_product_admin_perf_is_product_request() {
    if (!is_admin()) {
        return false;
    }

    global $pagenow;

    if ($pagenow === 'post-new.php') {
        return sanitize_key($_GET['post_type'] ?? 'post') === 'product';
    }

    if ($pagenow !== 'post.php') {
        return false;
    }

    $post_id = absint($_GET['post'] ?? $_POST['post_ID'] ?? 0);

    return $post_id > 0 && get_post_type($post_id) === 'product';
}

function hpt_product_admin_perf_is_product_screen($screen = null) {
    if (is_object($screen) && isset($screen->base, $screen->post_type)) {
        return $screen->base === 'post' && $screen->post_type === 'product';
    }

    return hpt_product_admin_perf_is_product_request();
}

function hpt_product_admin_perf_profiler_enabled($screen = null) {
    return hpt_product_admin_perf_user_can_manage()
        && hpt_product_admin_perf_is_product_screen($screen)
        && absint($_GET['hpt_profile_product_admin'] ?? 0) === 1;
}

function hpt_product_admin_perf_fast_mode_enabled($screen = null) {
    return hpt_product_admin_perf_user_can_manage()
        && hpt_product_admin_perf_is_product_screen($screen)
        && absint($_GET['hpt_full_product_admin'] ?? 0) !== 1;
}

function hpt_product_admin_perf_fast_request_enabled() {
    return hpt_product_admin_perf_is_product_request()
        && absint($_GET['hpt_full_product_admin'] ?? 0) !== 1;
}

function hpt_product_admin_perf_return_no_string() {
    return 'no';
}

function hpt_product_admin_perf_disable_woo_remote_features_for_product_request() {
    if (!hpt_product_admin_perf_fast_request_enabled()) {
        return;
    }

    add_filter('pre_option_woocommerce_show_marketplace_suggestions', 'hpt_product_admin_perf_return_no_string', 999);
    add_filter('woocommerce_allow_marketplace_suggestions', '__return_false', 999);

    if (class_exists('Automattic\\WooCommerce\\Admin\\RemoteInboxNotifications\\RemoteInboxNotificationsEngine')) {
        remove_action('init', array('Automattic\\WooCommerce\\Admin\\RemoteInboxNotifications\\RemoteInboxNotificationsEngine', 'on_init'), 0);
        remove_action('admin_init', array('Automattic\\WooCommerce\\Admin\\RemoteInboxNotifications\\RemoteInboxNotificationsEngine', 'on_admin_init'));
    }
}
hpt_product_admin_perf_disable_woo_remote_features_for_product_request();

function hpt_product_admin_perf_get_screen_label() {
    global $pagenow;

    return $pagenow === 'post-new.php' ? 'Thêm sản phẩm' : 'Sửa sản phẩm';
}

function hpt_product_admin_perf_get_minimal_rank_math_test_keys() {
    return array(
        'keywordInTitle',
        'keywordInMetaDescription',
        'keywordInPermalink',
        'lengthPermalink',
        'keywordNotUsed',
    );
}

function hpt_product_admin_perf_tune_rank_math_runtime_settings($screen = null) {
    if (!hpt_product_admin_perf_fast_mode_enabled($screen) || !function_exists('rank_math')) {
        return;
    }

    $rank_math = rank_math();

    if (
        !is_object($rank_math)
        || empty($rank_math->settings)
        || !is_object($rank_math->settings)
        || !method_exists($rank_math->settings, 'set')
    ) {
        return;
    }

    // Giữ metabox SEO chính, chỉ tắt phần gợi ý/liên kết và phân tích field nặng cho product.
    $rank_math->settings->set('titles', 'pt_product_link_suggestions', false);
    $rank_math->settings->set('titles', 'pt_product_ls_use_fk', false);
    $rank_math->settings->set('titles', 'pt_product_analyze_fields', array());
}
add_action('current_screen', 'hpt_product_admin_perf_tune_rank_math_runtime_settings', 20);

function hpt_product_admin_perf_filter_rank_math_metabox_values($values, $screen = null) {
    if (!hpt_product_admin_perf_fast_mode_enabled($screen) || !is_array($values)) {
        return $values;
    }

    if (!empty($values['postSettings']) && is_array($values['postSettings'])) {
        $values['postSettings']['linkSuggestions'] = false;
        $values['postSettings']['useFocusKeyword'] = false;
    }

    if (!empty($values['canUser']) && is_array($values['canUser'])) {
        $values['canUser']['analysis'] = false;
    }

    $values['showScore'] = false;
    $values['autoSuggestKeywords'] = false;
    $values['showKeywordIntent'] = false;

    if (!empty($values['assessor']) && is_array($values['assessor'])) {
        $minimal_tests = hpt_product_admin_perf_get_minimal_rank_math_test_keys();
        $current_tests = array_values(array_filter(array_map('strval', (array) ($values['assessor']['researchesTests'] ?? array()))));
        $filtered_tests = array_values(array_intersect($minimal_tests, $current_tests));

        $values['assessor']['researchesTests'] = !empty($filtered_tests) ? $filtered_tests : $minimal_tests;
        $values['assessor']['primaryTaxonomy'] = false;
    }

    return $values;
}
add_filter('rank_math/metabox/values', 'hpt_product_admin_perf_filter_rank_math_metabox_values', 999, 2);

function hpt_product_admin_perf_trim_rank_math_researches($tests, $object_type) {
    if (
        !hpt_product_admin_perf_fast_mode_enabled()
        || $object_type !== 'post'
        || !is_array($tests)
    ) {
        return $tests;
    }

    $trimmed_tests = array();

    foreach (hpt_product_admin_perf_get_minimal_rank_math_test_keys() as $test_key) {
        if (array_key_exists($test_key, $tests)) {
            $trimmed_tests[$test_key] = $tests[$test_key];
        }
    }

    return !empty($trimmed_tests) ? $trimmed_tests : $tests;
}
add_filter('rank_math/researches/tests', 'hpt_product_admin_perf_trim_rank_math_researches', 20, 2);

function hpt_product_admin_perf_disable_rank_math_primary_term($disabled) {
    if (!hpt_product_admin_perf_fast_mode_enabled()) {
        return $disabled;
    }

    return true;
}
add_filter('rank_math/admin/disable_primary_term', 'hpt_product_admin_perf_disable_rank_math_primary_term', 999);

function hpt_product_admin_perf_disable_rank_math_short_description_analysis($enabled) {
    if (!hpt_product_admin_perf_fast_mode_enabled()) {
        return $enabled;
    }

    return false;
}
add_filter('rank_math/woocommerce/analyze_short_description', 'hpt_product_admin_perf_disable_rank_math_short_description_analysis', 999);

function hpt_product_admin_perf_disable_async_action_scheduler($allow) {
    if (!hpt_product_admin_perf_fast_mode_enabled()) {
        return $allow;
    }

    return false;
}
add_filter('action_scheduler_allow_async_request_runner', 'hpt_product_admin_perf_disable_async_action_scheduler', 999);

function hpt_product_admin_perf_remove_shutdown_callbacks_for_object_class($class_name) {
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

function hpt_product_admin_perf_unhook_heavy_shutdown_callbacks($screen = null) {
    if (!hpt_product_admin_perf_fast_mode_enabled($screen)) {
        return;
    }

    if (class_exists('ActionScheduler_QueueRunner')) {
        ActionScheduler_QueueRunner::instance()->unhook_dispatch_async_request();
    }

    hpt_product_admin_perf_remove_shutdown_callbacks_for_object_class('Automattic\\WooCommerce\\Internal\\BatchProcessing\\BatchProcessingController');
}
add_action('admin_init', 'hpt_product_admin_perf_unhook_heavy_shutdown_callbacks', 100);
add_action('current_screen', 'hpt_product_admin_perf_unhook_heavy_shutdown_callbacks', 100);

function hpt_product_admin_perf_trim_rank_math_product_assets() {
    if (!function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();

    if (!hpt_product_admin_perf_fast_mode_enabled($screen)) {
        return;
    }

    // Giữ SEO metabox/editor, chỉ bỏ các watcher/phân tích phụ trên màn product.
    wp_dequeue_script('rank-math-custom-fields');
    wp_deregister_script('rank-math-custom-fields');
    wp_dequeue_script('rank-math-description-analysis');
    wp_deregister_script('rank-math-description-analysis');
    wp_dequeue_script('rank-math-acf-post-analysis');
    wp_deregister_script('rank-math-acf-post-analysis');
    wp_dequeue_script('rank-math-gallery-analysis');
    wp_deregister_script('rank-math-gallery-analysis');
}
add_action('admin_enqueue_scripts', 'hpt_product_admin_perf_trim_rank_math_product_assets', 9999);

function hpt_product_admin_perf_skip_rank_math_link_counter($should_process, $post) {
    if (!hpt_product_admin_perf_fast_mode_enabled()) {
        return $should_process;
    }

    if (!$post instanceof WP_Post) {
        return $should_process;
    }

    if ($post->post_type === 'product') {
        return false;
    }

    return $should_process;
}
add_filter('rank_math/links/process_post', 'hpt_product_admin_perf_skip_rank_math_link_counter', 999, 2);

function hpt_product_admin_perf_skip_rank_math_instant_indexing($url, $post) {
    if (!hpt_product_admin_perf_fast_mode_enabled()) {
        return $url;
    }

    if (!$post instanceof WP_Post) {
        return $url;
    }

    if ($post->post_type === 'product') {
        return false;
    }

    return $url;
}
add_filter('rank_math/instant_indexing/publish_url', 'hpt_product_admin_perf_skip_rank_math_instant_indexing', 999, 2);

function hpt_product_admin_perf_remove_rank_math_link_suggestions_box() {
    if (!hpt_product_admin_perf_fast_mode_enabled()) {
        return;
    }

    remove_meta_box('rank_math_metabox_link_suggestions', 'product', 'side');
}
add_action('add_meta_boxes_product', 'hpt_product_admin_perf_remove_rank_math_link_suggestions_box', 200);

function hpt_product_admin_perf_get_fast_taxonomies() {
    static $taxonomies = null;

    if (is_array($taxonomies)) {
        return $taxonomies;
    }

    $taxonomies = array();
    $candidate_taxonomies = array('product_cat', 'product_tag');

    if (function_exists('hpt_get_brand_filter_taxonomy')) {
        $candidate_taxonomies[] = hpt_get_brand_filter_taxonomy();
    } elseif (taxonomy_exists('product_brand')) {
        $candidate_taxonomies[] = 'product_brand';
    }

    foreach ($candidate_taxonomies as $taxonomy) {
        $taxonomy = sanitize_key((string) $taxonomy);

        if ($taxonomy === '' || isset($taxonomies[$taxonomy]) || !taxonomy_exists($taxonomy)) {
            continue;
        }

        $taxonomy_object = get_taxonomy($taxonomy);

        if (
            !$taxonomy_object
            || empty($taxonomy_object->object_type)
            || !in_array('product', (array) $taxonomy_object->object_type, true)
        ) {
            continue;
        }

        $taxonomies[$taxonomy] = $taxonomy_object;
    }

    return $taxonomies;
}

function hpt_product_admin_perf_get_default_taxonomy_box_id($taxonomy_object) {
    if (!is_object($taxonomy_object) || empty($taxonomy_object->name)) {
        return '';
    }

    return !empty($taxonomy_object->hierarchical)
        ? $taxonomy_object->name . 'div'
        : 'tagsdiv-' . $taxonomy_object->name;
}

function hpt_product_admin_perf_get_term_path($term, $taxonomy) {
    $term = get_term($term, $taxonomy);

    if (!$term || is_wp_error($term)) {
        return '';
    }

    $path_parts = array();
    $ancestor_ids = array_reverse(get_ancestors((int) $term->term_id, $taxonomy, 'taxonomy'));

    foreach ($ancestor_ids as $ancestor_id) {
        $ancestor = get_term($ancestor_id, $taxonomy);

        if ($ancestor && !is_wp_error($ancestor)) {
            $path_parts[] = $ancestor->name;
        }
    }

    $path_parts[] = $term->name;

    return implode(' > ', array_filter($path_parts));
}

function hpt_product_admin_perf_build_term_payload($term, $taxonomy_object) {
    $term = get_term($term, is_object($taxonomy_object) ? $taxonomy_object->name : '');

    if (!$term || is_wp_error($term) || !is_object($taxonomy_object)) {
        return array();
    }

    $path = hpt_product_admin_perf_get_term_path($term, $taxonomy_object->name);
    $label = $path !== '' ? $path : $term->name;

    return array(
        'id'    => (int) $term->term_id,
        'name'  => (string) $term->name,
        'slug'  => (string) $term->slug,
        'label' => $label . ' [#' . (int) $term->term_id . ']',
    );
}

function hpt_product_admin_perf_render_selected_term_chip($taxonomy, $taxonomy_object, $payload) {
    if (!is_array($payload) || empty($payload['id']) || empty($payload['name'])) {
        return;
    }

    $is_hierarchical = !empty($taxonomy_object->hierarchical);
    ?>
    <div
        class="hpt-product-taxonomy-chip"
        data-term-id="<?php echo esc_attr((int) $payload['id']); ?>"
        data-term-name="<?php echo esc_attr((string) $payload['name']); ?>"
        data-term-slug="<?php echo esc_attr((string) ($payload['slug'] ?? '')); ?>"
        data-term-label="<?php echo esc_attr((string) ($payload['label'] ?? $payload['name'])); ?>"
    >
        <span class="hpt-product-taxonomy-chip__text"><?php echo esc_html((string) ($payload['label'] ?? $payload['name'])); ?></span>
        <button type="button" class="button-link-delete hpt-product-taxonomy-chip__remove">Bỏ</button>
        <?php if ($is_hierarchical) : ?>
            <input type="hidden" name="tax_input[<?php echo esc_attr($taxonomy); ?>][]" value="<?php echo esc_attr((int) $payload['id']); ?>">
        <?php endif; ?>
    </div>
    <?php
}

function hpt_product_admin_perf_render_fast_taxonomy_box($post, $box) {
    $taxonomy = sanitize_key($box['args']['taxonomy'] ?? '');

    if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
        return;
    }

    $taxonomy_object = get_taxonomy($taxonomy);

    if (!$taxonomy_object) {
        return;
    }

    $can_assign = current_user_can($taxonomy_object->cap->assign_terms);
    $is_hierarchical = !empty($taxonomy_object->hierarchical);
    $selected_terms = array();
    $selected_names = array();

    if (!empty($post->ID)) {
        $selected_terms = wp_get_object_terms((int) $post->ID, $taxonomy, array(
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));

        if (is_wp_error($selected_terms)) {
            $selected_terms = array();
        }
    }

    $manage_url = admin_url('edit-tags.php?taxonomy=' . $taxonomy . '&post_type=product');
    ?>
    <div class="hpt-product-taxonomy-box" data-taxonomy="<?php echo esc_attr($taxonomy); ?>" data-hierarchical="<?php echo $is_hierarchical ? '1' : '0'; ?>">
        <p class="description" style="margin-top:0;">
            <?php echo esc_html($is_hierarchical ? 'Chỉ tải term theo từ khóa để tránh render toàn bộ danh sách lớn.' : 'Chỉ tải term theo từ khóa để tránh render toàn bộ danh sách lớn. Có thể nhấn Enter để thêm tag mới khi cần.'); ?>
        </p>

        <?php if (!$can_assign) : ?>
            <p>Bạn không có quyền gán term cho taxonomy này.</p>
        <?php else : ?>
            <input
                type="search"
                class="widefat hpt-product-taxonomy-search"
                placeholder="<?php echo esc_attr(sprintf('Tìm %s...', strtolower($taxonomy_object->labels->singular_name ?: $taxonomy_object->labels->name))); ?>"
                autocomplete="off"
            >

            <?php if ($is_hierarchical) : ?>
                <input type="hidden" name="tax_input[<?php echo esc_attr($taxonomy); ?>][]" value="0">
            <?php else : ?>
                <?php
                foreach ($selected_terms as $selected_term) {
                    $selected_names[] = $selected_term->name;
                }
                ?>
                <input
                    type="hidden"
                    class="hpt-product-taxonomy-flat-input"
                    name="tax_input[<?php echo esc_attr($taxonomy); ?>]"
                    value="<?php echo esc_attr(implode(', ', $selected_names)); ?>"
                >
            <?php endif; ?>

            <div class="hpt-product-taxonomy-selected">
                <?php if (!empty($selected_terms)) : ?>
                    <?php foreach ($selected_terms as $selected_term) : ?>
                        <?php hpt_product_admin_perf_render_selected_term_chip($taxonomy, $taxonomy_object, hpt_product_admin_perf_build_term_payload($selected_term, $taxonomy_object)); ?>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="hpt-product-taxonomy-empty">Chưa chọn term nào.</div>
                <?php endif; ?>
            </div>

            <div class="hpt-product-taxonomy-status">Nhập từ khóa để tìm nhanh.</div>
            <div class="hpt-product-taxonomy-results"></div>
        <?php endif; ?>

        <p style="margin:10px 0 0;">
            <a href="<?php echo esc_url($manage_url); ?>" target="_blank" rel="noopener noreferrer">
                Mở trang quản lý <?php echo esc_html(strtolower($taxonomy_object->labels->name)); ?>
            </a>
        </p>
    </div>
    <?php
}

function hpt_product_admin_perf_replace_heavy_taxonomy_metaboxes() {
    if (!hpt_product_admin_perf_fast_mode_enabled()) {
        return;
    }

    foreach (hpt_product_admin_perf_get_fast_taxonomies() as $taxonomy => $taxonomy_object) {
        $default_box_id = hpt_product_admin_perf_get_default_taxonomy_box_id($taxonomy_object);

        if ($default_box_id !== '') {
            remove_meta_box($default_box_id, 'product', 'side');
            remove_meta_box($default_box_id, 'product', 'normal');
            remove_meta_box($default_box_id, 'product', 'advanced');
        }

        add_meta_box(
            'hpt-fast-taxonomy-' . $taxonomy,
            $taxonomy_object->labels->name,
            'hpt_product_admin_perf_render_fast_taxonomy_box',
            'product',
            'side',
            'default',
            array(
                'taxonomy' => $taxonomy,
            )
        );
    }
}
add_action('add_meta_boxes_product', 'hpt_product_admin_perf_replace_heavy_taxonomy_metaboxes', 100);

function hpt_product_admin_perf_ajax_search_terms() {
    if (!hpt_product_admin_perf_user_can_manage()) {
        wp_send_json_error(array('message' => 'Bạn không có quyền thực hiện thao tác này.'), 403);
    }

    check_ajax_referer('hpt_product_admin_term_search', 'nonce');

    $taxonomy = sanitize_key($_POST['taxonomy'] ?? '');
    $query    = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));

    if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
        wp_send_json_error(array('message' => 'Taxonomy không hợp lệ.'), 400);
    }

    $taxonomy_object = get_taxonomy($taxonomy);

    if (!$taxonomy_object || !current_user_can($taxonomy_object->cap->assign_terms)) {
        wp_send_json_error(array('message' => 'Bạn không có quyền gán term cho taxonomy này.'), 403);
    }

    if ($query === '') {
        wp_send_json_success(array('items' => array()));
    }

    $terms = get_terms(array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'search'     => $query,
        'number'     => 20,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ));

    if (is_wp_error($terms)) {
        wp_send_json_error(array('message' => $terms->get_error_message()), 500);
    }

    $items = array();
    $seen_term_ids = array();

    foreach ((array) $terms as $term) {
        $payload = hpt_product_admin_perf_build_term_payload($term, $taxonomy_object);

        if (empty($payload['id']) || isset($seen_term_ids[$payload['id']])) {
            continue;
        }

        $seen_term_ids[$payload['id']] = true;
        $items[] = $payload;
    }

    $slug_match = get_term_by('slug', sanitize_title($query), $taxonomy);

    if ($slug_match && !is_wp_error($slug_match)) {
        $payload = hpt_product_admin_perf_build_term_payload($slug_match, $taxonomy_object);

        if (!empty($payload['id']) && !isset($seen_term_ids[$payload['id']])) {
            $items[] = $payload;
        }
    }

    if (ctype_digit($query)) {
        $id_match = get_term((int) $query, $taxonomy);

        if ($id_match && !is_wp_error($id_match)) {
            $payload = hpt_product_admin_perf_build_term_payload($id_match, $taxonomy_object);

            if (!empty($payload['id']) && !isset($seen_term_ids[$payload['id']])) {
                $items[] = $payload;
            }
        }
    }

    wp_send_json_success(array('items' => array_values($items)));
}
add_action('wp_ajax_hpt_product_admin_term_search', 'hpt_product_admin_perf_ajax_search_terms');

function hpt_product_admin_perf_enqueue_fast_taxonomy_assets() {
    if (!function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();

    if (!hpt_product_admin_perf_fast_mode_enabled($screen)) {
        return;
    }

    $style_handle = 'hpt-product-admin-taxonomy-style';
    $script_handle = 'hpt-product-admin-taxonomy-script';
    $script_path = get_stylesheet_directory() . '/assets/js/product-admin-taxonomy-selector.js';
    $script_url = get_stylesheet_directory_uri() . '/assets/js/product-admin-taxonomy-selector.js';

    wp_register_style($style_handle, false, array(), '1.0.0');
    wp_enqueue_style($style_handle);
    wp_add_inline_style($style_handle, '
        .hpt-product-taxonomy-box{display:flex;flex-direction:column;gap:10px}
        .hpt-product-taxonomy-selected,.hpt-product-taxonomy-results{display:flex;flex-direction:column;gap:8px}
        .hpt-product-taxonomy-chip,.hpt-product-taxonomy-result{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;padding:8px 10px;border:1px solid #dcdcde;border-radius:6px;background:#fff}
        .hpt-product-taxonomy-chip__text,.hpt-product-taxonomy-result__text{display:block;line-height:1.45;word-break:break-word}
        .hpt-product-taxonomy-chip__remove,.hpt-product-taxonomy-result__add{flex:0 0 auto}
        .hpt-product-taxonomy-empty,.hpt-product-taxonomy-status{padding:8px 10px;border:1px dashed #c3c4c7;border-radius:6px;background:#f6f7f7;color:#50575e}
        .hpt-product-taxonomy-result.is-selected{opacity:.55}
        .hpt-product-taxonomy-result__meta{display:block;margin-top:2px;color:#646970;font-size:12px}
    ');

    wp_enqueue_script(
        $script_handle,
        $script_url,
        array(),
        file_exists($script_path) ? (string) filemtime($script_path) : '1.0.0',
        true
    );

    wp_add_inline_script($script_handle, 'window.HPTProductAdminTaxonomy = ' . wp_json_encode(array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('hpt_product_admin_term_search'),
        'i18n'    => array(
            'searchPrompt' => 'Nhập từ khóa để tìm nhanh.',
            'searching'    => 'Đang tìm term...',
            'noResults'    => 'Không tìm thấy term phù hợp.',
            'addLabel'     => 'Chọn',
            'removeLabel'  => 'Bỏ',
            'emptyLabel'   => 'Chưa chọn term nào.',
            'searchError'  => 'Không tìm được term. Vui lòng thử lại.',
        ),
    )) . ';', 'before');
}
add_action('admin_enqueue_scripts', 'hpt_product_admin_perf_enqueue_fast_taxonomy_assets', 1000);

function hpt_product_admin_perf_reset_wc_batch_state_once() {
    if (!hpt_product_admin_perf_profiler_enabled()) {
        return;
    }

    $transient_key = 'hpt_wc_batch_state_reset_v1';

    if (get_transient($transient_key)) {
        return;
    }

    delete_transient('action_scheduler_lock_async-request-runner');
    delete_site_transient('action_scheduler_lock_async-request-runner');
    delete_option('action_scheduler_lock_async-request-runner');
    delete_site_option('action_scheduler_lock_async-request-runner');

    delete_transient('wc_pending_batch_processes');
    delete_site_transient('wc_pending_batch_processes');
    delete_option('wc_pending_batch_processes');
    delete_site_option('wc_pending_batch_processes');

    set_transient($transient_key, 1, DAY_IN_SECONDS);
}
add_action('admin_init', 'hpt_product_admin_perf_reset_wc_batch_state_once', 1);

function hpt_product_admin_perf_bootstrap($hook_suffix) {
    if (!function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();

    if (!hpt_product_admin_perf_profiler_enabled($screen)) {
        return;
    }

    $style_handle = 'hpt-product-admin-profiler-style';
    $script_handle = 'hpt-product-admin-profiler-script';
    $script_path = get_stylesheet_directory() . '/assets/js/product-admin-profiler.js';
    $script_url = get_stylesheet_directory_uri() . '/assets/js/product-admin-profiler.js';

    wp_register_style($style_handle, false, array(), '1.0.0');
    wp_enqueue_style($style_handle);
    wp_add_inline_style($style_handle, '
        #hpt-product-admin-profiler-panel{position:fixed;right:16px;bottom:16px;width:min(420px,calc(100vw - 32px));background:#111827;color:#f9fafb;border-radius:12px;box-shadow:0 14px 40px rgba(15,23,42,.28);z-index:99999;font-size:12px;line-height:1.5;overflow:hidden}
        #hpt-product-admin-profiler-panel .hpt-profiler__head{display:flex;justify-content:space-between;gap:12px;padding:12px 14px;background:#0f172a;border-bottom:1px solid rgba(255,255,255,.08)}
        #hpt-product-admin-profiler-panel .hpt-profiler__head strong{font-size:13px}
        #hpt-product-admin-profiler-panel .hpt-profiler__body{padding:12px 14px}
        #hpt-product-admin-profiler-panel .hpt-profiler__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:10px}
        #hpt-product-admin-profiler-panel .hpt-profiler__stat{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:8px 10px}
        #hpt-product-admin-profiler-panel .hpt-profiler__stat b{display:block;font-size:13px;color:#fff}
        #hpt-product-admin-profiler-panel .hpt-profiler__stat span{display:block;color:#cbd5e1}
        #hpt-product-admin-profiler-panel .hpt-profiler__meta{margin:0 0 10px;color:#cbd5e1}
        #hpt-product-admin-profiler-panel .hpt-profiler__details{margin-top:8px;border-top:1px solid rgba(255,255,255,.08);padding-top:8px}
        #hpt-product-admin-profiler-panel details{margin-top:8px}
        #hpt-product-admin-profiler-panel summary{cursor:pointer;color:#f8fafc;font-weight:600}
        #hpt-product-admin-profiler-panel .hpt-profiler__list{margin-top:8px;max-height:120px;overflow:auto;background:rgba(15,23,42,.45);border-radius:8px;padding:8px;white-space:pre-wrap;word-break:break-word}
        #hpt-product-admin-profiler-panel .hpt-profiler__requests{max-height:160px}
        #hpt-product-admin-profiler-panel .hpt-profiler__request{padding:6px 0;border-bottom:1px solid rgba(255,255,255,.06)}
        #hpt-product-admin-profiler-panel .hpt-profiler__request:last-child{border-bottom:0}
        #hpt-product-admin-profiler-panel .hpt-profiler__pill{display:inline-block;padding:2px 6px;border-radius:999px;background:#1d4ed8;color:#eff6ff;font-size:11px;margin-right:6px}
    ');

    wp_enqueue_script(
        $script_handle,
        $script_url,
        array(),
        file_exists($script_path) ? (string) filemtime($script_path) : '1.0.0',
        false
    );

    wp_add_inline_script($script_handle, 'window.HPTProductAdminProfilerConfig = ' . wp_json_encode(array(
        'screenLabel' => hpt_product_admin_perf_get_screen_label(),
    )) . ';', 'before');
}
add_action('admin_enqueue_scripts', 'hpt_product_admin_perf_bootstrap', 999);

function hpt_product_admin_perf_render_panel() {
    if (!function_exists('get_current_screen')) {
        return;
    }

    $screen = get_current_screen();

    if (!hpt_product_admin_perf_profiler_enabled($screen)) {
        return;
    }

    global $wp_scripts, $wp_styles;

    $script_handles = is_object($wp_scripts) ? array_values(array_unique(array_filter((array) $wp_scripts->queue))) : array();
    $style_handles = is_object($wp_styles) ? array_values(array_unique(array_filter((array) $wp_styles->queue))) : array();
    $profile_data = array(
        'screen_label'   => hpt_product_admin_perf_get_screen_label(),
        'php_render_ms'  => round((microtime(true) - HPT_PRODUCT_ADMIN_PROFILER_REQUEST_START) * 1000, 1),
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        'script_handles' => $script_handles,
        'style_handles'  => $style_handles,
    );
    ?>
    <div id="hpt-product-admin-profiler-panel" hidden>
        <div class="hpt-profiler__head">
            <div>
                <strong>Product Admin Profiler</strong>
                <div id="hpt-profiler-screen-label"></div>
            </div>
            <div>?hpt_profile_product_admin=1</div>
        </div>
        <div class="hpt-profiler__body">
            <div class="hpt-profiler__grid">
                <div class="hpt-profiler__stat">
                    <b id="hpt-profiler-php-render">0 ms</b>
                    <span>PHP render</span>
                </div>
                <div class="hpt-profiler__stat">
                    <b id="hpt-profiler-memory">0 MB</b>
                    <span>Peak memory</span>
                </div>
                <div class="hpt-profiler__stat">
                    <b id="hpt-profiler-assets">0 / 0</b>
                    <span>Scripts / Styles</span>
                </div>
                <div class="hpt-profiler__stat">
                    <b id="hpt-profiler-ajax-count">0</b>
                    <span>AJAX / REST</span>
                </div>
            </div>
            <p class="hpt-profiler__meta" id="hpt-profiler-slowest-request">Chưa có request bất đồng bộ nào được ghi nhận.</p>
            <p class="hpt-profiler__meta" id="hpt-profiler-save-roundtrip">Round-trip lưu sản phẩm: chưa có dữ liệu.</p>
            <div class="hpt-profiler__details">
                <details>
                    <summary>Script handles</summary>
                    <div class="hpt-profiler__list" id="hpt-profiler-script-handles"></div>
                </details>
                <details>
                    <summary>Style handles</summary>
                    <div class="hpt-profiler__list" id="hpt-profiler-style-handles"></div>
                </details>
                <details>
                    <summary>Request gần nhất</summary>
                    <div class="hpt-profiler__list hpt-profiler__requests" id="hpt-profiler-request-list"></div>
                </details>
            </div>
        </div>
    </div>
    <script>
        window.HPTProductAdminProfilerData = <?php echo wp_json_encode($profile_data); ?>;
        document.dispatchEvent(new CustomEvent('hpt:product-admin-profiler-data'));
    </script>
    <?php
}
add_action('admin_footer', 'hpt_product_admin_perf_render_panel', 999);
