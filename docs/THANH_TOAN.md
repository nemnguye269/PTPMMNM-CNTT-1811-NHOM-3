# Mua vé và thanh toán bằng mã QR

## Ai được làm gì
| Vai trò | Mua vé | Xem đơn |
|---|---|---|
| Khách (chưa đăng nhập) | Không (bị chuyển sang trang đăng nhập) | Không |
| Người dùng | **Có** (`/tickets`) | Đơn của chính mình (`/tickets/mine`) |
| Quản lý | **Không** (menu không hiện, vào thẳng URL cũng bị chặn) | Toàn bộ đơn + doanh thu (`/tickets/manage`), xác nhận tay khi cần |

## Luồng thanh toán
1. Người dùng chọn vé → hệ thống tạo đơn `GTFSxxxxxxxx` (giữ 15 phút).
2. Trang thanh toán hiện **mã VietQR** (ảnh từ `img.vietqr.io`) với đúng số tiền và nội dung chuyển khoản = mã đơn.
3. Người dùng quét bằng app ngân hàng bất kỳ và chuyển khoản.
4. Hệ thống đánh dấu **đã thanh toán** khi nhận được webhook báo có tiền (tự động) hoặc khi quản lý bấm xác nhận.
5. Trang thanh toán tự kiểm tra mỗi 3 giây và chuyển sang vé điện tử khi đã nhận tiền.

## Cấu hình nhận tiền thật
Sửa `config/payment.php` (hoặc đặt biến môi trường `GTFS_BANK_BIN`, `GTFS_BANK_NAME`, `GTFS_BANK_ACCOUNT`, `GTFS_BANK_HOLDER`, `GTFS_WEBHOOK_KEY`):

```php
return [
    'bank_bin'     => '970422',          // MB Bank
    'bank_name'    => 'MB Bank',
    'account_no'   => '0123456789',
    'account_name' => 'NGUYEN VAN A',
    'webhook_key'  => 'chuoi-bi-mat-dai-ngau-nhien',
];
```
Tiền khách chuyển đi thẳng vào tài khoản này; hệ thống không giữ tiền hộ.

## Tự động xác nhận (webhook)
Ngân hàng không tự báo cho website, nên cần một dịch vụ báo biến động số dư (ví dụ SePay, Casso) gọi về:

```
POST  <địa-chỉ-website>/api/payment/webhook
Authorization: Apikey <webhook_key>
Content-Type: application/json
{"transferType":"in","transferAmount":8000,"content":"... GTFS1A2B3C4D ...","id":123}
```
- Định dạng này khớp với SePay. Đơn chỉ được xác nhận khi **đúng mã đơn** trong nội dung và **số tiền ≥ tổng tiền**.
- Website chạy ở `localhost` thì dịch vụ ngoài không gọi tới được; cần tên miền công khai hoặc đường hầm (ngrok, Cloudflare Tunnel).
- Khi chưa có webhook, quản lý vào **Đơn vé** và bấm "Xác nhận đã nhận tiền" sau khi kiểm tra tiền về.

## Chế độ chưa cấu hình
Nếu `bank_bin`/`account_no` để trống, trang thanh toán không hiện QR và có nút **Mô phỏng thanh toán** để thử luồng. Nút này tự biến mất khi đã cấu hình tài khoản thật.
