<?php
declare(strict_types=1);
namespace Gtfs;

/** Phạm vi GTFS tĩnh được hỗ trợ (ghi rõ trong docs/PHAM_VI.md). */
final class Spec
{
    /** tệp => [cột bắt buộc, cột tùy chọn được đọc] */
    public const FILES = [
        'agency.txt'         => [['agency_name', 'agency_url', 'agency_timezone'], ['agency_id']],
        'stops.txt'          => [['stop_id', 'stop_name'], ['stop_lat', 'stop_lon']],
        'routes.txt'         => [['route_id', 'route_type'], ['route_short_name', 'route_long_name', 'agency_id']],
        'trips.txt'          => [['route_id', 'service_id', 'trip_id'], ['trip_headsign', 'direction_id']],
        'stop_times.txt'     => [['trip_id', 'arrival_time', 'departure_time', 'stop_id', 'stop_sequence'], []],
        'calendar.txt'       => [['service_id', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'start_date', 'end_date'], []],
        'calendar_dates.txt' => [['service_id', 'date', 'exception_type'], []],
    ];
    public const REQUIRED_FILES = ['stops.txt', 'routes.txt', 'trips.txt', 'stop_times.txt'];
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    public const MAX_FILE_BYTES = 30 * 1024 * 1024;
}
