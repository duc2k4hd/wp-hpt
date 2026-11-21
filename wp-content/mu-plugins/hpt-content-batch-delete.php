<?php
/**
 * Plugin Name: HPT Content Batch Delete
 * Description: Xoa bai viet/san pham theo ID va lam sach du lieu lien quan theo batch client-side.
 * Version: 1.1.0
 * Author: Hai Phong Tech
 */

if (!defined('ABSPATH')) {
    exit;
}

final class HPT_Content_Batch_Delete
{
    private const VERSION = '1.1.0';
    private const PAGE_SLUG = 'hpt-content-batch-delete';
    private const NONCE_ACTION = 'hpt_content_nonce';
    private const CONFIRM_TEXT = 'TOI_BACKUP_VA_DONG_Y_XOA_DU_LIEU';

    private const MAX_TIME_LIMIT = 120;
    private const MEMORY_SAFETY_THRESHOLD = 0.8;
    private const SAFE_BATCH_SIZE = 50;
    private const MAX_BATCH_SIZE = 500;
    private const PREVIEW_LIMIT = 20;

    private const CONTENT_TYPE_POST = 'post';
    private const CONTENT_TYPE_PRODUCT = 'product';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_page']);

        add_action('wp_ajax_hpt_content_debug_ping', [$this, 'ajax_debug_ping']);
        add_action('wp_ajax_hpt_content_refresh_nonce', [$this, 'ajax_refresh_nonce']);
        add_action('wp_ajax_hpt_content_analyze_db', [$this, 'ajax_analyze_db']);
        add_action('wp_ajax_hpt_content_preview', [$this, 'ajax_preview_content']);
        add_action('wp_ajax_hpt_content_verify', [$this, 'ajax_verify_before_delete']);
        add_action('wp_ajax_hpt_content_delete_batch', [$this, 'ajax_delete_batch']);
    }

    public function register_admin_page(): void
    {
        add_management_page(
            'Content Batch Delete',
            'Content Batch Delete',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        nocache_headers();

        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'confirmText' => self::CONFIRM_TEXT,
            'defaultBatchSize' => self::SAFE_BATCH_SIZE,
            'maxBatchSize' => self::MAX_BATCH_SIZE,
            'previewLimit' => self::PREVIEW_LIMIT,
        ];
        ?>
        <div class="wrap hpt-content-batch-delete">
            <h1>HPT Content Batch Delete v<?php echo esc_html(self::VERSION); ?></h1>

            <div class="notice notice-warning">
                <p><strong>Canh bao:</strong> Tool nay xoa vinh vien bai viet/san pham va du lieu lien quan. Luon backup database + uploads truoc khi chay.</p>
                <p>Ban 1.1.0 da sua luong nonce/AJAX de tranh loi <code>HTTP 403</code> mo ho, va xu ly <strong>analyze / verify / delete</strong> theo chunk client-side de khong day ca danh sach ID vao mot request.</p>
            </div>

            <div class="hpt-container" style="max-width: 1280px;">
                <div class="hpt-card">
                    <h2>Buoc 1: Nhap du lieu</h2>
                    <table class="form-table">
                        <tr>
                            <th>Loai noi dung</th>
                            <td>
                                <label>
                                    <input type="radio" name="content_type" value="post" checked>
                                    Bai viet
                                </label>
                                <label style="margin-left: 20px;">
                                    <input type="radio" name="content_type" value="product">
                                    San pham
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Danh sach ID</th>
                            <td>
                                <textarea id="content_ids" placeholder="Nhap ID cach nhau boi dau phay, khoang trang hoac xuong dong&#10;Vi du:&#10;123&#10;456&#10;789&#10;hoac 123,456,789"
                                    style="width: 100%; height: 120px; font-family: Consolas, monospace;"></textarea>
                                <p class="description">JS se tu dong loc so, loai trung, sau do chia batch client-side de tranh timeout.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Batch size</th>
                            <td>
                                <input type="number" id="batch_size" min="10" max="<?php echo esc_attr((string) self::MAX_BATCH_SIZE); ?>"
                                    value="<?php echo esc_attr((string) self::SAFE_BATCH_SIZE); ?>" style="width: 110px;">
                                <p class="description">Khuyen nghi 25-100 cho hosting thuong, 100-300 cho server manh, toi da <?php echo esc_html((string) self::MAX_BATCH_SIZE); ?>.</p>
                            </td>
                        </tr>
                    </table>

                    <button class="button button-primary" id="btn_analyze">Phan tich du lieu</button>
                    <button class="button" id="btn_ping" style="margin-left: 10px;">Test ket noi</button>
                </div>

                <div class="hpt-card">
                    <h2>Phan tich</h2>
                    <div id="analysis_result" style="display: none;">
                        <table class="widefat striped">
                            <tbody>
                                <tr><td><strong>Loai</strong></td><td id="info_type">-</td></tr>
                                <tr><td><strong>ID da nhap</strong></td><td id="info_ids">-</td></tr>
                                <tr><td><strong>ID hop le theo loai</strong></td><td id="info_exists">-</td></tr>
                                <tr><td><strong>ID thieu</strong></td><td id="info_missing">-</td></tr>
                                <tr><td><strong>ID sai loai</strong></td><td id="info_mismatch">-</td></tr>
                                <tr><td><strong>Ban ghi con</strong></td><td id="info_descendants">-</td></tr>
                                <tr><td><strong>Attachments</strong></td><td id="info_attachments">-</td></tr>
                                <tr><td><strong>Variations</strong></td><td id="info_variations">-</td></tr>
                                <tr><td><strong>Revisions</strong></td><td id="info_revisions">-</td></tr>
                                <tr><td><strong>Postmeta</strong></td><td id="info_meta">-</td></tr>
                                <tr><td><strong>Comments</strong></td><td id="info_comments">-</td></tr>
                                <tr><td><strong>Comment meta</strong></td><td id="info_commentmeta">-</td></tr>
                                <tr><td><strong>Term relationships</strong></td><td id="info_term_relationships">-</td></tr>
                                <tr><td><strong>Tong rows uoc tinh se dong vao xoa</strong></td><td id="info_total" style="color: #b32d2e; font-weight: 700;">-</td></tr>
                                <tr><td><strong>Thoi gian uoc tinh</strong></td><td id="info_time">-</td></tr>
                                <tr><td><strong>Batch khuyen nghi</strong></td><td id="info_batch">-</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="analysis_error" style="display: none;" class="notice notice-error inline"></div>
                </div>

                <div class="hpt-card">
                    <h2>Buoc 2: Preview</h2>
                    <button class="button" id="btn_preview" disabled>Xem truoc nhung gi se bi xoa</button>
                    <div id="preview_result" style="display: none; margin-top: 20px;">
                        <p id="preview_summary" style="margin-top: 0;"></p>
                        <table class="widefat striped" id="preview_table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Related Data</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <div class="hpt-card">
                    <h2>Buoc 3: Verify</h2>
                    <button class="button button-primary" id="btn_verify" disabled>Kiem tra ky truoc khi xoa</button>
                    <div id="verify_result" style="display: none; margin-top: 20px;">
                        <p id="verify_summary" style="margin-top: 0;"></p>
                        <div id="verify_issues" style="background: #fff8e5; border-left: 4px solid #dba617; padding: 14px;"></div>
                    </div>
                </div>

                <div class="hpt-card">
                    <h2>Buoc 4: Xoa</h2>
                    <div class="notice notice-warning inline">
                        <p><strong>Double confirmation:</strong> server chi chap nhan request xoa khi chuoi xac nhan khop hoan toan.</p>
                        <input type="text" id="confirm_text" placeholder="Nhap: <?php echo esc_attr(self::CONFIRM_TEXT); ?>"
                            style="width: 100%; padding: 10px; margin: 10px 0; font-family: Consolas, monospace;">
                    </div>
                    <button class="button button-primary" id="btn_delete" disabled>Bat dau xoa du lieu</button>
                </div>

                <div class="hpt-card">
                    <h2>Tien trinh va debug</h2>
                    <div id="progress_container" style="display: none;">
                        <div class="progress-bar" style="width: 100%; height: 30px; background: #e0e0e0; border-radius: 5px; overflow: hidden;">
                            <div id="progress_fill" style="height: 100%; background: #2271b1; width: 0%; transition: width 0.2s;"></div>
                        </div>
                        <p id="progress_text" style="margin-top: 10px;"><strong>San sang.</strong></p>
                        <div id="debug_log" style="background: #111; color: #d7fbd7; padding: 15px; border-radius: 5px; height: 320px; overflow-y: auto; font-family: Consolas, monospace; font-size: 12px; margin-top: 20px;"></div>
                    </div>
                </div>
            </div>

            <style>
                .hpt-container { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
                .hpt-card { background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 6px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04); }
                .hpt-card h2 { margin-top: 0; border-bottom: 2px solid #2271b1; padding-bottom: 10px; }
                #debug_log { font-size: 11px; line-height: 1.55; }
                .debug-line { margin: 2px 0; white-space: pre-wrap; word-break: break-word; }
                .debug-info { color: #8bd5ff; }
                .debug-ok { color: #7ee787; }
                .debug-warn { color: #f2cc60; }
                .debug-error { color: #ff7b72; }
            </style>
        </div>

        <script>
        (function() {
            const CONFIG = <?php echo wp_json_encode($config); ?>;

            const els = {
                contentIds: document.getElementById('content_ids'),
                batchSize: document.getElementById('batch_size'),
                confirmText: document.getElementById('confirm_text'),
                analysisResult: document.getElementById('analysis_result'),
                analysisError: document.getElementById('analysis_error'),
                previewResult: document.getElementById('preview_result'),
                previewSummary: document.getElementById('preview_summary'),
                previewTableBody: document.querySelector('#preview_table tbody'),
                verifyResult: document.getElementById('verify_result'),
                verifySummary: document.getElementById('verify_summary'),
                verifyIssues: document.getElementById('verify_issues'),
                progressContainer: document.getElementById('progress_container'),
                progressFill: document.getElementById('progress_fill'),
                progressText: document.getElementById('progress_text'),
                debugLog: document.getElementById('debug_log'),
                btnPing: document.getElementById('btn_ping'),
                btnAnalyze: document.getElementById('btn_analyze'),
                btnPreview: document.getElementById('btn_preview'),
                btnVerify: document.getElementById('btn_verify'),
                btnDelete: document.getElementById('btn_delete'),
                infoType: document.getElementById('info_type'),
                infoIds: document.getElementById('info_ids'),
                infoExists: document.getElementById('info_exists'),
                infoMissing: document.getElementById('info_missing'),
                infoMismatch: document.getElementById('info_mismatch'),
                infoDescendants: document.getElementById('info_descendants'),
                infoAttachments: document.getElementById('info_attachments'),
                infoVariations: document.getElementById('info_variations'),
                infoRevisions: document.getElementById('info_revisions'),
                infoMeta: document.getElementById('info_meta'),
                infoComments: document.getElementById('info_comments'),
                infoCommentMeta: document.getElementById('info_commentmeta'),
                infoTermRelationships: document.getElementById('info_term_relationships'),
                infoTotal: document.getElementById('info_total'),
                infoTime: document.getElementById('info_time'),
                infoBatch: document.getElementById('info_batch')
            };

            const STATE = {
                nonce: CONFIG.nonce,
                ids: [],
                contentType: 'post',
                batchSize: CONFIG.defaultBatchSize,
                analysis: null,
                verifySummary: null,
                verifyCompleted: false,
                isDeleting: false
            };

            function showProgress() {
                els.progressContainer.style.display = 'block';
            }

            function log(message, type = 'info') {
                showProgress();
                const timestamp = new Date().toLocaleTimeString('vi-VN');
                const line = document.createElement('div');
                line.className = `debug-line debug-${type}`;
                line.textContent = `[${timestamp}] ${message}`;
                els.debugLog.appendChild(line);
                els.debugLog.scrollTop = els.debugLog.scrollHeight;
            }

            function escapeHtml(value) {
                return String(value ?? '').replace(/[&<>"']/g, (char) => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char]));
            }

            function formatNumber(value) {
                return new Intl.NumberFormat('vi-VN').format(Number(value || 0));
            }

            function sleep(ms) {
                return new Promise((resolve) => window.setTimeout(resolve, ms));
            }

            function getContentType() {
                return document.querySelector('input[name="content_type"]:checked')?.value || 'post';
            }

            function parseIds(rawText) {
                const matches = String(rawText || '').match(/\d+/g) || [];
                const unique = [];
                const seen = new Set();

                for (const token of matches) {
                    const id = Number.parseInt(token, 10);
                    if (!Number.isInteger(id) || id <= 0 || seen.has(id)) {
                        continue;
                    }
                    seen.add(id);
                    unique.push(id);
                }

                return unique;
            }

            function clampBatchSize(value) {
                const parsed = Number.parseInt(value, 10);
                if (!Number.isInteger(parsed)) {
                    return CONFIG.defaultBatchSize;
                }

                return Math.min(CONFIG.maxBatchSize, Math.max(10, parsed));
            }

            function setButtons(disabled) {
                els.btnAnalyze.disabled = disabled;
                els.btnPreview.disabled = disabled || !STATE.analysis || STATE.analysis.matched_count === 0;
                els.btnVerify.disabled = disabled || !STATE.analysis || STATE.analysis.matched_count === 0;
                updateDeleteButtonState();
            }

            function updateDeleteButtonState() {
                const canDelete = STATE.verifyCompleted
                    && !STATE.isDeleting
                    && STATE.analysis
                    && STATE.analysis.matched_count > 0
                    && els.confirmText.value.trim() === CONFIG.confirmText;

                els.btnDelete.disabled = !canDelete;
            }

            function clearError() {
                els.analysisError.style.display = 'none';
                els.analysisError.innerHTML = '';
            }

            function showError(message) {
                clearError();
                els.analysisError.innerHTML = `<p><strong>Loi:</strong> ${escapeHtml(message)}</p>`;
                els.analysisError.style.display = 'block';
                log(`Loi UI: ${message}`, 'error');
            }

            function resetDerivedState() {
                STATE.analysis = null;
                STATE.verifySummary = null;
                STATE.verifyCompleted = false;

                els.analysisResult.style.display = 'none';
                els.previewResult.style.display = 'none';
                els.verifyResult.style.display = 'none';
                els.progressFill.style.width = '0%';
                els.progressText.innerHTML = '<strong>San sang.</strong>';

                els.btnPreview.disabled = true;
                els.btnVerify.disabled = true;
                els.btnDelete.disabled = true;
                clearError();
            }

            async function rawPost(formData) {
                const response = await fetch(CONFIG.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });

                const text = await response.text();
                let payload = null;

                if (text) {
                    try {
                        payload = JSON.parse(text);
                    } catch (error) {
                        payload = null;
                    }
                }

                return { response, text, payload };
            }

            function buildRequestMessage(action, data) {
                if (action === 'hpt_content_delete_batch') {
                    return `DELETE ${data.ids ? JSON.parse(data.ids).length : 0} ID`;
                }

                if (data.ids) {
                    return `${action} (${JSON.parse(data.ids).length} ID)`;
                }

                return action;
            }

            async function refreshNonce() {
                const formData = new FormData();
                formData.append('action', 'hpt_content_refresh_nonce');

                const { response, text, payload } = await rawPost(formData);

                if (!response.ok || !payload || !payload.success || !payload.data?.nonce) {
                    throw new Error(payload?.data?.message || text || `Khong lam moi duoc nonce (HTTP ${response.status})`);
                }

                STATE.nonce = payload.data.nonce;
                log('Nonce da duoc lam moi.', 'ok');
            }

            async function apiCall(action, data = {}, allowRetry = true) {
                const formData = new FormData();
                formData.append('action', action);
                if (STATE.nonce) {
                    formData.append('nonce', STATE.nonce);
                }

                Object.entries(data).forEach(([key, value]) => {
                    formData.append(key, value);
                });

                log(`Request: ${buildRequestMessage(action, data)}`, 'info');

                const { response, text, payload } = await rawPost(formData);
                const message = payload?.data?.message || text || `HTTP ${response.status}`;
                const isNonceError = response.status === 403 || text === '-1' || /nonce/i.test(message);

                if (response.ok && payload && payload.success) {
                    return payload.data;
                }

                if (allowRetry && isNonceError) {
                    log(`Gap loi nonce khi goi ${action}, dang thu lam moi token...`, 'warn');
                    await refreshNonce();
                    return apiCall(action, data, false);
                }

                throw new Error(message);
            }

            function collectInputState() {
                STATE.contentType = getContentType();
                STATE.ids = parseIds(els.contentIds.value);
                STATE.batchSize = clampBatchSize(els.batchSize.value);
                els.batchSize.value = String(STATE.batchSize);

                if (STATE.ids.length === 0) {
                    throw new Error('Khong tim thay ID hop le.');
                }
            }

            async function analyzeAll() {
                collectInputState();
                resetDerivedState();
                showProgress();

                const aggregate = {
                    input_count: STATE.ids.length,
                    matched_count: 0,
                    missing_count: 0,
                    mismatch_count: 0,
                    descendant_count: 0,
                    attachment_count: 0,
                    variation_count: 0,
                    revision_count: 0,
                    meta_count: 0,
                    comment_count: 0,
                    commentmeta_count: 0,
                    term_relationship_count: 0,
                    total_post_rows: 0,
                    estimated_total_rows: 0,
                    estimated_seconds: 0,
                    recommended_batch_size: CONFIG.defaultBatchSize,
                    missing_samples: [],
                    mismatch_samples: []
                };

                setButtons(true);
                log(`Bat dau phan tich ${formatNumber(STATE.ids.length)} ID theo batch ${STATE.batchSize}.`, 'warn');

                for (let offset = 0; offset < STATE.ids.length; offset += STATE.batchSize) {
                    const chunk = STATE.ids.slice(offset, offset + STATE.batchSize);
                    const batchNo = Math.floor(offset / STATE.batchSize) + 1;

                    const result = await apiCall('hpt_content_analyze_db', {
                        content_type: STATE.contentType,
                        ids: JSON.stringify(chunk)
                    });

                    aggregate.matched_count += result.matched_count;
                    aggregate.missing_count += result.missing_count;
                    aggregate.mismatch_count += result.mismatch_count;
                    aggregate.descendant_count += result.descendant_count;
                    aggregate.attachment_count += result.attachment_count;
                    aggregate.variation_count += result.variation_count;
                    aggregate.revision_count += result.revision_count;
                    aggregate.meta_count += result.meta_count;
                    aggregate.comment_count += result.comment_count;
                    aggregate.commentmeta_count += result.commentmeta_count;
                    aggregate.term_relationship_count += result.term_relationship_count;
                    aggregate.total_post_rows += result.total_post_rows;
                    aggregate.estimated_total_rows += result.estimated_total_rows;
                    aggregate.estimated_seconds += result.estimated_seconds;
                    aggregate.recommended_batch_size = Math.min(
                        aggregate.recommended_batch_size || result.recommended_batch_size,
                        result.recommended_batch_size || CONFIG.defaultBatchSize
                    );

                    if (aggregate.missing_samples.length < 10 && Array.isArray(result.missing_samples)) {
                        aggregate.missing_samples.push(...result.missing_samples.slice(0, 10 - aggregate.missing_samples.length));
                    }
                    if (aggregate.mismatch_samples.length < 10 && Array.isArray(result.mismatch_samples)) {
                        aggregate.mismatch_samples.push(...result.mismatch_samples.slice(0, 10 - aggregate.mismatch_samples.length));
                    }

                    els.progressFill.style.width = `${Math.min(100, ((offset + chunk.length) / STATE.ids.length) * 100)}%`;
                    els.progressText.innerHTML = `<strong>Dang phan tich: ${formatNumber(offset + chunk.length)}/${formatNumber(STATE.ids.length)} ID</strong>`;
                    log(
                        `Analyze batch ${batchNo}: hop le=${result.matched_count}, thieu=${result.missing_count}, sai loai=${result.mismatch_count}, con=${result.descendant_count}`,
                        'info'
                    );
                }

                STATE.analysis = aggregate;
                renderAnalysis(aggregate);
                STATE.verifyCompleted = false;
                updateDeleteButtonState();
                setButtons(false);
                log(
                    `Phan tich xong: hop le=${formatNumber(aggregate.matched_count)}, rows uoc tinh=${formatNumber(aggregate.estimated_total_rows)}`,
                    aggregate.matched_count > 0 ? 'ok' : 'warn'
                );
            }

            function renderAnalysis(summary) {
                els.infoType.textContent = summary && STATE.contentType === 'product' ? 'San pham' : 'Bai viet';
                els.infoIds.textContent = formatNumber(summary.input_count);
                els.infoExists.textContent = formatNumber(summary.matched_count);
                els.infoMissing.textContent = `${formatNumber(summary.missing_count)}${summary.missing_samples.length ? ` (VD: ${summary.missing_samples.join(', ')})` : ''}`;
                els.infoMismatch.textContent = `${formatNumber(summary.mismatch_count)}${summary.mismatch_samples.length ? ` (VD: ${summary.mismatch_samples.map((item) => `${item.id}:${item.type}`).join(', ')})` : ''}`;
                els.infoDescendants.textContent = formatNumber(summary.descendant_count);
                els.infoAttachments.textContent = formatNumber(summary.attachment_count);
                els.infoVariations.textContent = formatNumber(summary.variation_count);
                els.infoRevisions.textContent = formatNumber(summary.revision_count);
                els.infoMeta.textContent = formatNumber(summary.meta_count);
                els.infoComments.textContent = formatNumber(summary.comment_count);
                els.infoCommentMeta.textContent = formatNumber(summary.commentmeta_count);
                els.infoTermRelationships.textContent = formatNumber(summary.term_relationship_count);
                els.infoTotal.textContent = formatNumber(summary.estimated_total_rows);
                els.infoTime.textContent = `${formatNumber(summary.estimated_seconds)} giay (uoc tinh thuc te theo batch)`;
                els.infoBatch.textContent = `${formatNumber(summary.recommended_batch_size)} / batch`;
                els.analysisResult.style.display = 'block';
            }

            async function previewAll() {
                if (!STATE.analysis) {
                    await analyzeAll();
                }

                const collected = new Map();
                log('Bat dau preview.', 'warn');
                setButtons(true);

                for (let offset = 0; offset < STATE.ids.length && collected.size < CONFIG.previewLimit; offset += STATE.batchSize) {
                    const chunk = STATE.ids.slice(offset, offset + STATE.batchSize);
                    const result = await apiCall('hpt_content_preview', {
                        content_type: STATE.contentType,
                        ids: JSON.stringify(chunk)
                    });

                    for (const item of (result.items || [])) {
                        if (!collected.has(item.id)) {
                            collected.set(item.id, item);
                        }
                        if (collected.size >= CONFIG.previewLimit) {
                            break;
                        }
                    }

                    log(`Preview chunk ${Math.floor(offset / STATE.batchSize) + 1}: nhan them ${result.items?.length || 0} item.`, 'info');
                }

                renderPreview(Array.from(collected.values()));
                setButtons(false);
                log(`Preview xong ${collected.size} item dau tien hop le.`, 'ok');
            }

            function renderPreview(items) {
                els.previewTableBody.innerHTML = '';

                if (items.length === 0) {
                    els.previewSummary.textContent = 'Khong co item hop le nao de preview.';
                    els.previewResult.style.display = 'block';
                    return;
                }

                items.forEach((item) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${escapeHtml(item.id)}</td>
                        <td>${escapeHtml(item.title || '(khong co title)')}</td>
                        <td>${escapeHtml(item.type)}</td>
                        <td>${escapeHtml(item.status)}</td>
                        <td>${escapeHtml(item.related_summary)}</td>
                    `;
                    els.previewTableBody.appendChild(row);
                });

                const matched = STATE.analysis?.matched_count || items.length;
                els.previewSummary.textContent = `Dang hien ${formatNumber(items.length)} / ${formatNumber(matched)} item hop le dau tien.`;
                els.previewResult.style.display = 'block';
            }

            async function verifyAll() {
                if (!STATE.analysis) {
                    await analyzeAll();
                }

                const summary = {
                    issue_count: 0,
                    issues: []
                };

                setButtons(true);
                log('Bat dau verify theo batch.', 'warn');

                for (let offset = 0; offset < STATE.ids.length; offset += STATE.batchSize) {
                    const chunk = STATE.ids.slice(offset, offset + STATE.batchSize);
                    const result = await apiCall('hpt_content_verify', {
                        content_type: STATE.contentType,
                        ids: JSON.stringify(chunk)
                    });

                    summary.issue_count += result.issue_count || 0;
                    if (summary.issues.length < 100 && Array.isArray(result.issues)) {
                        summary.issues.push(...result.issues.slice(0, 100 - summary.issues.length));
                    }

                    els.progressFill.style.width = `${Math.min(100, ((offset + chunk.length) / STATE.ids.length) * 100)}%`;
                    els.progressText.innerHTML = `<strong>Dang verify: ${formatNumber(offset + chunk.length)}/${formatNumber(STATE.ids.length)} ID</strong>`;
                    log(`Verify batch ${Math.floor(offset / STATE.batchSize) + 1}: warning=${result.issue_count || 0}`, result.issue_count ? 'warn' : 'info');
                }

                STATE.verifySummary = summary;
                STATE.verifyCompleted = true;
                renderVerify(summary);
                setButtons(false);
                updateDeleteButtonState();
                log(`Verify xong: tong ${formatNumber(summary.issue_count)} canh bao.`, summary.issue_count ? 'warn' : 'ok');
            }

            function renderVerify(summary) {
                if (summary.issue_count === 0) {
                    els.verifySummary.textContent = 'Khong phat hien canh bao nao trong cac chunk da kiem tra.';
                    els.verifyIssues.innerHTML = '<p style="margin: 0; color: #008a20;"><strong>OK:</strong> Co the tiep tuc xoa neu da backup.</p>';
                } else {
                    els.verifySummary.textContent = `Tong cong ${formatNumber(summary.issue_count)} canh bao. Dang hien toi da 100 dong dau tien.`;
                    const items = summary.issues.map((issue) => (
                        `<li><strong>[${escapeHtml(issue.severity || 'warn').toUpperCase()}]</strong> ${escapeHtml(issue.message)}</li>`
                    )).join('');
                    els.verifyIssues.innerHTML = `<ul style="margin: 0 0 0 18px;">${items}</ul>`;
                }

                els.verifyResult.style.display = 'block';
            }

            async function deleteAll() {
                if (!STATE.analysis) {
                    throw new Error('Can phan tich truoc khi xoa.');
                }

                if (els.confirmText.value.trim() !== CONFIG.confirmText) {
                    throw new Error('Chuoi xac nhan khong khop.');
                }

                const confirmed = window.confirm(
                    'Day la xoa that. Tool se force delete va lam sach du lieu lien quan theo batch.\n\nBan chac chan muon tiep tuc?'
                );

                if (!confirmed) {
                    return;
                }

                STATE.isDeleting = true;
                updateDeleteButtonState();
                setButtons(true);

                let processed = 0;
                let deletedRoots = 0;
                let deletedQueue = 0;
                let skippedInput = 0;
                let totalErrors = 0;

                log(`Bat dau xoa ${formatNumber(STATE.ids.length)} ID dau vao theo batch ${STATE.batchSize}.`, 'warn');

                for (let offset = 0; offset < STATE.ids.length; offset += STATE.batchSize) {
                    const chunk = STATE.ids.slice(offset, offset + STATE.batchSize);
                    const batchNo = Math.floor(offset / STATE.batchSize) + 1;

                    const result = await apiCall('hpt_content_delete_batch', {
                        content_type: STATE.contentType,
                        ids: JSON.stringify(chunk),
                        confirm_text: els.confirmText.value.trim()
                    });

                    processed += result.input_count || chunk.length;
                    deletedRoots += result.deleted_count || 0;
                    deletedQueue += result.deleted_queue_count || 0;
                    skippedInput += result.skipped_input_count || 0;
                    totalErrors += result.error_count || 0;

                    els.progressFill.style.width = `${Math.min(100, (processed / STATE.ids.length) * 100)}%`;
                    els.progressText.innerHTML = `
                        <strong>Da xu ly ${formatNumber(processed)}/${formatNumber(STATE.ids.length)} ID dau vao</strong>
                        | root xoa OK: ${formatNumber(deletedRoots)}
                        | records xoa thuc te: ${formatNumber(deletedQueue)}
                        | bo qua: ${formatNumber(skippedInput)}
                        | loi: ${formatNumber(totalErrors)}
                    `;

                    log(
                        `Delete batch ${batchNo}: root_ok=${result.deleted_count}, queue_ok=${result.deleted_queue_count}, bo_qua=${result.skipped_input_count}, cleanup=${result.cleanup_total_count}, loi=${result.error_count}`,
                        result.error_count ? 'warn' : 'ok'
                    );

                    (result.warning_samples || []).forEach((warning) => log(`Warn batch ${batchNo}: ${warning}`, 'warn'));
                    (result.error_samples || []).forEach((error) => log(`Error batch ${batchNo}: ${error}`, 'error'));

                    await sleep(150);
                }

                STATE.isDeleting = false;
                STATE.verifyCompleted = false;
                setButtons(false);
                updateDeleteButtonState();
                log(
                    `Xoa xong: root_ok=${formatNumber(deletedRoots)}, records_ok=${formatNumber(deletedQueue)}, skipped=${formatNumber(skippedInput)}, errors=${formatNumber(totalErrors)}`,
                    totalErrors ? 'warn' : 'ok'
                );
            }

            els.btnPing?.addEventListener('click', async () => {
                try {
                    log('Dang ping admin-ajax...', 'info');
                    const result = await apiCall('hpt_content_debug_ping');
                    log(`Ping OK. User=${result.current_user}, posts=${result.total_posts}, memory=${result.memory_used_mb}MB/${result.memory_limit_mb}MB`, 'ok');
                } catch (error) {
                    log(`Ping fail: ${error.message}`, 'error');
                }
            });

            els.btnAnalyze?.addEventListener('click', async () => {
                try {
                    await analyzeAll();
                } catch (error) {
                    setButtons(false);
                    showError(error.message);
                }
            });

            els.btnPreview?.addEventListener('click', async () => {
                try {
                    await previewAll();
                } catch (error) {
                    log(`Preview fail: ${error.message}`, 'error');
                }
            });

            els.btnVerify?.addEventListener('click', async () => {
                try {
                    await verifyAll();
                } catch (error) {
                    log(`Verify fail: ${error.message}`, 'error');
                }
            });

            els.btnDelete?.addEventListener('click', async () => {
                try {
                    await deleteAll();
                } catch (error) {
                    STATE.isDeleting = false;
                    setButtons(false);
                    updateDeleteButtonState();
                    log(`Delete fail: ${error.message}`, 'error');
                    showError(error.message);
                }
            });

            els.confirmText?.addEventListener('input', updateDeleteButtonState);
            els.contentIds?.addEventListener('input', resetDerivedState);
            els.batchSize?.addEventListener('change', resetDerivedState);
            document.querySelectorAll('input[name="content_type"]').forEach((radio) => {
                radio.addEventListener('change', resetDerivedState);
            });
        })();
        </script>
        <?php
    }

    public function ajax_debug_ping(): void
    {
        $this->check_permission();

        global $wpdb;

        $total_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
        $user = wp_get_current_user();
        $memory_limit = $this->get_memory_limit_bytes();

        $this->send_json_success([
            'message' => 'Ket noi admin-ajax OK.',
            'current_user' => $user instanceof WP_User ? $user->user_login : 'unknown',
            'total_posts' => $total_posts,
            'memory_limit_mb' => $memory_limit > 0 ? round($memory_limit / 1024 / 1024, 2) : 0,
        ]);
    }

    public function ajax_refresh_nonce(): void
    {
        if (!is_user_logged_in()) {
            $this->send_json_error('Session dang nhap da het han.', 401);
        }

        if (!current_user_can('manage_options')) {
            $this->send_json_error('Khong co quyen truy cap tool nay.', 403);
        }

        $this->send_json_success([
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
        ]);
    }

    public function ajax_analyze_db(): void
    {
        $this->check_permission();
        $this->set_runtime_limits();

        $content_type = $this->get_requested_content_type();
        $input_ids = $this->get_requested_ids(true);

        $existing_posts = $this->get_existing_posts($input_ids);
        $matched_posts = $this->filter_matching_posts($existing_posts, $input_ids, $content_type);
        $matched_ids = array_keys($matched_posts);
        $missing_ids = $this->get_missing_ids($input_ids, $existing_posts);
        $mismatch_rows = $this->get_mismatch_rows($existing_posts, $input_ids, $content_type);
        $descendants = $this->collect_descendant_posts($matched_ids);
        $descendant_ids = array_map(static fn(array $row): int => (int) $row['ID'], $descendants);
        $all_target_ids = array_values(array_unique(array_merge($matched_ids, $descendant_ids)));
        $related_counts = $this->count_related_rows($all_target_ids);
        $child_counts = $this->summarize_child_types($descendants);

        $total_post_rows = count($all_target_ids);
        $estimated_total_rows = $total_post_rows
            + $related_counts['meta_count']
            + $related_counts['comment_count']
            + $related_counts['commentmeta_count']
            + $related_counts['term_relationship_count'];

        $estimated_seconds = (int) max(1, ceil($total_post_rows / 8));

        $this->send_json_success([
            'input_count' => count($input_ids),
            'matched_count' => count($matched_ids),
            'missing_count' => count($missing_ids),
            'mismatch_count' => count($mismatch_rows),
            'descendant_count' => count($descendant_ids),
            'attachment_count' => $child_counts['attachment'],
            'variation_count' => $child_counts['product_variation'],
            'revision_count' => $child_counts['revision'],
            'meta_count' => $related_counts['meta_count'],
            'comment_count' => $related_counts['comment_count'],
            'commentmeta_count' => $related_counts['commentmeta_count'],
            'term_relationship_count' => $related_counts['term_relationship_count'],
            'total_post_rows' => $total_post_rows,
            'estimated_total_rows' => $estimated_total_rows,
            'estimated_seconds' => $estimated_seconds,
            'recommended_batch_size' => $this->get_recommended_batch_size(),
            'missing_samples' => array_slice($missing_ids, 0, 10),
            'mismatch_samples' => array_slice(array_map(
                static fn(array $row): array => [
                    'id' => (int) $row['ID'],
                    'type' => (string) $row['post_type'],
                ],
                $mismatch_rows
            ), 0, 10),
        ]);
    }

    public function ajax_preview_content(): void
    {
        $this->check_permission();
        $this->set_runtime_limits();

        $content_type = $this->get_requested_content_type();
        $input_ids = $this->get_requested_ids(true);
        $existing_posts = $this->get_existing_posts($input_ids);
        $matched_posts = $this->filter_matching_posts($existing_posts, $input_ids, $content_type);

        if (empty($matched_posts)) {
            $this->send_json_success([
                'items' => [],
            ]);
        }

        $preview_ids = array_slice(array_keys($matched_posts), 0, self::PREVIEW_LIMIT);
        $meta_map = $this->get_grouped_counts($preview_ids, 'postmeta', 'post_id');
        $comment_map = $this->get_grouped_counts($preview_ids, 'comments', 'comment_post_ID');
        $child_map = $this->get_grouped_child_counts($preview_ids);

        $items = [];
        foreach ($preview_ids as $post_id) {
            $post = $matched_posts[$post_id];
            $children = $child_map[$post_id] ?? [];
            $attachment_count = (int) ($children['attachment'] ?? 0);
            $variation_count = (int) ($children['product_variation'] ?? 0);
            $revision_count = (int) ($children['revision'] ?? 0);

            $related_summary = sprintf(
                'meta=%d, comments=%d, attach=%d, variations=%d, revisions=%d',
                (int) ($meta_map[$post_id] ?? 0),
                (int) ($comment_map[$post_id] ?? 0),
                $attachment_count,
                $variation_count,
                $revision_count
            );

            $items[] = [
                'id' => (int) $post['ID'],
                'title' => $post['post_title'] !== '' ? $post['post_title'] : '(khong co title)',
                'type' => (string) $post['post_type'],
                'status' => (string) $post['post_status'],
                'related_summary' => $related_summary,
            ];
        }

        $this->send_json_success([
            'items' => $items,
        ]);
    }

    public function ajax_verify_before_delete(): void
    {
        $this->check_permission();
        $this->set_runtime_limits();

        $content_type = $this->get_requested_content_type();
        $input_ids = $this->get_requested_ids(true);
        $existing_posts = $this->get_existing_posts($input_ids);
        $matched_posts = $this->filter_matching_posts($existing_posts, $input_ids, $content_type);
        $matched_ids = array_keys($matched_posts);
        $missing_ids = $this->get_missing_ids($input_ids, $existing_posts);
        $mismatch_rows = $this->get_mismatch_rows($existing_posts, $input_ids, $content_type);
        $issues = [];

        if (!empty($missing_ids)) {
            $issues[] = [
                'severity' => 'warn',
                'message' => sprintf(
                    'Co %d ID khong ton tai. Vi du: %s',
                    count($missing_ids),
                    implode(', ', array_slice($missing_ids, 0, 10))
                ),
            ];
        }

        if (!empty($mismatch_rows)) {
            $issues[] = [
                'severity' => 'warn',
                'message' => sprintf(
                    'Co %d ID sai loai so voi radio da chon. Vi du: %s',
                    count($mismatch_rows),
                    implode(', ', array_map(
                        static fn(array $row): string => $row['ID'] . ':' . $row['post_type'],
                        array_slice($mismatch_rows, 0, 10)
                    ))
                ),
            ];
        }

        if (!empty($matched_ids)) {
            $published_count = $this->count_posts_by_status($matched_posts, ['publish', 'future', 'private']);
            if ($published_count > 0) {
                $issues[] = [
                    'severity' => 'warn',
                    'message' => sprintf('Co %d item dang o trang thai live/publish/private. Kiem tra lai truoc khi xoa.', $published_count),
                ];
            }
        }

        if ($content_type === self::CONTENT_TYPE_POST && !empty($matched_ids)) {
            $sticky_ids = array_intersect((array) get_option('sticky_posts', []), $matched_ids);
            if (!empty($sticky_ids)) {
                $issues[] = [
                    'severity' => 'warn',
                    'message' => 'Phat hien sticky post: ' . implode(', ', array_slice(array_map('intval', $sticky_ids), 0, 10)),
                ];
            }
        }

        if (!empty($matched_ids)) {
            $descendants = $this->collect_descendant_posts($matched_ids);
            $child_counts = $this->summarize_child_types($descendants);
            $descendant_count = count($descendants);

            if ($descendant_count > 0) {
                $issues[] = [
                    'severity' => 'info',
                    'message' => sprintf(
                        'Se xoa kem %d ban ghi con (attachments=%d, variations=%d, revisions=%d).',
                        $descendant_count,
                        $child_counts['attachment'],
                        $child_counts['product_variation'],
                        $child_counts['revision']
                    ),
                ];
            }
        }

        if ($content_type === self::CONTENT_TYPE_PRODUCT && !empty($matched_ids)) {
            $order_refs = $this->get_woocommerce_order_references($matched_ids);
            if (!empty($order_refs)) {
                $issues[] = [
                    'severity' => 'warn',
                    'message' => sprintf(
                        'Co san pham/variation da tung xuat hien trong order history. Vi du ID: %s',
                        implode(', ', array_slice($order_refs, 0, 10))
                    ),
                ];
            }
        }

        $this->send_json_success([
            'issue_count' => count($issues),
            'issues' => array_slice($issues, 0, 100),
        ]);
    }

    public function ajax_delete_batch(): void
    {
        $this->check_permission();
        $this->set_runtime_limits();

        if (!isset($_POST['confirm_text'])) {
            $this->send_json_error(
                'Request delete khong gui confirm_text. Trang admin cua ban dang chay JS cu. Hay Ctrl+F5 hoac mo lai Tools > Content Batch Delete roi thu lai.',
                400
            );
        }

        $confirm_text = trim((string) wp_unslash($_POST['confirm_text'] ?? ''));
        if ($confirm_text !== self::CONFIRM_TEXT) {
            $this->send_json_error('Chuoi xac nhan tren server khong hop le.', 400);
        }

        $content_type = $this->get_requested_content_type();
        $input_ids = $this->get_requested_ids(true);
        $existing_posts = $this->get_existing_posts($input_ids);
        $matched_posts = $this->filter_matching_posts($existing_posts, $input_ids, $content_type);
        $matched_ids = array_keys($matched_posts);
        $descendants = $this->collect_descendant_posts($matched_ids);
        $delete_queue = $this->build_delete_queue($matched_posts, $descendants);
        $delete_queue_ids = array_map(static fn(array $row): int => (int) $row['ID'], $delete_queue);

        if (empty($matched_ids)) {
            $this->send_json_success([
                'input_count' => count($input_ids),
                'deleted_count' => 0,
                'deleted_queue_count' => 0,
                'delete_queue_count' => 0,
                'skipped_input_count' => count($input_ids),
                'cleanup_total_count' => 0,
                'error_count' => 0,
                'warning_samples' => ['Khong co ID hop le theo loai da chon trong batch nay.'],
                'error_samples' => [],
            ]);
        }

        $comment_ids = $this->get_comment_ids_for_posts($delete_queue_ids);

        $deleted_root_count = 0;
        $deleted_queue_count = 0;
        $warning_samples = [];
        $error_samples = [];

        wp_defer_term_counting(true);
        wp_defer_comment_counting(true);

        try {
            foreach ($delete_queue as $row) {
                $post_id = (int) $row['ID'];
                $post_type = (string) $row['post_type'];
                $is_root_input = in_array($post_id, $matched_ids, true);

                try {
                    if (!get_post($post_id)) {
                        continue;
                    }

                    $deleted = $post_type === 'attachment'
                        ? wp_delete_attachment($post_id, true)
                        : wp_delete_post($post_id, true);

                    if ($deleted || !get_post($post_id)) {
                        $deleted_queue_count++;
                        if ($is_root_input) {
                            $deleted_root_count++;
                        }
                        continue;
                    }

                    $error_samples[] = sprintf('Khong the xoa ID %d (%s).', $post_id, $post_type);
                } catch (Throwable $throwable) {
                    $error_samples[] = sprintf('Exception khi xoa ID %d: %s', $post_id, $throwable->getMessage());
                }
            }
        } finally {
            wp_defer_term_counting(false);
            wp_defer_comment_counting(false);
        }

        $cleanup_counts = $this->cleanup_deleted_records($delete_queue_ids, $comment_ids, $matched_ids, $content_type);
        $cleanup_total_count = array_sum($cleanup_counts);

        if ($cleanup_total_count > 0) {
            $warning_samples[] = sprintf(
                'Da cleanup SQL bo sung: postmeta=%d, comments=%d, commentmeta=%d, term_rel=%d, wc=%d, wpml=%d',
                $cleanup_counts['postmeta'],
                $cleanup_counts['comments'],
                $cleanup_counts['commentmeta'],
                $cleanup_counts['term_relationships'],
                $cleanup_counts['woocommerce'],
                $cleanup_counts['wpml']
            );
        }

        $this->send_json_success([
            'input_count' => count($input_ids),
            'deleted_count' => $deleted_root_count,
            'deleted_queue_count' => $deleted_queue_count,
            'delete_queue_count' => count($delete_queue_ids),
            'skipped_input_count' => count($input_ids) - count($matched_ids),
            'cleanup_total_count' => $cleanup_total_count,
            'error_count' => count($error_samples),
            'warning_samples' => array_slice($warning_samples, 0, 20),
            'error_samples' => array_slice($error_samples, 0, 20),
        ]);
    }

    private function check_permission(): void
    {
        if (!is_user_logged_in()) {
            $this->send_json_error('Session dang nhap da het han.', 401);
        }

        if (!current_user_can('manage_options')) {
            $this->send_json_error('Khong co quyen truy cap tool nay.', 403);
        }

        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            $this->send_json_error('Nonce khong hop le hoac da het han. Tool co the tu lam moi nonce, neu van fail hay tai lai trang.', 403);
        }
    }

    private function set_runtime_limits(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::MAX_TIME_LIMIT);
        }

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    private function send_json_success(array $data = []): void
    {
        $memory_limit = $this->get_memory_limit_bytes();

        wp_send_json_success(array_merge([
            'version' => self::VERSION,
            'timestamp' => current_time('mysql'),
            'memory_used_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'memory_limit_mb' => $memory_limit > 0 ? round($memory_limit / 1024 / 1024, 2) : 0,
            'memory_safe' => $this->is_memory_safe(),
        ], $data));
    }

    private function send_json_error(string $message, int $code = 400, array $extra = []): void
    {
        $memory_limit = $this->get_memory_limit_bytes();

        wp_send_json_error(array_merge([
            'message' => $message,
            'version' => self::VERSION,
            'memory_used_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'memory_limit_mb' => $memory_limit > 0 ? round($memory_limit / 1024 / 1024, 2) : 0,
        ], $extra), $code);
    }

    private function get_requested_content_type(): string
    {
        $content_type = sanitize_key((string) wp_unslash($_POST['content_type'] ?? self::CONTENT_TYPE_POST));

        if (!in_array($content_type, [self::CONTENT_TYPE_POST, self::CONTENT_TYPE_PRODUCT], true)) {
            $this->send_json_error('Loai noi dung khong hop le.', 400);
        }

        return $content_type;
    }

    private function get_requested_ids(bool $enforce_limit): array
    {
        $raw_ids = $_POST['ids'] ?? [];

        if (is_string($raw_ids)) {
            $decoded = json_decode(wp_unslash($raw_ids), true);
            if (is_array($decoded)) {
                $raw_ids = $decoded;
            } else {
                $raw_ids = preg_split('/[^\d]+/', (string) wp_unslash($raw_ids));
            }
        }

        if (!is_array($raw_ids)) {
            $this->send_json_error('Danh sach ID khong hop le.', 400);
        }

        $ids = [];
        foreach ($raw_ids as $raw_id) {
            $id = absint($raw_id);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $ids = array_values($ids);

        if (empty($ids)) {
            $this->send_json_error('Danh sach ID rong hoac khong hop le.', 400);
        }

        if ($enforce_limit && count($ids) > self::MAX_BATCH_SIZE) {
            $this->send_json_error(
                sprintf(
                    'Mot request khong duoc vuot qua %d ID. Phia client phai tu chia batch.',
                    self::MAX_BATCH_SIZE
                ),
                400
            );
        }

        return $ids;
    }

    private function get_root_post_types(string $content_type): array
    {
        if ($content_type === self::CONTENT_TYPE_PRODUCT) {
            return ['product', 'product_variation'];
        }

        return [self::CONTENT_TYPE_POST];
    }

    private function get_existing_posts(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        global $wpdb;

        $placeholders = $this->build_placeholders(count($ids), '%d');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_type, post_status, post_parent
                 FROM {$wpdb->posts}
                 WHERE ID IN ({$placeholders})",
                ...$ids
            ),
            ARRAY_A
        );

        $posts = [];
        foreach ($rows as $row) {
            $posts[(int) $row['ID']] = [
                'ID' => (int) $row['ID'],
                'post_title' => (string) $row['post_title'],
                'post_type' => (string) $row['post_type'],
                'post_status' => (string) $row['post_status'],
                'post_parent' => (int) $row['post_parent'],
            ];
        }

        return $posts;
    }

    private function filter_matching_posts(array $existing_posts, array $input_ids, string $content_type): array
    {
        $allowed_types = array_fill_keys($this->get_root_post_types($content_type), true);
        $matched = [];

        foreach ($input_ids as $post_id) {
            if (!isset($existing_posts[$post_id])) {
                continue;
            }

            $post = $existing_posts[$post_id];
            if (!isset($allowed_types[$post['post_type']])) {
                continue;
            }

            $matched[$post_id] = $post;
        }

        return $matched;
    }

    private function get_missing_ids(array $input_ids, array $existing_posts): array
    {
        $missing = [];

        foreach ($input_ids as $post_id) {
            if (!isset($existing_posts[$post_id])) {
                $missing[] = $post_id;
            }
        }

        return $missing;
    }

    private function get_mismatch_rows(array $existing_posts, array $input_ids, string $content_type): array
    {
        $allowed_types = array_fill_keys($this->get_root_post_types($content_type), true);
        $rows = [];

        foreach ($input_ids as $post_id) {
            if (!isset($existing_posts[$post_id])) {
                continue;
            }

            if (isset($allowed_types[$existing_posts[$post_id]['post_type']])) {
                continue;
            }

            $rows[] = $existing_posts[$post_id];
        }

        return $rows;
    }

    private function collect_descendant_posts(array $root_ids): array
    {
        if (empty($root_ids)) {
            return [];
        }

        global $wpdb;

        $seen = array_fill_keys($root_ids, true);
        $frontier = array_values($root_ids);
        $descendants = [];

        while (!empty($frontier)) {
            $placeholders = $this->build_placeholders(count($frontier), '%d');
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_title, post_type, post_status, post_parent
                     FROM {$wpdb->posts}
                     WHERE post_parent IN ({$placeholders})",
                    ...$frontier
                ),
                ARRAY_A
            );

            $frontier = [];
            foreach ($rows as $row) {
                $post_id = (int) $row['ID'];
                if (isset($seen[$post_id])) {
                    continue;
                }

                $seen[$post_id] = true;
                $descendants[] = [
                    'ID' => $post_id,
                    'post_title' => (string) $row['post_title'],
                    'post_type' => (string) $row['post_type'],
                    'post_status' => (string) $row['post_status'],
                    'post_parent' => (int) $row['post_parent'],
                ];
                $frontier[] = $post_id;
            }
        }

        return $descendants;
    }

    private function summarize_child_types(array $descendants): array
    {
        $counts = [
            'attachment' => 0,
            'product_variation' => 0,
            'revision' => 0,
            'other' => 0,
        ];

        foreach ($descendants as $row) {
            $type = (string) $row['post_type'];
            if (isset($counts[$type])) {
                $counts[$type]++;
                continue;
            }

            $counts['other']++;
        }

        return $counts;
    }

    private function count_related_rows(array $post_ids): array
    {
        if (empty($post_ids)) {
            return [
                'meta_count' => 0,
                'comment_count' => 0,
                'commentmeta_count' => 0,
                'term_relationship_count' => 0,
            ];
        }

        global $wpdb;

        $placeholders = $this->build_placeholders(count($post_ids), '%d');

        $meta_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders})",
                ...$post_ids
            )
        );

        $comment_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID IN ({$placeholders})",
                ...$post_ids
            )
        );

        $commentmeta_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->commentmeta} cm
                 INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                 WHERE c.comment_post_ID IN ({$placeholders})",
                ...$post_ids
            )
        );

        $term_relationship_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN ({$placeholders})",
                ...$post_ids
            )
        );

        return [
            'meta_count' => $meta_count,
            'comment_count' => $comment_count,
            'commentmeta_count' => $commentmeta_count,
            'term_relationship_count' => $term_relationship_count,
        ];
    }

    private function get_grouped_counts(array $post_ids, string $table, string $column): array
    {
        if (empty($post_ids)) {
            return [];
        }

        global $wpdb;

        $table_name = match ($table) {
            'postmeta' => $wpdb->postmeta,
            'comments' => $wpdb->comments,
            default => '',
        };

        if ($table_name === '') {
            return [];
        }

        $placeholders = $this->build_placeholders(count($post_ids), '%d');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$column} AS grouped_id, COUNT(*) AS total
                 FROM {$table_name}
                 WHERE {$column} IN ({$placeholders})
                 GROUP BY {$column}",
                ...$post_ids
            ),
            ARRAY_A
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['grouped_id']] = (int) $row['total'];
        }

        return $map;
    }

    private function get_grouped_child_counts(array $post_ids): array
    {
        if (empty($post_ids)) {
            return [];
        }

        global $wpdb;

        $placeholders = $this->build_placeholders(count($post_ids), '%d');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_parent, post_type, COUNT(*) AS total
                 FROM {$wpdb->posts}
                 WHERE post_parent IN ({$placeholders})
                 GROUP BY post_parent, post_type",
                ...$post_ids
            ),
            ARRAY_A
        );

        $map = [];
        foreach ($rows as $row) {
            $parent_id = (int) $row['post_parent'];
            $post_type = (string) $row['post_type'];
            if (!isset($map[$parent_id])) {
                $map[$parent_id] = [];
            }

            $map[$parent_id][$post_type] = (int) $row['total'];
        }

        return $map;
    }

    private function count_posts_by_status(array $posts, array $statuses): int
    {
        $status_lookup = array_fill_keys($statuses, true);
        $count = 0;

        foreach ($posts as $post) {
            if (isset($status_lookup[$post['post_status']])) {
                $count++;
            }
        }

        return $count;
    }

    private function get_woocommerce_order_references(array $product_ids): array
    {
        if (empty($product_ids)) {
            return [];
        }

        global $wpdb;

        $table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        if (!$this->table_exists($table)) {
            return [];
        }

        $values = array_map('strval', $product_ids);
        $value_placeholders = $this->build_placeholders(count($values), '%s');

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_value
                 FROM {$table}
                 WHERE meta_key IN ('_product_id', '_variation_id')
                 AND meta_value IN ({$value_placeholders})
                 LIMIT 25",
                ...$values
            )
        );

        return array_values(array_unique(array_map('intval', $rows)));
    }

    private function build_delete_queue(array $matched_posts, array $descendants): array
    {
        $queue = [];
        $seen = [];

        for ($index = count($descendants) - 1; $index >= 0; $index--) {
            $row = $descendants[$index];
            $post_id = (int) $row['ID'];

            if (isset($seen[$post_id])) {
                continue;
            }

            $seen[$post_id] = true;
            $queue[] = $row;
        }

        $root_ids = array_keys($matched_posts);
        for ($index = count($root_ids) - 1; $index >= 0; $index--) {
            $post_id = (int) $root_ids[$index];
            if (isset($seen[$post_id])) {
                continue;
            }

            $seen[$post_id] = true;
            $queue[] = $matched_posts[$post_id];
        }

        return $queue;
    }

    private function get_comment_ids_for_posts(array $post_ids): array
    {
        if (empty($post_ids)) {
            return [];
        }

        global $wpdb;

        $placeholders = $this->build_placeholders(count($post_ids), '%d');
        $comment_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT comment_ID
                 FROM {$wpdb->comments}
                 WHERE comment_post_ID IN ({$placeholders})",
                ...$post_ids
            )
        );

        return array_values(array_unique(array_map('intval', $comment_ids)));
    }

    private function cleanup_deleted_records(array $post_ids, array $comment_ids, array $root_ids, string $content_type): array
    {
        $counts = [
            'postmeta' => 0,
            'comments' => 0,
            'commentmeta' => 0,
            'term_relationships' => 0,
            'woocommerce' => 0,
            'wpml' => 0,
        ];

        if (empty($post_ids)) {
            return $counts;
        }

        global $wpdb;

        $post_placeholders = $this->build_placeholders(count($post_ids), '%d');

        $counts['postmeta'] = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE pm
                 FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.post_id IN ({$post_placeholders})
                 AND p.ID IS NULL",
                ...$post_ids
            )
        );

        $counts['comments'] = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE c
                 FROM {$wpdb->comments} c
                 LEFT JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
                 WHERE c.comment_post_ID IN ({$post_placeholders})
                 AND p.ID IS NULL",
                ...$post_ids
            )
        );

        $counts['term_relationships'] = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE tr
                 FROM {$wpdb->term_relationships} tr
                 LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                 WHERE tr.object_id IN ({$post_placeholders})
                 AND p.ID IS NULL",
                ...$post_ids
            )
        );

        if (!empty($comment_ids)) {
            $comment_placeholders = $this->build_placeholders(count($comment_ids), '%d');
            $counts['commentmeta'] = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE cm
                     FROM {$wpdb->commentmeta} cm
                     LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                     WHERE cm.comment_id IN ({$comment_placeholders})
                     AND c.comment_ID IS NULL",
                    ...$comment_ids
                )
            );
        }

        if ($content_type === self::CONTENT_TYPE_PRODUCT && !empty($root_ids)) {
            $counts['woocommerce'] += $this->cleanup_woocommerce_tables($root_ids);
        }

        $counts['wpml'] += $this->cleanup_wpml_rows($post_ids);

        foreach ($counts as $key => $value) {
            if ($value < 0) {
                $counts[$key] = 0;
            }
        }

        return $counts;
    }

    private function cleanup_woocommerce_tables(array $product_ids): int
    {
        global $wpdb;

        $deleted = 0;
        $placeholders = $this->build_placeholders(count($product_ids), '%d');

        $meta_lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        if ($this->table_exists($meta_lookup_table)) {
            $deleted += (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE lookup_table
                     FROM {$meta_lookup_table} lookup_table
                     LEFT JOIN {$wpdb->posts} p ON p.ID = lookup_table.product_id
                     WHERE lookup_table.product_id IN ({$placeholders})
                     AND p.ID IS NULL",
                    ...$product_ids
                )
            );
        }

        $permissions_table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
        if ($this->table_exists($permissions_table)) {
            $deleted += (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE permissions
                     FROM {$permissions_table} permissions
                     LEFT JOIN {$wpdb->posts} p ON p.ID = permissions.product_id
                     WHERE permissions.product_id IN ({$placeholders})
                     AND p.ID IS NULL",
                    ...$product_ids
                )
            );
        }

        return max(0, $deleted);
    }

    private function cleanup_wpml_rows(array $post_ids): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'icl_translations';
        if (!$this->table_exists($table)) {
            return 0;
        }

        $post_placeholders = $this->build_placeholders(count($post_ids), '%d');
        $type_placeholders = $this->build_placeholders(5, '%s');
        $types = ['post_post', 'post_attachment', 'post_product', 'post_product_variation', 'post_revision'];

        $params = array_merge($post_ids, $types);

        $deleted = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE icl
                 FROM {$table} icl
                 LEFT JOIN {$wpdb->posts} p ON p.ID = icl.element_id
                 WHERE icl.element_id IN ({$post_placeholders})
                 AND icl.element_type IN ({$type_placeholders})
                 AND p.ID IS NULL",
                ...$params
            )
        );

        return max(0, $deleted);
    }

    private function table_exists(string $table_name): bool
    {
        static $cache = [];

        if (isset($cache[$table_name])) {
            return $cache[$table_name];
        }

        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        $cache[$table_name] = ($found === $table_name);

        return $cache[$table_name];
    }

    private function get_recommended_batch_size(): int
    {
        $memory_limit = $this->get_memory_limit_bytes();

        if ($memory_limit <= 0 || $memory_limit === PHP_INT_MAX) {
            return self::SAFE_BATCH_SIZE;
        }

        if ($memory_limit <= 256 * 1024 * 1024) {
            return 25;
        }

        if ($memory_limit <= 512 * 1024 * 1024) {
            return 50;
        }

        if ($memory_limit <= 1024 * 1024 * 1024) {
            return 100;
        }

        return 200;
    }

    private function is_memory_safe(): bool
    {
        $limit = $this->get_memory_limit_bytes();
        if ($limit <= 0 || $limit === PHP_INT_MAX) {
            return true;
        }

        return (memory_get_usage(true) / $limit) < self::MEMORY_SAFETY_THRESHOLD;
    }

    private function get_memory_limit_bytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '') {
            return 0;
        }

        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $value = (int) $limit;
        $unit = strtoupper(substr($limit, -1));

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
    }

    private function build_placeholders(int $count, string $placeholder): string
    {
        return implode(',', array_fill(0, $count, $placeholder));
    }
}

if (is_admin()) {
    new HPT_Content_Batch_Delete();
}
