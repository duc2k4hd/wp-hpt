<?php
/* HPT_BULK_REST_BRANDS_BEGIN */
// The WooCommerce native "brands" field uses product_brand. HPT uses thuong_hieu.
add_action('rest_api_init', 'hpt_register_product_brand_rest_field');
function hpt_register_product_brand_rest_field() {
    if (!taxonomy_exists('thuong_hieu')) {
        return;
    }
    register_rest_field('product', 'hpt_brand_names', array(
        'get_callback' => 'hpt_rest_get_product_brand_names',
        'update_callback' => 'hpt_rest_update_product_brand_names',
        'schema' => array(
            'description' => 'Tên thương hiệu thuộc taxonomy thuong_hieu của HPT.',
            'type' => 'array',
            'items' => array('type' => 'string'),
            'context' => array('view', 'edit'),
            'arg_options' => array('validate_callback' => 'hpt_rest_validate_product_brand_names'),
        ),
    ));
    register_rest_field('product', 'hpt_image_caption', array(
        'get_callback' => 'hpt_rest_get_product_image_caption',
        'update_callback' => 'hpt_rest_update_product_image_caption',
        'schema' => array(
            'description' => 'Caption và ALT dùng chung cho ảnh sản phẩm trong file nhập HPT.',
            'type' => 'string',
            'context' => array('view', 'edit'),
        ),
    ));
    register_rest_route('wc/v3', '/hpt/media', array(
        'methods' => 'GET',
        'permission_callback' => function () {
            return current_user_can('edit_products') && current_user_can('upload_files');
        },
        'args' => array('src' => array('type' => 'string', 'required' => true)),
        'callback' => function ($request) {
            // attachment_url_to_postid only searches local attachment metadata;
            // it does not fetch the supplied URL.
            $id = attachment_url_to_postid(esc_url_raw($request['src']));
            return rest_ensure_response(array('id' => $id, 'src' => $id ? wp_get_attachment_url($id) : ''));
        },
    ));
}
function hpt_rest_brand_product_id($object) {
    if (is_object($object) && method_exists($object, 'get_id')) {
        return (int) $object->get_id();
    }
    return is_array($object) ? absint($object['id'] ?? 0) : absint($object->ID ?? 0);
}
function hpt_rest_get_product_brand_names($object) {
    $names = wp_get_object_terms(hpt_rest_brand_product_id($object), 'thuong_hieu', array('fields' => 'names'));
    return is_wp_error($names) ? array() : $names;
}
function hpt_rest_validate_product_brand_names($names) {
    $taxonomy = get_taxonomy('thuong_hieu');
    if (!$taxonomy || !current_user_can($taxonomy->cap->assign_terms)) {
        return new WP_Error('hpt_brand_forbidden', 'Bạn không có quyền gán thương hiệu.', array('status' => 403));
    }
    if (!is_array($names) || count($names) > 50) {
        return new WP_Error('hpt_brand_invalid', 'Thương hiệu phải là danh sách tối đa 50 tên.', array('status' => 400));
    }
    foreach ($names as $name) {
        if (!is_string($name) || '' === trim($name) || strlen($name) > 200 || sanitize_text_field($name) !== $name) {
            return new WP_Error('hpt_brand_invalid', 'Tên thương hiệu không hợp lệ.', array('status' => 400));
        }
        if (!get_term_by('name', $name, 'thuong_hieu') && !get_term_by('slug', sanitize_title($name), 'thuong_hieu') && !current_user_can($taxonomy->cap->manage_terms)) {
            return new WP_Error('hpt_brand_forbidden', 'Bạn không có quyền tạo thương hiệu mới.', array('status' => 403));
        }
    }
    return true;
}
function hpt_rest_update_product_brand_names($names, $object) {
    $valid = hpt_rest_validate_product_brand_names($names);
    if (is_wp_error($valid)) {
        return $valid;
    }
    $ids = array();
    foreach ($names as $name) {
        $term = get_term_by('name', $name, 'thuong_hieu');
        if (!$term) {
            $term = get_term_by('slug', sanitize_title($name), 'thuong_hieu');
        }
        if ($term) {
            $ids[] = (int) $term->term_id;
            continue;
        }
        $created = wp_insert_term($name, 'thuong_hieu');
        if (is_wp_error($created)) {
            if ('term_exists' === $created->get_error_code() && $created->get_error_data()) {
                $ids[] = (int) $created->get_error_data();
                continue;
            }
            return $created;
        }
        $ids[] = (int) $created['term_id'];
    }
    $saved = wp_set_object_terms(hpt_rest_brand_product_id($object), array_values(array_unique($ids)), 'thuong_hieu', false);
    return is_wp_error($saved) ? $saved : true;
}
function hpt_rest_get_product_image_caption($object) {
    $product = wc_get_product(hpt_rest_brand_product_id($object));
    return $product && $product->get_image_id() ? (string) get_post_field('post_excerpt', $product->get_image_id(), 'raw') : '';
}
function hpt_rest_update_product_image_caption($caption, $object) {
    $product = wc_get_product(hpt_rest_brand_product_id($object));
    if (!$product) {
        return new WP_Error('hpt_caption_product_missing', 'Không tìm thấy sản phẩm để cập nhật caption.', array('status' => 400));
    }
    $ids = array_filter(array_unique(array_merge(array($product->get_image_id()), $product->get_gallery_image_ids())));
    foreach ($ids as $id) {
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('hpt_caption_forbidden', 'Không có quyền cập nhật ảnh #' . $id, array('status' => 403));
        }
    }
    foreach ($ids as $id) {
        $saved = wp_update_post(wp_slash(array('ID' => $id, 'post_excerpt' => wp_kses_post($caption))), true);
        if (is_wp_error($saved)) {
            return $saved;
        }
        update_post_meta($id, '_wp_attachment_image_alt', wp_slash(wp_strip_all_tags($caption)));
    }
    return true;
}
/* HPT_BULK_REST_BRANDS_END */

add_filter('woocommerce_product_export_column_names', 'add_custom_export_column');
add_filter( 'woocommerce_product_export_product_default_columns', 'add_custom_export_column' );
function add_custom_export_column($columns) {
    $columns['product_cat_ids'] = 'Danh mục theo ID';
    $columns['brand'] = 'Thương hiệu';
    $columns['logo'] = 'Logo';
    $columns['linktailieu'] = 'Link tài liệu';
    $columns['tongquan'] = 'Tổng quan';
    $columns['tablepress'] = 'Table Press';
    unset($columns['brand_ids']);
    return $columns;
}
// Thêm dữ liệu vào cột danh mục theo id
add_filter('woocommerce_product_export_product_column_product_cat_ids', 'export_data_product_cat_ids', 10, 2);
function export_data_product_cat_ids($value, $product) {
    // $value = $product->get_meta('_custom_meta_key', true);
    $terms = get_the_terms($product->get_id(), 'product_cat');
    $value = array();
    if (!empty($terms)) {
        foreach ($terms as $key => $term) {
            array_push($value,$term->term_id);
        }  
    }
    $value = implode("|",$value);
    return $value;
}
// Thêm dữ liệu vào cột brand
add_filter('woocommerce_product_export_product_column_brand', 'export_data_brand', 10, 2);
function export_data_brand($value, $product) {
    // $value = $product->get_meta('_custom_meta_key', true);
    $terms = get_the_terms($product->get_id(), 'thuong_hieu');
    $value = array();
    if (!empty($terms)) {
        foreach ($terms as $key => $term) {
            array_push($value,$term->name);
        }  
    }
    $value = implode(",",$value);
    return $value;
}
// Thêm dữ liệu vào cột logo
add_filter('woocommerce_product_export_product_column_logo', 'export_data_logo', 10, 2);
function export_data_logo($value, $product) {
    $logo = get_field('logo', $product->get_id());
    $value = array();
    if (!empty($logo)) {
        foreach ($logo as $item) {
            array_push($value,get_the_title($item));
        }
    }
    $value = implode(",",$value);
    return $value;
}
// Thêm dữ liệu vào cột Link tài liệu
add_filter('woocommerce_product_export_product_column_linktailieu', 'export_data_linktailieu', 10, 2);
function export_data_linktailieu($value, $product) {
    $value = get_field('link_pdf_product', $product->get_id());
    return function_exists('hpt_normalize_product_document_links')
        ? hpt_normalize_product_document_links($value)
        : trim((string) $value);
}
// Thêm dữ liệu vào cột tổng quan
add_filter('woocommerce_product_export_product_column_tongquan', 'export_data_tongquan', 10, 2);
function export_data_tongquan($value, $product) {
    $value = get_field('overview', $product->get_id());
    if (empty($value)) {
        $value = '';
    }
    return $value;
}
add_filter('woocommerce_product_export_product_column_tablepress', 'export_data_tablepress', 10, 2);
function export_data_tablepress($value, $product) {
    $table_field = get_field( 'tablepress', $product->get_id());
    if (empty($table_field)) {
        return "";
    }
    $value = is_array( $table_field ) && isset( $table_field[0] ) ? $table_field[0] : null;
    return get_tablepress_id($value,true);
}
// Xử lý import dữ liệu
add_filter('woocommerce_csv_product_import_mapping_options', 'add_custom_field_mapping_option');
function add_custom_field_mapping_option($options)
{
    // Thêm tùy chọn mới vào "Map to Field"
    $options['product_cat_ids'] = __('Danh mục theo ID', 'text-domain');
    $options['brand'] = __('Thương hiệu', 'text-domain');
    $options['logo'] = __('Logo', 'text-domain');
    $options['linktailieu'] = __('Link tài liệu', 'text-domain');
    $options['tongquan'] = __('Tổng quan', 'text-domain');
    $options['tablepress'] = __('Table Press', 'text-domain');
    return $options;
}
add_filter('woocommerce_csv_product_import_mapping_default_columns', 'map_custom_field_columns');
function map_custom_field_columns($columns)
{
    $columns['Danh mục theo ID'] = 'product_cat_ids';
    $columns['Thương hiệu'] = 'brand';
    $columns['Logo'] = 'logo';
    $columns['Link tài liệu'] = 'linktailieu';
    $columns['Tổng quan'] = 'tongquan';
    $columns['Table Press'] = 'tablepress';
    return $columns;
}
add_filter('woocommerce_product_import_pre_insert_product_object', 'customize_import_product', 10, 2);

function customize_import_product($product, $data)
{
    $tablepress = $data['tablepress'];
    $posttablepressid = get_post_by_tablepress($tablepress);
    $document_links = isset($data['linktailieu']) ? $data['linktailieu'] : '';
    if ($document_links !== ''){
        if (function_exists('hpt_normalize_product_document_links')) {
            $document_links = hpt_normalize_product_document_links($document_links);
        }

        // Ghi vào object để cả sản phẩm tạo mới (chưa có ID) cũng lưu đúng.
        $product->update_meta_data('link_pdf_product', $document_links);
        $product->update_meta_data('_link_pdf_product', 'field_66c749b6c8692');
    }
    if ($data['tongquan']){
        update_field('overview', $data['tongquan'], $product->id);
    }    
    if ($tablepress>0){
      update_field('tablepress', array($posttablepressid), $product->id);  
    }  
    $logo = explode(',', $data['logo']);
    $logoarr = array();
    foreach ($logo as $value) {
        $itemlogo = hpt_resolve_logo_id_by_title($value);
        if ($itemlogo>0){
              array_push($logoarr,$itemlogo);
        }
    }
    update_field('logo', $logoarr, $product->id);
    // Thương hiệu: gom toàn bộ term cần gán rồi set 1 lần để giảm số query.
    $term_names = explode(',', $data['brand']);
    $taxonomy   = 'thuong_hieu';
    $brand_ids  = array();
    foreach ($term_names as $value) {
        $brand_id = hpt_resolve_brand_term_id($value);
        if ($brand_id > 0) {
            $brand_ids[] = $brand_id;
        }
    }
    if (!empty($brand_ids)) {
        $brand_ids = array_values(array_unique(array_map('intval', $brand_ids)));
        // Chỉ ghi nếu khác dữ liệu đang có trong DB (tránh query/ghi thừa).
        if (!hpt_object_terms_unchanged($product->id, $taxonomy, $brand_ids)) {
            wp_set_object_terms($product->id, $brand_ids, $taxonomy);
        }
    }
    if ($data['product_cat_ids']){
       $term_namescat = array_values(array_filter(array_map('intval', explode('|', $data['product_cat_ids']))));
       // Chỉ cập nhật danh mục khi danh sách thay đổi.
       if (!hpt_object_terms_unchanged($product->id, 'product_cat', $term_namescat)) {
           wp_set_post_terms($product->id, $term_namescat, 'product_cat');
       }
    }
    if (isset($data['description'])) {
        $content = $data['description'];
        $alt = "";
        if (isset($data["name"])){
            $alt = ' alt="'.$data["name"].'"';
        }
        $newcontent = preg_replace_callback(
            '~^(https?:\/\/[^\s<"]+\.(?:jpg|jpeg|png|gif)(\?[^\s<"]*)?)$~im',
            function ($matches) use ($alt){
                return '<img src="' . esc_url($matches[1]) . '" '.$alt.'>';
            },
            $content
        );
        $newcontent = nl2br($newcontent);
        $product->set_description($newcontent);
    }
    return $product;
}
/**
 * So sánh danh sách term hiện có của 1 object với danh sách mới.
 * Trả về true nếu GIỐNG HỆT (không cần ghi lại) → bỏ qua query update thừa.
 *
 * @param int    $object_id
 * @param string $taxonomy
 * @param int[]  $new_ids
 * @return bool
 */
function hpt_object_terms_unchanged($object_id, $taxonomy, $new_ids) {
    $object_id = (int) $object_id;

    // Sản phẩm mới (chưa có ID) thì không có gì để so sánh → coi như cần ghi.
    if ($object_id <= 0) {
        return false;
    }

    $current = wp_get_object_terms($object_id, $taxonomy, array('fields' => 'ids'));

    if (is_wp_error($current)) {
        return false;
    }

    $current = array_map('intval', (array) $current);
    $new_ids = array_map('intval', (array) $new_ids);

    sort($current);
    sort($new_ids);

    return $current === $new_ids;
}

/**
 * Cache map TablePress (table_post) đã decode để tránh gọi get_option + json_decode
 * lặp lại trên từng dòng khi import/export hàng loạt sản phẩm.
 *
 * @return array{post_by_table:array<string,int>, table_by_post:array<int,string>}
 */
function hpt_get_tablepress_map() {
    static $map = null;

    if (null !== $map) {
        return $map;
    }

    $map = array(
        'post_by_table' => array(), // tablepress_id => post_id
        'table_by_post' => array(), // post_id => tablepress_id
    );

    $tableoption = get_option('tablepress_tables');
    $table       = is_string($tableoption) ? json_decode($tableoption, true) : (is_array($tableoption) ? $tableoption : null);

    if (is_array($table) && !empty($table['table_post']) && is_array($table['table_post'])) {
        foreach ($table['table_post'] as $table_id => $post_id) {
            $map['post_by_table'][(string) $table_id] = (int) $post_id;
            $map['table_by_post'][(int) $post_id]     = (string) $table_id;
        }
    }

    return $map;
}

function get_tablepress_id($postid,$returnid=false){
    if ($postid==null){
        return "";
    }

    $map     = hpt_get_tablepress_map();
    $tableid = isset($map['table_by_post'][(int) $postid]) ? $map['table_by_post'][(int) $postid] : 0;

    if ($returnid){
        return $tableid;
    }

    return "[table id=$tableid/]";
}
function get_post_by_tablepress($tablepressid){
    $map = hpt_get_tablepress_map();

    return isset($map['post_by_table'][(string) $tablepressid]) ? (int) $map['post_by_table'][(string) $tablepressid] : 0;
}

/**
 * Resolve ID của ảnh logo theo tiêu đề, có cache static để không lặp lại
 * post_exists() (quét wp_posts.post_title không index) trên từng dòng import.
 *
 * @param string $title
 * @return int
 */
function hpt_resolve_logo_id_by_title($title) {
    static $cache = array();

    $title = trim((string) $title);

    if ($title === '') {
        return 0;
    }

    if (array_key_exists($title, $cache)) {
        return $cache[$title];
    }

    $id            = (int) post_exists($title, '', '', 'logo');
    $cache[$title] = $id;

    return $id;
}

/**
 * Resolve term_id của thương hiệu theo tên, có cache static + ưu tiên khớp slug
 * để tránh tạo trùng term khi import hàng loạt. Tự tạo mới nếu chưa tồn tại.
 *
 * @param string $name
 * @return int 0 nếu không resolve/tạo được.
 */
function hpt_resolve_brand_term_id($name) {
    static $cache = array();

    $name = trim((string) $name);

    if ($name === '') {
        return 0;
    }

    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }

    $taxonomy = 'thuong_hieu';
    $term     = get_term_by('name', $name, $taxonomy);

    if (!$term) {
        // Thử khớp theo slug chuẩn hoá để bắt các biến thể khác hoa/thường, dấu cách.
        $term = get_term_by('slug', sanitize_title($name), $taxonomy);
    }

    if ($term && !is_wp_error($term)) {
        $cache[$name] = (int) $term->term_id;
        return $cache[$name];
    }

    $new_term = wp_insert_term($name, $taxonomy);

    if (!is_wp_error($new_term) && !empty($new_term['term_id'])) {
        $cache[$name] = (int) $new_term['term_id'];
        return $cache[$name];
    }

    $cache[$name] = 0;
    return 0;
}
add_shortcode("supportHPT", function () {
    $contactSettings   = hpt_get_product_contact_settings();
    $quotePhone       = $contactSettings['quote_phone'];
    $quotePhoneHref   = hpt_product_contact_phone_href($quotePhone);
    $quotePhoneDisplay = hpt_product_contact_phone_display($quotePhone);
    $quoteZalo        = $contactSettings['quote_zalo'];
    $quoteZaloHref    = hpt_product_contact_phone_href($quoteZalo);
    $technicalPhone   = $contactSettings['technical_phone'];
    $technicalPhoneHref = hpt_product_contact_phone_href($technicalPhone);
    $technicalPhoneDisplay = hpt_product_contact_phone_display($technicalPhone);
    $technicalZalo    = $contactSettings['technical_zalo'];
    $technicalZaloHref = hpt_product_contact_phone_href($technicalZalo);
    $contactEmail     = $contactSettings['email'];

    ob_start();
    ?>
    <aside class="haiphongtech_product_contact_card" aria-label="Chính sách bán hàng và thông tin liên hệ">
        <section class="haiphongtech_product_contact_section" aria-labelledby="haiphongtech-product-contact-quote-title">
            <div class="haiphongtech_product_contact_section_heading">
                <span class="haiphongtech_product_contact_section_icon" aria-hidden="true">
                    <svg class="haiphongtech_product_contact_icon" viewBox="0 0 24 24">
                        <path d="M21 11.5a8.4 8.4 0 0 1-9 8.3 9.6 9.6 0 0 1-4-.9L3 21l1.4-4.2A8.2 8.2 0 0 1 3 12.2 8.4 8.4 0 0 1 12 4a8.4 8.4 0 0 1 9 7.5Z" />
                    </svg>
                </span>
                <h3 class="haiphongtech_product_contact_section_title" id="haiphongtech-product-contact-quote-title">Liên hệ báo giá</h3>
            </div>

            <div class="haiphongtech_product_contact_person">
                <strong class="haiphongtech_product_contact_person_name">Phòng kinh doanh</strong>
                <span class="haiphongtech_product_contact_person_label">Phone</span>
                <a class="haiphongtech_product_contact_phone_number" href="tel:<?php echo esc_attr($quotePhoneHref); ?>">
                    <?php echo esc_html($quotePhoneDisplay); ?>
                </a>
                <div class="haiphongtech_product_contact_actions">
                    <a class="haiphongtech_product_contact_action" href="tel:<?php echo esc_attr($quotePhoneHref); ?>" aria-label="Gọi phòng kinh doanh số <?php echo esc_attr($quotePhone); ?>">
                        <svg class="haiphongtech_product_contact_action_icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="m7.2 3.5 3 4.2-2.4 2.4a15.5 15.5 0 0 0 6.1 6.1l2.4-2.4 4.2 3-1.4 3.4c-.3.7-1 1.1-1.7 1C9.7 20.5 3.5 14.3 2.8 6.6c-.1-.7.3-1.4 1-1.7l3.4-1.4Z" />
                        </svg>
                        <span>Gọi</span>
                    </a>
                    <a class="haiphongtech_product_contact_action" href="https://zalo.me/<?php echo esc_attr($quoteZaloHref); ?>" target="_blank" rel="noopener noreferrer" aria-label="Nhắn Zalo phòng kinh doanh số <?php echo esc_attr($quoteZalo); ?>">
                        <span class="haiphongtech_product_contact_zalo_mark" aria-hidden="true">Zalo</span>
                        <span>Zalo</span>
                    </a>
                </div>
                <a class="haiphongtech_product_contact_email" href="mailto:<?php echo esc_attr($contactEmail); ?>">
                    <svg class="haiphongtech_product_contact_email_icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 6h18v12H3V6Zm1 1 8 6 8-6" />
                    </svg>
                    <span><?php echo esc_html($contactEmail); ?></span>
                </a>
            </div>
        </section>

        <section class="haiphongtech_product_contact_section" aria-labelledby="haiphongtech-product-contact-technical-title">
            <div class="haiphongtech_product_contact_section_heading">
                <span class="haiphongtech_product_contact_section_icon" aria-hidden="true">
                    <svg class="haiphongtech_product_contact_icon" viewBox="0 0 24 24">
                        <path d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.4-6.4L17 7m-10 10-1.4 1.4m12.8 0L17 17M7 7 5.6 5.6M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z" />
                    </svg>
                </span>
                <h3 class="haiphongtech_product_contact_section_title" id="haiphongtech-product-contact-technical-title">Liên hệ tư vấn kỹ thuật và bảo hành</h3>
            </div>

            <div class="haiphongtech_product_contact_person">
                <strong class="haiphongtech_product_contact_person_name">Bộ phận kỹ thuật &amp; bảo hành</strong>
                <span class="haiphongtech_product_contact_person_label">Phone</span>
                <a class="haiphongtech_product_contact_phone_number" href="tel:<?php echo esc_attr($technicalPhoneHref); ?>">
                    <?php echo esc_html($technicalPhoneDisplay); ?>
                </a>
                <div class="haiphongtech_product_contact_actions">
                    <a class="haiphongtech_product_contact_action" href="tel:<?php echo esc_attr($technicalPhoneHref); ?>" aria-label="Gọi bộ phận kỹ thuật và bảo hành số <?php echo esc_attr($technicalPhone); ?>">
                        <svg class="haiphongtech_product_contact_action_icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="m7.2 3.5 3 4.2-2.4 2.4a15.5 15.5 0 0 0 6.1 6.1l2.4-2.4 4.2 3-1.4 3.4c-.3.7-1 1.1-1.7 1C9.7 20.5 3.5 14.3 2.8 6.6c-.1-.7.3-1.4 1-1.7l3.4-1.4Z" />
                        </svg>
                        <span>Gọi</span>
                    </a>
                    <a class="haiphongtech_product_contact_action" href="https://zalo.me/<?php echo esc_attr($technicalZaloHref); ?>" target="_blank" rel="noopener noreferrer" aria-label="Nhắn Zalo bộ phận kỹ thuật và bảo hành số <?php echo esc_attr($technicalZalo); ?>">
                        <span class="haiphongtech_product_contact_zalo_mark" aria-hidden="true">Zalo</span>
                        <span>Zalo</span>
                    </a>
                </div>
            </div>

            <ul class="haiphongtech_product_contact_note_list">
                <li class="haiphongtech_product_contact_note_item">Miễn phí tư vấn, hỗ trợ kỹ thuật từ xa 24/7.</li>
                <li class="haiphongtech_product_contact_note_item">Được trả sản phẩm nếu tư vấn không đúng.</li>
            </ul>
        </section>

        <header class="haiphongtech_product_contact_header">
            <span class="haiphongtech_product_contact_header_icon" aria-hidden="true">
                <svg class="haiphongtech_product_contact_icon" viewBox="0 0 24 24">
                    <path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 7H6.2M9.5 20a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0Zm9 0a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0Z" />
                </svg>
            </span>
            <h2 class="haiphongtech_product_contact_title">Chính sách bán hàng</h2>
        </header>

        <ul class="haiphongtech_product_contact_policy_list">
            <li class="haiphongtech_product_contact_policy_item">
                <svg class="haiphongtech_product_contact_policy_icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="m12 3 7 4v10l-7 4-7-4V7l7-4Zm0 0v8m7-4-7 4-7-4m7 4v10" />
                </svg>
                <span>Cam kết hàng chính hãng, sản phẩm mới chất lượng.</span>
            </li>
            <li class="haiphongtech_product_contact_policy_item">
                <svg class="haiphongtech_product_contact_policy_icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 3.5 15 5l3.3-.1.5 3.2 1.8 2.8-2.2 2.5-.8 3.2-3.3.3L12 19.5l-2.3-2.6-3.3-.3-.8-3.2-2.2-2.5 1.8-2.8.5-3.2L9 5l3-1.5Zm-2.2 7.4 1.5 1.5 3.2-3.2" />
                </svg>
                <span>Bảo hành tối đa theo chính sách của Hãng.</span>
            </li>
            <li class="haiphongtech_product_contact_policy_item">
                <svg class="haiphongtech_product_contact_policy_icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M20 7v5h-5M4 17v-5h5m9.2-3A7 7 0 0 0 6.1 6.6L4 9m16 6-2.1 2.4A7 7 0 0 1 5.8 15" />
                </svg>
                <span>Được đổi mới, trả lại nếu sản phẩm lỗi.</span>
            </li>
            <li class="haiphongtech_product_contact_policy_item">
                <svg class="haiphongtech_product_contact_policy_icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 6h11v11H3V6Zm11 4h3.5l3.5 3.5V17h-7v-7ZM7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm10 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" />
                </svg>
                <span>Giao hàng nhanh trên toàn quốc.</span>
            </li>
        </ul>
    </aside>

    <style>
        .haiphongtech_product_contact_card,
        .haiphongtech_product_contact_card * {
            box-sizing: border-box;
        }

        .haiphongtech_product_contact_card {
            width: 100%;
            overflow: hidden;
            border: 1px solid #dce3eb;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 14px rgba(15, 35, 58, .07);
            color: #172235;
            font-size: 14px;
            line-height: 1.45;
        }

        .haiphongtech_product_contact_header,
        .haiphongtech_product_contact_section_heading {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            padding: 13px 14px;
        }

        .haiphongtech_product_contact_header_icon,
        .haiphongtech_product_contact_section_icon {
            display: inline-flex;
            flex: 0 0 34px;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #f1f5f9;
            color: #50637b;
        }

        .haiphongtech_product_contact_icon {
            width: 21px;
            height: 21px;
            fill: none;
            stroke: currentColor;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-width: 1.8;
        }

        .haiphongtech_product_contact_title,
        .haiphongtech_product_contact_section_title {
            margin: 0 !important;
            padding: 0 !important;
            border: 0 !important;
            background: transparent !important;
            color: #172235 !important;
            font-weight: 700;
            line-height: 1.3;
            text-transform: uppercase;
        }

        .haiphongtech_product_contact_title {
            font-size: 17px;
        }

        .haiphongtech_product_contact_section_title {
            font-size: 14px;
        }

        .haiphongtech_product_contact_policy_list,
        .haiphongtech_product_contact_note_list {
            margin: 0;
            list-style: none;
        }

        .haiphongtech_product_contact_policy_list {
            padding: 0 14px 12px;
        }

        .haiphongtech_product_contact_policy_item {
            display: grid;
            grid-template-columns: 24px minmax(0, 1fr);
            gap: 9px;
            align-items: start;
            padding: 7px 0;
            color: #354154;
        }

        .haiphongtech_product_contact_policy_icon {
            width: 21px;
            height: 21px;
            margin-top: 1px;
            fill: none;
            stroke: #61738a;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-width: 1.75;
        }

        .haiphongtech_product_contact_section {
            border-top: 1px solid #dce3eb;
        }

        .haiphongtech_product_contact_section_heading {
            border-bottom: 1px solid #e8edf2;
        }

        .haiphongtech_product_contact_person {
            padding: 13px 14px;
        }

        .haiphongtech_product_contact_person_name,
        .haiphongtech_product_contact_person_label,
        .haiphongtech_product_contact_phone_number {
            display: block;
        }

        .haiphongtech_product_contact_person_name {
            margin-bottom: 2px;
            color: #172235;
            font-size: 14px;
            line-height: 1.35;
        }

        .haiphongtech_product_contact_person_label {
            color: #66758a;
            font-size: 12px;
        }

        .haiphongtech_product_contact_phone_number {
            margin-top: 1px;
            color: #183884;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: .2px;
            text-decoration: none;
        }

        .haiphongtech_product_contact_actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 7px;
            margin-top: 10px;
        }

        .haiphongtech_product_contact_action {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            gap: 6px;
            min-height: 42px;
            padding: 5px 8px;
            border: 1.5px solid #c9def2;
            border-radius: 8px;
            background: #f3f8fd;
            color: #0969c3;
            font-size: 16px;
            font-weight: 700;
            line-height: 1;
            text-align: center;
            text-decoration: none;
            transition: border-color .2s ease, background-color .2s ease, color .2s ease;
        }

        .haiphongtech_product_contact_action:hover,
        .haiphongtech_product_contact_action:focus {
            border-color: #0969c3;
            background: #0969c3;
            color: #fff;
        }

        .haiphongtech_product_contact_action_icon {
            width: 22px;
            height: 22px;
            fill: none;
            stroke: currentColor;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-width: 1.8;
        }

        .haiphongtech_product_contact_zalo_mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 25px;
            height: 25px;
            border: 1.8px solid currentColor;
            border-radius: 50%;
            font-size: 9px;
            font-weight: 700;
        }

        .haiphongtech_product_contact_email {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            margin-top: 10px;
            color: #40536a;
            font-size: 12px;
            line-height: 1.4;
            overflow-wrap: anywhere;
            text-decoration: none;
        }

        .haiphongtech_product_contact_email:hover,
        .haiphongtech_product_contact_email:focus {
            color: #0969c3;
        }

        .haiphongtech_product_contact_email_icon {
            flex: 0 0 17px;
            width: 17px;
            height: 17px;
            margin-top: 1px;
            fill: none;
            stroke: currentColor;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-width: 1.8;
        }

        .haiphongtech_product_contact_note_list {
            padding: 0 14px 13px 31px;
        }

        .haiphongtech_product_contact_note_item {
            position: relative;
            padding: 5px 0;
            color: #465469;
            font-size: 13px;
        }

        .haiphongtech_product_contact_note_item::before {
            position: absolute;
            top: 13px;
            left: -16px;
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #61738a;
            content: '';
        }

        @media (max-width: 849px) {
            .haiphongtech_product_contact_card {
                max-width: 520px;
                margin-right: auto;
                margin-left: auto;
            }
        }

        div#product-sidebar {
            padding: 0 10px !important;
        }
    </style>
    <?php
    return ob_get_clean();
});


add_shortcode("supportHPTMobile", function () {
    $contactSettings = hpt_get_product_contact_settings();
    $contactPhone    = $contactSettings['mobile_phone'];
    $contactPhoneHref = hpt_product_contact_phone_href($contactPhone);
    $contactZalo     = $contactSettings['mobile_zalo'];
    $contactZaloHref = hpt_product_contact_phone_href($contactZalo);

    ob_start();
    ?>
    <nav class="hpt-mobile-contact-bar" aria-label="Liên hệ nhanh">
        <a
            class="hpt-mobile-contact-bar__item"
            href="tel:<?php echo esc_attr($contactPhoneHref); ?>"
            aria-label="Gọi <?php echo esc_attr($contactPhone); ?>"
        >
            <svg class="hpt-mobile-contact-bar__phone" viewBox="0 0 32 32" aria-hidden="true">
                <path d="M10.1 4.5 14 10l-3.2 3.1c1.8 3.8 4.3 6.3 8.1 8.1L22 18l5.5 3.9-1.8 4.5c-.4 1-1.4 1.6-2.5 1.5C12.8 27 5 19.2 4.1 8.8 4 7.7 4.6 6.7 5.6 6.3l4.5-1.8Z" />
            </svg>
        </a>
        <a
            class="hpt-mobile-contact-bar__item hpt-mobile-contact-bar__item--zalo"
            href="https://zalo.me/<?php echo esc_attr($contactZaloHref); ?>"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="Nhắn Zalo <?php echo esc_attr($contactZalo); ?>"
        >
            <img
                class="hpt-mobile-contact-bar__zalo"
                src="/wp-content/uploads/2024/11/zalo-100x41.png"
                alt="Zalo"
            />
        </a>
    </nav>
    <style>
        .hpt-mobile-contact-bar {
            display: none;
        }

        @media (max-width: 1023px) {
            .hpt-mobile-contact-bar {
                position: fixed;
                right: 0;
                bottom: 0;
                left: 0;
                z-index: 99999;
                display: flex;
                width: 100%;
                height: calc(50px + env(safe-area-inset-bottom));
                padding-bottom: env(safe-area-inset-bottom);
                background: rgb(49 156 212);
                box-shadow: 0 -2px 8px rgba(0, 0, 0, .16);
            }

            .hpt-mobile-contact-bar__item {
                display: flex;
                flex: 1 1 50%;
                align-items: center;
                justify-content: center;
                min-width: 0;
                color: #8ed8f8;
                text-decoration: none;
                -webkit-tap-highlight-color: transparent;
            }

            .hpt-mobile-contact-bar__item:focus,
            .hpt-mobile-contact-bar__item:hover {
                color: #fff;
                background: rgba(255, 255, 255, .08);
            }

            .hpt-mobile-contact-bar__item--zalo {
                position: relative;
            }

            .hpt-mobile-contact-bar__item--zalo::before {
                position: absolute;
                top: 7px;
                bottom: 7px;
                left: 0;
                width: 1px;
                background: rgba(255, 255, 255, .9);
                content: '';
            }

            .hpt-mobile-contact-bar__phone {
                width: 30px;
                height: 30px;
                fill: none;
                stroke: currentColor;
                stroke-linecap: round;
                stroke-linejoin: round;
                stroke-width: 1.8;
            }

            .hpt-mobile-contact-bar__zalo {
                width: 62px;
                height: 30px;
                object-fit: contain;
                filter: brightness(0) invert(1);
                opacity: .82;
            }
        }
    </style>
    <?php
    return ob_get_clean();
});
// Custom permalinks
// function webops_sku_to_slug($sku) {
//     $slug = strtolower($sku);
//     $slug = str_replace(['/', '.'], '-', $slug);
//     $slug = preg_replace('/[^a-z0-9\- ]+/', '', $slug); // Loại bỏ ký tự đặc biệt
//     $slug = preg_replace('/[\s]+/', '-', $slug);        // Dấu cách thành gạch ngang
//     return $slug;
// }
// add_filter('post_type_link', function ($permalink, $post) {
//     if ('product' === $post->post_type) {
//         $sku = webops_sku_to_slug(get_post_meta($post->ID, '_sku', true));
//         if (!empty($sku)) {
//             return home_url(user_trailingslashit('product/' . $sku));
//         }
//     }
//     return $permalink;
// }, 10, 2);
// add_action('wp_insert_post', 'webops_update_slug_from_sku', 20, 3);

// function webops_update_slug_from_sku($post_id, $post, $update) {
//     if ($post->post_type !== 'product') return;
//     if (wp_is_post_revision($post_id) || $post->post_status === 'auto-draft') return;
//     $sku = get_post_meta($post_id, '_sku', true);
//     if (!$sku) return;
//     if ($post->post_name !== $sku) {
//         remove_action('wp_insert_post', 'webops_update_slug_from_sku', 20);        
//         wp_update_post([
//             'ID' => $post_id,
//             'post_name' => $sku
//         ]);
//         add_action('wp_insert_post', 'webops_update_slug_from_sku', 20, 3);
//     }
// }
