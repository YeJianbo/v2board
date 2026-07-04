<?php

namespace App\Services;

use App\Models\Setting as SettingModel;
use Illuminate\Support\Facades\Cache;

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
                return SettingModel::pluck('value', 'name')->toArray();
            });
        } catch (\Throwable $e) {
            $settings = SettingModel::pluck('value', 'name')->toArray();
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
}
