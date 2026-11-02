<?php
declare(strict_types=1);

namespace Gtfs;

use PDO;

/**
 * Dữ liệu phục vụ trang bản đồ.
 *
 * Nếu trip có shape_id và shapes.txt đã được nhập, API trả shape_points để map
 * vẽ đúng đường GTFS. Nếu không có shape, frontend có thể fallback theo trạm.
 */
final class MapData
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Danh sách tuyến của một phiên bản.
     */
    public function routes(int $versionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                r.route_id,
                r.route_short_name,
                r.route_long_name,
                r.route_type,
                COUNT(t.trip_id) AS trip_count
             FROM routes r
             LEFT JOIN trips t
               ON t.version_id = r.version_id
              AND t.route_id = r.route_id
             WHERE r.version_id = :version_id
             GROUP BY
                r.route_id,
                r.route_short_name,
                r.route_long_name,
                r.route_type
             ORDER BY
                CASE
                    WHEN r.route_short_name IS NULL OR r.route_short_name = \'\'
                    THEN r.route_id
                    ELSE r.route_short_name
                END,
                r.route_id'
        );

        $stmt->execute([
            ':version_id' => $versionId,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Thông tin tuyến và các hành trình đại diện.
     *
     * Mỗi direction_id chọn trip có nhiều điểm dừng nhất để biểu diễn
     * đường tuyến. Nếu direction_id là NULL, nó vẫn được coi là một mẫu riêng.
     */
    public function routeDetail(
        int $versionId,
        string $routeId
    ): ?array {
        $routeId = trim($routeId);
        if ($routeId === '') {
            return null;
        }

        $routeStmt = $this->pdo->prepare(
            'SELECT
                route_id,
                route_short_name,
                route_long_name,
                route_type
             FROM routes
             WHERE version_id = :version_id
               AND route_id = :route_id
             LIMIT 1'
        );

        $routeStmt->execute([
            ':version_id' => $versionId,
            ':route_id' => $routeId,
        ]);

        $route = $routeStmt->fetch();
        if (!$route) {
            return null;
        }

        $tripStmt = $this->pdo->prepare(
            'SELECT
                t.trip_id,
                t.trip_headsign,
                t.direction_id,
                t.shape_id,
                COUNT(st.stop_sequence) AS stop_count
             FROM trips t
             JOIN stop_times st
               ON st.version_id = t.version_id
              AND st.trip_id = t.trip_id
             WHERE t.version_id = :version_id
               AND t.route_id = :route_id
             GROUP BY
                t.trip_id,
                t.trip_headsign,
                t.direction_id,
                t.shape_id
             ORDER BY
                CASE WHEN t.direction_id IS NULL THEN 2 ELSE t.direction_id END,
                stop_count DESC,
                t.trip_id'
        );

        $tripStmt->execute([
            ':version_id' => $versionId,
            ':route_id' => $routeId,
        ]);

        $bestByDirection = [];

        foreach ($tripStmt->fetchAll() as $trip) {
            $key = $trip['direction_id'] === null
                ? 'null'
                : (string)(int)$trip['direction_id'];

            if (!isset($bestByDirection[$key])) {
                $bestByDirection[$key] = $trip;
            }
        }

        $stopStmt = $this->pdo->prepare(
            'SELECT
                st.stop_sequence,
                st.stop_id,
                s.stop_name,
                s.stop_lat,
                s.stop_lon,
                st.arrival_text,
                st.departure_text
             FROM stop_times st
             JOIN stops s
               ON s.version_id = st.version_id
              AND s.stop_id = st.stop_id
             WHERE st.version_id = :version_id
               AND st.trip_id = :trip_id
             ORDER BY st.stop_sequence'
        );

        $shapeStmt = $this->pdo->prepare(
            'SELECT
                shape_pt_lat,
                shape_pt_lon,
                shape_pt_sequence,
                shape_dist_traveled
             FROM shapes
             WHERE version_id = :version_id
               AND shape_id = :shape_id
             ORDER BY shape_pt_sequence'
        );

        $patterns = [];

        foreach ($bestByDirection as $trip) {
            $stopStmt->execute([
                ':version_id' => $versionId,
                ':trip_id' => (string)$trip['trip_id'],
            ]);

            $stops = [];

            foreach ($stopStmt->fetchAll() as $stop) {
                if (
                    $stop['stop_lat'] === null ||
                    $stop['stop_lon'] === null
                ) {
                    continue;
                }

                $lat = (float)$stop['stop_lat'];
                $lon = (float)$stop['stop_lon'];

                if (
                    !is_finite($lat) ||
                    !is_finite($lon) ||
                    $lat < -90 ||
                    $lat > 90 ||
                    $lon < -180 ||
                    $lon > 180
                ) {
                    continue;
                }

                $stops[] = [
                    'sequence' => (int)$stop['stop_sequence'],
                    'id' => (string)$stop['stop_id'],
                    'name' => (string)$stop['stop_name'],
                    'lat' => $lat,
                    'lon' => $lon,
                    'arrival' => (string)$stop['arrival_text'],
                    'departure' => (string)$stop['departure_text'],
                ];
            }

            if (!$stops) {
                continue;
            }

            $shapeId = trim((string)($trip['shape_id'] ?? ''));
            $shapePoints = [];

            if ($shapeId !== '') {
                $shapeStmt->execute([
                    ':version_id' => $versionId,
                    ':shape_id' => $shapeId,
                ]);

                foreach ($shapeStmt->fetchAll() as $point) {
                    $lat = (float)$point['shape_pt_lat'];
                    $lon = (float)$point['shape_pt_lon'];
                    if (
                        !is_finite($lat) || !is_finite($lon) ||
                        $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180
                    ) {
                        continue;
                    }
                    $shapePoints[] = [
                        'lat' => $lat,
                        'lon' => $lon,
                        'sequence' => (int)$point['shape_pt_sequence'],
                        'distance' => $point['shape_dist_traveled'] === null
                            ? null
                            : (float)$point['shape_dist_traveled'],
                    ];
                }
            }

            $patterns[] = [
                'trip_id' => (string)$trip['trip_id'],
                'headsign' => (string)($trip['trip_headsign'] ?? ''),
                'direction_id' => $trip['direction_id'] === null
                    ? null
                    : (int)$trip['direction_id'],
                'stop_count' => count($stops),
                'shape_id' => $shapeId === '' ? null : $shapeId,
                'shape_points' => $shapePoints,
                'stops' => $stops,
            ];
        }

        usort(
            $patterns,
            static function (array $a, array $b): int {
                $da = $a['direction_id'] ?? 99;
                $db = $b['direction_id'] ?? 99;
                return $da <=> $db;
            }
        );

        return [
            'route' => [
                'id' => (string)$route['route_id'],
                'short_name' => (string)($route['route_short_name'] ?? ''),
                'long_name' => (string)($route['route_long_name'] ?? ''),
                'type' => $route['route_type'] === null
                    ? null
                    : (int)$route['route_type'],
            ],
            'patterns' => $patterns,
        ];
    }

    /**
     * Tìm trạm theo tên (không cần dấu) và các tuyến đi qua từng trạm.
     * Dùng cho ô "Nhập điểm đến" trên bản đồ.
     *
     * @return array<int, array{id:string,name:string,lat:float,lon:float,routes:string[]}>
     */
    public function searchStops(int $versionId, string $query, int $limit = 30): array
    {
        $folded = Text::fold($query);
        if ($folded === '') {
            return [];
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $folded) . '%';

        $stmt = $this->pdo->prepare(
            'SELECT stop_id, stop_name, stop_lat, stop_lon
             FROM stops
             WHERE version_id = :v
               AND name_norm LIKE :q ESCAPE \'\\\'
               AND stop_lat IS NOT NULL AND stop_lon IS NOT NULL
             ORDER BY stop_name, stop_id
             LIMIT ' . (int)$limit
        );
        $stmt->execute([':v' => $versionId, ':q' => $like]);

        $routeStmt = $this->pdo->prepare(
            'SELECT DISTINCT t.route_id
             FROM stop_times st
             JOIN trips t ON t.version_id = st.version_id AND t.trip_id = st.trip_id
             WHERE st.version_id = :v AND st.stop_id = :s
             ORDER BY t.route_id'
        );

        $out = [];
        foreach ($stmt->fetchAll() as $s) {
            $lat = (float)$s['stop_lat'];
            $lon = (float)$s['stop_lon'];
            if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) {
                continue;
            }
            $routeStmt->execute([':v' => $versionId, ':s' => (string)$s['stop_id']]);
            $out[] = [
                'id' => (string)$s['stop_id'],
                'name' => (string)$s['stop_name'],
                'lat' => $lat,
                'lon' => $lon,
                'routes' => array_map('strval', $routeStmt->fetchAll(PDO::FETCH_COLUMN)),
            ];
        }
        return $out;
    }
}
