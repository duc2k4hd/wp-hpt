<?php
/**
 * Plugin Name: HPT Bulk Product Writer Tool
 * Description: Them shortcut trong Tools de mo Bulk Product Writer tu wp-admin.
 * Author: Hai Phong Tech
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class HPT_Bulk_Product_Writer_Tool
{
    private const PAGE_SLUG = 'hpt-bulk-product-writer';
    private const CAPABILITY = 'manage_woocommerce';
    private const TOOL_FILE = 'write_articles_in_bulk.php';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_page']);
    }

    public function register_admin_page(): void
    {
        add_management_page(
            'Bulk Product Writer',
            'Bulk Product Writer',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    private function get_tool_path(): string
    {
        return trailingslashit(ABSPATH) . self::TOOL_FILE;
    }

    private function get_tool_url(): string
    {
        return site_url('/' . self::TOOL_FILE);
    }

    private function tool_exists(): bool
    {
        return is_file($this->get_tool_path());
    }

    public function render_page(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Ban khong co quyen truy cap trang nay.');
        }

        $tool_url  = $this->get_tool_url();
        $tool_path = $this->get_tool_path();
        ?>
        <div class="wrap">
            <h1>Bulk Product Writer</h1>
            <p>
                Tool import nay van dung file goc <code><?php echo esc_html(self::TOOL_FILE); ?></code>,
                nhung da co shortcut trong <code>Tools</code> de mo nhanh tu <code>wp-admin</code>.
            </p>

            <?php if (!$this->tool_exists()) : ?>
                <div class="notice notice-error">
                    <p>
                        Khong tim thay file tool tai:
                        <code><?php echo esc_html($tool_path); ?></code>
                    </p>
                </div>
            <?php else : ?>
                <div style="margin:16px 0 18px;display:flex;gap:12px;flex-wrap:wrap;">
                    <a class="button button-primary button-large" href="<?php echo esc_url($tool_url); ?>" target="_blank" rel="noopener noreferrer">
                        Mo tool full page
                    </a>
                    <a class="button button-large" href="<?php echo esc_url(admin_url('tools.php?page=' . self::PAGE_SLUG)); ?>">
                        Tai lai khung embed
                    </a>
                </div>

                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);">
                    <iframe
                        src="<?php echo esc_url($tool_url); ?>"
                        title="Bulk Product Writer"
                        style="display:block;width:100%;min-height:calc(100vh - 220px);border:0;background:#fff;"
                    ></iframe>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

new HPT_Bulk_Product_Writer_Tool();
