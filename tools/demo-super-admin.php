<?php

declare(strict_types=1);

use App\Support\DemoDatabase;
use App\Support\DemoSuperAdminProvisioner;
use Illuminate\Contracts\Console\Kernel;

// Only the PowerShell wrapper is supported. Never accept credentials in argv or environment.
ini_set('zend.exception_ignore_args', '1');
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Windows' || $argc !== 1 || stream_isatty(STDIN)
        || ! extension_loaded('sqlite3')) {
        throw new RuntimeException('Unsupported provisioning invocation.');
    }
    $input = stream_get_contents(STDIN, 8193);
    if ($input === false || strlen($input) > 8192) {
        throw new RuntimeException('Invalid private input.');
    }
    $payload = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    unset($input);
    if (! is_array($payload) || array_keys($payload) !== ['password', 'confirmation', 'writers_stopped']
        || ! is_string($payload['password']) || ! is_string($payload['confirmation'])
        || ! hash_equals($payload['password'], $payload['confirmation']) || $payload['writers_stopped'] !== true) {
        throw new RuntimeException('Private input validation failed.');
    }
    $app = require dirname(__DIR__).'/bootstrap/demo.php';
    $app->make(Kernel::class)->bootstrap();
    DemoDatabase::assertIsolated();
    $path = database_path('zemboa_demo.sqlite');
    if (strtolower(str_replace('\\', '/', realpath($path))) !== 'c:/laragon/www/zemboa/database/zemboa_demo.sqlite') {
        throw new RuntimeException('Unexpected DEMO path.');
    }
    $connection = $app->make('db')->connection('sqlite_demo');
    $backupPath = null;
    $user = (new DemoSuperAdminProvisioner)->create($connection, $payload['password'], function () use ($path, &$backupPath): void {
        $directory = storage_path('demo/backups');
        DemoDatabase::assertLocalPath($directory, 'storage/demo/backups');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot prepare backup directory.');
        }
        $backupPath = $directory.'/before-super-admin-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.sqlite';
        DemoDatabase::assertLocalPath($backupPath, 'storage/demo/backups/'.basename($backupPath));
        $handle = fopen($backupPath, 'x');
        if ($handle === false) {
            throw new RuntimeException('Refusing to overwrite backup.');
        }
        fclose($handle);
        $source = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $destination = new SQLite3($backupPath, SQLITE3_OPEN_READWRITE);
        try {
            DemoSuperAdminProvisioner::backup($source, $destination);
        } finally {
            $source->close();
            $destination->close();
        }
        echo 'Verified backup: '.$backupPath."\n";
    });
    unset($payload);
    echo 'Created exclusively qualified DEMO SUPER_ADMIN: '.DemoSuperAdminProvisioner::EMAIL."\n";
    echo "Existing records preserved. Login: http://127.0.0.1:8016/admin/login\n";
} catch (Throwable) {
    // Never pass exception arguments, validation input, SQL bindings or credentials to a logger.
    fwrite(STDERR, "Provisioning stopped. No automatic retry or restore. Preserve any backup and request diagnosis.\n");
    exit(1);
}
