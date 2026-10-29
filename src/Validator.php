<?php
declare(strict_types=1);
namespace Gtfs;

/**
 * Kiểm tra bộ GTFS đã đọc vào bộ nhớ: tệp, cột, giá trị, mã tham chiếu giữa các tệp.
 * Chỉ đọc – không chạm vào CSDL, nên một bản lỗi không thể ảnh hưởng lịch đang dùng.
 */
final class Validator
{
    private const MAX_ISSUES = 2000;
    /** @var array<int,array{severity:string,code:string,file:string,line:?int,message:string}> */
    private array $issues = [];
    private int $errors = 0;
    private int $warnings = 0;
    private array $usable = []; // tệp có đủ cột bắt buộc

    private function __construct(private array $t) {}

    /** @return array{issues:array, errors:int, warnings:int} */
    public static function validate(array $tables): array
    {
        $v = new self($tables);
        $v->run();
        return ['issues' => $v->issues, 'errors' => $v->errors, 'warnings' => $v->warnings];
    }

    private function add(string $sev, string $code, string $file, ?int $line, string $msg): void
    {
        $sev === 'error' ? $this->errors++ : $this->warnings++;
        if (count($this->issues) < self::MAX_ISSUES) {
            $this->issues[] = ['severity' => $sev, 'code' => $code, 'file' => $file, 'line' => $line, 'message' => $msg];
        }
    }
    private function err(string $c, string $f, ?int $l, string $m): void { $this->add('error', $c, $f, $l, $m); }
    private function warn(string $c, string $f, ?int $l, string $m): void { $this->add('warning', $c, $f, $l, $m); }

    private function run(): void
    {
        foreach (array_keys($this->t) as $name) {
            if (!isset(Spec::FILES[$name])) {
                $this->warn('UNSUPPORTED_FILE', $name, null, 'Tệp ngoài phạm vi hỗ trợ, được bỏ qua.');
            }
        }
        foreach (Spec::REQUIRED_FILES as $f) {
            if (!isset($this->t[$f])) {
                $this->err('MISSING_FILE', $f, null, "Thiếu tệp bắt buộc $f.");
            }
        }
        if (!isset($this->t['calendar.txt']) && !isset($this->t['calendar_dates.txt'])) {
            $this->err('NO_SERVICE_FILE', 'calendar.txt', null, 'Cần ít nhất một trong calendar.txt hoặc calendar_dates.txt.');
        }

        foreach (Spec::FILES as $name => [$required, $_]) {
            if (!isset($this->t[$name])) {
                continue;
            }
            $this->checkStructure($name, $required);
        }

        $stops = $this->checkStops();
        $routes = $this->checkRoutes();
        $services = $this->checkCalendars();
        $trips = $this->checkTrips($routes, $services);
        $this->checkStopTimes($trips, $stops);
        $this->checkAgency();
    }

    private function checkStructure(string $name, array $required): void
    {
        $f = $this->t[$name];
        $dups = array_diff_assoc($f['header'], array_unique($f['header']));
        foreach ($dups as $d) {
            $this->err('DUPLICATE_COLUMN', $name, 1, "Cột '$d' xuất hiện nhiều lần.");
        }
        $missing = array_diff($required, $f['header']);
        foreach ($missing as $c) {
            $this->err('MISSING_COLUMN', $name, 1, "Thiếu cột bắt buộc '$c'.");
        }
        foreach ($f['bad'] as $line) {
            $this->err('BAD_ROW_WIDTH', $name, $line, 'Số cột của dòng không khớp dòng tiêu đề.');
        }
        if (!$missing && !$dups) {
            $this->usable[$name] = true;
            if (!$f['rows'] && in_array($name, Spec::REQUIRED_FILES, true)) {
                $this->err('EMPTY_FILE', $name, null, 'Tệp không có dòng dữ liệu nào.');
            }
        }
    }

    private function rows(string $name): array
    {
        return isset($this->usable[$name]) ? $this->t[$name]['rows'] : [];
    }

    private function need(string $name, int $line, array $d, string $col): bool
    {
        if (($d[$col] ?? '') === '') {
            $this->err('EMPTY_VALUE', $name, $line, "Giá trị bắt buộc '$col' bị trống.");
            return false;
        }
        return true;
    }

    /** @return array<string,true> */
    private function checkStops(): array
    {
        $ids = [];
        foreach ($this->rows('stops.txt') as ['line' => $l, 'data' => $d]) {
            if ($this->need('stops.txt', $l, $d, 'stop_id')) {
                isset($ids[$d['stop_id']])
                    ? $this->err('DUPLICATE_ID', 'stops.txt', $l, "stop_id '{$d['stop_id']}' bị trùng.")
                    : $ids[$d['stop_id']] = true;
            }
            $this->need('stops.txt', $l, $d, 'stop_name');
            foreach (['stop_lat' => 90, 'stop_lon' => 180] as $c => $max) {
                $v = $d[$c] ?? '';
                if ($v !== '' && (!is_numeric($v) || abs((float)$v) > $max)) {
                    $this->err('INVALID_VALUE', 'stops.txt', $l, "$c '$v' không hợp lệ.");
                }
            }
        }
        return $ids;
    }

    private function checkRoutes(): array
    {
        $ids = [];
        foreach ($this->rows('routes.txt') as ['line' => $l, 'data' => $d]) {
            if ($this->need('routes.txt', $l, $d, 'route_id')) {
                isset($ids[$d['route_id']])
                    ? $this->err('DUPLICATE_ID', 'routes.txt', $l, "route_id '{$d['route_id']}' bị trùng.")
                    : $ids[$d['route_id']] = true;
            }
            if (!ctype_digit($d['route_type'] ?? '')) {
                $this->err('INVALID_VALUE', 'routes.txt', $l, "route_type '" . ($d['route_type'] ?? '') . "' phải là số nguyên.");
            }
            if (($d['route_short_name'] ?? '') === '' && ($d['route_long_name'] ?? '') === '') {
                $this->err('EMPTY_VALUE', 'routes.txt', $l, 'Cần route_short_name hoặc route_long_name.');
            }
        }
        return $ids;
    }

    /** @return array<string,true> các service_id khai báo ở calendar hoặc calendar_dates */
    private function checkCalendars(): array
    {
        $ids = [];
        foreach ($this->rows('calendar.txt') as ['line' => $l, 'data' => $d]) {
            if ($this->need('calendar.txt', $l, $d, 'service_id')) {
                isset($ids[$d['service_id']]) && $this->err('DUPLICATE_ID', 'calendar.txt', $l, "service_id '{$d['service_id']}' bị trùng.");
                $ids[$d['service_id']] = true;
            }
            foreach (Spec::DAYS as $day) {
                if (!in_array($d[$day] ?? '', ['0', '1'], true)) {
                    $this->err('INVALID_VALUE', 'calendar.txt', $l, "Cột $day phải là 0 hoặc 1.");
                }
            }
            $s = Time::date($d['start_date'] ?? '');
            $e = Time::date($d['end_date'] ?? '');
            if (!$s) { $this->err('BAD_DATE', 'calendar.txt', $l, "start_date '" . ($d['start_date'] ?? '') . "' không phải YYYYMMDD hợp lệ."); }
            if (!$e) { $this->err('BAD_DATE', 'calendar.txt', $l, "end_date '" . ($d['end_date'] ?? '') . "' không phải YYYYMMDD hợp lệ."); }
            if ($s && $e && $s > $e) {
                $this->err('INVALID_VALUE', 'calendar.txt', $l, 'start_date lớn hơn end_date.');
            }
        }
        $seen = [];
        foreach ($this->rows('calendar_dates.txt') as ['line' => $l, 'data' => $d]) {
            if ($this->need('calendar_dates.txt', $l, $d, 'service_id')) {
                $ids[$d['service_id']] = true;
            }
            if (!Time::date($d['date'] ?? '')) {
                $this->err('BAD_DATE', 'calendar_dates.txt', $l, "date '" . ($d['date'] ?? '') . "' không phải YYYYMMDD hợp lệ.");
            }
            if (!in_array($d['exception_type'] ?? '', ['1', '2'], true)) {
                $this->err('INVALID_VALUE', 'calendar_dates.txt', $l, 'exception_type phải là 1 (thêm) hoặc 2 (hủy).');
            }
            $k = ($d['service_id'] ?? '') . '|' . ($d['date'] ?? '');
            isset($seen[$k]) && $this->err('DUPLICATE_ID', 'calendar_dates.txt', $l, "Cặp (service_id, date) '$k' bị trùng.");
            $seen[$k] = true;
        }
        return $ids;
    }

    private function checkTrips(array $routes, array $services): array
    {
        $ids = [];
        $haveRoutes = isset($this->usable['routes.txt']);
        $haveSvc = isset($this->usable['calendar.txt']) || isset($this->usable['calendar_dates.txt']);
        foreach ($this->rows('trips.txt') as ['line' => $l, 'data' => $d]) {
            if ($this->need('trips.txt', $l, $d, 'trip_id')) {
                isset($ids[$d['trip_id']])
                    ? $this->err('DUPLICATE_ID', 'trips.txt', $l, "trip_id '{$d['trip_id']}' bị trùng.")
                    : $ids[$d['trip_id']] = true;
            }
            if ($this->need('trips.txt', $l, $d, 'route_id') && $haveRoutes && !isset($routes[$d['route_id']])) {
                $this->err('BAD_REF_ROUTE', 'trips.txt', $l, "route_id '{$d['route_id']}' không có trong routes.txt.");
            }
            if ($this->need('trips.txt', $l, $d, 'service_id') && $haveSvc && !isset($services[$d['service_id']])) {
                $this->err('BAD_REF_SERVICE', 'trips.txt', $l, "service_id '{$d['service_id']}' không có trong calendar.txt/calendar_dates.txt.");
            }
            if (($d['direction_id'] ?? '') !== '' && !in_array($d['direction_id'], ['0', '1'], true)) {
                $this->err('INVALID_VALUE', 'trips.txt', $l, 'direction_id phải là 0 hoặc 1.');
            }
        }
        return $ids;
    }

    private function checkStopTimes(array $trips, array $stops): void
    {
        $haveTrips = isset($this->usable['trips.txt']);
        $haveStops = isset($this->usable['stops.txt']);
        $perTrip = [];
        foreach ($this->rows('stop_times.txt') as ['line' => $l, 'data' => $d]) {
            $f = 'stop_times.txt';
            $okTrip = $this->need($f, $l, $d, 'trip_id');
            if ($okTrip && $haveTrips && !isset($trips[$d['trip_id']])) {
                $this->err('BAD_REF_TRIP', $f, $l, "trip_id '{$d['trip_id']}' không tồn tại trong trips.txt.");
            }
            if ($this->need($f, $l, $d, 'stop_id') && $haveStops && !isset($stops[$d['stop_id']])) {
                $this->err('BAD_REF_STOP', $f, $l, "stop_id '{$d['stop_id']}' không tồn tại trong stops.txt.");
            }
            $seq = $d['stop_sequence'];
            if (!ctype_digit($seq)) {
                $this->err('INVALID_VALUE', $f, $l, "stop_sequence '$seq' phải là số nguyên không âm.");
                continue;
            }
            $arr = $d['arrival_time'] === '' ? null : Time::parse($d['arrival_time']);
            $dep = $d['departure_time'] === '' ? null : Time::parse($d['departure_time']);
            if (($d['arrival_time'] !== '' && $arr === null) || ($d['departure_time'] !== '' && $dep === null)) {
                $this->err('BAD_TIME', $f, $l, "Giờ '{$d['arrival_time']}' / '{$d['departure_time']}' không đúng dạng HH:MM:SS.");
                continue;
            }
            if ($arr === null && $dep === null) {
                $this->err('BAD_TIME', $f, $l, 'Thiếu cả arrival_time và departure_time.');
                continue;
            }
            $arr ??= $dep;
            $dep ??= $arr;
            if ($okTrip) {
                $perTrip[$d['trip_id']][] = [(int)$seq, $arr, $dep, $l];
            }
        }
        foreach ($perTrip as $tid => $list) {
            usort($list, static fn($a, $b) => $a[0] <=> $b[0]);
            if (count($list) < 2) {
                $this->warn('SHORT_TRIP', 'stop_times.txt', $list[0][3], "Chuyến '$tid' chỉ có 1 lần dừng.");
            }
            for ($i = 0; $i < count($list); $i++) {
                [$seq, $arr, $dep, $line] = $list[$i];
                if ($i > 0 && $seq === $list[$i - 1][0]) {
                    $this->err('SEQ_DUPLICATE', 'stop_times.txt', $line, "Chuyến '$tid' trùng stop_sequence $seq.");
                }
                if ($dep < $arr) {
                    $this->err('TIME_ORDER', 'stop_times.txt', $line, "Chuyến '$tid': giờ rời nhỏ hơn giờ đến tại stop_sequence $seq.");
                }
                if ($i > 0 && $arr < $list[$i - 1][2]) {
                    $this->err('TIME_ORDER', 'stop_times.txt', $line, "Chuyến '$tid': giờ đến tại stop_sequence $seq sớm hơn giờ rời trạm trước.");
                }
            }
        }
        if ($haveTrips && isset($this->usable['stop_times.txt'])) {
            foreach ($trips as $tid => $_) {
                if (!isset($perTrip[$tid])) {
                    $this->warn('TRIP_NO_STOPS', 'trips.txt', null, "Chuyến '$tid' không có lần dừng nào.");
                }
            }
        }
    }

    private function checkAgency(): void
    {
        foreach ($this->rows('agency.txt') as ['line' => $l, 'data' => $d]) {
            foreach (['agency_name', 'agency_url', 'agency_timezone'] as $c) {
                $this->need('agency.txt', $l, $d, $c);
            }
        }
    }
}
