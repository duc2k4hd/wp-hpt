(function () {
    var state = window.HPTProductAdminProfilerState || {
        requests: [],
        slowest: null,
        saveRoundtrip: null,
        requestCountTotal: 0,
        isBooted: false
    };

    if (state.isBooted) {
        return;
    }

    state.isBooted = true;
    window.HPTProductAdminProfilerState = state;

    patchFetch();
    patchXHR();

    document.addEventListener("DOMContentLoaded", function () {
        state.saveRoundtrip = consumeSaveRoundtrip();
        attachSaveRoundtripListener();
        renderPanel();
        window.setInterval(renderPanel, 1500);
    });

    document.addEventListener("hpt:product-admin-profiler-data", renderPanel);

    function patchFetch() {
        if (typeof window.fetch !== "function" || window.fetch.__hptWrapped) {
            return;
        }

        var originalFetch = window.fetch;
        var wrappedFetch = function (input, init) {
            var method = normalizeMethod(init && init.method ? init.method : "GET");
            var url = extractFetchUrl(input);
            var startedAt = window.performance && typeof window.performance.now === "function"
                ? window.performance.now()
                : Date.now();

            return originalFetch.apply(this, arguments).then(function (response) {
                pushRequest(url, method, response ? response.status : 0, startedAt);
                return response;
            }).catch(function (error) {
                pushRequest(url, method, 0, startedAt);
                throw error;
            });
        };

        wrappedFetch.__hptWrapped = true;
        window.fetch = wrappedFetch;
    }

    function patchXHR() {
        if (typeof window.XMLHttpRequest === "undefined") {
            return;
        }

        var proto = window.XMLHttpRequest.prototype;

        if (proto.open.__hptWrapped) {
            return;
        }

        var originalOpen = proto.open;
        var originalSend = proto.send;

        proto.open = function (method, url) {
            this.__hptProfiler = {
                method: normalizeMethod(method),
                url: typeof url === "string" ? url : "",
                startedAt: 0
            };

            return originalOpen.apply(this, arguments);
        };

        proto.send = function () {
            if (this.__hptProfiler) {
                this.__hptProfiler.startedAt = window.performance && typeof window.performance.now === "function"
                    ? window.performance.now()
                    : Date.now();

                this.addEventListener("loadend", function () {
                    pushRequest(
                        this.__hptProfiler ? this.__hptProfiler.url : "",
                        this.__hptProfiler ? this.__hptProfiler.method : "GET",
                        this.status || 0,
                        this.__hptProfiler ? this.__hptProfiler.startedAt : 0
                    );
                }, { once: true });
            }

            return originalSend.apply(this, arguments);
        };

        proto.open.__hptWrapped = true;
    }

    function attachSaveRoundtripListener() {
        var form = document.getElementById("post");

        if (!form) {
            return;
        }

        var saved = false;

        function markSaveRoundtrip(label) {
            if (saved) {
                return;
            }

            saved = true;

            try {
                window.sessionStorage.setItem("hptProductAdminSaveRoundtrip", JSON.stringify({
                    label: label || "Lưu sản phẩm",
                    startedAt: Date.now()
                }));
            } catch (error) {
                return;
            }
        }

        form.addEventListener("submit", function () {
            var activeElement = document.activeElement;
            var label = activeElement && (activeElement.value || activeElement.textContent)
                ? String(activeElement.value || activeElement.textContent).trim()
                : "Lưu sản phẩm";

            markSaveRoundtrip(label);
        }, true);
    }

    function consumeSaveRoundtrip() {
        try {
            var raw = window.sessionStorage.getItem("hptProductAdminSaveRoundtrip");

            if (!raw) {
                return null;
            }

            window.sessionStorage.removeItem("hptProductAdminSaveRoundtrip");

            var parsed = JSON.parse(raw);
            var startedAt = Number(parsed && parsed.startedAt ? parsed.startedAt : 0);

            if (!startedAt) {
                return null;
            }

            var duration = Date.now() - startedAt;

            if (duration < 0 || duration > 600000) {
                return null;
            }

            return {
                duration: duration,
                label: parsed && parsed.label ? parsed.label : "Lưu sản phẩm"
            };
        } catch (error) {
            return null;
        }
    }

    function pushRequest(url, method, status, startedAt) {
        if (!startedAt) {
            return;
        }

        var finishedAt = window.performance && typeof window.performance.now === "function"
            ? window.performance.now()
            : Date.now();
        var duration = Math.max(0, Math.round(finishedAt - startedAt));
        var item = {
            url: simplifyUrl(url),
            method: normalizeMethod(method),
            status: Number(status || 0),
            duration: duration,
            type: classifyRequest(url)
        };

        state.requests.unshift(item);
        state.requests = state.requests.slice(0, 20);
        state.requestCountTotal += 1;

        if (!state.slowest || item.duration > state.slowest.duration) {
            state.slowest = item;
        }

        renderPanel();
    }

    function renderPanel() {
        var panel = document.getElementById("hpt-product-admin-profiler-panel");
        var data = window.HPTProductAdminProfilerData || null;

        if (!panel || !data) {
            return;
        }

        panel.hidden = false;
        setText("hpt-profiler-screen-label", data.screen_label || "");
        setText("hpt-profiler-php-render", formatNumber(data.php_render_ms) + " ms");
        setText("hpt-profiler-memory", formatNumber(data.peak_memory_mb) + " MB");
        setText(
            "hpt-profiler-assets",
            String((data.script_handles || []).length) + " / " + String((data.style_handles || []).length)
        );
        setText("hpt-profiler-ajax-count", String(state.requestCountTotal));
        setText(
            "hpt-profiler-slowest-request",
            state.slowest
                ? "Request chậm nhất: " + state.slowest.type + " " + state.slowest.method + " " + state.slowest.duration + " ms | " + state.slowest.url
                : "Chưa có request bất đồng bộ nào được ghi nhận."
        );
        setText(
            "hpt-profiler-save-roundtrip",
            state.saveRoundtrip
                ? "Round-trip " + state.saveRoundtrip.label + ": " + state.saveRoundtrip.duration + " ms"
                : "Round-trip lưu sản phẩm: chưa có dữ liệu."
        );
        setText(
            "hpt-profiler-script-handles",
            (data.script_handles || []).length ? data.script_handles.join(", ") : "Không có."
        );
        setText(
            "hpt-profiler-style-handles",
            (data.style_handles || []).length ? data.style_handles.join(", ") : "Không có."
        );
        renderRequests();
    }

    function renderRequests() {
        var container = document.getElementById("hpt-profiler-request-list");

        if (!container) {
            return;
        }

        if (!state.requests.length) {
            container.innerHTML = "Chưa có request nào.";
            return;
        }

        container.innerHTML = state.requests.map(function (item) {
            return [
                '<div class="hpt-profiler__request">',
                '<span class="hpt-profiler__pill">' + escapeHtml(item.type) + "</span>",
                "<strong>" + escapeHtml(item.method) + "</strong> ",
                escapeHtml(String(item.duration)) + " ms",
                item.status ? " | " + escapeHtml(String(item.status)) : "",
                '<div>' + escapeHtml(item.url) + "</div>",
                "</div>"
            ].join("");
        }).join("");
    }

    function extractFetchUrl(input) {
        if (typeof input === "string") {
            return input;
        }

        if (input && typeof input.url === "string") {
            return input.url;
        }

        return "";
    }

    function classifyRequest(url) {
        var normalizedUrl = String(url || "");

        if (normalizedUrl.indexOf("admin-ajax.php") !== -1) {
            return "admin-ajax";
        }

        if (normalizedUrl.indexOf("/wp-json/") !== -1) {
            return "REST";
        }

        return "XHR";
    }

    function simplifyUrl(url) {
        var normalizedUrl = String(url || "");

        if (!normalizedUrl) {
            return "(unknown)";
        }

        try {
            var parsedUrl = new window.URL(normalizedUrl, window.location.origin);
            return parsedUrl.pathname + parsedUrl.search;
        } catch (error) {
            return normalizedUrl;
        }
    }

    function normalizeMethod(method) {
        return String(method || "GET").toUpperCase();
    }

    function setText(id, value) {
        var element = document.getElementById(id);

        if (element) {
            element.textContent = value;
        }
    }

    function formatNumber(value) {
        var number = Number(value || 0);

        if (!isFinite(number)) {
            return "0";
        }

        return Math.round(number * 10) / 10;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
})();
