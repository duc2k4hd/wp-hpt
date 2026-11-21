# Triển khai, cập nhật và rollback

## Điều kiện triển khai

Chỉ xem xét production sau khi có staging tương đương, backup code/database/private files, quyền deploy rõ ràng, parity với extensions thực tế, benchmark workload thực tế và xác nhận runner/offload/retention. Môi trường hiện tại là bản sao local; không có production deployment trong công việc này.

Base patch là WooCommerce 10.3.5 tại Git `c8e7a752`. Nhánh tiếp tục: `wc-export-engine-hardening-20261121`. Bản sao trước lượt sửa được lưu ngoài repository trong thư mục temp `opencode/wc-export-continuation-20261121-125745`; có thêm bản sao từ lượt làm trước. Các bản sao local này không thay thế backup production.

Review/bàn giao cuối: nhánh `wc-export-engine-finalize-20261121`; snapshot runtime trước lượt review tại `%TEMP%/wc-export-review-20261121-154716`. Patch chứa toàn bộ 11 runtime files so với base, không chứa tests/evidence/configuration. Sau khi hoàn tất, patch phải qua cả apply check trên base và reverse check trên workspace hiện tại.

1. Review `git diff` và danh sách file trong `EXPORT_ENGINE_CHANGES.md`; kiểm tra patch không chứa credentials/config test hoặc dữ liệu sản phẩm.
2. Kiểm tra `git apply --check docs/patches/woocommerce-10.3.5-large-export.patch` trên checkout đúng base, chưa có bản vá. Đừng áp dụng lần hai vào workspace đã có thay đổi.
3. Deploy code khi feature flag vẫn tắt. Chạy syntax/coding-standard/parity/security tests trên staging; provision storage/timer và kiểm tra rollback trước khi bật cờ.
4. Bật cờ cho canary staging, chạy smoke 1.000 và workload thực tế, kiểm tra website/cart/checkout cùng DB/FPM metrics. Chỉ tăng quy mô sau khi đo.
5. Hạ tầng mới phải chạy lại download Range và HTTP offload. Không suy luận rằng local loopback test tương đương Nginx/Apache/CDN.

## Rollback mềm ưu tiên

1. Dừng tạo job mới; pause/cancel jobs cần thiết bằng UI/CLI và chờ writer hoàn tất unit. Nếu runner treo, xử lý đúng PID của worker đã nhận diện; không kill chung PHP/MySQL.
2. Dừng timer CLI riêng hoặc vô hiệu hóa lịch export riêng, không xóa queue payment/email của WooCommerce. Đợi private writer lock nhả và lease ổn định.
3. Đặt `WC_LARGE_PRODUCT_EXPORT` thành `false` trong cấu hình môi trường được phép. Standard exporter cũ trở lại làm luồng đang dùng; không có dữ liệu sản phẩm cần rollback.
4. Giữ nguyên bảng job và private storage để điều tra/khôi phục. Khi cờ tắt, callbacks export không đăng ký; các scheduled export actions cũ cần được quản lý theo group riêng khi bảo trì, không xóa mọi Action Scheduler actions.

## Rollback code

Sau khi dừng worker và backup diff hiện tại, trên fork/deployment artifact đúng bản vá:

```bash
git apply --reverse --check docs/patches/woocommerce-10.3.5-large-export.patch
git apply --reverse docs/patches/woocommerce-10.3.5-large-export.patch
```

Nếu check không qua, dừng và review thay đổi giao nhau; không `reset --hard`, không chép đè file có sửa của người khác. Patch đảo chỉ gỡ bootstrap, hook/capability marker read-only trong variation data store và các file mà patch thêm. Không DROP bảng job, không xóa sản phẩm, không recursive delete private root. Muốn purge dữ liệu job cần quy trình riêng có backup và owner approval. Đổi cờ off thường đủ để rollback hành vi.

## Nâng cấp WooCommerce

Duy trì custom fork hoặc patch artifact đã review. Khi đổi version, bắt đầu từ code WooCommerce mới, trace lại exporter/data store/row hooks, rà callback allowlist, import format, query marker và Action Scheduler API. Patch chỉ được áp sau review conflict; chạy lại PHP matrix, field parity, crash recovery và benchmark lớn. Giữ legacy sau flag cho giai đoạn chuyển tiếp. Các tối ưu dùng protected/internal exporter methods và cấu trúc tax query là điểm nhạy cảm với update dù public API không đổi.
