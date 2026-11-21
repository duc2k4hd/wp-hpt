# Kết quả kiểm thử Large Export Engine

Hardening sau log hosting: kết quả mới nhất ở `EXPORT_ENGINE_OOM_FIX.md` và `evidence/oom-*`. Các kết quả `final-*` bên dưới là lịch sử của commit trước hardening, không thay cho benchmark/runtime source mới.

Ngày chạy: 21/11/2026. Database và filesystem staging local độc lập; PHP worker giới hạn 256 MiB. Evidence nằm trong `docs/evidence/`; mỗi JSON chỉ được coi là pass nếu script thực sự hoàn tất và các assertion độc lập không ném lỗi.

## Review cuối

Source cuối đã qua syntax matrix PHP 7.4.33–8.4.0 trên 10 PHP runtime files, JavaScript syntax check, WordPress-Core PHPCS và PHPCompatibility 7.4–8.4 (0 errors/warnings). Evidence mới có prefix `final-`; kết quả trước đó vẫn là evidence lịch sử.

Chạy lại tuần tự recovery, dynamic columns, read-only, retention, runtime/large cell, formulas, feature flag, process crash và HTTP download; tất cả exit 0. Contract suite đối chiếu legacy/keyset/compatibility, ZIP parser và importer được chạy lại. Có một lần recovery thử trong khi contract suite đang giữ writer lock nên fixture assertion không hoàn tất; đã chạy lại từng suite tuần tự, không giảm hoặc bỏ writer lock.

Regression mới: page width đổi giữa các trang bị từ chối, CPT/custom store boundary đổi bị từ chối; Node harness xác minh cùng token sau timeout và token mới sau acknowledgement/đổi lựa chọn. Scheduler unit test mock chứng minh CLI không chạm store/cleanup, Cron không bị pending Action Scheduler cũ chặn, và AS pending không bị enqueue lặp bởi sweeper. Đây là kiểm tra lựa chọn backend, chưa phải scheduler hosting end-to-end.

Đọc lại artifact million bằng CSV parser và bitmap IDs: 1.000.000 rows, 149.997 variations, 0 thiếu/trùng, SKU khớp, SHA-256 khớp DB (`final-final-review.jsonl`). Không nhận đây là chạy lại pipeline million sau guard mới. Smoke benchmark 10.000 rows của source cuối nằm ở `final-keyset-10000.jsonl`.

Scripts test đã được dọn khỏi repository sau chạy theo yêu cầu người dùng. Giữ evidence và tài liệu; muốn tái lập đầy đủ suite khi nâng cấp cần phục hồi/recreate test harness trên staging, không deploy fixture vào website.

## Đã chạy

| Phạm vi | Bằng chứng / giới hạn |
|---|---|
| Syntax PHP 7.4.33, 8.0.30, 8.1.31, 8.2.26, 8.3.14, 8.4.x | CLI `php -n -l` trên toàn bộ PHP mới; chạy lại ở final review. Syntax matrix không tự chứng minh runtime parity trên mọi version. |
| PHPCS WordPress-Core | `evidence/phpcs-core.json`; không loại sniff để làm đẹp benchmark. Không nhận đây là toàn bộ WordPress-Extra/WooCommerce CI upstream. |
| PHPCompatibility 7.4–8.4 | `evidence/phpcompatibility.json`, kiểm tra API tĩnh. |
| Legacy regression | Benchmark 1.000/10.000 parse CSV và đối chiếu IDs/SKU/Unicode; exporter cũ vẫn dùng API cũ. |
| Compatibility / keyset / legacy field parity | `export-contract.jsonl`: simple, variable, variation, grouped, external, custom type, downloadable, price/stock/tax/category/tag, attributes/meta/custom columns/images. 9 unique rows, child chọn trực tiếp + parent được deduplicate, future child theo semantics gốc. |
| Import roundtrip | Cùng contract suite import lại một sản phẩm test bằng `WC_Product_CSV_Importer` thật với `parse=true`, update_existing, mapping rõ ràng; kiểm tra SKU Unicode/leading zero và giá. Chưa import lại 1 triệu dòng. |
| Dynamic columns | `export-dynamic-columns.jsonl`: meta phát hiện ở batch sau có trong header cuối, mọi dòng đủ cột, trường cũ giữ đúng vị trí. |
| Recovery/state/lease | `export-recovery.jsonl`: idempotent Start, single lease, pause/resume/cancel, retry giới hạn, orphan chunk replay, CSV multiline/Unicode và corruption rejection. |
| Kill process thật | `export-crash.jsonl`: terminate child PHP ở giữa row/chunk chưa đóng, sau chunk rename trước DB checkpoint, sau CSV append trước finalize checkpoint; phục hồi đủ 15 IDs mỗi case, checksum giống nhau. Process thứ hai bị file lock chặn. Lease của đúng fixture job được làm hết hạn để test không đợi 300 giây. |
| HTTP endpoint thật | `export-http.jsonl`: cURL qua PHP loopback server + WordPress auth cookies/session thật, full 200, byte-exact 206/suffix/resume, 416, HEAD, If-Range mismatch ->200, nonce có chữ ký hết hạn ->403, owner khác ->404, capability bị thu hồi ->403; private no-store. Fixture file nhỏ, không phải file GB qua CDN. |
| WP-CLI thật | `cli-start/pause/status/resume/run/verify.json` từ WP-CLI 2.12.0; command registration hoạt động, job hoàn tất, checksum verify và cleanup exit 0. Không chỉ test bằng WP_CLI mock. |
| Runtime / large cell | `export-runtime.jsonl` và các bản matrix nếu có: sparse-row writer, cell nhiều MB, UTF-8, newline/quote, delimiter khác, no BOM, empty/leading-zero, capacity/path/proof guards và ZIP replay. Chỉ nhận version có evidence thành công. |
| Runtime matrix thật | `runtime-7.4.33/8.0.30/8.1.31/8.2.26/8.3.14/8.4.0.jsonl`: tất cả qua worker 5 records + cell 4.200.000 byte; PHP 7.4 dùng segment validator và không ZIP, PHP 8.x qua strict ZIP64 parser/replay. |
| Không ghi sản phẩm | `export-readonly.jsonl`: variation cố tình stale title; posts title/timestamps và meta trước/sau giống hệt, 0 product DML, CSV dùng computed name, scope tháo sạch, legacy read sau đó vẫn repair bình thường. Benchmark cuối cũng đếm product SQL writes. |
| Retention/retry race | `export-retention.jsonl`: retry thắng trước delete giữ chunk; 257 files cần hai lượt purge, marker purging/purged bền vững, không nghẽn queue. |
| Cờ rollback | `export-flag.jsonl`: tắt bootstrap không load Engine/chạm DB, class legacy vẫn load được. |
| Browser + status HTTP | `browser-ui.json`: Chromium đăng nhập fixture, form/mode/CSV-ZIP/20 rows/real poll/Pause-Cancel/Download render được, không console error sau sửa fixture. Không click mutating controls trong benchmark. `http-status-poll.json` ghi HTTP 200 thực tế. Screenshot bị giới hạn vì desktop window không visible. |
| Benchmark / ID integrity | Chỉ các dòng hoàn tất trong `EXPORT_ENGINE_BENCHMARK.md`; bitmap ID và CSV parser, không dùng newline count. |

## Chưa được kiểm chứng hoặc chỉ giả lập

- Nguyên nhân 503 trên máy nguồn: thiếu access/FPM/proxy/kernel logs đúng request/timestamp.
- Nginx X-Accel, Apache X-Sendfile, reverse proxy/CDN, download nhiều GB, ngắt TCP thật giữa chừng. Đã kiểm tra lắp lại hai Range requests trên fixture nhỏ.
- Physical disk-full, inode exhaustion, filesystem read-only/ACL failure thật, storage mất điện, directory fsync, reboot, NFS hoặc nhiều node. Capacity guard và byte-corruption có test, không đồng nghĩa fault vật lý đã được thử.
- MySQL service chết/mất kết nối/query timeout thật; không dừng MySQL đang phục vụ công việc khác để làm test.
- Action Scheduler/WP-Cron chạy nền end-to-end trên hosting không CLI; CLI được chạy thật. Runner mặc định/fallback có implementation nhưng chưa kiểm chứng mọi scheduler deployment.
- Click Start/Pause/Resume/Cancel trong browser chưa tự động hóa; render/poll đã kiểm tra bằng Chromium, state control đã kiểm tra qua Store/CLI. Chưa có screenshot visual QA.
- Tương thích mọi extension/HPOS-like custom product store, hooks thay cả chuỗi batch, dữ liệu vừa sửa trong suốt snapshot, full cart/checkout load test và frontend p95 latency trong benchmark.
- ZIP64 trên 4 GiB, dataset 5 triệu, import lại toàn bộ million records và benchmark million rows với toàn bộ cột/metadata/image-heavy worst case.

Không đánh dấu các mục này VERIFIED. Xem báo cáo vận hành/rollback và kết quả cuối để quyết định staging acceptance tiếp theo.
