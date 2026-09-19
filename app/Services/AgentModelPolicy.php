<?php

namespace App\Services;

class AgentModelPolicy
{
    public const MODELS = [
        'gemini' => 'gemini-3.8-flash',
        'grok' => 'grok-4.6',
    ];

    public static function schedule(): array
    {
        return ['server_time' => time(), 'primary' => 'gemini', 'fallback' => 'grok'];
    }

    public static function models(array $profiles): array
    {
        $models = self::MODELS;
        foreach ($profiles as $key => $profile) {
            if (preg_match('/^custom_[a-zA-Z0-9_-]{1,64}$/D', (string) $key) && is_array($profile) && is_string($profile['model'] ?? null) && trim($profile['model']) !== '') {
                $models[$key] = trim($profile['model']);
            }
        }
        return $models;
    }

    public static function candidates(array $config): array
    {
        $names = array_keys(self::MODELS);
        $models = self::models($config['profiles'] ?? []);
        $choice = $config['model_choice'] ?? 'auto';
        if ($choice !== 'auto') {
            abort_unless(isset($models[$choice]), 422, '所选模型不可用，请重新选择');
            $names = [$choice];
        }
        $result = [];
        foreach ($names as $name) {
            $profile = $config['profiles'][$name] ?? [];
            if (!empty($profile['enabled']) && !empty($profile['base_url']) && !empty($profile['api_key'])) {
                $result[] = ['model' => $models[$name], 'base_url' => $profile['base_url'], 'api_key' => $profile['api_key'], 'protocol' => $profile['protocol'] ?? 'openai'];
            }
        }
        return $result;
    }
}
