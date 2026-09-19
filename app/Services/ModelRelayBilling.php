<?php

namespace App\Services;

class ModelRelayBilling
{
    public static function calculate(?array $prices, ?int $input, ?int $output, ?int $cacheRead, ?int $cacheWrite): array
    {
        if (!$prices || !function_exists('bcmul')) return ['cost' => null, 'cost_status' => 'unpriced'];
        if ($input === null || $output === null) return ['cost' => null, 'cost_status' => 'unknown'];
        if (($cacheRead ?? 0) + ($cacheWrite ?? 0) > $input) return ['cost' => null, 'cost_status' => 'inconsistent'];
        $counts = ['input' => $input - ($cacheRead ?? 0) - ($cacheWrite ?? 0), 'output' => $output, 'cache_read' => $cacheRead ?? 0, 'cache_write' => $cacheWrite ?? 0];
        $sum = '0';
        foreach ($counts as $type => $count) {
            if (!isset($prices[$type])) return ['cost' => null, 'cost_status' => 'unpriced'];
            $sum = bcadd($sum, bcmul((string) $count, (string) $prices[$type], 12), 12);
        }
        return ['cost' => bcdiv($sum, '1000000', 12), 'cost_status' => $cacheRead === null || $cacheWrite === null ? 'estimated' : 'confirmed'];
    }
}
