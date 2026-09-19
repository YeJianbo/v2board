<?php

namespace App\Models;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

class SubscribeTemplate
{
    private const DEFINITIONS = [
        'singbox' => [
            'format' => 'json',
            'fallbacks' => ['custom.sing-box.json', 'default.sing-box.json'],
        ],
        'clash' => [
            'format' => 'yaml',
            'fallbacks' => ['custom.clash.yaml', 'default.clash.yaml'],
        ],
        'clashmeta' => [
            'format' => 'yaml',
            'fallbacks' => ['custom2.clash.yaml', 'default.clash.yaml'],
        ],
        'clashverge' => [
            'format' => 'yaml',
            'template_fallback' => 'clashmeta',
            'fallbacks' => ['custom2.clash.yaml', 'default.clash.yaml'],
        ],
        'stash' => [
            'format' => 'yaml',
            'fallbacks' => ['custom.stash.yaml', 'default.stash.yaml'],
        ],
        'surge' => [
            'format' => 'text',
            'fallbacks' => ['custom.surge.conf', 'default.surge.conf'],
        ],
        'surfboard' => [
            'format' => 'text',
            'fallbacks' => ['custom.surfboard.conf', 'default.surfboard.conf'],
        ],
    ];

    public static function setContent(string $name, ?string $content): void
    {
        if (!isset(self::DEFINITIONS[$name])) {
            return;
        }

        self::validateContent($name, (string) $content);
        $path = self::path($name);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) $content);
    }

    public static function getContent(string $name): string
    {
        if (!isset(self::DEFINITIONS[$name])) {
            return '';
        }

        $path = self::path($name);
        if (File::exists($path)) {
            $content = File::get($path);
            if (trim($content) !== '') {
                return $content;
            }
        }

        $templateFallback = self::DEFINITIONS[$name]['template_fallback'] ?? null;
        if ($templateFallback) {
            $fallbackTemplatePath = self::path($templateFallback);
            if (File::exists($fallbackTemplatePath)) {
                $content = File::get($fallbackTemplatePath);
                if (trim($content) !== '') {
                    return $content;
                }
            }
        }

        foreach (self::DEFINITIONS[$name]['fallbacks'] as $filename) {
            $fallbackPath = resource_path('rules/' . $filename);
            if (File::exists($fallbackPath)) {
                return File::get($fallbackPath);
            }
        }

        return '';
    }

    public static function parseYaml(string $name): array
    {
        $content = self::getContent($name);
        return Cache::remember(self::parsedCacheKey('yaml', $name, $content), 3600, static function () use ($name, $content) {
            $config = Yaml::parse($content);

            if (!is_array($config)) {
                throw new InvalidArgumentException("{$name} 订阅模板必须是有效的 YAML 对象");
            }

            return $config;
        });
    }

    public static function parseJson(string $name): array
    {
        $content = self::getContent($name);
        return Cache::remember(self::parsedCacheKey('json', $name, $content), 3600, static function () use ($name, $content) {
            $config = json_decode($content, true);
            if (!is_array($config) || json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException("{$name} 订阅模板必须是有效的 JSON 对象");
            }

            return $config;
        });
    }

    public static function validateContent(string $name, string $content): void
    {
        if (!isset(self::DEFINITIONS[$name]) || trim($content) === '') {
            return;
        }

        $format = self::DEFINITIONS[$name]['format'];
        if ($format === 'json') {
            json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException("{$name} JSON 格式错误：" . json_last_error_msg());
            }
            return;
        }

        if ($format === 'yaml') {
            try {
                $parsed = Yaml::parse($content);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException("{$name} YAML 格式错误：" . $e->getMessage(), 0, $e);
            }
            if (!is_array($parsed)) {
                throw new InvalidArgumentException("{$name} 订阅模板必须是 YAML 对象");
            }
        }
    }

    private static function path(string $name): string
    {
        return resource_path("rules/templates/{$name}.tpl");
    }

    private static function parsedCacheKey(string $format, string $name, string $content): string
    {
        return "subscribe_template:parsed:{$format}:{$name}:" . sha1($content);
    }
}
