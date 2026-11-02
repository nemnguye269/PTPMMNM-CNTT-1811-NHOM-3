<?php
declare(strict_types=1);
namespace Gtfs;

use PDO;

/** Bộ điều khiển web (PHP thuần): định tuyến, quyền, biểu mẫu và hiển thị. */
final class App
{
    use TicketUi;

    private Tickets $tickets;
    private Versions $versions;
    private Catalog $catalog;
    private Departures $dep;
    private SavedQueries $saved;
    private Auth $auth;
    private MapData $mapData;
    private Actor $actor;

    public function __construct(private PDO $pdo)
    {
        $this->versions = new Versions($pdo);
        $this->catalog = new Catalog($pdo);
        $this->dep = new Departures($pdo);
        $this->saved = new SavedQueries($pdo);
        $this->auth = new Auth($pdo);
        $this->mapData = new MapData($pdo);
        $this->tickets = new Tickets($pdo);
        $this->auth->ensureAdmin();
        $this->actor = !empty($_SESSION['manager'])
            ? Actor::manager((string)($_SESSION['admin_name'] ?? 'quanly'))
            : Actor::guest();
    }

    public function handle(string $method, string $path): void
    {
        $q = $_GET;
        try {
            if ($method === 'POST' && $path === '/api/payment/webhook') {
                $this->paymentWebhook(); // webhook từ dịch vụ ngoài: xác thực bằng khóa API, không dùng CSRF
            }
            if ($method === 'POST') {
                $this->checkCsrf();
                $this->post($path);
                return;
            }
            match ($path) {
                '/' => $this->home(),
                '/map' => $this->mapPage(),
                '/api/map/routes' => $this->mapRoutesApi(),
                '/api/map/route' => $this->mapRouteApi((string)($q['route_id'] ?? '')),
                '/api/map/search' => $this->mapSearchApi((string)($q['q'] ?? '')),
                '/api/map/waits' => $this->mapWaitsApi($q),
                '/api/map/waits-batch' => $this->mapWaitsBatchApi($q),
                '/tickets' => $this->ticketBuyPage($q),
                '/tickets/pay' => $this->ticketPayPage((string)($q['code'] ?? '')),
                '/tickets/view' => $this->ticketViewPage((string)($q['code'] ?? '')),
                '/tickets/mine' => $this->ticketMinePage(),
                '/tickets/manage' => $this->ticketManagePage(),
                '/api/tickets/status' => $this->ticketStatusApi((string)($q['code'] ?? '')),
                '/register' => $this->registerPage(),
                '/user/login' => $this->userLoginPage(),
                '/admin' => $this->adminLoginPage(),
                '/versions' => $this->versionsPage(),
                '/versions/errors' => $this->errorsPage((int)($q['id'] ?? 0)),
                '/search' => $this->searchPage($q),
                '/trip' => $this->tripPage((string)($q['id'] ?? '')),
                '/next' => $this->nextPage($q),
                '/compare' => $this->comparePage($q),
                '/saved' => $this->savedPage($q),
                '/export/schedule' => $this->exportSchedule($q),
                '/export/errors' => $this->exportErrors((int)($q['id'] ?? 0)),
                '/export/diff' => $this->exportDiff($q),
                default => $this->notFound(),
            };
        } catch (ForbiddenException $e) {
            http_response_code(403);
            $this->flash('error', $e->getMessage());
            $this->redirect($this->actor->isManager() ? '/versions' : '/');
        }
    }

    /** Chỉ quản lý mới được vào khu "Phiên bản lịch"; khách/người dùng thường nhận 404. */
    private function adminOnly(): void
    {
        if (!$this->actor->isManager()) {
            $this->notFound();
        }
    }

    private function exportErrors(int $id): void
    {
        $this->adminOnly();
        $this->download('loi_nhap_v' . $id . '.csv', (new Export($this->pdo))->importErrors($id));
    }

    private function adminLoginPage(): void
    {
        if ($this->actor->isManager()) {
            $this->redirect('/versions');
        }
        $b = '<section class="auth-shell">'
            . '<div class="auth-intro">'
            . '<span class="eyebrow">KHU VỰC QUẢN TRỊ</span>'
            . '<h1>Quản lý dữ liệu<br><span>lịch GTFS</span></h1>'
            . '<p>Chỉ tài khoản quản trị viên mới được nhập bộ lịch, đổi phiên bản đang dùng và xem nhật ký thao tác.</p>'
            . '</div>'
            . '<form class="auth-card" method="post" action="/login">' . $this->csrf()
            . '<div class="auth-card-head"><div class="auth-logo"><img src="/assets/logo.png" alt="" width="40" height="40"></div><div><h2>Đăng nhập quản trị</h2><p>Dành cho quản trị viên.</p></div></div>'
            . '<label class="field"><span>Tên đăng nhập quản trị</span><div class="input-wrap">' . $this->icon('user') . '<input name="identity" required autocomplete="username" placeholder="admin"></div></label>'
            . '<label class="field"><span>Mật khẩu</span><div class="input-wrap">' . $this->icon('lock') . '<input type="password" name="password" required autocomplete="current-password" placeholder="Nhập mật khẩu"></div></label>'
            . '<button class="btn-primary btn-block" type="submit">' . $this->icon('login') . '<span>Đăng nhập</span></button>'
            . '</form></section>';
        $this->page('Đăng nhập quản trị', $b);
    }

    private function startAdminSession(array $admin): void
    {
        session_regenerate_id(true);
        unset($_SESSION['user_id'], $_SESSION['admin_fails'], $_SESSION['admin_lock_until']);
        $_SESSION['manager'] = true;
        $_SESSION['admin_name'] = (string)$admin['username'];
    }

    // ---------- POST ----------
    private function post(string $path): void
    {
        if (str_starts_with($path, '/tickets/')) {
            $this->ticketPost($path);
        }
        if (str_starts_with($path, '/versions/') && !$this->actor->isManager()) {
            throw new ForbiddenException('Chỉ người quản lý mới được thao tác phiên bản lịch.');
        }
        switch ($path) {
            case '/register':
                try {
                    $password = (string)($_POST['password'] ?? '');
                    $confirm = (string)($_POST['confirm_password'] ?? '');
                    if ($password !== $confirm) {
                        throw new \InvalidArgumentException('Mật khẩu nhập lại không khớp.');
                    }
                    $id = $this->auth->register(
                        (string)($_POST['username'] ?? ''),
                        (string)($_POST['email'] ?? ''),
                        $password
                    );
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $id;
                    $this->flash('ok', 'Đăng ký tài khoản thành công.');
                    $this->redirect('/');
                } catch (\InvalidArgumentException $e) {
                    $this->flash('error', $e->getMessage());
                    $this->redirect('/register');
                }
            case '/user/login':
                $user = $this->auth->login(
                    (string)($_POST['identity'] ?? ''),
                    (string)($_POST['password'] ?? '')
                );
                if (!$user) {
                    $this->flash('error', 'Tên đăng nhập/email hoặc mật khẩu không đúng.');
                    $this->redirect('/user/login');
                }
                if (($user['role'] ?? '') === 'admin') {
                    $this->startAdminSession($user);
                    $this->flash('ok', 'Đã đăng nhập với vai trò người quản lý.');
                    $this->redirect('/versions');
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $this->flash('ok', 'Đăng nhập thành công.');
                $this->redirect('/');
            case '/user/logout':
                unset($_SESSION['user_id'], $_SESSION['manager'], $_SESSION['admin_name']);
                session_regenerate_id(true);
                $this->flash('ok', 'Đã đăng xuất tài khoản.');
                $this->redirect('/');
            case '/login':
                if (($_SESSION['admin_lock_until'] ?? 0) > time()) {
                    $this->flash('error', 'Bạn nhập sai quá nhiều lần. Vui lòng thử lại sau ít phút.');
                    $this->redirect('/admin');
                }
                $admin = $this->auth->loginAdmin((string)($_POST['identity'] ?? ''), (string)($_POST['password'] ?? ''));
                if (!$admin) {
                    $_SESSION['admin_fails'] = (int)($_SESSION['admin_fails'] ?? 0) + 1;
                    if ($_SESSION['admin_fails'] >= 5) {
                        $_SESSION['admin_lock_until'] = time() + 300;
                        $_SESSION['admin_fails'] = 0;
                    }
                    $this->flash('error', 'Sai tài khoản hoặc mật khẩu quản trị.');
                    $this->redirect('/admin');
                }
                $this->startAdminSession($admin);
                $this->flash('ok', 'Đã đăng nhập với vai trò người quản lý.');
                $this->redirect('/versions');
            case '/logout':
                unset($_SESSION['manager'], $_SESSION['admin_name'], $_SESSION['user_id']);
                session_regenerate_id(true);
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
                    $this->saved->save(trim((string)($_POST['name'] ?? '')) ?: 'Truy vấn mẫu', (string)($_POST['kind'] ?? ''), is_array($params) ? $params : []);
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
        // Bộ GTFS lớn (hàng trăm nghìn dòng): nới thời gian chạy và bộ nhớ cho riêng thao tác nhập.
        @set_time_limit(600);
        $lim = trim((string)ini_get('memory_limit'));
        $mult = ['g' => 1073741824, 'm' => 1048576, 'k' => 1024][strtolower(substr($lim, -1))] ?? 1;
        if ($lim !== '-1' && (int)$lim * $mult < 1073741824) {
            @ini_set('memory_limit', '1024M');
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
    private function registerPage(): void
    {
        if (!empty($_SESSION['user_id'])) {
            $this->redirect('/');
        }

        $b = '<section class="auth-shell">'
            . '<div class="auth-intro">'
            . '<span class="eyebrow">TÀI KHOẢN NGƯỜI DÙNG</span>'
            . '<h1>Tham gia hệ thống<br><span>tra cứu GTFS</span></h1>'
            . '<p>Tạo tài khoản để sử dụng hệ thống thuận tiện hơn và sẵn sàng cho các chức năng cá nhân hóa sau này.</p>'
            . '<div class="auth-benefits">'
            . '<div>' . $this->icon('check') . '<span>Đăng nhập nhanh bằng tên tài khoản hoặc email</span></div>'
            . '<div>' . $this->icon('shield') . '<span>Mật khẩu được lưu dưới dạng mã hóa an toàn</span></div>'
            . '<div>' . $this->icon('route') . '<span>Tiếp tục tra cứu tuyến, trạm và chuyến như bình thường</span></div>'
            . '</div></div>'
            . '<form class="auth-card" method="post" action="/register">' . $this->csrf()
            . '<div class="auth-card-head"><div class="auth-logo"><img src="/assets/logo.png" alt="" width="40" height="40"></div><div><h2>Tạo tài khoản</h2><p>Điền thông tin bên dưới để bắt đầu.</p></div></div>'
            . '<label class="field"><span>Tên đăng nhập</span><div class="input-wrap">' . $this->icon('user') . '<input name="username" required minlength="3" maxlength="50" autocomplete="username" placeholder="Ví dụ: hung123"></div></label>'
            . '<label class="field"><span>Email</span><div class="input-wrap">' . $this->icon('mail') . '<input type="email" name="email" required autocomplete="email" placeholder="ban@example.com"></div></label>'
            . '<label class="field"><span>Mật khẩu</span><div class="input-wrap">' . $this->icon('lock') . '<input type="password" name="password" required minlength="6" autocomplete="new-password" placeholder="Ít nhất 6 ký tự"></div></label>'
            . '<label class="field"><span>Nhập lại mật khẩu</span><div class="input-wrap">' . $this->icon('lock') . '<input type="password" name="confirm_password" required minlength="6" autocomplete="new-password" placeholder="Nhập lại mật khẩu"></div></label>'
            . '<button class="btn-primary btn-block" type="submit">' . $this->icon('user-plus') . '<span>Đăng ký tài khoản</span></button>'
            . '<p class="auth-switch">Đã có tài khoản? <a href="/user/login">Đăng nhập ngay</a></p>'
            . '</form></section>';

        $this->page('Đăng ký', $b);
    }
    private function userLoginPage(): void
    {
        if (!empty($_SESSION['user_id'])) {
            $this->redirect('/');
        }

        $b = '<section class="auth-shell">'
            . '<div class="auth-intro">'
            . '<span class="eyebrow">CHÀO MỪNG TRỞ LẠI</span>'
            . '<h1>Tra cứu hành trình<br><span>nhanh và rõ ràng</span></h1>'
            . '<p>Đăng nhập để tiếp tục sử dụng hệ thống. Bạn vẫn có thể tra cứu dữ liệu GTFS với vai trò khách.</p>'
            . '<div class="auth-benefits">'
            . '<div>' . $this->icon('clock') . '<span>Tìm chuyến tiếp theo theo ngày và giờ</span></div>'
            . '<div>' . $this->icon('search') . '<span>Tra cứu nhanh tuyến, trạm và chuyến</span></div>'
            . '<div>' . $this->icon('compare') . '<span>So sánh thay đổi giữa các phiên bản lịch</span></div>'
            . '</div></div>'
            . '<form class="auth-card" method="post" action="/user/login">' . $this->csrf()
            . '<div class="auth-card-head"><div class="auth-logo"><img src="/assets/logo.png" alt="" width="40" height="40"></div><div><h2>Đăng nhập</h2><p>Nhập tài khoản của bạn để tiếp tục.</p></div></div>'
            . '<label class="field"><span>Tên đăng nhập hoặc email</span><div class="input-wrap">' . $this->icon('user') . '<input name="identity" required autocomplete="username" placeholder="Tên đăng nhập hoặc email"></div></label>'
            . '<label class="field"><span>Mật khẩu</span><div class="input-wrap">' . $this->icon('lock') . '<input type="password" name="password" required autocomplete="current-password" placeholder="Nhập mật khẩu"></div></label>'
            . '<button class="btn-primary btn-block" type="submit">' . $this->icon('login') . '<span>Đăng nhập</span></button>'
            . '<p class="auth-switch">Chưa có tài khoản? <a href="/register">Đăng ký miễn phí</a></p>'
            . '</form></section>';

        $this->page('Đăng nhập', $b);
    }
    private function mapPage(): void
    {
        $this->needActive();

        $body = '<section class="map-shell">'
            . '<aside class="map-sidebar">'

            // Màn 1: danh sách tuyến.
            . '<section id="mapBrowsePanel" class="map-side-panel">'
            . '<div class="map-sidebar-head">'
            . '<span class="eyebrow">BẢN ĐỒ TUYẾN XE</span>'
            . '<h1>Khám phá tuyến xe buýt</h1>'
            . '<p>Chọn một tuyến để xem lộ trình, trạm dừng và thời gian.</p>'
            . '</div>'
            . '<label class="map-search"><span class="sr-only">Tìm điểm đến hoặc tuyến</span>'
            . '<input id="mapRouteSearch" type="search" placeholder="Nhập điểm đến (vd: Times City) hoặc số tuyến..." autocomplete="off">'
            . '</label>'
            . '<div id="mapStopResults" class="map-stop-results" hidden></div>'
            . '<div class="map-toolbar">'
            . '<span id="mapRouteCount" class="muted">Đang tải tuyến...</span>'
            . '<button id="mapShowAll" class="map-ghost-btn" type="button">Hiện tất cả</button>'
            . '</div>'
            . '<div id="mapRouteList" class="map-route-list" aria-live="polite"></div>'
            . '</section>'

            // Màn 2: chi tiết tuyến kiểu VinBus.
            . '<section id="mapDetailPanel" class="map-side-panel map-detail-panel" hidden>'
            . '<div class="route-detail-head">'
            . '<button id="mapBackToRoutes" class="map-back-btn" type="button" aria-label="Quay lại danh sách tuyến">←</button>'
            . '<div><span class="eyebrow">LỘ TRÌNH</span><h2 id="mapDetailRouteName">Tuyến</h2><p id="mapDetailRouteSubtitle">Đang tải thông tin...</p></div>'
            . '</div>'

            . '<div id="mapDirectionTabs" class="map-direction-switch" aria-label="Chọn chiều tuyến"></div>'

            . '<div id="mapInfoTabs" class="map-info-tabs" role="tablist" aria-label="Chi tiết tuyến">'
            . '<button class="map-info-tab" type="button" data-tab="schedule" role="tab">Biểu đồ giờ</button>'
            . '<button class="map-info-tab active" type="button" data-tab="stops" role="tab">Trạm dừng</button>'
            . '<button class="map-info-tab" type="button" data-tab="info" role="tab">Thông tin</button>'
            . '</div>'

            . '<div class="map-detail-content">'
            . '<section id="mapPanelSchedule" class="map-tab-panel" data-panel="schedule" hidden>'
            . '<div id="mapRouteSchedule" class="map-schedule-list"></div>'
            . '</section>'
            . '<section id="mapPanelStops" class="map-tab-panel" data-panel="stops">'
            . '<div id="mapStopTimeline" class="map-stop-timeline"></div>'
            . '</section>'
            . '<section id="mapPanelInfo" class="map-tab-panel" data-panel="info" hidden>'
            . '<div id="mapRouteInfo" class="map-route-info"></div>'
            . '</section>'
            . '</div>'
            . '</section>'

            . '</aside>'

            . '<section class="map-stage">'
            . '<div class="map-mapbar">'
            . '<div><span class="section-kicker">TUYẾN ĐANG HIỂN THỊ</span><h2 id="mapRouteTitle">Chọn một tuyến</h2><p id="mapRouteSubtitle">Chọn tuyến ở danh sách bên trái để xem lộ trình.</p></div>'
            . '</div>'
            . '<div class="map-canvas-wrap">'
            . '<div id="busMap" class="bus-map" aria-label="Bản đồ tuyến xe buýt"></div>'
            . '<div id="mapLoading" class="map-loading" hidden><span class="map-spinner"></span><span>Đang tải dữ liệu tuyến...</span></div>'
            . '</div>'
            . '</section>'

            . '</section>';

        $this->page('Bản đồ tuyến xe', $body);
    }

    private function mapRoutesApi(): never
    {
        $versionId = $this->versions->activeId();
        if (!$versionId) {
            $this->jsonResponse([
                'ok' => false,
                'error' => 'Chưa có phiên bản lịch đang sử dụng.',
            ], 404);
        }

        $routes = [];
        foreach ($this->mapData->routes($versionId) as $route) {
            $short = trim((string)($route['route_short_name'] ?? ''));
            $long = trim((string)($route['route_long_name'] ?? ''));
            $name = trim($short . ' ' . $long);

            $routes[] = [
                'id' => (string)$route['route_id'],
                'short_name' => $short,
                'long_name' => $long,
                'name' => $name !== '' ? $name : (string)$route['route_id'],
                'type' => $route['route_type'] !== null ? (int)$route['route_type'] : null,
                'trip_count' => (int)($route['trip_count'] ?? 0),
            ];
        }

        $this->jsonResponse([
            'ok' => true,
            'version_id' => $versionId,
            'routes' => $routes,
        ]);
    }

    private function mapSearchApi(string $query): never
    {
        $versionId = $this->versions->activeId();
        if (!$versionId) {
            $this->jsonResponse(['ok' => false, 'error' => 'Chưa có phiên bản lịch đang sử dụng.'], 404);
        }
        $this->jsonResponse([
            'ok' => true,
            'stops' => $this->mapData->searchStops($versionId, mb_substr(trim($query), 0, 80)),
        ]);
    }

    private function waitParams(array $q): array
    {
        $vid = $this->versions->activeId();
        if (!$vid) {
            $this->jsonResponse(['ok' => false, 'error' => 'Chưa có phiên bản lịch đang sử dụng.'], 404);
        }
        $routeId = trim((string)($q['route_id'] ?? ''));
        $dir = (isset($q['direction_id']) && $q['direction_id'] !== '') ? (int)$q['direction_id'] : null;
        return [$vid, $routeId !== '' ? $routeId : null, $dir];
    }

    /** Thời gian chờ các chuyến sắp tới tại một trạm. */
    private function mapWaitsApi(array $q): never
    {
        [$vid, $routeId, $dir] = $this->waitParams($q);
        $stopId = trim((string)($q['stop_id'] ?? ''));
        if ($stopId === '') {
            $this->jsonResponse(['ok' => false, 'error' => 'Thiếu stop_id.'], 400);
        }
        $limit = max(1, min(10, (int)($q['limit'] ?? 5)));
        $this->jsonResponse(['ok' => true] + (new Waits($this->dep))->forStop($vid, $stopId, $routeId, $dir, $limit));
    }

    /** Chuyến gần nhất tại từng trạm của một tuyến (hiện trong danh sách trạm). */
    private function mapWaitsBatchApi(array $q): never
    {
        [$vid, $routeId, $dir] = $this->waitParams($q);
        $ids = array_values(array_filter(array_map('trim', explode(',', (string)($q['stops'] ?? '')))));
        if (!$ids) {
            $this->jsonResponse(['ok' => false, 'error' => 'Thiếu danh sách trạm.'], 400);
        }
        $this->jsonResponse(['ok' => true] + (new Waits($this->dep))->forStops($vid, $ids, $routeId, $dir));
    }

    private function mapRouteApi(string $routeId): never
    {
        $versionId = $this->versions->activeId();
        if (!$versionId) {
            $this->jsonResponse([
                'ok' => false,
                'error' => 'Chưa có phiên bản lịch đang sử dụng.',
            ], 404);
        }

        $routeId = trim($routeId);
        if ($routeId === '') {
            $this->jsonResponse([
                'ok' => false,
                'error' => 'Thiếu route_id.',
            ], 400);
        }

        $data = $this->mapData->routeDetail($versionId, $routeId);
        if (!$data) {
            $this->jsonResponse([
                'ok' => false,
                'error' => 'Không tìm thấy tuyến.',
            ], 404);
        }

        $this->jsonResponse([
            'ok' => true,
            'version_id' => $versionId,
            'data' => $data,
        ]);
    }

    private function home(): void
    {
        $a = $this->versions->active();
        $userName = '';
        if (!empty($_SESSION['user_id'])) {
            $user = $this->auth->find((int)$_SESSION['user_id']);
            $userName = $user ? (string)$user['username'] : '';
        }

        $greeting = $userName !== ''
            ? 'Xin chào, ' . Text::h($userName)
            : 'Hệ thống tra cứu GTFS';

        $b = '<section class="hero">'
            . '<div class="hero-copy">'
            . '<span class="eyebrow">GIAO THÔNG CÔNG CỘNG · GTFS</span>'
            . '<h1>' . $greeting . '<br><span>Tra cứu hành trình dễ dàng hơn.</span></h1>'
            . '<p>Khám phá tuyến, trạm, chuyến và thời gian khởi hành từ dữ liệu lịch trình GTFS tĩnh trên một giao diện trực quan.</p>'
            . '<div class="hero-actions">'
            . '<a class="btn-link primary" href="/next">' . $this->icon('clock') . '<span>Tìm chuyến tiếp theo</span></a>'
            . '<a class="btn-link secondary" href="/search?type=routes">' . $this->icon('search') . '<span>Tra cứu dữ liệu</span></a>'
            . ($this->currentUser() ? '<a class="btn-link secondary" href="/tickets">' . $this->icon('ticket') . '<span>Mua vé</span></a>' : '')
            . '</div>'
            . '</div>'
            . '<div class="hero-visual">'
            . '<div class="route-line"></div>'
            . '<div class="route-stop stop-a"><span></span><small>Điểm đi</small></div>'
            . '<div class="route-stop stop-b"><span></span><small>Trung chuyển</small></div>'
            . '<div class="route-stop stop-c"><span></span><small>Điểm đến</small></div>'
            . '<div class="bus-card">' . $this->icon('bus') . '<div><b>Nam Thành Hùng</b><small>Lịch trình thông minh</small></div></div>'
            . '</div>'
            . '</section>';

        if (!$a) {
            $b .= '<div class="empty-state card">'
                . '<div class="empty-icon">' . $this->icon('database') . '</div>'
                . '<div><h2>Chưa có phiên bản lịch đang dùng</h2><p>'
                . ($this->actor->isManager()
                    ? 'Hãy vào <a href="/versions">Phiên bản lịch</a> để nhập dữ liệu GTFS và chọn một phiên bản.'
                    : 'Người quản lý cần nhập và kích hoạt một phiên bản lịch trước khi tra cứu.')
                . '</p></div></div>';
            $this->page('Trang chủ', $b);
            return;
        }

        $c = $this->catalog->counts((int)$a['id']);
        $b .= '<section class="dashboard-head">'
            . '<div><span class="section-kicker">TỔNG QUAN DỮ LIỆU</span><h2>Phiên bản đang hoạt động</h2></div>'
            . '<div class="active-version">' . $this->icon('check-circle') . '<span><small>Đang dùng</small><b>#' . $a['id'] . ' ' . Text::h($a['label']) . '</b></span></div>'
            . '</section>';

        $b .= '<section class="stats-grid">'
            . '<article class="stat-card"><div class="stat-icon blue">' . $this->icon('route') . '</div><div><strong>' . (int)$c['routes'] . '</strong><span>Tuyến</span></div></article>'
            . '<article class="stat-card"><div class="stat-icon cyan">' . $this->icon('pin') . '</div><div><strong>' . (int)$c['stops'] . '</strong><span>Trạm</span></div></article>'
            . '<article class="stat-card"><div class="stat-icon violet">' . $this->icon('bus') . '</div><div><strong>' . (int)$c['trips'] . '</strong><span>Chuyến</span></div></article>'
            . '<article class="stat-card"><div class="stat-icon green">' . $this->icon('list') . '</div><div><strong>' . (int)$c['stop_times'] . '</strong><span>Lần dừng</span></div></article>'
            . '</section>';

        $b .= '<section class="section-title"><div><span class="section-kicker">CÔNG CỤ</span><h2>Bạn muốn làm gì?</h2><p>Truy cập nhanh các chức năng chính của hệ thống.</p></div></section>'
            . '<section class="feature-grid">'
            . '<a class="feature-card" href="/next"><div class="feature-icon">' . $this->icon('clock') . '</div><div><h3>Chuyến tiếp theo</h3><p>Chọn trạm, ngày và giờ để tìm các chuyến sắp khởi hành.</p></div><span class="feature-arrow">' . $this->icon('arrow') . '</span></a>'
            . '<a class="feature-card" href="/search?type=routes"><div class="feature-icon">' . $this->icon('search') . '</div><div><h3>Tra cứu dữ liệu</h3><p>Tìm tuyến, trạm và chuyến theo tên, mã hoặc bộ lọc.</p></div><span class="feature-arrow">' . $this->icon('arrow') . '</span></a>'
            . '<a class="feature-card" href="/map"><div class="feature-icon">' . $this->icon('map') . '</div><div><h3>Bản đồ tuyến xe</h3><p>Xem hướng đi và các trạm của từng tuyến trên bản đồ tương tác.</p></div><span class="feature-arrow">' . $this->icon('arrow') . '</span></a>'
            . '<a class="feature-card" href="/compare"><div class="feature-icon">' . $this->icon('compare') . '</div><div><h3>So sánh lịch</h3><p>Xem tuyến và chuyến nào được thêm, xóa hoặc thay đổi giờ.</p></div><span class="feature-arrow">' . $this->icon('arrow') . '</span></a>'
            . '<a class="feature-card" href="/saved"><div class="feature-icon">' . $this->icon('bookmark') . '</div><div><h3>Truy vấn mẫu</h3><p>Lưu các điều kiện thường dùng và chạy lại trên phiên bản mới.</p></div><span class="feature-arrow">' . $this->icon('arrow') . '</span></a>'
            . '</section>';

        $this->page('Trang chủ', $b);
    }
    private function versionsPage(): void
    {
        $this->adminOnly();
        $act = $this->versions->activeId();
        $b = '<h1>Phiên bản lịch</h1>';
        $b .= '<div class="card"><h2>Nhập bộ GTFS tĩnh (.zip)</h2><form method="post" action="/versions/import" enctype="multipart/form-data" class="row">'
            . $this->csrf() . '<input name="label" placeholder="Tên phiên bản, vd: Lịch mùa đông 2026" required maxlength="100"> <input type="file" name="zip" accept=".zip" required> <button>Nhập &amp; kiểm tra</button></form>'
            . '<p class="muted">Bản có lỗi chỉ được lưu để xem lỗi, không thể đưa vào sử dụng và không ảnh hưởng bản đang dùng.</p></div>';
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
        $this->adminOnly();
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
            $this->page('Chưa có lịch', '<div class="card warn">Chưa có phiên bản nào được đưa vào sử dụng.' . ($this->actor->isManager() ? ' <a href="/versions">Quản lý phiên bản</a>' : '') . '</div>');
            exit;
        }
        return $id;
    }

    private function searchPage(array $q): void
    {
        $vid = $this->needActive();
        $type = in_array($q['type'] ?? '', ['routes', 'stops', 'trips'], true) ? $q['type'] : 'routes';
        $f = array_intersect_key($q, array_flip(['q', 'route_id', 'stop_id', 'route_type', 'direction_id', 'date', 'from', 'to']));
        $routes = $this->catalog->routes($vid);
        $stops = $this->catalog->stops($vid, [], 1000);
        $b = '<h1>Tra cứu tuyến, trạm, chuyến</h1><div class="tabs">';
        foreach (['routes' => 'Tuyến', 'stops' => 'Trạm', 'trips' => 'Chuyến'] as $k => $l) {
            $b .= '<a class="' . ($k === $type ? 'on' : '') . '" href="/search?type=' . $k . '">' . $l . '</a>';
        }
        $b .= '</div><form class="card row" method="get" action="/search"><input type="hidden" name="type" value="' . $type . '">'
            ;
        $routeOpts = [];
        foreach ($routes as $r) { $routeOpts[$r['route_id']] = $this->routeLabel($r); }
        if ($type === 'routes') {
            $b .= '<input name="q" value="' . Text::h($f['q'] ?? '') . '" placeholder="Nhập số tuyến hoặc tên tuyến (vd: 01, Mỹ Đình)" autocomplete="off">';
        } elseif ($type === 'stops') {
            $b .= '<input name="q" value="' . Text::h($f['q'] ?? '') . '" placeholder="Nhập tên trạm (vd: Mỹ Đình)" autocomplete="off">';
        } else {
            $dl = '<datalist id="stop-names">';
            $seen = [];
            foreach ($stops as $s) {
                $n = (string)$s['stop_name'];
                if (!isset($seen[$n])) { $seen[$n] = true; $dl .= '<option value="' . Text::h($n) . '">'; }
            }
            $b .= $dl . '</datalist>'
                . '<input name="from" list="stop-names" value="' . Text::h($f['from'] ?? '') . '" placeholder="Điểm đi (vd: Bến xe Mỹ Đình)" autocomplete="off">'
                . '<span class="muted">→</span>'
                . '<input name="to" list="stop-names" value="' . Text::h($f['to'] ?? '') . '" placeholder="Điểm đến (vd: Hà Đông)" autocomplete="off">';
        }
        if ($type !== 'routes') { $b .= $this->select('route_id', 'Mọi tuyến', $routeOpts, $f['route_id'] ?? ''); }
        if ($type === 'routes') { $b .= $this->select('stop_id', 'Đi qua trạm bất kỳ', array_column($stops, 'stop_name', 'stop_id'), $f['stop_id'] ?? ''); }
        if ($type === 'trips') {
            $b .= $this->select('direction_id', 'Hai chiều', ['0' => 'Lượt đi', '1' => 'Lượt về'], $f['direction_id'] ?? '')
                . '<label class="muted">Ngày chạy <input type="date" name="date" value="' . Text::h($f['date'] ?? '') . '"></label>';
        }
        $b .= '<button>Tìm</button></form>';

        $rows = match ($type) { 'routes' => $this->catalog->routes($vid, $f), 'stops' => $this->catalog->stops($vid, $f), 'trips' => $this->catalog->trips($vid, $f) };
        $b .= '<p class="muted">' . count($rows) . ' kết quả' . (count($rows) >= 200 ? ' (hiển thị tối đa 200)' : '') . '</p>';
        $b .= $this->resultTable($type, $rows);
        $b .= $this->saveForm($type, $f);
        $this->page('Tra cứu', $b);
    }

    /** Diễn giải mã dịch vụ (WK, WE...) thành lịch chạy dễ hiểu, dựa vào bảng calendar. */
    private function serviceLabel(int $vid, string $serviceId): string
    {
        static $cache = [];
        if (!isset($cache[$vid])) {
            $cache[$vid] = [];
            $st = $this->pdo->prepare('SELECT * FROM calendar WHERE version_id = ?');
            $st->execute([$vid]);
            $names = ['monday' => 'T2', 'tuesday' => 'T3', 'wednesday' => 'T4', 'thursday' => 'T5', 'friday' => 'T6', 'saturday' => 'T7', 'sunday' => 'CN'];
            foreach ($st->fetchAll() as $c) {
                $on = [];
                foreach ($names as $col => $short) {
                    if ((int)($c[$col] ?? 0) === 1) { $on[] = $short; }
                }
                $cache[$vid][(string)$c['service_id']] = match (true) {
                    $on === ['T2', 'T3', 'T4', 'T5', 'T6'] => 'Ngày thường (T2–T6)',
                    $on === ['T7', 'CN'] => 'Cuối tuần (T7, CN)',
                    count($on) === 7 => 'Hằng ngày',
                    $on === [] => 'Chỉ chạy vào ngày đặc biệt',
                    default => 'Các ngày ' . implode(', ', $on),
                };
            }
        }
        return $cache[$vid][$serviceId] ?? 'Ngày đặc biệt';
    }

    /** "Chủ nhật 01/11/2026" từ ngày dạng YYYY-MM-DD hoặc YYYYMMDD. */
    private function dayLabel(string $date): string
    {
        $ymd = Time::normalizeDate($date);
        $d = $ymd ? Time::date($ymd) : null;
        if (!$d) { return ''; }
        $names = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
        return $names[(int)$d->format('w')] . ' ' . Time::pretty($ymd);
    }

    /** Tiêu đề truy vấn mẫu tự sinh từ tham số: tuyến nào, trạm nào, ngày nào. */
    private function savedTitle(array $s, int $vid): string
    {
        $p = $s['params'];
        $routes = [];
        foreach ($this->catalog->routes($vid) as $r) { $routes[$r['route_id']] = $r; }
        $stops = [];
        foreach ($this->catalog->stops($vid, [], 1000) as $st) { $stops[$st['stop_id']] = $st['stop_name']; }

        $routeText = '';
        $rid = (string)($p['route_id'] ?? '');
        if ($rid !== '') {
            $routeText = isset($routes[$rid])
                ? $this->routeLabel($routes[$rid]) . ' (' . $routes[$rid]['route_long_name'] . ')'
                : 'Tuyến ' . $rid;
        }
        $date = $this->dayLabel((string)($p['date'] ?? ''));
        $stopId = (string)($p['stop_id'] ?? '');
        $stopText = $stopId !== '' ? ($stops[$stopId] ?? $stopId) : '';
        $parts = [];

        switch ($s['kind']) {
            case 'next':
                $parts[] = 'Chuyến tiếp theo' . ($stopText !== '' ? ' tại ' . $stopText : '');
                if ($date !== '') { $parts[] = $date; }
                if (($p['time'] ?? '') !== '') { $parts[] = 'từ ' . $p['time']; }
                if ($routeText !== '') { $parts[] = $routeText; }
                break;
            case 'trips':
                if ($routeText !== '') { $parts[] = $routeText; }
                if (($p['from'] ?? '') !== '' || ($p['to'] ?? '') !== '') {
                    $parts[] = 'từ ' . (($p['from'] ?? '') !== '' ? $p['from'] : '…') . ' đến ' . (($p['to'] ?? '') !== '' ? $p['to'] : '…');
                }
                if ($stopText !== '') { $parts[] = 'qua ' . $stopText; }
                if (($p['direction_id'] ?? '') !== '') { $parts[] = (string)$p['direction_id'] === '0' ? 'lượt đi' : 'lượt về'; }
                if ($date !== '') { $parts[] = $date; }
                if (($p['q'] ?? '') !== '') { $parts[] = '"' . $p['q'] . '"'; }
                if (!$parts) { $parts[] = 'Tất cả chuyến'; }
                break;
            case 'routes':
                $parts[] = ($p['q'] ?? '') !== '' ? 'Tìm tuyến "' . $p['q'] . '"' : 'Danh sách tuyến';
                if ($stopText !== '') { $parts[] = 'đi qua ' . $stopText; }
                break;
            case 'stops':
                $parts[] = ($p['q'] ?? '') !== '' ? 'Tìm trạm "' . $p['q'] . '"' : 'Danh sách trạm';
                if ($routeText !== '') { $parts[] = 'trên ' . $routeText; }
                break;
        }
        return $parts ? implode(' · ', $parts) : (string)$s['name'];
    }

    /** "Tuyến số 01" (dùng số hiệu tuyến, không dùng mã nội bộ). */
    private function routeLabel(array $r): string
    {
        $n = trim((string)($r['route_short_name'] ?? ''));
        return 'Tuyến số ' . ($n !== '' ? $n : (string)$r['route_id']);
    }

    private function resultTable(string $type, array $rows, ?int $vid = null): string
    {
        if (!$rows) { return '<div class="card">Không có kết quả phù hợp.</div>'; }
        $o = '<table>';
        if ($type === 'routes') {
            $o .= '<tr><th>Tuyến</th><th>Lộ trình</th><th>Số chuyến</th><th></th></tr>';
            foreach ($rows as $r) {
                $o .= '<tr><td><b>' . Text::h($this->routeLabel($r)) . '</b></td><td>' . Text::h((string)$r['route_long_name']) . '</td><td>' . $r['trip_count'] . '</td><td><a href="/search?type=trips&route_id=' . urlencode($r['route_id']) . '">Xem chuyến</a> · <a href="/search?type=stops&route_id=' . urlencode($r['route_id']) . '">Xem trạm</a> · <a href="/export/schedule?route=' . urlencode($r['route_id']) . '">Xuất lịch</a></td></tr>';
            }
        } elseif ($type === 'stops') {
            $o .= '<tr><th>Tên trạm</th><th>Tọa độ</th><th></th></tr>';
            foreach ($rows as $r) {
                $o .= '<tr><td><b>' . Text::h($r['stop_name']) . '</b></td><td>' . ($r['stop_lat'] !== null ? $r['stop_lat'] . ', ' . $r['stop_lon'] : '') . '</td><td><a href="/next?stop_id=' . urlencode($r['stop_id']) . '">Chuyến tiếp theo</a> · <a href="/search?type=routes&stop_id=' . urlencode($r['stop_id']) . '">Tuyến qua trạm</a></td></tr>';
            }
        } else {
            $hasFrom = ($rows[0]['from_dep'] ?? null) !== null;
            $hasTo = ($rows[0]['to_arr'] ?? null) !== null;
            $o .= '<tr><th>Tuyến</th><th>Hướng đi</th><th>' . ($hasFrom ? 'Giờ đi' : 'Giờ xuất phát') . '</th>' . ($hasTo ? '<th>Giờ đến</th>' : '') . '<th>Lịch chạy</th><th>Số trạm</th><th></th></tr>';
            foreach ($rows as $r) {
                $dep = $hasFrom ? $r['from_dep'] : ($r['first_dep'] ?? null);
                $o .= '<tr><td><b>' . Text::h($this->routeLabel($r)) . '</b></td><td>' . Text::h($r['trip_headsign']) . '</td><td>' . ($dep !== null ? Time::format((int)$dep) : '') . '</td>'
                    . ($hasTo ? '<td>' . (($r['to_arr'] ?? null) !== null ? Time::format((int)$r['to_arr']) : '') . '</td>' : '')
                    . '<td>' . Text::h($this->serviceLabel($vid ?? (int)$this->versions->activeId(), (string)$r['service_id'])) . '</td><td>' . $r['stop_count'] . '</td><td><a href="/trip?id=' . urlencode($r['trip_id']) . '">Xem hành trình</a></td></tr>';
            }
        }
        return $o . '</table>';
    }

    private function tripPage(string $id): void
    {
        $vid = $this->needActive();
        $t = $this->catalog->trip($vid, $id);
        if (!$t) { $this->notFound(); }
        $title = $this->routeLabel($t) . ' · ' . trim((string)$t['trip_headsign']);
        $b = '<h1>' . Text::h($title) . '</h1><p>Lộ trình ' . Text::h((string)$t['route_long_name']) . ' · lịch chạy: <b>'
            . Text::h($this->serviceLabel($vid, (string)$t['service_id'])) . '</b></p>'
            . '<table><tr><th>#</th><th>Trạm</th><th>Đến</th><th>Rời</th></tr>';
        foreach ($t['stop_times'] as $s) {
            $b .= '<tr><td>' . $s['stop_sequence'] . '</td><td>' . Text::h($s['stop_name']) . '</td><td>' . $s['arrival_text'] . '</td><td>' . $s['departure_text'] . '</td></tr>';
        }
        $this->page($title, $b . '</table><p class="muted">Giờ ≥ 24:00:00 là giờ của ngày hoạt động hôm đó nhưng diễn ra sau nửa đêm.</p>');
    }

    /** Ô nhập có nhãn + biểu tượng (dùng cho các biểu mẫu tìm kiếm). */
    private function ff(string $label, string $icon, string $control, string $cls = ''): string
    {
        return '<label class="ff' . ($cls !== '' ? ' ' . $cls : '') . '"><span class="ff-label">' . $this->icon($icon) . Text::h($label) . '</span>' . $control . '</label>';
    }

    private function routePill(string $routeId, string $routeName): array
    {
        $first = explode(' ', trim($routeName), 2);
        $pill = (isset($first[0]) && $first[0] !== '' && mb_strlen($first[0]) <= 6) ? $first[0] : $routeId;
        $rest = (count($first) === 2 && $pill === $first[0]) ? $first[1] : $routeName;
        $hue = crc32($routeId) % 360;
        return ['<span class="route-pill" style="--h:' . $hue . '">' . Text::h($pill) . '</span>', $rest];
    }

    private function nextPage(array $q): void
    {
        $vid = $this->needActive();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Ho_Chi_Minh')));
        $p = ['stop_id' => (string)($q['stop_id'] ?? ''), 'date' => (string)($q['date'] ?? $today->format('Y-m-d')),
              'time' => (string)($q['time'] ?? $today->format('H:i')), 'route_id' => (string)($q['route_id'] ?? ''), 'limit' => (string)($q['limit'] ?? '10')];
        $stops = $this->catalog->stops($vid, [], 1000);
        $routes = $this->catalog->routes($vid);
        $routeOpts = array_combine(array_column($routes, 'route_id'), array_map(fn($r) => $this->routeLabel($r), $routes)) ?: [];

        $limitOpts = '';
        foreach ([5, 10, 20, 50] as $n) {
            $limitOpts .= '<option value="' . $n . '"' . ((string)$n === $p['limit'] ? ' selected' : '') . '>' . $n . ' chuyến</option>';
        }

        $b = '<section class="pg-head"><div><span class="eyebrow">TRA CỨU GIỜ XE</span><h1>Tìm chuyến tiếp theo</h1>'
            . '<p>Chọn trạm, ngày và giờ để xem các chuyến sắp rời trạm, kể cả chuyến chạy sau nửa đêm.</p></div></section>'
            . '<form class="panel finder" method="get" action="/next">'
            . $this->ff('Trạm đón', 'pin', $this->select('stop_id', '— Chọn trạm —', array_column($stops, 'stop_name', 'stop_id'), $p['stop_id']), 'ff-wide')
            . $this->ff('Ngày', 'calendar', '<input type="date" name="date" value="' . Text::h($p['date']) . '" required>')
            . $this->ff('Giờ', 'clock', '<input name="time" value="' . Text::h($p['time']) . '" size="6" placeholder="HH:MM" title="HH:MM, có thể nhập 24:00–47:59">')
            . $this->ff('Tuyến', 'route', $this->select('route_id', 'Mọi tuyến', $routeOpts, $p['route_id']))
            . $this->ff('Hiển thị', 'list', '<select name="limit">' . $limitOpts . '</select>')
            . '<button class="btn-primary finder-go" type="submit">' . $this->icon('search') . '<span>Tìm chuyến</span></button>'
            . '</form>';

        if ($p['stop_id'] === '') {
            $chips = '';
            foreach (array_slice($stops, 0, 10) as $s) {
                $chips .= '<a class="chip chip-link" href="/next?stop_id=' . urlencode((string)$s['stop_id']) . '">' . $this->icon('pin') . Text::h((string)$s['stop_name']) . '</a>';
            }
            $b .= '<div class="empty-box big">' . $this->icon('bus') . '<div><b>Bạn muốn lên xe ở đâu?</b>'
                . '<p>Chọn một trạm ở trên, hoặc bấm nhanh vào một trạm phổ biến:</p>' . ($chips !== '' ? '<div class="chip-cloud">' . $chips . '</div>' : '') . '</div></div>';
            $this->page('Chuyến tiếp theo', $b);
            return;
        }

        $ymd = Time::normalizeDate($p['date']) ?? '';
        $r = $this->dep->next($vid, $p['stop_id'], $ymd, $p['time'], max(1, min(50, (int)$p['limit'])), $p['route_id'] ?: null);
        $stopName = (string)($r['query']['stop_name'] ?? '');

        $qAbs = null;
        $d0 = Time::date($ymd);
        $qs = Time::parseQuery($p['time']);
        if ($d0 !== null && $qs !== null) {
            $qAbs = $d0->getTimestamp() + $qs;
        }

        $b .= '<div class="res-head"><div><h2>' . $this->icon('pin') . Text::h($stopName !== '' ? $stopName : $p['stop_id']) . '</h2>'
            . '<p>Từ <b>' . Text::h(substr($p['time'], 0, 5)) . '</b> ngày <b>' . Text::h(Time::pretty($ymd)) . '</b>'
            . ($r['trips'] ? ' · <b>' . count($r['trips']) . '</b> chuyến sắp tới' : '') . '</p></div>'
            . '<a class="btn-soft" href="/next?stop_id=' . urlencode($p['stop_id']) . '">' . $this->icon('clock') . '<span>Về giờ hiện tại</span></a></div>';
        $b .= $this->nextTable($r['trips'], $r['message'], $qAbs);
        if ($r['trips']) {
            $b .= '<details class="save-box"><summary>' . $this->icon('bookmark') . 'Lưu truy vấn này</summary>' . $this->saveForm('next', $p) . '</details>';
        }
        $this->page('Chuyến tiếp theo', $b);
    }

    private function nextTable(array $trips, ?string $msg, ?int $qAbs = null): string
    {
        if (!$trips) {
            return '<div class="empty-box warn">' . $this->icon('info') . '<div><b>Không có chuyến phù hợp</b><p>' . Text::h($msg ?? '') . '</p></div></div>';
        }
        $buyer = $this->currentUser() !== null;
        $o = '<div class="dep-list">';
        foreach ($trips as $i => $t) {
            [$pill, $routeText] = $this->routePill((string)$t['route_id'], (string)$t['route_name']);
            $chips = '';
            if ($t['next_day']) {
                $chips .= '<span class="chip">' . $this->icon('clock') . 'sau nửa đêm, thuộc ngày hoạt động ' . Text::h(Time::pretty($t['service_date'])) . '</span>';
            }
            if ($t['via'] === 'added') {
                $chips .= '<span class="chip chip-accent">' . $this->icon('plus') . 'Dịch vụ thêm theo ngày ngoại lệ</span>';
            }
            $gtfs = substr((string)$t['departure_text'], 0, 5) !== $t['clock'] ? '<small>Giờ GTFS ' . Text::h((string)$t['departure_text']) . '</small>' : '<small>' . Text::h(Time::pretty($t['real_date'])) . '</small>';
            $eta = '';
            if ($qAbs !== null) {
                $diff = max(0, intdiv((int)$t['abs'] - $qAbs, 60));
                $eta = $diff === 0 ? 'Ngay bây giờ' : ($diff < 60 ? 'Sau ' . $diff . ' phút' : 'Sau ' . intdiv($diff, 60) . 'h' . ($diff % 60 ? sprintf('%02d', $diff % 60) : ''));
                $eta = '<span class="eta' . ($i === 0 ? ' eta-first' : '') . '">' . $eta . '</span>';
            }
            $o .= '<article class="dep' . ($i === 0 && $qAbs !== null ? ' dep-first' : '') . '">'
                . '<div class="dep-time"><b>' . Text::h((string)$t['clock']) . '</b>' . $gtfs . '</div>'
                . '<div class="dep-main"><div class="dep-route">' . $pill . '<span class="dep-name">' . Text::h($routeText) . '</span></div>'
                . '<div class="dep-head">' . $this->icon('arrow') . '<span>' . Text::h((string)$t['headsign']) . '</span></div>'
                . ($chips !== '' ? '<div class="chips">' . $chips . '</div>' : '') . '</div>'
                . '<div class="dep-side">' . $eta . '<div class="dep-actions">'
                . '<a class="btn-soft sm" href="/trip?id=' . urlencode((string)$t['trip_id']) . '">' . $this->icon('route') . '<span>Hành trình</span></a>'
                . ($buyer ? '<a class="btn-link primary sm" href="/tickets?type=single&amp;route_id=' . urlencode((string)$t['route_id']) . '">' . $this->icon('ticket') . '<span>Mua vé</span></a>' : '')
                . '</div></div></article>';
        }
        return $o . '</div>';
    }

    private function diffLine(array $c): string
    {
        $del = static fn($v): string => '<del>' . Text::h((string)$v) . '</del>';
        $ins = static fn($v): string => '<ins>' . Text::h((string)$v) . '</ins>';
        return match ($c['type']) {
            'time' => '<span class="dtag">Giờ</span><span>Trạm <b>' . Text::h((string)$c['stop_id']) . '</b> (#' . (int)$c['seq'] . ') · ' . Text::h((string)$c['field']) . ': ' . $del($c['old']) . ' → ' . $ins($c['new']) . '</span>',
            'attr' => '<span class="dtag">Thông tin</span><span>' . Text::h((string)$c['field']) . ': ' . $del($c['old']) . ' → ' . $ins($c['new']) . '</span>',
            'stop_added' => '<span class="dtag dtag-add">Thêm trạm</span><span>Trạm <b>' . Text::h((string)$c['stop_id']) . '</b> (#' . (int)$c['seq'] . ')</span>',
            'stop_removed' => '<span class="dtag dtag-del">Bỏ trạm</span><span>Trạm <b>' . Text::h((string)$c['stop_id']) . '</b> (#' . (int)$c['seq'] . ')</span>',
            default => '<span class="dtag">Đổi trạm</span><span>Vị trí #' . (int)$c['seq'] . ': ' . $del($c['old']) . ' → ' . $ins($c['new']) . '</span>',
        };
    }

    private function comparePage(array $q): void
    {
        $vs = array_values(array_filter($this->versions->all(), static fn($v) => $v['status'] === 'valid'));
        $opts = [];
        foreach ($vs as $v) { $opts[$v['id']] = '#' . $v['id'] . ' ' . $v['label']; }
        $a = (int)($q['a'] ?? 0);
        $bId = (int)($q['b'] ?? 0);
        $routeOpts = [];
        if ($a && $bId) { foreach ($this->catalog->routes($bId) as $r) { $routeOpts[$r['route_id']] = $this->routeLabel($r); } }

        $out = '<section class="pg-head"><div><span class="eyebrow">PHIÊN BẢN LỊCH</span><h1>So sánh hai bản lịch</h1>'
            . '<p>Xem chính xác chuyến nào được thêm, bị xóa hay đổi giờ khi chuyển từ bản lịch cũ sang bản mới.</p></div></section>'
            . '<form class="panel cmp-form" method="get" action="/compare">'
            . '<div class="cmp-pick"><span class="pick-tag old">BẢN CŨ</span>' . $this->select('a', '— Chọn bản cũ —', $opts, (string)$a, true) . '</div>'
            . '<div class="cmp-arrow" aria-hidden="true">' . $this->icon('arrow') . '</div>'
            . '<div class="cmp-pick"><span class="pick-tag new">BẢN MỚI</span>' . $this->select('b', '— Chọn bản mới —', $opts, (string)$bId, true) . '</div>'
            . '<div class="cmp-pick cmp-route"><span class="pick-tag">TUYẾN</span>' . $this->select('route', 'Mọi tuyến', $routeOpts, (string)($q['route'] ?? '')) . '</div>'
            . '<button class="btn-primary" type="submit">' . $this->icon('compare') . '<span>So sánh</span></button></form>';

        if (!($a && $bId && isset($opts[$a], $opts[$bId]))) {
            $out .= '<div class="empty-box big">' . $this->icon('compare') . '<div><b>Chọn hai phiên bản để bắt đầu</b>'
                . '<p>' . (count($opts) < 2 ? 'Cần ít nhất hai bản lịch hợp lệ. Hãy nhập thêm bản mới ở trang Phiên bản lịch.' : 'Chọn “bản cũ” và “bản mới” ở trên rồi bấm So sánh. Bạn có thể giới hạn theo một tuyến.') . '</p></div></div>';
            $this->page('So sánh', $out);
            return;
        }

        $d = (new Diff($this->pdo))->compare($a, $bId, ($q['route'] ?? '') ?: null);
        $s = $d['summary'];
        $total = (int)$s['trips_added'] + (int)$s['trips_removed'] + (int)$s['trips_changed'] + (int)$s['routes_changed'] + (int)$s['services_changed'];
        $stat = fn(string $cls, string $ic, int $n, string $label): string
            => '<div class="stat ' . $cls . '"><span class="stat-ic">' . $this->icon($ic) . '</span><div><b>' . $n . '</b><small>' . $label . '</small></div></div>';

        $out .= '<div class="cmp-banner"><div><span class="pick-tag old">' . Text::h($opts[$a]) . '</span>' . $this->icon('arrow') . '<span class="pick-tag new">' . Text::h($opts[$bId]) . '</span></div>'
            . '<a class="btn-soft" href="/export/diff?a=' . $a . '&amp;b=' . $bId . (($q['route'] ?? '') ? '&amp;route=' . urlencode((string)$q['route']) : '') . '">' . $this->icon('download') . '<span>Xuất CSV</span></a></div>'
            . '<div class="stat-grid five">'
            . $stat('green', 'plus', (int)$s['trips_added'], 'chuyến mới')
            . $stat('red', 'minus', (int)$s['trips_removed'], 'chuyến bị xóa')
            . $stat('amber', 'edit', (int)$s['trips_changed'], 'chuyến đổi giờ / thông tin')
            . $stat('blue', 'route', (int)$s['routes_changed'], 'tuyến đổi tên')
            . $stat('violet', 'calendar', (int)$s['services_changed'], 'dịch vụ đổi lịch')
            . '</div>';

        if ($total === 0) {
            $out .= '<div class="empty-box ok">' . $this->icon('check-circle') . '<div><b>Hai phiên bản giống nhau</b><p>Không phát hiện thay đổi nào' . (($q['route'] ?? '') ? ' trên tuyến đã chọn' : '') . '.</p></div></div>';
            $this->page('So sánh', $out);
            return;
        }

        $routeRows = '';
        foreach ($d['routes']['added'] as $r) { $routeRows .= '<div class="crow"><span class="dtag dtag-add">Tuyến mới</span><span>' . Text::h($r['route_id'] . ' ' . $r['name']) . '</span></div>'; }
        foreach ($d['routes']['removed'] as $r) { $routeRows .= '<div class="crow"><span class="dtag dtag-del">Tuyến bị xóa</span><span>' . Text::h($r['route_id'] . ' ' . $r['name']) . '</span></div>'; }
        foreach ($d['routes']['changed'] as $r) { $routeRows .= '<div class="crow"><span class="dtag">Đổi tên</span><span>Tuyến <b>' . Text::h($r['route_id']) . '</b>: <del>' . Text::h($r['old']) . '</del> → <ins>' . Text::h($r['new']) . '</ins></span></div>'; }
        if ($routeRows !== '') {
            $out .= '<section class="cmp-sec"><h2>Thay đổi tuyến</h2><div class="panel chg-list">' . $routeRows . '</div></section>';
        }

        if ($d['services']) {
            $kinds = ['added' => ['dtag-add', 'Mới'], 'removed' => ['dtag-del', 'Bị xóa'], 'changed' => ['', 'Đổi']];
            $out .= '<section class="cmp-sec"><h2>Lịch hoạt động thay đổi</h2><div class="panel chg-list">';
            foreach ($d['services'] as $sv) {
                [$kc, $kl] = $kinds[$sv['kind']] ?? ['', (string)$sv['kind']];
                $out .= '<div class="crow"><span class="dtag ' . $kc . '">' . $kl . '</span><span><b>' . Text::h((string)$sv['service_id']) . '</b> · ' . Text::h(implode('; ', $sv['details']))
                    . ' <span class="chip">' . (int)$sv['affected_trips'] . ' chuyến ảnh hưởng</span></span></div>';
            }
            $out .= '</div></section>';
        }

        if ($d['per_route']) {
            $max = 1;
            foreach ($d['per_route'] as $c) { $max = max($max, (int)($c['added'] ?? 0) + (int)($c['removed'] ?? 0) + (int)($c['changed'] ?? 0)); }
            $out .= '<section class="cmp-sec"><h2>Theo tuyến</h2><div class="panel route-bars">'
                . '<div class="legend"><span class="lg add">Mới</span><span class="lg del">Xóa</span><span class="lg chg">Đổi</span></div>';
            foreach ($d['per_route'] as $rid => $c) {
                $ad = (int)($c['added'] ?? 0); $rm = (int)($c['removed'] ?? 0); $ch = (int)($c['changed'] ?? 0);
                $seg = static fn(string $cls, int $n): string => $n > 0 ? '<i class="' . $cls . '" style="width:' . round($n / $max * 100, 2) . '%" title="' . $n . '"></i>' : '';
                $out .= '<div class="rb"><b>' . Text::h((string)$rid) . '</b><div class="rb-bar">' . $seg('add', $ad) . $seg('del', $rm) . $seg('chg', $ch) . '</div>'
                    . '<span class="rb-num"><em class="add">+' . $ad . '</em><em class="del">−' . $rm . '</em><em class="chg">~' . $ch . '</em></span></div>';
            }
            $out .= '</div></section>';
        }

        $cloud = static function (array $trips): string {
            if (!$trips) { return '<p class="muted">Không có.</p>'; }
            $h = '<div class="chip-cloud">';
            foreach (array_slice($trips, 0, 60) as $t) { $h .= '<span class="chip mono">' . Text::h((string)$t['trip_id']) . '</span>'; }
            if (count($trips) > 60) { $h .= '<span class="chip chip-accent">+' . (count($trips) - 60) . ' chuyến nữa</span>'; }
            return $h . '</div>';
        };
        $out .= '<div class="two-col">'
            . '<section class="cmp-sec"><h2>Chuyến mới <span class="count add">' . count($d['trips']['added']) . '</span></h2><div class="panel">' . $cloud($d['trips']['added']) . '</div></section>'
            . '<section class="cmp-sec"><h2>Chuyến bị xóa <span class="count del">' . count($d['trips']['removed']) . '</span></h2><div class="panel">' . $cloud($d['trips']['removed']) . '</div></section></div>';

        $out .= '<section class="cmp-sec"><h2>Chuyến được sửa <span class="count chg">' . count($d['trips']['changed']) . '</span></h2>';
        if (!$d['trips']['changed']) {
            $out .= '<div class="panel"><p class="muted">Không có.</p></div>';
        }
        foreach ($d['trips']['changed'] as $t) {
            $lines = '';
            foreach (array_slice($t['changes'], 0, 6) as $c) { $lines .= '<div class="crow">' . $this->diffLine($c) . '</div>'; }
            if (count($t['changes']) > 6) { $lines .= '<div class="crow more">… và ' . (count($t['changes']) - 6) . ' thay đổi khác</div>'; }
            $out .= '<div class="panel diff-card"><div class="diff-id">' . $this->icon('bus') . '<code>' . Text::h((string)$t['trip_id']) . '</code></div><div class="chg-list flat">' . $lines . '</div></div>';
        }
        $out .= '</section>';
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
        $titleVid = ($sel && isset($opts[$sel])) ? $sel : (int)$this->versions->activeId();
        if (!$all) { $b .= '<div class="card">Chưa có truy vấn mẫu. Lưu từ trang <a href="/next">Chuyến tiếp theo</a> hoặc <a href="/search">Tra cứu</a>.</div>'; }
        foreach ($all as $s) {
            $b .= '<div class="card"><div class="row"><h2 style="margin:0" title="' . Text::h((string)$s['name']) . '">' . Text::h($this->savedTitle($s, $titleVid)) . '</h2><span class="badge">' . SavedQueries::KINDS[$s['kind']] . '</span>'
                . '<form method="post" action="/saved/delete">' . $this->csrf() . '<input type="hidden" name="id" value="' . $s['id'] . '"><button class="danger">Xóa</button></form></div>';
            if ($sel && isset($opts[$sel])) {
                $r = $this->saved->run($s, $sel);
                $b .= $r['kind'] === 'next' ? $this->nextTable($r['rows'], $r['message']) : '<p>' . count($r['rows']) . ' kết quả.</p>' . $this->resultTable($r['kind'], array_slice($r['rows'], 0, 10), $sel);
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
        $h = '<form class="save-form" method="post" action="/saved/save">' . $this->csrf() . '<input type="hidden" name="kind" value="' . Text::h($kind) . '">';
        foreach ($params as $k => $v) {
            if ($v !== '' && $v !== null) { $h .= '<input type="hidden" name="p[' . Text::h((string)$k) . ']" value="' . Text::h((string)$v) . '">'; }
        }
        return $h . '<input name="name" placeholder="Ghi chú cho truy vấn (có thể bỏ trống)" maxlength="100"> <button type="submit">Lưu truy vấn</button></form>';
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
        header('Location: ' . gtfs_url($to));
        exit;
    }

    private function download(string $name, string $content): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . '"');
        echo $content;
        exit;
    }

    private function jsonResponse(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    private function notFound(): never
    {
        http_response_code(404);
        $this->page('Không tìm thấy', '<h1>404</h1><p>Không tìm thấy trang hoặc dữ liệu.</p>');
        exit;
    }

    private function icon(string $name): string
    {
        $paths = [
            'search' => '<circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path>',
            'clock' => '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>',
            'route' => '<circle cx="6" cy="5" r="2"></circle><circle cx="18" cy="19" r="2"></circle><path d="M8 5h4a4 4 0 0 1 4 4v1a4 4 0 0 1-4 4H9a3 3 0 0 0-3 3v0"></path>',
            'map' => '<path d="M9 18 3.5 21V6L9 3l6 3 5.5-3v15L15 21l-6-3Z"></path><path d="M9 3v15M15 6v15"></path>',
            'pin' => '<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle>',
            'bus' => '<rect x="4" y="3" width="16" height="16" rx="3"></rect><path d="M7 8h10M7 13h10M8 19v2M16 19v2"></path><circle cx="8" cy="16" r="1"></circle><circle cx="16" cy="16" r="1"></circle>',
            'list' => '<path d="M9 6h11M9 12h11M9 18h11"></path><circle cx="4" cy="6" r="1"></circle><circle cx="4" cy="12" r="1"></circle><circle cx="4" cy="18" r="1"></circle>',
            'compare' => '<path d="M7 7h11l-3-3M18 17H7l3 3"></path><path d="M18 4l3 3-3 3M6 14l-3 3 3 3"></path>',
            'bookmark' => '<path d="M6 4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18l-6-4-6 4Z"></path>',
            'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"></ellipse><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"></path>',
            'user' => '<circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>',
            'user-plus' => '<circle cx="9" cy="8" r="4"></circle><path d="M2 21a7 7 0 0 1 14 0M19 8v6M16 11h6"></path>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path>',
            'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path>',
            'login' => '<path d="M14 8l4 4-4 4M18 12H7"></path><path d="M11 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6"></path>',
            'logout' => '<path d="M10 8 6 12l4 4M6 12h11"></path><path d="M13 4h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5"></path>',
            'shield' => '<path d="M12 22s8-3 8-10V5l-8-3-8 3v7c0 7 8 10 8 10Z"></path><path d="m9 12 2 2 4-5"></path>',
            'check' => '<path d="m5 12 4 4L19 6"></path>',
            'check-circle' => '<circle cx="12" cy="12" r="9"></circle><path d="m8 12 3 3 5-6"></path>',
            'arrow' => '<path d="M5 12h14M14 7l5 5-5 5"></path>',
            'ticket' => '<path d="M3 9a2 2 0 0 0 0 4v3a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3a2 2 0 0 1 0-4V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2Z"></path><path d="M14 4v3M14 11v2M14 17v3"></path>',
            'sun' => '<circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>',
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M3 10h18M8 3v4M16 3v4"></path>',
            'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><path d="M14 14h3v3h-3zM20 14v1M14 20h1M18 18v3h3M20 17h1"></path>',
            'info' => '<circle cx="12" cy="12" r="9"></circle><path d="M12 11v5M12 8h.01"></path>',
            'users' => '<circle cx="9" cy="8" r="3.5"></circle><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6.5 6.5 0 0 1 3.5 5.5"></path>',
            'print' => '<path d="M7 9V3h10v6M7 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="7" y="14" width="10" height="7" rx="1"></rect>',
            'receipt' => '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2Z"></path><path d="M9 8h6M9 12h6"></path>',
            'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h13v4"></path><path d="M3 7v11a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2Z"></path><circle cx="16" cy="14" r="1"></circle>',
            'x' => '<path d="M6 6l12 12M18 6 6 18"></path>',
            'plus' => '<path d="M12 5v14M5 12h14"></path>',
            'minus' => '<path d="M5 12h14"></path>',
            'edit' => '<path d="M4 20h4L19 9l-4-4L4 16Z"></path><path d="m13.5 6.5 4 4"></path>',
            'download' => '<path d="M12 4v11M7 11l5 5 5-5M5 20h14"></path>',
            'layers' => '<path d="m12 3 9 5-9 5-9-5Z"></path><path d="m3 13 9 5 9-5"></path>',
            'chevron' => '<path d="m8 10 4 4 4-4"></path>',
        ];
        $body = $paths[$name] ?? $paths['route'];
        return '<svg class="icon icon-' . Text::h($name) . '" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
    }

    private function page(string $title, string $body): void
    {
        $a = $this->versions->active();
        $flash = '';
        foreach ($_SESSION['flash'] ?? [] as [$t, $m]) {
            $flash .= '<div class="toast ' . ($t === 'ok' ? 'toast-ok' : 'toast-bad') . '"><span class="toast-icon">' . $this->icon($t === 'ok' ? 'check-circle' : 'shield') . '</span><span>' . Text::h($m) . '</span></div>';
        }
        unset($_SESSION['flash']);

        $cur = rtrim(gtfs_strip_base((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')), '/') ?: '/';

        $extraHead = '';
        $extraScripts = '';
        if ($cur === '/map') {
            $extraHead = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">'
                . '<link rel="stylesheet" href="/assets/map.css">';
            $extraScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>'
                . '<script src="/assets/map.js"></script>';
        }

        if (str_starts_with($cur, '/tickets')) {
            $extraScripts .= '<script src="/assets/tickets.js"></script>';
        }

        $nav = '';
        $navItems = [
            '/' => ['Trang chủ', 'route'],
            '/map' => ['Bản đồ', 'map'],
            '/next' => ['Chuyến tiếp theo', 'clock'],
            '/search' => ['Tra cứu', 'search'],
            '/compare' => ['So sánh', 'compare'],
            '/saved' => ['Truy vấn mẫu', 'bookmark'],
            '/versions' => ['Phiên bản lịch', 'database'],
        ];
        if (!$this->actor->isManager()) {
            unset($navItems['/versions']);
        }
        if ($this->actor->isManager()) {
            $extra = ['/tickets/manage' => ['Đơn vé', 'receipt']];
        } elseif ($this->currentUser()) {
            $extra = ['/tickets' => ['Mua vé', 'ticket']];
        } else {
            $extra = [];
        }
        $navItems = array_slice($navItems, 0, 3, true) + $extra + array_slice($navItems, 3, null, true);
        foreach ($navItems as $href => [$label, $navIcon]) {
            $on = $href === '/' ? $cur === '/' : str_starts_with($cur, $href);
            $nav .= '<a href="' . $href . '"' . ($on ? ' class="on" aria-current="page"' : '') . '>'
                . $this->icon($navIcon) . '<span>' . $label . '</span></a>';
        }

        if ($this->actor->isManager()) {
            $who = '<details class="user-menu"><summary><span class="avatar admin">A</span><span class="user-meta"><b>Quản lý</b><small>Administrator</small></span>' . $this->icon('chevron') . '</summary>'
                . '<div class="user-dropdown"><div class="dropdown-head"><b>Tài khoản quản lý</b><small>Toàn quyền quản lý dữ liệu GTFS</small></div>'
                . '<form method="post" action="/logout">' . $this->csrf() . '<button class="dropdown-action danger-action" type="submit">' . $this->icon('logout') . '<span>Đăng xuất</span></button></form></div></details>';
        } elseif (!empty($_SESSION['user_id'])) {
            $user = $this->auth->find((int)$_SESSION['user_id']);
            if ($user) {
                $username = (string)$user['username'];
                $initial = mb_strtoupper(mb_substr($username, 0, 1));
                $who = '<details class="user-menu"><summary><span class="avatar">' . Text::h($initial) . '</span><span class="user-meta"><b>' . Text::h($username) . '</b><small>Người dùng</small></span>' . $this->icon('chevron') . '</summary>'
                    . '<div class="user-dropdown"><div class="dropdown-head"><b>' . Text::h($username) . '</b><small>' . Text::h((string)$user['email']) . '</small></div>'
                    . '<a class="dropdown-action" href="/tickets/mine">' . $this->icon('receipt') . '<span>Vé của tôi</span></a>'
                    . '<form method="post" action="/user/logout">' . $this->csrf() . '<button class="dropdown-action" type="submit">' . $this->icon('logout') . '<span>Đăng xuất</span></button></form></div></details>';
            } else {
                unset($_SESSION['user_id']);
                $who = '<div class="guest-actions"><a class="login-link" href="/user/login">Đăng nhập</a><a class="signup-link" href="/register">Đăng ký</a></div>';
            }
        } else {
            $who = '<div class="guest-actions"><a class="login-link" href="/user/login">Đăng nhập</a><a class="signup-link" href="/register">Đăng ký</a></div>';
        }

        $active = $a
            ? '<div class="version-chip"><span class="version-dot"></span><span><small>Đang dùng</small><b>#' . $a['id'] . ' ' . Text::h($a['label']) . '</b></span></div>'
            : '<div class="version-chip inactive"><span class="version-dot"></span><span><small>Dữ liệu</small><b>Chưa có bản đang dùng</b></span></div>';

        $icon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop stop-color='%235b8cff'/%3E%3Cstop offset='1' stop-color='%23856cff'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='32' height='32' rx='9' fill='url(%23g)'/%3E%3Cpath d='M9 22V10h8a4 4 0 010 8h-3' fill='none' stroke='white' stroke-width='3' stroke-linecap='round'/%3E%3C/svg%3E";

        header('Content-Type: text/html; charset=utf-8');
        ob_start('gtfs_rebase_html');
        echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>' . Text::h($title) . ' · Nam Thành Hùng</title><meta name="description" content="Hệ thống quản lý và tra cứu lịch trình giao thông công cộng GTFS"><link rel="icon" type="image/png" href="/assets/logo.png"><link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/assets/ui.css">' . $extraHead . '</head><body>'
            . '<a class="skip" href="#main">Bỏ qua điều hướng</a>'
            . '<header class="top"><div class="bar"><a class="brand" href="/"><img class="brand-logo" src="/assets/logo.png" alt="" width="40" height="40"><span><b>Nam Thành Hùng</b></span></a><nav aria-label="Điều hướng chính">' . $nav . '</nav><div class="header-side">' . $active . $who . '</div></div></header>'
            . '<div class="page-glow glow-one"></div><div class="page-glow glow-two"></div>'
            . '<main id="main"><div class="toast-stack">' . $flash . '</div>' . $body . '</main>'
            . '<footer class="foot"><div><a class="footer-brand" href="/"><img src="/assets/logo.png" alt="" width="28" height="28"><b>Nam Thành Hùng</b></a><p>Dữ liệu GTFS tĩnh · Giờ gắn với ngày hoạt động.</p></div><div class="footer-links"><a href="/map">Bản đồ</a><a href="/search">Tra cứu</a><a href="/next">Chuyến tiếp theo</a>' . ($this->actor->isManager() ? '<a href="/versions">Phiên bản lịch</a>' : '<a href="/admin">Quản trị</a>') . '</div><small>MIT · Project học tập</small></footer>' . $extraScripts . '</body></html>';
        ob_end_flush();
    }
}