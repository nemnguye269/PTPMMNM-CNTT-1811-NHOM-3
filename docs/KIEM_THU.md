# Đối chiếu đề bài – kiểm thử

Chạy: `bash lab.sh test` → kết quả mong đợi `TESTS=25 FAILED=0` và `SMOKE_TESTS=25 FAILED=0`.

## Phép thử bắt buộc (mục 6)
| # | Phép thử | Test | Dữ liệu / đáp án |
|---|---|---|---|
| 1 | Tra cứu sau nửa đêm trả đúng chuyến của ngày hoạt động trước | `P1` | Trạm S013, 01:00 Chủ nhật 25/10/2026 → `R06-0-NIGHT-03`, ngày hoạt động 24/10, giờ `25:10:00`, giờ thật 01:10 ngày 25/10. Sau 01:11 thì không còn. 01:00 ngày 08/11 không có (dịch vụ đêm 07/11 bị hủy). |
| 2 | Áp đúng ngày ngoại lệ | `P2` | 02/11/2026 (thứ Hai) hủy WK, thêm WE; 07/11 hủy NIGHT; 14/11 thêm EVENT (chỉ có trong calendar_dates). |
| 3 | Phát hiện stop_times tham chiếu trạm/chuyến không tồn tại | `P3` | `gtfs_loi.zip`: `BAD_REF_STOP` (S999), `BAD_REF_TRIP` (T_KHONG_CO), `BAD_REF_ROUTE` (R99), `BAD_TIME` (7:5), có tệp + dòng. |
| 4 | Nhập bản lỗi không đổi lịch đang dùng | `P4` + smoke | Bản lỗi không lưu dữ liệu, kích hoạt bị từ chối, ZIP hỏng không tạo phiên bản, kết quả tra cứu y nguyên. |
| 5 | So sánh hai bản báo đúng | `P5` | 4 chuyến mới (R02-*-WK-09/10), 3 chuyến bị xóa (R03-0-WK-08, R03-1-WE-01/02), 3 chuyến đổi giờ (R01-0-WK-01 +3 phút, R04-1-WK-02 +2 phút từ trạm 5, R05-0-WE-03 sửa 1 giờ rời), R05 đổi tên. |

## Hai tình huống (mục 4)
- **TH1** Nhập → kiểm tra → chọn bản → tra cứu: `/versions` → nhập `gtfs_v1.zip` → *Xem lỗi* → *Dùng bản này* → `/next`.
- **TH2** Nhập mới → so sánh → chuyển bản → chạy lại truy vấn mẫu: nhập `gtfs_v2.zip` → `/compare?a=1&b=2` → *Dùng bản này* → `/saved` (chọn bản 1 rồi bản 2; truy vấn "R03 ngày Chủ nhật" ra 8 rồi 6 kết quả).

## Ma trận rubric
| Tiêu chí | Nơi thể hiện |
|---|---|
| 5 chức năng | 1: `Importer`, `Validator` · 2: `Versions`, `/versions`, `/versions/errors` · 3: `Catalog`, `/search`, `/trip` · 4: `Departures`, `/next` · 5: `Diff`, `SavedQueries`, `Export` |
| Kỹ thuật 1 (giờ – ngày hoạt động) | `Departures::activeServices`, `Departures::next`; test P1, P2 |
| Kỹ thuật 2 (mã tham chiếu) | `Validator`, `Versions::integrity`; test P3, P4 |
| Dữ liệu, an toàn | Mọi bảng có `version_id`; chỉ bản `valid` mới kích hoạt được; `Actor` phân quyền; CSRF; chống SQL injection (tham số hóa), XSS (escape), CSV injection |
| Kiểm thử, sửa lỗi | `tests/run.php`, `tests/smoke.sh`, CI `.github/workflows/test.yml` |

## Ca thử bổ sung (nâng cấp)
| Nội dung | Test |
|---|---|
| Giờ `24:00:00` hợp lệ; nhập giờ tra cứu `24:30` được, `48:00` bị từ chối | `Time: 24:00:00 ...` |
| Ngày hủy (loại 2) cho dịch vụ chỉ có trong `calendar_dates`; ngày thêm hiện đúng | `Ngày hủy (loại 2) ...` |
| So sánh bản với chính nó: không khác biệt, kể cả dịch vụ | `So sánh bản với chính nó ...` |
| So sánh bắt đổi thứ chạy, khoảng ngày, ngày ngoại lệ dù giữ nguyên `service_id` | `So sánh bắt thay đổi lịch hoạt động ...` |
| Kích hoạt bản lỗi/không tồn tại bị từ chối và hoàn tác đúng, sau đó kích hoạt bản tốt vẫn được | `Kích hoạt bản lỗi rồi bản tốt ...` |
| Truy vấn mẫu báo rõ mã trạm/tuyến không còn trong bản lịch | `Truy vấn mẫu báo rõ ...` |
| ZIP nhỏ nhập được qua đường đọc theo luồng | `ZIP nhỏ vẫn nhập được ...` |
| Khách không đổi được bản đang dùng (HTTP) | smoke |
| Không có mật khẩu mặc định: đăng nhập bị vô hiệu khi chưa cấu hình | smoke (máy chủ thứ hai) |
