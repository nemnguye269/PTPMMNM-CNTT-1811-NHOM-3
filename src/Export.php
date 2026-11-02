<?php
declare(strict_types=1);
namespace Gtfs;

use PDO;

/** Xuất CSV (UTF-8 có BOM để Excel mở đúng tiếng Việt). */
final class Export
{
    public function __construct(private PDO $pdo) {}

    private static function csv(array $header, iterable $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header, ',', '"', '');
        foreach ($rows as $r) {
            // chống CSV injection khi mở bằng Excel
            $r = array_map(static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@") !== false && !is_numeric($v) ? "'" . $v : $v, $r);
            fputcsv($fh, $r, ',', '"', '');
        }
        rewind($fh);
        return (string)stream_get_contents($fh);
    }

    /** Lịch: lọc theo tuyến và/hoặc trạm (mỗi dòng một lần dừng). */
    public function schedule(int $vid, ?string $routeId = null, ?string $stopId = null): string
    {
        $w = ['st.version_id = ?'];
        $a = [$vid];
        if ($routeId) { $w[] = 't.route_id = ?'; $a[] = $routeId; }
        if ($stopId) { $w[] = 'st.stop_id = ?'; $a[] = $stopId; }
        $s = $this->pdo->prepare('SELECT t.route_id, t.trip_id, t.service_id, t.direction_id, t.trip_headsign, st.stop_sequence, st.stop_id, s.stop_name, st.arrival_text, st.departure_text
            FROM stop_times st
            JOIN trips t ON t.version_id = st.version_id AND t.trip_id = st.trip_id
            JOIN stops s ON s.version_id = st.version_id AND s.stop_id = st.stop_id
            WHERE ' . implode(' AND ', $w) . ' ORDER BY t.route_id, t.trip_id, st.stop_sequence');
        $s->execute($a);
        return self::csv(['route_id', 'trip_id', 'service_id', 'direction_id', 'trip_headsign', 'stop_sequence', 'stop_id', 'stop_name', 'arrival_time', 'departure_time'],
            array_map('array_values', $s->fetchAll()));
    }

    public function importErrors(int $vid): string
    {
        $s = $this->pdo->prepare("SELECT severity, code, file, line, message FROM import_issues WHERE version_id = ? ORDER BY (severity='error') DESC, id");
        $s->execute([$vid]);
        return self::csv(['severity', 'code', 'file', 'line', 'message'], array_map('array_values', $s->fetchAll()));
    }

    public function diff(array $d): string
    {
        $rows = [];
        foreach ($d['trips']['added'] as $t) { $rows[] = ['trip_added', $t['route_id'], $t['trip_id'], '', '', '', '']; }
        foreach ($d['trips']['removed'] as $t) { $rows[] = ['trip_removed', $t['route_id'], $t['trip_id'], '', '', '', '']; }
        foreach ($d['trips']['changed'] as $t) {
            foreach ($t['changes'] as $c) {
                $rows[] = ['trip_changed', $t['route_id'], $t['trip_id'], $c['type'], $c['seq'] ?? ($c['field'] ?? ''), $c['old'] ?? '', $c['new'] ?? ''];
            }
        }
        foreach ($d['services'] ?? [] as $sv) {
            $rows[] = ['service_' . $sv['kind'], '', '', 'service', $sv['service_id'], '', implode('; ', $sv['details'])];
        }
        return self::csv(['change', 'route_id', 'trip_id', 'type', 'seq_or_field', 'old', 'new'], $rows);
    }
}
