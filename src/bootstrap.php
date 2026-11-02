<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Gtfs\\')) {
        $file = __DIR__ . '/' . substr($class, 5) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function gtfs_root(): string { return dirname(__DIR__); }

function gtfs_db_path(): string {
    return getenv('GTFS_DB') ?: gtfs_root() . '/data/gtfs.sqlite';
}

/** Thư mục gốc URL của ứng dụng (vd. "/PTPMMNM-CNTT-1811-NHOM-3-main/public" khi chạy trong XAMPP, "" khi chạy php -S). */
function gtfs_base(): string {
    static $base = null;
    if ($base === null) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    }
    return $base;
}

/** Đường dẫn nội bộ ("/map") -> URL đầy đủ có tiền tố thư mục. */
function gtfs_url(string $path): string {
    return gtfs_base() . $path;
}

/** Bỏ tiền tố thư mục khỏi đường dẫn request. */
function gtfs_strip_base(string $path): string {
    $b = gtfs_base();
    if ($b !== '' && str_starts_with($path, $b)) {
        $path = substr($path, strlen($b));
    }
    return ($path === '' ? '/' : $path);
}

/** Thêm tiền tố thư mục vào mọi href/src/action bắt đầu bằng "/" trong HTML. */
function gtfs_rebase_html(string $html): string {
    // Chống cache cũ: gắn ?v=<thời gian sửa file> vào CSS/JS của ứng dụng
    $html = preg_replace_callback('~\b(href|src)=(["\'])/assets/([A-Za-z0-9_.-]+\.(?:css|js))\2~', static function (array $m): string {
        $file = __DIR__ . '/../public/assets/' . $m[3];
        $v = is_file($file) ? filemtime($file) : time();
        return $m[1] . '=' . $m[2] . '/assets/' . $m[3] . '?v=' . $v . $m[2];
    }, $html);
    $b = gtfs_base();
    if ($b === '') {
        return $html;
    }
    $html = preg_replace('~\b(href|src|action)=(["\'])/(?!/)~', '$1=$2' . $b . '/', $html);
    return str_replace('</head>', '<script>window.GTFS_BASE=' . json_encode($b) . ';</script></head>', $html);
}

/** Mật khẩu quản lý: lấy từ biến môi trường GTFS_ADMIN_PASSWORD, nếu không đặt thì dùng mặc định 123456. */
function gtfs_admin_password(): string {
    $pw = getenv('GTFS_ADMIN_PASSWORD');
    return ($pw !== false && $pw !== '') ? $pw : '123456';
}

/**
 * Cấu hình nhận tiền (VietQR). Ưu tiên biến môi trường, sau đó đến config/payment.php.
 * Khi chưa điền bank_bin + account_no, hệ thống chạy ở chế độ chưa cấu hình (có nút mô phỏng thanh toán).
 */
function gtfs_payment_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $file = gtfs_root() . '/config/payment.php';
        $f = is_file($file) ? (array)(require $file) : [];
        $pick = static function (string $env, string $key) use ($f): string {
            $v = getenv($env);
            if ($v !== false && trim($v) !== '') {
                return trim($v);
            }
            return trim((string)($f[$key] ?? ''));
        };
        $cfg = [
            'bank_bin' => $pick('GTFS_BANK_BIN', 'bank_bin'),
            'bank_name' => $pick('GTFS_BANK_NAME', 'bank_name'),
            'account_no' => $pick('GTFS_BANK_ACCOUNT', 'account_no'),
            'account_name' => $pick('GTFS_BANK_HOLDER', 'account_name'),
            'webhook_key' => $pick('GTFS_WEBHOOK_KEY', 'webhook_key'),
        ];
        $cfg['configured'] = $cfg['bank_bin'] !== '' && $cfg['account_no'] !== '';
    }
    return $cfg;
}
