<div class="brand-filter">
    <ul style="
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: 5px;
        padding: 2px;
        list-style: none;
        align-items: center;
        justify-items: center;
    ">
    <?php
    $desired_order = [
        'AUTONICS', 'OMRON', 'PANASONIC', 'KEYENCE', 'MOLEX',
        'JST', 'COGNEX', 'PROFACE', 'SAMWON', 'SAFEX',
        'KG AUTO', 'MITSUBISHI', 'ROOTECH', 'WOONYOUNG', 'HONEYWELL',
        'JEONO', 'KACON', 'WOONSHIN', 'KOINO', 'QLIGHT'
    ];

    if (!empty($brands) && !is_wp_error($brands)) {

        usort($brands, function($a, $b) use ($desired_order) {
            $pos_a = array_search(strtoupper(trim($a->name)), $desired_order);
            $pos_b = array_search(strtoupper(trim($b->name)), $desired_order);

            $pos_a = $pos_a === false ? PHP_INT_MAX : $pos_a;
            $pos_b = $pos_b === false ? PHP_INT_MAX : $pos_b;

            return $pos_a - $pos_b;
        });

        foreach ($brands as $brand) {
            $brand_image_url = hpt_get_brand_avatar_url($brand->term_id);

            if ($brand_image_url) {
                ?>
                <li class="brand-item" data-brand-id="<?php echo esc_attr($brand->term_id); ?>">
                    <a href="javascript:void(0);"
                       class="filter-brand"
                       data-brand-id="<?php echo esc_attr($brand->term_id); ?>"
                       rel="nofollow">
                        <img src="<?php echo esc_url($brand_image_url); ?>"
                             alt="<?php echo esc_attr($brand->name); ?>"
                             loading="lazy"
                             decoding="async">
                    </a>

                    <span class="brand-remove">✔</span>
                </li>
                <?php
            }
        }
    } else {
        echo '<p>Không tìm thấy thương hiệu nào.</p>';
    }
    ?>
    </ul>
</div>

<style>
.brand-filter ul {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
    gap: 5px;
    padding: 2px;
    list-style: none;
    align-items: center;
    justify-items: center;
}

.brand-item {
    position: relative !important;
    width: 100px !important;
    height: 45px !important;
    list-style: none !important;
    border: 1px solid #0284C7;
    padding: 3px;
    display: flex;
    justify-content: center;
    align-items: center;
}

.brand-item a {
    display: block;
    width: 100%;
    height: 100%;
    position: relative;
}

.brand-item img {
    position: absolute !important;
    width: 99% !important;
    height: 99% !important;
    object-fit: contain !important;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
}

.brand-item.active {
    background: rgba(0, 0, 0, 0.1);
}

.brand-remove {
    position: absolute;
    right: -10px;
    top: -10px;
    cursor: pointer;
    display: none;
    color: red;
    font-size: 22px;
    margin-left: 4px;
    z-index: 5;
}

.brand-item.active .brand-remove {
    display: block;
}
</style>


<div class="product_cat" style="display:none;">
    <h4 class="product_cat_title"><span class="dashicons dashicons-portfolio"></span> Danh mục nhóm sản phẩm</h4>
<div class="product_cat_items">
</div>
</div>
