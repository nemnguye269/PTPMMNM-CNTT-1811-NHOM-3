<?php
declare(strict_types=1);
namespace Gtfs;

use PDO;

/** Bộ điều khiển web (PHP thuần): định tuyến, quyền, biểu mẫu và hiển thị. */
final class App
{
    private Versions $versions;
    private Catalog $catalog;
    private Departures $dep;
    private SavedQueries $saved;
    private Actor $actor;

    public function __construct(private PDO $pdo)
    {
        $this->versions = new Versions($pdo);
        $this->catalog = new Catalog($pdo);
        $this->dep = new Departures($pdo);
        $this->saved = new SavedQueries($pdo);
        $this->actor = !empty($_SESSION['manager']) ? Actor::manager() : Actor::guest();
    }

    public function handle(string $method, string $path): void
    {
        $q = $_GET;
        try {
            if ($method === 'POST') {
                $this->checkCsrf();
                $this->post($path);
                return;
            }
            match ($path) {
                '/' => $this->home(),
                '/versions' => $this->versionsPage(),
                '/versions/errors' => $this->errorsPage((int)($q['id'] ?? 0)),
                '/search' => $this->searchPage($q),
                '/trip' => $this->tripPage((string)($q['id'] ?? '')),
                '/next' => $this->nextPage($q),
                '/compare' => $this->comparePage($q),
                '/saved' => $this->savedPage($q),
                '/export/schedule' => $this->exportSchedule($q),
                '/export/errors' => $this->download("loi_nhap_v" . (int)($q['id'] ?? 0) . '.csv', (new Export($this->pdo))->importErrors((int)($q['id'] ?? 0))),
                '/export/diff' => $this->exportDiff($q),
                default => $this->notFound(),
            };
        } catch (ForbiddenException $e) {
            http_response_code(403);
            $this->flash('error', $e->getMessage());
            $this->redirect('/versions');
        }
    }

    // ---------- POST ----------
    private function post(string $path): void
    {
        switch ($path) {
            case '/login':
                $pw = (string)getenv('GTFS_ADMIN_PASSWORD');
                if ($pw === '') {
                    $this->flash('error', 'Chưa cấu hình mật khẩu quản lý: đặt biến môi trường GTFS_ADMIN_PASSWORD rồi chạy lại. Đăng nhập đang bị vô hiệu hóa.');
                } elseif (hash_equals($pw, (string)($_POST['password'] ?? ''))) {
                    session_regenerate_id(true);
                    $_SESSION['manager'] = true;
                    $this->flash('ok', 'Đã đăng nhập với vai trò người quản lý.');
                } else {
                    $this->flash('error', 'Sai mật khẩu.');
                }
                $this->redirect('/versions');
            case '/logout':
                unset($_SESSION['manager']);
                $this->flash('ok', 'Đã đăng xuất.');
                $this->redirect('/');
            case '/versions/import':
                if (!$this->actor->isManager()) { throw new ForbiddenException('Chỉ người quản lý mới được nhập bộ lịch.'); }
                $this->importUpload();
                $this->redirect('/versions');
            case '/versions/activate':
                $id = (int)($_POST['id'] ?? 0);
                try {
                    $this->versions->activate($id, $this->actor);
                    $this->flash('ok', "Đã chuyển sang phiên bản #$id.");
                } catch (VersionException $e) {
                    $this->flash('error', $e->getMessage() . ' Bản đang sử dụng không thay đổi.');
                }
                $this->redirect('/versions');
            case '/versions/delete':
                try {
                    $this->versions->delete((int)($_POST['id'] ?? 0), $this->actor);
                    $this->flash('ok', 'Đã xóa phiên bản.');
                } catch (VersionException $e) {
                    $this->flash('error', $e->getMessage());
                }
                $this->redirect('/versions');
            case '/saved/save':
                try {
                    $params = $_POST['p'] ?? [];
                    $this->saved->save((string)($_POST['name'] ?? ''), (string)($_POST['kind'] ?? ''), is_array($params) ? $params : []);
                    $this->flash('ok', 'Đã lưu truy vấn mẫu.');
                } catch (\InvalidArgumentException $e) {
                    $this->flash('error', $e->getMessage());
                }
                $this->redirect('/saved');
            case '/saved/delete':
                $this->saved->delete((int)($_POST['id'] ?? 0));
                $this->redirect('/saved');
        }
        $this->notFound();
    }

    private function importUpload(): void
    {
        $f = $_FILES['zip'] ?? null;
        $label = trim((string)($_POST['label'] ?? ''));
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $label === '') {
            $this->flash('error', 'Cần nhập tên phiên bản và chọn tệp ZIP hợp lệ.');
            return;
        }
        if ($f['size'] > 3 * Spec::MAX_FILE_BYTES) {
            $this->flash('error', 'Tệp ZIP quá lớn.');
            return;
        }
        try {
            $id = (new Importer($this->pdo))->importZip(mb_substr($label, 0, 100), $f['tmp_name'], basename((string)$f['name']));
            $this->versions->log($this->actor, 'import', "version=$id");
            $v = $this->versions->find($id);
            $this->flash($v['status'] === 'valid' ? 'ok' : 'error',
                $v['status'] === 'valid'
                    ? "Đã nhập phiên bản #$id (hợp lệ, {$v['warning_count']} cảnh báo). Bấm \"Dùng bản này\" để đưa vào sử dụng."
                    : "Phiên bản #$id có {$v['error_count']} lỗi nên KHÔNG thể đưa vào sử dụng. Bản đang dùng không thay đổi.");
        } catch (\Throwable $e) {
            $this->flash('error', 'Nhập thất bại: ' . $e->getMessage() . ' Bản đang sử dụng không thay đổi.');
        }
    }

    // ---------- Trang ----------
    private function home(): void
    {
        $a = $this->versions->active();
        $b = '<h1>Tra cứu lịch trình giao thông công cộng</h1>';
        if (!$a) {
            $b .= '<div class="card warn">Chưa có phiên bản lịch nào được đưa vào sử dụng. ' . ($this->actor->isManager() ? 'Vào <a href="/versions">Phiên bản lịch</a> để nhập và chọn bản.' : 'Người quản lý cần nhập và chọn một bản lịch.') . '</div>';
        } else {
            $c = $this->catalog->counts((int)$a['id']);
            $b .= '<div class="card"><p>Đang dùng: <strong>' . Text::h($a['label']) . '</strong> (phiên bản #' . $a['id'] . ')</p>'
                . "<p class='muted'>{$c['routes']} tuyến · {$c['stops']} trạm · {$c['trips']} chuyến · {$c['stop_times']} lần dừng</p></div>"
                . '<div class="grid"><a class="card tile" href="/next"><b>Tìm chuyến tiếp theo</b><br>Chọn trạm, ngày, giờ</a>'
                . '<a class="card tile" href="/search?type=routes"><b>Tuyến, trạm, chuyến</b><br>Tìm theo tên hoặc mã</a>'
                . '<a class="card tile" href="/compare"><b>So sánh hai bản lịch</b><br>Chuyến mới, bị xóa, đổi giờ</a>'
                . '<a class="card tile" href="/saved"><b>Truy vấn mẫu</b><br>Chạy lại trên bản mới</a></div>';
        }
        $this->page('Trang chủ', $b);
    }

    private function versionsPage(): void
    {
        $act = $this->versions->activeId();
        $b = '<h1>Phiên bản lịch</h1>';
        if (!$this->actor->isManager()) {
            $b .= '<div class="card"><p>Bạn đang xem với vai trò khách. Đăng nhập để nhập bộ lịch và đổi bản đang sử dụng.</p>'
                . '<form method="post" action="/login" class="row">' . $this->csrf() . '<input type="password" name="password" placeholder="Mật khẩu quản lý" required> <button>Đăng nhập</button></form></div>';
        } else {
            $b .= '<div class="card"><h2>Nhập bộ GTFS tĩnh (.zip)</h2><form method="post" action="/versions/import" enctype="multipart/form-data" class="row">'
                . $this->csrf() . '<input name="label" placeholder="Tên phiên bản, vd: Lịch mùa đông 2026" required maxlength="100"> <input type="file" name="zip" accept=".zip" required> <button>Nhập &amp; kiểm tra</button></form>'
                . '<p class="muted">Bản có lỗi chỉ được lưu để xem lỗi, không thể đưa vào sử dụng và không ảnh hưởng bản đang dùng.</p></div>';
        }
        $b .= '<table><tr><th>#</th><th>Tên</th><th>Nguồn</th><th>Trạng thái</th><th>Lỗi</th><th>Cảnh báo</th><th>Chuyến</th><th>Nhập lúc (UTC)</th><th></th></tr>';
        foreach ($this->versions->all() as $v) {
            $isAct = (int)$v['id'] === $act;
            $st = $isAct ? '<span class="badge ok">ĐANG DÙNG</span>' : ($v['status'] === 'valid' ? '<span class="badge">hợp lệ</span>' : '<span class="badge bad">có lỗi</span>');
            $acts = '<a href="/versions/errors?id=' . $v['id'] . '">Xem lỗi</a>';
            if ($this->actor->isManager()) {
                if (!$isAct && $v['status'] === 'valid') {
                    $acts .= ' ' . $this->miniForm('/versions/activate', (int)$v['id'], 'Dùng bản này');
                }
                if (!$isAct) {
                    $acts .= ' ' . $this->miniForm('/versions/delete', (int)$v['id'], 'Xóa', 'danger');
                }
            }
            $b .= '<tr><td>' . $v['id'] . '</td><td>' . Text::h($v['label']) . '</td><td>' . Text::h($v['source_name']) . "</td><td>$st</td><td>{$v['error_count']}</td><td>{$v['warning_count']}</td><td>{$v['trip_count']}</td><td>{$v['created_at']}</td><td>$acts</td></tr>";
        }
        $b .= '</table>';
        $log = $this->versions->auditLog(8);
        if ($log) {
            $b .= '<h2>Nhật ký thao tác</h2><table><tr><th>Lúc (UTC)</th><th>Người</th><th>Thao tác</th><th>Chi tiết</th></tr>';
            foreach ($log as $l) {
                $b .= '<tr><td>' . $l['at'] . '</td><td>' . Text::h($l['actor']) . '</td><td>' . Text::h($l['action']) . '</td><td>' . Text::h($l['detail']) . '</td></tr>';
            }
            $b .= '</table>';
        }
        $this->page('Phiên bản lịch', $b);
    }

    private function errorsPage(int $id): void
    {
        $v = $this->versions->find($id);
        if (!$v) { $this->notFound(); }
        $b = '<h1>Kết quả kiểm tra phiên bản #' . $id . ' – ' . Text::h($v['label']) . '</h1>'
            . "<p>{$v['error_count']} lỗi, {$v['warning_count']} cảnh báo. <a href='/export/errors?id=$id'>Xuất báo cáo lỗi (CSV)</a></p>";
        $issues = $this->versions->issues($id);
        $b .= $issues ? '<table><tr><th>Mức</th><th>Mã</th><th>Tệp</th><th>Dòng</th><th>Nội dung</th></tr>' : '<div class="card ok">Không có lỗi hay cảnh báo nào.</div>';
        foreach ($issues as $i) {
            $b .= '<tr><td><span class="badge ' . ($i['severity'] === 'error' ? 'bad' : '') . '">' . ($i['severity'] === 'error' ? 'lỗi' : 'cảnh báo') . '</span></td><td>' . Text::h($i['code']) . '</td><td>' . Text::h($i['file']) . '</td><td>' . ($i['line'] ?? '') . '</td><td>' . Text::h($i['message']) . '</td></tr>';
        }
        $this->page('Lỗi nhập', $b . ($issues ? '</table>' : ''));
    }

    private function needActive(): int
    {
        $id = $this->versions->activeId();
        if (!$id) {
            $this->page('Chưa có lịch', '<div class="card warn">Chưa có phiên bản nào được đưa vào sử dụng. <a href="/versions">Quản lý phiên bản</a></div>');
            exit;
        }
        return $id;
    }

    private function searchPage(array $q): void
    {
        $vid = $this->needActive();
        $type = in_array($q['type'] ?? '', ['routes', 'stops', 'trips'], true) ? $q['type'] : 'routes';
        $f = array_intersect_key($q, array_flip(['q', 'route_id', 'stop_id', 'route_type', 'direction_id', 'date']));
        $routes = $this->catalog->routes($vid);
        $stops = $this->catalog->stops($vid);
        $b = '<h1>Tra cứu tuyến, trạm, chuyến</h1><div class="tabs">';
        foreach (['routes' => 'Tuyến', 'stops' => 'Trạm', 'trips' => 'Chuyến'] as $k => $l) {
            $b .= '<a class="' . ($k === $type ? 'on' : '') . '" href="/search?type=' . $k . '">' . $l . '</a>';
        }
        $b .= '</div><form class="card row" method="get" action="/search"><input type="hidden" name="type" value="' . $type . '">'
            . '<input name="q" value="' . Text::h($f['q'] ?? '') . '" placeholder="Tên hoặc mã (không cần dấu)">';
        if ($type !== 'routes') { $b .= $this->select('route_id', 'Mọi tuyến', array_column($routes, 'route_id', 'route_id'), $f['route_id'] ?? ''); }
        if ($type !== 'stops') { $b .= $this->select('stop_id', 'Mọi trạm', array_column($stops, 'stop_name', 'stop_id'), $f['stop_id'] ?? '', true); }
        if ($type === 'trips') {
            $b .= $this->select('direction_id', 'Hai chiều', ['0' => 'Chiều 0', '1' => 'Chiều 1'], $f['direction_id'] ?? '')
                . '<label class="muted">Ngày chạy <input type="date" name="date" value="' . Text::h($f['date'] ?? '') . '"></label>';
        }
        $b .= '<button>Tìm</button></form>';

        $rows = match ($type) { 'routes' => $this->catalog->routes($vid, $f), 'stops' => $this->catalog->stops($vid, $f), 'trips' => $this->catalog->trips($vid, $f) };
        $b .= '<p class="muted">' . count($rows) . ' kết quả' . (count($rows) >= 200 ? ' (hiển thị tối đa 200)' : '') . '</p>';
        $b .= $this->resultTable($type, $rows);
        $b .= $this->saveForm($type, $f);
        $this->page('Tra cứu', $b);
    }

    private function resultTable(string $type, array $rows): string
    {
        if (!$rows) { return '<div class="card">Không có kết quả phù hợp.</div>'; }
        $o = '<table>';
        if ($type === 'routes') {
            $o .= '<tr><th>Mã</th><th>Tên</th><th>Số chuyến</th><th></th></tr>';
            foreach ($rows as $r) {
                $o .= '<tr><td>' . Text::h($r['route_id']) . '</td><td>' . Text::h(trim($r['route_short_name'] . ' ' . $r['route_long_name'])) . '</td><td>' . $r['trip_count'] . '</td><td><a href="/search?type=trips&route_id=' . urlencode($r['route_id']) . '">Xem chuyến</a> · <a href="/search?type=stops&route_id=' . urlencode($r['route_id']) . '">Xem trạm</a> · <a href="/export/schedule?route=' . urlencode($r['route_id']) . '">Xuất lịch</a></td></tr>';
            }
        } elseif ($type === 'stops') {
            $o .= '<tr><th>Mã</th><th>Tên trạm</th><th>Tọa độ</th><th></th></tr>';
            foreach ($rows as $r) {
                $o .= '<tr><td>' . Text::h($r['stop_id']) . '</td><td>' . Text::h($r['stop_name']) . '</td><td>' . ($r['stop_lat'] !== null ? $r['stop_lat'] . ', ' . $r['stop_lon'] : '') . '</td><td><a href="/next?stop_id=' . urlencode($r['stop_id']) . '">Chuyến tiếp theo</a> · <a href="/search?type=routes&stop_id=' . urlencode($r['stop_id']) . '">Tuyến qua trạm</a></td></tr>';
            }
        } else {
            $o .= '<tr><th>Chuyến</th><th>Tuyến</th><th>Hướng đi</th><th>Dịch vụ</th><th>Giờ xuất phát</th><th>Số trạm</th></tr>';
            foreach ($rows as $r) {
                $o .= '<tr><td><a href="/trip?id=' . urlencode($r['trip_id']) . '">' . Text::h($r['trip_id']) . '</a></td><td>' . Text::h($r['route_id']) . '</td><td>' . Text::h($r['trip_headsign']) . '</td><td>' . Text::h($r['service_id']) . '</td><td>' . ($r['first_dep'] !== null ? Time::format((int)$r['first_dep']) : '') . '</td><td>' . $r['stop_count'] . '</td></tr>';
            }
        }
        return $o . '</table>';
    }

    private function tripPage(string $id): void
    {
        $vid = $this->needActive();
        $t = $this->catalog->trip($vid, $id);
        if (!$t) { $this->notFound(); }
        $b = '<h1>Chuyến ' . Text::h($id) . '</h1><p>Tuyến ' . Text::h(trim($t['route_short_name'] . ' ' . $t['route_long_name'])) . ' · dịch vụ <b>' . Text::h($t['service_id']) . '</b> · ' . Text::h($t['trip_headsign']) . '</p>'
            . '<table><tr><th>#</th><th>Trạm</th><th>Đến</th><th>Rời</th></tr>';
        foreach ($t['stop_times'] as $s) {
            $b .= '<tr><td>' . $s['stop_sequence'] . '</td><td>' . Text::h($s['stop_name']) . ' <span class="muted">' . Text::h($s['stop_id']) . '</span></td><td>' . $s['arrival_text'] . '</td><td>' . $s['departure_text'] . '</td></tr>';
        }
        $this->page('Chuyến ' . $id, $b . '</table><p class="muted">Giờ ≥ 24:00:00 là giờ của ngày hoạt động hôm đó nhưng diễn ra sau nửa đêm.</p>');
    }

    private function nextPage(array $q): void
    {
        $vid = $this->needActive();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Ho_Chi_Minh')));
        $p = ['stop_id' => (string)($q['stop_id'] ?? ''), 'date' => (string)($q['date'] ?? $today->format('Y-m-d')),
              'time' => (string)($q['time'] ?? $today->format('H:i')), 'route_id' => (string)($q['route_id'] ?? ''), 'limit' => (string)($q['limit'] ?? '10')];
        $stops = $this->catalog->stops($vid, [], 1000);
        $routes = $this->catalog->routes($vid);
        $b = '<h1>Tìm chuyến tiếp theo</h1><form class="card row" method="get" action="/next">'
            . $this->select('stop_id', '— Chọn trạm —', array_column($stops, 'stop_name', 'stop_id'), $p['stop_id'], true)
            . '<input type="date" name="date" value="' . Text::h($p['date']) . '" required> <input name="time" value="' . Text::h($p['time']) . '" size="6" placeholder="HH:MM" title="HH:MM, có thể nhập 24:00–47:59">'
            . $this->select('route_id', 'Mọi tuyến', array_column($routes, 'route_id', 'route_id'), $p['route_id'])
            . '<input type="number" name="limit" min="1" max="50" value="' . Text::h($p['limit']) . '" style="width:4.5em"><button>Tìm</button></form>';
        if ($p['stop_id'] !== '') {
            $ymd = Time::normalizeDate($p['date']) ?? '';
            $r = $this->dep->next($vid, $p['stop_id'], $ymd, $p['time'], max(1, min(50, (int)$p['limit'])), $p['route_id'] ?: null);
            $b .= $this->nextTable($r['trips'], $r['message']);
            $b .= $this->saveForm('next', $p);
        }
        $this->page('Chuyến tiếp theo', $b);
    }

    private function nextTable(array $trips, ?string $msg): string
    {
        if (!$trips) {
            return '<div class="card warn"><b>Không có chuyến.</b> ' . Text::h($msg ?? '') . '</div>';
        }
        $o = '<table><tr><th>Giờ rời (GTFS)</th><th>Giờ thật</th><th>Ngày hoạt động</th><th>Tuyến</th><th>Chuyến</th><th>Hướng đi</th><th>Ghi chú</th></tr>';
        foreach ($trips as $t) {
            $note = [];
            if ($t['next_day']) { $note[] = 'sau nửa đêm, thuộc ngày hoạt động ' . Time::pretty($t['service_date']); }
            if ($t['via'] === 'added') { $note[] = 'dịch vụ được thêm theo ngày ngoại lệ'; }
            $o .= '<tr><td><b>' . $t['departure_text'] . '</b></td><td>' . $t['clock'] . ' ' . Time::pretty($t['real_date']) . '</td><td>' . Time::pretty($t['service_date']) . '</td><td>' . Text::h($t['route_name']) . '</td><td><a href="/trip?id=' . urlencode($t['trip_id']) . '">' . Text::h($t['trip_id']) . '</a></td><td>' . Text::h($t['headsign']) . '</td><td class="muted">' . Text::h(implode('; ', $note)) . '</td></tr>';
        }
        return $o . '</table>';
    }

    private function comparePage(array $q): void
    {
        $vs = array_values(array_filter($this->versions->all(), static fn($v) => $v['status'] === 'valid'));
        $opts = [];
        foreach ($vs as $v) { $opts[$v['id']] = '#' . $v['id'] . ' ' . $v['label']; }
        $a = (int)($q['a'] ?? 0);
        $bId = (int)($q['b'] ?? 0);
        $out = '<h1>So sánh hai bản lịch</h1><form class="card row" method="get" action="/compare">Từ '
            . $this->select('a', '— bản cũ —', $opts, (string)$a, true) . ' sang ' . $this->select('b', '— bản mới —', $opts, (string)$bId, true);
        $routeOpts = [];
        if ($a && $bId) { foreach ($this->catalog->routes($bId) as $r) { $routeOpts[$r['route_id']] = $r['route_id']; } }
        $out .= $this->select('route', 'Mọi tuyến', $routeOpts, (string)($q['route'] ?? '')) . '<button>So sánh</button></form>';
        if ($a && $bId && isset($opts[$a], $opts[$bId])) {
            $d = (new Diff($this->pdo))->compare($a, $bId, ($q['route'] ?? '') ?: null);
            $s = $d['summary'];
            $out .= "<div class='grid'><div class='card ok'><b>{$s['trips_added']}</b> chuyến mới</div><div class='card bad'><b>{$s['trips_removed']}</b> chuyến bị xóa</div><div class='card warn'><b>{$s['trips_changed']}</b> chuyến đổi giờ/thông tin</div><div class='card'><b>{$s['routes_changed']}</b> tuyến đổi tên</div><div class='card warn'><b>{$s['services_changed']}</b> dịch vụ đổi lịch</div></div>"
                . "<p><a href='/export/diff?a=$a&b=$bId" . (($q['route'] ?? '') ? '&route=' . urlencode($q['route']) : '') . "'>Xuất kết quả so sánh (CSV)</a></p>";
            foreach ($d['routes']['added'] as $r) { $out .= '<p>➕ Tuyến mới: ' . Text::h($r['route_id'] . ' ' . $r['name']) . '</p>'; }
            foreach ($d['routes']['removed'] as $r) { $out .= '<p>➖ Tuyến bị xóa: ' . Text::h($r['route_id'] . ' ' . $r['name']) . '</p>'; }
            foreach ($d['routes']['changed'] as $r) { $out .= '<p>✏️ Tuyến ' . Text::h($r['route_id']) . ' đổi tên: “' . Text::h($r['old']) . '” → “' . Text::h($r['new']) . '”</p>'; }
            if ($d['services']) {
                $out .= '<h2>Dịch vụ (lịch hoạt động) thay đổi</h2><table><tr><th>service_id</th><th>Loại</th><th>Chi tiết</th><th>Chuyến ảnh hưởng</th></tr>';
                $kinds = ['added' => 'Mới', 'removed' => 'Bị xóa', 'changed' => 'Đổi'];
                foreach ($d['services'] as $sv) {
                    $out .= '<tr><td>' . Text::h($sv['service_id']) . '</td><td>' . $kinds[$sv['kind']] . '</td><td>' . Text::h(implode('; ', $sv['details'])) . '</td><td>' . (int)$sv['affected_trips'] . '</td></tr>';
                }
                $out .= '</table>';
            }
            if ($d['per_route']) {
                $out .= '<h2>Theo tuyến</h2><table><tr><th>Tuyến</th><th>Mới</th><th>Xóa</th><th>Đổi</th></tr>';
                foreach ($d['per_route'] as $rid => $c) { $out .= '<tr><td>' . Text::h($rid) . '</td><td>' . ($c['added'] ?? 0) . '</td><td>' . ($c['removed'] ?? 0) . '</td><td>' . ($c['changed'] ?? 0) . '</td></tr>'; }
                $out .= '</table>';
            }
            $out .= '<h2>Chuyến mới</h2>' . $this->tripList($d['trips']['added']) . '<h2>Chuyến bị xóa</h2>' . $this->tripList($d['trips']['removed']) . '<h2>Giờ được sửa</h2>';
            $out .= $d['trips']['changed'] ? '<table><tr><th>Chuyến</th><th>Thay đổi</th></tr>' : '<p class="muted">Không có.</p>';
            foreach ($d['trips']['changed'] as $t) {
                $lines = [];
                foreach ($t['changes'] as $c) {
                    $lines[] = match ($c['type']) {
                        'time' => "trạm {$c['stop_id']} (#{$c['seq']}) {$c['field']}: {$c['old']} → {$c['new']}",
                        'attr' => "{$c['field']}: {$c['old']} → {$c['new']}",
                        'stop_added' => "thêm trạm {$c['stop_id']} (#{$c['seq']})",
                        'stop_removed' => "bỏ trạm {$c['stop_id']} (#{$c['seq']})",
                        default => "đổi trạm #{$c['seq']}: {$c['old']} → {$c['new']}",
                    };
                }
                $out .= '<tr><td>' . Text::h($t['trip_id']) . '</td><td>' . Text::h(implode(' | ', array_slice($lines, 0, 6))) . (count($lines) > 6 ? ' … (+' . (count($lines) - 6) . ')' : '') . '</td></tr>';
            }
            $out .= $d['trips']['changed'] ? '</table>' : '';
        }
        $this->page('So sánh', $out);
    }

    private function tripList(array $trips): string
    {
        if (!$trips) { return '<p class="muted">Không có.</p>'; }
        return '<p>' . implode(', ', array_map(static fn($t) => Text::h($t['trip_id']), $trips)) . '</p>';
    }

    private function savedPage(array $q): void
    {
        $vs = array_values(array_filter($this->versions->all(), static fn($v) => $v['status'] === 'valid'));
        $sel = (int)($q['v'] ?? ($this->versions->activeId() ?? 0));
        $opts = [];
        foreach ($vs as $v) { $opts[$v['id']] = '#' . $v['id'] . ' ' . $v['label'] . ((int)$v['id'] === $this->versions->activeId() ? ' (đang dùng)' : ''); }
        $b = '<h1>Truy vấn mẫu</h1><form class="card row" method="get" action="/saved">Chạy trên phiên bản ' . $this->select('v', '— chọn —', $opts, (string)$sel, true) . '<button>Chạy lại tất cả</button></form>';
        $all = $this->saved->all();
        if (!$all) { $b .= '<div class="card">Chưa có truy vấn mẫu. Lưu từ trang <a href="/next">Chuyến tiếp theo</a> hoặc <a href="/search">Tra cứu</a>.</div>'; }
        foreach ($all as $s) {
            $b .= '<div class="card"><div class="row"><h2 style="margin:0">' . Text::h($s['name']) . '</h2><span class="badge">' . SavedQueries::KINDS[$s['kind']] . '</span><span class="muted">' . Text::h(json_encode($s['params'], JSON_UNESCAPED_UNICODE)) . '</span>'
                . '<form method="post" action="/saved/delete">' . $this->csrf() . '<input type="hidden" name="id" value="' . $s['id'] . '"><button class="danger">Xóa</button></form></div>';
            if ($sel && isset($opts[$sel])) {
                $r = $this->saved->run($s, $sel);
                $b .= $r['kind'] === 'next' ? $this->nextTable($r['rows'], $r['message']) : '<p>' . count($r['rows']) . ' kết quả.</p>' . $this->resultTable($r['kind'], array_slice($r['rows'], 0, 10));
            }
            $b .= '</div>';
        }
        $this->page('Truy vấn mẫu', $b);
    }

    private function exportSchedule(array $q): void
    {
        $vid = $this->needActive();
        $this->download('lich_' . ($q['route'] ?? 'tatca') . '.csv', (new Export($this->pdo))->schedule($vid, ($q['route'] ?? '') ?: null, ($q['stop'] ?? '') ?: null));
    }

    private function exportDiff(array $q): void
    {
        $d = (new Diff($this->pdo))->compare((int)($q['a'] ?? 0), (int)($q['b'] ?? 0), ($q['route'] ?? '') ?: null);
        $this->download('so_sanh.csv', (new Export($this->pdo))->diff($d));
    }

    // ---------- Tiện ích hiển thị ----------
    private function saveForm(string $kind, array $params): string
    {
        $h = '<form class="card row" method="post" action="/saved/save">' . $this->csrf() . '<input type="hidden" name="kind" value="' . Text::h($kind) . '">';
        foreach ($params as $k => $v) {
            if ($v !== '' && $v !== null) { $h .= '<input type="hidden" name="p[' . Text::h((string)$k) . ']" value="' . Text::h((string)$v) . '">'; }
        }
        return $h . '<input name="name" placeholder="Đặt tên để lưu truy vấn mẫu" required maxlength="100"> <button>Lưu truy vấn</button></form>';
    }

    private function select(string $name, string $placeholder, array $options, string $selected, bool $withKey = false): string
    {
        $o = '<select name="' . $name . '"><option value="">' . Text::h($placeholder) . '</option>';
        foreach ($options as $k => $v) {
            $label = $withKey && !str_starts_with((string)$v, '#') ? "$v ($k)" : (string)$v;
            $o .= '<option value="' . Text::h((string)$k) . '"' . ((string)$k === $selected ? ' selected' : '') . '>' . Text::h($label) . '</option>';
        }
        return $o . '</select>';
    }

    private function miniForm(string $action, int $id, string $label, string $cls = ''): string
    {
        return '<form method="post" action="' . $action . '" class="inline">' . $this->csrf() . '<input type="hidden" name="id" value="' . $id . '"><button class="' . $cls . '">' . $label . '</button></form>';
    }

    private function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="' . Text::h($_SESSION['csrf']) . '">';
    }

    private function checkCsrf(): void
    {
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            http_response_code(400);
            exit('Phiên làm việc không hợp lệ (CSRF). Hãy tải lại trang.');
        }
    }

    private function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }

    private function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }

    private function download(string $name, string $content): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . '"');
        echo $content;
        exit;
    }

    private function notFound(): never
    {
        http_response_code(404);
        $this->page('Không tìm thấy', '<h1>404</h1><p>Không tìm thấy trang hoặc dữ liệu.</p>');
        exit;
    }

    private function page(string $title, string $body): void
    {
        $a = $this->versions->active();
        $flash = '';
        foreach ($_SESSION['flash'] ?? [] as [$t, $m]) { $flash .= '<div class="card ' . ($t === 'ok' ? 'ok' : 'bad') . '">' . Text::h($m) . '</div>'; }
        unset($_SESSION['flash']);
        $cur = rtrim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/') ?: '/';
        $nav = '';
        foreach (['/' => 'Trang chủ', '/next' => 'Chuyến tiếp theo', '/search' => 'Tra cứu', '/compare' => 'So sánh', '/saved' => 'Truy vấn mẫu', '/versions' => 'Phiên bản lịch'] as $href => $l) {
            $on = $href === '/' ? $cur === '/' : str_starts_with($cur, $href);
            $nav .= "<a href='$href'" . ($on ? " class='on' aria-current='page'" : '') . ">$l</a>";
        }
        $who = $this->actor->isManager()
            ? '<form method="post" action="/logout" class="inline">' . $this->csrf() . '<button>Đăng xuất (quản lý)</button></form>' : '<span class="muted">Khách</span>';
        $active = $a ? 'Bản đang dùng: <b>#' . $a['id'] . ' ' . Text::h($a['label']) . '</b>' : '<span class="pill none">Chưa có bản đang dùng</span>';
        $icon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%231a5fd1'/%3E%3Cpath d='M9 22V10h8a4 4 0 010 8h-3' fill='none' stroke='white' stroke-width='3' stroke-linecap='round'/%3E%3C/svg%3E";
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark"><title>' . Text::h($title) . ' · Lịch trình GTFS</title><link rel="icon" href="' . $icon . '"><link rel="stylesheet" href="/assets/style.css"></head><body>'
            . '<a class="skip" href="#main">Bỏ qua điều hướng</a>'
            . '<header class="top"><div class="bar"><a class="brand" href="/"><i>G</i>Lịch trình GTFS</a><nav aria-label="Điều hướng chính">' . $nav . '</nav>'
            . '<div class="who">' . $active . ' · ' . $who . '</div></div></header>'
            . '<main id="main">' . $flash . $body . '</main>'
            . '<footer class="foot">Dữ liệu GTFS tĩnh · Giờ gắn với ngày hoạt động · MIT</footer></body></html>';
    }
}
