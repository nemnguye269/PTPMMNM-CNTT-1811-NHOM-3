<?php
declare(strict_types=1);
namespace Gtfs;

/**
 * Thời gian chờ xe tại trạm: số phút từ BÂY GIỜ (giờ Việt Nam) đến các chuyến sắp rời trạm.
 * Dựa trên Departures::next nên xử lý đúng ngày hoạt động, ngoại lệ lịch và chuyến sau nửa đêm.
 */
final class Waits
{
    public function __construct(private Departures $dep)
    {
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Asia/Ho_Chi_Minh'));
    }

    public static function text(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'Sắp đến';
        }
        if ($minutes < 60) {
            return $minutes . ' phút';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h . ' giờ' . ($m > 0 ? ' ' . $m . ' phút' : '');
    }

    /**
     * Các chuyến sắp tới tại một trạm.
     * @param ?int $direction chỉ lấy chiều này (null = mọi chiều)
     * @return array{now:string,stop_name:string,arrivals:list<array>,message:?string}
     */
    public function forStop(
        int $vid,
        string $stopId,
        ?string $routeId = null,
        ?int $direction = null,
        int $limit = 5,
        ?\DateTimeImmutable $now = null,
        int $horizonDays = 3
    ): array {
        $now ??= self::now();
        $ymd = $now->format('Ymd');
        $fetch = $direction === null ? $limit : $limit * 6; // lấy dư để còn lọc theo chiều
        $r = $this->dep->next($vid, $stopId, $ymd, $now->format('H:i'), $fetch, $routeId, $horizonDays);

        $base = (Time::date($ymd)?->getTimestamp() ?? 0) + (int)$now->format('G') * 3600 + (int)$now->format('i') * 60;
        $items = [];
        foreach ($r['trips'] as $t) {
            if ($direction !== null && $t['direction_id'] !== $direction) {
                continue;
            }
            $min = max(0, intdiv((int)$t['abs'] - $base, 60));
            $items[] = [
                'trip_id' => (string)$t['trip_id'],
                'route_id' => (string)$t['route_id'],
                'route_name' => (string)$t['route_name'],
                'headsign' => (string)($t['headsign'] ?? ''),
                'direction_id' => $t['direction_id'],
                'clock' => (string)$t['clock'],
                'date' => $t['real_date'] === $ymd ? null : Time::pretty((string)$t['real_date']),
                'minutes' => $min,
                'text' => self::text($min),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return [
            'now' => $now->format('H:i'),
            'stop_name' => (string)($r['query']['stop_name'] ?? ''),
            'arrivals' => $items,
            'message' => $items ? null : ($r['message'] ?? 'Chưa có chuyến nào sắp tới.'),
        ];
    }

    /**
     * Chuyến gần nhất tại nhiều trạm (cho danh sách trạm của một tuyến).
     * @param list<string> $stopIds
     * @return array{now:string,waits:array<string,?array>}
     */
    public function forStops(int $vid, array $stopIds, ?string $routeId, ?int $direction, ?\DateTimeImmutable $now = null): array
    {
        $now ??= self::now();
        $out = [];
        foreach (array_slice(array_values(array_unique($stopIds)), 0, 80) as $id) {
            $r = $this->forStop($vid, (string)$id, $routeId, $direction, 1, $now, 2);
            $a = $r['arrivals'][0] ?? null;
            $out[(string)$id] = $a ? ['minutes' => $a['minutes'], 'text' => $a['text'], 'clock' => $a['clock'], 'date' => $a['date']] : null;
        }
        return ['now' => $now->format('H:i'), 'waits' => $out];
    }
}
