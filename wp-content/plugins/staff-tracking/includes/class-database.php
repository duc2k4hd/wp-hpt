<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles database table creation, upgrade, and direct queries.
 * All output is escaped at the presentation layer; this class
 * only sanitises / prepares values before writing.
 */
class ST_Database {

    private static ?ST_Database $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Run install automatically if version changes (safe because dbDelta)
        $db_version = get_option( 'st_db_version', '0' );
        if ( version_compare( $db_version, ST_VERSION, '<' ) ) {
            self::install();
        }
    }

    // ── Schema ──────────────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;

        $table   = $wpdb->prefix . ST_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_id`  VARCHAR(64)     NOT NULL DEFAULT '',
            `ip`          VARCHAR(45)     NOT NULL DEFAULT '',
            `user_agent`  TEXT            NOT NULL,
            `url`         TEXT            NOT NULL,
            `page_path`   VARCHAR(512)    NOT NULL DEFAULT '',
            `duration`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `user_name`   VARCHAR(150)    NOT NULL DEFAULT '',
            `emp_code`    VARCHAR(150)    NOT NULL DEFAULT '',
            `class_code`  VARCHAR(150)    NOT NULL DEFAULT '',
            `team`        VARCHAR(150)    NOT NULL DEFAULT '',
            `is_valid`    TINYINT(1)      NOT NULL DEFAULT 0,
            `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_created_at` (`created_at`),
            INDEX `idx_user_name`  (`user_name`(50)),
            INDEX `idx_emp_code`   (`emp_code`(50)),
            INDEX `idx_class_code` (`class_code`(50)),
            INDEX `idx_team`       (`team`(50)),
            INDEX `idx_ip`         (`ip`),
            INDEX `idx_session`    (`session_id`),
            INDEX `idx_personnel`  (`user_name`(50), `emp_code`(50), `class_code`(50), `team`(50), `created_at`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        // Suppress warnings for ALTER TABLE if columns already exist
        $wpdb->suppress_errors(true);
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `emp_code` VARCHAR(150) NOT NULL DEFAULT '' AFTER `user_name`" );
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `class_code` VARCHAR(150) NOT NULL DEFAULT '' AFTER `emp_code`" );
        $wpdb->suppress_errors(false);

        dbDelta( $sql );

        // dbDelta may silently fail to create these composite/length-limited indexes
        // Let's force them and support automatic upgrade.
        $indexes = [
            'idx_emp_code'    => 'INDEX `idx_emp_code` (`emp_code`(50))',
            'idx_class_code'  => 'INDEX `idx_class_code` (`class_code`(50))',
            'idx_personnel'   => 'INDEX `idx_personnel` (`user_name`(50), `emp_code`(50), `class_code`(50), `team`(50), `created_at` DESC)',
            'idx_link_cheat'  => 'INDEX `idx_link_cheat` (`emp_code`(50), `page_path`(150), `is_valid`, `created_at` DESC)',
            'idx_staff_stats' => 'INDEX `idx_staff_stats` (`emp_code`(50), `class_code`(50), `created_at` DESC, `is_valid`, `duration`)',
            'idx_page_path'   => 'INDEX `idx_page_path` (`page_path`(150))'
        ];
        foreach ($indexes as $key => $definition) {
            $exists = $wpdb->get_results("SHOW INDEXES FROM {$table} WHERE Key_name = '$key'");
            if (!empty($exists) && $key === 'idx_personnel') {
                $has_created_at = false;
                foreach ($exists as $index_col) {
                    if ($index_col->Column_name === 'created_at') {
                        $has_created_at = true;
                        break;
                    }
                }
                if (!$has_created_at) {
                    $wpdb->suppress_errors(true);
                    $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `idx_personnel`");
                    $wpdb->suppress_errors(false);
                    $exists = []; // Reset to trigger recreate
                }
            }
            if (empty($exists)) {
                $wpdb->suppress_errors(true);
                $wpdb->query("ALTER TABLE `{$table}` ADD $definition");
                $wpdb->suppress_errors(false);
            }
        }


        $table_assign = $wpdb->prefix . 'st_assignments';
        
        // Kiểm tra danh sách các cột đang tồn tại thực tế
        $existing_cols = $wpdb->get_col( "DESCRIBE `{$table_assign}`" );
        
        if ( ! empty( $existing_cols ) ) {
            $wpdb->suppress_errors( true );
            
            // Rename month sang period_value nếu có
            if ( in_array( 'month', $existing_cols, true ) && ! in_array( 'period_value', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` CHANGE `month` `period_value` VARCHAR(20) NOT NULL DEFAULT ''" );
            }
            
            // Thêm period_type nếu chưa có
            if ( ! in_array( 'period_type', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` ADD COLUMN `period_type` VARCHAR(10) NOT NULL DEFAULT 'month'" );
            }
            
            // Thêm assign_scope nếu chưa có
            if ( ! in_array( 'assign_scope', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` ADD COLUMN `assign_scope` VARCHAR(15) NOT NULL DEFAULT 'specific'" );
            }
            
            // Thêm product_selection nếu chưa có
            if ( ! in_array( 'product_selection', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` ADD COLUMN `product_selection` VARCHAR(15) NOT NULL DEFAULT 'all'" );
            }
            
            // Thêm product_ids nếu chưa có
            if ( ! in_array( 'product_ids', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` ADD COLUMN `product_ids` TEXT NULL" );
            }
            
            // Thêm category_ids nếu chưa có
            if ( ! in_array( 'category_ids', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` ADD COLUMN `category_ids` TEXT NULL" );
            }

            // Thêm allowed_paths nếu chưa có (pre-computed paths)
            if ( ! in_array( 'allowed_paths', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` ADD COLUMN `allowed_paths` MEDIUMTEXT NULL" );
            }

            // Thêm phases_data nếu chưa có (lưu trữ phân đoạn thời gian)
            if ( ! in_array( 'phases_data', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` ADD COLUMN `phases_data` LONGTEXT NULL" );
            }

            // Thêm holidays nếu chưa có (lưu trữ danh sách ngày nghỉ lễ)
            if ( ! in_array( 'holidays', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` ADD COLUMN `holidays` LONGTEXT NULL" );
            }

            // Thêm title nếu chưa có
            if ( ! in_array( 'title', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` ADD COLUMN `title` VARCHAR(255) NULL" );
            }

            // Thêm created_by nếu chưa có
            if ( ! in_array( 'created_by', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` ADD COLUMN `created_by` VARCHAR(150) NULL" );
            }

            // Thêm status nếu chưa có
            if ( ! in_array( 'status', $existing_cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$table_assign}` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active'" );
            }
            
            $wpdb->suppress_errors( false );
        }

        $sql_assign = "CREATE TABLE IF NOT EXISTS `{$table_assign}` (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title`             VARCHAR(255)    NULL,
            `created_by`        VARCHAR(150)    NULL,
            `status`            VARCHAR(20)     NOT NULL DEFAULT 'active',
            `period_type`       VARCHAR(10)     NOT NULL DEFAULT 'month',
            `period_value`      VARCHAR(20)     NOT NULL DEFAULT '',
            `assign_scope`      VARCHAR(15)     NOT NULL DEFAULT 'specific',
            `target_type`       VARCHAR(20)     NOT NULL DEFAULT 'team',
            `target_value`      VARCHAR(150)    NOT NULL DEFAULT '',
            `product_selection` VARCHAR(15)     NOT NULL DEFAULT 'all',
            `product_ids`       TEXT            NULL,
            `category_ids`      TEXT            NULL,
            `allowed_paths`     MEDIUMTEXT      NULL,
            `phases_data`       LONGTEXT        NULL,
            `holidays`          LONGTEXT        NULL,
            `kpi_target`        INT UNSIGNED    NOT NULL DEFAULT 0,
            `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_period`      (`period_type`, `period_value`),
            INDEX `idx_target`      (`target_type`, `target_value`(50)),
            INDEX `idx_scope`       (`assign_scope`),
            INDEX `idx_status`      (`status`)
        ) {$charset};";

        dbDelta( $sql_assign );

        $table_payouts = $wpdb->prefix . 'st_payouts';
        $sql_payouts = "CREATE TABLE IF NOT EXISTS `{$table_payouts}` (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `emp_code`          VARCHAR(150)    NOT NULL DEFAULT '',
            `user_name`         VARCHAR(150)    NOT NULL DEFAULT '',
            `assignment_id`     BIGINT UNSIGNED NOT NULL,
            `period_type`       VARCHAR(10)     NOT NULL DEFAULT 'month',
            `period_value`      VARCHAR(20)     NOT NULL DEFAULT '',
            `kpi_target`        INT UNSIGNED    NOT NULL DEFAULT 0,
            `kpi_achieved`      INT UNSIGNED    NOT NULL DEFAULT 0,
            `amount`            DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
            `status`            VARCHAR(15)     NOT NULL DEFAULT 'unpaid',
            `paid_at`           DATETIME        NULL,
            `sepay_transaction_id` VARCHAR(100) NULL,
            `qr_code`           VARCHAR(50)     NULL,
            `qr_expires_at`     DATETIME        NULL,
            `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_payout_unique` (`emp_code`, `assignment_id`, `period_value`),
            INDEX `idx_status` (`status`)
        ) {$charset};";

        dbDelta( $sql_payouts );

        // Force add sepay_transaction_id if dbDelta skipped it
        $sepay_col_check = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_payouts}` LIKE 'sepay_transaction_id'" );
        if ( empty( $sepay_col_check ) ) {
            $wpdb->query( "ALTER TABLE `{$table_payouts}` ADD COLUMN `sepay_transaction_id` VARCHAR(100) NULL" );
        }

        $qr_col_check = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_payouts}` LIKE 'qr_code'" );
        if ( empty( $qr_col_check ) ) {
            $wpdb->query( "ALTER TABLE `{$table_payouts}` ADD COLUMN `qr_code` VARCHAR(50) NULL" );
            $wpdb->query( "ALTER TABLE `{$table_payouts}` ADD COLUMN `qr_expires_at` DATETIME NULL" );
            $wpdb->query( "ALTER TABLE `{$table_payouts}` ADD INDEX `idx_qr_code` (`qr_code`)" );
        }

        $table_behaviors = $wpdb->prefix . 'st_behavior_logs';
        $sql_behaviors = "CREATE TABLE IF NOT EXISTS `{$table_behaviors}` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `log_id`        BIGINT UNSIGNED NOT NULL,
            `emp_code`      VARCHAR(150)    NOT NULL DEFAULT '',
            `event_type`    VARCHAR(50)     NOT NULL DEFAULT '',
            `event_data`    TEXT            NULL,
            `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_log_id` (`log_id`),
            INDEX `idx_emp_code` (`emp_code`(50)),
            INDEX `idx_event_type` (`event_type`),
            INDEX `idx_created_at` (`created_at`)
        ) {$charset};";

        dbDelta( $sql_behaviors );

        $table_personnel = $wpdb->prefix . 'st_personnel';
        $sql_personnel = "CREATE TABLE IF NOT EXISTS `{$table_personnel}` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_name`     VARCHAR(255)    NOT NULL DEFAULT '',
            `emp_code`      VARCHAR(100)    NOT NULL DEFAULT '',
            `class_code`    VARCHAR(100)    NOT NULL DEFAULT '',
            `team`          VARCHAR(255)    NOT NULL DEFAULT '',
            `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_emp_code_class` (`emp_code`, `class_code`),
            INDEX `idx_emp_code` (`emp_code`),
            INDEX `idx_class_code` (`class_code`)
        ) {$charset};";
        dbDelta( $sql_personnel );

        // ── Wallet and Withdrawal Tables ──
        $table_wallets = $wpdb->prefix . 'staff_tracking_wallets';
        $table_wallet_transactions = $wpdb->prefix . 'staff_tracking_wallet_transactions';
        $table_bank_accounts = $wpdb->prefix . 'staff_tracking_bank_accounts';
        $table_withdrawals = $wpdb->prefix . 'staff_tracking_withdrawals';

        // Tự động dọn dẹp cấu trúc cũ nếu có cột user_id
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $check_col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_wallets}` LIKE 'user_id'" );
        if ( ! empty( $check_col ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "DROP TABLE IF EXISTS `{$table_withdrawals}`" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "DROP TABLE IF EXISTS `{$table_bank_accounts}`" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "DROP TABLE IF EXISTS `{$table_wallet_transactions}`" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "DROP TABLE IF EXISTS `{$table_wallets}`" );
        }

        // Bảng wallets
        $sql_wallets = "CREATE TABLE IF NOT EXISTS `{$table_wallets}` (
            `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `emp_code`          VARCHAR(100)    NOT NULL DEFAULT '',
            `available_balance` DECIMAL(18,0)   NOT NULL DEFAULT 0,
            `held_balance`      DECIMAL(18,0)   NOT NULL DEFAULT 0,
            `version`           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `emp_code` (`emp_code`)
        ) {$charset};";
        dbDelta( $sql_wallets );

        // Bảng wallet_transactions
        $sql_wallet_transactions = "CREATE TABLE IF NOT EXISTS `{$table_wallet_transactions}` (
            `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `wallet_id`               BIGINT UNSIGNED NOT NULL,
            `emp_code`                VARCHAR(100)    NOT NULL DEFAULT '',
            `transaction_type`        VARCHAR(50)     NOT NULL,
            `amount`                  DECIMAL(18,0)   NOT NULL,
            `available_balance_after` DECIMAL(18,0)   NOT NULL,
            `held_balance_after`      DECIMAL(18,0)   NOT NULL,
            `reference_type`          VARCHAR(50)     NULL,
            `reference_id`            VARCHAR(100)    NULL,
            `description`             TEXT            NULL,
            `created_by`              BIGINT UNSIGNED NULL,
            `created_at`              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_reference` (`emp_code`, `transaction_type`, `reference_type`(50), `reference_id`(50)),
            INDEX `idx_wallet` (`wallet_id`),
            INDEX `idx_emp_code` (`emp_code`),
            INDEX `idx_reference` (`reference_type`(30), `reference_id`(50))
        ) {$charset};";
        dbDelta( $sql_wallet_transactions );

        // Bảng bank_accounts
        $sql_bank_accounts = "CREATE TABLE IF NOT EXISTS `{$table_bank_accounts}` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `emp_code`       VARCHAR(100)    NOT NULL DEFAULT '',
            `bank_code`      VARCHAR(50)     NOT NULL,
            `bank_name`      VARCHAR(150)    NOT NULL,
            `account_number` VARCHAR(100)    NOT NULL,
            `account_name`   VARCHAR(200)    NOT NULL,
            `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
            `is_verified`    TINYINT(1)      NOT NULL DEFAULT 0,
            `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_emp_code` (`emp_code`),
            INDEX `idx_active` (`is_active`)
        ) {$charset};";
        dbDelta( $sql_bank_accounts );

        // Bảng withdrawals
        $sql_withdrawals = "CREATE TABLE IF NOT EXISTS `{$table_withdrawals}` (
            `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `withdrawal_code`       VARCHAR(50)     NOT NULL,
            `emp_code`              VARCHAR(100)    NOT NULL DEFAULT '',
            `wallet_id`             BIGINT UNSIGNED NOT NULL,
            `bank_account_id`       BIGINT UNSIGNED NULL,
            `amount`                DECIMAL(18,0)   NOT NULL,
            `bank_code`             VARCHAR(50)     NOT NULL,
            `bank_name`             VARCHAR(150)    NOT NULL,
            `account_number`        VARCHAR(100)    NOT NULL,
            `account_name`          VARCHAR(200)    NOT NULL,
            `status`                VARCHAR(50)     NOT NULL,
            `requested_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `approved_at`           DATETIME        NULL,
            `processing_at`         DATETIME        NULL,
            `paid_at`               DATETIME        NULL,
            `rejected_at`           DATETIME        NULL,
            `failed_at`             DATETIME        NULL,
            `approved_by`           BIGINT UNSIGNED NULL,
            `processed_by`          BIGINT UNSIGNED NULL,
            `rejected_by`           BIGINT UNSIGNED NULL,
            `admin_note`            TEXT            NULL,
            `failure_code`          VARCHAR(100)    NULL,
            `failure_reason`        TEXT            NULL,
            `sepay_transaction_id`  VARCHAR(100)    NULL,
            `bank_reference_code`   VARCHAR(255)    NULL,
            `bank_transaction_date` DATETIME        NULL,
            `version`               BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `withdrawal_code` (`withdrawal_code`),
            UNIQUE KEY `sepay_transaction_id` (`sepay_transaction_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_emp_code` (`emp_code`),
            INDEX `idx_requested` (`requested_at`)
        ) {$charset};";
        dbDelta( $sql_withdrawals );

        $table_sepay_transactions = $wpdb->prefix . 'staff_tracking_sepay_transactions';
        $sql_sepay_transactions = "CREATE TABLE IF NOT EXISTS `{$table_sepay_transactions}` (
            `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `provider`                VARCHAR(50)     NOT NULL DEFAULT 'sepay',
            `provider_transaction_id` VARCHAR(100)    NOT NULL,
            `gateway`                 VARCHAR(100)    NULL,
            `transaction_date`        DATETIME        NULL,
            `account_number`          VARCHAR(100)    NULL,
            `sub_account`             VARCHAR(100)    NULL,
            `transaction_code`        VARCHAR(100)    NULL,
            `content`                 TEXT            NULL,
            `transfer_type`           VARCHAR(20)     NULL,
            `transfer_amount`         DECIMAL(18,0)   NOT NULL DEFAULT 0,
            `accumulated`             DECIMAL(18,0)   NULL,
            `reference_code`          VARCHAR(255)    NULL,
            `raw_payload`             LONGTEXT        NOT NULL,
            `signature`               VARCHAR(255)    NULL,
            `process_status`          VARCHAR(50)     NOT NULL,
            `process_error`           TEXT            NULL,
            `retry_count`             INT UNSIGNED    NOT NULL DEFAULT 0,
            `withdrawal_id`           BIGINT UNSIGNED NULL,
            `received_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `processed_at`            DATETIME        NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_provider_transaction` (`provider`, `provider_transaction_id`),
            INDEX `idx_withdrawal` (`withdrawal_id`),
            INDEX `idx_received` (`received_at`),
            INDEX `idx_status` (`process_status`)
        ) {$charset};";
        dbDelta( $sql_sepay_transactions );

        update_option( 'st_db_version', ST_VERSION );
    }

    // ── Rate-limit check ────────────────────────────────────────────────────

    /**
     * Returns true when the IP and Account has already logged a session within ST_RATE_LIMIT seconds.
     */
    public static function is_rate_limited( string $ip, string $emp_code = '' ): bool {
        global $wpdb;

        $table  = $wpdb->prefix . ST_TABLE;
        $dt     = new DateTime( 'now', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
        
        // Lấy rate limit do admin cài đặt
        $rate_opt = get_option( 'st_rate_limit' );
        $rate_limit = ( $rate_opt !== '' && $rate_opt !== false ) ? (int) $rate_opt : 60;
        
        $dt->modify( '-' . $rate_limit . ' seconds' );
        $window = $dt->format( 'Y-m-d H:i:s' );

        if ( ! empty( $emp_code ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE ip = %s AND emp_code = %s AND created_at >= %s",
                    $ip,
                    $emp_code,
                    $window
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE ip = %s AND created_at >= %s",
                    $ip,
                    $window
                )
            );
        }

        return $count > 0;
    }

    /**
     * Kiểm tra xem nhân viên (emp_code) đã chạy thành công (is_valid=1) link này trong ngày hôm nay chưa (00:00 - 23:59)
     */
    public static function is_link_done_today( string $emp_code, string $page_path ): bool {
        global $wpdb;
        $table = $wpdb->prefix . ST_TABLE;
        
        $dt_start = new DateTime( 'now', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
        $dt_start->setTime( 0, 0, 0 );
        $dt_end = new DateTime( 'now', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
        $dt_end->setTime( 23, 59, 59 );
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` 
                 WHERE emp_code = %s AND page_path = %s AND is_valid = 1 AND created_at BETWEEN %s AND %s",
                $emp_code,
                $page_path,
                $dt_start->format( 'Y-m-d H:i:s' ),
                $dt_end->format( 'Y-m-d H:i:s' )
            )
        );
        return $count > 0;
    }

    // ── Write ────────────────────────────────────────────────────────────────

    public static function insert_session( array $data ): int {
        global $wpdb;

        $table = $wpdb->prefix . ST_TABLE;

        $result = $wpdb->insert(
            $table,
            [
                'session_id' => sanitize_text_field( $data['session_id'] ?? '' ),
                'ip'         => sanitize_text_field( $data['ip']         ?? '' ),
                'user_agent' => sanitize_textarea_field( $data['user_agent'] ?? '' ),
                'url'        => esc_url_raw( $data['url'] ?? '' ),
                'page_path'  => sanitize_text_field( $data['page_path']  ?? '' ),
                'duration'   => absint( $data['duration']   ?? 0 ),
                'user_name'  => sanitize_text_field( $data['user_name']  ?? '' ),
                'emp_code'   => sanitize_text_field( $data['emp_code']   ?? '' ),
                'class_code' => sanitize_text_field( $data['class_code'] ?? '' ),
                'team'       => sanitize_text_field( $data['team']       ?? '' ),
                'is_valid'   => isset($data['is_valid']) ? (int) $data['is_valid'] : 0,
                'created_at' => ( new DateTime( 'now', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) ) )->format( 'Y-m-d H:i:s' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        return $result ? (int) $wpdb->insert_id : 0;
    }

    public static function insert_behavior_logs( int $log_id, string $emp_code, array $events ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'st_behavior_logs';
        $count = 0;

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
                $table,
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
                $count++;
            }
        }

        return $count;
    }

    // ── Read (paginated) ────────────────────────────────────────────────────

    public static function get_logs( array $args = [] ): array {
        global $wpdb;

        $table      = $wpdb->prefix . ST_TABLE;
        $page       = max( 1, (int) ( $args['page']     ?? 1 ) );
        $per_page   = min( 100, max( 10, (int) ( $args['per_page'] ?? 25 ) ) );
        $offset     = ( $page - 1 ) * $per_page;
        $where      = [];
        $params     = [];

        // Date range
        if ( ! empty( $args['start_date'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = sanitize_text_field( $args['start_date'] ) . ' 00:00:00';
        }
        if ( ! empty( $args['end_date'] ) ) {
            $where[]  = 'created_at <= %s';
            $params[] = sanitize_text_field( $args['end_date'] ) . ' 23:59:59';
        }

        // Staff / team / code filters
        $exact = ! empty( $args['exact_match'] ) && $args['exact_match'] !== 'false' && $args['exact_match'] !== '0';

        if ( ! empty( $args['user_name'] ) ) {
            if ( $exact ) {
                $where[]  = 'user_name = %s';
                $params[] = sanitize_text_field( $args['user_name'] );
            } else {
                $where[]  = 'user_name LIKE %s';
                $params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['user_name'] ) ) . '%';
            }
        }
        if ( ! empty( $args['emp_code'] ) ) {
            if ( $exact ) {
                $where[]  = 'emp_code = %s';
                $params[] = sanitize_text_field( $args['emp_code'] );
            } else {
                $where[]  = 'emp_code LIKE %s';
                $params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['emp_code'] ) ) . '%';
            }
        }
        if ( ! empty( $args['class_code'] ) ) {
            if ( $exact ) {
                $where[]  = 'class_code = %s';
                $params[] = sanitize_text_field( $args['class_code'] );
            } else {
                $where[]  = 'class_code LIKE %s';
                $params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['class_code'] ) ) . '%';
            }
        }
        if ( ! empty( $args['team'] ) ) {
            if ( $exact ) {
                $where[]  = 'team = %s';
                $params[] = sanitize_text_field( $args['team'] );
            } else {
                $where[]  = 'team LIKE %s';
                $params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['team'] ) ) . '%';
            }
        }

        // Valid only
        if ( isset( $args['valid'] ) && $args['valid'] !== '' ) {
            $where[]  = 'is_valid = %d';
            $params[] = (int) $args['valid'];
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Total count
        $count_sql = "SELECT COUNT(*) FROM `{$table}` {$where_sql}";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

        // Rows
        $order_by   = self::sanitize_orderby( $args['orderby'] ?? 'created_at' );
        $order      = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
        $params[]   = $per_page;
        $params[]   = $offset;

        $sql = "SELECT * FROM `{$table}` {$where_sql} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d";
        $prepared = $wpdb->prepare( $sql, $params );
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $prepared, ARRAY_A );

        return [
            'total'    => $total,
            'per_page' => $per_page,
            'page'     => $page,
            'rows'     => $rows ?: [],
        ];
    }

    // ── KPI summary ─────────────────────────────────────────────────────────

    public static function get_kpi( string $start = '', string $end = '' ): array {
        global $wpdb;

        $table  = $wpdb->prefix . ST_TABLE;
        $where  = [];
        $params = [];

        if ( $start ) {
            $where[]  = 'created_at >= %s';
            $params[] = $start . ' 00:00:00';
        }
        if ( $end ) {
            $where[]  = 'created_at <= %s';
            $params[] = $end . ' 23:59:59';
        }

        $w = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $sql = "SELECT
                    COUNT(*)                                            AS total,
                    SUM(is_valid)                                       AS valid,
                    ROUND( AVG( CASE WHEN is_valid=1 THEN duration END ), 1 ) AS avg_duration
                FROM `{$table}` {$w}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row( $params ? $wpdb->prepare( $sql, ...$params ) : $sql, ARRAY_A );

        $total    = (int) ( $row['total']        ?? 0 );
        $valid    = (int) ( $row['valid']        ?? 0 );
        $avg      = (float) ( $row['avg_duration'] ?? 0 );
        $rate     = $total > 0 ? round( $valid / $total * 100, 1 ) : 0;

        // Top staff (Gom nhóm theo emp_code duy nhất, tránh gộp trùng tên)
        $top_staff_sql = "SELECT user_name, emp_code, COUNT(*) AS cnt FROM `{$table}` {$w} " . ( $w ? "AND is_valid=1" : "WHERE is_valid=1" ) . "
                    GROUP BY emp_code, user_name ORDER BY cnt DESC LIMIT 5";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $top_staff = $wpdb->get_results( $params ? $wpdb->prepare( $top_staff_sql, ...$params ) : $top_staff_sql, ARRAY_A );

        // Top teams
        $team_sql = "SELECT team, COUNT(*) AS cnt FROM `{$table}` {$w} " . ( $w ? "AND is_valid=1" : "WHERE is_valid=1" ) . "
                    GROUP BY team ORDER BY cnt DESC LIMIT 5";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $top_teams = $wpdb->get_results( $params ? $wpdb->prepare( $team_sql, ...$params ) : $team_sql, ARRAY_A );

        // Top class
        $class_sql = "SELECT class_code, COUNT(*) AS cnt FROM `{$table}` {$w} " . ( $w ? "AND is_valid=1" : "WHERE is_valid=1" ) . "
                    GROUP BY class_code ORDER BY cnt DESC LIMIT 5";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $top_classes = $wpdb->get_results( $params ? $wpdb->prepare( $class_sql, ...$params ) : $class_sql, ARRAY_A );

        // ─── TỐI ƯU HÓA TRIỆT ĐỂ: PHÂN TÍCH XU HƯỚNG TỨC THÌ, TRÁNH TREO CSDL ───
        
        // 1. Phân tích Thiết bị & Trình duyệt: Lấy mẫu 1000 logs hợp lệ gần đây nhất bằng index primary (chỉ mất 0.001 giây)
        $ua_sql = "SELECT user_agent FROM `{$table}` WHERE is_valid = 1 ORDER BY id DESC LIMIT 1000";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ua_results = $wpdb->get_results( $ua_sql, ARRAY_A );

        $devices = [ 'Desktop' => 0, 'Mobile' => 0, 'Tablet' => 0 ];
        $browsers = [ 'Chrome' => 0, 'Safari' => 0, 'Firefox' => 0, 'Edge' => 0, 'Khác' => 0 ];

        if ( $ua_results ) {
            foreach ( $ua_results as $row ) {
                $ua = strtolower( $row['user_agent'] ?? '' );
                if ( ! $ua ) {
                    continue;
                }

                if ( str_contains( $ua, 'ipad' ) || str_contains( $ua, 'tablet' ) ) {
                    $devices['Tablet']++;
                } elseif ( str_contains( $ua, 'mobile' ) || str_contains( $ua, 'iphone' ) || str_contains( $ua, 'android' ) ) {
                    $devices['Mobile']++;
                } else {
                    $devices['Desktop']++;
                }

                if ( str_contains( $ua, 'edg/' ) || str_contains( $ua, 'edge' ) ) {
                    $browsers['Edge']++;
                } elseif ( str_contains( $ua, 'chrome' ) || str_contains( $ua, 'crios' ) ) {
                    $browsers['Chrome']++;
                } elseif ( str_contains( $ua, 'safari' ) && ! str_contains( $ua, 'chrome' ) ) {
                    $browsers['Safari']++;
                } elseif ( str_contains( $ua, 'firefox' ) ) {
                    $browsers['Firefox']++;
                } else {
                    $browsers['Khác']++;
                }
            }
        }

        // Tạo điều kiện thời gian cho 7 ngày qua đối với Top Pages và Khung giờ hoạt động
        $dt_7days = new DateTime( 'now', new DateTimeZone( 'Asia/Ho_Chi_Minh' ) );
        $dt_7days->modify( '-7 days' );
        $where_7d = [ 'created_at >= %s', 'is_valid = 1' ];
        $params_7d = [ $dt_7days->format( 'Y-m-d' ) . ' 00:00:00' ];

        // Nếu admin đang lọc khoảng thời gian cụ thể NHỎ HƠN 7 ngày, ta tôn trọng khoảng lọc của admin
        if ( $start && $end && ( strtotime($end) - strtotime($start) < 7 * DAY_IN_SECONDS ) ) {
            $where_7d = $where;
            $params_7d = $params;
            if ( $w ) {
                $where_7d[] = 'is_valid = 1';
            }
        }

        $w_7d = 'WHERE ' . implode( ' AND ', $where_7d );

        // 2. Top Pages (5 bài viết nhiều traffic nhất trong 7 ngày qua)
        $pages_sql = "SELECT page_path, COUNT(*) AS cnt FROM `{$table}` {$w_7d} GROUP BY page_path ORDER BY cnt DESC LIMIT 5";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $top_pages = $wpdb->get_results( $wpdb->prepare( $pages_sql, ...$params_7d ), ARRAY_A );

        // 3. Khung giờ hoạt động cao điểm (trong 7 ngày qua)
        $hour_sql = "SELECT HOUR(created_at) AS hr, COUNT(*) AS cnt FROM `{$table}` {$w_7d} GROUP BY HOUR(created_at) ORDER BY hr ASC";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $hours_raw = $wpdb->get_results( $wpdb->prepare( $hour_sql, ...$params_7d ), ARRAY_A );

        $hourly_periods = [
            'Sáng (06:00 - 12:00)'  => 0,
            'Chiều (12:00 - 18:00)' => 0,
            'Tối (18:00 - 24:00)'   => 0,
            'Đêm (00:00 - 06:00)'   => 0,
        ];
        if ( $hours_raw ) {
            foreach ( $hours_raw as $r ) {
                $hr  = (int) $r['hr'];
                $cnt = (int) $r['cnt'];
                if ( $hr >= 6 && $hr < 12 ) {
                    $hourly_periods['Sáng (06:00 - 12:00)'] += $cnt;
                } elseif ( $hr >= 12 && $hr < 18 ) {
                    $hourly_periods['Chiều (12:00 - 18:00)'] += $cnt;
                } elseif ( $hr >= 18 && $hr < 24 ) {
                    $hourly_periods['Tối (18:00 - 24:00)'] += $cnt;
                } else {
                    $hourly_periods['Đêm (00:00 - 06:00)'] += $cnt;
                }
            }
        }

        return compact(
            'total', 'valid', 'rate', 'avg',
            'top_staff', 'top_teams', 'top_classes',
            'top_pages', 'devices', 'browsers', 'hourly_periods'
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    public static function clear_kpi_cache(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_st_kpi_v2_%' OR `option_name` LIKE '_transient_st_kpi_%'" );
    }

    public static function get_assign_rules_at_time( array $current_assign, string $datetime ): array {
        $history = get_option( 'st_assign_history_' . $current_assign['id'], [] );
        if ( empty( $history ) ) {
            return $current_assign;
        }
        ksort( $history );
        foreach ( $history as $end_date => $rules ) {
            if ( $datetime <= $end_date ) {
                return array_merge( $current_assign, $rules );
            }
        }
        return $current_assign;
    }

    public static function get_daily_random_paths( array $assign_rules, string $date_str, string $emp_code ): array {
        global $wpdb;
        $table_personnel = $wpdb->prefix . 'staff_tracking_personnel';
        
        $allowed_paths_raw = $assign_rules['allowed_paths'] ?? '';
        if ( empty( $allowed_paths_raw ) ) return [];
        
        $paths = array_filter( array_map( 'trim', explode( "\n", $allowed_paths_raw ) ) );
        if ( empty( $paths ) ) return [];
        
        // 1. Get assigned staff
        $scope = $assign_rules['assign_scope'] ?? 'specific';
        $type  = $assign_rules['target_type'] ?? '';
        $value = $assign_rules['target_value'] ?? '';
        
        $staff_list = [];
        if ( 'general' === $scope ) {
            $staff_list = $wpdb->get_col( "SELECT emp_code FROM `{$table_personnel}` WHERE is_active = 1" );
        } else {
            $where = [ 'is_active = 1' ];
            $args = [];
            if ( $type === 'emp_code' || $type === 'class_code' || $type === 'team' ) {
                $vals = array_filter( array_map( 'trim', explode( ',', $value ) ) );
                if ( ! empty( $vals ) ) {
                    $placeholders = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
                    $where[] = "`{$type}` IN ($placeholders)";
                    $args = array_merge( $args, $vals );
                }
            }
            if ( empty( $args ) ) return [];
            $where_sql = implode( ' AND ', $where );
            $staff_list = $wpdb->get_col( $wpdb->prepare( "SELECT emp_code FROM `{$table_personnel}` WHERE {$where_sql}", ...$args ) );
        }
        
        if ( empty( $staff_list ) ) return [];
        
        // Clean and sort staff list deterministically
        $staff_list = array_values( array_unique( array_map( 'trim', $staff_list ) ) );
        sort( $staff_list );
        
        $staff_index = array_search( $emp_code, $staff_list, true );
        if ( $staff_index === false ) return [];
        
        $num_staff = count( $staff_list );
        $num_paths = count( $paths );
        
        // Sort paths deterministically
        sort( $paths );
        
        // Pseudo-random shuffle seeded by assignment ID + date
        $date_only = substr( $date_str, 0, 10 );
        $seed_str = ( $assign_rules['id'] ?? '0' ) . '_' . $date_only;
        
        // Fisher-Yates shuffle with md5 PRNG
        $shuffled = $paths;
        for ( $i = $num_paths - 1; $i > 0; $i-- ) {
            $hash = md5( $seed_str . '_' . $i );
            $rand_int = hexdec( substr( $hash, 0, 8 ) ) % ( $i + 1 );
            
            $temp = $shuffled[$i];
            $shuffled[$i] = $shuffled[$rand_int];
            $shuffled[$rand_int] = $temp;
        }
        
        // Divide paths evenly
        $base_count = (int) floor( $num_paths / $num_staff );
        $remainder  = $num_paths % $num_staff;
        
        $start_index = 0;
        $my_count = $base_count;
        
        for ( $i = 0; $i < $staff_index; $i++ ) {
            $add = ( $i < $remainder ) ? 1 : 0;
            $start_index += ( $base_count + $add );
        }
        if ( $staff_index < $remainder ) {
            $my_count++;
        }
        
        return array_slice( $shuffled, $start_index, $my_count );
    }

    public static function calc_staff_assignment_stats( $emp_code, $assign ) {
        global $wpdb;
        $table_logs = $wpdb->prefix . ST_TABLE;
        list( $start_date, $end_date ) = self::get_period_dates( $assign['period_type'], $assign['period_value'] );
        
        $sql = $wpdb->prepare(
            "SELECT page_path, created_at, duration FROM `{$table_logs}` 
             WHERE emp_code = %s AND is_valid = 1 AND created_at BETWEEN %s AND %s AND created_at >= %s",
            $emp_code, $start_date . ' 00:00:00', $end_date . ' 23:59:59', $assign['created_at']
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $logs = $wpdb->get_results( $sql, ARRAY_A );
        
        $achieved = 0;
        $total_dur = 0;

        foreach ( $logs as $log ) {
            $path = $log['page_path'];
            $created = $log['created_at']; // e.g. "2026-08-01 15:30:00"
            $dur = (float)$log['duration'];
            $is_valid_path = false;

            $effective_assign = self::get_assign_rules_at_time( $assign, $created );
            $sel = $effective_assign['product_selection'] ?? 'all';
            
            $phases = [];
            if ( 'phases' === $sel && ! empty( $effective_assign['phases_data'] ) ) {
                $p_dec = json_decode( $effective_assign['phases_data'], true );
                if ( is_array( $p_dec ) ) $phases = $p_dec;
            } else {
                $allowed_arr = [];
                if ( 'all' !== $sel && ! empty( $effective_assign['allowed_paths'] ) ) {
                    $allowed_arr = array_filter( array_map( 'trim', explode( "\n", $effective_assign['allowed_paths'] ) ) );
                }
            }

            if ( 'phases' === $sel ) {
                $p_found = false;
                foreach ( $phases as $p ) {
                    $p_start = !empty($p['start_date']) ? $p['start_date'] . ' 00:00:00' : $start_date . ' 00:00:00';
                    $p_end   = !empty($p['end_date'])   ? $p['end_date'] . ' 23:59:59'   : $end_date . ' 23:59:59';
                    if ( $created >= $p_start && $created <= $p_end ) {
                        $p_sel = $p['product_selection'] ?? 'all';
                        if ( 'all' === $p_sel ) {
                            $is_valid_path = true;
                        } else {
                            $p_allowed = !empty($p['allowed_paths']) ? array_filter( array_map( 'trim', explode( "\n", $p['allowed_paths'] ) ) ) : [];
                            if ( in_array( $path, $p_allowed, true ) || in_array( rtrim($path,'/').'/', $p_allowed, true ) ) {
                                $is_valid_path = true;
                            }
                        }
                        $p_found = true;
                        break;
                    }
                }
                if ( !$p_found ) $is_valid_path = false;
            } else {
                if ( 'all' === $sel ) {
                    $is_valid_path = true;
                } elseif ( 'random_distribution' === $sel ) {
                    $random_paths = self::get_daily_random_paths( $effective_assign, $created, $emp_code );
                    if ( in_array( $path, $random_paths, true ) || in_array( rtrim($path,'/').'/', $random_paths, true ) ) {
                        $is_valid_path = true;
                    }
                } else {
                    if ( in_array( $path, $allowed_arr, true ) || in_array( rtrim($path,'/').'/', $allowed_arr, true ) ) {
                        $is_valid_path = true;
                    }
                }
            }

            if ( $is_valid_path ) {
                $achieved++;
                $total_dur += $dur;
            }
        }

        $avg_dur = $achieved > 0 ? $total_dur / $achieved : 0;

        return [
            'achieved' => $achieved,
            'avg_dur'  => round($avg_dur, 1)
        ];
    }

    public static function calc_staff_assignment_daily_stats( string $emp_code, array $assign ): array {
        global $wpdb;
        $table_logs = $wpdb->prefix . ST_TABLE;
        
        list( $start_date, $end_date ) = self::get_period_dates( $assign['period_type'], $assign['period_value'] );
        if ( empty( $start_date ) || empty( $end_date ) ) {
            return [];
        }
        
        $sql = $wpdb->prepare(
            "SELECT page_path, created_at, duration FROM `{$table_logs}` 
             WHERE emp_code = %s AND is_valid = 1 AND created_at BETWEEN %s AND %s AND created_at >= %s",
            $emp_code, $start_date . ' 00:00:00', $end_date . ' 23:59:59', $assign['created_at']
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $logs = $wpdb->get_results( $sql, ARRAY_A );

        $daily_stats = [];
        $start_dt = new DateTime( $start_date );
        $end_dt   = new DateTime( $end_date );
        $today_dt = new DateTime( 'now', new DateTimeZone('Asia/Ho_Chi_Minh') );
        $today_str = $today_dt->format('Y-m-d');
        
        // Cắt bớt end_date không vượt quá hôm nay nếu chu kỳ ở tương lai
        if ( $end_dt > $today_dt ) {
            // Vẫn show đủ ngày, nhưng những ngày tương lai sẽ không tính là "missed"
        }

        // Tạo mảng từng ngày
        $interval = new DateInterval('P1D');
        $period   = new DatePeriod( $start_dt, $interval, $end_dt->modify('+1 day') );

        foreach ( $period as $dt ) {
            $d_str = $dt->format('Y-m-d');
            
            $effective_assign = self::get_assign_rules_at_time( $assign, $d_str . ' 23:59:59' );
            $sel = $effective_assign['product_selection'] ?? 'all';
            
            $phases = [];
            if ( 'phases' === $sel && ! empty( $effective_assign['phases_data'] ) ) {
                $p_dec = json_decode( $effective_assign['phases_data'], true );
                if ( is_array( $p_dec ) ) $phases = $p_dec;
            } else {
                $allowed_arr = [];
                if ( 'all' !== $sel && ! empty( $effective_assign['allowed_paths'] ) ) {
                    $allowed_arr = array_filter( array_map( 'trim', explode( "\n", $effective_assign['allowed_paths'] ) ) );
                }
            }

            // Tìm target
            $target = 0;
            $paths_for_day = [];
            $is_holiday = false;
            
            $holiday_note = '';
            
            // Check for holidays
            $holidays = [];
            if ( ! empty( $effective_assign['holidays'] ) ) {
                $h_dec = json_decode( $effective_assign['holidays'], true );
                if ( is_array( $h_dec ) ) $holidays = $h_dec;
            }
            
            foreach ( $holidays as $h ) {
                if ( $h['date'] === $d_str ) {
                    $is_holiday = true;
                    $holiday_note = $h['note'] ?? '';
                    break;
                }
            }

            if ( $is_holiday ) {
                $target = 0; // Nghỉ lễ không cần traffic
            } else {
                if ( 'phases' === $sel ) {
                    foreach ( $phases as $p ) {
                        $p_start = !empty($p['start_date']) ? $p['start_date'] : $start_date;
                        $p_end   = !empty($p['end_date'])   ? $p['end_date']   : $end_date;
                        if ( $d_str >= $p_start && $d_str <= $p_end ) {
                            $p_sel = $p['product_selection'] ?? 'all';
                            if ( 'all' !== $p_sel && !empty($p['allowed_paths']) ) {
                                $paths_for_day = array_filter( array_map( 'trim', explode( "\n", $p['allowed_paths'] ) ) );
                                $target = count( $paths_for_day );
                            }
                            break;
                        }
                    }
                } elseif ( 'random_distribution' === $sel ) {
                    $paths_for_day = self::get_daily_random_paths( $effective_assign, $d_str, $emp_code );
                    $target = count( $paths_for_day );
                } elseif ( 'all' !== $sel && !empty($allowed_arr) ) {
                    $paths_for_day = $allowed_arr;
                    $target = count( $paths_for_day );
                }
            }

            // Đếm log cho ngày này
            $achieved = 0;
            $total_dur = 0;
            foreach ( $logs as $log ) {
                $created = $log['created_at']; // e.g. 2026-08-01 10:00:00
                if ( strpos( $created, $d_str ) === 0 ) {
                    $path = $log['page_path'];
                    $dur = (float)$log['duration'];
                    $is_valid_path = false;

                    if ( 'all' === $sel || ( 'phases' === $sel && empty($paths_for_day) ) ) {
                        $is_valid_path = true; // All or phase with all
                    } else {
                        if ( in_array( $path, $paths_for_day, true ) || in_array( rtrim($path,'/').'/', $paths_for_day, true ) ) {
                            $is_valid_path = true;
                        }
                    }

                    if ( $is_valid_path ) {
                        $achieved++;
                        $total_dur += $dur;
                    }
                }
            }

            $daily_stats[] = [
                'date'         => $d_str,
                'target'       => $target,
                'achieved'     => $achieved,
                'avg_dur'      => $achieved > 0 ? round($total_dur / $achieved, 1) : 0,
                'is_past'      => $d_str < $today_str,
                'is_today'     => $d_str === $today_str,
                'is_sunday'    => $dt->format('w') === '0', // 0 is Sunday
                'is_holiday'   => $is_holiday,
                'holiday_note' => $holiday_note,
            ];
        }

        return $daily_stats;
    }

    public static function bump_assignment_version(): void {
        $ver = (int) get_option( 'st_assign_version', 0 );
        update_option( 'st_assign_version', $ver + 1 );
    }

    private static function sanitize_orderby( string $col ): string {
        $allowed = [ 'id', 'created_at', 'user_name', 'team', 'duration', 'ip', 'is_valid' ];
        return in_array( $col, $allowed, true ) ? "`{$col}`" : '`created_at`';
    }

    public static function get_distinct_teams(): array {
        global $wpdb;
        $table = $wpdb->prefix . ST_TABLE;
        $table_personnel = $wpdb->prefix . 'st_personnel';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $sql = "SELECT team FROM `{$table}` WHERE team != '' 
                UNION 
                SELECT team FROM `{$table_personnel}` WHERE team != ''";
        $results = $wpdb->get_col( $sql );

        $default_teams = [
            'Công nghệ thông tin (IT)',
            'Khoa học máy tính',
            'Kỹ thuật phần mềm',
            'Quản trị kinh doanh',
            'Kế toán - Kiểm toán',
            'Tài chính - Ngân hàng',
            'Marketing',
            'Truyền thông đa phương tiện',
            'Báo chí',
            'Logistics và Quản lý chuỗi cung ứng',
            'Kinh tế quốc tế / Xuất nhập khẩu',
            'Ngôn ngữ Anh',
            'Ngôn ngữ Trung Quốc',
            'Ngôn ngữ Nhật',
            'Ngôn ngữ Hàn Quốc',
            'Y khoa',
            'Dược học',
            'Điều dưỡng',
            'Răng Hàm Mặt',
            'Kỹ thuật ô tô',
            'Kỹ thuật điện, điện tử',
            'Kỹ thuật cơ điện tử',
            'Kỹ thuật điều khiển và tự động hóa',
            'Công nghệ thực phẩm',
            'Kiến trúc',
            'Kỹ thuật xây dựng',
            'Thiết kế đồ họa',
            'Thiết kế nội thất',
            'Du lịch - Khách sạn',
            'Luật kinh tế',
            'Luật học',
            'Sư phạm',
            'Nông nghiệp và Phát triển nông thôn',
            'Tâm lý học',
            'Quản trị nhân sự',
            'Quan hệ công chúng (PR)',
            'Thương mại điện tử',
            'Khác'
        ];
        return array_values( array_unique( array_merge( $results ?: [], $default_teams ) ) );
    }

    public static function get_distinct_classes(): array {
        global $wpdb;
        $table = $wpdb->prefix . ST_TABLE;
        $table_personnel = $wpdb->prefix . 'st_personnel';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $sql = "SELECT class_code FROM `{$table}` WHERE class_code != '' 
                UNION 
                SELECT class_code FROM `{$table_personnel}` WHERE class_code != ''";
        $results = $wpdb->get_col( $sql );
        
        if ( ! empty( $results ) ) {
            sort( $results );
        }
        return $results ?: [];
    }

    public static function get_distinct_personnel(): array {
        global $wpdb;
        $table = $wpdb->prefix . ST_TABLE;
        $table_personnel = $wpdb->prefix . 'st_personnel';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $sql = "SELECT emp_code, MAX(user_name) as user_name FROM (
                    SELECT emp_code, user_name FROM `{$table}` WHERE emp_code != '' 
                    UNION 
                    SELECT emp_code, user_name FROM `{$table_personnel}` WHERE emp_code != ''
                ) as t GROUP BY emp_code ORDER BY emp_code ASC";
        $results = $wpdb->get_results( $sql, ARRAY_A );
        
        return $results ?: [];
    }

    public static function get_period_dates( string $type, string $val ): array {
        $start = '';
        $end   = '';
        if ( 'day' === $type ) {
            // Check if format is DD/MM/YYYY
            if ( str_contains( $val, '/' ) ) {
                $dto = DateTime::createFromFormat( 'd/m/Y', $val );
                if ( $dto ) {
                    $val = $dto->format( 'Y-m-d' );
                }
            }
            $start = $val;
            $end   = $val;
        } elseif ( 'week' === $type ) {
            if ( preg_match( '/^(\d{4})-W(\d{2})$/', $val, $m ) ) {
                $year = intval( $m[1] );
                $week = intval( $m[2] );
                $dto = new DateTime();
                $dto->setISODate( $year, $week );
                $start = $dto->format( 'Y-m-d' );
                $dto->modify( '+6 days' );
                $end = $dto->format( 'Y-m-d' );
            }
        } elseif ( 'month' === $type ) {
            $start = $val . '-01';
            $end   = date( 'Y-m-t', strtotime( $start ) );
        } elseif ( 'year' === $type ) {
            $start = $val . '-01-01';
            $end   = $val . '-12-31';
        }
        return [ $start, $end ];
    }

    public static function create_payout_records( string $date = '' ): int {
        global $wpdb;
        $table_logs    = $wpdb->prefix . ST_TABLE;
        $table_assign  = $wpdb->prefix . 'st_assignments';
        $table_payouts = $wpdb->prefix . 'st_payouts';

        if ( empty( $date ) ) {
            $date = date( 'Y-m-d', strtotime( '-1 day' ) );
        }

        $payout_rate = (float) get_option( 'st_payout_per_kpi', 0 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assignments = $wpdb->get_results( "SELECT * FROM `{$table_assign}`", ARRAY_A );
        if ( empty( $assignments ) ) {
            return 0;
        }

        $records_count = 0;

        foreach ( $assignments as $assign ) {
            list( $start_date, $end_date ) = self::get_period_dates( $assign['period_type'], $assign['period_value'] );
            if ( empty( $start_date ) || empty( $end_date ) ) {
                continue;
            }

            if ( $end_date === $date ) {
                $sql_staff = $wpdb->prepare(
                    "SELECT emp_code, user_name, class_code, team 
                     FROM `{$table_logs}` 
                     WHERE is_valid = 1 AND created_at BETWEEN %s AND %s
                     GROUP BY emp_code, user_name, class_code, team",
                    $start_date . ' 00:00:00',
                    $end_date . ' 23:59:59'
                );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $staff_list = $wpdb->get_results( $sql_staff, ARRAY_A );
                if ( empty( $staff_list ) ) {
                    continue;
                }

                foreach ( $staff_list as $staff ) {
                    $matched = false;
                    $scope   = $assign['assign_scope'] ?? 'specific';
                    if ( 'general' === $scope ) {
                        $matched = true;
                    } else {
                        $t_type = $assign['target_type'] ?? '';
                        $t_val  = $assign['target_value'] ?? '';
                        $t_vals = array_filter( array_map( 'trim', explode( ',', strtolower( $t_val ) ) ) );
                        if ( 'emp_code' === $t_type && in_array( strtolower( $staff['emp_code'] ), $t_vals, true ) ) {
                            $matched = true;
                        } elseif ( 'class_code' === $t_type && in_array( strtolower( $staff['class_code'] ), $t_vals, true ) ) {
                            $matched = true;
                        } elseif ( 'team' === $t_type && in_array( strtolower( $staff['team'] ), $t_vals, true ) ) {
                            $matched = true;
                        }
                    }

                    if ( ! $matched ) {
                        continue;
                    }

                    $stats = self::calc_staff_assignment_stats( $staff['emp_code'], $assign );
                    $achieved = $stats['achieved'];

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $existing = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id, status FROM `{$table_payouts}` 
                         WHERE emp_code = %s AND assignment_id = %d AND period_value = %s",
                        $staff['emp_code'],
                        $assign['id'],
                        $assign['period_value']
                    ), ARRAY_A );

                    if ( $achieved > 0 ) {
                        $payout_amount = $achieved * $payout_rate;
                        if ( $existing ) {
                            if ( 'paid' === $existing['status'] ) {
                                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                                $wpdb->update(
                                    $table_payouts,
                                    [ 'kpi_achieved' => $achieved ],
                                    [ 'id' => $existing['id'] ],
                                    [ '%d' ],
                                    [ '%d' ]
                                );
                            } else {
                                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                                $wpdb->update(
                                    $table_payouts,
                                    [
                                        'user_name'    => $staff['user_name'],
                                        'kpi_target'   => $assign['kpi_target'],
                                        'kpi_achieved' => $achieved,
                                        'amount'       => $payout_amount,
                                    ],
                                    [ 'id' => $existing['id'] ],
                                    [ '%s', '%d', '%d', '%f' ],
                                    [ '%d' ]
                                );
                            }
                            $payout_id = $existing['id'];
                        } else {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                            $wpdb->insert(
                                $table_payouts,
                                [
                                    'emp_code'      => $staff['emp_code'],
                                    'user_name'     => $staff['user_name'],
                                    'assignment_id' => $assign['id'],
                                    'period_type'   => $assign['period_type'],
                                    'period_value'  => $assign['period_value'],
                                    'kpi_target'    => $assign['kpi_target'],
                                    'kpi_achieved'  => $achieved,
                                    'amount'        => $payout_amount,
                                    'status'        => 'unpaid',
                                    'created_at'    => current_time( 'mysql' )
                                ],
                                [ '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s' ]
                            );
                            $payout_id = $wpdb->insert_id;
                        }

                        // Đồng bộ sang ví
                        ST_Wallet_Service::sync_payout_to_wallet( $payout_id );

                        $records_count++;
                    } else {
                        if ( $existing && 'unpaid' === $existing['status'] ) {
                            // Cập nhật số tiền về 0 trước để ví đồng bộ giảm tiền
                            $wpdb->update( $table_payouts, [ 'amount' => 0.0 ], [ 'id' => $existing['id'] ], [ '%f' ], [ '%d' ] );
                            ST_Wallet_Service::sync_payout_to_wallet( $existing['id'] );
                            // Sau đó mới xóa bản ghi payout
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                            $wpdb->delete( $table_payouts, [ 'id' => $existing['id'] ], [ '%d' ] );
                        }
                    }
                }
            }
        }

        return $records_count;
    }

    public static function get_payouts( array $args = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'st_payouts';

        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page = min( 100, max( 10, (int) ( $args['per_page'] ?? 20 ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['status'] ) && in_array( $args['status'], [ 'unpaid', 'paid' ], true ) ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        if ( ! empty( $args['emp_code'] ) ) {
            $where[]  = '(emp_code LIKE %s OR user_name LIKE %s)';
            $like = '%' . $wpdb->esc_like( sanitize_text_field( $args['emp_code'] ) ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ( ! empty( $args['period_value'] ) ) {
            $where[]  = 'period_value = %s';
            $params[] = sanitize_text_field( $args['period_value'] );
        }

        $where_sql = implode( ' AND ', $where );

        $count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

        $params[] = $per_page;
        $params[] = $offset;
        $sql = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        $sum_unpaid_sql = "SELECT SUM(amount) FROM `{$table}` WHERE status = 'unpaid'";
        $sum_paid_sql   = "SELECT SUM(amount) FROM `{$table}` WHERE status = 'paid'";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total_unpaid = (float) $wpdb->get_var( $sum_unpaid_sql );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total_paid   = (float) $wpdb->get_var( $sum_paid_sql );

        return [
            'total'        => $total,
            'per_page'     => $per_page,
            'page'         => $page,
            'rows'         => $rows ?: [],
            'total_unpaid' => $total_unpaid,
            'total_paid'   => $total_paid
        ];
    }

    public static function mark_payout_paid( int $id, string $sepay_tx_id = '' ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'st_payouts';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $current_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id = %d", $id ) );
        if ( 'paid' === $current_status ) {
            return true;
        }

        $data = [
            'status'  => 'paid',
            'paid_at' => current_time( 'mysql' )
        ];
        $format = [ '%s', '%s' ];

        if ( ! empty( $sepay_tx_id ) ) {
            $data['sepay_transaction_id'] = $sepay_tx_id;
            $format[] = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $success = $wpdb->update(
            $table,
            $data,
            [ 'id' => $id ],
            $format,
            [ '%d' ]
        );

        if ( false !== $success ) {
            ST_Wallet_Service::sync_payout_to_wallet( $id );
        }

        return false !== $success;
    }

    public static function mark_payout_refunded( int $id, string $sepay_tx_id = '' ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'st_payouts';

        $data = [
            'status'  => 'refunded',
            'paid_at' => null
        ];
        $format = [ '%s', '%s' ];

        if ( ! empty( $sepay_tx_id ) ) {
            $data['sepay_transaction_id'] = 'REFUNDED_' . $sepay_tx_id;
            $format[] = '%s';
        }

        $success = $wpdb->update(
            $table,
            $data,
            [ 'id' => $id ],
            $format,
            [ '%d' ]
        );

        return (bool) $success;
    }

    public static function calculate_single_assignment_payout( int $assignment_id ): int {
        global $wpdb;
        $table_logs    = $wpdb->prefix . ST_TABLE;
        $table_assign  = $wpdb->prefix . 'st_assignments';
        $table_payouts = $wpdb->prefix . 'st_payouts';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table_assign}` WHERE id = %d", $assignment_id ), ARRAY_A );
        if ( ! $assign ) {
            return 0;
        }

        list( $start_date, $end_date ) = self::get_period_dates( $assign['period_type'], $assign['period_value'] );
        if ( empty( $start_date ) || empty( $end_date ) ) {
            return 0;
        }

        $payout_rate = (float) get_option( 'st_payout_per_kpi', 0 );
        $records_count = 0;

        $sql_staff = $wpdb->prepare(
            "SELECT emp_code, user_name, class_code, team 
             FROM `{$table_logs}` 
             WHERE is_valid = 1 AND created_at BETWEEN %s AND %s
             GROUP BY emp_code, user_name, class_code, team",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $staff_list = $wpdb->get_results( $sql_staff, ARRAY_A );
        if ( empty( $staff_list ) ) {
            return 0;
        }

        foreach ( $staff_list as $staff ) {
            $matched = false;
            $scope   = $assign['assign_scope'] ?? 'specific';
            if ( 'general' === $scope ) {
                $matched = true;
            } else {
                $t_type = $assign['target_type'] ?? '';
                $t_val  = $assign['target_value'] ?? '';
                $t_vals = array_filter( array_map( 'trim', explode( ',', strtolower( $t_val ) ) ) );
                if ( 'emp_code' === $t_type && in_array( strtolower( $staff['emp_code'] ), $t_vals, true ) ) {
                    $matched = true;
                } elseif ( 'class_code' === $t_type && in_array( strtolower( $staff['class_code'] ), $t_vals, true ) ) {
                    $matched = true;
                } elseif ( 'team' === $t_type && in_array( strtolower( $staff['team'] ), $t_vals, true ) ) {
                    $matched = true;
                }
            }

            if ( ! $matched ) {
                continue;
            }

            $stats = self::calc_staff_assignment_stats( $staff['emp_code'], $assign );
            $achieved = $stats['achieved'];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, status FROM `{$table_payouts}` 
                 WHERE emp_code = %s AND assignment_id = %d AND period_value = %s",
                $staff['emp_code'],
                $assign['id'],
                $assign['period_value']
            ), ARRAY_A );

            if ( $achieved > 0 ) {
                $payout_amount = $achieved * $payout_rate;
                if ( $existing ) {
                    if ( 'paid' === $existing['status'] ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->update(
                            $table_payouts,
                            [ 'kpi_achieved' => $achieved ],
                            [ 'id' => $existing['id'] ],
                            [ '%d' ],
                            [ '%d' ]
                        );
                    } else {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->update(
                            $table_payouts,
                            [
                                'user_name'    => $staff['user_name'],
                                'kpi_target'   => $assign['kpi_target'],
                                'kpi_achieved' => $achieved,
                                'amount'       => $payout_amount,
                            ],
                            [ 'id' => $existing['id'] ],
                            [ '%s', '%d', '%d', '%f' ],
                            [ '%d' ]
                        );
                    }
                    $payout_id = $existing['id'];
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->insert(
                        $table_payouts,
                        [
                            'emp_code'      => $staff['emp_code'],
                            'user_name'     => $staff['user_name'],
                            'assignment_id' => $assign['id'],
                            'period_type'   => $assign['period_type'],
                            'period_value'  => $assign['period_value'],
                            'kpi_target'    => $assign['kpi_target'],
                            'kpi_achieved'  => $achieved,
                            'amount'        => $payout_amount,
                            'status'        => 'unpaid',
                            'created_at'    => current_time( 'mysql' )
                        ],
                        [ '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s' ]
                    );
                    $payout_id = $wpdb->insert_id;
                }

                // Đồng bộ sang ví
                ST_Wallet_Service::sync_payout_to_wallet( $payout_id );

                $records_count++;
            } else {
                if ( $existing && 'unpaid' === $existing['status'] ) {
                    // Cập nhật số tiền về 0 trước để ví đồng bộ giảm tiền
                    $wpdb->update( $table_payouts, [ 'amount' => 0.0 ], [ 'id' => $existing['id'] ], [ '%f' ], [ '%d' ] );
                    ST_Wallet_Service::sync_payout_to_wallet( $existing['id'] );
                    // Sau đó mới xóa bản ghi payout
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->delete( $table_payouts, [ 'id' => $existing['id'] ], [ '%d' ] );
                }
            }
        }

        return $records_count;
    }
}
