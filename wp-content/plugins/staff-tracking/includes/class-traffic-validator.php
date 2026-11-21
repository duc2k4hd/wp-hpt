<?php
defined( 'ABSPATH' ) || exit;

class ST_Traffic_Validator {

    /**
     * Kiểm tra điều kiện tối thiểu để được lưu vào CSDL
     */
    public static function can_save( array $data ): bool {
        // 0. Kiểm tra referrer phải xuất phát từ tìm kiếm Google (chống spam / cheat)
        if ( empty( $data['referrer'] ) || ! str_contains( strtolower( $data['referrer'] ), 'google.' ) ) {
            return false;
        }

        // 1. Kiểm tra thời gian onsite và cuộn trang động từ admin settings
        $onsite_opt = get_option( 'st_onsite_threshold' );
        $onsite_threshold = ( $onsite_opt !== '' && $onsite_opt !== false ) ? (int) $onsite_opt : 30;

        $scroll_opt = get_option( 'st_scroll_threshold' );
        $scroll_threshold = ( $scroll_opt !== '' && $scroll_opt !== false ) ? (int) $scroll_opt : 60;

        if ( empty( $data['duration'] ) || (int) $data['duration'] < $onsite_threshold ) {
            return false;
        }

        // ── Lớp 4: Trần thời gian — chặn duration bịa đặt quá cao ───────────
        // Tối đa = ngưỡng + 20 phút (1200s) — cho phép người dùng thực sự đọc lâu
        $max_duration = $onsite_threshold + 1200;
        if ( (int) $data['duration'] > $max_duration ) {
            return false;
        }

        if ( ! isset( $data['max_scroll'] ) || (int) $data['max_scroll'] < $scroll_threshold ) {
            return false;
        }

        // Scroll không được vượt quá 100%
        if ( (int) $data['max_scroll'] > 100 ) {
            return false;
        }

        $user_name  = trim( $data['user_name'] ?? '' );
        $emp_code   = trim( $data['emp_code'] ?? '' );
        $class_code = trim( $data['class_code'] ?? '' );
        $team       = trim( $data['team'] ?? '' );

        // 2. Kiểm tra thông tin bắt buộc: 4 trường đều phải được nhập
        if ( empty( $user_name ) || empty( $emp_code ) || empty( $class_code ) || empty( $team ) ) {
            return false;
        }

        // 3. Chống hacker, chỉ cho phép chữ cái (kể cả Tiếng Việt), số, và các ký tự cơ bản
        $safe_pattern = '/^[\p{L}\p{N}\s\.\-\_\,\(\)]+$/u';
        if (
            ! preg_match( $safe_pattern, $user_name ) ||
            ! preg_match( $safe_pattern, $emp_code ) ||
            ! preg_match( $safe_pattern, $class_code ) ||
            ! preg_match( $safe_pattern, $team )
        ) {
            return false;
        }

        // 4. Bộ lọc từ ngữ thô tục / rác
        $bad_words = [
            'đù', 'đm', 'đmm', 'địt', 'dit', 'loz', 'lồn', 'lon', 'cặc', 'cac', 'buồi', 'buoi',
            'vcl', 'vl', 'vcc', 'đéo', 'deo', 'phò', 'pho', 'điếm', 'diem', 'đĩ', 'chó',
            'fuck', 'shit', 'bitch', 'asshole', 'cunt', 'dick', 'hack', 'hacker', 'h@ck', 'h@cker', 'ion', 'Ion',
            'h@ck3r', 'h@ck3rr'
        ];

        $combined_text = mb_strtolower( $user_name . ' ' . $emp_code . ' ' . $class_code . ' ' . $team, 'UTF-8' );
        foreach ( $bad_words as $word ) {
            if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/iu', $combined_text ) ) {
                return false;
            }
        }

        // 5. Yêu cầu events và kiểm tra tính nhất quán hành vi (Behavior Coherence)
        $events = $data['events'] ?? [];
        if ( empty( $events ) || ! is_array( $events ) ) {
            return false;
        }

        $scroll_depths    = [];
        $heartbeat_count  = 0;
        $max_event_offset = 0;
        $heartbeat_offsets = [];

        foreach ( $events as $ev ) {
            $type   = $ev['type']   ?? '';
            $offset = (int) ( $ev['offset'] ?? 0 );

            if ( $offset < 0 ) {
                return false; // offset âm là bịa đặt
            }
            if ( $offset > $max_event_offset ) {
                $max_event_offset = $offset;
            }

            if ( 'scroll' === $type ) {
                $depth = (int) ( $ev['data']['depth'] ?? 0 );
                $scroll_depths[] = $depth;
            } elseif ( 'heartbeat' === $type ) {
                $heartbeat_count++;
                $heartbeat_offsets[] = $offset;
            }
        }

        // ── Lớp 2a: Phải có ít nhất 2 sự kiện cuộn và 1 heartbeat ────────────
        if ( count( $scroll_depths ) < 2 ) {
            return false;
        }
        if ( $heartbeat_count < 1 ) {
            return false;
        }

        // ── Lớp 2b: Scroll phải tăng dần, không được nhảy cóc ───────────────
        // Bot thường gửi 25, 50, 75, 100 hoặc nhảy từ 0 → 100
        // Người thật cuộn từng bước, chiều tăng là bình thường nhưng không được giảm
        $prev_depth = -1;
        foreach ( $scroll_depths as $depth ) {
            if ( $depth < $prev_depth ) {
                // Scroll giảm đột ngột (không thể xảy ra với cuộn xuống)
                // Thực ra milestones tracker ghi theo mức milestone nên sẽ luôn tăng dần
                return false;
            }
            $prev_depth = $depth;
        }
        // Không được toàn bộ scroll là 100 ngay từ đầu (bot scroll ngay lập tức)
        if ( ! empty( $scroll_depths ) && $scroll_depths[0] === 100 && count( $scroll_depths ) === 1 ) {
            return false;
        }

        // ── Lớp 2c: max_event_offset phải xấp xỉ duration ───────────────────
        // Chênh lệch tối đa cho phép: 30s (mạng chậm, tab ẩn, etc.)
        if ( $max_event_offset < ( $onsite_threshold - 5 ) ) {
            return false;
        }
        $duration = (int) $data['duration'];
        if ( abs( $max_event_offset - $duration ) > 60 ) {
            // Event offset và duration chênh nhau quá nhiều — nghi ngờ bịa đặt
            return false;
        }

        // ── Lớp 2d: Heartbeat offsets phải phân bổ đều (không phải tất cả = 0) ─
        if ( count( $heartbeat_offsets ) >= 2 ) {
            sort( $heartbeat_offsets );
            $min_hb = $heartbeat_offsets[0];
            $max_hb = $heartbeat_offsets[ count( $heartbeat_offsets ) - 1 ];
            // Nếu tất cả heartbeat cùng offset thì là fake
            if ( $min_hb === $max_hb ) {
                return false;
            }
            // Phải có heartbeat trải dài ít nhất 50% thời gian
            if ( ( $max_hb - $min_hb ) < ( $onsite_threshold * 0.5 ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kiểm tra tính hợp lệ của dữ liệu traffic.
     */
    public static function is_valid( array $data ): bool {
        return self::can_save( $data );
    }
}
