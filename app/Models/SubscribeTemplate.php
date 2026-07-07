<?php

namespace App\Models;

use Illuminate\Support\Facades\File;

class SubscribeTemplate
{
    private const NAMES = [
        'singbox',
        'clash',
        'clashmeta',
        'stash',
        'surge',
        'surfboard',
    ];

    public static function setContent(string $name, ?string $content): void
    {
        if (!in_array($name, self::NAMES, true)) {
            return;
        }

        $path = self::path($name);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) $content);
    }

    public static function getContent(string $name): string
    {
        $path = self::path($name);
        return File::exists($path) ? File::get($path) : '';
    }

    private static function path(string $name): string
    {
        return resource_path("rules/templates/{$name}.tpl");
    }
}
