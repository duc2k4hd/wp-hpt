<div>
    <h2 class="tw-text-[#0284C7] tw-text-[18px] tw-font-bold">Thông tin tài khoản</h2>
    <div class="lg:tw-grid tw-grid-cols-2 tw-gap-x-8">
        <div class="tw-border-solid tw-border-[1px] tw-border-[#A3A3A3] tw-rounded-[5px] tw-flex-col tw-flex tw-p-8 tw-gap-y-1">
            <h3 class="tw-text-[#171717] tw-text-[18px] tw-font-bold ">Thông tin tài khoản</h3>
            <?php 
            // Lấy thông tin người dùng hiện tại
            $current_user = wp_get_current_user();
            ?>
            
            <span>Họ và tên: <?php echo esc_html($current_user->display_name); ?></span>
            <span>Email: <?php echo esc_html($current_user->user_email); ?></span>
            <span>Số điện thoại: <?php echo esc_html(get_user_meta($current_user->ID, 'billing_phone', true)); ?></span>
            <?php
                $address_parts = array();

                // Lấy thông tin từng phần của địa chỉ
                $shipping_address = get_user_meta($current_user->ID, 'shipping_address_1', true);
                $shipping_address_2 = get_user_meta($current_user->ID, 'shipping_address_2', true);
                $shipping_city = get_user_meta($current_user->ID, 'shipping_city', true);
                $shipping_state = get_user_meta($current_user->ID, 'shipping_state', true);
                $shipping_postcode = get_user_meta($current_user->ID, 'shipping_postcode', true);
                $shipping_country = get_user_meta($current_user->ID, 'shipping_country', true);

                // Thêm từng phần vào mảng nếu tồn tại giá trị
                if (!empty($shipping_address)) {
                    $address_parts[] = $shipping_address;
                }
                if (!empty($shipping_address_2)) {
                    $address_parts[] = $shipping_address_2;
                }
                if (!empty($shipping_city)) {
                    $address_parts[] = $shipping_city;
                }
                if (!empty($shipping_state)) {
                    $address_parts[] = $shipping_state;
                }
                if (!empty($shipping_postcode)) {
                    $address_parts[] = $shipping_postcode;
                }
                if (!empty($shipping_country)) {
                    $address_parts[] = $shipping_country;
                }

                // Kết hợp các phần địa chỉ với dấu phẩy
                $full_shipping_address = implode(', ', $address_parts);
                ?>

                <span>Địa chỉ giao hàng: <?php echo esc_html($full_shipping_address); ?></span>


            
            <a href="/my-account/edit-account/" class="tw-border-solid tw-py-2 tw-text-center tw-border-[1px] tw-border-[#0284C7] tw-text-[#0284C7] tw-rounded-[5px] hover:tw-bg-[#0284C7] tw-transition-all hover:tw-text-white">Sửa thông tin</a>
        </div>
        <div class="tw-border-solid tw-border-[1px] tw-border-[#A3A3A3] tw-rounded-[5px] tw-flex-col tw-flex tw-p-8 tw-gap-y-1">
            <h3 class="tw-text-[#171717] tw-text-[18px] tw-font-bold">Thống kê lịch sử mua hàng</h3>
        
            <?php 
            // Lấy ID người dùng hiện tại
            $current_user_id = get_current_user_id();
            
            // Đếm tổng số đơn hàng
            $orders = wc_get_orders(array(
                'customer_id' => $current_user_id,
                'return' => 'ids',
            ));
            $total_orders = count($orders);
            
            // Đếm đơn hàng theo trạng thái
            $completed_orders = wc_get_orders(array(
                'customer_id' => $current_user_id,
                'status' => 'completed',
                'return' => 'ids',
            ));
            $processing_orders = wc_get_orders(array(
                'customer_id' => $current_user_id,
                'status' => 'processing',
                'return' => 'ids',
            ));
            $cancelled_orders = wc_get_orders(array(
                'customer_id' => $current_user_id,
                'status' => 'cancelled',
                'return' => 'ids',
            ));
            ?>

            <span>Tổng số đơn hàng: <?php echo $total_orders; ?></span>
            <span>Đơn hàng hoàn thành: <?php echo count($completed_orders); ?></span>
            <span>Đơn hàng đang xử lý: <?php echo count($processing_orders); ?></span>
            <span>Đơn hàng đã hủy: <?php echo count($cancelled_orders); ?></span>

            <a href="/my-account/orders/" class="tw-border-solid tw-border-[1px] tw-py-2 tw-text-center tw-border-[#0284C7] tw-text-[#0284C7] tw-rounded-[5px] hover:tw-bg-[#0284C7] tw-transition-all hover:tw-text-white">Xem lịch sử mua hàng</a>
        </div>

    </div>
</div>