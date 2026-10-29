<?php
declare(strict_types=1);
namespace Gtfs;

final class Csv
{
    /**
     * @return array{header: string[], rows: array<int, array{line:int, data:array<string,string>}>, bad: int[]}
     * line = số thứ tự bản ghi trong tệp (dòng tiêu đề là 1).
     */
    public static function parse(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $header = null;
        $rows = [];
        $bad = [];
        $n = 0;
        while (($rec = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $n++;
            if ($rec === [null]) {
                continue; // dòng trống
            }
            $rec = array_map(static fn($v) => trim((string)$v), $rec);
            if ($header === null) {
                $header = $rec;
                continue;
            }
            if (count($rec) !== count($header)) {
                $bad[] = $n;
                continue;
            }
            $rows[] = ['line' => $n, 'data' => array_combine($header, $rec)];
        }
        fclose($fh);
        return ['header' => $header ?? [], 'rows' => $rows, 'bad' => $bad];
    }
}
