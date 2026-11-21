jQuery(document).ready(function ($) {
  const $form = $('#st-settings-form');
  const $status = $('#st-settings-status');
  const $submit = $('#st-settings-submit');

  if (!$form.length) return;

  // 1. Fetch current settings on page load
  function loadSettings() {
    $submit.prop('disabled', true);
    $status.text('Đang tải cấu hình...').css('color', '#787c82');

    $.ajax({
      url: STAdmin.settingsEndpoint,
      method: 'GET',
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', STAdmin.nonce);
      },
      success: function (res) {
        $('#st-setting-onsite').val(res.onsite_threshold);
        $('#st-setting-scroll').val(res.scroll_threshold);
        $('#st-setting-rate').val(res.rate_limit);
        $('#st-setting-toast').val(res.toast_duration || 5);
        $('#st-setting-payout').val(res.payout_per_kpi || 0);
        $('#st-setting-gear-pass').val(res.gear_password || '123456');
        $('#st-setting-sepay-token').val(res.sepay_webhook_token || '');
        $('#st-setting-sepay-reconcile').prop('checked', parseInt(res.sepay_auto_reconcile, 10) === 1);
        $('#st-setting-allowed-admins').val(res.allowed_admins || '');
        $('#st-setting-require-confirm').prop('checked', parseInt(res.require_confirmation, 10) === 1);
        
        $('#st-msg-require-google').val(res.msg_require_google || '');
        $('#st-show-msg-require-google').prop('checked', parseInt(res.show_msg_require_google ?? 1, 10) === 1);
        
        $('#st-msg-scroll-not-enough').val(res.msg_scroll_not_enough || '');
        $('#st-show-msg-scroll-not-enough').prop('checked', parseInt(res.show_msg_scroll_not_enough ?? 1, 10) === 1);
        
        $('#st-msg-invalid-staff').val(res.msg_invalid_staff || '');
        $('#st-show-msg-invalid-staff').prop('checked', parseInt(res.show_msg_invalid_staff ?? 1, 10) === 1);
        
        $('#st-msg-already-completed').val(res.msg_already_completed || '');
        $('#st-show-msg-already-completed').prop('checked', parseInt(res.show_msg_already_completed ?? 1, 10) === 1);
        
        $('#st-msg-not-in-mission').val(res.msg_not_in_mission || '');
        $('#st-show-msg-not-in-mission').prop('checked', parseInt(res.show_msg_not_in_mission ?? 1, 10) === 1);
        
        $('#st-msg-rate-limit').val(res.msg_rate_limit || '');
        $('#st-show-msg-rate-limit').prop('checked', parseInt(res.show_msg_rate_limit ?? 1, 10) === 1);
        
        $('#st-msg-success').val(res.msg_success || '');
        $('#st-show-msg-success').prop('checked', parseInt(res.show_msg_success ?? 1, 10) === 1);
        
        $('#st-msg-not-enough-conditions').val(res.msg_not_enough_conditions || '');
        $('#st-show-msg-not-enough-conditions').prop('checked', parseInt(res.show_msg_not_enough_conditions ?? 1, 10) === 1);
        
        $('#st-msg-connection-failed').val(res.msg_connection_failed || '');
        $('#st-show-msg-connection-failed').prop('checked', parseInt(res.show_msg_connection_failed ?? 1, 10) === 1);
        
        $('#st-msg-scroll-down-prompt').val(res.msg_scroll_down_prompt || '');
        $('#st-show-msg-scroll-down-prompt').prop('checked', parseInt(res.show_msg_scroll_down_prompt ?? 1, 10) === 1);
        
        $('#st-msg-stay-longer').val(res.msg_stay_longer || '');
        $('#st-show-msg-stay-longer').prop('checked', parseInt(res.show_msg_stay_longer ?? 1, 10) === 1);
        
        $('#st-msg-recording-traffic').val(res.msg_recording_traffic || '');
        $('#st-show-msg-recording-traffic').prop('checked', parseInt(res.show_msg_recording_traffic ?? 1, 10) === 1);
        
        $status.text('');
      },
      error: function (xhr) {
        let msg = 'Không thể tải cấu hình.';
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        $status.text(msg).css('color', '#d63638');
      },
      complete: function () {
        $submit.prop('disabled', false);
      }
    });
  }

  loadSettings();

  // 2. Handle Save Settings on Form Submit
  $form.on('submit', function (e) {
    e.preventDefault();

    const onsite = parseInt($('#st-setting-onsite').val(), 10);
    const scroll = parseInt($('#st-setting-scroll').val(), 10);
    const rate   = parseInt($('#st-setting-rate').val(), 10);
    const toast  = parseInt($('#st-setting-toast').val(), 10);
    const payout = parseFloat($('#st-setting-payout').val());

    if (isNaN(onsite) || onsite < 1) {
      $status.text('Thời gian onsite phải lớn hơn hoặc bằng 1 giây.').css('color', '#d63638');
      return;
    }
    if (isNaN(scroll) || scroll < 1 || scroll > 100) {
      $status.text('Tỷ lệ cuộn phải nằm trong khoảng từ 1% đến 100%.').css('color', '#d63638');
      return;
    }
    if (isNaN(rate) || rate < 0) {
      $status.text('IP Rate limit phải lớn hơn hoặc bằng 0 giây.').css('color', '#d63638');
      return;
    }
    if (isNaN(toast) || toast < 1 || toast > 60) {
      $status.text('Thời gian hiển thị thông báo phải từ 1 đến 60 giây.').css('color', '#d63638');
      return;
    }
    if (isNaN(payout) || payout < 0) {
      $status.text('Định mức tiền mỗi lượt traffic phải lớn hơn hoặc bằng 0 VNĐ.').css('color', '#d63638');
      return;
    }

    $submit.prop('disabled', true).text('Đang lưu...');
    $status.text('Đang lưu cấu hình...').css('color', '#787c82');

    $.ajax({
      url: STAdmin.settingsEndpoint,
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        onsite_threshold: onsite,
        scroll_threshold: scroll,
        rate_limit: rate,
        toast_duration: toast,
        payout_per_kpi: payout,
        gear_password: $('#st-setting-gear-pass').val().trim(),
        sepay_webhook_token: $('#st-setting-sepay-token').val().trim(),
        sepay_auto_reconcile: $('#st-setting-sepay-reconcile').is(':checked') ? 1 : 0,
        allowed_admins: $('#st-setting-allowed-admins').val().trim(),
        require_confirmation: $('#st-setting-require-confirm').is(':checked') ? 1 : 0,
        msg_require_google: $('#st-msg-require-google').val().trim(),
        show_msg_require_google: $('#st-show-msg-require-google').is(':checked') ? 1 : 0,
        
        msg_scroll_not_enough: $('#st-msg-scroll-not-enough').val().trim(),
        show_msg_scroll_not_enough: $('#st-show-msg-scroll-not-enough').is(':checked') ? 1 : 0,
        
        msg_invalid_staff: $('#st-msg-invalid-staff').val().trim(),
        show_msg_invalid_staff: $('#st-show-msg-invalid-staff').is(':checked') ? 1 : 0,
        
        msg_already_completed: $('#st-msg-already-completed').val().trim(),
        show_msg_already_completed: $('#st-show-msg-already-completed').is(':checked') ? 1 : 0,
        
        msg_not_in_mission: $('#st-msg-not-in-mission').val().trim(),
        show_msg_not_in_mission: $('#st-show-msg-not-in-mission').is(':checked') ? 1 : 0,
        
        msg_rate_limit: $('#st-msg-rate-limit').val().trim(),
        show_msg_rate_limit: $('#st-show-msg-rate-limit').is(':checked') ? 1 : 0,
        
        msg_success: $('#st-msg-success').val().trim(),
        show_msg_success: $('#st-show-msg-success').is(':checked') ? 1 : 0,
        
        msg_not_enough_conditions: $('#st-msg-not-enough-conditions').val()?.trim() || $('#st-msg-not-enough-conditions').val().trim(),
        show_msg_not_enough_conditions: $('#st-show-msg-not-enough-conditions').is(':checked') ? 1 : 0,
        
        msg_connection_failed: $('#st-msg-connection-failed').val().trim(),
        show_msg_connection_failed: $('#st-show-msg-connection-failed').is(':checked') ? 1 : 0,
        
        msg_scroll_down_prompt: $('#st-msg-scroll-down-prompt').val().trim(),
        show_msg_scroll_down_prompt: $('#st-show-msg-scroll-down-prompt').is(':checked') ? 1 : 0,
        
        msg_stay_longer: $('#st-msg-stay-longer').val().trim(),
        show_msg_stay_longer: $('#st-show-msg-stay-longer').is(':checked') ? 1 : 0,
        
        msg_recording_traffic: $('#st-msg-recording-traffic').val().trim(),
        show_msg_recording_traffic: $('#st-show-msg-recording-traffic').is(':checked') ? 1 : 0
      }),
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', STAdmin.nonce);
      },
      success: function (res) {
        $status.text('🎉 Lưu cấu hình thành công!').css('color', '#1a7a2e');
        setTimeout(function() {
          $status.text('');
        }, 3000);
      },
      error: function (xhr) {
        let msg = 'Lưu cấu hình thất bại.';
        if (xhr.responseJSON && xhr.responseJSON.message) {
          msg = xhr.responseJSON.message;
        }
        $status.text(msg).css('color', '#d63638');
      },
      complete: function () {
        $submit.prop('disabled', false).text('Lưu cài đặt');
      }
    });
  });
});
