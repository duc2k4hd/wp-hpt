jQuery(document).ready(function ($) {
  if ( $('#st-payouts-table').length === 0 ) return;

  const assignApi = STAdmin.assignEndpoint;
  const payoutApi = STAdmin.payoutsEndpoint;
  let currentPage = 1;
  const perPage = 20;
  let currentActiveAssignmentId = null;

  function esc(str) {
    if (str === null || str === undefined) return '';
    if (typeof str !== 'string') return String(str);
    return str.replace(/[&<>'"]/g, function(tag) {
      const charsToReplace = { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' };
      return charsToReplace[tag] || tag;
    });
  }

  function fmt(num) {
    if (num === null || num === undefined || isNaN(num)) return '0';
    return Number(num).toLocaleString('vi-VN');
  }

  function getTypeLabel(type) {
    if (type === 'team') return '<span class="st-badge" style="background:#00a0d2;color:#fff;padding:2px 6px;border-radius:3px;">Team/Ngành</span>';
    if (type === 'class_code') return '<span class="st-badge" style="background:#46b450;color:#fff;padding:2px 6px;border-radius:3px;">Lớp</span>';
    return '<span class="st-badge" style="background:#82878c;color:#fff;padding:2px 6px;border-radius:3px;">Nhân viên</span>';
  }

  function formatPeriodDisplay(period_type, period_value) {
    let displayTime = esc(period_value || '');
    if (period_type === 'day') {
      if (displayTime.length === 10) {
        displayTime = displayTime.substring(8,10) + '/' + displayTime.substring(5,7) + '/' + displayTime.substring(0,4);
      }
    } else if (period_type === 'week') {
      let match = displayTime.match(/^(\d{4})-W(\d{1,2})$/);
      if (match) {
        let y = parseInt(match[1], 10);
        let w = parseInt(match[2], 10);
        let d = new Date(y, 0, 4);
        let day = d.getDay() || 7;
        d.setDate(d.getDate() - day + 1 + (w - 1) * 7);
        let e = new Date(d.getTime());
        e.setDate(e.getDate() + 6);
        let f = date => (date.getDate() < 10 ? '0' : '') + date.getDate() + '/' + (date.getMonth() < 9 ? '0' : '') + (date.getMonth() + 1) + '/' + date.getFullYear();
        displayTime = 'Từ ' + f(d) + ' đến ' + f(e);
      }
    } else if (period_type === 'year') {
      displayTime = 'Cả năm ' + displayTime;
    } else {
      // month
      if (displayTime.length === 7) {
        let y = parseInt(displayTime.substring(0,4), 10);
        let m = parseInt(displayTime.substring(5,7), 10);
        let endDay = new Date(y, m, 0).getDate();
        let mm = m < 10 ? '0' + m : m;
        displayTime = 'Từ 01/' + mm + '/' + y + ' đến ' + endDay + '/' + mm + '/' + y;
      }
    }
    return displayTime;
  }

  function loadPayouts() {
    const $tbody = $('#st-payouts-body');
    $tbody.html('<tr><td colspan="10" class="st-loading">Đang tải dữ liệu...</td></tr>');

    const searchVal = $('#st-payout-filter-staff').val().trim();
    const periodVal = $('#st-payout-filter-period').val().trim();

    let pType = 'all';
    if (periodVal) {
      if (periodVal.length === 10) pType = 'day';
      else if (periodVal.length === 7) pType = 'month';
      else if (periodVal.length === 4) pType = 'year';
    }

    $.ajax({
      url: assignApi,
      data: {
        page: currentPage,
        per_page: perPage,
        search: searchVal,
        period_type: pType,
        period_value: periodVal
      },
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function (res) {
        // Update summary cards
        $('#st-payout-sum-unpaid').text(fmt(res.total_unpaid) + ' đ');
        $('#st-payout-sum-paid').text(fmt(res.total_paid) + ' đ');

        if (!res.rows || res.rows.length === 0) {
          $tbody.html('<tr><td colspan="10" style="text-align:center;padding:20px;color:#787c82;">Không tìm thấy dữ liệu phân công dự án.</td></tr>');
          $('#st-payout-pagination').html('');
          return;
        }

        let html = '';
        res.rows.forEach(function (r) {
          const displayTime = formatPeriodDisplay(r.period_type, r.period_value);

          html += `
            <tr>
              <td><strong style="color:#2271b1; font-size: 14px;">${esc(r.title || '—')}</strong></td>
              <td><strong>${displayTime}</strong></td>
              <td>${r.assign_scope === 'general' ? '<span class="st-badge" style="background:#722ed1;color:#fff;padding:2px 6px;border-radius:3px;">Chung</span>' : '<span class="st-badge" style="background:#2f54eb;color:#fff;padding:2px 6px;border-radius:3px;">Riêng</span>'}</td>
              <td>${r.assign_scope === 'general' ? '—' : getTypeLabel(r.target_type) + ' <strong>' + esc(r.target_value) + '</strong>'}</td>
              <td>${esc(r.kpi_target)}</td>
              <td><span style="color:#1a7a2e;font-weight:700;font-size: 14px;">${esc(r.payouts_completed_count)} NV</span><br><span class="description" style="margin:0;font-size:11px;white-space:nowrap;">(Tổng traffic: ${esc(r.achieved)})</span></td>
              <td><strong style="color:#1e3a8a;">${fmt(r.payouts_total_amount)} đ</strong></td>
              <td><span style="color:#16a34a;font-weight:600;">${fmt(r.payouts_paid_amount)} đ</span></td>
              <td><span style="color:#dc2626;font-weight:600;">${fmt(r.payouts_unpaid_amount)} đ</span></td>
              <td style="text-align:right;">
                <button type="button" class="button button-small button-secondary st-view-details-btn" data-id="${esc(r.id)}">Xem chi tiết</button>
              </td>
            </tr>
          `;
        });
        $tbody.html(html);

        // Render pagination
        renderPagination(res.total);
      },
      error: function () {
        $tbody.html('<tr><td colspan="10" style="text-align:center;padding:20px;color:#d63638;">Không thể tải dữ liệu thanh toán.</td></tr>');
      }
    });
  }

  function renderPagination(total) {
    const totalPages = Math.ceil(total / perPage);
    if (totalPages <= 1) {
      $('#st-payout-pagination').html('');
      return;
    }

    let html = `<span class="st-pag-info">Tổng số: ${fmt(total)} nhiệm vụ</span>`;
    for (let i = 1; i <= totalPages; i++) {
      const activeClass = i === currentPage ? 'active button-primary' : 'button-secondary';
      html += `<button type="button" class="button button-small st-pag-btn ${activeClass}" data-page="${i}">${i}</button> `;
    }
    $('#st-payout-pagination').html(html);
  }

  // ─── Modal state ─────────────────────────────────────────────────────────
  let modalState = {
    id: null,
    page: 1,
    perPage: 25,
    search: '',
    status: '',
    inFlight: false,
  };

  // Details Modal
  function openDetailsModal(id, resetFilters) {
    modalState.id = id;
    if (resetFilters !== false) {
      // Reset search/filter only when opening a NEW assignment
      modalState.page   = 1;
      modalState.search = '';
      modalState.status = '';
      $('#st-modal-search').val('');
      $('#st-modal-status-filter').val('');
    }
    currentActiveAssignmentId = id;

    // Sét tiêu đề modal phù hợp với thanh toán hoa hồng
    $('#st-payout-modal-title').text('Chi tiết tiền hoa hồng theo nhân sự');

    // Đảm bảo hiển thị cột hoa hồng, ẩn cột tiến độ KPI trên màn Payouts
    $('.st-col-payout').show();
    $('.st-col-kpi').hide();

    const $modal = $('#st-payout-details-modal');
    $modal.fadeIn(160);
    $('body').addClass('st-modal-open');

    loadModalPage();
  }

  function loadModalPage() {
    if (!modalState.id || modalState.inFlight) return;
    modalState.inFlight = true;

    const $tbody     = $('#st-payout-modal-body');
    const $info      = $('#st-payout-modal-assignment-info');
    const $badge     = $('#st-modal-total-badge');
    const $payAllBtn = $('#st-payout-pay-all-btn');
    const $pag       = $('#st-modal-pagination');

    $tbody.html('<tr><td colspan="9" class="st-loading" style="text-align:center;padding:18px;">Đang tải...</td></tr>');
    $pag.html('');

    $.ajax({
      url: assignApi + '/' + modalState.id + '/payout-details',
      method: 'GET',
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      data: {
        page:     modalState.page,
        per_page: modalState.perPage,
        search:   modalState.search,
        status:   modalState.status,
      },
      success: function(res) {
        const assign  = res.assignment;
        const payouts = res.payouts;
        const total   = res.total || 0;

        // Info bar — only update on first load (page=1 + no search)
        if (modalState.page === 1 && !modalState.search && !modalState.status) {
          $info.html(`
            <strong>Nhiệm vụ:</strong> ${esc(assign.title)} |
            <strong>KPI:</strong> ${esc(assign.kpi_target)} traffic |
            <strong>Chu kỳ:</strong> ${formatPeriodDisplay(assign.period_type, assign.period_value)}
          `);
        }

        $badge.text(`Tổng: ${fmt(total)} nhân sự`);

        let hasUnpaid = false;
        let html = '';

        if (!payouts || payouts.length === 0) {
          $tbody.html('<tr><td colspan="9" style="text-align:center;padding:15px;color:#787c82;">' +
            (modalState.search || modalState.status
              ? 'Không tìm thấy nhân sự phù hợp.'
              : 'Chưa có dữ liệu hoa hồng. Nhấn <strong>🔄 Tính lại tiền</strong> để tính.') +
            '</td></tr>');
          $payAllBtn.hide();
          renderModalPagination(total);
          return;
        }

        payouts.forEach(function(p) {
          const isPaid = p.status === 'paid';
          const isRefunded = p.status === 'refunded';
          let statusBadge = '';
          if (isPaid) {
            statusBadge = '<span class="st-badge st-badge-ok" style="background:#e6f7ff;color:#1890ff;border:1px solid #91d5ff;padding:2px 6px;border-radius:3px;">Đã chuyển</span>';
          } else if (isRefunded) {
            statusBadge = '<span class="st-badge" style="background:#fff1f0;color:#f5222d;border:1px solid #ffa39e;padding:2px 6px;border-radius:3px;font-weight:600;">Lỗi/Hoàn tiền</span>';
            hasUnpaid = true;
          } else {
            statusBadge = '<span class="st-badge" style="background:#fffbe6;color:#faad14;border:1px solid #ffe58f;padding:2px 6px;border-radius:3px;font-weight:600;">Chưa chuyển</span>';
            hasUnpaid = true;
          }

          const isEnded = assign.status === 'ended';
          let actionHtml = '';

          if (isPaid) {
            actionHtml = `<span style="font-size:11px;color:#8c8c8c;font-style:italic;">Trả: ${esc(p.paid_at)}</span>`;
          } else if (!isEnded) {
            actionHtml = `<span style="font-size:11px;color:#ef4444;font-style:italic;font-weight:500;">Chờ kết thúc dự án</span>`;
          } else {
            let qrBtn = '';
            if (p.bank_account) {
              const cleanEmp = p.emp_code.toUpperCase().replace(/[^A-Z0-9]/g, '');
              qrBtn = `<button type="button" class="button button-small st-qr-btn" data-id="${esc(p.id)}" data-empname="${esc(p.user_name)}" data-bank="${esc(p.bank_account.bank_code)}" data-account="${esc(p.bank_account.account_number)}" data-name="${esc(p.bank_account.account_name)}" data-amount="${esc(p.amount)}" data-desc="HHTR${esc(cleanEmp)}P${esc(p.id)}" style="background:#10b981;color:#fff;border-color:#10b981;font-weight:600;height:26px;line-height:24px;padding:0 8px;">Quét QR</button>`;
            }
            actionHtml = `<div style="display:inline-flex;gap:4px;justify-content:flex-end;">` + qrBtn + `<button type="button" class="button button-small button-primary st-pay-individual-btn" data-id="${esc(p.id)}" style="height:26px;line-height:24px;padding:0 8px;">Đã chuyển</button></div>`;
          }

          let bankInfoHtml = '';
          if (p.bank_account) {
            bankInfoHtml = `<span style="font-size:11.5px;color:#1e293b;background:#f1f5f9;padding:2px 6px;border-radius:4px;border:1px solid #e2e8f0;white-space:nowrap;display:inline-block;" title="Chủ tài khoản: ${esc(p.bank_account.account_name)}">🏦 <strong>${esc(p.bank_account.bank_code)}</strong> - ${esc(p.bank_account.account_number)}</span>`;
          } else {
            bankInfoHtml = `<span style="font-size:11px;color:#ef4444;font-weight:500;">⚠️ Chưa liên kết</span>`;
          }

          html += `
            <tr>
              <td><strong>${esc(p.user_name)}</strong></td>
              <td>${bankInfoHtml}</td>
              <td><code>${esc(p.emp_code)}</code></td>
              <td>${esc(p.class_code)}</td>
              <td>${esc(p.team)}</td>
              <td><span style="color:#1a7a2e;font-weight:600;">${esc(p.kpi_achieved)}</span></td>
              <td><strong>${esc(p.avg_onsite)} s</strong></td>
              <td class="st-col-payout"><strong style="color:#2271b1;">${fmt(p.amount)} đ</strong></td>
              <td class="st-col-payout">${statusBadge}</td>
              <td class="st-col-kpi" style="display:none;">—</td>
              <td class="st-col-kpi" style="display:none;">—</td>
              <td style="text-align:right;vertical-align:middle;">${actionHtml}</td>
            </tr>
          `;
        });

        $tbody.html(html);
        $payAllBtn.toggle(hasUnpaid);
        renderModalPagination(total);
      },
      error: function() {
        $tbody.html('<tr><td colspan="9" style="text-align:center;padding:15px;color:#d63638;">Không thể tải chi tiết hoa hồng.</td></tr>');
      },
      complete: function() {
        modalState.inFlight = false;
      }
    });
  }

  function renderModalPagination(total) {
    const totalPages = Math.ceil(total / modalState.perPage);
    const $pag = $('#st-modal-pagination');

    if (totalPages <= 1) {
      $pag.html('');
      return;
    }

    const cur = modalState.page;
    let html = `<span style="color:#555;font-size:12px;">Trang ${cur}/${totalPages} &nbsp;</span>`;

    // Smart range: always show first, last, and window around current
    const range = new Set([1, totalPages]);
    for (let i = Math.max(1, cur - 2); i <= Math.min(totalPages, cur + 2); i++) range.add(i);

    let prev = 0;
    [...range].sort((a,b)=>a-b).forEach(function(p) {
      if (prev && p - prev > 1) html += '<span style="padding:0 4px;color:#aaa;">…</span>';
      const active = p === cur ? 'button-primary' : 'button-secondary';
      html += `<button type="button" class="button button-small st-modal-pag-btn ${active}" data-page="${p}" style="min-width:32px;">${p}</button> `;
      prev = p;
    });

    $pag.html(html);
  }

  function closeDetailsModal() {
    $('#st-payout-details-modal').fadeOut(160);
    $('body').removeClass('st-modal-open');
    modalState.id = null;
    currentActiveAssignmentId = null;
  }

  let activeQRId = null;
  let activeEmpName = '';
  let qrPollTimer = null;

  function showQRSuccess(res) {
    const txIdStr = res && res.sepay_transaction_id ? res.sepay_transaction_id : '';
    
    $('#st-qr-modal-image').hide();
    
    let $successMsg = $('#st-qr-success-msg');
    if ($successMsg.length === 0) {
      $('#st-qr-modal-image').after(`
        <div id="st-qr-success-msg" style="text-align:center; padding: 20px 10px;">
          <div style="color: #10b981; font-size: 56px; line-height: 1; margin-bottom: 12px;">✓</div>
          <h3 style="color: #10b981; margin: 0 0 10px 0; font-size: 20px;">Chuyển khoản thành công!</h3>
          <p style="margin: 0; color: #555; font-size: 14px;">Đã chuyển khoản thành công cho <strong>${esc(activeEmpName)}</strong></p>
          <p style="margin: 8px 0 0 0; color: #555; font-size: 14px;" class="st-tx-id">Mã GD: <strong>${esc(txIdStr)}</strong></p>
        </div>
      `);
    } else {
      $successMsg.find('.st-tx-id strong').text(txIdStr);
      $successMsg.show();
    }

    $('#st-qr-modal-btn-confirm').hide();
    
    // Update the row in the detail modal immediately
    if (activeQRId) {
      const $btn = $('#st-payout-modal-body').find('.st-qr-btn[data-id="' + activeQRId + '"]');
      if ($btn.length) {
        $btn.closest('td').html('<span class="st-badge" style="background:#dcfce7;color:#166534;border-color:#bbf7d0;">Đã chuyển</span>');
      }
    }
    
    // Auto reload background tables
    loadPayouts();
    if (modalState.id) {
       loadModalPage();
    }
  }

  function pollQRStatus() {
    if (!activeQRId) return;
    $.ajax({
      url: payoutApi + '/' + activeQRId + '/status',
      method: 'GET',
      cache: false,
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function(res) {
        if (res && res.success && res.status === 'paid') {
           if (qrPollTimer) clearInterval(qrPollTimer);
           showQRSuccess(res);
        }
      },
      error: function(xhr) {
        // Stop polling if payout not found or bad request
        if (xhr.status === 404 || xhr.status === 400) {
          if (qrPollTimer) clearInterval(qrPollTimer);
          alert('Khoản hoa hồng không tồn tại hoặc đã bị thay đổi. Vui lòng tải lại trang.');
          closeQRModal();
        }
        // Otherwise silently ignore network errors and try again next tick
      }
    });
  }

  document.addEventListener("visibilitychange", function() {
    if (document.visibilityState === "visible" && activeQRId) {
      pollQRStatus();
    }
  });

  let qrCountdownTimer = null;

  function openQRModal(id, bank, account, name, amount) {
    activeQRId = id;
    
    $('#st-qr-info-bank').text(bank);
    $('#st-qr-info-number').text(account);
    $('#st-qr-info-name').text(name);
    $('#st-qr-info-amount').text(fmt(amount) + ' VNĐ');
    $('#st-qr-info-desc').text('Đang tạo mã...');
    $('#st-qr-countdown').text('--:--');

    // Reset UI state
    $('#st-qr-success-msg').hide();
    $('#st-qr-modal-image').hide(); // Ẩn ảnh QR cho đến khi load xong
    $('#st-qr-modal-btn-confirm').show().prop('disabled', false).text('Xác nhận đã chuyển khoản (Thủ công)');

    $('#st-payout-qr-modal').fadeIn(160);

    // Xóa timer cũ nếu có
    if (qrPollTimer) clearInterval(qrPollTimer);
    if (qrCountdownTimer) clearInterval(qrCountdownTimer);

    // Call API để lấy mã QR động
    $.ajax({
      url: payoutApi + '/' + id + '/qr',
      method: 'POST',
      beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', STAdmin.nonce); },
      success: function(res) {
        if (res.success && res.qr_code) {
          const desc = res.qr_code;
          $('#st-qr-info-desc').text(desc);
          const qrUrl = `https://img.vietqr.io/image/${bank}-${account}-compact.png?amount=${amount}&addInfo=${encodeURIComponent(desc)}&accountName=${encodeURIComponent(name)}`;
          $('#st-qr-modal-image').attr('src', qrUrl).show();

          // Start đếm ngược
          let secondsLeft = res.expires_in || 900;
          
          qrCountdownTimer = setInterval(function() {
            secondsLeft--;
            if (secondsLeft <= 0) {
              clearInterval(qrCountdownTimer);
              $('#st-qr-countdown').text('00:00 (HẾT HẠN)').css('color', 'red');
              // Có thể reload lại qrModal hoặc ẩn QR
              $('#st-qr-modal-image').css('opacity', '0.2');
              $('#st-qr-info-desc').text('MÃ ĐÃ HẾT HẠN - VUI LÒNG MỞ LẠI');
            } else {
              const m = Math.floor(secondsLeft / 60).toString().padStart(2, '0');
              const s = (secondsLeft % 60).toString().padStart(2, '0');
              $('#st-qr-countdown').text(`${m}:${s}`).css('color', '#b91c1c');
            }
          }, 1000);

          // Bắt đầu polling server mỗi 3 giây
          qrPollTimer = setInterval(pollQRStatus, 3000);
        } else {
          alert('Không thể tạo mã QR. Vui lòng thử lại.');
          closeQRModal();
        }
      },
      error: function() {
        alert('Lỗi kết nối khi tạo mã QR.');
        closeQRModal();
      }
    });
  }

  function closeQRModal() {
    $('#st-payout-qr-modal').fadeOut(160);
    activeQRId = null;
    if (qrPollTimer) {
      clearInterval(qrPollTimer);
      qrPollTimer = null;
    }
    if (qrCountdownTimer) {
      clearInterval(qrCountdownTimer);
      qrCountdownTimer = null;
    }
    $('#st-qr-modal-image').css('opacity', '1');
  }

  // ─── Modal event handlers ────────────────────────────────────────────────

  // Open modal
  $('#st-payouts-body').on('click', '.st-view-details-btn', function() {
    openDetailsModal($(this).data('id'), true);
  });

  // Close
  $('#st-payout-modal-close').on('click', closeDetailsModal);
  $(window).on('click', function(e) {
    const $target = $(e.target);
    if ($target.is('#st-payout-qr-modal')) {
      closeQRModal();
    } else if ($target.is('#st-payout-details-modal')) {
      closeDetailsModal();
    }
  });

  // Click on VietQR button
  $('#st-payout-modal-body').on('click', '.st-qr-btn', function() {
    const btn = $(this);
    const id = btn.data('id');
    const bank = btn.data('bank');
    const account = btn.data('account');
    const name = btn.data('name');
    const amount = btn.data('amount');

    openQRModal(id, bank, account, name, amount);
  });

  // QR Modal Close buttons
  $('#st-qr-modal-close, #st-qr-modal-btn-close').on('click', closeQRModal);

  // Confirm payment inside QR Modal
  $('#st-qr-modal-btn-confirm').on('click', function() {
    if (!activeQRId) return;

    const $btn = $(this);
    $btn.prop('disabled', true).text('Đang xử lý...');

    $.ajax({
      url: payoutApi + '/' + activeQRId + '/pay',
      method: 'POST',
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function () {
        if (qrPollTimer) clearInterval(qrPollTimer);
        showQRSuccess({ sepay_transaction_id: 'Thủ công', amount: 0 }); // Fallback success view
      },
      error: function (xhr) {
        $btn.prop('disabled', false).text('Xác nhận đã chuyển khoản (Thủ công)');
        const msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Lỗi kết nối!';
        alert(msg);
      }
    });
  });

  // Debounced search inside modal
  let _modalSearchTimer = null;
  $('#st-modal-search').on('input', function() {
    clearTimeout(_modalSearchTimer);
    _modalSearchTimer = setTimeout(function() {
      modalState.search = $('#st-modal-search').val().trim();
      modalState.page   = 1;
      loadModalPage();
    }, 280);
  });

  // Status filter
  $('#st-modal-status-filter').on('change', function() {
    modalState.status = $(this).val();
    modalState.page   = 1;
    loadModalPage();
  });

  // Modal pagination click
  $('#st-modal-pagination').on('click', '.st-modal-pag-btn', function() {
    modalState.page = parseInt($(this).data('page'), 10);
    loadModalPage();
  });

  // Mark individual as paid
  $('#st-payout-modal-body').on('click', '.st-pay-individual-btn', function () {
    const id   = $(this).data('id');
    if (!confirm('Bạn có chắc chắn muốn đánh dấu là đã chuyển khoản thành công cho nhân viên này?')) return;

    const $btn = $(this);
    $btn.prop('disabled', true).text('Đang lưu...');

    $.ajax({
      url: payoutApi + '/' + id + '/pay',
      method: 'POST',
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function () {
        loadModalPage();
        loadPayouts();
      },
      error: function (xhr) {
        alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể cập nhật trạng thái.'));
        $btn.prop('disabled', false).text('Đã chuyển tiền');
      }
    });
  });

  // Pay All
  $('#st-payout-pay-all-btn').on('click', function() {
    if (!modalState.id) return;
    if (!confirm('Bạn có chắc chắn muốn đánh dấu Đã chuyển tiền cho TẤT CẢ nhân sự trong nhiệm vụ này?')) return;

    const $btn = $(this);
    $btn.prop('disabled', true).text('Đang xử lý...');

    $.ajax({
      url: assignApi + '/' + modalState.id + '/pay-all',
      method: 'POST',
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function(res) {
        alert(res.message);
        loadModalPage();
        loadPayouts();
      },
      error: function(xhr) {
        alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể thanh toán hàng loạt.'));
      },
      complete: function() {
        $btn.prop('disabled', false).text('Đã chuyển tiền tất cả');
      }
    });
  });

  // Recalculate assignment payout
  $('#st-payout-recalc-assignment-btn').on('click', function() {
    if (!modalState.id) return;
    const $btn = $(this);
    $btn.prop('disabled', true).text('Đang tính...');

    $.ajax({
      url: assignApi + '/' + modalState.id + '/recalculate',
      method: 'POST',
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function(res) {
        modalState.page = 1;
        loadModalPage();
        loadPayouts();
        if (res.count === 0) alert('Không có nhân sự nào chạy traffic trong nhiệm vụ này.');
      },
      error: function(xhr) {
        alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể tính lại hoa hồng.'));
      },
      complete: function() {
        $btn.prop('disabled', false).text('🔄 Tính lại tiền');
      }
    });
  });

  // ─── Main table event handlers ───────────────────────────────────────────

  // Handle pagination click
  $('#st-payout-pagination').on('click', '.st-pag-btn', function () {
    currentPage = parseInt($(this).data('page'), 10);
    loadPayouts();
  });

  // Handle filter submission
  $('#st-payout-filter-btn').on('click', function () {
    currentPage = 1;
    loadPayouts();
  });

  $('#st-payout-filter-staff, #st-payout-filter-period').on('keypress', function (e) {
    if (e.which === 13) { currentPage = 1; loadPayouts(); }
  });

  // Toggle recalculate block
  $('#st-payout-recalc-btn').on('click', function () {
    $('#st-payout-recalc-block').slideToggle(200);
  });

  $('.st-payout-recalc-cancel-btn').on('click', function () {
    $('#st-payout-recalc-block').slideUp(200);
    $('#st-payout-recalc-status').text('');
  });

  // Submit recalculate
  $('#st-payout-recalc-submit-btn').on('click', function () {
    const date = $('#st-payout-recalc-date').val();
    if (!date) { alert('Vui lòng chọn ngày!'); return; }

    const $btn    = $(this);
    const $status = $('#st-payout-recalc-status');

    $btn.prop('disabled', true).text('Đang xử lý...');
    $status.text('Đang chạy tính toán...').css('color', '#787c82');

    $.ajax({
      url: payoutApi + '/recalculate',
      method: 'POST',
      data: JSON.stringify({ date: date }),
      contentType: 'application/json',
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function (res) {
        $status.text('🎉 ' + res.message).css('color', '#1a7a2e');
        loadPayouts();
        setTimeout(function () {
          $('#st-payout-recalc-block').slideUp(200);
          $status.text('');
        }, 3000);
      },
      error: function (xhr) {
        $status.text(xhr.responseJSON?.error || 'Tính toán thất bại.').css('color', '#d63638');
      },
      complete: function () {
        $btn.prop('disabled', false).text('Bắt đầu tính toán');
      }
    });
  });

  // Initial load
  loadPayouts();
});
