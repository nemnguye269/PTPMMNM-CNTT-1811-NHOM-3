<?php
declare(strict_types=1);
/** Bộ kiểm thử tự động (không cần PHPUnit). Chạy: bash lab.sh test */
require __DIR__ . '/../src/bootstrap.php';

use Gtfs\{Actor, Catalog, Csv, Db, Departures, Diff, Export, ForbiddenException, Importer, SavedQueries, Text, Time, Validator, VersionException, Versions};

$tmp = sys_get_temp_dir() . '/gtfs_test_' . getmypid();
mkdir($tmp);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/make_sample.php') . ' ' . escapeshellarg($tmp) . ' > /dev/null');

$tests = 0;
$failed = 0;
function t(string $name, callable $fn): void
{
    global $tests, $failed;
    $tests++;
    try { $fn(); echo "  ok   $name\n"; }
    catch (\Throwable $e) { $failed++; echo "  FAIL $name\n       " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n"; }
}
function eq(mixed $exp, mixed $act, string $msg = ''): void
{
    if ($exp !== $act) {
        throw new \Exception(($msg ? "$msg: " : '') . 'mong đợi ' . json_encode($exp, JSON_UNESCAPED_UNICODE) . ', nhận ' . json_encode($act, JSON_UNESCAPED_UNICODE));
    }
}
function ok(bool $c, string $msg): void { if (!$c) { throw new \Exception($msg); } }
function throws(string $cls, callable $fn): void
{
    try { $fn(); }
    catch (\Throwable $e) { if ($e instanceof $cls) { return; } throw new \Exception("mong đợi $cls, nhận " . get_class($e) . ': ' . $e->getMessage()); }
    throw new \Exception("mong đợi $cls nhưng không có ngoại lệ");
}
function sorted(array $a): array { ksort($a); return $a; }

// #1 = v1 (hợp lệ), #2 = v2 (hợp lệ), #3 = bản lỗi
$pdo = Db::connect(':memory:');
$imp = new Importer($pdo);
$v = new Versions($pdo);
$id1 = $imp->importZip('v1', "$tmp/gtfs_v1.zip");
$id2 = $imp->importZip('v2', "$tmp/gtfs_v2.zip");
$id3 = $imp->importZip('loi', "$tmp/gtfs_loi.zip");
$dep = new Departures($pdo);
$mgr = Actor::manager();
$codes = static fn(int $id): array => array_values(array_unique(array_column($v->issues($id), 'code')));

echo "== 5 phép thử bắt buộc (mục 6 của đề) ==\n";

t('P1 tra cứu sau nửa đêm trả đúng chuyến của ngày hoạt động trước', function () use ($pdo, $dep) {
    $stop = $pdo->query("SELECT stop_id FROM stop_times WHERE version_id=1 AND trip_id='R06-0-NIGHT-03' AND departure_text='25:10:00'")->fetchColumn();
    ok($stop !== false, 'dữ liệu mẫu phải có giờ 25:10:00');
    $r = $dep->next(1, $stop, '20261025', '01:00', 5);                // Chủ nhật 25/10, 01:00
    $f = $r['trips'][0];
    eq('R06-0-NIGHT-03', $f['trip_id']);
    eq('20261024', $f['service_date'], 'ngày hoạt động là hôm trước');
    eq('25:10:00', $f['departure_text']);
    eq('01:10', $f['clock']);
    eq('20261025', $f['real_date'], 'ngày thật là hôm sau');
    ok($f['next_day'], 'phải đánh dấu sau nửa đêm');
    $r2 = $dep->next(1, $stop, '20261025', '01:11', 3);               // quá 01:10 -> không còn
    ok($r2['trips'][0]['trip_id'] !== 'R06-0-NIGHT-03', 'không được trả chuyến đã qua');
    $r3 = $dep->next(1, $stop, '20261108', '01:00', 5);               // dịch vụ đêm 07/11 bị hủy
    foreach ($r3['trips'] as $x) { ok($x['trip_id'] !== 'R06-0-NIGHT-03', 'chuyến đêm đã hủy không được xuất hiện'); }
    $r4 = $dep->next(1, $stop, '20261025', '25:00', 1);               // nhập giờ ≥ 24 theo ngày hoạt động
    eq('R06-0-NIGHT-03', $r4['trips'][0]['trip_id'], 'giờ nhập 25:00 của ngày 25/10 = 01:00 ngày 26/10');
});

t('P2 áp đúng ngày ngoại lệ thêm hoặc hủy dịch vụ', function () use ($pdo, $dep) {
    eq(['NIGHT' => 'calendar', 'WK' => 'calendar'], sorted($dep->activeServices(1, '20261026')), 'thứ Hai thường');
    eq(['NIGHT' => 'calendar', 'WE' => 'added'], sorted($dep->activeServices(1, '20261102')), 'thứ Hai nghỉ lễ: hủy WK, thêm WE');
    eq(['NIGHT' => 'calendar', 'WK' => 'calendar'], sorted($dep->activeServices(1, '20261106')), 'thứ Sáu');
    eq(['WE' => 'calendar'], $dep->activeServices(1, '20261107'), 'thứ Bảy 07/11: NIGHT bị hủy');
    eq(['EVENT' => 'added', 'NIGHT' => 'calendar', 'WE' => 'calendar'], sorted($dep->activeServices(1, '20261114')), 'ngày sự kiện thêm EVENT (chỉ có trong calendar_dates)');
    $r = $dep->next(1, 'S001', '20261114', '18:50', 20, 'R01');
    $ev = array_values(array_filter($r['trips'], static fn($t) => str_contains($t['trip_id'], 'EVENT')));
    ok(count($ev) >= 1 && $ev[0]['via'] === 'added', 'chuyến EVENT phải xuất hiện, đánh dấu dịch vụ được thêm');
    $r = $dep->next(1, 'S001', '20261113', '18:50', 20, 'R01');
    foreach ($r['trips'] as $x) { ok(!(str_contains($x['trip_id'], 'EVENT') && $x['service_date'] !== '20261114'), 'EVENT chỉ chạy đúng ngày 14/11'); }
    // Thứ Hai nghỉ lễ chạy lịch cuối tuần: chuyến WK 05:30 không có, chuyến WE 07:00 có
    $day = array_filter($dep->next(1, 'S001', '20261102', '00:00', 30, 'R01')['trips'], static fn($t) => $t['service_date'] === '20261102');
    $ids = array_column($day, 'trip_id');
    ok(!in_array('R01-0-WK-01', $ids, true) && in_array('R01-0-WE-01', $ids, true), 'ngày lễ 02/11 phải dùng lịch WE: ' . implode(',', $ids));
});

t('P3 phát hiện stop_times tham chiếu trạm hoặc chuyến không tồn tại', function () use ($codes, $id3, $v) {
    $c = $codes($id3);
    ok(in_array('BAD_REF_STOP', $c, true), 'thiếu BAD_REF_STOP');
    ok(in_array('BAD_REF_TRIP', $c, true), 'thiếu BAD_REF_TRIP');
    ok(in_array('BAD_REF_ROUTE', $c, true), 'thiếu BAD_REF_ROUTE');
    ok(in_array('BAD_TIME', $c, true), 'thiếu BAD_TIME');
    $stopErr = array_values(array_filter($v->issues($id3), static fn($i) => $i['code'] === 'BAD_REF_STOP'));
    ok(str_contains($stopErr[0]['message'], 'S999') && $stopErr[0]['file'] === 'stop_times.txt' && $stopErr[0]['line'] > 1, 'lỗi phải nêu mã, tệp, dòng');
});

t('P4 nhập phiên bản lỗi không làm đổi lịch đang sử dụng', function () use ($pdo, $imp, $v, $dep, $mgr, $id1, $id3, $tmp) {
    $v->activate($id1, $mgr);
    $before = $dep->next($id1, 'S001', '20261026', '07:00', 5);
    $nTrips = (int)$pdo->query('SELECT COUNT(*) FROM trips WHERE version_id=1')->fetchColumn();
    eq('invalid', $v->find($id3)['status']);
    eq(0, (int)$pdo->query("SELECT COUNT(*) FROM trips WHERE version_id=$id3")->fetchColumn(), 'bản lỗi không lưu dữ liệu lịch');
    throws(VersionException::class, fn() => $v->activate($id3, $mgr));
    eq($id1, $v->activeId(), 'bản đang dùng không đổi sau khi thử kích hoạt bản lỗi');
    // ZIP hỏng: ném ngoại lệ, không để lại phiên bản dở dang
    file_put_contents("$tmp/hong.zip", 'khong phai zip');
    $nv = count($v->all());
    throws(\RuntimeException::class, fn() => $imp->importZip('hong', "$tmp/hong.zip"));
    eq($nv, count($v->all()), 'ZIP hỏng không tạo phiên bản');
    // Nhập thêm một bản lỗi nữa
    $imp->importFiles('thieu', 'x', ['stops.txt' => "stop_id,stop_name\nA,Trạm A\n"]);
    eq($id1, $v->activeId());
    eq($before['trips'], $dep->next($id1, 'S001', '20261026', '07:00', 5)['trips'], 'kết quả tra cứu y nguyên');
    eq($nTrips, (int)$pdo->query('SELECT COUNT(*) FROM trips WHERE version_id=1')->fetchColumn());
});

t('P5 so sánh hai phiên bản: chuyến mới, chuyến bị xóa, giờ được sửa', function () use ($pdo, $id1, $id2) {
    $d = (new Diff($pdo))->compare($id1, $id2);
    $ids = static fn(array $l) => array_column($l, 'trip_id');
    $added = $ids($d['trips']['added']);
    sort($added);
    eq(['R02-0-WK-09', 'R02-0-WK-10', 'R02-1-WK-09', 'R02-1-WK-10'], $added, 'chuyến mới');
    $removed = $ids($d['trips']['removed']);
    sort($removed);
    eq(['R03-0-WK-08', 'R03-1-WE-01', 'R03-1-WE-02'], $removed, 'chuyến bị xóa');
    $changed = $ids($d['trips']['changed']);
    sort($changed);
    eq(['R01-0-WK-01', 'R04-1-WK-02', 'R05-0-WE-03'], $changed, 'chuyến đổi giờ');
    $by = array_column($d['trips']['changed'], null, 'trip_id');
    $first = $by['R01-0-WK-01']['changes'][0];
    eq(['time', 1, '05:30:00', '05:33:00'], [$first['type'], $first['seq'], $first['old'], $first['new']], 'giờ cũ → mới trạm đầu');
    $r4 = $by['R04-1-WK-02']['changes'];
    eq(20, count($r4), '10 trạm × (đến + rời) bị dời 2 phút');
    eq([5, '', ''], [$r4[0]['seq'], '', ''], 'bắt đầu từ trạm thứ 5');
    $one = array_values(array_filter($by['R05-0-WE-03']['changes'], static fn($c) => $c['type'] === 'time'));
    eq(1, count($one), 'chỉ một giờ được sửa');
    eq('departure_time', $one[0]['field']);
    eq('R05', $d['routes']['changed'][0]['route_id'], 'tuyến đổi tên');
    // lọc theo tuyến
    $d2 = (new Diff($pdo))->compare($id1, $id2, 'R02');
    eq([4, 0, 0], [$d2['summary']['trips_added'], $d2['summary']['trips_removed'], $d2['summary']['trips_changed']], 'lọc theo tuyến R02');
    // so sánh bản với chính nó -> không khác biệt
    $d3 = (new Diff($pdo))->compare($id1, $id1);
    eq([0, 0, 0], [$d3['summary']['trips_added'], $d3['summary']['trips_removed'], $d3['summary']['trips_changed']]);
});

echo "== Chức năng 1–2: nhập, kiểm tra, phiên bản, quyền ==\n";

t('Bộ mẫu đạt quy mô tối thiểu (≥5 tuyến, ≥40 trạm, ≥100 chuyến, có calendar_dates, 25:10:00)', function () use ($pdo, $id1) {
    $c = (new Catalog($pdo))->counts($id1);
    ok($c['routes'] >= 5 && $c['stops'] >= 40 && $c['trips'] >= 100 && $c['calendar_dates'] >= 1, json_encode($c));
    eq('25:10:00', $pdo->query("SELECT departure_text FROM stop_times WHERE version_id=1 AND departure_secs=90600 LIMIT 1")->fetchColumn());
});

t('Hai bản hợp lệ không có lỗi; bản lỗi có lỗi', function () use ($v, $id1, $id2, $id3) {
    eq(['valid', 0], [$v->find($id1)['status'], (int)$v->find($id1)['error_count']]);
    eq('valid', $v->find($id2)['status']);
    ok((int)$v->find($id3)['error_count'] >= 4, 'bản lỗi phải có ≥ 4 lỗi');
});

t('Thiếu tệp bắt buộc / thiếu cột / tệp ngoài phạm vi', function () {
    $r = Validator::validate(['stops.txt' => Csv::parse("stop_id,stop_name\nA,Trạm A\n"), 'shapes.txt' => Csv::parse("shape_id\n1\n")]);
    $c = array_column($r['issues'], 'code');
    ok(in_array('MISSING_FILE', $c, true) && in_array('NO_SERVICE_FILE', $c, true) && in_array('UNSUPPORTED_FILE', $c, true), implode(',', $c));
    $r = Validator::validate(['stops.txt' => Csv::parse("stop_id\nA\n")]);
    ok(in_array('MISSING_COLUMN', array_column($r['issues'], 'code'), true), 'MISSING_COLUMN');
});

t('Kiểm tra giá trị: ngày sai, giờ ngược, trùng mã, service_id không tồn tại', function () use ($imp, $v) {
    $files = [
        'stops.txt' => "stop_id,stop_name\nA,Trạm A\nB,Trạm B\nA,Trùng\n",
        'routes.txt' => "route_id,route_short_name,route_type\nR,1,3\n",
        'trips.txt' => "route_id,service_id,trip_id\nR,SV,T1\nR,KHONGCO,T2\n",
        'stop_times.txt' => "trip_id,arrival_time,departure_time,stop_id,stop_sequence\nT1,08:10:00,08:10:00,A,1\nT1,08:00:00,08:00:00,B,2\nT2,09:00:00,09:00:00,A,1\nT2,09:05:00,09:05:00,B,2\n",
        'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nSV,1,1,1,1,1,0,0,20261301,20261231\n",
    ];
    $id = $imp->importFiles('gia tri', 't', $files);
    $c = array_column($v->issues($id), 'code');
    foreach (['DUPLICATE_ID', 'TIME_ORDER', 'BAD_REF_SERVICE', 'BAD_DATE'] as $k) { ok(in_array($k, $c, true), "thiếu $k: " . implode(',', $c)); }
});

t('Chỉ người quản lý được đổi bản đang dùng, nhập, xóa; không xóa bản đang dùng', function () use ($v, $id1, $id2, $id3, $mgr) {
    throws(ForbiddenException::class, fn() => $v->activate($id2, Actor::guest()));
    throws(ForbiddenException::class, fn() => $v->delete($id2, Actor::guest()));
    $v->activate($id1, $mgr);
    eq($id1, $v->activeId());
    throws(VersionException::class, fn() => $v->delete($id1, $mgr));
    throws(VersionException::class, fn() => $v->activate(9999, $mgr));
    $v->activate($id2, $mgr);
    eq($id2, $v->activeId());
    $v->activate($id1, $mgr);
    ok(count($v->auditLog()) >= 2, 'phải ghi nhật ký');
});

t('Kiểm tra toàn vẹn lớp thứ hai bắt dữ liệu hỏng trong CSDL', function () use ($pdo, $v, $id2, $mgr) {
    eq([], Versions::integrity($pdo, $id2));
    $pdo->exec("INSERT INTO stop_times VALUES ($id2,'R01-0-WK-01',99,'S_MA',1,1,'00:00:01','00:00:01',0)"); // mô phỏng hỏng dữ liệu
    $pdo->exec('PRAGMA foreign_keys=ON');
    ok(count(Versions::integrity($pdo, $id2)) > 0, 'phải phát hiện');
    throws(VersionException::class, fn() => $v->activate($id2, $mgr));
    $pdo->exec("DELETE FROM stop_times WHERE version_id=$id2 AND stop_sequence=99");
    eq([], Versions::integrity($pdo, $id2));
});

echo "== Chức năng 3: tra cứu ==\n";

t('Tìm trạm/tuyến theo tên không dấu hoặc mã; kết hợp bộ lọc', function () use ($pdo, $id1) {
    $c = new Catalog($pdo);
    $r = $c->stops($id1, ['q' => 'my dinh']);
    ok(count($r) === 1 && $r[0]['stop_name'] === 'Bến xe Mỹ Đình', 'tìm "my dinh" -> Mỹ Đình');
    eq('S001', $c->stops($id1, ['q' => 's001'])[0]['stop_id'], 'tìm theo mã');
    $onR06 = $c->stops($id1, ['q' => 'kim', 'route_id' => 'R06']);
    ok(count($onR06) >= 1, 'Kim Mã trên R06');
    eq([], $c->stops($id1, ['q' => 'Mỹ Đình', 'route_id' => 'R06']), 'Mỹ Đình không thuộc R06');
    eq('R06', $c->routes($id1, ['q' => 'đêm'])[0]['route_id'], 'tìm tuyến theo tên');
    ok(count($c->routes($id1, ['stop_id' => 'S020'])) === 2, 'S020 nằm trên R02 và R03');
    ok(count($c->trips($id1, ['route_id' => 'R01', 'direction_id' => '0', 'date' => '2026-10-26'])) === 8, 'R01 chiều 0 thứ Hai: 8 chuyến WK');
    eq(4, count($c->trips($id1, ['route_id' => 'R01', 'direction_id' => '0', 'date' => '2026-11-02'])), 'ngày lễ: 4 chuyến WE');
    eq([], $c->trips($id1, ['q' => "'; DROP TABLE trips;--"]), 'chống SQL injection');
    eq(14, count($c->trip($id1, 'R01-0-WK-01')['stop_times']), 'lần dừng của chuyến');
    eq(null, $c->trip($id1, 'KHONG_CO'));
});

echo "== Chức năng 4: chuyến tiếp theo ==\n";

t('Báo rõ khi không có chuyến', function () use ($dep) {
    $r = $dep->next(1, 'S001', '20270301', '10:00');
    ok(!$r['trips'] && str_contains($r['message'], 'ngoài phạm vi'), (string)$r['message']);
    $r = $dep->next(1, 'S_KHONG_CO', '20261026', '10:00');
    ok(!$r['trips'] && str_contains($r['message'], 'không có trong bộ lịch'), (string)$r['message']);
    $r = $dep->next(1, 'S001', '20261026', '25:99');
    ok(!$r['trips'] && str_contains($r['message'], 'không hợp lệ'), (string)$r['message']);
    $r = $dep->next(1, 'S014', '20261026', '23:59', 5, 'R01', 0);       // S014 là trạm cuối R01 hướng 0, chuyến cuối đã qua
    ok(count($r['trips']) === 0 && $r['message'] !== null, 'phải có thông báo');
});

t('Không trả chuyến đã kết thúc tại trạm (trạm cuối) và sắp xếp theo thời điểm thật', function () use ($dep) {
    $r = $dep->next(1, 'S014', '20261026', '00:00', 50, 'R01');
    foreach ($r['trips'] as $x) { ok(!str_starts_with($x['trip_id'], 'R01-0-'), 'S014 là trạm cuối của R01 chiều 0'); }
    $t = $dep->next(1, 'S001', '20261026', '00:00', 30)['trips'];
    $abs = array_column($t, 'abs');
    $s = $abs; sort($s);
    eq($s, $abs, 'phải tăng dần');
});

echo "== Chức năng 5: truy vấn mẫu & xuất ==\n";

t('Truy vấn mẫu lưu và chạy lại trên hai phiên bản cho kết quả khác nhau', function () use ($pdo, $id1, $id2) {
    $s = new SavedQueries($pdo);
    $qid = $s->save('R03 đầu giờ tối', 'trips', ['route_id' => 'R03', 'date' => '2026-11-01']);
    $q2 = $s->save('Chuyến kế tiếp S015', 'next', ['stop_id' => 'S015', 'date' => '2026-11-01', 'time' => '06:00', 'route_id' => 'R03', 'limit' => '3']);
    $a = $s->run($s->find($qid), $id1);
    $b = $s->run($s->find($qid), $id2);
    eq([8, 6], [count($a['rows']), count($b['rows'])], 'Chủ nhật 01/11: R03 có 8 chuyến WE ở v1, v2 xóa 2 chuyến WE còn 6');
    ok($s->run($s->find($q2), $id1)['rows'] !== [], 'truy vấn next chạy được');
    throws(\InvalidArgumentException::class, fn() => $s->save('', 'next', []));
    throws(\InvalidArgumentException::class, fn() => $s->save('x', 'drop', []));
    $s->delete($qid);
    eq(null, $s->find($qid));
});

t('Xuất lịch và báo cáo lỗi CSV (có BOM, chống CSV injection)', function () use ($pdo, $id1, $id3) {
    $e = new Export($pdo);
    $csv = $e->schedule($id1, 'R06');
    ok(str_starts_with($csv, "\xEF\xBB\xBF"), 'BOM');
    ok(str_contains($csv, 'R06-0-NIGHT-03') && str_contains($csv, '25:10:00'), 'có chuyến đêm và 25:10:00');
    ok(!str_contains($csv, 'R01-0-WK-01'), 'lọc theo tuyến');
    $err = $e->importErrors($id3);
    ok(str_contains($err, 'BAD_REF_STOP') && str_contains($err, 'severity'), 'báo cáo lỗi');
    $pdo->exec("INSERT INTO versions(label,source_name,status,created_at) VALUES ('inj','x','valid','now')");
    $iv = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO import_issues(version_id,severity,code,file,line,message) VALUES ($iv,'error','X','f',1,'=HYPERLINK(\"http://x\")')");
    ok(str_contains($e->importErrors($iv), "'=HYPERLINK"), 'công thức phải được vô hiệu hóa');
});

echo "== Tiện ích ==\n";

t('Time: phân tích giờ GTFS, giờ ≥ 24, ngày', function () {
    eq(90600, Time::parse('25:10:00'));
    eq(null, Time::parse('7:5'));
    eq(null, Time::parse('99:00:00'));
    eq('25:10:00', Time::format(90600));
    eq(45 * 3600, Time::parseQuery('45:00'));
    eq(null, Time::parseQuery('48:00'));
    eq('20261102', Time::normalizeDate('2026-11-02'));
    eq(null, Time::normalizeDate('2026-02-30'));
    eq('mo dinh dai nam', Text::fold('Mỗ Đình Đại Nam'));
});


echo "== Bổ sung: ca thử cạnh và các nâng cấp ==\n";

function mini(array $override = []): array
{
    return array_replace([
        'stops.txt' => "stop_id,stop_name\nA,Tram A\nB,Tram B\n",
        'routes.txt' => "route_id,route_short_name,route_type\nR1,1,3\n",
        'trips.txt' => "route_id,service_id,trip_id\nR1,WD,T1\nR1,XX,T2\n",
        'stop_times.txt' => "trip_id,arrival_time,departure_time,stop_id,stop_sequence\nT1,08:00:00,08:00:00,A,1\nT1,08:10:00,08:10:00,B,2\nT2,09:00:00,09:00:00,A,1\nT2,09:10:00,09:10:00,B,2\n",
        'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nWD,1,1,1,1,1,0,0,20261001,20261231\n",
        'calendar_dates.txt' => "service_id,date,exception_type\nXX,20261105,1\nWD,20261104,2\n",
    ], $override);
}

t('Time: 24:00:00 hợp lệ; giờ truy vấn 24:30 nhận, 48:00 từ chối', function () {
    eq(86400, Time::parse('24:00:00'));
    eq('24:00:00', Time::format(86400));
    eq(88200, Time::parseQuery('24:30'));
    eq(null, Time::parseQuery('48:00'));
});

t('Ngày hủy (loại 2) cho dịch vụ chỉ có trong calendar_dates không gây lỗi; ngày thêm hiện đúng', function () use ($imp, $dep, $v) {
    $id = $imp->importFiles('mini', 'm', mini(['calendar_dates.txt' => "service_id,date,exception_type\nXX,20261105,1\nWD,20261104,2\nXX,20261106,2\n"]));
    eq('valid', $v->find($id)['status']);
    eq([], $dep->activeServices($id, '20261104'), 'thứ Tư: WD bị hủy');
    eq(['WD' => 'calendar', 'XX' => 'added'], sorted($dep->activeServices($id, '20261105')), 'thứ Năm: thêm XX');
    eq(['WD' => 'calendar'], $dep->activeServices($id, '20261106'), 'thứ Sáu: hủy XX không tồn tại là vô hại');
});

t('So sánh bản với chính nó: không có khác biệt nào, kể cả dịch vụ', function () use ($pdo, $id1) {
    $d = (new Diff($pdo))->compare($id1, $id1);
    eq([], $d['services']);
    eq(0, $d['summary']['services_changed']);
});

t('So sánh bắt thay đổi lịch hoạt động dù giữ nguyên service_id', function () use ($pdo, $imp) {
    $a = $imp->importFiles('mini-a', 'm', mini());
    $b = $imp->importFiles('mini-b', 'm', mini([
        'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nWD,1,1,1,1,0,0,0,20261001,20261130\n",
        'calendar_dates.txt' => "service_id,date,exception_type\nXX,20261105,2\nWD,20261104,2\n",
    ]));
    $d = (new Diff($pdo))->compare($a, $b);
    eq(0, $d['summary']['trips_changed'], 'chuyến và giờ không đổi');
    eq(['WD', 'XX'], array_column($d['services'], 'service_id'));
    eq([1, 1], array_column($d['services'], 'affected_trips'));
    $wd = implode(' | ', $d['services'][0]['details']);
    ok(str_contains($wd, 'Thứ chạy') && str_contains($wd, 'Khoảng ngày'), $wd);
    ok(str_contains(implode(' ', $d['services'][1]['details']), '05/11/2026'), 'nêu ngày ngoại lệ đổi');
    $csv = (new Export($pdo))->diff($d);
    ok(str_contains($csv, 'service_changed'), 'xuất CSV có dịch vụ đổi');
});

t('Kích hoạt bản lỗi rồi bản tốt: giao dịch được hoàn tác đúng, không kẹt khóa', function () use ($pdo, $imp, $v, $mgr) {
    $bad = $imp->importFiles('hong', 'm', ['stops.txt' => "stop_id,stop_name\nA,A\n"]);
    $good = $imp->importFiles('tot', 'm', mini());
    throws(VersionException::class, fn() => $v->activate($bad, $mgr));
    throws(VersionException::class, fn() => $v->activate(99999, $mgr));
    $v->activate($good, $mgr);
    eq($good, $v->activeId());
    throws(VersionException::class, fn() => $v->delete($good, $mgr));
    $v->activate(1, $mgr);                       // trả về v1 cho các test sau
    $v->delete($good, $mgr);
    eq(null, $v->find($good));
});

t('Truy vấn mẫu báo rõ khi mã trạm/tuyến không còn trong bản lịch', function () use ($pdo, $id1) {
    $s = new SavedQueries($pdo);
    $r = $s->run(['kind' => 'next', 'params' => ['stop_id' => 'S001', 'date' => '2026-10-26', 'time' => '07:00', 'route_id' => 'R_KHONG_CO']], $id1);
    ok($r['rows'] === [] && str_contains((string)$r['message'], 'R_KHONG_CO'), (string)$r['message']);
    $r = $s->run(['kind' => 'trips', 'params' => ['stop_id' => 'S_KHONG_CO']], $id1);
    ok($r['rows'] === [] && str_contains((string)$r['message'], 'S_KHONG_CO'), (string)$r['message']);
});

t('ZIP nhỏ vẫn nhập được qua đường đọc theo luồng (giới hạn theo byte thực)', function () use ($imp, $v, $tmp) {
    $p = "$tmp/lon.zip";
    $z = new \ZipArchive();
    $z->open($p, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $z->addFromString('stops.txt', "stop_id,stop_name\n" . str_repeat("A,B\n", 8));
    $z->close();
    $n = count($v->all());
    $imp->importZip('nho', $p);                  // ZIP nhỏ vẫn nhập được (bản lỗi thiếu tệp) -> thêm 1 phiên bản
    eq($n + 1, count($v->all()));
});

t('Lược đồ db/schema.sql khớp Db::migrate', function () {
    $cols = static function (PDO $p): array {
        $o = [];
        foreach ($p->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $tb) {
            $o[$tb] = array_column($p->query("PRAGMA table_info($tb)")->fetchAll(), 'name');
        }
        ksort($o);
        return $o;
    };
    $a = new PDO('sqlite::memory:');
    $a->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $a->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $a->exec((string)file_get_contents(__DIR__ . '/../db/schema.sql'));
    $b = Db::connect(':memory:');
    eq($cols($b), $cols($a), 'bảng/cột của schema.sql và Db::migrate');
});

echo "\nTESTS=$tests FAILED=$failed\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($failed ? 1 : 0);
