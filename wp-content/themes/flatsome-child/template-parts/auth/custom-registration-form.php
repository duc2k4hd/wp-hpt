<div class="tw-max-w-[688px] tw-mx-auto tw-border-solid tw-border-[1px] tw-border-[#0284C7] tw-rounded-[5px] tw-p-8">
        <div class="register-header tw-text-center tw-mb-3">
            <h1 class="tw-text-[#171717] tw-text-[24px] lg:tw-text-[32px] tw-font-bold tw-uppercase">Đăng ký tài khoản</h1>
            <p class="tw-text-base tw-text-[#A3A3A3] tw-font-[400]">Đăng ký ngay để nhận những ưu đãi đến từ Haiphongtech</p>
        </div>
        <form id="custom_registration_form" method="post">
            <div>
                <label for="name">Họ và tên *</label>
                <input type="text" name="name" placeholder="Tên người dùng" required>
                <p class="error-message" id="fullname_message"></p>
            </div>
            <div>
                <label for="phone">Số điện thoại *</label>
                <input type="text" name="phone" placeholder="Số điện thoại" required>
                <p class="error-message" id="phone_message"></p>
            </div>
            <div>
                <label for="email">Email *</label>
                <input type="email" name="email" id="email" placeholder="Email" required>
                <p class="error-message" id="email_message"></p> <!-- Thông báo cho email -->
            </div>
            <div>
                <label for="password">Mật khẩu *</label>
                <input type="password" name="password" id="password" placeholder="Mật khẩu" required>
                <p class="error-message" id="password_message"></p> <!-- Thông báo cho mật khẩu -->
            </div>
            <div>
                <label for="confirm_password">Xác nhận lại Mật khẩu *</label>
                <input type="password" name="confirm_password" placeholder="Xác nhận mật khẩu" required>
                <p class="error-message" id="confirm_password_message"></p>
            </div>
            <input type="hidden" name="action" value="custom_register_user">
            <input type="hidden" name="hpt_registration_nonce" value="<?php echo esc_attr( wp_create_nonce( 'hpt_custom_registration' ) ); ?>">
            <button type="submit" class="tw-w-full tw-bg-[#0284C7] tw-rounded-[5px] tw-text-white">Đăng ký</button>
        </form>
        <div id="success_message" style="display:none; margin-top: 10px;" class="tw-text-green-500"></div> <!-- Success message container -->
    </div>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#custom_registration_form').on('submit', function(e) {
                e.preventDefault(); // Prevent default form submission
                console.log("Form đã được gửi"); // Log for testing

                var formData = $(this).serialize();
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', formData, function(response) {
                    console.log(response); // Show response in console

                    // Clear old messages
                    $('.error-message').html('');
                    $('#success_message').hide().html(''); // Clear previous success message

                    if (response.success) {
                        $('#success_message').text(response.data.message || '').show(); // Show success message
                    } else {
                        // Display error messages for respective fields
                        if (response.data.field === 'email') {
                            $('#email_message').text(response.data.message || '');
                        } else if (response.data.field === 'password') {
                            $('#password_message').text(response.data.message || '');
                        } else if (response.data.field === 'fullname') {
                            $('#fullname_message').text(response.data.message || '');
                        } else if (response.data.field === 'phone') {
                            $('#phone_message').text(response.data.message || '');
                        } else if (response.data.field === 'confirm_password') {
                            $('#confirm_password_message').text(response.data.message || '');
                        }
                    }
                }).fail(function(xhr, status, error) {
                    console.error("Lỗi AJAX:", error); // Log AJAX error
                });
            });
        });
    </script>
