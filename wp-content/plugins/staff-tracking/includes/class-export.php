<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles CSV export via admin-post.php action.
 * No heavy libraries – streams directly to browser.
 */
class ST_Export {

    private static ?ST_Export $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_post_st_export_csv', [ $this, 'export_csv' ] );
        add_action( 'admin_post_st_export_assignment_excel', [ $this, 'export_assignment_csv' ] );
        add_action( 'admin_post_st_export_personnel_excel', [ $this, 'export_personnel_excel' ] );
    }

    public function export_csv(): void {
        // Auth + nonce
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'staff-tracking' ), 403 );
        }
        check_admin_referer( 'st_admin_nonce', 'st_nonce' );

        global $wpdb;

        $table  = $wpdb->prefix . ST_TABLE;
        $where  = [];
        $params = [];

        // Sanitise query params
        $start = sanitize_text_field( wp_unslash( $_GET['start_date'] ?? '' ) );
        $end   = sanitize_text_field( wp_unslash( $_GET['end_date']   ?? '' ) );
        $staff = sanitize_text_field( wp_unslash( $_GET['staff']       ?? '' ) );
        $emp   = sanitize_text_field( wp_unslash( $_GET['emp_code']    ?? '' ) );
        $cls   = sanitize_text_field( wp_unslash( $_GET['class_code']  ?? '' ) );
        $team  = sanitize_text_field( wp_unslash( $_GET['team']        ?? '' ) );
        $exact = ! empty( $_GET['exact_match'] ) && $_GET['exact_match'] !== '0' && $_GET['exact_match'] !== 'false';
        $valid = isset( $_GET['valid'] ) && $_GET['valid'] !== '' ? (int) $_GET['valid'] : null;

        if ( $start ) {
            $where[]  = 'created_at >= %s';
            $params[] = $start . ' 00:00:00';
        }
        if ( $end ) {
            $where[]  = 'created_at <= %s';
            $params[] = $end . ' 23:59:59';
        }
        if ( $staff ) {
            if ( $exact ) {
                $where[]  = 'user_name = %s';
                $params[] = $staff;
            } else {
                $where[]  = 'user_name LIKE %s';
                $params[] = '%' . $wpdb->esc_like( $staff ) . '%';
            }
        }
        if ( $emp ) {
            if ( $exact ) {
                $where[]  = 'emp_code = %s';
                $params[] = $emp;
            } else {
                $where[]  = 'emp_code LIKE %s';
                $params[] = '%' . $wpdb->esc_like( $emp ) . '%';
            }
        }
        if ( $cls ) {
            if ( $exact ) {
                $where[]  = 'class_code = %s';
                $params[] = $cls;
            } else {
                $where[]  = 'class_code LIKE %s';
                $params[] = '%' . $wpdb->esc_like( $cls ) . '%';
            }
        }
        if ( $team ) {
            if ( $exact ) {
                $where[]  = 'team = %s';
                $params[] = $team;
            } else {
                $where[]  = 'team LIKE %s';
                $params[] = '%' . $wpdb->esc_like( $team ) . '%';
            }
        }
        if ( null !== $valid ) {
            $where[]  = 'is_valid = %d';
            $params[] = $valid;
        }

        $w = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $sql = "SELECT id, session_id, created_at, user_name, emp_code, class_code, team, ip, url, page_path, duration, is_valid
                FROM `{$table}` {$w} ORDER BY created_at DESC";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A );

        // Stream CSV
        $filename = 'staff-tracking-' . gmdate( 'Y-m-d_Hi' ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'wb' );

        // UTF-8 BOM for Excel
        fwrite( $out, "\xEF\xBB\xBF" );

        // Header row
        fputcsv( $out, [
            'ID', 'Session ID', 'Thời gian', 'Họ tên', 'Mã NV', 'Mã Lớp', 'Team / Bộ phận',
            'IP', 'URL', 'Page Path', 'Thời lượng (s)', 'Hợp lệ',
        ] );

        if ( $rows ) {
            foreach ( $rows as $row ) {
                fputcsv( $out, [
                    $row['id'],
                    $row['session_id'],
                    $row['created_at'],
                    $row['user_name'],
                    $row['emp_code'],
                    $row['class_code'],
                    $row['team'],
                    $row['ip'],
                    $row['url'],
                    $row['page_path'],
                    $row['duration'],
                    $row['is_valid'] ? 'Có' : 'Không',
                ] );
            }
        }

        fclose( $out );
        exit;
    }

    public function export_assignment_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'staff-tracking' ), 403 );
        }
        
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        if ( $id <= 0 ) {
            wp_die( esc_html__( 'ID nhiệm vụ không hợp lệ.', 'staff-tracking' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'st_assignments';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );

        if ( ! $assign ) {
            wp_die( esc_html__( 'Nhiệm vụ không tồn tại.', 'staff-tracking' ), 404 );
        }

        // Tách đường dẫn allowed_paths hoặc phases
        $paths = [];
        $is_phases = ( $assign['product_selection'] === 'phases' );
        $phases = [];
        
        if ( $is_phases && ! empty( $assign['phases_data'] ) ) {
            $p_dec = json_decode( $assign['phases_data'], true );
            if ( is_array( $p_dec ) ) {
                $phases = $p_dec;
                foreach ( $phases as &$p ) {
                    if ( ! empty( $p['allowed_paths'] ) && is_string( $p['allowed_paths'] ) ) {
                        $p['allowed_paths'] = array_filter( array_map( 'trim', explode( "\n", $p['allowed_paths'] ) ) );
                    }
                    if ( ! empty( $p['allowed_paths'] ) && is_array( $p['allowed_paths'] ) ) {
                        $paths = array_merge( $paths, $p['allowed_paths'] );
                    }
                }
            }
        } elseif ( ! empty( $assign['allowed_paths'] ) ) {
            $paths = array_filter( array_map( 'trim', explode( "\n", $assign['allowed_paths'] ) ) );
        }
        
        $paths = array_unique( $paths );

        // Lấy tên miền hiện tại để tạo liên kết tuyệt đối
        $site_url = rtrim( get_site_url(), '/' );

        // Lấy thông tin Tên sản phẩm và Mã SKU ứng với các page_path (WooCommerce products)
        $product_data = [];
        if ( ! empty( $paths ) ) {
            // Xây dựng map từ page_path / post_name sang post_title và sku
            $slugs = [];
            foreach ( $paths as $path ) {
                $slug = trim( $path, '/' );
                // Nếu path có dạng /product/slug hoặc /san-pham/slug, lấy phần cuối
                $parts = explode( '/', $slug );
                $slugs[] = end( $parts );
            }

            if ( ! empty( $slugs ) ) {
                $slug_placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
                $results = $wpdb->get_results( $wpdb->prepare(
                    "SELECT p.post_name, p.post_title, m.meta_value as sku 
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_sku'
                     WHERE p.post_name IN ($slug_placeholders) AND p.post_type = 'product' AND p.post_status = 'publish'",
                    ...$slugs
                ), ARRAY_A );

                if ( $results ) {
                    foreach ( $results as $r ) {
                        $product_data[$r['post_name']] = [
                            'title' => $r['post_title'],
                            'sku'   => ! empty( $r['sku'] ) ? $r['sku'] : $r['post_name']
                        ];
                    }
                }
            }
        }

        $filename = 'nhiem_vu_traffic_' . sanitize_title_with_dashes( $assign['title'] ) . '.xls';

        header( 'Content-Type: application/vnd.ms-excel; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // In ra mã HTML Excel
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head>';
        echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
        echo '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Nhiệm vụ Traffic</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
        echo '<style>';
        echo '  body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }';
        echo '  table { border-collapse: collapse; }';
        echo '  td, th { border: 1px solid #cbd5e0; padding: 8px 12px; font-size: 13px; }';
        echo '  .title { font-size: 16px; font-weight: bold; color: #1e7e34; padding: 10px 0; }';
        echo '  .meta-label { font-weight: bold; background-color: #f7fafc; color: #4a5568; width: 140px; }';
        echo '  .meta-value { color: #2d3748; }';
        echo '  .header-row th { background-color: #1e7e34; color: #ffffff; font-weight: bold; text-align: left; }';
        echo '  .stt-col { text-align: center; width: 60px; }';
        echo '  .name-col { width: 300px; }';
        echo '  .sku-col { width: 180px; }';
        echo '  .link-col { width: 550px; }';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<table>';
        
        echo '  <tr>';
        echo '    <td colspan="4" class="title" style="border:none; height: 35px; vertical-align: middle;">THÔNG TIN NHIỆM VỤ CHẠY TRAFFIC</td>';
        echo '  </tr>';
        
        echo '  <tr>';
        echo '    <td class="meta-label">Tên nhiệm vụ:</td>';
        echo '    <td colspan="3" class="meta-value"><strong>' . esc_html( $assign['title'] ) . '</strong></td>';
        echo '  </tr>';
        
        echo '  <tr>';
        echo '    <td class="meta-label">Người giao:</td>';
        echo '    <td colspan="3" class="meta-value">' . esc_html( $assign['created_by'] ) . '</td>';
        echo '  </tr>';
        
        $period_display = ( $assign['period_type'] === 'month' ? 'Tháng ' : '' ) . $assign['period_value'];
        echo '  <tr>';
        echo '    <td class="meta-label">Chu kỳ:</td>';
        echo '    <td colspan="3" class="meta-value">' . esc_html( $period_display ) . '</td>';
        echo '  </tr>';
        
        echo '  <tr>';
        echo '    <td class="meta-label">Chỉ tiêu KPI:</td>';
        echo '    <td colspan="3" class="meta-value"><strong>' . esc_html( $assign['kpi_target'] ) . ' lượt truy cập</strong></td>';
        echo '  </tr>';
        
        $onsite_threshold = (int) get_option( 'st_onsite_threshold', 30 );
        $scroll_threshold = (int) get_option( 'st_scroll_threshold', 60 );

        echo '  <tr>';
        echo '    <td class="meta-label" style="vertical-align: top;">Yêu cầu bắt buộc:</td>';
        echo '    <td colspan="3" class="meta-value" style="color: #c53030; font-weight: bold; line-height: 1.4;">';
        echo '      1. Phải truy cập thông qua tìm kiếm Google (Google Referrer)<br>';
        echo '      2. Đọc onsite tối thiểu ' . $onsite_threshold . ' giây<br>';
        echo '      3. Cuộn trang (Scroll depth) đạt tối thiểu ' . $scroll_threshold . '%';
        echo '    </td>';
        echo '  </tr>';
        
        echo '  <tr style="height:20px;"><td colspan="4" style="border:none;"></td></tr>';
        
        echo '  <tr class="header-row" style="height: 30px; vertical-align: middle;">';
        echo '    <th class="stt-col">STT</th>';
        echo '    <th class="name-col">Tên sản phẩm</th>';
        echo '    <th class="sku-col">Từ khóa</th>';
        echo '    <th class="link-col">Đường dẫn chạy traffic (Click vào để mở)</th>';
        echo '  </tr>';

        if ( $is_phases && ! empty( $phases ) ) {
            foreach ( $phases as $p ) {
                $p_start = $p['start_date'] ?? '';
                $p_end = $p['end_date'] ?? '';
                $date_label = "Từ " . date('d/m/Y', strtotime($p_start)) . " đến " . date('d/m/Y', strtotime($p_end));
                
                echo '  <tr class="header-row" style="height: 30px; vertical-align: middle;">';
                echo '    <th colspan="4" style="background-color: #e2e8f0; color: #2d3748; text-align: left; font-style: italic;">Giai đoạn: ' . esc_html( $date_label ) . '</th>';
                echo '  </tr>';
                
                $phase_paths = $p['allowed_paths'] ?? [];
                if ( ! empty( $phase_paths ) && is_array( $phase_paths ) ) {
                    $stt = 1;
                    foreach ( $phase_paths as $path ) {
                        $slug = trim( $path, '/' );
                        $parts = explode( '/', $slug );
                        $last_part = end( $parts );
                        
                        $prod_title = isset( $product_data[$last_part] ) ? $product_data[$last_part]['title'] : 'Sản phẩm liên kết';
                        $prod_sku   = isset( $product_data[$last_part] ) ? $product_data[$last_part]['sku'] : $last_part;
                        $absolute_url = $site_url . '/' . ltrim( $path, '/' );

                        echo '  <tr style="height: 25px; vertical-align: middle;">';
                        echo '    <td style="text-align: center;">' . $stt++ . '</td>';
                        echo '    <td>' . esc_html( $prod_title ) . '</td>';
                        echo '    <td>' . esc_html( $prod_sku ) . '</td>';
                        echo '    <td><a href="' . esc_url( $absolute_url ) . '" style="color: #1a0dab; text-decoration: underline;">' . esc_html( $absolute_url ) . '</a></td>';
                        echo '  </tr>';
                    }
                } else {
                    echo '  <tr style="height: 25px; vertical-align: middle;">';
                    echo '    <td style="text-align: center;">-</td>';
                    $sel = $p['product_selection'] ?? 'all';
                    if ( $sel === 'all' ) {
                        echo '    <td>Áp dụng cho toàn bộ sản phẩm</td>';
                        echo '    <td>Tất cả</td>';
                        echo '    <td><a href="' . esc_url( $site_url ) . '" style="color: #1a0dab; text-decoration: underline;">' . esc_html( $site_url ) . '</a></td>';
                    } else {
                        echo '    <td colspan="3">Không có sản phẩm nào cho giai đoạn này</td>';
                    }
                    echo '  </tr>';
                }
            }
        } elseif ( ! empty( $paths ) ) {
            $stt = 1;
            foreach ( $paths as $path ) {
                $slug = trim( $path, '/' );
                $parts = explode( '/', $slug );
                $last_part = end( $parts );
                
                $prod_title = isset( $product_data[$last_part] ) ? $product_data[$last_part]['title'] : 'Sản phẩm liên kết';
                $prod_sku   = isset( $product_data[$last_part] ) ? $product_data[$last_part]['sku'] : $last_part;
                $absolute_url = $site_url . '/' . ltrim( $path, '/' );

                echo '  <tr style="height: 25px; vertical-align: middle;">';
                echo '    <td style="text-align: center;">' . $stt++ . '</td>';
                echo '    <td>' . esc_html( $prod_title ) . '</td>';
                echo '    <td>' . esc_html( $prod_sku ) . '</td>';
                echo '    <td><a href="' . esc_url( $absolute_url ) . '" style="color: #1a0dab; text-decoration: underline;">' . esc_html( $absolute_url ) . '</a></td>';
                echo '  </tr>';
            }
        } else {
            echo '  <tr style="height: 25px; vertical-align: middle;">';
            echo '    <td style="text-align: center;">-</td>';
            echo '    <td>Áp dụng cho toàn bộ sản phẩm trên website</td>';
            echo '    <td>Tất cả</td>';
            echo '    <td><a href="' . esc_url( $site_url ) . '" style="color: #1a0dab; text-decoration: underline;">' . esc_html( $site_url ) . '</a></td>';
            echo '  </tr>';
        }

        echo '</table>';
        echo '</body>';
        echo '</html>';
        exit;
    }

    public function export_personnel_excel(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'staff-tracking' ), 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'st_personnel';

        $class_filter = isset( $_GET['class'] ) ? sanitize_text_field( trim( $_GET['class'] ) ) : '';
        $team_filter  = isset( $_GET['team'] ) ? sanitize_text_field( trim( $_GET['team'] ) ) : '';

        $where = ["1=1"];
        $params = [];

        if ( ! empty( $class_filter ) ) {
            $where[] = "class_code = %s";
            $params[] = $class_filter;
        }
        if ( ! empty( $team_filter ) ) {
            $where[] = "team = %s";
            $params[] = $team_filter;
        }

        $where_sql = implode( ' AND ', $where );
        
        $sql = "SELECT id, user_name, emp_code, class_code, team FROM `{$table}` WHERE {$where_sql} ORDER BY class_code ASC, emp_code ASC";
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A );

        // Trả về dữ liệu JSON thô để Client-side dùng SheetJS tạo file XLSX zin 100%
        wp_send_json( $rows );
    }
}
