# Benchmark Large Export Engine

Source sau hardening OOM: 10.000 dòng / 18,416 s / 543 rows/s / peak 108 MiB, initial batch 50/max 100, checksum khớp legacy. Xem `evidence/oom-benchmark.jsonl`. Số liệu million bên dưới thuộc source trước guard mới, chưa chạy lại toàn pipeline million sau hardening.

## Phương pháp

Staging local: WordPress 6.8.2, WooCommerce 10.3.5, PHP CLI 8.4.4 x64 với `memory_limit=256M`, MariaDB 11.5.2; prefix `wcstage_`, database `wc_export_stage`, loopback Windows. Source website production không được kết nối để benchmark. Fixture 1.000.000 entities có simple/variable/variation/grouped/external, trạng thái publish/private/draft, tên Việt/Trung/Hàn/Nhật, SKU leading-zero, mô tả HTML có newline và mẫu mô tả lớn mỗi 1.000 record, metadata thưa. Không tuyên bố đây là workload full-image/full-meta mọi dòng.

Benchmark cố định cột `id,sku,type,name,description`; ba mode dùng cùng bộ chọn và dữ liệu. Legacy là exporter có sẵn trong repository base, đã có cursor/streaming patch trước công việc này. Bài keyset gồm đọc/transform, sparse chunks, final CSV alignment và checksum verification; không chỉ đo truy vấn. Start/setup/SQL instrumentation có trong wall time; CSV validation/hash đối chiếu cuối được chạy riêng sau khi worker hoàn tất. SAVEQUERIES chỉ dùng trong fixture và được xóa theo window, không tích lũy mọi query của million-row run.

Không chạy hai benchmark lớn đồng thời. Một số lượt thăm dò nhỏ và regression có chạy trong giai đoạn điều tra; số liệu local là baseline tham khảo, không phải chứng nhận throughput hoặc p95 cho production. Không điều chỉnh `memory_limit`/execution timeout/worker concurrency để che lỗi.

Mỗi CSV parse bằng `fgetcsv()`: so ID với khoảng fixture, bitmap phát hiện trùng, SKU và tên Unicode, field count, variation count và checksum cuối. Không dùng số newline làm số record. Bộ parity chi tiết của các trường khác nằm trong contract suite riêng.

## Kết quả hoàn tất

Các mốc bên dưới lấy từ JSON hoàn tất đã có khi bắt đầu lượt review cuối. Artifact million đã được parse lại và đối chiếu checkpoint/checksum trong lượt review này (`evidence/final-final-review.jsonl`). Đây không phải một lần chạy lại toàn bộ million-row pipeline sau các guard mới. Không suy diễn số liệu từ tốc độ 1.000 dòng.

| Mode | Record | Thời gian (s) | Record/s | Peak PHP MiB | Trùng / thiếu / lỗi |
|---|---:|---:|---:|---:|---|
| Legacy | 1.000 | 51,526 | 19,4 | 74 | 0 / 0 / 0 |
| Legacy | 10.000 | 520,211 | 19,2 | 74 | 0 / 0 / 0 |
| Keyset sau sửa prefetch | 10.000 | 11,227 | 890,7 | 132 | 0 / 0 / 0 |
| Keyset | 100.000 | 145,586 | 686,9 | 132 | 0 / 0 / 0 |
| Keyset | 500.000 | 682,256 | 732,9 | 132 | 0 / 0 / 0 |
| Keyset | 1.000.000 | 1.213,259 | 824,2 | 132 | 0 / 0 / 0 |

Million: 149.997 variations, output 107.830.848 byte, 25.805 queries / 129,790 giây SQL, 0 product writes, 0 retries. SHA-256: `82b97ae36f37338624d0ae8bc0fffa020649c2dc28b6cda86e0c5d1a06ca3664`. Evidence: `keyset-100000.jsonl`, `keyset-500000.jsonl`, `keyset-1000000.jsonl`. Throughput này áp dụng cho bộ 5 cột đã mô tả, không phải mọi workload.

Smoke benchmark của source cuối nằm riêng trong `evidence/final-keyset-10000.jsonl`; không thay thế kết quả million của lượt trước. Guard mới không đổi dữ liệu CSV trên đường chạy ổn định; parity, recovery và integrity được chạy lại.

SHA-256 1.000 dòng: `0efda0f719055707769868429f9d63575207984f2aadd992de86d5c45f7b81d1`.

SHA-256 10.000 dòng: `2c1ecee6b0a3cd7ec57d7b6a9bc0140c61e9eb53e8f2a66d3aaa5afcbd7d37ab` — legacy và keyset byte-identical trên selection này.

Evidence JSON chứa query count/time, CPU user/system, average/max unit time, p95 của **128 unit gần nhất**, peak process/worker memory, output size, attempts và unique-ID integrity. P95 recent window không được nhận là p95 của toàn bộ million-row population. `selection_seconds` gồm hydrate/CPU; `database_query_seconds` mới là tổng thời gian SQL được profiler ghi. Pipeline bytes/s không phải đo bandwidth vật lý của ổ đĩa. Chưa đo FPM/frontend latency hoặc host-wide CPU utilization.

## Các thử nghiệm trước khi chốt code

- Legacy 100k bị dừng chủ động sau baseline 1k/10k do kế hoạch taxonomy materialization ~5 s/batch. Không có kết quả legacy 100k/500k/1M; không ghi speedup ở các quy mô đó.
- Keyset trước khi giới hạn metadata-prefetch: 10k mất 62,978 s, 100k mất 190,162 s; có unit ~52–54 s. Một lượt 500k bị dừng để điều tra. Đây không phải acceptance của code đã sửa prefetch; không dùng thay số liệu cuối.
- `EXPLAIN` chứng minh hai metadata queries IN lớn quét ~5 triệu hàng. Mẫu 2.000 rows trước: 54,621 s; sau prefetch theo nhóm 500 + cache priming trước hydration: 0,939 s. Không đưa FORCE INDEX/STRAIGHT_JOIN hoặc optimizer switch vào runtime.
- `ANALYZE TABLE` chỉ chạy trên fixture, có evidence; không giải quyết được materialized taxonomy path. Không thêm index hoặc thay cấu hình MySQL toàn server. Nếu cần so sánh thống kê khác trên staging thực tế, chạy lại cả legacy và keyset cùng điều kiện.

## Acceptance còn mở

Chỉ nhận mốc million khi có output JSON hoàn tất, CSV parse/bitmap đầy đủ, checksum và tài nguyên thật. Ngay cả khi million rows qua bộ cột đang đo, vẫn phải đo lại dữ liệu thật/all columns/extensions, cart/checkout latency, queue fallback và server offload trước production. 5 triệu và ZIP trên 4 GiB chưa chạy.
