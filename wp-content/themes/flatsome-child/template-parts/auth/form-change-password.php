<form method="post" class="woocommerce-ResetPassword lost_reset_password">
        <?php wp_nonce_field( 'hpt_change_password', 'hpt_change_password_nonce' ); ?>
        <p>
            <input type="password" name="password_current" id="password_current"  autocomplete="current-password" placeholder="Mật khẩu hiện tại" class="!tw-rounded-[5px]"/>
        </p>
        <p>
            <input type="password" name="password_1" id="password_1" autocomplete="new-password" placeholder="Mật khẩu mới"  class="!tw-rounded-[5px]" />
        </p>
        <p>
            <input type="password" name="password_2" id="password_2" autocomplete="new-password" placeholder="Xác nhận mật khẩu mới"  class="!tw-rounded-[5px]"/>
        </p>
        <p>
            <button type="submit" class="woocommerce-Button button" name="save_password"><?php _e( 'Lưu mật khẩu', 'woocommerce' ); ?></button>
        </p>
    </form>
