<?php

defined('ABSPATH') || exit;

/**
 * Keep the core Media Library uploader fast without allowing unbounded requests.
 * The actual attachment and metadata workflow remains in WordPress core.
 */
function hpt_media_upload_limits() {
    $parallel = (int) apply_filters('hpt_media_upload_parallel', 8);
    $ceiling  = (int) apply_filters('hpt_media_upload_parallel_ceiling', 8);
    $batch    = (int) apply_filters('hpt_media_upload_batch_size', 48);

    // Keep a bounded burst and let the browser back off on server pressure.
    $parallel = max(1, min(8, $parallel));
    $ceiling  = max($parallel, min(8, $ceiling));
    $batch    = max($ceiling, min(48, $batch));

    return array(
        'parallel' => $parallel,
        'ceiling'  => $ceiling,
        'batch'    => $batch,
    );
}

function hpt_media_upload_apply_plupload_limits($settings) {
    $limits = hpt_media_upload_limits();

    $settings['max_parallel_uploads'] = $limits['ceiling'];
    $settings['upload_batch_size']     = $limits['batch'];
    $settings['initial_parallel_uploads'] = $limits['parallel'];
    $settings['min_parallel_uploads']     = max(3, min($limits['parallel'], 4));
    $settings['adaptive_parallel_uploads'] = true;
    $settings['parallel_ramp_up_after']    = 6;
    $settings['max_retries']           = max(2, (int) ($settings['max_retries'] ?? 0));
    $settings['hpt_defer_metadata']    = true;
    $settings['hpt_metadata_ajax_url'] = admin_url('admin-ajax.php');

    return $settings;
}

// Media modal/grid uploader.
add_filter('plupload_default_settings', 'hpt_media_upload_apply_plupload_limits', 20);

// Classic Media Library uploader.
add_filter('plupload_init', 'hpt_media_upload_apply_plupload_limits', 20);

function hpt_media_should_defer_attachment_metadata($defer, $attachment_id, $file, $mime_type) {
    if ($defer || strpos((string) $mime_type, 'image/') !== 0) {
        return (bool) $defer;
    }

    if (empty($_FILES['async-upload']) || !hpt_media_is_plupload_request()) {
        return false;
    }

    if (isset($_REQUEST['post_data'])) {
        $post_data = wp_unslash($_REQUEST['post_data']);
        if (is_string($post_data)) {
            parse_str($post_data, $post_data);
        }

        if (!is_array($post_data)) {
            $post_data = array();
        }

        if (!empty($post_data['context']) && in_array($post_data['context'], array('custom-header', 'custom-background'), true)) {
            return false;
        }
    }

    return true;
}
add_filter('wp_defer_attachment_metadata', 'hpt_media_should_defer_attachment_metadata', 10, 4);

function hpt_media_is_plupload_request() {
    if (defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && $_REQUEST['action'] === 'upload-attachment') {
        return true;
    }

    if (!empty($_REQUEST['html-upload'])) {
        return false;
    }

    return !empty($_REQUEST['short']) && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'async-upload.php';
}

function hpt_media_metadata_worker_event_args($attachment_id) {
    return array(absint($attachment_id));
}

function hpt_media_schedule_metadata_fallback($attachment_id = 0, $delay = 60) {
    $attachment_id = absint($attachment_id);

    // One shared fallback event avoids adding a cron option write for every image.
    if ($attachment_id > 0 && wp_next_scheduled('hpt_media_metadata_worker', hpt_media_metadata_worker_event_args($attachment_id))) {
        return;
    }

    if (wp_next_scheduled('hpt_media_metadata_worker')) {
        return;
    }

    wp_schedule_single_event(time() + max(15, (int) $delay), 'hpt_media_metadata_worker');
}

function hpt_media_queue_deferred_metadata($attachment_id, $file, $mime_type) {
    $attachment_id = absint($attachment_id);
    if ($attachment_id <= 0 || strpos((string) $mime_type, 'image/') !== 0) {
        return;
    }

    update_post_meta($attachment_id, '_hpt_media_metadata_pending', 1);
    // Let the active upload burst finish before the cron safety net runs.
    hpt_media_schedule_metadata_fallback(0, 300);
}
add_action('wp_attachment_metadata_deferred', 'hpt_media_queue_deferred_metadata', 10, 3);

function hpt_media_metadata_lock_path($attachment_id) {
    return trailingslashit(get_temp_dir()) . 'hpt-media-metadata-' . absint($attachment_id) . '.lock';
}

function hpt_media_mark_metadata_complete($attachment_id) {
    $attachment_id = absint($attachment_id);
    if ($attachment_id <= 0) {
        return;
    }

    delete_post_meta($attachment_id, '_hpt_media_metadata_pending');
    delete_post_meta($attachment_id, '_hpt_media_metadata_attempts');
    wp_clear_scheduled_hook('hpt_media_metadata_worker', hpt_media_metadata_worker_event_args($attachment_id));
}

function hpt_media_clear_metadata_fallback($attachment_id, $metadata) {
    if (is_array($metadata) && !empty($metadata) && get_post_meta((int) $attachment_id, '_hpt_media_metadata_pending', true)) {
        hpt_media_mark_metadata_complete((int) $attachment_id);
    }
}
add_action('wp_attachment_metadata_completed', 'hpt_media_clear_metadata_fallback', 10, 2);

function hpt_media_pending_attachment_ids($limit = 1) {
    return get_posts(array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => max(1, (int) $limit),
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_query'     => array(
            array(
                'key'   => '_hpt_media_metadata_pending',
                'value' => '1',
            ),
        ),
        'no_found_rows'  => true,
    ));
}

function hpt_media_process_metadata($attachment_id) {
    $attachment_id = absint($attachment_id);
    if ($attachment_id <= 0 || !get_post_meta($attachment_id, '_hpt_media_metadata_pending', true)) {
        return true;
    }

    $lock_path = hpt_media_metadata_lock_path($attachment_id);
    $lock      = @fopen($lock_path, 'x');

    if (!$lock) {
        if (file_exists($lock_path) && @filemtime($lock_path) < (time() - 600)) {
            @unlink($lock_path);
        }
        hpt_media_schedule_metadata_fallback(0, 30);
        return;
    }

    $attempts = (int) get_post_meta($attachment_id, '_hpt_media_metadata_attempts', true) + 1;
    update_post_meta($attachment_id, '_hpt_media_metadata_attempts', $attempts);

    try {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $result = wp_update_image_subsizes($attachment_id);
    } catch (Throwable $exception) {
        $result = new WP_Error('hpt_media_metadata_failed', $exception->getMessage());
    } finally {
        fclose($lock);
        @unlink($lock_path);
    }

    if (is_wp_error($result) || !is_array($result) || empty($result)) {
        // Keep the marker so a transient image-editor failure can be retried later.
        hpt_media_schedule_metadata_fallback(0, $attempts >= 5 ? 600 : 120);
        return;
    }

    $stored_metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($stored_metadata) || empty($stored_metadata)) {
        hpt_media_schedule_metadata_fallback(0, $attempts >= 5 ? 600 : 120);
        return;
    }

    hpt_media_mark_metadata_complete($attachment_id);
    return true;
}

function hpt_media_metadata_worker($attachment_id = 0) {
    $attachment_id = absint($attachment_id);

    if ($attachment_id > 0) {
        hpt_media_process_metadata($attachment_id);
    } else {
        $pending_ids = hpt_media_pending_attachment_ids(1);
        if (!empty($pending_ids)) {
            hpt_media_process_metadata((int) $pending_ids[0]);
        }
    }

    if (!empty(hpt_media_pending_attachment_ids(1))) {
        hpt_media_schedule_metadata_fallback(0, 60);
    }
}
add_action('hpt_media_metadata_worker', 'hpt_media_metadata_worker');

function hpt_media_mark_pending_attachment_for_js($response, $attachment, $meta) {
    if (!empty($meta) || empty($response['id']) || !get_post_meta((int) $response['id'], '_hpt_media_metadata_pending', true)) {
        return $response;
    }

    $response['hptMetadataPending'] = true;
    return $response;
}
add_filter('wp_prepare_attachment_for_js', 'hpt_media_mark_pending_attachment_for_js', 10, 3);

/**
 * Keep the core Media Library attachment query bounded and lightweight.
 * Search semantics are intentionally untouched: WordPress still searches
 * title, caption, description and (when a search is present) the attachment
 * filename. This hook only removes unnecessary term-cache work and prevents
 * an oversized client request from loading an unbounded result page.
 */
function hpt_media_query_attachments_args($query) {
    if (!is_array($query)) {
        return $query;
    }

    $query['update_post_term_cache'] = false;
    $query['cache_results'] = true;

    if (isset($query['posts_per_page'])) {
        $requested = (int) $query['posts_per_page'];
        if ($requested > 80) {
            $query['posts_per_page'] = 80;
        }
    }

    return $query;
}
add_filter('ajax_query_attachments_args', 'hpt_media_query_attachments_args', 20);
