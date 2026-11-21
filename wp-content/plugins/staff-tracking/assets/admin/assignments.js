jQuery(document).ready(function ($) {
  if ( $('#st-assignments-table').length === 0 ) return;

  const apiBase = STAdmin.assignEndpoint;

  // Utils
  function esc(str) {
    if (str === null || str === undefined) return '';
    if (typeof str !== 'string') return String(str);
    return str.replace(/[&<>'"]/g, function(tag) {
      const charsToReplace = { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' };
      return charsToReplace[tag] || tag;
    });
  }

  function getTypeLabel(type) {
    if (type === 'team') return '<span class="st-badge" style="background:#00a0d2;color:#fff;">Team/Ngành</span>';
    if (type === 'class_code') return '<span class="st-badge" style="background:#46b450;color:#fff;">Lớp</span>';
    return '<span class="st-badge" style="background:#82878c;color:#fff;">Nhân viên</span>';
  }

  function formatTargetDisplay(type, value) {
    if (!value) return '—';
    const arr = String(value).split(',');
    if (arr.length <= 2) {
      return getTypeLabel(type) + ' <strong>' + esc(value) + '</strong>';
    }
    const truncated = arr.slice(0, 2).join(', ');
    const count = arr.length - 2;
    return getTypeLabel(type) + ' <strong>' + esc(truncated) + ` và ${count} đối tượng khác</strong>`;
  }

  let currentPage = 1;
  const perPage = 20;

  let valSelectSlim = null;
  let catsSlim = null;
  let productsSlim = null;

  function fmt(num) {
    if (num === null || num === undefined) return '0';
    return Number(num).toLocaleString('vi-VN');
  }

  function applySlimSelect($selectEl, currentInstance) {
    const optionCount = $selectEl.find('option').length;
    if (currentInstance) {
      currentInstance.destroy();
      currentInstance = null;
    }

    if (optionCount >= 5) {
      try {
        if (typeof SlimSelect !== 'undefined') {
          currentInstance = new SlimSelect({
            select: $selectEl[0],
            settings: {
              placeholderText: '-- Chọn đối tượng --',
              searchText: 'Không tìm thấy kết quả',
              searchPlaceholder: 'Tìm nhanh...'
            }
          });
        }
      } catch (e) {
        console.warn('SlimSelect initialization failed:', e);
      }
    }
    return currentInstance;
  }

  function initCatsSlim() {
    const $cats = $('#st-assign-form-cats');
    if ($cats.length && $cats.find('option').length >= 5) {
      try {
        if (typeof SlimSelect !== 'undefined') {
          catsSlim = new SlimSelect({
            select: $cats[0],
            settings: {
              placeholderText: 'Chọn danh mục...',
              searchText: 'Không tìm thấy kết quả',
              searchPlaceholder: 'Tìm nhanh...',
              closeOnSelect: false
            }
          });
        }
      } catch (e) {
        console.warn('SlimSelect for categories failed:', e);
      }
    }
  }

  function initProductsSlim(initialOptions = []) {
    const $select = $('#st-assign-form-products-select');
    if (!$select.length) return;

    if (productsSlim) {
      productsSlim.destroy();
      productsSlim = null;
    }

    let html = '';
    initialOptions.forEach(opt => {
      html += `<option value="${esc(opt.value)}">${esc(opt.text)}</option>`;
    });
    $select.html(html);

    try {
      if (typeof SlimSelect !== 'undefined') {
        productsSlim = new SlimSelect({
          select: $select[0],
          settings: {
            placeholderText: 'Gõ tên hoặc ID sản phẩm...',
            searchText: 'Không tìm thấy kết quả',
            searchPlaceholder: 'Tìm nhanh sản phẩm...',
            closeOnSelect: false
          },
          events: {
            search: function(search, currentData) {
              return new Promise((resolve, reject) => {
                $.ajax({
                  url: STAdmin.searchProductsEndpoint,
                  data: { q: search },
                  headers: { 'X-WP-Nonce': STAdmin.nonce },
                  success: function(res) {
                    const selectedValues = $select.val() || [];
                    const filtered = res.filter(item => !selectedValues.includes(item.value));
                    resolve(filtered);
                  },
                  error: function() {
                    reject('Không thể tìm kiếm sản phẩm.');
                  }
                });
              });
            }
          }
        });
      }
    } catch (e) {
      console.warn('SlimSelect for products failed:', e);
    }
  }

  // Load Data
  function loadAssignments() {
    const $tbody = $('#st-assignments-body');
    $tbody.html('<tr><td colspan="11" class="st-loading">Đang tải...</td></tr>');

    $.ajax({
      url: apiBase,
      data: { 
        period_type: $('#st-assign-period-type').val(),
        period_value: $('#st-assign-period-value').val(),
        page: currentPage,
        per_page: perPage
      },
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function (res) {
        renderTable(res.rows);
        renderPagination(res.total || 0, perPage, currentPage);
      },
      error: function () {
        $tbody.html('<tr><td colspan="11" style="color:red;">Lỗi tải dữ liệu.</td></tr>');
      }
    });
  }

  function renderPagination( total, perPage, currentPage ) {
    const totalPages = Math.ceil( total / perPage );
    const $pag       = $( '#st-assign-pagination' );

    if ( totalPages <= 1 ) { $pag.html( '' ); return; }

    let html = '<span class="st-pag-info">Trang ' + currentPage + ' / ' + totalPages + ' (' + fmt( total ) + ' bản ghi)</span> ';

    if ( currentPage > 1 ) {
      html += '<button class="button st-pag-btn" data-page="1">«</button> ';
      html += '<button class="button st-pag-btn" data-page="' + ( currentPage - 1 ) + '">‹</button> ';
    }

    const start = Math.max( 1, currentPage - 2 );
    const end   = Math.min( totalPages, currentPage + 2 );

    for ( let p = start; p <= end; p++ ) {
      const active = p === currentPage ? ' button-primary' : '';
      html += '<button class="button' + active + ' st-pag-btn" data-page="' + p + '">' + p + '</button> ';
    }

    if ( currentPage < totalPages ) {
      html += '<button class="button st-pag-btn" data-page="' + ( currentPage + 1 ) + '">›</button> ';
      html += '<button class="button st-pag-btn" data-page="' + totalPages + '">»</button>';
    }

    $pag.html( html );

    $pag.find( '.st-pag-btn' ).on( 'click', function () {
      currentPage = parseInt( $( this ).data( 'page' ), 10 );
      loadAssignments();
      $( 'html, body' ).animate( { scrollTop: $( '#st-assignments-table' ).offset().top - 60 }, 200 );
    } );
  }

  function renderTable(rows) {
    const $tbody = $('#st-assignments-body');
    if (!rows || !rows.length) {
      $tbody.html('<tr><td colspan="11">Không có nhiệm vụ nào được giao trong khoảng thời gian này.</td></tr>');
      return;
    }

    let html = '';
    rows.forEach(function (r) {
      const target = parseInt(r.kpi_target, 10) || 1;
      const achieved = parseInt(r.achieved, 10) || 0;
      let percent = Math.round((achieved / target) * 100);
      if (percent > 100) percent = 100;
      
      let pColor = '#d63638'; // red
      if (percent >= 100) pColor = '#46b450'; // green
      else if (percent >= 50) pColor = '#f56e28'; // orange

      const progressBar = `
        <div style="background:#e5e5e5; border-radius:3px; height:10px; width:100%; margin-bottom:4px; overflow:hidden;">
          <div style="background:${pColor}; height:100%; width:${percent}%;"></div>
        </div>
        <div style="font-size:11px; text-align:right; color:#666;">${percent}% hoàn thành</div>
      `;

      let displayTime = esc(r.period_value || '');
      if (r.period_type === 'day') {
        if (displayTime.length === 10) {
          displayTime = displayTime.substring(8,10) + '/' + displayTime.substring(5,7) + '/' + displayTime.substring(0,4);
        }
      } else if (r.period_type === 'week') {
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
      } else if (r.period_type === 'year') {
        displayTime = 'Cả năm ' + displayTime;
      } else {
        // month
        if (displayTime.length === 7) {
          let y = parseInt(displayTime.substring(0,4), 10);
          let m = parseInt(displayTime.substring(5,7), 10);
          let endDay = new Date(y, m, 0).getDate(); // last day of month
          let mm = m < 10 ? '0' + m : m;
          displayTime = 'Từ 01/' + mm + '/' + y + ' đến ' + endDay + '/' + mm + '/' + y;
        }
      }

      let prodText = 'Toàn bộ sản phẩm';
      if (r.product_selection === 'category' || r.product_selection === 'random_category') {
        prodText = 'Danh mục ID: ' + (r.category_ids || '—');
      } else if (r.product_selection === 'custom' || r.product_selection === 'random_custom') {
        prodText = 'Sản phẩm ID: ' + (r.product_ids || '—');
      } else if (r.product_selection === 'random_all') {
        prodText = 'Ngẫu nhiên tất cả sản phẩm';
      } else if (r.product_selection === 'phases') {
        prodText = 'Theo giai đoạn (Phases)';
      }

      let statusBadge = '';
      if (r.status === 'paused') {
        statusBadge = '<span class="st-badge" style="background:#fffbe6; color:#faad14; border:1px solid #ffe58f; padding:2px 6px; border-radius:3px; font-weight:600;">Tạm dừng</span>';
      } else if (r.status === 'ended') {
        statusBadge = '<span class="st-badge" style="background:#f5f5f5; color:#8c8c8c; border:1px solid #d9d9d9; padding:2px 6px; border-radius:3px; font-weight:600;">Đã kết thúc</span>';
      } else {
        statusBadge = '<span class="st-badge" style="background:#e6f7ff; color:#1890ff; border:1px solid #91d5ff; padding:2px 6px; border-radius:3px; font-weight:600;">Hoạt động</span>';
      }

      let actionButtons = '';
      const excelUrl = STAdmin.exportUrl.replace('action=st_export_csv', 'action=st_export_assignment_excel') + '&id=' + r.id;
      if (r.status === 'ended') {
        actionButtons = `
          <button type="button" class="button button-small button-secondary st-view-details-btn" data-id="${esc(r.id)}" style="margin-right:5px;">Chi tiết</button>
          <a href="${excelUrl}" class="button button-small" style="margin-right:5px;background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7;" target="_blank">📥 Xuất Excel</a>
          <span style="color:#8c8c8c;font-style:italic;">Khóa</span>
        `;
      } else {
        let toggleBtn = '';
        if (r.status === 'active') {
          toggleBtn = `
            <a href="#" class="st-status-toggle" data-id="${r.id}" data-status="paused" style="color:#d97706;">⏸ Tạm dừng</a>
            <a href="#" class="st-status-toggle" data-id="${r.id}" data-status="ended" style="color:#b91c1c;">⏹ Kết thúc</a>
          `;
        } else if (r.status === 'paused') {
          toggleBtn = `
            <a href="#" class="st-status-toggle" data-id="${r.id}" data-status="active" style="color:#059669;">▶ Kích hoạt</a>
            <a href="#" class="st-status-toggle" data-id="${r.id}" data-status="ended" style="color:#b91c1c;">⏹ Kết thúc</a>
          `;
        }

        actionButtons = `
          <button type="button" class="button button-small button-secondary st-view-details-btn" data-id="${esc(r.id)}" style="margin-right:5px;">Chi tiết</button>
          <a href="${excelUrl}" class="button button-small" style="margin-right:5px;background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7;" target="_blank">📥 Xuất Excel</a>
          
          <div class="st-dropdown" style="display:inline-block; position:relative; vertical-align: middle;">
            <button type="button" class="button button-small st-dropdown-trigger" style="background:#fff;border:1px solid #ccc;cursor:pointer;font-weight:600;padding:0 8px;height:26px;line-height:24px;">•••</button>
            <div class="st-dropdown-menu" style="display:none; position:absolute; right:0; top:100%; margin-top:4px; background:#fff; border:1px solid #ccc; box-shadow:0 2px 8px rgba(0,0,0,0.15); border-radius:4px; z-index:10000; min-width:115px; text-align:left;">
              <a href="#" class="st-action-btn edit" 
                      data-id="${r.id}"
                      data-title="${esc(r.title)}"
                      data-status="${esc(r.status)}"
                      data-scope="${esc(r.assign_scope)}"
                      data-period-type="${esc(r.period_type)}"
                      data-period-value="${esc(r.period_value)}"
                      data-target-type="${esc(r.target_type)}"
                      data-target-value="${esc(r.target_value)}"
                      data-product-selection="${esc(r.product_selection)}"
                      data-product-ids="${esc(r.product_ids)}"
                      data-category-ids="${esc(r.category_ids)}"
                      data-phases-data="${esc(r.phases_data)}"
                      data-kpi="${esc(r.kpi_target)}">✏️ Sửa</a>
              <a href="#" class="st-action-btn copy" 
                      data-id="${r.id}"
                      data-title="${esc(r.title)}"
                      data-status="${esc(r.status)}"
                      data-scope="${esc(r.assign_scope)}"
                      data-period-type="${esc(r.period_type)}"
                      data-period-value="${esc(r.period_value)}"
                      data-target-type="${esc(r.target_type)}"
                      data-target-value="${esc(r.target_value)}"
                      data-product-selection="${esc(r.product_selection)}"
                      data-product-ids="${esc(r.product_ids)}"
                      data-category-ids="${esc(r.category_ids)}"
                      data-phases-data="${esc(r.phases_data)}"
                      data-kpi="${esc(r.kpi_target)}">📑 Sao chép</a>
              ${toggleBtn}
              <a href="#" class="st-action-btn delete" data-id="${r.id}">🗑 Xóa</a>
            </div>
          </div>
        `;
      }

      html += `<tr>
        <td><strong style="color:#2271b1;">${esc(r.title || '—')}</strong></td>
        <td><strong>${displayTime}</strong></td>
        <td>${r.assign_scope === 'general' ? '<span class="st-badge" style="background:#722ed1;color:#fff;padding:2px 6px;border-radius:3px;">Chung</span>' : '<span class="st-badge" style="background:#2f54eb;color:#fff;padding:2px 6px;border-radius:3px;">Riêng</span>'}</td>
        <td>${r.assign_scope === 'general' ? '—' : formatTargetDisplay(r.target_type, r.target_value)}</td>
        <td><span style="font-size:12px;color:#555;">${esc(prodText)}</span></td>
        <td>${esc(r.kpi_target)}</td>
        <td><strong>${esc(r.achieved)}</strong></td>
        <td>${progressBar}</td>
        <td><span style="font-size:12px;color:#555;">${esc(r.created_by || '—')}</span></td>
        <td>${statusBadge}</td>
        <td style="text-align:right; white-space: nowrap;">
          ${actionButtons}
        </td>
      </tr>`;
    });
    $tbody.html(html);
  }

  // Events
  $('#st-assign-filter-btn').on('click', function () {
    currentPage = 1;
    loadAssignments();
  });

  // Change input type dynamically
  function setupPeriodInput($select, $input) {
    $select.on('change', function() {
      const type = $(this).val();
      if (type === 'all') {
        $input.hide().val('');
      } else {
        $input.show();
        if (type === 'year') {
          $input.removeAttr('min').removeAttr('max');
          $input.attr('type', 'number').val(new Date().getFullYear());
        } else {
          $input.removeAttr('min').removeAttr('max');
          $input.attr('type', type === 'day' ? 'date' : type);
          const d = new Date();
          if (type === 'day') {
            const dd = String(d.getDate()).padStart(2, '0');
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            $input.val(d.getFullYear() + '-' + mm + '-' + dd);
          } else if (type === 'week') {
            const currentThurs = new Date(d.getTime() + (3 - ((d.getDay() + 6) % 7)) * 86400000);
            const yearStart = new Date(currentThurs.getFullYear(), 0, 4);
            const weekNum = Math.round(((currentThurs.getTime() - yearStart.getTime()) / 86400000 - 3 + (yearStart.getDay() + 6) % 7) / 7) + 1;
            $input.val(currentThurs.getFullYear() + '-W' + String(weekNum).padStart(2, '0'));
          } else {
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            $input.val(d.getFullYear() + '-' + mm);
          }
        }
      }
    });
  }

  setupPeriodInput($('#st-assign-period-type'), $('#st-assign-period-value'));
  setupPeriodInput($('#st-assign-form-period-type'), $('#st-assign-form-period-value'));

  // Toggle scope
  $('#st-assign-form-scope').on('change', function () {
    if ($(this).val() === 'general') {
      $('#st-assign-target-block').hide();
      $('#st-assign-form-value').removeAttr('required');
      $('#st-assign-form-value-select').removeAttr('required');
      $('#st-assign-form-value-custom').removeAttr('required');
    } else {
      $('#st-assign-target-block').show();
      $('#st-assign-form-value-select').attr('required', 'required');
      // Trigger target type select to populate initial list
      $('#st-assign-form-type').trigger('change');
    }
  });

  // Toggle WC product selection
  $('#st-assign-form-product-sel').on('change', function () {
    const sel = $(this).val();
    $('#st-assign-cat-block, #st-assign-prod-block, #st-assign-phases-block').hide();
    
    if (sel === 'category' || sel === 'random_category') {
      $('#st-assign-cat-block').show();
    } else if (sel === 'custom' || sel === 'random_custom') {
      $('#st-assign-prod-block').show();
    } else if (sel === 'phases') {
      $('#st-assign-phases-block').show();
      if ($('#st-assign-phases-container').children().length === 0) {
        addPhase();
      }
    }
  });

  function addPhase(data = null) {
    const idx = $('#st-assign-phases-container .st-phase-item').length;
    const html = `
      <div class="st-phase-item" style="border: 1px dashed #ccc; padding: 10px; margin-bottom: 10px; border-radius: 4px; position: relative;">
        <button type="button" class="button-link st-remove-phase" style="position: absolute; right: 10px; top: 10px; color: #a00;">Xóa</button>
        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
          <div style="flex:1;">
            <label style="font-size:12px;">Từ ngày</label>
            <input type="date" class="st-phase-start" style="width:100%;" required>
          </div>
          <div style="flex:1;">
            <label style="font-size:12px;">Đến ngày</label>
            <input type="date" class="st-phase-end" style="width:100%;" required>
          </div>
        </div>
        <div style="margin-bottom: 10px;">
          <label style="font-size:12px;">Sản phẩm</label>
          <select class="st-phase-sel" style="width:100%;">
              <option value="all">Toàn bộ sản phẩm</option>
              <option value="category">Theo Danh mục</option>
              <option value="custom">Sản phẩm cụ thể</option>
          </select>
        </div>
        <div class="st-phase-cat-block" style="display:none; margin-bottom: 10px;">
          <label style="font-size:12px;">Chọn Danh mục</label>
          <select class="st-phase-cats" multiple style="width:100%;"></select>
        </div>
        <div class="st-phase-prod-block" style="display:none; margin-bottom: 10px;">
          <label style="font-size:12px;">Chọn Sản phẩm</label>
          <select class="st-phase-prods-select" multiple style="width:100%;"></select>
        </div>
      </div>
    `;
    const $item = $(html);
    $('#st-assign-phases-container').append($item);
    
    // Copy options from main category select
    const catHtml = $('#st-assign-form-cats').html();
    $item.find('.st-phase-cats').html(catHtml);

    const initSlim = function(options) {
      if (options && options.length) {
        let h = '';
        options.forEach(opt => {
          h += `<option value="${esc(opt.value)}" selected>${esc(opt.text)}</option>`;
        });
        $item.find('.st-phase-prods-select').html(h);
      }
      try {
        new SlimSelect({
          select: $item.find('.st-phase-prods-select')[0],
          settings: { placeholderText: 'Gõ tên/ID...', searchPlaceholder: 'Tìm nhanh sản phẩm...', closeOnSelect: false },
          events: {
            search: function(search, currentData) {
              return new Promise((resolve, reject) => {
                $.ajax({
                  url: STAdmin.searchProductsEndpoint,
                  data: { q: search },
                  headers: { 'X-WP-Nonce': STAdmin.nonce },
                  success: function(res) {
                    const sel = $item.find('.st-phase-prods-select').val() || [];
                    resolve(res.filter(i => !sel.includes(i.value)));
                  },
                  error: function() { reject('Lỗi tìm kiếm'); }
                });
              });
            }
          }
        });
      } catch (e) {
        console.warn('SlimSelect phase prods failed', e);
      }
    };

    if (data) {
      $item.find('.st-phase-start').val(data.start_date || '');
      $item.find('.st-phase-end').val(data.end_date || '');
      $item.find('.st-phase-sel').val(data.product_selection || 'all');
      
      if (data.product_selection === 'category') {
        $item.find('.st-phase-cat-block').show();
        if (data.category_ids) {
          $item.find('.st-phase-cats').val(data.category_ids.split(','));
        }
      }
      
      if (data.product_selection === 'custom') {
        $item.find('.st-phase-prod-block').show();
        if (data.product_ids) {
          // fetch products
          $.ajax({
            url: STAdmin.apiBase + '/products-info',
            data: { ids: data.product_ids },
            headers: { 'X-WP-Nonce': STAdmin.nonce },
            success: function(res) {
               initSlim(res);
            },
            error: function() { initSlim([]); }
          });
        } else {
          initSlim([]);
        }
      } else {
        initSlim([]);
      }
    } else {
      initSlim([]);
    }
  }

  function addHoliday(data = null) {
    const html = `
      <div class="st-holiday-item" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
        <input type="date" class="st-holiday-date" required style="width: 140px;">
        <input type="text" class="st-holiday-note" placeholder="Ghi chú (Ví dụ: Quốc khánh)" style="flex: 1;">
        <button type="button" class="button-link st-remove-holiday" style="color: #a00;">Xóa</button>
      </div>
    `;
    const $item = $(html);
    $('#st-assign-holidays-container').append($item);
    
    if (data) {
      $item.find('.st-holiday-date').val(data.date || '');
      $item.find('.st-holiday-note').val(data.note || '');
    }
  }

  $('#st-add-holiday-btn').on('click', function() {
    addHoliday();
  });

  $(document).on('click', '.st-remove-holiday', function() {
    $(this).closest('.st-holiday-item').remove();
  });

  $('#st-add-phase-btn').on('click', function() {
      addPhase();
  });

  $('#st-assign-phases-container').on('click', '.st-remove-phase', function() {
      $(this).closest('.st-phase-item').remove();
  });

  $('#st-assign-phases-container').on('change', '.st-phase-sel', function() {
      const v = $(this).val();
      const $item = $(this).closest('.st-phase-item');
      $item.find('.st-phase-cat-block, .st-phase-prod-block').hide();
      if (v === 'category') $item.find('.st-phase-cat-block').show();
      else if (v === 'custom') $item.find('.st-phase-prod-block').show();
  });

  // Populate Target Value options dynamically
  function populateTargetSelect(targetType, currentValue) {
    const $select = $('#st-assign-form-value-select');
    let html = '<option value="">-- Chọn đối tượng --</option>';
    let exists = false;
    const selectedVals = currentValue ? String(currentValue).split(',') : [];

    if (targetType === 'team') {
      const rawTeams = STAdmin.teamsList || [];
      const teams = Array.isArray(rawTeams) ? rawTeams : Object.values(rawTeams);
      teams.forEach(function (team) {
        const isSel = selectedVals.includes(team);
        if (isSel) exists = true;
        html += `<option value="${esc(team)}" ${isSel ? 'selected' : ''}>${esc(team)}</option>`;
      });
    } else if (targetType === 'class_code') {
      const rawClasses = STAdmin.classesList || [];
      const classes = Array.isArray(rawClasses) ? rawClasses : Object.values(rawClasses);
      classes.forEach(function (cls) {
        const isSel = selectedVals.includes(cls);
        if (isSel) exists = true;
        html += `<option value="${esc(cls)}" ${isSel ? 'selected' : ''}>Lớp: ${esc(cls)}</option>`;
      });
    } else if (targetType === 'emp_code') {
      const rawStaff = STAdmin.staffList || [];
      const staff = Array.isArray(rawStaff) ? rawStaff : Object.values(rawStaff);
      staff.forEach(function (st) {
        const display = st.emp_code + (st.user_name ? ' - ' + st.user_name : '');
        const isSel = selectedVals.includes(st.emp_code);
        if (isSel) exists = true;
        html += `<option value="${esc(st.emp_code)}" ${isSel ? 'selected' : ''}>${esc(display)}</option>`;
      });
    }

    // Luôn có option custom nhập mới
    const isCustomSel = (currentValue && !exists) || (currentValue === '__custom__');
    html += `<option value="__custom__" ${isCustomSel ? 'selected' : ''}>[ + Nhập mới / Đối tượng khác... ]</option>`;

    $select.html(html);
    valSelectSlim = applySlimSelect($select, valSelectSlim);

    // Xử lý hiển thị block nhập mới
    if (isCustomSel) {
      $('#st-assign-custom-target-block').show();
      if (currentValue && currentValue !== '__custom__') {
        $('#st-assign-form-value-custom').val(currentValue);
      }
      $('#st-assign-form-value-custom').attr('required', 'required');
    } else {
      $('#st-assign-custom-target-block').hide();
      $('#st-assign-form-value-custom').val('').removeAttr('required');
    }
  }

  // Handle target type selection change
  $('#st-assign-form-type').on('change', function() {
    populateTargetSelect($(this).val(), '');
  });

  // Handle target value select box change
  $('#st-assign-form-value-select').on('change', function() {
    const val = $(this).val();
    const isCustom = Array.isArray(val) ? val.includes('__custom__') : (val === '__custom__');
    if (isCustom) {
      $('#st-assign-custom-target-block').show();
      $('#st-assign-form-value-custom').attr('required', 'required').focus();
    } else {
      $('#st-assign-custom-target-block').hide();
      $('#st-assign-form-value-custom').val('').removeAttr('required');
      if (Array.isArray(val)) {
        $('#st-assign-form-value').val(val.join(','));
      } else {
        $('#st-assign-form-value').val(val || '');
      }
    }
  });

  // Modal (Create mode)
  function openModal(row = null) {
    $('#st-assign-form')[0].reset();
    $('#st-assign-form-id').val(''); 
    $('#st-assign-status-group').hide();
    $('#st-assign-form-status').val('active');
    $('#st-assign-modal-title').text('Giao nhiệm vụ mới');
    
    $('#st-assign-form-scope').trigger('change');
    $('#st-assign-form-product-sel').trigger('change');
    
    $('#st-assign-phases-container').empty();
    $('#st-assign-holidays-container').empty();
    try {
      if (catsSlimInstance) {
        catsSlimInstance.setSelected([]);
      }
      if (productsSlimInstance) {
        productsSlimInstance.destroy();
        productsSlimInstance = null;
        $('#st-assign-form-products-select').html('');
      }
    } catch(e){}

    if (row) {
        // ... (existing logic to fill modal with row data) ...
        if (row.holidays) {
          try {
            const holidaysData = typeof row.holidays === 'string' ? JSON.parse(row.holidays) : row.holidays;
            if (Array.isArray(holidaysData)) {
              holidaysData.forEach(h => addHoliday(h));
            }
          } catch(e){}
        }
    }

    // Load demo products on modal open
    $.ajax({
      url: STAdmin.searchProductsEndpoint,
      data: { q: '' },
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function(res) {
        initProductsSlim(res);
      },
      error: function() {
        initProductsSlim([]);
      }
    });

    if (catsSlim) {
      catsSlim.setSelected([]);
    } else {
      $('#st-assign-form-cats').val([]);
    }

    // Copy filter state to modal (if not 'all')
    const currentType = $('#st-assign-period-type').val();
    const currentValue = $('#st-assign-period-value').val();
    if (currentType && currentType !== 'all') {
      $('#st-assign-form-period-type').val(currentType);
      $('#st-assign-form-period-value').attr('type', currentType === 'year' ? 'number' : (currentType === 'day' ? 'date' : currentType)).val(currentValue);
    } else {
      // Default to current month
      $('#st-assign-form-period-type').val('month');
      const d = new Date();
      const mm = String(d.getMonth() + 1).padStart(2, '0');
      $('#st-assign-form-period-value').attr('type', 'month').val(d.getFullYear() + '-' + mm);
    }

    $('#st-assign-modal').fadeIn(200);
    $('body').addClass('st-modal-open');
  }

  function closeModal() {
    $('#st-assign-modal').fadeOut(200);
    $('body').removeClass('st-modal-open');
  }

  $('#st-assign-add-btn').on('click', openModal);
  $('.st-assign-cancel').on('click', closeModal);

  // Edit Click
  $('#st-assignments-body').on('click', '.edit', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const title = $(this).data('title') || '';
    const status = $(this).data('status') || 'active';
    const scope = $(this).data('scope') || 'specific';
    const pType = $(this).data('period-type') || 'month';
    const pValue = $(this).data('period-value') || '';
    const tType = $(this).data('target-type') || 'team';
    const tValue = $(this).data('target-value') || '';
    const pSel = $(this).data('product-selection') || 'all';
    const pIds = $(this).data('product-ids') || '';
    const cIds = $(this).data('category-ids') || '';
    const phasesStr = $(this).data('phases-data') || '';
    const kpi = $(this).data('kpi') || '';

    // Parse phases data if available
    let phasesData = null;
    if (phasesStr) {
      if (typeof phasesStr === 'object') {
        phasesData = phasesStr;
      } else {
        try {
          // html-encoded json
          phasesData = JSON.parse($('<textarea/>').html(phasesStr).text());
        } catch (e) {
          console.error('Failed to parse phases data', e);
        }
      }
    }

    // Fill form
    $('#st-assign-form-id').val(id);
    $('#st-assign-form-title').val(title);
    $('#st-assign-status-group').show();
    $('#st-assign-form-status').val(status);
    $('#st-assign-form-period-type').val(pType);
    
    if (pType === 'year') {
      $('#st-assign-form-period-value').attr('type', 'number').val(pValue);
    } else {
      $('#st-assign-form-period-value').attr('type', pType === 'day' ? 'date' : pType).val(pValue);
    }

    $('#st-assign-form-scope').val(scope);
    
    if (scope === 'general') {
      $('#st-assign-target-block').hide();
      $('#st-assign-form-value').removeAttr('required');
      $('#st-assign-form-value-select').removeAttr('required');
      $('#st-assign-form-value-custom').removeAttr('required');
    } else {
      $('#st-assign-target-block').show();
      $('#st-assign-form-value-select').attr('required', 'required');
      $('#st-assign-form-type').val(tType);
      populateTargetSelect(tType, tValue);
    }

    $('#st-assign-form-value').val(tValue);
    
    // reset phases container
    $('#st-assign-phases-container').empty();
    if (pSel === 'phases' && phasesData && Array.isArray(phasesData)) {
       phasesData.forEach(p => addPhase(p));
    }
    
    $('#st-assign-form-product-sel').val(pSel).trigger('change');
    $('#st-assign-form-products').val(pIds);
    
    // Fetch selected product titles for edit click
    if (pIds) {
      $.ajax({
        url: STAdmin.searchProductsEndpoint,
        data: { ids: pIds },
        headers: { 'X-WP-Nonce': STAdmin.nonce },
        success: function(res) {
          initProductsSlim(res);
          const idsArr = String(pIds).split(',');
          if (productsSlim) {
            productsSlim.setSelected(idsArr);
          }
        },
        error: function() {
          initProductsSlim([]);
        }
      });
    } else {
      $.ajax({
        url: STAdmin.searchProductsEndpoint,
        data: { q: '' },
        headers: { 'X-WP-Nonce': STAdmin.nonce },
        success: function(res) {
          initProductsSlim(res);
        },
        error: function() {
          initProductsSlim([]);
        }
      });
    }
    
    // Set multi select cats
    const $cats = $('#st-assign-form-cats');
    $cats.val([]); 
    let catArr = [];
    if (cIds) {
      catArr = String(cIds).split(',');
    }
    if (catsSlim) {
      catsSlim.setSelected(catArr);
    } else {
      $cats.val(catArr);
    }

    $('#st-assign-form-kpi').val(kpi);

    $('#st-assign-modal-title').text('Chỉnh sửa nhiệm vụ');
    $('#st-assign-modal').fadeIn(200);
    $('body').addClass('st-modal-open');
  });

  // Copy
  $('#st-assignments-body').on('click', '.copy', function (e) {
    e.preventDefault();
    const title = $(this).data('title');
    
    // Trigger edit to populate all fields correctly (including AJAX loading)
    $(this).siblings('.edit').trigger('click');
    
    // Override specific fields for copy
    setTimeout(() => {
      $('#st-assign-form-id').val('');
      $('#st-assign-form-title').val(title + ' (Bản sao)');
      $('#st-assign-modal-title').text('Sao chép nhiệm vụ');
    }, 50);
  });

  // Delete
  $('#st-assignments-body').on('click', '.delete', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    if (confirm('Bạn có chắc chắn muốn xóa phân công này?')) {
      $.ajax({
        url: apiBase + '/' + id,
        type: 'DELETE',
        headers: { 'X-WP-Nonce': STAdmin.nonce },
        success: function () {
          loadAssignments();
        },
        error: function (xhr) {
          alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể xóa'));
        }
      });
    }
  });

  // Submit form
  $('#st-assign-form').on('submit', function (e) {
    e.preventDefault();

    // Đồng bộ giá trị đối tượng trước khi đóng gói
    const scope = $('#st-assign-form-scope').val();
    if (scope === 'specific') {
      const selVal = $('#st-assign-form-value-select').val();
      if (selVal === '__custom__') {
        $('#st-assign-form-value').val($('#st-assign-form-value-custom').val().trim());
      } else {
        $('#st-assign-form-value').val(selVal);
      }
    } else {
      $('#st-assign-form-value').val('');
    }
    
    // Đồng bộ product_ids từ SlimSelect (mảng các ID) sang input ẩn phân tách bằng dấu phẩy
    const prodSelection = $('#st-assign-form-product-sel').val();
    if (prodSelection === 'custom' || prodSelection === 'random_custom') {
      const productSelectVal = $('#st-assign-form-products-select').val();
      if (productSelectVal && Array.isArray(productSelectVal)) {
        $('#st-assign-form-products').val(productSelectVal.join(','));
      } else {
        $('#st-assign-form-products').val('');
      }
    } else {
      $('#st-assign-form-products').val('');
    }

    const formData = {};
    $(this).serializeArray().forEach(item => {
      if (item.name === 'category_ids') {
        if (!formData.category_ids) {
          formData.category_ids = [];
        }
        formData.category_ids.push(item.value);
      } else {
        formData[item.name] = item.value;
      }
    });

    if (Array.isArray(formData.category_ids)) {
      formData.category_ids = formData.category_ids.join(',');
    }

    if (prodSelection === 'phases') {
      const phasesData = [];
      $('#st-assign-phases-container .st-phase-item').each(function() {
         const pSel = $(this).find('.st-phase-sel').val();
         let pCats = '';
         if (pSel === 'category') {
             const cVals = $(this).find('.st-phase-cats').val();
             pCats = cVals && cVals.length ? cVals.join(',') : '';
         }
         let pProds = '';
         if (pSel === 'custom') {
             const pVals = $(this).find('.st-phase-prods-select').val();
             pProds = pVals && pVals.length ? pVals.join(',') : '';
         }
         phasesData.push({
             start_date: $(this).find('.st-phase-start').val(),
             end_date: $(this).find('.st-phase-end').val(),
             product_selection: pSel,
             category_ids: pCats,
             product_ids: pProds
         });
      });
      formData.phases = phasesData;
      formData.phases = phasesData;
    }

    const holidays = [];
    $('#st-assign-holidays-container .st-holiday-item').each(function() {
      const hDate = $(this).find('.st-holiday-date').val();
      const hNote = $(this).find('.st-holiday-note').val();
      if (hDate) {
        holidays.push({ date: hDate, note: hNote });
      }
    });
    formData.holidays = holidays;

    const id = $('#st-assign-form-id').val();
    const isEdit = !!id;

    const $btn = $(this).find('button[type="submit"]');
    $btn.prop('disabled', true).text('Đang lưu...');

    $.ajax({
      url: isEdit ? apiBase + '/' + id : apiBase,
      type: isEdit ? 'PUT' : 'POST',
      contentType: 'application/json',
      data: JSON.stringify(formData),
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function () {
        closeModal();
        $('#st-assign-period-type').val(formData.period_type);
        $('#st-assign-period-value').attr('type', formData.period_type === 'year' ? 'number' : (formData.period_type === 'day' ? 'date' : formData.period_type)).val(formData.period_value);
        loadAssignments();
      },
      error: function (xhr) {
        alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể lưu'));
      },
      complete: function () {
        $btn.prop('disabled', false).text('Lưu phân công');
      }
    });
  });

  // Handle status toggle (Tạm dừng, Kích hoạt, Kết thúc)
  $(document).on('click', '.st-status-toggle', function (e) {
    e.preventDefault();
    if ($(this).hasClass('st-in-flight')) return;
    const id = $(this).data('id');
    const newStatus = $(this).data('status');
    let confirmMsg = 'Bạn có chắc chắn muốn thay đổi trạng thái của nhiệm vụ này?';
    if (newStatus === 'ended') {
      confirmMsg = 'Bạn có chắc chắn muốn KẾT THÚC nhiệm vụ này? Lượng traffic sẽ không được ghi nhận nữa và hệ thống sẽ tự động tính toán tiền thưởng ngay lập tức cho các nhân sự hoàn thành!';
    }
    if (!confirm(confirmMsg)) return;

    const $btn = $(this);
    $btn.addClass('st-in-flight');

    $.ajax({
      url: apiBase + '/' + id,
      method: 'PUT',
      contentType: 'application/json',
      data: JSON.stringify({ status: newStatus }),
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function (res) {
        loadAssignments();
      },
      error: function (xhr) {
        alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể cập nhật trạng thái.'));
        $btn.removeClass('st-in-flight');
      }
    });
  });

  // ─── Modal state for Details ──────────────────────────────────────────────
  const payoutApi = STAdmin.payoutsEndpoint;
  let currentActiveAssignmentId = null;
  let modalState = {
    id: null,
    page: 1,
    perPage: 25,
    search: '',
    status: '',
    inFlight: false,
  };

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

  // Details Modal
  function openDetailsModal(id, resetFilters) {
    modalState.id = id;
    if (resetFilters !== false) {
      modalState.page   = 1;
      modalState.search = '';
      modalState.status = '';
      $('#st-modal-search').val('');
      $('#st-modal-status-filter').val('');
    }
    currentActiveAssignmentId = id;

    // Sét tiêu đề modal phù hợp với phân công KPI
    $('#st-payout-modal-title').text('Chi tiết KPI & tiến độ nhiệm vụ');

    // Hiển thị các cột KPI, ẩn các cột thanh toán tiền
    $('.st-col-payout').hide();
    $('.st-col-kpi').show();
    $('#st-payout-pay-all-btn').hide();

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
      url: apiBase + '/' + modalState.id + '/details',
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

        if (modalState.page === 1 && !modalState.search && !modalState.status) {
          $info.html(`
            <strong>Nhiệm vụ:</strong> ${esc(assign.title)} |
            <strong>KPI:</strong> ${esc(assign.kpi_target)} traffic |
            <strong>Chu kỳ:</strong> ${formatPeriodDisplay(assign.period_type, assign.period_value)}
          `);
        }

        $badge.text(`Tổng: ${fmt(total)} nhân sự`);

        let html = '';

        if (!payouts || payouts.length === 0) {
          $tbody.html('<tr><td colspan="9" style="text-align:center;padding:15px;color:#787c82;">' +
            (modalState.search || modalState.status
              ? 'Không tìm thấy nhân sự phù hợp.'
              : 'Không có dữ liệu tiến độ KPI.') +
            '</td></tr>');
          $payAllBtn.hide();
          renderModalPagination(total);
          return;
        }

        payouts.forEach(function(p) {
          const targetVal = parseInt(p.kpi_target, 10) || 1;
          const achievedVal = parseInt(p.kpi_achieved, 10) || 0;
          let percentVal = Math.round((achievedVal / targetVal) * 100);
          if (percentVal > 100) percentVal = 100;
          let colorVal = '#d63638';
          if (percentVal >= 100) colorVal = '#46b450';
          else if (percentVal >= 50) colorVal = '#f56e28';

          const kpiProgressHtml = `
            <div style="background:#e5e5e5; border-radius:3px; height:8px; width:100px; margin-bottom:2px; overflow:hidden; display:inline-block; vertical-align:middle;">
              <div style="background:${colorVal}; height:100%; width:${percentVal}%;"></div>
            </div>
            <span style="font-size:11px; margin-left:4px; font-weight:600;">${percentVal}%</span>
          `;

          const isCompleted = p.status === 'completed';
          const kpiStatusBadge = isCompleted
            ? '<span class="st-badge st-badge-ok" style="background:#f6ffed;color:#52c41a;border:1px solid #b7eb8f;padding:2px 6px;border-radius:3px;">Đạt KPI</span>'
            : '<span class="st-badge" style="background:#fff7e6;color:#d46b08;border:1px solid #ffd591;padding:2px 6px;border-radius:3px;font-weight:600;">Chưa đạt</span>';

          let bankInfoHtml = '';
          if (p.bank_account) {
            bankInfoHtml = `<span style="font-size:11.5px;color:#1e293b;background:#f1f5f9;padding:2px 6px;border-radius:4px;border:1px solid #e2e8f0;white-space:nowrap;display:inline-block;" title="Chủ tài khoản: ${esc(p.bank_account.account_name)}">🏦 <strong>${esc(p.bank_account.bank_code)}</strong> - ${esc(p.bank_account.account_number)}</span>`;
          } else {
            bankInfoHtml = `<span style="font-size:11px;color:#ef4444;font-weight:500;">⚠️ Chưa liên kết</span>`;
          }

          const viewLogsUrl = `admin.php?page=st-logs&empcode=${encodeURIComponent(p.emp_code)}&classcode=${encodeURIComponent(p.class_code || '')}&exact=1`;
          let actionHtml = `<button type="button" class="button button-small st-view-daily-btn" data-emp="${esc(p.emp_code)}" style="height:26px;line-height:24px;padding:0 8px;margin-right:4px;">📅 Chi tiết ngày</button>`;
          actionHtml += `<a href="${viewLogsUrl}" class="button button-small" target="_blank" style="height:26px;line-height:24px;padding:0 8px;">Xem logs</a>`;

          html += `
            <tr>
              <td><strong>${esc(p.user_name)}</strong></td>
              <td>${bankInfoHtml}</td>
              <td><code>${esc(p.emp_code)}</code></td>
              <td>${esc(p.class_code)}</td>
              <td>${esc(p.team)}</td>
              <td><span style="color:#1a7a2e;font-weight:600;">${esc(p.kpi_achieved)}</span></td>
              <td><strong>${esc(p.avg_onsite)} s</strong></td>
              <td class="st-col-payout" style="display:none;"><strong style="color:#2271b1;">0 đ</strong></td>
              <td class="st-col-payout" style="display:none;">—</td>
              <td class="st-col-kpi">${kpiProgressHtml}</td>
              <td class="st-col-kpi">${kpiStatusBadge}</td>
              <td style="text-align:right;vertical-align:middle;white-space:nowrap;">${actionHtml}</td>
            </tr>
          `;
        });

        $tbody.html(html);
        $payAllBtn.hide(); // Ẩn nút pay-all trên màn phân công
        renderModalPagination(total);
      },
      error: function() {
        $tbody.html('<tr><td colspan="9" style="text-align:center;padding:15px;color:#d63638;">Không thể tải chi tiết tiến độ.</td></tr>');
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

  // Open modal
  $('#st-assignments-table').on('click', '.st-view-details-btn', function() {
    openDetailsModal($(this).data('id'), true);
  });

  // Close
  $('#st-payout-modal-close').on('click', closeDetailsModal);
  $(window).on('click', function(e) {
    if ($(e.target).is('#st-payout-details-modal')) closeDetailsModal();
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

  // Mark individual as paid (không dùng ở màn KPI, nhưng giữ cho đủ callback nếu có gọi)
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
        loadAssignments();
      },
      error: function (xhr) {
        alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể cập nhật trạng thái.'));
        $btn.prop('disabled', false).text('Đã chuyển tiền');
      }
    });
  });

  // ─── Daily Details Sub-Modal ─────────────────────────────────────────────
  function openDailyDetailsModal(empCode) {
    if (!currentActiveAssignmentId) return;

    $('#st-daily-details-tbody').html('<tr><td colspan="4" class="st-loading" style="text-align:center;padding:18px;">Đang tải...</td></tr>');
    $('#st-daily-completed-days').text('0');
    $('#st-daily-missed-days').text('0');
    $('#st-daily-total-days').text('0');

    $('#st-daily-details-modal').fadeIn(160);

    $.ajax({
      url: `${apiBase}/${currentActiveAssignmentId}/staff/${empCode}/details`,
      method: 'GET',
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function(res) {
        $('#st-daily-completed-days').text(res.completed_days);
        $('#st-daily-missed-days').text(res.missed_days);
        $('#st-daily-total-days').text(res.total_days);

        let html = '';
        if (res.daily_stats && res.daily_stats.length > 0) {
          res.daily_stats.forEach(function(stat) {
            let rowStyle = '';
            let statusHtml = '';
            
            if (stat.is_holiday) {
              statusHtml = `<span style="color:#d48806;font-size:12px;font-style:italic;">Nghỉ lễ: ${esc(stat.holiday_note || '')}</span>`;
              rowStyle = 'background-color: #fffbe6; color: #d48806;';
            } else if (stat.is_sunday && stat.achieved === 0) {
              statusHtml = '<span style="color:#94a3b8;font-size:12px;font-style:italic;">Nghỉ Chủ nhật</span>';
              rowStyle = 'background-color: #f8fafc; color: #94a3b8;';
            } else if (stat.is_past || stat.is_today) {
              if (stat.target > 0) {
                if (stat.achieved >= stat.target) {
                  statusHtml = '<span class="st-badge st-badge-ok" style="background:#f6ffed;color:#52c41a;border:1px solid #b7eb8f;padding:2px 6px;border-radius:3px;">Hoàn thành</span>';
                  rowStyle = 'background-color: #f0fdf4;';
                } else {
                  statusHtml = '<span class="st-badge" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:2px 6px;border-radius:3px;">Chưa đạt</span>';
                }
              } else {
                statusHtml = '<span style="color:#94a3b8;font-size:12px;">Không có chỉ tiêu</span>';
                rowStyle = 'background-color: #f8fafc; color: #94a3b8;';
              }
            } else {
              statusHtml = '<span style="color:#94a3b8;font-size:12px;">Chưa tới ngày</span>';
            }

            const dateObj = new Date(stat.date);
            const dateStr = (dateObj.getDate() < 10 ? '0' : '') + dateObj.getDate() + '/' + 
                            ((dateObj.getMonth() + 1) < 10 ? '0' : '') + (dateObj.getMonth() + 1) + '/' + 
                            dateObj.getFullYear();

            let achievedStyle = 'color:#1a7a2e;';
            if (stat.is_past || stat.is_today) {
              if (stat.target <= 0 && !stat.is_holiday) achievedStyle = 'color:#94a3b8;';
            }
            
            let targetDisplay = stat.target > 0 ? stat.target : '-';
            if (stat.is_holiday) {
                targetDisplay = '0';
            }

            html += `
              <tr style="${rowStyle}">
                <td><strong>${dateStr}</strong></td>
                <td style="text-align:center;">${targetDisplay}</td>
                <td style="text-align:center;"><strong style="${achievedStyle}">${stat.achieved}</strong></td>
                <td style="text-align:center;">${statusHtml}</td>
              </tr>
            `;
          });
        } else {
          html = '<tr><td colspan="4" style="text-align:center;padding:15px;color:#d63638;">Không có dữ liệu ngày.</td></tr>';
        }
        $('#st-daily-details-tbody').html(html);
      },
      error: function() {
        $('#st-daily-details-tbody').html('<tr><td colspan="4" style="text-align:center;padding:15px;color:#d63638;">Lỗi tải dữ liệu.</td></tr>');
      }
    });
  }

  function closeDailyDetailsModal() {
    $('#st-daily-details-modal').fadeOut(160);
  }

  $('#st-payout-modal-body').on('click', '.st-view-daily-btn', function() {
    const empCode = $(this).data('emp');
    openDailyDetailsModal(empCode);
  });

  $('#st-daily-modal-close, #st-daily-modal-btn-close').on('click', closeDailyDetailsModal);
  $(window).on('click', function(e) {
    if ($(e.target).is('#st-daily-details-modal')) closeDailyDetailsModal();
  });

  // Recalculate assignment payout / live details
  $('#st-payout-recalc-assignment-btn').on('click', function() {
    if (!modalState.id) return;
    const $btn = $(this);
    $btn.prop('disabled', true).text('Đang tính...');

    $.ajax({
      url: apiBase + '/' + modalState.id + '/recalculate',
      method: 'POST',
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function(res) {
        modalState.page = 1;
        loadModalPage();
        loadAssignments();
        if (res.count === 0) alert('Không có nhân sự nào chạy traffic trong nhiệm vụ này.');
      },
      error: function(xhr) {
        alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể tính lại hoa hồng.'));
      },
      complete: function() {
        $btn.prop('disabled', false).text('🔄 Tính lại');
      }
    });
  });

  // Export Assignment Excel
  $('#st-export-assignment-btn').on('click', function() {
    if (!modalState.id) return;
    if (typeof XLSX === 'undefined') {
      alert('Thư viện Excel chưa được tải. Vui lòng tải lại trang.');
      return;
    }
    const $btn = $(this);
    $btn.prop('disabled', true).text('⏳ Đang xuất...');

    $.ajax({
      url: apiBase + '/' + modalState.id + '/export-data',
      method: 'GET',
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function(res) {
        if (!res.assignment || !res.staff) {
          alert('Dữ liệu không hợp lệ.');
          return;
        }
        
        try {
          const wb = XLSX.utils.book_new();
          
          // Sheet 1: Tổng quan
          const assign = res.assignment;
          const overviewData = [
            ["Thông tin Nhiệm vụ / Dự án"],
            ["Tên nhiệm vụ:", assign.title || ''],
            ["Chu kỳ:", assign.period_type + ' - ' + assign.period_value],
            ["Phạm vi:", assign.assign_scope],
            ["Chỉ tiêu:", assign.kpi_target],
            ["Sản phẩm:", assign.product_selection],
            ["Trạng thái:", assign.status === 'active' ? 'Đang chạy' : (assign.status === 'ended' ? 'Đã kết thúc' : 'Tạm dừng')],
            ["Người tạo:", assign.created_by],
            ["Ngày tạo:", assign.created_at]
          ];
          const wsOverview = XLSX.utils.aoa_to_sheet(overviewData);
          wsOverview['!cols'] = [{wch: 20}, {wch: 40}];
          XLSX.utils.book_append_sheet(wb, wsOverview, "Tổng quan");
          
          // Sheet 2: Tiến độ Nhân sự
          const staffData = [
            ["Mã NV", "Họ tên", "Lớp", "Team", "Chỉ tiêu", "Đã đạt", "Tiến độ (%)", "Avg Onsite (s)", "Số ngày hoàn thành", "Số ngày chưa đạt", "Ngân hàng", "STK", "Chủ TK", "Tiền hoa hồng", "Trạng thái thanh toán"]
          ];
          
          res.staff.forEach(s => {
            const progress = s.kpi_target > 0 ? ((s.kpi_achieved / s.kpi_target) * 100).toFixed(1) + '%' : '100%';
            staffData.push([
              s.emp_code,
              s.user_name,
              s.class_code,
              s.team,
              s.kpi_target,
              s.kpi_achieved,
              progress,
              s.avg_onsite,
              s.completed_days || 0,
              s.missed_days || 0,
              s.bank_account ? s.bank_account.bank_code : '',
              s.bank_account ? s.bank_account.account_number : '',
              s.bank_account ? s.bank_account.account_name : '',
              s.payout_amount || 0,
              s.payout_status === 'paid' ? 'Đã chuyển' : 'Chưa chuyển'
            ]);
          });
          
          const wsStaff = XLSX.utils.aoa_to_sheet(staffData);
          wsStaff['!cols'] = [{wch: 15}, {wch: 25}, {wch: 15}, {wch: 25}, {wch: 10}, {wch: 10}, {wch: 12}, {wch: 15}, {wch: 18}, {wch: 18}, {wch: 15}, {wch: 20}, {wch: 20}, {wch: 15}, {wch: 20}];
          XLSX.utils.book_append_sheet(wb, wsStaff, "Tiến độ nhân sự");

          // Sheet 3: Chi tiết Từng ngày
          const dailyData = [
            ["Ngày", "Họ tên", "Mã NV", "Lớp", "Team", "Chỉ tiêu", "Thực đạt", "Trạng thái"]
          ];
          
          res.staff.forEach(s => {
            if (s.daily_stats && s.daily_stats.length > 0) {
              s.daily_stats.forEach(d => {
                let status = "Chưa tới ngày";
                if (d.is_past || d.is_today) {
                  if (d.target > 0) {
                    if (d.achieved >= d.target) status = "Hoàn thành";
                    else status = "Chưa đạt";
                  } else {
                    status = "Không có chỉ tiêu";
                  }
                }
                dailyData.push([
                  d.date,
                  s.user_name,
                  s.emp_code,
                  s.class_code,
                  s.team,
                  d.target,
                  d.achieved,
                  status
                ]);
              });
            }
          });
          
          const wsDaily = XLSX.utils.aoa_to_sheet(dailyData);
          wsDaily['!cols'] = [{wch: 15}, {wch: 25}, {wch: 15}, {wch: 15}, {wch: 25}, {wch: 10}, {wch: 10}, {wch: 20}];
          XLSX.utils.book_append_sheet(wb, wsDaily, "Chi tiết từng ngày");
          
          const filename = "ThongKe_NhiemVu_" + (assign.title ? assign.title.replace(/[^a-z0-9]/gi, '_') : assign.id) + ".xlsx";
          XLSX.writeFile(wb, filename);

        } catch (e) {
          console.error(e);
          alert('Có lỗi xảy ra khi tạo file Excel.');
        }
      },
      error: function(xhr) {
        alert('Lỗi: ' + (xhr.responseJSON?.error || 'Không thể lấy dữ liệu xuất.'));
      },
      complete: function() {
        $btn.prop('disabled', false).text('📥 Xuất Excel');
      }
    });
  });

  // Toggle action dropdown in assignments table
  $(document).on('click', '.st-dropdown-trigger', function(e) {
    e.stopPropagation();
    $('.st-dropdown-menu').not($(this).next('.st-dropdown-menu')).hide();
    $(this).next('.st-dropdown-menu').toggle();
  });

  $(document).on('click', function() {
    $('.st-dropdown-menu').hide();
  });

  // Init
  loadAssignments();
  initCatsSlim();
});
