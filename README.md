# Quản lý và tra cứu lịch trình giao thông công cộng (GTFS)

Đề tài 8 – Phát triển phần mềm mã nguồn mở, Khoa CNTT, Đại học Đại Nam.
PHP 8.1+ thuần (không framework, không Composer), SQLite qua PDO, giao diện web, cùng quy ước `lab.sh` với các bài Lab.

Người quản lý nhập bộ GTFS tĩnh, xem lỗi, chọn phiên bản đang dùng. Người tra cứu chọn trạm, ngày, giờ để tìm chuyến tiếp theo.

## Chạy nhanh
```bash
sudo apt install php-cli php-sqlite3 php-zip php-mbstring curl git
bash lab.sh check     # kiểm tra môi trường
bash lab.sh seed      # (tuỳ chọn) CSDL demo: v1 đang dùng, v2, bản lỗi, 3 truy vấn mẫu
bash lab.sh run       # http://127.0.0.1:8108
bash lab.sh test      # TESTS=25 FAILED=0 và SMOKE_TESTS=25 FAILED=0
```
Không có mật khẩu mặc định: đặt `GTFS_ADMIN_PASSWORD=... bash lab.sh run`. Nếu bỏ trống, `lab.sh run` sinh mật khẩu ngẫu nhiên cho lần chạy đó và in ra màn hình; chạy trực tiếp bằng `php -S` mà không đặt biến thì đăng nhập quản lý bị vô hiệu hóa.
Khách chỉ tra cứu; chỉ quản lý được nhập, đổi bản đang dùng, xóa bản.

## Năm chức năng
| # | Chức năng | Trang |
|---|---|---|
| 1 | Nhập GTFS (ZIP), kiểm tra tệp, cột, giá trị, mã tham chiếu | `/versions` |
| 2 | Lưu từng phiên bản, xem lỗi, chỉ bản hợp lệ mới dùng được; nhập lỗi không ghi đè bản đang dùng | `/versions`, `/versions/errors` |
| 3 | Tra cứu tuyến, trạm, chuyến, lần dừng; tìm theo tên (không cần dấu) hoặc mã; kết hợp bộ lọc | `/search`, `/trip` |
| 4 | Chuyến tiếp theo theo ngày hoạt động, ngày thêm/hủy, giờ ≥ 24; báo rõ khi không có chuyến | `/next` |
| 5 | So sánh hai bản theo tuyến/chuyến; lưu truy vấn mẫu; xuất lịch và báo cáo lỗi CSV | `/compare`, `/saved`, `/export/*` |

## Hai yêu cầu kỹ thuật
1. **Giờ gắn với ngày hoạt động** (`src/Departures.php`): thời điểm thật = nửa đêm ngày hoạt động + giờ GTFS. Tra 01:00 ngày D xét cả chuyến của ngày D−1 (giờ 24:xx/25:xx); mỗi ngày hoạt động áp `calendar` rồi `calendar_dates`.
2. **Mã tham chiếu trước khi dùng** (`src/Validator.php`, `Versions::integrity`): kiểm tra trong bộ nhớ trước khi ghi; bản lỗi không lưu dữ liệu; khi kích hoạt kiểm tra lại toàn vẹn trên CSDL. Mọi bảng có `version_id` nên bản mới không đụng bản đang dùng.

## Dữ liệu mẫu
`data/sample/` (tự tạo bằng `tools/make_sample.php`, CC0): `gtfs_v1.zip` (6 tuyến, 45 trạm, 128 chuyến, calendar_dates, giờ `25:10:00`), `gtfs_v2.zip` (+4 / −3 / sửa giờ 3 chuyến), `gtfs_loi.zip` (lỗi tham chiếu cố ý).

## Tài liệu
[Cơ sở dữ liệu](docs/CSDL.md) · [Phạm vi hỗ trợ](docs/PHAM_VI.md) · [Kiểm thử & đối chiếu rubric](docs/KIEM_THU.md) · [Hướng dẫn nộp bài](docs/NOP_BAI.md)

## Cấu trúc
```
src/        Importer, Validator, Departures, Catalog, Diff, Export, SavedQueries, Versions, App ...
public/     index.php (front controller), assets/style.css
tests/      run.php (25 test đơn vị), smoke.sh (25 test tích hợp HTTP)
db/         schema.sql (lược đồ tham chiếu)
tools/      make_sample.php, seed.php
```
Giấy phép: MIT (mã nguồn), CC0 (dữ liệu mẫu).
