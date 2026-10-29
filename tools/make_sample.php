<?php
declare(strict_types=1);
/**
 * Sinh dữ liệu GTFS mẫu TỰ TẠO (giấy phép CC0, không lấy từ nguồn ngoài).
 *   php tools/make_sample.php [thư_mục_đích]
 * Tạo: gtfs_v1.zip, gtfs_v2.zip (hợp lệ), gtfs_loi.zip (cố ý có lỗi tham chiếu).
 * v1: 6 tuyến, 45 trạm, 128 chuyến, có calendar_dates và giờ 25:10:00.
 * v2: thêm 4 chuyến, xóa 3 chuyến, sửa giờ 3 chuyến, đổi tên tuyến R05.
 */

const STOP_NAMES = [
    'Bến xe Mỹ Đình', 'Cầu Giấy', 'Kim Mã', 'Ba Đình', 'Hồ Gươm', 'Hàng Bài', 'Bạch Mai', 'Giáp Bát', 'Văn Quán', 'Hà Đông',
    'Nhổn', 'Long Biên', 'Gia Lâm', 'Yên Nghĩa', 'Nghĩa Tân', 'Thanh Xuân', 'Ngã Tư Sở', 'Láng Hạ', 'Chùa Bộc', 'Đại Cồ Việt',
    'Time City', 'Royal City', 'Sân vận động Quốc gia', 'Công viên Thống Nhất', 'Hồ Tây', 'Phố cổ', 'Nguyễn Trãi', 'Trần Duy Hưng',
    'Hoàng Quốc Việt', 'Phạm Hùng', 'Lê Văn Lương', 'Tây Sơn', 'Xã Đàn', 'Kim Liên', 'Đại học Bách khoa', 'Đại học Quốc gia',
    'Đại học Đại Nam', 'Aeon Long Biên', 'Sân bay Nội Bài', 'Vạn Phúc', 'Phùng Khoang', 'Văn Khê', 'Ngọc Hồi', 'Pháp Vân', 'Thượng Đình',
];
// route_id => [tên dài, chỉ số trạm đầu (1-based), số trạm, phút giữa hai trạm]
const ROUTES = [
    'R01' => ['Mỹ Đình – Hà Đông', 1, 14, 3],
    'R02' => ['Cầu Giấy – Long Biên', 8, 14, 4],
    'R03' => ['Giáp Bát – Nhổn', 15, 14, 3],
    'R04' => ['Hồ Tây – Pháp Vân', 22, 14, 4],
    'R05' => ['Nội Bài – Văn Khê', 29, 14, 3],
    'R06' => ['Tuyến đêm Kim Mã – Thanh Xuân', 3, 14, 4],
];
const WK = ['05:30', '06:30', '07:15', '08:00', '12:00', '16:30', '17:15', '18:30'];
const WE = ['07:00', '09:30', '14:00', '18:00'];
const NIGHT = ['22:30', '23:30', '24:30']; // chuyến 24:30 qua mốc 25:10:00 tại trạm thứ 11

function hms(int $s): string { return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60); }
function secs(string $hm): int { [$h, $m] = array_map('intval', explode(':', $hm)); return $h * 3600 + $m * 60; }

function build_v1(): array
{
    $stops = [];
    foreach (STOP_NAMES as $i => $n) {
        $stops[] = [sprintf('S%03d', $i + 1), $n, round(21.0 + $i * 0.004, 5), round(105.75 + ($i % 9) * 0.01, 5)];
    }
    $routes = [];
    $trips = [];
    $times = [];
    foreach (ROUTES as $rid => [$long, $first, $count, $gap]) {
        $routes[$rid] = [$rid, 'VN-DEMO', substr($rid, 1), $long, 3];
        $ids = [];
        for ($i = 0; $i < $count; $i++) { $ids[] = sprintf('S%03d', $first + $i); }
        foreach ([0, 1] as $dir) {
            $seq = $dir === 0 ? $ids : array_reverse($ids);
            $head = $stops[(int)substr(end($seq), 1) - 1][1];
            $plan = $rid === 'R06' ? ['NIGHT' => NIGHT] : ['WK' => WK, 'WE' => WE];
            foreach ($plan as $svc => $starts) {
                foreach ($starts as $n => $hm) {
                    $tid = sprintf('%s-%d-%s-%02d', $rid, $dir, $svc, $n + 1);
                    $trips[$tid] = [$rid, $svc, $tid, "Đi $head", $dir];
                    foreach ($seq as $k => $sid) {
                        $t = secs($hm) + $k * $gap * 60;
                        $times[$tid][] = [$tid, hms($t), hms($t), $sid, $k + 1];
                    }
                }
            }
        }
    }
    // Chuyến sự kiện: chỉ chạy ngày thêm trong calendar_dates (không có trong calendar.txt)
    foreach ([0, 1] as $dir) {
        $ids = array_map(fn($i) => sprintf('S%03d', 1 + $i), range(0, 13));
        $seq = $dir === 0 ? $ids : array_reverse($ids);
        $tid = "R01-$dir-EVENT-01";
        $trips[$tid] = ['R01', 'EVENT', $tid, 'Sự kiện Mỹ Đình', $dir];
        foreach ($seq as $k => $sid) {
            $t = secs('19:00') + $k * 180;
            $times[$tid][] = [$tid, hms($t), hms($t), $sid, $k + 1];
        }
    }
    $all = ['0', '1'];
    return [
        'agency' => [['VN-DEMO', 'Xe buýt Demo Hà Nội', 'https://example.org/demo', 'Asia/Ho_Chi_Minh']],
        'stops' => $stops, 'routes' => $routes, 'trips' => $trips, 'times' => $times,
        'calendar' => [
            ['WK', 1, 1, 1, 1, 1, 0, 0, '20260101', '20261231'],
            ['WE', 0, 0, 0, 0, 0, 1, 1, '20260101', '20261231'],
            ['NIGHT', 1, 1, 1, 1, 1, 1, 1, '20260101', '20261231'],
        ],
        'calendar_dates' => [
            ['WK', '20261102', 2], ['WE', '20261102', 1],   // thứ Hai 02/11/2026: nghỉ lễ, chạy lịch cuối tuần
            ['NIGHT', '20261107', 2],                       // đêm thứ Bảy 07/11: hủy tuyến đêm
            ['EVENT', '20261114', 1],                       // ngày sự kiện: thêm dịch vụ
        ],
    ];
}

function build_v2(array $d): array
{
    foreach (['R02-0-WK-09' => '19:30', 'R02-1-WK-09' => '19:30', 'R02-0-WK-10' => '20:30', 'R02-1-WK-10' => '20:30'] as $tid => $hm) {
        $dir = (int)$tid[4];
        $ids = array_map(fn($i) => sprintf('S%03d', 8 + $i), range(0, 13));
        $seq = $dir === 0 ? $ids : array_reverse($ids);
        $d['trips'][$tid] = ['R02', 'WK', $tid, 'Đi mới', $dir];
        foreach ($seq as $k => $sid) {
            $t = secs($hm) + $k * 240;
            $d['times'][$tid][] = [$tid, hms($t), hms($t), $sid, $k + 1];
        }
    }
    foreach (['R03-1-WE-01', 'R03-1-WE-02', 'R03-0-WK-08'] as $tid) { // xóa 3 chuyến
        unset($d['trips'][$tid], $d['times'][$tid]);
    }
    foreach ($d['times']['R01-0-WK-01'] as &$r) { $r[1] = hms(secs_of($r[1]) + 180); $r[2] = hms(secs_of($r[2]) + 180); } unset($r); // +3 phút cả chuyến
    foreach ($d['times']['R04-1-WK-02'] as $k => &$r) { if ($k >= 4) { $r[1] = hms(secs_of($r[1]) + 120); $r[2] = hms(secs_of($r[2]) + 120); } } unset($r); // +2 phút từ trạm 5
    $d['times']['R05-0-WE-03'][5][2] = hms(secs_of($d['times']['R05-0-WE-03'][5][2]) + 60);       // sửa giờ rời 1 trạm
    $d['routes']['R05'][3] = 'Nội Bài – Văn Khê (tuyến mở rộng)';
    return $d;
}
function secs_of(string $hms): int { [$h, $m, $s] = array_map('intval', explode(':', $hms)); return $h * 3600 + $m * 60 + $s; }

function build_bad(array $d): array
{
    $d['times']['R01-0-WK-01'][3][3] = 'S999';                       // trạm không tồn tại
    $d['times']['T_KHONG_CO'][] = ['T_KHONG_CO', '08:00:00', '08:00:00', 'S001', 1]; // chuyến không tồn tại
    $d['trips']['R99-0-X-01'] = ['R99', 'WK', 'R99-0-X-01', 'Lỗi', 0];            // tuyến không tồn tại
    $d['times']['R02-0-WK-01'][2][1] = '7:5';                         // giờ sai dạng
    return $d;
}

function write_zip(string $path, array $d): void
{
    $csv = function (array $header, array $rows): string {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $header, ',', '"', '');
        foreach ($rows as $r) { fputcsv($fh, $r, ',', '"', ''); }
        rewind($fh);
        return stream_get_contents($fh);
    };
    $st = [];
    foreach ($d['times'] as $list) { foreach ($list as $r) { $st[] = $r; } }
    $files = [
        'agency.txt' => $csv(['agency_id', 'agency_name', 'agency_url', 'agency_timezone'], $d['agency']),
        'stops.txt' => $csv(['stop_id', 'stop_name', 'stop_lat', 'stop_lon'], $d['stops']),
        'routes.txt' => $csv(['route_id', 'agency_id', 'route_short_name', 'route_long_name', 'route_type'], array_values($d['routes'])),
        'trips.txt' => $csv(['route_id', 'service_id', 'trip_id', 'trip_headsign', 'direction_id'], array_values($d['trips'])),
        'stop_times.txt' => $csv(['trip_id', 'arrival_time', 'departure_time', 'stop_id', 'stop_sequence'], $st),
        'calendar.txt' => $csv(['service_id', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'start_date', 'end_date'], $d['calendar']),
        'calendar_dates.txt' => $csv(['service_id', 'date', 'exception_type'], $d['calendar_dates']),
    ];
    @unlink($path);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($files as $n => $c) { $zip->addFromString($n, $c); }
    $zip->close();
}

$out = $argv[1] ?? __DIR__ . '/../data/sample';
@mkdir($out, 0775, true);
$v1 = build_v1();
write_zip("$out/gtfs_v1.zip", $v1);
write_zip("$out/gtfs_v2.zip", build_v2($v1));
write_zip("$out/gtfs_loi.zip", build_bad($v1));
echo "Đã tạo gtfs_v1.zip, gtfs_v2.zip, gtfs_loi.zip tại $out\n";
