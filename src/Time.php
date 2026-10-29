<?php
declare(strict_types=1);
namespace Gtfs;

/** Giờ GTFS: HH:MM:SS, giờ có thể >= 24 (tính từ nửa đêm của ngày hoạt động). */
final class Time
{
    public static function parse(string $t): ?int
    {
        if (!preg_match('/^(\d{1,2}):([0-5]\d):([0-5]\d)$/', trim($t), $m)) {
            return null;
        }
        $h = (int)$m[1];
        return $h > 71 ? null : $h * 3600 + (int)$m[2] * 60 + (int)$m[3];
    }

    /** Giờ nhập từ người dùng: HH:MM hoặc HH:MM:SS, 0–47 giờ. */
    public static function parseQuery(string $t): ?int
    {
        $t = trim($t);
        if (preg_match('/^\d{1,2}:[0-5]\d$/', $t)) {
            $t .= ':00';
        }
        $s = self::parse($t);
        return ($s !== null && $s < 48 * 3600) ? $s : null;
    }

    public static function format(int $secs): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60), $secs % 60);
    }

    /** "YYYYMMDD" hợp lệ -> DateTimeImmutable (UTC, 00:00) hoặc null. */
    public static function date(string $ymd): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ymd, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return new \DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]} 00:00:00", new \DateTimeZone('UTC'));
    }

    /** Nhận "YYYY-MM-DD" (form) hoặc "YYYYMMDD", trả "YYYYMMDD" hoặc null. */
    public static function normalizeDate(string $s): ?string
    {
        $s = str_replace('-', '', trim($s));
        return self::date($s) ? $s : null;
    }

    public static function pretty(string $ymd): string
    {
        return substr($ymd, 6, 2) . '/' . substr($ymd, 4, 2) . '/' . substr($ymd, 0, 4);
    }
}
