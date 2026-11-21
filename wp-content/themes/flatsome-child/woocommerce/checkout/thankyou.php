<?php
/**
 * Thankyou page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/thankyou.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see              https://docs.woocommerce.com/document/template-structure/
 * @package          WooCommerce\Templates
 * @version          8.1.0
 * @flatsome-version 3.17.7
 *
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="woocommerce-order row">

	<?php
	if ( $order ) :

		do_action( 'woocommerce_before_thankyou', $order->get_id() );
		?>
	
		<?php if ( $order->has_status( 'failed' ) ) : ?>
			<div class="large-12 col order-failed">
				<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed"><?php esc_html_e( 'Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce' ); ?></p>

				<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions">
					<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="button pay"><?php esc_html_e( 'Pay', 'woocommerce' ); ?></a>
					<?php if ( is_user_logged_in() ) : ?>
						<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="button pay"><?php esc_html_e( 'My account', 'woocommerce' ); ?></a>
					<?php endif; ?>
				</p>
			</div>

		<?php else : ?>
		<div class="large-7 col tw-mx-auto">
			<?php if ( fl_woocommerce_version_check( '8.1' ) ) : ?>
				<div class="tw-text-center">
					<svg class="lg:tw-w-[120px] tw-w-[70px]" viewBox="0 0 148 148" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M65.3673 102.367L108.842 58.8916L100.209 50.2582L65.3673 85.0999L47.7923 67.5249L39.159 76.1582L65.3673 102.367ZM74.0006 135.667C65.4701 135.667 57.4534 134.048 49.9506 130.81C42.4479 127.573 35.9215 123.179 30.3715 117.629C24.8215 112.079 20.4277 105.553 17.1902 98.0499C13.9527 90.5471 12.334 82.5305 12.334 73.9999C12.334 65.4694 13.9527 57.4527 17.1902 49.9499C20.4277 42.4471 24.8215 35.9207 30.3715 30.3708C35.9215 24.8208 42.4479 20.427 49.9506 17.1895C57.4534 13.952 65.4701 12.3333 74.0006 12.3333C82.5312 12.3333 90.5479 13.952 98.0506 17.1895C105.553 20.427 112.08 24.8208 117.63 30.3708C123.18 35.9207 127.574 42.4471 130.811 49.9499C134.049 57.4527 135.667 65.4694 135.667 73.9999C135.667 82.5305 134.049 90.5471 130.811 98.0499C127.574 105.553 123.18 112.079 117.63 117.629C112.08 123.179 105.553 127.573 98.0506 130.81C90.5479 134.048 82.5312 135.667 74.0006 135.667ZM74.0006 123.333C87.7729 123.333 99.4381 118.554 108.996 108.996C118.555 99.4374 123.334 87.7721 123.334 73.9999C123.334 60.2277 118.555 48.5624 108.996 39.0041C99.4381 29.4458 87.7729 24.6666 74.0006 24.6666C60.2284 24.6666 48.5631 29.4458 39.0048 39.0041C29.4465 48.5624 24.6673 60.2277 24.6673 73.9999C24.6673 87.7721 29.4465 99.4374 39.0048 108.996C48.5631 118.554 60.2284 123.333 74.0006 123.333Z" fill="#0284C7"/>
					</svg>
				</div>
					<h2 class="tw-text-center tw-uppercase tw-text-[20px] lg:tw-text-[40px] tw-text-[#171717]">Bạn đã thanh toán thành công</h2>
				<?php else : ?>
					<p class="success-color woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received"><span><?php echo apply_filters( 'woocommerce_thankyou_order_received_text', esc_html__( 'Thank you. Your order has been received.', 'woocommerce' ), $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></p>
				<?php endif; ?>
			<div class="tw-bg-[#0284C7]">
				<h3 class="tw-text-center tw-mb-0 tw-py-4 tw-uppercase tw-text-[18px] lg:tw-text-[24px] tw-text-[#FFFFFF]">Thông tin đơn hàng</h3>
			</div>
			<div class="is-well col-inner entry-content">
				
				<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details *:tw-list-none *:tw-grid *:tw-grid-cols-3 *:tw-text-[#171717] *:tw-font-semibold">
					<li class="woocommerce-order-overview__order order">
						<?php esc_html_e( 'Order number:', 'woocommerce' ); ?>
						<span class="tw-col-span-2"><?php echo $order->get_order_number(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</li>
					<li class="woocommerce-order-overview__total total">
						<?php esc_html_e( 'Total:', 'woocommerce' ); ?>
						<span class="tw-col-span-2"><?php echo $order->get_formatted_order_total(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</li>
					<?php if ( is_user_logged_in() && $order->get_user_id() === get_current_user_id() && $order->get_billing_email() ) : ?>
						<li class="woocommerce-order-overview__email email">
							<?php esc_html_e( 'Email:', 'woocommerce' ); ?>
							<span><?php echo $order->get_billing_email(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</li>
					<?php endif; ?>	
					<!-- Họ và tên -->
					<li class="woocommerce-order-overview__name name">
						<?php esc_html_e( 'Full Name:', 'woocommerce' ); ?>
						<span class="tw-col-span-2"><?php echo esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?></span>
					</li>

					<!-- Số điện thoại -->
					<li class="woocommerce-order-overview__phone phone">
						<?php esc_html_e( 'Phone:', 'woocommerce' ); ?>
						<span class="tw-col-span-2"><?php echo esc_html( $order->get_billing_phone() ); ?></span>
					</li>

					<!-- Địa chỉ -->
					<li class="woocommerce-order-overview__address address">
						<?php esc_html_e( 'Address:', 'woocommerce' ); ?>
						<div class="tw-col-span-2">
							<span><?php echo esc_html( $order->get_billing_address_1() ); ?></span>
							<?php if ( $order->get_billing_address_2() ) : ?>
								, <span><?php echo esc_html( $order->get_billing_address_2() ); ?></span>
							<?php endif; ?>
							<?php if ( $order->get_billing_city() ) : ?>
								, <?php echo esc_html( $order->get_billing_city() ); ?>
							<?php endif; ?>
							<?php if ( $order->get_billing_state() ) : ?>
								, <?php echo esc_html( $order->get_billing_state() ); ?>
							<?php endif; ?>
							<?php if ( $order->get_billing_postcode() ) : ?>
								, <?php echo esc_html( $order->get_billing_postcode() ); ?>
							<?php endif; ?>
						</div>
					</li>
				</ul>

				<div class="clear"></div>
			</div>
		</div>

		<?php endif; ?>
	
		<?php echo do_shortcode('[block id="cac-doi-tac-cua-chung-toi"]') ?>

	<?php else : ?>

		<?php if ( fl_woocommerce_version_check( '8.1' ) ) : ?>
			<?php wc_get_template( 'checkout/order-received.php', array( 'order' => false ) ); ?>
		<?php else : ?>
			<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received"><?php echo apply_filters( 'woocommerce_thankyou_order_received_text', esc_html__( 'Thank you. Your order has been received.', 'woocommerce' ), null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
		<?php endif; ?>

	<?php endif; ?>

</div>
