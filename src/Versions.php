<?php
declare(strict_types=1);
namespace Gtfs;

use PDO;

final class ForbiddenException extends \RuntimeException {}
final class VersionException extends \RuntimeException {}

/** Danh sách phiên bản, bản đang dùng, đổi bản (có kiểm tra quyền + toàn vẹn). */
final class Versions
{
    public function __construct(private PDO $pdo) {}

    public function all(): array
    {
        return $this->pdo->query('SELECT v.*, (SELECT COUNT(*) FROM trips t WHERE t.version_id = v.id) AS trip_count FROM versions v ORDER BY v.id DESC')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM versions WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public function issues(int $id): array
    {
        $s = $this->pdo->prepare("SELECT * FROM import_issues WHERE version_id = ? ORDER BY (severity = 'error') DESC, id");
        $s->execute([$id]);
        return $s->fetchAll();
    }

    public function activeId(): ?int
    {
        $v = $this->pdo->query("SELECT value FROM settings WHERE key = 'active_version'")->fetchColumn();
        return $v === false || $v === null ? null : (int)$v;
    }

    public function active(): ?array
    {
        $id = $this->activeId();
        return $id ? $this->find($id) : null;
    }

    public function activate(int $id, Actor $actor): void
    {
        if (!$actor->isManager()) {
            throw new ForbiddenException('Chỉ người quản lý bộ lịch mới được đổi bản đang sử dụng.');
        }
        // Kiểm tra và đổi bản trong CÙNG một giao dịch có khóa ghi: không ai xóa/sửa bản này giữa lúc kiểm tra và lúc đổi.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $v = $this->find($id);
            if (!$v) {
                throw new VersionException("Không có phiên bản #$id.");
            }
            if ($v['status'] !== 'valid' || (int)$v['error_count'] > 0) {
                throw new VersionException("Phiên bản #$id có lỗi nhập, không thể đưa vào sử dụng.");
            }
            $problems = self::integrity($this->pdo, $id);
            if ($problems) {
                throw new VersionException("Phiên bản #$id không qua kiểm tra toàn vẹn: " . implode('; ', $problems));
            }
            $this->pdo->prepare("INSERT INTO settings(key, value) VALUES ('active_version', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([(string)$id]);
            $this->log($actor, 'activate', "version=$id");
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->rollbackQuietly();
            throw $e;
        }
    }

    private function rollbackQuietly(): void
    {
        try { $this->pdo->exec('ROLLBACK'); } catch (\Throwable) { /* giao dịch đã kết thúc */ }
    }

    public function delete(int $id, Actor $actor): void
    {
        if (!$actor->isManager()) {
            throw new ForbiddenException('Chỉ người quản lý mới được xóa phiên bản.');
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($this->activeId() === $id) {
                throw new VersionException('Không thể xóa phiên bản đang sử dụng.');
            }
            $this->pdo->prepare('DELETE FROM versions WHERE id = ?')->execute([$id]);
            $this->log($actor, 'delete', "version=$id");
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->rollbackQuietly();
            throw $e;
        }
    }

    public function log(Actor $actor, string $action, string $detail = ''): void
    {
        $this->pdo->prepare('INSERT INTO audit_log(at, actor, action, detail) VALUES (?,?,?,?)')
            ->execute([gmdate('Y-m-d H:i:s'), $actor->name, $action, $detail]);
    }

    public function auditLog(int $limit = 20): array
    {
        return $this->pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . (int)$limit)->fetchAll();
    }

    /**
     * Kiểm tra lại mã tham chiếu ngay trên dữ liệu đã lưu (lớp bảo vệ thứ hai).
     * @return string[] danh sách vấn đề; rỗng nghĩa là toàn vẹn.
     */
    public static function integrity(PDO $pdo, int $vid): array
    {
        $checks = [
            'stop_times tham chiếu chuyến không tồn tại' =>
                'SELECT COUNT(*) FROM stop_times st LEFT JOIN trips t ON t.version_id = st.version_id AND t.trip_id = st.trip_id WHERE st.version_id = ? AND t.trip_id IS NULL',
            'stop_times tham chiếu trạm không tồn tại' =>
                'SELECT COUNT(*) FROM stop_times st LEFT JOIN stops s ON s.version_id = st.version_id AND s.stop_id = st.stop_id WHERE st.version_id = ? AND s.stop_id IS NULL',
            'trips tham chiếu tuyến không tồn tại' =>
                'SELECT COUNT(*) FROM trips t LEFT JOIN routes r ON r.version_id = t.version_id AND r.route_id = t.route_id WHERE t.version_id = ? AND r.route_id IS NULL',
            'trips tham chiếu service_id không tồn tại' =>
                'SELECT COUNT(*) FROM trips t WHERE t.version_id = ?1
                   AND NOT EXISTS (SELECT 1 FROM calendar c WHERE c.version_id = ?1 AND c.service_id = t.service_id)
                   AND NOT EXISTS (SELECT 1 FROM calendar_dates d WHERE d.version_id = ?1 AND d.service_id = t.service_id)',
        ];
        $out = [];
        foreach ($checks as $msg => $sql) {
            $s = $pdo->prepare($sql);
            $s->execute([$vid]);
            if ((int)$s->fetchColumn() > 0) {
                $out[] = $msg;
            }
        }
        $s = $pdo->prepare('SELECT COUNT(*) FROM trips WHERE version_id = ?');
        $s->execute([$vid]);
        if ((int)$s->fetchColumn() === 0) {
            $out[] = 'bộ lịch không có chuyến nào';
        }
        return $out;
    }
}
