<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Storage;

final class StoreBranding
{
    public static function url(?StoreSetting $settings, string $slot): ?string
    {
        if (! $settings || ! in_array($slot, ['logo', 'banner'], true)) {
            return null;
        }
        if ($settings->getAttribute('preview_'.$slot.'_url')) {
            return $settings->getAttribute('preview_'.$slot.'_url');
        }
        $path = $settings->getAttribute($slot.'_path');
        if (! is_string($path) || str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, ':') || str_contains($path, '\\')
            || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public static function accent(?string $color): string
    {
        $color = preg_match('/^#[0-9a-fA-F]{6}$/D', $color ?? '') ? $color : '#0F6B57';
        $rgb = array_map(hexdec(...), str_split(substr($color, 1), 2));
        while (self::contrast($rgb) < 4.5) {
            $rgb = array_map(fn (int $channel): int => max(0, (int) floor($channel * 0.9)), $rgb);
        }

        return sprintf('#%02X%02X%02X', ...$rgb);
    }

    private static function contrast(array $rgb): float
    {
        $channels = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 1.05 / (0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2] + 0.05);
    }
}
