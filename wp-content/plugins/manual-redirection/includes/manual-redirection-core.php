<?php

// ✅ Đường dẫn chính xác đến file redirects.txt trong thư mục plugin
function get_redirects_file_path() {
    return plugin_dir_path(dirname(__FILE__)) . 'redirects.txt';
}

// ✅ Redirect người dùng khi truy cập 404
add_action('template_redirect', 'redirect_from_txt_file');
function redirect_from_txt_file() {
    if (!is_404()) return;

    $file_path = get_redirects_file_path();

    if (!file_exists($file_path)) {
        error_log('❌ Không tìm thấy file redirects.txt tại: ' . $file_path);
        return;
    }

    $redirects = [];
    $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '|') === false) continue;
        [$old, $new] = array_map('trim', explode('|', $line));
        if ($old && $new) {
            $redirects[untrailingslashit($old)] = trailingslashit($new);
        }
    }

    $current_path = untrailingslashit(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if (isset($redirects[$current_path])) {
        $target = $redirects[$current_path];
        error_log("✅ Redirect: $current_path → $target");
        wp_redirect(home_url($target), 301);
        exit;
    } else {
        error_log("🚫 Không tìm thấy redirect cho: $current_path");
    }
}

// ✅ Giao diện admin để thêm redirect
add_action('admin_menu', 'add_redirection_menu');
function add_redirection_menu() {
    add_menu_page(
        'Redirection',
        'Redirection',
        'manage_options',
        'custom-redirection',
        'render_redirection_page',
        'dashicons-randomize',
        90
    );
}

// ✅ Tạo slug
function to_slug($str) {
    $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    $str = strtolower($str);
    $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
    $str = preg_replace('/[\s-]+/', '-', $str);
    return trim($str, '-');
}

// ✅ HTML giao diện quản lý redirect
function render_redirection_page() {
    $file_path = get_redirects_file_path();
    $message = '';

    if (isset($_POST['old_link'], $_POST['new_link'])) {
        $old_link = '/product/' . to_slug(sanitize_text_field($_POST['old_link'])) . '/';
        $new_link = '/product/' . to_slug(sanitize_text_field($_POST['new_link'])) . '/';

        if ($old_link && $new_link) {
            $lines = file_exists($file_path) ? file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

            // Xóa dòng trùng
            $lines = array_filter($lines, function ($line) use ($old_link) {
                $parts = explode('|', $line);
                return trim($parts[0] ?? '') !== $old_link;
            });

            $lines[] = "$old_link|$new_link";

            $fp = fopen($file_path, 'w');
            if ($fp && flock($fp, LOCK_EX)) {
                fwrite($fp, implode(PHP_EOL, $lines) . PHP_EOL);
                flock($fp, LOCK_UN);
                fclose($fp);
                delete_transient('redirect_cache');
                $message = '✅ Redirect đã được lưu.';
            } else {
                $message = '❌ Không thể ghi file. Vui lòng kiểm tra quyền ghi.';
            }
        } else {
            $message = '⚠️ Vui lòng nhập đầy đủ link cũ và link mới.';
        }
    }

    $redirects = file_exists($file_path) ? file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $search = isset($_GET['s']) ? trim($_GET['s']) : '';
    if ($search !== '') {
        $redirects = array_filter($redirects, fn($line) => stripos($line, $search) !== false);
    }

    $per_page = 1000;
    $total = count($redirects);
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($page - 1) * $per_page;
    $paged_data = array_slice($redirects, $offset, $per_page);
    ?>

    <div class="redirect-form-wrapper">
        <h1>📌 Quản lý Redirect</h1>
        <?php if ($message): ?>
            <div class="redirect-form-message"><?php echo esc_html($message); ?></div>
        <?php endif; ?>
        <form method="post">
            <table>
                <tr>
                    <th><label for="old_link">🔗 Link cũ</label></th>
                    <td><input name="old_link" type="text" id="old_link" required></td>
                </tr>
                <tr>
                    <th><label for="new_link">➡️ Link mới</label></th>
                    <td><input name="new_link" type="text" id="new_link" required></td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" value="Lưu Redirect" class="button button-primary">
            </p>
        </form>
    </div>

    <div class="redirect-table-wrapper">
        <h2>📄 Danh sách Redirect (<?= $total ?> dòng)</h2>
        <div class="search-box">
            <input type="text" id="js-search" placeholder="🔍 Tìm kiếm link..." oninput="filterRedirects()">
        </div>
        <table class="redirect-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>🔗 Link cũ</th>
                    <th>➡️ Link mới</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paged_data as $i => $line):
                    [$old, $new] = array_map('trim', explode('|', $line));
                    if (!$old || !$new) continue;
                ?>
                    <tr>
                        <td><?= esc_html($offset + $i + 1) ?></td>
                        <td><code><?= esc_html($old) ?></code></td>
                        <td><code><?= esc_html($new) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($paged_data)): ?>
                    <tr><td colspan="3">Không có redirect nào.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <script>
        window.addEventListener('DOMContentLoaded', () => {
            const input = document.getElementById('old_link');
            if (input) input.focus();
        });

        function filterRedirects() {
            const keyword = document.getElementById('js-search').value.toLowerCase();
            document.querySelectorAll('.redirect-table tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
            });
        }
        </script>

        <?php if ($total > $per_page): ?>
            <div class="pagination">
                <?php for ($p = 1; $p <= ceil($total / $per_page); $p++): ?>
                    <a href="<?= esc_url(admin_url("admin.php?page=custom-redirection&paged=$p")) ?>" class="<?= $p === $page ? 'current' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <style>
        .redirect-form-wrapper,
        .redirect-table-wrapper {
            position: relative;
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            max-width: 1024px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .redirect-form-wrapper h1,
        .redirect-table-wrapper h2 {
            font-size: 24px;
            margin-bottom: 20px;
            color: #2271b1;
            border-bottom: 2px solid #2271b1;
            padding-bottom: 5px;
        }
        
        .redirect-form-message {
            background: #e7f7e7;
            border-left: 5px solid #46b450;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: 500;
            color: #1e4620;
        }
        
        .redirect-form-wrapper table {
            width: 100%;
            border-spacing: 10px;
        }
        
        .redirect-form-wrapper table th {
            text-align: left;
            font-weight: 600;
            padding-right: 10px;
        }
        
        .redirect-form-wrapper input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccd0d4;
            border-radius: 6px;
            background: #f9f9f9;
            font-size: 14px;
        }
        
        .redirect-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 13px;
        }
        
        .redirect-table th,
        .redirect-table td {
            border: 1px solid #ccd0d4;
            padding: 10px;
            text-align: left;
        }
        
        .redirect-table thead {
            background: #f0f0f1;
            font-weight: 600;
        }
        
        .redirect-table tbody tr:nth-child(even) {
            background: #fafafa;
        }
        
        .redirect-table code {
            background: #f1f1f1;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
        }
        
        .search-box {
            margin-bottom: 10px;
            text-align: right;
        }
        
        .search-box input {
            padding: 8px 12px;
            border: 1px solid #ccd0d4;
            border-radius: 6px;
            width: 250px;
            background: #fff;
        }
        
        .pagination {
            margin-top: 20px;
            text-align: center;
        }
        
        .pagination a {
            display: inline-block;
            padding: 6px 12px;
            margin: 0 4px;
            border: 1px solid #ccd0d4;
            border-radius: 5px;
            text-decoration: none;
            color: #2271b1;
            font-weight: 500;
            transition: 0.2s;
        }
        
        .pagination a:hover {
            background: #f1f1f1;
        }
        
        .pagination a.current {
            background: #2271b1;
            color: white;
            font-weight: 600;
            pointer-events: none;
        }
        </style>

    <?php
}
