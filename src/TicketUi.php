<?php
declare(strict_types=1);
namespace Gtfs;

/**
 * Trang và thao tác mua vé. Dùng trong App (truy cập các hàm private của App).
 * - Chỉ tài khoản người dùng đã đăng nhập mới mua được vé.
 * - Tài khoản quản lý KHÔNG mua vé; chỉ xem và xác nhận đơn tại /tickets/manage.
 */
trait TicketUi
{
    /** Tài khoản người dùng đang đăng nhập (null nếu là khách hoặc đang ở vai trò quản lý). */
    private function currentUser(): ?array
    {
        if ($this->actor->isManager() || empty($_SESSION['user_id'])) {
            return null;
        }
        return $this->auth->find((int)$_SESSION['user_id']);
    }

    /** Bắt buộc là người dùng thường; quản lý bị chuyển sang trang quản lý đơn, khách bị chuyển sang đăng nhập. */
    private function requireBuyer(): array
    {
        if ($this->actor->isManager()) {
            $this->flash('error', 'Tài khoản quản lý không có chức năng mua vé.');
            $this->redirect('/tickets/manage');
        }
        $user = $this->currentUser();
        if (!$user) {
            $this->flash('error', 'Vui lòng đăng nhập tài khoản người dùng để mua vé.');
            $this->redirect('/user/login');
        }
        return $user;
    }

    private function requireManager(): void
    {
        if (!$this->actor->isManager()) {
            $this->flash('error', 'Chỉ người quản lý mới xem được trang này.');
            $this->redirect('/versions');
        }
    }

    private function stateChip(array $o): string
    {
        $map = [
            'pending' => ['st-wait', 'Chờ thanh toán'],
            'paid' => ['st-paid', 'Đã thanh toán'],
            'cancelled' => ['st-off', 'Đã hủy'],
            'expired' => ['st-off', 'Hết hạn thanh toán'],
        ];
        [$cls, $label] = $map[$o['state']] ?? ['st-off', (string)$o['state']];
        return '<span class="st ' . $cls . '">' . $label . '</span>';
    }

    private function usageChip(array $o): string
    {
        return match ($o['usage']) {
            'active' => '<span class="st st-paid">Đang có hiệu lực</span>',
            'upcoming' => '<span class="st st-wait">Chưa đến ngày dùng</span>',
            'ended' => '<span class="st st-off">Đã hết hạn sử dụng</span>',
            default => '',
        };
    }

    private function ticketTabs(string $active): string
    {
        $tabs = ['buy' => ['/tickets', 'Mua vé', 'ticket'], 'mine' => ['/tickets/mine', 'Vé của tôi', 'receipt']];
        $o = '<nav class="tk-tabs" aria-label="Vé">';
        foreach ($tabs as $k => [$href, $label, $ic]) {
            $o .= '<a href="' . $href . '"' . ($k === $active ? ' class="on"' : '') . '>' . $this->icon($ic) . '<span>' . $label . '</span></a>';
        }
        return $o . '</nav>';
    }

    private function d(string $ymd): string
    {
        $t = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        return $t ? $t->format('d/m/Y') : $ymd;
    }

    private function orderSummaryLines(array $o): string
    {
        $validity = $o['valid_from'] === $o['valid_to']
            ? $this->d($o['valid_from'])
            : $this->d($o['valid_from']) . ' – ' . $this->d($o['valid_to']);
        return '<dl class="kv">'
            . '<div><dt>Loại vé</dt><dd>' . Text::h($o['type_name']) . '</dd></div>'
            . '<div><dt>Phạm vi</dt><dd>' . ($o['route_name'] ? Text::h('Tuyến ' . $o['route_name']) : 'Mọi tuyến') . '</dd></div>'
            . '<div><dt>Số lượng</dt><dd>' . (int)$o['quantity'] . ' × ' . Tickets::money((int)$o['unit_price']) . '</dd></div>'
            . '<div><dt>Hiệu lực</dt><dd>' . $validity . '</dd></div>'
            . '<div class="kv-total"><dt>Tổng tiền</dt><dd>' . Tickets::money((int)$o['total']) . '</dd></div>'
            . '</dl>';
    }

    // ---------- Mua vé ----------
    private function ticketBuyPage(array $q): void
    {
        $this->requireBuyer();
        $vid = $this->versions->activeId();
        $routes = $vid ? $this->catalog->routes($vid) : [];

        $type = isset(Tickets::TYPES[(string)($q['type'] ?? '')]) ? (string)$q['type'] : 'single';
        $routeSel = (string)($q['route_id'] ?? '');
        $today = Tickets::today();
        $max = (new \DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');

        $cards = '';
        foreach (Tickets::TYPES as $key => $def) {
            $on = $key === $type;
            $cards .= '<label class="type-card' . ($on ? ' on' : '') . '">'
                . '<input type="radio" name="type" value="' . $key . '"' . ($on ? ' checked' : '')
                . ' data-name="' . Text::h($def['name']) . '" data-price="' . $def['price'] . '" data-days="' . $def['days']
                . '" data-route="' . ($def['needs_route'] ? '1' : '0') . '" data-max="' . $def['max_qty'] . '">'
                . '<span class="type-ic">' . $this->icon($key === 'single' ? 'ticket' : ($key === 'day' ? 'sun' : 'calendar')) . '</span>'
                . '<b>' . Text::h($def['name']) . '</b>'
                . '<span class="type-price">' . Tickets::money($def['price']) . '</span>'
                . '<small>' . Text::h($def['desc']) . '</small></label>';
        }

        $routeOpts = '<option value="">— Chọn tuyến —</option>';
        foreach ($routes as $r) {
            $label = $this->routeLabel($r) . ($r['route_long_name'] ? ' · ' . $r['route_long_name'] : '');
            $routeOpts .= '<option value="' . Text::h((string)$r['route_id']) . '"' . ((string)$r['route_id'] === $routeSel ? ' selected' : '') . '>' . Text::h($label) . '</option>';
        }

        $def = Tickets::TYPES[$type];
        $b = '<section class="pg-head"><div><span class="eyebrow">VÉ ĐIỆN TỬ</span><h1>Mua vé xe buýt</h1>'
            . '<p>Chọn loại vé, thanh toán bằng mã QR từ ứng dụng ngân hàng và nhận vé ngay trên tài khoản của bạn.</p></div></section>'
            . $this->ticketTabs('buy');

        if (!$routes) {
            $b .= '<div class="empty-box">' . $this->icon('info') . '<div><b>Chưa có dữ liệu tuyến</b><p>Hiện chưa có bộ lịch đang sử dụng nên chưa thể bán vé lượt. Bạn vẫn có thể mua vé ngày hoặc vé tháng.</p></div></div>';
        }

        $b .= '<div class="buy-grid">'
            . '<form class="panel buy-form" id="buyForm" method="post" action="/tickets/buy">' . $this->csrf()
            . '<h2><span class="step">1</span>Chọn loại vé</h2><div class="type-cards">' . $cards . '</div>'
            . '<h2><span class="step">2</span>Thông tin chuyến đi</h2>'
            . '<div class="fgrid">'
            . '<label class="ff" id="routeField"' . ($def['needs_route'] ? '' : ' hidden') . '><span class="ff-label">' . $this->icon('route') . 'Tuyến xe</span><select name="route_id" id="routeSelect"' . ($def['needs_route'] ? ' required' : '') . '>' . $routeOpts . '</select></label>'
            . '<label class="ff"><span class="ff-label">' . $this->icon('calendar') . 'Ngày bắt đầu dùng</span><input type="date" name="valid_from" id="validFrom" value="' . $today . '" min="' . $today . '" max="' . $max . '" required></label>'
            . '<div class="ff"><span class="ff-label">' . $this->icon('users') . 'Số lượng</span>'
            . '<div class="qty"><button type="button" id="qtyMinus" aria-label="Giảm">−</button><input type="number" name="quantity" id="qty" value="1" min="1" max="' . $def['max_qty'] . '" required><button type="button" id="qtyPlus" aria-label="Tăng">+</button></div></div>'
            . '</div>'
            . '<button class="btn-primary btn-block" type="submit">' . $this->icon('qr') . '<span>Tiếp tục thanh toán</span></button>'
            . '</form>'
            . '<aside class="panel summary"><h2>Tóm tắt đơn hàng</h2>'
            . '<dl class="kv">'
            . '<div><dt>Loại vé</dt><dd id="sumType">' . Text::h($def['name']) . '</dd></div>'
            . '<div><dt>Phạm vi</dt><dd id="sumRoute">—</dd></div>'
            . '<div><dt>Số lượng</dt><dd id="sumQty">1</dd></div>'
            . '<div><dt>Hiệu lực</dt><dd id="sumValid">—</dd></div>'
            . '<div class="kv-total"><dt>Tổng tiền</dt><dd id="sumTotal">' . Tickets::money($def['price']) . '</dd></div>'
            . '</dl>'
            . '<ul class="notes"><li>' . $this->icon('shield') . '<span>Thanh toán bằng QR VietQR, hỗ trợ mọi ngân hàng.</span></li>'
            . '<li>' . $this->icon('clock') . '<span>Đơn được giữ ' . Tickets::HOLD_MINUTES . ' phút để bạn hoàn tất thanh toán.</span></li></ul>'
            . '</aside></div>';

        $this->page('Mua vé', $b);
    }

    // ---------- Thanh toán ----------
    private function ticketPayPage(string $code): void
    {
        $user = $this->requireBuyer();
        $o = $this->tickets->find($code);
        if (!$o || (int)$o['user_id'] !== (int)$user['id']) {
            $this->notFound();
        }
        if ($o['state'] === 'paid') {
            $this->flash('ok', 'Thanh toán thành công! Vé của bạn đã sẵn sàng.');
            $this->redirect('/tickets/view?code=' . urlencode($o['code']));
        }

        $cfg = gtfs_payment_config();
        $b = '<section class="pg-head"><div><span class="eyebrow">THANH TOÁN ĐƠN ' . Text::h($o['code']) . '</span><h1>Quét mã QR để thanh toán</h1>'
            . '<p>Mở ứng dụng ngân hàng, chọn quét mã QR và xác nhận chuyển khoản. Hệ thống sẽ tự cập nhật khi nhận được tiền.</p></div></section>';

        if ($o['state'] !== 'pending') {
            $msg = $o['state'] === 'expired' ? 'Đơn đã hết thời gian giữ chỗ thanh toán.' : 'Đơn này đã bị hủy.';
            $b .= '<div class="empty-box">' . $this->icon('info') . '<div><b>' . $msg . '</b><p>Hãy tạo đơn mới để tiếp tục mua vé.</p>'
                . '<a class="btn-link primary" href="/tickets">' . $this->icon('ticket') . '<span>Mua vé mới</span></a></div></div>';
            $this->page('Thanh toán', $b);
            return;
        }

        $qr = $this->tickets->qrUrl($o, $cfg);
        $b .= '<div class="pay-grid">'
            . '<section class="panel qr-card" id="payBox" data-code="' . Text::h($o['code']) . '" data-expires="' . (int)$o['expires_ts']
            . '" data-view="/tickets/view?code=' . urlencode($o['code']) . '">';

        if ($qr !== null) {
            $b .= '<div class="qr-frame"><img src="' . Text::h($qr) . '" alt="Mã QR thanh toán đơn ' . Text::h($o['code']) . '" width="300" height="300"></div>'
                . '<div class="pay-amount">' . Tickets::money((int)$o['total']) . '</div>'
                . '<div class="pay-state" id="payState"><span class="map-spinner"></span><span>Đang chờ bạn thanh toán…</span></div>'
                . '<div class="countdown">Đơn được giữ trong <b id="countdown">--:--</b></div>';
        } else {
            $b .= '<div class="qr-frame qr-missing">' . $this->icon('qr') . '<b>Chưa cấu hình tài khoản nhận tiền</b>'
                . '<small>Điền thông tin ngân hàng trong <code>config/payment.php</code> để hiện mã QR thanh toán thật.</small></div>'
                . '<div class="pay-amount">' . Tickets::money((int)$o['total']) . '</div>'
                . '<div class="countdown">Đơn được giữ trong <b id="countdown">--:--</b></div>'
                . '<form method="post" action="/tickets/demo-pay">' . $this->csrf() . '<input type="hidden" name="code" value="' . Text::h($o['code']) . '">'
                . '<button class="btn-primary btn-block" type="submit">' . $this->icon('check') . '<span>Mô phỏng thanh toán (chỉ để thử nghiệm)</span></button></form>';
        }
        $b .= '</section>';

        $b .= '<aside class="pay-side">';
        if ($qr !== null) {
            $copy = static fn(string $v): string => '<button type="button" class="copy-btn" data-copy="' . Text::h($v) . '">Chép</button>';
            $b .= '<section class="panel"><h2>Hoặc chuyển khoản thủ công</h2><dl class="kv kv-copy">'
                . '<div><dt>Ngân hàng</dt><dd>' . Text::h($cfg['bank_name'] !== '' ? $cfg['bank_name'] : 'BIN ' . $cfg['bank_bin']) . '</dd></div>'
                . '<div><dt>Số tài khoản</dt><dd>' . Text::h($cfg['account_no']) . $copy($cfg['account_no']) . '</dd></div>'
                . ($cfg['account_name'] !== '' ? '<div><dt>Chủ tài khoản</dt><dd>' . Text::h($cfg['account_name']) . '</dd></div>' : '')
                . '<div><dt>Số tiền</dt><dd>' . Tickets::money((int)$o['total']) . $copy((string)$o['total']) . '</dd></div>'
                . '<div><dt>Nội dung CK</dt><dd><b>' . Text::h($o['code']) . '</b>' . $copy($o['code']) . '</dd></div>'
                . '</dl><p class="hint">' . $this->icon('info') . '<span>Giữ nguyên nội dung chuyển khoản để hệ thống tự nhận diện đơn.</span></p></section>';
        }
        $b .= '<section class="panel"><h2>Đơn hàng</h2>' . $this->orderSummaryLines($o)
            . '<form method="post" action="/tickets/cancel" onsubmit="return confirm(\'Hủy đơn này?\')">' . $this->csrf()
            . '<input type="hidden" name="code" value="' . Text::h($o['code']) . '"><button class="btn-ghost-danger" type="submit">Hủy đơn</button></form></section>'
            . '</aside></div>';

        $this->page('Thanh toán', $b);
    }

    // ---------- Xem vé ----------
    private function ticketViewPage(string $code): void
    {
        $manager = $this->actor->isManager();
        $user = $manager ? null : $this->requireBuyer();
        if (!$manager && !$user) {
            $this->notFound();
        }
        $o = $this->tickets->find($code);
        if (!$o || (!$manager && (int)$o['user_id'] !== (int)$user['id'])) {
            $this->notFound();
        }
        if ($o['status'] !== 'paid') {
            if ($manager) {
                $this->redirect('/tickets/manage');
            }
            $this->redirect('/tickets/pay?code=' . urlencode($o['code']));
        }

        $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=0&data=' . rawurlencode($o['code']);
        $paidAt = (new \DateTimeImmutable((string)$o['paid_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'));
        $validity = $o['valid_from'] === $o['valid_to'] ? $this->d($o['valid_from']) : $this->d($o['valid_from']) . ' – ' . $this->d($o['valid_to']);

        $b = '<section class="pg-head"><div><span class="eyebrow">VÉ ĐIỆN TỬ</span><h1>Vé của bạn</h1>'
            . '<p>Đưa mã QR này cho nhân viên soát vé khi lên xe.</p></div></section>'
            . ($manager ? '' : $this->ticketTabs('mine'))
            . '<article class="pass">'
            . '<div class="pass-main">'
            . '<div class="pass-top"><span class="pass-brand"><img src="/assets/logo.png" alt="" width="30" height="30">Nam Thành Hùng</span>' . $this->usageChip($o) . '</div>'
            . '<h2>' . Text::h($o['type_name']) . '</h2>'
            . '<p class="pass-route">' . ($o['route_name'] ? Text::h('Tuyến ' . $o['route_name']) : 'Đi mọi tuyến xe buýt') . '</p>'
            . '<div class="pass-info">'
            . '<div><small>Hiệu lực</small><b>' . $validity . '</b></div>'
            . '<div><small>Số lượng</small><b>' . (int)$o['quantity'] . ' vé</b></div>'
            . '<div><small>Đã thanh toán</small><b>' . Tickets::money((int)$o['total']) . '</b></div>'
            . '<div><small>Hành khách</small><b>' . Text::h($o['username']) . '</b></div>'
            . '</div></div>'
            . '<div class="pass-cut"></div>'
            . '<div class="pass-qr"><img src="' . Text::h($qr) . '" alt="Mã QR vé ' . Text::h($o['code']) . '" width="170" height="170">'
            . '<code>' . Text::h($o['code']) . '</code><small>Thanh toán lúc ' . $paidAt->format('H:i d/m/Y') . '</small></div>'
            . '</article>'
            . '<div class="pass-actions"><button type="button" class="btn-soft" onclick="window.print()">' . $this->icon('print') . '<span>In vé</span></button>'
            . '<a class="btn-soft" href="' . ($manager ? '/tickets/manage' : '/tickets/mine') . '">' . $this->icon('receipt') . '<span>' . ($manager ? 'Quay lại quản lý đơn' : 'Tất cả vé của tôi') . '</span></a></div>';

        $this->page('Vé ' . $o['code'], $b);
    }

    // ---------- Vé của tôi ----------
    private function ticketMinePage(): void
    {
        $user = $this->requireBuyer();
        $orders = $this->tickets->forUser((int)$user['id']);

        $b = '<section class="pg-head"><div><span class="eyebrow">TÀI KHOẢN</span><h1>Vé của tôi</h1>'
            . '<p>Toàn bộ đơn vé bạn đã đặt. Vé đã thanh toán có thể mở lại bất cứ lúc nào.</p></div></section>' . $this->ticketTabs('mine');

        if (!$orders) {
            $b .= '<div class="empty-box">' . $this->icon('ticket') . '<div><b>Bạn chưa có vé nào</b><p>Mua vé đầu tiên chỉ mất vài giây.</p>'
                . '<a class="btn-link primary" href="/tickets">' . $this->icon('ticket') . '<span>Mua vé ngay</span></a></div></div>';
            $this->page('Vé của tôi', $b);
            return;
        }

        $b .= '<div class="order-list">';
        foreach ($orders as $o) {
            $validity = $o['valid_from'] === $o['valid_to'] ? $this->d($o['valid_from']) : $this->d($o['valid_from']) . ' – ' . $this->d($o['valid_to']);
            $created = (new \DateTimeImmutable((string)$o['created_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'));
            $action = match ($o['state']) {
                'paid' => '<a class="btn-soft" href="/tickets/view?code=' . urlencode($o['code']) . '">' . $this->icon('qr') . '<span>Xem vé</span></a>',
                'pending' => '<a class="btn-link primary" href="/tickets/pay?code=' . urlencode($o['code']) . '">' . $this->icon('qr') . '<span>Thanh toán</span></a>',
                default => '',
            };
            $b .= '<article class="order-card">'
                . '<div class="order-ic">' . $this->icon($o['ticket_type'] === 'single' ? 'ticket' : ($o['ticket_type'] === 'day' ? 'sun' : 'calendar')) . '</div>'
                . '<div class="order-main"><div class="order-title"><b>' . Text::h($o['type_name']) . ' × ' . (int)$o['quantity'] . '</b>' . $this->stateChip($o) . ($o['state'] === 'paid' ? $this->usageChip($o) : '') . '</div>'
                . '<p>' . ($o['route_name'] ? Text::h('Tuyến ' . $o['route_name']) : 'Mọi tuyến') . ' · Hiệu lực ' . $validity . '</p>'
                . '<small>Mã đơn <code>' . Text::h($o['code']) . '</code> · Đặt lúc ' . $created->format('H:i d/m/Y') . '</small></div>'
                . '<div class="order-side"><b class="money">' . Tickets::money((int)$o['total']) . '</b>' . $action . '</div></article>';
        }
        $b .= '</div>';
        $this->page('Vé của tôi', $b);
    }

    // ---------- Quản lý đơn (chỉ quản lý) ----------
    private function ticketManagePage(): void
    {
        $this->requireManager();
        $st = $this->tickets->stats();
        $cfg = gtfs_payment_config();
        $orders = $this->tickets->all();

        $stat = fn(string $cls, string $ic, string $num, string $label): string
            => '<div class="stat ' . $cls . '"><span class="stat-ic">' . $this->icon($ic) . '</span><div><b>' . $num . '</b><small>' . $label . '</small></div></div>';

        $b = '<section class="pg-head"><div><span class="eyebrow">QUẢN LÝ</span><h1>Đơn vé &amp; doanh thu</h1>'
            . '<p>Theo dõi đơn đặt vé của người dùng và xác nhận thủ công khi cần. Tài khoản quản lý không mua vé.</p></div></section>'
            . '<div class="stat-grid">'
            . $stat('green', 'wallet', Tickets::money($st['revenue']), 'Doanh thu đã thu')
            . $stat('blue', 'receipt', (string)$st['paid'], 'Đơn đã thanh toán')
            . $stat('amber', 'clock', (string)$st['pending'], 'Đơn đang chờ')
            . $stat('red', 'x', (string)$st['closed'], 'Đơn hủy / hết hạn')
            . '</div>';

        $b .= '<div class="panel cfg-box ' . ($cfg['configured'] ? 'cfg-ok' : 'cfg-warn') . '">' . $this->icon($cfg['configured'] ? 'check-circle' : 'info') . '<div>'
            . ($cfg['configured']
                ? '<b>Đã cấu hình nhận tiền</b><p>' . Text::h(($cfg['bank_name'] !== '' ? $cfg['bank_name'] : 'BIN ' . $cfg['bank_bin']) . ' · ' . $cfg['account_no']) . '. '
                    . ($cfg['webhook_key'] !== '' ? 'Webhook tự xác nhận đã bật tại <code>/api/payment/webhook</code>.' : 'Chưa đặt <code>webhook_key</code> nên đơn cần xác nhận thủ công bên dưới.') . '</p>'
                : '<b>Chưa cấu hình tài khoản nhận tiền</b><p>Điền ngân hàng, số tài khoản trong <code>config/payment.php</code> để QR thanh toán chuyển tiền vào tài khoản của bạn.</p>')
            . '</div></div>';

        if (!$orders) {
            $b .= '<div class="empty-box">' . $this->icon('receipt') . '<div><b>Chưa có đơn vé nào</b><p>Đơn sẽ xuất hiện ở đây khi người dùng đặt vé.</p></div></div>';
            $this->page('Đơn vé', $b);
            return;
        }

        $b .= '<div class="table-wrap"><table><tr><th>Mã đơn</th><th>Người mua</th><th>Vé</th><th>Hiệu lực</th><th>Tổng tiền</th><th>Trạng thái</th><th>Đặt lúc</th><th></th></tr>';
        foreach ($orders as $o) {
            $created = (new \DateTimeImmutable((string)$o['created_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'));
            $confirm = $o['status'] === 'pending'
                ? '<form method="post" action="/tickets/confirm" class="inline" onsubmit="return confirm(\'Xác nhận đã nhận đủ tiền cho đơn này?\')">' . $this->csrf()
                    . '<input type="hidden" name="code" value="' . Text::h($o['code']) . '"><button type="submit">Xác nhận đã nhận tiền</button></form>'
                : ($o['status'] === 'paid' ? '<a href="/tickets/view?code=' . urlencode($o['code']) . '">Xem vé</a>' : '');
            $b .= '<tr><td><code>' . Text::h($o['code']) . '</code></td><td>' . Text::h($o['username']) . '<br><small class="muted">' . Text::h($o['email']) . '</small></td>'
                . '<td>' . Text::h($o['type_name']) . ' × ' . (int)$o['quantity'] . ($o['route_name'] ? '<br><small class="muted">' . Text::h($o['route_name']) . '</small>' : '') . '</td>'
                . '<td>' . $this->d($o['valid_from']) . ($o['valid_from'] === $o['valid_to'] ? '' : ' – ' . $this->d($o['valid_to'])) . '</td>'
                . '<td><b>' . Tickets::money((int)$o['total']) . '</b></td><td>' . $this->stateChip($o) . '</td><td>' . $created->format('H:i d/m/Y') . '</td><td>' . $confirm . '</td></tr>';
        }
        $b .= '</table></div>';
        $this->page('Đơn vé', $b);
    }

    // ---------- API ----------
    private function ticketStatusApi(string $code): never
    {
        $o = $this->tickets->find($code);
        $user = $this->currentUser();
        $allowed = $o && ($this->actor->isManager() || ($user && (int)$user['id'] === (int)$o['user_id']));
        if (!$allowed) {
            $this->jsonResponse(['ok' => false, 'error' => 'Không tìm thấy đơn.'], 404);
        }
        $this->jsonResponse(['ok' => true, 'state' => $o['state'], 'paid' => $o['status'] === 'paid']);
    }

    /** Webhook báo có tiền (SePay/Casso...). Xác thực bằng "Authorization: Apikey <webhook_key>". */
    private function paymentWebhook(): never
    {
        $key = gtfs_payment_config()['webhook_key'];
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $given = trim((string)preg_replace('/^(apikey|bearer)\s+/i', '', $header));
        if ($key === '' || !hash_equals($key, $given)) {
            $this->jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->jsonResponse(['success' => false, 'message' => 'Dữ liệu không hợp lệ.'], 400);
        }
        $r = $this->tickets->handleWebhook($payload);
        $this->jsonResponse(['success' => $r['ok'], 'message' => $r['message']]);
    }

    // ---------- POST ----------
    private function ticketPost(string $path): never
    {
        switch ($path) {
            case '/tickets/buy':
                $user = $this->requireBuyer();
                try {
                    $type = (string)($_POST['type'] ?? '');
                    $route = null;
                    $rid = trim((string)($_POST['route_id'] ?? ''));
                    if ((Tickets::TYPES[$type]['needs_route'] ?? false) && $rid !== '') {
                        $vid = $this->versions->activeId();
                        foreach ($vid ? $this->catalog->routes($vid) : [] as $r) {
                            if ((string)$r['route_id'] === $rid) {
                                $short = (string)($r['route_short_name'] ?: $r['route_id']);
                                $long = trim((string)$r['route_long_name']);
                                $name = $long !== '' ? $short . ' · ' . $long : $short;
                                $route = ['route_id' => $rid, 'name' => $name];
                                break;
                            }
                        }
                        if ($route === null) {
                            throw new \InvalidArgumentException('Tuyến đã chọn không còn trong bộ lịch đang dùng.');
                        }
                    }
                    $o = $this->tickets->create((int)$user['id'], $type, $route, (int)($_POST['quantity'] ?? 1), (string)($_POST['valid_from'] ?? ''));
                    $this->redirect('/tickets/pay?code=' . urlencode($o['code']));
                } catch (\InvalidArgumentException $e) {
                    $this->flash('error', $e->getMessage());
                    $this->redirect('/tickets');
                }
            case '/tickets/cancel':
                $user = $this->requireBuyer();
                $ok = $this->tickets->cancel((string)($_POST['code'] ?? ''), (int)$user['id']);
                $this->flash($ok ? 'ok' : 'error', $ok ? 'Đã hủy đơn.' : 'Không thể hủy đơn này.');
                $this->redirect('/tickets/mine');
            case '/tickets/demo-pay':
                $user = $this->requireBuyer();
                if (gtfs_payment_config()['configured']) {
                    $this->flash('error', 'Đã cấu hình thanh toán thật nên không dùng được chế độ mô phỏng.');
                    $this->redirect('/tickets/mine');
                }
                $o = $this->tickets->find((string)($_POST['code'] ?? ''));
                if (!$o || (int)$o['user_id'] !== (int)$user['id']) {
                    $this->notFound();
                }
                if ($o['state'] === 'pending') {
                    $this->tickets->markPaid($o['code'], 'demo', null);
                }
                $this->redirect('/tickets/pay?code=' . urlencode($o['code']));
            case '/tickets/confirm':
                $this->requireManager();
                $code = (string)($_POST['code'] ?? '');
                $ok = $this->tickets->markPaid($code, 'manual', 'manager');
                if ($ok) {
                    $this->versions->log($this->actor, 'ticket_confirm', 'order=' . strtoupper($code));
                }
                $this->flash($ok ? 'ok' : 'error', $ok ? 'Đã xác nhận đơn ' . strtoupper($code) . '.' : 'Không thể xác nhận đơn này.');
                $this->redirect('/tickets/manage');
        }
        $this->notFound();
    }
}
