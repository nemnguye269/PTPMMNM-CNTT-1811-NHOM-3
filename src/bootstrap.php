<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Gtfs\\')) {
        $file = __DIR__ . '/' . substr($class, 5) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function gtfs_root(): string { return dirname(__DIR__); }

function gtfs_db_path(): string {
    return getenv('GTFS_DB') ?: gtfs_root() . '/data/gtfs.sqlite';
}
