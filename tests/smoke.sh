#!/usr/bin/env bash
# Kiểm thử tích hợp qua HTTP: phân quyền, CSRF, nhập ZIP, đổi bản, bản lỗi không ảnh hưởng bản đang dùng.
cd "$(dirname "$0")/.."
command -v curl >/dev/null || { echo "SMOKE=SKIP (khong co curl)"; exit 0; }
PORT=8199; T=$(mktemp -d); export GTFS_DB="$T/s.sqlite"; export GTFS_ADMIN_PASSWORD="mk-test"
php tools/make_sample.php "$T" >/dev/null
php -S 127.0.0.1:$PORT -t public public/index.php >"$T/server.log" 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.2; done
B="http://127.0.0.1:$PORT"; J="$T/cj"; n=0; f=0
chk() { n=$((n+1)); if [ "$2" = "$3" ]; then echo "  ok   $1"; else f=$((f+1)); echo "  FAIL $1 (mong doi '$2', nhan '$3')"; fi; }
has() { n=$((n+1)); if grep -q -- "$2" "$3"; then echo "  ok   $1"; else f=$((f+1)); echo "  FAIL $1 (khong thay '$2')"; fi; }
tok() { curl -s -b "$J" -c "$J" "$B${1:-/versions}" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }

echo "== Kiem thu tich hop web =="
chk "GET / (chua co ban nao) -> 200" 200 "$(curl -s -o "$T/o" -w '%{http_code}' -b "$J" -c "$J" $B/)"
has "trang chu bao chua co ban dang dung" "Chưa có phiên bản" "$T/o"
chk "POST khong CSRF -> 400" 400 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -b "$J" -c "$J" -d 'password=mk-test' $B/login)"
C=$(tok)
curl -s -o /dev/null -b "$J" -c "$J" -F "csrf=$C" -F label=khach -F "zip=@$T/gtfs_v1.zip" $B/versions/import
curl -s -b "$J" -c "$J" $B/versions > "$T/o"; has "khach nhap ZIP bi tu choi" "Chỉ người quản lý" "$T/o"
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$C&id=1" $B/versions/activate
curl -s -b "$J" -c "$J" $B/versions > "$T/o"; has "khach doi ban dang dung bi tu choi" "mới được đổi bản đang sử dụng" "$T/o"
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$C&password=sai" $B/login
curl -s -b "$J" -c "$J" $B/versions > "$T/o"; has "sai mat khau bi tu choi" "Sai mật khẩu" "$T/o"
C=$(tok); curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$C&password=mk-test" $B/login
curl -s -b "$J" -c "$J" $B/versions > "$T/o"; has "dang nhap quan ly" "Nhập &amp; kiểm tra" "$T/o"
C=$(tok)
for p in "v1:Ban v1" "v2:Ban v2" "loi:Ban loi"; do
  curl -s -o /dev/null -b "$J" -c "$J" -F "csrf=$C" -F "label=${p#*:}" -F "zip=@$T/gtfs_${p%%:*}.zip" $B/versions/import
done
curl -s -b "$J" -c "$J" $B/versions > "$T/o"
has "nhap ban loi: bao khong the dung" "có lỗi" "$T/o"
C=$(tok)
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$C&id=1" $B/versions/activate
curl -s -b "$J" -c "$J" $B/versions > "$T/o"; has "chon v1 lam ban dang dung" "ĐANG DÙNG" "$T/o"
C=$(tok)
curl -s -o /dev/null -b "$J" -c "$J" -d "csrf=$C&id=3" $B/versions/activate
curl -s -b "$J" -c "$J" $B/versions > "$T/o"; has "kich hoat ban loi bi tu choi, giu nguyen ban dang dung" "Bản đang sử dụng không thay đổi" "$T/o"
has "ban dang dung van la #1" "Bản đang dùng: <b>#1" "$T/o"
curl -s "$B/next?stop_id=S013&date=2026-10-25&time=01:00" > "$T/o"
has "tra 01:00 CN: co chuyen 25:10:00 cua ngay hoat dong 24/10" "25:10:00" "$T/o"
has "ghi chu sau nua dem" "sau nửa đêm, thuộc ngày hoạt động 24/10/2026" "$T/o"
curl -s "$B/next?stop_id=S001&date=2027-03-01&time=10:00" > "$T/o"; has "ngoai pham vi: bao ro khong co chuyen" "Không có chuyến" "$T/o"
curl -s "$B/search?type=stops&q=my+dinh" > "$T/o"; has "tim tram khong dau" "Bến xe Mỹ Đình" "$T/o"
curl -s "$B/compare?a=1&b=2" > "$T/o"; has "so sanh v1-v2" "chuyến bị xóa" "$T/o"
has "chuyen bi xoa liet ke" "R03-1-WE-01" "$T/o"
chk "xuat lich CSV" 200 "$(curl -s -o "$T/o" -w '%{http_code}' "$B/export/schedule?route=R06")"
has "CSV co 25:10:00" "25:10:00" "$T/o"
chk "xuat bao cao loi CSV" 200 "$(curl -s -o "$T/o" -w '%{http_code}' "$B/export/errors?id=3")"
has "bao cao loi co BAD_REF_STOP" "BAD_REF_STOP" "$T/o"
chk "XSS: dau vao duoc escape" 0 "$(curl -s "$B/search?type=stops&q=%3Cscript%3Ealert(1)%3C/script%3E" | grep -c '<script>alert')"
chk "404" 404 "$(curl -s -o /dev/null -w '%{http_code}' $B/khong-co)"
# Máy chủ thứ hai KHÔNG đặt mật khẩu: không được có mật khẩu mặc định
P2=8198; B2="http://127.0.0.1:$P2"; J2="$T/cj2"
GTFS_ADMIN_PASSWORD= GTFS_DB="$T/s2.sqlite" php -S 127.0.0.1:$P2 -t public public/index.php >"$T/server2.log" 2>&1 & SRV2=$!
trap 'kill $SRV $SRV2 2>/dev/null; rm -rf "$T"' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null "$B2/" && break; sleep 0.2; done
C2=$(curl -s -b "$J2" -c "$J2" "$B2/versions" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -b "$J2" -c "$J2" -d "csrf=$C2&password=quanly123" $B2/login
curl -s -b "$J2" -c "$J2" $B2/versions > "$T/o"; has "khong co mat khau mac dinh: dang nhap bi vo hieu" "GTFS_ADMIN_PASSWORD" "$T/o"
curl -s -b "$J2" -c "$J2" $B2/versions > "$T/o"; chk "van la khach sau khi thu mat khau cu" 0 "$(grep -c 'Nhập &amp; kiểm tra' "$T/o")"

echo; echo "SMOKE_TESTS=$n FAILED=$f"; exit $((f>0))
