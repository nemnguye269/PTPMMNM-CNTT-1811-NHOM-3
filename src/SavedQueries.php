<?php
declare(strict_types=1);
namespace Gtfs;

use PDO;

/** Truy vấn mẫu: lưu (loại + tham số) và chạy lại trên bất kỳ phiên bản nào. */
final class SavedQueries
{
    public const KINDS = ['next' => 'Chuyến tiếp theo', 'routes' => 'Tìm tuyến', 'stops' => 'Tìm trạm', 'trips' => 'Tìm chuyến'];

    public function __construct(private PDO $pdo) {}

    public function save(string $name, string $kind, array $params): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Tên truy vấn phải từ 1 đến 100 ký tự.');
        }
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('Loại truy vấn không hỗ trợ.');
        }
        $params = array_filter(array_map('strval', $params), static fn($v) => $v !== '');
        $this->pdo->prepare('INSERT INTO saved_queries(name, kind, params, created_at) VALUES (?,?,?,?)')
            ->execute([$name, $kind, json_encode($params, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')]);
        return (int)$this->pdo->lastInsertId();
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM saved_queries ORDER BY id')->fetchAll();
        foreach ($rows as &$r) {
            $r['params'] = json_decode($r['params'], true) ?: [];
        }
        return $rows;
    }

    public function find(int $id): ?array
    {
        foreach ($this->all() as $r) {
            if ((int)$r['id'] === $id) { return $r; }
        }
        return null;
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM saved_queries WHERE id = ?')->execute([$id]);
    }

    /** Mã trạm/tuyến trong tham số mà bản lịch $vid không có (ví dụ sau khi chuyển sang bản mới). @return string[] */
    private function missingRefs(array $p, int $vid): array
    {
        $out = [];
        foreach (['stop_id' => ['stops', 'trạm'], 'route_id' => ['routes', 'tuyến']] as $key => [$table, $label]) {
            $val = (string)($p[$key] ?? '');
            if ($val === '') {
                continue;
            }
            $s = $this->pdo->prepare("SELECT 1 FROM $table WHERE version_id = ? AND $key = ?");
            $s->execute([$vid, $val]);
            if ($s->fetchColumn() === false) {
                $out[] = "$label '$val' không có";
            }
        }
        return $out;
    }

    /** @return array{kind:string, rows:array, message:?string} */
    public function run(array $q, int $vid): array
    {
        $p = $q['params'];
        $missing = $this->missingRefs($p, $vid);
        if ($missing) {
            return ['kind' => $q['kind'], 'rows' => [], 'message' => "Truy vấn mẫu tham chiếu mã không còn trong bản lịch #$vid: " . implode('; ', $missing) . '.'];
        }
        $cat = new Catalog($this->pdo);
        switch ($q['kind']) {
            case 'next':
                $date = Time::normalizeDate($p['date'] ?? '') ?? '';
                $r = (new Departures($this->pdo))->next($vid, $p['stop_id'] ?? '', $date, $p['time'] ?? '', (int)($p['limit'] ?? 5), $p['route_id'] ?? null);
                return ['kind' => 'next', 'rows' => $r['trips'], 'message' => $r['message']];
            case 'routes': return ['kind' => 'routes', 'rows' => $cat->routes($vid, $p), 'message' => null];
            case 'stops':  return ['kind' => 'stops', 'rows' => $cat->stops($vid, $p), 'message' => null];
            case 'trips':  return ['kind' => 'trips', 'rows' => $cat->trips($vid, $p), 'message' => null];
        }
        throw new \InvalidArgumentException('Loại truy vấn không hỗ trợ.');
    }
}
