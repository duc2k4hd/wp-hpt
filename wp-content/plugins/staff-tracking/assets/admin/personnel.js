jQuery(document).ready(function ($) {
  if ( $('#st-personnel-table').length === 0 ) return;

  const apiBase = STAdmin.personnelEndpoint;
  
  let currentPage = 1;
  const perPage = 25;
  let currentSort = { col: 'last_active', order: 'desc' };
  let slimSelectInstance = null;

  if ($('#st-pers-form-team').length > 0 && typeof SlimSelect !== 'undefined') {
    slimSelectInstance = new SlimSelect({
      select: '#st-pers-form-team',
      settings: {
        addable: true,
        placeholderText: '-- Chọn hoặc Nhập Ngành học --'
      },
      events: {
        addable: function (value) {
          return {
            text: value,
            value: value
          }
        }
      }
    });
  }

  function setSlimSelectValue(value) {
    if (!slimSelectInstance) return;
    
    const exists = $('#st-pers-form-team option').filter(function() {
      return $(this).val() === value;
    }).length > 0;
    
    if (value && !exists) {
      slimSelectInstance.addOption({
        text: value,
        value: value
      });
    }
    
    slimSelectInstance.setSelected(value || '');
  }

  // Utils
  function esc(str) {
    if (typeof str !== 'string') return str;
    return str.replace(/[&<>'"]/g, function(tag) {
      const charsToReplace = { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' };
      return charsToReplace[tag] || tag;
    });
  }
  function fmt(num) { return new Intl.NumberFormat().format(num); }

  // Load Data
  function loadPersonnel() {
    const $tbody = $('#st-personnel-body');
    $tbody.html('<tr><td colspan="11" class="st-loading">Đang tải...</td></tr>');

    const classFilter = $('#st-personnel-table').data('class') || '';
    const mode = classFilter ? 'personnel' : 'classes';

    const params = {
      mode: mode,
      class: classFilter,
      page: currentPage,
      per_page: perPage,
      start_date: $('#st-pers-start-date').val(),
      end_date: $('#st-pers-end-date').val(),
      search: $('#st-pers-search').val(),
      team: $('#st-pers-team').val(),
      exact_match: $('#st-pers-exact').is(':checked') ? 1 : 0,
      orderby: currentSort.col,
      order: currentSort.order
    };

    $.ajax({
      url: apiBase,
      data: params,
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function (res) {
        renderTable(res.rows, mode);
        renderPagination(res.total, res.per_page, res.page, mode);
      },
      error: function () {
        $tbody.html('<tr><td colspan="11" style="color:red;">Lỗi tải dữ liệu.</td></tr>');
      }
    });
  }

  function renderTable(rows, mode) {
    const $tbody = $('#st-personnel-body');
    if (!rows || !rows.length) {
      $tbody.html('<tr><td colspan="11">Không có dữ liệu.</td></tr>');
      return;
    }

    let html = '';
    if (mode === 'classes') {
      rows.forEach(function (r, idx) {
        html += `<tr>
          <td>${idx + 1}</td>
          <td><strong>${esc(r.class_code || '—')}</strong></td>
          <td><code>${fmt(r.total_staff)}</code></td>
          <td><strong>${fmt(r.total_logs)}</strong></td>
          <td style="color:#46b450; font-weight:bold;">${fmt(r.valid_logs)}</td>
          <td><strong>${fmt(r.valid_rate)}%</strong></td>
          <td>${fmt(r.avg_duration)}s</td>
          <td>${esc(r.last_active)}</td>
          <td style="text-align:right; white-space:nowrap;">
            <a href="admin.php?page=st-personnel&class=${encodeURIComponent(r.class_code)}" class="button button-small button-primary" style="margin-right:6px;">Xem chi tiết</a>
            <button class="button button-small delete-class-btn" data-class="${esc(r.class_code)}" style="color:#d63638; background:none; border:1px solid #d63638; cursor:pointer;" title="Xóa lớp và toàn bộ nhân sự + logs liên quan">Xóa lớp</button>
          </td>
        </tr>`;
      });
    } else {
      rows.forEach(function (r) {
        const delData = `data-uname="${esc(r.user_name)}" data-ecode="${esc(r.emp_code)}" data-ccode="${esc(r.class_code)}" data-team="${esc(r.team)}"`;
        html += `<tr class="st-row-person" ${delData}>
          <td class="st-toggle-cell" style="cursor:pointer; text-align:center; font-size:12px; color:#2271b1; font-weight:bold;" title="Bấm để xem lịch sử logs chi tiết">▶</td>
          <td><strong>${esc(r.user_name || '—')}</strong></td>
          <td><code>${esc(r.emp_code || '—')}</code></td>
          <td><code>${esc(r.class_code || '—')}</code></td>
          <td>${esc(r.team || '—')}</td>
          <td><strong>${fmt(r.total_logs)}</strong></td>
          <td style="color:#46b450; font-weight:bold;">${fmt(r.valid_logs)}</td>
          <td><strong>${fmt(r.valid_rate)}%</strong></td>
          <td>${fmt(r.avg_duration)}s</td>
          <td>${esc(r.last_active)}</td>
          <td style="text-align:right; white-space:nowrap;">
            <button class="st-action-btn toggle-details" ${delData} style="color:#2271b1; background:none; border:none; cursor:pointer; margin-right:8px; text-decoration:underline;" title="Xem/Ẩn logs chi tiết">Xem logs</button>
            <button class="st-action-btn edit-person" data-id="${r.id}" data-name="${esc(r.user_name)}" data-ecode="${esc(r.emp_code)}" data-ccode="${esc(r.class_code)}" data-team="${esc(r.team)}" style="color:#2271b1; background:none; border:none; cursor:pointer; margin-right:8px; text-decoration:underline;" title="Chỉnh sửa thông tin nhân sự">Sửa</button>
            <button class="st-action-btn delete-staff" data-id="${r.id}" data-name="${esc(r.user_name)}" style="color:#d63638; background:none; border:none; cursor:pointer; margin-right:8px; text-decoration:underline;" title="Xóa nhân sự khỏi hệ thống">Xóa NS</button>
            <button class="st-action-btn delete-person" ${delData} style="color:#d63638; background:none; border:none; cursor:pointer; text-decoration:underline;" title="Xóa toàn bộ Log của người này">Xóa Logs</button>
          </td>
        </tr>`;
      });
    }
    $tbody.html(html);
  }

  function renderPagination(total, per_page, current_page, mode) {
    const totalPages = Math.ceil(total / per_page);
    const $pag = $('#st-pers-pagination');

    if (totalPages <= 1) { $pag.html(''); return; }

    const typeLabel = mode === 'classes' ? 'lớp' : 'nhân sự';
    let html = `<span class="st-pag-info">Trang ${current_page} / ${totalPages} (${fmt(total)} ${typeLabel})</span> `;
    
    if (current_page > 1) {
      html += `<button class="button st-pag-btn" data-page="1">«</button> `;
      html += `<button class="button st-pag-btn" data-page="${current_page - 1}">‹</button> `;
    }

    let start = Math.max(1, current_page - 2);
    let end = Math.min(totalPages, current_page + 2);
    for (let i = start; i <= end; i++) {
      if (i === current_page) {
        html += `<button class="button button-primary st-pag-btn" disabled>${i}</button> `;
      } else {
        html += `<button class="button st-pag-btn" data-page="${i}">${i}</button> `;
      }
    }

    if (current_page < totalPages) {
      html += `<button class="button st-pag-btn" data-page="${current_page + 1}">›</button> `;
      html += `<button class="button st-pag-btn" data-page="${totalPages}">»</button> `;
    }

    $pag.html(html);
  }

  // Events
  $('#st-pers-filter-btn').on('click', function () {
    currentPage = 1;
    loadPersonnel();
  });

  $('#st-pers-reset-btn').on('click', function () {
    $('#st-pers-search, #st-pers-team').val('');
    $('#st-pers-exact').prop('checked', false);
    currentPage = 1;
    loadPersonnel();
  });

  $('#st-pers-search, #st-pers-team').on('keypress', function (e) {
    if (e.which === 13) { currentPage = 1; loadPersonnel(); }
  });

  $('#st-pers-pagination').on('click', '.st-pag-btn', function () {
    if ($(this).prop('disabled')) return;
    currentPage = parseInt($(this).data('page'), 10);
    loadPersonnel();
  });

  // Sort
  $('#st-personnel-table th.sortable').on('click', function () {
    const col = $(this).data('col');
    if (currentSort.col === col) {
      currentSort.order = currentSort.order === 'desc' ? 'asc' : 'desc';
    } else {
      currentSort.col = col;
      currentSort.order = 'desc'; // Default desc for logs/active
    }
    currentPage = 1;
    loadPersonnel();
  });

  // Toggle details (Sub-table logs)
  $('#st-personnel-body').on('click', '.st-toggle-cell, .toggle-details', function (e) {
    e.stopPropagation();
    const classFilter = $('#st-personnel-table').data('class') || '';
    if (!classFilter) return;

    const $row = $(this).closest('tr');
    const uname = $row.data('uname') || '';
    const ecode = $row.data('ecode') || '';
    const ccode = $row.data('ccode') || '';
    const team  = $row.data('team') || '';
    
    const $toggleCell = $row.find('.st-toggle-cell');
    
    // Find next row
    const $nextRow = $row.next('tr');
    if ($nextRow.hasClass('st-detail-row')) {
      $nextRow.toggle();
      const isVisible = $nextRow.is(':visible');
      $toggleCell.text(isVisible ? '▼' : '▶');
      return;
    }
    
    // Create new sub-table
    $toggleCell.text('▼');
    const $detailRow = $(`<tr class="st-detail-row">
      <td colspan="11" style="background:#f6f7f7; padding: 15px 20px; border-top: 1px solid #dcdcde; border-bottom: 1px solid #dcdcde;">
        <div class="st-detail-container" style="border-left: 4px solid #2271b1; padding-left: 15px;">
          <h4 style="margin: 0 0 10px; font-size:13px; color:#1d2327;">Nhật ký chạy traffic chi tiết của: <strong>${esc(uname || '—')}</strong> (Mã NV: <code>${esc(ecode || '—')}</code>, Lớp: <code>${esc(ccode || '—')}</code>, Team: <code>${esc(team || '—')}</code>)</h4>
          <table class="widefat striped" style="background:#fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <thead>
              <tr style="background:#f0f0f1;">
                <th>Thời gian</th>
                <th>Địa chỉ IP</th>
                <th>URL bài viết</th>
                <th>Thời lượng</th>
                <th>Trạng thái</th>
                <th style="width:60px; text-align:right;">Thao tác</th>
              </tr>
            </thead>
            <tbody class="st-detail-tbody">
              <tr><td colspan="6" class="st-loading">Đang tải chi tiết logs...</td></tr>
            </tbody>
          </table>
        </div>
      </td>
    </tr>`);
    
    $row.after($detailRow);
    
    // AJAX load logs
    $.ajax({
      url: STAdmin.logsEndpoint,
      data: {
        staff: uname,
        emp_code: ecode,
        class_code: ccode,
        team: team,
        exact_match: 1,
        per_page: 20 // display 20 newest logs
      },
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function (res) {
        const $tbody = $detailRow.find('.st-detail-tbody');
        if (!res.rows || !res.rows.length) {
          $tbody.html('<tr><td colspan="6">Không tìm thấy nhật ký traffic nào.</td></tr>');
          return;
        }
        
        let subHtml = '';
        res.rows.forEach(function (log) {
          const validBadge = log.is_valid == 1
            ? '<span style="color:#46b450; font-weight:bold;">✔ Hợp lệ</span>'
            : '<span style="color:#d63638;">✘ Không hợp lệ</span>';
            
          subHtml += `<tr data-logid="${log.id}">
            <td>${esc(log.created_at)}</td>
            <td><code>${esc(log.ip)}</code></td>
            <td><a href="${esc(log.url)}" target="_blank" rel="noopener" title="${esc(log.url)}">${esc(log.url.length > 70 ? log.url.slice(0, 67) + '...' : log.url)}</a></td>
            <td>${log.duration}s</td>
            <td>${validBadge}</td>
            <td style="text-align:right; white-space:nowrap;">
              <button class="st-action-btn view-single-behavior" data-id="${log.id}" data-name="${esc(uname)}" data-code="${esc(ecode)}" style="color:#2271b1; background:none; border:none; cursor:pointer; margin-right:8px; text-decoration:underline;" title="Xem nhật ký hành vi chi tiết">Xem hành vi</button>
              <button class="st-action-btn delete-single-log" data-id="${log.id}" style="color:#d63638; background:none; border:none; cursor:pointer; text-decoration:underline;">Xóa</button>
            </td>
          </tr>`;
        });
        $tbody.html(subHtml);
      },
      error: function () {
        $detailRow.find('.st-detail-tbody').html('<tr><td colspan="6" style="color:red;">Lỗi tải dữ liệu chi tiết.</td></tr>');
      }
    });
  });

  // Open behavior modal for a single log
  $('#st-personnel-body').on('click', '.view-single-behavior', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
    const name = $(this).data('name');
    const code = $(this).data('code');
    if (typeof window.openBehaviorModal === 'function') {
      window.openBehaviorModal(id, name, code);
    } else {
      alert('Chức năng hiển thị hành vi chưa được tải hoàn toàn.');
    }
  });

  // Delete single log inside sub-table
  $('#st-personnel-body').on('click', '.delete-single-log', function (e) {
    e.stopPropagation();
    const btn = $(this);
    const id = btn.data('id');
    const $subRow = btn.closest('tr');
    
    if (confirm('Bạn có chắc chắn muốn xóa log này?')) {
      btn.prop('disabled', true).text('Xóa...');
      $.ajax({
        url: STAdmin.logsEndpoint.replace('/logs', '/logs/' + id),
        type: 'DELETE',
        headers: { 'X-WP-Nonce': STAdmin.nonce },
        success: function () {
          $subRow.fadeOut(300, function() {
            $(this).remove();
          });
        },
        error: function(xhr) {
          alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể xóa'));
          btn.prop('disabled', false).text('Xóa');
        }
      });
    }
  });

  // Delete all logs of a person
  $('#st-personnel-body').on('click', '.delete-person', function () {
    const btn = $(this);
    const uname = btn.data('uname');
    const ecode = btn.data('ecode');
    const ccode = btn.data('ccode');
    const team = btn.data('team');
    
    let dispName = uname || ecode || ccode || team;

    if (confirm('CẢNH BÁO: Thao tác này sẽ xóa TOÀN BỘ lịch sử làm việc (Logs) của "' + dispName + '" trong hệ thống.\nBạn có chắc chắn muốn xóa không?')) {
      btn.prop('disabled', true).text('Xóa...');
      
      $.ajax({
        url: apiBase,
        type: 'DELETE',
        data: JSON.stringify({
          user_name: uname,
          emp_code: ecode,
          class_code: ccode,
          team: team
        }),
        contentType: 'application/json',
        headers: { 'X-WP-Nonce': STAdmin.nonce },
        success: function (res) {
          alert('Đã xóa ' + res.deleted_rows + ' lượt log của người này.');
          loadPersonnel();
        },
        error: function (xhr) {
          alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể xóa'));
          btn.prop('disabled', false).text('Xóa');
        }
      });
    }
  });

  // Open Add Modal
  $('#st-add-personnel-btn').on('click', function() {
    const classFilter = $('#st-personnel-table').data('class') || '';
    $('#st-personnel-modal-title').text('Thêm nhân sự mới');
    $('#st-personnel-id').val('');
    $('#st-pers-form-name').val('');
    $('#st-pers-form-empcode').val('').prop('disabled', false);
    
    if (classFilter) {
      $('#st-pers-form-classcode').val(classFilter).prop('disabled', true);
    } else {
      $('#st-pers-form-classcode').val('').prop('disabled', false);
    }
    
    setSlimSelectValue('');
    $('#st-pers-form-error').hide();
    $('#st-personnel-modal').show();
  });

  // Open Edit Modal
  $('#st-personnel-body').on('click', '.edit-person', function() {
    const btn = $(this);
    $('#st-personnel-modal-title').text('Chỉnh sửa nhân sự');
    $('#st-personnel-id').val(btn.data('id'));
    $('#st-pers-form-name').val(btn.data('name'));
    $('#st-pers-form-empcode').val(btn.data('ecode')).prop('disabled', true);
    $('#st-pers-form-classcode').val(btn.data('ccode'));
    setSlimSelectValue(btn.data('team'));
    $('#st-pers-form-error').hide();
    $('#st-personnel-modal').show();
  });

  // Close Modal
  $('#st-personnel-modal-close, #st-pers-form-cancel').on('click', function() {
    $('#st-personnel-modal').hide();
  });
  $(window).on('click', function(e) {
    if ($(e.target).hasClass('st-modal-overlay') && $(e.target).attr('id') === 'st-personnel-modal') {
      $('#st-personnel-modal').hide();
    }
  });
  $(document).on('keydown', function(e) {
    if (e.key === 'Escape') $('#st-personnel-modal').hide();
  });

  // Submit Add/Edit form
  $('#st-personnel-form').on('submit', function(e) {
    e.preventDefault();
    const id = $('#st-personnel-id').val();
    const isEdit = !!id;
    const url = isEdit ? (apiBase + '/' + id) : apiBase;
    const method = isEdit ? 'PUT' : 'POST';

    const payload = {
      user_name: $('#st-pers-form-name').val().trim(),
      emp_code: $('#st-pers-form-empcode').val().trim(),
      class_code: $('#st-pers-form-classcode').val().trim(),
      team: $('#st-pers-form-team').val().trim(),
    };

    const $submitBtn = $('#st-pers-form-submit');
    $submitBtn.prop('disabled', true).text('Đang lưu...');
    $('#st-pers-form-error').hide();

    $.ajax({
      url: url,
      type: method,
      contentType: 'application/json',
      data: JSON.stringify(payload),
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function() {
        $('#st-personnel-modal').hide();
        $submitBtn.prop('disabled', false).text('Lưu');
        loadPersonnel();
      },
      error: function(xhr) {
        $submitBtn.prop('disabled', false).text('Lưu');
        const errMsg = xhr.responseJSON?.error || 'Không thể lưu nhân sự.';
        $('#st-pers-form-error').text(errMsg).show();
      }
    });
  });

  $('#st-personnel-body').on('click', '.delete-staff', function() {
    const btn = $(this);
    const id = btn.data('id');
    const name = btn.data('name');

    if (confirm('Bạn có chắc chắn muốn xóa nhân sự "' + name + '" khỏi danh sách?\n(Thao tác này chỉ xóa hồ sơ nhân sự, không xóa logs lịch sử của họ)')) {
      btn.prop('disabled', true).text('Xóa...');
      $.ajax({
        url: apiBase + '/' + id,
        type: 'DELETE',
        headers: { 'X-WP-Nonce': STAdmin.nonce },
        success: function() {
          loadPersonnel();
        },
        error: function(xhr) {
          alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể xóa nhân sự'));
          btn.prop('disabled', false).text('Xóa NS');
        }
      });
    }
  });

  // Xóa toàn bộ lớp học và các dữ liệu liên quan sạch sẽ
  $('#st-personnel-body').on('click', '.delete-class-btn', function() {
    const btn = $(this);
    const classCode = btn.data('class');

    if (confirm('CẢNH BÁO CỰC KỲ QUAN TRỌNG:\nHành động này sẽ XÓA SẠCH lớp "' + classCode + '", cùng với:\n- Toàn bộ nhân sự trong lớp\n- Toàn bộ Traffic Logs\n- Toàn bộ chi tiết hành vi click/cuộn\n- Toàn bộ KPI phân công & bảng thanh toán liên quan.\n\nBạn có chắc chắn muốn xóa không? Hành động này không thể hoàn tác!')) {
      btn.prop('disabled', true).text('Đang xóa...');
      $.ajax({
        url: apiBase + '/class?class=' + encodeURIComponent(classCode),
        type: 'DELETE',
        headers: { 'X-WP-Nonce': STAdmin.nonce },
        success: function(res) {
          alert(res.message || 'Xóa lớp thành công!');
          loadPersonnel();
        },
        error: function(xhr) {
          alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể xóa lớp'));
          btn.prop('disabled', false).text('Xóa lớp');
        }
      });
    }
  });

  // Xuất Excel danh sách nhân sự (Tạo file XLSX nguyên bản trực tiếp ở Client-side)
  $('#st-pers-export-btn').on('click', function () {
    const btn = $(this);
    const originalText = btn.text();
    btn.prop('disabled', true).text('Đang tạo file...');

    const classFilter = $('#st-personnel-table').data('class') || '';
    const teamFilter = $('#st-pers-team').val().trim();
    
    let url = ajaxurl.replace('admin-ajax.php', 'admin-post.php') + '?action=st_export_personnel_excel';
    if (classFilter) {
      url += '&class=' + encodeURIComponent(classFilter);
    }
    if (teamFilter) {
      url += '&team=' + encodeURIComponent(teamFilter);
    }

    $.ajax({
      url: url,
      type: 'GET',
      dataType: 'json',
      success: function (res) {
        btn.prop('disabled', false).text(originalText);
        
        if (!res || !Array.isArray(res)) {
          alert('Không thể xuất dữ liệu hoặc dữ liệu rỗng.');
          return;
        }

        // Định dạng cấu trúc bảng 2 chiều nguyên bản (Zin 100%)
        const aoa = [
          ['ID', 'Họ tên', 'Mã nhân viên', 'Mã lớp', 'Ngành học / Team']
        ];

        res.forEach(function (row) {
          aoa.push([
            row.id || '',
            row.user_name || '',
            row.emp_code || '',
            row.class_code || '',
            row.team || ''
          ]);
        });

        // Sử dụng SheetJS để tạo workbook XLSX nhị phân chuẩn
        const worksheet = XLSX.utils.aoa_to_sheet(aoa);
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, 'Danh sách Nhân sự');

        // Đặt tên file
        let filename = 'danh_sach_nhan_su';
        if (classFilter) {
          filename += '_lop_' + classFilter.toLowerCase().replace(/[^a-z0-9]/g, '_');
        }
        if (teamFilter) {
          filename += '_team_' + teamFilter.toLowerCase().replace(/[^a-z0-9]/g, '_');
        }
        filename += '_' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + '.xlsx';

        // Tải xuống file XLSX nhị phân chuẩn
        XLSX.writeFile(workbook, filename);
      },
      error: function () {
        btn.prop('disabled', false).text(originalText);
        alert('Có lỗi xảy ra khi tải dữ liệu xuất bản.');
      }
    });
  });

  // Mở modal Nhập Excel
  $('#st-pers-import-btn').on('click', function () {
    $('#st-import-error').hide();
    $('#st-import-success').hide();
    $('#st-import-file-input').val('');
    $('#st-import-modal').show();
  });

  // Đóng modal Nhập Excel
  $('#st-import-modal-close, #st-import-cancel').on('click', function () {
    $('#st-import-modal').hide();
  });

  $(window).on('click', function(e) {
    if ($(e.target).hasClass('st-modal-overlay') && $(e.target).attr('id') === 'st-import-modal') {
      $('#st-import-modal').hide();
    }
  });

  // Đọc và phân tích file ở clientside
  function parseFileClientSide(file, callback) {
    const reader = new FileReader();

    // Dùng ArrayBuffer để SheetJS đọc được cả .xlsx nhị phân lẫn .xls HTML
    reader.onload = function (e) {
      const data = e.target.result;

      // Nếu SheetJS có sẵn (đã load từ CDN) → dùng nó để đọc mọi định dạng
      if (typeof XLSX !== 'undefined') {
        try {
          const workbook = XLSX.read(data, { type: 'array' });
          const firstSheetName = workbook.SheetNames[0];
          const worksheet = workbook.Sheets[firstSheetName];
          // Chuyển sheet thành mảng 2 chiều, bỏ qua ô rỗng
          const rawRows = XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: '' });
          // Lọc bỏ các dòng mà tất cả ô đều trống
          const rows = rawRows.filter(function (row) {
            return row.some(function (cell) {
              return (cell || '').toString().trim() !== '';
            });
          }).map(function (row) {
            return row.map(function (cell) {
              return (cell === null || cell === undefined) ? '' : cell.toString().trim();
            });
          });
          callback(null, rows);
          return;
        } catch (xlsxErr) {
          // SheetJS thất bại → thử fallback
        }
      }

      // Fallback: đọc dạng text (HTML XLS hoặc CSV thuần)
      const textReader = new FileReader();
      textReader.onload = function (te) {
        const content = te.target.result;
        let rows = [];
        const lowerContent = (content || '').toLowerCase();

        if (lowerContent.indexOf('<table') !== -1 || lowerContent.indexOf('<tr') !== -1) {
          const parser = new DOMParser();
          const doc = parser.parseFromString(content, 'text/html');
          $(doc).find('tr').each(function () {
            const row = [];
            $(this).find('th, td').each(function () {
              row.push($(this).text().trim());
            });
            if (row.length > 0) {
              rows.push(row);
            }
          });
        } else {
          const lines = content.split(/\r?\n/);
          for (let i = 0; i < lines.length; i++) {
            const line = lines[i].trim();
            if (!line) continue;
            let cells = [];
            let current = '';
            let inQuotes = false;
            for (let j = 0; j < line.length; j++) {
              const char = line[j];
              if (char === '"') {
                inQuotes = !inQuotes;
              } else if (char === ',' && !inQuotes) {
                cells.push(current.trim());
                current = '';
              } else {
                current += char;
              }
            }
            cells.push(current.trim());
            rows.push(cells);
          }
        }
        callback(null, rows);
      };
      textReader.onerror = function () { callback('Lỗi đọc tệp tin.'); };
      textReader.readAsText(file, 'UTF-8');
    };

    reader.onerror = function () { callback('Lỗi đọc tệp tin.'); };
    reader.readAsArrayBuffer(file);
  }

  // Chia lô & Chạy đa luồng song song lên server
  function executeImportInBatches(rows) {
    if (!rows || !Array.isArray(rows) || rows.length === 0) {
      $('#st-import-error').text('Không tìm thấy dữ liệu hợp lệ trong tệp tin.').show();
      $('#st-import-submit').prop('disabled', false).text('Tải lên & Nhập dữ liệu');
      return;
    }
    const firstRow = rows[0];
    if (!firstRow || !Array.isArray(firstRow)) {
      $('#st-import-error').text('Định dạng hàng cột không hợp lệ. Hãy kiểm tra lại file.').show();
      $('#st-import-submit').prop('disabled', false).text('Tải lên & Nhập dữ liệu');
      return;
    }
    // Tìm dòng header thực sự:
    // - Phải có ít nhất 4 ô
    // - Phải chứa ô có nội dung "họ tên" (chỉ dòng header cột mới có từ này)
    // - Và khớp ít nhất 2 từ khoá cột khác
    const headerIndicators = ['id', 'họ tên', 'mã nv', 'mã nhân viên', 'lớp', 'ngành', 'nhân viên'];
    let headerRowIndex = -1;
    for (let ri = 0; ri < rows.length; ri++) {
      const row = rows[ri];
      if (!row || row.length < 4) continue;
      // Bắt buộc phải có ô chứa "họ tên" — loại bỏ hoàn toàn title và metadata
      const hasHoTen = row.some(function(cell) {
        return (cell || '').toString().toLowerCase().indexOf('họ tên') !== -1;
      });
      if (!hasHoTen) continue;
      let matchCount = 0;
      for (let cell of row) {
        const cellLower = (cell || '').toString().toLowerCase();
        for (let ind of headerIndicators) {
          if (cellLower.indexOf(ind) !== -1) {
            matchCount++;
            break;
          }
        }
      }
      if (matchCount >= 2) {
        headerRowIndex = ri;
        break;
      }
    }

    // Lấy dataRows: bỏ tất cả dòng từ header trở lên, chỉ giữ dữ liệu thực
    const dataRows = headerRowIndex >= 0 ? rows.slice(headerRowIndex + 1) : rows;

    if (dataRows.length === 0) {
      $('#st-import-error').text('Không có dữ liệu nhân sự để nhập.').show();
      $('#st-import-submit').prop('disabled', false).text('Tải lên & Nhập dữ liệu');
      $('#st-import-cancel').prop('disabled', false);
      return;
    }

    // Lọc bỏ các dòng mà tất cả ô đều trống (dòng padding, dòng trống cuối file)
    const cleanDataRows = dataRows.filter(function (row) {
      return row && row.some(function (cell) {
        return (cell || '').toString().trim() !== '';
      });
    });

    if (cleanDataRows.length === 0) {
      $('#st-import-error').text('Không có dữ liệu nhân sự để nhập.').show();
      $('#st-import-submit').prop('disabled', false).text('Tải lên & Nhập dữ liệu');
      $('#st-import-cancel').prop('disabled', false);
      return;
    }

    const batchSize = 100;
    const batches = [];
    for (let i = 0; i < cleanDataRows.length; i += batchSize) {
      const slice = cleanDataRows.slice(i, i + batchSize);
      // Gửi dữ liệu sạch không kèm header — server nhận trực tiếp từ index 0
      batches.push(slice);
    }

    let activeRequests = 0;
    let batchIndex = 0;
    const maxConcurrency = 5; // Tải 5 luồng song song cực nhanh

    let totalInserted = 0;
    let totalUpdated = 0;
    let totalSkipped = 0;
    let allErrors = [];

    $('#st-import-progress-container').show();
    $('#st-import-progress-bar').css('width', '0%');
    $('#st-import-progress-text').text('Bắt đầu xử lý...');
    $('#st-import-progress-count').text('0 / ' + cleanDataRows.length);
    $('#st-import-submit').prop('disabled', true).text('Đang nhập dữ liệu...');
    $('#st-import-cancel').prop('disabled', true);

    function sendNext() {
      if (batchIndex >= batches.length) {
        if (activeRequests === 0) {
          onImportComplete();
        }
        return;
      }

      while (activeRequests < maxConcurrency && batchIndex < batches.length) {
        const currentIdx = batchIndex;
        const batchData = batches[currentIdx];
        batchIndex++;
        activeRequests++;

        $.ajax({
          url: apiBase + '/import',
          type: 'POST',
          contentType: 'application/json',
          data: JSON.stringify({ batch: batchData }),
          headers: { 'X-WP-Nonce': STAdmin.nonce },
          success: function (res) {
            activeRequests--;
            if (res.success) {
              totalInserted += res.inserted || 0;
              totalUpdated += res.updated || 0;
              totalSkipped += res.skipped || 0;
              if (res.errors && res.errors.length) {
                allErrors = allErrors.concat(res.errors);
              }
            }
            updateProgress();
            sendNext();
          },
          error: function (xhr) {
            activeRequests--;
            const errText = xhr.responseJSON?.error || 'Lỗi kết nối máy chủ.';
            allErrors.push('Lỗi ở gói ' + (currentIdx + 1) + ': ' + errText);
            updateProgress();
            sendNext();
          }
        });
      }
    }

    function updateProgress() {
      const processedBatches = batchIndex - activeRequests;
      const percent = Math.round((processedBatches / batches.length) * 100);
      const processedRows = Math.min(cleanDataRows.length, processedBatches * batchSize);
      
      $('#st-import-progress-bar').css('width', percent + '%');
      $('#st-import-progress-text').text('Đang nhập: ' + percent + '%');
      $('#st-import-progress-count').text(processedRows + ' / ' + cleanDataRows.length);
    }

    function onImportComplete() {
      $('#st-import-submit').hide();
      $('#st-import-cancel').prop('disabled', false).text('Hoàn tất (Đóng)').addClass('button-primary');

      let message = 'Nhập thành công: Thêm mới ' + totalInserted + ', Cập nhật ' + totalUpdated + ', Bỏ qua ' + totalSkipped + ' nhân sự.';
      if (allErrors.length > 0) {
        message += '\nCó ' + allErrors.length + ' dòng lỗi.';
        const errorSnippet = allErrors.slice(0, 5).join('<br>');
        $('#st-import-error').html('<strong>Lỗi phát sinh:</strong><br>' + errorSnippet).show();
      }
      
      $('#st-import-success').html(message.replace(/\n/g, '<br>')).show();

      $('#st-import-cancel').off('click').on('click', function () {
        $('#st-import-modal').hide();
        $('#st-import-progress-container').hide();
        
        // Trả lại trạng thái cũ cho nút bấm
        $('#st-import-submit').show().prop('disabled', false).text('Tải lên & Nhập dữ liệu');
        $('#st-import-cancel').text('Hủy').removeClass('button-primary');
        
        // Khôi phục hành vi đóng modal mặc định
        $('#st-import-cancel').off('click').on('click', function () {
          $('#st-import-modal').hide();
        });
        
        loadPersonnel();
      });
    }

    // Khởi chạy
    sendNext();
  }

  // Submit file Nhập Excel
  $('#st-import-form').on('submit', function (e) {
    e.preventDefault();
    
    const fileInput = $('#st-import-file-input')[0];
    if (fileInput.files.length === 0) {
      $('#st-import-error').text('Vui lòng chọn tệp tin!').show();
      return;
    }

    const file = fileInput.files[0];
    $('#st-import-error').hide();
    $('#st-import-success').hide();
    $('#st-import-submit').prop('disabled', true).text('Đang đọc tệp tin...');

    parseFileClientSide(file, function (err, rows) {
      if (err) {
        $('#st-import-error').text(err).show();
        $('#st-import-submit').prop('disabled', false).text('Tải lên & Nhập dữ liệu');
        return;
      }
      executeImportInBatches(rows);
    });
  });

  // Init
  loadPersonnel();
});
