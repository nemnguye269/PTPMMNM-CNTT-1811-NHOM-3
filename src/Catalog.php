<?php
declare(strict_types=1);
namespace Gtfs;

use PDO;

/** Tra cứu tuyến, trạm, chuyến, lần dừng. Tìm theo tên (không phân biệt dấu) hoặc mã; bộ lọc kết hợp được. */
final class Catalog
{
    public function __construct(private PDO $pdo) {}

    private function like(string $q): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Text::fold($q)) . '%';
    }

    public function routes(int $vid, array $f = [], int $limit = 200): array
    {
        $w = ['r.version_id = :v'];
        $p = [':v' => $vid];
        if (($f['q'] ?? '') !== '') {
            $w[] = "(r.name_norm LIKE :q ESCAPE '\\' OR lower(r.route_id) LIKE :q ESCAPE '\\')";
            $p[':q'] = $this->like($f['q']);
        }
        if (($f['stop_id'] ?? '') !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM trips t JOIN stop_times s ON s.version_id = t.version_id AND s.trip_id = t.trip_id
                            WHERE t.version_id = r.version_id AND t.route_id = r.route_id AND s.stop_id = :stop)';
            $p[':stop'] = $f['stop_id'];
        }
        if (($f['route_type'] ?? '') !== '') {
            $w[] = 'r.route_type = :rt';
            $p[':rt'] = (int)$f['route_type'];
        }
        $sql = 'SELECT r.*, (SELECT COUNT(*) FROM trips t WHERE t.version_id = r.version_id AND t.route_id = r.route_id) AS trip_count
                FROM routes r WHERE ' . implode(' AND ', $w) . ' ORDER BY r.route_id LIMIT ' . (int)$limit;
        $s = $this->pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    }

    public function stops(int $vid, array $f = [], int $limit = 200): array
    {
        $w = ['s.version_id = :v'];
        $p = [':v' => $vid];
        if (($f['q'] ?? '') !== '') {
            $w[] = "(s.name_norm LIKE :q ESCAPE '\\' OR lower(s.stop_id) LIKE :q ESCAPE '\\')";
            $p[':q'] = $this->like($f['q']);
        }
        if (($f['route_id'] ?? '') !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM stop_times st JOIN trips t ON t.version_id = st.version_id AND t.trip_id = st.trip_id
                            WHERE st.version_id = s.version_id AND st.stop_id = s.stop_id AND t.route_id = :route)';
            $p[':route'] = $f['route_id'];
        }
        $s = $this->pdo->prepare('SELECT s.* FROM stops s WHERE ' . implode(' AND ', $w) . ' ORDER BY s.stop_name, s.stop_id LIMIT ' . (int)$limit);
        $s->execute($p);
        return $s->fetchAll();
    }

    public function trips(int $vid, array $f = [], int $limit = 200): array
    {
        $w = ['t.version_id = :v'];
        $p = [':v' => $vid];
        if (($f['q'] ?? '') !== '') {
            $w[] = "(lower(t.trip_id) LIKE :q ESCAPE '\\' OR t.headsign_norm LIKE :q ESCAPE '\\')";
            $p[':q'] = $this->like($f['q']);
        }
        if (($f['route_id'] ?? '') !== '') {
            $w[] = 't.route_id = :route';
            $p[':route'] = $f['route_id'];
        }
        if (($f['stop_id'] ?? '') !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM stop_times st WHERE st.version_id = t.version_id AND st.trip_id = t.trip_id AND st.stop_id = :stop)';
            $p[':stop'] = $f['stop_id'];
        }
        if (($f['direction_id'] ?? '') !== '') {
            $w[] = 't.direction_id = :dir';
            $p[':dir'] = (int)$f['direction_id'];
        }
        if (($f['date'] ?? '') !== '') {
            $ymd = Time::normalizeDate($f['date']);
            $ids = $ymd ? array_keys((new Departures($this->pdo))->activeServices($vid, $ymd)) : [];
            if (!$ids) {
                return [];
            }
            $in = [];
            foreach ($ids as $i => $sid) {
                $in[] = ":sv$i";
                $p[":sv$i"] = $sid;
            }
            $w[] = 't.service_id IN (' . implode(',', $in) . ')';
        }
        $sql = 'SELECT t.*, r.route_short_name, r.route_long_name,
                  (SELECT MIN(departure_secs) FROM stop_times s WHERE s.version_id = t.version_id AND s.trip_id = t.trip_id) AS first_dep,
                  (SELECT COUNT(*) FROM stop_times s WHERE s.version_id = t.version_id AND s.trip_id = t.trip_id) AS stop_count
                FROM trips t JOIN routes r ON r.version_id = t.version_id AND r.route_id = t.route_id
                WHERE ' . implode(' AND ', $w) . ' ORDER BY t.route_id, first_dep, t.trip_id LIMIT ' . (int)$limit;
        $s = $this->pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    }

    /** Một chuyến và toàn bộ lần dừng. */
    public function trip(int $vid, string $tripId): ?array
    {
        $s = $this->pdo->prepare('SELECT t.*, r.route_short_name, r.route_long_name FROM trips t
            JOIN routes r ON r.version_id = t.version_id AND r.route_id = t.route_id WHERE t.version_id = ? AND t.trip_id = ?');
        $s->execute([$vid, $tripId]);
        $trip = $s->fetch();
        if (!$trip) {
            return null;
        }
        $s = $this->pdo->prepare('SELECT st.stop_sequence, st.stop_id, s.stop_name, st.arrival_text, st.departure_text
            FROM stop_times st JOIN stops s ON s.version_id = st.version_id AND s.stop_id = st.stop_id
            WHERE st.version_id = ? AND st.trip_id = ? ORDER BY st.stop_sequence');
        $s->execute([$vid, $tripId]);
        $trip['stop_times'] = $s->fetchAll();
        return $trip;
    }

    public function counts(int $vid): array
    {
        $o = [];
        foreach (['routes', 'stops', 'trips', 'stop_times', 'calendar', 'calendar_dates'] as $t) {
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM $t WHERE version_id = ?");
            $s->execute([$vid]);
            $o[$t] = (int)$s->fetchColumn();
        }
        return $o;
    }
}
