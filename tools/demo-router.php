<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
try {
    $root = dirname(__DIR__);
    $public = realpath($root.'/public');
    if ($public === false || $public !== realpath($root).DIRECTORY_SEPARATOR.'public') {
        throw new RuntimeException('Demo public directory is not confirmed.');
    }
    define('LARAVEL_START', microtime(true));
    $app = require $root.'/bootstrap/demo.php';
    $app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
    App\Support\DemoDatabase::assertIsolated();
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
    $file = realpath($public.$path);
    $types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'woff' => 'font/woff',
        'woff2' => 'font/woff2', 'txt' => 'text/plain'];
    $extension = $file === false ? '' : strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($file !== false && is_file($file)
        && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && isset($types[$extension])) {
        header('Content-Type: '.$types[$extension]);
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
    // Never return false: the PHP server must never execute the normal public/index.php.
    $app->handleRequest(Illuminate\Http\Request::capture());
} catch (Throwable $exception) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'DEMO unavailable: isolated configuration/database not confirmed.';
}
