<?php
defined( 'ABSPATH' ) || exit;

class ST_Withdrawal_Service {

    /**
     * Danh sách các trạng thái hợp lệ.
     */
    public const STATUS_PENDING       = 'pending_approval';
    public const STATUS_APPROVED      = 'approved';
    public const STATUS_PROCESSING    = 'processing';
    public const STATUS_PAID          = 'paid';
    public const STATUS_REJECTED      = 'rejected';
    public const STATUS_CANCELLED     = 'cancelled';
    public const STATUS_FAILED        = 'failed';
    public const STATUS_RECONCILE     = 'reconciliation_required';

    /**
     * Kiểm tra chuyển trạng thái hợp lệ.
     */
    public static function is_valid_transition( string $from, string $to ): bool {
        $allowed = [
            self::STATUS_PENDING => [
                self::STATUS_APPROVED,
                self::STATUS_REJECTED,
                self::STATUS_CANCELLED
            ],
            self::STATUS_APPROVED => [
                self::STATUS_PROCESSING,
                self::STATUS_PAID,
                self::STATUS_CANCELLED,
                self::STATUS_RECONCILE
            ],
            self::STATUS_PROCESSING => [
                self::STATUS_PAID,
                self::STATUS_FAILED,
                self::STATUS_RECONCILE
            ],
            self::STATUS_RECONCILE => [
                self::STATUS_PAID,
                self::STATUS_FAILED,
                self::STATUS_CANCELLED
            ]
        ];

        return isset( $allowed[$from] ) && in_array( $to, $allowed[$from], true );
    }

    /**
     * Tạo mã rút tiền duy nhất (ví dụ WD260715000001).
     * Format: WD + YYMMDD + 6 số tự động tăng.
     */
    public static function generate_withdrawal_code(): string {
        global $wpdb;
        $table = $wpdb->prefix . 'staff_tracking_withdrawals';
        
        $date_prefix = 'WD' . date( 'ymd' );
        
        // Lấy số thứ tự lớn nhất trong ngày
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $last_code = $wpdb->get_var( $wpdb->prepare(
            "SELECT withdrawal_code FROM `{$table}` 
             WHERE withdrawal_code LIKE %s 
             ORDER BY id DESC LIMIT 1",
            $date_prefix . '%'
        ) );

        if ( $last_code ) {
            $seq = (int) substr( $last_code, 8 );
            $next_seq = $seq + 1;
        } else {
            $next_seq = 1;
        }

        return $date_prefix . str_pad( (string) $next_seq, 6, '0', STR_PAD_LEFT );
    }

    /**
     * Tạo mới một yêu cầu rút tiền (Withdrawal Request).
     * Trừ tiền khả dụng, cộng tiền tạm giữ trong ví (freeze).
     */
    public static function create_withdrawal( int $user_id, int $bank_account_id, float $amount ): ?string {
        global $wpdb;

        if ( $amount <= 0 ) {
            return null;
        }

        $table_bank = $wpdb->prefix . 'staff_tracking_bank_accounts';
        $table_withdrawals = $wpdb->prefix . 'staff_tracking_withdrawals';

        // 1. Kiểm tra tài khoản ngân hàng của user
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $bank = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table_bank}` WHERE id = %d AND user_id = %d AND is_active = 1",
            $bank_account_id,
            $user_id
        ), ARRAY_A );

        if ( ! $bank ) {
            return null;
        }

        $wpdb->query( 'START TRANSACTION' );

        try {
            // Lấy ví của user
            $wallet = ST_Wallet_Service::get_or_create_wallet( $user_id );
            if ( ! $wallet ) {
                throw new RuntimeException( 'Ví của user không tồn tại.' );
            }

            // Sinh mã rút tiền duy nhất
            $code = self::generate_withdrawal_code();

            // Thực hiện đóng băng tiền (freeze available_balance -> held_balance)
            $frozen = ST_Wallet_Service::freeze_funds(
                $user_id,
                $amount,
                'withdrawal',
                $code,
                'Đóng băng số dư rút tiền: ' . $code
            );

            if ( ! $frozen ) {
                throw new RuntimeException( 'Không thể đóng băng tiền ví.' );
            }

            // Ghi nhận yêu cầu rút tiền vào bảng withdrawals
            $inserted = $wpdb->insert(
                $table_withdrawals,
                [
                    'withdrawal_code' => $code,
                    'user_id'         => $user_id,
                    'wallet_id'       => (int) $wallet['id'],
                    'bank_account_id' => $bank_account_id,
                    'amount'          => $amount,
                    'bank_code'       => $bank['bank_code'],
                    'bank_name'       => $bank['bank_name'],
                    'account_number'  => $bank['account_number'],
                    'account_name'    => $bank['account_name'],
                    'status'          => self::STATUS_PENDING,
                    'requested_at'    => current_time( 'mysql' ),
                    'version'         => 0,
                    'created_at'      => current_time( 'mysql' ),
                    'updated_at'      => current_time( 'mysql' )
                ],
                [ '%s', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
            );

            if ( ! $inserted ) {
                throw new RuntimeException( 'Tạo bản ghi yêu cầu rút tiền thất bại.' );
            }

            $wpdb->query( 'COMMIT' );
            return $code;

        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'ST_Withdrawal_Service::create_withdrawal error: ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * Cập nhật trạng thái yêu cầu rút tiền (có kiểm tra transition và audit log).
     */
    public static function update_status( int $withdrawal_id, string $new_status, array $data = [] ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'staff_tracking_withdrawals';

        $wpdb->query( 'START TRANSACTION' );

        try {
            // Lấy dòng withdrawal bằng SELECT ... FOR UPDATE để chống race conditions
            $w = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE id = %d FOR UPDATE",
                $withdrawal_id
            ), ARRAY_A );

            if ( ! $w ) {
                throw new RuntimeException( 'Không tìm thấy yêu cầu rút tiền.' );
            }

            $old_status = $w['status'];

            // 1. Kiểm tra chuyển đổi trạng thái hợp lệ
            if ( ! self::is_valid_transition( $old_status, $new_status ) ) {
                throw new RuntimeException( "Chuyển đổi trạng thái không hợp lệ: từ {$old_status} sang {$new_status}" );
            }

            $update_fields = [
                'status'     => $new_status,
                'version'    => (int) $w['version'] + 1,
                'updated_at' => current_time( 'mysql' )
            ];
            $formats = [ '%s', '%d', '%s' ];

            // Map thêm thông tin bổ sung tùy theo trạng thái đích
            if ( self::STATUS_APPROVED === $new_status ) {
                $update_fields['approved_at'] = current_time( 'mysql' );
                $update_fields['approved_by'] = $data['admin_user_id'] ?? get_current_user_id();
                $formats[] = '%s';
                $formats[] = '%d';
            } elseif ( self::STATUS_PROCESSING === $new_status ) {
                $update_fields['processing_at'] = current_time( 'mysql' );
                $update_fields['processed_by']  = $data['admin_user_id'] ?? get_current_user_id();
                $formats[] = '%s';
                $formats[] = '%d';
            } elseif ( self::STATUS_PAID === $new_status ) {
                $update_fields['paid_at'] = current_time( 'mysql' );
                $formats[] = '%s';
                
                if ( isset( $data['sepay_transaction_id'] ) ) {
                    $update_fields['sepay_transaction_id'] = sanitize_text_field( $data['sepay_transaction_id'] );
                    $formats[] = '%s';
                }
                if ( isset( $data['bank_reference_code'] ) ) {
                    $update_fields['bank_reference_code'] = sanitize_text_field( $data['bank_reference_code'] );
                    $formats[] = '%s';
                }
                if ( isset( $data['bank_transaction_date'] ) ) {
                    $update_fields['bank_transaction_date'] = sanitize_text_field( $data['bank_transaction_date'] );
                    $formats[] = '%s';
                }
            } elseif ( self::STATUS_REJECTED === $new_status ) {
                $update_fields['rejected_at'] = current_time( 'mysql' );
                $update_fields['rejected_by'] = $data['admin_user_id'] ?? get_current_user_id();
                $formats[] = '%s';
                $formats[] = '%d';
            } elseif ( self::STATUS_FAILED === $new_status ) {
                $update_fields['failed_at'] = current_time( 'mysql' );
                $formats[] = '%s';
            }

            if ( isset( $data['admin_note'] ) ) {
                $update_fields['admin_note'] = sanitize_textarea_field( $data['admin_note'] );
                $formats[] = '%s';
            }
            if ( isset( $data['failure_code'] ) ) {
                $update_fields['failure_code'] = sanitize_text_field( $data['failure_code'] );
                $formats[] = '%s';
            }
            if ( isset( $data['failure_reason'] ) ) {
                $update_fields['failure_reason'] = sanitize_textarea_field( $data['failure_reason'] );
                $formats[] = '%s';
            }

            // 2. Cập nhật Ví tương ứng theo trạng thái rút tiền
            $amount  = (float) $w['amount'];
            $user_id = (int) $w['user_id'];
            $code    = $w['withdrawal_code'];

            if ( in_array( $new_status, [ self::STATUS_REJECTED, self::STATUS_CANCELLED ], true ) ) {
                // Hủy / Từ chối rút -> Trả tiền tạm giữ về khả dụng (release)
                $released = ST_Wallet_Service::release_held_funds(
                    $user_id,
                    $amount,
                    'withdrawal',
                    $code,
                    "Giải phóng số dư yêu cầu rút bị hủy/từ chối: " . $code
                );
                if ( ! $released ) {
                    throw new RuntimeException( 'Giải phóng tiền tạm giữ thất bại.' );
                }
            } elseif ( self::STATUS_PAID === $new_status ) {
                // Hoàn tất thanh toán -> Khấu trừ vĩnh viễn khỏi ví held (deduct)
                $deducted = ST_Wallet_Service::deduct_held_funds(
                    $user_id,
                    $amount,
                    'withdrawal',
                    $code,
                    "Tất toán số dư yêu cầu rút thành công: " . $code
                );
                if ( ! $deducted ) {
                    throw new RuntimeException( 'Khấu trừ tiền tạm giữ thất bại.' );
                }
            }

            // 3. Thực hiện lưu vào CSDL
            $updated = $wpdb->update(
                $table,
                $update_fields,
                [ 'id' => $withdrawal_id, 'version' => $w['version'] ],
                $formats,
                [ '%d', '%d' ]
            );

            if ( $updated === false || $updated === 0 ) {
                throw new RuntimeException( 'Cập nhật trạng thái yêu cầu rút tiền thất bại hoặc lỗi đồng thời.' );
            }

            // Ghi audit log nếu lớp quản lý logs tồn tại
            self::log_audit( $withdrawal_id, $old_status, $new_status, $data );

            $wpdb->query( 'COMMIT' );
            return true;

        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'ST_Withdrawal_Service::update_status error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Ghi nhận audit log lịch sử thao tác.
     */
    private static function log_audit( int $withdrawal_id, string $from, string $to, array $data ): void {
        // Chúng ta ghi lại sự kiện vào file logs hoặc bảng log nếu có
        $user_id = $data['admin_user_id'] ?? get_current_user_id();
        $note = $data['admin_note'] ?? '';
        
        $log_msg = sprintf(
            "[Withdrawal Audit] ID: %d | Chuyển trạng thái: %s -> %s | Thực hiện bởi User ID: %d | Ghi chú: %s",
            $withdrawal_id,
            $from,
            $to,
            $user_id,
            $note
        );
        
        error_log( $log_msg );
    }
}
