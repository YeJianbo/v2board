<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingService
{
    private const CACHE_KEY = 'admin_settings';

    private ?array $loadedSettings = null;

    private function settingsCache()
    {
        try {
            return Cache::store('redis');
        } catch (\Throwable $e) {
            return Cache::store(config('cache.default', 'file'));
        }
    }

    private function load(): array
    {
        if ($this->loadedSettings !== null) {
            return $this->loadedSettings;
        }

        try {
            $settings = $this->settingsCache()->rememberForever(self::CACHE_KEY, function (): array {
                return DB::table('v2_settings')->pluck('value', 'name')->toArray();
            });
        } catch (\Throwable $e) {
            return $this->loadedSettings = [];
        }

        return $this->loadedSettings = is_array($settings) ? $settings : [];
    }

    public function flush(): void
    {
        $this->loadedSettings = null;

        try {
            $this->settingsCache()->forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
        }
    }

    public function get($name, $default = null)
    {
        $settings = $this->load();
        return array_key_exists($name, $settings) ? $settings[$name] : $default;
    }

    public function getAll()
    {
        return $this->load();
    }

    public function getBatch(array $names): array
    {
        $settings = $this->load();
        $result = [];
        foreach ($names as $name) {
            if (array_key_exists($name, $settings)) {
                $result[$name] = $settings[$name];
            }
        }

        return $result;
    }
}
