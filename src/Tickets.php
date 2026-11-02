<?php
declare(strict_types=1);
namespace Gtfs;

use PDO;

/** Vé điện tử: bảng giá, tạo đơn, xác nhận thanh toán và mã QR chuyển khoản (VietQR). */
final class Tickets
{
    public const HOLD_MINUTES = 15;

    public const TYPES = [
        'single' => [
            'name' => 'Vé lượt', 'price' => 8000, 'days' => 1, 'needs_route' => true, 'max_qty' => 10,
            'desc' => '1 lượt đi trên một tuyến, dùng trong ngày đã chọn.',
        ],
        'day' => [
            'name' => 'Vé ngày', 'price' => 30000, 'days' => 1, 'needs_route' => false, 'max_qty' => 5,
            'desc' => 'Đi không giới hạn mọi tuyến trong 1 ngày.',
        ],
        'month' => [
            'name' => 'Vé tháng', 'price' => 200000, 'days' => 30, 'needs_route' => false, 'max_qty' => 3,
            'desc' => 'Đi không giới hạn mọi tuyến trong 30 ngày liên tiếp.',
        ],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public static function money(int $n): string
    {
        return number_format($n, 0, ',', '.') . ' ₫';
    }

    public static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
    }

    /** @param array{route_id:string,name:string}|null $route */
    public function create(int $userId, string $type, ?array $route, int $qty, string $validFrom): array
    {
        $def = self::TYPES[$type] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException('Loại vé không hợp lệ.');
        }
        if ($qty < 1 || $qty > $def['max_qty']) {
            throw new \InvalidArgumentException('Số lượng vé phải từ 1 đến ' . $def['max_qty'] . '.');
        }
        $tz = new \DateTimeZone('Asia/Ho_Chi_Minh');
        $today = new \DateTimeImmutable('today', $tz);
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom, $tz);
        if (!$from || $from->format('Y-m-d') !== $validFrom) {
            throw new \InvalidArgumentException('Ngày sử dụng không hợp lệ.');
        }
        if ($from < $today || $from > $today->modify('+30 days')) {
            throw new \InvalidArgumentException('Ngày sử dụng phải nằm trong 30 ngày kể từ hôm nay.');
        }
        if ($def['needs_route'] && $route === null) {
            throw new \InvalidArgumentException('Vui lòng chọn tuyến xe cho vé lượt.');
        }
        if (!$def['needs_route']) {
            $route = null;
        }
        $to = $from->modify('+' . ($def['days'] - 1) . ' days');
        $unit = (int)$def['price'];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_orders
               (code, user_id, ticket_type, route_id, route_name, quantity, unit_price, total,
                valid_from, valid_to, status, created_at, expires_at)
             VALUES (:code, :uid, :type, :rid, :rname, :qty, :unit, :total,
                :vf, :vt, \'pending\', :created, :expires)'
        );
        for ($attempt = 1; ; $attempt++) {
            $code = 'GTFS' . strtoupper(bin2hex(random_bytes(4)));
            try {
                $stmt->execute([
                    ':code' => $code, ':uid' => $userId, ':type' => $type,
                    ':rid' => $route['route_id'] ?? null, ':rname' => $route['name'] ?? null,
                    ':qty' => $qty, ':unit' => $unit, ':total' => $unit * $qty,
                    ':vf' => $from->format('Y-m-d'), ':vt' => $to->format('Y-m-d'),
                    ':created' => gmdate('Y-m-d H:i:s'),
                    ':expires' => gmdate('Y-m-d H:i:s', time() + self::HOLD_MINUTES * 60),
                ]);
                break;
            } catch (\PDOException $e) {
                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }
        return $this->find($code) ?? [];
    }

    private function decorate(array $o): array
    {
        $o['expires_ts'] = (int)strtotime((string)$o['expires_at'] . ' UTC');
        $o['state'] = ($o['status'] === 'pending' && $o['expires_ts'] < time()) ? 'expired' : (string)$o['status'];
        $o['type_name'] = self::TYPES[$o['ticket_type']]['name'] ?? (string)$o['ticket_type'];
        $usage = '';
        if ($o['status'] === 'paid') {
            $today = self::today();
            $usage = $today < $o['valid_from'] ? 'upcoming' : ($today > $o['valid_to'] ? 'ended' : 'active');
        }
        $o['usage'] = $usage;
        return $o;
    }

    public function find(string $code): ?array
    {
        $s = $this->pdo->prepare(
            'SELECT o.*, u.username, u.email FROM ticket_orders o
             JOIN users u ON u.id = o.user_id WHERE o.code = :c LIMIT 1'
        );
        $s->execute([':c' => strtoupper(trim($code))]);
        $r = $s->fetch();
        return $r ? $this->decorate($r) : null;
    }

    public function forUser(int $userId): array
    {
        $s = $this->pdo->prepare(
            'SELECT o.*, u.username, u.email FROM ticket_orders o
             JOIN users u ON u.id = o.user_id WHERE o.user_id = :u ORDER BY o.id DESC LIMIT 200'
        );
        $s->execute([':u' => $userId]);
        return array_map(fn(array $r): array => $this->decorate($r), $s->fetchAll());
    }

    public function all(int $limit = 200): array
    {
        $s = $this->pdo->query(
            'SELECT o.*, u.username, u.email FROM ticket_orders o
             JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT ' . (int)$limit
        );
        return array_map(fn(array $r): array => $this->decorate($r), $s->fetchAll());
    }

    public function stats(): array
    {
        $s = $this->pdo->prepare(
            'SELECT
               COALESCE(SUM(CASE WHEN status = \'paid\' THEN total END), 0) AS revenue,
               COALESCE(SUM(CASE WHEN status = \'paid\' THEN 1 ELSE 0 END), 0) AS paid,
               COALESCE(SUM(CASE WHEN status = \'pending\' AND expires_at >= :now1 THEN 1 ELSE 0 END), 0) AS pending,
               COALESCE(SUM(CASE WHEN status = \'cancelled\' OR (status = \'pending\' AND expires_at < :now2) THEN 1 ELSE 0 END), 0) AS closed
             FROM ticket_orders'
        );
        $now = gmdate('Y-m-d H:i:s');
        $s->execute([':now1' => $now, ':now2' => $now]);
        $r = $s->fetch() ?: [];
        return [
            'revenue' => (int)($r['revenue'] ?? 0), 'paid' => (int)($r['paid'] ?? 0),
            'pending' => (int)($r['pending'] ?? 0), 'closed' => (int)($r['closed'] ?? 0),
        ];
    }

    /** Đánh dấu đã thanh toán. Chỉ đơn đang chờ mới chuyển được. */
    public function markPaid(string $code, string $method, ?string $ref = null): bool
    {
        $s = $this->pdo->prepare(
            'UPDATE ticket_orders SET status = \'paid\', paid_at = :at, pay_method = :m, pay_ref = :r
             WHERE code = :c AND status = \'pending\''
        );
        $s->execute([':at' => gmdate('Y-m-d H:i:s'), ':m' => $method, ':r' => $ref, ':c' => strtoupper($code)]);
        return $s->rowCount() > 0;
    }

    public function cancel(string $code, int $userId): bool
    {
        $s = $this->pdo->prepare(
            'UPDATE ticket_orders SET status = \'cancelled\'
             WHERE code = :c AND user_id = :u AND status = \'pending\''
        );
        $s->execute([':c' => strtoupper($code), ':u' => $userId]);
        return $s->rowCount() > 0;
    }

    /** Link ảnh QR VietQR (quét được bằng mọi app ngân hàng). Null nếu chưa cấu hình tài khoản nhận tiền. */
    public function qrUrl(array $order, array $cfg): ?string
    {
        if (empty($cfg['configured'])) {
            return null;
        }
        $query = http_build_query([
            'amount' => (int)$order['total'],
            'addInfo' => (string)$order['code'],
            'accountName' => (string)$cfg['account_name'],
        ], '', '&', PHP_QUERY_RFC3986);
        return 'https://img.vietqr.io/image/' . rawurlencode($cfg['bank_bin']) . '-' . rawurlencode($cfg['account_no'])
            . '-compact2.png?' . $query;
    }

    /**
     * Xử lý webhook báo có tiền vào (định dạng của SePay: transferType, transferAmount, content, code, id).
     * @return array{ok:bool,message:string}
     */
    public function handleWebhook(array $p): array
    {
        if (strtolower((string)($p['transferType'] ?? 'in')) !== 'in') {
            return ['ok' => true, 'message' => 'Bỏ qua giao dịch tiền ra.'];
        }
        $haystack = strtoupper((string)($p['code'] ?? '') . ' ' . (string)($p['content'] ?? '') . ' ' . (string)($p['description'] ?? ''));
        if (!preg_match('/GTFS[0-9A-F]{8}/', $haystack, $m)) {
            return ['ok' => true, 'message' => 'Không tìm thấy mã đơn trong nội dung chuyển khoản.'];
        }
        $order = $this->find($m[0]);
        if (!$order) {
            return ['ok' => true, 'message' => 'Mã đơn không tồn tại.'];
        }
        if ($order['status'] === 'paid') {
            return ['ok' => true, 'message' => 'Đơn đã được xác nhận trước đó.'];
        }
        if ($order['status'] !== 'pending') {
            return ['ok' => true, 'message' => 'Đơn đã hủy, cần hoàn tiền thủ công: ' . $order['code']];
        }
        $amount = (int)round((float)($p['transferAmount'] ?? 0));
        if ($amount < (int)$order['total']) {
            return ['ok' => true, 'message' => 'Số tiền chưa đủ (' . $amount . ' < ' . $order['total'] . ').'];
        }
        $ref = (string)($p['referenceCode'] ?? $p['id'] ?? '');
        $this->markPaid($order['code'], 'bank_qr', $ref !== '' ? $ref : null);
        return ['ok' => true, 'message' => 'Đã xác nhận đơn ' . $order['code'] . '.'];
    }
}
