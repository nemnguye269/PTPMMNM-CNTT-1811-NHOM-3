<?php
declare(strict_types=1);
namespace Gtfs;

use PDO;

/**
 * Ngày hoạt động & tìm chuyến tiếp theo.
 * Mọi giờ GTFS gắn với NGÀY HOẠT ĐỘNG: thời điểm thật = nửa đêm ngày hoạt động + giờ (có thể >= 24:00:00).
 * Nên khi tra lúc 01:00 ngày D, phải xét cả các chuyến thuộc ngày hoạt động D-1 (giờ 24:xx, 25:xx).
 * (Tính theo UTC-naive, không xử lý dịch giờ mùa hè; Việt Nam không có DST.)
 */
final class Departures
{
    public function __construct(private PDO $pdo) {}

    /**
     * Các service_id chạy vào ngày hoạt động $ymd: calendar (khoảng ngày + thứ) rồi áp calendar_dates.
     * @return array<string,string> service_id => 'calendar' | 'added'
     */
    public function activeServices(int $vid, string $ymd): array
    {
        $d = Time::date($ymd) ?? throw new \InvalidArgumentException("Ngày '$ymd' không hợp lệ.");
        $dow = Spec::DAYS[(int)$d->format('N') - 1];
        $out = [];
        $s = $this->pdo->prepare("SELECT service_id FROM calendar WHERE version_id = ? AND start_date <= ? AND end_date >= ? AND $dow = 1");
        $s->execute([$vid, $ymd, $ymd]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $out[$sid] = 'calendar';
        }
        $s = $this->pdo->prepare('SELECT service_id, exception_type FROM calendar_dates WHERE version_id = ? AND date = ?');
        $s->execute([$vid, $ymd]);
        foreach ($s->fetchAll() as $r) {
            if ((int)$r['exception_type'] === 1) {
                $out[$r['service_id']] = 'added';
            } else {
                unset($out[$r['service_id']]);
            }
        }
        return $out;
    }

    /** Khoảng ngày mà bộ lịch có dữ liệu dịch vụ (min, max) hoặc null. */
    public function coverage(int $vid): ?array
    {
        $s = $this->pdo->prepare('SELECT MIN(a), MAX(b) FROM (
            SELECT start_date a, end_date b FROM calendar WHERE version_id = ?1
            UNION ALL SELECT date, date FROM calendar_dates WHERE version_id = ?1 AND exception_type = 1)');
        $s->execute([$vid]);
        $r = $s->fetch(PDO::FETCH_NUM);
        return $r && $r[0] ? [$r[0], $r[1]] : null;
    }

    /**
     * @return array{trips: array, message: ?string, query: array}
     * Mỗi chuyến có: service_date (ngày hoạt động), departure_text (giờ GTFS, vd 25:10:00),
     * clock (giờ thật), real_date (ngày thật), via (calendar|added).
     */
    public function next(int $vid, string $stopId, string $ymd, string $time, int $limit = 10, ?string $routeId = null, int $horizonDays = 7): array
    {
        $qsecs = Time::parseQuery($time);
        $date0 = Time::date($ymd);
        $query = ['stop_id' => $stopId, 'date' => $ymd, 'time' => $time];
        if ($qsecs === null || $date0 === null) {
            return ['trips' => [], 'message' => 'Ngày hoặc giờ tra cứu không hợp lệ (giờ dạng HH:MM, 00:00–47:59).', 'query' => $query];
        }
        $st = $this->pdo->prepare('SELECT stop_name FROM stops WHERE version_id = ? AND stop_id = ?');
        $st->execute([$vid, $stopId]);
        $stopName = $st->fetchColumn();
        if ($stopName === false) {
            return ['trips' => [], 'message' => "Trạm '$stopId' không có trong bộ lịch này.", 'query' => $query];
        }
        $query['stop_name'] = $stopName;

        $qAbs = $date0->getTimestamp() + $qsecs;
        $m = $this->pdo->prepare('SELECT MAX(departure_secs) FROM stop_times WHERE version_id = ?');
        $m->execute([$vid]);
        $lookBack = intdiv((int)$m->fetchColumn(), 86400);

        $sql = 'SELECT st.trip_id, st.stop_sequence, st.departure_secs, st.departure_text, st.arrival_text,
                       t.route_id, t.service_id, t.trip_headsign, r.route_short_name, r.route_long_name
                FROM stop_times st
                JOIN trips t ON t.version_id = st.version_id AND t.trip_id = st.trip_id
                JOIN routes r ON r.version_id = t.version_id AND r.route_id = t.route_id
                WHERE st.version_id = ? AND st.stop_id = ? AND st.is_last = 0 AND st.departure_secs >= ?'
             . ($routeId ? ' AND t.route_id = ?' : '') . ' ORDER BY st.departure_secs';

        $found = [];
        $anyService = false;
        for ($k = -$lookBack; $k <= $horizonDays; $k++) {
            $day = $date0->modify(($k >= 0 ? '+' : '') . $k . ' days');
            $dayStart = $day->getTimestamp();
            if (count($found) >= $limit) {
                usort($found, static fn($a, $b) => $a['abs'] <=> $b['abs']);
                if ($dayStart > $found[$limit - 1]['abs']) {
                    break;
                }
            }
            $services = $this->activeServices($vid, $day->format('Ymd'));
            if (!$services) {
                continue;
            }
            $anyService = true;
            $args = [$vid, $stopId, max(0, $qAbs - $dayStart)];
            $routeId && $args[] = $routeId;
            $q = $this->pdo->prepare($sql);
            $q->execute($args);
            foreach ($q->fetchAll() as $r) {
                if (!isset($services[$r['service_id']])) {
                    continue;
                }
                $abs = $dayStart + (int)$r['departure_secs'];
                $real = (new \DateTimeImmutable('@' . $abs))->setTimezone(new \DateTimeZone('UTC'));
                $found[] = [
                    'abs' => $abs, 'trip_id' => $r['trip_id'], 'route_id' => $r['route_id'],
                    'route_name' => trim(($r['route_short_name'] ?: '') . ' ' . ($r['route_long_name'] ?: '')),
                    'headsign' => $r['trip_headsign'], 'service_id' => $r['service_id'],
                    'service_date' => $day->format('Ymd'), 'via' => $services[$r['service_id']],
                    'departure_text' => $r['departure_text'], 'clock' => $real->format('H:i'),
                    'real_date' => $real->format('Ymd'), 'next_day' => (int)$r['departure_secs'] >= 86400,
                ];
            }
        }
        usort($found, static fn($a, $b) => $a['abs'] <=> $b['abs']);
        $found = array_slice($found, 0, $limit);

        $message = null;
        if (!$found) {
            $cov = $this->coverage($vid);
            $from = Time::pretty($ymd) . ' ' . substr($time, 0, 5);
            if ($cov && ($ymd > $cov[1] || $date0->modify("+$horizonDays days")->format('Ymd') < $cov[0])) {
                $message = 'Ngày tra cứu nằm ngoài phạm vi bộ lịch (' . Time::pretty($cov[0]) . ' – ' . Time::pretty($cov[1]) . ').';
            } elseif (!$anyService) {
                $message = "Không có dịch vụ nào hoạt động trong $horizonDays ngày kể từ $from.";
            } else {
                $message = "Không có chuyến nào rời trạm '$stopName' trong $horizonDays ngày kể từ $from"
                    . ($routeId ? " trên tuyến $routeId." : '.');
            }
        }
        return ['trips' => $found, 'message' => $message, 'query' => $query];
    }
}
