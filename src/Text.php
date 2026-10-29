<?php
declare(strict_types=1);
namespace Gtfs;

final class Text
{
    private static ?array $map = null;

    /** Chữ thường, bỏ dấu tiếng Việt: dùng để tìm kiếm không phân biệt dấu. */
    public static function fold(string $s): string
    {
        if (self::$map === null) {
            $groups = [
                'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ',
                'o' => 'òóọỏõôồốộổỗơờớợởỡ', 'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ',
            ];
            self::$map = [];
            foreach ($groups as $base => $chars) {
                foreach (mb_str_split($chars) as $c) {
                    self::$map[$c] = $base;
                }
            }
        }
        $s = mb_strtolower(trim($s));
        $s = (string)preg_replace('/\p{Mn}+/u', '', $s); // dấu rời (NFD)
        return trim(strtr($s, self::$map));
    }

    public static function h(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
