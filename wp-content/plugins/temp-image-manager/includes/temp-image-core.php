<?php
add_action('admin_menu', 'custom_temp_image_upload_menu');

function custom_temp_image_upload_menu() {
    add_menu_page(
        'Upload Ảnh Tạm',
        'Ảnh Tạm',
        'manage_options',
        'temp-image-upload',
        'render_temp_image_upload_form',
        'dashicons-format-image'
    );
}

function temp_image_upload_is_target_admin_screen() {
    if (!is_admin()) {
        return false;
    }

    if (isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === 'temp-image-upload') {
        return true;
    }

    if (!function_exists('get_current_screen')) {
        return false;
    }

    $screen = get_current_screen();

    return $screen && $screen->base === 'toplevel_page_temp-image-upload';
}

add_action('admin_head', function () {
    if (!temp_image_upload_is_target_admin_screen()) {
        return;
    }

    ?>
    <style>
        .wrap h1 {
            font-size: 28px;
            margin-bottom: 20px;
            border-bottom: 2px solid #2271b1;
            padding-bottom: 10px;
        }

        /* FORM CONTAINER */
        .wrap form[enctype="multipart/form-data"] {
            background: #fff;
            border: 1px solid #ccd0d4;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            max-width: 600px;
            margin: 0 auto 30px auto;
        }

        /* FILE INPUT STYLE */
        .wrap input[type="file"] {
            display: block;
            padding: 15px;
            font-size: 16px;
            border: 2px dashed #2271b1;
            background-color: #f9f9f9;
            border-radius: 10px;
            transition: all 0.3s ease;
            width: 100%;
            margin-bottom: 20px;
        }

        .wrap input[type="file"]:hover {
            background-color: #eef6ff;
            border-color: #1a5d99;
        }

        /* SUBMIT BUTTON STYLE */
        .wrap form[enctype="multipart/form-data"] .button-primary {
            padding: 10px 24px;
            font-size: 16px;
            font-weight: 600;
            background: #2271b1;
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: background 0.3s ease;
        }

        .wrap form[enctype="multipart/form-data"] .button-primary:hover {
            background: #1a5d99;
        }

        .button.delete {
            background: #dc3232;
            color: white;
            border-color: #dc3232;
        }

        .button.delete:hover {
            background: #a81919;
            border-color: #a81919;
        }

        .widefat.fixed {
            border: 1px solid #ccd0d4;
            border-radius: 6px;
            overflow: hidden;
            background: white;
        }

        .widefat th, .widefat td {
            padding: 5px 10px;
            vertical-align: middle;
            font-size: 12px;
        }

        .widefat thead {
            background: #f0f0f1;
            border-bottom: 2px solid #ccd0d4;
        }

        .widefat tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        .widefat img {
            border: 2px solid #ccd0d4;
            border-radius: 4px;
            max-height: 50px;
        }

        .widefat a {
            color: #2271b1;
            text-decoration: none;
            font-weight: 500;
        }

        .widefat a:hover {
            text-decoration: underline;
        }

        .notice-success {
            background: #e7f7e7;
            border-left: 5px solid #46b450;
            padding: 15px;
            margin-top: 20px;
            border-radius: 5px;
        }
        
        .wrap .delete-img {
            display: flex;
            width: 100%;
            height: max-content;
            column-gap: 20px;
            justify-content: end;
        }

        #check-all {
            cursor: pointer;
        }

        @media screen and (max-width: 768px) {
            .widefat td:nth-child(3),
            .widefat th:nth-child(3) {
                display: none;
            }
        }
    </style>
    <?php
});

add_action('admin_enqueue_scripts', function() {
    if (!temp_image_upload_is_target_admin_screen()) {
        return;
    }

    wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
});

add_action('wp_ajax_get_attachment_data', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
    }

    check_ajax_referer('hpt_temp_image_admin', 'nonce');

    $id = intval($_GET['id']);
    $post = get_post($id);
    if (!$post || $post->post_type !== 'attachment' || !hpt_temp_image_is_temp_attachment($id)) {
        wp_send_json_error();
    }

    wp_send_json_success([
        'title' => $post->post_title,
        'alt' => get_post_meta($id, '_wp_attachment_image_alt', true),
        'caption' => $post->post_excerpt,
    ]);
});

add_action('wp_ajax_update_attachment_data', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
    }

    check_ajax_referer('hpt_temp_image_admin', 'nonce');

    $id = intval($_POST['image_id']);
    if (!current_user_can('edit_post', $id) || !hpt_temp_image_is_temp_attachment($id)) {
        wp_send_json_error();
    }

    wp_update_post([
        'ID' => $id,
        'post_title' => sanitize_text_field($_POST['title']),
        'post_excerpt' => sanitize_text_field($_POST['caption']),
    ]);

    update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($_POST['alt']));
    wp_send_json_success();
});

function render_temp_image_upload_form() {
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 500;
    $images = get_temp_uploaded_attachments($paged, $per_page, $total_images);
    $total_pages = ceil($total_images / $per_page);
    echo '<div style="margin: 10px 0; font-size: 15px;"><strong>Tổng ảnh tạm:</strong> ' . esc_html($total_images) . '</div>';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!current_user_can('manage_options')) {
            wp_die('Bạn không có quyền thực hiện thao tác này.');
        }

        if (isset($_POST['delete_selected']) && !empty($_POST['delete_ids'])) {
            check_admin_referer('hpt_temp_image_delete', 'hpt_temp_image_delete_nonce');
            foreach ($_POST['delete_ids'] as $id) {
                delete_temp_attachment((int) $id);
            }
            wp_safe_redirect(admin_url('admin.php?page=temp-image-upload&paged=' . $paged . '&deleted=1'));
            exit;
        }

        if (isset($_POST['delete_all'])) {
            check_admin_referer('hpt_temp_image_delete', 'hpt_temp_image_delete_nonce');
            $all_images = get_temp_uploaded_attachments(1, 9999, $count);
            foreach ($all_images as $img) {
                delete_temp_attachment($img->ID);
            }
            wp_safe_redirect(admin_url('admin.php?page=temp-image-upload&deleted_all=1'));
            exit;
        }

        if (!empty($_FILES['temp_images'])) {
            check_admin_referer('hpt_temp_image_upload', 'hpt_temp_image_upload_nonce');
            handle_temp_image_upload($_FILES['temp_images']);
            wp_safe_redirect(admin_url('admin.php?page=temp-image-upload&paged=' . $paged . '&uploaded=1'));
            exit;
        }
    }

    if (isset($_GET['delete_single'])) {
        $delete_id = absint($_GET['delete_single']);
        check_admin_referer('hpt_temp_image_delete_' . $delete_id);
        delete_temp_attachment($delete_id);
        wp_safe_redirect(admin_url('admin.php?page=temp-image-upload&paged=' . $paged . '&deleted_single=1'));
        exit;
    }

    if (isset($_GET['uploaded'])) {
        echo '<div class="notice notice-success"><p>✅ Đã tải lên ảnh thành công.</p></div>';
    }
    if (isset($_GET['deleted_all'])) {
        echo '<div class="notice notice-success"><p>Đã xóa tất cả ảnh tạm.</p></div>';
    }
    if (isset($_GET['deleted_single'])) {
        echo '<div class="notice notice-success"><p>Đã xóa ảnh.</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>Upload Ảnh Tạm (Lưu vào Media)</h1>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('hpt_temp_image_upload', 'hpt_temp_image_upload_nonce'); ?>
            <input type="file" name="temp_images[]" accept="image/*" multiple required>
            <?php submit_button('Tải lên'); ?>
        </form>
        
        <div style="margin-bottom: 20px; text-align: right;">
            <form method="get" style="display: inline;">
                <input type="hidden" name="page" value="temp-image-upload" />
                <input type="text" name="search" placeholder="🔍 Tìm theo tiêu đề ảnh..." value="<?php echo esc_attr($_GET['search'] ?? ''); ?>" />
                <button type="submit" class="button">Tìm kiếm</button>
            </form>
        </div>

        <form method="post" style="margin-top:30px;">
            <?php wp_nonce_field('hpt_temp_image_delete', 'hpt_temp_image_delete_nonce'); ?>
            <div class="delete-img">
                <?php submit_button('❗️ Xóa ảnh đã chọn', 'primary', 'delete_selected'); ?>
                <?php submit_button('🗑️ Xóa tất cả ảnh tạm', 'delete', 'delete_all'); ?>
            </div>

            <table class="widefat fixed" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" id="check-all"></th>
                        <th>Ảnh</th>
                        <th>Link</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($images)) {
                        echo '<tr><td colspan="4">Chưa có ảnh nào.</td></tr>';
                    } else {
                        foreach ($images as $image) {
                            $image_url = esc_url(wp_get_attachment_url($image->ID));
                            echo '<tr>';
                            echo '<td><input type="checkbox" name="delete_ids[]" value="' . esc_attr($image->ID) . '"></td>';
                            echo '<td><img src="' . $image_url . '" class="edit-image" data-id="' . $image->ID . '" style="cursor: pointer;" /></td>';
                            echo '<td><a href="' . $image_url . '" target="_blank">' . esc_html($image->post_title) . '</a></td>';
                            $delete_url = wp_nonce_url(
                                admin_url('admin.php?page=temp-image-upload&delete_single=' . $image->ID . '&paged=' . $paged),
                                'hpt_temp_image_delete_' . $image->ID
                            );
                            echo '<td><a href="' . esc_url($delete_url) . '" onclick="return confirm(\'Xóa ảnh này?\')">🗑️ Xóa</a></td>';
                            echo '</tr>';
                        }
                    }
                    ?>
                </tbody>
            </table>
            <?php if ($total_pages > 1): ?>
                <div style="margin-top: 20px; text-align: center;">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="<?php echo esc_url(add_query_arg(['paged' => $i, 'search' => $_GET['search'] ?? ''])); ?>"
   class="button <?php echo ($i == $paged) ? 'button-primary' : ''; ?>"
   style="margin: 0 5px;"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <div id="edit-image-modal" style="display:none;">
        <form id="edit-image-form">
            <input type="hidden" name="image_id" id="edit-image-id">
            <p><label>Tiêu đề:</label><br><input type="text" name="title" id="edit-image-title" style="width:100%"></p>
            <p><label>Alt Text:</label><br><input type="text" name="alt" id="edit-image-alt" style="width:100%"></p>
            <p><label>Caption:</label><br><textarea name="caption" id="edit-image-caption" style="width:100%"></textarea></p>
            <p>
                <button type="submit" class="button button-primary">💾 Lưu</button>
                <button type="button" class="button" id="edit-image-cancel">Hủy</button>
            </p>
        </form>
    </div>

    <script>
        const hptTempImageAdminNonce = '<?php echo esc_js(wp_create_nonce('hpt_temp_image_admin')); ?>';

        document.getElementById('check-all').addEventListener('change', function () {
            document.querySelectorAll('input[name="delete_ids[]"]').forEach(e => e.checked = this.checked);
        });

        document.querySelectorAll('.edit-image').forEach(img => {
            img.addEventListener('click', function () {
                const imageId = this.dataset.id;
                fetch(ajaxurl + '?action=get_attachment_data&id=' + imageId + '&nonce=' + encodeURIComponent(hptTempImageAdminNonce), {
                    credentials: 'same-origin'
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            const modalClone = document.getElementById('edit-image-modal').cloneNode(true);
                            modalClone.style.display = 'block';
                            Swal.fire({
                                title: 'Chỉnh sửa ảnh',
                                html: modalClone,
                                showConfirmButton: false,
                                showCloseButton: true,
                                didOpen: () => {
                                    const form = Swal.getHtmlContainer().querySelector('#edit-image-form');
                                    form.querySelector('#edit-image-id').value = imageId;
                                    form.querySelector('#edit-image-title').value = data.title || '';
                                    form.querySelector('#edit-image-alt').value = data.alt || '';
                                    form.querySelector('#edit-image-caption').value = data.caption || '';

                                    form.addEventListener('submit', function (e) {
                                        e.preventDefault();
                                        const formData = new FormData(form);
                                        formData.append('action', 'update_attachment_data');
                                        formData.append('nonce', hptTempImageAdminNonce);
                                        fetch(ajaxurl, {
                                            method: 'POST',
                                            body: formData,
                                            credentials: 'same-origin'
                                        }).then(res => res.json()).then(result => {
                                            if (result.success) {
                                                Swal.close();
                                                location.reload();
                                            } else {
                                                alert('Lỗi khi lưu dữ liệu.');
                                            }
                                        });
                                    });

                                    form.querySelector('#edit-image-cancel').addEventListener('click', () => Swal.close());
                                }
                            });
                        }
                    });
            });
        });
    </script>
    <?php
}

function hpt_temp_image_is_temp_attachment($attachment_id) {
    $attachment_id = absint($attachment_id);
    if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
        return false;
    }

    $upload_dir = wp_upload_dir();
    $base_dir = trailingslashit(wp_normalize_path($upload_dir['basedir'])) . 'temp-images/';
    $file = wp_normalize_path((string) get_attached_file($attachment_id));

    return $file !== '' && strpos($file, $base_dir) === 0;
}

function hpt_temp_image_schedule_metadata_worker() {
    if (!wp_next_scheduled('hpt_temp_image_metadata_worker')) {
        wp_schedule_single_event(time() + 5, 'hpt_temp_image_metadata_worker');
    }
}

function hpt_temp_image_get_allowed_mimes() {
    $allowed = [];

    foreach ((array) get_allowed_mime_types() as $extensions => $mime) {
        if (strpos((string) $mime, 'image/') === 0) {
            $allowed[$extensions] = $mime;
        }
    }

    return $allowed;
}

function hpt_temp_image_upload_one($file) {
    require_once ABSPATH . 'wp-admin/includes/file.php';

    if (!is_array($file) || empty($file['tmp_name']) || empty($file['name'])) {
        return new WP_Error('invalid_upload', 'Dữ liệu file upload không hợp lệ.', ['retryable' => false]);
    }

    if (!empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_error', 'Upload thất bại: ' . (int) $file['error'], ['retryable' => false]);
    }

    if (!empty($file['size']) && (int) $file['size'] > wp_max_upload_size()) {
        return new WP_Error('upload_too_large', 'File vượt quá giới hạn upload của máy chủ.', ['retryable' => false]);
    }

    $original_name = sanitize_file_name(wp_unslash((string) $file['name']));
    if ($original_name === '') {
        return new WP_Error('invalid_filename', 'Tên file không hợp lệ.', ['retryable' => false]);
    }

    $upload_dir_filter = static function ($uploads) {
        $subdir = '/temp-images';
        $uploads['subdir'] = $subdir;
        $uploads['path'] = trailingslashit($uploads['basedir']) . ltrim($subdir, '/');
        $uploads['url'] = trailingslashit($uploads['baseurl']) . ltrim($subdir, '/');
        $uploads['error'] = false;
        return $uploads;
    };

    add_filter('upload_dir', $upload_dir_filter, 20);
    $upload = wp_handle_upload(
        $file,
        [
            'test_form' => false,
            'mimes'    => hpt_temp_image_get_allowed_mimes(),
        ]
    );
    remove_filter('upload_dir', $upload_dir_filter, 20);

    if (!empty($upload['error']) || empty($upload['file']) || empty($upload['url'])) {
        return new WP_Error(
            'upload_move_failed',
            !empty($upload['error']) ? (string) $upload['error'] : 'Không thể lưu file upload.',
            ['retryable' => true]
        );
    }

    $mime = (string) ($upload['type'] ?? '');
    if (strpos($mime, 'image/') !== 0) {
        @unlink($upload['file']);
        return new WP_Error('not_an_image', 'File không phải là ảnh hợp lệ.', ['retryable' => false]);
    }

    $attachment = [
        'guid'           => esc_url_raw($upload['url']),
        'post_mime_type' => $mime,
        'post_title'     => pathinfo($original_name, PATHINFO_FILENAME),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_author'    => get_current_user_id(),
    ];

    $attachment_id = wp_insert_attachment($attachment, $upload['file'], 0, true);
    if (is_wp_error($attachment_id) || (int) $attachment_id <= 0) {
        @unlink($upload['file']);
        return new WP_Error(
            'attachment_insert_failed',
            is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'Không thể tạo attachment.',
            ['retryable' => true]
        );
    }

    if (!update_attached_file($attachment_id, $upload['file'])) {
        wp_delete_attachment($attachment_id, true);
        @unlink($upload['file']);
        return new WP_Error('attachment_file_link_failed', 'Không thể liên kết file với attachment.', ['retryable' => true]);
    }
    update_post_meta($attachment_id, '_hpt_temp_image_processing', 'pending');
    update_post_meta($attachment_id, '_hpt_temp_image_processing_at', time());
    update_post_meta($attachment_id, '_hpt_temp_image_processing_attempts', 0);
    hpt_temp_image_schedule_metadata_worker();

    return [
        'attachment_id' => (int) $attachment_id,
        'file_name'     => basename($upload['file']),
        'url'           => esc_url_raw($upload['url']),
        'status'        => 'pending_metadata',
    ];
}

function hpt_temp_image_normalize_files($files) {
    if (!is_array($files) || empty($files['name'])) {
        return [];
    }

    $is_multiple = is_array($files['name']);
    $count = $is_multiple ? count($files['name']) : 1;
    $normalized = [];

    for ($i = 0; $i < $count; $i++) {
        $normalized[] = [
            'name'     => $is_multiple ? ($files['name'][$i] ?? '') : ($files['name'] ?? ''),
            'type'     => $is_multiple ? ($files['type'][$i] ?? '') : ($files['type'] ?? ''),
            'tmp_name' => $is_multiple ? ($files['tmp_name'][$i] ?? '') : ($files['tmp_name'] ?? ''),
            'error'    => $is_multiple ? ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'size'     => $is_multiple ? ($files['size'][$i] ?? 0) : ($files['size'] ?? 0),
        ];
    }

    return $normalized;
}

function handle_temp_image_upload($files) {
    $results = [
        'success_count' => 0,
        'failed_count'  => 0,
        'uploaded'      => [],
        'errors'        => [],
    ];

    foreach (hpt_temp_image_normalize_files($files) as $file) {
        $result = hpt_temp_image_upload_one($file);

        if (is_wp_error($result)) {
            $results['failed_count']++;
            $results['errors'][] = [
                'file_name' => sanitize_file_name((string) ($file['name'] ?? '')),
                'message'   => $result->get_error_message(),
            ];
            continue;
        }

        $results['success_count']++;
        $results['uploaded'][] = $result;
    }

    return $results;
}

function hpt_temp_image_metadata_lock() {
    $lock_path = trailingslashit(get_temp_dir()) . 'hpt-temp-image-metadata.lock';
    $handle = @fopen($lock_path, 'c');

    if (!$handle || !@flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return false;
    }

    return $handle;
}

function hpt_temp_image_process_metadata($attachment_id) {
    $attachment_id = absint($attachment_id);
    if (!hpt_temp_image_is_temp_attachment($attachment_id)) {
        return new WP_Error('invalid_attachment', 'Attachment không thuộc thư mục ảnh tạm.');
    }

    if (get_post_meta($attachment_id, '_hpt_temp_image_processing', true) === 'ready') {
        return true;
    }

    $lock = hpt_temp_image_metadata_lock();
    if (!$lock) {
        return new WP_Error('metadata_busy', 'Server đang xử lý ảnh khác, vui lòng thử lại.', ['retryable' => true]);
    }

    try {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            throw new RuntimeException('File ảnh gốc không còn tồn tại.');
        }

        $attempts = (int) get_post_meta($attachment_id, '_hpt_temp_image_processing_attempts', true) + 1;
        update_post_meta($attachment_id, '_hpt_temp_image_processing_attempts', $attempts);
        update_post_meta($attachment_id, '_hpt_temp_image_processing', 'processing');
        update_post_meta($attachment_id, '_hpt_temp_image_processing_at', time());

        $metadata = wp_generate_attachment_metadata($attachment_id, $file);
        if (is_wp_error($metadata) || !is_array($metadata) || empty($metadata)) {
            throw new RuntimeException(is_wp_error($metadata) ? $metadata->get_error_message() : 'Không tạo được metadata ảnh.');
        }

        wp_update_attachment_metadata($attachment_id, $metadata);
        update_post_meta($attachment_id, '_hpt_temp_image_processing', 'ready');
        delete_post_meta($attachment_id, '_hpt_temp_image_processing_error');

        return true;
    } catch (Throwable $exception) {
        $attempts = (int) get_post_meta($attachment_id, '_hpt_temp_image_processing_attempts', true);
        update_post_meta($attachment_id, '_hpt_temp_image_processing_error', sanitize_text_field($exception->getMessage()));
        update_post_meta($attachment_id, '_hpt_temp_image_processing', $attempts < 3 ? 'pending' : 'failed');

        if ($attempts < 3) {
            hpt_temp_image_schedule_metadata_worker();
        }

        return new WP_Error('metadata_failed', $exception->getMessage(), ['retryable' => $attempts < 3]);
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function hpt_temp_image_metadata_worker() {
    $stale = get_posts([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 20,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query'     => [
            [
                'key'     => '_hpt_temp_image_processing',
                'value'   => 'processing',
                'compare' => '=',
            ],
            [
                'key'     => '_hpt_temp_image_processing_at',
                'value'   => time() - 600,
                'type'    => 'NUMERIC',
                'compare' => '<',
            ],
        ],
    ]);

    foreach ($stale as $attachment_id) {
        update_post_meta($attachment_id, '_hpt_temp_image_processing', 'pending');
    }

    $pending = get_posts([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_key'       => '_hpt_temp_image_processing',
        'meta_value'     => 'pending',
    ]);

    if (!empty($pending)) {
        hpt_temp_image_process_metadata((int) $pending[0]);
        hpt_temp_image_schedule_metadata_worker();
    }
}
add_action('hpt_temp_image_metadata_worker', 'hpt_temp_image_metadata_worker');

add_action('admin_footer', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'temp-image-upload') return;
    ?>
    <style>
        #upload-bar {
            height: 20px;
            width: 0;
            background: linear-gradient(90deg, #ffc107 0%, #28a745 100%);
            transition: width 0.3s ease;
            border-radius: 5px;
        }
        #upload-container {
            width: 100%;
            background: #f1f1f1;
            border: 1px solid #ccc;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        .swal2-html-container {
            overflow: hidden;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const uploadInput = document.querySelector('input[type="file"][name="temp_images[]"]');
            if (!uploadInput) return;
        
            const form = uploadInput.closest('form');
            const uploadBtn = form.querySelector('button[type="submit"]');
        
            // Tạo progress bar
            const container = document.createElement('div');
            container.id = 'upload-container';
            container.style = `
                width: 100%;
                background: #f1f1f1;
                border: 1px solid #ccc;
                border-radius: 5px;
                margin-top: 10px;
                height: 20px;
                overflow: hidden;
            `;
        
            const bar = document.createElement('div');
            bar.id = 'upload-bar';
            bar.style = `
                height: 100%;
                width: 0;
                background: linear-gradient(to right, #ffc107, #28a745);
                transition: width 0.3s ease;
                border-radius: 5px;
                overflow: hidden;
            `;
        
            container.appendChild(bar);
            form.appendChild(container);
        
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                const files = uploadInput.files;
                if (!files.length) return;

                uploadBtn.disabled = true;
                const total = files.length;
                const uploadBatchSize = 12;
                const uploadConcurrency = 3;
                const nonce = '<?php echo esc_js(wp_create_nonce('hpt_temp_image_upload')); ?>';
                const uploadedIds = [];
                const failures = [];

                const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

                async function uploadOne(file, attempt = 0) {
                    const formData = new FormData();
                    formData.append('action', 'batch_upload_temp_images');
                    formData.append('nonce', nonce);
                    formData.append('temp_image', file, file.name);

                    try {
                        const response = await fetch(ajaxurl, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        });
                        const payload = await response.json().catch(() => null);

                        if (response.ok && payload && payload.success) {
                            return payload.data;
                        }

                        const errorData = payload && payload.data ? payload.data : {};
                        const error = new Error(errorData.message || 'Upload thất bại.');
                        error.retryable = response.status >= 500 || response.status === 429 || errorData.retryable === true;
                        throw error;
                    } catch (error) {
                        if (attempt < 2 && error.retryable !== false) {
                            await sleep(500 * Math.pow(2, attempt));
                            return uploadOne(file, attempt + 1);
                        }
                        throw error;
                    }
                }

                async function uploadBatch(batch) {
                    let cursor = 0;
                    const worker = async () => {
                        while (cursor < batch.length) {
                            const file = batch[cursor++];
                            try {
                                const result = await uploadOne(file);
                                if (result && result.uploaded) {
                                    result.uploaded.forEach(item => uploadedIds.push(item.attachment_id));
                                }
                            } catch (error) {
                                failures.push({name: file.name, message: error.message});
                            }
                        }
                    };

                    await Promise.all(Array.from({length: Math.min(uploadConcurrency, batch.length)}, worker));
                }

                async function processMetadata() {
                    let processed = 0;

                    async function processOne(attachmentId, attempt = 0) {
                        const formData = new FormData();
                        formData.append('action', 'hpt_process_temp_image_metadata');
                        formData.append('nonce', nonce);
                        formData.append('attachment_id', String(attachmentId));

                        try {
                            const response = await fetch(ajaxurl, {
                                method: 'POST',
                                body: formData,
                                credentials: 'same-origin'
                            });
                            const payload = await response.json().catch(() => null);
                            if (response.ok && payload && payload.success) {
                                return;
                            }

                            const errorData = payload && payload.data ? payload.data : {};
                            const error = new Error(errorData.message || 'Không tạo được thumbnail.');
                            error.retryable = response.status === 409 || errorData.retryable === true;
                            throw error;
                        } catch (error) {
                            if (attempt < 2 && error.retryable) {
                                await sleep(700 * (attempt + 1));
                                return processOne(attachmentId, attempt + 1);
                            }
                            throw error;
                        }
                    }

                    for (const attachmentId of uploadedIds) {
                        try {
                            await processOne(attachmentId);
                        } catch (error) {
                            failures.push({name: `attachment #${attachmentId}`, message: error.message});
                        }

                        processed++;
                        bar.style.width = (60 + Math.floor((processed / Math.max(1, uploadedIds.length)) * 40)) + '%';
                    }
                }

                Swal.fire({
                    title: '⏳ Đang upload ảnh...',
                    html: container,
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: async () => {
                        let completed = 0;

                        for (let offset = 0; offset < total; offset += uploadBatchSize) {
                            const batch = Array.from(files).slice(offset, offset + uploadBatchSize);
                            await uploadBatch(batch);
                            completed += batch.length;
                            bar.style.width = Math.floor((completed / total) * 60) + '%';
                        }

                        if (uploadedIds.length) {
                            Swal.update({title: '🧩 Đang tạo thumbnail an toàn...'});
                            await processMetadata();
                        }

                        bar.style.width = '100%';

                        if (failures.length) {
                            const summary = failures.slice(0, 8).map(item => `${item.name}: ${item.message}`).join('<br>');
                            Swal.update({
                                title: `Hoàn tất với ${failures.length} lỗi`,
                                html: `<div style="text-align:left;max-height:260px;overflow:auto">${summary}</div>`,
                                showConfirmButton: true,
                                confirmButtonText: 'Đóng'
                            });
                        } else {
                            Swal.close();
                            location.reload();
                        }
                    }
                });
            });
        });
        </script>
    <?php
});

add_action('wp_ajax_batch_upload_temp_images', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
    }

    check_ajax_referer('hpt_temp_image_upload', 'nonce');

    $files = $_FILES['temp_image'] ?? $_FILES['temp_images'] ?? null;
    $response = handle_temp_image_upload($files);

    if ($response['success_count'] <= 0) {
        $first_error = $response['errors'][0]['message'] ?? 'Upload thất bại.';
        $response['message'] = $first_error;
        $response['retryable'] = false;
        wp_send_json_error($response, 400);
    }

    wp_send_json_success($response);
});

add_action('wp_ajax_hpt_process_temp_image_metadata', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
    }

    check_ajax_referer('hpt_temp_image_upload', 'nonce');

    $attachment_id = absint($_POST['attachment_id'] ?? 0);
    $result = hpt_temp_image_process_metadata($attachment_id);

    if (is_wp_error($result)) {
        wp_send_json_error([
            'message'   => $result->get_error_message(),
            'retryable' => (bool) ($result->get_error_data()['retryable'] ?? false),
        ], 409);
    }

    wp_send_json_success(['attachment_id' => $attachment_id, 'status' => 'ready']);
});

function get_temp_uploaded_attachments($paged = 1, $per_page = 10, &$total = 0) {
    $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

    $args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => max(1, (int) $per_page),
        'paged'          => max(1, (int) $paged),
        'orderby'        => 'ID',
        'order'          => 'DESC',
        'fields'         => 'all',
        'meta_query'     => [
            [
                'key'     => '_wp_attached_file',
                'value'   => 'temp-images/',
                'compare' => 'LIKE',
            ],
        ],
    ];

    if ($search !== '') {
        $args['s'] = $search;
    }

    $query = new WP_Query($args);
    $total = (int) $query->found_posts;

    return $query->posts;
}


function delete_temp_attachment($attachment_id) {
    if (!hpt_temp_image_is_temp_attachment($attachment_id)) {
        return false;
    }

    return (bool) wp_delete_attachment($attachment_id, true);
}
