# Sửa nguy cơ hết RAM và chiếm worker export — 21/11/2026

## Bằng chứng và giới hạn kết luận

Log hosting người dùng cung cấp có 521 entries: 368 fatal hết RAM tại `WP_Object_Cache`, 140 lỗi MySQL `Commands out of sync`, 3 warning Array-to-string trong theme, 1 lỗi cron `could_not_set` cho `action_scheduler_run_queue` và 9 thông tin SePay không liên quan export. PHP đã cho phép 1 GiB/request nhưng vẫn không cấp thêm được 2 MiB. Log từ 19–21/11/2026; không có request ID/URL gắn với stack trace để khẳng định mỗi 503 đều cùng nguyên nhân. URL ảnh lỗi là `download_product_csv` của exporter cũ, không phải endpoint tải job nền.

Không sửa WordPress object cache, không tăng RAM/time limit, không sửa credential hoặc deploy hosting. Những sửa dưới đây chặn các đường nạp không giới hạn đã tìm được trong source; chúng không chứng minh rằng mọi plugin/bootstrap request trên hosting đều đã hết lỗi.

## Thay đổi runtime

- Thêm `includes/export/class-wc-product-export-resources.php`: ngân sách request bằng mức nhỏ hơn giữa 256 MiB và 70% PHP memory limit. Chạy query ID giữ nguyên bộ lọc/thứ tự/page trước khi đọc post body, scalar preflight dung lượng mô tả/metadata/taxonomy theo nhóm ID, gồm parent data; sau đó hydrate đúng các ID đã chọn, không lặp lại joins/OFFSET nặng. Giữ tổng phân trang native bằng kết quả FOUND_ROWS của query chọn ID. SQL projection/limit không thể kiểm chứng thì dừng an toàn. Tính cả overhead và hệ số dự phòng 8 lần; đây là ước lượng bảo thủ, không phải giới hạn allocator của PHP hay sandbox extension.
- Product exporter giới hạn 100 sản phẩm chính mỗi batch kể cả batch-limit filter đặt quá lớn; legacy expansion tối đa 500 dòng/batch và legacy selection tối đa 10.000 primary records. Không còn `limit=-1` và không cho catalog million chạy chuỗi AJAX legacy. Vượt giới hạn trả lỗi rõ ràng yêu cầu job nền, không cắt bỏ biến thể rồi báo thành công. Tiến độ dựa trên primary rows thay vì cộng expanded variations. Không prefetch ảnh/relationships của cột không được xuất; liên kết/ảnh nhiều được chia nhóm 100 thay vì nạp cache toàn bộ một lượt. Kiểm tra kích thước row/batch sau row hooks, chừa thêm 4 lần dung lượng cho encoding/escaping/buffer trước khi tạo CSV. Preflight tính cả overhead các meta row có key/value NULL.
- Native legacy export cũng bọc hook read-only của variation trong try/finally: giữ computed name nhưng không UPDATE title khi chỉ đọc. Getter ngoài export vẫn sửa title theo hành vi WooCommerce cũ; custom columns đọc trực tiếp raw post_title phải đối chiếu parity như ở engine nền.
- Compatibility chỉ bỏ predicate product_type đã chứng minh là hằng đúng nếu không có SQL/query extension hooks phụ thuộc cấu trúc query; nếu có hook lạ thì giữ native predicate. Không thay bộ lọc hoặc thứ tự kết quả để đạt tốc độ.
- Job mới mặc định initial 50, min 25, max 100. Keyset gặp preflight memory error giữ nguyên cursor/chunk checkpoint, tính lại batch an toàn từ dung lượng đo được, retry có giới hạn/backoff và ghi `resource_batch_max` để tránh tăng lại tới mức đã lỗi. Compatibility giữ page width, fail có kiểm soát khi không đủ RAM; muốn giảm batch thì tạo job mới để không làm sai OFFSET.
- Standard Export page render giao diện background khi `WC_LARGE_PRODUCT_EXPORT=true`; enqueue đúng asset trên cả trang cũ và trang Large Export. Tab cũ gửi AJAX synchronous sau khi bật cờ nhận JSON 409, yêu cầu tải lại trang. Không tự bật cờ hoặc tự chọn một private directory chưa được cấu hình.
- Khi engine chưa bật, legacy vẫn chạy cho selection nhỏ trong giới hạn. Có notice yêu cầu cấu hình background cho selection lớn, khóa không chờ để không chạy nhiều batch legacy đồng thời, và JSON error có kiểm soát khi không đủ tài nguyên.
- Batch download tháo các output buffer đã tồn tại và tắt compression trước stream. String API `get_file()`/header chỉ cho dữ liệu vừa ngân sách; không còn đọc file cũ vào RAM nếu unlink ở batch đầu thất bại. Native download yêu cầu header hoàn tất, không phục vụ file đang xuất; HEAD không truyền/xóa artifact.
- Không đặt execution time thành vô hạn hoặc bỏ qua disconnect cho CSV download. PHP stream download giới hạn một transfer/storage directory bằng khóa không chờ, trả 429 + Retry-After khi bận. Khóa download riêng với writer; Range/HEAD của engine và server offload được giữ nguyên.
- CSV writer kiểm tra short write/flush/header write, rollback phần append chưa hoàn tất khi có thể và giữ lỗi có kiểm soát; không báo 100% khi không ghi được file.

## Các file runtime sửa trong lượt này

Trong `wp-content/plugins/woocommerce/`:

1. `includes/admin/class-wc-admin-exporters.php`
2. `includes/admin/views/html-admin-page-large-product-export.php`
3. `includes/export/abstract-wc-csv-exporter.php`
4. `includes/export/abstract-wc-csv-batch-exporter.php`
5. `includes/export/class-wc-product-csv-exporter.php`
6. `includes/export/large-export/class-engine.php`
7. `includes/export/large-export/class-files.php`
8. `includes/export/large-export/class-integration.php`
9. `includes/export/large-export/class-reader.php`
10. `includes/export/large-export/class-store.php`

Thêm `includes/export/class-wc-product-export-resources.php`. Lượt bổ sung bản xem Excel sửa thêm template/JS của giao diện xuất WooCommerce và form xuất sản phẩm trong `wp-content/themes/flatsome-child/paths/product-import-export-tools.php`. Không sửa frontend, cart/checkout hoặc WordPress core.

`core-patch/payload/` đồng bộ toàn bộ subsystem WooCommerce liên quan, gồm bootstrap/read-only data store/UI/helper để apply/rollback không để lại một phần engine tham chiếu class đã xóa. `reapply.py --check` kiểm tra đủ 22 payload files, không áp dụng trực tiếp thay đổi lên site khác. File theme triển khai riêng cùng plugin nếu dùng form HPT. Full runtime Git patch trong `docs/patches/woocommerce-10.3.5-large-export.patch` được dựng lại so với base `c8e7a752`; lượt sửa bắt đầu ở `6cd003b2`. Đây là mirror triển khai, không phải plugin/logic export thứ hai.

## Kiểm chứng local

Fixture WordPress 6.8.2/WooCommerce 10.3.5, MariaDB 11.5.2 riêng trên loopback. PHP worker 256 MiB; child download 96 MiB. Scripts QA ở thư mục temp, không thêm vào repository/website production.

| Kiểm tra | Kết quả |
|---|---|
| 550 variations của một parent | Legacy dừng an toàn; background xuất đủ 551 ID duy nhất gồm parent |
| Catalog fixture 1 triệu records qua legacy | Bị từ chối có kiểm soát, yêu cầu background thay vì khởi chạy hàng nghìn request AJAX |
| Metadata 24 MiB | Bị từ chối trước product hydration; không đọc giá trị lớn vào cache |
| Post description 24 MiB | Bị chặn trước SELECT posts.*; không đọc body lớn để kiểm tra dung lượng |
| Taxonomy description 24 MiB | Scalar preflight chặn trước hydration |
| Custom row 24 MiB | Dừng trước encoding CSV, không nhân nhiều bản string trong buffer |
| 100.001 meta rows key/value NULL | Tính đủ overhead, chặn trước cache hydration |
| SQL projection do extension bổ sung fields | Dừng trước hydrate, không âm thầm bỏ custom fields |
| Keyset retry | Không tiến cursor khi batch lỗi; giảm batch theo preflight, giữ trần sau lỗi |
| Bốn records, metadata 4 MiB/record | Hoàn tất sau 1 retry; safe batch giảm còn 2 |
| 600 media/relationship references | Prefetch theo nhóm có giới hạn, không từ chối chỉ vì tổng reference vượt 500 |
| Legacy/compatibility/keyset | 15 dòng, byte-identical; legacy 8 batch, tiến độ không vượt 100% |
| Legacy variation read-only trên PHP 7.4/8.4 | Title/timestamps DB không đổi; CSV dùng computed name; filter được tháo sạch |
| CSV 64 MiB với output buffer không giới hạn đang mở | Tải đúng 67.108.875 byte gồm header, PHP limit 96 MiB, peak 72 MiB; string API bị chặn |
| HTTP thật | Full 200, Range 206 byte-exact, HEAD vẫn 200 khi transfer slot bận; concurrent download 429 ở cả legacy/background; tab AJAX cũ 409 |
| Benchmark source có guard | 10.000 rows / 18,416 giây / 543 rows/s / peak 108 MiB; không thiếu/trùng, checksum bằng selection đã kiểm chứng trước đó |
| Danh sách 20 jobs, mỗi checkpoint 2.173.834 byte/4.000 cột | Đọc từng job, bỏ schema khỏi danh sách; worker vẫn giữ schema đầy đủ. PHP limit 128 MiB, peak 92 MiB |

## Bản xem Excel và tiếng Việt

Yêu cầu làm sạch mới thay thế hành vi giữ nguyên markup/xuống dòng bên dưới: mô tả dài/ngắn luôn được sửa bằng DOM, bỏ BR/class/thuộc tính ngoài A/IMG, bỏ wrapper dư và lồng định dạng trùng; mọi ô CSV được chuyển thành một dòng vật lý. A/IMG giữ các thuộc tính còn lại. Giới hạn 32.767 vẫn áp dụng nếu chọn Excel nhưng cắt text/entity và dự trù đủ thẻ đóng. Config `html_clean_version=1` yêu cầu job mới; file completed cũ vẫn tải được nhưng chưa có bước làm sạch này. PHP DOM được kiểm tra ngay khi tạo job. Evidence mới `html-clean.jsonl` kiểm tra mẫu 178 sản phẩm/15 cột, native và background cùng 178 dòng vật lý, không mất/trùng ID; không nhận đây là kiểm toán toàn bộ file trên hosting.

Đã sửa lại theo yêu cầu giữ toàn bộ HTML: lựa chọn **Giới hạn ô Excel** được chọn sẵn cho lần xuất mới, giữ nguyên thẻ HTML, entities, khoảng trắng và xuống dòng trong ô. Chỉ cắt phần vượt dung lượng ô Excel: 32.767 đơn vị UTF-16, tính cả ký tự bảo vệ công thức nếu có. Không thêm dấu ba chấm, không cắt ở 120 ký tự, không cắt giữa cặp surrogate của emoji. CSV vẫn có UTF-8 BOM, dấu nháy kép chuẩn và bảo vệ công thức. Chế độ CLI dùng `--excel-view` với ý nghĩa mới này.

Bỏ chọn để xuất không giới hạn độ dài cho sao lưu/nhập lại. CSV không lưu độ rộng cột/kiểu clip của Excel; nội dung nhiều dòng trong dấu nháy kép vẫn là một ô của một bản ghi CSV. UTF-8 hợp lệ giữ nguyên; byte UTF-8 hỏng không được mặc định đoán là Latin-1. File cũ đã bị xóa HTML/cắt 120 ký tự phải xuất lại từ database, không thể khôi phục phần đã mất từ CSV. Job đang chạy dùng định dạng cũ bị dừng ngay với hướng dẫn tạo job mới để tránh trộn chunks cũ/mới; job completed vẫn có thể tải file cũ. Phiên bản định dạng lưu trong config của job nền và form xuất HPT.

Kiểm chứng giới hạn mới: HTML/entities/CR/LF/nháy kép giữ nguyên qua native và background CSV; đúng 32.767 ký tự BMP không bị cắt, vượt ngưỡng mới cắt; tiếng Việt/emoji không hỏng tại biên, ký tự bảo vệ công thức nằm trong giới hạn; tắt giới hạn giữ đủ chuỗi 50.000 ký tự. Kiểm tra cũ giới hạn 120 ký tự trong evidence chỉ phản ánh source trước lần sửa này.

Evidence: `docs/evidence/oom-*.json[l]`. SHA-256 10k: `2c1ecee6b0a3cd7ec57d7b6a9bc0140c61e9eb53e8f2a66d3aaa5afcbd7d37ab`. Không nhận số 1 triệu của lượt trước là benchmark source sau hardening. Cú pháp kiểm tra PHP 7.4–8.4, PHPCompatibility 7.4–8.4; PHPCS giữ nguyên các vi phạm baseline về tên abstract class, một Yoda condition và warning có sẵn, không tắt sniff để báo sạch giả.

## Bắt buộc khi đưa lên hosting

Deploy đủ file theo manifest/patch, kiểm tra syntax trên staging tương đương, backup và quyền triển khai. Tạo directory private ngoài `public_html`, cấp quyền cho PHP user; đường dẫn dưới đây là ví dụ theo log hosting và phải thực sự tồn tại/được phép truy cập:

```php
define( 'WC_LARGE_PRODUCT_EXPORT', true );
define( 'WC_LARGE_EXPORT_DIR', '/home/duljqgdz/wc-private-exports' );
define( 'WC_LARGE_EXPORT_RUNNER', 'action-scheduler' );
```

Ưu tiên WP-CLI/system scheduler với `RUNNER=cli` và timer riêng theo `EXPORT_ENGINE_OPERATIONS.md`. Log hosting có lỗi không lưu được cron; nếu dùng Action Scheduler phải sửa/kiểm chứng cron/queue thực sự chạy khi đóng tab, không coi việc tạo job thành công là bằng chứng runner đã chạy. Chọn keyset sau parity với extensions thực tế để có giảm batch tự động mà không sai paging. Không ghi các define này vào wp-config production từ phiên làm việc local.

Với CSV hàng GB hoặc mạng chậm, cấu hình và kiểm chứng X-Accel/X-Sendfile trên webserver: PHP fallback đã giới hạn RAM/concurrency nhưng vẫn giữ một worker trong lúc truyền. Theo dõi access/error/FPM/LSAPI/CloudLinux logs đúng timestamp để xác định giới hạn process/account, DB outage hoặc OOM trước khi exporter kịp chạy. Custom data stores/hooks có thể tự cấp phát không giới hạn; guard native không thể ngăn mọi allocation của code ngoài subsystem.

Tắt cờ sẽ trở lại legacy có giới hạn an toàn, không làm legacy trở lại truy vấn `limit=-1`. Đảo full Git patch phải trên đúng base/checkpoint, dừng worker trước. Source update vẫn có thể ghi đè core fork; cần áp lại patch có kiểm chứng.
