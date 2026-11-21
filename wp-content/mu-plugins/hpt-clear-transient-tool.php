<?php
/**
 * Plugin Name: HPT Clear Transient Tool
 * Description: Công cụ xóa toàn bộ WordPress/WooCommerce transient cache trong wp_options.
 * Author: Hai Phong Tech
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class HPT_Clear_Transient_Tool
{
    const MENU_SLUG = 'hpt-clear-transient-tool';
    const NONCE_ACTION = 'hpt_clear_transient_action';
    const NONCE_NAME = 'hpt_clear_transient_nonce';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_hpt_clear_transients', [$this, 'handle_clear_transients']);
    }

    public function register_admin_menu()
    {
        add_management_page(
            'HPT Clear Transients',
            'HPT Clear Transients',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Bạn không có quyền truy cập trang này.');
        }

        $deleted = isset($_GET['deleted']) ? intval($_GET['deleted']) : null;
        $flushed = isset($_GET['flushed']) ? sanitize_text_field($_GET['flushed']) : null;
        ?>

        <div class="wrap">
            <h1>HPT Clear Transients</h1>

            <p>
                Công cụ này dùng để xóa toàn bộ transient cache trong bảng
                <code>wp_options</code>, bao gồm WordPress transients và WooCommerce cache.
            </p>

            <?php if ($deleted !== null): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        Đã xóa <strong><?php echo esc_html($deleted); ?></strong> dòng transient/cache.
                        Object cache flush:
                        <strong><?php echo esc_html($flushed === 'yes' ? 'Có' : 'Không'); ?></strong>
                    </p>
                </div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #ccd0d4;padding:16px;margin-top:16px;max-width:900px;">
                <h2>Lệnh sẽ chạy</h2>

                <pre style="background:#f6f7f7;padding:12px;overflow:auto;">DELETE FROM wp_options
WHERE option_name LIKE '_transient_%'
   OR option_name LIKE '_site_transient_%';

DELETE FROM wp_options
WHERE option_name LIKE '_transient_wc_%'
   OR option_name LIKE '_site_transient_wc_%'
   OR option_name LIKE '%woocommerce_cache%';</pre>

                <p style="color:#b32d2e;">
                    Lưu ý: Không xóa option cấu hình WooCommerce như
                    <code>woocommerce_currency</code>,
                    <code>woocommerce_manage_stock</code>,
                    <code>woocommerce_shop_page_id</code>.
                    Chỉ xóa transient/cache.
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      onsubmit="return confirm('Bạn chắc chắn muốn xóa toàn bộ transient/cache?');">

                    <input type="hidden" name="action" value="hpt_clear_transients">

                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

                    <?php submit_button('Xóa toàn bộ Transients / WooCommerce Cache', 'delete'); ?>
                </form>
            </div>
        </div>

        <?php
    }

    public function handle_clear_transients()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Bạn không có quyền thực hiện thao tác này.');
        }

        if (
            !isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)
        ) {
            wp_die('Nonce không hợp lệ.');
        }

        global $wpdb;

        $options_table = $wpdb->options;

        /*
         * Đếm trước số dòng sẽ xóa.
         */
        $count_sql = "
            SELECT COUNT(*)
            FROM {$options_table}
            WHERE option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
        ";

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                $count_sql,
                '\_transient\_%',
                '\_site\_transient\_%',
                '\_transient\_wc\_%',
                '\_site\_transient\_wc\_%',
                '%woocommerce_cache%'
            )
        );

        /*
         * Xóa toàn bộ transient WordPress + WooCommerce.
         */
        $delete_sql = "
            DELETE FROM {$options_table}
            WHERE option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
               OR option_name LIKE %s
        ";

        $wpdb->query(
            $wpdb->prepare(
                $delete_sql,
                '\_transient\_%',
                '\_site\_transient\_%',
                '\_transient\_wc\_%',
                '\_site\_transient\_wc\_%',
                '%woocommerce_cache%'
            )
        );

        /*
         * Xóa thêm cache runtime/object cache nếu site có dùng.
         * Ví dụ Redis Object Cache, Memcached, object-cache.php...
         */
        $object_cache_flushed = 'no';

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $object_cache_flushed = 'yes';
        }

        /*
         * Xóa WooCommerce product transients nếu WooCommerce đang active.
         */
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }

        /*
         * Xóa WooCommerce template cache nếu hàm tồn tại.
         */
        if (function_exists('wc_clear_template_cache')) {
            wc_clear_template_cache();
        }

        $redirect_url = add_query_arg(
            [
                'page'    => self::MENU_SLUG,
                'deleted' => $total,
                'flushed' => $object_cache_flushed,
            ],
            admin_url('tools.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }
}

new HPT_Clear_Transient_Tool();