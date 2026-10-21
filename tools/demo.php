<?php

declare(strict_types=1);

use App\Support\DemoDatabase;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Process\Process;

$action = $argv[1] ?? 'help';
if ($argc > 2 || ! in_array($action, ['help', 'check', 'key', 'create', 'migrate', 'seed', 'serve'], true)) {
    fwrite(STDERR, "Unsupported demo action or extra arguments.\n");
    exit(1);
}
if ($action === 'help') {
    echo "php tools/demo.php [check|key|create|migrate|seed|serve]\n";
    exit(0);
}
try {
    $app = require dirname(__DIR__).'/bootstrap/demo.php';
    if ($action === 'key') {
        // Generates a key in .env.demo only. Does not bootstrap or connect a database.
        $contents = file_get_contents($app->environmentFilePath());
        if (preg_match_all('/^APP_KEY=/m', $contents) !== 1) {
            throw new RuntimeException('Demo requires exactly one APP_KEY entry.');
        }
        $localKey = Dotenv\Dotenv::parse($contents)['APP_KEY'] ?? '';
        if ($localKey !== '') {
            throw new RuntimeException('Demo key already exists; nothing overwritten.');
        }
        $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), $contents, 1, $count);
        if ($count !== 1 || file_put_contents($app->environmentFilePath(), $contents, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write APP_KEY in .env.demo.');
        }
        echo "Demo key generated in .env.demo.\n";
        exit(0);
    }
    $app->make(Kernel::class)->bootstrap();
    DemoDatabase::assertConfiguration();
    if ($action === 'check') {
        echo "DEMO only: ".config('database.connections.sqlite_demo.database')."\n";
        echo "Configured driver: sqlite; foreign keys: on; no database opened.\n";
        exit(0);
    }
    if ($action === 'create') {
        DemoDatabase::assertPath(false);
        $path = database_path('zemboa_demo.sqlite');
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Demo database already exists; nothing overwritten.');
        }
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new RuntimeException('Cannot create demo database file.');
        }
        fclose($file);
        DemoDatabase::assertPath(true);
        echo "Empty demo file created; no migrations run.\n";
        exit(0);
    }
    DemoDatabase::assertIsolated();
    foreach (['sessions', 'views', 'private'] as $directory) {
        $path = storage_path('demo/'.$directory);
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create demo directory.');
        }
    }
    if ($action === 'serve') {
        if (! config('app.key')) {
            throw new RuntimeException('Generate the demo key first.');
        }
        $process = new Process([PHP_BINARY, '-S', '127.0.0.1:8016', '-t', public_path(), base_path('tools/demo-router.php')], base_path());
        $process->setTimeout(null);
        exit($process->run(function ($type, $buffer): void { echo $buffer; }));
    }
    $arguments = $action === 'migrate'
        ? ['command' => 'migrate', '--database' => 'sqlite_demo']
        : ['command' => 'db:seed', '--database' => 'sqlite_demo', '--class' => Database\Seeders\Demo\DemoSeeder::class];
    exit($app->handleCommand(new ArrayInput($arguments)));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
