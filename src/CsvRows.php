<?php
declare(strict_types=1);
namespace Gtfs;

/**
 * Các dòng dữ liệu của một tệp CSV, đọc lại từ luồng mỗi lần duyệt.
 * Bộ nhớ dùng chỉ ở mức một dòng, bất kể tệp lớn đến đâu.
 * Lưu ý: một CsvRows chỉ nên được duyệt bởi một vòng foreach tại một thời điểm.
 */
final class CsvRows implements \IteratorAggregate, \Countable
{
    /** @param resource $fh luồng đang giữ nội dung CSV (đã bỏ BOM) */
    public function __construct(private $fh, private array $header) {}

    /** Số dòng dữ liệu hợp lệ (không tính tiêu đề, dòng trống, dòng sai số cột). */
    public function count(): int
    {
        $n = 0;
        foreach ($this as $_) {
            $n++;
        }
        return $n;
    }

    /** @return \Generator<int, array{line:int, data:array<string,string>}> */
    public function getIterator(): \Generator
    {
        $header = $this->header;
        $width = count($header);
        rewind($this->fh);
        $n = 0;
        $seenHeader = false;
        while (($rec = fgetcsv($this->fh, 0, ',', '"', '')) !== false) {
            $n++;
            if ($rec === [null]) {
                continue; // dòng trống
            }
            if (!$seenHeader) {
                $seenHeader = true; // bỏ dòng tiêu đề
                continue;
            }
            if (count($rec) !== $width) {
                continue; // dòng sai số cột: đã được báo lỗi ở Csv::parse
            }
            $rec = array_map(static fn($v) => trim((string)$v), $rec);
            yield ['line' => $n, 'data' => array_combine($header, $rec)];
        }
    }
}
