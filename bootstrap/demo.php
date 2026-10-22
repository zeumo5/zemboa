<?php

declare(strict_types=1);

use App\Support\DemoConnectionFactory;
use App\Support\DemoDatabase;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

$root = dirname(__DIR__);
if (PHP_VERSION_ID < 80300 || ! extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Demo requires PHP >= 8.3 and pdo_sqlite.');
}
if (! is_file($root.'/.env.demo') || is_link($root.'/.env.demo')) {
    throw new RuntimeException('Copy .env.demo.example to .env.demo first.');
}
if ((lstat($root.'/.env.demo')['nlink'] ?? 0) !== 1) {
    throw new RuntimeException('Demo refuses a hard-linked environment file.');
}
require_once $root.'/vendor/autoload.php';

// Parse only the dedicated file: never import development secrets from .env.
$local = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.demo'));
$forced = [
    'APP_ENV' => 'demo',
    'APP_KEY' => $local['APP_KEY'] ?? '',
    'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/demo-config.php',
    'APP_ROUTES_CACHE' => $root.'/bootstrap/cache/demo-routes.php',
    'APP_EVENTS_CACHE' => $root.'/bootstrap/cache/demo-events.php',
    'DB_CONNECTION' => 'sqlite_demo',
    'DB_URL' => '',
];
foreach ($forced as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $name) {
    if (file_exists($forced[$name])) {
        throw new RuntimeException('Remove the dedicated demo cache before using the demo: '.$forced[$name]);
    }
}

$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.demo');
DemoDatabase::assertLocalPath($app->environmentFilePath(), '.env.demo');
// The dedicated file was already parsed above. Disable all implicit dotenv searches.
$app->instance(LoadEnvironmentVariables::class, new class extends LoadEnvironmentVariables
{
    public function bootstrap($app)
    {
        // Intentionally no dotenv loading, including .env.demo.demo or --env overrides.
    }
});
// Do not evaluate the normal database.php (MySQL options and platform-specific constants).
$app->instance(LoadConfiguration::class, new class extends LoadConfiguration
{
    protected function loadConfigurationFile(\Illuminate\Contracts\Config\Repository $repository, $name, $path, array $base)
    {
        if ($name === 'database') {
            $repository->set('database', require __DIR__.'/demo-database.php');
            unset($base['database']);

            return $base;
        }

        return parent::loadConfigurationFile($repository, $name, $path, $base);
    }
});
// Container extenders also apply when the framework provider registers db.factory later.
$app->extend('db.factory', fn ($factory, $app) => new DemoConnectionFactory($app));
$app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($local): void {
    $config = $app->make('config');
    $connection = $config->get('database.connections.sqlite_demo');
    // No MySQL or alternative connection is available to this application instance.
    $config->set('database.default', 'sqlite_demo');
    $config->set('database.connections', ['sqlite_demo' => $connection]);
    $config->set('app.env', 'demo');
    $config->set('app.name', 'ZEMBOA DEMO');
    $config->set('app.url', 'http://127.0.0.1:8016');
    $config->set('app.debug', false);
    $config->set('app.maintenance.driver', 'file');
    $config->set('app.locale', 'fr');
    $config->set('app.key', $local['APP_KEY'] ?? '');
    $config->set('demo.owner_password', $local['DEMO_OWNER_PASSWORD'] ?? '');
    $config->set('cache', require __DIR__.'/demo-cache.php');
    $config->set('queue.default', 'null');
    $config->set('queue.connections', ['null' => ['driver' => 'null']]);
    $config->set('queue.failed.driver', 'null');
    $config->set('queue.batching.database', 'sqlite_demo');
    $config->set('mail.default', 'array');
    $config->set('mail.mailers', ['array' => ['transport' => 'array']]);
    $config->set('broadcasting.default', 'null');
    $config->set('broadcasting.connections', ['null' => ['driver' => 'null']]);
    $config->set('database.redis', []);
    $config->set('session.driver', 'file');
    $config->set('session.connection', null);
    $config->set('session.files', storage_path('demo/sessions'));
    $config->set('session.cookie', 'zemboa_demo_session');
    $config->set('session.path', '/');
    $config->set('session.domain', null);
    $config->set('session.secure', false);
    $config->set('view.compiled', storage_path('demo/views'));
    $config->set('logging.default', 'single');
    $config->set('logging.channels.single.path', storage_path('demo/laravel.log'));
    $config->set('filesystems.default', 'local');
    $config->set('filesystems.disks', [
        'local' => ['driver' => 'local', 'root' => storage_path('demo/private'), 'throw' => true],
        'public' => [
            'driver' => 'local', 'root' => public_path('marketplace/demo'),
            'url' => '/marketplace/demo', 'visibility' => 'public', 'throw' => true,
        ],
    ]);
    $config->set('filesystems.links', []);
    DemoDatabase::assertConfiguration();
    foreach (['demo', 'demo/sessions', 'demo/views', 'demo/private', 'demo/limiter', 'demo/laravel.log'] as $path) {
        DemoDatabase::assertLocalPath(storage_path($path), 'storage/'.$path);
    }
    DemoDatabase::assertLocalPath(public_path('marketplace/demo'), 'public/marketplace/demo');
});

return $app;
