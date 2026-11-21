<?php
/**
 * Single Product tabs
 *
 * @see              https://docs.woocommerce.com/document/template-structure/
 * @package          WooCommerce/Templates
 * @version          3.8.0
 * @flatsome-version 3.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tabs_style = get_theme_mod( 'product_display', 'tabs' );

// Get sections instead of tabs if set.
if ( $tabs_style == 'sections' ) {
	wc_get_template_part( 'single-product/tabs/sections' );
	return;
}

// Get accordion instead of tabs if set.
if ( $tabs_style == 'accordian' || $tabs_style == 'accordian-collapsed' ) {
	wc_get_template_part( 'single-product/tabs/accordian' );
	return;
}

/**
 * Filter tabs and allow third parties to add their own.
 *
 * Each tab is an array containing title, callback and priority.
 *
 * @see woocommerce_default_product_tabs()
 */
$product_tabs = apply_filters( 'woocommerce_product_tabs', array() );

$tab_count   = 0;
$panel_count = 0;

if ( ! empty( $product_tabs ) ) : ?>
	<div class="woocommerce-tabs wc-tabs-wrapper container tabbed-content ">
		<ul class="tabs wc-tabs product-tabs small-nav-collapse <?php flatsome_product_tabs_classes(); ?>" role="tablist">
			<?php foreach ( $product_tabs as $key => $product_tab ) : ?>
				<?php if ( is_array( $product_tab ) && isset( $product_tab['title'] ) ) : ?>
					<li class="<?php echo esc_attr( $key ); ?>_tab <?php if ( $tab_count == 0 ) echo 'active'; ?> !tw-w-auto" id="tab-title-<?php echo esc_attr( $key ); ?> " role="presentation">
						<a href="#tab-<?php echo esc_attr( $key ); ?>" role="tab" aria-selected="<?php echo $tab_count == 0 ? 'true' : 'false'; ?>" aria-controls="tab-<?php echo esc_attr( $key ); ?>"<?php echo $tab_count != 0 ? ' tabindex="-1"' : ''; ?>>
							<?php echo wp_kses_post( apply_filters( 'woocommerce_product_' . $key . '_tab_title', $product_tab['title'], $key ) ); ?>
						</a>
					</li>
					<?php $tab_count++; ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>

		<div class="tab-panels !tw-px-0 lg:!tw-px-2 !tw-py-4">
			<?php foreach ( $product_tabs as $key => $product_tab ) : ?>
				<?php if ( is_array( $product_tab ) ) : ?>
					<div class="woocommerce-Tabs-panel woocommerce-Tabs-panel--<?php echo esc_attr( $key ); ?> panel entry-content <?php if ( $panel_count == 0 ) echo 'active'; ?>" id="tab-<?php echo esc_attr( $key ); ?>" role="tabpanel" aria-labelledby="tab-title-<?php echo esc_attr( $key ); ?>">
						<?php if ( $key == 'description' && ux_builder_is_active() ) echo flatsome_dummy_text(); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped ?>
						<?php
						if ( isset( $product_tab['callback'] ) ) {
							call_user_func( $product_tab['callback'], $key, $product_tab );
						}
						?>
					</div>
					<?php $panel_count++; ?>
				<?php endif; ?>
			<?php endforeach; ?>

			<?php do_action( 'woocommerce_product_after_tabs' ); ?>
		</div>

		<div class="tw-w-full tw-mt-4 single-product-table">
			<?php
			$table_field = get_field( 'tablepress' );
			$tbid = is_array( $table_field ) && isset( $table_field[0] ) ? $table_field[0] : null;

			if ( $tbid ) {
				echo do_shortcode( get_tablepress_id( $tbid ) );
			}
			?>
		</div>
	</div>
<?php endif; ?>
