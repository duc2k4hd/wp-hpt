/**
 * Staff Tracking — Admin JS
 * Depends on: jQuery (WP core), STAdmin (wp_localize_script)
 */
( function ( $ ) {
  'use strict';

  if ( typeof STAdmin === 'undefined' ) return;

  /* ── Shared state ──────────────────────────────────────────────────────── */
  const state = {
    startDate : todayStr(),
    endDate   : todayStr(),
    staff     : '',
    empCode   : '',
    classCode : '',
    team      : '',
    exact     : 0,
    valid     : '',
    page      : 1,
    perPage   : 25,
    orderby   : 'created_at',
    order     : 'DESC',
  };

  /* ── Boot ───────────────────────────────────────────────────────────────── */
  $( document ).ready( function () {
    bindDateFilters();

    if ( $( '#st-kpi-grid' ).length ) {
      loadKPI();

      $( document ).on( 'click', '#st-kpi-refresh', function () {
        const btn = $( this );
        btn.prop( 'disabled', true );
        btn.find( '.dashicons' ).addClass( 'spin' );
        loadKPI( true, function () {
          btn.prop( 'disabled', false );
          btn.find( '.dashicons' ).removeClass( 'spin' );
        } );
      } );
    }
    if ( $( '#st-logs-body' ).length ) {
      bindLogFilters();
      bindSortHeaders();

      // Read URL params if redirected from Personnel
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('staff') !== null) {
        state.staff = urlParams.get('staff');
        $('#st-filter-staff').val(state.staff);
      }
        if (urlParams.get('empcode') !== null || urlParams.get('emp_code') !== null) {
          state.empCode = urlParams.get('empcode') || urlParams.get('emp_code');
          $('#st-filter-empcode').val(state.empCode);
        }
        if (urlParams.get('classcode') !== null || urlParams.get('class_code') !== null) {
          state.classCode = urlParams.get('classcode') || urlParams.get('class_code');
          $('#st-filter-classcode').val(state.classCode);
        }
      if (urlParams.get('team') !== null) {
        state.team = urlParams.get('team');
        $('#st-filter-team').val(state.team);
      }
      if (urlParams.get('exact') === '1') {
        state.exact = 1;
        $('#st-filter-exact').prop('checked', true);
      }
      // Also reset date filter to broader range if redirected, maybe "All time"
      if (urlParams.has('staff') || urlParams.has('empcode') || urlParams.has('classcode') || urlParams.has('team')) {
        state.startDate = '2024-01-01'; // Broad past date
        state.endDate = todayStr();
        $('.st-date-from').val(state.startDate);
        $('.st-date-to').val(state.endDate);
        $('.st-preset').removeClass('active'); // clear preset highlights
      }

      loadLogs();
    }
    if ( $( '#st-do-export' ).length ) {
      bindExport();
    }
  } );

  /* ── Date filter (shared across pages) ──────────────────────────────────── */
  function bindDateFilters() {
    $( document ).on( 'click', '.st-preset', function () {
      $( '.st-preset' ).removeClass( 'active' );
      $( this ).addClass( 'active' );
      const container = $( this ).closest( '.st-date-filter' );
      container.find( '.st-date-from' ).val( $( this ).data( 'start' ) );
      container.find( '.st-date-to'   ).val( $( this ).data( 'end'   ) );
    } );

    $( document ).on( 'click', '.st-apply-date', function () {
      const container = $( this ).closest( '.st-date-filter' );
      state.startDate = container.find( '.st-date-from' ).val();
      state.endDate   = container.find( '.st-date-to'   ).val();
      state.page      = 1;
      refreshAll();
    } );
  }

  function refreshAll() {
    if ( $( '#st-kpi-grid' ).length ) loadKPI();
    if ( $( '#st-logs-body' ).length ) loadLogs();
  }

  /* ── KPI ────────────────────────────────────────────────────────────────── */
  function loadKPI( forceRefresh = false, callback = null ) {
    const params = {
      start_date : state.startDate,
      end_date   : state.endDate,
    };
    if ( forceRefresh ) {
      params.force = 1;
    }

    $.ajax( {
      url     : STAdmin.kpiEndpoint,
      data    : params,
      headers : { 'X-WP-Nonce': STAdmin.nonce },
      success : function ( kpi ) {
        $( '#kpi-total' ).text( fmt( kpi.total    ) );
        $( '#kpi-valid' ).text( fmt( kpi.valid    ) );
        $( '#kpi-rate'  ).text( ( kpi.rate || 0 ) + '%' );
        $( '#kpi-avg'   ).text( ( kpi.avg  || 0 ) + 's' );

        // 1. Top Nhân Viên (Gộp theo emp_code, hiển thị "Tên (Mã NV)" có link)
        if ( kpi.top_staff && kpi.top_staff.length ) {
          let sHtml = '';
          kpi.top_staff.forEach( function ( s ) {
            const label = esc( s.user_name || '—' ) + ' (' + esc( s.emp_code || '—' ) + ')';
            // Tạo link lọc logs của nhân sự này
            const detailUrl = STAdmin.exportUrl.split('admin-post.php')[0] + 'admin.php?page=st-logs&staff=' + encodeURIComponent(s.user_name) + '&empcode=' + encodeURIComponent(s.emp_code) + '&exact=1';
            sHtml += '<tr><td><a href="' + detailUrl + '" style="font-weight:600; text-decoration:none;">' + label + '</a></td><td>' + fmt( s.cnt ) + '</td></tr>';
          } );
          $( '#st-top-staff tbody' ).html( sHtml );
        } else {
          $( '#st-top-staff tbody' ).html( '<tr><td colspan="2">Không có dữ liệu.</td></tr>' );
        }

        // 2. Top Lớp
        if ( kpi.top_classes && kpi.top_classes.length ) {
          let cHtml = '';
          kpi.top_classes.forEach( function ( c ) {
            cHtml += '<tr><td>' + esc( c.class_code || '—' ) + '</td><td>' + fmt( c.cnt ) + '</td></tr>';
          } );
          $( '#st-top-classes tbody' ).html( cHtml );
        } else {
          $( '#st-top-classes tbody' ).html( '<tr><td colspan="2">Không có dữ liệu.</td></tr>' );
        }

        // 3. Top Teams
        if ( kpi.top_teams && kpi.top_teams.length ) {
          let tHtml = '';
          kpi.top_teams.forEach( function ( t ) {
            tHtml += '<tr><td>' + esc( t.team || '—' ) + '</td><td>' + fmt( t.cnt ) + '</td></tr>';
          } );
          $( '#st-top-teams tbody' ).html( tHtml );
        } else {
          $( '#st-top-teams tbody' ).html( '<tr><td colspan="2">Không có dữ liệu.</td></tr>' );
        }

        // 4. Top Pages (New)
        if ( kpi.top_pages && kpi.top_pages.length ) {
          let pHtml = '';
          kpi.top_pages.forEach( function ( p ) {
            pHtml += '<tr><td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="' + esc( p.page_path ) + '">' + esc( p.page_path || '—' ) + '</td><td>' + fmt( p.cnt ) + '</td></tr>';
          } );
          $( '#st-top-pages tbody' ).html( pHtml );
        } else {
          $( '#st-top-pages tbody' ).html( '<tr><td colspan="2">Không có dữ liệu.</td></tr>' );
        }

        // Helper render progress bars
        function renderProgress(containerSelector, label, value, maxVal, colorClass) {
          const percent = maxVal > 0 ? Math.round((value / maxVal) * 100) : 0;
          return '<div class="st-stat-bar-wrapper">' +
            '<div class="st-stat-label-row">' +
              '<span>' + label + '</span>' +
              '<strong>' + value + ' (' + percent + '%)</strong>' +
            '</div>' +
            '<div class="st-stat-bar-outer">' +
              '<div class="st-stat-bar-inner ' + colorClass + '" style="width:' + percent + '%;"></div>' +
            '</div>' +
          '</div>';
        }

        // 5. Devices
        if ( kpi.devices ) {
          const dev = kpi.devices;
          const maxDev = (dev.Desktop || 0) + (dev.Mobile || 0) + (dev.Tablet || 0);
          let devHtml = '';
          devHtml += renderProgress('.st-device-bars', 'Máy tính (Desktop)', dev.Desktop || 0, maxDev, 'device-desktop');
          devHtml += renderProgress('.st-device-bars', 'Điện thoại (Mobile)', dev.Mobile || 0, maxDev, 'device-mobile');
          devHtml += renderProgress('.st-device-bars', 'Máy tính bảng (Tablet)', dev.Tablet || 0, maxDev, 'device-tablet');
          $('.st-device-bars').replaceWith('<div class="st-device-bars">' + devHtml + '</div>');
        }

        // 6. Browsers
        if ( kpi.browsers ) {
          const br = kpi.browsers;
          const maxBr = (br.Chrome || 0) + (br.Safari || 0) + (br.Firefox || 0) + (br.Edge || 0) + (br.Khác || 0);
          let brHtml = '';
          brHtml += renderProgress('.st-browser-bars', 'Google Chrome', br.Chrome || 0, maxBr, 'browser-chrome');
          brHtml += renderProgress('.st-browser-bars', 'Safari', br.Safari || 0, maxBr, 'browser-safari');
          brHtml += renderProgress('.st-browser-bars', 'Firefox', br.Firefox || 0, maxBr, 'browser-firefox');
          brHtml += renderProgress('.st-browser-bars', 'Microsoft Edge', br.Edge || 0, maxBr, 'browser-edge');
          brHtml += renderProgress('.st-browser-bars', 'Trình duyệt khác', br.Khác || 0, maxBr, 'browser-other');
          $('.st-browser-bars').replaceWith('<div class="st-browser-bars">' + brHtml + '</div>');
        }

        // 7. Hourly Periods
        if ( kpi.hourly_periods ) {
          const hr = kpi.hourly_periods;
          const maxHr = (hr['Sáng (06:00 - 12:00)'] || 0) + (hr['Chiều (12:00 - 18:00)'] || 0) + (hr['Tối (18:00 - 24:00)'] || 0) + (hr['Đêm (00:00 - 06:00)'] || 0);
          let hrHtml = '';
          hrHtml += renderProgress('.st-hourly-bars', 'Sáng (06:00 - 12:00)', hr['Sáng (06:00 - 12:00)'] || 0, maxHr, 'hour-morning');
          hrHtml += renderProgress('.st-hourly-bars', 'Chiều (12:00 - 18:00)', hr['Chiều (12:00 - 18:00)'] || 0, maxHr, 'hour-afternoon');
          hrHtml += renderProgress('.st-hourly-bars', 'Tối (18:00 - 24:00)', hr['Tối (18:00 - 24:00)'] || 0, maxHr, 'hour-evening');
          hrHtml += renderProgress('.st-hourly-bars', 'Đêm (00:00 - 06:00)', hr['Đêm (00:00 - 06:00)'] || 0, maxHr, 'hour-night');
          $('.st-hourly-bars').replaceWith('<div class="st-hourly-bars">' + hrHtml + '</div>');
        }
      },
      complete : function () {
        if ( typeof callback === 'function' ) {
          callback();
        }
      }
    } );
  }

  /* ── Logs ───────────────────────────────────────────────────────────────── */
  function bindLogFilters() {
    $( '#st-apply-filters' ).on( 'click', function () {
      state.staff     = $( '#st-filter-staff' ).val().trim();
      state.empCode   = $( '#st-filter-empcode' ).val().trim();
      state.classCode = $( '#st-filter-classcode' ).val().trim();
      state.team      = $( '#st-filter-team'  ).val().trim();
      state.exact     = $( '#st-filter-exact' ).is(':checked') ? 1 : 0;
      state.valid     = $( '#st-filter-valid' ).val();
      state.page      = 1;
      loadLogs();
    } );

    $( '#st-reset-filters' ).on( 'click', function () {
      $( '#st-filter-staff' ).val( '' );
      $( '#st-filter-empcode' ).val( '' );
      $( '#st-filter-classcode' ).val( '' );
      $( '#st-filter-team'  ).val( '' );
      $( '#st-filter-exact' ).prop( 'checked', false );
      $( '#st-filter-valid' ).val( '' );
      state.staff     = '';
      state.empCode   = '';
      state.classCode = '';
      state.team      = '';
      state.exact     = 0;
      state.valid     = '';
      state.page      = 1;
      loadLogs();
    } );

    // CSV button (server-rendered admin-post URL with filter params)
    $( '#st-export-csv' ).on( 'click', function ( e ) {
      e.preventDefault();
      doExport();
    } );
  }

  function bindSortHeaders() {
    $( '#st-logs-table thead th[data-col]' ).on( 'click', function () {
      const col = $( this ).data( 'col' );
      if ( state.orderby === col ) {
        state.order = state.order === 'ASC' ? 'DESC' : 'ASC';
      } else {
        state.orderby = col;
        state.order   = 'DESC';
      }
      state.page = 1;

      // Update icons
      $( '#st-logs-table thead .sort-icon' ).text( '↕' );
      $( this ).find( '.sort-icon' ).text( state.order === 'ASC' ? '↑' : '↓' );

      loadLogs();
    } );
  }

  function loadLogs() {
    const $body = $( '#st-logs-body' );
    $body.html( '<tr><td colspan="9" class="st-loading">Đang tải…</td></tr>' );

    const params = {
      start_date  : state.startDate,
      end_date    : state.endDate,
      staff       : state.staff,
      emp_code    : state.empCode,
      class_code  : state.classCode,
      team        : state.team,
      exact_match : state.exact,
      valid       : state.valid,
      page        : state.page,
      per_page    : state.perPage,
      orderby     : state.orderby,
      order       : state.order,
    };

    $.ajax( {
      url     : STAdmin.logsEndpoint,
      data    : params,
      headers : { 'X-WP-Nonce': STAdmin.nonce },
      success : function ( data ) {
        renderLogsTable( data.rows || [] );
        renderPagination( data.total, data.per_page, data.page );
      },
      error   : function () {
        $body.html( '<tr><td colspan="7">Lỗi khi tải dữ liệu.</td></tr>' );
      },
    } );
  }

  function renderLogsTable( rows ) {
    const $body = $( '#st-logs-body' );
    if ( ! rows.length ) {
      $body.html( '<tr><td colspan="10">Không có dữ liệu.</td></tr>' );
      return;
    }
    let html = '';
    rows.forEach( function ( r ) {
      const validBadge = r.is_valid == 1
        ? '<span class="st-badge st-badge-ok">✔ Hợp lệ</span>'
        : '<span class="st-badge st-badge-no">✘</span>';

      const shortUrl = r.url.length > 55 ? r.url.slice( 0, 52 ) + '…' : r.url;

      html += '<tr>' +
        '<td class="st-cell-time">' + esc( r.created_at ) + '</td>' +
        '<td>' + esc( r.user_name || '—' ) + '</td>' +
        '<td>' + esc( r.emp_code  || '—' ) + '</td>' +
        '<td>' + esc( r.class_code|| '—' ) + '</td>' +
        '<td>' + esc( r.team      || '—' ) + '</td>' +
        '<td class="st-cell-ip"><code>' + esc( r.ip ) + '</code></td>' +
        '<td title="' + esc( r.url ) + '"><a href="' + esc( r.url ) + '" target="_blank" rel="noopener">' + esc( shortUrl ) + '</a></td>' +
        '<td class="st-cell-num">' + esc( r.duration ) + 's</td>' +
        '<td>' + validBadge + '</td>' +
        '<td style="text-align:right;white-space:nowrap;">' +
        '  <button class="button button-small st-log-behavior-btn" data-id="' + r.id + '" data-name="' + esc( r.user_name || '—' ) + '" data-code="' + esc( r.emp_code || '—' ) + '" style="margin-right:6px;background:#e1f5fe;color:#0288d1;border-color:#b3e5fc;">👁 Hành vi</button>' +
        '  <button class="st-action-btn delete st-log-delete-btn" data-id="' + r.id + '">Xóa</button>' +
        '</td>' +
        '</tr>';
    } );
    $body.html( html );
  }

  function renderPagination( total, perPage, currentPage ) {
    const totalPages = Math.ceil( total / perPage );
    const $pag       = $( '#st-pagination' );

    if ( totalPages <= 1 ) { $pag.html( '' ); return; }

    let html = '<span class="st-pag-info">Trang ' + currentPage + ' / ' + totalPages + ' (' + fmt( total ) + ' bản ghi)</span> ';

    if ( currentPage > 1 ) {
      html += '<button class="button st-pag-btn" data-page="1">«</button> ';
      html += '<button class="button st-pag-btn" data-page="' + ( currentPage - 1 ) + '">‹</button> ';
    }

    // Window of 5 pages
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
      state.page = parseInt( $( this ).data( 'page' ), 10 );
      loadLogs();
      $( 'html, body' ).animate( { scrollTop: $( '#st-logs-table' ).offset().top - 60 }, 200 );
    } );
  }

  // Handle log deletion
  $( '#st-logs-body' ).on( 'click', '.st-log-delete-btn', function() {
    const id = $(this).data('id');
    if ( confirm( 'Bạn có chắc chắn muốn xóa log truy cập này? Hành động này không thể hoàn tác.' ) ) {
      $.ajax( {
        url: STAdmin.logsEndpoint.replace('/logs', '/logs/' + id),
        type: 'DELETE',
        headers: { 'X-WP-Nonce': STAdmin.nonce },
        success: function() {
          loadLogs();
        },
        error: function(xhr) {
          alert( 'Lỗi: ' + (xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'Không thể xóa') );
        }
      });
    }
  });

  // Handle behavior logs modal popup
  $( '#st-logs-body' ).on( 'click', '.st-log-behavior-btn', function() {
    const id = $(this).data('id');
    const name = $(this).data('name');
    const code = $(this).data('code');
    openBehaviorModal(id, name, code);
  });

  function openBehaviorModal(id, name, code) {
    const $modal = $('#st-behavior-modal');
    const $title = $('#st-behavior-modal-title');
    const $body = $('#st-behavior-modal-body');

    $title.text('Lịch sử hành vi chi tiết: ' + name + ' (' + code + ')');
    $body.html('<div class="st-loading" style="padding:40px;text-align:center;">Đang tải hành vi...</div>');
    $modal.fadeIn(160);
    $('body').addClass('st-modal-open');

    $.ajax({
      url: STAdmin.logsEndpoint + '/' + id + '/behavior',
      method: 'GET',
      headers: { 'X-WP-Nonce': STAdmin.nonce },
      success: function(data) {
        if (!data || !data.length) {
          $body.html('<div style="padding:40px;text-align:center;color:#718096;font-size:13px;">Không ghi nhận hành vi chi tiết nào trong phiên này (hoặc phiên cũ trước khi cập nhật).</div>');
          return;
        }

        let html = '<div class="st-timeline" style="position:relative;padding:10px 0 10px 20px;border-left:2px solid #e2e8f0;margin-left:10px;font-size:13px;max-height:450px;overflow-y:auto;">';
        data.forEach(function(ev) {
          let badgeColor = '#4a5568';
          let badgeBg = '#edf2f7';
          let icon = '📝';
          let detailHtml = '';

          const type = ev.event_type;
          const ed = typeof ev.event_data === 'string' ? {} : (ev.event_data || {});

          if (type === 'click') {
            badgeColor = '#2b6cb0';
            badgeBg = '#ebf8ff';
            icon = '🖱 Click';
            detailHtml = 'Click thẻ <code>' + esc(ed.tag || '') + '</code>' + 
                         (ed.selector ? ' (Selector: <code>' + esc(ed.selector) + '</code>)' : '') +
                         (ed.text ? ' với nội dung: <strong>"' + esc(ed.text) + '"</strong>' : '') +
                         (ed.href ? ' link dẫn tới: <a href="' + esc(ed.href) + '" target="_blank">' + esc(ed.href) + '</a>' : '') +
                         (ed.title ? ' (Tiêu đề: <em>"' + esc(ed.title) + '"</em>)' : '') +
                         (ed.alt ? ' (Mô tả ảnh: <em>"' + esc(ed.alt) + '"</em>)' : '') +
                         (ed.src ? ' (Nguồn ảnh: <a href="' + esc(ed.src) + '" target="_blank">Xem ảnh</a>)' : '');
          } else if (type === 'scroll') {
            badgeColor = '#dd6b20';
            badgeBg = '#fffaf0';
            icon = '📜 Cuộn';
            detailHtml = 'Cuộn trang đạt độ sâu: <strong>' + esc(ed.depth || 0) + '%</strong>';
          } else if (type === 'visibility') {
            badgeColor = '#4c51bf';
            badgeBg = '#ebf4ff';
            icon = '👁 Focus';
            detailHtml = ed.state === 'hidden' 
              ? '<span style="color:#e53e3e;font-weight:600;">⚠️ Rời khỏi tab / Tab bị ẩn (Mất tập trung)</span>'
              : '<span style="color:#38a169;font-weight:600;">✅ Quay lại tab / Tab hoạt động</span>';
          } else if (type === 'copy') {
            badgeColor = '#319795';
            badgeBg = '#e6fffa';
            icon = '📋 Copy';
            detailHtml = 'Copy nội dung: <em>"' + esc(ed.text || '') + '"</em>' +
                         (ed.selector ? ' từ thẻ <code>' + esc(ed.selector) + '</code>' : '');
          } else if (type === 'paste') {
            badgeColor = '#d69e2e';
            badgeBg = '#fefcbf';
            icon = '📋 Paste';
            detailHtml = 'Dán nội dung' +
                         (ed.text_length ? ' (Độ dài: <strong>' + ed.text_length + ' ký tự</strong>)' : '') +
                         (ed.selector ? ' vào thẻ <code>' + esc(ed.selector) + '</code>' : '');
          } else {
            icon = esc(type);
            detailHtml = typeof ev.event_data === 'string' ? esc(ev.event_data) : JSON.stringify(ev.event_data);
          }

          const offsetStr = ed.offset !== undefined ? ' (Giây thứ ' + ed.offset + ')' : '';

          html += '<div class="st-timeline-item" style="position:relative;margin-bottom:18px;">' +
                  '  <div style="position:absolute;left:-27px;top:2px;width:12px;height:12px;border-radius:50%;background:' + badgeColor + ';border:2px solid #fff;"></div>' +
                  '  <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">' +
                  '    <span class="st-badge" style="background:' + badgeBg + ';color:' + badgeColor + ';font-weight:bold;font-size:11px;padding:2px 6px;border-radius:4px;">' + icon + '</span>' +
                  '    <span style="font-size:11px;color:#a0aec0;">' + esc(ev.created_at) + offsetStr + '</span>' +
                  '  </div>' +
                  '  <div style="color:#2d3748;line-height:1.4;">' + detailHtml + '</div>' +
                  '</div>';
        });
        html += '</div>';
        $body.html(html);
      },
      error: function() {
        $body.html('<div style="padding:40px;text-align:center;color:#e53e3e;font-size:13px;">Lỗi khi tải lịch sử hành vi chi tiết. Vui lòng thử lại sau.</div>');
      }
    });
  }
  window.openBehaviorModal = openBehaviorModal;

  function closeBehaviorModal() {
    $('#st-behavior-modal').fadeOut(160);
    $('body').removeClass('st-modal-open');
  }

  $(document).on('click', '#st-behavior-modal-close', closeBehaviorModal);
  $(window).on('click', function(e) {
    if ($(e.target).hasClass('st-modal-overlay') && $(e.target).attr('id') === 'st-behavior-modal') {
      closeBehaviorModal();
    }
  });

  /* ── Export ─────────────────────────────────────────────────────────────── */
  function bindExport() {
    $( '#st-do-export' ).on( 'click', function ( e ) {
      e.preventDefault();
      // Collect export-page specific filters
      const startDate = $( '.st-date-filter .st-date-from' ).last().val();
      const endDate   = $( '.st-date-filter .st-date-to'   ).last().val();
      const staff     = $( '#st-exp-staff' ).val().trim();
      const empCode   = $( '#st-exp-empcode' ).val().trim();
      const classCode = $( '#st-exp-classcode' ).val().trim();
      const team      = $( '#st-exp-team'  ).val().trim();
      const exact     = $( '#st-exp-exact' ).is(':checked') ? 1 : 0;
      const valid     = $( '#st-exp-valid' ).val();

      const url = buildExportUrl( { startDate, endDate, staff, empCode, classCode, team, exact, valid } );
      window.location.href = url;
    } );
  }

  function doExport() {
    const url = buildExportUrl( {
      startDate : state.startDate,
      endDate   : state.endDate,
      staff     : state.staff,
      empCode   : state.empCode,
      classCode : state.classCode,
      team      : state.team,
      exact     : state.exact,
      valid     : state.valid,
    } );
    window.location.href = url;
  }

  function buildExportUrl( p ) {
    const base   = STAdmin.exportUrl;
    const params  = new URLSearchParams( {
      st_nonce   : STAdmin.adminNonce,
      start_date : p.startDate || '',
      end_date   : p.endDate   || '',
      staff      : p.staff     || '',
      emp_code   : p.empCode   || '',
      class_code : p.classCode || '',
      team       : p.team      || '',
      exact_match: p.exact     || '',
      valid      : p.valid     || '',
    } );
    return base + '&' + params.toString();
  }

  /* ── Utilities ───────────────────────────────────────────────────────────── */
  function esc( s ) {
    const d = document.createElement( 'div' );
    d.appendChild( document.createTextNode( String( s ) ) );
    return d.innerHTML;
  }

  function fmt( n ) {
    return Number( n ).toLocaleString( 'vi-VN' );
  }

  function todayStr() {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 10);
  }

} )( jQuery );
