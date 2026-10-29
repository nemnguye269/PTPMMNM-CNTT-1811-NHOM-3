<?php
declare(strict_types=1);
namespace Gtfs;

/** Người thực hiện thao tác. Chỉ "manager" mới được nhập, đổi bản đang dùng, xóa bản. */
final class Actor
{
    private function __construct(public readonly string $name, public readonly string $role) {}
    public static function manager(string $name = 'quanly'): self { return new self($name, 'manager'); }
    public static function guest(): self { return new self('khach', 'guest'); }
    public function isManager(): bool { return $this->role === 'manager'; }
}
