<?php
declare(strict_types=1);
namespace Gtfs;

use PDO;

/** So sánh hai phiên bản theo tuyến và chuyến (khóa chuyến = trip_id, khóa lần dừng = stop_sequence). */
final class Diff
{
    public function __construct(private PDO $pdo) {}

    public function compare(int $a, int $b, ?string $routeId = null): array
    {
        $ra = $this->routes($a);
        $rb = $this->routes($b);
        $routes = ['added' => [], 'removed' => [], 'changed' => []];
        foreach ($rb as $id => $r) {
            if (!isset($ra[$id])) { $routes['added'][] = $r; }
            elseif ($ra[$id]['name'] !== $r['name']) { $routes['changed'][] = ['route_id' => $id, 'old' => $ra[$id]['name'], 'new' => $r['name']]; }
        }
        foreach ($ra as $id => $r) {
            if (!isset($rb[$id])) { $routes['removed'][] = $r; }
        }

        $ta = $this->trips($a, $routeId);
        $tb = $this->trips($b, $routeId);
        $sa = $this->stopTimes($a);
        $sb = $this->stopTimes($b);

        $trips = ['added' => [], 'removed' => [], 'changed' => []];
        foreach ($tb as $id => $t) {
            if (!isset($ta[$id])) {
                $trips['added'][] = $t;
                continue;
            }
            $changes = [];
            foreach (['route_id', 'service_id', 'trip_headsign'] as $f) {
                if ($ta[$id][$f] !== $t[$f]) {
                    $changes[] = ['type' => 'attr', 'field' => $f, 'old' => $ta[$id][$f], 'new' => $t[$f]];
                }
            }
            $old = $sa[$id] ?? [];
            $new = $sb[$id] ?? [];
            foreach ($new as $seq => $n) {
                if (!isset($old[$seq])) {
                    $changes[] = ['type' => 'stop_added', 'seq' => $seq, 'stop_id' => $n['stop_id'], 'new' => $n['dep']];
                    continue;
                }
                $o = $old[$seq];
                if ($o['stop_id'] !== $n['stop_id']) {
                    $changes[] = ['type' => 'stop_changed', 'seq' => $seq, 'stop_id' => $n['stop_id'], 'old' => $o['stop_id'], 'new' => $n['stop_id']];
                }
                foreach (['arr' => 'arrival_time', 'dep' => 'departure_time'] as $k => $label) {
                    if ($o[$k] !== $n[$k]) {
                        $changes[] = ['type' => 'time', 'seq' => $seq, 'stop_id' => $n['stop_id'], 'field' => $label, 'old' => $o[$k], 'new' => $n[$k]];
                    }
                }
            }
            foreach ($old as $seq => $o) {
                if (!isset($new[$seq])) {
                    $changes[] = ['type' => 'stop_removed', 'seq' => $seq, 'stop_id' => $o['stop_id'], 'old' => $o['dep']];
                }
            }
            if ($changes) {
                $trips['changed'][] = ['trip_id' => $id, 'route_id' => $t['route_id'], 'changes' => $changes];
            }
        }
        foreach ($ta as $id => $t) {
            if (!isset($tb[$id])) {
                $trips['removed'][] = $t;
            }
        }
        $services = $this->services($a, $b, $ta, $tb, $routeId !== null);

        // Tổng hợp theo tuyến
        $perRoute = [];
        foreach (['added', 'removed'] as $k) {
            foreach ($trips[$k] as $t) { $perRoute[$t['route_id']][$k] = ($perRoute[$t['route_id']][$k] ?? 0) + 1; }
        }
        foreach ($trips['changed'] as $t) { $perRoute[$t['route_id']]['changed'] = ($perRoute[$t['route_id']]['changed'] ?? 0) + 1; }
        ksort($perRoute);

        return ['from' => $a, 'to' => $b, 'routes' => $routes, 'trips' => $trips, 'services' => $services, 'per_route' => $perRoute,
                'summary' => [
                    'trips_added' => count($trips['added']), 'trips_removed' => count($trips['removed']),
                    'trips_changed' => count($trips['changed']),
                    'routes_added' => count($routes['added']), 'routes_removed' => count($routes['removed']),
                    'routes_changed' => count($routes['changed']),
                    'services_changed' => count($services),
                ]];
    }

    /**
     * So sánh lịch hoạt động (calendar + calendar_dates) theo service_id.
     * Bắt trường hợp giữ nguyên service_id nhưng đổi thứ chạy, khoảng ngày hoặc ngày ngoại lệ.
     * @param array $ta chuyến bản cũ (đã lọc tuyến nếu có); $tb chuyến bản mới
     * @return list<array{service_id:string, kind:string, details:list<string>, affected_trips:int}>
     */
    private function services(int $a, int $b, array $ta, array $tb, bool $filtered): array
    {
        $da = $this->serviceDefs($a);
        $db = $this->serviceDefs($b);
        $ids = array_unique(array_merge(array_keys($da), array_keys($db)));
        sort($ids, SORT_STRING);
        $count = static function (array $trips, string $sid): int {
            $n = 0;
            foreach ($trips as $t) { $n += $t['service_id'] === $sid ? 1 : 0; }
            return $n;
        };
        $out = [];
        foreach ($ids as $sid) {
            $sid = (string)$sid;
            $o = $da[$sid] ?? null;
            $n = $db[$sid] ?? null;
            $details = [];
            if ($o === null) {
                $kind = 'added';
                $details[] = 'Dịch vụ mới';
            } elseif ($n === null) {
                $kind = 'removed';
                $details[] = 'Dịch vụ bị xóa';
            } else {
                $kind = 'changed';
                if (($o['cal'] ?? null) !== ($n['cal'] ?? null)) {
                    $oc = $o['cal'] ?? null;
                    $nc = $n['cal'] ?? null;
                    if ($oc === null || $nc === null) {
                        $details[] = $oc === null ? 'Thêm lịch theo tuần (calendar)' : 'Bỏ lịch theo tuần (calendar)';
                    } else {
                        if ($oc['days'] !== $nc['days']) { $details[] = 'Thứ chạy: ' . self::dayLabel($oc['days']) . ' → ' . self::dayLabel($nc['days']); }
                        if ($oc['start'] !== $nc['start'] || $oc['end'] !== $nc['end']) {
                            $details[] = 'Khoảng ngày: ' . Time::pretty($oc['start']) . '–' . Time::pretty($oc['end']) . ' → ' . Time::pretty($nc['start']) . '–' . Time::pretty($nc['end']);
                        }
                    }
                }
                $dates = array_unique(array_merge(array_keys($o['dates'] ?? []), array_keys($n['dates'] ?? [])));
                sort($dates, SORT_STRING);
                $label = static fn($t) => $t === null ? 'không có ngoại lệ' : ((int)$t === 1 ? 'thêm dịch vụ' : 'hủy dịch vụ');
                foreach ($dates as $d) {
                    $d = (string)$d;
                    $ot = $o['dates'][$d] ?? null;
                    $nt = $n['dates'][$d] ?? null;
                    if ($ot !== $nt) { $details[] = 'Ngày ' . Time::pretty($d) . ': ' . $label($ot) . ' → ' . $label($nt); }
                }
            }
            if (!$details) {
                continue;
            }
            $affected = $kind === 'removed' ? $count($ta, $sid) : $count($tb, $sid);
            if ($filtered && $affected === 0) {
                continue;
            }
            $out[] = ['service_id' => $sid, 'kind' => $kind, 'details' => $details, 'affected_trips' => $affected];
        }
        return $out;
    }

    private static function dayLabel(string $bits): string
    {
        $names = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
        $on = [];
        foreach (str_split($bits) as $i => $c) { if ($c === '1') { $on[] = $names[$i]; } }
        return $on ? implode(',', $on) : '(không ngày nào)';
    }

    /** @return array<string, array{cal?: array{days:string,start:string,end:string}, dates?: array<string,int>}> */
    private function serviceDefs(int $vid): array
    {
        $o = [];
        $s = $this->pdo->prepare('SELECT * FROM calendar WHERE version_id = ?');
        $s->execute([$vid]);
        foreach ($s->fetchAll() as $r) {
            $bits = '';
            foreach (Spec::DAYS as $d) { $bits .= (int)$r[$d] === 1 ? '1' : '0'; }
            $o[(string)$r['service_id']]['cal'] = ['days' => $bits, 'start' => (string)$r['start_date'], 'end' => (string)$r['end_date']];
        }
        $s = $this->pdo->prepare('SELECT service_id, date, exception_type FROM calendar_dates WHERE version_id = ?');
        $s->execute([$vid]);
        foreach ($s->fetchAll() as $r) {
            $o[(string)$r['service_id']]['dates'][(string)$r['date']] = (int)$r['exception_type'];
        }
        return $o;
    }

    private function routes(int $vid): array
    {
        $s = $this->pdo->prepare('SELECT route_id, route_short_name, route_long_name FROM routes WHERE version_id = ?');
        $s->execute([$vid]);
        $o = [];
        foreach ($s->fetchAll() as $r) {
            $o[$r['route_id']] = ['route_id' => $r['route_id'], 'name' => trim($r['route_short_name'] . ' ' . $r['route_long_name'])];
        }
        return $o;
    }

    private function trips(int $vid, ?string $route): array
    {
        $s = $this->pdo->prepare('SELECT trip_id, route_id, service_id, trip_headsign FROM trips WHERE version_id = ?' . ($route ? ' AND route_id = ?' : ''));
        $s->execute($route ? [$vid, $route] : [$vid]);
        $o = [];
        foreach ($s->fetchAll() as $r) {
            $o[$r['trip_id']] = $r;
        }
        return $o;
    }

    private function stopTimes(int $vid): array
    {
        $s = $this->pdo->prepare('SELECT trip_id, stop_sequence, stop_id, arrival_text, departure_text FROM stop_times WHERE version_id = ? ORDER BY trip_id, stop_sequence');
        $s->execute([$vid]);
        $o = [];
        foreach ($s->fetchAll() as $r) {
            $o[$r['trip_id']][(int)$r['stop_sequence']] = ['stop_id' => $r['stop_id'], 'arr' => $r['arrival_text'], 'dep' => $r['departure_text']];
        }
        return $o;
    }
}
