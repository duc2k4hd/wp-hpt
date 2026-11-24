/**
 * HPT Post Import/Export Admin JS
 * Điều khiển luồng Ajax batch cho Import & Export bài viết
 */
(function ($) {
    'use strict';

    var cfg = window.HPTPostIE || {};
    var activeJobs = {};

    function post(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = cfg.nonce;
        return $.ajax({
            url: cfg.ajax_url,
            method: 'POST',
            data: data,
            dataType: 'json'
        });
    }

    /* ============================================================
     * 1) IMPORT LUỒNG AJAX BATCH
     * ============================================================ */

    $('#hpt-post-import-form').on('submit', function (e) {
        e.preventDefault();

        var fileInput = document.getElementById('hpt-post-import-file');
        if (!fileInput.files.length) {
            alert('Vui lòng chọn file dữ liệu (.xlsx hoặc .csv).');
            return;
        }

        var btn = $('#hpt-post-import-btn');
        var progressBox = $('#hpt-post-import-progress');
        var errorsBox = progressBox.find('.hpt-pie-errors');

        btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Đang tải file lên...');
        progressBox.show();
        progressBox.find('.hpt-pie-fill').css('width', '0%');
        progressBox.find('.hpt-pie-title').text('Đang tải file lên máy chủ và phân tích tiêu đề...');
        progressBox.find('.hpt-pie-meta').text('Khởi tạo...');
        errorsBox.hide().empty();

        var formData = new FormData(this);
        formData.append('action', 'hpt_post_ie_import_init');
        formData.append('nonce', cfg.nonce);

        $.ajax({
            url: cfg.ajax_url,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (res) {
            if (!res.success) {
                alert((res.data && res.data.message) || 'Lỗi khởi tạo file import.');
                btn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> Bắt đầu nhập bài viết');
                progressBox.hide();
                return;
            }

            var jobId = res.data.job_id;
            var total = res.data.total_rows;

            progressBox.find('.hpt-pie-title').text('Đang nhập dữ liệu theo từng batch...');
            progressBox.find('.hpt-pie-meta').text('0 / ' + total + ' dòng');
            progressBox.find('.hpt-post-pause').show();
            progressBox.find('.hpt-post-resume').hide();

            runImportBatches(jobId, total, btn, progressBox);
        }).fail(function () {
            alert('Không thể kết nối máy chủ để tải file lên.');
            btn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> Bắt đầu nhập bài viết');
            progressBox.hide();
        });
    });

    function runImportBatches(jobId, total, btn, progressBox) {
        var stopped = false;
        var consecutiveErrors = 0;
        activeJobs[jobId] = {
            stop: function () { stopped = true; }
        };

        function step() {
            if (stopped) return;

            post('hpt_post_ie_import_batch', { job_id: jobId }).done(function (res) {
                if (!res.success) {
                    alert((res.data && res.data.message) || 'Lỗi khi xử lý batch import.');
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> Bắt đầu nhập bài viết');
                    return;
                }

                consecutiveErrors = 0;
                var data = res.data;
                var percent = Math.min(100, Math.max(0, parseInt(data.percent || 0, 10)));

                progressBox.find('.hpt-pie-fill').css('width', percent + '%');

                if (data.status === 'paused') {
                    progressBox.find('.hpt-pie-title').text('Đã tạm dừng (' + percent + '%)');
                    progressBox.find('.hpt-post-pause').hide();
                    progressBox.find('.hpt-post-resume').show();
                    return;
                }

                var metaText = 'Đã xử lý: ' + data.processed + ' / ' + data.total +
                    ' | Tạo mới: ' + (data.created || 0) +
                    ' | Cập nhật: ' + (data.updated || 0) +
                    ' | Bỏ qua: ' + (data.skipped || 0) +
                    ' | Lỗi: ' + (data.failed || 0);
                progressBox.find('.hpt-pie-meta').text(metaText);

                // Hiển thị lỗi nếu có
                if (data.errors && data.errors.length) {
                    var errorsBox = progressBox.find('.hpt-pie-errors');
                    var html = '<h4>Bảng log cảnh báo / lỗi (' + data.errors.length + ' mục gần nhất):</h4><ul>';
                    data.errors.forEach(function (err) {
                        html += '<li><strong>Dòng ' + err.row_num + ' (' + err.post_identifier + '):</strong> ' + err.error_message + '</li>';
                    });
                    html += '</ul>';
                    errorsBox.html(html).show();
                }

                if (data.done) {
                    delete activeJobs[jobId];
                    progressBox.find('.hpt-pie-title').html('<span class="dashicons dashicons-yes-alt" style="color:#10b981;"></span> Nhập bài viết hoàn tất 100%!');
                    progressBox.find('.hpt-post-pause').hide();
                    progressBox.find('.hpt-post-resume').hide();
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> Bắt đầu nhập bài viết');

                    var doneMsg = 'Nhập hoàn tất! Đã tạo: ' + (data.created || 0) +
                        ', Cập nhật: ' + (data.updated || 0) +
                        ', Bỏ qua: ' + (data.skipped || 0) +
                        ', Lỗi: ' + (data.failed || 0);
                    alert(doneMsg);
                    return;
                }

                if (!stopped) {
                    setTimeout(step, 200);
                }
            }).fail(function () {
                consecutiveErrors++;
                if (consecutiveErrors > 5) {
                    alert('Lỗi kết nối liên tục khi chạy batch. Đã dừng tiến trình.');
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> Bắt đầu nhập bài viết');
                    return;
                }
                setTimeout(step, 2000);
            });
        }

        step();
    }

    /* ============================================================
     * 2) EXPORT LUỒNG AJAX BATCH
     * ============================================================ */

    $('#hpt-post-export-form').on('submit', function (e) {
        e.preventDefault();

        var btn = $('#hpt-post-export-btn');
        var progressBox = $('#hpt-post-export-progress');
        var downloadLink = progressBox.find('.hpt-post-download');

        btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Đang khởi tạo file xuất...');
        progressBox.show();
        downloadLink.hide().attr('href', '#');
        progressBox.find('.hpt-pie-fill').css('width', '0%');
        progressBox.find('.hpt-pie-title').text('Đang đếm số lượng bài viết...');
        progressBox.find('.hpt-pie-meta').text('Khởi tạo...');

        var formData = $(this).serializeArray();
        var dataObj = {};
        var fields = [];

        formData.forEach(function (item) {
            if (item.name === 'fields[]') {
                fields.push(item.value);
            } else {
                dataObj[item.name] = item.value;
            }
        });
        dataObj.fields = fields;

        post('hpt_post_ie_export_init', dataObj).done(function (res) {
            if (!res.success) {
                alert((res.data && res.data.message) || 'Lỗi khởi tạo xuất bài viết.');
                btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Bắt đầu xuất bài viết');
                progressBox.hide();
                return;
            }

            var jobId = res.data.job_id;
            var total = res.data.total_rows;

            progressBox.find('.hpt-pie-title').text('Đang xuất bài viết ra file...');
            progressBox.find('.hpt-pie-meta').text('0 / ' + total + ' bài viết');
            progressBox.find('.hpt-post-pause').show();
            progressBox.find('.hpt-post-resume').hide();

            runExportBatches(jobId, total, btn, progressBox);
        }).fail(function () {
            alert('Không thể kết nối máy chủ để xuất file.');
            btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Bắt đầu xuất bài viết');
            progressBox.hide();
        });
    });

    function runExportBatches(jobId, total, btn, progressBox) {
        var stopped = false;
        var consecutiveErrors = 0;
        activeJobs[jobId] = {
            stop: function () { stopped = true; }
        };

        function step() {
            if (stopped) return;

            post('hpt_post_ie_export_batch', { job_id: jobId }).done(function (res) {
                if (!res.success) {
                    alert((res.data && res.data.message) || 'Lỗi khi xử lý batch export.');
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Bắt đầu xuất bài viết');
                    return;
                }

                consecutiveErrors = 0;
                var data = res.data;
                var percent = Math.min(100, Math.max(0, parseInt(data.percent || 0, 10)));

                progressBox.find('.hpt-pie-fill').css('width', percent + '%');
                progressBox.find('.hpt-pie-meta').text('Đã xuất: ' + data.processed + ' / ' + data.total + ' bài viết (' + percent + '%)');

                if (data.done) {
                    delete activeJobs[jobId];
                    progressBox.find('.hpt-pie-title').html('<span class="dashicons dashicons-yes-alt" style="color:#10b981;"></span> Xuất bài viết hoàn tất 100%!');
                    progressBox.find('.hpt-post-pause').hide();
                    progressBox.find('.hpt-post-resume').hide();
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Bắt đầu xuất bài viết');

                    if (data.download_url) {
                        progressBox.find('.hpt-post-download').attr('href', data.download_url).show();
                    }
                    return;
                }

                if (!stopped) {
                    setTimeout(step, 200);
                }
            }).fail(function () {
                consecutiveErrors++;
                if (consecutiveErrors > 5) {
                    alert('Lỗi kết nối liên tục khi xuất bài viết.');
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Bắt đầu xuất bài viết');
                    return;
                }
                setTimeout(step, 2000);
            });
        }

        step();
    }

    /* ============================================================
     * 3) NÚT ĐIỀU KHIỂN PAUSE / RESUME / CANCEL
     * ============================================================ */

    $(document).on('click', '.hpt-post-pause', function () {
        var btn = $(this);
        var type = btn.data('type');
        var progressBox = $('#hpt-post-' + type + '-progress');

        Object.keys(activeJobs).forEach(function (id) {
            activeJobs[id].stop();
            post('hpt_post_ie_control', { job_id: id, cmd: 'pause' });
        });

        btn.hide();
        progressBox.find('.hpt-post-resume').show();
        progressBox.find('.hpt-pie-title').text('Đã tạm dừng.');
    });

    $(document).on('click', '.hpt-post-resume', function () {
        var btn = $(this);
        var type = btn.data('type');
        var progressBox = $('#hpt-post-' + type + '-progress');

        btn.hide();
        progressBox.find('.hpt-post-pause').show();
        progressBox.find('.hpt-pie-title').text('Đang tiếp tục...');

        // Trigger batch tiếp theo
        if (type === 'import') {
            $('#hpt-post-import-form').submit();
        } else {
            $('#hpt-post-export-form').submit();
        }
    });

    $(document).on('click', '.hpt-post-cancel', function () {
        if (!confirm('Bạn có chắc muốn hủy tiến trình này?')) return;

        var type = $(this).data('type');
        var progressBox = $('#hpt-post-' + type + '-progress');

        Object.keys(activeJobs).forEach(function (id) {
            activeJobs[id].stop();
            post('hpt_post_ie_control', { job_id: id, cmd: 'cancel' });
            delete activeJobs[id];
        });

        progressBox.hide();
        if (type === 'import') {
            $('#hpt-post-import-btn').prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> Bắt đầu nhập bài viết');
        } else {
            $('#hpt-post-export-btn').prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Bắt đầu xuất bài viết');
        }
    });

})(jQuery);
