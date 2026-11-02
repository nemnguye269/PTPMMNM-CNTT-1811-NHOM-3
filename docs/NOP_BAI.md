# Hướng dẫn nộp bài (phần nhóm phải tự làm)

Mã nguồn, dữ liệu và test đã sẵn sàng. Các mục sau cần **hoạt động thật của nhóm trên GitHub**, không thể tạo sẵn:

1. **Đóng góp cá nhân (1,0 điểm)**: mỗi thành viên commit phần của mình bằng tài khoản riêng. Điền bảng dưới, và chuẩn bị giải thích/sửa nhanh một yêu cầu nhỏ khi vấn đáp.
2. **Issue và review (0,5)**: tạo issue cho từng chức năng/lỗi; làm việc theo nhánh + Pull Request (mẫu có sẵn trong `.github/`), người khác review trước khi merge.
3. **Bản phát hành (0,5)**: sau khi `bash lab.sh test` đạt, gắn tag `v1.0.0` và tạo GitHub Release; thử clone sạch rồi `check`, `seed`, `run`.
4. **Giấy phép và nguồn (0,5)**: `LICENSE` (MIT) và `data/sample/LICENSE.txt` (CC0) đã có; sửa tên nhóm trong `LICENSE`.

## Phân công (điền)
| Thành viên | Phần lập trình | Tệp / commit chính |
|---|---|---|
| | | |

## Kịch bản trình diễn
1. `bash lab.sh run` → `/versions` (khách) → đăng nhập quản lý.
2. **TH1**: nhập `gtfs_v1.zip` → xem lỗi (không có) → *Dùng bản này* → `/next`: trạm S013, ngày 25/10/2026, giờ 01:00 → chuyến `25:10:00` thuộc ngày hoạt động 24/10.
3. Nhập `gtfs_loi.zip` → xem 4 lỗi, xuất CSV → thử *Dùng bản này* (không có nút; POST bị từ chối) → bản đang dùng không đổi.
4. **TH2**: nhập `gtfs_v2.zip` → `/compare?a=1&b=2` (4 mới, 3 xóa, 3 đổi giờ) → chuyển sang v2 → `/saved` chạy lại truy vấn mẫu.
5. Ngày ngoại lệ: `/next` trạm S001, ngày 02/11/2026 (chạy lịch cuối tuần) và 14/11/2026 (thêm EVENT).
