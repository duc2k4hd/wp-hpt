<?php

/**
 * Hide the obsolete product-description table settings group from ACF.
 * The group is database-backed, so filtering it here removes its UI without
 * touching the active product contact settings group.
 */
function hpt_hide_legacy_product_table_settings_group($field_groups, $post_type = '') {
    foreach ((array) $field_groups as $index => $field_group) {
        $key   = (string) ($field_group['key'] ?? '');
        $title = (string) ($field_group['title'] ?? '');

        if ($key === 'group_678dd1e87e9b6' || $title === 'Cài đặt thông số cho bảng ở phần mô tả sản phẩm') {
            unset($field_groups[$index]);
        }
    }

    return array_values($field_groups);
}
add_filter('acf/load_field_groups', 'hpt_hide_legacy_product_table_settings_group', 99, 2);

/**
 * Cấu hình liên hệ cho cột sản phẩm desktop và thanh mobile.
 */
function hpt_register_product_contact_settings_fields() {
    if (!function_exists('acf_add_local_field_group')) {
        return;
    }

    acf_add_local_field_group(array(
        'key'                   => 'group_67caabc0860c1',
        'title'                 => 'Liên hệ sản phẩm',
        'fields'                => array(
            array(
                'key'       => 'field_hpt_product_contact_intro',
                'label'     => '',
                'name'      => '',
                'type'      => 'message',
                'message'   => '<strong>Cấu hình liên hệ cho trang sản phẩm.</strong><br>Mỗi nhóm có số Phone và số Zalo riêng. Nếu chưa có dữ liệu mới, hệ thống tạm dùng số cũ làm fallback để không làm hỏng liên hệ đang hiển thị.',
                'new_lines' => 'wpautop',
                'esc_html'  => 0,
            ),
            array(
                'key'       => 'field_hpt_product_contact_mobile_tab',
                'label'     => 'Mobile (Phone + Zalo)',
                'name'      => '',
                'type'      => 'tab',
                'placement' => 'top',
                'endpoint'  => 0,
            ),
            array(
                'key'           => 'field_hpt_product_contact_mobile_phone',
                'label'         => 'Số điện thoại trên mobile',
                'name'          => 'product_contact_mobile_phone',
                'type'          => 'text',
                'instructions'  => 'Số dùng cho nút Gọi ở thanh liên hệ dưới màn hình mobile.',
                'required'      => 1,
                'default_value' => '0866555212',
                'placeholder'   => '0866555212',
                'prepend'       => 'Phone',
                'wrapper'       => array(
                    'width' => '50',
                    'class' => '',
                    'id'    => '',
                ),
            ),
            array(
                'key'           => 'field_hpt_product_contact_mobile_zalo',
                'label'         => 'Số Zalo trên mobile',
                'name'          => 'product_contact_mobile_zalo',
                'type'          => 'text',
                'instructions'  => 'Số dùng cho nút Zalo ở thanh liên hệ dưới màn hình mobile. Có thể khác số điện thoại.',
                'required'      => 1,
                'default_value' => '0866555212',
                'placeholder'   => '0866555212',
                'prepend'       => 'Zalo',
                'wrapper'       => array(
                    'width' => '50',
                    'class' => '',
                    'id'    => '',
                ),
            ),
            array(
                'key'       => 'field_hpt_product_contact_desktop_tab',
                'label'     => 'Desktop (mỗi nhóm có Phone + Zalo)',
                'name'      => '',
                'type'      => 'tab',
                'placement' => 'top',
                'endpoint'  => 0,
            ),
            array(
                'key'           => 'field_hpt_product_contact_quote_phone',
                'label'         => 'Số điện thoại - Liên hệ báo giá',
                'name'          => 'product_contact_quote_phone',
                'type'          => 'text',
                'instructions'  => '<strong>Phòng kinh doanh</strong> — số dùng cho nút Gọi trên desktop.',
                'required'      => 1,
                'default_value' => '0866555212',
                'placeholder'   => '0866555212',
                'prepend'       => 'Phone',
                'wrapper'       => array(
                    'width' => '50',
                    'class' => '',
                    'id'    => '',
                ),
            ),
            array(
                'key'           => 'field_hpt_product_contact_quote_zalo',
                'label'         => 'Số Zalo - Liên hệ báo giá',
                'name'          => 'product_contact_quote_zalo',
                'type'          => 'text',
                'instructions'  => '<strong>Phòng kinh doanh</strong> — số dùng cho nút Zalo trên desktop. Có thể khác số điện thoại.',
                'required'      => 1,
                'default_value' => '0866555212',
                'placeholder'   => '0866555212',
                'prepend'       => 'Zalo',
                'wrapper'       => array(
                    'width' => '50',
                    'class' => '',
                    'id'    => '',
                ),
            ),
            array(
                'key'           => 'field_hpt_product_contact_technical_phone',
                'label'         => 'Số điện thoại - Tư vấn kỹ thuật và bảo hành',
                'name'          => 'product_contact_technical_phone',
                'type'          => 'text',
                'instructions'  => '<strong>Bộ phận kỹ thuật &amp; bảo hành</strong> — số dùng cho nút Gọi trên desktop.',
                'required'      => 1,
                'default_value' => '0979316108',
                'placeholder'   => '0979316108',
                'prepend'       => 'Phone',
                'wrapper'       => array(
                    'width' => '50',
                    'class' => '',
                    'id'    => '',
                ),
            ),
            array(
                'key'           => 'field_hpt_product_contact_technical_zalo',
                'label'         => 'Số Zalo - Tư vấn kỹ thuật và bảo hành',
                'name'          => 'product_contact_technical_zalo',
                'type'          => 'text',
                'instructions'  => '<strong>Bộ phận kỹ thuật &amp; bảo hành</strong> — số dùng cho nút Zalo trên desktop. Có thể khác số điện thoại.',
                'required'      => 1,
                'default_value' => '0979316108',
                'placeholder'   => '0979316108',
                'prepend'       => 'Zalo',
                'wrapper'       => array(
                    'width' => '50',
                    'class' => '',
                    'id'    => '',
                ),
            ),
            array(
                'key'       => 'field_hpt_product_contact_email_tab',
                'label'     => 'Email',
                'name'      => '',
                'type'      => 'tab',
                'placement' => 'top',
                'endpoint'  => 0,
            ),
            array(
                'key'           => 'field_67caac7c22750',
                'label'         => 'Email liên hệ',
                'name'          => 'email_lien_he',
                'type'          => 'email',
                'required'      => 1,
                'default_value' => 'kinhdoanhhpt@haiphongtech.vn',
                'placeholder'   => 'kinhdoanhhpt@haiphongtech.vn',
            ),
        ),
        'location'              => array(
            array(
                array(
                    'param'    => 'options_page',
                    'operator' => '==',
                    'value'    => 'setting-page',
                ),
            ),
        ),
        'menu_order'            => 1,
        'position'              => 'normal',
        'style'                 => 'default',
        'label_placement'       => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen'        => array(),
        'active'                => true,
        'description'           => 'Số điện thoại, Zalo và email hiển thị trên trang sản phẩm.',
        'show_in_rest'          => 0,
    ));
}
add_action('acf/init', 'hpt_register_product_contact_settings_fields', 20);

/**
 * Chuẩn hóa số cho tel: và zalo.me, vẫn hỗ trợ số quốc tế có dấu + ở đầu.
 */
function hpt_product_contact_phone_href($phone) {
    $phone    = trim((string) $phone);
    $has_plus = strpos($phone, '+') === 0;
    $digits   = preg_replace('/\D+/', '', $phone);

    if ($digits === '') {
        return '';
    }

    return ($has_plus ? '+' : '') . $digits;
}

/**
 * Format only a complete Vietnamese 10-digit phone number for visible text.
 * Keep extensions, labels, international values, and malformed values intact.
 */
function hpt_product_contact_phone_display($phone) {
    $phone = (string) $phone;
    $value = trim($phone);

    if (preg_match('/^0[0-9]{9}$/D', $value)) {
        return substr($value, 0, 4) . '.' . substr($value, 4, 3) . '.' . substr($value, 7, 3);
    }

    return $phone;
}

/**
 * Chỉ lấy giá trị đã lưu của field mới. Cách này giúp phân biệt giá trị thật
 * với default_value của ACF để ưu tiên dữ liệu repeater cũ ở lần nâng cấp đầu.
 */
function hpt_get_saved_product_contact_option($field_name) {
    $field_name = trim((string) $field_name);

    if ($field_name === '') {
        return '';
    }

    if (function_exists('get_option')) {
        return trim((string) get_option('options_' . $field_name, ''));
    }

    return function_exists('get_field')
        ? trim((string) get_field($field_name, 'option'))
        : '';
}

/**
 * Trả về cấu hình đã chuẩn hóa. Dữ liệu repeater Desktop cũ chỉ dùng fallback
 * để website không bị trống trước lần lưu Setting Page đầu tiên.
 */
function hpt_get_product_contact_settings() {
    $defaults = array(
        'mobile_phone'          => '0866555212',
        'mobile_zalo'           => '0866555212',
        'quote_phone'           => '0866555212',
        'quote_zalo'            => '0866555212',
        'technical_phone'       => '0979316108',
        'technical_zalo'        => '0979316108',
        'email'                 => 'kinhdoanhhpt@haiphongtech.vn',
    );

    if (!function_exists('get_field')) {
        return $defaults;
    }

    $legacy_desktop = get_field('desktop', 'option');
    $legacy_quote   = '';
    $legacy_tech    = '';

    if (is_array($legacy_desktop)) {
        if (!empty($legacy_desktop[0]['phone'])) {
            $legacy_quote = trim((string) $legacy_desktop[0]['phone']);
        }

        if (!empty($legacy_desktop[1]['phone'])) {
            $legacy_tech = trim((string) $legacy_desktop[1]['phone']);
        }
    }

    if (function_exists('get_option')) {
        if ($legacy_quote === '') {
            $legacy_quote = trim((string) get_option('options_desktop_0_phone', ''));
        }

        if ($legacy_tech === '') {
            $legacy_tech = trim((string) get_option('options_desktop_1_phone', ''));
        }
    }

    $quote_phone = hpt_get_saved_product_contact_option('product_contact_quote_phone');
    if ($quote_phone === '') {
        $quote_phone = $legacy_quote !== '' ? $legacy_quote : $defaults['quote_phone'];
    }

    $quote_zalo = hpt_get_saved_product_contact_option('product_contact_quote_zalo');
    if ($quote_zalo === '') {
        $quote_zalo = $quote_phone;
    }

    $technical_phone = hpt_get_saved_product_contact_option('product_contact_technical_phone');
    if ($technical_phone === '') {
        $technical_phone = $legacy_tech !== '' ? $legacy_tech : $defaults['technical_phone'];
    }

    $technical_zalo = hpt_get_saved_product_contact_option('product_contact_technical_zalo');
    if ($technical_zalo === '') {
        $technical_zalo = $technical_phone;
    }

    $mobile_phone = hpt_get_saved_product_contact_option('product_contact_mobile_phone');
    if ($mobile_phone === '') {
        $mobile_phone = $quote_phone;
    }

    $mobile_zalo = hpt_get_saved_product_contact_option('product_contact_mobile_zalo');
    if ($mobile_zalo === '') {
        $mobile_zalo = $mobile_phone;
    }

    $email = trim((string) get_field('email_lien_he', 'option'));
    if ($email === '') {
        $email = $defaults['email'];
    }

    return array(
        'mobile_phone'    => $mobile_phone,
        'mobile_zalo'     => $mobile_zalo,
        'quote_phone'     => $quote_phone,
        'quote_zalo'      => $quote_zalo,
        'technical_phone' => $technical_phone,
        'technical_zalo'  => $technical_zalo,
        'email'           => $email,
    );
}

