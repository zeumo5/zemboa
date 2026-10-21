<?php

declare(strict_types=1);

// File-only installer. No Laravel bootstrap, environment loading, PDO, SQL or seeders.
if (PHP_SAPI !== 'cli' || $argc !== 2 || ! in_array($argv[1], ['--dry-run', '--apply'], true)) {
    fwrite(STDERR, "Usage: php tools/install-demo-media.php --dry-run|--apply\n");
    exit(1);
}
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/resources/demo-media/catalogue-v1.json'), true, 512, JSON_THROW_ON_ERROR);
$jobs = [];
foreach ($manifest as $item) {
    if (! preg_match('~^public/marketplace/demo-photos-v1/[a-z-]+\.jpg$~D', $item['source'])
        || ! preg_match('~^public/marketplace/demo/demo-[a-z-]+/demo-[a-z-]+\.svg$~D', $item['target'])) {
        throw new RuntimeException('Unexpected media path.');
    }
    foreach ([$item['source'], $item['target']] as $relative) {
        $cursor = realpath($root);
        foreach (explode('/', $relative) as $part) {
            $cursor .= DIRECTORY_SEPARATOR.$part;
            if (is_link($cursor) || ! file_exists($cursor) || realpath($cursor) !== $cursor
                || (is_file($cursor) && (lstat($cursor)['nlink'] ?? 0) !== 1)) {
                throw new RuntimeException('Missing or redirected media path: '.$relative);
            }
        }
    }
    $jpeg = file_get_contents($root.'/'.$item['source']);
    if (substr($jpeg, 0, 2) !== "\xFF\xD8") {
        throw new RuntimeException('Expected JPEG source.');
    }
    $previous = file_get_contents($root.'/'.$item['target']);
    if (! str_contains($previous, '<svg')) {
        throw new RuntimeException('Expected an existing SVG demo image.');
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 960 960" width="960" height="960">'
        .'<title>DEMO - AI generated product visual, not for sale</title>'
        .'<rect width="960" height="960" fill="#F8FAFC"/>'
        .'<image width="960" height="910" preserveAspectRatio="xMidYMid slice" href="data:image/jpeg;base64,'.base64_encode($jpeg).'"/>'
        .'<text x="480" y="942" text-anchor="middle" font-family="sans-serif" font-size="17" fill="#475569">DEMO / VISUEL IA - NOT FOR SALE</text></svg>';
    $jobs[] = [$item['target'], $previous, $svg];
}
if ($argv[1] === '--apply') {
    foreach ($jobs as [$target, $previous, $svg]) {
        $backupRelative = 'storage/demo/media-backups/v1/'.substr($target, strlen('public/marketplace/demo/'));
        $backup = $root.'/'.$backupRelative;
        $cursor = realpath($root);
        foreach (explode('/', $backupRelative) as $part) {
            $cursor .= DIRECTORY_SEPARATOR.$part;
            if (is_link($cursor) || (file_exists($cursor) && realpath($cursor) !== $cursor)
                || (is_file($cursor) && (lstat($cursor)['nlink'] ?? 0) !== 1)) {
                throw new RuntimeException('Redirected media backup path.');
            }
        }
        if (! is_dir(dirname($backup)) && ! mkdir(dirname($backup), 0755, true) && ! is_dir(dirname($backup))) {
            throw new RuntimeException('Cannot create local media backup directory.');
        }
        // Keep the first version for restoration; do not replace it on reruns.
        if (! file_exists($backup)) {
            $handle = fopen($backup, 'x');
            if ($handle === false || fwrite($handle, $previous) !== strlen($previous)) {
                throw new RuntimeException('Cannot back up existing SVG.');
            }
            fclose($handle);
        }
        if (file_put_contents($root.'/'.$target, $svg, LOCK_EX) !== strlen($svg)) {
            throw new RuntimeException('Cannot install SVG photo wrapper.');
        }
    }
}
echo count($jobs).' demo media '.($argv[1] === '--apply' ? 'installed' : 'validated')."; no database accessed.\n";
