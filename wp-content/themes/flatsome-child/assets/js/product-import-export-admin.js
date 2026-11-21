(function ($) {
    'use strict';

    var settings = window.HPTProductIE || {};
    var activeRunners = {};

    function domType(type) {
        return String(type).replace(/_/g, '-');
    }

    function post(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = settings.nonce;
        return $.ajax({ url: settings.ajax_url, method: 'POST', data: data, dataType: 'json' });
    }

    function showProgress(type, progress) {
        var box = $('#hpt-pie-' + domType(type) + '-progress');
        if (!box.length) return;
        box.show();
        var percent = Math.max(0, Math.min(100, parseInt(progress.percent || 0, 10)));
        box.find('.hpt-pie-fill').css('width', percent + '%');
        var total = progress.total || 0;
        var statusText = progress.done
            ? (progress.status === 'completed' ? (type === 'import' ? 'Đã nhập hoàn tất' : 'Đã xuất hoàn tất') : 'Đã dừng')
            : (progress.status === 'finalizing' ? 'Đang hoàn tất việc dọn dẹp' : 'Đang xử lý');
        box.find('.hpt-pie-title').text(statusText + ' - ' + percent + '%');
        var detail = type === 'sku_export'
            ? 'Đã xử lý: ' + (progress.processed || 0) + (total ? ' / ' + total : '') + ' | Xuất được: ' + (progress.created || 0) + ' | Mã không tồn tại: ' + (progress.failed || 0)
            : 'Đã xử lý: ' + (progress.processed || 0) + ' | Tạo mới: ' + (progress.created || 0) + ' | Cập nhật: ' + (progress.updated || 0) + ' | Không đổi: ' + (progress.unchanged || 0) + ' | Lỗi/bỏ qua: ' + (progress.failed || 0);
        var timing = progress.batch_time_ms ? ' | Batch: ' + progress.batch_time_ms + ' ms' : '';
        box.find('.hpt-pie-meta').text(detail + timing + ' | ' + percent + '%');
        box.toggleClass('hpt-pie-finished', !!progress.done);
        if (progress.status === 'paused') {
            box.find('.hpt-pie-pause').hide();
            box.find('.hpt-pie-resume').show();
        } else {
            box.find('.hpt-pie-pause').toggle(!progress.done);
            box.find('.hpt-pie-resume').hide();
        }
        if (progress.download_url) box.find('.hpt-pie-download').attr('href', progress.download_url).show();
    }

    function runBatches(type, jobId, batchAction) {
        jobId = parseInt(jobId, 10);
        var existingRunner = activeRunners[jobId];
        if (existingRunner) existingRunner();

        var stopped = false;
        var consecutiveFailures = 0;
        activeRunners[jobId] = function () { stopped = true; };

        function next(delay) {
            if (stopped) return;
            window.setTimeout(function () {
                if (stopped) return;
                post(batchAction, { job_id: jobId }).done(function (response) {
                if (!response.success) {
                    if (response.status === 403 || response.status === 404) {
                        stopped = true;
                        delete activeRunners[jobId];
                        alert((response.data && response.data.message) || 'Không thể tiếp tục job này.');
                        return;
                    }
                    stopped = true;
                    delete activeRunners[jobId];
                    alert((response.data && response.data.message) || 'Batch xử lý thất bại. Hãy kiểm tra trạng thái job trước khi thử lại.');
                    return;
                }
                consecutiveFailures = 0;
                showProgress(type, response.data);
                if (response.data.done || response.data.stop_polling || response.data.status === 'paused') {
                    delete activeRunners[jobId];
                    return;
                }
                if (!stopped) {
                    next(250);
                }
                }).fail(function (xhr) {
                    if (xhr.status === 403 || xhr.status === 404) {
                        stopped = true;
                        delete activeRunners[jobId];
                        alert('Không thể tiếp tục job này.');
                        return;
                    }
                    consecutiveFailures++;
                    if (consecutiveFailures > 8) {
                        stopped = true;
                        delete activeRunners[jobId];
                        alert('Không thể khôi phục kết nối sau 8 lần thử. Tiến độ đã lưu trên server; mở lại trang để tiếp tục.');
                        return;
                    }
                    next(Math.min(30000, 1000 * Math.pow(2, Math.min(consecutiveFailures, 5))));
                });
            }, delay || 0);
        }

        next(0);
        return activeRunners[jobId];
    }

    function bindControls(type, batchAction) {
        var box = $('#hpt-pie-' + domType(type) + '-progress');
        box.on('click', '.hpt-pie-pause,.hpt-pie-resume,.hpt-pie-cancel', function () {
            var button = $(this);
            var control = button.hasClass('hpt-pie-pause') ? 'pause' : (button.hasClass('hpt-pie-resume') ? 'resume' : 'cancel');
            var jobId = box.data('job-id');
            if (!jobId) return;
            if (control !== 'resume' && activeRunners[parseInt(jobId, 10)]) {
                activeRunners[parseInt(jobId, 10)]();
            }
            post('hpt_pie_control', { job_id: jobId, control: control }).done(function (response) {
                if (response.success) {
                    showProgress(type, response.data);
                    if (control === 'resume') runBatches(type, jobId, batchAction);
                }
            });
        });
    }

    function initExportForm(formSelector, type, initAction, batchAction, fileName) {
        var form = $(formSelector);
        if (!form.length) return;
        var progress = $('#hpt-pie-' + domType(type) + '-progress');
        bindControls(type, batchAction);
        form.on('submit', function (event) {
            event.preventDefault();
            var file = form.find('input[type=file]')[0];
            var data = new FormData(form[0]);
            data.append('action', initAction);
            data.append('nonce', settings.nonce);
            form.find(':submit').prop('disabled', true);
            $.ajax({ url: settings.ajax_url, method: 'POST', data: data, processData: false, contentType: false, dataType: 'json' }).done(function (response) {
                if (!response.success) {
                    alert((response.data && response.data.message) || 'Không thể tạo job.');
                    return;
                }
                progress.data('job-id', response.data.job_id);
                showProgress(type, { percent: 0, processed: 0, total: response.data.total || 0, created: 0, failed: 0 });
                runBatches(type, response.data.job_id, batchAction);
            }).fail(function () {
                alert('Upload file thất bại.');
            }).always(function () {
                form.find(':submit').prop('disabled', false);
            });
        });
    }

    function initImportForm() {
        var form = $('#hpt-pie-import-form');
        if (!form.length) return;
        bindControls('import', 'hpt_pie_import_batch');
        form.on('submit', function (event) {
            event.preventDefault();
            var data = new FormData(form[0]);
            data.append('action', 'hpt_pie_import_init');
            data.append('nonce', settings.nonce);
            form.find(':submit').prop('disabled', true);
            showProgress('import', { percent: 0, processed: 0, total: 0, created: 0, failed: 0 });
            $('#hpt-pie-import-progress').find('.hpt-pie-title').text('Đang tải và đọc file...');
            $.ajax({ url: settings.ajax_url, method: 'POST', data: data, processData: false, contentType: false, dataType: 'json' }).done(function (response) {
                if (!response.success) {
                    alert((response.data && response.data.message) || 'Không thể tạo job import.');
                    return;
                }
                var progress = $('#hpt-pie-import-progress');
                progress.data('job-id', response.data.job_id);
                showProgress('import', { percent: 0, processed: 0, created: 0, failed: 0 });
                runBatches('import', response.data.job_id, 'hpt_pie_import_batch');
            }).fail(function () {
                alert('Upload file import thất bại.');
            }).always(function () {
                form.find(':submit').prop('disabled', false);
            });
        });
    }

    $(function () {
        initImportForm();
        initExportForm('#hpt-pie-export-form', 'export', 'hpt_pie_export_init', 'hpt_pie_export_batch');
        initExportForm('#hpt-pie-sku-export-form', 'sku_export', 'hpt_pie_sku_export_init', 'hpt_pie_sku_export_batch');

        var batchActions = {
            import: 'hpt_pie_import_batch',
            export: 'hpt_pie_export_batch',
            sku_export: 'hpt_pie_sku_export_batch'
        };
        $.each(settings.active_jobs || [], function (_, job) {
            if (!job || !job.job_id || !batchActions[job.type]) return;
            var box = $('#hpt-pie-' + domType(job.type) + '-progress');
            box.data('job-id', job.job_id);
            showProgress(job.type, job);
            if (job.status === 'running' || job.status === 'finalizing') runBatches(job.type, job.job_id, batchActions[job.type]);
        });

        $('#hpt-pie-add-index').on('click', function () {
            var button = $(this), message = $('#hpt-pie-add-index-msg');
            button.prop('disabled', true);
            post('hpt_pie_add_index').done(function (response) {
                message.text((response.data && response.data.message) || 'Đã xử lý.');
            }).fail(function () {
                message.text('Không thể xử lý.');
            }).always(function () { button.prop('disabled', false); });
        });
    });
}(jQuery));
