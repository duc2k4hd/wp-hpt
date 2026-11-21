# Vận hành Large Product Export

## Chuẩn bị và bật trên staging

1. Backup code + database; xác nhận đúng staging và chủ sở hữu cho phép triển khai. Đối chiếu version/base/patch; không upload nguyên bản sao local chứa cấu hình hay dữ liệu test lên production.
2. Provision PHP 64-bit >=7.4, MySQL/MariaDB, private local volume ngoài public root. ZIP cần PHP >=8.0. Cấp quyền ghi/chỉ đọc cho đúng OS user; không dùng thư mục world-writable hoặc symlink. Kiểm tra ACL trên Windows. Volume phải dùng chung nếu có nhiều worker.
3. Dự trù staging rows + CSV cuối; ZIP STORE thêm một bản gần bằng CSV. Reserve 64 MiB không phải ước lượng toàn output. Theo dõi free space/inode/I/O; filtered/custom exports chưa có size forecast đáng tin cậy.
4. Sau khi thử đúng dữ liệu và extensions, thêm vào **wp-config của môi trường được phê duyệt**:

```php
define( 'WC_LARGE_PRODUCT_EXPORT', true );
define( 'WC_LARGE_EXPORT_DIR', '/var/lib/woocommerce-private-exports' );
define( 'WC_LARGE_EXPORT_RUNNER', 'cli' );
```

Directory cần được tạo trước. Engine không tự dựng private storage trong uploads. Các define này không được thêm vào wp-config của website gốc trong repository.

## WP-CLI ưu tiên

Ví dụ `OWNER_ID` là tài khoản có cả `edit_products` và `export`:

```bash
wp --user=OWNER_ID wc export-large start --type=products --mode=compatibility --format=json
wp --user=OWNER_ID wc export-large start --mode=keyset --columns=id,sku,name --meta --format=json
wp --user=OWNER_ID wc export-large start --mode=keyset --file-format=zip --format=json
wp --user=OWNER_ID wc export-large status --job-id=JOB_UUID --format=json
wp wc export-large run --job-id=JOB_UUID --seconds=30 --format=json
wp wc export-large run --seconds=30 --format=json
wp --user=OWNER_ID wc export-large pause --job-id=JOB_UUID --format=json
wp --user=OWNER_ID wc export-large resume --job-id=JOB_UUID --format=json
wp --user=OWNER_ID wc export-large cancel --job-id=JOB_UUID --format=json
wp --user=OWNER_ID wc export-large retry --job-id=JOB_UUID --format=json
wp --user=OWNER_ID wc export-large verify --job-id=JOB_UUID --format=json
wp wc export-large cleanup
```

`--no-spreadsheet` giữ giá trị dùng cho import; `--no-bom` bỏ BOM. `--categories` nhận slug, `--types`, `--statuses`, `--ids` và `--columns` là danh sách phân cách bằng dấu phẩy. Selected IDs tối đa 2.000/job; export catalog lớn dùng filter. `--format=json` là format console, khác `--file-format=zip` của artifact.

`run` được coi là lệnh cho OS worker đáng tin cậy; có thể drain due jobs mà không giả lập user frontend. Worker áp dụng user context của từng job và kiểm tra lại capability owner. `status/control/verify` cần `--user` là owner hoặc site admin có `manage_options`; `manage_woocommerce` riêng không đủ để đọc file owner khác. Budget 1–300 giây, mỗi invocation hữu hạn; một query/I/O đang chạy có thể vượt budget. Exit code nonzero cho lỗi/failed job. Operator phải theo dõi `available_at`/backoff, `last_error`, attempts và status. Không dùng vòng `while true` không có supervisor/timeout/log rotation.

System scheduler ví dụ (điều chỉnh executable/path/user cho staging đã kiểm thử):

```cron
* * * * * cd /srv/wordpress && /usr/local/bin/wp wc export-large run --seconds=45 --format=json >> /var/log/wc-export-worker.log 2>&1
17 * * * * cd /srv/wordpress && /usr/local/bin/wp wc export-large cleanup >> /var/log/wc-export-retention.log 2>&1
```

Không cài cron/systemd/Supervisor vào production trong nhiệm vụ local này. Với `RUNNER=cli`, HTTP/Action Scheduler callbacks không chạy export và không tạo successor export actions; người vận hành phải có timer CLI thật, bao gồm retention cleanup. Có thể dùng systemd timer + oneshot với timeout hợp lý và OS user không phải root. Không đặt timeout ngắn hơn một unit đo được rồi kết luận engine không recover.

## Action Scheduler / WP-Cron fallback

`RUNNER=action-scheduler` dùng group `woocommerce-large-product-export`, một action xử lý nhiều row trong cửa sổ ngắn rồi mới tạo successor; không một action/row. Nếu thiếu Action Scheduler sẽ dùng single WP-Cron events. Sweeper theo phút tìm lease stale và eligible jobs, không phụ thuộc tab trình duyệt. `RUNNER=cron` chủ động bỏ qua Action Scheduler. WP-Cron dựa vào traffic hoặc system cron gọi WordPress; không hứa job tự tiến khi không có runner. CLI-only tránh dùng queue runner chung với payment/email.

Queue group riêng không có nghĩa tài nguyên CPU/MySQL được cách ly vật lý. Duy trì một writer/site, throttle mặc định và đo latency website trước khi đổi batch/concurrency. Không nâng `memory_limit`, FPM workers hoặc execution timeout để che query plan xấu.

## Download và server offload

Admin dùng URL có nonce do màn hình tạo; không chia sẻ token hoặc filesystem path. Default PHP stream hỗ trợ single Range/HEAD, nhưng vẫn giữ một FPM worker trong thời gian transfer. Không tăng timeout vô hạn. Với artifact GB, triển khai và kiểm chứng offload trước.

Nginx ví dụ, sau khi administrator xác nhận alias thực sự trỏ đúng private root:

```nginx
location /_wc_exports/ {
    internal;
    alias /var/lib/woocommerce-private-exports/;
    sendfile on;
    max_ranges 1;
}
```

```php
define( 'WC_LARGE_EXPORT_X_ACCEL_PREFIX', '/_wc_exports' );
```

Với Apache/mod_xsendfile, cấu hình `XSendFile On`, allowlist `XSendFilePath` vào private root, rồi bật `WC_LARGE_EXPORT_X_SENDFILE`. Không bật define chỉ vì dùng Apache. Không đưa thư mục thành public alias. X-Accel/X-Sendfile chưa được kiểm thử trên máy local này; cần test chính xác headers/byte ranges/CDN của deployment.

```bash
curl -D headers.txt -b AUTHENTICATED_COOKIE_JAR -o products.csv 'AUTHORIZED_DOWNLOAD_URL'
curl -D range.txt -b AUTHENTICATED_COOKIE_JAR -H 'Range: bytes=1000000-' -H 'If-Range: "EXPECTED_ETAG"' -o tail.bin 'AUTHORIZED_DOWNLOAD_URL'
curl -I -b AUTHENTICATED_COOKIE_JAR 'AUTHORIZED_DOWNLOAD_URL'
```

Kiểm tra 200/206/416, Content-Length/Content-Range/ETag, so byte với artifact gốc, ngắt transfer thật rồi resume, URL hết hạn và user khác. Không lưu cookie/URL nonce trong báo cáo công khai. Test local đã xác minh download endpoint bằng cURL; chưa chứng minh resume file nhiều GB qua proxy production.

## Recovery và bảo trì

Pause chờ unit hiện tại; resume giữ phase/checkpoint. Sau kill, đợi lease 300 giây và sweep/CLI claim lại; file lock được OS nhả khi process chết. Không xóa/mở/append chunk bằng tay, không đổi cursor để vượt qua lỗi. Retry failed job giữ checkpoint, giới hạn attempts được reset theo thao tác operator. Query/schema thay đổi không an toàn thì tạo job mới. Cancel là cleanup có thể tiếp tục, không can thiệp job khác.

Artifacts terminal completed/cancelled/failed mặc định hết hạn sau 7 ngày; cleanup thực hiện theo trang giới hạn, không quét/xóa recursive tùy ý. Có thể đổi `woocommerce_large_export_retention_seconds` trong phạm vi được kiểm thử. Job paused không tự expire. CLI verify cần chạy lại khi nghi ngờ storage bị can thiệp; download không rehash toàn GB ở mỗi request. Bảo vệ quyền ghi volume và backup/monitor DB + private storage như một cặp.

Theo dõi rows/rate/batch/attempts/last_error, active memory, private free space, slow query log và frontend latency. `database_seconds` trong status là thời gian select/hydrate, không phải profiler SQL thuần. Benchmark fixture có SAVEQUERIES được xóa sau mỗi window để đo SQL time mà không tích lũy toàn job; không bật SAVEQUERIES không giới hạn trong production.

Getter variation có thể ghi title: deploy cả patch data store cùng bootstrap/classes. Engine từ chối đọc nếu thiếu capability marker. Extension chưa audit nên chạy trên clone hoặc account worker chỉ SELECT trên bảng sản phẩm nhưng được ghi job store/caches cần thiết; provision quyền là công việc hạ tầng riêng, không tự đổi credential production. Workload nhiều cell MB cần hạ initial/min batch qua filters trước tạo job; adaptive sizing không thể ngắt allocation quá lớn của batch đầu hoặc callback extension không bounded.
