<?php
defined( 'ABSPATH' ) || exit;

class ST_Wallet_Service {

    /**
     * Lấy hoặc tự động tạo ví mới cho emp_code.
     * Sử dụng transactions để bảo toàn tính nhất quán.
     */
    public static function get_or_create_wallet( string $emp_code ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'staff_tracking_wallets';

        if ( empty( $emp_code ) ) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wallet = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE emp_code = %s",
            $emp_code
        ), ARRAY_A );

        if ( $wallet ) {
            return $wallet;
        }

        // Tạo mới ví nếu chưa có
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert(
            $table,
            [
                'emp_code'          => $emp_code,
                'available_balance' => 0,
                'held_balance'      => 0,
                'version'           => 0,
                'created_at'        => current_time( 'mysql' ),
                'updated_at'        => current_time( 'mysql' )
            ],
            [ '%s', '%f', '%f', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE emp_code = %s",
            $emp_code
        ), ARRAY_A );
    }

    /**
     * Lấy WordPress User ID tương ứng với emp_code dựa trên User Meta (giữ lại tương thích).
     */
    public static function get_user_id_by_emp_code( string $emp_code ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $user_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM `{$wpdb->usermeta}` WHERE meta_key = 'st_emp_code' AND meta_value = %s LIMIT 1",
            $emp_code
        ) );
        return $user_id;
    }

    /**
     * Đồng bộ hóa số tiền của bản ghi Payout với Ví khả dụng của nhân viên.
     */
    public static function sync_payout_to_wallet( int $payout_id ): void {
        global $wpdb;
        $table_payouts = $wpdb->prefix . 'st_payouts';
        $table_txs     = $wpdb->prefix . 'staff_tracking_wallet_transactions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $payout = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table_payouts}` WHERE id = %d",
            $payout_id
        ), ARRAY_A );

        if ( ! $payout ) {
            return;
        }

        $emp_code = $payout['emp_code'];
        if ( empty( $emp_code ) ) {
            return;
        }
        $amount = ( 'paid' === $payout['status'] ) ? (float) $payout['amount'] : 0.0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rewarded = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(amount) FROM `{$table_txs}` 
             WHERE emp_code = %s AND transaction_type = 'task_reward' AND reference_type = 'payout' AND reference_id = %s",
            $emp_code,
            (string) $payout_id
        ) );

        $diff = $amount - $rewarded;
        if ( $diff > 0 ) {
            self::add_reward(
                $emp_code,
                $diff,
                'payout',
                (string) $payout_id,
                sprintf( 'Cộng thưởng KPI nhiệm vụ #%d chu kỳ %s', $payout['assignment_id'], $payout['period_value'] )
            );
        } elseif ( $diff < 0 ) {
            $abs_diff = abs( $diff );
            self::deduct_reward_diff(
                $emp_code,
                $abs_diff,
                'payout',
                (string) $payout_id,
                sprintf( 'Khấu trừ chênh lệch KPI nhiệm vụ #%d chu kỳ %s', $payout['assignment_id'], $payout['period_value'] )
            );
        }
    }

    /**
     * Thực hiện cộng tiền hoa hồng vào ví khả dụng (available_balance).
     * Bảng st_wallet_transactions có unique key chống ghi nhận trùng lặp.
     */
    public static function add_reward( string $emp_code, float $amount, string $ref_type, string $ref_id, string $description = '' ): bool {
        global $wpdb;

        if ( empty( $emp_code ) || $amount <= 0 ) {
            return false;
        }

        $table_wallets = $wpdb->prefix . 'staff_tracking_wallets';
        $table_txs     = $wpdb->prefix . 'staff_tracking_wallet_transactions';

        $wpdb->query( 'START TRANSACTION' );

        try {
            // 1. Lấy và khóa dòng ví bằng SELECT ... FOR UPDATE
            $wallet = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, available_balance, held_balance, version FROM `{$table_wallets}` WHERE emp_code = %s FOR UPDATE",
                $emp_code
            ), ARRAY_A );

            if ( ! $wallet ) {
                self::get_or_create_wallet( $emp_code );
                $wallet = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, available_balance, held_balance, version FROM `{$table_wallets}` WHERE emp_code = %s FOR UPDATE",
                    $emp_code
                ), ARRAY_A );
            }

            if ( ! $wallet ) {
                throw new RuntimeException( 'Không thể tạo hoặc tải ví cho emp_code: ' . $emp_code );
            }

            $wallet_id = (int) $wallet['id'];

            // 2. Chống trùng lặp giao dịch (Idempotency check)
            $existing_tx = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `{$table_txs}` 
                 WHERE emp_code = %s AND transaction_type = 'task_reward' AND reference_type = %s AND reference_id = %s",
                $emp_code,
                $ref_type,
                $ref_id
            ) );

            if ( $existing_tx ) {
                // Đã cộng thưởng rồi -> skip (commit để giải phóng khóa)
                $wpdb->query( 'COMMIT' );
                return true;
            }

            // 3. Tính toán số dư mới
            $new_avail = (float) $wallet['available_balance'] + $amount;
            $new_held  = (float) $wallet['held_balance'];
            $new_ver   = (int) $wallet['version'] + 1;

            // 4. Cập nhật ví với version check (optimistic + pessimistic mix)
            $updated = $wpdb->update(
                $table_wallets,
                [
                    'available_balance' => $new_avail,
                    'version'           => $new_ver,
                    'updated_at'        => current_time( 'mysql' )
                ],
                [ 'id' => $wallet_id, 'version' => $wallet['version'] ],
                [ '%f', '%d', '%s' ],
                [ 'id' => '%d', 'version' => '%d' ]
            );

            if ( $updated === false || $updated === 0 ) {
                throw new RuntimeException( 'Cập nhật số dư ví thất bại hoặc lỗi đồng thời.' );
            }

            // 5. Ghi sổ transaction ledger
            $tx_inserted = $wpdb->insert(
                $table_txs,
                [
                    'wallet_id'               => $wallet_id,
                    'emp_code'                => $emp_code,
                    'transaction_type'        => 'task_reward',
                    'amount'                  => $amount,
                    'available_balance_after' => $new_avail,
                    'held_balance_after'      => $new_held,
                    'reference_type'          => $ref_type,
                    'reference_id'            => $ref_id,
                    'description'             => $description,
                    'created_at'              => current_time( 'mysql' )
                ],
                [ '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s' ]
            );

            if ( ! $tx_inserted ) {
                throw new RuntimeException( 'Ghi nhận lịch sử giao dịch ví thất bại.' );
            }

            $wpdb->query( 'COMMIT' );
            return true;

        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'ST_Wallet_Service::add_reward error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Khấu trừ chênh lệch tiền hoa hồng thừa khỏi ví khả dụng (available_balance).
     */
    public static function deduct_reward_diff( string $emp_code, float $amount, string $ref_type, string $ref_id, string $description = '' ): bool {
        global $wpdb;

        if ( empty( $emp_code ) || $amount <= 0 ) {
            return false;
        }

        $table_wallets = $wpdb->prefix . 'staff_tracking_wallets';
        $table_txs     = $wpdb->prefix . 'staff_tracking_wallet_transactions';

        $wpdb->query( 'START TRANSACTION' );

        try {
            $wallet = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, available_balance, held_balance, version FROM `{$table_wallets}` WHERE emp_code = %s FOR UPDATE",
                $emp_code
            ), ARRAY_A );

            if ( ! $wallet ) {
                throw new RuntimeException( 'Ví không tồn tại.' );
            }

            $wallet_id = (int) $wallet['id'];

            // Lấy tổng số tiền đã khấu trừ cho chênh lệch âm này (để tránh lặp)
            $existing_tx = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `{$table_txs}` 
                 WHERE emp_code = %s AND transaction_type = 'manual_adjustment' AND reference_type = %s AND reference_id = %s",
                $emp_code,
                $ref_type . '_negative',
                $ref_id
            ) );

            if ( $existing_tx ) {
                $wpdb->query( 'COMMIT' );
                return true;
            }

            $new_avail = (float) $wallet['available_balance'] - $amount;
            $new_held  = (float) $wallet['held_balance'];
            $new_ver   = (int) $wallet['version'] + 1;

            $updated = $wpdb->update(
                $table_wallets,
                [
                    'available_balance' => $new_avail,
                    'version'           => $new_ver,
                    'updated_at'        => current_time( 'mysql' )
                ],
                [ 'id' => $wallet_id, 'version' => $wallet['version'] ],
                [ '%f', '%d', '%s' ],
                [ 'id' => '%d', 'version' => '%d' ]
            );

            if ( $updated === false || $updated === 0 ) {
                throw new RuntimeException( 'Khấu trừ chênh lệch ví thất bại.' );
            }

            $tx_inserted = $wpdb->insert(
                $table_txs,
                [
                    'wallet_id'               => $wallet_id,
                    'emp_code'                => $emp_code,
                    'transaction_type'        => 'manual_adjustment',
                    'amount'                  => -$amount,
                    'available_balance_after' => $new_avail,
                    'held_balance_after'      => $new_held,
                    'reference_type'          => $ref_type . '_negative',
                    'reference_id'            => $ref_id,
                    'description'             => $description,
                    'created_at'              => current_time( 'mysql' )
                ],
                [ '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s' ]
            );

            if ( ! $tx_inserted ) {
                throw new RuntimeException( 'Ghi nhận lịch sử khấu trừ ví thất bại.' );
            }

            $wpdb->query( 'COMMIT' );
            return true;

        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'ST_Wallet_Service::deduct_reward_diff error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Đóng băng tiền trong ví (chuyển available_balance sang held_balance).
     */
    public static function freeze_funds( string $emp_code, float $amount, string $ref_type, string $ref_id, string $description = '' ): bool {
        global $wpdb;

        if ( empty( $emp_code ) || $amount <= 0 ) {
            return false;
        }

        $table_wallets = $wpdb->prefix . 'staff_tracking_wallets';
        $table_txs     = $wpdb->prefix . 'staff_tracking_wallet_transactions';

        $wpdb->query( 'START TRANSACTION' );

        try {
            $wallet = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, available_balance, held_balance, version FROM `{$table_wallets}` WHERE emp_code = %s FOR UPDATE",
                $emp_code
            ), ARRAY_A );

            if ( ! $wallet || (float) $wallet['available_balance'] < $amount ) {
                throw new RuntimeException( 'Số dư khả dụng không đủ để đóng băng.' );
            }

            $wallet_id = (int) $wallet['id'];

            // Chống trùng
            $existing_tx = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `{$table_txs}` 
                 WHERE emp_code = %s AND transaction_type = 'withdrawal_hold' AND reference_type = %s AND reference_id = %s",
                $emp_code,
                $ref_type,
                $ref_id
            ) );

            if ( $existing_tx ) {
                $wpdb->query( 'COMMIT' );
                return true;
            }

            $new_avail = (float) $wallet['available_balance'] - $amount;
            $new_held  = (float) $wallet['held_balance'] + $amount;
            $new_ver   = (int) $wallet['version'] + 1;

            $updated = $wpdb->update(
                $table_wallets,
                [
                    'available_balance' => $new_avail,
                    'held_balance'      => $new_held,
                    'version'           => $new_ver,
                    'updated_at'        => current_time( 'mysql' )
                ],
                [ 'id' => $wallet_id, 'version' => $wallet['version'] ],
                [ '%f', '%f', '%d', '%s' ],
                [ 'id' => '%d', 'version' => '%d' ]
            );

            if ( $updated === false || $updated === 0 ) {
                throw new RuntimeException( 'Đóng băng tiền trong ví thất bại.' );
            }

            $tx_inserted = $wpdb->insert(
                $table_txs,
                [
                    'wallet_id'               => $wallet_id,
                    'emp_code'                => $emp_code,
                    'transaction_type'        => 'withdrawal_hold',
                    'amount'                  => -$amount,
                    'available_balance_after' => $new_avail,
                    'held_balance_after'      => $new_held,
                    'reference_type'          => $ref_type,
                    'reference_id'            => $ref_id,
                    'description'             => $description,
                    'created_at'              => current_time( 'mysql' )
                ],
                [ '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s' ]
            );

            if ( ! $tx_inserted ) {
                throw new RuntimeException( 'Ghi nhận lịch sử đóng băng ví thất bại.' );
            }

            $wpdb->query( 'COMMIT' );
            return true;

        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'ST_Wallet_Service::freeze_funds error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Giải phóng tiền đang bị giữ ngược lại về ví khả dụng.
     */
    public static function release_held_funds( string $emp_code, float $amount, string $ref_type, string $ref_id, string $description = '' ): bool {
        global $wpdb;

        if ( empty( $emp_code ) || $amount <= 0 ) {
            return false;
        }

        $table_wallets = $wpdb->prefix . 'staff_tracking_wallets';
        $table_txs     = $wpdb->prefix . 'staff_tracking_wallet_transactions';

        $wpdb->query( 'START TRANSACTION' );

        try {
            $wallet = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, available_balance, held_balance, version FROM `{$table_wallets}` WHERE emp_code = %s FOR UPDATE",
                $emp_code
            ), ARRAY_A );

            if ( ! $wallet || (float) $wallet['held_balance'] < $amount ) {
                throw new RuntimeException( 'Số dư tạm giữ không đủ để giải phóng.' );
            }

            $wallet_id = (int) $wallet['id'];

            // Chống trùng
            $existing_tx = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `{$table_txs}` 
                 WHERE emp_code = %s AND transaction_type = 'withdrawal_released' AND reference_type = %s AND reference_id = %s",
                $emp_code,
                $ref_type,
                $ref_id
            ) );

            if ( $existing_tx ) {
                $wpdb->query( 'COMMIT' );
                return true;
            }

            $new_avail = (float) $wallet['available_balance'] + $amount;
            $new_held  = (float) $wallet['held_balance'] - $amount;
            $new_ver   = (int) $wallet['version'] + 1;

            $updated = $wpdb->update(
                $table_wallets,
                [
                    'available_balance' => $new_avail,
                    'held_balance'      => $new_held,
                    'version'           => $new_ver,
                    'updated_at'        => current_time( 'mysql' )
                ],
                [ 'id' => $wallet_id, 'version' => $wallet['version'] ],
                [ '%f', '%f', '%d', '%s' ],
                [ 'id' => '%d', 'version' => '%d' ]
            );

            if ( $updated === false || $updated === 0 ) {
                throw new RuntimeException( 'Giải phóng tiền tạm giữ thất bại.' );
            }

            $tx_inserted = $wpdb->insert(
                $table_txs,
                [
                    'wallet_id'               => $wallet_id,
                    'emp_code'                => $emp_code,
                    'transaction_type'        => 'withdrawal_released',
                    'amount'                  => $amount,
                    'available_balance_after' => $new_avail,
                    'held_balance_after'      => $new_held,
                    'reference_type'          => $ref_type,
                    'reference_id'            => $ref_id,
                    'description'             => $description,
                    'created_at'              => current_time( 'mysql' )
                ],
                [ '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s' ]
            );

            if ( ! $tx_inserted ) {
                throw new RuntimeException( 'Ghi nhận lịch sử giải phóng ví thất bại.' );
            }

            $wpdb->query( 'COMMIT' );
            return true;

        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'ST_Wallet_Service::release_held_funds error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Tất toán hoàn toàn tiền đang bị giữ.
     */
    public static function deduct_held_funds( string $emp_code, float $amount, string $ref_type, string $ref_id, string $description = '' ): bool {
        global $wpdb;

        if ( empty( $emp_code ) || $amount <= 0 ) {
            return false;
        }

        $table_wallets = $wpdb->prefix . 'staff_tracking_wallets';
        $table_txs     = $wpdb->prefix . 'staff_tracking_wallet_transactions';

        $wpdb->query( 'START TRANSACTION' );

        try {
            $wallet = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, available_balance, held_balance, version FROM `{$table_wallets}` WHERE emp_code = %s FOR UPDATE",
                $emp_code
            ), ARRAY_A );

            if ( ! $wallet || (float) $wallet['held_balance'] < $amount ) {
                throw new RuntimeException( 'Số dư tạm giữ không đủ để hoàn tất rút.' );
            }

            $wallet_id = (int) $wallet['id'];

            // Chống trùng
            $existing_tx = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `{$table_txs}` 
                 WHERE emp_code = %s AND transaction_type = 'withdrawal_completed' AND reference_type = %s AND reference_id = %s",
                $emp_code,
                $ref_type,
                $ref_id
            ) );

            if ( $existing_tx ) {
                $wpdb->query( 'COMMIT' );
                return true;
            }

            $new_avail = (float) $wallet['available_balance'];
            $new_held  = (float) $wallet['held_balance'] - $amount;
            $new_ver   = (int) $wallet['version'] + 1;

            $updated = $wpdb->update(
                $table_wallets,
                [
                    'held_balance'      => $new_held,
                    'version'           => $new_ver,
                    'updated_at'        => current_time( 'mysql' )
                ],
                [ 'id' => $wallet_id, 'version' => $wallet['version'] ],
                [ '%f', '%d', '%s' ],
                [ 'id' => '%d', 'version' => '%d' ]
            );

            if ( $updated === false || $updated === 0 ) {
                throw new RuntimeException( 'Trừ số dư tạm giữ thất bại.' );
            }

            $tx_inserted = $wpdb->insert(
                $table_txs,
                [
                    'wallet_id'               => $wallet_id,
                    'emp_code'                => $emp_code,
                    'transaction_type'        => 'withdrawal_completed',
                    'amount'                  => -$amount,
                    'available_balance_after' => $new_avail,
                    'held_balance_after'      => $new_held,
                    'reference_type'          => $ref_type,
                    'reference_id'            => $ref_id,
                    'description'             => $description,
                    'created_at'              => current_time( 'mysql' )
                ],
                [ '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s' ]
            );

            if ( ! $tx_inserted ) {
                throw new RuntimeException( 'Ghi nhận lịch sử hoàn tất rút ví thất bại.' );
            }

            $wpdb->query( 'COMMIT' );
            return true;

        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'ST_Wallet_Service::deduct_held_funds error: ' . $e->getMessage() );
            return false;
        }
    }
}
