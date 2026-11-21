<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders all WordPress admin pages.
 */
class ST_Admin {

    private static ?ST_Admin $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
    }

    // ── Menu ────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        if ( ! st_is_user_allowed() ) {
            return;
        }

        add_menu_page(
            __( 'Staff Tracking', 'staff-tracking' ),
            __( 'Staff Tracking', 'staff-tracking' ),
            'manage_options',
            'staff-tracking',
            [ $this, 'page_dashboard' ],
            'dashicons-chart-bar',
            58
        );

        add_submenu_page( 'staff-tracking', __( 'Dashboard',    'staff-tracking' ), __( 'Dashboard',    'staff-tracking' ), 'manage_options', 'staff-tracking',          [ $this, 'page_dashboard' ] );
        add_submenu_page( 'staff-tracking', __( 'Nhân sự',      'staff-tracking' ), __( 'Nhân sự',      'staff-tracking' ), 'manage_options', 'st-personnel',            [ $this, 'page_personnel' ] );
        add_submenu_page( 'staff-tracking', __( 'Phân công KPI','staff-tracking' ), __( 'Phân công KPI',  'staff-tracking' ), 'manage_options', 'st-assignments',          [ $this, 'page_assignments' ] );
        add_submenu_page( 'staff-tracking', __( 'Tính tiền & Chuyển tiền', 'staff-tracking' ), __( 'Tính tiền & Chuyển tiền', 'staff-tracking' ), 'manage_options', 'st-payouts', [ $this, 'page_payouts' ] );
        add_submenu_page( 'staff-tracking', __( 'Logs',         'staff-tracking' ), __( 'Logs',         'staff-tracking' ), 'manage_options', 'st-logs',                 [ $this, 'page_logs' ] );
        add_submenu_page( 'staff-tracking', __( 'Xuất dữ liệu',  'staff-tracking' ), __( 'Xuất dữ liệu',  'staff-tracking' ), 'manage_options', 'st-export',               [ $this, 'page_export' ] );
        add_submenu_page( 'staff-tracking', __( 'Cài đặt',      'staff-tracking' ), __( 'Cài đặt',      'staff-tracking' ), 'manage_options', 'st-settings',             [ $this, 'page_settings' ] );
    }

    // ── Assets ─────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        $pages = [
            'toplevel_page_staff-tracking',
            'staff-tracking_page_st-personnel',
            'staff-tracking_page_st-assignments',
            'staff-tracking_page_st-payouts',
            'staff-tracking_page_st-logs',
            'staff-tracking_page_st-export',
            'staff-tracking_page_st-settings',
        ];
        if ( ! in_array( $hook, $pages, true ) ) {
            return;
        }

        // 1. Luôn load CSS và JS cốt lõi của admin
        wp_enqueue_style(
            'st-admin',
            ST_PLUGIN_URL . 'assets/admin/admin.css',
            [],
            ST_VERSION
        );

        wp_enqueue_script(
            'st-admin',
            ST_PLUGIN_URL . 'assets/admin/admin.js',
            [ 'jquery' ],
            ST_VERSION,
            true
        );

        wp_localize_script( 'st-admin', 'STAdmin', [
            'logsEndpoint'      => esc_url_raw( rest_url( 'staff/v1/logs' ) ),
            'assignEndpoint'    => esc_url_raw( rest_url( 'staff/v1/assignments' ) ),
            'personnelEndpoint' => esc_url_raw( rest_url( 'staff/v1/personnel' ) ),
            'kpiEndpoint'       => esc_url_raw( rest_url( 'staff/v1/kpi' ) ),
            'settingsEndpoint'  => esc_url_raw( rest_url( 'staff/v1/settings' ) ),
            'payoutsEndpoint'   => esc_url_raw( rest_url( 'staff/v1/payouts' ) ),
            'exportUrl'    => esc_url_raw( admin_url( 'admin-post.php?action=st_export_csv' ) ),
            'searchProductsEndpoint' => esc_url_raw( rest_url( 'staff/v1/products' ) ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'adminNonce'   => wp_create_nonce( 'st_admin_nonce' ),
            'teamsList'    => ST_Database::get_distinct_teams(),
            'classesList'  => ST_Database::get_distinct_classes(),
            'staffList'    => ST_Database::get_distinct_personnel(),
        ] );

        // 2. Chỉ load SlimSelect trên trang Nhân sự và Phân công KPI
        if ( $hook === 'staff-tracking_page_st-personnel' || $hook === 'staff-tracking_page_st-assignments' ) {
            wp_enqueue_style(
                'slimselect',
                'https://cdn.jsdelivr.net/npm/slim-select@2.9.0/dist/slimselect.min.css',
                [],
                '2.9.0'
            );
            wp_enqueue_script(
                'slimselect',
                'https://cdn.jsdelivr.net/npm/slim-select@2.9.0/dist/slimselect.min.js',
                [],
                '2.9.0',
                true
            );
        }

        // 3. Chỉ load SheetJS trên trang Nhân sự và Phân công KPI (để import/export Excel)
        if ( $hook === 'staff-tracking_page_st-personnel' || $hook === 'staff-tracking_page_st-assignments' ) {
            wp_enqueue_script(
                'sheetjs',
                'https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js',
                [],
                '0.20.3',
                true
            );
        }

        // 4. Chỉ load JS của phân hệ Nhân sự
        if ( $hook === 'staff-tracking_page_st-personnel' ) {
            wp_enqueue_script(
                'st-personnel',
                ST_PLUGIN_URL . 'assets/admin/personnel.js',
                [ 'jquery', 'st-admin', 'sheetjs' ],
                ST_VERSION,
                true
            );
        }

        // 5. Chỉ load JS của phân hệ Phân công KPI
        if ( $hook === 'staff-tracking_page_st-assignments' ) {
            wp_enqueue_script(
                'st-assignments',
                ST_PLUGIN_URL . 'assets/admin/assignments.js',
                [ 'jquery', 'st-admin', 'sheetjs' ],
                ST_VERSION,
                true
            );
        }

        // 6. Chỉ load JS của phân hệ Cài đặt
        if ( $hook === 'staff-tracking_page_st-settings' ) {
            wp_enqueue_script(
                'st-settings',
                ST_PLUGIN_URL . 'assets/admin/settings.js',
                [ 'jquery', 'st-admin' ],
                ST_VERSION,
                true
            );
        }

        // 7. Chỉ load JS của phân hệ Tính tiền & Chuyển tiền
        if ( $hook === 'staff-tracking_page_st-payouts' ) {
            wp_enqueue_script(
                'st-payouts',
                ST_PLUGIN_URL . 'assets/admin/payouts.js',
                [ 'jquery', 'st-admin' ],
                ST_VERSION,
                true
            );
        }
    }

    // ── Pages ────────────────────────────────────────────────────────────────

    public function page_dashboard(): void {
        ?>
        <div class="wrap st-wrap">
            <h1 class="st-page-title" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <?php esc_html_e( 'Staff Tracking — Dashboard', 'staff-tracking' ); ?>
                </div>
                <button type="button" class="button button-primary" id="st-kpi-refresh" style="display: inline-flex; align-items: center; gap: 6px; padding: 0 12px; height: 30px;">
                    <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px; margin: 0;"></span>
                    <?php esc_html_e( 'Làm mới số liệu', 'staff-tracking' ); ?>
                </button>
            </h1>

            <!-- Date filter -->
            <?php $this->render_date_filter(); ?>

            <!-- KPI cards -->
            <div class="st-kpi-grid" id="st-kpi-grid">
                <?php $this->render_kpi_skeleton(); ?>
            </div>

            <!-- Top tables -->
            <div class="st-top-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
                <div class="st-card">
                    <h3><?php esc_html_e( 'Top Nhân Viên', 'staff-tracking' ); ?></h3>
                    <table class="widefat striped" id="st-top-staff">
                        <thead><tr><th><?php esc_html_e( 'Tên', 'staff-tracking' ); ?></th><th><?php esc_html_e( 'Sessions', 'staff-tracking' ); ?></th></tr></thead>
                        <tbody><tr><td colspan="2" class="st-loading"><?php esc_html_e( 'Đang tải…', 'staff-tracking' ); ?></td></tr></tbody>
                    </table>
                </div>
                <div class="st-card">
                    <h3><?php esc_html_e( 'Top Mã Lớp', 'staff-tracking' ); ?></h3>
                    <table class="widefat striped" id="st-top-classes">
                        <thead><tr><th><?php esc_html_e( 'Lớp', 'staff-tracking' ); ?></th><th><?php esc_html_e( 'Sessions', 'staff-tracking' ); ?></th></tr></thead>
                        <tbody><tr><td colspan="2" class="st-loading"><?php esc_html_e( 'Đang tải…', 'staff-tracking' ); ?></td></tr></tbody>
                    </table>
                </div>
                <div class="st-card">
                    <h3><?php esc_html_e( 'Top Ngành Học (Team)', 'staff-tracking' ); ?></h3>
                    <table class="widefat striped" id="st-top-teams">
                        <thead><tr><th><?php esc_html_e( 'Ngành / Team', 'staff-tracking' ); ?></th><th><?php esc_html_e( 'Sessions', 'staff-tracking' ); ?></th></tr></thead>
                        <tbody><tr><td colspan="2" class="st-loading"><?php esc_html_e( 'Đang tải…', 'staff-tracking' ); ?></td></tr></tbody>
                    </table>
                </div>
            </div>

            <!-- Grid phân tích chi tiết bổ sung -->
            <div class="st-top-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); margin-top: 20px;">
                <!-- Top Pages -->
                <div class="st-card">
                    <h3><?php esc_html_e( 'Top Bài Viết Nhiều Traffic Nhất', 'staff-tracking' ); ?></h3>
                    <table class="widefat striped" id="st-top-pages">
                        <thead><tr><th><?php esc_html_e( 'Đường dẫn bài viết', 'staff-tracking' ); ?></th><th><?php esc_html_e( 'Sessions', 'staff-tracking' ); ?></th></tr></thead>
                        <tbody><tr><td colspan="2" class="st-loading"><?php esc_html_e( 'Đang tải…', 'staff-tracking' ); ?></td></tr></tbody>
                    </table>
                </div>

                <!-- Thiết bị & Trình duyệt -->
                <div class="st-card">
                    <h3><?php esc_html_e( 'Phân Tích Thiết Bị & Trình Duyệt', 'staff-tracking' ); ?></h3>
                    <div id="st-analysis-devices" style="margin-bottom: 20px;">
                        <h4 style="margin: 0 0 8px; font-size: 12px; color: #787c82; font-weight:600; text-transform: uppercase;"><?php esc_html_e( 'Thiết bị truy cập', 'staff-tracking' ); ?></h4>
                        <div class="st-device-bars st-loading-container">
                            <span class="st-loading"><?php esc_html_e( 'Đang tải…', 'staff-tracking' ); ?></span>
                        </div>
                    </div>
                    <div id="st-analysis-browsers">
                        <h4 style="margin: 0 0 8px; font-size: 12px; color: #787c82; font-weight:600; text-transform: uppercase;"><?php esc_html_e( 'Trình duyệt sử dụng', 'staff-tracking' ); ?></h4>
                        <div class="st-browser-bars st-loading-container">
                            <span class="st-loading"><?php esc_html_e( 'Đang tải…', 'staff-tracking' ); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Khung giờ hoạt động -->
                <div class="st-card">
                    <h3><?php esc_html_e( 'Khung Giờ Hoạt Động Cao Điểm', 'staff-tracking' ); ?></h3>
                    <div id="st-analysis-hourly">
                        <div class="st-hourly-bars st-loading-container">
                            <span class="st-loading"><?php esc_html_e( 'Đang tải…', 'staff-tracking' ); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function page_logs(): void {
        ?>
        <div class="wrap st-wrap">
            <h1 class="st-page-title">
                <span class="dashicons dashicons-list-view"></span>
                <?php esc_html_e( 'Staff Tracking — Logs', 'staff-tracking' ); ?>
            </h1>

            <!-- Filters -->
            <div class="st-card st-filters" id="st-filters">
                <?php $this->render_date_filter(); ?>
                <div class="st-filter-row" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 15px;">
                    <input type="text"  id="st-filter-staff" placeholder="<?php esc_attr_e( 'Họ tên…', 'staff-tracking' ); ?>" class="regular-text" style="width: 140px;">
                    <input type="text"  id="st-filter-empcode" placeholder="<?php esc_attr_e( 'Mã NV…', 'staff-tracking' ); ?>" class="regular-text" style="width: 100px;">
                    <input type="text"  id="st-filter-classcode" placeholder="<?php esc_attr_e( 'Mã Lớp…', 'staff-tracking' ); ?>" class="regular-text" style="width: 100px;">
                    <input type="text"  id="st-filter-team"  placeholder="<?php esc_attr_e( 'Team…', 'staff-tracking' ); ?>" class="regular-text" style="width: 120px;">
                    <select id="st-filter-valid" style="width: 120px;">
                        <option value=""><?php esc_html_e( 'Tất cả trạng thái', 'staff-tracking' ); ?></option>
                        <option value="1"><?php esc_html_e( 'Hợp lệ', 'staff-tracking' ); ?></option>
                        <option value="0"><?php esc_html_e( 'Không hợp lệ', 'staff-tracking' ); ?></option>
                    </select>
                    <label style="display:flex; align-items:center; gap:4px; font-weight: 500;">
                        <input type="checkbox" id="st-filter-exact" value="1"> <?php esc_html_e( 'Chính xác', 'staff-tracking' ); ?>
                    </label>
                    <button class="button button-primary" id="st-apply-filters"><?php esc_html_e( 'Lọc', 'staff-tracking' ); ?></button>
                    <button class="button" id="st-reset-filters"><?php esc_html_e( 'Reset', 'staff-tracking' ); ?></button>
                    <a href="#" class="button button-secondary" id="st-export-csv" target="_blank" style="margin-left: auto;">
                        ⬇ <?php esc_html_e( 'Export CSV', 'staff-tracking' ); ?>
                    </a>
                </div>
            </div>

            <!-- Table -->
            <div class="st-card">
                <table class="widefat striped st-table" id="st-logs-table">
                    <thead>
                        <tr>
                            <th data-col="created_at" class="sortable"><?php esc_html_e( 'Thời gian', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                            <th data-col="user_name"  class="sortable"><?php esc_html_e( 'Họ tên', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                            <th data-col="emp_code"   class="sortable"><?php esc_html_e( 'Mã NV', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                            <th data-col="class_code" class="sortable"><?php esc_html_e( 'Mã Lớp', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                            <th data-col="team"       class="sortable"><?php esc_html_e( 'Team', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                            <th><?php esc_html_e( 'IP', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'URL', 'staff-tracking' ); ?></th>
                            <th data-col="duration"   class="sortable"><?php esc_html_e( 'Thời gian (s)', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                            <th><?php esc_html_e( 'Hợp lệ', 'staff-tracking' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Thao tác', 'staff-tracking' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="st-logs-body">
                        <tr><td colspan="10" class="st-loading"><?php esc_html_e( 'Đang tải…', 'staff-tracking' ); ?></td></tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div class="st-pagination" id="st-pagination"></div>
            </div>
        </div>

        <!-- Behavior Logs Modal -->
        <div id="st-behavior-modal" class="st-modal-overlay" style="display:none;">
            <div class="st-modal-content" style="max-width: 720px; width: 95%;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 15px;">
                    <h3 style="margin: 0;" id="st-behavior-modal-title"><?php esc_html_e( 'Lịch sử hành vi chi tiết', 'staff-tracking' ); ?></h3>
                    <span id="st-behavior-modal-close" style="font-size: 24px; font-weight: bold; cursor: pointer; color: #a0aec0;">&times;</span>
                </div>
                <div id="st-behavior-modal-body" style="max-height: 480px; overflow-y: auto;">
                    <!-- Content loaded dynamically via JS -->
                </div>
            </div>
        </div>
        <?php
    }

    public function page_export(): void {
        ?>
        <div class="wrap st-wrap">
            <h1 class="st-page-title">
                <span class="dashicons dashicons-download"></span>
                <?php esc_html_e( 'Staff Tracking — Export', 'staff-tracking' ); ?>
            </h1>

            <div class="st-card st-export-card">
                <h2><?php esc_html_e( 'Xuất dữ liệu CSV', 'staff-tracking' ); ?></h2>
                <p><?php esc_html_e( 'Chọn khoảng thời gian và bộ lọc, sau đó nhấn Export.', 'staff-tracking' ); ?></p>

                <?php $this->render_date_filter( 'export' ); ?>

                <div class="st-export-filters">
                    <label><?php esc_html_e( 'Họ tên:', 'staff-tracking' ); ?></label>
                    <input type="text" id="st-exp-staff" class="regular-text" placeholder="<?php esc_attr_e( 'Để trống = tất cả', 'staff-tracking' ); ?>">

                    <label><?php esc_html_e( 'Mã NV:', 'staff-tracking' ); ?></label>
                    <input type="text" id="st-exp-empcode" class="regular-text" placeholder="<?php esc_attr_e( 'Để trống = tất cả', 'staff-tracking' ); ?>">

                    <label><?php esc_html_e( 'Mã Lớp:', 'staff-tracking' ); ?></label>
                    <input type="text" id="st-exp-classcode" class="regular-text" placeholder="<?php esc_attr_e( 'Để trống = tất cả', 'staff-tracking' ); ?>">

                    <label><?php esc_html_e( 'Team:', 'staff-tracking' ); ?></label>
                    <input type="text" id="st-exp-team" class="regular-text" placeholder="<?php esc_attr_e( 'Để trống = tất cả', 'staff-tracking' ); ?>">

                    <label style="display:flex; align-items:center; gap:4px; margin-top:10px;">
                        <input type="checkbox" id="st-exp-exact" value="1"> <strong><?php esc_html_e( 'Tìm kiếm chính xác', 'staff-tracking' ); ?></strong>
                    </label>

                    <label style="margin-top:10px;"><?php esc_html_e( 'Chỉ hợp lệ:', 'staff-tracking' ); ?></label>
                    <select id="st-exp-valid">
                        <option value=""><?php esc_html_e( 'Tất cả', 'staff-tracking' ); ?></option>
                        <option value="1"><?php esc_html_e( 'Chỉ hợp lệ (≥30s)', 'staff-tracking' ); ?></option>
                    </select>
                </div>

                <p class="st-export-actions">
                    <a href="#" class="button button-primary button-large" id="st-do-export">
                        ⬇ <?php esc_html_e( 'Download CSV', 'staff-tracking' ); ?>
                    </a>
                </p>
                <p class="description">
                    <?php esc_html_e( 'File CSV tương thích Excel, mã hóa UTF-8 BOM.', 'staff-tracking' ); ?>
                </p>
            </div>
        </div>
        <?php
    }

    // ── Partials ─────────────────────────────────────────────────────────────

    private function render_date_filter( string $prefix = 'dash' ): void {
        $today  = gmdate( 'Y-m-d' );
        $week   = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
        $month  = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        ?>
        <div class="st-date-filter" data-prefix="<?php echo esc_attr( $prefix ); ?>">
            <button class="button st-preset active" data-range="today"
                    data-start="<?php echo esc_attr( $today ); ?>"
                    data-end="<?php echo esc_attr( $today ); ?>">
                <?php esc_html_e( 'Hôm nay', 'staff-tracking' ); ?>
            </button>
            <button class="button st-preset" data-range="7d"
                    data-start="<?php echo esc_attr( $week ); ?>"
                    data-end="<?php echo esc_attr( $today ); ?>">
                <?php esc_html_e( '7 ngày', 'staff-tracking' ); ?>
            </button>
            <button class="button st-preset" data-range="30d"
                    data-start="<?php echo esc_attr( $month ); ?>"
                    data-end="<?php echo esc_attr( $today ); ?>">
                <?php esc_html_e( '30 ngày', 'staff-tracking' ); ?>
            </button>
            <span class="st-date-sep"><?php esc_html_e( 'hoặc:', 'staff-tracking' ); ?></span>
            <input type="date" class="st-date-from" value="<?php echo esc_attr( $today ); ?>">
            <span>→</span>
            <input type="date" class="st-date-to"   value="<?php echo esc_attr( $today ); ?>">
            <button class="button button-primary st-apply-date">
                <?php esc_html_e( 'Áp dụng', 'staff-tracking' ); ?>
            </button>
        </div>
        <?php
    }

    private function render_kpi_skeleton(): void {
        $cards = [
            [ 'id' => 'kpi-total',    'label' => 'Tổng Sessions' ],
            [ 'id' => 'kpi-valid',    'label' => 'Session Hợp Lệ' ],
            [ 'id' => 'kpi-rate',     'label' => 'Tỷ lệ hợp lệ' ],
            [ 'id' => 'kpi-avg',      'label' => 'Avg Onsite (s)' ],
        ];
        foreach ( $cards as $c ) {
            printf(
                '<div class="st-kpi-card"><span class="st-kpi-label">%s</span><span class="st-kpi-value" id="%s">—</span></div>',
                esc_html( $c['label'] ),
                esc_attr( $c['id'] )
            );
        }
    }

    public function page_personnel(): void {
        $class_filter = isset( $_GET['class'] ) ? sanitize_text_field( trim( $_GET['class'] ) ) : '';
        ?>
        <div class="wrap st-wrap">
            <h1 class="st-page-title">
                <span class="dashicons dashicons-groups"></span>
                <?php if ( ! empty( $class_filter ) ) : ?>
                    <?php printf( esc_html__( 'Nhân sự Lớp: %s', 'staff-tracking' ), esc_html( $class_filter ) ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Quản lý Lớp (Nhân sự)', 'staff-tracking' ); ?>
                <?php endif; ?>
            </h1>

            <div class="st-card st-filters">
                <div class="st-toolbar-wrapper" style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 8px;">
                    
                    <!-- Left: Filters & Search -->
                    <div class="st-toolbar-left" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; flex: 1;">
                        <?php if ( ! empty( $class_filter ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=st-personnel' ) ); ?>" class="button button-secondary" style="display: flex; align-items: center; gap: 4px;">
                                <span class="dashicons dashicons-arrow-left-alt" style="margin-top: 3px;"></span>
                                <?php esc_html_e( 'Quay lại', 'staff-tracking' ); ?>
                            </a>
                        <?php endif; ?>

                        <input type="date" id="st-pers-start-date" class="regular-text" style="width: 130px;" title="<?php esc_attr_e( 'Từ ngày', 'staff-tracking' ); ?>">
                        <span>-</span>
                        <input type="date" id="st-pers-end-date" class="regular-text" style="width: 130px;" title="<?php esc_attr_e( 'Đến ngày', 'staff-tracking' ); ?>">
                        
                        <input type="text" id="st-pers-search" placeholder="<?php echo ! empty( $class_filter ) ? esc_attr__( 'Tên, Mã NV...', 'staff-tracking' ) : esc_attr__( 'Mã Lớp...', 'staff-tracking' ); ?>" class="regular-text" style="width: 160px;">
                        <input type="text" id="st-pers-team" placeholder="<?php esc_attr_e( 'Team/Ngành...', 'staff-tracking' ); ?>" class="regular-text" style="width: 120px;">
                        
                        <label style="display:flex; align-items:center; gap:4px; font-weight: 500;">
                            <input type="checkbox" id="st-pers-exact" value="1"> <?php esc_html_e( 'Chính xác', 'staff-tracking' ); ?>
                        </label>
                        
                        <button class="button button-primary" id="st-pers-filter-btn"><?php esc_html_e( 'Tìm', 'staff-tracking' ); ?></button>
                        <button class="button" id="st-pers-reset-btn"><?php esc_html_e( 'Reset', 'staff-tracking' ); ?></button>
                    </div>

                    <!-- Right: Actions -->
                    <div class="st-toolbar-right" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <button class="button button-secondary" id="st-pers-export-btn" style="display: flex; align-items: center; gap: 4px;">
                            <span class="dashicons dashicons-download" style="font-size: 16px; width: 16px; height: 16px; line-height: 1;"></span>
                            <?php esc_html_e( 'Xuất Excel', 'staff-tracking' ); ?>
                        </button>
                        <button class="button button-secondary" id="st-pers-import-btn" style="display: flex; align-items: center; gap: 4px;">
                            <span class="dashicons dashicons-upload" style="font-size: 16px; width: 16px; height: 16px; line-height: 1;"></span>
                            <?php esc_html_e( 'Nhập CSV', 'staff-tracking' ); ?>
                        </button>
                        <button class="button button-primary" id="st-add-personnel-btn">+ <?php esc_html_e( 'Thêm mới', 'staff-tracking' ); ?></button>
                    </div>
                </div>
            </div>

            <div class="st-card">
                <table class="widefat striped st-table" id="st-personnel-table" data-class="<?php echo esc_attr( $class_filter ); ?>">
                    <thead>
                        <?php if ( ! empty( $class_filter ) ) : ?>
                            <tr>
                                <th style="width: 30px;"></th>
                                <th><?php esc_html_e( 'Họ tên', 'staff-tracking' ); ?></th>
                                <th><?php esc_html_e( 'Mã NV', 'staff-tracking' ); ?></th>
                                <th><?php esc_html_e( 'Mã Lớp', 'staff-tracking' ); ?></th>
                                <th><?php esc_html_e( 'Team / Ngành', 'staff-tracking' ); ?></th>
                                <th class="sortable" data-col="total_logs"><?php esc_html_e( 'Tổng Traffic', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                                <th class="sortable" data-col="valid_logs"><?php esc_html_e( 'Hợp lệ', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                                <th class="sortable" data-col="valid_rate"><?php esc_html_e( 'Tỷ lệ hợp lệ', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                                <th class="sortable" data-col="avg_duration"><?php esc_html_e( 'Avg Onsite', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                                <th class="sortable" data-col="last_active"><?php esc_html_e( 'Hoạt động cuối', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                                <th style="width: 140px; text-align: right;"><?php esc_html_e( 'Thao tác', 'staff-tracking' ); ?></th>
                            </tr>
                        <?php else : ?>
                            <tr>
                                <th style="width: 30px;"></th>
                                <th><?php esc_html_e( 'Mã Lớp', 'staff-tracking' ); ?></th>
                                <th><?php esc_html_e( 'Số Nhân sự', 'staff-tracking' ); ?></th>
                                <th class="sortable" data-col="total_logs"><?php esc_html_e( 'Tổng Traffic', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                                <th class="sortable" data-col="valid_logs"><?php esc_html_e( 'Hợp lệ', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                                <th class="sortable" data-col="valid_rate"><?php esc_html_e( 'Tỷ lệ hợp lệ', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                                <th class="sortable" data-col="avg_duration"><?php esc_html_e( 'Avg Onsite', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                                <th class="sortable" data-col="last_active"><?php esc_html_e( 'Hoạt động cuối', 'staff-tracking' ); ?> <span class="sort-icon">↕</span></th>
                                <th style="width: 140px; text-align: right;"><?php esc_html_e( 'Hành động', 'staff-tracking' ); ?></th>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody id="st-personnel-body">
                        <tr><td colspan="11" class="st-loading"><?php esc_html_e( 'Đang tải...', 'staff-tracking' ); ?></td></tr>
                    </tbody>
                </table>
                <div class="st-pagination" id="st-pers-pagination"></div>
            </div>
        </div>

        <!-- Add/Edit Personnel Modal -->
        <div id="st-personnel-modal" class="st-modal-overlay" style="display:none;">
            <div class="st-modal-content" style="max-width: 480px; width: 95%;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 15px;">
                    <h3 style="margin: 0;" id="st-personnel-modal-title"><?php esc_html_e( 'Thêm nhân sự mới', 'staff-tracking' ); ?></h3>
                    <span id="st-personnel-modal-close" style="font-size: 24px; font-weight: bold; cursor: pointer; color: #a0aec0;">&times;</span>
                </div>
                <form id="st-personnel-form">
                    <input type="hidden" id="st-personnel-id" value="">
                    
                    <div style="margin-bottom: 12px;">
                        <label for="st-pers-form-name" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Họ tên:', 'staff-tracking' ); ?></label>
                        <input type="text" id="st-pers-form-name" class="regular-text" style="width: 100%;" required placeholder="Nguyễn Văn A">
                    </div>
                    
                    <div style="margin-bottom: 12px;">
                        <label for="st-pers-form-empcode" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Mã nhân viên:', 'staff-tracking' ); ?></label>
                        <input type="text" id="st-pers-form-empcode" class="regular-text" style="width: 100%;" required placeholder="NV001">
                    </div>
                    
                    <div style="margin-bottom: 12px;">
                        <label for="st-pers-form-classcode" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Mã lớp:', 'staff-tracking' ); ?></label>
                        <input type="text" id="st-pers-form-classcode" class="regular-text" style="width: 100%;" required placeholder="K01">
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label for="st-pers-form-team" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Team / Ngành:', 'staff-tracking' ); ?></label>
                        <select id="st-pers-form-team" style="width: 100%;">
                            <option value=""><?php esc_html_e( '-- Chọn hoặc Nhập Ngành học --', 'staff-tracking' ); ?></option>
                            <option value="Công nghệ thông tin (IT)">Công nghệ thông tin (IT)</option>
                            <option value="Khoa học máy tính">Khoa học máy tính</option>
                            <option value="Kỹ thuật phần mềm">Kỹ thuật phần mềm</option>
                            <option value="Quản trị kinh doanh">Quản trị kinh doanh</option>
                            <option value="Kế toán - Kiểm toán">Kế toán - Kiểm toán</option>
                            <option value="Tài chính - Ngân hàng">Tài chính - Ngân hàng</option>
                            <option value="Marketing">Marketing</option>
                            <option value="Truyền thông đa phương tiện">Truyền thông đa phương tiện</option>
                            <option value="Báo chí">Báo chí</option>
                            <option value="Logistics và Quản lý chuỗi cung ứng">Logistics và Quản lý chuỗi cung ứng</option>
                            <option value="Kinh tế quốc tế / Xuất nhập khẩu">Kinh tế quốc tế / Xuất nhập khẩu</option>
                            <option value="Ngôn ngữ Anh">Ngôn ngữ Anh</option>
                            <option value="Ngôn ngữ Trung Quốc">Ngôn ngữ Trung Quốc</option>
                            <option value="Ngôn ngữ Nhật">Ngôn ngữ Nhật</option>
                            <option value="Ngôn ngữ Hàn Quốc">Ngôn ngữ Hàn Quốc</option>
                            <option value="Y khoa">Y khoa</option>
                            <option value="Dược học">Dược học</option>
                            <option value="Điều dưỡng">Điều dưỡng</option>
                            <option value="Răng Hàm Mặt">Răng Hàm Mặt</option>
                            <option value="Kỹ thuật ô tô">Kỹ thuật ô tô</option>
                            <option value="Kỹ thuật điện, điện tử">Kỹ thuật điện, điện tử</option>
                            <option value="Kỹ thuật cơ điện tử">Kỹ thuật cơ điện tử</option>
                            <option value="Kỹ thuật điều khiển và tự động hóa">Kỹ thuật điều khiển và tự động hóa</option>
                            <option value="Công nghệ thực phẩm">Công nghệ thực phẩm</option>
                            <option value="Kiến trúc">Kiến trúc</option>
                            <option value="Kỹ thuật xây dựng">Kỹ thuật xây dựng</option>
                            <option value="Thiết kế đồ họa">Thiết kế đồ họa</option>
                            <option value="Thiết kế nội thất">Thiết kế nội thất</option>
                            <option value="Du lịch - Khách sạn">Du lịch - Khách sạn</option>
                            <option value="Luật kinh tế">Luật kinh tế</option>
                            <option value="Luật học">Luật học</option>
                            <option value="Sư phạm">Sư phạm</option>
                            <option value="Nông nghiệp và Phát triển nông thôn">Nông nghiệp và Phát triển nông thôn</option>
                            <option value="Tâm lý học">Tâm lý học</option>
                            <option value="Quản trị nhân sự">Quản trị nhân sự</option>
                            <option value="Quan hệ công chúng (PR)">Quan hệ công chúng (PR)</option>
                            <option value="Thương mại điện tử">Thương mại điện tử</option>
                        </select>
                    </div>

                    <div id="st-pers-form-error" style="color: #d63638; font-weight: 600; margin-bottom: 12px; display: none;"></div>

                    <div style="display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
                        <button type="button" class="button" id="st-pers-form-cancel"><?php esc_html_e( 'Hủy', 'staff-tracking' ); ?></button>
                        <button type="submit" class="button button-primary" id="st-pers-form-submit"><?php esc_html_e( 'Lưu', 'staff-tracking' ); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Import Personnel Modal -->
        <div id="st-import-modal" class="st-modal-overlay" style="display:none;">
            <div class="st-modal-content" style="max-width: 480px; width: 95%;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 15px;">
                    <h3 style="margin: 0;"><?php esc_html_e( 'Nhập nhân sự từ file Excel/CSV', 'staff-tracking' ); ?></h3>
                    <span id="st-import-modal-close" style="font-size: 24px; font-weight: bold; cursor: pointer; color: #a0aec0;">&times;</span>
                </div>
                <form id="st-import-form" enctype="multipart/form-data">
                    <div style="margin-bottom: 15px;">
                        <p style="margin: 0 0 10px 0; color: #4a5568; font-size: 13px; line-height: 1.5;">
                            <?php esc_html_e( 'Chọn tệp tin Excel (.xls) hoặc CSV (.csv) chứa danh sách nhân sự. Cấu trúc các cột trong file tải lên phải trùng khớp với file được xuất ra từ hệ thống.', 'staff-tracking' ); ?>
                        </p>
                        <ul style="margin: 0 0 15px 0; padding-left: 20px; color: #4a5568; font-size: 13px; list-style-type: disc;">
                            <li><strong>Cột A (ID):</strong> Mã ID nhân sự để cập nhật (để trống nếu thêm mới)</li>
                            <li><strong>Cột B (Họ tên):</strong> Họ tên nhân viên</li>
                            <li><strong>Cột C (Mã NV):</strong> Mã số nhân viên (Phải là duy nhất)</li>
                            <li><strong>Cột D (Mã lớp):</strong> Mã lớp</li>
                            <li><strong>Cột E (Team/Ngành):</strong> Bộ phận hoặc ngành học</li>
                        </ul>
                        <label for="st-import-file-input" style="display: block; font-weight: 600; margin-bottom: 8px;"><?php esc_html_e( 'Chọn tệp tin:', 'staff-tracking' ); ?></label>
                        <input type="file" id="st-import-file-input" name="file" accept=".xls,.csv" required style="width: 100%; border: 1px solid #cbd5e0; padding: 6px; border-radius: 4px; background: #fff;">
                    </div>

                    <div id="st-import-progress-container" style="display:none; margin-top: 15px; margin-bottom: 15px;">
                        <div style="display: flex; justify-content: space-between; font-weight: 600; margin-bottom: 5px; font-size: 13px;">
                            <span id="st-import-progress-text"><?php esc_html_e( 'Đang xử lý: 0%', 'staff-tracking' ); ?></span>
                            <span id="st-import-progress-count">0 / 0</span>
                        </div>
                        <div style="background-color: #e2e8f0; border-radius: 4px; overflow: hidden; height: 12px; width: 100%;">
                            <div id="st-import-progress-bar" style="background-color: #1e7e34; width: 0%; height: 100%; transition: width 0.2s ease;"></div>
                        </div>
                    </div>

                    <div id="st-import-error" style="color: #d63638; font-weight: 600; margin-bottom: 12px; display: none;"></div>
                    <div id="st-import-success" style="color: #1e7e34; font-weight: 600; margin-bottom: 12px; display: none;"></div>

                    <div style="display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid #e2e8f0; padding-top: 12px;">
                        <button type="button" class="button" id="st-import-cancel"><?php esc_html_e( 'Hủy', 'staff-tracking' ); ?></button>
                        <button type="submit" class="button button-primary" id="st-import-submit"><?php esc_html_e( 'Tải lên & Nhập dữ liệu', 'staff-tracking' ); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Behavior Logs Modal -->
        <div id="st-behavior-modal" class="st-modal-overlay" style="display:none;">
            <div class="st-modal-content" style="max-width: 720px; width: 95%;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 15px;">
                    <h3 style="margin: 0;" id="st-behavior-modal-title"><?php esc_html_e( 'Lịch sử hành vi chi tiết', 'staff-tracking' ); ?></h3>
                    <span id="st-behavior-modal-close" style="font-size: 24px; font-weight: bold; cursor: pointer; color: #a0aec0;">&times;</span>
                </div>
                <div id="st-behavior-modal-body" style="max-height: 480px; overflow-y: auto;">
                    <!-- Content loaded dynamically via JS -->
                </div>
            </div>
        </div>
        <?php
    }

    public function page_assignments(): void {
        ?>
        <div class="wrap st-wrap">
            <h1 class="st-page-title">
                <span class="dashicons dashicons-clipboard"></span>
                <?php esc_html_e( 'Phân công KPI', 'staff-tracking' ); ?>
            </h1>

            <div class="st-card st-filters">
                <div class="st-filter-row" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    <select id="st-assign-period-type" style="width: 150px;">
                        <option value="all" selected><?php esc_html_e( 'Tất cả thời gian', 'staff-tracking' ); ?></option>
                        <option value="month"><?php esc_html_e( 'Theo tháng', 'staff-tracking' ); ?></option>
                        <option value="week"><?php esc_html_e( 'Theo tuần', 'staff-tracking' ); ?></option>
                        <option value="day"><?php esc_html_e( 'Theo ngày', 'staff-tracking' ); ?></option>
                        <option value="year"><?php esc_html_e( 'Theo năm', 'staff-tracking' ); ?></option>
                    </select>
                    <input type="month" id="st-assign-period-value" value="<?php echo date('Y-m'); ?>" class="regular-text" style="width: 160px; display: none;">
                    <button class="button button-primary" id="st-assign-filter-btn"><?php esc_html_e( 'Xem tiến độ', 'staff-tracking' ); ?></button>
                    <button class="button button-secondary" id="st-assign-add-btn" style="margin-left: auto;">+ <?php esc_html_e( 'Giao nhiệm vụ mới', 'staff-tracking' ); ?></button>
                </div>
            </div>

            <div class="st-card">
                <table class="widefat striped st-table" id="st-assignments-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Tên nhiệm vụ', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Thời gian', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Phạm vi', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Đối tượng nhận', 'staff-tracking' ); ?></th>
                                                        <th><?php esc_html_e( 'Liên kết sản phẩm', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Chỉ tiêu KPI', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Đã đạt được', 'staff-tracking' ); ?></th>
                            <th style="width:200px;"><?php esc_html_e( 'Tiến độ', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Người giao', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Trạng thái', 'staff-tracking' ); ?></th>
                            <th style="width: 150px; text-align: right;"><?php esc_html_e( 'Thao tác', 'staff-tracking' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="st-assignments-body">
                        <tr><td colspan="11" class="st-loading"><?php esc_html_e( 'Đang tải...', 'staff-tracking' ); ?></td></tr>
                    </tbody>
                </table>
                <div class="st-pagination" id="st-assign-pagination" style="margin-top: 15px;"></div>
            </div>
        </div>
 
        <!-- CRUD Modal cho Phân công -->
        <div id="st-assign-modal" class="st-modal-overlay" style="display:none;">
            <div class="st-modal-content">
                <h3 id="st-assign-modal-title"><?php esc_html_e( 'Giao nhiệm vụ mới', 'staff-tracking' ); ?></h3>
                <form id="st-assign-form">
                    <input type="hidden" name="id" id="st-assign-form-id" value="">
                    
                    <div class="st-form-group">
                        <label><?php esc_html_e( 'Tên nhiệm vụ / dự án', 'staff-tracking' ); ?> <span style="color:red;">*</span></label>
                        <input type="text" name="title" id="st-assign-form-title" required placeholder="Nhập tên nhiệm vụ..." style="width: 100%;">
                    </div>

                    <div class="st-form-group" id="st-assign-status-group" style="display: none;">
                        <label><?php esc_html_e( 'Trạng thái', 'staff-tracking' ); ?> <span style="color:red;">*</span></label>
                        <select name="status" id="st-assign-form-status" style="width: 100%;">
                            <option value="active"><?php esc_html_e( 'Đang hoạt động', 'staff-tracking' ); ?></option>
                            <option value="paused"><?php esc_html_e( 'Tạm dừng', 'staff-tracking' ); ?></option>
                            <option value="ended"><?php esc_html_e( 'Đã kết thúc', 'staff-tracking' ); ?></option>
                        </select>
                    </div>

                    <div class="st-form-group">
                        <label><?php esc_html_e( 'Loại thời gian', 'staff-tracking' ); ?> <span style="color:red;">*</span></label>
                        <select name="period_type" id="st-assign-form-period-type" required style="width: 100%;">
                            <option value="month"><?php esc_html_e( 'Theo tháng', 'staff-tracking' ); ?></option>
                            <option value="week"><?php esc_html_e( 'Theo tuần', 'staff-tracking' ); ?></option>
                            <option value="day"><?php esc_html_e( 'Theo ngày', 'staff-tracking' ); ?></option>
                            <option value="year"><?php esc_html_e( 'Theo năm', 'staff-tracking' ); ?></option>
                        </select>
                    </div>
                    <div class="st-form-group">
                        <label><?php esc_html_e( 'Giá trị thời gian', 'staff-tracking' ); ?> <span style="color:red;">*</span></label>
                        <input type="month" name="period_value" id="st-assign-form-period-value" value="<?php echo date('Y-m'); ?>" required style="width: 100%;">
                    </div>
                    
                    <div class="st-form-group">
                        <label><?php esc_html_e( 'Phạm vi phân công', 'staff-tracking' ); ?> <span style="color:red;">*</span></label>
                        <select name="assign_scope" id="st-assign-form-scope" required style="width: 100%;">
                            <option value="specific"><?php esc_html_e( 'Phân công theo đối tượng (Riêng)', 'staff-tracking' ); ?></option>
                            <option value="general"><?php esc_html_e( 'Phân công chung (Ai cũng làm được)', 'staff-tracking' ); ?></option>
                        </select>
                    </div>
 
                    <!-- Block giao đối tượng (chỉ hiện khi chọn specific) -->
                    <div id="st-assign-target-block">
                        <div class="st-form-group">
                            <label><?php esc_html_e( 'Giao KPI cho', 'staff-tracking' ); ?> <span style="color:red;">*</span></label>
                            <select name="target_type" id="st-assign-form-type" style="width: 100%;">
                                <option value="team"><?php esc_html_e( 'Team / Ngành học', 'staff-tracking' ); ?></option>
                                <option value="class_code"><?php esc_html_e( 'Lớp (Mã Lớp)', 'staff-tracking' ); ?></option>
                                <option value="emp_code"><?php esc_html_e( 'Nhân viên (Mã NV)', 'staff-tracking' ); ?></option>
                            </select>
                        </div>
                        <div class="st-form-group">
                            <label><?php esc_html_e( 'Chọn Đối tượng nhận', 'staff-tracking' ); ?> <span style="color:red;">*</span></label>
                             <select id="st-assign-form-value-select" style="width: 100%;" multiple="multiple">
                                <!-- Sẽ được render động bằng JS -->
                            </select>
                        </div>
                        <div class="st-form-group" id="st-assign-custom-target-block" style="display:none;">
                            <label><?php esc_html_e( 'Nhập đối tượng mới', 'staff-tracking' ); ?> <span style="color:red;">*</span></label>
                            <input type="text" id="st-assign-form-value-custom" placeholder="Nhập tên/mã đối tượng mới..." style="width: 100%;">
                        </div>
                        <!-- Input ẩn thực tế gửi lên -->
                        <input type="hidden" name="target_value" id="st-assign-form-value">
                    </div>
 
                    <!-- WooCommerce Product Selection -->
                    <div class="st-form-group">
                        <label><?php esc_html_e( 'Sản phẩm WooCommerce liên kết', 'staff-tracking' ); ?> <span style="color:red;">*</span></label>
                        <select name="product_selection" id="st-assign-form-product-sel" required style="width: 100%;">
                            <option value="all"><?php esc_html_e( 'Toàn bộ sản phẩm', 'staff-tracking' ); ?></option>
                            <option value="category"><?php esc_html_e( 'Theo Danh mục sản phẩm', 'staff-tracking' ); ?></option>
                            <option value="custom"><?php esc_html_e( 'Sản phẩm cụ thể (Nhập ID)', 'staff-tracking' ); ?></option>
                            <option value="random_all"><?php esc_html_e( 'Chia đều ngẫu nhiên mỗi ngày (Tất cả sản phẩm)', 'staff-tracking' ); ?></option>
                            <option value="random_category"><?php esc_html_e( 'Chia đều ngẫu nhiên mỗi ngày (Theo Danh mục)', 'staff-tracking' ); ?></option>
                            <option value="random_custom"><?php esc_html_e( 'Chia đều ngẫu nhiên mỗi ngày (Sản phẩm cụ thể)', 'staff-tracking' ); ?></option>
                            <option value="phases"><?php esc_html_e( 'Chia giai đoạn theo thời gian', 'staff-tracking' ); ?></option>
                                                </select>
                    </div>

                    <!-- Danh mục sản phẩm (chỉ hiện khi chọn category) -->
                    <div class="st-form-group" id="st-assign-cat-block" style="display:none;">
                        <label><?php esc_html_e( 'Chọn Danh mục sản phẩm', 'staff-tracking' ); ?></label>
                        <select name="category_ids[]" id="st-assign-form-cats" multiple="multiple" style="width: 100%;">
                            <?php
                            if ( class_exists( 'WooCommerce' ) ) {
                                $product_categories = get_terms( array(
                                    'taxonomy'   => 'product_cat',
                                    'hide_empty' => false,
                                ) );
                                if ( ! empty( $product_categories ) && ! is_wp_error( $product_categories ) ) {
                                    foreach ( $product_categories as $cat ) {
                                        echo '<option value="' . esc_attr( $cat->term_id ) . '">' . esc_html( $cat->name ) . '</option>';
                                    }
                                }
                            }
                            ?>
                        </select>
                    </div>
 
                    <!-- ID sản phẩm cụ thể (chỉ hiện khi chọn custom) -->
                    <div class="st-form-group" id="st-assign-prod-block" style="display:none;">
                        <label><?php esc_html_e( 'Chọn sản phẩm liên kết (Hỗ trợ tìm kiếm)', 'staff-tracking' ); ?></label>
                        <select id="st-assign-form-products-select" multiple style="width: 100%;">
                            <!-- Load động bằng SlimSelect & AJAX -->
                        </select>
                        <input type="hidden" name="product_ids" id="st-assign-form-products" value="">
                    </div>

                    <!-- Chia giai đoạn (chỉ hiện khi chọn phases) -->
                    <div class="st-form-group" id="st-assign-phases-block" style="display:none; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 15px;">
                        <label style="font-weight: 600; margin-bottom: 10px; display: block;"><?php esc_html_e( 'Các Giai đoạn (Phases)', 'staff-tracking' ); ?></label>
                        <div id="st-assign-phases-container"></div>
                        <button type="button" class="button button-small" id="st-add-phase-btn">+ Thêm Giai đoạn</button>
                    </div>

                    <!-- Ngày nghỉ lễ -->
                    <div class="st-form-group" style="background: #fffbe6; border: 1px solid #ffe58f; border-radius: 6px; padding: 15px; margin-top: 15px;">
                        <label style="font-weight: 600; margin-bottom: 10px; display: block; color: #d48806;"><?php esc_html_e( 'Ngày nghỉ lễ / Không làm traffic', 'staff-tracking' ); ?></label>
                        <p style="font-size: 12px; color: #555; margin-bottom: 10px;">Những ngày được thêm vào đây sẽ có chỉ tiêu KPI = 0 (không bắt buộc chạy traffic).</p>
                        <div id="st-assign-holidays-container"></div>
                        <button type="button" class="button button-small" id="st-add-holiday-btn" style="border-color: #faad14; color: #d48806;">+ Thêm ngày nghỉ</button>
                    </div>
 
                    <div class="st-form-group">
                        <label><?php esc_html_e( 'Chỉ tiêu cần đạt (Traffic)', 'staff-tracking' ); ?> <span style="color:red;">*</span></label>
                        <input type="number" name="kpi_target" id="st-assign-form-kpi" required min="1" placeholder="VD: 100" style="width: 100%;">
                    </div>
 
                    <div class="st-form-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Lưu phân công', 'staff-tracking' ); ?></button>
                        <button type="button" class="button st-assign-cancel"><?php esc_html_e( 'Hủy', 'staff-tracking' ); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        $this->render_payout_details_modal();
    }

    public function page_settings(): void {
        ?>
        <div class="wrap st-wrap">
            <h1 class="st-page-title">
                <span class="dashicons dashicons-admin-generic"></span>
                <?php esc_html_e( 'Staff Tracking — Cài đặt hệ thống', 'staff-tracking' ); ?>
            </h1>

            <div class="st-card st-settings-card" style="max-width: 1200px;">
                <h3><?php esc_html_e( 'Cấu hình điều kiện chạy Traffic & Bảo mật', 'staff-tracking' ); ?></h3>
                
                <form id="st-settings-form" style="margin-top: 15px;">
                    <div class="st-export-filters st-settings-grid" style="display: grid; grid-template-columns: 280px 1fr; gap: 20px; align-items: start;">
                        
                        <label for="st-setting-onsite">
                            <?php esc_html_e( 'Thời gian Onsite tối thiểu (giây)', 'staff-tracking' ); ?>
                            <span style="color:red;">*</span>
                        </label>
                        <div>
                            <input type="number" id="st-setting-onsite" name="onsite_threshold" required min="1" style="width: 100px;">
                            <p class="description"><?php esc_html_e( 'Thời gian đọc bài viết tối thiểu (onsite) của nhân viên để bắt đầu ghi nhận log. Mặc định: 30 giây.', 'staff-tracking' ); ?></p>
                        </div>

                        <label for="st-setting-scroll">
                            <?php esc_html_e( 'Tỷ lệ cuộn trang tối thiểu (%)', 'staff-tracking' ); ?>
                            <span style="color:red;">*</span>
                        </label>
                        <div>
                            <input type="number" id="st-setting-scroll" name="scroll_threshold" required min="1" max="100" style="width: 100px;">
                            <p class="description"><?php esc_html_e( 'Chiều sâu cuộn trang tối thiểu để đảm bảo nhân viên đã kéo chuột đọc bài viết. Mặc định: 60%.', 'staff-tracking' ); ?></p>
                        </div>

                        <label for="st-setting-rate">
                            <?php esc_html_e( 'IP Rate Limit (giây)', 'staff-tracking' ); ?>
                            <span style="color:red;">*</span>
                        </label>
                        <div>
                            <input type="number" id="st-setting-rate" name="rate_limit" required min="0" style="width: 100px;">
                            <p class="description"><?php esc_html_e( 'Thời gian giãn cách tối thiểu giữa 2 lần gửi traffic thành công trên cùng 1 IP (phòng spam). Mặc định: 60 giây.', 'staff-tracking' ); ?></p>
                        </div>

                        <label for="st-setting-toast">
                            <?php esc_html_e( 'Thời gian hiển thị thông báo (giây)', 'staff-tracking' ); ?>
                            <span style="color:red;">*</span>
                        </label>
                        <div>
                            <input type="number" id="st-setting-toast" name="toast_duration" required min="1" max="60" style="width: 100px;">
                            <p class="description"><?php esc_html_e( 'Thời gian tồn tại của các thông báo Toast ở góc dưới màn hình bài viết. Mặc định: 5 giây.', 'staff-tracking' ); ?></p>
                        </div>

                        <label for="st-setting-require-confirm">
                            <?php esc_html_e( 'Bật chức năng Nút Xác Nhận', 'staff-tracking' ); ?>
                        </label>
                        <div>
                            <label>
                                <input type="checkbox" id="st-setting-require-confirm" name="require_confirmation" value="1">
                                <?php esc_html_e( 'Hiển thị nút "Bấm vào đây để xác nhận" thay vì tự động ghi nhận', 'staff-tracking' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Khi bật, nhân viên phải tự bấm nút xác nhận hiện ngẫu nhiên trên màn hình mới được tính KPI, giúp chống giả lập 100%.', 'staff-tracking' ); ?></p>
                        </div>

                        <label for="st-setting-payout">
                            <?php esc_html_e( 'Định mức tiền mỗi lượt Traffic hợp lệ (VND)', 'staff-tracking' ); ?>
                            <span style="color:red;">*</span>
                        </label>
                        <div>
                            <input type="number" id="st-setting-payout" name="payout_per_kpi" required min="0" style="width: 150px;">
                            <p class="description"><?php esc_html_e( 'Số tiền hoa hồng sẽ được tự động tính bằng cách nhân số lượt traffic hợp lệ chạy được trong dự án với định mức này. Mặc định: 0 VNĐ.', 'staff-tracking' ); ?></p>
                        </div>

                        <label for="st-setting-gear-pass">
                            <?php esc_html_e( 'Mật khẩu truy cập cài đặt', 'staff-tracking' ); ?>
                            <span style="color:red;">*</span>
                        </label>
                        <div>
                            <input type="password" id="st-setting-gear-pass" name="gear_password" required style="width: 200px;">
                            <p class="description">
                                <?php esc_html_e( 'Mật khẩu dùng để xác thực trước khi mở khu vực cài đặt nội bộ.', 'staff-tracking' ); ?>
                            </p>
                        </div>

                        <label for="st-setting-sepay-token">
                            <?php esc_html_e( 'SePay Webhook Secret Key (HMAC-SHA256)', 'staff-tracking' ); ?>
                        </label>
                        <div>
                            <input type="text" id="st-setting-sepay-token" name="sepay_webhook_token" style="width: 380px;" placeholder="whsec_...">
                            <p class="description">
                                <?php esc_html_e( 'Nhập chuỗi Secret Key (HMAC-SHA256) lấy từ dashboard SePay để xác thực chữ ký biến động số dư.', 'staff-tracking' ); ?><br>
                                <strong>URL Webhook của bạn:</strong> <code><?php echo esc_url( rest_url( 'staff-tracking/v1/sepay-webhook' ) ); ?></code>
                            </p>
                        </div>

                        <label for="st-setting-sepay-reconcile">
                            <?php esc_html_e( 'Tự động đối soát SePay', 'staff-tracking' ); ?>
                        </label>
                        <div>
                            <label style="display: inline-flex; align-items: center; gap: 6px; font-weight: 500;">
                                <input type="checkbox" id="st-setting-sepay-reconcile" name="sepay_auto_reconcile" value="1">
                                <?php esc_html_e( 'Kích hoạt tự động xác nhận hoa hồng là "Đã chuyển tiền" khi nhận được giao dịch từ SePay.', 'staff-tracking' ); ?>
                            </label>
                        </div>

                        <label for="st-setting-allowed-admins">
                            <?php esc_html_e( 'Tài khoản Admin được truy cập', 'staff-tracking' ); ?>
                        </label>
                        <div>
                            <input type="text" id="st-setting-allowed-admins" name="allowed_admins" style="width: 100%; max-width: 600px;" placeholder="Ví dụ: admin1, admin2">
                            <p class="description">
                                <?php esc_html_e( 'Chỉ "nguyenminhduc" và các tài khoản được liệt kê ở đây mới thấy plugin này. Phân tách bằng dấu phẩy.', 'staff-tracking' ); ?>
                            </p>
                        </div>

                    </div>
                    <h3 style="margin-top: 30px; margin-bottom: 20px; border-bottom: 1px solid #ddd; padding-bottom: 10px; font-size: 15px;">Tùy chỉnh Thông báo (Frontend)</h3>
                    <!-- Thêm các checkbox "show_msg_..." để bật/tắt hiển thị thông báo ở Frontend -->
                    <div class="st-export-filters st-settings-grid" style="display: grid; grid-template-columns: 280px 1fr; gap: 20px; align-items: start;">
                        <label for="st-msg-require-google">Thiếu nguồn Google</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-require-google" name="show_msg_require_google" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-require-google" name="msg_require_google" style="flex: 1; max-width: 600px;">
                            </div>
                        </div>

                        <label for="st-msg-scroll-not-enough">Chưa cuộn đủ</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-scroll-not-enough" name="show_msg_scroll_not_enough" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-scroll-not-enough" name="msg_scroll_not_enough" style="flex: 1; max-width: 600px;">
                            </div>
                            <p class="description">Dùng <code>{scroll}</code> để hiển thị số phần trăm yêu cầu.</p>
                        </div>

                        <label for="st-msg-invalid-staff">Sai thông tin nhân sự</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-invalid-staff" name="show_msg_invalid_staff" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-invalid-staff" name="msg_invalid_staff" style="flex: 1; max-width: 600px;">
                            </div>
                        </div>

                        <label for="st-msg-already-completed">Đã làm nhiệm vụ</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-already-completed" name="show_msg_already-completed" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-already-completed" name="msg_already_completed" style="flex: 1; max-width: 600px;">
                            </div>
                        </div>

                        <label for="st-msg-not-in-mission">Sai sản phẩm</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-not-in-mission" name="show_msg_not_in_mission" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-not-in-mission" name="msg_not_in_mission" style="flex: 1; max-width: 600px;">
                            </div>
                        </div>

                        <label for="st-msg-rate-limit">Gửi quá nhanh</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-rate-limit" name="show_msg_rate_limit" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-rate-limit" name="msg_rate_limit" style="flex: 1; max-width: 600px;">
                            </div>
                        </div>

                        <label for="st-msg-success">Ghi nhận thành công</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-success" name="show_msg_success" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-success" name="msg_success" style="flex: 1; max-width: 600px;">
                            </div>
                            <p class="description">Dùng <code>{name}</code> để hiển thị tên nhân sự.</p>
                        </div>

                        <label for="st-msg-not-enough-conditions">Chưa đủ điều kiện</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-not-enough-conditions" name="show_msg_not_enough_conditions" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-not-enough-conditions" name="msg_not_enough_conditions" style="flex: 1; max-width: 600px;">
                            </div>
                            <p class="description">Dùng <code>{onsite}</code> và <code>{scroll}</code> để hiển thị thời gian và phần trăm.</p>
                        </div>

                        <label for="st-msg-connection-failed">Lỗi kết nối</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-connection-failed" name="show_msg_connection_failed" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-connection-failed" name="msg_connection_failed" style="flex: 1; max-width: 600px;">
                            </div>
                        </div>

                        <label for="st-msg-scroll-down-prompt">Nhắc cuộn xuống (Góc trên)</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-scroll-down-prompt" name="show_msg_scroll_down_prompt" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-scroll-down-prompt" name="msg_scroll_down_prompt" style="flex: 1; max-width: 600px;">
                            </div>
                            <p class="description">Hỗ trợ mã HTML cơ bản như <code>&lt;br/&gt;</code>, <code>&lt;span&gt;</code>.</p>
                        </div>

                        <label for="st-msg-stay-longer">Đã cuộn đủ, hãy đợi</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-stay-longer" name="show_msg_stay_longer" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-stay-longer" name="msg_stay_longer" style="flex: 1; max-width: 600px;">
                            </div>
                            <p class="description">Dùng <code>{remaining}</code> để chèn số giây còn lại.</p>
                        </div>

                        <label for="st-msg-recording-traffic">Đang ghi nhận traffic</label>
                        <div>
                            <div style="display: flex; gap: 12px; align-items: center;">
                                <label style="display: flex; gap: 5px; align-items: center; margin: 0; cursor: pointer;">
                                    <input type="checkbox" id="st-show-msg-recording-traffic" name="show_msg_recording_traffic" title="Hiển thị thông báo này" checked>
                                    <span style="font-size: 13px; font-weight: normal; white-space: nowrap;">Hiển thị</span>
                                </label>
                                <input type="text" id="st-msg-recording-traffic" name="msg_recording_traffic" style="flex: 1; max-width: 600px;">
                            </div>
                        </div>

                    </div>

                    <div style="margin-top: 25px; display: flex; align-items: center; gap: 12px;">
                        <button type="submit" class="button button-primary" id="st-settings-submit">
                            <?php esc_html_e( 'Lưu cài đặt', 'staff-tracking' ); ?>
                        </button>
                        <span id="st-settings-status" style="font-weight: 600; font-size: 13px;"></span>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    public function page_payouts(): void {
        ?>
        <div class="wrap st-wrap">
            <h1 class="wp-heading-inline st-page-title">
                <span class="dashicons dashicons-money-alt"></span>
                <?php esc_html_e( 'Tính tiền & Chuyển khoản nhân sự', 'staff-tracking' ); ?>
            </h1>
            <hr class="wp-header-end">

            <!-- Summary cards -->
            <div class="st-kpi-grid" style="grid-template-columns: repeat(2, 1fr); margin-top: 15px;">
                <div class="st-kpi-card" style="border-top-color: #d63638;">
                    <span class="st-kpi-label"><?php esc_html_e( 'Tổng chưa chuyển tiền', 'staff-tracking' ); ?></span>
                    <span class="st-kpi-value" id="st-payout-sum-unpaid">0 đ</span>
                </div>
                <div class="st-kpi-card" style="border-top-color: #46b450;">
                    <span class="st-kpi-label"><?php esc_html_e( 'Tổng đã chuyển tiền', 'staff-tracking' ); ?></span>
                    <span class="st-kpi-value" id="st-payout-sum-paid">0 đ</span>
                </div>
            </div>

            <!-- Filters row -->
            <div class="st-card">
                <div class="st-filter-row" style="justify-content: space-between;">
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <input type="text" id="st-payout-filter-staff" placeholder="Mã NV hoặc tên..." class="regular-text" style="width: 180px;">
                        
                        <select id="st-payout-filter-status">
                            <option value=""><?php esc_html_e( '-- Tất cả trạng thái --', 'staff-tracking' ); ?></option>
                            <option value="unpaid"><?php esc_html_e( 'Chưa chuyển tiền', 'staff-tracking' ); ?></option>
                            <option value="paid"><?php esc_html_e( 'Đã chuyển tiền', 'staff-tracking' ); ?></option>
                        </select>

                        <input type="text" id="st-payout-filter-period" placeholder="Lọc chu kỳ (VD: 2026-07-02)" class="regular-text" style="width: 180px; height: 30px;">

                        <button type="button" class="button" id="st-payout-filter-btn">
                            <?php esc_html_e( 'Lọc', 'staff-tracking' ); ?>
                        </button>
                    </div>

                    <div>
                        <button type="button" class="button button-secondary" id="st-payout-recalc-btn">
                            <span class="dashicons dashicons-update" style="vertical-align: middle; font-size: 17px; margin-right: 4px;"></span>
                            <?php esc_html_e( 'Tính lại tiền hoa hồng', 'staff-tracking' ); ?>
                        </button>
                    </div>
                </div>

                <!-- Recalculate block -->
                <div id="st-payout-recalc-block" style="display: none; margin-top: 15px; padding: 12px; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px;">
                    <h4 style="margin: 0 0 8px;"><?php esc_html_e( 'Tính toán lại tiền hoa hồng', 'staff-tracking' ); ?></h4>
                    <p class="description" style="margin-bottom: 10px;">
                        <?php esc_html_e( 'Nhập ngày kết thúc chu kỳ phân công để tính toán tiền thưởng cho những nhân sự hoàn thành KPI vào ngày đó.', 'staff-tracking' ); ?>
                    </p>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="date" id="st-payout-recalc-date" value="<?php echo esc_attr( date('Y-m-d') ); ?>" style="height: 30px;">
                        <button type="button" class="button button-primary" id="st-payout-recalc-submit-btn"><?php esc_html_e( 'Bắt đầu tính toán', 'staff-tracking' ); ?></button>
                        <button type="button" class="button st-payout-recalc-cancel-btn"><?php esc_html_e( 'Hủy', 'staff-tracking' ); ?></button>
                    </div>
                    <span id="st-payout-recalc-status" style="margin-left: 10px; font-weight: 600;"></span>
                </div>
            </div>

            <!-- Table -->
            <div class="st-card">
                <table class="st-table" id="st-payouts-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Tên nhiệm vụ / Dự án', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Thời gian', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Phạm vi', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Đối tượng', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Chỉ tiêu KPI', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Đạt KPI (Nhân sự)', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Tổng hoa hồng', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Đã chuyển', 'staff-tracking' ); ?></th>
                            <th><?php esc_html_e( 'Chưa chuyển', 'staff-tracking' ); ?></th>
                            <th style="text-align: right; width: 140px;"><?php esc_html_e( 'Hành động', 'staff-tracking' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="st-payouts-body">
                        <!-- Ajax data -->
                    </tbody>
                </table>

                <div class="st-pagination" id="st-payout-pagination">
                    <!-- Pagination links -->
                </div>
            </div>
        </div>
        <?php
        $this->render_payout_details_modal();
    }

    private function render_payout_details_modal(): void {
        ?>
        <!-- Payout Details Modal -->
        <div id="st-payout-details-modal" class="st-modal-overlay" style="display:none;">
            <style>
                #st-payout-details-modal .st-modal-content {
                    max-width: 1280px !important;
                    width: 96% !important;
                    padding: 18px !important;
                }
                #st-payout-details-modal .st-table th,
                #st-payout-details-modal .st-table td {
                    padding: 6px 8px !important;
                    font-size: 12.5px !important;
                    vertical-align: middle !important;
                    height: 38px !important;
                }
                #st-payout-details-modal .st-table td strong {
                    font-size: 13px;
                }
                #st-payout-details-modal .st-table td code {
                    font-size: 11.5px;
                }
            </style>
            <div class="st-modal-content">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 12px;">
                    <h3 style="margin: 0;" id="st-payout-modal-title"><?php esc_html_e( 'Chi tiết tiền hoa hồng theo nhân sự', 'staff-tracking' ); ?></h3>
                    <span id="st-payout-modal-close" style="font-size: 24px; font-weight: bold; cursor: pointer; color: #a0aec0;">&times;</span>
                </div>
                <!-- Assignment info -->
                <div style="font-size: 13px; color: #4a5568; margin-bottom: 10px;">
                    <span id="st-payout-modal-assignment-info"></span>
                </div>
                <!-- Toolbar: search + filter + actions -->
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
                    <input type="text" id="st-modal-search" placeholder="🔍 Tìm tên hoặc mã NV..." style="flex:1;min-width:180px;max-width:280px;padding:5px 10px;border:1px solid #ddd;border-radius:4px;font-size:13px;height:30px;box-sizing:border-box;">
                    <select id="st-modal-status-filter" style="padding:0 10px;border:1px solid #ddd;border-radius:4px;font-size:13px;height:30px;box-sizing:border-box;">
                        <option value="">Tất cả trạng thái</option>
                        <option value="unpaid" class="st-col-payout">Chưa chuyển</option>
                        <option value="paid" class="st-col-payout">Đã chuyển</option>
                        <option value="pending" class="st-col-kpi" style="display:none;">Chưa đạt KPI</option>
                        <option value="completed" class="st-col-kpi" style="display:none;">Đạt KPI</option>
                    </select>
                    <span id="st-modal-total-badge" style="font-size:12px;color:#555;white-space:nowrap;"></span>
                    <div style="margin-left:auto;display:flex;gap:8px;">
                        <button type="button" class="button button-secondary" id="st-payout-recalc-assignment-btn" style="height:30px;line-height:28px;padding:0 12px;" title="Tính lại tiền hoa hồng cho nhiệm vụ này dựa trên số traffic thực tế hiện tại"><?php esc_html_e( '🔄 Tính lại', 'staff-tracking' ); ?></button>
                        <button type="button" class="button button-secondary" id="st-export-assignment-btn" style="height:30px;line-height:28px;padding:0 12px; border-color: #2271b1; color: #2271b1; font-weight: 600;" title="Xuất toàn bộ chi tiết thống kê và lịch sử hàng ngày ra file Excel"><?php esc_html_e( '📥 Xuất Excel', 'staff-tracking' ); ?></button>
                        <button type="button" class="button button-primary" id="st-payout-pay-all-btn" style="display:none;height:30px;line-height:28px;padding:0 12px;"><?php esc_html_e( 'Đã chuyển tiền tất cả', 'staff-tracking' ); ?></button>
                    </div>
                </div>
                <!-- Table -->
                <div style="max-height: 485px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 4px;">
                    <table class="widefat striped st-table" style="width: 100%; border: none;">
                        <thead style="position:sticky;top:0;z-index:2;background:#f8f9fa;">
                            <tr>
                                <th><?php esc_html_e( 'Họ tên', 'staff-tracking' ); ?></th>
                                <th><?php esc_html_e( 'Tài khoản nhận', 'staff-tracking' ); ?></th>
                                <th><?php esc_html_e( 'Mã NV', 'staff-tracking' ); ?></th>
                                <th><?php esc_html_e( 'Mã Lớp', 'staff-tracking' ); ?></th>
                                <th><?php esc_html_e( 'Team / Ngành', 'staff-tracking' ); ?></th>
                                <th><?php esc_html_e( 'Traffic đạt', 'staff-tracking' ); ?></th>
                                <th><?php esc_html_e( 'Avg Onsite (giây)', 'staff-tracking' ); ?></th>
                                <th class="st-col-payout"><?php esc_html_e( 'Tiền hoa hồng', 'staff-tracking' ); ?></th>
                                <th class="st-col-payout"><?php esc_html_e( 'Trạng thái chuyển', 'staff-tracking' ); ?></th>
                                <th class="st-col-kpi" style="display:none;"><?php esc_html_e( 'Tiến độ KPI', 'staff-tracking' ); ?></th>
                                <th class="st-col-kpi" style="display:none;"><?php esc_html_e( 'Trạng thái KPI', 'staff-tracking' ); ?></th>
                                <th style="text-align: right; width: 130px;"><?php esc_html_e( 'Thao tác', 'staff-tracking' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="st-payout-modal-body">
                            <!-- Nạp động qua AJAX -->
                        </tbody>
                    </table>
                </div>
                <!-- Pagination -->
                <div id="st-modal-pagination" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:10px;font-size:13px;"></div>
            </div>
        </div>

        <!-- Payout QR Modal -->
        <div id="st-payout-qr-modal" class="st-modal-overlay" style="display:none; z-index: 100000;">
            <div class="st-modal-content" style="max-width: 420px; width: 90%;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px;">
                    <h3 style="margin: 0; font-size: 16px;" id="st-qr-modal-title"><?php esc_html_e( 'Quét mã VietQR chuyển khoản nhanh', 'staff-tracking' ); ?></h3>
                    <span id="st-qr-modal-close" style="font-size: 24px; font-weight: bold; cursor: pointer; color: #a0aec0;">&times;</span>
                </div>
                <div style="text-align: center; margin-bottom: 15px;">
                    <img id="st-qr-modal-image" src="" alt="VietQR" style="max-width: 280px; width: 100%; height: auto; display: inline-block; border: 1px solid #cbd5e1; border-radius: 8px;">
                </div>
                <div style="font-size: 13px; line-height: 1.5; color: #334155; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; margin-bottom: 18px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span style="color:#64748b;"><?php esc_html_e( 'Ngân hàng:', 'staff-tracking' ); ?></span>
                        <strong id="st-qr-info-bank"></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span style="color:#64748b;"><?php esc_html_e( 'Số tài khoản:', 'staff-tracking' ); ?></span>
                        <strong id="st-qr-info-number"></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span style="color:#64748b;"><?php esc_html_e( 'Chủ tài khoản:', 'staff-tracking' ); ?></span>
                        <strong id="st-qr-info-name"></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span style="color:#64748b;"><?php esc_html_e( 'Số tiền:', 'staff-tracking' ); ?></span>
                        <strong id="st-qr-info-amount" style="color: #ef4444; font-size: 14px;"></strong>
                    </div>
                    <div style="margin-top: 8px; border-top: 1px dashed #cbd5e1; padding-top: 8px;">
                        <div style="color:#64748b; margin-bottom: 2px;"><?php esc_html_e( 'Nội dung chuyển khoản (bắt buộc khớp):', 'staff-tracking' ); ?></div>
                        <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 6px; border-radius: 4px; font-family: monospace; font-size: 12.5px; font-weight: bold; text-align: center; letter-spacing: 0.5px;" id="st-qr-info-desc"></div>
                    </div>
                </div>
                <!-- Hết hạn QR -->
                <div style="font-size: 13px; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; line-height: 1.4; margin-bottom: 15px; border-radius: 6px; padding: 10px; text-align: center;">
                    <strong><?php esc_html_e( '⏳ Mã QR có hiệu lực trong:', 'staff-tracking' ); ?></strong>
                    <div id="st-qr-countdown" style="font-size: 20px; font-weight: bold; margin-top: 5px;">15:00</div>
                    <div style="font-size: 11px; color: #7f1d1d; margin-top: 4px;"><?php esc_html_e( 'Vui lòng không sử dụng lại mã này khi đã hết hạn.', 'staff-tracking' ); ?></div>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="button button-secondary" id="st-qr-modal-btn-close"><?php esc_html_e( 'Đóng', 'staff-tracking' ); ?></button>
                    <button type="button" class="button button-primary" id="st-qr-modal-btn-confirm" style="background:#10b981; border-color:#10b981;"><?php esc_html_e( 'Đã chuyển khoản thành công', 'staff-tracking' ); ?></button>
                </div>
            </div>
        </div>

        <!-- Staff Daily Details Modal -->
        <div id="st-daily-details-modal" class="st-modal-overlay" style="display:none; z-index: 100000;">
            <div class="st-modal-content" style="max-width: 600px; width: 95%;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px;">
                    <h3 style="margin: 0; font-size: 16px;" id="st-daily-modal-title"><?php esc_html_e( 'Chi tiết hoàn thành theo ngày', 'staff-tracking' ); ?></h3>
                    <span id="st-daily-modal-close" style="font-size: 24px; font-weight: bold; cursor: pointer; color: #a0aec0;">&times;</span>
                </div>
                
                <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                    <div style="flex: 1; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #166534;" id="st-daily-completed-days">0</div>
                        <div style="font-size: 12px; color: #166534; font-weight: 500;"><?php esc_html_e( 'Ngày đã hoàn thành', 'staff-tracking' ); ?></div>
                    </div>
                    <div style="flex: 1; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #991b1b;" id="st-daily-missed-days">0</div>
                        <div style="font-size: 12px; color: #991b1b; font-weight: 500;"><?php esc_html_e( 'Ngày chưa đạt', 'staff-tracking' ); ?></div>
                    </div>
                    <div style="flex: 1; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #334155;" id="st-daily-total-days">0</div>
                        <div style="font-size: 12px; color: #475569; font-weight: 500;"><?php esc_html_e( 'Tổng số ngày', 'staff-tracking' ); ?></div>
                    </div>
                </div>

                <div style="max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <table class="wp-list-table widefat fixed striped table-view-list" style="margin: 0; border: none;">
                        <thead>
                            <tr>
                                <th style="width: 120px;"><?php esc_html_e( 'Ngày', 'staff-tracking' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Mục tiêu (sản phẩm)', 'staff-tracking' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Đã hoàn thành', 'staff-tracking' ); ?></th>
                                <th style="text-align: center;"><?php esc_html_e( 'Trạng thái', 'staff-tracking' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="st-daily-details-tbody">
                            <!-- Rows will be injected via JS -->
                        </tbody>
                    </table>
                </div>

                <div style="display: flex; justify-content: flex-end; margin-top: 15px;">
                    <button type="button" class="button button-secondary" id="st-daily-modal-btn-close"><?php esc_html_e( 'Đóng', 'staff-tracking' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}



