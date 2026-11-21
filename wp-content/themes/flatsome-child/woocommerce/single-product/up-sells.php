<?php
/**
 * Single Product Up-Sells
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/up-sells.php.
 *
 * @see              https://docs.woocommerce.com/document/template-structure/
 * @package          WooCommerce/Templates
 * @version          3.0.0
 * @flatsome-version 3.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( $upsells ) : ?>

    <?php
    if ( get_theme_mod( 'product_upsell', 'sidebar' ) !== 'sidebar' ) :

        $type             = get_theme_mod( 'related_products', 'slider' );
        $repeater_classes = array();

        if ( $type == 'grid' ) {
            $type = 'row';
        }

        if ( get_theme_mod('category_force_image_height' ) ) $repeater_classes[] = 'has-equal-box-heights';
        if ( get_theme_mod('equalize_product_box' ) ) $repeater_classes[] = 'equalize-box';

        $repeater['type']         = $type;
        $repeater['columns']      = get_theme_mod( 'related_products_pr_row', 4 );
        $repeater['columns__md']  = get_theme_mod( 'related_products_pr_row_tablet', 3 );
        $repeater['columns__sm']  = get_theme_mod( 'related_products_pr_row_mobile', 2 );
        $repeater['class']        = implode( ' ', $repeater_classes );
        $repeater['slider_style'] = 'reveal';
        $repeater['row_spacing']  = 'small';

        if ( count( $upsells ) < $repeater['columns'] ) {
            $repeater['type'] = 'row';
        }
        ?>
        <div class="up-sells upsells products upsells-wrapper product-section">
            <?php
            $heading = apply_filters( 'woocommerce_product_upsells_products_heading', __( 'You may also like&hellip;', 'woocommerce' ) );

            if ( $heading ) :
                ?>
                <h3 class="product-section-title product-section-title-upsell pt-half pb-half uppercase">
                    <?php echo esc_html( $heading ); ?>
                </h3>
            <?php endif; ?>

            <?php get_flatsome_repeater_start( $repeater ); ?>

            <?php foreach ( $upsells as $upsell ) :
                $post_object = get_post( $upsell->get_id() );

                setup_postdata( $GLOBALS['post'] =& $post_object ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, Squiz.PHP.DisallowMultipleAssignments.Found

                wc_get_template_part( 'content', 'product' );
            endforeach;
            ?>

            <?php get_flatsome_repeater_end( $repeater ); ?>

            <!-- Thêm nút "Xem thêm" -->
            <div class="text-center mt-3">
                <button id="load-more-button" style="display:block; margin:20px auto; padding:10px 20px; background-color:#007bff; color:white; border:none; cursor:pointer; font-size:16px;">Xem thêm</button>
            </div>
        </div>
    <?php else : ?>

        <aside class="widget widget-upsell">
            <?php
            $heading = apply_filters( 'woocommerce_product_upsells_products_heading', __( 'You may also like&hellip;', 'woocommerce' ) );

            if ( $heading ) :
                ?>
                <h3 class="widget-title shop-sidebar">
                    <?php echo esc_html( $heading ); ?>
                    <div class="is-divider small"></div>
                </h3>
            <?php endif; ?>
            <!-- Upsell List style -->
            <ul class="product_list_widget">
                <?php foreach ( $upsells as $upsell ) : ?>

                    <?php
                    $post_object = get_post( $upsell->get_id() );

                    setup_postdata( $GLOBALS['post'] =& $post_object ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, Squiz.PHP.DisallowMultipleAssignments.Found

                    wc_get_template_part( 'content', 'product-small' );
                    ?>

                <?php endforeach; ?>
            </ul>
            
            <!-- Thêm nút "Xem thêm" -->
            <div class="text-center mt-3">
                <button id="load-more-button" style="display:block; margin:20px auto; padding:10px 20px; background-color:#007bff; color:white; border:none; cursor:pointer; font-size:16px;">Xem thêm</button>
            </div>

        </aside>

    <?php endif; ?>

    <?php
endif;

wp_reset_postdata();
?>

<!-- AJAX script to load more products -->
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    var button = document.getElementById('load-more-button');
    var offset = 6; // Bắt đầu từ sản phẩm thứ 6

    button.addEventListener('click', function() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

        xhr.onload = function() {
            if (xhr.status === 200) {
                var response = JSON.parse(xhr.responseText);
                var container = document.querySelector('.up-sells .products');

                response.data.forEach(function(product) {
                    var div = document.createElement('div');
                    div.innerHTML = product.html;
                    container.appendChild(div.firstChild);
                });

                offset += 6; // Cập nhật offset
            }
        };

        xhr.send('action=load_more_upsells&offset=' + offset);
    });
});
</script>
