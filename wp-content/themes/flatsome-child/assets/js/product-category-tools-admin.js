(function () {
    const config = window.HPTProductCatTools || null;

    if (!config) {
        return;
    }

    document.addEventListener("DOMContentLoaded", function () {
        initExportForm();
        initImportForm();
    });

    function initExportForm() {
        const form = document.getElementById("hpt-product-cat-export-form");
        const progressBox = document.getElementById("hpt-export-progress");

        if (!form || !progressBox) {
            return;
        }

        form.addEventListener("submit", async function (event) {
            event.preventDefault();

            if (form.dataset.running === "1") {
                return;
            }

            const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
            const exportColumns = Array.from(form.querySelectorAll('input[name="export_columns[]"]:checked, input[type="hidden"][name="export_columns[]"]'))
                .map(function (input) {
                    return input.value;
                })
                .filter(Boolean);
            const categorySelect = form.querySelector('select[name="export_category_ids[]"]');
            const categoryIds = categorySelect
                ? Array.from(categorySelect.selectedOptions).map(function (option) {
                    return option.value;
                }).filter(Boolean)
                : [];

            const includeChildrenInput = form.querySelector('input[name="include_children"]');
            const includeChildren = includeChildrenInput && includeChildrenInput.checked ? "1" : "";

            form.dataset.running = "1";
            toggleButton(submitButton, true, "Đang export...");
            setProgress(progressBox, {
                active: true,
                title: "Đang chuẩn bị export...",
                percent: 0,
                meta: "Đang tạo phiên export.",
                errors: []
            });

            let jobToken = "";

            try {
                const initPayload = new FormData();

                exportColumns.forEach(function (value) {
                    initPayload.append("export_columns[]", value);
                });

                categoryIds.forEach(function (value) {
                    initPayload.append("export_category_ids[]", value);
                });

                if (includeChildren) {
                    initPayload.append("include_children", includeChildren);
                }

                const initData = await requestAjax("hpt_product_cat_export_init", initPayload);
                jobToken = initData.job_token || "";

                if (!initData.total_batches || initData.total_batches < 1) {
                    downloadCsv(initData.filename || "product-categories.csv", ["\uFEFF", initData.header_csv || ""]);
                    setProgress(progressBox, {
                        active: true,
                        title: "Export hoàn tất",
                        percent: 100,
                        meta: "Không có dòng dữ liệu nào phù hợp. File header đã được tải.",
                        errors: []
                    });
                    return;
                }

                const chunks = new Array(initData.total_batches);
                let completedBatches = 0;
                let exportedRows = 0;

                setProgress(progressBox, {
                    active: true,
                    title: "Đang export dữ liệu...",
                    percent: 0,
                    meta: "0/" + initData.total_batches + " batch | 0/" + initData.total_rows + " dòng",
                    errors: []
                });

                await runConcurrent(initData.total_batches, initData.concurrency || 1, async function (batchIndex) {
                    const batchData = await withRetry(function () {
                        return requestAjax("hpt_product_cat_export_batch", {
                            job_token: jobToken,
                            batch_index: String(batchIndex)
                        });
                    });

                    chunks[batchIndex] = batchData.chunk || "";
                    completedBatches += 1;
                    exportedRows += batchData.row_count || 0;

                    setProgress(progressBox, {
                        active: true,
                        title: "Đang export dữ liệu...",
                        percent: Math.round((completedBatches / initData.total_batches) * 100),
                        meta: completedBatches + "/" + initData.total_batches + " batch | " + exportedRows + "/" + initData.total_rows + " dòng",
                        errors: []
                    });
                });

                downloadCsv(initData.filename || "product-categories.csv", ["\uFEFF", initData.header_csv || ""].concat(chunks));

                setProgress(progressBox, {
                    active: true,
                    title: "Export hoàn tất",
                    percent: 100,
                    meta: "Đã export " + exportedRows + " dòng qua " + initData.total_batches + " batch.",
                    errors: []
                });
            } catch (error) {
                setProgress(progressBox, {
                    active: true,
                    title: "Export thất bại",
                    percent: 100,
                    meta: error.message || "Có lỗi khi export dữ liệu.",
                    errors: [error.message || "Có lỗi khi export dữ liệu."]
                });
            } finally {
                if (jobToken) {
                    requestAjax("hpt_product_cat_export_cleanup", {
                        job_token: jobToken
                    }).catch(function () {
                        return null;
                    });
                }

                form.dataset.running = "0";
                toggleButton(submitButton, false);
            }
        });
    }

    function initImportForm() {
        const form = document.getElementById("hpt-product-cat-import-form");
        const progressBox = document.getElementById("hpt-import-progress");

        if (!form || !progressBox) {
            return;
        }

        form.addEventListener("submit", async function (event) {
            event.preventDefault();

            if (form.dataset.running === "1") {
                return;
            }

            const fileInput = form.querySelector('input[type="file"][name="product_cat_csv"]');
            const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');

            if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                setProgress(progressBox, {
                    active: true,
                    title: "Import thất bại",
                    percent: 100,
                    meta: "Chưa chọn file CSV hợp lệ.",
                    errors: ["Chưa chọn file CSV hợp lệ."]
                });
                return;
            }

            form.dataset.running = "1";
            toggleButton(submitButton, true, "Đang import...");
            setProgress(progressBox, {
                active: true,
                title: "Đang đọc file CSV...",
                percent: 0,
                meta: "Đang phân tích dữ liệu ở trình duyệt.",
                errors: []
            });

            try {
                const file = fileInput.files[0];
                const text = await file.text();
                const delimiter = detectDelimiter(text);
                const parsedRows = parseCsv(text, delimiter);

                if (!parsedRows.length) {
                    throw new Error("File CSV không có dữ liệu.");
                }

                const headerRow = parsedRows[0];
                const dataRows = [];

                for (let i = 1; i < parsedRows.length; i += 1) {
                    const cells = parsedRows[i];

                    if (!rowHasData(cells)) {
                        continue;
                    }

                    dataRows.push({
                        row_number: i + 1,
                        cells: cells
                    });
                }

                const initData = await requestAjax("hpt_product_cat_import_init", {
                    header_row: JSON.stringify(headerRow)
                });

                const importBatches = chunkArray(dataRows, initData.import_batch_size || 80);
                const summary = createEmptySummary();
                const pendingParents = [];
                let completedImportBatches = 0;

                if (!importBatches.length) {
                    setProgress(progressBox, {
                        active: true,
                        title: "Import hoàn tất",
                        percent: 100,
                        meta: "Không có dòng dữ liệu nào để import.",
                        errors: []
                    });
                    return;
                }

                setProgress(progressBox, {
                    active: true,
                    title: "Đang import dữ liệu...",
                    percent: 0,
                    meta: "0/" + importBatches.length + " batch | 0/" + dataRows.length + " dòng",
                    errors: []
                });

                await runConcurrent(importBatches.length, initData.import_concurrency || 1, async function (batchIndex) {
                    const batchRows = importBatches[batchIndex];
                    const batchData = await withRetry(function () {
                        return requestAjax("hpt_product_cat_import_batch", {
                            header_map: JSON.stringify(initData.header_map),
                            available_fields: JSON.stringify(initData.available_fields),
                            rows: JSON.stringify(batchRows)
                        });
                    });

                    mergeSummary(summary, batchData.summary || {});

                    if (Array.isArray(batchData.pending_parents) && batchData.pending_parents.length) {
                        pendingParents.push.apply(pendingParents, batchData.pending_parents);
                    }

                    completedImportBatches += 1;

                    setProgress(progressBox, {
                        active: true,
                        title: "Đang import dữ liệu...",
                        percent: Math.round((completedImportBatches / importBatches.length) * 80),
                        meta: completedImportBatches + "/" + importBatches.length + " batch | " + summary.processed + "/" + dataRows.length + " dòng",
                        errors: summary.errors
                    });
                });

                const parentBatches = chunkArray(pendingParents, initData.import_parent_batch_size || 120);
                let completedParentBatches = 0;

                if (parentBatches.length) {
                    await runConcurrent(parentBatches.length, initData.import_parent_concurrency || 1, async function (batchIndex) {
                        const parentData = await withRetry(function () {
                            return requestAjax("hpt_product_cat_import_resolve_parents", {
                                items: JSON.stringify(parentBatches[batchIndex])
                            });
                        });

                        mergeSummary(summary, parentData.summary || {});
                        completedParentBatches += 1;

                        const percent = 80 + Math.round((completedParentBatches / parentBatches.length) * 20);

                        setProgress(progressBox, {
                            active: true,
                            title: "Đang hoàn tất quan hệ cha-con...",
                            percent: percent,
                            meta: completedParentBatches + "/" + parentBatches.length + " batch parent | " + summary.parent_updates + " cập nhật cha-con",
                            errors: summary.errors
                        });
                    });
                }

                setProgress(progressBox, {
                    active: true,
                    title: summary.errors_count > 0 ? "Import hoàn tất, có cảnh báo" : "Import hoàn tất",
                    percent: 100,
                    meta: buildSummaryText(summary),
                    errors: summary.errors
                });
            } catch (error) {
                setProgress(progressBox, {
                    active: true,
                    title: "Import thất bại",
                    percent: 100,
                    meta: error.message || "Có lỗi khi import dữ liệu.",
                    errors: [error.message || "Có lỗi khi import dữ liệu."]
                });
            } finally {
                form.dataset.running = "0";
                toggleButton(submitButton, false);
            }
        });
    }

    async function requestAjax(action, payload) {
        const formData = payload instanceof FormData ? payload : new FormData();

        if (!(payload instanceof FormData)) {
            Object.keys(payload || {}).forEach(function (key) {
                appendFormValue(formData, key, payload[key]);
            });
        }

        formData.append("action", action);
        formData.append("nonce", config.nonce);

        const response = await fetch(config.ajax_url, {
            method: "POST",
            credentials: "same-origin",
            body: formData
        });

        const responseText = await response.text();
        let json = null;

        try {
            json = JSON.parse(responseText);
        } catch (error) {
            throw new Error((responseText || "Phản hồi máy chủ không hợp lệ.").slice(0, 500));
        }

        if (!response.ok || !json || !json.success) {
            throw new Error((json && json.data && json.data.message) || "Yêu cầu AJAX thất bại.");
        }

        return json.data || {};
    }

    async function withRetry(task, retries) {
        const maxRetries = typeof retries === "number" ? retries : 2;
        let lastError = null;

        for (let attempt = 0; attempt <= maxRetries; attempt += 1) {
            try {
                return await task();
            } catch (error) {
                lastError = error;

                if (attempt >= maxRetries) {
                    break;
                }

                await sleep(500 * (attempt + 1));
            }
        }

        throw lastError || new Error("Yêu cầu thất bại.");
    }

    async function runConcurrent(total, concurrency, worker) {
        const limit = Math.max(1, Math.min(concurrency || 1, total || 1));
        let cursor = 0;

        const workers = Array.from({ length: limit }, function () {
            return (async function () {
                while (cursor < total) {
                    const current = cursor;
                    cursor += 1;
                    await worker(current);
                }
            })();
        });

        await Promise.all(workers);
    }

    function setProgress(box, state) {
        const titleEl = box.querySelector("strong");
        const fillEl = box.querySelector(".hpt-tool-progress__fill");
        const metaEl = box.querySelector(".hpt-tool-progress__meta");
        const errorsEl = box.querySelector(".hpt-tool-progress__errors");
        const errors = Array.isArray(state.errors) ? state.errors.filter(Boolean) : [];

        box.classList.toggle("is-active", !!state.active);

        if (titleEl) {
            titleEl.textContent = state.title || "";
        }

        if (fillEl) {
            fillEl.style.width = Math.max(0, Math.min(100, state.percent || 0)) + "%";
        }

        if (metaEl) {
            metaEl.textContent = state.meta || "";
        }

        if (errorsEl) {
            if (errors.length) {
                errorsEl.hidden = false;
                errorsEl.innerHTML = errors.slice(0, 20).map(function (errorText) {
                    return "<div>" + escapeHtml(errorText) + "</div>";
                }).join("");
            } else {
                errorsEl.hidden = true;
                errorsEl.innerHTML = "";
            }
        }
    }

    function toggleButton(button, disabled, busyText) {
        if (!button) {
            return;
        }

        if (!button.dataset.originalText) {
            button.dataset.originalText = button.tagName === "INPUT" ? button.value : button.textContent;
        }

        button.disabled = !!disabled;

        if (disabled) {
            if (button.tagName === "INPUT") {
                button.value = busyText || button.dataset.originalText;
            } else {
                button.textContent = busyText || button.dataset.originalText;
            }
        } else if (button.tagName === "INPUT") {
            button.value = button.dataset.originalText;
        } else {
            button.textContent = button.dataset.originalText;
        }
    }

    function appendFormValue(formData, key, value) {
        if (Array.isArray(value)) {
            value.forEach(function (item) {
                formData.append(key, item);
            });
            return;
        }

        formData.append(key, value == null ? "" : String(value));
    }

    function detectDelimiter(text) {
        const firstLine = (text.split(/\r\n|\n|\r/, 1)[0] || "");
        const delimiters = [",", ";", "\t", "|"];
        let bestDelimiter = ",";
        let bestScore = -1;

        delimiters.forEach(function (delimiter) {
            const score = firstLine.split(delimiter).length - 1;

            if (score > bestScore) {
                bestScore = score;
                bestDelimiter = delimiter;
            }
        });

        return bestDelimiter;
    }

    function parseCsv(text, delimiter) {
        const rows = [];
        let row = [];
        let cell = "";
        let inQuotes = false;

        for (let i = 0; i < text.length; i += 1) {
            const char = text[i];

            if (inQuotes) {
                if (char === '"') {
                    if (text[i + 1] === '"') {
                        cell += '"';
                        i += 1;
                    } else {
                        inQuotes = false;
                    }
                } else {
                    cell += char;
                }

                continue;
            }

            if (char === '"') {
                inQuotes = true;
                continue;
            }

            if (char === delimiter) {
                row.push(cell);
                cell = "";
                continue;
            }

            if (char === "\n") {
                row.push(cell);
                rows.push(row);
                row = [];
                cell = "";
                continue;
            }

            if (char === "\r") {
                continue;
            }

            cell += char;
        }

        row.push(cell);

        if (row.length > 1 || row[0] !== "") {
            rows.push(row);
        }

        return rows;
    }

    function rowHasData(cells) {
        return Array.isArray(cells) && cells.some(function (cell) {
            return String(cell || "").trim() !== "";
        });
    }

    function chunkArray(items, size) {
        const result = [];
        const chunkSize = Math.max(1, size || 1);

        for (let index = 0; index < items.length; index += chunkSize) {
            result.push(items.slice(index, index + chunkSize));
        }

        return result;
    }

    function createEmptySummary() {
        return {
            processed: 0,
            created: 0,
            updated: 0,
            unchanged: 0,
            skipped: 0,
            parent_updates: 0,
            errors_count: 0,
            errors: []
        };
    }

    function mergeSummary(target, source) {
        if (!source) {
            return;
        }

        target.processed += Number(source.processed || 0);
        target.created += Number(source.created || 0);
        target.updated += Number(source.updated || 0);
        target.unchanged += Number(source.unchanged || 0);
        target.skipped += Number(source.skipped || 0);
        target.parent_updates += Number(source.parent_updates || 0);
        target.errors_count += Number(source.errors_count || 0);

        if (Array.isArray(source.errors) && source.errors.length) {
            target.errors = target.errors.concat(source.errors).slice(0, 80);
        }
    }

    function buildSummaryText(summary) {
        return [
            "Đã xử lý: " + summary.processed,
            "Tạo mới: " + summary.created,
            "Cập nhật: " + summary.updated,
            "Không đổi: " + summary.unchanged,
            "Bỏ qua: " + summary.skipped,
            "Cập nhật cha-con: " + summary.parent_updates,
            "Lỗi: " + summary.errors_count
        ].join(" | ");
    }

    function downloadCsv(filename, parts) {
        const blob = new Blob(parts, {
            type: "text/csv;charset=utf-8"
        });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");

        link.href = url;
        link.download = filename || "export.csv";
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function sleep(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, ms);
        });
    }
})();
