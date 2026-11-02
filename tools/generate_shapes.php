<?php
declare(strict_types=1);

/**
 * ============================================================
 * GTFS AUTO SHAPES GENERATOR
 * ============================================================
 *
 * Tạo shapes.txt tự động từ:
 *
 * stops.txt
 * stop_times.txt
 * trips.txt
 *
 * Đường đi được suy ra bằng OSRM.
 *
 * Sau đó:
 * - lọc điểm quá gần nhau
 * - simplify shape để giảm zig-zag
 * - ghi shapes.txt
 * - thêm shape_id vào trips.txt
 *
 * Cách chạy:
 *
 * C:\xampp\php\php.exe tools\generate_shapes.php ^
 * data\sample\gtfs_v1.zip ^
 * data\sample\gtfs_v1_shapes.zip
 *
 * LƯU Ý:
 * AUTO_* là shape suy ra từ OSRM,
 * KHÔNG phải shape chính thức của nhà vận tải.
 */


/* ============================================================
   ARGUMENTS
============================================================ */

if ($argc < 3) {
    echo "Cách dùng:\n";
    echo "php tools\\generate_shapes.php input.zip output.zip\n";
    exit(1);
}

$inputZipPath = $argv[1];
$outputZipPath = $argv[2];


if (!is_file($inputZipPath)) {
    echo "Không tìm thấy file:\n";
    echo $inputZipPath . "\n";
    exit(1);
}


if (!class_exists('ZipArchive')) {
    echo "PHP chưa bật extension zip.\n";
    exit(1);
}


if (!function_exists('curl_init')) {
    echo "PHP chưa bật extension curl.\n";
    exit(1);
}


/* ============================================================
   CONFIG
============================================================ */

const OSRM_BASE =
    'https://router.project-osrm.org/route/v1/driving';


const USER_AGENT =
    'GTFS-Student-Project/1.0';


/*
 * Nghỉ nhẹ giữa các request
 * để tránh spam server OSRM public.
 */
const REQUEST_DELAY_US =
    200000;


/*
 * Timeout.
 */
const CONNECT_TIMEOUT =
    15;

const REQUEST_TIMEOUT =
    45;


/*
 * Khoảng cách tối thiểu giữa 2 điểm shape.
 *
 * Tăng lên -> đường ít điểm hơn, mượt hơn.
 *
 * 15-20m: khá chi tiết
 * 20-30m: đẹp cho project
 */
const MIN_POINT_DISTANCE_METERS =
    22.0;


/*
 * Douglas-Peucker epsilon.
 *
 * 0.00008 = nhẹ
 * 0.00012 = vừa
 * 0.00016 = mượt hơn
 * 0.00020 = khá mạnh
 *
 * Mình để 0.00015 để cân bằng.
 */
const SIMPLIFY_EPSILON =
    0.00015;


/* ============================================================
   CSV
============================================================ */

function parseCsv(string $content): array
{
    $stream = fopen(
        'php://temp',
        'r+'
    );

    if ($stream === false) {
        throw new RuntimeException(
            'Không tạo được stream CSV.'
        );
    }


    fwrite(
        $stream,
        $content
    );

    rewind(
        $stream
    );


    $headers =
        fgetcsv(
            $stream
        );


    if ($headers === false) {
        fclose($stream);

        return [
            'headers' => [],
            'rows' => [],
        ];
    }


    /*
     * Xóa BOM UTF-8.
     */
    if (isset($headers[0])) {
        $headers[0] =
            preg_replace(
                '/^\xEF\xBB\xBF/',
                '',
                (string)$headers[0]
            );
    }


    $rows = [];


    while (
        ($values = fgetcsv($stream))
        !== false
    ) {

        if (
            count($values) === 1
            &&
            trim((string)$values[0]) === ''
        ) {
            continue;
        }


        $values =
            array_pad(
                $values,
                count($headers),
                ''
            );


        $values =
            array_slice(
                $values,
                0,
                count($headers)
            );


        $row = [];


        foreach (
            $headers as
            $index => $header
        ) {
            $row[$header] =
                $values[$index]
                ?? '';
        }


        $rows[] =
            $row;
    }


    fclose(
        $stream
    );


    return [
        'headers' => $headers,
        'rows' => $rows,
    ];
}


function buildCsv(
    array $headers,
    array $rows
): string {

    $stream =
        fopen(
            'php://temp',
            'r+'
        );


    if ($stream === false) {
        throw new RuntimeException(
            'Không tạo được stream CSV.'
        );
    }


    fputcsv(
        $stream,
        $headers
    );


    foreach ($rows as $row) {

        $values = [];


        foreach ($headers as $header) {
            $values[] =
                $row[$header]
                ?? '';
        }


        fputcsv(
            $stream,
            $values
        );
    }


    rewind(
        $stream
    );


    $content =
        stream_get_contents(
            $stream
        );


    fclose(
        $stream
    );


    return $content === false
        ? ''
        : $content;
}


/* ============================================================
   ZIP
============================================================ */

function readZipFile(
    ZipArchive $zip,
    string $name
): string {

    $content =
        $zip->getFromName(
            $name
        );


    if ($content === false) {
        throw new RuntimeException(
            "Thiếu file {$name} trong GTFS."
        );
    }


    return $content;
}


/* ============================================================
   HELPERS
============================================================ */

function cleanId(string $value): string
{
    $value =
        preg_replace(
            '/[^A-Za-z0-9_-]+/',
            '_',
            $value
        );


    return trim(
        (string)$value,
        '_'
    );
}


/* ============================================================
   DISTANCE
============================================================ */

/**
 * Tính khoảng cách giữa 2 tọa độ theo mét.
 */
function pointDistanceMeters(
    array $a,
    array $b
): float {

    $earthRadius =
        6371000.0;


    $lat1 =
        deg2rad(
            (float)$a['lat']
        );

    $lon1 =
        deg2rad(
            (float)$a['lon']
        );

    $lat2 =
        deg2rad(
            (float)$b['lat']
        );

    $lon2 =
        deg2rad(
            (float)$b['lon']
        );


    $dLat =
        $lat2 - $lat1;

    $dLon =
        $lon2 - $lon1;


    $h =
        sin($dLat / 2)
        *
        sin($dLat / 2)
        +
        cos($lat1)
        *
        cos($lat2)
        *
        sin($dLon / 2)
        *
        sin($dLon / 2);


    return
        2
        *
        $earthRadius
        *
        atan2(
            sqrt($h),
            sqrt(1 - $h)
        );
}


/* ============================================================
   REMOVE CLOSE POINTS
============================================================ */

/**
 * Bỏ các điểm quá gần nhau.
 *
 * Giúp giảm:
 * - rung đường
 * - nhiều điểm vô nghĩa
 * - zig-zag nhỏ
 */
function removeClosePoints(
    array $points,
    float $minDistanceMeters
): array {

    $count =
        count($points);


    if ($count < 3) {
        return $points;
    }


    $result = [];

    $result[] =
        $points[0];


    for (
        $i = 1;
        $i < $count - 1;
        $i++
    ) {

        $last =
            $result[
                count($result) - 1
            ];


        $current =
            $points[$i];


        $distance =
            pointDistanceMeters(
                $last,
                $current
            );


        if (
            $distance >=
            $minDistanceMeters
        ) {
            $result[] =
                $current;
        }
    }


    /*
     * Luôn giữ điểm cuối.
     */
    $result[] =
        $points[
            $count - 1
        ];


    return $result;
}


/* ============================================================
   DOUGLAS PEUCKER
============================================================ */

function perpendicularDistance(
    array $point,
    array $start,
    array $end
): float {

    $x =
        (float)$point['lon'];

    $y =
        (float)$point['lat'];


    $x1 =
        (float)$start['lon'];

    $y1 =
        (float)$start['lat'];


    $x2 =
        (float)$end['lon'];

    $y2 =
        (float)$end['lat'];


    if (
        $x1 === $x2
        &&
        $y1 === $y2
    ) {
        return
            sqrt(
                (($x - $x1) ** 2)
                +
                (($y - $y1) ** 2)
            );
    }


    $numerator =
        abs(
            (($y2 - $y1) * $x)
            -
            (($x2 - $x1) * $y)
            +
            ($x2 * $y1)
            -
            ($y2 * $x1)
        );


    $denominator =
        sqrt(
            (($y2 - $y1) ** 2)
            +
            (($x2 - $x1) ** 2)
        );


    if ($denominator == 0.0) {
        return 0.0;
    }


    return
        $numerator
        /
        $denominator;
}


/**
 * Douglas-Peucker simplify.
 */
function simplifyDouglasPeucker(
    array $points,
    float $epsilon
): array {

    $count =
        count($points);


    if ($count < 3) {
        return $points;
    }


    $maxDistance =
        0.0;

    $index =
        0;


    $start =
        $points[0];


    $end =
        $points[
            $count - 1
        ];


    for (
        $i = 1;
        $i < $count - 1;
        $i++
    ) {

        $distance =
            perpendicularDistance(
                $points[$i],
                $start,
                $end
            );


        if (
            $distance >
            $maxDistance
        ) {

            $maxDistance =
                $distance;


            $index =
                $i;
        }
    }


    if (
        $maxDistance >
        $epsilon
    ) {

        $left =
            simplifyDouglasPeucker(
                array_slice(
                    $points,
                    0,
                    $index + 1
                ),
                $epsilon
            );


        $right =
            simplifyDouglasPeucker(
                array_slice(
                    $points,
                    $index
                ),
                $epsilon
            );


        /*
         * Tránh lặp điểm nối.
         */
        array_pop(
            $left
        );


        return
            array_merge(
                $left,
                $right
            );
    }


    return [
        $start,
        $end,
    ];
}


/* ============================================================
   REMOVE EXACT DUPLICATES
============================================================ */

function removeDuplicatePoints(
    array $points
): array {

    $result = [];

    $lastKey = null;


    foreach ($points as $point) {

        $key =
            number_format(
                (float)$point['lat'],
                7,
                '.',
                ''
            )
            .
            ','
            .
            number_format(
                (float)$point['lon'],
                7,
                '.',
                ''
            );


        if ($key === $lastKey) {
            continue;
        }


        $result[] =
            $point;


        $lastKey =
            $key;
    }


    return $result;
}


/* ============================================================
   OSRM REQUEST
============================================================ */

function osrmRoutePair(
    array $from,
    array $to
): array {

    $coordinates =
        (float)$from['lon']
        .
        ','
        .
        (float)$from['lat']
        .
        ';'
        .
        (float)$to['lon']
        .
        ','
        .
        (float)$to['lat'];


    $url =
        OSRM_BASE
        .
        '/'
        .
        $coordinates
        .
        '?overview=full'
        .
        '&geometries=geojson'
        .
        '&steps=false'
        .
        '&alternatives=false'
        .
        '&continue_straight=false'
        .
        '&snapping=any';


    $ch =
        curl_init(
            $url
        );


    if ($ch === false) {
        throw new RuntimeException(
            'Không khởi tạo được cURL.'
        );
    }


    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_FOLLOWLOCATION =>
                true,

            CURLOPT_CONNECTTIMEOUT =>
                CONNECT_TIMEOUT,

            CURLOPT_TIMEOUT =>
                REQUEST_TIMEOUT,

            CURLOPT_USERAGENT =>
                USER_AGENT,

            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],

            CURLOPT_ENCODING =>
                '',
        ]
    );


    $body =
        curl_exec(
            $ch
        );


    $curlError =
        curl_error(
            $ch
        );


    $httpCode =
        (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    curl_close(
        $ch
    );


    if ($body === false) {
        throw new RuntimeException(
            'cURL lỗi: '
            .
            $curlError
        );
    }


    $json =
        json_decode(
            $body,
            true
        );


    if ($httpCode !== 200) {

        $message =
            is_array($json)
                ? (
                    $json['message']
                    ??
                    $json['code']
                    ??
                    'Không rõ lỗi'
                )
                : 'Không rõ lỗi';


        throw new RuntimeException(
            'OSRM HTTP '
            .
            $httpCode
            .
            ' - '
            .
            $message
        );
    }


    if (!is_array($json)) {
        throw new RuntimeException(
            'OSRM trả JSON không hợp lệ.'
        );
    }


    if (
        ($json['code'] ?? '')
        !==
        'Ok'
    ) {
        throw new RuntimeException(
            'OSRM: '
            .
            (
                $json['message']
                ??
                $json['code']
                ??
                'Không tìm được đường.'
            )
        );
    }


    $coordinates =
        $json['routes'][0]
        ['geometry']
        ['coordinates']
        ?? null;


    if (
        !is_array($coordinates)
        ||
        count($coordinates) < 2
    ) {
        throw new RuntimeException(
            'OSRM không trả geometry hợp lệ.'
        );
    }


    $result = [];


    foreach (
        $coordinates
        as $coordinate
    ) {

        if (
            !is_array($coordinate)
            ||
            count($coordinate) < 2
        ) {
            continue;
        }


        $result[] = [
            'lat' =>
                (float)$coordinate[1],

            'lon' =>
                (float)$coordinate[0],
        ];
    }


    if (
        count($result) < 2
    ) {
        throw new RuntimeException(
            'Geometry OSRM không hợp lệ.'
        );
    }


    return $result;
}


/* ============================================================
   CREATE RAW SHAPE
============================================================ */

function createRawShapeGeometry(
    array $points
): array {

    if (
        count($points) < 2
    ) {
        throw new RuntimeException(
            'Không đủ trạm để tạo shape.'
        );
    }


    $geometry = [];


    $segmentCount =
        count($points) - 1;


    for (
        $i = 0;
        $i < $segmentCount;
        $i++
    ) {

        $from =
            $points[$i];


        $to =
            $points[
                $i + 1
            ];


        $part =
            osrmRoutePair(
                $from,
                $to
            );


        /*
         * Điểm cuối đoạn trước
         * = điểm đầu đoạn sau.
         */
        if (
            $i > 0
            &&
            count($part) > 0
        ) {
            array_shift(
                $part
            );
        }


        foreach ($part as $point) {
            $geometry[] =
                $point;
        }


        usleep(
            REQUEST_DELAY_US
        );
    }


    if (
        count($geometry) < 2
    ) {
        throw new RuntimeException(
            'Không tạo được geometry.'
        );
    }


    return $geometry;
}


/* ============================================================
   SMOOTH SHAPE
============================================================ */

function smoothShape(
    array $geometry
): array {

    /*
     * Bước 1:
     * bỏ điểm trùng.
     */
    $geometry =
        removeDuplicatePoints(
            $geometry
        );


    /*
     * Bước 2:
     * bỏ điểm quá sát nhau.
     */
    $geometry =
        removeClosePoints(
            $geometry,
            MIN_POINT_DISTANCE_METERS
        );


    /*
     * Bước 3:
     * simplify.
     */
    $geometry =
        simplifyDouglasPeucker(
            $geometry,
            SIMPLIFY_EPSILON
        );


    /*
     * Bước 4:
     * clean lần cuối.
     */
    $geometry =
        removeDuplicatePoints(
            $geometry
        );


    return $geometry;
}


/* ============================================================
   OPEN INPUT ZIP
============================================================ */

$inputZip =
    new ZipArchive();


$result =
    $inputZip->open(
        $inputZipPath
    );


if ($result !== true) {
    echo "Không mở được:\n";
    echo $inputZipPath . "\n";
    exit(1);
}


/* ============================================================
   READ GTFS
============================================================ */

try {

    $stopsCsv =
        parseCsv(
            readZipFile(
                $inputZip,
                'stops.txt'
            )
        );


    $tripsCsv =
        parseCsv(
            readZipFile(
                $inputZip,
                'trips.txt'
            )
        );


    $stopTimesCsv =
        parseCsv(
            readZipFile(
                $inputZip,
                'stop_times.txt'
            )
        );

} catch (Throwable $e) {

    echo "Lỗi đọc GTFS:\n";
    echo $e->getMessage();
    echo "\n";


    $inputZip->close();


    exit(1);
}


/* ============================================================
   STOPS
============================================================ */

$stopsById = [];


foreach (
    $stopsCsv['rows']
    as $stop
) {

    $id =
        trim(
            (string)(
                $stop['stop_id']
                ?? ''
            )
        );


    if ($id === '') {
        continue;
    }


    $latRaw =
        trim(
            (string)(
                $stop['stop_lat']
                ?? ''
            )
        );


    $lonRaw =
        trim(
            (string)(
                $stop['stop_lon']
                ?? ''
            )
        );


    if (
        $latRaw === ''
        ||
        $lonRaw === ''
    ) {
        continue;
    }


    $lat =
        (float)$latRaw;


    $lon =
        (float)$lonRaw;


    if (
        !is_finite($lat)
        ||
        !is_finite($lon)
    ) {
        continue;
    }


    $stopsById[$id] = [
        'lat' => $lat,
        'lon' => $lon,
    ];
}


/* ============================================================
   STOP TIMES BY TRIP
============================================================ */

$stopTimesByTrip = [];


foreach (
    $stopTimesCsv['rows']
    as $row
) {

    $tripId =
        trim(
            (string)(
                $row['trip_id']
                ?? ''
            )
        );


    $stopId =
        trim(
            (string)(
                $row['stop_id']
                ?? ''
            )
        );


    if (
        $tripId === ''
        ||
        $stopId === ''
    ) {
        continue;
    }


    $stopTimesByTrip[
        $tripId
    ][] = [

        'stop_id' =>
            $stopId,

        'sequence' =>
            (int)(
                $row[
                    'stop_sequence'
                ]
                ?? 0
            ),
    ];
}


foreach (
    $stopTimesByTrip
    as &$tripStops
) {

    usort(
        $tripStops,
        static fn(
            array $a,
            array $b
        ): int =>
            $a['sequence']
            <=>
            $b['sequence']
    );
}


unset(
    $tripStops
);


/* ============================================================
   GROUP TRIPS
============================================================ */

$groups = [];


foreach (
    $tripsCsv['rows']
    as $index => $trip
) {

    $tripId =
        trim(
            (string)(
                $trip['trip_id']
                ?? ''
            )
        );


    $routeId =
        trim(
            (string)(
                $trip['route_id']
                ?? ''
            )
        );


    $direction =
        trim(
            (string)(
                $trip['direction_id']
                ?? ''
            )
        );


    if ($direction === '') {
        $direction =
            'NA';
    }


    if (
        $tripId === ''
        ||
        $routeId === ''
    ) {
        continue;
    }


    $groupKey =
        $routeId
        .
        '|'
        .
        $direction;


    if (
        !isset(
            $groups[
                $groupKey
            ]
        )
    ) {

        $groups[
            $groupKey
        ] = [

            'route_id' =>
                $routeId,

            'direction_id' =>
                $direction,

            'trip_indexes' =>
                [],

            'representative_trip' =>
                null,

            'max_stop_count' =>
                -1,
        ];
    }


    $groups[
        $groupKey
    ][
        'trip_indexes'
    ][] =
        $index;


    $stopCount =
        count(
            $stopTimesByTrip[
                $tripId
            ] ?? []
        );


    if (
        $stopCount >
        $groups[
            $groupKey
        ][
            'max_stop_count'
        ]
    ) {

        $groups[
            $groupKey
        ][
            'max_stop_count'
        ] =
            $stopCount;


        $groups[
            $groupKey
        ][
            'representative_trip'
        ] =
            $tripId;
    }
}


/* ============================================================
   ADD shape_id COLUMN
============================================================ */

$tripHeaders =
    $tripsCsv[
        'headers'
    ];


if (
    !in_array(
        'shape_id',
        $tripHeaders,
        true
    )
) {
    $tripHeaders[] =
        'shape_id';
}


foreach (
    $tripsCsv['rows']
    as &$tripRow
) {

    if (
        !array_key_exists(
            'shape_id',
            $tripRow
        )
    ) {
        $tripRow[
            'shape_id'
        ] = '';
    }
}


unset(
    $tripRow
);


/* ============================================================
   GENERATE SHAPES
============================================================ */

$shapeRows = [];


$successCount = 0;

$errorCount = 0;


$totalRawPoints = 0;

$totalSmoothPoints = 0;


foreach (
    $groups
    as $groupKey => $group
) {

    echo "Đang tạo shape cho ";
    echo $groupKey;
    echo " ... ";


    $tripId =
        $group[
            'representative_trip'
        ];


    if ($tripId === null) {

        echo "LỖI: không có trip đại diện.\n";

        $errorCount++;

        continue;
    }


    $tripStops =
        $stopTimesByTrip[
            $tripId
        ] ?? [];


    $points = [];


    foreach (
        $tripStops
        as $tripStop
    ) {

        $stopId =
            $tripStop[
                'stop_id'
            ];


        if (
            !isset(
                $stopsById[
                    $stopId
                ]
            )
        ) {
            continue;
        }


        $points[] =
            $stopsById[
                $stopId
            ];
    }


    if (
        count($points) < 2
    ) {

        echo "LỖI: không đủ tọa độ trạm.\n";

        $errorCount++;

        continue;
    }


    $shapeId =
        'AUTO_'
        .
        cleanId(
            (string)$group[
                'route_id'
            ]
        )
        .
        '_'
        .
        cleanId(
            (string)$group[
                'direction_id'
            ]
        );


    try {

        /*
         * Geometry gốc.
         */
        $rawGeometry =
            createRawShapeGeometry(
                $points
            );


        $rawCount =
            count(
                $rawGeometry
            );


        /*
         * Geometry đã làm mượt.
         */
        $geometry =
            smoothShape(
                $rawGeometry
            );


        $smoothCount =
            count(
                $geometry
            );


        if (
            $smoothCount < 2
        ) {
            throw new RuntimeException(
                'Shape sau khi simplify không hợp lệ.'
            );
        }


        $totalRawPoints +=
            $rawCount;


        $totalSmoothPoints +=
            $smoothCount;


        $sequence =
            1;


        foreach (
            $geometry
            as $point
        ) {

            $shapeRows[] = [

                'shape_id' =>
                    $shapeId,

                'shape_pt_lat' =>
                    number_format(
                        (float)$point['lat'],
                        7,
                        '.',
                        ''
                    ),

                'shape_pt_lon' =>
                    number_format(
                        (float)$point['lon'],
                        7,
                        '.',
                        ''
                    ),

                'shape_pt_sequence' =>
                    $sequence,
            ];


            $sequence++;
        }


        /*
         * Gán shape_id cho toàn bộ trip
         * cùng route + direction.
         */
        foreach (
            $group[
                'trip_indexes'
            ]
            as $tripIndex
        ) {

            $tripsCsv[
                'rows'
            ][
                $tripIndex
            ][
                'shape_id'
            ] =
                $shapeId;
        }


        echo "OK - ";

        echo $rawCount;

        echo " -> ";

        echo $smoothCount;

        echo " điểm\n";


        $successCount++;

    } catch (Throwable $e) {

        echo "LỖI: ";

        echo $e->getMessage();

        echo "\n";


        $errorCount++;
    }
}


/* ============================================================
   OUTPUT ZIP
============================================================ */

$outputZip =
    new ZipArchive();


$result =
    $outputZip->open(
        $outputZipPath,
        ZipArchive::CREATE
        |
        ZipArchive::OVERWRITE
    );


if ($result !== true) {

    echo "Không tạo được:\n";

    echo $outputZipPath;

    echo "\n";


    $inputZip->close();


    exit(1);
}


/*
 * Copy file cũ,
 * trừ trips.txt + shapes.txt.
 */
for (
    $i = 0;
    $i < $inputZip->numFiles;
    $i++
) {

    $name =
        $inputZip->getNameIndex(
            $i
        );


    if (
        $name === false
        ||
        $name === 'trips.txt'
        ||
        $name === 'shapes.txt'
    ) {
        continue;
    }


    $content =
        $inputZip->getFromIndex(
            $i
        );


    if ($content === false) {
        continue;
    }


    $outputZip->addFromString(
        $name,
        $content
    );
}


/*
 * trips.txt mới.
 */
$outputZip->addFromString(
    'trips.txt',
    buildCsv(
        $tripHeaders,
        $tripsCsv['rows']
    )
);


/*
 * shapes.txt mới.
 */
$outputZip->addFromString(
    'shapes.txt',
    buildCsv(
        [
            'shape_id',
            'shape_pt_lat',
            'shape_pt_lon',
            'shape_pt_sequence',
        ],
        $shapeRows
    )
);


$outputZip->close();

$inputZip->close();


/* ============================================================
   RESULT
============================================================ */

echo "\n";

echo "========================================\n";

echo "HOÀN TẤT\n";

echo "========================================\n";


echo "Shape tạo được: ";

echo $successCount;


echo "\n";


echo "Shape lỗi: ";

echo $errorCount;


echo "\n";


echo "Điểm OSRM ban đầu: ";

echo $totalRawPoints;


echo "\n";


echo "Điểm sau làm mượt: ";

echo $totalSmoothPoints;


echo "\n";


if ($totalRawPoints > 0) {

    $reduction =
        100
        -
        (
            (
                $totalSmoothPoints
                /
                $totalRawPoints
            )
            *
            100
        );


    echo "Giảm số điểm: ";

    echo number_format(
        $reduction,
        1
    );

    echo "%\n";
}


echo "File mới: ";

echo $outputZipPath;

echo "\n";


echo "========================================\n";


echo "\n";

echo "Lưu ý:\n";

echo "AUTO_* là shape suy ra từ OSRM.\n";

echo "Không phải shape chính thức của nhà vận tải.\n";

echo "Shape đã được lọc + simplify để giảm zig-zag.\n";