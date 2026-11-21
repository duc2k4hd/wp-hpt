# Bàn giao Large Product Export — 21/11/2026

**Cập nhật sau log hosting:** xem `EXPORT_ENGINE_OOM_FIX.md` và evidence `oom-*` cho source mới nhất. Bản hardening bổ sung resource preflight, batch 50/25/100, bảo vệ legacy/download và routing từ trang Export cũ; thay thế các mô tả batch/file count và benchmark source cuối ở bản bàn giao dưới đây.

## 1. Root Cause

Source legacy còn phụ thuộc chuỗi AJAX từ browser, OFFSET fallback, mở rộng toàn variations của từng parent, append không xác minh partial write và API có thể đọc cả file. Profiling staging chứng minh taxonomy materialization và metadata-prefetch IN quá lớn gây query chậm; variation getter còn UPDATE title. Không có log request 503 từ hosting nên chưa xác nhận nguyên nhân response 503 thực tế. Xem `EXPORT_ENGINE_AUDIT.md`.

## 2. Implemented Architecture

HTTP tạo durable job; CLI/Action Scheduler/Cron xử lý bounded units; native WooCommerce query/CRUD transformer → atomic sparse chunks → checkpoint/manifest → căn schema cuối → streaming CSV/ZIP64 → kiểm chứng checksum → download có phân quyền/Range. Một writer/site, lease/retry/backoff, pause/resume/cancel/retention. Compatibility mặc định, keyset opt-in, best-effort live watermark. Không có snapshot transaction kéo dài. Chi tiết: `EXPORT_ENGINE_ARCHITECTURE.md`.

Review cuối bổ sung guard page width/data store, sửa chọn queue của sweeper, tắt sweep callback cũ trong CLI mode và giữ Start request token khi retry sau timeout.

## 3. Files Modified

Hai core files so với base:

- `wp-content/plugins/woocommerce/woocommerce.php`: bootstrap sau feature flag.
- `wp-content/plugins/woocommerce/includes/data-stores/class-wc-product-variation-data-store-cpt.php`: hook/marker cho read-only hydration trong worker.

## 4. Files Added

Bảy class trong `wp-content/plugins/woocommerce/includes/export/large-export/`: `class-bootstrap.php`, `class-command.php`, `class-engine.php`, `class-files.php`, `class-integration.php`, `class-reader.php`, `class-store.php`.

UI: `includes/admin/views/html-admin-page-large-product-export.php` và `assets/js/admin/wc-large-product-export.js` trong plugin. Báo cáo, evidence và patch ở `docs/`. Không thêm runtime dependency.

Toàn bộ scripts test trong `tests/` được dọn sau khi chạy theo yêu cầu; stderr rỗng được bỏ. Giữ bằng chứng kiểm thử/benchmark có nội dung và tài liệu vận hành. Các tiện ích/core-patch đã được Git theo dõi từ trước không bị xóa vì chưa có bằng chứng chúng không còn được dùng.

## 5. Database Changes

Lazily tạo `{prefix}wc_large_export_jobs` schema version 1, index owner/queue/retention và request key unique; option version không autoload. Không migration sản phẩm/index posts, không ghi dữ liệu sản phẩm trong engine. Chỉ database staging độc lập được dùng cho test.

## 6. Performance Results

| Workload | Thời gian | Tốc độ | Peak PHP |
|---|---:|---:|---:|
| Legacy 10.000, evidence trước review | 520,211 s | 19,2 rows/s | 74 MiB |
| Keyset 10.000, source cuối chạy lại | 13,551 s | 738 rows/s | 128 MiB |
| Keyset 1.000.000, evidence trước review | 1.213,259 s | 824,2 rows/s | 132 MiB |

Cùng selection 5 cột; 10k legacy/keyset có checksum byte-identical. Million output 107.830.848 byte, 149.997 variations, 0 product writes/retries. Không chạy lại toàn pipeline million sau các guard cuối; đã đọc lại artifact million/checkpoint độc lập. Không nhận đây là benchmark full metadata/images hoặc chứng nhận production. Xem `EXPORT_ENGINE_BENCHMARK.md`.

## 7. Data Integrity Results

Source cuối qua contract legacy/compatibility/keyset, simple/variable/variation/grouped/external/custom/downloadable, metadata/taxonomy/attributes/images/custom columns, ZIP parser và một importer roundtrip. CSV Unicode/newline/quote/leading-zero, ô 4.200.000 byte, late schema và formula modes đều qua regression.

Artifact million đã được parse lại bằng CSV parser + bitmap: 1.000.000 ID duy nhất, không thiếu/trùng, SKU/width khớp. SHA-256: `82b97ae36f37338624d0ae8bc0fffa020649c2dc28b6cda86e0c5d1a06ca3664`. Evidence mới prefix `final-` trong `docs/evidence/`.

## 8. Failure Recovery Results

Process kill thật ở giữa row, sau chunk rename và sau CSV append trước DB checkpoint: mỗi case phục hồi đủ 15 ID, checksum giống nhau, writer thứ hai bị chặn. Bounded retry/lease, pause/resume/cancel, orphan replay, corrupt proof và retention/retry race đều qua. Test Start timeout và queue-selection là harness mock, không phải browser/scheduler hosting end-to-end.

## 9. Security Review

Private storage ngoài webroot/uploads, canonical path, chống traversal/symlink, owner/capability/nonce, chỉ completed+verified artifact được tải, no-store, Range/HEAD/If-Range. HTTP loopback thật qua 200/206/416/403/404, ghép resume byte-exact. Scope variation read-only được tháo trong finally; legacy vẫn sửa title theo hành vi cũ.

## 10. Remaining Risks

Chưa có logs 503 hosting, server offload/CDN thật, ZIP >4 GiB, physical disk-full/power loss/NFS/multinode, DB outage, scheduler hosting end-to-end, extensions thực tế hoặc frontend/cart/checkout load test. Chưa benchmark lại toàn million pipeline trên source sau guard; chưa đo 5–10 triệu hoặc full-column worst case. Snapshot chỉ best-effort live; compatibility OFFSET có thể chịu tác động khi dữ liệu đang thay đổi. Chưa có size forecast tổng quát cho custom exports. Xem giới hạn đầy đủ trong `EXPORT_ENGINE_TEST_RESULTS.md`.

## 11. Deployment & Rollback

Nhánh `wc-export-engine-finalize-20261121`, base `c8e7a752` / WooCommerce 10.3.5. Patch runtime: `docs/patches/woocommerce-10.3.5-large-export.patch`. Bật trên staging bằng `WC_LARGE_PRODUCT_EXPORT=true`, cấu hình private `WC_LARGE_EXPORT_DIR`, chọn `WC_LARGE_EXPORT_RUNNER` và timer thật. Không tự bật cờ trong wp-config của repository, không deploy production.

Rollback ưu tiên tắt feature flag sau khi dừng worker riêng; giữ job/files để điều tra. Core update có thể ghi đè subsystem/hook: duy trì fork/patch và kiểm tra trên version mới. Quy trình chi tiết trong `EXPORT_ENGINE_OPERATIONS.md` và `EXPORT_ENGINE_ROLLBACK.md`.

## 12. Final Status

**IMPLEMENTED BUT NOT FULLY BENCHMARKED** cho nghiệm thu trên hosting thực tế. Local functional/security/recovery suites, syntax PHP 7.4–8.4, WordPress-Core PHPCS và PHPCompatibility đã qua; benchmark source cuối 10k và xác minh lại artifact million đã hoàn tất. Không nhận toàn bộ deployment production hoặc mọi mục chưa đo là VERIFIED.
