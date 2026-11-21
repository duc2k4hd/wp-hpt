(function () {
    var config = window.HPTProductAdminTaxonomy || null;

    if (!config || typeof document === "undefined") {
        return;
    }

    document.addEventListener("DOMContentLoaded", function () {
        var boxes = document.querySelectorAll(".hpt-product-taxonomy-box");

        boxes.forEach(function (box) {
            initBox(box);
        });
    });

    function initBox(box) {
        var taxonomy = String(box.getAttribute("data-taxonomy") || "");
        var hierarchical = box.getAttribute("data-hierarchical") === "1";
        var searchInput = box.querySelector(".hpt-product-taxonomy-search");
        var selectedContainer = box.querySelector(".hpt-product-taxonomy-selected");
        var resultsContainer = box.querySelector(".hpt-product-taxonomy-results");
        var statusContainer = box.querySelector(".hpt-product-taxonomy-status");
        var flatInput = box.querySelector(".hpt-product-taxonomy-flat-input");
        var requestController = null;
        var selected = readSelectedTerms(selectedContainer);

        if (!taxonomy || !searchInput || !selectedContainer || !resultsContainer || !statusContainer) {
            return;
        }

        renderSelected();
        setStatus(getSearchPrompt());

        searchInput.addEventListener("input", debounce(function () {
            var keyword = searchInput.value.trim();

            if (!keyword) {
                resultsContainer.innerHTML = "";
                setStatus(getSearchPrompt());
                return;
            }

            setStatus(config.i18n && config.i18n.searching ? config.i18n.searching : "");

            if (requestController && typeof requestController.abort === "function") {
                requestController.abort();
            }

            requestController = typeof AbortController !== "undefined" ? new AbortController() : null;

            searchTerms(taxonomy, keyword, requestController ? requestController.signal : null).then(function (items) {
                renderResults(items || []);
            }).catch(function (error) {
                if (error && error.name === "AbortError") {
                    return;
                }

                resultsContainer.innerHTML = "";
                setStatus(error && error.message ? error.message : (config.i18n && config.i18n.searchError ? config.i18n.searchError : ""));
            });
        }, 280));

        if (!hierarchical) {
            searchInput.addEventListener("keydown", function (event) {
                var key = String(event.key || "");
                var value = searchInput.value.trim().replace(/,+$/, "");

                if ((key !== "Enter" && key !== ",") || !value) {
                    return;
                }

                event.preventDefault();
                addFlatTerm(value);
                searchInput.value = "";
                resultsContainer.innerHTML = "";
                setStatus("Đã thêm tag. Lưu sản phẩm để cập nhật.");
            });
        }

        selectedContainer.addEventListener("click", function (event) {
            var removeButton = event.target.closest(".hpt-product-taxonomy-chip__remove");
            var chip = removeButton ? removeButton.closest(".hpt-product-taxonomy-chip") : null;

            if (!chip) {
                return;
            }

            event.preventDefault();

            var termId = String(chip.getAttribute("data-term-id") || "");

            if (termId && selected[termId]) {
                delete selected[termId];
            }

            renderSelected();
            refreshResultSelectionState();
        });

        resultsContainer.addEventListener("click", function (event) {
            var addButton = event.target.closest(".hpt-product-taxonomy-result__add");
            var row = addButton ? addButton.closest(".hpt-product-taxonomy-result") : null;

            if (!row || row.classList.contains("is-selected")) {
                return;
            }

            event.preventDefault();

            var item = {
                id: Number(row.getAttribute("data-term-id") || 0),
                name: String(row.getAttribute("data-term-name") || ""),
                slug: String(row.getAttribute("data-term-slug") || ""),
                label: String(row.getAttribute("data-term-label") || "")
            };

            if (!item.id || !item.name) {
                return;
            }

            selected[String(item.id)] = item;
            renderSelected();
            refreshResultSelectionState();
        });

        function readSelectedTerms(container) {
            var map = {};

            container.querySelectorAll(".hpt-product-taxonomy-chip").forEach(function (chip) {
                var termId = String(chip.getAttribute("data-term-id") || "");
                var name = String(chip.getAttribute("data-term-name") || "");

                if (!termId || !name) {
                    return;
                }

                map[termId] = {
                    id: Number(termId),
                    name: name,
                    slug: String(chip.getAttribute("data-term-slug") || ""),
                    label: String(chip.getAttribute("data-term-label") || name)
                };
            });

            return map;
        }

        function renderSelected() {
            var ids = Object.keys(selected);

            if (!ids.length) {
                selectedContainer.innerHTML = '<div class="hpt-product-taxonomy-empty">' + escapeHtml(config.i18n && config.i18n.emptyLabel ? config.i18n.emptyLabel : "Chưa chọn term nào.") + "</div>";
            } else {
                selectedContainer.innerHTML = ids.map(function (termId) {
                    return buildChipHtml(selected[termId], hierarchical, taxonomy);
                }).join("");
            }

            if (!hierarchical && flatInput) {
                flatInput.value = ids.map(function (termId) {
                    return selected[termId].name;
                }).join(", ");
            }
        }

        function renderResults(items) {
            if (!Array.isArray(items) || !items.length) {
                resultsContainer.innerHTML = "";
                setStatus(!hierarchical && searchInput.value.trim()
                    ? "Không thấy term phù hợp. Nhấn Enter để thêm tag mới."
                    : (config.i18n && config.i18n.noResults ? config.i18n.noResults : ""));
                return;
            }

            resultsContainer.innerHTML = items.map(function (item) {
                var termId = String(item.id || "");
                var isSelected = hierarchical
                    ? !!selected[termId]
                    : !!findSelectedFlatTermKey(item.name || "");

                return [
                    '<div class="hpt-product-taxonomy-result' + (isSelected ? " is-selected" : "") + '"',
                    ' data-term-id="' + escapeAttr(termId) + '"',
                    ' data-term-name="' + escapeAttr(item.name || "") + '"',
                    ' data-term-slug="' + escapeAttr(item.slug || "") + '"',
                    ' data-term-label="' + escapeAttr(item.label || item.name || "") + '">',
                    '<div class="hpt-product-taxonomy-result__text">',
                    escapeHtml(item.label || item.name || ""),
                    item.slug ? '<span class="hpt-product-taxonomy-result__meta">' + escapeHtml(item.slug) + "</span>" : "",
                    "</div>",
                    '<button type="button" class="button button-secondary hpt-product-taxonomy-result__add"' + (isSelected ? " disabled" : "") + ">" + escapeHtml(config.i18n && config.i18n.addLabel ? config.i18n.addLabel : "Chọn") + "</button>",
                    "</div>"
                ].join("");
            }).join("");

            setStatus("Đã tìm thấy " + items.length + " term.");
        }

        function refreshResultSelectionState() {
            resultsContainer.querySelectorAll(".hpt-product-taxonomy-result").forEach(function (row) {
                var termId = String(row.getAttribute("data-term-id") || "");
                var termName = String(row.getAttribute("data-term-name") || "");
                var isSelected = !!selected[termId];
                var addButton = row.querySelector(".hpt-product-taxonomy-result__add");

                if (!hierarchical) {
                    isSelected = !!findSelectedFlatTermKey(termName);
                }

                row.classList.toggle("is-selected", isSelected);

                if (addButton) {
                    addButton.disabled = isSelected;
                }
            });
        }

        function addFlatTerm(name) {
            var normalizedName = normalizeFlatTermName(name);
            var existingKey = findSelectedFlatTermKey(normalizedName);

            if (!normalizedName || existingKey) {
                return;
            }

            selected["new:" + normalizedName] = {
                id: "new:" + normalizedName,
                name: String(name).trim().replace(/\s+/g, " "),
                slug: "",
                label: String(name).trim().replace(/\s+/g, " ")
            };

            renderSelected();
            refreshResultSelectionState();
        }

        function findSelectedFlatTermKey(name) {
            var normalizedName = normalizeFlatTermName(name);

            if (!normalizedName) {
                return "";
            }

            return Object.keys(selected).find(function (termId) {
                return normalizeFlatTermName(selected[termId] && selected[termId].name) === normalizedName;
            }) || "";
        }

        function normalizeFlatTermName(name) {
            return String(name || "").trim().replace(/\s+/g, " ").toLowerCase();
        }

        function getSearchPrompt() {
            if (!hierarchical) {
                return "Nhập để tìm nhanh, hoặc nhấn Enter để thêm tag mới.";
            }

            return config.i18n && config.i18n.searchPrompt ? config.i18n.searchPrompt : "";
        }

        function setStatus(message) {
            statusContainer.textContent = message || "";
        }
    }

    function searchTerms(taxonomy, keyword, signal) {
        var formData = new FormData();

        formData.append("action", "hpt_product_admin_term_search");
        formData.append("nonce", config.nonce || "");
        formData.append("taxonomy", taxonomy);
        formData.append("q", keyword);

        return fetch(config.ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            body: formData,
            signal: signal || undefined
        }).then(function (response) {
            return response.text().then(function (text) {
                var json = null;

                try {
                    json = JSON.parse(text);
                } catch (error) {
                    throw new Error(config.i18n && config.i18n.searchError ? config.i18n.searchError : "Phản hồi không hợp lệ.");
                }

                if (!response.ok || !json || !json.success) {
                    throw new Error(json && json.data && json.data.message ? json.data.message : (config.i18n && config.i18n.searchError ? config.i18n.searchError : "Không tìm được term."));
                }

                return json.data && Array.isArray(json.data.items) ? json.data.items : [];
            });
        });
    }

    function buildChipHtml(item, hierarchical, taxonomy) {
        return [
            '<div class="hpt-product-taxonomy-chip"',
            ' data-term-id="' + escapeAttr(item.id || "") + '"',
            ' data-term-name="' + escapeAttr(item.name || "") + '"',
            ' data-term-slug="' + escapeAttr(item.slug || "") + '"',
            ' data-term-label="' + escapeAttr(item.label || item.name || "") + '">',
            '<span class="hpt-product-taxonomy-chip__text">' + escapeHtml(item.label || item.name || "") + "</span>",
            '<button type="button" class="button-link-delete hpt-product-taxonomy-chip__remove">' + escapeHtml(config.i18n && config.i18n.removeLabel ? config.i18n.removeLabel : "Bỏ") + "</button>",
            hierarchical ? '<input type="hidden" name="tax_input[' + escapeAttr(taxonomy) + '][]" value="' + escapeAttr(item.id || "") + '">' : "",
            "</div>"
        ].join("");
    }

    function debounce(callback, wait) {
        var timeoutId = 0;

        return function () {
            var args = arguments;
            var context = this;

            window.clearTimeout(timeoutId);
            timeoutId = window.setTimeout(function () {
                callback.apply(context, args);
            }, wait || 0);
        };
    }

    function escapeHtml(value) {
        return String(value || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function escapeAttr(value) {
        return escapeHtml(value);
    }
})();
