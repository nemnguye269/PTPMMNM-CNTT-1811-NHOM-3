# Phạm vi GTFS được hỗ trợ

Chỉ **GTFS tĩnh**. Không có GPS/GTFS-realtime. Hành trình có một lần chuyển tuyến là phần mở rộng, chưa làm.

| Tệp | Mức | Cột bắt buộc | Cột tùy chọn được đọc |
|---|---|---|---|
| `stops.txt` | bắt buộc | stop_id, stop_name | stop_lat, stop_lon |
| `routes.txt` | bắt buộc | route_id, route_type (+ short hoặc long name) | route_short_name, route_long_name, agency_id |
| `trips.txt` | bắt buộc | route_id, service_id, trip_id | trip_headsign, direction_id |
| `stop_times.txt` | bắt buộc | trip_id, arrival_time, departure_time, stop_id, stop_sequence | – |
| `calendar.txt` | cần ít nhất một trong hai tệp lịch | service_id, monday…sunday, start_date, end_date | – |
| `calendar_dates.txt` | (như trên) | service_id, date, exception_type (1 thêm, 2 hủy) | – |
| `agency.txt` | tùy chọn | agency_name, agency_url, agency_timezone | agency_id |

Tệp khác (shapes, frequencies, transfers, fare…) bị **bỏ qua** và báo cảnh báo `UNSUPPORTED_FILE`.

## Các kiểm tra khi nhập
- Thiếu tệp / thiếu cột / cột lặp / dòng sai số cột / tệp rỗng.
- Giá trị: trùng mã, ngày `YYYYMMDD` hợp lệ, `start_date ≤ end_date`, ngày trong tuần 0/1, `exception_type` 1/2, tọa độ, `route_type` là số.
- **Mã tham chiếu**: `trips.route_id → routes`, `trips.service_id → calendar/calendar_dates`, `stop_times.trip_id → trips`, `stop_times.stop_id → stops`.
- Giờ `H:MM:SS` với giờ 0–71 (cho phép ≥ 24); thứ tự giờ không giảm theo `stop_sequence`; `stop_sequence` không trùng.
- Cảnh báo (không chặn): chuyến chỉ có 1 lần dừng, chuyến không có lần dừng, tệp ngoài phạm vi.

## Quy ước và giới hạn
- Giờ GTFS gắn với **ngày hoạt động**: thời điểm thật = 00:00 ngày hoạt động + giờ. Tra cứu xét lùi đủ số ngày để bắt giờ 24:xx, 25:xx.
- Tính theo UTC-naive, không xử lý dịch giờ mùa hè (Việt Nam không dùng).
- Lần dừng bỏ trống **cả** giờ đến và giờ rời là lỗi (không nội suy). Nếu chỉ có một trong hai, giá trị kia lấy bằng nó.
- Chuyến tại **trạm cuối** không được tính là chuyến để lên xe.
- Không hỗ trợ `frequencies.txt`, `pickup_type`, `drop_off_type`, `timepoint`.
- Bản có lỗi chỉ lưu danh sách lỗi, **không lưu dữ liệu lịch**.
- So sánh hai bản: khóa chuyến là `trip_id`, khóa lần dừng là `stop_sequence`; nếu bản mới đánh số lại `stop_sequence` thì sẽ báo nhiều thay đổi lần dừng. Ngoài chuyến và giờ, so sánh còn báo **dịch vụ đổi lịch** (thứ chạy, khoảng ngày, ngày ngoại lệ) theo `service_id`. Diff nạp `stop_times` của hai bản vào bộ nhớ nên phù hợp bộ lịch cỡ vừa.
- Nhập ZIP: giới hạn kích thước được kiểm tra theo kích thước khai báo và lại theo số byte thực đọc.
- Đổi bản đang dùng và xóa bản chạy trong giao dịch `BEGIN IMMEDIATE`: kiểm tra và ghi không bị xen ngang.
- Truy vấn mẫu không gắn với một bản lịch; khi chạy trên bản không có mã trạm/tuyến tương ứng sẽ báo rõ thay vì trả kết quả rỗng.
- CSDL: SQLite qua PDO để chạy không cần cài đặt; lược đồ dùng SQL chuẩn, có thể chuyển sang PostgreSQL bằng cách thay lớp `Db`.
