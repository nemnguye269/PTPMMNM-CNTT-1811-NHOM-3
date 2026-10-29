<?php
declare(strict_types=1);
namespace Gtfs;

use PDO;

/**
 * Nhập một bộ GTFS thành MỘT phiên bản mới, độc lập.
 * - Kiểm tra trong bộ nhớ trước (Validator).
 * - Ghi trong một giao dịch; bản có lỗi chỉ lưu danh sách lỗi, không lưu dữ liệu lịch.
 * - Không bao giờ sửa/xóa dữ liệu của phiên bản khác hoặc con trỏ "bản đang dùng".
 */
final class Importer
{
    public function __construct(private PDO $pdo) {}

    /** @param array<string,string> $files tên tệp (basename) => nội dung */
    public function importFiles(string $label, string $sourceName, array $files): int
    {
        $tables = [];
        foreach ($files as $name => $content) {
            if (str_ends_with(strtolower($name), '.txt')) {
                $tables[strtolower($name)] = Csv::parse($content);
            }
        }
        $result = Validator::validate($tables);
        $status = $result['errors'] === 0 ? 'valid' : 'invalid';

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO versions(label, source_name, status, error_count, warning_count, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$label, $sourceName, $status, $result['errors'], $result['warnings'], gmdate('Y-m-d H:i:s')]);
            $vid = (int)$this->pdo->lastInsertId();

            $ins = $this->pdo->prepare('INSERT INTO import_issues(version_id, severity, code, file, line, message) VALUES (?,?,?,?,?,?)');
            foreach ($result['issues'] as $i) {
                $ins->execute([$vid, $i['severity'], $i['code'], $i['file'], $i['line'], $i['message']]);
            }
            if ($status === 'valid') {
                $this->store($vid, $tables);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $vid;
    }

    public function importZip(string $label, string $zipPath, ?string $sourceName = null): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Không mở được tệp ZIP.');
        }
        $files = [];
        $declared = 0;
        $actual = 0;
        $limit = Spec::MAX_FILE_BYTES;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $base = basename(str_replace('\\', '/', $st['name']));
            if ($st['size'] === 0 && str_ends_with($st['name'], '/')) {
                continue;
            }
            // Kích thước khai báo trong tiêu đề ZIP có thể bị làm giả -> kiểm tra sớm, rồi kiểm tra lại theo byte thực đọc.
            $declared += $st['size'];
            if ($st['size'] > $limit || $declared > 3 * $limit) {
                $zip->close();
                throw new \RuntimeException('ZIP vượt giới hạn kích thước cho phép.');
            }
            if (!str_ends_with(strtolower($base), '.txt')) {
                continue;
            }
            $h = $zip->getStream($st['name']);
            if ($h === false) {
                $zip->close();
                throw new \RuntimeException("Không đọc được tệp '$base' trong ZIP.");
            }
            $data = stream_get_contents($h, $limit + 1);
            fclose($h);
            if ($data === false || strlen($data) > $limit || ($actual += strlen($data)) > 3 * $limit) {
                $zip->close();
                throw new \RuntimeException('ZIP vượt giới hạn kích thước cho phép (theo dữ liệu thực tế).');
            }
            $files[$base] = $data;
        }
        $zip->close();
        if (!$files) {
            throw new \RuntimeException('ZIP không chứa tệp .txt nào.');
        }
        return $this->importFiles($label, $sourceName ?? basename($zipPath), $files);
    }

    public function importDir(string $label, string $dir): int
    {
        $files = [];
        foreach (glob(rtrim($dir, '/') . '/*.txt') ?: [] as $p) {
            $files[basename($p)] = (string)file_get_contents($p);
        }
        return $this->importFiles($label, basename($dir), $files);
    }

    private function store(int $vid, array $t): void
    {
        $p = $this->pdo;
        $rows = static fn(string $n) => isset($t[$n]) ? $t[$n]['rows'] : [];

        $s = $p->prepare('INSERT INTO agency VALUES (?,?,?,?,?)');
        foreach ($rows('agency.txt') as ['data' => $d]) {
            $s->execute([$vid, $d['agency_id'] ?? null, $d['agency_name'], $d['agency_url'], $d['agency_timezone']]);
        }
        $s = $p->prepare('INSERT INTO stops VALUES (?,?,?,?,?,?)');
        foreach ($rows('stops.txt') as ['data' => $d]) {
            $s->execute([$vid, $d['stop_id'], $d['stop_name'], Text::fold($d['stop_name']),
                ($d['stop_lat'] ?? '') === '' ? null : (float)$d['stop_lat'],
                ($d['stop_lon'] ?? '') === '' ? null : (float)$d['stop_lon']]);
        }
        $s = $p->prepare('INSERT INTO routes VALUES (?,?,?,?,?,?)');
        foreach ($rows('routes.txt') as ['data' => $d]) {
            $short = $d['route_short_name'] ?? '';
            $long = $d['route_long_name'] ?? '';
            $s->execute([$vid, $d['route_id'], $short, $long, (int)$d['route_type'], Text::fold("$short $long")]);
        }
        $s = $p->prepare('INSERT INTO trips VALUES (?,?,?,?,?,?,?)');
        foreach ($rows('trips.txt') as ['data' => $d]) {
            $hs = $d['trip_headsign'] ?? '';
            $dir = ($d['direction_id'] ?? '') === '' ? null : (int)$d['direction_id'];
            $s->execute([$vid, $d['trip_id'], $d['route_id'], $d['service_id'], $hs, Text::fold($hs), $dir]);
        }

        $byTrip = [];
        foreach ($rows('stop_times.txt') as ['data' => $d]) {
            $arr = $d['arrival_time'] === '' ? null : Time::parse($d['arrival_time']);
            $dep = $d['departure_time'] === '' ? null : Time::parse($d['departure_time']);
            $arr ??= $dep;
            $dep ??= $arr;
            $byTrip[$d['trip_id']][] = [(int)$d['stop_sequence'], $d['stop_id'], $arr, $dep];
        }
        $s = $p->prepare('INSERT INTO stop_times VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($byTrip as $tid => $list) {
            $max = max(array_column($list, 0));
            foreach ($list as [$seq, $stop, $arr, $dep]) {
                $s->execute([$vid, $tid, $seq, $stop, $arr, $dep, Time::format($arr), Time::format($dep), $seq === $max ? 1 : 0]);
            }
        }
        $s = $p->prepare('INSERT INTO calendar VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($rows('calendar.txt') as ['data' => $d]) {
            $s->execute(array_merge([$vid, $d['service_id']], array_map(static fn($day) => (int)$d[$day], Spec::DAYS), [$d['start_date'], $d['end_date']]));
        }
        $s = $p->prepare('INSERT INTO calendar_dates VALUES (?,?,?,?)');
        foreach ($rows('calendar_dates.txt') as ['data' => $d]) {
            $s->execute([$vid, $d['service_id'], $d['date'], (int)$d['exception_type']]);
        }
    }
}
