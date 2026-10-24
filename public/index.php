<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false; // để máy chủ PHP tích hợp phục vụ tệp tĩnh
}
$pdo = Gtfs\Db::connect(gtfs_db_path());
(new Gtfs\App($pdo))->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', rtrim($path, '/') ?: '/');
