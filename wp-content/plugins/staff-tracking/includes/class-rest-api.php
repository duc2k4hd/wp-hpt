<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers all REST API routes for the plugin.
 *
 * Public  → POST /wp-json/tracking/v1/log     (frontend tracker)
 * Private → GET  /wp-json/staff/v1/logs        (admin table)
 * Private → GET  /wp-json/staff/v1/kpi         (admin dashboard KPIs)
 * Private → GET  /wp-json/staff/v1/export      (CSV export redirect)
 */
class ST_REST_API {

    private static ?ST_REST_API $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {

        // ── Challenge token (chống API spoofing) ────────────────────────────
        register_rest_route( 'tracking/v1', '/challenge', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_challenge' ],
            'permission_callback' => '__return_true',
        ] );

        // ── Frontend tracker ────────────────────────────────────────────────
        register_rest_route( 'tracking/v1', '/log', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_log' ],
            'permission_callback' => '__return_true',
            'args'                => $this->log_args(),
        ] );

        register_rest_route( 'tracking/v1', '/check-mission', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_check_mission' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'tracking/v1', '/verify-staff', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_verify_staff' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'tracking/v1', '/behavior', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_log_behavior' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'tracking/v1', '/member-dashboard', [
            'methods'             => [ 'GET', 'POST' ],
            'callback'            => [ $this, 'handle_member_dashboard' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'tracking/v1', '/member-history', [
            'methods'             => [ 'GET', 'POST' ],
            'callback'            => [ $this, 'handle_member_history' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'tracking/v1', '/verify-gear-password', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_verify_gear_password' ],
            'permission_callback' => '__return_true',
        ] );

        // ── Admin: paginated logs ────────────────────────────────────────────
        register_rest_route( 'staff/v1', '/logs', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_logs' ],
            'permission_callback' => [ $this, 'require_manage_cap' ],
        ] );

        // ── Admin: personnel list ────────────────────────────────────────────
        register_rest_route( 'staff/v1', '/personnel', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_personnel' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_create_personnel' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'handle_delete_personnel' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff/v1', '/personnel/import', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_import_personnel' ],
            'permission_callback' => [ $this, 'require_manage_cap' ],
        ] );

        register_rest_route( 'staff/v1', '/personnel/class', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'handle_delete_class' ],
            'permission_callback' => [ $this, 'require_manage_cap' ],
        ] );

        register_rest_route( 'staff/v1', '/personnel/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'handle_update_personnel' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'handle_delete_personnel' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        // ── Admin: KPI ───────────────────────────────────────────────────────
        register_rest_route( 'staff/v1', '/kpi', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_kpi' ],
            'permission_callback' => [ $this, 'require_manage_cap' ],
        ] );

        // ── Admin: Assignments (KPI) ───────────────────────────────────────────
        register_rest_route( 'staff/v1', '/assignments', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_get_assignments' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_create_assignment' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff/v1', '/assignments/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'handle_delete_assignment' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'handle_update_assignment' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff/v1', '/assignments/(?P<id>\d+)/payout-details', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_get_assignment_payout_details' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff/v1', '/assignments/(?P<id>\d+)/details', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_get_assignment_details' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff/v1', '/assignments/(?P<id>\d+)/pay-all', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_pay_all_assignment_payouts' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff/v1', '/assignments/(?P<id>\d+)/export-data', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_export_assignment_data' ],
            'permission_callback' => [ $this, 'require_manage_cap' ],
        ] );

        register_rest_route( 'staff/v1', '/assignments/(?P<id>\d+)/recalculate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_recalculate_assignment_payout' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff/v1', '/assignments/(?P<id>\d+)/staff/(?P<emp_code>[a-zA-Z0-9_-]+)/details', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_get_assignment_staff_details' ],
            'permission_callback' => [ $this, 'require_manage_cap' ],
        ] );

        // ── Admin: Delete Log ──────────────────────────────────────────────
        register_rest_route( 'staff/v1', '/logs/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'handle_delete_log' ],
            'permission_callback' => [ $this, 'require_manage_cap' ],
        ] );

        register_rest_route( 'staff/v1', '/logs/(?P<id>\d+)/behavior', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_get_log_behavior' ],
            'permission_callback' => [ $this, 'require_manage_cap' ],
        ] );

        // ── Admin: Settings ────────────────────────────────────────────────
        register_rest_route( 'staff/v1', '/settings', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_get_settings' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_save_settings' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        // ── Admin: Search WooCommerce products ────────────────────────────────
        register_rest_route( 'staff/v1', '/products', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_search_products' ],
            'permission_callback' => [ $this, 'require_manage_cap' ],
        ] );

        // ── Admin: Payouts ───────────────────────────────────────────────────
        register_rest_route( 'staff/v1', '/payouts', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_get_payouts' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff/v1', '/payouts/(?P<id>\d+)/pay', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_pay_payout' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff/v1', '/payouts/(?P<id>\d+)/qr', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_generate_payout_qr' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff/v1', '/payouts/(?P<id>\d+)/status', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_get_payout_status' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff/v1', '/payouts/recalculate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_recalculate_payouts' ],
                'permission_callback' => [ $this, 'require_manage_cap' ],
            ]
        ] );

        register_rest_route( 'staff-tracking/v1', '/sepay-webhook', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_sepay_webhook' ],
            'permission_callback' => '__return_true',
        ] );

        // ── User: Bank Accounts ──────────────────────────────────────────────
        register_rest_route( 'staff-tracking/v1', '/bank-accounts', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_get_bank_accounts' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_create_bank_account' ],
                'permission_callback' => '__return_true',
            ]
        ] );

        register_rest_route( 'staff-tracking/v1', '/bank-accounts/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'handle_update_bank_account' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'handle_delete_bank_account' ],
                'permission_callback' => '__return_true',
            ]
        ] );

        // ── User: Wallet balance & Transactions ──────────────────────────────
        register_rest_route( 'staff-tracking/v1', '/wallet', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_get_user_wallet' ],
            'permission_callback' => '__return_true',
        ] );

        // ── User: Withdrawals ────────────────────────────────────────────────
        register_rest_route( 'staff-tracking/v1', '/withdrawals', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle_get_user_withdrawals' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handle_create_withdrawal' ],
                'permission_callback' => '__return_true',
            ]
        ] );

        register_rest_route( 'staff-tracking/v1', '/withdrawals/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_get_user_withdrawal_details' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'staff-tracking/v1', '/withdrawals/(?P<id>\d+)/cancel', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_cancel_withdrawal' ],
            'permission_callback' => '__return_true',
        ] );

        // ── Admin: Withdrawals Management ────────────────────────────────────
        register_rest_route( 'staff/v1', '/admin/withdrawals', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_admin_get_withdrawals' ],
            'permission_callback' => [ $this, 'require_view_payouts_cap' ],
        ] );

        register_rest_route( 'staff/v1', '/admin/withdrawals/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handle_admin_get_withdrawal_details' ],
            'permission_callback' => [ $this, 'require_view_payouts_cap' ],
        ] );

        register_rest_route( 'staff/v1', '/admin/withdrawals/(?P<id>\d+)/approve', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_admin_approve_withdrawal' ],
            'permission_callback' => [ $this, 'require_manage_payouts_cap' ],
        ] );

        register_rest_route( 'staff/v1', '/admin/withdrawals/(?P<id>\d+)/reject', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_admin_reject_withdrawal' ],
            'permission_callback' => [ $this, 'require_manage_payouts_cap' ],
        ] );

        register_rest_route( 'staff/v1', '/admin/withdrawals/(?P<id>\d+)/mark-transfer-initiated', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_admin_mark_transfer_initiated' ],
            'permission_callback' => [ $this, 'require_manage_payouts_cap' ],
        ] );

        register_rest_route( 'staff/v1', '/admin/withdrawals/(?P<id>\d+)/reconcile', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_admin_reconcile_withdrawal' ],
            'permission_callback' => [ $this, 'require_reconcile_payouts_cap' ],
        ] );
    }

    // ── Permission callbacks ─────────────────────────────────────────────────

    public function verify_nonce( WP_REST_Request $req ): bool {
        $nonce = $req->get_header( 'X-WP-Nonce' );
        return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
    }

    public function require_manage_cap(): bool {
        return current_user_can( 'manage_options' ) && st_is_user_allowed();
    }

    // ── Handlers ────────────────────────────────────────────────────────────

    /**
     * Lớp 1: Sinh challenge token một lần dùng, lưu vào transient 90 giây.
     * Chỉ trình duyệt thật tải trang mới gọi được endpoint này trước khi gửi log.
     */
    public function handle_challenge( WP_REST_Request $req ): WP_REST_Response {
        // Tạo token ngẫu nhiên 32 bytes
        $token = bin2hex( random_bytes( 16 ) );
        // Lưu transient: TTL 90s (đủ để client ghi nhận session và gửi log)
        set_transient( 'st_challenge_' . $token, 1, 90 );
        return new WP_REST_Response( [ 'token' => $token ], 200 );
    }

    public function handle_log( WP_REST_Request $req ): WP_REST_Response {

        $ip = $this->get_client_ip();

        // ── Giải nén body thủ công (Safari Beacon gửi text/plain) ────────────
        $body   = $req->get_body();
        $params = [];
        if ( ! empty( $body ) ) {
            $decoded = json_decode( $body, true );
            if ( is_array( $decoded ) ) {
                $params = $decoded;
            }
        }
        
        $emp_code = sanitize_text_field( $req->get_param( 'emp_code' ) ?? $params['emp_code'] ?? '' );

        // Rate limit theo IP và Tài khoản (1 tài khoản trên 1 IP)
        if ( ST_Database::is_rate_limited( $ip, $emp_code ) ) {
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'rate_limited' ], 429 );
        }

        // ── Lớp 1: Validate challenge token một lần dùng ─────────────────────
        $challenge_token = sanitize_text_field( $req->get_param( 'challenge' ) ?? $params['challenge'] ?? '' );
        if ( empty( $challenge_token ) ) {
            error_log( '[AntiCheat] Rejected: missing challenge token. IP: ' . $ip );
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'invalid_challenge' ], 403 );
        }
        $transient_key = 'st_challenge_' . $challenge_token;
        if ( ! get_transient( $transient_key ) ) {
            error_log( '[AntiCheat] Rejected: invalid or expired challenge token. IP: ' . $ip . ' | Token: ' . $challenge_token );
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'invalid_challenge' ], 403 );
        }
        // Xóa ngay sau khi dùng — không thể replay
        delete_transient( $transient_key );

        $events = $req->get_param( 'events' ) ?? $params['events'] ?? [];

        $data = [
            'session_id'    => sanitize_text_field( $req->get_param( 'session_id' )       ?? $params['session_id'] ?? '' ),
            'ip'            => $ip,
            'user_agent'    => sanitize_textarea_field( $req->get_header( 'User-Agent' )  ?? '' ),
            'url'           => esc_url_raw( $req->get_param( 'url' )                      ?? $params['url'] ?? '' ),
            'page_path'     => sanitize_text_field( $req->get_param( 'page_path' )        ?? $params['page_path'] ?? '/' ),
            'duration'      => absint( $req->get_param( 'duration_seconds' )              ?? $params['duration_seconds'] ?? 0 ),
            'user_name'     => sanitize_text_field( $req->get_param( 'user_name' )        ?? $params['user_name'] ?? '' ),
            'emp_code'      => sanitize_text_field( $req->get_param( 'emp_code' )         ?? $params['emp_code'] ?? '' ),
            'class_code'    => sanitize_text_field( $req->get_param( 'class_code' )       ?? $params['class_code'] ?? '' ),
            'team'          => sanitize_text_field( $req->get_param( 'team' )             ?? $params['team'] ?? '' ),
            'max_scroll'    => absint( $req->get_param( 'max_scroll' )                    ?? $params['max_scroll'] ?? 0 ),
            'referrer'      => esc_url_raw( $req->get_param( 'referrer' )                 ?? $params['referrer'] ?? $req->get_header( 'referer' ) ?? '' ),
            'events'        => $events,
            // Bot fingerprint signals từ JS (Lớp 3 & 6)
            'webdriver_flag'    => ! empty( $params['webdriver_flag'] ),
            'plugin_count'      => absint( $params['plugin_count'] ?? -1 ),
            'has_chrome_object' => ! empty( $params['has_chrome_object'] ),
        ];

        // ── Lớp 3: Phát hiện WebDriver / automation từ JS signal ─────────────
        if ( $data['webdriver_flag'] ) {
            error_log( '[AntiCheat] Rejected: navigator.webdriver=true detected. IP: ' . $ip . ' | emp: ' . $data['emp_code'] );
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'bot' ], 200 );
        }
        // Plugin count = 0 + không có chrome object là dấu hiệu headless mạnh
        if ( $data['plugin_count'] === 0 && ! $data['has_chrome_object'] ) {
            error_log( '[AntiCheat] Rejected: headless browser fingerprint (0 plugins, no chrome obj). IP: ' . $ip . ' | emp: ' . $data['emp_code'] );
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'bot' ], 200 );
        }

        // ── Lớp 3: Bot UA detection (nâng cao) ───────────────────────────────
        if ( empty( $data['user_agent'] ) || $this->is_bot( $data['user_agent'] ) || ! $this->is_valid_browser( $data['user_agent'] ) ) {
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'bot' ], 200 );
        }

        global $wpdb;

        // ── Zero-trust: Resolve thông tin chính thức từ DB ───────────────────
        $table_personnel = $wpdb->prefix . 'st_personnel';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $staff = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_name, team FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s) AND LOWER(class_code) = LOWER(%s)",
            $data['emp_code'],
            $data['class_code']
        ), ARRAY_A );

        if ( ! $staff ) {
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'invalid_staff_credentials' ], 200 );
        }

        // Ghi đè bằng dữ liệu chính thức từ DB
        $data['user_name'] = $staff['user_name'];
        $data['team']      = $staff['team'];
        // ── (Đã gỡ bỏ Lớp 5 hardcode, sử dụng rate limit chính ở trên) ──────────────

        // Chống cheat trùng lặp: 1 người chỉ được làm 1 link/ngày
        if ( ST_Database::is_link_done_today( $data['emp_code'], $data['page_path'] ) ) {
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'already_done_today' ], 200 );
        }

        // ── Kiểm tra điều kiện tối thiểu (Lớp 2 & 4 ở Validator) ────────────
        if ( ! ST_Traffic_Validator::can_save( $data ) ) {
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'missing_requirements' ], 200 );
        }

        // Validate traffic origin
        $is_valid         = ST_Traffic_Validator::is_valid( $data );
        $data['is_valid'] = $is_valid ? 1 : 0;

        $inserted_id = ST_Database::insert_session( $data );

        if ( $inserted_id > 0 ) {
            if ( ! empty( $events ) && is_array( $events ) ) {
                ST_Database::insert_behavior_logs( $inserted_id, $data['emp_code'], $events );
            }
        }

        return new WP_REST_Response( [ 'success' => $inserted_id > 0, 'id' => $inserted_id ], $inserted_id > 0 ? 201 : 500 );
    }

    public function handle_logs( WP_REST_Request $req ): WP_REST_Response {
        $args = [
            'start_date'  => sanitize_text_field( $req->get_param( 'start_date' ) ?? '' ),
            'end_date'    => sanitize_text_field( $req->get_param( 'end_date' )   ?? '' ),
            'user_name'   => sanitize_text_field( $req->get_param( 'staff' )      ?? '' ),
            'emp_code'    => sanitize_text_field( $req->get_param( 'emp_code' )   ?? '' ),
            'class_code'  => sanitize_text_field( $req->get_param( 'class_code' ) ?? '' ),
            'team'        => sanitize_text_field( $req->get_param( 'team' )       ?? '' ),
            'exact_match' => sanitize_text_field( $req->get_param( 'exact_match' ) ?? '' ),
            'valid'       => ( $req->get_param( 'valid' ) !== null && $req->get_param( 'valid' ) !== '' ) ? (int) $req->get_param( 'valid' ) : '',
            'page'        => absint( $req->get_param( 'page' )     ?? 1 ),
            'per_page'    => absint( $req->get_param( 'per_page' ) ?? 25 ),
            'orderby'     => sanitize_text_field( $req->get_param( 'orderby' ) ?? 'created_at' ),
            'order'       => sanitize_text_field( $req->get_param( 'order' )   ?? 'DESC' ),
        ];

        $data = ST_Database::get_logs( $args );
        return new WP_REST_Response( $data, 200 );
    }

    public function handle_kpi( WP_REST_Request $req ): WP_REST_Response {
        $start = sanitize_text_field( $req->get_param( 'start_date' ) ?? '' );
        $end   = sanitize_text_field( $req->get_param( 'end_date' )   ?? '' );
        $force = (bool) $req->get_param( 'force' );

        $cache_key = 'st_kpi_v2_' . md5( $start . '|' . $end );
        
        if ( $force ) {
            delete_transient( $cache_key );
        }

        $cached = get_transient( $cache_key );

        if ( false === $cached ) {
            $cached = ST_Database::get_kpi( $start, $end );
            set_transient( $cache_key, $cached, 10 * MINUTE_IN_SECONDS );
        }

        return new WP_REST_Response( $cached, 200 );
    }

    // ── Personnel List ───────────────────────────────────────────────────────
    
    public function handle_personnel( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $table_personnel = $wpdb->prefix . 'st_personnel';
        $table_logs = $wpdb->prefix . ST_TABLE;

        $mode = sanitize_text_field( $req->get_param('mode') ?? 'personnel' );
        $class_filter = sanitize_text_field( $req->get_param('class') ?? '' );

        $start_date = sanitize_text_field( $req->get_param('start_date') ?? '' );
        $end_date = sanitize_text_field( $req->get_param('end_date') ?? '' );
        $search = sanitize_text_field( $req->get_param('search') ?? '' );
        $team = sanitize_text_field( $req->get_param('team') ?? '' );
        $exact = (bool) $req->get_param('exact_match');

        $page = absint( $req->get_param('page') ?? 1 );
        $per_page = absint( $req->get_param('per_page') ?? 25 );
        $offset = ($page - 1) * $per_page;
        
        $orderby = sanitize_text_field( $req->get_param('orderby') ?? 'last_active' );
        $order = sanitize_text_field( $req->get_param('order') ?? 'DESC' );

        // Allowed sort columns
        $allowed_sort = [ 'class_code', 'total_staff', 'total_logs', 'valid_logs', 'last_active', 'avg_duration', 'valid_rate' ];
        if ( ! in_array( $orderby, $allowed_sort, true ) ) {
            $orderby = 'last_active';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $where = ["1=1"];
        $args = [];

        // Apply filters depending on the view mode
        if ( $mode === 'classes' ) {
            if ( ! empty($search) ) {
                if ( $exact ) {
                    $where[] = "p.class_code = %s";
                    $args[] = $search;
                } else {
                    $like = '%' . $wpdb->esc_like( $search ) . '%';
                    $where[] = "p.class_code LIKE %s";
                    $args[] = $like;
                }
            }
        } else {
            // personnel mode
            if ( ! empty( $class_filter ) ) {
                $where[] = "p.class_code = %s";
                $args[] = $class_filter;
            }
            if ( ! empty($search) ) {
                if ( $exact ) {
                    $where[] = "(p.user_name = %s OR p.emp_code = %s OR p.class_code = %s)";
                    $args[] = $search; $args[] = $search; $args[] = $search;
                } else {
                    $like = '%' . $wpdb->esc_like( $search ) . '%';
                    $where[] = "(p.user_name LIKE %s OR p.emp_code LIKE %s OR p.class_code LIKE %s)";
                    $args[] = $like; $args[] = $like; $args[] = $like;
                }
            }
        }

        if ( ! empty($team) ) {
            if ( $exact ) {
                $where[] = "p.team = %s";
                $args[] = $team;
            } else {
                $like = '%' . $wpdb->esc_like( $team ) . '%';
                $where[] = "p.team LIKE %s";
                $args[] = $like;
            }
        }

        $where_sql = implode(' AND ', $where);

        $log_where = ["1=1"];
        $log_args = [];
        if ( ! empty($start_date) ) {
            $log_where[] = "created_at >= %s";
            $log_args[] = $start_date . ' 00:00:00';
        }
        if ( ! empty($end_date) ) {
            $log_where[] = "created_at <= %s";
            $log_args[] = $end_date . ' 23:59:59';
        }
        $log_where_sql = implode(' AND ', $log_where);

        if ( $mode === 'classes' ) {
            // Count total distinct classes matching filters
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.class_code) FROM `{$table_personnel}` p WHERE {$where_sql}",
                ...$args
            ) );

            // Sorting normalization for classes mode
            if ( $orderby === 'class_code' ) {
                $sort_col = 'p.class_code';
            } elseif ( $orderby === 'total_staff' ) {
                $sort_col = 'total_staff';
            } elseif ( $orderby === 'total_logs' ) {
                $sort_col = 'total_logs';
            } elseif ( $orderby === 'valid_logs' ) {
                $sort_col = 'valid_logs';
            } elseif ( $orderby === 'avg_duration' ) {
                $sort_col = 'avg_duration';
            } elseif ( $orderby === 'valid_rate' ) {
                $sort_col = 'valid_rate';
            } else {
                $sort_col = 'last_active';
            }

            $data_sql = "
                SELECT 
                    p.class_code,
                    COUNT(DISTINCT p.id) as total_staff,
                    COALESCE(SUM(stats.total_logs), 0) as total_logs,
                    COALESCE(SUM(stats.valid_logs), 0) as valid_logs,
                    COALESCE(ROUND(AVG(stats.avg_duration), 1), 0) as avg_duration,
                    COALESCE(ROUND((SUM(stats.valid_logs) / SUM(stats.total_logs)) * 100, 1), 0) as valid_rate,
                    COALESCE(MAX(stats.last_active), '—') as last_active
                FROM `{$table_personnel}` p
                LEFT JOIN (
                    SELECT 
                        emp_code,
                        class_code,
                        COUNT(id) as total_logs,
                        SUM(IF(is_valid=1, 1, 0)) as valid_logs,
                        AVG(duration) as avg_duration,
                        MAX(created_at) as last_active
                    FROM `{$table_logs}`
                    WHERE {$log_where_sql}
                    GROUP BY emp_code, class_code
                ) stats ON LOWER(p.emp_code) = LOWER(stats.emp_code) AND LOWER(p.class_code) = LOWER(stats.class_code)
                WHERE {$where_sql}
                GROUP BY p.class_code
                ORDER BY {$sort_col} {$order}
                LIMIT %d OFFSET %d
            ";

            $all_prep_args = array_merge( $log_args, $args, [ $per_page, $offset ] );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$all_prep_args ), ARRAY_A );
        } else {
            // Count total registered staff matching the search filters
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(p.id) FROM `{$table_personnel}` p WHERE {$where_sql}",
                ...$args
            ) );

            // Sorting normalization for personnel mode
            if ( $orderby === 'class_code' ) {
                $sort_col = 'p.class_code';
            } elseif ( $orderby === 'total_logs' ) {
                $sort_col = 'total_logs';
            } elseif ( $orderby === 'valid_logs' ) {
                $sort_col = 'valid_logs';
            } elseif ( $orderby === 'avg_duration' ) {
                $sort_col = 'avg_duration';
            } elseif ( $orderby === 'valid_rate' ) {
                $sort_col = 'valid_rate';
            } else {
                $sort_col = 'last_active';
            }

            $data_sql = "
                SELECT 
                    p.id,
                    p.user_name,
                    p.emp_code,
                    p.class_code,
                    p.team,
                    p.created_at as registered_at,
                    COALESCE(stats.total_logs, 0) as total_logs,
                    COALESCE(stats.valid_logs, 0) as valid_logs,
                    COALESCE(stats.avg_duration, 0) as avg_duration,
                    COALESCE(stats.valid_rate, 0) as valid_rate,
                    COALESCE(stats.last_active, '—') as last_active
                FROM `{$table_personnel}` p
                LEFT JOIN (
                    SELECT 
                        emp_code,
                        class_code,
                        COUNT(id) as total_logs,
                        SUM(IF(is_valid=1, 1, 0)) as valid_logs,
                        ROUND(AVG(duration), 1) as avg_duration,
                        ROUND((SUM(IF(is_valid=1, 1, 0)) / COUNT(id)) * 100, 1) as valid_rate,
                        MAX(created_at) as last_active
                    FROM `{$table_logs}`
                    WHERE {$log_where_sql}
                    GROUP BY emp_code, class_code
                ) stats ON LOWER(p.emp_code) = LOWER(stats.emp_code) AND LOWER(p.class_code) = LOWER(stats.class_code)
                WHERE {$where_sql}
                ORDER BY {$sort_col} {$order}
                LIMIT %d OFFSET %d
            ";

            $all_prep_args = array_merge( $log_args, $args, [ $per_page, $offset ] );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$all_prep_args ), ARRAY_A );
        }

        return new WP_REST_Response( [
            'rows' => $rows ?: [],
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page
        ], 200 );
    }

    public function handle_create_personnel( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $table_personnel = $wpdb->prefix . 'st_personnel';
        
        $data = $req->get_json_params() ?: $req->get_body_params();
        $user_name  = sanitize_text_field( trim( $data['user_name'] ?? '' ) );
        $emp_code   = sanitize_text_field( trim( $data['emp_code'] ?? '' ) );
        $class_code = sanitize_text_field( trim( $data['class_code'] ?? '' ) );
        $team       = sanitize_text_field( trim( $data['team'] ?? '' ) );

        if ( empty( $user_name ) || empty( $emp_code ) || empty( $class_code ) || empty( $team ) ) {
            return new WP_REST_Response( [ 'error' => 'Vui lòng nhập đầy đủ tất cả thông tin.' ], 400 );
        }

        // Check if emp_code already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s)",
            $emp_code
        ) );

        if ( $exists > 0 ) {
            return new WP_REST_Response( [ 'error' => 'Mã nhân viên này đã tồn tại trên hệ thống.' ], 400 );
        }

        $inserted = $wpdb->insert( $table_personnel, [
            'user_name'  => $user_name,
            'emp_code'   => $emp_code,
            'class_code' => $class_code,
            'team'       => $team,
            'created_at' => current_time( 'mysql' )
        ], [ '%s', '%s', '%s', '%s', '%s' ] );

        if ( ! $inserted ) {
            return new WP_REST_Response( [ 'error' => 'Không thể lưu nhân sự mới.' ], 400 );
        }

        return new WP_REST_Response( [ 'id' => $wpdb->insert_id, 'message' => 'Thêm nhân sự thành công!' ], 201 );
    }

    public function handle_update_personnel( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param( 'id' );
        $table_personnel = $wpdb->prefix . 'st_personnel';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table_personnel}` WHERE id = %d", $id ), ARRAY_A );
        if ( ! $old ) {
            return new WP_REST_Response( [ 'error' => 'Nhân sự không tồn tại.' ], 404 );
        }

        $data = $req->get_json_params() ?: $req->get_body_params();
        $user_name  = isset( $data['user_name'] ) ? sanitize_text_field( trim( $data['user_name'] ) ) : $old['user_name'];
        $emp_code   = isset( $data['emp_code'] ) ? sanitize_text_field( trim( $data['emp_code'] ) ) : $old['emp_code'];
        $class_code = isset( $data['class_code'] ) ? sanitize_text_field( trim( $data['class_code'] ) ) : $old['class_code'];
        $team       = isset( $data['team'] ) ? sanitize_text_field( trim( $data['team'] ) ) : $old['team'];

        if ( empty( $user_name ) || empty( $emp_code ) || empty( $class_code ) || empty( $team ) ) {
            return new WP_REST_Response( [ 'error' => 'Thông tin không được để trống.' ], 400 );
        }

        // Check if new emp_code belongs to another person
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s) AND id != %d",
            $emp_code,
            $id
        ) );

        if ( $exists > 0 ) {
            return new WP_REST_Response( [ 'error' => 'Mã nhân viên này đã được sử dụng bởi người khác.' ], 400 );
        }

        $updated = $wpdb->update( $table_personnel, [
            'user_name'  => $user_name,
            'emp_code'   => $emp_code,
            'class_code' => $class_code,
            'team'       => $team
        ], [ 'id' => $id ], [ '%s', '%s', '%s', '%s' ], [ '%d' ] );

        if ( false === $updated ) {
            return new WP_REST_Response( [ 'error' => 'Không thể cập nhật nhân sự.' ], 400 );
        }

        return new WP_REST_Response( [ 'message' => 'Cập nhật nhân sự thành công!' ], 200 );
    }

    public function handle_delete_personnel( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param( 'id' );
        $table_personnel = $wpdb->prefix . 'st_personnel';
        $table_tracking  = $wpdb->prefix . ST_TABLE;
        $table_behavior  = $wpdb->prefix . 'st_behavior_logs';
        $table_payouts   = $wpdb->prefix . 'st_payouts';
        $table_assign    = $wpdb->prefix . 'st_assignments';
        
        if ( $id > 0 ) {
            // Lấy thông tin nhân sự trước khi xóa để làm khóa xóa các bảng liên quan
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $person = $wpdb->get_row( $wpdb->prepare( "SELECT emp_code, class_code FROM `{$table_personnel}` WHERE id = %d", $id ), ARRAY_A );
            
            if ( $person ) {
                $emp_code   = $person['emp_code'];
                $class_code = $person['class_code'];

                // 1. Xóa behavior logs của nhân sự này trước
                $wpdb->query( $wpdb->prepare(
                    "DELETE b FROM `{$table_behavior}` b JOIN `{$table_tracking}` t ON b.log_id = t.id WHERE t.emp_code = %s AND t.class_code = %s",
                    $emp_code,
                    $class_code
                ) );

                // 2. Xóa traffic logs của nhân sự này
                $wpdb->delete( $table_tracking, [ 'emp_code' => $emp_code, 'class_code' => $class_code ], [ '%s', '%s' ] );

                // 3. Xóa payouts liên quan của nhân sự này
                $wpdb->delete( $table_payouts, [ 'emp_code' => $emp_code ], [ '%s' ] );

                // 4. Xóa phân công KPI riêng cho nhân sự này (nếu có)
                $wpdb->delete( $table_assign, [ 'target_type' => 'emp_code', 'target_value' => $emp_code ], [ '%s', '%s' ] );
            }

            // 5. Xóa bản ghi nhân sự chính
            $success = $wpdb->delete( $table_personnel, [ 'id' => $id ], [ '%d' ] );
            if ( $success === false ) {
                return new WP_REST_Response( [ 'error' => 'Không thể xóa nhân sự.' ], 400 );
            }
            ST_Database::clear_kpi_cache();
            return new WP_REST_Response( [ 'message' => 'Xóa nhân sự và toàn bộ dữ liệu liên quan thành công!' ], 200 );
        }

        // Fallback: Xóa logs của một người (khi bấm "Xóa Logs" ngoài bảng)
        $emp_code   = sanitize_text_field( $req->get_param('emp_code') ?? '' );
        $class_code = sanitize_text_field( $req->get_param('class_code') ?? '' );

        // 1. Xóa behavior logs tương ứng
        $wpdb->query( $wpdb->prepare(
            "DELETE b FROM `{$table_behavior}` b JOIN `{$table_tracking}` t ON b.log_id = t.id WHERE t.emp_code = %s AND t.class_code = %s",
            $emp_code,
            $class_code
        ) );

        // 2. Xóa traffic logs tương ứng
        $success = $wpdb->delete( $table_tracking, [
            'emp_code'   => $emp_code,
            'class_code' => $class_code
        ], [ '%s', '%s' ] );

        ST_Database::clear_kpi_cache();
        return new WP_REST_Response( [ 'message' => 'Xóa logs của nhân sự thành công!', 'deleted_rows' => $success ], 200 );
    }

    public function handle_delete_class( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $class_code = sanitize_text_field( trim( $req->get_param( 'class' ) ?? '' ) );

        if ( empty( $class_code ) ) {
            return new WP_REST_Response( [ 'error' => 'Mã lớp không được để trống.' ], 400 );
        }

        $table_personnel = $wpdb->prefix . 'st_personnel';
        $table_tracking  = $wpdb->prefix . ST_TABLE;
        $table_behavior  = $wpdb->prefix . 'st_behavior_logs';
        $table_payouts   = $wpdb->prefix . 'st_payouts';
        $table_assign    = $wpdb->prefix . 'st_assignments';

        // 1. Xóa behavior logs trước
        $wpdb->query( $wpdb->prepare(
            "DELETE b FROM `{$table_behavior}` b JOIN `{$table_tracking}` t ON b.log_id = t.id WHERE t.class_code = %s",
            $class_code
        ) );

        // 2. Xóa traffic logs
        $wpdb->delete( $table_tracking, [ 'class_code' => $class_code ], [ '%s' ] );

        // 3. Xóa payouts của nhân sự thuộc lớp này
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table_payouts}` WHERE emp_code IN (SELECT emp_code FROM `{$table_personnel}` WHERE class_code = %s)",
            $class_code
        ) );

        // 4. Xóa nhân sự trong lớp
        $wpdb->delete( $table_personnel, [ 'class_code' => $class_code ], [ '%s' ] );

        // 5. Xóa phân công KPI (assignments) hướng tới lớp này
        $wpdb->delete( $table_assign, [ 'target_type' => 'class', 'target_value' => $class_code ], [ '%s', '%s' ] );

        // Clear cache KPI
        ST_Database::clear_kpi_cache();

        return new WP_REST_Response( [ 'success' => true, 'message' => sprintf( 'Đã xóa sạch lớp "%s" cùng toàn bộ dữ liệu nhân sự, traffic logs và phân công liên quan!', $class_code ) ], 200 );
    }

    public function handle_verify_staff( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $params = $req->get_json_params() ?: $req->get_body_params();
        $emp_code   = sanitize_text_field( trim( $params['emp_code'] ?? '' ) );
        $class_code = sanitize_text_field( trim( $params['class_code'] ?? '' ) );

        if ( empty( $emp_code ) || empty( $class_code ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Vui lòng nhập đầy đủ Mã nhân viên và Mã lớp!' ], 200 );
        }

        $table_personnel = $wpdb->prefix . 'st_personnel';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $staff = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_name, team FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s) AND LOWER(class_code) = LOWER(%s)",
            $emp_code,
            $class_code
        ), ARRAY_A );

        if ( ! $staff ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Mã nhân viên hoặc Mã lớp không chính xác hoặc chưa được đăng ký!' ], 200 );
        }

        return new WP_REST_Response( [
            'success'   => true,
            'user_name' => $staff['user_name'],
            'team'      => $staff['team']
        ], 200 );
    }

    public function handle_member_dashboard( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $emp_code   = sanitize_text_field( trim( $req->get_param('emp_code') ?? '' ) );
        $class_code = sanitize_text_field( trim( $req->get_param('class_code') ?? '' ) );

        if ( empty( $emp_code ) || empty( $class_code ) ) {
            return new WP_REST_Response( [ 'error' => 'Thiếu thông tin xác thực.' ], 400 );
        }

        $table_personnel = $wpdb->prefix . 'st_personnel';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $person = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_name, team FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s) AND LOWER(class_code) = LOWER(%s)",
            $emp_code,
            $class_code
        ), ARRAY_A );

        if ( ! $person ) {
            return new WP_REST_Response( [ 'error' => 'Nhân sự không tồn tại hoặc thông tin không khớp.' ], 403 );
        }

        $team = $person['team'];
        $payout_val = get_option( 'st_payout_per_kpi' );
        $payout_rate = ( $payout_val !== '' && $payout_val !== false ) ? (float) $payout_val : 0.0;

        $table_assign  = $wpdb->prefix . 'st_assignments';
        $table_tracking = $wpdb->prefix . ST_TABLE;
        $table_payouts  = $wpdb->prefix . 'st_payouts';

        // 1. Lấy tất cả nhiệm vụ ACTIVE áp dụng cho người này
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $active_assigns = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table_assign}`
             WHERE status = 'active'
               AND (
                   assign_scope = 'general'
                   OR (target_type = 'emp_code'   AND FIND_IN_SET(LOWER(%s), LOWER(target_value)) > 0)
                   OR (target_type = 'class_code' AND FIND_IN_SET(LOWER(%s), LOWER(target_value)) > 0)
                   OR (target_type = 'team'       AND FIND_IN_SET(LOWER(%s), LOWER(target_value)) > 0)
               )",
            $emp_code,
            $class_code,
            $team
        ), ARRAY_A );

        $slug_to_sku = [];
        if ( ! empty( $active_assigns ) ) {
            $all_slugs = [];
            foreach ( $active_assigns as $assign ) {
                $sel = $assign['product_selection'] ?? 'all';
                $allowed_paths_str = '';

                if ( 'phases' === $sel && ! empty( $assign['phases_data'] ) ) {
                    $phases = json_decode( $assign['phases_data'], true );
                    if ( is_array( $phases ) ) {
                        $today_date = date( 'Y-m-d' );
                        list( $a_start, $a_end ) = ST_Database::get_period_dates( $assign['period_type'], $assign['period_value'] );
                        foreach ( $phases as $p ) {
                            $p_start = !empty( $p['start_date'] ) ? $p['start_date'] : $a_start;
                            $p_end   = !empty( $p['end_date'] ) ? $p['end_date'] : $a_end;
                            if ( $today_date >= $p_start && $today_date <= $p_end ) {
                                $allowed_paths_str = $p['allowed_paths'] ?? '';
                                break;
                            }
                        }
                    }
                } else {
                    $allowed_paths_str = $assign['allowed_paths'] ?? '';
                }

                $allowed_arr = array_filter( array_map( 'trim', explode( "\n", $allowed_paths_str ) ) );
                foreach ( $allowed_arr as $path ) {
                    $slug = trim( $path, '/' );
                    $parts = explode( '/', $slug );
                    $last_part = end( $parts );
                    if ( ! empty( $last_part ) ) {
                        $all_slugs[$last_part] = true;
                    }
                }
            }

            if ( ! empty( $all_slugs ) ) {
                $slugs_keys = array_keys( $all_slugs );
                $slug_placeholders = implode( ',', array_fill( 0, count( $slugs_keys ), '%s' ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
                $results = $wpdb->get_results( $wpdb->prepare(
                    "SELECT p.post_name, m.meta_value as sku 
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_sku'
                     WHERE p.post_name IN ($slug_placeholders) AND p.post_type = 'product' AND p.post_status = 'publish'",
                    ...$slugs_keys
                ), ARRAY_A );

                if ( $results ) {
                    foreach ( $results as $r ) {
                        $slug_to_sku[$r['post_name']] = ! empty( $r['sku'] ) ? $r['sku'] : $r['post_name'];
                    }
                }
            }
        }

        $dt_now = new DateTime( 'now', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
        $today = $dt_now->format( 'Y-m-d' );
        $today_start = $today . ' 00:00:00';
        $today_end   = $today . ' 23:59:59';

        $dt_week_start = clone $dt_now;
        $dt_week_start->modify( 'monday this week' )->setTime( 0, 0, 0 );
        $dt_week_end = clone $dt_now;
        $dt_week_end->modify( 'sunday this week' )->setTime( 23, 59, 59 );
        $week_start = $dt_week_start->format( 'Y-m-d H:i:s' );
        $week_end   = $dt_week_end->format( 'Y-m-d H:i:s' );

        $dt_month_start = clone $dt_now;
        $dt_month_start->modify( 'first day of this month' )->setTime( 0, 0, 0 );
        $dt_month_end = clone $dt_now;
        $dt_month_end->modify( 'last day of this month' )->setTime( 23, 59, 59 );
        $month_start = $dt_month_start->format( 'Y-m-d H:i:s' );
        $month_end   = $dt_month_end->format( 'Y-m-d H:i:s' );

        $periods = [
            'today' => [
                'total_unpaid' => 0.0,
                'total_paid'   => 0.0,
                'missions'     => []
            ],
            'week' => [
                'total_unpaid' => 0.0,
                'total_paid'   => 0.0,
                'missions'     => []
            ],
            'month' => [
                'total_unpaid' => 0.0,
                'total_paid'   => 0.0,
                'missions'     => []
            ],
            'all' => [
                'total_unpaid' => 0.0,
                'total_paid'   => 0.0,
                'missions'     => []
            ]
        ];

        $earliest_start = $today;
        if ( ! empty( $active_assigns ) ) {
            foreach ( $active_assigns as $assign ) {
                list( $start_date, $end_date ) = ST_Database::get_period_dates( $assign['period_type'], $assign['period_value'] );
                if ( ! empty( $start_date ) && $start_date < $earliest_start ) {
                    $earliest_start = $start_date;
                }
            }
        }
        $earliest_start_dt = $earliest_start . ' 00:00:00';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $raw_logs = $wpdb->get_results( $wpdb->prepare(
            "SELECT page_path, created_at FROM `{$table_tracking}`
             WHERE emp_code = %s
               AND is_valid = 1
               AND created_at >= %s",
            $emp_code,
            $earliest_start_dt
        ), ARRAY_A );
        if ( ! $raw_logs ) {
            $raw_logs = [];
        }

        $active_missions = [];
        $completed_missions = [];

        // Tính toán tiến độ thực tế cho các nhiệm vụ active
        if ( ! empty( $active_assigns ) ) {
            foreach ( $active_assigns as $assign ) {
                list( $start_date, $end_date ) = ST_Database::get_period_dates( $assign['period_type'], $assign['period_value'] );
                if ( empty( $start_date ) || empty( $end_date ) ) {
                    continue;
                }

                $today_ymd = date( 'Y-m-d' );
                if ( $today_ymd < $start_date || $today_ymd > $end_date ) {
                    continue;
                }

                $sel = $assign['product_selection'] ?? 'all';
                $phases = [];
                if ( 'phases' === $sel && ! empty( $assign['phases_data'] ) ) {
                    $p_dec = json_decode( $assign['phases_data'], true );
                    if ( is_array( $p_dec ) ) $phases = $p_dec;
                } else {
                    $allowed_paths_str = $assign['allowed_paths'] ?? '';
                    $allowed_arr = array_filter( array_map( 'trim', explode( "\n", $allowed_paths_str ) ) );
                    $allowed_map = array_flip( $allowed_arr );
                }

                $achieved_all   = 0;
                $achieved_today = 0;
                $achieved_week  = 0;
                $achieved_month = 0;

                foreach ( $raw_logs as $log ) {
                    $ldate = $log['created_at'];
                    $path = $log['page_path'];

                    // check if log is within assignment active period (from 00:00 midnight)
                    if ( $ldate >= $start_date . ' 00:00:00' && $ldate <= $end_date . ' 23:59:59' ) {
                        // check if path is allowed
                        $is_valid_path = false;
                        if ( 'phases' === $sel ) {
                            foreach ( $phases as $p ) {
                                $p_start = !empty($p['start_date']) ? $p['start_date'] . ' 00:00:00' : $start_date . ' 00:00:00';
                                $p_end   = !empty($p['end_date'])   ? $p['end_date'] . ' 23:59:59'   : $end_date . ' 23:59:59';
                                if ( $ldate >= $p_start && $ldate <= $p_end ) {
                                    $p_sel = $p['product_selection'] ?? 'all';
                                    if ( 'all' === $p_sel ) {
                                        $is_valid_path = true;
                                    } else {
                                        $p_allowed = !empty($p['allowed_paths']) ? array_filter( array_map( 'trim', explode( "\n", $p['allowed_paths'] ) ) ) : [];
                                        if ( in_array( $path, $p_allowed, true ) || in_array( rtrim($path,'/').'/', $p_allowed, true ) ) {
                                            $is_valid_path = true;
                                        }
                                    }
                                    break;
                                }
                            }
                        } else {
                            if ( 'all' === $sel || isset( $allowed_map[$path] ) ) {
                                $is_valid_path = true;
                            }
                        }

                        if ( $is_valid_path ) {
                            $achieved_all++;

                            if ( $ldate >= $today_start && $ldate <= $today_end ) {
                                $achieved_today++;
                            }
                            if ( $ldate >= $week_start && $ldate <= $week_end ) {
                                $achieved_week++;
                            }
                            if ( $ldate >= $month_start && $ldate <= $month_end ) {
                                $achieved_month++;
                            }
                        }
                    }
                }

                $kpi_target = (int) $assign['kpi_target'];
                $is_done = ( $achieved_all >= $kpi_target );
                
                $earnings_all   = $achieved_all * $payout_rate;
                $earnings_today = $achieved_today * $payout_rate;
                $earnings_week  = $achieved_week * $payout_rate;
                $earnings_month = $achieved_month * $payout_rate;

                $periods['all']['total_unpaid']   += $earnings_all;
                $periods['today']['total_unpaid'] += $earnings_today;
                $periods['week']['total_unpaid']  += $earnings_week;
                $periods['month']['total_unpaid'] += $earnings_month;

                $paths_data = [];
                foreach ( $allowed_arr as $path ) {
                    // Nếu đường dẫn này nhân viên đã làm hôm nay rồi thì không hiển thị nữa (để nhường chỗ cho link khác)
                    if ( ST_Database::is_link_done_today( $emp_code, $path ) ) {
                        continue;
                    }

                    $slug = trim( $path, '/' );
                    $parts = explode( '/', $slug );
                    $last_part = end( $parts );
                    $sku = $slug_to_sku[$last_part] ?? $last_part;
                    $paths_data[] = [
                        'path' => $path,
                        'sku'  => $sku
                    ];
                }

                $item = [
                    'id'           => $assign['id'],
                    'title'        => $assign['title'],
                    'period_type'  => $assign['period_type'],
                    'period_value' => $assign['period_value'],
                    'kpi_target'   => $kpi_target,
                    'kpi_achieved' => $achieved_all,
                    'earnings'     => $earnings_all,
                    'status'       => $is_done ? 'completed' : 'active',
                    'paths'        => $paths_data
                ];

                if ( $is_done ) {
                    $completed_missions[] = $item;
                } else {
                    $active_missions[] = $item;
                }

                // Push details to periods
                $periods['all']['missions'][] = [
                    'title'        => $assign['title'],
                    'period_value' => $assign['period_value'],
                    'kpi_achieved' => $achieved_all,
                    'kpi_target'   => $kpi_target,
                    'amount'       => $earnings_all,
                    'status'       => 'unpaid'
                ];
                $periods['today']['missions'][] = [
                    'title'        => $assign['title'],
                    'period_value' => $assign['period_value'],
                    'kpi_achieved' => $achieved_today,
                    'kpi_target'   => $kpi_target,
                    'amount'       => $earnings_today,
                    'status'       => 'unpaid'
                ];
                $periods['week']['missions'][] = [
                    'title'        => $assign['title'],
                    'period_value' => $assign['period_value'],
                    'kpi_achieved' => $achieved_week,
                    'kpi_target'   => $kpi_target,
                    'amount'       => $earnings_week,
                    'status'       => 'unpaid'
                ];
                $periods['month']['missions'][] = [
                    'title'        => $assign['title'],
                    'period_value' => $assign['period_value'],
                    'kpi_achieved' => $achieved_month,
                    'kpi_target'   => $kpi_target,
                    'amount'       => $earnings_month,
                    'status'       => 'unpaid'
                ];
            }
        }

        // 2. Lấy tất cả lịch sử phân công/thanh toán cũ từ bảng payouts
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $payout_records = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, a.title FROM `{$table_payouts}` p
             LEFT JOIN `{$table_assign}` a ON p.assignment_id = a.id
             WHERE p.emp_code = %s
             ORDER BY p.id DESC",
            $emp_code
        ), ARRAY_A );

        $history = [];
        $active_assign_ids = array_column( $active_assigns, 'id' );

        // Đưa các nhiệm vụ đang active đã hoàn thành vào danh sách lịch sử hiển thị
        foreach ( $completed_missions as $m ) {
            $history[] = [
                'title'        => $m['title'],
                'period_value' => $m['period_value'],
                'kpi_target'   => $m['kpi_target'],
                'kpi_achieved' => $m['kpi_achieved'],
                'amount'       => $m['earnings'],
                'status'       => 'unpaid'
            ];
        }

        if ( ! empty( $payout_records ) ) {
            foreach ( $payout_records as $p ) {
                $is_active_assign = in_array( (int) $p['assignment_id'], $active_assign_ids, true );
                $p_date = $p['created_at'];

                if ( ! $is_active_assign ) {
                    if ( $p['status'] === 'paid' ) {
                        $periods['all']['total_paid'] += (float) $p['amount'];
                    } else {
                        $periods['all']['total_unpaid'] += (float) $p['amount'];
                    }
                    $periods['all']['missions'][] = [
                        'title'        => $p['title'] ?: 'Nhiệm vụ cũ',
                        'period_value' => $p['period_value'],
                        'kpi_achieved' => (int) $p['kpi_achieved'],
                        'kpi_target'   => (int) $p['kpi_target'],
                        'amount'       => (float) $p['amount'],
                        'status'       => $p['status']
                    ];

                    if ( $p_date >= $today_start && $p_date <= $today_end ) {
                        if ( $p['status'] === 'paid' ) {
                            $periods['today']['total_paid'] += (float) $p['amount'];
                        } else {
                            $periods['today']['total_unpaid'] += (float) $p['amount'];
                        }
                        $periods['today']['missions'][] = [
                            'title'        => $p['title'] ?: 'Nhiệm vụ cũ',
                            'period_value' => $p['period_value'],
                            'kpi_achieved' => (int) $p['kpi_achieved'],
                            'kpi_target'   => (int) $p['kpi_target'],
                            'amount'       => (float) $p['amount'],
                            'status'       => $p['status']
                        ];
                    }

                    if ( $p_date >= $week_start && $p_date <= $week_end ) {
                        if ( $p['status'] === 'paid' ) {
                            $periods['week']['total_paid'] += (float) $p['amount'];
                        } else {
                            $periods['week']['total_unpaid'] += (float) $p['amount'];
                        }
                        $periods['week']['missions'][] = [
                            'title'        => $p['title'] ?: 'Nhiệm vụ cũ',
                            'period_value' => $p['period_value'],
                            'kpi_achieved' => (int) $p['kpi_achieved'],
                            'kpi_target'   => (int) $p['kpi_target'],
                            'amount'       => (float) $p['amount'],
                            'status'       => $p['status']
                        ];
                    }

                    if ( $p_date >= $month_start && $p_date <= $month_end ) {
                        if ( $p['status'] === 'paid' ) {
                            $periods['month']['total_paid'] += (float) $p['amount'];
                        } else {
                            $periods['month']['total_unpaid'] += (float) $p['amount'];
                        }
                        $periods['month']['missions'][] = [
                            'title'        => $p['title'] ?: 'Nhiệm vụ cũ',
                            'period_value' => $p['period_value'],
                            'kpi_achieved' => (int) $p['kpi_achieved'],
                            'kpi_target'   => (int) $p['kpi_target'],
                            'amount'       => (float) $p['amount'],
                            'status'       => $p['status']
                        ];
                    }

                    $history[] = [
                        'title'        => $p['title'] ?: 'Nhiệm vụ cũ',
                        'period_value' => $p['period_value'],
                        'kpi_target'   => (int) $p['kpi_target'],
                        'kpi_achieved' => (int) $p['kpi_achieved'],
                        'amount'       => (float) $p['amount'],
                        'status'       => $p['status']
                    ];
                }
            }
        }

        $today_start_log = $today_start;
        $today_end_log   = $today_end;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $today_visited_paths = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT page_path FROM `{$table_tracking}`
             WHERE emp_code = %s
               AND is_valid = 1
               AND created_at BETWEEN %s AND %s",
            $emp_code,
            $today_start_log,
            $today_end_log
        ) );
        if ( ! $today_visited_paths ) {
            $today_visited_paths = [];
        }

        return new WP_REST_Response( [
            'success'            => true,
            'active_missions'    => $active_missions,
            'completed_missions' => $history,
            'periods'            => $periods,
            'today_visited_paths'=> $today_visited_paths
        ], 200 );
    }

    public function handle_member_history( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $emp_code   = sanitize_text_field( trim( $req->get_param('emp_code') ?? '' ) );
        $class_code = sanitize_text_field( trim( $req->get_param('class_code') ?? '' ) );
        $page       = max( 1, (int) $req->get_param('page') );
        $per_page   = max( 1, (int) $req->get_param('per_page') ?: 5 );

        if ( empty( $emp_code ) || empty( $class_code ) ) {
            return new WP_REST_Response( [ 'error' => 'Thiếu thông tin xác thực.' ], 400 );
        }

        $table_personnel = $wpdb->prefix . 'st_personnel';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $person = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s) AND LOWER(class_code) = LOWER(%s)",
            $emp_code,
            $class_code
        ) );

        if ( ! $person ) {
            return new WP_REST_Response( [ 'error' => 'Nhân sự không tồn tại.' ], 403 );
        }

        $table_tracking = $wpdb->prefix . ST_TABLE;
        $today_start = date( 'Y-m-d' ) . ' 00:00:00';
        $today_end   = date( 'Y-m-d' ) . ' 23:59:59';
        $offset = ( $page - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total_items = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table_tracking}`
             WHERE emp_code = %s
               AND is_valid = 1
               AND created_at BETWEEN %s AND %s",
            $emp_code,
            $today_start,
            $today_end
        ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $logs = $wpdb->get_results( $wpdb->prepare(
            "SELECT page_path, created_at FROM `{$table_tracking}`
             WHERE emp_code = %s
               AND is_valid = 1
               AND created_at BETWEEN %s AND %s
             ORDER BY id DESC
             LIMIT %d OFFSET %d",
            $emp_code,
            $today_start,
            $today_end,
            $per_page,
            $offset
        ), ARRAY_A );

        $total_pages = (int) ceil( $total_items / $per_page );

        return new WP_REST_Response( [
            'success'     => true,
            'logs'        => $logs ?: [],
            'total_items' => $total_items,
            'total_pages' => max( 1, $total_pages ),
            'current_page'=> $page
        ], 200 );
    }

    // ── Assignments CRUD ────────────────────────────────────────────────────────

    private function auto_expire_assignments(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'st_assignments';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $active_assigns = $wpdb->get_results( "SELECT id, status, period_type, period_value FROM `{$table}` WHERE status != 'ended'", ARRAY_A );
        $today = date( 'Y-m-d' );
        if ( ! empty( $active_assigns ) ) {
            foreach ( $active_assigns as $as ) {
                list( $start_date, $end_date ) = ST_Database::get_period_dates( $as['period_type'], $as['period_value'] );
                if ( ! empty( $end_date ) && $today > $end_date ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->update( $table, [ 'status' => 'ended' ], [ 'id' => $as['id'] ], [ '%s' ], [ '%d' ] );
                    ST_Database::calculate_single_assignment_payout( $as['id'] );
                }
            }
        }
    }
    
    public function handle_get_assignments( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $this->auto_expire_assignments();
        $table = $wpdb->prefix . 'st_assignments';
        
        $period_type  = sanitize_text_field( $req->get_param('period_type') ?? '' );
        $period_value = sanitize_text_field( $req->get_param('period_value') ?? '' );
        
        // Cú pháp cũ truyền "month", xử lý tương thích ngược
        if ( ! $req->has_param('period_type') && $req->has_param('month') ) {
            $period_type = 'month';
            $period_value = sanitize_text_field( $req->get_param('month') );
        }
        
        $page     = absint( $req->get_param('page') ?? 1 );
        $per_page = absint( $req->get_param('per_page') ?? 20 );
        $offset   = ($page - 1) * $per_page;

        $search = sanitize_text_field( $req->get_param('search') ?? '' );

        $where = ['1=1'];
        $args  = [];

        if ( ! empty( $search ) ) {
            $where[] = "(title LIKE %s OR target_value LIKE %s)";
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $args[]  = $like;
            $args[]  = $like;
        }

        if ( ! empty( $period_type ) && 'all' !== $period_type ) {
            $where[] = "period_type = %s";
            $args[]  = $period_type;
            if ( ! empty( $period_value ) ) {
                $where[] = "period_value = %s";
                $args[]  = $period_value;
            }
        }

        $where_sql = implode( ' AND ', $where );

        // Đếm tổng số bản ghi
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        if ( ! empty( $args ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) );
        } else {
            $total = (int) $wpdb->get_var( $count_sql );
        }

        // Lấy danh sách phân công phân trang và sắp xếp theo thời gian tạo mới nhất
        $data_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $data_args = array_merge( $args, [ $per_page, $offset ] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assignments = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_args ), ARRAY_A );

        if ( ! $assignments ) {
            return new WP_REST_Response( [ 'rows' => [], 'total' => 0 ], 200 );
        }

        // Tính tiến độ (đếm số traffic hợp lệ từ bảng staff_tracking)
        $logs_table = $wpdb->prefix . ST_TABLE;
        $assign_table = $wpdb->prefix . 'st_assignments';

        // 1. Gộp truy vấn thống kê payouts của toàn bộ assignments trong trang này
        $assign_ids = array_column( $assignments, 'id' );
        $payout_stats_map = [];
        if ( ! empty( $assign_ids ) ) {
            $ids_str = implode( ',', array_map( 'intval', $assign_ids ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $p_stats_list = $wpdb->get_results(
                "SELECT assignment_id,
                        COUNT(*) as completed_count,
                        COALESCE(SUM(amount), 0) as total_amount,
                        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as paid_amount,
                        COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END), 0) as unpaid_amount
                 FROM `{$wpdb->prefix}st_payouts`
                 WHERE assignment_id IN ($ids_str)
                 GROUP BY assignment_id",
                ARRAY_A
            );
            if ( $p_stats_list ) {
                foreach ( $p_stats_list as $ps ) {
                    $payout_stats_map[(int)$ps['assignment_id']] = $ps;
                }
            }
        }

        foreach ( $assignments as &$row ) {
            $scope = $row['assign_scope'] ?? 'specific';
            $type  = $row['target_type']; // 'emp_code', 'class_code', 'team'
            $val   = $row['target_value'];
            $ptype = $row['period_type'] ?? 'month';
            $pval  = $row['period_value'] ?? $row['month'] ?? '';
            
            $col = '';
            if ( 'specific' === $scope ) {
                $col = 'team';
                if ( $type === 'emp_code' ) $col = 'emp_code';
                elseif ( $type === 'class_code' ) $col = 'class_code';
            }

            // Lấy danh sách URL sản phẩm được phép của nhiệm vụ này
            $allowed_paths = [];
            $sel = $row['product_selection'] ?? 'all';
            
            if ( 'all' !== $sel ) {
                // Tự động cập nhật nếu dữ liệu cũ chưa có allowed_paths (Self-healing migration)
                if ( null === $row['allowed_paths'] ) {
                    $resolved = $this->resolve_allowed_paths( $sel, $row['product_ids'] ?? '', $row['category_ids'] ?? '' );
                    $wpdb->update(
                        $assign_table,
                        [ 'allowed_paths' => $resolved ],
                        [ 'id' => $row['id'] ],
                        [ '%s' ],
                        [ '%d' ]
                    );
                    $row['allowed_paths'] = $resolved;
                }

                if ( ! empty( $row['allowed_paths'] ) ) {
                    $allowed_paths = array_filter( array_map( 'trim', explode( "\n", $row['allowed_paths'] ) ) );
                }
            }

            // Xây dựng các điều kiện SQL
            $where_clauses = [ 'is_valid = 1' ];
            $query_args    = [];

            if ( ! empty( $row['created_at'] ) ) {
                $where_clauses[] = 'created_at >= %s';
                $query_args[]    = $row['created_at'];
            }

            if ( 'specific' === $scope && $col ) {
                $vals = array_filter( array_map( 'trim', explode( ',', $val ) ) );
                if ( ! empty( $vals ) ) {
                    $val_placeholders = implode( ',', array_fill( 0, count($vals), '%s' ) );
                    $where_clauses[] = "`{$col}` IN ($val_placeholders)";
                    foreach ( $vals as $v ) {
                        $query_args[] = $v;
                    }
                }
            }

            // KHÔNG thêm điều kiện page_path IN vào SQL nữa để tránh câu lệnh SQL khổng lồ làm treo cơ sở dữ liệu.
            // Thay vào đó, ta thực hiện GROUP BY page_path và khớp nối trong PHP với tốc độ O(1) cực kỳ tối ưu.

            $achieved = 0;
            list( $start_date, $end_date ) = ST_Database::get_period_dates( $ptype, $pval );
            if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
                $where_clauses[] = "created_at >= %s";
                $where_clauses[] = "created_at <= %s";
                $query_args[]    = $start_date . ' 00:00:00';
                $query_args[]    = $end_date . ' 23:59:59';
                $where_sql = implode( ' AND ', $where_clauses );
                
                // Lấy toàn bộ nhóm dữ liệu
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $results = $wpdb->get_results( $wpdb->prepare(
                    "SELECT DATE(created_at) as log_date, page_path, COUNT(id) as cnt 
                     FROM `{$logs_table}` 
                     WHERE {$where_sql} 
                     GROUP BY DATE(created_at), page_path",
                    ...$query_args
                ), ARRAY_A );
                
                if ( $results ) {
                    foreach ( $results as $res_row ) {
                        $log_date = $res_row['log_date'];
                        
                        $effective_assign = ST_Database::get_assign_rules_at_time( $row, $log_date . ' 23:59:59' );
                        $eff_sel = $effective_assign['product_selection'] ?? 'all';
                        
                        $eff_phases = [];
                        if ( 'phases' === $eff_sel && ! empty( $effective_assign['phases_data'] ) ) {
                            $p_dec = json_decode( $effective_assign['phases_data'], true );
                            if ( is_array( $p_dec ) ) $eff_phases = $p_dec;
                        } else {
                            $eff_allowed = [];
                            if ( 'all' !== $eff_sel && ! empty( $effective_assign['allowed_paths'] ) ) {
                                $eff_allowed = array_filter( array_map( 'trim', explode( "\n", $effective_assign['allowed_paths'] ) ) );
                            }
                        }
                        
                        $is_valid = false;
                        $p = rtrim( $res_row['page_path'], '/' ) . '/';
                        
                        if ( 'phases' === $eff_sel ) {
                            foreach ( $eff_phases as $phase ) {
                                $p_start = !empty( $phase['start_date'] ) ? $phase['start_date'] : $start_date;
                                $p_end   = !empty( $phase['end_date'] ) ? $phase['end_date'] : $end_date;
                                if ( $log_date >= $p_start && $log_date <= $p_end ) {
                                    $p_sel = $phase['product_selection'] ?? 'all';
                                    if ( 'all' === $p_sel ) {
                                        $is_valid = true;
                                    } else {
                                        $p_allowed = !empty( $phase['allowed_paths'] ) ? array_filter( array_map( 'trim', explode( "\n", $phase['allowed_paths'] ) ) ) : [];
                                        if ( in_array( $res_row['page_path'], $p_allowed, true ) || in_array( $p, $p_allowed, true ) ) {
                                            $is_valid = true;
                                        }
                                    }
                                    break;
                                }
                            }
                        } else {
                            if ( 'all' === $eff_sel ) {
                                $is_valid = true;
                            } else {
                                if ( in_array( $res_row['page_path'], $eff_allowed, true ) || in_array( $p, $eff_allowed, true ) ) {
                                    $is_valid = true;
                                }
                            }
                        }
                        
                        if ( $is_valid ) {
                            $achieved += (int) $res_row['cnt'];
                        }
                    }
                }
            }

            $row['achieved'] = (int) $achieved;

            // Map payouts statistics từ truy vấn gộp bên ngoài
            $p_stats = $payout_stats_map[(int)$row['id']] ?? [
                'completed_count' => 0,
                'total_amount'    => 0,
                'paid_amount'     => 0,
                'unpaid_amount'   => 0
            ];

            $row['payouts_completed_count'] = (int) $p_stats['completed_count'];
            $row['payouts_total_amount']    = (float) $p_stats['total_amount'];
            $row['payouts_paid_amount']     = (float) $p_stats['paid_amount'];
            $row['payouts_unpaid_amount']   = (float) $p_stats['unpaid_amount'];
        }

        $global_unpaid = (float) $wpdb->get_var( "SELECT SUM(amount) FROM `{$wpdb->prefix}st_payouts` WHERE status = 'unpaid'" );
        $global_paid   = (float) $wpdb->get_var( "SELECT SUM(amount) FROM `{$wpdb->prefix}st_payouts` WHERE status = 'paid'" );

        return new WP_REST_Response( [
            'rows'         => $assignments,
            'total'        => $total,
            'total_unpaid' => $global_unpaid,
            'total_paid'   => $global_paid
        ], 200 );
    }

    /**
     * Pre-compute product URL paths for an assignment.
     * Runs ONE time when admin creates/edits — never on visitor page load.
     * Uses direct DB queries to avoid slow WordPress API (get_posts, get_permalink).
     *
     * @return string|null  newline-separated paths, or NULL for 'all' products.
     */
    private function resolve_allowed_paths( string $prod_sel, string $prod_ids_raw, string $cat_ids_raw ): ?string {
        global $wpdb;

        if ( 'all' === $prod_sel ) {
            return null; // NULL = tất cả sản phẩm → không cần lưu gì
        }

        $post_ids = [];

        if ( in_array( $prod_sel, ['custom', 'random_custom'], true ) && ! empty( $prod_ids_raw ) ) {
            // Lấy trực tiếp từ danh sách ID admin đã chọn
            $post_ids = array_filter( array_map( 'intval', explode( ',', $prod_ids_raw ) ) );

        } elseif ( in_array( $prod_sel, ['category', 'random_category'], true ) && ! empty( $cat_ids_raw ) ) {
            // Direct query: lấy sản phẩm theo danh mục — không dùng get_posts()
            // wp_term_relationships + wp_posts: 2 bảng được index tốt, nhanh dù 100k sản phẩm
            $cat_ids = array_filter( array_map( 'intval', explode( ',', $cat_ids_raw ) ) );
            if ( empty( $cat_ids ) ) return '';

            $placeholders = implode( ',', array_fill( 0, count( $cat_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
            $post_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT tr.object_id
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                 WHERE tr.term_taxonomy_id IN ({$placeholders})
                   AND p.post_type = 'product'
                   AND p.post_status = 'publish'",
                ...$cat_ids
            ) );
        } elseif ( 'random_all' === $prod_sel ) {
            $post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
        }

        if ( empty( $post_ids ) ) return '';

        // Lấy post_name (slug) cho tất cả ID trong 1 query — không dùng get_permalink()
        // page_path = /<post_name>/ là đủ để match với tracker.js page_path
        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $slugs = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_name, post_parent, post_type
             FROM {$wpdb->posts}
             WHERE ID IN ({$placeholders}) AND post_status = 'publish'",
            ...$post_ids
        ), ARRAY_A );

        // Lấy page_path đầy đủ từ wp_options (siteurl) + rewrite structure
        // Với WooCommerce: sản phẩm thường là /san-pham/{slug}/ hoặc /{slug}/
        // Ta lấy permalink chính xác nhưng chỉ lấy PATH (không dùng get_permalink loop)
        // Batch get permalink bằng URL cache của WordPress
        $paths = [];
        foreach ( $slugs as $row ) {
            $permalink = get_permalink( (int) $row['ID'] );
            if ( $permalink ) {
                $path = wp_parse_url( $permalink, PHP_URL_PATH );
                if ( $path ) $paths[] = rtrim( $path, '/' ) . '/';
            }
        }

        return empty( $paths ) ? '' : implode( "\n", $paths );
    }

    private function process_phases_data( $data ) {
        $phases = isset( $data['phases'] ) && is_array( $data['phases'] ) ? $data['phases'] : [];
        $processed_phases = [];
        foreach ( $phases as $p ) {
            $p_sel      = sanitize_text_field( $p['product_selection'] ?? 'all' );
            $p_prod_ids = sanitize_text_field( $p['product_ids'] ?? '' );
            $p_cat_ids  = sanitize_text_field( $p['category_ids'] ?? '' );
            $p_start    = sanitize_text_field( $p['start_date'] ?? '' );
            $p_end      = sanitize_text_field( $p['end_date'] ?? '' );

            $p_allowed_paths = $this->resolve_allowed_paths( $p_sel, $p_prod_ids, $p_cat_ids );

            $processed_phases[] = [
                'start_date'        => $p_start,
                'end_date'          => $p_end,
                'product_selection' => $p_sel,
                'product_ids'       => $p_prod_ids,
                'category_ids'      => $p_cat_ids,
                'allowed_paths'     => $p_allowed_paths,
            ];
        }
        return wp_json_encode( $processed_phases );
    }

    public function handle_create_assignment( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'st_assignments';
        
        $data = $req->get_json_params() ?: $req->get_body_params();
        $title        = sanitize_text_field( $data['title'] ?? '' );
        $period_type  = sanitize_text_field( $data['period_type'] ?? 'month' );
        $period_value = sanitize_text_field( $data['period_value'] ?? date('Y-m') );
        $scope        = sanitize_text_field( $data['assign_scope'] ?? 'specific' );
        $type         = sanitize_text_field( $data['target_type'] ?? 'team' );
        $value        = sanitize_text_field( $data['target_value'] ?? '' );
        $prod_sel     = sanitize_text_field( $data['product_selection'] ?? 'all' );
        $prod_ids     = sanitize_text_field( $data['product_ids'] ?? '' );
        $cat_ids      = sanitize_text_field( $data['category_ids'] ?? '' );
        $kpi          = absint( $data['kpi_target'] ?? 0 );
        $status       = sanitize_text_field( $data['status'] ?? 'active' );

        if ( empty( $title ) ) {
            return new WP_REST_Response( [ 'error' => 'Vui lòng nhập tên nhiệm vụ.' ], 400 );
        }
        if ( 'specific' === $scope && empty( $value ) ) {
            return new WP_REST_Response( [ 'error' => 'Vui lòng nhập đối tượng nhận nhiệm vụ.' ], 400 );
        }
        if ( empty( $kpi ) || empty( $period_value ) ) {
            return new WP_REST_Response( [ 'error' => 'Thiếu thông tin phân công.' ], 400 );
        }

        // Lấy thông tin người phân công
        $current_user = wp_get_current_user();
        $created_by   = $current_user->display_name ?: $current_user->user_login;

        $phases_data = null;
        $allowed_paths = null;
        if ( 'phases' === $prod_sel ) {
            $phases_data = $this->process_phases_data( $data );
        } else {
            $allowed_paths = $this->resolve_allowed_paths( $prod_sel, $prod_ids, $cat_ids );
        }

        $inserted = $wpdb->insert( $table, [
            'title'             => $title,
            'created_by'        => $created_by,
            'status'            => $status,
            'period_type'       => $period_type,
            'period_value'      => $period_value,
            'assign_scope'      => $scope,
            'target_type'       => $type,
            'target_value'      => $value,
            'product_selection' => $prod_sel,
            'product_ids'       => $prod_ids,
            'category_ids'      => $cat_ids,
            'allowed_paths'     => $allowed_paths,
            'phases_data'       => $phases_data,
            'holidays'          => isset( $data['holidays'] ) && is_array( $data['holidays'] ) ? wp_json_encode( $data['holidays'] ) : null,
            'kpi_target'        => $kpi
        ], [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ] );

        if ( ! $inserted ) {
            return new WP_REST_Response( [ 'error' => 'Không thể thêm phân công. Chi tiết: ' . $wpdb->last_error ], 400 );
        }

        ST_Database::clear_kpi_cache();
        ST_Database::bump_assignment_version();
        $this->clear_check_mission_transients();
        return new WP_REST_Response( [ 'id' => $wpdb->insert_id, 'message' => 'Created successfully' ], 201 );
    }

    public function handle_update_assignment( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param( 'id' );
        $table = $wpdb->prefix . 'st_assignments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $old_assign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );
        if ( ! $old_assign ) {
            return new WP_REST_Response( [ 'error' => 'Phân công không tồn tại.' ], 404 );
        }
        if ( 'ended' === $old_assign['status'] ) {
            return new WP_REST_Response( [ 'error' => 'Nhiệm vụ đã kết thúc không thể chỉnh sửa hoặc xóa.' ], 400 );
        }

        $data = $req->get_json_params() ?: $req->get_body_params();

        $title        = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : $old_assign['title'];
        $period_type  = isset( $data['period_type'] ) ? sanitize_text_field( $data['period_type'] ) : $old_assign['period_type'];
        $period_value = isset( $data['period_value'] ) ? sanitize_text_field( $data['period_value'] ) : $old_assign['period_value'];
        $scope        = isset( $data['assign_scope'] ) ? sanitize_text_field( $data['assign_scope'] ) : $old_assign['assign_scope'];
        $type         = isset( $data['target_type'] ) ? sanitize_text_field( $data['target_type'] ) : $old_assign['target_type'];
        $value        = isset( $data['target_value'] ) ? sanitize_text_field( $data['target_value'] ) : $old_assign['target_value'];
        $prod_sel     = isset( $data['product_selection'] ) ? sanitize_text_field( $data['product_selection'] ) : $old_assign['product_selection'];
        $prod_ids     = isset( $data['product_ids'] ) ? sanitize_text_field( $data['product_ids'] ) : $old_assign['product_ids'];
        $cat_ids      = isset( $data['category_ids'] ) ? sanitize_text_field( $data['category_ids'] ) : $old_assign['category_ids'];
        $kpi          = isset( $data['kpi_target'] ) ? absint( $data['kpi_target'] ) : intval( $old_assign['kpi_target'] );
        $status       = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : $old_assign['status'];

        if ( empty( $title ) ) {
            return new WP_REST_Response( [ 'error' => 'Tên nhiệm vụ không được để trống.' ], 400 );
        }
        if ( 'specific' === $scope && empty( $value ) ) {
            return new WP_REST_Response( [ 'error' => 'Vui lòng nhập đối tượng nhận nhiệm vụ.' ], 400 );
        }
        if ( empty( $kpi ) || empty( $period_value ) ) {
            return new WP_REST_Response( [ 'error' => 'Thiếu thông tin phân công.' ], 400 );
        }

        $phases_data = null;
        $allowed_paths = null;
        if ( 'phases' === $prod_sel ) {
            $phases_data = $this->process_phases_data( $data );
        } else {
            $allowed_paths = $this->resolve_allowed_paths( $prod_sel, $prod_ids, $cat_ids );
            $phases_data = null; // Clear phases if switching back
        }

        $rules_changed = (
            (int)$kpi !== (int)$old_assign['kpi_target'] ||
            $prod_sel !== $old_assign['product_selection'] ||
            $prod_ids !== $old_assign['product_ids'] ||
            $cat_ids !== $old_assign['category_ids'] ||
            $allowed_paths !== $old_assign['allowed_paths']
        );
        if ( 'phases' === $prod_sel ) {
            $rules_changed = $rules_changed || ( $phases_data !== $old_assign['phases_data'] );
        }

        if ( $rules_changed ) {
            $history_key = 'st_assign_history_' . $id;
            $history = get_option( $history_key, [] );
            if ( ! is_array( $history ) ) $history = [];
            
            $yesterday_end = wp_date( 'Y-m-d 23:59:59', strtotime( '-1 day' ) );
            if ( ! isset( $history[ $yesterday_end ] ) ) {
                $history[ $yesterday_end ] = [
                    'kpi_target'        => $old_assign['kpi_target'],
                    'product_selection' => $old_assign['product_selection'],
                    'product_ids'       => $old_assign['product_ids'],
                    'category_ids'      => $old_assign['category_ids'],
                    'allowed_paths'     => $old_assign['allowed_paths'],
                    'phases_data'       => $old_assign['phases_data'],
                ];
                update_option( $history_key, $history );
            }
        }

        $updated = $wpdb->update( $table, [
            'title'             => $title,
            'period_type'       => $period_type,
            'period_value'      => $period_value,
            'assign_scope'      => $scope,
            'target_type'       => $type,
            'target_value'      => $value,
            'product_selection' => $prod_sel,
            'product_ids'       => $prod_ids,
            'category_ids'      => $cat_ids,
            'allowed_paths'     => $allowed_paths,
            'phases_data'       => $phases_data,
            'kpi_target'        => $kpi,
            'status'            => $status,
        ], [ 'id' => $id ], [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ], [ '%d' ] );

        if ( $updated === false ) {
            return new WP_REST_Response( [ 'error' => 'Không thể cập nhật phân công. Chi tiết: ' . $wpdb->last_error ], 400 );
        }

        if ( 'ended' === $status && 'ended' !== $old_assign['status'] ) {
            ST_Database::calculate_single_assignment_payout( $id );
        }

        ST_Database::clear_kpi_cache();
        ST_Database::bump_assignment_version();
        $this->clear_check_mission_transients();
        return new WP_REST_Response( [ 'id' => $id, 'message' => 'Updated successfully' ], 200 );
    }

    public function handle_delete_assignment( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param( 'id' );
        $table = $wpdb->prefix . 'st_assignments';
        $table_payouts = $wpdb->prefix . 'st_payouts';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $old_assign = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );
        if ( $old_assign && 'ended' === $old_assign['status'] ) {
            return new WP_REST_Response( [ 'error' => 'Nhiệm vụ đã kết thúc không thể chỉnh sửa hoặc xóa.' ], 400 );
        }

        // 1. Xóa toàn bộ payouts liên quan đến nhiệm vụ này trước
        $wpdb->delete( $table_payouts, [ 'assignment_id' => $id ], [ '%d' ] );

        // 2. Xóa lịch sử thay đổi nhiệm vụ (nếu có)
        delete_option( 'st_assign_history_' . $id );

        // 3. Xóa bản ghi nhiệm vụ chính
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $success = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        
        if ( ! $success ) {
            return new WP_REST_Response( [ 'error' => 'Could not delete assignment' ], 400 );
        }
        
        ST_Database::clear_kpi_cache();
        ST_Database::bump_assignment_version();
        $this->clear_check_mission_transients();
        return new WP_REST_Response( [ 'id' => $id, 'message' => 'Deleted successfully' ], 200 );
    }

    /**
     * Xóa toàn bộ transient cache kiểm tra nhiệm vụ (st_chkmiss_*)
     * Gọi sau khi tạo/sửa/xóa nhiệm vụ để đảm bảo nhân viên thấy trạng thái mới ngay.
     */
    private function clear_check_mission_transients(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '_transient_st_chkmiss_%' OR option_name LIKE '_transient_timeout_st_chkmiss_%'"
        );
        // Tăng version để JS sessionStorage cache bị invalidate cho tất cả user
        $current = (int) get_option( 'st_assign_version', 0 );
        update_option( 'st_assign_version', $current + 1, false );
    }

    // ── Logs Delete ──────────────────────────────────────────────────────────

    public function handle_delete_log( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param( 'id' );
        $table = $wpdb->prefix . ST_TABLE;
        $table_behavior = $wpdb->prefix . 'st_behavior_logs';

        // 1. Xóa toàn bộ behavior logs liên quan đến log chính này trước
        $wpdb->delete( $table_behavior, [ 'log_id' => $id ], [ '%d' ] );

        // 2. Xóa bản ghi traffic log chính
        $success = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        if ( ! $success ) {
            return new WP_REST_Response( [ 'error' => 'Could not delete log' ], 400 );
        }
        
        ST_Database::clear_kpi_cache();
        return new WP_REST_Response( [ 'id' => $id, 'message' => 'Deleted successfully' ], 200 );
    }

    // ── Argument schema ──────────────────────────────────────────────────────

    private function log_args(): array {
        return [
            'session_id'       => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
            'url'              => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => 'esc_url_raw' ],
            'page_path'        => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'duration_seconds' => [ 'type' => 'integer', 'required' => true,  'minimum' => 1 ],
            'timestamp_start'  => [ 'type' => 'string',  'required' => false ],
            'timestamp_end'    => [ 'type' => 'string',  'required' => false ],
            'user_name'        => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'emp_code'         => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'class_code'       => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'team'             => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'max_scroll'       => [ 'type' => 'integer', 'required' => false ],
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function get_client_ip(): string {
        // Prefer real IP forwarded by trusted proxy
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];
        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    return $ip;
                }
            }
        }
        // Fall back – may be private/LAN
        return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
    }

    private function is_bot( string $ua ): bool {
        if ( strlen( $ua ) < 20 ) {
            return true; // UA quá ngắn — scripted request
        }
        $patterns = [
            // Crawlers & scrapers
            'bot', 'spider', 'crawl', 'slurp', 'mediapartners',
            'facebookexternalhit', 'ahrefsbot', 'semrushbot',
            // CLI tools
            'curl', 'wget', 'python', 'go-http', 'java/', 'okhttp', 'libwww',
            // Headless / automation
            'headlesschrome', 'headless', 'phantomjs', 'selenium',
            'webdriver', 'puppeteer', 'playwright', 'cypress',
            'electron/', 'node-fetch', 'axios/', 'got/',
        ];
        $ua_lower = strtolower( $ua );
        foreach ( $patterns as $p ) {
            if ( str_contains( $ua_lower, $p ) ) {
                return true;
            }
        }
        return false;
    }

    private function is_valid_browser( string $ua ): bool {
        $ua_lower = strtolower( $ua );
        // Chỉ chấp nhận các trình duyệt chính
        $valid_patterns = [
            'chrome/',
            'safari/',
            'firefox/',
            'edg/', // Edge
            'coc_coc_browser', // Cốc Cốc
            'gsa/', // Google Search App
            'crios/', // Chrome iOS
            'fxios/', // Firefox iOS
            'edgios/', // Edge iOS
            'trident/', // IE11
        ];
        
        foreach ( $valid_patterns as $p ) {
            if ( str_contains( $ua_lower, $p ) ) {
                return true;
            }
        }
        return false;
    }

    public function handle_check_mission( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $this->auto_expire_assignments();
        $table_assign = $wpdb->prefix . 'st_assignments';

        $url        = esc_url_raw( $req->get_param( 'url' ) ?? '' );
        $emp_code   = trim( $req->get_param( 'emp_code' )   ?? '' );
        $class_code = trim( $req->get_param( 'class_code' ) ?? '' );
        $team       = trim( $req->get_param( 'team' )       ?? '' );
        $referrer   = sanitize_text_field( $req->get_param( 'referrer' ) ?? '' );
        $is_google  = strpos( strtolower( $referrer ), 'google.' ) !== false;

        if ( empty( $url ) || empty( $emp_code ) || empty( $class_code ) ) {
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'missing_params' ], 200 );
        }

        // Zero-trust: Overwrite team from database to prevent spoofing
        $table_personnel = $wpdb->prefix . 'st_personnel';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $official_team = $wpdb->get_var( $wpdb->prepare(
            "SELECT team FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s) AND LOWER(class_code) = LOWER(%s)",
            $emp_code,
            $class_code
        ) );
        if ( ! empty( $official_team ) ) {
            $team = $official_team;
        } else {
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'invalid_staff_credentials' ], 200 );
        }

        // ── Transient cache (key bao gồm assign_version để tự invalidate khi admin thay đổi) ──
        $assign_ver = (string) get_option( 'st_assign_version', '0' );
        $today      = ( new DateTime( 'now', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) )->format( 'Y-m-d' );
        $cache_key  = 'st_chkms3_' . substr( md5( $assign_ver . $url . $emp_code . $team . $today . ( $is_google ? '1' : '0' ) ), 0, 20 );

        $cached_result = get_transient( $cache_key );
        if ( false !== $cached_result ) {
            // Cached dạng "reason|success": ví dụ "matched|1", "specific_mismatch|0"
            [ $reason, $success ] = explode( '|', $cached_result, 2 );
            
            // Nếu cache đang là matched (có nhiệm vụ), phải kiểm tra nhanh xem có vừa làm xong không
            if ( '1' === $success && 'matched' === $reason ) {
                $visitor_path = wp_parse_url( $url, PHP_URL_PATH );
                $visitor_path = $visitor_path ? ( rtrim( $visitor_path, '/' ) . '/' ) : '/';
                if ( ST_Database::is_link_done_today( $emp_code, $visitor_path ) ) {
                    return new WP_REST_Response( [ 'success' => false, 'reason' => 'already_done_today' ], 200 );
                }
            }

            return new WP_REST_Response( [ 'success' => ( '1' === $success ), 'reason' => $reason ], 200 );
        }

        // ── Chuẩn hóa path của visitor (ví dụ: /san-pham/ao-thun/) ──
        $visitor_path = wp_parse_url( $url, PHP_URL_PATH );
        $visitor_path = $visitor_path ? ( rtrim( $visitor_path, '/' ) . '/' ) : '/';

        // ── Lấy thời gian hiện tại theo múi giờ VN ──
        $tz       = new DateTimeZone( 'Asia/Ho_Chi_Minh' );
        $dt_now   = new DateTime( 'now', $tz );
        $now_day  = $dt_now->format( 'Y-m-d' );
        $now_week = $dt_now->format( 'Y' ) . '-W' . $dt_now->format( 'W' );
        $now_mon  = $dt_now->format( 'Y-m' );
        $now_yr   = $dt_now->format( 'Y' );

        // ── Query: lấy tất cả nhiệm vụ active áp dụng cho nhân viên này ──
        // (bao gồm cả nhiệm vụ chung 'general' lẫn nhiệm vụ riêng 'specific' khớp với nhân viên)
        $sql = "SELECT id, assign_scope, product_selection, allowed_paths, product_ids, category_ids, phases_data, period_type, period_value
                FROM `{$table_assign}`
                WHERE status = 'active'
                AND (
                        (period_type = 'day'   AND period_value = %s)
                     OR (period_type = 'week'  AND period_value = %s)
                     OR (period_type = 'month' AND period_value = %s)
                     OR (period_type = 'year'  AND period_value = %s)
                )
                AND (
                    assign_scope = 'general'
                    OR (target_type = 'emp_code'   AND FIND_IN_SET(LOWER(%s), LOWER(target_value)) > 0)
                    OR (target_type = 'class_code' AND FIND_IN_SET(LOWER(%s), LOWER(target_value)) > 0)
                    OR (target_type = 'team'       AND FIND_IN_SET(LOWER(%s), LOWER(target_value)) > 0)
                )";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assignments = $wpdb->get_results(
            $wpdb->prepare( $sql, $now_day, $now_week, $now_mon, $now_yr, $emp_code, $class_code, $team ),
            ARRAY_A
        );

        // ── Không có nhiệm vụ nào → im lặng hoàn toàn ──
        if ( ! $assignments ) {
            set_transient( $cache_key, 'no_assignments|0', 5 * MINUTE_IN_SECONDS );
            return new WP_REST_Response( [ 'success' => false, 'reason' => 'no_assignments' ], 200 );
        }

        // ── Phân loại: có nhiệm vụ riêng (specific) hay chỉ chung (general)? ──
        $has_specific = false;
        foreach ( $assignments as $a ) {
            if ( 'specific' === ( $a['assign_scope'] ?? '' ) ) {
                $has_specific = true;
                break;
            }
        }

        // ── Kiểm tra trang hiện tại có khớp với nhiệm vụ nào không ──
        $matched = false;
        $post_type = sanitize_text_field( $req->get_param( 'post_type' ) ?? '' );

        foreach ( $assignments as $assign ) {
            $sel = $assign['product_selection'] ?? 'all';

            if ( 'phases' === $sel ) {
                $p_dec = json_decode( $assign['phases_data'] ?? '', true );
                $phases = is_array($p_dec) ? $p_dec : [];
                
                list( $a_start, $a_end ) = ST_Database::get_period_dates( $assign['period_type'], $assign['period_value'] );
                $now_str = $dt_now->format('Y-m-d H:i:s');
                
                foreach ( $phases as $p ) {
                    $p_start = !empty($p['start_date']) ? $p['start_date'] . ' 00:00:00' : $a_start . ' 00:00:00';
                    $p_end   = !empty($p['end_date'])   ? $p['end_date'] . ' 23:59:59'   : $a_end . ' 23:59:59';
                    
                    if ( $now_str >= $p_start && $now_str <= $p_end ) {
                        $p_sel = $p['product_selection'] ?? 'all';
                        if ( 'all' === $p_sel ) {
                            if ( 'product' === $post_type ) {
                                $matched = true;
                                break 2;
                            }
                        } else {
                            $p_allowed = !empty($p['allowed_paths']) ? array_filter( array_map( 'trim', explode( "\n", $p['allowed_paths'] ) ) ) : [];
                            if ( in_array( $visitor_path, $p_allowed, true ) || in_array( rtrim($visitor_path,'/').'/', $p_allowed, true ) ) {
                                $matched = true;
                                break 2;
                            }
                        }
                    }
                }
                continue;
            }

            // Nếu nhiệm vụ áp dụng toàn bộ sản phẩm → khớp ngay, nhưng BẮT BUỘC phải là trang sản phẩm
            if ( 'all' === $sel ) {
                if ( 'product' === $post_type ) {
                    $matched = true;
                    break;
                }
                continue;
            }

            // Self-healing: tự động resolve allowed_paths nếu cũ chưa có
            if ( null === $assign['allowed_paths'] ) {
                $resolved = $this->resolve_allowed_paths( $sel, $assign['product_ids'] ?? '', $assign['category_ids'] ?? '' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update( $table_assign, [ 'allowed_paths' => $resolved ], [ 'id' => $assign['id'] ], [ '%s' ], [ '%d' ] );
                $assign['allowed_paths'] = $resolved;
            }

            if ( ! empty( $assign['allowed_paths'] ) ) {
                $paths = [];
                if ( 'random_distribution' === $sel ) {
                    $now_str = $dt_now->format('Y-m-d');
                    $paths = ST_Database::get_daily_random_paths( $assign, $now_str, $emp_code );
                } else {
                    $paths = array_filter( array_map( 'trim', explode( "\n", $assign['allowed_paths'] ) ) );
                }
                if ( in_array( $visitor_path, $paths, true ) || in_array( rtrim($visitor_path,'/').'/', $paths, true ) ) {
                    $matched = true;
                    break;
                }
            }
        }

        // ── Xác định reason theo ma trận logic ──
        if ( $matched ) {
            if ( ST_Database::is_link_done_today( $emp_code, $visitor_path ) ) {
                $reason  = 'already_done_today';
                $success = false;
            } else {
                // Trang khớp với nhiệm vụ → bắt đầu tracking
                $reason  = 'matched';
                $success = true;
            }
        } elseif ( $has_specific ) {
            // Có nhiệm vụ riêng nhưng trang này không thuộc → nhắc nhở nhân viên (nếu là trang sản phẩm VÀ đến từ Google)
            if ( 'product' !== $post_type || ! $is_google ) {
                $reason  = 'general_mismatch';
                $success = false;
            } else {
                $reason  = 'specific_mismatch';
                $success = false;
            }
        } else {
            // Chỉ có nhiệm vụ chung, trang ngoài phạm vi → im lặng (không làm phiền)
            $reason  = 'general_mismatch';
            $success = false;
        }

        // Cache kết quả (key đã chứa assign_version nên tự invalidate khi admin đổi nhiệm vụ)
        set_transient( $cache_key, $reason . '|' . ( $success ? '1' : '0' ), 5 * MINUTE_IN_SECONDS );

        return new WP_REST_Response( [ 'success' => $success, 'reason' => $reason ], 200 );
    }

    public function handle_search_products( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $q       = trim( $req->get_param( 'q' ) ?? '' );
        $ids_raw = trim( $req->get_param( 'ids' ) ?? '' );
        $rows    = [];

        // 1. Trường hợp fetch sản phẩm cụ thể theo danh sách ID (phục vụ Edit click)
        if ( ! empty( $ids_raw ) ) {
            $ids = array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) );
            if ( ! empty( $ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $sql = "SELECT ID, post_title 
                        FROM {$wpdb->posts} 
                        WHERE post_type = 'product' AND ID IN ({$placeholders})";
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$ids ), ARRAY_A );
            }
        } 
        // 2. Trường hợp load demo/recent ban đầu khi chưa search
        elseif ( empty( $q ) ) {
            $sql = "SELECT ID, post_title 
                    FROM {$wpdb->posts} 
                    WHERE post_type = 'product' AND post_status = 'publish' 
                    ORDER BY ID DESC 
                    LIMIT 10";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results( $sql, ARRAY_A );
        } 
        // 3. Trường hợp tìm kiếm theo ID hoặc Tên (tách từ khóa nâng cao, tối ưu 1tr sản phẩm)
        else {
            if ( is_numeric( $q ) ) {
                $like = '%' . $wpdb->esc_like( $q ) . '%';
                $sql = "SELECT ID, post_title 
                        FROM {$wpdb->posts} 
                        WHERE post_type = 'product' AND post_status = 'publish' 
                          AND (ID = %d OR post_title LIKE %s) 
                        LIMIT 15";
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $rows = $wpdb->get_results( $wpdb->prepare( $sql, intval( $q ), $like ), ARRAY_A );
            } else {
                $limit = 15;
                $results_map = [];

                // Bước 1: Tìm cụm từ gốc (Khớp chính xác liền mạch)
                $like_exact = '%' . $wpdb->esc_like( $q ) . '%';
                $sql_exact = "SELECT ID, post_title FROM {$wpdb->posts} 
                              WHERE post_type = 'product' AND post_status = 'publish' 
                                AND post_title LIKE %s LIMIT %d";
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $res_exact = $wpdb->get_results( $wpdb->prepare( $sql_exact, $like_exact, $limit ), ARRAY_A );
                if ( ! empty( $res_exact ) ) {
                    foreach ( $res_exact as $row ) {
                        $results_map[ $row['ID'] ] = $row['post_title'];
                    }
                }

                // Bước 2: Tìm cụm từ thay dấu cách bằng gạch ngang (VD: "bw20 20" -> "bw20-20")
                if ( count( $results_map ) < $limit && strpos( $q, ' ' ) !== false ) {
                    $q_hyphen = str_replace( ' ', '-', $q );
                    $like_hyphen = '%' . $wpdb->esc_like( $q_hyphen ) . '%';
                    $sql_hyphen = "SELECT ID, post_title FROM {$wpdb->posts} 
                                   WHERE post_type = 'product' AND post_status = 'publish' 
                                     AND post_title LIKE %s LIMIT %d";
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $res_hyphen = $wpdb->get_results( $wpdb->prepare( $sql_hyphen, $like_hyphen, $limit ), ARRAY_A );
                    if ( ! empty( $res_hyphen ) ) {
                        foreach ( $res_hyphen as $row ) {
                            if ( count( $results_map ) >= $limit ) break;
                            $results_map[ $row['ID'] ] = $row['post_title'];
                        }
                    }
                }

                // Bước 3: Tách từ khóa và tìm kiếm dạng AND (VD: chứa cả "bw20" và "20")
                if ( count( $results_map ) < $limit ) {
                    $words = array_filter( array_map( 'trim', explode( ' ', $q ) ) );
                    if ( count( $words ) > 1 ) {
                        $where_clauses = [];
                        $prep_args = [];
                        foreach ( $words as $word ) {
                            $where_clauses[] = "post_title LIKE %s";
                            $prep_args[] = '%' . $wpdb->esc_like( $word ) . '%';
                        }
                        $where_sql = implode( ' AND ', $where_clauses );
                        $prep_args[] = $limit;

                        $sql_and = "SELECT ID, post_title FROM {$wpdb->posts} 
                                    WHERE post_type = 'product' AND post_status = 'publish' 
                                      AND ({$where_sql}) LIMIT %d";
                        
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $res_and = $wpdb->get_results( $wpdb->prepare( $sql_and, ...$prep_args ), ARRAY_A );
                        if ( ! empty( $res_and ) ) {
                            foreach ( $res_and as $row ) {
                                if ( count( $results_map ) >= $limit ) break;
                                $results_map[ $row['ID'] ] = $row['post_title'];
                            }
                        }
                    }
                }

                // Chuyển kết quả từ Map sang rows để xử lý tiếp
                foreach ( $results_map as $id => $title ) {
                    $rows[] = [ 'ID' => $id, 'post_title' => $title ];
                }
            }
        }

        $results = [];
        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $results[] = [
                    'value' => (string) $row['ID'],
                    'text'  => $row['post_title'] . ' (#' . $row['ID'] . ')'
                ];
            }
        }

        return new WP_REST_Response( $results, 200 );
    }

    public function handle_get_payouts( WP_REST_Request $req ): WP_REST_Response {
        $args = [
            'page'         => absint( $req->get_param('page') ?? 1 ),
            'per_page'     => absint( $req->get_param('per_page') ?? 20 ),
            'status'       => sanitize_text_field( $req->get_param('status') ?? '' ),
            'emp_code'     => sanitize_text_field( $req->get_param('emp_code') ?? '' ),
            'period_value' => sanitize_text_field( $req->get_param('period_value') ?? '' ),
        ];

        $data = ST_Database::get_payouts( $args );
        return new WP_REST_Response( $data, 200 );
    }

    public function handle_pay_payout( WP_REST_Request $req ): WP_REST_Response {
        $id = absint( $req->get_param('id') );
        if ( empty( $id ) ) {
            return new WP_REST_Response( [ 'error' => 'ID không hợp lệ.' ], 400 );
        }

        global $wpdb;
        $table_payouts = $wpdb->prefix . 'st_payouts';
        $table_assign  = $wpdb->prefix . 'st_assignments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $payout = $wpdb->get_row( $wpdb->prepare( "SELECT assignment_id, status FROM `{$table_payouts}` WHERE id = %d", $id ), ARRAY_A );
        if ( ! $payout ) {
            return new WP_REST_Response( [ 'error' => 'Khoản hoa hồng không tồn tại.' ], 404 );
        }

        if ( 'paid' === $payout['status'] ) {
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Khoản hoa hồng này đã được thanh toán thành công.' ], 200 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assign_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table_assign}` WHERE id = %d", $payout['assignment_id'] ) );
        if ( 'ended' !== $assign_status ) {
            return new WP_REST_Response( [ 'error' => 'Nhiệm vụ/Dự án này chưa kết thúc (phải ở trạng thái "ended" mới chuyển tiền được).' ], 400 );
        }

        $success = ST_Database::mark_payout_paid( $id );
        if ( ! $success ) {
            return new WP_REST_Response( [ 'error' => 'Không thể cập nhật trạng thái thanh toán.' ], 400 );
        }

        return new WP_REST_Response( [ 'success' => true, 'message' => 'Đã đánh dấu thanh toán thành công.' ], 200 );
    }

    public function handle_generate_payout_qr( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param( 'id' );

        $table_payouts = $wpdb->prefix . 'st_payouts';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $payout = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table_payouts}` WHERE id = %d",
            $id
        ), ARRAY_A );

        if ( ! $payout ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Không tìm thấy Payout.' ], 404 );
        }

        $now = current_time( 'mysql' );
        $needs_new_code = false;

        if ( empty( $payout['qr_code'] ) || empty( $payout['qr_expires_at'] ) ) {
            $needs_new_code = true;
        } elseif ( $now > $payout['qr_expires_at'] ) {
            $needs_new_code = true;
        }

        if ( $needs_new_code ) {
            // Sinh mã mới ngẫu nhiên: HHTR + 6 ký tự
            $random_str = strtoupper( wp_generate_password( 6, false, false ) );
            $qr_code = 'HHTR' . $random_str;
            $qr_expires_at = gmdate( 'Y-m-d H:i:s', time() + 15 * 60 + get_option( 'gmt_offset' ) * 3600 );

            // Cập nhật DB
            $wpdb->update(
                $table_payouts,
                [ 'qr_code' => $qr_code, 'qr_expires_at' => $qr_expires_at ],
                [ 'id' => $id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        } else {
            $qr_code = $payout['qr_code'];
            $qr_expires_at = $payout['qr_expires_at'];
        }

        // Tính số giây còn lại
        $expires_timestamp = strtotime( get_gmt_from_date( $qr_expires_at ) );
        $current_timestamp = time();
        $expires_in_seconds = max( 0, $expires_timestamp - $current_timestamp );

        return new WP_REST_Response( [
            'success'       => true,
            'qr_code'       => $qr_code,
            'qr_expires_at' => $qr_expires_at,
            'expires_in'    => $expires_in_seconds
        ], 200 );
    }

    public function handle_get_payout_status( WP_REST_Request $req ): WP_REST_Response {
        $id = absint( $req->get_param('id') );
        if ( empty( $id ) ) {
            return new WP_REST_Response( [ 'error' => 'ID không hợp lệ.' ], 400 );
        }

        global $wpdb;
        $table_payouts = $wpdb->prefix . 'st_payouts';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $payout = $wpdb->get_row( $wpdb->prepare( "SELECT status, amount, sepay_transaction_id FROM `{$table_payouts}` WHERE id = %d", $id ), ARRAY_A );
        if ( ! $payout ) {
            return new WP_REST_Response( [ 'error' => 'Khoản hoa hồng không tồn tại.' ], 404 );
        }

        return new WP_REST_Response( [ 
            'success' => true, 
            'status'  => $payout['status'],
            'amount'  => $payout['amount'],
            'sepay_transaction_id' => $payout['sepay_transaction_id']
        ], 200 );
    }

    public function handle_recalculate_payouts( WP_REST_Request $req ): WP_REST_Response {
        $date = sanitize_text_field( $req->get_param('date') ?? '' );
        if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return new WP_REST_Response( [ 'error' => 'Ngày không hợp lệ (định dạng YYYY-MM-DD).' ], 400 );
        }

        $count = ST_Database::create_payout_records( $date );
        return new WP_REST_Response( [
            'success' => true,
            'message' => sprintf( 'Đã tính toán xong. Có %d bản ghi được ghi nhận.', $count ),
            'count'   => $count
        ], 200 );
    }

    public function handle_get_settings( WP_REST_Request $req ): WP_REST_Response {
        // Lấy và trả về thêm các cờ st_show_msg_... để quản lý trạng thái ẩn/hiện thông báo
        return new WP_REST_Response( [
            'onsite_threshold' => (int) get_option( 'st_onsite_threshold', 30 ),
            'scroll_threshold' => (int) get_option( 'st_scroll_threshold', 60 ),
            'rate_limit'        => (int) get_option( 'st_rate_limit', 60 ),
            'toast_duration'   => (int) get_option( 'st_toast_duration', 5 ),
            'payout_per_kpi'   => (float) get_option( 'st_payout_per_kpi', 0 ),
            'gear_password'    => get_option( 'st_gear_password', '123456' ),
            'sepay_webhook_token' => get_option( 'st_sepay_webhook_token', '' ),
            'sepay_auto_reconcile' => (int) get_option( 'st_sepay_auto_reconcile', 0 ),
            'allowed_admins'       => get_option( 'st_allowed_admins', '' ),
            'require_confirmation' => (int) get_option( 'st_require_confirmation', 1 ),
            'msg_require_google' => get_option( 'st_msg_require_google', 'Bạn phải truy cập bài viết thông qua tìm kiếm Google!' ),
            'show_msg_require_google' => (int) get_option( 'st_show_msg_require_google', 1 ),
            'msg_scroll_not_enough' => get_option( 'st_msg_scroll_not_enough', 'Bạn chưa cuộn đủ {scroll}% chiều dài bài viết!' ),
            'show_msg_scroll_not_enough' => (int) get_option( 'st_show_msg_scroll_not_enough', 1 ),
            'msg_invalid_staff' => get_option( 'st_msg_invalid_staff', 'Thông tin nhân sự không tồn tại hoặc không khớp!' ),
            'show_msg_invalid_staff' => (int) get_option( 'st_show_msg_invalid_staff', 1 ),
            'msg_already_completed' => get_option( 'st_msg_already_completed', 'Bạn đã hoàn thành nhiệm vụ cho đường dẫn này trong ngày hôm nay rồi!' ),
            'show_msg_already_completed' => (int) get_option( 'st_show_msg_already_completed', 1 ),
            'msg_not_in_mission' => get_option( 'st_msg_not_in_mission', 'Sản phẩm này không nằm trong nhiệm vụ phân công của bạn!' ),
            'show_msg_not_in_mission' => (int) get_option( 'st_show_msg_not_in_mission', 1 ),
            'msg_rate_limit' => get_option( 'st_msg_rate_limit', 'Bạn đang gửi quá nhanh (Rate Limit)! Vui lòng thử lại sau.' ),
            'show_msg_rate_limit' => (int) get_option( 'st_show_msg_rate_limit', 1 ),
            'msg_success' => get_option( 'st_msg_success', '🎉 Ghi nhận KPI traffic thành công cho: {name}' ),
            'show_msg_success' => (int) get_option( 'st_show_msg_success', 1 ),
            'msg_not_enough_conditions' => get_option( 'st_msg_not_enough_conditions', 'Chưa đủ điều kiện: Cần onsite >= {onsite}s và cuộn >= {scroll}%' ),
            'show_msg_not_enough_conditions' => (int) get_option( 'st_show_msg_not_enough_conditions', 1 ),
            'msg_connection_failed' => get_option( 'st_msg_connection_failed', 'Kết nối thất bại, không thể ghi nhận KPI traffic!' ),
            'show_msg_connection_failed' => (int) get_option( 'st_show_msg_connection_failed', 1 ),
            'msg_scroll_down_prompt' => get_option( 'st_msg_scroll_down_prompt', 'Hãy cuộn xuống<br/><span class="st-scroll-highlight">để hoàn thành</span>' ),
            'show_msg_scroll_down_prompt' => (int) get_option( 'st_show_msg_scroll_down_prompt', 1 ),
            'msg_stay_longer' => get_option( 'st_msg_stay_longer', 'Bạn đã cuộn đủ! Hãy ở lại thêm {remaining} giây nữa để hoàn thành.' ),
            'show_msg_stay_longer' => (int) get_option( 'st_show_msg_stay_longer', 1 ),
            'msg_recording_traffic' => get_option( 'st_msg_recording_traffic', 'Đang ghi nhận traffic...' ),
            'show_msg_recording_traffic' => (int) get_option( 'st_show_msg_recording_traffic', 1 ),
        ], 200 );
    }

    public function handle_save_settings( WP_REST_Request $req ): WP_REST_Response {
        $data = $req->get_json_params() ?: $req->get_body_params();
        
        $onsite = isset( $data['onsite_threshold'] ) ? absint( $data['onsite_threshold'] ) : 30;
        $scroll = isset( $data['scroll_threshold'] ) ? absint( $data['scroll_threshold'] ) : 60;
        $rate   = isset( $data['rate_limit'] ) ? absint( $data['rate_limit'] ) : 60;
        $toast  = isset( $data['toast_duration'] ) ? absint( $data['toast_duration'] ) : 5;
        $payout = isset( $data['payout_per_kpi'] ) ? (float) $data['payout_per_kpi'] : 0.0;
        $gear   = isset( $data['gear_password'] ) ? sanitize_text_field( trim( $data['gear_password'] ) ) : '123456';
        $sepay_token = isset( $data['sepay_webhook_token'] ) ? sanitize_text_field( trim( $data['sepay_webhook_token'] ) ) : '';
        $sepay_reconcile = isset( $data['sepay_auto_reconcile'] ) ? (int) $data['sepay_auto_reconcile'] : 0;
        $allowed_admins = isset( $data['allowed_admins'] ) ? sanitize_text_field( trim( $data['allowed_admins'] ) ) : '';
        $require_confirm = isset( $data['require_confirmation'] ) ? (int) $data['require_confirmation'] : 1;

        if ( $onsite < 1 || $scroll < 1 || $scroll > 100 || $rate < 0 || $toast < 1 || $toast > 60 || $payout < 0 || empty( $gear ) ) {
            return new WP_REST_Response( [ 'error' => 'Thông tin cấu hình không hợp lệ.' ], 400 );
        }

        update_option( 'st_onsite_threshold', $onsite );
        update_option( 'st_scroll_threshold', $scroll );
        update_option( 'st_rate_limit', $rate );
        update_option( 'st_toast_duration', $toast );
        update_option( 'st_payout_per_kpi', $payout );
        update_option( 'st_gear_password', $gear );
        update_option( 'st_sepay_webhook_token', $sepay_token );
        update_option( 'st_sepay_auto_reconcile', $sepay_reconcile );
        update_option( 'st_allowed_admins', $allowed_admins );
        update_option( 'st_require_confirmation', $require_confirm );

        // Messages
        if ( isset( $data['msg_require_google'] ) ) update_option( 'st_msg_require_google', sanitize_text_field( $data['msg_require_google'] ) );
        if ( isset( $data['show_msg_require_google'] ) ) update_option( 'st_show_msg_require_google', (int) $data['show_msg_require_google'] );
        if ( isset( $data['msg_scroll_not_enough'] ) ) update_option( 'st_msg_scroll_not_enough', sanitize_text_field( $data['msg_scroll_not_enough'] ) );
        if ( isset( $data['show_msg_scroll_not_enough'] ) ) update_option( 'st_show_msg_scroll_not_enough', (int) $data['show_msg_scroll_not_enough'] );
        if ( isset( $data['msg_invalid_staff'] ) ) update_option( 'st_msg_invalid_staff', sanitize_text_field( $data['msg_invalid_staff'] ) );
        if ( isset( $data['show_msg_invalid_staff'] ) ) update_option( 'st_show_msg_invalid_staff', (int) $data['show_msg_invalid_staff'] );
        if ( isset( $data['msg_already_completed'] ) ) update_option( 'st_msg_already_completed', sanitize_text_field( $data['msg_already_completed'] ) );
        if ( isset( $data['show_msg_already_completed'] ) ) update_option( 'st_show_msg_already_completed', (int) $data['show_msg_already_completed'] );
        if ( isset( $data['msg_not_in_mission'] ) ) update_option( 'st_msg_not_in_mission', sanitize_text_field( $data['msg_not_in_mission'] ) );
        if ( isset( $data['show_msg_not_in_mission'] ) ) update_option( 'st_show_msg_not_in_mission', (int) $data['show_msg_not_in_mission'] );
        if ( isset( $data['msg_rate_limit'] ) ) update_option( 'st_msg_rate_limit', sanitize_text_field( $data['msg_rate_limit'] ) );
        if ( isset( $data['show_msg_rate_limit'] ) ) update_option( 'st_show_msg_rate_limit', (int) $data['show_msg_rate_limit'] );
        if ( isset( $data['msg_success'] ) ) update_option( 'st_msg_success', sanitize_text_field( $data['msg_success'] ) );
        if ( isset( $data['show_msg_success'] ) ) update_option( 'st_show_msg_success', (int) $data['show_msg_success'] );
        if ( isset( $data['msg_not_enough_conditions'] ) ) update_option( 'st_msg_not_enough_conditions', sanitize_text_field( $data['msg_not_enough_conditions'] ) );
        if ( isset( $data['show_msg_not_enough_conditions'] ) ) update_option( 'st_show_msg_not_enough_conditions', (int) $data['show_msg_not_enough_conditions'] );
        if ( isset( $data['msg_connection_failed'] ) ) update_option( 'st_msg_connection_failed', sanitize_text_field( $data['msg_connection_failed'] ) );
        if ( isset( $data['show_msg_connection_failed'] ) ) update_option( 'st_show_msg_connection_failed', (int) $data['show_msg_connection_failed'] );
        if ( isset( $data['msg_scroll_down_prompt'] ) ) update_option( 'st_msg_scroll_down_prompt', wp_kses_post( $data['msg_scroll_down_prompt'] ) );
        if ( isset( $data['show_msg_scroll_down_prompt'] ) ) update_option( 'st_show_msg_scroll_down_prompt', (int) $data['show_msg_scroll_down_prompt'] );
        if ( isset( $data['msg_stay_longer'] ) ) update_option( 'st_msg_stay_longer', sanitize_text_field( $data['msg_stay_longer'] ) );
        if ( isset( $data['show_msg_stay_longer'] ) ) update_option( 'st_show_msg_stay_longer', (int) $data['show_msg_stay_longer'] );
        if ( isset( $data['msg_recording_traffic'] ) ) update_option( 'st_msg_recording_traffic', sanitize_text_field( $data['msg_recording_traffic'] ) );
        if ( isset( $data['show_msg_recording_traffic'] ) ) update_option( 'st_show_msg_recording_traffic', (int) $data['show_msg_recording_traffic'] );

        ST_Database::clear_kpi_cache();

        return new WP_REST_Response( [ 'success' => true, 'message' => 'Lưu cài đặt thành công.' ], 200 );
    }

    public function handle_verify_gear_password( WP_REST_Request $req ): WP_REST_Response {
        $params   = $req->get_json_params() ?: $req->get_body_params();
        $password = sanitize_text_field( trim( $params['password'] ?? '' ) );
        
        $correct_password = trim( get_option( 'st_gear_password', '' ) );
        if ( empty( $correct_password ) ) {
            $correct_password = '123456'; // Default password
        }

        if ( $password === $correct_password ) {
            return new WP_REST_Response( [ 'success' => true ], 200 );
        }

        return new WP_REST_Response( [ 'success' => false, 'error' => 'Mật khẩu bảo mật không chính xác!' ], 200 );
    }

    public function handle_get_assignment_payout_details( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id       = (int) $req->get_param( 'id' );
        $page     = max( 1, (int) ( $req->get_param('page') ?? 1 ) );
        $per_page = max( 1, min( 200, (int) ( $req->get_param('per_page') ?? 25 ) ) );
        $search   = sanitize_text_field( $req->get_param('search') ?? '' );
        $status   = sanitize_text_field( $req->get_param('status') ?? '' );
        $offset   = ( $page - 1 ) * $per_page;

        $table_assign  = $wpdb->prefix . 'st_assignments';
        $table_payouts = $wpdb->prefix . 'st_payouts';
        $table_logs    = $wpdb->prefix . ST_TABLE;

        // Check if assignment exists — use SELECT specific columns for speed
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assign = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table_assign}` WHERE id = %d", $id
        ), ARRAY_A );
        if ( ! $assign ) {
            return new WP_REST_Response( [ 'error' => 'Nhiệm vụ không tồn tại.' ], 404 );
        }

        // Build dynamic WHERE for payouts
        $where_parts = [ 'assignment_id = %d' ];
        $where_args  = [ $id ];

        if ( ! empty( $search ) ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where_parts[] = '( user_name LIKE %s OR emp_code LIKE %s )';
            $where_args[]  = $like;
            $where_args[]  = $like;
        }
        if ( in_array( $status, [ 'paid', 'unpaid', 'refunded' ], true ) ) {
            $where_parts[] = 'status = %s';
            $where_args[]  = $status;
        }

        $where_sql = implode( ' AND ', $where_parts );

        // COUNT total — cheap, no JOIN
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table_payouts}` WHERE {$where_sql}",
            ...$where_args
        ) );

        // Paginated payouts
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $payouts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table_payouts}` WHERE {$where_sql} ORDER BY id ASC LIMIT %d OFFSET %d",
            ...array_merge( $where_args, [ $per_page, $offset ] )
        ), ARRAY_A );

        if ( ! empty( $payouts ) ) {
            list( $start_date, $end_date ) = ST_Database::get_period_dates( $assign['period_type'], $assign['period_value'] );

            // Batch-load staff meta for all emp_codes in one query (reduce N+1)
            $emp_codes     = array_column( $payouts, 'emp_code' );
            $meta_ph       = implode( ',', array_fill( 0, count( $emp_codes ), '%s' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $meta_rows     = $wpdb->get_results( $wpdb->prepare(
                "SELECT emp_code, class_code, team FROM `{$table_logs}` WHERE emp_code IN ({$meta_ph}) GROUP BY emp_code",
                ...$emp_codes
            ), ARRAY_A );
            $meta_map = [];
            foreach ( $meta_rows as $m ) {
                $meta_map[ $m['emp_code'] ] = $m;
            }

            foreach ( $payouts as &$p ) {
                $stats = ST_Database::calc_staff_assignment_stats( $p['emp_code'], $assign );
                $p['avg_onsite'] = $stats['avg_dur'];

                $meta = $meta_map[ $p['emp_code'] ] ?? [];
                $p['class_code'] = $meta['class_code'] ?? '—';
                $p['team']       = $meta['team']       ?? '—';

                // Lấy thông tin tài khoản ngân hàng nhận tiền
                $p['bank_account'] = null;
                $table_bank = $wpdb->prefix . 'staff_tracking_bank_accounts';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $bank = $wpdb->get_row( $wpdb->prepare(
                    "SELECT bank_code, bank_name, account_number, account_name 
                     FROM `{$table_bank}` 
                     WHERE emp_code = %s AND is_active = 1 
                     LIMIT 1",
                    $p['emp_code']
                ), ARRAY_A );
                if ( $bank ) {
                    $p['bank_account'] = $bank;
                }
            }
            unset( $p );
        }

        return new WP_REST_Response( [
            'assignment' => $assign,
            'payouts'    => $payouts ?: [],
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
        ], 200 );
    }

    public function handle_get_assignment_details( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id       = (int) $req->get_param( 'id' );
        $page     = max( 1, (int) ( $req->get_param('page') ?? 1 ) );
        $per_page = max( 1, min( 200, (int) ( $req->get_param('per_page') ?? 25 ) ) );
        $search   = sanitize_text_field( $req->get_param('search') ?? '' );
        $status   = sanitize_text_field( $req->get_param('status') ?? '' );
        $offset   = ( $page - 1 ) * $per_page;

        $table_assign  = $wpdb->prefix . 'st_assignments';
        $table_logs    = $wpdb->prefix . ST_TABLE;

        // Check if assignment exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assign = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table_assign}` WHERE id = %d", $id
        ), ARRAY_A );
        if ( ! $assign ) {
            return new WP_REST_Response( [ 'error' => 'Nhiệm vụ không tồn tại.' ], 404 );
        }

        // Get all unique personnel from logs
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $all_staff = $wpdb->get_results(
            "SELECT emp_code, user_name, class_code, team 
             FROM `{$table_logs}` 
             WHERE emp_code != '' 
             GROUP BY emp_code 
             ORDER BY emp_code ASC",
            ARRAY_A
        );

        $matched_staff = [];
        $scope  = $assign['assign_scope'] ?? 'specific';
        $t_type = $assign['target_type'] ?? '';
        $t_val  = $assign['target_value'] ?? '';
        
        $t_vals = array_filter( array_map( 'trim', explode( ',', strtolower( $t_val ) ) ) );

        foreach ( $all_staff as $staff ) {
            $matched = false;
            if ( 'general' === $scope ) {
                $matched = true;
            } else {
                if ( 'emp_code' === $t_type && in_array( strtolower( $staff['emp_code'] ), $t_vals, true ) ) {
                    $matched = true;
                } elseif ( 'class_code' === $t_type && in_array( strtolower( $staff['class_code'] ), $t_vals, true ) ) {
                    $matched = true;
                } elseif ( 'team' === $t_type && in_array( strtolower( $staff['team'] ), $t_vals, true ) ) {
                    $matched = true;
                }
            }

            if ( $matched ) {
                $matched_staff[] = $staff;
            }
        }

        // Loop through each matched staff and get live traffic count
        foreach ( $matched_staff as &$staff ) {
            $stats = ST_Database::calc_staff_assignment_stats( $staff['emp_code'], $assign );
            $achieved = $stats['achieved'];
            $avg_dur  = $stats['avg_dur'];

            $staff['kpi_target']   = (int) $assign['kpi_target'];
            $staff['kpi_achieved'] = $achieved;
            $staff['avg_onsite']   = $avg_dur;
            $staff['status']       = ( $achieved >= $assign['kpi_target'] ) ? 'completed' : 'pending';

            // Lấy thông tin tài khoản ngân hàng nhận tiền
            $staff['bank_account'] = null;
            $table_bank = $wpdb->prefix . 'staff_tracking_bank_accounts';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $bank = $wpdb->get_row( $wpdb->prepare(
                "SELECT bank_code, bank_name, account_number, account_name 
                 FROM `{$table_bank}` 
                 WHERE emp_code = %s AND is_active = 1 
                 LIMIT 1",
                $staff['emp_code']
            ), ARRAY_A );
            if ( $bank ) {
                $staff['bank_account'] = $bank;
            }
        }
        unset( $staff );

        // Filter by search query
        if ( ! empty( $search ) ) {
            $matched_staff = array_filter( $matched_staff, function( $s ) use ( $search ) {
                return ( stripos( $s['user_name'], $search ) !== false ) || ( stripos( $s['emp_code'], $search ) !== false );
            } );
        }

        // Filter by KPI status
        if ( ! empty( $status ) ) {
            $matched_staff = array_filter( $matched_staff, function( $s ) use ( $status ) {
                return $s['status'] === $status;
            } );
        }

        $total = count( $matched_staff );

        // Slice for pagination
        $paginated_staff = array_slice( $matched_staff, $offset, $per_page );

        return new WP_REST_Response( [
            'assignment' => $assign,
            'payouts'    => $paginated_staff,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
        ], 200 );
    }

    public function handle_get_assignment_staff_details( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id       = (int) $req->get_param( 'id' );
        $emp_code = sanitize_text_field( $req->get_param( 'emp_code' ) );

        $table_assign  = $wpdb->prefix . 'st_assignments';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table_assign}` WHERE id = %d", $id ), ARRAY_A );
        if ( ! $assign ) {
            return new WP_REST_Response( [ 'error' => 'Nhiệm vụ không tồn tại.' ], 404 );
        }

        $daily_stats = ST_Database::calc_staff_assignment_daily_stats( $emp_code, $assign );
        
        $missed_days = 0;
        $completed_days = 0;
        
        foreach ( $daily_stats as $stat ) {
            if ( ! empty( $stat['is_sunday'] ) ) {
                continue; // Skip counting Sunday
            }
            if ( $stat['is_past'] ) {
                if ( $stat['target'] > 0 ) {
                    if ( $stat['achieved'] >= $stat['target'] ) {
                        $completed_days++;
                    } else {
                        $missed_days++;
                    }
                }
            } else if ( $stat['is_today'] ) {
                if ( $stat['target'] > 0 && $stat['achieved'] >= $stat['target'] ) {
                    $completed_days++;
                }
            }
        }

        return new WP_REST_Response( [
            'daily_stats'    => $daily_stats,
            'missed_days'    => $missed_days,
            'completed_days' => $completed_days,
            'total_days'     => count($daily_stats),
        ], 200 );
    }

    public function handle_export_assignment_data( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param( 'id' );

        $table_assign  = $wpdb->prefix . 'st_assignments';
        $table_logs    = $wpdb->prefix . ST_TABLE;
        $table_personnel = $wpdb->prefix . 'st_personnel';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assign = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table_assign}` WHERE id = %d", $id
        ), ARRAY_A );
        if ( ! $assign ) {
            return new WP_REST_Response( [ 'error' => 'Nhiệm vụ không tồn tại.' ], 404 );
        }

        // Get all unique personnel from logs
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $all_staff = $wpdb->get_results(
            "SELECT emp_code, user_name, class_code, team 
             FROM `{$table_logs}` 
             WHERE emp_code != '' 
             GROUP BY emp_code 
             ORDER BY emp_code ASC",
            ARRAY_A
        );

        $matched_staff = [];
        $scope  = $assign['assign_scope'] ?? 'specific';
        $t_type = $assign['target_type'] ?? '';
        $t_val  = $assign['target_value'] ?? '';
        
        $t_vals = array_filter( array_map( 'trim', explode( ',', strtolower( $t_val ) ) ) );

        foreach ( $all_staff as $staff ) {
            $matched = false;
            if ( 'general' === $scope ) {
                $matched = true;
            } else {
                if ( 'emp_code' === $t_type && in_array( strtolower( $staff['emp_code'] ), $t_vals, true ) ) {
                    $matched = true;
                } elseif ( 'class_code' === $t_type && in_array( strtolower( $staff['class_code'] ), $t_vals, true ) ) {
                    $matched = true;
                } elseif ( 'team' === $t_type && in_array( strtolower( $staff['team'] ), $t_vals, true ) ) {
                    $matched = true;
                }
            }
            if ( $matched ) {
                $matched_staff[] = $staff;
            }
        }

        foreach ( $matched_staff as &$staff ) {
            $stats = ST_Database::calc_staff_assignment_stats( $staff['emp_code'], $assign );
            $kpi_target   = (int) $assign['kpi_target'];
            $kpi_achieved = (int) $stats['achieved'];
            
            $staff['kpi_target']   = $kpi_target;
            $staff['kpi_achieved'] = $kpi_achieved;
            
            $staff['avg_onsite']   = $stats['avg_dur'];

            // Get Bank info
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $bank = $wpdb->get_row( $wpdb->prepare(
                "SELECT bank_code, account_number, account_name FROM `{$table_personnel}` WHERE emp_code = %s",
                $staff['emp_code']
            ), ARRAY_A );
            $staff['bank_account'] = $bank;

            // Get Payout info
            $table_payouts = $wpdb->prefix . 'st_payouts';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $payout = $wpdb->get_row( $wpdb->prepare(
                "SELECT amount, status FROM `{$table_payouts}` WHERE assignment_id = %d AND emp_code = %s",
                $id, $staff['emp_code']
            ), ARRAY_A );
            $staff['payout_amount'] = $payout ? (int)$payout['amount'] : 0;
            $staff['payout_status'] = $payout ? $payout['status'] : 'unpaid';

            // Get daily stats
            $daily_stats = ST_Database::calc_staff_assignment_daily_stats( $staff['emp_code'], $assign );
            $staff['daily_stats'] = $daily_stats;
            
            $missed_days = 0;
            $completed_days = 0;
            foreach ( $daily_stats as $dstat ) {
                if ( $dstat['is_past'] ) {
                    if ( $dstat['target'] > 0 ) {
                        if ( $dstat['achieved'] >= $dstat['target'] ) {
                            $completed_days++;
                        } else {
                            $missed_days++;
                        }
                    }
                } else if ( $dstat['is_today'] ) {
                    if ( $dstat['target'] > 0 && $dstat['achieved'] >= $dstat['target'] ) {
                        $completed_days++;
                    }
                }
            }
            $staff['completed_days'] = $completed_days;
            $staff['missed_days']    = $missed_days;
            $staff['total_days']     = count($daily_stats);
        }
        unset( $staff );

        return new WP_REST_Response( [
            'assignment' => $assign,
            'staff'      => array_values( $matched_staff )
        ], 200 );
    }

    public function handle_pay_all_assignment_payouts( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param( 'id' );
        $table_payouts = $wpdb->prefix . 'st_payouts';
        $table_assign  = $wpdb->prefix . 'st_assignments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assign_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table_assign}` WHERE id = %d", $id ) );
        if ( 'ended' !== $assign_status ) {
            return new WP_REST_Response( [ 'error' => 'Nhiệm vụ/Dự án này chưa kết thúc (phải ở trạng thái "ended" mới chuyển tiền được).' ], 400 );
        }

        // Lấy danh sách ID các payout unpaid để đồng bộ ví sau đó
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $payout_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM `{$table_payouts}` WHERE assignment_id = %d AND status = 'unpaid'",
            $id
        ) );

        if ( empty( $payout_ids ) ) {
            return new WP_REST_Response( [
                'success' => true,
                'message' => 'Không có nhân sự nào cần chuyển tiền.',
                'updated' => 0
            ], 200 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table_payouts}` 
             SET status = 'paid', paid_at = %s 
             WHERE assignment_id = %d AND status = 'unpaid'",
            current_time( 'mysql' ),
            $id
        ) );

        // Đồng bộ từng ví
        foreach ( $payout_ids as $payout_id ) {
            ST_Wallet_Service::sync_payout_to_wallet( (int) $payout_id );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => sprintf( 'Đã chuyển tiền thành công cho %d nhân sự.', $updated ),
            'updated' => $updated
        ], 200 );
    }

    public function handle_recalculate_assignment_payout( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        if ( $id <= 0 ) {
            return new WP_REST_Response( [ 'error' => 'ID không hợp lệ.' ], 400 );
        }

        $count = ST_Database::calculate_single_assignment_payout( $id );

        return new WP_REST_Response( [
            'success' => true,
            'message' => sprintf( 'Tính lại xong. Có %d nhân sự được ghi nhận hoa hồng.', $count ),
            'count'   => $count
        ], 200 );
    }

    public function handle_log_behavior( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;

        $body = $req->get_body();
        $params = [];
        if ( ! empty( $body ) ) {
            $decoded = json_decode( $body, true );
            if ( is_array( $decoded ) ) {
                $params = $decoded;
            }
        }

        $log_id   = (int) ( $req->get_param( 'log_id' ) ?? $params['log_id'] ?? 0 );
        $emp_code = sanitize_text_field( $req->get_param( 'emp_code' ) ?? $params['emp_code'] ?? '' );
        $events   = $req->get_param( 'events' ) ?? $params['events'] ?? [];

        if ( $log_id <= 0 || empty( $emp_code ) || empty( $events ) || ! is_array( $events ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Dữ liệu không hợp lệ.' ], 400 );
        }

        $table_behaviors = $wpdb->prefix . 'st_behavior_logs';
        $inserted_count = 0;

        foreach ( $events as $ev ) {
            $type = sanitize_text_field( $ev['type'] ?? '' );
            $data = $ev['data'] ?? null;
            if ( is_array( $data ) || is_object( $data ) ) {
                $data_str = json_encode( $data, JSON_UNESCAPED_UNICODE );
            } else {
                $data_str = sanitize_textarea_field( (string) $data );
            }
            $time = sanitize_text_field( $ev['time'] ?? current_time( 'mysql' ) );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $success = $wpdb->insert(
                $table_behaviors,
                [
                    'log_id'     => $log_id,
                    'emp_code'   => $emp_code,
                    'event_type' => $type,
                    'event_data' => $data_str,
                    'created_at' => $time,
                ],
                [ '%d', '%s', '%s', '%s', '%s' ]
            );

            if ( $success ) {
                $inserted_count++;
            }
        }

        return new WP_REST_Response( [ 'success' => true, 'count' => $inserted_count ], 200 );
    }

    public function handle_get_log_behavior( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param( 'id' );
        if ( $id <= 0 ) {
            return new WP_REST_Response( [ 'error' => 'ID không hợp lệ.' ], 400 );
        }

        $table = $wpdb->prefix . 'st_behavior_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event_type, event_data, created_at
             FROM `{$table}`
             WHERE log_id = %d
             ORDER BY id ASC",
            $id
        ), ARRAY_A );

        // Decode JSON data if needed
        foreach ( $events as &$ev ) {
            $decoded = json_decode( $ev['event_data'], true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $ev['event_data'] = $decoded;
            }
        }

        return new WP_REST_Response( $events, 200 );
    }

    public function handle_import_personnel( WP_REST_Request $req ): WP_REST_Response {
        $data_param = $req->get_json_params() ?: $req->get_body_params();
        $rows = [];

        if ( ! empty( $data_param['batch'] ) && is_array( $data_param['batch'] ) ) {
            $rows = $data_param['batch'];
        } elseif ( ! empty( $_FILES['file'] ) ) {
            $file = $_FILES['file'];
            $filepath = $file['tmp_name'];

            if ( ! is_uploaded_file( $filepath ) ) {
                return new WP_REST_Response( [ 'error' => 'Tệp tin tải lên không hợp lệ.' ], 400 );
            }

            $content = file_get_contents( $filepath );
            if ( empty( $content ) ) {
                return new WP_REST_Response( [ 'error' => 'Tệp tin rỗng.' ], 400 );
            }

            if ( str_contains( $content, '<table' ) || str_contains( $content, '<tr' ) ) {
                $rows = $this->parse_html_xls( $content );
            } else {
                $rows = $this->parse_csv( $filepath );
            }
        } else {
            return new WP_REST_Response( [ 'error' => 'Không tìm thấy dữ liệu hợp lệ.' ], 400 );
        }

        if ( empty( $rows ) ) {
            return new WP_REST_Response( [ 'error' => 'Không tìm thấy dữ liệu hợp lệ trong tệp tin.' ], 400 );
        }

        global $wpdb;
        $table_personnel = $wpdb->prefix . 'st_personnel';

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        // Tìm dòng header thực sự: phải có ít nhất 4 cột VÀ khớp ít nhất 2 từ khoá cột
        // Bắt buộc phải chứa "họ tên" — từ này chỉ xuất hiện trong dòng header cột thực sự
        $header_indicators = [ 'id', 'họ tên', 'mã nv', 'mã nhân viên', 'lớp', 'ngành', 'nhân viên' ];
        $start_idx = 0; // Nếu không tìm thấy header hợp lệ thì đọc từ đầu
        foreach ( $rows as $row_idx => $row_check ) {
            if ( count( $row_check ) < 4 ) {
                continue;
            }
            // Bắt buộc phải có ô chứa "họ tên" → loại bỏ title/metadata rows
            $has_ho_ten = false;
            foreach ( $row_check as $cell ) {
                if ( str_contains( mb_strtolower( trim( (string) $cell ), 'UTF-8' ), 'họ tên' ) ) {
                    $has_ho_ten = true;
                    break;
                }
            }
            if ( ! $has_ho_ten ) {
                continue;
            }
            $match_count = 0;
            foreach ( $row_check as $cell ) {
                $cell_lower = mb_strtolower( trim( (string) $cell ), 'UTF-8' );
                foreach ( $header_indicators as $indicator ) {
                    if ( str_contains( $cell_lower, $indicator ) ) {
                        $match_count++;
                        break;
                    }
                }
            }
            if ( $match_count >= 2 ) {
                $start_idx = $row_idx + 1;
                break;
            }
        }
        
        for ( $i = $start_idx; $i < count( $rows ); $i++ ) {
            $row = $rows[$i];
            if ( count( $row ) < 4 ) {
                $errors[] = sprintf( 'Dòng %d: Thiếu cột dữ liệu (Yêu cầu ít nhất 4 cột).', $i + 1 );
                $skipped++;
                continue;
            }

            $id         = isset( $row[0] ) ? (int) trim( $row[0] ) : 0;
            $user_name  = isset( $row[1] ) ? sanitize_text_field( trim( $row[1] ) ) : '';
            $emp_code   = isset( $row[2] ) ? sanitize_text_field( trim( $row[2] ) ) : '';
            $class_code = isset( $row[3] ) ? sanitize_text_field( trim( $row[3] ) ) : '';
            $team       = isset( $row[4] ) ? sanitize_text_field( trim( $row[4] ) ) : '';

            // LOG BẮT ĐẦU DÒNG
            $log_file = dirname( __FILE__ ) . '/../import_debug.log';
            $log_msg = sprintf(
                "[%s] Dòng %d: Nhận row_data=%s | ID=%s (int=%d) | Tên=%s | MãNV=%s | Lớp=%s | Ngành=%s\n",
                date('Y-m-d H:i:s'),
                $i + 1,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                isset($row[0]) ? $row[0] : 'NOT_SET',
                $id,
                $user_name,
                $emp_code,
                $class_code,
                $team
            );
            file_put_contents( $log_file, $log_msg, FILE_APPEND );

            if ( empty( $user_name ) || empty( $emp_code ) || empty( $class_code ) || empty( $team ) ) {
                $errors[] = sprintf( 'Dòng %d: Họ tên, Mã NV, Mã Lớp, và Ngành không được để trống.', $i + 1 );
                $skipped++;
                continue;
            }

            // Chuẩn hóa Unicode NFD → NFC (một số trình soạn thảo hoặc OS có thể lưu dạng phân rã)
            if ( class_exists( 'Normalizer' ) ) {
                $user_name  = \Normalizer::normalize( $user_name,  \Normalizer::FORM_C ) ?: $user_name;
                $emp_code   = \Normalizer::normalize( $emp_code,   \Normalizer::FORM_C ) ?: $emp_code;
                $class_code = \Normalizer::normalize( $class_code, \Normalizer::FORM_C ) ?: $class_code;
                $team       = \Normalizer::normalize( $team,       \Normalizer::FORM_C ) ?: $team;
            }

            // Chặn HTML injection: sanitize_text_field() đã strip tag nhưng kiểm tra thêm < >
            // KHÔNG dùng regex ký tự cứng nhắc vì sẽ chặn nhầm dữ liệu tiếng Việt, ký tự đặc biệt hợp lệ
            $contains_html = str_contains( $user_name, '<' )  || str_contains( $user_name, '>' )
                          || str_contains( $emp_code, '<' )   || str_contains( $emp_code, '>' )
                          || str_contains( $class_code, '<' ) || str_contains( $class_code, '>' )
                          || str_contains( $team, '<' )       || str_contains( $team, '>' );
            if ( $contains_html ) {
                $errors[] = sprintf( 'Dòng %d: Dữ liệu chứa thẻ HTML không được phép.', $i + 1 );
                $skipped++;
                continue;
            }

            // Lọc từ ngữ thô tục
            $bad_words = [ 'đù', 'đm', 'đmm', 'địt', 'dit', 'loz', 'lồn', 'lon', 'cặc', 'cac', 'buồi', 'buoi', 'vcl', 'vl', 'vcc', 'đéo', 'deo', 'phò', 'điếm', 'đĩ', 'chó', 'fuck', 'shit', 'bitch', 'asshole', 'cunt', 'dick' ];
            $combined_text = mb_strtolower( $user_name . ' ' . $emp_code . ' ' . $class_code . ' ' . $team, 'UTF-8' );
            $is_bad = false;
            foreach ( $bad_words as $bad_word ) {
                if ( preg_match( '/\b' . preg_quote( $bad_word, '/' ) . '\b/iu', $combined_text ) ) {
                    $is_bad = true;
                    break;
                }
            }
            if ( $is_bad ) {
                $errors[] = sprintf( 'Dòng %d: Dữ liệu chứa từ ngữ không phù hợp.', $i + 1 );
                $skipped++;
                continue;
            }

            $existing_by_id = null;
            if ( $id > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $existing_by_id = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table_personnel}` WHERE id = %d", $id ), ARRAY_A );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $existing_by_code = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s)", $emp_code ), ARRAY_A );

            file_put_contents( $log_file, sprintf(
                "-> Khớp ID: %s | Khớp Code: %s\n",
                $existing_by_id ? json_encode($existing_by_id, JSON_UNESCAPED_UNICODE) : 'NULL',
                $existing_by_code ? json_encode($existing_by_code, JSON_UNESCAPED_UNICODE) : 'NULL'
            ), FILE_APPEND );

            if ( $existing_by_id ) {
                // Check code duplicate on others
                if ( strtolower( $existing_by_id['emp_code'] ) !== strtolower( $emp_code ) && $existing_by_code ) {
                    $errors[] = sprintf( 'Dòng %d: Trùng Mã NV "%s" với nhân sự khác.', $i + 1, $emp_code );
                    $skipped++;
                    file_put_contents( $log_file, "-> Bỏ qua do trùng Mã NV với người khác\n", FILE_APPEND );
                    continue;
                }

                // Check change to avoid requests/queries
                if (
                    $existing_by_id['user_name'] === $user_name &&
                    $existing_by_id['emp_code'] === $emp_code &&
                    $existing_by_id['class_code'] === $class_code &&
                    $existing_by_id['team'] === $team
                ) {
                    $skipped++;
                    file_put_contents( $log_file, "-> Bỏ qua do không thay đổi\n", FILE_APPEND );
                    continue;
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update( $table_personnel, [
                    'user_name'  => $user_name,
                    'emp_code'   => $emp_code,
                    'class_code' => $class_code,
                    'team'       => $team,
                ], [ 'id' => $id ], [ '%s', '%s', '%s', '%s' ], [ '%d' ] );
                
                $updated++;
                file_put_contents( $log_file, "-> CẬP NHẬT theo ID = " . $id . "\n", FILE_APPEND );

            } elseif ( $existing_by_code ) {
                // Duplicate code means updating the existing record
                if (
                    $existing_by_code['user_name'] === $user_name &&
                    $existing_by_code['class_code'] === $class_code &&
                    $existing_by_code['team'] === $team
                ) {
                    $skipped++;
                    file_put_contents( $log_file, "-> Bỏ qua do trùng Code và không thay đổi\n", FILE_APPEND );
                    continue;
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update( $table_personnel, [
                    'user_name'  => $user_name,
                    'class_code' => $class_code,
                    'team'       => $team,
                ], [ 'id' => $existing_by_code['id'] ], [ '%s', '%s', '%s' ], [ '%d' ] );

                $updated++;
                file_put_contents( $log_file, "-> CẬP NHẬT theo Code = " . $emp_code . " (ID=" . $existing_by_code['id'] . ")\n", FILE_APPEND );

            } else {
                // Create new
                $inserted_row = $wpdb->insert( $table_personnel, [
                    'user_name'  => $user_name,
                    'emp_code'   => $emp_code,
                    'class_code' => $class_code,
                    'team'       => $team,
                    'created_at' => current_time( 'mysql' )
                ], [ '%s', '%s', '%s', '%s', '%s' ] );

                if ( $inserted_row ) {
                    $inserted++;
                    file_put_contents( $log_file, "-> TẠO MỚI thành công\n", FILE_APPEND );
                } else {
                    $errors[] = sprintf( 'Dòng %d: Lỗi ghi dữ liệu.', $i + 1 );
                    $skipped++;
                    file_put_contents( $log_file, "-> TẠO MỚI thất bại (Lỗi ghi dữ liệu)\n", FILE_APPEND );
                }
            }
        }

        return new WP_REST_Response( [
            'success'   => true,
            'inserted'  => $inserted,
            'updated'   => $updated,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'message'   => sprintf( 'Nhập thành công: Thêm mới %d, Cập nhật %d, Bỏ qua/Lỗi %d dòng.', $inserted, $updated, $skipped )
        ], 200 );
    }

    private function parse_csv( string $filepath ): array {
        $rows = [];
        if ( ( $handle = fopen( $filepath, 'r' ) ) !== false ) {
            $bom = fread( $handle, 3 );
            if ( $bom !== "\xEF\xBB\xBF" ) {
                rewind( $handle );
            }
            while ( ( $data = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
                $rows[] = $data;
            }
            fclose( $handle );
        }
        return $rows;
    }

    private function parse_html_xls( string $content ): array {
        $rows = [];
        preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/is', $content, $tr_matches );
        if ( ! empty( $tr_matches[1] ) ) {
            foreach ( $tr_matches[1] as $tr ) {
                preg_match_all( '/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $tr, $td_matches );
                if ( ! empty( $td_matches[1] ) ) {
                    $row = [];
                    foreach ( $td_matches[1] as $td ) {
                        $val = html_entity_decode( strip_tags( $td ), ENT_QUOTES, 'UTF-8' );
                        $val = trim( preg_replace( '/\s+/u', ' ', $val ) );
                        $row[] = $val;
                    }
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }

    // ─── User and Admin Wallet & Withdrawal Handlers ────────────────────────────

    public function require_login(): bool {
        return get_current_user_id() > 0;
    }

    public function require_view_payouts_cap(): bool {
        return ( current_user_can( 'staff_tracking_view_payouts' ) || current_user_can( 'manage_options' ) ) && st_is_user_allowed();
    }

    public function require_manage_payouts_cap(): bool {
        return ( current_user_can( 'staff_tracking_manage_payouts' ) || current_user_can( 'manage_options' ) ) && st_is_user_allowed();
    }

    public function require_reconcile_payouts_cap(): bool {
        return ( current_user_can( 'staff_tracking_reconcile_payouts' ) || current_user_can( 'manage_options' ) ) && st_is_user_allowed();
    }

    public function handle_get_bank_accounts( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $emp_code   = sanitize_text_field( trim( $req->get_param( 'emp_code' ) ?? '' ) );
        $class_code = sanitize_text_field( trim( $req->get_param( 'class_code' ) ?? '' ) );

        if ( empty( $emp_code ) || empty( $class_code ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Thông tin nhân viên không hợp lệ.' ], 401 );
        }

        $table_personnel = $wpdb->prefix . 'st_personnel';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $staff = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s) AND LOWER(class_code) = LOWER(%s)",
            $emp_code,
            $class_code
        ), ARRAY_A );

        if ( ! $staff ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Thông tin nhân viên không hợp lệ.' ], 401 );
        }

        $table = $wpdb->prefix . 'staff_tracking_bank_accounts';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $accounts = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, bank_code, bank_name, account_number, account_name, is_verified 
             FROM `{$table}` WHERE emp_code = %s AND is_active = 1",
            $emp_code
        ), ARRAY_A );

        return new WP_REST_Response( [
            'success'  => true,
            'accounts' => $accounts ?: []
        ], 200 );
    }

    public function handle_create_bank_account( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $emp_code   = sanitize_text_field( trim( $req->get_param( 'emp_code' ) ?? '' ) );
        $class_code = sanitize_text_field( trim( $req->get_param( 'class_code' ) ?? '' ) );

        if ( empty( $emp_code ) || empty( $class_code ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Thông tin nhân viên không hợp lệ.' ], 401 );
        }

        $table_personnel = $wpdb->prefix . 'st_personnel';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $staff = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s) AND LOWER(class_code) = LOWER(%s)",
            $emp_code,
            $class_code
        ), ARRAY_A );

        if ( ! $staff ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Thông tin nhân viên không hợp lệ.' ], 401 );
        }

        $table = $wpdb->prefix . 'staff_tracking_bank_accounts';
        $bank_code      = sanitize_text_field( trim( $req->get_param( 'bank_code' ) ?? '' ) );
        $bank_name      = sanitize_text_field( trim( $req->get_param( 'bank_name' ) ?? '' ) );
        $account_number = sanitize_text_field( trim( $req->get_param( 'account_number' ) ?? '' ) );
        $account_name   = sanitize_text_field( trim( $req->get_param( 'account_name' ) ?? '' ) );

        if ( empty( $bank_code ) || empty( $bank_name ) || empty( $account_number ) || empty( $account_name ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Vui lòng nhập đầy đủ thông tin tài khoản.' ], 400 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE emp_code = %s AND account_number = %s AND bank_code = %s AND is_active = 1",
            $emp_code,
            $account_number,
            $bank_code
        ) );

        if ( $exists ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Tài khoản ngân hàng này đã tồn tại trong danh sách của bạn.' ], 400 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert(
            $table,
            [
                'emp_code'       => $emp_code,
                'bank_code'      => $bank_code,
                'bank_name'      => $bank_name,
                'account_number' => $account_number,
                'account_name'   => $account_name,
                'is_active'      => 1,
                'is_verified'    => 0,
                'created_at'     => current_time( 'mysql' ),
                'updated_at'     => current_time( 'mysql' )
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Lỗi lưu thông tin tài khoản ngân hàng.' ], 500 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => 'Thêm tài khoản ngân hàng thành công.',
            'id'      => $wpdb->insert_id
        ], 200 );
    }

    public function handle_update_bank_account( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $emp_code   = sanitize_text_field( trim( $req->get_param( 'emp_code' ) ?? '' ) );
        $class_code = sanitize_text_field( trim( $req->get_param( 'class_code' ) ?? '' ) );

        if ( empty( $emp_code ) || empty( $class_code ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Thông tin nhân viên không hợp lệ.' ], 401 );
        }

        $table_personnel = $wpdb->prefix . 'st_personnel';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $staff = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s) AND LOWER(class_code) = LOWER(%s)",
            $emp_code,
            $class_code
        ), ARRAY_A );

        if ( ! $staff ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Thông tin nhân viên không hợp lệ.' ], 401 );
        }

        $account_id = (int) $req->get_param( 'id' );
        $table = $wpdb->prefix . 'staff_tracking_bank_accounts';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $account = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d AND emp_code = %s AND is_active = 1",
            $account_id,
            $emp_code
        ), ARRAY_A );

        if ( ! $account ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Tài khoản ngân hàng không tồn tại hoặc đã bị xóa.' ], 404 );
        }

        $bank_code      = sanitize_text_field( trim( $req->get_param( 'bank_code' ) ?? $account['bank_code'] ) );
        $bank_name      = sanitize_text_field( trim( $req->get_param( 'bank_name' ) ?? $account['bank_name'] ) );
        $account_number = sanitize_text_field( trim( $req->get_param( 'account_number' ) ?? $account['account_number'] ) );
        $account_name   = sanitize_text_field( trim( $req->get_param( 'account_name' ) ?? $account['account_name'] ) );

        if ( empty( $bank_code ) || empty( $bank_name ) || empty( $account_number ) || empty( $account_name ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Thông tin tài khoản không được để trống.' ], 400 );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [
                'bank_code'      => $bank_code,
                'bank_name'      => $bank_name,
                'account_number' => $account_number,
                'account_name'   => $account_name,
                'updated_at'     => current_time( 'mysql' )
            ],
            [ 'id' => $account_id, 'emp_code' => $emp_code ],
            [ '%s', '%s', '%s', '%s', '%s' ],
            [ '%d', '%s' ]
        );

        return new WP_REST_Response( [
            'success' => true,
            'message' => 'Cập nhật tài khoản ngân hàng thành công.'
        ], 200 );
    }

    public function handle_delete_bank_account( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $emp_code   = sanitize_text_field( trim( $req->get_param( 'emp_code' ) ?? '' ) );
        $class_code = sanitize_text_field( trim( $req->get_param( 'class_code' ) ?? '' ) );

        if ( empty( $emp_code ) || empty( $class_code ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Thông tin nhân viên không hợp lệ.' ], 401 );
        }

        $table_personnel = $wpdb->prefix . 'st_personnel';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $staff = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s) AND LOWER(class_code) = LOWER(%s)",
            $emp_code,
            $class_code
        ), ARRAY_A );

        if ( ! $staff ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Thông tin nhân viên không hợp lệ.' ], 401 );
        }

        $account_id = (int) $req->get_param( 'id' );
        $table = $wpdb->prefix . 'staff_tracking_bank_accounts';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE id = %d AND emp_code = %s AND is_active = 1",
            $account_id,
            $emp_code
        ) );

        if ( ! $exists ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Tài khoản ngân hàng không tồn tại.' ], 404 );
        }

        // Thay vì xóa cứng, chúng ta đổi is_active thành 0
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [ 'is_active' => 0, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $account_id, 'emp_code' => $emp_code ],
            [ '%d', '%s' ],
            [ '%d', '%s' ]
        );

        return new WP_REST_Response( [
            'success' => true,
            'message' => 'Xóa tài khoản ngân hàng thành công.'
        ], 200 );
    }

    public function handle_get_user_wallet( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $emp_code   = sanitize_text_field( trim( $req->get_param( 'emp_code' ) ?? '' ) );
        $class_code = sanitize_text_field( trim( $req->get_param( 'class_code' ) ?? '' ) );

        if ( empty( $emp_code ) || empty( $class_code ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Thông tin nhân viên không hợp lệ.' ], 401 );
        }

        $table_personnel = $wpdb->prefix . 'st_personnel';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $staff = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM `{$table_personnel}` WHERE LOWER(emp_code) = LOWER(%s) AND LOWER(class_code) = LOWER(%s)",
            $emp_code,
            $class_code
        ), ARRAY_A );

        if ( ! $staff ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Thông tin nhân viên không hợp lệ.' ], 401 );
        }

        $table_txs = $wpdb->prefix . 'staff_tracking_wallet_transactions';

        // Đảm bảo ví tồn tại
        $wallet = ST_Wallet_Service::get_or_create_wallet( $emp_code );

        // Lấy lịch sử giao dịch gần đây
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $txs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, transaction_type, amount, available_balance_after, held_balance_after, description, created_at 
             FROM `{$table_txs}` 
             WHERE emp_code = %s 
             ORDER BY id DESC LIMIT 50",
            $emp_code
        ), ARRAY_A );

        return new WP_REST_Response( [
            'success' => true,
            'wallet'  => $wallet,
            'history' => $txs ?: []
        ], 200 );
    }

    public function handle_create_withdrawal( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => false, 'error' => 'Chức năng tự yêu cầu rút tiền bị vô hiệu hóa. Quản trị viên sẽ trực tiếp chuyển tiền hoa hồng khi dự án kết thúc.' ], 403 );
        $user_id = get_current_user_id();
        $bank_account_id = (int) $req->get_param( 'bank_account_id' );
        $amount = (float) $req->get_param( 'amount' );

        if ( $amount <= 0 ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Số tiền rút phải lớn hơn 0 VNĐ.' ], 400 );
        }

        // Hạn mức tối thiểu/tối đa trong settings
        $min_opt = get_option( 'st_min_withdrawal_limit' );
        $max_opt = get_option( 'st_max_withdrawal_limit' );
        $min_limit = ( $min_opt !== '' && $min_opt !== false ) ? (float) $min_opt : 50000;
        $max_limit = ( $max_opt !== '' && $max_opt !== false ) ? (float) $max_opt : 10000000;

        if ( $amount < $min_limit ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => sprintf( 'Số tiền rút tối thiểu là %s đ.', number_format( $min_limit ) ) ], 400 );
        }
        if ( $amount > $max_limit ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => sprintf( 'Số tiền rút tối đa một lần là %s đ.', number_format( $max_limit ) ) ], 400 );
        }

        // Chống race condition rút tiền
        $code = ST_Withdrawal_Service::create_withdrawal( $user_id, $bank_account_id, $amount );

        if ( ! $code ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Tạo yêu cầu rút tiền thất bại. Vui lòng kiểm tra lại số dư khả dụng hoặc tài khoản ngân hàng.' ], 400 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => 'Gửi yêu cầu rút tiền thành công.',
            'code'    => $code
        ], 200 );
    }

    public function handle_get_user_withdrawals( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $emp_code = sanitize_text_field( $req->get_param( 'emp_code' ) ?? '' );
        $table = $wpdb->prefix . 'staff_tracking_withdrawals';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, withdrawal_code, amount, bank_code, bank_name, account_number, account_name, status, requested_at, paid_at, admin_note, failure_reason 
             FROM `{$table}` WHERE emp_code = %s ORDER BY id DESC",
            $emp_code
        ), ARRAY_A );

        return new WP_REST_Response( [
            'success'     => true,
            'withdrawals' => $rows ?: []
        ], 200 );
    }

    public function handle_get_user_withdrawal_details( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $emp_code = sanitize_text_field( $req->get_param( 'emp_code' ) ?? '' );
        $id = (int) $req->get_param( 'id' );
        $table = $wpdb->prefix . 'staff_tracking_withdrawals';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d AND emp_code = %s",
            $id,
            $emp_code
        ), ARRAY_A );

        if ( ! $row ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Không tìm thấy yêu cầu rút tiền.' ], 404 );
        }

        return new WP_REST_Response( [
            'success'    => true,
            'withdrawal' => $row
        ], 200 );
    }

    public function handle_cancel_withdrawal( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $emp_code = sanitize_text_field( $req->get_param( 'emp_code' ) ?? '' );
        $id = (int) $req->get_param( 'id' );
        $table = $wpdb->prefix . 'staff_tracking_withdrawals';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT status FROM `{$table}` WHERE id = %d AND emp_code = %s",
            $id,
            $emp_code
        ), ARRAY_A );

        if ( ! $row ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Không tìm thấy yêu cầu rút tiền.' ], 404 );
        }

        if ( ST_Withdrawal_Service::STATUS_PENDING !== $row['status'] && ST_Withdrawal_Service::STATUS_APPROVED !== $row['status'] ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Yêu cầu không còn ở trạng thái chờ duyệt hoặc đã duyệt nên không thể hủy.' ], 400 );
        }

        $success = ST_Withdrawal_Service::update_status( $id, ST_Withdrawal_Service::STATUS_CANCELLED, [
            'admin_note' => 'Hội viên tự hủy yêu cầu rút tiền.'
        ] );

        if ( ! $success ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Hủy yêu cầu rút tiền thất bại.' ], 500 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => 'Đã hủy yêu cầu rút tiền và hoàn lại ví.'
        ], 200 );
    }

    public function handle_admin_get_withdrawals( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'staff_tracking_withdrawals';

        $page     = max( 1, (int) $req->get_param( 'page' ) );
        $per_page = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ?: 20 ) );
        $offset   = ( $page - 1 ) * $per_page;
        $status   = sanitize_text_field( $req->get_param( 'status' ) ?? '' );
        $search   = sanitize_text_field( $req->get_param( 'search' ) ?? '' );

        $where = [ '1=1' ];
        $params = [];

        if ( ! empty( $status ) ) {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        if ( ! empty( $search ) ) {
            $where[] = '(withdrawal_code LIKE %s OR account_number LIKE %s OR account_name LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $params ) : "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}" );

        $params[] = $per_page;
        $params[] = $offset;
        $sql = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        // Thêm tên hiển thị của WordPress user
        if ( ! empty( $rows ) ) {
            foreach ( $rows as &$r ) {
                $user = get_userdata( $r['user_id'] );
                $r['wp_user_name'] = $user ? $user->display_name : 'Không xác định';
            }
            unset( $r );
        }

        return new WP_REST_Response( [
            'success'     => true,
            'rows'        => $rows ?: [],
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page
        ], 200 );
    }

    public function handle_admin_get_withdrawal_details( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param( 'id' );
        $table = $wpdb->prefix . 'staff_tracking_withdrawals';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d",
            $id
        ), ARRAY_A );

        if ( ! $row ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Không tìm thấy yêu cầu rút tiền.' ], 404 );
        }

        $user = get_userdata( $row['user_id'] );
        $row['wp_user_name'] = $user ? $user->display_name : 'Không xác định';

        return new WP_REST_Response( [
            'success'    => true,
            'withdrawal' => $row
        ], 200 );
    }

    public function handle_admin_approve_withdrawal( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $admin_note = sanitize_textarea_field( $req->get_param( 'admin_note' ) ?? '' );

        $success = ST_Withdrawal_Service::update_status( $id, ST_Withdrawal_Service::STATUS_APPROVED, [
            'admin_user_id' => get_current_user_id(),
            'admin_note'    => $admin_note
        ] );

        if ( ! $success ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Phê duyệt yêu cầu rút tiền thất bại.' ], 400 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => 'Đã phê duyệt yêu cầu rút tiền.'
        ], 200 );
    }

    public function handle_admin_reject_withdrawal( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $admin_note = sanitize_textarea_field( $req->get_param( 'admin_note' ) ?? '' );

        if ( empty( $admin_note ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Vui lòng cung cấp lý do từ chối.' ], 400 );
        }

        $success = ST_Withdrawal_Service::update_status( $id, ST_Withdrawal_Service::STATUS_REJECTED, [
            'admin_user_id' => get_current_user_id(),
            'admin_note'    => $admin_note,
            'failure_reason' => $admin_note
        ] );

        if ( ! $success ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Từ chối yêu cầu rút tiền thất bại.' ], 400 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => 'Đã từ chối yêu cầu rút tiền và hoàn lại ví.'
        ], 200 );
    }

    public function handle_admin_mark_transfer_initiated( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $admin_note = sanitize_textarea_field( $req->get_param( 'admin_note' ) ?? '' );

        $success = ST_Withdrawal_Service::update_status( $id, ST_Withdrawal_Service::STATUS_PROCESSING, [
            'admin_user_id' => get_current_user_id(),
            'admin_note'    => $admin_note
        ] );

        if ( ! $success ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Không thể cập nhật trạng thái yêu cầu rút tiền.' ], 400 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => 'Yêu cầu rút tiền được chuyển sang trạng thái đang chuyển khoản.'
        ], 200 );
    }

    public function handle_admin_reconcile_withdrawal( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $admin_note = sanitize_textarea_field( $req->get_param( 'admin_note' ) ?? '' );

        if ( empty( $admin_note ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Vui lòng cung cấp lý do đối soát và thông tin giao dịch ngân hàng thủ công.' ], 400 );
        }

        $success = ST_Withdrawal_Service::update_status( $id, ST_Withdrawal_Service::STATUS_PAID, [
            'admin_user_id' => get_current_user_id(),
            'admin_note'    => $admin_note,
            'sepay_transaction_id' => 'manual_' . time(),
            'bank_reference_code'  => 'manual_reconciled',
            'bank_transaction_date'=> current_time( 'mysql' )
        ] );

        if ( ! $success ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Không thể hoàn tất đối soát thủ công.' ], 400 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => 'Đã hoàn tất đối soát thủ công chuyển sang Đã chuyển tiền thành công.'
        ], 200 );
    }

    public function handle_sepay_webhook( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;

        $signature = $req->get_header( 'x-sepay-signature' );
        $timestamp = $req->get_header( 'x-sepay-timestamp' );
        $rawBody   = $req->get_body();

        $secret_key = trim( get_option( 'st_sepay_webhook_token', '' ) );
        $is_auto_reconcile = (int) get_option( 'st_sepay_auto_reconcile', 0 );

        if ( ! $is_auto_reconcile || empty( $secret_key ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'SePay auto-reconciliation is disabled or not configured.' ], 400 );
        }

        $received_sig = str_replace( 'sha256=', '', trim( strtolower( $signature ?? '' ) ) );

        // Đối chiếu chữ ký HMAC-SHA256: thử cả định dạng {timestamp}.{body} và chỉ {body}
        $calc_standard = hash_hmac( 'sha256', ( $timestamp ?? '' ) . '.' . $rawBody, $secret_key );
        $calc_fallback = hash_hmac( 'sha256', $rawBody, $secret_key );

        if ( empty( $received_sig ) || ( ! hash_equals( $calc_standard, $received_sig ) && ! hash_equals( $calc_fallback, $received_sig ) ) ) {
            error_log( '[SePay] Signature mismatch. TX Body: ' . $rawBody );
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Unauthorized SePay Webhook (Signature mismatch).' ], 401 );
        }

        $data = $req->get_json_params() ?: [];
        $sepay_tx_id = sanitize_text_field( $data['id'] ?? '' );
        if ( empty( $sepay_tx_id ) ) {
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Missing transaction ID.' ], 400 );
        }

        // Kiểm tra trùng lặp giao dịch
        $table_sepay = $wpdb->prefix . 'staff_tracking_sepay_transactions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $duplicate = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table_sepay}` WHERE provider_transaction_id = %s LIMIT 1",
            $sepay_tx_id
        ) );
        if ( $duplicate ) {
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Transaction already processed.' ], 200 );
        }

        $content = sanitize_text_field( $data['content'] ?? ($data['transactionContent'] ?? ($data['description'] ?? '')) );

        $amount = 0.0;
        if ( isset( $data['transferAmount'] ) && (float)$data['transferAmount'] > 0 ) {
            $amount = (float)$data['transferAmount'];
        } elseif ( isset( $data['amountOut'] ) && (float)$data['amountOut'] > 0 ) {
            $amount = (float)$data['amountOut'];
        } elseif ( isset( $data['amountIn'] ) && (float)$data['amountIn'] > 0 ) {
            $amount = (float)$data['amountIn'];
        } elseif ( isset( $data['amount'] ) && (float)$data['amount'] > 0 ) {
            $amount = (float)$data['amount'];
        }

        $reference_code = sanitize_text_field( $data['referenceCode'] ?? ($data['code'] ?? ($data['referenceNumber'] ?? '')) );
        $transaction_date = sanitize_text_field( $data['transactionDate'] ?? current_time( 'mysql' ) );

        if ( empty( $content ) || $amount <= 0 ) {
            error_log( '[SePay] Invalid content or amount. Content: ' . $content . ' | Amount: ' . $amount . ' | TxID: ' . $sepay_tx_id );
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Invalid content or amount.' ], 400 );
        }

        // Phân tích nội dung chuyển khoản
        $clean_content = strtoupper( str_replace( ' ', '', $content ) );
        $desc_clean = strtoupper( str_replace( ' ', '', sanitize_text_field( $data['description'] ?? '' ) ) );

        $table_payouts = $wpdb->prefix . 'st_payouts';
        $payout = null;

        // 1. Tìm theo mã QR Code 15 phút (Dynamic QR)
        // qr_code thường có dạng HHTR + 6 ký tự
        $payout = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table_payouts}` WHERE qr_code = %s",
            $clean_content
        ), ARRAY_A );

        if ( ! $payout ) {
            // Tìm trong mô tả xem có chứa mã QR không (dùng LIKE hoặc rtrim, nhưng SePay thường tách content rõ ràng)
            // Thử search exact match trên desc_clean
            $payout = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM `{$table_payouts}` WHERE qr_code = %s",
                $desc_clean
            ), ARRAY_A );
        }
        
        if ( ! $payout ) {
            // Thử dùng LIKE nếu ngân hàng gắn thêm tiền tố/hậu tố
            $payout = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM `{$table_payouts}` WHERE qr_code IS NOT NULL AND qr_code != '' AND ( %s LIKE CONCAT('%%', qr_code, '%%') OR %s LIKE CONCAT('%%', qr_code, '%%') ) LIMIT 1",
                $clean_content, $desc_clean
            ), ARRAY_A );
        }

        // 2. Fallback: Tìm theo pattern tĩnh cũ (HHTR{emp}P{id}) nếu không tìm thấy mã động
        if ( ! $payout ) {
            if ( preg_match( '/^HHTR([A-Z0-9]+)P([0-9]+)$/', $clean_content, $matches ) ||
                 preg_match( '/HHTR([A-Z0-9]+)P([0-9]+)/', $desc_clean, $matches ) ) {
                $payout_id = (int) $matches[2];

                $payout = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM `{$table_payouts}` WHERE id = %d",
                    $payout_id
                ), ARRAY_A );
            }
        }

        if ( ! $payout ) {
            error_log( '[SePay] Content pattern mismatch or QR not found. Content: "' . $content . '" | TxID: ' . $sepay_tx_id );
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Content pattern mismatch or QR not found.' ], 400 );
        }

        $payout_id = $payout['id'];
        $emp_code  = $payout['emp_code'];

        $transfer_type = strtolower( sanitize_text_field( $data['transferType'] ?? '' ) );

        // Xử lý hoàn tiền (tiền trả lại ngân hàng Admin)
        if ( $transfer_type === 'in' ) {
            // Đánh dấu lại thành refunded (hoàn tiền)
            $success = ST_Database::mark_payout_refunded( $payout_id, $sepay_tx_id );
            if ( ! $success ) {
                error_log( '[SePay] Failed to mark payout ' . $payout_id . ' as refunded. TxID: ' . $sepay_tx_id );
                return new WP_REST_Response( [ 'success' => false, 'error' => 'Failed to mark payout as refunded.' ], 500 );
            }
            error_log( '[SePay] ⚠️ Payout #' . $payout_id . ' (' . $payout['emp_code'] . ') REFUNDED (Money IN). TxID: ' . $sepay_tx_id );
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Marked as refunded' ], 200 );
        }

        // Bắt đầu xử lý tiền đi
        if ( $transfer_type !== 'out' ) {
            // Nếu không phải là out thì bỏ qua, trả về 200 để tránh spam
            error_log( '[SePay] Ignored non-OUT transaction for Payout #' . $payout_id . '. transferType: ' . $transfer_type );
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Ignored non-OUT transaction' ], 200 );
        }

        // Kiểm tra sai số lượng tiền (cho phép sai số dưới 1000đ do làm tròn nếu có)
        if ( abs( $amount - (float)$payout['amount'] ) > 1000 ) {
            error_log( '[SePay] ❌ Amount mismatch for Payout #' . $payout_id . '. Expected: ' . $payout['amount'] . ', Got: ' . $amount . ' | TxID: ' . $sepay_tx_id );
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Amount mismatch. Ignored.' ], 200 );
        }

        if ( 'paid' === $payout['status'] ) {
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Payout already paid.' ], 200 );
        }

        $db_clean_emp = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $payout['emp_code'] ) );
        if ( $db_clean_emp !== $emp_code ) {
            error_log( '[SePay] Employee mismatch. Content: "' . $emp_code . '" | DB: "' . $db_clean_emp . '" | TxID: ' . $sepay_tx_id );
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Employee mismatch.' ], 400 );
        }

        // Đánh dấu đã thanh toán
        $success = ST_Database::mark_payout_paid( $payout_id, $sepay_tx_id );
        if ( ! $success ) {
            error_log( '[SePay] Failed to mark payout ' . $payout_id . ' as paid. TxID: ' . $sepay_tx_id );
            return new WP_REST_Response( [ 'success' => false, 'error' => 'Failed to mark payout as paid.' ], 500 );
        }

        // Ghi nhật ký giao dịch SePay
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table_sepay,
            [
                'provider_transaction_id' => $sepay_tx_id,
                'provider'                => 'sepay',
                'gateway'                 => sanitize_text_field( $data['gateway'] ?? '' ),
                'account_number'          => sanitize_text_field( $data['accountNumber'] ?? '' ),
                'sub_account'             => sanitize_text_field( $data['subAccount'] ?? '' ),
                'content'                 => $content,
                'transfer_type'           => sanitize_text_field( $data['transferType'] ?? '' ),
                'transfer_amount'         => $amount,
                'accumulated'             => isset($data['accumulated']) ? (float)$data['accumulated'] : 0.0,
                'reference_code'          => $reference_code,
                'raw_payload'             => $rawBody,
                'signature'               => $signature,
                'process_status'          => 'success',
                'transaction_date'        => $transaction_date,
                'received_at'             => current_time( 'mysql' )
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        error_log( '[SePay] ✓ Payout #' . $payout_id . ' (' . $payout['emp_code'] . ') marked paid. Amount: ' . $amount . ' | TxID: ' . $sepay_tx_id );
        return new WP_REST_Response( [ 'success' => true, 'message' => 'Payout processed successfully.' ], 200 );
    }
}
