<?php
declare(strict_types=1);
namespace Gtfs;

final class Csv
{
    /**
     * Đọc CSV theo luồng để không nạp hàng trăm nghìn dòng vào bộ nhớ.
     *
     * Lượt đọc đầu tiên chỉ ghi nhận tiêu đề, số dòng hợp lệ và các dòng sai số cột.
     * Nội dung dòng dữ liệu KHÔNG được giữ trong mảng: 'rows' là một CsvRows,
     * mỗi lần duyệt (foreach) nó đọc lại từng dòng từ luồng tạm.
     *
     * @return array{header: string[], rows: CsvRows, bad: int[], count: int}
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
        $bad = [];
        $count = 0;
        $n = 0;
        while (($rec = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $n++;
            if ($rec === [null]) {
                continue; // dòng trống
            }
            if ($header === null) {
                $header = array_map(static fn($v) => trim((string)$v), $rec);
                continue;
            }
            if (count($rec) !== count($header)) {
                $bad[] = $n;
                continue;
            }
            $count++;
        }
        $header ??= [];
        return ['header' => $header, 'rows' => new CsvRows($fh, $header), 'bad' => $bad, 'count' => $count];
    }
}
