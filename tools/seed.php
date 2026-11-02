<?php
declare(strict_types=1);
/** Tạo CSDL demo: nhập v1, v2, bản lỗi; chọn v1 đang dùng; lưu truy vấn mẫu. */
require __DIR__ . '/../src/bootstrap.php';

use Gtfs\{Actor, Db, Importer, SavedQueries, Versions};

$path = gtfs_db_path();
foreach ([$path, "$path-wal", "$path-shm"] as $f) { if (is_file($f)) { unlink($f); } }
$pdo = Db::connect($path);
$imp = new Importer($pdo);
$v = new Versions($pdo);
$dir = __DIR__ . '/../data/sample';
$a = $imp->importZip('Lịch mùa thu 2026 (v1)', "$dir/gtfs_v1.zip");
$b = $imp->importZip('Lịch mùa đông 2026 (v2)', "$dir/gtfs_v2.zip");
$c = $imp->importZip('Bản thử có lỗi', "$dir/gtfs_loi.zip");
$v->activate($a, Actor::manager('seed'));
$s = new SavedQueries($pdo);
$s->save('Chuyến kế tiếp tại Mỹ Đình (thứ Hai)', 'next', ['stop_id' => 'S001', 'date' => '2026-10-26', 'time' => '07:00', 'limit' => '5']);
$s->save('Chuyến đêm sau 01:00 (Chủ nhật)', 'next', ['stop_id' => 'S013', 'date' => '2026-10-25', 'time' => '01:00', 'limit' => '3']);
$s->save('Chuyến R03 ngày Chủ nhật', 'trips', ['route_id' => 'R03', 'date' => '2026-11-01']);
echo "Đã tạo $path: v1=#$a (đang dùng), v2=#$b, bản lỗi=#$c\n";
