<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoDatabase
{
    public static function assertConfiguration(): void
    {
        $connections = config('database.connections');
        if (! app()->environment('demo') || config('database.default') !== 'sqlite_demo'
            || ! is_array($connections) || array_keys($connections) !== ['sqlite_demo']) {
            throw new RuntimeException('Demo refused: isolated configuration not confirmed.');
        }
        self::assertConnectionConfiguration($connections['sqlite_demo']);
        self::assertPath(false);
    }

    public static function assertConnectionConfiguration(mixed $config, bool $parsed = false): void
    {
        $allowed = ['driver', 'url', 'database', 'prefix', 'foreign_key_constraints',
            'busy_timeout', 'journal_mode', 'synchronous', 'transaction_mode', 'name'];
        if (! is_array($config) || array_diff(array_keys($config), $allowed) !== []
            || ($config['driver'] ?? null) !== 'sqlite'
            || (! $parsed && ! array_key_exists('url', $config)) || ($config['url'] ?? null) !== null
            || ($config['database'] ?? null) !== database_path('zemboa_demo.sqlite')
            || ($config['foreign_key_constraints'] ?? null) !== true
            || ($config['prefix'] ?? null) !== ''
            || ($config['name'] ?? 'sqlite_demo') !== 'sqlite_demo') {
            throw new RuntimeException('Demo refused: SQLite configuration is missing or unsafe.');
        }
    }

    public static function assertLocalPath(string $path, string $relative): void
    {
        $root = realpath(base_path());
        if ($root === false
            || str_replace('\\', '/', $path) !== str_replace('\\', '/', $root.'/'.$relative)) {
            throw new RuntimeException('Demo refused: unexpected local path.');
        }
        $cursor = $root;
        foreach (explode('/', $relative) as $part) {
            $cursor .= DIRECTORY_SEPARATOR.$part;
            if (is_link($cursor) || (file_exists($cursor) && realpath($cursor) !== $cursor)
                || (is_file($cursor) && (lstat($cursor)['nlink'] ?? 0) !== 1)) {
                throw new RuntimeException('Demo refused: redirected or hard-linked local path.');
            }
        }
    }

    public static function assertPath(bool $requireFile): void
    {
        $directory = realpath(database_path());
        $root = realpath(base_path());
        $expected = database_path('zemboa_demo.sqlite');
        // Refuse redirected database directories, symbolic links and hard-linked files.
        if ($root === false || $directory !== $root.DIRECTORY_SEPARATOR.'database'
            || is_link($expected)) {
            throw new RuntimeException('Demo refused: unexpected database directory or link.');
        }
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            $sidecar = $expected.$suffix;
            if ((! file_exists($expected) && file_exists($sidecar))
                || is_link($sidecar) || (file_exists($sidecar)
                && (! is_file($sidecar) || (lstat($sidecar)['nlink'] ?? 0) !== 1))) {
                throw new RuntimeException('Demo refused: unsafe SQLite sidecar.');
            }
        }
        if (! file_exists($expected)) {
            if ($requireFile) {
                throw new RuntimeException('Demo database is absent. Use the dedicated create action after approval.');
            }
            return;
        }
        $stat = lstat($expected);
        if (! is_file($expected) || realpath($expected) !== $directory.DIRECTORY_SEPARATOR.'zemboa_demo.sqlite'
            || $stat === false || ($stat['nlink'] ?? 0) !== 1) {
            throw new RuntimeException('Demo refused: unexpected database file.');
        }
        // Reading the header does not open a PDO connection or change the file.
        $header = file_get_contents($expected, false, null, 0, 16);
        if ($header === false || ($stat['size'] !== 0 && $header !== "SQLite format 3\0")) {
            throw new RuntimeException('Demo refused: existing file is not SQLite.');
        }
    }

    public static function assertIsolated(): void
    {
        self::assertConfiguration();
        self::assertPath(true);
        $connection = DB::connection('sqlite_demo');
        self::assertConnectionConfiguration($connection->getConfig(), true);
        if ($connection->getDriverName() !== 'sqlite'
            || $connection->getDatabaseName() !== database_path('zemboa_demo.sqlite')) {
            throw new RuntimeException('Demo refused: unexpected resolved connection.');
        }
    }
}
