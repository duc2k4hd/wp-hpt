# Thay đổi Large Export Engine

Sửa giới hạn ô Excel theo yêu cầu giữ HTML: bỏ việc strip tags, decode entities, gộp dòng và cắt 120 ký tự. Nội dung chỉ cắt khi vượt 32.767 đơn vị UTF-16 của ô Excel; giữ nguyên tiền tố nội dung, không thêm dấu ba chấm, không cắt giữa emoji. Native/background/HPT dùng cùng formatter; các form và trợ giúp CLI đã sửa mô tả. Config mới có `excel_cell_version=2`; job cũ dùng chế độ cắt 120 bị dừng ngay nếu tiếp tục chạy, tránh trộn các chunk khác định dạng. File đã xuất bị cắt phải tạo lần xuất mới từ database.

Lượt sửa 22/11/2026: URL download trong JSON dùng `add_query_arg` với nonce thô thay cho `wp_nonce_url` (hàm sau escape HTML thành `&amp;`, sai khi jQuery gán vào href). Giữ nguyên kiểm tra nonce/quyền và file của job completed; chỉ cần tải lại trang để nhận link mới, không cần xuất lại. Bỏ ước tính tổng từ `information_schema.TABLE_ROWS` vì gồm ảnh/revision/post types khác; checkpoint cũ cũng không hiển thị số này như tổng sản phẩm. Xóa lỗi hiện tại khi commit batch thành công, không hiển thị lỗi đã phục hồi dưới job completed; lịch sử lỗi vẫn giữ trong worker log. Đối chiếu ID chọn ở SQL preflight với ID hydrate: trả thiếu sẽ dừng và giữ checkpoint, không được coi là EOF hay âm thầm bỏ sản phẩm. Kiểm chứng phục hồi sau lỗi bộ nhớ giữ đủ ID theo thứ tự; legacy/compatibility/keyset vẫn byte-identical.

Lượt sửa OOM sau commit `6cd003b2`: chi tiết trong `EXPORT_ENGINE_OOM_FIX.md`. Có thêm sửa exporter/admin legacy, lớp `WC_Product_Export_Resources`, giới hạn download và đồng bộ đầy đủ `core-patch/payload/`. Các mô tả "không sửa legacy" bên dưới chỉ áp dụng lượt trước.

Base: Git `c8e7a752` (đầy đủ commit hash trong evidence/version report), WordPress 6.8.2, WooCommerce 10.3.5, PHP tối thiểu 7.4. Công việc tiếp tục từ các file chưa commit trên nhánh `wc-export-engine-complete-20261121`; đã sao lưu trước sửa và tạo nhánh `wc-export-engine-hardening-20261121`. Không revert/commit đè thay đổi trước đó.

## Core file đã sửa

- `wp-content/plugins/woocommerce/woocommerce.php`: bootstrap 7 dòng, chỉ chạy nếu `WC_LARGE_PRODUCT_EXPORT === true`.
- `wp-content/plugins/woocommerce/includes/data-stores/class-wc-product-variation-data-store-cpt.php`: capability marker và hook `woocommerce_product_variation_update_title_on_read` (mặc định `true`). Worker tạm trả `false` để không ghi sửa title khi hydrate, nhưng vẫn giữ tên tính toán trong CSV; hành vi đọc ngoài worker không đổi. Engine fail closed nếu thiếu bản vá read-only này.

Không sửa thêm các exporter legacy, importer, theme, permalink, frontend, cart hoặc checkout trong lượt triển khai này. Những tối ưu legacy có sẵn ở base không được nhận là thay đổi mới.

## Runtime files thêm

Trong `wp-content/plugins/woocommerce/includes/export/large-export/`:

- `class-bootstrap.php`
- `class-store.php`
- `class-engine.php`
- `class-reader.php`
- `class-files.php`
- `class-integration.php`
- `class-command.php`

UI:

- `wp-content/plugins/woocommerce/includes/admin/views/html-admin-page-large-product-export.php`
- `wp-content/plugins/woocommerce/assets/js/admin/wc-large-product-export.js`

Không chỉnh tay asset minified; UI mới dùng source JavaScript riêng, enqueue trực tiếp, dependency jQuery. Không thêm dependency runtime/Composer vào plugin.

## Database và cờ

Tạo lazily bảng `{prefix}wc_large_export_jobs` bằng `dbDelta()` có version, primary UUID, unique request key, indexes owner/queue/retention. Option `woocommerce_large_export_schema_version` là một số version, không chứa dataset và không autoload. Không ALTER bảng sản phẩm hoặc thêm index vào posts/postmeta, không migration phá hủy dữ liệu. Sản phẩm chỉ được đọc trong engine. Fixtures test có tạo/sửa/xóa dữ liệu trong database local riêng.

Các define opt-in: `WC_LARGE_PRODUCT_EXPORT`, `WC_LARGE_EXPORT_DIR`, `WC_LARGE_EXPORT_RUNNER` (`cli`, `action-scheduler`, `cron`), tùy chọn `WC_LARGE_EXPORT_X_ACCEL_PREFIX`, `WC_LARGE_EXPORT_X_SENDFILE`. Tắt cờ đưa website về luồng legacy; không tự xóa job/artifact.

## Tests và tài liệu

Review cuối trên nhánh `wc-export-engine-finalize-20261121`: thêm guard cố định page width cho compatibility, kiểm tra lại CPT/custom store từng batch, tránh để lại `split_the_query` callback nếu query chuẩn bị lỗi, dùng đúng queue khi sweep và bỏ callback sweep cũ khi chọn CLI. UI giữ request token sau timeout để retry Start không tạo job mới. Không đổi schema DB hoặc format CSV.

Theo yêu cầu bàn giao mới nhất, toàn bộ scripts trong thư mục `tests/` được xóa **sau khi chạy kiểm thử**, cùng stderr rỗng; giữ báo cáo/evidence kết quả và patch runtime để review/rollback. Danh sách scripts bên dưới là lịch sử các kiểm thử đã thực hiện, không phải dependency cần deploy. File đã có từ trước ngoài phạm vi export mới không bị xóa chỉ vì tên có chữ test/export.

Scripts trong `tests/`: `export-stage.php`, `export-sync-stage.ps1`, `export-seed.php`, `export-analyze.php`, `export-environment.php`, `export-benchmark.php`, `export-profile.php`, `export-recovery.php`, `export-dynamic-columns.php`, `export-contract.php`, `export-crash.php`, `export-crash-worker.php`, `export-http.php`, `export-runtime.php`. Các file evidence là output thực tế, không phải fixture kết quả giả.

Bổ sung regression scripts: `export-status-http.php`, `export-readonly.php`, `export-retention.php`, `export-flag.php`. Việc sửa variation data store là bắt buộc vì getter WooCommerce gốc có side effect UPDATE title, không phải thay đổi chức năng sản phẩm không liên quan.

Bảy báo cáo: `EXPORT_ENGINE_AUDIT.md`, `EXPORT_ENGINE_ARCHITECTURE.md`, `EXPORT_ENGINE_CHANGES.md`, `EXPORT_ENGINE_BENCHMARK.md`, `EXPORT_ENGINE_TEST_RESULTS.md`, `EXPORT_ENGINE_OPERATIONS.md`, `EXPORT_ENGINE_ROLLBACK.md`. Patch core được tạo trong `docs/patches/` khi hoàn tất review; không chứa cấu hình staging hoặc credentials.

## Rủi ro update

Các file trong thư mục plugin bị WooCommerce update ghi đè/xóa. Duy trì fork hoặc apply patch vào đúng base trong pipeline, chạy `git apply --check`, review lại internal APIs/query structure/hook allowlist trên version mới, chạy parity/regression/benchmark trên staging rồi mới deploy. Không áp dụng mù patch 10.3.5 lên WooCommerce khác version. Không vô hiệu hóa cập nhật bảo mật; cập nhật fork có kiểm chứng thay cho cập nhật ghi đè không kiểm soát.
